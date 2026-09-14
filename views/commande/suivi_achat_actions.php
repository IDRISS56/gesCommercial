<?php
// Extrait de suivi_achat.php (découpage du fichier — voir audit technique) :
// traitement de toutes les actions AJAX/POST (validation, suppression,
// modification de lignes, listes paginées, etc.). Inclus tel quel ; partage
// sa portée avec le fichier appelant.

// ==========================================
// 3. TRAITEMENT DES ACTIONS (AJAX / POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifierCsrfToken();
    $action = $_POST['action'];

    if ($action === 'liste_achats') {
        header('Content-Type: application/json');
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $filtres = [
            'fournisseur' => trim($_POST['fournisseur'] ?? ''),
            'etat' => trim($_POST['etat'] ?? ''),
            'statut' => trim($_POST['statut'] ?? ''),
        ];
        $resultat = getAchatsListe($pdo, $boutiquesAutorisees, $filtres, $page);
        echo json_encode([
            'success' => true,
            'html' => $resultat['html'],
            'pagination' => $resultat['pagination'],
            'total' => $resultat['total'],
        ]);
        exit;
    }

    if ($action === 'produits_par_categorie') {
        header('Content-Type: application/json');
        $categorieId = trim($_POST['categorie_id'] ?? '');
        $boutiqueIdVerif = trim($_POST['boutique_id'] ?? '');
        if (empty($categorieId)) {
            echo json_encode(['success' => false, 'error' => 'Catégorie manquante', 'produits' => []]);
            exit;
        }
        $catsAutoriseesVerif = getCategoriesAutoriseesBoutique($pdo, $_SESSION['role'] ?? null, $boutiqueIdVerif);
        if ($catsAutoriseesVerif !== null && !in_array($categorieId, $catsAutoriseesVerif, true)) {
            echo json_encode(['success' => false, 'error' => "Cette boutique n'est pas autorisée à gérer cette catégorie.", 'produits' => []]);
            exit;
        }
        $stmtProd = $pdo->prepare(
            "SELECT code_produit, titre_produit, prix_fournisseur, etat_produit
             FROM produit
             WHERE categorie_id = ?
             ORDER BY CASE WHEN etat_produit = 'RUPTURE' THEN 1 ELSE 0 END, titre_produit"
        );
        $stmtProd->execute([$categorieId]);
        echo json_encode(['success' => true, 'produits' => $stmtProd->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'validate_facture') {
        header('Content-Type: application/json');
        $id = $_POST['id'] ?? '';
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'ID manquant']); exit; }
        try {
            $pdo->beginTransaction();

            $stmtF = $pdo->prepare("SELECT contact_id, avance, reste, statut_facture FROM facture WHERE numero_facture = ? AND type_facture = 'Fournisseur' FOR UPDATE");
            $stmtF->execute([$id]);
            $f = $stmtF->fetch(PDO::FETCH_ASSOC);
            if (!$f) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'Facture introuvable']); exit; }

            $stmt = $pdo->prepare("UPDATE facture SET statut_facture = 'Validee' WHERE numero_facture = ? AND type_facture = 'Fournisseur' AND statut_facture <> 'Validee'");
            $stmt->execute([$id]);
            $factureTouchee = $stmt->rowCount() > 0;

            if ($factureTouchee) {
                // Recharge le solde du fournisseur, exactement comme le fichier vente
                // le fait déjà pour le solde client lors de la validation d'une facture :
                // on ajoute le montant dû (reste) au solde_contact du fournisseur, en
                // tenant compte d'une éventuelle avance déjà versée (solde_contact < 0
                // => le fournisseur nous doit / avance disponible, cf. convention plus
                // haut : solde_contact > 0 => on doit ce montant au fournisseur).
                $resteInitial = floatval($f['reste']);
                if ($resteInitial != 0) {
                    $stmtSoldeF = $pdo->prepare("SELECT solde_contact FROM contact WHERE code_contact = ? FOR UPDATE");
                    $stmtSoldeF->execute([$f['contact_id']]);
                    $soldeAvantF = floatval($stmtSoldeF->fetchColumn());
                    $avanceDispoF = max(0, -$soldeAvantF);
                    $avanceUtilisee = min($avanceDispoF, $resteInitial);
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
            }

            // Réception physique confirmée : on crédite le stock des lignes qui
            // étaient encore "EN ATTENTE" (celles déjà "VALIDEE" ont déjà été
            // créditées à la commande et ne doivent pas l'être une seconde fois).
            $stmtLignes = $pdo->prepare("SELECT numero_commande, produit_id, boutique_id, quantite_commande FROM commande WHERE facture_id = ? AND etat_commande = 'EN ATTENTE'");
            $stmtLignes->execute([$id]);
            $lignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lignes as $l) {
                if (!empty($l['boutique_id'])) {
                    $pdo->prepare("INSERT INTO stock (produit_id, boutique_id, quantite, stock_alerte)
                                  VALUES (?, ?, ?, 10)
                                  ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)")
                        ->execute([$l['produit_id'], $l['boutique_id'], $l['quantite_commande']]);
                }
                $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) + ? AS CHAR) WHERE code_produit = ?")
                    ->execute([$l['quantite_commande'], $l['produit_id']]);
                $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                    ->execute([$l['produit_id']]);
                $pdo->prepare("UPDATE commande SET etat_commande = 'VALIDEE' WHERE numero_commande = ?")
                    ->execute([$l['numero_commande']]);
            }

            $pdo->commit();
            echo json_encode(['success' => $factureTouchee, 'message' => 'Achat validé, stock réceptionné']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_facture') {
        header('Content-Type: application/json');
        $id = $_POST['id'] ?? '';
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'ID manquant']); exit; }
        try {
            $pdo->beginTransaction();

            // Réajuster le stock : on retire ce que cet achat avait fait entrer,
            // puisque l'achat lui-même est annulé/supprimé. Seules les lignes déjà
            // "VALIDEE" (donc réellement créditées en stock) sont concernées ; les
            // lignes encore "EN ATTENTE" n'ont jamais touché le stock.
            $stmtLignes = $pdo->prepare("SELECT produit_id, boutique_id, quantite_commande FROM commande WHERE facture_id = ? AND etat_commande = 'VALIDEE'");
            $stmtLignes->execute([$id]);
            foreach ($stmtLignes->fetchAll(PDO::FETCH_ASSOC) as $l) {
                if (!empty($l['boutique_id'])) {
                    $stmtLock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                    $stmtLock->execute([$l['produit_id'], $l['boutique_id']]);
                    $stockActuel = $stmtLock->fetchColumn();
                    $stockActuel = ($stockActuel === false) ? 0 : (int) $stockActuel;
                    if ($stockActuel < $l['quantite_commande']) {
                        throw new Exception(
                            "Impossible de supprimer cet achat : le produit {$l['produit_id']} a déjà été partiellement " .
                            "vendu/sorti depuis sa réception (stock actuel $stockActuel, à retirer {$l['quantite_commande']}). " .
                            "Faites d'abord un ajustement de stock si nécessaire."
                        );
                    }
                    $pdo->prepare("UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                        ->execute([$stockActuel - $l['quantite_commande'], $l['produit_id'], $l['boutique_id']]);
                }
                $pdo->prepare("UPDATE produit SET stock_produit = CAST(GREATEST(0, CAST(COALESCE(stock_produit,0) AS SIGNED) - ?) AS CHAR) WHERE code_produit = ?")
                    ->execute([$l['quantite_commande'], $l['produit_id']]);
            }

            $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM facture WHERE numero_facture = ? AND type_facture = 'Fournisseur'");
            $stmt->execute([$id]);
            $pdo->commit();
            echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Achat supprimé, stock réajusté']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_details') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact FROM facture f LEFT JOIN contact c ON f.contact_id = c.code_contact WHERE f.numero_facture = ? AND f.type_facture = 'Fournisseur'");
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$facture) { echo json_encode(['error' => 'Achat introuvable']); exit; }
        $stmt2 = $pdo->prepare("SELECT c.*, p.titre_produit, l.libelle AS libelle_lot FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit LEFT JOIN lot l ON c.lot_id = l.code_lot WHERE c.facture_id = ?");
        $stmt2->execute([$id]);
        $commandesDetail = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Sécurité : un utilisateur ne peut ouvrir que les achats d'une
        // boutique à laquelle il a accès (même si l'id est deviné/forgé).
        $boutiquesLignesAchat = array_unique(array_filter(array_column($commandesDetail, 'boutique_id')));
        if (!empty($boutiquesLignesAchat) && !array_intersect($boutiquesLignesAchat, $boutiquesAutorisees)) {
            echo json_encode(['error' => 'Accès refusé : cet achat appartient à une autre boutique.']); exit;
        }

        $isLocked = in_array(strtolower($facture['etat_facture'] ?? ''), ['payee', 'payee cash'])
            && strtolower($facture['statut_facture'] ?? '') === 'validee';

        // Boutique de réception de cet achat (pour associer correctement les
        // nouvelles lignes ajoutées lors de la modification).
        $boutiqueAchat = $commandesDetail[0]['boutique_id'] ?? null;
        if (empty($boutiqueAchat) && !empty($facture['utilisateur_id'])) {
            $stmtUb = $pdo->prepare("SELECT boutique_id FROM utilisateur WHERE id = ?");
            $stmtUb->execute([$facture['utilisateur_id']]);
            $boutiqueAchat = $stmtUb->fetchColumn() ?: null;
        }

        echo json_encode([
            'facture' => $facture,
            'commandes' => $commandesDetail,
            'is_locked' => $isLocked,
            'boutique_id' => $boutiqueAchat
        ]);
        exit;
    }

    if ($action === 'check_stock_produit') {
        // Simple lecture informative du stock actuel d'un produit dans une
        // boutique (un achat alimente le stock, il n'y a donc rien à bloquer ici,
        // contrairement à une vente).
        $produitId = trim($_POST['produit_id'] ?? '');
        $boutiqueId = trim($_POST['boutique_id'] ?? '');
        if ($boutiqueId !== '' && !in_array($boutiqueId, $boutiquesAutorisees, true)) $boutiqueId = '';
        $response = ['success' => false, 'disponible' => 0, 'prix' => 0, 'titre' => '', 'lots' => [], 'saisie_par_carton' => 0];
        if ($produitId !== '') {
            $dispo = 0;
            if ($boutiqueId !== '') {
                $stmtS = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ?");
                $stmtS->execute([$produitId, $boutiqueId]);
                $d = $stmtS->fetchColumn();
                $dispo = ($d !== false) ? (int) $d : 0;
            }
            $stmtP = $pdo->prepare("SELECT titre_produit, prix_fournisseur, saisie_par_carton FROM produit WHERE code_produit = ?");
            $stmtP->execute([$produitId]);
            $rowP = $stmtP->fetch(PDO::FETCH_ASSOC);
            if ($rowP) {
                $response['success'] = true;
                $response['disponible'] = $dispo;
                $response['prix'] = (float) $rowP['prix_fournisseur'];
                $response['titre'] = $rowP['titre_produit'];
                $response['saisie_par_carton'] = (int) $rowP['saisie_par_carton'];

                // Lots catalogue (menu "Configuration des lots") : structure fixe,
                // seul cout_lot sert à suggérer le prix d'achat de la ligne.
                $stmtLots = $pdo->prepare("SELECT libelle, unites_par_lot, cout_lot FROM lot WHERE produit_id = ? AND etat_lot = 'Actif'");
                $stmtLots->execute([$produitId]);
                $response['lots'] = $stmtLots->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        echo json_encode($response);
        exit;
    }

    // Mise à jour des lignes d'achat uniquement (quantité, prix d'achat).
    // Le règlement (avance/reste/état) n'est PAS modifiable ici : il est géré
    // par votre interface de règlement fournisseur dédiée.
    if ($action === 'update_achat') {
        $facture_id = $_POST['facture_id'];
        $checkStmt = $pdo->prepare("SELECT contact_id, etat_facture, statut_facture FROM facture WHERE numero_facture = ? AND type_facture = 'Fournisseur'");
        $checkStmt->execute([$facture_id]);
        $fData = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fData) { echo json_encode(['success' => false, 'error' => 'Achat introuvable']); exit; }
        $isLocked = (in_array(strtolower($fData['etat_facture']), ['payee', 'payee cash'])
            && strtolower($fData['statut_facture']) === 'validee');
        if ($isLocked) {
            echo json_encode(['success' => false, 'error' => 'Achat déjà réglé intégralement, modification impossible.']);
            exit;
        }
        $commandes_data = json_decode($_POST['commandes'], true);
        $nouvelles_lignes_data = json_decode($_POST['nouvelles_lignes'] ?? '[]', true) ?: [];
        $lignesAjoutees = 0;
        try {
            $pdo->beginTransaction();
            $nouveauTotal = 0;
            foreach ($commandes_data as $cmd) {
                // Réajuster le stock selon la différence de quantité
                $stmtOld = $pdo->prepare("SELECT produit_id, boutique_id, quantite_commande, etat_commande FROM commande WHERE numero_commande = ?");
                $stmtOld->execute([$cmd['id']]);
                $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
                // Le stock n'a été crédité que pour les lignes déjà "VALIDEE"
                // (réceptionnées) ; une ligne encore "EN ATTENTE" n'a jamais
                // touché le stock, donc on ne doit pas y toucher ici non plus.
                $stockDejaCredite = $old && ($old['etat_commande'] === 'VALIDEE');

                if ($cmd['supprimer']) {
                    if ($old) {
                        if ($stockDejaCredite && !empty($old['boutique_id'])) {
                            $stmtLock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                            $stmtLock->execute([$old['produit_id'], $old['boutique_id']]);
                            $stockActuel = $stmtLock->fetchColumn();
                            $stockActuel = ($stockActuel === false) ? 0 : (int) $stockActuel;
                            if ($stockActuel < $old['quantite_commande']) {
                                throw new Exception(
                                    "Impossible de supprimer la ligne {$old['produit_id']} : stock actuel " .
                                    "$stockActuel, insuffisant pour retirer {$old['quantite_commande']}."
                                );
                            }
                            $pdo->prepare("UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                                ->execute([$stockActuel - $old['quantite_commande'], $old['produit_id'], $old['boutique_id']]);
                        }
                        if ($stockDejaCredite) {
                            $pdo->prepare("UPDATE produit SET stock_produit = CAST(GREATEST(0, CAST(COALESCE(stock_produit,0) AS SIGNED) - ?) AS CHAR) WHERE code_produit = ?")
                                ->execute([$old['quantite_commande'], $old['produit_id']]);
                        }
                    }
                    $pdo->prepare("DELETE FROM commande WHERE numero_commande = ?")->execute([$cmd['id']]);
                } else {
                    $montant = $cmd['quantite'] * $cmd['prix'];
                    $nouveauTotal += $montant;
                    if ($old) {
                        $diff = $cmd['quantite'] - $old['quantite_commande'];
                        if ($stockDejaCredite && $diff != 0 && !empty($old['boutique_id'])) {
                            $stmtLock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                            $stmtLock->execute([$old['produit_id'], $old['boutique_id']]);
                            $stockActuel = $stmtLock->fetchColumn();
                            $stockActuel = ($stockActuel === false) ? 0 : (int) $stockActuel;
                            $stockCible = $stockActuel + $diff;
                            if ($stockCible < 0) {
                                throw new Exception(
                                    "Impossible de réduire la quantité de {$old['produit_id']} : stock actuel " .
                                    "$stockActuel, réduction demandée " . abs($diff) . "."
                                );
                            }
                            $pdo->prepare("UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                                ->execute([$stockCible, $old['produit_id'], $old['boutique_id']]);
                        }
                        if ($stockDejaCredite && $diff != 0) {
                            $pdo->prepare("UPDATE produit SET stock_produit = CAST(GREATEST(0, CAST(COALESCE(stock_produit,0) AS SIGNED) + ?) AS CHAR) WHERE code_produit = ?")
                                ->execute([$diff, $old['produit_id']]);
                        }
                    }
                    $prixLotLigne = (isset($cmd['prix_lot']) && $cmd['prix_lot'] !== null && $cmd['prix_lot'] !== '') ? round((float)$cmd['prix_lot'], 2) : null;
                    $pdo->prepare("UPDATE commande SET quantite_commande = ?, prix_achat = ?, prix_lot_ligne = ?, montant_commande = ? WHERE numero_commande = ?")
                        ->execute([$cmd['quantite'], $cmd['prix'], $prixLotLigne, $montant, $cmd['id']]);
                }
            }

            // ---- AJOUT DE NOUVELLES LIGNES (produits ajoutés pendant la modification) ----
            if (!empty($nouvelles_lignes_data)) {
                $factureEstValidee = (strtolower($fData['statut_facture'] ?? '') === 'validee');
                // Statut à réutiliser : celui d'une ligne existante du même achat,
                // pour rester cohérent avec le reste du document. Le lot, lui, est
                // configurable indépendamment pour chaque nouvelle ligne (voir
                // achat.php pour le même principe).
                $stmtRef = $pdo->prepare("SELECT statut_id FROM commande WHERE facture_id = ? LIMIT 1");
                $stmtRef->execute([$facture_id]);
                $statutRef = $stmtRef->fetchColumn() ?: '011';
                $libellesLotValides = ['Boîte', 'Palette', 'Carton', 'Bidon', 'Unité'];
                $numBase = date('dmYHis');

                foreach ($nouvelles_lignes_data as $i => $nl) {
                    $produitId = trim($nl['produit_id'] ?? '');
                    $boutiqueId = trim($nl['boutique_id'] ?? '');
                    $quantite = max(0, intval($nl['quantite'] ?? 0));
                    $prix = floatval($nl['prix'] ?? 0);
                    if ($produitId === '' || $quantite <= 0) {
                        continue;
                    }

                    // Catégories autorisées par boutique (restriction optionnelle) :
                    // vérification autoritaire, indépendante du filtrage déjà fait
                    // côté client sur le sélecteur de catégorie.
                    if (!empty($boutiqueId)) {
                        $catsAutoriseesNewLigneAchat = getCategoriesAutoriseesBoutique($pdo, $_SESSION['role'] ?? null, $boutiqueId);
                        if ($catsAutoriseesNewLigneAchat !== null) {
                            $stmtCatProdNewAchat = $pdo->prepare("SELECT categorie_id FROM produit WHERE code_produit = ?");
                            $stmtCatProdNewAchat->execute([$produitId]);
                            $categorieProdNewAchat = $stmtCatProdNewAchat->fetchColumn();
                            if (!in_array($categorieProdNewAchat, $catsAutoriseesNewLigneAchat, true)) {
                                throw new Exception("Cette boutique n'est pas autorisée à gérer la catégorie de ce produit.");
                            }
                        }
                    }

                    // Configuration de lot (optionnelle, comme dans achat.php) : si
                    // l'utilisateur n'a pas configuré de lot pour cette ligne, on reste
                    // sur le comportement "produit simple" (lot_id NULL, produits_par_lot = 1).
                    $lotConfigure = filter_var($nl['lot_configure'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $unitesParLot = max(2, intval($nl['unites_par_lot'] ?? 2));
                    $libelleLot = in_array($nl['libelle_lot'] ?? '', $libellesLotValides, true) ? $nl['libelle_lot'] : 'Unité';

                    $lot_id = null;
                    $produits_par_lot = 1;
                    if ($lotConfigure) {
                        // Un lot est déjà configuré pour ce produit (même libellé) ?
                        // On le réutilise — le lot ne doit être créé qu'une seule fois,
                        // pas à chaque nouvel achat du même produit.
                        $stmtLotExist = $pdo->prepare("SELECT code_lot, unites_par_lot FROM lot WHERE produit_id = ? AND libelle = ? AND etat_lot = 'Actif' LIMIT 1");
                        $stmtLotExist->execute([$produitId, $libelleLot]);
                        $lotExistant = $stmtLotExist->fetch(PDO::FETCH_ASSOC);

                        if ($lotExistant) {
                            $lot_id = $lotExistant['code_lot'];
                            $produits_par_lot = (int) $lotExistant['unites_par_lot'];
                        } else {
                            $lot_id = 'LOT-' . date('YmdHis') . rand(100, 999) . '-' . $i;
                            $produits_par_lot = $unitesParLot;
                            $pdo->prepare("INSERT INTO lot (code_lot, libelle, unites_par_lot, produit_id, quantite, etat_lot)
                                          VALUES (?, ?, ?, ?, ?, 'Actif')")
                                ->execute([$lot_id, $libelleLot, $unitesParLot, $produitId, $quantite]);
                        }
                    }

                    $montantNew = $quantite * $prix;
                    $nouveauTotal += $montantNew;
                    // Si l'achat est déjà validé (marchandise déjà réceptionnée), la
                    // nouvelle ligne est considérée reçue immédiatement (comme ses
                    // lignes sœurs déjà VALIDEE) et son stock est crédité tout de
                    // suite — sinon elle ne serait jamais créditée automatiquement,
                    // la validation globale de l'achat ne s'exécutant qu'une fois.
                    // Sinon, elle reste EN ATTENTE comme les autres lignes du bon,
                    // en attendant la validation/réception de l'ensemble.
                    $etatLigne = $factureEstValidee ? 'VALIDEE' : 'EN ATTENTE';
                    $numCmdNew = $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '-ADD';
                    $prixLotLigne = (isset($nl['prix_lot']) && $nl['prix_lot'] !== null && $nl['prix_lot'] !== '') ? round((float)$nl['prix_lot'], 2) : null;

                    $pdo->prepare("
                        INSERT INTO commande
                        (numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id,
                         date_commande, heure_commande, prix_achat, prix_commande, prix_lot_ligne, quantite_commande, produits_par_lot,
                         montant_commande, utilisateur_id, boutique_id, etat_commande)
                        VALUES (?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $numCmdNew, $produitId, $lot_id, $fData['contact_id'], $facture_id, $statutRef,
                        $prix, $prix, $prixLotLigne, $quantite, $produits_par_lot, $montantNew,
                        ($_SESSION['user_id'] ?? null), $boutiqueId, $etatLigne
                    ]);

                    if ($factureEstValidee && !empty($boutiqueId)) {
                        $pdo->prepare("INSERT INTO stock (produit_id, boutique_id, quantite, stock_alerte)
                                      VALUES (?, ?, ?, 10)
                                      ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)")
                            ->execute([$produitId, $boutiqueId, $quantite]);
                        $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) + ? AS CHAR) WHERE code_produit = ?")
                            ->execute([$quantite, $produitId]);
                        $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                        WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                        WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                        ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                            ->execute([$produitId]);
                    }

                    $lignesAjoutees++;
                }
            }
            // Recalcule le montant de la facture ; le reste suit automatiquement
            // (avance inchangée, gérée par l'interface de règlement).
            $stmtF = $pdo->prepare("SELECT avance FROM facture WHERE numero_facture = ?");
            $stmtF->execute([$facture_id]);
            $avanceActuelle = floatval($stmtF->fetchColumn());
            $nouveauReste = max(0, $nouveauTotal - $avanceActuelle);
            $pdo->prepare("UPDATE facture SET montant_ht = ?, montant_ttc = ?, reste = ? WHERE numero_facture = ?")
                ->execute([$nouveauTotal, $nouveauTotal, $nouveauReste, $facture_id]);

            $pdo->commit();
            $message = $lignesAjoutees > 0
                ? 'Achat mis à jour (' . $lignesAjoutees . ' nouvelle(s) ligne(s) ajoutée(s)).'
                : 'Achat mis à jour, stock ajusté';
            echo json_encode(['success' => true, 'message' => $message]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}