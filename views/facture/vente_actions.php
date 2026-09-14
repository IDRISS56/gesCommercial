<?php
// Extrait de vente.php (découpage du fichier — voir audit technique) :
// traitement de toutes les actions AJAX/POST (validation, suppression,
// modification de lignes, listes paginées, etc.). Inclus tel quel dans
// vente.php ; partage sa portée avec le fichier appelant.

// ==========================================
// 3. TRAITEMENT DES ACTIONS (AJAX / POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    header('Content-Type: application/json');
    $csrfCheck = $_POST['csrf_token'] ?? '';
    if (empty($csrfCheck) || $csrfCheck !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Token de sécurité invalide.']);
        exit;
    }

    if ($action === 'produits_par_categorie') {
        $categorieId = trim($_POST['categorie_id'] ?? '');
        if (empty($categorieId)) {
            echo json_encode(['success' => false, 'error' => 'Catégorie manquante', 'produits' => []]);
            exit;
        }
        $stmtProd = $pdo->prepare(
            "SELECT code_produit, titre_produit, prix_produit, etat_produit
             FROM produit
             WHERE categorie_id = ?
             ORDER BY CASE WHEN etat_produit = 'RUPTURE' THEN 1 ELSE 0 END, titre_produit"
        );
        $stmtProd->execute([$categorieId]);
        echo json_encode(['success' => true, 'produits' => $stmtProd->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'liste_factures') {
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $filtres = [
            'client' => trim($_POST['client'] ?? ''),
            'etat' => trim($_POST['etat'] ?? ''),
            'statut' => trim($_POST['statut'] ?? ''),
        ];
        $resultat = getFacturesListe($pdo, $boutiquesAutorisees, $filtres, $page);
        echo json_encode([
            'success' => true,
            'html' => $resultat['html'],
            'pagination' => $resultat['pagination'],
            'total' => $resultat['total'],
        ]);
        exit;
    }

    if ($action === 'validate_facture') {
        $id = $_POST['id'] ?? '';
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'ID manquant']); exit; }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT contact_id, avance, reste, statut_facture, categorie_facture, reference_id FROM facture WHERE numero_facture = ? FOR UPDATE");
            $stmt->execute([$id]);
            $f = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$f) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'Facture introuvable']); exit; }

            $stmt = $pdo->prepare("UPDATE facture SET statut_facture = 'Validee' WHERE numero_facture = ? AND statut_facture <> 'Validee'");
            $stmt->execute([$id]);

            $numBL = null;

            if ($stmt->rowCount() > 0) {
                // Le crédit devient effectif au moment de la validation (le Bon en attente
                // n'engageait encore aucune dette). Si le client est déjà en avance
                // (solde_contact < 0), cette avance couvre directement ce qui reste dû :
                // totalement -> Payée, partiellement -> Partielle, sans avance -> Impayée
                // inchangée. Le reste non couvert s'ajoute au solde/dette du client.
                $resteInitial = floatval($f['reste']);
                if ($resteInitial != 0) {
                    $stmtSoldeC = $pdo->prepare("SELECT solde_contact FROM contact WHERE code_contact = ? FOR UPDATE");
                    $stmtSoldeC->execute([$f['contact_id']]);
                    $soldeAvantC = floatval($stmtSoldeC->fetchColumn());
                    $avanceDispoC = max(0, -$soldeAvantC);

                    $avanceUtilisee = min($avanceDispoC, $resteInitial);
                    $nouveauReste = round($resteInitial - $avanceUtilisee, 2);
                    if ($nouveauReste <= 0) {
                        $nouvelEtat = 'Payee';
                    } elseif ($avanceUtilisee > 0) {
                        $nouvelEtat = 'Partielle';
                    } else {
                        $nouvelEtat = 'Impayee';
                    }

                    $pdo->prepare("UPDATE facture SET etat_facture = ?, avance = avance + ?, reste = ? WHERE numero_facture = ?")
                        ->execute([$nouvelEtat, $avanceUtilisee, $nouveauReste, $id]);

                    $pdo->prepare("UPDATE contact SET solde_contact = solde_contact + ? WHERE code_contact = ?")
                        ->execute([$resteInitial, $f['contact_id']]);
                }

                // ---- CRÉATION DU BON DE LIVRAISON ----
                // C'est la validation du bon de commande, et uniquement elle, qui déclenche
                // la génération du bon de livraison (le devis ne le fait plus à la transformation).
                if ($f['categorie_facture'] === 'Bon') {
                    $stmtLignes = $pdo->prepare("SELECT * FROM commande WHERE facture_id = ?");
                    $stmtLignes->execute([$id]);
                    $lignesBon = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);

                    // ---- RÉSERVATION DU STOCK (bons créés SANS passer par un devis) ----
                    // Un bon transformé depuis un devis (reference_id = DEV-...) a déjà
                    // réservé son stock à la transformation. Un bon créé directement depuis
                    // la vente au comptoir (reference_id NULL) n'a encore touché ni le stock
                    // ni les lots : c'est ici, à la validation, qu'on vérifie et décrémente.
                    if (empty($f['reference_id']) && !empty($lignesBon)) {
                        foreach ($lignesBon as $l) {
                            $boutiqueLigne = $l['boutique_id'] ?: null;
                            if (empty($boutiqueLigne) || empty($l['produit_id'])) {
                                throw new Exception("Ligne de bon incomplète (produit ou boutique manquant) : validation impossible.");
                            }

                            $stmtStockLock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                            $stmtStockLock->execute([$l['produit_id'], $boutiqueLigne]);
                            $dispo = $stmtStockLock->fetchColumn();
                            $dispo = ($dispo === false) ? 0 : (int) $dispo;
                            if ($dispo < (int) $l['quantite_commande']) {
                                $stmtNom = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
                                $stmtNom->execute([$l['produit_id']]);
                                $nomProd = $stmtNom->fetchColumn() ?: $l['produit_id'];
                                throw new Exception("Stock insuffisant pour « $nomProd » : disponible $dispo, demandé {$l['quantite_commande']}.");
                            }

                            if (!empty($l['lot_id'])) {
                                $stmtLotLock = $pdo->prepare("SELECT quantite FROM lot WHERE code_lot = ? FOR UPDATE");
                                $stmtLotLock->execute([$l['lot_id']]);
                                $qteLot = $stmtLotLock->fetchColumn();
                                $qteLot = ($qteLot === false) ? 0 : (int) $qteLot;
                                if ($qteLot < (int) $l['quantite_commande']) {
                                    throw new Exception("Stock de lot insuffisant pour le lot {$l['lot_id']} : disponible $qteLot, demandé {$l['quantite_commande']}.");
                                }
                            }
                        }

                        foreach ($lignesBon as $l) {
                            $boutiqueLigne = $l['boutique_id'];
                            $pdo->prepare("UPDATE stock SET quantite = GREATEST(0, quantite - ?) WHERE produit_id = ? AND boutique_id = ?")
                                ->execute([$l['quantite_commande'], $l['produit_id'], $boutiqueLigne]);
                            $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) - ? AS CHAR) WHERE code_produit = ?")
                                ->execute([$l['quantite_commande'], $l['produit_id']]);
                            $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                            WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                            WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                            ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                                ->execute([$l['produit_id']]);

                            if (!empty($l['lot_id'])) {
                                // Le lot reste Actif même à quantité 0 : un lot déjà
                                // configuré ne doit jamais être désactivé ni supprimé
                                // automatiquement.
                                $pdo->prepare("UPDATE lot SET quantite = quantite - ? WHERE code_lot = ? AND quantite >= ?")
                                    ->execute([$l['quantite_commande'], $l['lot_id'], $l['quantite_commande']]);
                            }
                        }
                    }

                    if (!empty($lignesBon)) {
                        $numBL = 'BL-' . date('Ymd') . '-' . str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);

                        $pdo->prepare("INSERT INTO bon_livraison(code_bon, date_livraison, facture_id, adresse_livraison, transporteur, statut, commentaire)
                                       VALUES (?, CURDATE(), ?, NULL, NULL, 'En attente', NULL)")
                            ->execute([$numBL, $id]);

                        $numBase = date('dmYHis');

                        foreach ($lignesBon as $i => $l) {
                            $numCmd = $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '-BL';

                            $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, prix_lot_ligne, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                                           VALUES (?, ?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?, 'EN ATTENTE')")
                                ->execute([$numCmd, $l['produit_id'], $l['lot_id'], $l['contact_id'], $numBL, $l['prix_achat'], $l['prix_commande'], $l['prix_lot_ligne'], $l['quantite_commande'], $l['produits_par_lot'], $l['montant_commande'], $l['utilisateur_id'], $l['boutique_id']]);
                        }
                    }
                }
            }

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Facture validée' . ($numBL ? ' (bon de livraison ' . $numBL . ' généré)' : ''),
                'bon_livraison' => $numBL
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_facture') {
        $id = $_POST['id'] ?? '';
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'ID manquant']); exit; }
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? FOR UPDATE");
            $stmt->execute([$id]);
            $facture = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$facture) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'Facture introuvable']); exit; }

            // ---- DEUX RÉGIMES DE SUPPRESSION ----
            // 1) CORRECTION D'ERREUR (facture pas encore payée+validée) : suppression
            //    ouverte aux rôles habituels de cet écran, avec restitution complète
            //    du stock, annulation de l'encaissement caisse et de la créance.
            // 2) NETTOYAGE D'ARCHIVE (facture déjà payée ET validée) : la vente est
            //    définitivement conclue — la supprimer ne doit PLUS toucher au stock
            //    ni à la caisse (le produit est physiquement sorti, l'argent a été
            //    réellement encaissé). Réservé au Superviseur/Administrateur, et
            //    seulement après un délai de sécurité pour ne pas purger une vente
            //    du jour par erreur.
            $isPaidValidee = in_array(strtolower($facture['etat_facture'] ?? ''), ['payee', 'payee cash'])
                && strtolower($facture['statut_facture'] ?? '') === 'validee';

            if ($isPaidValidee) {
                $delaiJours = 7;
                $roleUtilisateur = $_SESSION['role'] ?? '';
                if (!in_array($roleUtilisateur, ['Administrateur', 'Superviseur'], true)) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Seul un Superviseur (ou Administrateur) peut supprimer une facture déjà validée et payée.']);
                    exit;
                }
                $ageJours = (strtotime(date('Y-m-d')) - strtotime($facture['date_facture'])) / 86400;
                if ($ageJours < $delaiJours) {
                    $pdo->rollBack();
                    $reste = (int)ceil($delaiJours - $ageJours);
                    echo json_encode(['success' => false, 'error' => "Cette facture ne peut être supprimée que $delaiJours jours après son émission (encore $reste jour(s) à attendre)."]);
                    exit;
                }

                // Nettoyage pur : on ne touche NI au stock NI à la caisse. On ne fait
                // que retirer la créance si, par exception, un reste subsistait.
                if (floatval($facture['reste']) != 0) {
                    $pdo->prepare("UPDATE contact SET solde_contact = solde_contact - ? WHERE code_contact = ?")
                        ->execute([floatval($facture['reste']), $facture['contact_id']]);
                }

                $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM bon_livraison WHERE facture_id = ?")->execute([$id]);
                $stmt = $pdo->prepare("DELETE FROM facture WHERE numero_facture = ?");
                $stmt->execute([$id]);
                $pdo->commit();
                echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Facture archivée supprimée (nettoyage) — stock et caisse non modifiés.']);
                exit;
            }

            // ---- CORRECTION D'ERREUR : restitution complète ----
            // 1. RESTITUTION DU STOCK réservé/vendu par chaque ligne de la facture
            // (toute vente ou tout bon décrémente le stock à la création : le
            // supprimer sans le restituer désynchronise définitivement le stock).
            // Si une ligne ancienne n'a pas de boutique_id (import/donnée hors du
            // flux normal), on ne devine PAS où restituer : on le signale plutôt
            // que de désynchroniser silencieusement le stock.
            $lignesIgnorees = [];
            $stmtLignes = $pdo->prepare("SELECT produit_id, quantite_commande, boutique_id FROM commande WHERE facture_id = ?");
            $stmtLignes->execute([$id]);
            foreach ($stmtLignes->fetchAll(PDO::FETCH_ASSOC) as $l) {
                if (empty($l['boutique_id']) || empty($l['produit_id'])) {
                    $lignesIgnorees[] = $l['produit_id'] ?: '?';
                    continue;
                }
                $pdo->prepare("UPDATE stock SET quantite = quantite + ? WHERE produit_id = ? AND boutique_id = ?")
                    ->execute([$l['quantite_commande'], $l['produit_id'], $l['boutique_id']]);
                $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) + ? AS CHAR) WHERE code_produit = ?")
                    ->execute([$l['quantite_commande'], $l['produit_id']]);
                $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                    ->execute([$l['produit_id']]);
            }

            // 2. ANNULATION DES ENCAISSEMENTS CAISSE liés à cette facture (une
            // suppression ne doit jamais laisser un encaissement fantôme dans la caisse).
            $stmtTr = $pdo->prepare("SELECT numero_transaction, caisse_id, montant_transaction FROM transaction WHERE facture_id = ? AND etat_transaction = 'Succes' FOR UPDATE");
            $stmtTr->execute([$id]);
            foreach ($stmtTr->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $pdo->prepare("UPDATE caisse SET solde = solde - ? WHERE caisse_id = ?")
                    ->execute([$t['montant_transaction'], $t['caisse_id']]);
                $pdo->prepare("UPDATE transaction SET etat_transaction = 'Annulee' WHERE numero_transaction = ?")
                    ->execute([$t['numero_transaction']]);
            }

            // 3. ANNULATION DE LA CRÉANCE CLIENT si la facture avait été validée avec
            // un reste dû (validate_facture avait ajouté ce reste à solde_contact).
            if (strtolower($facture['statut_facture']) === 'validee' && floatval($facture['reste']) != 0) {
                $pdo->prepare("UPDATE contact SET solde_contact = solde_contact - ? WHERE code_contact = ?")
                    ->execute([floatval($facture['reste']), $facture['contact_id']]);
            }

            $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$id]);
            // Le bon de livraison n'a de sens que rattaché à sa facture (bon de
            // commande) : le supprimer aussi évite un bon de livraison "fantôme"
            // (client affiché en N/C) une fois la facture supprimée.
            $pdo->prepare("DELETE FROM bon_livraison WHERE facture_id = ?")->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM facture WHERE numero_facture = ?");
            $stmt->execute([$id]);
            $pdo->commit();

            $message = 'Facture supprimée, stock et caisse rétablis.';
            if (!empty($lignesIgnorees)) {
                $message = 'Facture supprimée. ATTENTION : le stock n\'a pas pu être restitué pour ' . count($lignesIgnorees) . ' ligne(s) sans boutique associée (' . implode(', ', $lignesIgnorees) . ') — vérifiez le stock manuellement.';
            }
            echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => $message, 'lignes_non_restituees' => $lignesIgnorees]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_details') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact FROM facture f LEFT JOIN contact c ON f.contact_id = c.code_contact WHERE f.numero_facture = ?");
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$facture) { echo json_encode(['error' => 'Facture introuvable']); exit; }
        $stmt2 = $pdo->prepare("SELECT c.*, p.titre_produit, l.libelle AS libelle_lot FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit LEFT JOIN lot l ON c.lot_id = l.code_lot WHERE c.facture_id = ?");
        $stmt2->execute([$id]);
        $commandesDetail = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Sécurité : un utilisateur ne peut ouvrir que les bons d'une boutique
        // à laquelle il a accès (même si l'id est deviné/forgé côté client).
        $boutiquesLignes = array_unique(array_filter(array_column($commandesDetail, 'boutique_id')));
        if (!empty($boutiquesLignes) && !array_intersect($boutiquesLignes, $boutiquesAutorisees)) {
            echo json_encode(['error' => 'Accès refusé : ce bon appartient à une autre boutique.']); exit;
        }

        $isLocked = in_array(strtolower($facture['etat_facture'] ?? ''), ['payee', 'payee cash'])
            && strtolower($facture['statut_facture'] ?? '') === 'validee';

        // Boutique de la facture (pour vérifier le stock des nouvelles lignes
        // ajoutées lors de la modification) : celle des lignes existantes, sinon
        // celle de l'utilisateur qui a créé la facture.
        $boutiqueFacture = $commandesDetail[0]['boutique_id'] ?? null;
        if (empty($boutiqueFacture) && !empty($facture['utilisateur_id'])) {
            $stmtUb = $pdo->prepare("SELECT boutique_id FROM utilisateur WHERE id = ?");
            $stmtUb->execute([$facture['utilisateur_id']]);
            $boutiqueFacture = $stmtUb->fetchColumn() ?: null;
        }

        echo json_encode([
            'facture' => $facture,
            'commandes' => $commandesDetail,
            'is_locked' => $isLocked,
            'boutique_id' => $boutiqueFacture
        ]);
        exit;
    }

    if ($action === 'check_stock_produit') {
        // Vérifie le stock disponible d'un produit dans une boutique donnée,
        // utilisé lors de l'ajout d'une nouvelle ligne à un bon en modification.
        $produitId = trim($_POST['produit_id'] ?? '');
        $boutiqueId = trim($_POST['boutique_id'] ?? '');
        if ($boutiqueId !== '' && !in_array($boutiqueId, $boutiquesAutorisees, true)) $boutiqueId = '';
        $response = ['success' => false, 'disponible' => 0, 'prix' => 0, 'titre' => '', 'lots' => [], 'saisie_par_carton' => 0];
        if ($produitId !== '' && $boutiqueId !== '') {
            $stmtS = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ?");
            $stmtS->execute([$produitId, $boutiqueId]);
            $dispo = $stmtS->fetchColumn();
            $stmtP = $pdo->prepare("SELECT titre_produit, prix_produit, saisie_par_carton FROM produit WHERE code_produit = ?");
            $stmtP->execute([$produitId]);
            $rowP = $stmtP->fetch(PDO::FETCH_ASSOC);
            if ($rowP) {
                $response['success'] = true;
                $response['disponible'] = (int) ($dispo !== false ? $dispo : 0);
                $response['prix'] = (float) $rowP['prix_produit'];
                $response['titre'] = $rowP['titre_produit'];
                $response['saisie_par_carton'] = (int) $rowP['saisie_par_carton'];

                // Prix de lot éventuellement configurés pour ce produit (menu
                // "Configuration des lots") : permet de pré-remplir le prix de
                // la ligne sans que la caisse ait à le connaître par cœur.
                $stmtLots = $pdo->prepare("SELECT libelle, unites_par_lot, prix_lot FROM lot WHERE produit_id = ? AND etat_lot = 'Actif'");
                $stmtLots->execute([$produitId]);
                $response['lots'] = $stmtLots->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        echo json_encode($response);
        exit;
    }

    if ($action === 'update_facture') {
        $facture_id = $_POST['facture_id'];
        $checkStmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? FOR UPDATE");
        $checkStmt->execute([$facture_id]);
        $fData = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fData) { echo json_encode(['success' => false, 'error' => 'Facture introuvable.']); exit; }
        $isLocked = in_array(strtolower($fData['etat_facture']), ['payee', 'payee cash'])
            && strtolower($fData['statut_facture']) === 'validee';
        if ($isLocked) {
            echo json_encode(['success' => false, 'error' => 'Facture validée et payée, modification impossible.']);
            exit;
        }
        $avance = floatval($_POST['avance']);
        $reste = floatval($_POST['reste']);
        $ancienReste = floatval($fData['reste']);
        $commandes_data = json_decode($_POST['commandes'], true) ?: [];
        $nouvelles_lignes_data = json_decode($_POST['nouvelles_lignes'] ?? '[]', true) ?: [];
        $lignesIgnorees = [];
        $lignesAjoutees = 0;
        try {
            $pdo->beginTransaction();

            foreach ($commandes_data as $cmd) {
                $stmtCmd = $pdo->prepare("SELECT produit_id, quantite_commande, boutique_id FROM commande WHERE numero_commande = ? FOR UPDATE");
                $stmtCmd->execute([$cmd['id']]);
                $ligneActuelle = $stmtCmd->fetch(PDO::FETCH_ASSOC);
                if (!$ligneActuelle) continue;
                $boutique_id = $ligneActuelle['boutique_id'];
                $produit_id = $ligneActuelle['produit_id'];
                $ancienneQte = (int)$ligneActuelle['quantite_commande'];
                $quantiteVaChanger = $cmd['supprimer'] ? ($ancienneQte != 0) : ((max(0, intval($cmd['quantite']))) != $ancienneQte);
                if (empty($boutique_id) && $quantiteVaChanger) {
                    $lignesIgnorees[] = $produit_id ?: '?';
                }

                if ($cmd['supprimer']) {
                    // Ligne retirée de la facture : la quantité vendue/réservée retourne au stock
                    if (!empty($boutique_id)) {
                        $pdo->prepare("UPDATE stock SET quantite = quantite + ? WHERE produit_id = ? AND boutique_id = ?")
                            ->execute([$ancienneQte, $produit_id, $boutique_id]);
                        $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) + ? AS CHAR) WHERE code_produit = ?")
                            ->execute([$ancienneQte, $produit_id]);
                    }
                    $pdo->prepare("DELETE FROM commande WHERE numero_commande = ?")->execute([$cmd['id']]);
                } else {
                    $nouvelleQte = max(0, intval($cmd['quantite']));
                    $delta = $nouvelleQte - $ancienneQte; // >0 : on vend/réserve plus ; <0 : on restitue

                    if ($delta > 0 && !empty($boutique_id)) {
                        $stmtStock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                        $stmtStock->execute([$produit_id, $boutique_id]);
                        $dispo = (int)($stmtStock->fetchColumn() ?: 0);
                        if ($dispo < $delta) {
                            throw new Exception("Stock insuffisant pour augmenter la quantité de ce produit (disponible $dispo, demandé $delta de plus).");
                        }
                    }
                    if ($delta != 0 && !empty($boutique_id)) {
                        $pdo->prepare("UPDATE stock SET quantite = quantite - ? WHERE produit_id = ? AND boutique_id = ?")
                            ->execute([$delta, $produit_id, $boutique_id]);
                        $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) - ? AS CHAR) WHERE code_produit = ?")
                            ->execute([$delta, $produit_id]);
                    }
                    $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                    WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                    WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                    ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                        ->execute([$produit_id]);

                    $montant = $nouvelleQte * $cmd['prix'];
                    $produits_par_lot = max(1, intval($cmd['produits_par_lot'] ?? 1));
                    $prixLotLigne = (isset($cmd['prix_lot']) && $cmd['prix_lot'] !== null && $cmd['prix_lot'] !== '') ? round((float)$cmd['prix_lot'], 2) : null;
                    $pdo->prepare("UPDATE commande SET quantite_commande = ?, prix_commande = ?, prix_lot_ligne = ?, produits_par_lot = ?, montant_commande = ? WHERE numero_commande = ?")
                        ->execute([$nouvelleQte, $cmd['prix'], $prixLotLigne, $produits_par_lot, $montant, $cmd['id']]);
                }
            }

            // ---- AJOUT DE NOUVELLES LIGNES (produits ajoutés pendant la modification) ----
            if (!empty($nouvelles_lignes_data)) {
                // Statut / contact à réutiliser : ceux d'une ligne existante du même
                // bon (pour rester cohérent avec le reste du document). À défaut, on
                // retombe sur un statut de vente standard. Le lot, lui, est
                // configurable indépendamment pour chaque nouvelle ligne (voir
                // achat.php pour le même principe).
                $stmtRef = $pdo->prepare("SELECT statut_id, etat_commande FROM commande WHERE facture_id = ? LIMIT 1");
                $stmtRef->execute([$facture_id]);
                $refLigne = $stmtRef->fetch(PDO::FETCH_ASSOC);
                $statutRef = $refLigne['statut_id'] ?? '012';
                $etatRef = $refLigne['etat_commande'] ?? 'EN ATTENTE';
                $libellesLotValides = ['Boîte', 'Palette', 'Carton', 'Bidon', 'Unité'];
                $numBase = date('dmYHis');

                foreach ($nouvelles_lignes_data as $i => $nl) {
                    $produitId = trim($nl['produit_id'] ?? '');
                    $boutiqueId = trim($nl['boutique_id'] ?? '');
                    $quantite = max(0, intval($nl['quantite'] ?? 0));
                    $prix = floatval($nl['prix'] ?? 0);
                    $produitsParLot = max(1, intval($nl['produits_par_lot'] ?? 1));

                    if ($produitId === '' || $quantite <= 0) {
                        continue;
                    }
                    if ($boutiqueId === '') {
                        $lignesIgnorees[] = $produitId;
                        continue;
                    }

                    // Vérification du stock de la boutique avant ajout
                    $stmtStockNew = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                    $stmtStockNew->execute([$produitId, $boutiqueId]);
                    $dispoNew = $stmtStockNew->fetchColumn();
                    $dispoNew = ($dispoNew === false) ? 0 : (int) $dispoNew;
                    if ($dispoNew < $quantite) {
                        $stmtNomNew = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
                        $stmtNomNew->execute([$produitId]);
                        $nomProdNew = $stmtNomNew->fetchColumn() ?: $produitId;
                        throw new Exception("Stock insuffisant pour « $nomProdNew » : disponible $dispoNew, demandé $quantite.");
                    }

                    // Décrémente le stock de la boutique
                    $pdo->prepare("UPDATE stock SET quantite = quantite - ? WHERE produit_id = ? AND boutique_id = ?")
                        ->execute([$quantite, $produitId, $boutiqueId]);
                    $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) - ? AS CHAR) WHERE code_produit = ?")
                        ->execute([$quantite, $produitId]);
                    $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                    WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                    WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                    ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                        ->execute([$produitId]);

                    // Configuration de lot (optionnelle, comme dans achat.php) : si
                    // l'utilisateur n'a pas configuré de lot pour cette ligne, on reste
                    // sur le comportement "produit simple" (lot_id NULL) tout en
                    // conservant le produits_par_lot saisi sur la ligne (affichage).
                    $lotConfigure = filter_var($nl['lot_configure'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $libelleLot = in_array($nl['libelle_lot'] ?? '', $libellesLotValides, true) ? $nl['libelle_lot'] : 'Unité';
                    $lot_id = null;
                    if ($lotConfigure) {
                        // Un lot est déjà configuré pour ce produit (même libellé) ?
                        // On le réutilise — le lot ne doit être créé qu'une seule fois,
                        // pas à chaque nouvelle vente du même produit.
                        $stmtLotExist = $pdo->prepare("SELECT code_lot FROM lot WHERE produit_id = ? AND libelle = ? AND etat_lot = 'Actif' LIMIT 1");
                        $stmtLotExist->execute([$produitId, $libelleLot]);
                        $lotExistant = $stmtLotExist->fetchColumn();

                        if ($lotExistant) {
                            $lot_id = $lotExistant;
                        } else {
                            $lot_id = 'LOT-' . date('YmdHis') . rand(100, 999) . '-' . $i;
                            $pdo->prepare("INSERT INTO lot (code_lot, libelle, unites_par_lot, produit_id, quantite, etat_lot)
                                          VALUES (?, ?, ?, ?, ?, 'Actif')")
                                ->execute([$lot_id, $libelleLot, $produitsParLot, $produitId, $quantite]);
                        }
                    }

                    $numCmdNew = $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '-ADD';
                    $montantNew = $quantite * $prix;
                    $prixLotLigne = (isset($nl['prix_lot']) && $nl['prix_lot'] !== null && $nl['prix_lot'] !== '') ? round((float)$nl['prix_lot'], 2) : null;

                    $pdo->prepare("
                        INSERT INTO commande
                        (numero_commande, produit_id, lot_id, produits_par_lot, contact_id, facture_id, statut_id,
                         date_commande, heure_commande, prix_achat, prix_commande, prix_lot_ligne, quantite_commande, montant_commande,
                         utilisateur_id, boutique_id, etat_commande)
                        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $numCmdNew, $produitId, $lot_id, $produitsParLot, $fData['contact_id'], $facture_id, $statutRef,
                        $prix, $prix, $prixLotLigne, $quantite, $montantNew,
                        USER_ID, $boutiqueId, $etatRef
                    ]);

                    $lignesAjoutees++;
                }
            }

            // ---- RECALCUL AUTORITAIRE DU MONTANT DE LA FACTURE ----
            // Le total ne doit jamais être décidé par le client : on le recalcule
            // ici à partir des lignes réellement en base (après ajouts/modifs/
            // suppressions), pour que le montant et le reste changent bien à
            // chaque ajout de ligne. La taxe et la remise sont conservées telles
            // qu'enregistrées sur la facture (valeurs absolues), avec repli sur
            // 18% si aucune taxe n'a été fixée (même logique que la génération PDF).
            $stmtTotalHt = $pdo->prepare("SELECT COALESCE(SUM(montant_commande), 0) FROM commande WHERE facture_id = ?");
            $stmtTotalHt->execute([$facture_id]);
            $totalHtActuel = (float) $stmtTotalHt->fetchColumn();

            $taxeVal = (isset($fData['taxe']) && $fData['taxe'] !== null && $fData['taxe'] !== '')
                ? floatval($fData['taxe'])
                : round($totalHtActuel * 0.18, 2);
            $remiseVal = floatval($fData['remise'] ?? 0);
            $nouveauMontantTtc = round($totalHtActuel + $taxeVal - $remiseVal, 2);

            // Le reste est recalculé à partir du nouveau montant et de l'avance
            // saisie par l'utilisateur (le reste envoyé par le client n'est
            // qu'une prévisualisation, la valeur enregistrée fait foi côté serveur).
            $reste = max(0, round($nouveauMontantTtc - $avance, 2));

            $pdo->prepare("UPDATE facture SET avance = ?, reste = ?, montant_ttc = ? WHERE numero_facture = ?")
                ->execute([$avance, $reste, $nouveauMontantTtc, $facture_id]);

            // Une facture déjà validée engage une créance suivie dans solde_contact
            // (ajoutée par validate_facture) : toute variation du reste doit s'y
            // répercuter à l'identique, sinon le solde du client se désynchronise.
            if (strtolower($fData['statut_facture']) === 'validee') {
                $deltaReste = round($reste - $ancienReste, 2);
                if ($deltaReste != 0) {
                    $pdo->prepare("UPDATE contact SET solde_contact = solde_contact + ? WHERE code_contact = ?")
                        ->execute([$deltaReste, $fData['contact_id']]);
                }
            }

            $pdo->commit();
            $message = 'Facture mise à jour.';
            if ($lignesAjoutees > 0) {
                $message = 'Facture mise à jour (' . $lignesAjoutees . ' nouvelle(s) ligne(s) ajoutée(s)).';
            }
            if (!empty($lignesIgnorees)) {
                $message = 'Facture mise à jour. ATTENTION : le stock n\'a pas été ajusté pour ' . count($lignesIgnorees) . ' ligne(s) sans boutique associée (' . implode(', ', $lignesIgnorees) . ') — vérifiez le stock manuellement.';
            }
            echo json_encode(['success' => true, 'message' => $message, 'lignes_non_ajustees' => $lignesIgnorees]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
