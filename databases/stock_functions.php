<?php
// databases/stock_functions.php
// Fonctions communes de gestion des mouvements de stock.
// La direction (entrée/sortie) et la nature du mouvement sont portées
// UNIQUEMENT par `commande.statut_id`, qui référence `statut.code_statut`.
// Il n'y a plus de colonne type_mouvement redondante : la table `statut`
// (via son champ type_statut) est la seule source de vérité.

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
            $stmt = $pdo->prepare(
                "SELECT quantite, quantite_reservee FROM stock_boutique WHERE produit_id = ? AND boutique_id = ? FOR UPDATE"
            );
            $stmt->execute([$produit_id, $boutique_id]);
            $ligne = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ligne === false) {
                $pdo->prepare(
                    "INSERT INTO stock_boutique (produit_id, boutique_id, quantite, quantite_reservee) VALUES (?, ?, 0, 0)"
                )->execute([$produit_id, $boutique_id]);
                $stock_avant = 0;
                $reserve = 0;
            } else {
                $stock_avant = (int) $ligne['quantite'];
                $reserve = (int) ($ligne['quantite_reservee'] ?? 0);
            }

            $stock_apres = $stock_avant + ($sens * $quantite);

            if ($stock_apres < 0) {
                throw new Exception(
                    "Stock insuffisant pour $produit_id dans la boutique $boutique_id " .
                        "(disponible : $stock_avant, demandé : $quantite)."
                );
            }
            // Une sortie ne doit jamais entamer la part déjà réservée par des ventes en attente.
            if ($sens < 0 && ($stock_avant - $reserve) < $quantite) {
                throw new Exception(
                    "Stock insuffisant (hors réservations) pour $produit_id dans la boutique $boutique_id " .
                        "(disponible net : " . ($stock_avant - $reserve) . ", demandé : $quantite)."
                );
            }

            $jour = date('Ymd');
            $stmtCode = $pdo->prepare("SELECT COUNT(*) FROM commande WHERE numero_commande LIKE ?");
            $stmtCode->execute(["MV-$jour-%"]);
            $numero_commande = sprintf('MV-%s-%03d', $jour, ((int) $stmtCode->fetchColumn()) + 1);

            $pdo->prepare(
                "INSERT INTO commande
                    (numero_commande, produit_id, boutique_id, statut_id,
                     date_commande, heure_commande, prix_achat, quantite_commande, montant_commande,
                     utilisateur_id, etat_commande, stock_avant, stock_apres, commentaire,
                     date_validation, utilisateur_validation_id)
                 VALUES (?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, 'Valider', ?, ?, ?, NOW(), ?)"
            )->execute([
                $numero_commande,
                $produit_id,
                $boutique_id,
                $statut_id,
                $prix_unitaire,
                $quantite,
                $quantite * $prix_unitaire,
                $utilisateur_id,
                $stock_avant,
                $stock_apres,
                $commentaire ?? $reference_document,
                $utilisateur_id,
            ]);

            $pdo->prepare(
                "UPDATE stock_boutique SET quantite = ? WHERE produit_id = ? AND boutique_id = ?"
            )->execute([$stock_apres, $produit_id, $boutique_id]);

            $pdo->prepare(
                "UPDATE produit SET stock_produit = (
                    SELECT COALESCE(SUM(quantite), 0) FROM stock_boutique WHERE produit_id = ?
                ) WHERE code_produit = ?"
            )->execute([$produit_id, $produit_id]);

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