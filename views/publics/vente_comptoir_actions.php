<?php
// Extrait de vente_comptoir.php (découpage du fichier — voir audit technique) :
// traitement de toutes les actions AJAX/POST de l'écran de vente comptoir
// (recherche produit, panier, encaissement, etc.). Inclus tel quel ; partage
// sa portée avec le fichier appelant.

// - TRAITEMENT AJAX - TOUT EN POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        switch ($action) {

            // ===== CRÉER UN BON DE COMMANDE (EN ATTENTE) =====
            // Contrairement à la vente comptoir (cash, immédiate), le bon de commande :
            //  - n'exige AUCUN paiement (avance optionnelle, 0 par défaut) ;
            //  - NE TOUCHE PAS le stock (ni produit, ni lot) à la création : le stock
            //    n'est vérifié/réservé qu'à la validation du bon (views/commande/vente.php,
            //    action validate_facture — cf. condition reference_id IS NULL) ;
            //  - crée une vraie ligne `facture` (categorie_facture='Bon', statut 'En
            //    attente') afin d'apparaître dans le suivi existant (/commande/vente),
            //    exactement comme un bon transformé depuis un devis.
            case 'creer_bon_attente':
                $data = $_POST;
                $token = $data['csrf_token'] ?? '';
                if ($token !== $csrf_token) {
                    echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
                    exit;
                }

                $panier = json_decode($data['panier'] ?? '[]', true) ?: [];
                if (empty($panier)) throw new Exception('Le panier est vide.');

                // Client et boutique : ceux choisis dans les sélecteurs du panier,
                // sinon le client générique comptoir et la boutique connectée
                // (find-or-create pour le client comptoir, comme pour la vente cash).
                $client_id = trim($data['client_id'] ?? '') ?: CLIENT_COMPTOIR_CODE;
                $boutique_id = trim($data['boutique_id'] ?? '') ?: USER_BOUTIQUE;
                // Sécurité : un utilisateur ne peut enregistrer une vente que pour
                // une boutique à laquelle il a accès, quoi qu'il envoie côté client.
                if (!in_array($boutique_id, $boutiquesAutorisees, true)) $boutique_id = USER_BOUTIQUE;
                $stmtCheckClient = $pdo->prepare("SELECT code_contact FROM contact WHERE code_contact = ?");
                $stmtCheckClient->execute([$client_id]);
                if (!$stmtCheckClient->fetchColumn()) {
                    if ($client_id === CLIENT_COMPTOIR_CODE) {
                        $pdo->prepare("INSERT INTO contact(code_contact, nom_prenom_contact, telephone_contact, email_contact, type_contact, statut_contact, solde_contact, solde_maximum, etat_contact) VALUES (?, 'Client comptoir', '-', '-', 'Client', 'Particulier', 0, 0, 'Actif')")
                            ->execute([$client_id]);
                    } else {
                        throw new Exception('Client introuvable.');
                    }
                }

                $tax_rate = floatval($data['taux_tva'] ?? 0);
                $discount_rate = floatval($data['taux_remise'] ?? 0);
                $lotsData = json_decode($data['lots'] ?? '[]', true) ?: [];

                $montantHT = 0;
                foreach ($panier as $item) {
                    $montantHT += floatval($item['montant'] ?? ($item['prix'] * $item['qte']));
                }
                $taxe = round($montantHT * $tax_rate / 100, 2);
                $remise = round($montantHT * $discount_rate / 100, 2);
                $montantTTC = round($montantHT + $taxe - $remise, 2);

                // Acompte optionnel — jamais obligatoire pour créer le bon.
                $avance = max(0, min(floatval($data['avance'] ?? 0), $montantTTC));
                $reste = round($montantTTC - $avance, 2);
                if ($avance <= 0) {
                    $etatFacture = 'Impayee';
                } elseif ($reste > 0) {
                    $etatFacture = 'Partielle';
                } else {
                    $etatFacture = 'Payee';
                }

                $numBon = 'BON-' . date('Ymd') . '-' . str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);

                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO facture(numero_facture, titre_facture, type_facture, categorie_facture, date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, contact_id, utilisateur_id, etat_facture, statut_facture, reference_id)
                                   VALUES (?, ?, 'Client', 'Bon', CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'En attente', NULL)")
                        ->execute([$numBon, 'Bon de commande ' . $numBon, $montantHT, $taxe, $remise, $montantTTC, $avance, $reste, $client_id, USER_ID, $etatFacture]);

                    // Lignes de commande — AUCUNE vérification/mise à jour de stock ni de
                    // lot ici : c'est repoussé à la validation du bon (validate_facture
                    // dans commande/vente.php), qui reconnaît ce cas via reference_id NULL.
                    $numBase = date('dmYHis');
                    foreach ($panier as $i => $ligne) {
                        $numCmd = $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '-DOC';
                        $prix = floatval($ligne['prix'] ?? 0);
                        $qte = intval($ligne['qte'] ?? 1);
                        $montant = floatval($ligne['montant'] ?? ($prix * $qte));
                        $prix_achat = floatval($ligne['prix_achat'] ?? $prix);
                        $prixLotLigne = (isset($ligne['prix_lot']) && $ligne['prix_lot'] !== null && $ligne['prix_lot'] !== '') ? round((float)$ligne['prix_lot'], 2) : null;
                        $code_prod = $ligne['code'] ?? $ligne['product_id'];
                        // Pas de lot réel créé ici (aucune écriture de stock/lot n'est
                        // faite avant la validation du bon) : on retient seulement le
                        // nombre d'unités par lot choisi, pour l'affichage (X Boîte(s)...).
                        $lot_id = null;
                        $lotConfigure = filter_var($ligne['lot_configure'] ?? false, FILTER_VALIDATE_BOOLEAN);
                        $produits_par_lot = $lotConfigure ? max(2, intval($ligne['unites_par_lot'] ?? 2)) : 1;

                        $stmtCmd = $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, prix_lot_ligne, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                                                  VALUES (?, ?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?, 'VALIDEE')");
                        $stmtCmd->execute([$numCmd, $code_prod, $lot_id, $client_id, $numBon,
                                           $prix_achat, $prix, $prixLotLigne, $qte, $produits_par_lot, $montant, USER_ID, $boutique_id]);
                    }

                    $pdo->commit();
                    echo json_encode([
                        'success' => true,
                        'message' => 'Bon de commande ' . $numBon . ' créé (en attente). Le stock sera vérifié à la validation.',
                        'document' => $numBon,
                        'etat' => $etatFacture,
                        'lots' => $lotsData,
                        'totaux' => ['ht' => $montantHT, 'taxe' => $taxe, 'remise' => $remise, 'ttc' => $montantTTC, 'reste' => $reste, 'avance' => $avance]
                    ]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                exit;

            // ===== CHARGER LES CATÉGORIES =====
            case 'load_categories':
                $cats = $pdo->query("SELECT titre_categorie FROM categorie WHERE etat_categorie = 'ACTIF' ORDER BY titre_categorie ASC")->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode(['success' => true, 'data' => $cats, 'has_categorie' => count($cats) > 0]);
                exit;

            // ===== CHARGER TOUS LES CLIENTS =====
            case 'load_all_clients':
                $sql = "SELECT c.code_contact, c.nom_prenom_contact, c.telephone_contact,
                        COALESCE(c.type_contact, 'Client') as type_contact,
                        COALESCE(c.statut_contact, 'Particulier') as statut_contact
                        FROM contact c
                        WHERE c.type_contact = 'Client' AND c.etat_contact = 'Actif'
                        ORDER BY c.nom_prenom_contact ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $clients]);
                exit;

            // ===== CLIENTS POUR LE SELECTPICKER =====
            case 'get_clients':
                $q = trim($_POST['q'] ?? '');
                $sql = "SELECT c.code_contact, c.nom_prenom_contact, c.telephone_contact,
                        COALESCE(c.type_contact, 'Client') as type_contact,
                        COALESCE(c.statut_contact, 'Particulier') as statut_contact
                        FROM contact c
                        WHERE c.type_contact = 'Client' AND c.etat_contact = 'Actif'";
                $params = [];
                if ($q) {
                    $sql .= " AND (c.nom_prenom_contact LIKE ? OR c.telephone_contact LIKE ? OR c.code_contact LIKE ?)";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                }
                $sql .= " ORDER BY c.nom_prenom_contact ASC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['success' => true, 'clients' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                exit;

            // ===== RECHERCHER CLIENTS =====
            case 'search_customers':
                $q = trim($_POST['q'] ?? '');
                $sql = "SELECT c.code_contact, c.nom_prenom_contact, c.telephone_contact,
                        COALESCE(c.type_contact, 'Client') as type_contact,
                        COALESCE(c.statut_contact, 'Particulier') as statut_contact
                        FROM contact c
                        WHERE c.type_contact = 'Client' AND c.etat_contact = 'Actif'";
                $params = [];
                if ($q) {
                    $sql .= " AND (c.nom_prenom_contact LIKE ? OR c.telephone_contact LIKE ? OR c.code_contact LIKE ?)";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                }
                $sql .= " ORDER BY c.nom_prenom_contact ASC LIMIT 20";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $clients]);
                exit;

            // ===== CHARGER PRODUITS =====
            case 'get_products':
            case 'search_products':
                $q = trim($_POST['q'] ?? '');
                $cat = $_POST['categorie'] ?? 'Tous';
                // Boutique dont on affiche le stock : celle choisie dans le sélecteur,
                // sinon la boutique de l'utilisateur connecté par défaut.
                $boutiqueAffichage = trim($_POST['boutique_id'] ?? '') ?: USER_BOUTIQUE;
                if (!in_array($boutiqueAffichage, $boutiquesAutorisees, true)) $boutiqueAffichage = USER_BOUTIQUE;
                // Le stock affiché doit être celui de la boutique choisie uniquement
                // (pas le stock global du produit) : si aucune ligne de stock n'existe
                // pour cette boutique, on affiche 0 — sauf si aucune boutique n'est
                // disponible, auquel cas on retombe sur le stock global.
                $stockExpr = !empty($boutiqueAffichage) ? "COALESCE(sb.quantite, 0)" : "CAST(p.stock_produit AS SIGNED)";
                $sql = "SELECT p.code_produit, p.titre_produit, p.stock_produit, p.prix_produit, p.prix_fournisseur,
                        p.categorie_id, p.etat_produit, p.saisie_par_carton,
                        $stockExpr as stock,
                        COALESCE(c.titre_categorie, 'Autre') as categorie
                        FROM produit p
                        LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
                        LEFT JOIN stock sb ON sb.produit_id = p.code_produit AND sb.boutique_id = ?
                        WHERE p.etat_produit != 'RUPTURE'";
                $params = [$boutiqueAffichage];
                if ($cat !== 'Tous') {
                    $sql .= " AND c.titre_categorie = ?";
                    $params[] = $cat;
                }
                if ($q) {
                    $sql .= " AND (p.titre_produit LIKE ? OR p.code_produit LIKE ?)";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                }
                $sql .= " ORDER BY p.titre_produit ASC LIMIT 80";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Lots catalogue (menu "Configuration des lots") des produits renvoyés :
                // structure fixe (libelle + unites_par_lot), seul prix_lot est modifiable
                // au moment de la vente.
                if ($products) {
                    $codes = array_column($products, 'code_produit');
                    $in = implode(',', array_fill(0, count($codes), '?'));
                    $stmtLots = $pdo->prepare("SELECT produit_id, libelle, unites_par_lot, prix_lot, cout_lot FROM lot WHERE etat_lot = 'Actif' AND produit_id IN ($in)");
                    $stmtLots->execute($codes);
                    $lotsParProduit = [];
                    foreach ($stmtLots->fetchAll(PDO::FETCH_ASSOC) as $l) {
                        $lotsParProduit[$l['produit_id']][] = [
                            'libelle' => $l['libelle'],
                            'unites_par_lot' => (int) $l['unites_par_lot'],
                            'prix_lot' => $l['prix_lot'] !== null ? (float) $l['prix_lot'] : null,
                            'cout_lot' => $l['cout_lot'] !== null ? (float) $l['cout_lot'] : null,
                        ];
                    }
                    foreach ($products as &$p) {
                        $p['lots'] = $lotsParProduit[$p['code_produit']] ?? [];
                    }
                    unset($p);
                }

                // Tranches de prix ACTIVES (dégressif par quantité) : tableau vide si aucune.
                $products = joindreTranches($pdo, $products);

                echo json_encode(['success' => true, 'products' => $products]);
                exit;

            // ===== CRÉER CLIENT =====
            case 'create_customer':
                $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
                $token = $data['csrf_token'] ?? '';
                if ($token !== $csrf_token) {
                    echo json_encode(['success' => false, 'message' => 'Token invalide']);
                    exit;
                }
                $nom = trim($data['nom'] ?? '');
                if (!$nom) {
                    echo json_encode(['success' => false, 'message' => 'Nom requis']);
                    exit;
                }
                $numClient = 'CT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO contact (code_contact, nom_prenom_contact, telephone_contact, email_contact, type_contact, statut_contact, adresse_contact, etat_contact) VALUES (?, ?, ?, ?, 'Client', ?, ?, 'Actif')");
                $stmt->execute([$numClient, $nom, $data['tel'] ?? '', $data['email'] ?? '', $data['statut'] ?? 'Particulier', $data['adresse'] ?? '']);
                echo json_encode(['success' => true, 'code' => $numClient, 'nom' => $nom]);
                exit;

            // ===== VALIDER VENTE =====
            case 'valider_vente':
                $data = $_POST;
                $token = $data['csrf_token'] ?? '';
                if ($token !== $csrf_token) {
                    echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
                    exit;
                }

                // L'encaissement (vente cash immédiate) exige une caisse ouverte —
                // contrairement au bon de commande, qui peut être créé sans caisse.
                if (!defined('CAISSE_ID')) {
                    throw new Exception("Aucune caisse n'est ouverte. Demandez au caissier d'ouvrir sa journée pour pouvoir encaisser, ou créez un bon de commande en attente.");
                }

                $panier = json_decode($data['panier'] ?? '[]', true) ?: [];
                if (empty($panier)) throw new Exception('Le panier est vide.');

                // Client et boutique : ceux choisis dans les sélecteurs du panier ;
                // à défaut, on retombe sur le client comptoir générique et la boutique
                // de l'utilisateur connecté (find-or-create pour le client comptoir).
                $boutique_id = trim($data['boutique_id'] ?? '') ?: USER_BOUTIQUE;
                if (!in_array($boutique_id, $boutiquesAutorisees, true)) $boutique_id = USER_BOUTIQUE;
                $client_id = trim($data['client_id'] ?? '') ?: CLIENT_COMPTOIR_CODE;
                $stmtCheckClient = $pdo->prepare("SELECT code_contact FROM contact WHERE code_contact = ?");
                $stmtCheckClient->execute([$client_id]);
                if (!$stmtCheckClient->fetchColumn()) {
                    if ($client_id === CLIENT_COMPTOIR_CODE) {
                        $pdo->prepare("INSERT INTO contact(code_contact, nom_prenom_contact, telephone_contact, email_contact, type_contact, statut_contact, solde_contact, solde_maximum, etat_contact) VALUES (?, 'Client comptoir', '-', '-', 'Client', 'Particulier', 0, 0, 'Actif')")
                            ->execute([$client_id]);
                    } else {
                        throw new Exception('Client introuvable.');
                    }
                }

                // Vente comptoir = paiement cash uniquement, aucun crédit ni bon en attente.
                $mode_reglement = 'Espèce';
                $amount_paid = floatval($data['avance'] ?? 0);
                $tax_rate = floatval($data['taux_tva'] ?? 0);
                $discount_rate = floatval($data['taux_remise'] ?? 0);

                // Récupération des données de lots
                $lotsData = json_decode($data['lots'] ?? '[]', true) ?: [];

                $montantHT = 0;
                foreach ($panier as $item) {
                    $montantHT += floatval($item['montant'] ?? ($item['prix'] * $item['qte']));
                }
                $taxe = round($montantHT * $tax_rate / 100, 2);
                $remise = round($montantHT * $discount_rate / 100, 2);
                $montantTTC = round($montantHT + $taxe - $remise, 2);

                // ===== PAIEMENT CASH INTÉGRAL OBLIGATOIRE =====
                // La vente comptoir ne gère plus les bons / crédits : le montant reçu
                // doit couvrir le total (le devis + transformation en bon de commande
                // prend maintenant en charge les ventes à crédit).
                if ($amount_paid < $montantTTC) {
                    throw new Exception('Paiement insuffisant : la vente comptoir se règle intégralement en espèces (reçu ' . $amount_paid . ', dû ' . $montantTTC . ').');
                }
                // Seul le montant dû entre en caisse et sur la facture ; le surplus
                // éventuel repart en monnaie rendue au client (jamais en caisse).
                $avance = $montantTTC;
                $reste = 0;
                $etatFacture = 'Payee';
                $statutFacture = 'Validee';
                $categorieDocument = 'Ticket';
                $titreDocument = 'Ticket de caisse';

                // Numéro unique du ticket (aucune facture n'est créée pour la vente
                // comptoir : elle est réglée intégralement en cash, le ticket suffit
                // comme justificatif ; ce numéro sert uniquement de référence pour
                // les lignes de commande et la transaction de caisse).
                $numDocument = 'TICKET-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

                $pdo->beginTransaction();
                try {
                    // 0. CONTRÔLE DE STOCK (verrouillage + vérification AVANT toute écriture)
                    // On agrège les quantités demandées par produit (le panier peut contenir
                    // plusieurs lignes du même produit, ex. lots différents) puis on verrouille
                    // et vérifie le stock réellement disponible pour la boutique de vente.
                    $qteDemandeeParProduit = [];
                    foreach ($panier as $ligne) {
                        $code_prod = $ligne['code'] ?? $ligne['product_id'] ?? null;
                        if (!$code_prod) {
                            throw new Exception('Ligne de panier invalide : produit non identifié.');
                        }
                        $qte = intval($ligne['qte'] ?? 1);
                        if ($qte <= 0) {
                            throw new Exception('Quantité invalide pour un article du panier.');
                        }
                        $qteDemandeeParProduit[$code_prod] = ($qteDemandeeParProduit[$code_prod] ?? 0) + $qte;
                    }

                    foreach ($qteDemandeeParProduit as $code_prod => $qteDemandee) {
                        $stmtNomProd = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
                        $stmtNomProd->execute([$code_prod]);
                        $nomProd = $stmtNomProd->fetchColumn() ?: $code_prod;

                        if (!empty($boutique_id)) {
                            // Verrouille la ligne de stock de cette boutique jusqu'au commit/rollback
                            $stmtStockLock = $pdo->prepare(
                                "SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE"
                            );
                            $stmtStockLock->execute([$code_prod, $boutique_id]);
                            $stockDispo = $stmtStockLock->fetchColumn();
                            $stockDispo = ($stockDispo === false) ? 0 : (int) $stockDispo;
                        } else {
                            // Pas de boutique sélectionnée : on se rabat sur le stock global
                            $stmtStockLock = $pdo->prepare(
                                "SELECT stock_produit FROM produit WHERE code_produit = ? FOR UPDATE"
                            );
                            $stmtStockLock->execute([$code_prod]);
                            $stockDispo = (int) $stmtStockLock->fetchColumn();
                        }

                        if ($qteDemandee > $stockDispo) {
                            throw new Exception(
                                "Stock insuffisant pour « $nomProd » : disponible $stockDispo, demandé $qteDemandee."
                            );
                        }
                    }

                    // 1. AUCUNE FACTURE CRÉÉE — la vente comptoir est réglée intégralement
                    // en cash, le ticket (numéro $numDocument, non stocké en base facture)
                    // suffit comme justificatif. Seules les lignes de commande et la
                    // transaction de caisse ci-dessous sont enregistrées.

                    // 2. LIGNES DE COMMANDE — vente comptoir = remise immédiate au client,
                    // donc pas de bon de livraison (le client repart avec sa marchandise
                    // tout de suite ; le bon de livraison ne sert que pour les commandes
                    // à préparer/livrer plus tard, gérées via le circuit devis → bon).
                    $libellesLotValides = ['Boîte', 'Palette', 'Carton', 'Bidon', 'Unité'];
                    $numBase = date('dmYHis');
                    foreach ($panier as $i => $ligne) {
                        $numCmd = $numBase . str_pad($i, 2, '0', STR_PAD_LEFT);
                        $prix = floatval($ligne['prix'] ?? 0);
                        $qte = intval($ligne['qte'] ?? 1);
                        $montant = floatval($ligne['montant'] ?? ($prix * $qte));
                        $prix_achat = floatval($ligne['prix_achat'] ?? $prix);
                        $prixLotLigne = (isset($ligne['prix_lot']) && $ligne['prix_lot'] !== null && $ligne['prix_lot'] !== '') ? round((float)$ligne['prix_lot'], 2) : null;
                        $code_prod = $ligne['code'] ?? $ligne['product_id'];

                        // Configuration de lot (optionnelle, saisie manuellement dans le
                        // panier) : si non configurée, on reste sur le comportement
                        // "produit simple" (lot_id NULL, produits_par_lot = 1), et le
                        // ticket affichera "X Produit(s)" plutôt que de parler de lot.
                        $lotConfigure = filter_var($ligne['lot_configure'] ?? false, FILTER_VALIDATE_BOOLEAN);
                        $unitesParLot = max(2, intval($ligne['unites_par_lot'] ?? 2));
                        $libelleLot = in_array($ligne['libelle_lot'] ?? '', $libellesLotValides, true) ? $ligne['libelle_lot'] : 'Unité';

                        $lot_id = null;
                        $produits_par_lot = 1;
                        $lotNouvellementCree = false;
                        if ($lotConfigure) {
                            // Un lot est déjà configuré pour ce produit (même libellé) ?
                            // On le réutilise — le lot ne doit être créé qu'une seule fois,
                            // pas à chaque nouvelle vente comptoir du même produit (c'est
                            // notamment toujours le cas pour un produit déjà catalogué).
                            $stmtLotExist = $pdo->prepare("SELECT code_lot, unites_par_lot FROM lot WHERE produit_id = ? AND libelle = ? AND etat_lot = 'Actif' LIMIT 1");
                            $stmtLotExist->execute([$code_prod, $libelleLot]);
                            $lotExistant = $stmtLotExist->fetch(PDO::FETCH_ASSOC);

                            if ($lotExistant) {
                                $lot_id = $lotExistant['code_lot'];
                                $produits_par_lot = (int) $lotExistant['unites_par_lot'];
                            } else {
                                $lot_id = 'LOT-' . date('YmdHis') . rand(100, 999) . '-' . $i;
                                $produits_par_lot = $unitesParLot;
                                $lotNouvellementCree = true;
                                $pdo->prepare("INSERT INTO lot (code_lot, libelle, unites_par_lot, produit_id, quantite, etat_lot)
                                               VALUES (?, ?, ?, ?, ?, 'Actif')")
                                    ->execute([$lot_id, $libelleLot, $unitesParLot, $code_prod, $qte]);
                            }
                        }

                        $stmtCmd = $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, prix_lot_ligne, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                                                  VALUES (?, ?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?, 'VALIDEE')");
                        $stmtCmd->execute([$numCmd . '-DOC', $code_prod, $lot_id, $client_id, $numDocument,
                                           $prix_achat, $prix, $prixLotLigne, $qte, $produits_par_lot, $montant, USER_ID, $boutique_id]);

                        // Mise à jour stock boutique
                        if (!empty($boutique_id)) {
                            $pdo->prepare("UPDATE stock SET quantite = GREATEST(0, quantite - ?) WHERE produit_id = ? AND boutique_id = ?")
                                ->execute([$qte, $code_prod, $boutique_id]);
                        }

                        // Mise à jour stock produit
                        $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) - ? AS CHAR) WHERE code_produit = ?")
                            ->execute([$qte, $code_prod]);

                        // Mise à jour état produit
                        $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                        WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                        WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                        ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                            ->execute([$code_prod]);

                        // Gestion du lot ad-hoc tout juste créé : il est immédiatement
                        // vendu, donc déplété au même montant que sa quantité de création.
                        // Un lot CATALOGUE réutilisé n'est jamais déplété ici : sa quantité
                        // est resynchronisée sur le stock produit par lots.php, pas
                        // décrémentée vente par vente. Dans tous les cas, le lot reste
                        // Actif même à quantité 0 : un lot déjà configuré ne doit jamais
                        // être désactivé ni supprimé automatiquement.
                        if ($lot_id && $lotNouvellementCree) {
                            $pdo->prepare("UPDATE lot SET quantite = quantite - ? WHERE code_lot = ? AND quantite >= ?")
                                ->execute([$qte, $lot_id, $qte]);
                        }
                    }

                    // 4. TRANSACTION CAISSE — la vente comptoir est toujours payée cash et
                    // intégralement : on encaisse exactement le montant dû (montantTTC).
                    // La monnaie rendue au client ne transite jamais par la caisse.
                    if ($montantTTC > 0) {
                        $stmtSolde = $pdo->prepare("SELECT solde FROM caisse WHERE caisse_id = ? FOR UPDATE");
                        $stmtSolde->execute([CAISSE_ID]);
                        $soldeAvant = floatval($stmtSolde->fetchColumn());
                        $soldeApres = $soldeAvant + $montantTTC;
                        $numTrans = 'TR-' . date('YmdHis') . rand(100, 999);

                        $stmtTr = $pdo->prepare("INSERT INTO transaction
                            (numero_transaction, date_transaction, heure_transaction, montant_transaction, frais_transaction, montant_total, type_transaction, objet_transaction, caisse_id, facture_id, contact_id, mode_reglement, utilisateur_id, etat_transaction)
                            VALUES (?, CURDATE(), CURTIME(), ?, 0, ?, 'Entree', 'Vente comptoir', ?, ?, ?, ?, ?, 'Succes')");
                        $stmtTr->execute([$numTrans, $montantTTC, $montantTTC, CAISSE_ID, $numDocument, $client_id, $mode_reglement, USER_ID]);

                        $pdo->prepare("UPDATE caisse SET solde = ? WHERE caisse_id = ?")
                            ->execute([$soldeApres, CAISSE_ID]);
                    }

                    // 5. SOLDE DU CONTACT : sans objet ici. La vente comptoir étant
                    // toujours payée intégralement cash sur le client générique, elle
                    // ne crée jamais de créance/avance sur un compte client.

                    $pdo->commit();
                    echo json_encode([
                        'success' => true,
                        'document' => $numDocument,
                        'type_document' => $categorieDocument,
                        'reste' => $reste,
                        'etat' => $etatFacture,
                        'statut' => $statutFacture,
                        'lots' => $lotsData,
                        'totaux' => [
                            'ht' => $montantHT,
                            'taxe' => $taxe,
                            'remise' => $remise,
                            'ttc' => $montantTTC,
                            'reste' => $reste,
                            'avance' => $avance
                        ]
                    ]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                exit;

            default:
                throw new Exception('Action inconnue');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'message' => $e->getMessage()]);
        exit;
    }
}