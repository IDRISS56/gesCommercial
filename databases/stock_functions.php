<?php
// databases/stock_functions.php
// Fonctions communes de gestion des mouvements de stock.
// La direction (entrée/sortie) et la nature du mouvement sont portées
// UNIQUEMENT par `commande.statut_id`, qui référence `statut.code_statut`.
// Il n'y a plus de colonne type_mouvement redondante : la table `statut`
// (via son champ type_statut) est la seule source de vérité.

if (!function_exists('calculerEtatProduit')) {
    /**
     * Calcule l'état d'un produit (RUPTURE / ALERTE / DISPONIBLE) à partir
     * de son stock courant et de son seuil d'alerte. Utilisé partout où le
     * stock d'un produit est modifié directement (hors enregistrerMouvementStock,
     * qui le fait déjà automatiquement).
     */
    function calculerEtatProduit($stock, $stockAlerte)
    {
        $stock = (int) $stock;
        $stockAlerte = (int) $stockAlerte;
        if ($stock <= 0) return 'RUPTURE';
        if ($stock <= $stockAlerte) return 'ALERTE';
        return 'DISPONIBLE';
    }
}

if (!function_exists('enregistrerMouvementStock')) {
    /**
     * Enregistre un mouvement de stock immédiat (hors achat/vente qui suivent
     * un cycle attente -> réception/validation géré ailleurs) : ajustements
     * d'inventaire, pertes, stock initial.
     *
     * @param string $statut_id Code de la table `statut` (ex: '001' Mauvais état,
     *                          '002' Vol/Perte, '003' Cadeau, '004' Surplus,
     *                          '006' Stock d'entrée, '007' Stock de sortie).
     *                          Son `type_statut` ('entree'/'sortie') détermine le sens.
     */
    function enregistrerMouvementStock(
        PDO $pdo,
        string $produit_id,
        string $boutique_id,
        string $statut_id,
        int $quantite,
        float $prix_unitaire,
        ?string $reference_document,
        string $utilisateur_id,
        ?string $commentaire = null
    ): array {
        if ($quantite <= 0) {
            throw new Exception("La quantité doit être positive.");
        }

        $stmtStatut = $pdo->prepare("SELECT type_statut, titre_statut FROM statut WHERE code_statut = ? AND etat_statut = 'Actif'");
        $stmtStatut->execute([$statut_id]);
        $statut = $stmtStatut->fetch(PDO::FETCH_ASSOC);
        if (!$statut) {
            throw new Exception("Statut de mouvement inconnu ou inactif : $statut_id");
        }
        $sensLabel = strtolower(trim($statut['type_statut']));
        if (!in_array($sensLabel, ['entree', 'sortie'], true)) {
            throw new Exception("type_statut invalide pour le statut $statut_id : {$statut['type_statut']}");
        }
        $sens = ($sensLabel === 'entree') ? 1 : -1;

        $dejaEnTransaction = $pdo->inTransaction();
        if (!$dejaEnTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Vérifier le stock actuel dans la table `stock` (pas de réservation)
            $stmt = $pdo->prepare(
                "SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE"
            );
            $stmt->execute([$produit_id, $boutique_id]);
            $ligne = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ligne === false) {
                // Si pas de ligne, on crée un stock à zéro
                $pdo->prepare(
                    "INSERT INTO stock (produit_id, boutique_id, quantite) VALUES (?, ?, 0)"
                )->execute([$produit_id, $boutique_id]);
                $stock_avant = 0;
            } else {
                $stock_avant = (int) $ligne['quantite'];
            }

            $stock_apres = $stock_avant + ($sens * $quantite);

            if ($stock_apres < 0) {
                throw new Exception(
                    "Stock insuffisant pour $produit_id dans la boutique $boutique_id " .
                        "(disponible : $stock_avant, demandé : $quantite)."
                );
            }

            // Générer un numéro de commande
            $jour = date('Ymd');
            $stmtCode = $pdo->prepare("SELECT COUNT(*) FROM commande WHERE numero_commande LIKE ?");
            $stmtCode->execute(["MV-$jour-%"]);
            $numero_commande = sprintf('MV-%s-%03d', $jour, ((int) $stmtCode->fetchColumn()) + 1);

            // Insérer la commande (mouvement) avec les colonnes existantes
            $pdo->prepare(
                "INSERT INTO commande
                    (numero_commande, produit_id, boutique_id, statut_id,
                     date_commande, heure_commande, prix_achat, prix_commande,
                     quantite_commande, montant_commande,
                     utilisateur_id, etat_commande)
                 VALUES (?, ?, ?, ?, CURDATE(), CURTIME(), ?, 0, ?, ?, ?, 'VALIDEE')"
            )->execute([
                $numero_commande,
                $produit_id,
                $boutique_id,
                $statut_id,
                $prix_unitaire,
                $quantite,
                $quantite * $prix_unitaire,
                $utilisateur_id,
            ]);

            // Mettre à jour le stock dans la table `stock`
            $pdo->prepare(
                "UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?"
            )->execute([$stock_apres, $produit_id, $boutique_id]);

            // Mettre à jour le stock total dans `produit`
            $delta = $sens * $quantite;
            $pdo->prepare(
                "UPDATE produit SET stock_produit = COALESCE(stock_produit, 0) + ? WHERE code_produit = ?"
            )->execute([$delta, $produit_id]);

            // Mettre à jour l'état du produit
            $pdo->prepare(
                "UPDATE produit SET etat_produit = CASE
                    WHEN stock_produit <= 0 THEN 'RUPTURE'
                    WHEN stock_produit <= COALESCE(stock_alerte, 0) THEN 'ALERTE'
                    ELSE 'DISPONIBLE' END
                WHERE code_produit = ?"
            )->execute([$produit_id]);

            if (!$dejaEnTransaction) {
                $pdo->commit();
            }

            return [
                'numero_commande' => $numero_commande,
                'stock_avant' => $stock_avant,
                'stock_apres' => $stock_apres,
                'titre_statut' => $statut['titre_statut'],
            ];
        } catch (Exception $e) {
            if (!$dejaEnTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}