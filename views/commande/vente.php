<?php
// views/ventes/index.php – Gestion des bons de commande client
// Design dashboard identique à la gestion des prix
// Intègre la validation des ventes via une modale (style inventaire)

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require_once 'databases/database.php';
require_once 'librairies/fpdf/fpdf.php';

$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---- FONCTION POUR RÉCUPÉRER LE PRIX UNITAIRE DEPUIS LA TABLE prix ----
function getPrixUnitaire($pdo, $produitId, $boutiqueId, $lotId, $quantiteBase) {
    $sql = "SELECT prix_unitaire 
            FROM prix 
            WHERE produit_id = ? 
              AND etat_prix = 'Actif'
              AND quantite_min <= ?
              AND (quantite_max IS NULL OR quantite_max >= ?)
              AND (boutique_id = ? OR (boutique_id IS NULL AND ? IS NOT NULL))
              AND (lot_id = ? OR (lot_id IS NULL AND ? IS NOT NULL))
            ORDER BY 
              CASE WHEN boutique_id IS NOT NULL THEN 1 ELSE 2 END,
              CASE WHEN lot_id IS NOT NULL THEN 1 ELSE 2 END,
              quantite_min DESC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$produitId, $quantiteBase, $quantiteBase, $boutiqueId, $boutiqueId, $lotId, $lotId]);
    $prix = $stmt->fetchColumn();
    if ($prix === false) {
        $sqlGlobal = "SELECT prix_unitaire 
                      FROM prix 
                      WHERE produit_id = ? 
                        AND etat_prix = 'Actif'
                        AND quantite_min <= ?
                        AND (quantite_max IS NULL OR quantite_max >= ?)
                        AND boutique_id IS NULL
                        AND lot_id IS NULL
                      ORDER BY quantite_min DESC
                      LIMIT 1";
        $stmt = $pdo->prepare($sqlGlobal);
        $stmt->execute([$produitId, $quantiteBase, $quantiteBase]);
        $prix = $stmt->fetchColumn();
        if ($prix === false) {
            throw new Exception("Aucun prix trouvé pour ce produit, cette boutique et ce lot avec la quantité demandée.");
        }
    }
    return (float)$prix;
}

// ---- LISTES ----
$clients = $pdo->query("SELECT code_contact, nom_prenom_contact, telephone_contact, email_contact, adresse_contact FROM contact WHERE type_contact = 'Client' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique, adresse_boutique, telephone_boutique, email_boutique, ville_boutique, pays_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$produits = $pdo->query("SELECT code_produit, titre_produit, prix_produit FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);
$factures = $pdo->query("SELECT numero_facture, titre_facture, date_facture, montant_ttc FROM facture WHERE type_facture = 'Client' ORDER BY date_facture DESC")->fetchAll(PDO::FETCH_ASSOC);

// ---- PRÉCHARGEMENT DES LOTS ----
$lotsParProduit = [];
$stmtLots = $pdo->query("SELECT produit_id, code_lot_produit, titre_lot, unites_par_lot FROM lot_produit WHERE etat_lot = 'Actif'");
while ($lot = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
    $lotsParProduit[$lot['produit_id']][] = $lot;
}

// ---- PRIX DE VENTE (pour compatibilité) ----
$prixVente = [];
foreach ($produits as $p) {
    $prixVente[$p['code_produit']] = $p['prix_produit'] ?? 0;
}

// ---- TRAITEMENT DU FORMULAIRE DE CRÉATION DE BON ----
$message = '';
$messageType = '';
$bonData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'valider_bon_vente') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        $clientId = $_POST['client_id'] ?? '';
        $dateBon = $_POST['date_bon'] ?? date('Y-m-d');
        $boutiqueId = $_POST['boutique_id'] ?? '';
        $factureId = $_POST['facture_id'] ?? null;
        $numBon = 'BC-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $produitsPost = $_POST['produit_id'] ?? [];
        $lots = $_POST['lot_id'] ?? [];
        $quantites = $_POST['quantite'] ?? [];
        $totalBon = 0;

        if (empty($clientId) || empty($boutiqueId) || empty($produitsPost)) {
            $message = "Veuillez sélectionner un client, une boutique et au moins un produit.";
            $messageType = 'error';
        } else {
            $errors = false;
            $lignesValides = [];
            foreach ($produitsPost as $index => $produitId) {
                if (empty($produitId)) continue;
                $quantiteSaisie = intval($quantites[$index] ?? 0);
                if ($quantiteSaisie <= 0) {
                    $errors = true;
                    $message = "La quantité doit être positive.";
                    $messageType = 'error';
                    break;
                }
                $lotId = $lots[$index] ?? null;
                $facteur = 1;
                $unite = 'Unité';
                if (!empty($lotId)) {
                    $stmtLot = $pdo->prepare("SELECT unites_par_lot, titre_lot FROM lot_produit WHERE code_lot_produit = ? AND produit_id = ?");
                    $stmtLot->execute([$lotId, $produitId]);
                    $lotData = $stmtLot->fetch(PDO::FETCH_ASSOC);
                    if ($lotData) {
                        $facteur = intval($lotData['unites_par_lot'] ?: 1);
                        $unite = $lotData['titre_lot'];
                    }
                }
                $quantiteBase = $quantiteSaisie * $facteur;

                try {
                    $prix = getPrixUnitaire($pdo, $produitId, $boutiqueId, $lotId, $quantiteBase);
                } catch (Exception $e) {
                    $errors = true;
                    $message = "Erreur pour le produit $produitId : " . $e->getMessage();
                    $messageType = 'error';
                    break;
                }

                $totalLigne = $quantiteBase * $prix;
                $totalBon += $totalLigne;
                $lignesValides[] = [
                    'produit_id' => $produitId,
                    'lot_id' => $lotId,
                    'quantite_saisie' => $quantiteSaisie,
                    'facteur' => $facteur,
                    'quantite_base' => $quantiteBase,
                    'prix_unitaire' => $prix,
                    'total_ligne' => $totalLigne,
                    'unite_affichage' => $unite
                ];
            }

            if (!$errors && !empty($lignesValides)) {
                try {
                    $pdo->beginTransaction();

                    foreach ($lignesValides as $ligne) {
                        $numCommandeUnique = $numBon . '-' . date('His') . rand(100, 999);

                        // Vérifie la disponibilité NETTE (stock réel - déjà réservé par d'autres ventes en attente)
                        $stmtLock = $pdo->prepare("SELECT quantite, quantite_reservee FROM stock_boutique WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                        $stmtLock->execute([$ligne['produit_id'], $boutiqueId]);
                        $ligneStock = $stmtLock->fetch(PDO::FETCH_ASSOC);
                        $stockReel = $ligneStock ? (int)$ligneStock['quantite'] : 0;
                        $reserveActuelle = $ligneStock ? (int)($ligneStock['quantite_reservee'] ?? 0) : 0;
                        $disponibleNet = $stockReel - $reserveActuelle;
                        if ($disponibleNet < $ligne['quantite_base']) {
                            throw new Exception("Stock disponible insuffisant pour {$ligne['produit_id']} dans la boutique $boutiqueId (disponible net : $disponibleNet, demandé : {$ligne['quantite_base']}).");
                        }

                        $stmt = $pdo->prepare("INSERT INTO commande 
                            (numero_commande, produit_id, contact_id, facture_id, statut_id,
                             date_commande, heure_commande, 
                             prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id, 
                             boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion,
                             reference_liee)
                            VALUES (?, ?, ?, ?, '012', ?, CURTIME(), 0, ?, ?, ?, ?, ?, 'En attente', ?, ?, ?, ?)");
                        $stmt->execute([
                            $numCommandeUnique,
                            $ligne['produit_id'],
                            $clientId,
                            $factureId,
                            $dateBon,
                            $ligne['prix_unitaire'],
                            $ligne['quantite_base'],
                            $ligne['total_ligne'],
                            $user['id'],
                            $boutiqueId,
                            $ligne['lot_id'],
                            $ligne['unite_affichage'],
                            $ligne['facteur'],
                            $numBon
                        ]);

                        // Réserver le stock
                        if ($ligneStock === false) {
                            $pdo->prepare("INSERT INTO stock_boutique (produit_id, boutique_id, quantite, quantite_reservee) VALUES (?, ?, 0, 0)")
                                ->execute([$ligne['produit_id'], $boutiqueId]);
                        }
                        $pdo->prepare("UPDATE stock_boutique SET quantite_reservee = quantite_reservee + ? WHERE produit_id = ? AND boutique_id = ?")
                            ->execute([$ligne['quantite_base'], $ligne['produit_id'], $boutiqueId]);
                    }

                    $numFacture = 'FAC-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $taxe = 18;
                    $remise = 0;
                    $montant_ht = $totalBon;
                    $montant_ttc = $montant_ht * (1 + $taxe / 100) - $remise;

                    $stmtFact = $pdo->prepare("INSERT INTO facture 
                        (numero_facture, titre_facture, type_facture, categorie_facture, 
                         date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, 
                         contact_id, utilisateur_id, etat_facture)
                        VALUES (?, ?, 'Client', 'Facture', ?, ?, ?, ?, ?, 0, ?, ?, ?, 'En attente')");
                    $stmtFact->execute([
                        $numFacture,
                        'Facture client ' . $numFacture,
                        $dateBon,
                        $montant_ht,
                        $taxe,
                        $remise,
                        $montant_ttc,
                        $montant_ttc,
                        $clientId,
                        $user['id']
                    ]);

                    $pdo->prepare("UPDATE commande SET facture_id = ? WHERE reference_liee = ? AND statut_id = '012'")
                        ->execute([$numFacture, $numBon]);

                    $pdo->commit();

                    $message = "Bon de commande client $numBon enregistré en attente de validation (stock réservé). Facture $numFacture créée en attente.";
                    $messageType = 'success';
                    $bonData = [
                        'num' => $numBon,
                        'date' => $dateBon,
                        'client' => $clientId,
                        'boutique' => $boutiqueId,
                        'facture' => $factureId ?: $numFacture,
                        'facture_auto' => $numFacture,
                        'lignes' => $lignesValides,
                        'total' => $totalBon
                    ];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
    }
}

// ---- TRAITEMENT DE LA VALIDATION / ANNULATION DES VENTES (MODALE) ----
$validationMessage = '';
$validationMessageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_validation'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $validationMessage = "Token de sécurité invalide.";
        $validationMessageType = 'error';
    } else {
        $reference = $_POST['reference_liee'] ?? '';
        $action = $_POST['action_validation'];

        $stmtLignes = $pdo->prepare("SELECT * FROM commande WHERE reference_liee = ? AND statut_id = '012' AND etat_commande = 'En attente'");
        $stmtLignes->execute([$reference]);
        $lignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);

        if (empty($lignes)) {
            $validationMessage = "Cette vente n'a plus de ligne en attente.";
            $validationMessageType = 'error';
        } elseif ($action === 'valider') {
            try {
                $pdo->beginTransaction();
                foreach ($lignes as $ligne) {
                    $qte = (int) $ligne['quantite_commande'];
                    $produitId = $ligne['produit_id'];
                    $boutiqueId = $ligne['boutique_id'];

                    $stmtLock = $pdo->prepare("SELECT quantite, quantite_reservee FROM stock_boutique WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                    $stmtLock->execute([$produitId, $boutiqueId]);
                    $ligneStock = $stmtLock->fetch(PDO::FETCH_ASSOC);
                    $stockAvant = $ligneStock ? (int) $ligneStock['quantite'] : 0;
                    $stockApres = $stockAvant - $qte;
                    if ($stockApres < 0) {
                        throw new Exception("Stock physique insuffisant pour $produitId (disponible : $stockAvant, demandé : $qte).");
                    }

                    // Consommation réelle du stock + libération de la réservation correspondante
                    $pdo->prepare("UPDATE stock_boutique SET quantite = ?, quantite_reservee = GREATEST(0, quantite_reservee - ?) WHERE produit_id = ? AND boutique_id = ?")
                        ->execute([$stockApres, $qte, $produitId, $boutiqueId]);

                    $pdo->prepare("UPDATE produit SET stock_produit = (
                            SELECT COALESCE(SUM(quantite), 0) FROM stock_boutique WHERE produit_id = ?
                        ) WHERE code_produit = ?")
                        ->execute([$produitId, $produitId]);

                    $pdo->prepare("UPDATE commande SET etat_commande = 'Validé', stock_avant = ?, stock_apres = ?,
                            date_validation = NOW(), utilisateur_validation_id = ?
                        WHERE numero_commande = ?")
                        ->execute([$stockAvant, $stockApres, $user['id'], $ligne['numero_commande']]);
                }
                // La facture liée passe de "En attente" à "Validée"
                $pdo->prepare("UPDATE facture SET etat_facture = 'Validée' WHERE numero_facture = (
                        SELECT facture_id FROM commande WHERE reference_liee = ? AND facture_id IS NOT NULL LIMIT 1
                    )")->execute([$reference]);
                $pdo->commit();
                $validationMessage = "Vente $reference validée : stock décrémenté et facture confirmée.";
                $validationMessageType = 'success';
            } catch (Exception $ex) {
                $pdo->rollBack();
                $validationMessage = "Erreur lors de la validation : " . $ex->getMessage();
                $validationMessageType = 'error';
            }
        } elseif ($action === 'annuler') {
            try {
                $pdo->beginTransaction();
                foreach ($lignes as $ligne) {
                    $qte = (int) $ligne['quantite_commande'];
                    $pdo->prepare("UPDATE stock_boutique SET quantite_reservee = GREATEST(0, quantite_reservee - ?) WHERE produit_id = ? AND boutique_id = ?")
                        ->execute([$qte, $ligne['produit_id'], $ligne['boutique_id']]);
                    $pdo->prepare("UPDATE commande SET etat_commande = 'Annulé', date_validation = NOW(), utilisateur_validation_id = ?
                            WHERE numero_commande = ?")
                        ->execute([$user['id'], $ligne['numero_commande']]);
                }
                $pdo->prepare("UPDATE facture SET etat_facture = 'Annulée' WHERE numero_facture = (
                        SELECT facture_id FROM commande WHERE reference_liee = ? AND facture_id IS NOT NULL LIMIT 1
                    )")->execute([$reference]);
                $pdo->commit();
                $validationMessage = "Vente $reference annulée : la réservation de stock a été libérée.";
                $validationMessageType = 'success';
            } catch (Exception $ex) {
                $pdo->rollBack();
                $validationMessage = "Erreur lors de l'annulation : " . $ex->getMessage();
                $validationMessageType = 'error';
            }
        }
    }
}

// ---- ENDPOINT AJAX POUR OBTENIR LE PRIX UNITAIRE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_prix_unitaire') {
    while (ob_get_level()) ob_end_clean();

    $produitId = $_POST['produit_id'] ?? '';
    $boutiqueId = $_POST['boutique_id'] ?? '';
    $lotId = $_POST['lot_id'] ?? null;
    $quantiteSaisie = intval($_POST['quantite'] ?? 0);
    $facteur = 1;
    if (!empty($lotId)) {
        $stmtLot = $pdo->prepare("SELECT unites_par_lot FROM lot_produit WHERE code_lot_produit = ? AND produit_id = ?");
        $stmtLot->execute([$lotId, $produitId]);
        $facteur = intval($stmtLot->fetchColumn() ?: 1);
    }
    $quantiteBase = $quantiteSaisie * $facteur;
    try {
        $prix = getPrixUnitaire($pdo, $produitId, $boutiqueId, $lotId, $quantiteBase);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'prix' => $prix]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ---- IMPRESSION PDF (BON DE LIVRAISON) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'print_bon_pdf') {
    $bonId = $_POST['bon_id'] ?? '';
    if (empty($bonId)) die("Numéro de bon manquant.");

    $stmt = $pdo->prepare("
        SELECT c.*, p.titre_produit, 
               ct.nom_prenom_contact AS client_nom, ct.telephone_contact AS client_tel, 
               ct.email_contact AS client_email, ct.adresse_contact AS client_adresse,
               b.nom_boutique, b.adresse_boutique, b.telephone_boutique, b.email_boutique, b.ville_boutique, b.pays_boutique
        FROM commande c
        LEFT JOIN produit p ON c.produit_id = p.code_produit
        LEFT JOIN contact ct ON c.contact_id = ct.code_contact
        LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
        WHERE c.reference_liee = ? AND c.statut_id = '012' AND c.etat_commande != 'Annulé'
        ORDER BY c.numero_commande
    ");
    $stmt->execute([$bonId]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($lignes)) die("Aucune ligne trouvée.");
    $bonInfo = $lignes[0];

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);
    $blueDark = [0, 51, 102];
    $blueLight = [240, 245, 255];
    $grayBg = [245, 245, 245];
    $toLatin = function ($chaine) {
        return mb_convert_encoding($chaine, 'ISO-8859-1', 'UTF-8');
    };

    $yStart = 10;
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(10, $yStart + 6, $toLatin(strtoupper($bonInfo['nom_boutique'] ?? 'ABC DISTRIBUTION')));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Text(10, $yStart + 11, $toLatin("Commerce Général - Distribution de Produits"));
    $pdf->Text(10, $yStart + 15, $toLatin(($bonInfo['adresse_boutique'] ?? '') . ', ' . ($bonInfo['ville_boutique'] ?? '') . ', ' . ($bonInfo['pays_boutique'] ?? '')));
    $pdf->Text(10, $yStart + 19, $toLatin("Tél. : " . ($bonInfo['telephone_boutique'] ?? '')));
    $pdf->Text(10, $yStart + 23, $toLatin("Email : " . ($bonInfo['email_boutique'] ?? '')));

    $pdf->SetFont('Arial', 'B', 22);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->SetXY(0, $yStart + 8);
    $pdf->Cell(297, 10, $toLatin('BON DE LIVRAISON'), 0, 1, 'C');

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Rect(200, $yStart + 5, 70, 8, 'F');
    $pdf->Text(205, $yStart + 11, $toLatin('N° ' . $bonId));

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text(200, $yStart + 20, $toLatin('Date : ' . date('d/m/Y', strtotime($bonInfo['date_commande']))));
    if (!empty($bonInfo['facture_id'])) {
        $pdf->Text(200, $yStart + 26, $toLatin('Facture liée : ' . $bonInfo['facture_id']));
    }
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(10, 42, 287, 42);
    $yBlocks = 48;
    $drawAddressBlock = function ($pdf, $x, $y, $w, $title, $name, $address, $phone, $email) use ($toLatin, $blueDark, $grayBg) {
        $h = 30;
        $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->Rect($x, $y, 40, 6, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Text($x + 3, $y + 4.5, $toLatin(strtoupper($title)));
        $pdf->SetFillColor($grayBg[0], $grayBg[1], $grayBg[2]);
        $pdf->Rect($x, $y + 6, $w, $h - 6, 'F');
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Rect($x, $y + 6, $w, $h - 6, 'D');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Text($x + 3, $y + 12, $toLatin($name));
        $pdf->SetFont('Arial', '', 8);
        $pdf->Text($x + 3, $y + 17, $toLatin($address));
        $pdf->Text($x + 3, $y + 22, $toLatin('Tél. : ' . $phone));
        $pdf->Text($x + 3, $y + 27, $toLatin('Email : ' . $email));
    };
    $wBlock = (277 - 10) / 2;
    $drawAddressBlock(
        $pdf,
        10,
        $yBlocks,
        $wBlock,
        'EXPÉDITEUR',
        $bonInfo['nom_boutique'] ?? '',
        ($bonInfo['adresse_boutique'] ?? '') . ', ' . ($bonInfo['ville_boutique'] ?? '') . ', ' . ($bonInfo['pays_boutique'] ?? ''),
        $bonInfo['telephone_boutique'] ?? '',
        $bonInfo['email_boutique'] ?? ''
    );
    $drawAddressBlock(
        $pdf,
        10 + $wBlock + 10,
        $yBlocks,
        $wBlock,
        'DESTINATAIRE',
        $bonInfo['client_nom'] ?? '',
        $bonInfo['client_adresse'] ?? '',
        $bonInfo['client_tel'] ?? '',
        $bonInfo['client_email'] ?? ''
    );

    // Tableau sans colonnes prix/montant
    $colWidths = [18, 100, 30, 20, 20, 24, 24, 24, 16];
    $headers = ['RÉF.', 'DÉSIGNATION', 'LOT/UNITÉ', 'QTÉ (lots)', 'QTÉ (base)', 'LIVREUR', 'CONTRÔLEUR', 'RESPONSABLE', 'VISA'];
    $headerH = 8;
    $rowH = 8;
    $yTable = 90;

    $drawTableHeader = function () use ($pdf, $colWidths, $headers, $headerH, $toLatin, $blueDark, &$yTable) {
        $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 6.5);
        $x = 10;
        foreach ($headers as $i => $h) {
            $label = $toLatin($h);
            $pdf->Rect($x, $yTable, $colWidths[$i], $headerH, 'F');
            $pdf->Text($x + ($colWidths[$i] / 2) - ($pdf->GetStringWidth($label) / 2), $yTable + 5.5, $label);
            $x += $colWidths[$i];
        }
        $yTable += $headerH;
    };
    $drawTableHeader();

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 7);
    $yCurrent = $yTable;
    $totalLots = 0;
    $totalBase = 0;

    foreach ($lignes as $ligne) {
        if ($yCurrent + $rowH > 200) {
            $pdf->AddPage();
            $yTable = 20;
            $drawTableHeader();
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 7);
            $yCurrent = $yTable;
        }

        $ref = $ligne['produit_id'];
        $des = substr($ligne['titre_produit'] ?? $ligne['produit_id'], 0, 60);
        $unite = $ligne['unite_affichage'] ?? 'Unité';
        $facteur = intval($ligne['facteur_conversion'] ?: 1);
        $qtLots = $ligne['quantite_commande'] / max(1, $facteur);
        $qtBase = $ligne['quantite_commande'];

        $totalLots += $qtLots;
        $totalBase += $qtBase;

        $data = [
            $ref,
            $des,
            $unite,
            number_format($qtLots, 0),
            $qtBase,
            '', // Livreur
            '', // Contrôleur
            '', // Responsable
            ''  // Visa
        ];

        $x = 10;
        foreach ($data as $i => $val) {
            $align = ($i >= 3 && $i < 5) ? 'C' : (($i >= 5) ? 'C' : 'L');
            $label = $toLatin((string)$val);
            $txtX = ($align == 'R') ? $x + $colWidths[$i] - 2 - $pdf->GetStringWidth($label) : (($align == 'C') ? $x + ($colWidths[$i] / 2) - ($pdf->GetStringWidth($label) / 2) : $x + 1);
            $pdf->Rect($x, $yCurrent, $colWidths[$i], $rowH, 'D');
            $pdf->Text($txtX, $yCurrent + 5.5, $label);
            $x += $colWidths[$i];
        }
        $yCurrent += $rowH;
    }

    // Ligne des totaux
    if ($yCurrent + $rowH > 200) {
        $pdf->AddPage();
        $yTable = 20;
        $drawTableHeader();
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 7);
        $yCurrent = $yTable;
    }
    $dataTot = [
        'TOTAUX',
        '',
        '',
        number_format($totalLots, 0),
        $totalBase,
        '',
        '',
        '',
        ''
    ];
    $x = 10;
    foreach ($dataTot as $i => $val) {
        $align = ($i >= 3 && $i < 5) ? 'C' : (($i >= 5) ? 'C' : 'L');
        $label = $toLatin((string)$val);
        $txtX = ($align == 'R') ? $x + $colWidths[$i] - 2 - $pdf->GetStringWidth($label) : (($align == 'C') ? $x + ($colWidths[$i] / 2) - ($pdf->GetStringWidth($label) / 2) : $x + 1);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Rect($x, $yCurrent, $colWidths[$i], $rowH, 'FD');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Text($txtX, $yCurrent + 5.5, $label);
        $x += $colWidths[$i];
    }
    $yCurrent += $rowH;

    while (ob_get_level()) ob_end_clean();
    $pdf->Output('I', 'Bon_' . $bonId . '.pdf');
    exit;
}

// ---- RÉCUPÉRATION DE L'HISTORIQUE ----
function getBonsVente($pdo, $search, $client_filter, $boutique_filter, $page, $perPage = 10)
{
    $sql = "SELECT 
                reference_liee AS bon_id,
                MAX(date_commande) AS date_bon,
                MAX(ct.nom_prenom_contact) AS client,
                MAX(ct.telephone_contact) AS client_tel,
                MAX(ct.email_contact) AS client_email,
                MAX(ct.adresse_contact) AS client_adresse,
                MAX(boutique_id) AS boutique_id,
                MAX(facture_id) AS facture_id,
                COUNT(*) AS nb_lignes,
                SUM(montant_commande) AS total_bon
            FROM commande c
            LEFT JOIN contact ct ON c.contact_id = ct.code_contact
            WHERE statut_id = '012' 
              AND etat_commande != 'Annulé'
              AND reference_liee IS NOT NULL
    ";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (c.reference_liee LIKE ? OR ct.nom_prenom_contact LIKE ? OR c.boutique_id LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($client_filter)) {
        $sql .= " AND ct.code_contact = ?";
        $params[] = $client_filter;
    }
    if (!empty($boutique_filter)) {
        $sql .= " AND c.boutique_id = ?";
        $params[] = $boutique_filter;
    }
    $sql .= " GROUP BY reference_liee ORDER BY date_bon DESC";

    $countSql = "SELECT COUNT(*) FROM (" . $sql . ") AS sub";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $sql .= " LIMIT $perPage OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bons as &$bon) {
        $stmt = $pdo->prepare("
            SELECT produit_id, quantite_commande, unite_affichage, 
                   facteur_conversion, prix_commande, montant_commande
            FROM commande
            WHERE reference_liee = ? AND statut_id = '012'
        ");
        $stmt->execute([$bon['bon_id']]);
        $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lignes as &$ligne) {
            $stmtProd = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
            $stmtProd->execute([$ligne['produit_id']]);
            $ligne['titre_produit'] = $stmtProd->fetchColumn();
            $facteur = intval($ligne['facteur_conversion'] ?: 1);
            $ligne['quantite_lots'] = $ligne['quantite_commande'] / max(1, $facteur);
        }
        $bon['lignes'] = $lignes;
        $stmtBoutique = $pdo->prepare("SELECT nom_boutique, adresse_boutique FROM boutique WHERE code_boutique = ?");
        $stmtBoutique->execute([$bon['boutique_id']]);
        $boutiqueInfo = $stmtBoutique->fetch(PDO::FETCH_ASSOC);
        $bon['nom_boutique'] = $boutiqueInfo['nom_boutique'] ?? '';
        $bon['adresse_boutique'] = $boutiqueInfo['adresse_boutique'] ?? '';
    }
    unset($bon);
    return ['bons' => $bons, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages];
}

$search = trim($_POST['search'] ?? '');
$client_filter = trim($_POST['client_filter'] ?? '');
$boutique_filter = trim($_POST['boutique_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

$bonsData = getBonsVente($pdo, $search, $client_filter, $boutique_filter, $page, 10);

// ---- AJAX pour le tableau ----
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $client_filter = trim($_POST['client_filter'] ?? '');
    $boutique_filter = trim($_POST['boutique_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    $data = getBonsVente($pdo, $search, $client_filter, $boutique_filter, $page, 10);
    ob_start();
    if (empty($data['bons'])): ?>
        <tr>
            <td colspan="8" class="text-center py-5 text-muted">
                <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>
                Aucun bon trouvé
            </td>
        </tr>
    <?php else: foreach ($data['bons'] as $bon): ?>
        <tr>
            <td class="td-bold"><?= e($bon['bon_id']) ?></td>
            <td><?= e($bon['date_bon']) ?></td>
            <td><?= e($bon['client'] ?? '—') ?></td>
            <td><?= $bon['nb_lignes'] ?></td>
            <td><strong><?= fmt($bon['total_bon']) ?> F</strong></td>
            <td><?= e($bon['adresse_boutique'] ?? '—') ?></td>
            <td><?= e($bon['facture_id'] ?? '—') ?></td>
            <td class="text-end">
                <form method="POST" style="display:inline-block;">
                    <input type="hidden" name="action" value="print_bon_pdf">
                    <input type="hidden" name="bon_id" value="<?= e($bon['bon_id']) ?>">
                    <button type="submit" class="act-btn" title="PDF" style="color:#dc3545; border:none; background:transparent; padding:0; width:34px; height:34px;">
                        <i class="bi bi-file-pdf"></i>
                    </button>
                </form>
            </td>
        </tr>
    <?php endforeach; endif;
    $tableHtml = ob_get_clean();

    ob_start();
    if ($data['totalPages'] > 1): ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
            <span class="text-muted small">Affichage de <?= (($data['page'] - 1) * 10 + 1) ?> à <?= min($data['page'] * 10, $data['total']) ?> sur <?= $data['total'] ?></span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($data['page'] <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $data['page'] - 1 ?>"><i class="bi bi-chevron-left"></i></a>
                    </li>
                    <?php
                    $start = max(1, $data['page'] - 2);
                    $end = min($data['totalPages'], $data['page'] + 2);
                    if ($start > 1) {
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                        if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?= ($i == $data['page']) ? 'active' : '' ?>">
                            <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor;
                    if ($end < $data['totalPages']) {
                        if ($end < $data['totalPages'] - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $data['totalPages'] . '">' . $data['totalPages'] . '</a></li>';
                    }
                    ?>
                    <li class="page-item <?= ($data['page'] >= $data['totalPages']) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $data['page'] + 1 ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif;
    $paginationHtml = ob_get_clean();

    header('Content-Type: application/json');
    echo json_encode(['table' => $tableHtml, 'pagination' => $paginationHtml, 'total' => $data['total'], 'page' => $data['page'], 'totalPages' => $data['totalPages']]);
    exit;
}

// ---- RÉCUPÉRATION DES VENTES EN ATTENTE POUR LA MODALE ----
$ventesEnAttente = $pdo->query("
    SELECT c.reference_liee, c.contact_id, c.boutique_id, c.date_commande, c.facture_id,
           ct.nom_prenom_contact, b.nom_boutique,
           COUNT(*) as nb_lignes, SUM(c.montant_commande) as montant_total
    FROM commande c
    LEFT JOIN contact ct ON c.contact_id = ct.code_contact
    LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
    WHERE c.statut_id = '012' AND c.etat_commande = 'En attente'
    GROUP BY c.reference_liee, c.contact_id, c.boutique_id, c.date_commande, c.facture_id, ct.nom_prenom_contact, b.nom_boutique
    ORDER BY c.date_commande DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des bons de commande client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
    <style>
        /* ===== STYLE DASHBOARD (identique à la gestion des prix) ===== */
        :root {
            --b: #2563eb;
            --bd: #1d4ed8;
            --bl: #eff6ff;
            --bb: #bfdbfe;
            --bg: #f1f5f9;
            --w: #fff;
            --dk: #0f172a;
            --mt: #64748b;
            --lt: #94a3b8;
            --brd: #e2e8f0;
            --dng: #ef4444;
            --dngl: #fef2f2;
            --dngb: #fecaca;
            --suc: #10b981;
            --sucl: #ecfdf5;
            --sucb: #a7f3d0;
            --wrn: #f59e0b;
            --wrnl: #fffbeb;
            --wrnb: #fde68a;
            --prp: #8b5cf6;
            --prpl: #f5f3ff;
            --prpb: #e9d5ff;
            --tl: #0891b2;
            --tll: #ecfeff;
            --tlb: #cffafe;
            --R: 16px;
            --Rs: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg);
            color: var(--dk);
            min-height: 100vh;
            line-height: 1.5;
            padding: 28px 20px;
        }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .W { max-width: 1400px; margin: 0 auto; }
        .hdr {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--dk); letter-spacing: -0.02em; }
        .hdr-l p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
        .hdr-r {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .hdr-badge {
            background: var(--bl);
            border: 1px solid var(--bb);
            color: var(--b);
            padding: 8px 14px;
            border-radius: var(--Rs);
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .pbar {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            padding: 16px 20px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .prow {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .prow label {
            font-size: 11px;
            font-weight: 600;
            color: var(--mt);
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .prow input, .prow select {
            padding: 7px 10px;
            border: 1.5px solid var(--brd);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: var(--dk);
            background: var(--bg);
            font-family: 'Inter', sans-serif;
            transition: all .2s;
        }
        .prow input:focus, .prow select:focus {
            border-color: var(--b);
            background: #fff;
            box-shadow: 0 0 0 3px var(--bl);
            outline: none;
        }
        .prow select {
            appearance: none;
            padding-right: 32px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }
        .btn-go {
            background: var(--b);
            color: #fff;
            padding: 7px 16px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 4px rgba(37,99,235,.2);
            transition: background .15s;
            border: none;
            cursor: pointer;
        }
        .btn-go:hover { background: var(--bd); }
        .btn-go-outline {
            background: transparent;
            color: var(--mt);
            border: 1.5px solid var(--brd);
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s;
            cursor: pointer;
        }
        .btn-go-outline:hover {
            background: var(--bg);
            border-color: var(--lt);
        }

        .data-table-wrap {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .table>:not(caption)>*>* { padding: 12px 18px; }
        .table thead th {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--lt);
            background: var(--bg);
            border-bottom: 1px solid var(--brd);
        }
        .table tbody tr {
            border-bottom: 1px solid var(--brd);
            transition: background .2s;
        }
        .table tbody tr:hover { background: var(--bl); }
        .table tbody td {
            vertical-align: middle;
            color: var(--dk);
            font-size: 0.85rem;
        }
        .td-bold { color: var(--dk) !important; font-weight: 700; }
        .td-semi { color: var(--dk) !important; font-weight: 500; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            border-radius: 999px;
            font-size: 0.73rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .status-badge .sdot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .status-badge.on { background: var(--sucl); color: #059669; }
        .status-badge.off { background: var(--dngl); color: #dc2626; }

        .act-btn {
            width: 34px;
            height: 34px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--lt);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all .2s;
        }
        .act-btn:hover { transform: scale(1.1); }
        .act-btn.v:hover { color: var(--b); background: var(--bl); border-color: rgba(37,99,235,.15); }
        .act-btn.e:hover { color: var(--wrn); background: var(--wrnl); border-color: rgba(245,158,11,.15); }
        .act-btn.d:hover { color: var(--dng); background: var(--dngl); border-color: rgba(239,68,68,.15); }

        .pagination .page-link {
            color: var(--b);
            border: 1px solid var(--brd);
            border-radius: 6px;
            margin: 0 2px;
            padding: 6px 14px;
            font-weight: 500;
        }
        .pagination .page-link:hover { background: var(--bl); border-color: var(--b); }
        .pagination .page-item.active .page-link { background: var(--b); border-color: var(--b); color: #fff; }
        .pagination .page-item.disabled .page-link { color: var(--lt); border-color: var(--brd); }

        .modal-content {
            border-radius: var(--R);
            border: none;
            box-shadow: 0 12px 40px rgba(15,23,42,.08);
        }
        .modal-header { border-bottom: 1px solid var(--brd); background: var(--bg); }
        .modal-footer { border-top: 1px solid var(--brd); background: var(--bg); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .data-table-wrap { animation: fadeUp .4s ease both; }

        @media (max-width:700px) {
            body { padding: 14px; }
            .hdr { flex-direction: column; align-items: flex-start; }
            .prow { flex-direction: column; align-items: stretch; }
            .prow .btn-go { width: 100%; justify-content: center; }
        }
        .bootstrap-select .dropdown-toggle .filter-option { color: var(--dk); }
        .bootstrap-select .dropdown-menu {
            border-radius: var(--Rs);
            border-color: var(--brd);
        }
        .bootstrap-select .dropdown-menu .bs-searchbox input {
            border-radius: 6px;
            border: 1px solid var(--brd);
            padding: 8px 12px;
        }
        .bootstrap-select .dropdown-menu .bs-searchbox input:focus {
            border-color: var(--b);
            box-shadow: 0 0 0 3px var(--bl);
        }

        /* ===== STYLE DE LA MODALE (inspiré de inventaire.php) ===== */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 16px;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-box {
            background: white;
            border-radius: 16px;
            width: 900px;
            max-width: 100%;
            max-height: 90vh;
            box-shadow: 0 20px 25px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .modal-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--brd);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .modal-head h3 {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--dk);
            margin: 0;
        }

        .modal-head h3 i {
            color: var(--b);
        }

        .modal-close {
            background: #f1f5f9;
            font-size: 18px;
            color: var(--lt);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .modal-close:hover {
            background: var(--dngl);
            color: var(--dng);
        }

        .modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-foot {
            padding: 14px 20px;
            border-top: 1px solid var(--brd);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
            background: #f8fafc;
        }

        .modal-foot .btn-secondary {
            background: #f1f5f9;
            color: var(--dk);
            padding: 8px 16px;
            border-radius: var(--Rs);
            font-weight: 600;
            font-size: 14px;
            border: 1px solid var(--brd);
            transition: all 0.2s;
            cursor: pointer;
        }

        .modal-foot .btn-secondary:hover {
            background: #e2e8f0;
        }

        .modal-foot .btn-success {
            background: var(--suc);
            color: white;
            padding: 8px 16px;
            border-radius: var(--Rs);
            font-weight: 600;
            font-size: 14px;
            border: none;
            transition: background 0.2s;
            cursor: pointer;
        }

        .modal-foot .btn-success:hover {
            background: #059669;
        }

        .modal-body .product-ref {
            background: var(--bl);
            border: 1px solid var(--bb);
            border-radius: var(--Rs);
            padding: 10px 14px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .modal-body .product-ref .ref-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--bd);
        }

        .modal-body .product-ref .ref-stock {
            font-size: 12px;
            color: var(--mt);
        }

        .modal-body .badge-lot {
            background: var(--bl);
            color: var(--bd);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--bb);
        }

        .modal-body .badge-lot.empty {
            background: #f1f5f9;
            color: var(--lt);
            border-color: var(--brd);
        }

        .modal-body .btn-sm {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
        }

        .modal-body .btn-success {
            background: var(--suc);
            color: white;
        }

        .modal-body .btn-success:hover {
            background: #059669;
        }

        .modal-body .btn-outline-danger {
            background: transparent;
            color: var(--dng);
            border: 1px solid var(--dng);
        }

        .modal-body .btn-outline-danger:hover {
            background: var(--dngl);
        }

        .modal-body table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .modal-body thead th {
            background: var(--b);
            color: white;
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            letter-spacing: 0.03em;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-body tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--brd);
            color: var(--dk);
            vertical-align: middle;
        }

        .modal-body tbody tr:hover {
            background: var(--bl);
        }

        .modal-body .text-center {
            text-align: center;
        }

        .modal-body .text-muted {
            color: var(--mt);
        }

        .modal-body .py-5 {
            padding-top: 3rem;
            padding-bottom: 3rem;
        }

        .modal-body .toast-notif {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--dk);
            color: white;
            padding: 12px 20px;
            border-radius: var(--Rs);
            font-size: 13px;
            font-weight: 600;
            z-index: 2000;
            display: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            align-items: center;
            gap: 8px;
            max-width: 400px;
        }

        .modal-body .toast-notif.error {
            background: var(--dng);
        }

        .modal-body .toast-notif.success {
            background: var(--b);
        }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1>Gestion des bons de commande client</h1>
            <p>Suivez vos commandes clients</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-receipt"></i> <?= $bonsData['total'] ?? 0 ?> bons</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau bon</button>
            <button class="btn-go" id="validationBtn" style="background:#059669;"><i class="bi bi-check2-circle"></i> Valider ventes</button>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
            <?= e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Barre de recherche / filtres -->
    <div class="pbar">
        <form id="searchForm" method="post" onsubmit="return false;">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="page" id="pageInput" value="<?= $bonsData['page'] ?? 1 ?>">
            <div class="prow">
                <label for="searchInput"><i class="bi bi-search"></i> Recherche</label>
                <input type="text" name="search" id="searchInput" placeholder="N° bon, client..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                <label for="clientFilter">Client</label>
                <select name="client_filter" id="clientFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Tous">
                    <option value="">Tous</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= e($c['code_contact']) ?>" <?= ($client_filter == $c['code_contact']) ? 'selected' : '' ?>><?= e($c['nom_prenom_contact']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="boutiqueFilter">Boutique</label>
                <select name="boutique_filter" id="boutiqueFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Toutes">
                    <option value="">Toutes</option>
                    <?php foreach ($boutiques as $b): ?>
                        <option value="<?= e($b['code_boutique']) ?>" <?= ($boutique_filter == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
            </div>
        </form>
    </div>

    <!-- Tableau -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des bons</h5>
            <span class="text-muted small" id="totalCount"><?= $bonsData['total'] ?? 0 ?> bon(s) - Page <?= $bonsData['page'] ?? 1 ?> / <?= max(1, $bonsData['totalPages'] ?? 1) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>N° bon</th>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Articles</th>
                        <th>Total</th>
                        <th>Lieu de livraison</th>
                        <th>Facture liée</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if (empty($bonsData['bons'])): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>
                                Aucun bon trouvé
                            </td>
                        </tr>
                    <?php else: foreach ($bonsData['bons'] as $bon): ?>
                        <tr>
                            <td class="td-bold"><?= e($bon['bon_id']) ?></td>
                            <td><?= e($bon['date_bon']) ?></td>
                            <td><?= e($bon['client'] ?? '—') ?></td>
                            <td><?= $bon['nb_lignes'] ?></td>
                            <td><strong><?= fmt($bon['total_bon']) ?> F</strong></td>
                            <td><?= e($bon['adresse_boutique'] ?? '—') ?></td>
                            <td><?= e($bon['facture_id'] ?? '—') ?></td>
                            <td class="text-end">
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="action" value="print_bon_pdf">
                                    <input type="hidden" name="bon_id" value="<?= e($bon['bon_id']) ?>">
                                    <button type="submit" class="act-btn" title="PDF" style="color:#dc3545; border:none; background:transparent; padding:0; width:34px; height:34px;">
                                        <i class="bi bi-file-pdf"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div id="paginationContainer">
            <?php if (($bonsData['totalPages'] ?? 0) > 1): ?>
                <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
                    <span class="text-muted small">Affichage de <?= (($bonsData['page'] - 1) * 10 + 1) ?> à <?= min($bonsData['page'] * 10, $bonsData['total']) ?> sur <?= $bonsData['total'] ?></span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= ($bonsData['page'] <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="#" data-page="<?= $bonsData['page'] - 1 ?>"><i class="bi bi-chevron-left"></i></a>
                            </li>
                            <?php
                            $start = max(1, $bonsData['page'] - 2);
                            $end = min($bonsData['totalPages'], $bonsData['page'] + 2);
                            if ($start > 1) {
                                echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                                if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                            }
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <li class="page-item <?= ($i == $bonsData['page']) ? 'active' : '' ?>">
                                    <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor;
                            if ($end < $bonsData['totalPages']) {
                                if ($end < $bonsData['totalPages'] - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $bonsData['totalPages'] . '">' . $bonsData['totalPages'] . '</a></li>';
                            }
                            ?>
                            <li class="page-item <?= ($bonsData['page'] >= $bonsData['totalPages']) ? 'disabled' : '' ?>">
                                <a class="page-link" href="#" data-page="<?= $bonsData['page'] + 1 ?>"><i class="bi bi-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODALE VALIDATION DES VENTES (style inventaire) -->
<!-- ========================================================= -->
<div class="modal-overlay" id="validationModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="bi bi-check2-circle"></i> Validation des ventes en attente</h3>
            <button class="modal-close" onclick="closeModal('validationModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <?php if ($validationMessage): ?>
                <div class="alert alert-<?= $validationMessageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                    <?= e($validationMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="product-ref">
                <span class="ref-name"><i class="bi bi-info-circle"></i> Ventes en attente de validation</span>
                <span class="ref-stock"><?= count($ventesEnAttente) ?> vente(s) en attente</span>
            </div>

            <?php if (empty($ventesEnAttente)): ?>
                <p class="text-center text-muted py-4"><i class="bi bi-check-circle fs-1 d-block mb-2 opacity-50"></i>Aucune vente en attente.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Référence</th>
                                <th>Client</th>
                                <th>Boutique</th>
                                <th>Date</th>
                                <th>Facture</th>
                                <th>Lignes</th>
                                <th>Montant</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ventesEnAttente as $v): ?>
                                <tr>
                                    <td><strong><?= e($v['reference_liee']) ?></strong></td>
                                    <td><?= e($v['nom_prenom_contact']) ?></td>
                                    <td><?= e($v['nom_boutique']) ?></td>
                                    <td><?= e($v['date_commande']) ?></td>
                                    <td><?= e($v['facture_id']) ?></td>
                                    <td><?= (int)$v['nb_lignes'] ?></td>
                                    <td><?= fmt($v['montant_total']) ?> F</td>
                                    <td class="text-end" style="white-space:nowrap;">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Valider cette vente ? Le stock sera décrémenté.');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                            <input type="hidden" name="reference_liee" value="<?= e($v['reference_liee']) ?>">
                                            <input type="hidden" name="action_validation" value="valider">
                                            <button class="btn-sm btn-success"><i class="bi-check2"></i> Valider</button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Annuler cette vente ? La réservation de stock sera libérée.');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                            <input type="hidden" name="reference_liee" value="<?= e($v['reference_liee']) ?>">
                                            <input type="hidden" name="action_validation" value="annuler">
                                            <button class="btn-sm btn-outline-danger"><i class="bi-x-lg"></i> Annuler</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-foot">
            <button class="btn-secondary" onclick="closeModal('validationModal')">Fermer</button>
            <button class="btn-success" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Rafraîchir</button>
        </div>
    </div>
</div>

<!-- ===== MODAL NOUVEAU BON ===== -->
<div class="modal fade" id="bonModal" tabindex="-1" aria-labelledby="bonModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="bonModalLabel"><i class="bi bi-file-earmark-text text-primary me-2"></i> Nouveau bon de commande client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="bonForm">
                <input type="hidden" name="action" value="valider_bon_vente">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Client <span class="text-danger">*</span></label>
                            <select name="client_id" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= e($c['code_contact']) ?>"><?= e($c['nom_prenom_contact']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Boutique <span class="text-danger">*</span></label>
                            <select name="boutique_id" id="boutiqueSelect" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($boutiques as $b): ?>
                                    <option value="<?= e($b['code_boutique']) ?>" data-adresse="<?= e($b['adresse_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Date du bon</label>
                            <input type="date" name="date_bon" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">N° (auto)</label>
                            <input type="text" class="form-control" value="BC-<?= date('Y') ?>-XXXX" disabled>
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Lieu de livraison (adresse boutique)</label>
                            <input type="text" name="lieu_livraison" id="lieuLivraison" class="form-control" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Facture associée (optionnelle)</label>
                            <select name="facture_id" class="form-select">
                                <option value="">-- Aucune --</option>
                                <?php foreach ($factures as $f): ?>
                                    <option value="<?= e($f['numero_facture']) ?>">
                                        <?= e($f['numero_facture']) ?> - <?= e($f['titre_facture']) ?> (<?= e($f['date_facture']) ?>) - <?= fmt($f['montant_ttc']) ?> F
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <hr>

                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-box me-1"></i> Lignes de commande</h6>
                    <div id="lignesContainer">
                        <div class="ligne-produit" data-index="0">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Produit <span class="text-danger">*</span></label>
                                    <select name="produit_id[]" class="form-select select-produit" required>
                                        <option value="">-- Choisir --</option>
                                        <?php foreach ($produits as $p): ?>
                                            <option value="<?= e($p['code_produit']) ?>"><?= e($p['titre_produit']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-semibold">Lot/unité</label>
                                    <select name="lot_id[]" class="form-select select-lot">
                                        <option value="">Unité</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-semibold">Unité affichée</label>
                                    <input type="text" name="unite_affichage[]" class="form-control unite-affichage" readonly placeholder="Auto">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label fw-semibold">Qté (lots)</label>
                                    <input type="number" name="quantite[]" class="form-control quantite" min="1" value="1" required oninput="mettreAJourPrix(this)">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-semibold">Prix unitaire (base) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" name="prix_unitaire[]" class="form-control prix-unitaire" readonly value="0">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-semibold">Total ligne</label>
                                    <input type="text" name="total_ligne[]" class="form-control total-ligne" readonly value="0">
                                </div>
                                <div class="col-md-auto d-flex align-items-end">
                                    <button type="button" class="btn btn-danger btn-sm supprimer-ligne" onclick="supprimerLigne(this)" style="display:none;"><i class="bi bi-trash3"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary btn-ajouter" onclick="ajouterLigne()"><i class="bi bi-plus"></i> Ajouter une ligne</button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Valider le bon</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Formulaires cachés pour actions -->
<form method="post" id="actionForm" style="display:none;">
    <input type="hidden" name="action" id="actionField">
    <input type="hidden" name="edit_numero" id="editNumeroField">
    <input type="hidden" name="view_numero" id="viewNumeroField">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    // ---- Données injectées ----
    const lotsParProduit = <?= json_encode($lotsParProduit) ?>;

    // ---- Mise à jour des lots et unité affichée ----
    function mettreAJourLots(selectProduit) {
        const ligne = selectProduit.closest('.ligne-produit');
        const lotSelect = ligne.querySelector('.select-lot');
        const uniteAff = ligne.querySelector('.unite-affichage');
        const produitId = selectProduit.value;

        const lots = lotsParProduit[produitId] || [];
        let options = '<option value="">Unité</option>';
        lots.forEach(lot => {
            options += `<option value="${lot.code_lot_produit}" data-unite="${lot.titre_lot}" data-facteur="${lot.unites_par_lot}">${lot.titre_lot}</option>`;
        });
        lotSelect.innerHTML = options;

        const selectedLot = lotSelect.options[lotSelect.selectedIndex];
        uniteAff.value = selectedLot ? (selectedLot.text || 'Unité') : 'Unité';
        // Mettre à jour le prix après changement de lot
        mettreAJourPrix(ligne.querySelector('.quantite'));
    }

    // ---- Fonction pour interroger le serveur et récupérer le prix unitaire ----
    function mettreAJourPrix(quantiteInput) {
        const ligne = quantiteInput.closest('.ligne-produit');
        const produitId = ligne.querySelector('.select-produit').value;
        const boutiqueId = document.getElementById('boutiqueSelect').value;
        const lotId = ligne.querySelector('.select-lot').value;
        const quantite = parseFloat(quantiteInput.value) || 0;

        if (!produitId || !boutiqueId || quantite <= 0) {
            ligne.querySelector('.prix-unitaire').value = '0';
            calculerTotal(ligne);
            return;
        }

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'get_prix_unitaire',
                produit_id: produitId,
                boutique_id: boutiqueId,
                lot_id: lotId,
                quantite: quantite
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    ligne.querySelector('.prix-unitaire').value = response.prix;
                } else {
                    ligne.querySelector('.prix-unitaire').value = '0';
                    alert('Erreur : ' + response.message);
                }
                calculerTotal(ligne);
            },
            error: function() {
                ligne.querySelector('.prix-unitaire').value = '0';
                alert('Erreur réseau lors de la récupération du prix.');
            }
        });
    }

    // ---- Calcul du total ligne ----
    function calculerTotal(ligne) {
        const quantite = parseFloat(ligne.querySelector('.quantite').value) || 0;
        const prix = parseFloat(ligne.querySelector('.prix-unitaire').value) || 0;
        const lotSelect = ligne.querySelector('.select-lot');
        const selectedOption = lotSelect.options[lotSelect.selectedIndex];
        let facteur = 1;
        if (selectedOption && selectedOption.dataset.facteur) {
            facteur = parseFloat(selectedOption.dataset.facteur) || 1;
        }
        const quantiteBase = quantite * facteur;
        const total = quantiteBase * prix;
        ligne.querySelector('.total-ligne').value = total.toFixed(0);
    }

    // ---- Événements ----
    $(document).on('change', '.select-produit', function() {
        mettreAJourLots(this);
    });

    $(document).on('change', '.select-lot', function() {
        const ligne = this.closest('.ligne-produit');
        const uniteAff = ligne.querySelector('.unite-affichage');
        const selected = this.options[this.selectedIndex];
        uniteAff.value = selected ? (selected.text || 'Unité') : 'Unité';
        mettreAJourPrix(ligne.querySelector('.quantite'));
    });

    $(document).on('input', '.quantite', function() {
        mettreAJourPrix(this);
    });

    // ---- Ajouter / Supprimer lignes ----
    function ajouterLigne() {
        const container = document.getElementById('lignesContainer');
        const original = container.querySelector('.ligne-produit');
        const clone = original.cloneNode(true);
        clone.querySelector('.select-produit').value = '';
        clone.querySelector('.select-lot').innerHTML = '<option value="">Unité</option>';
        clone.querySelector('.unite-affichage').value = '';
        clone.querySelector('.quantite').value = 1;
        clone.querySelector('.prix-unitaire').value = '0';
        clone.querySelector('.total-ligne').value = '0';
        clone.querySelector('.supprimer-ligne').style.display = 'inline-block';
        container.appendChild(clone);
    }
    window.ajouterLigne = ajouterLigne;

    function supprimerLigne(btn) {
        const ligne = btn.closest('.ligne-produit');
        if (document.querySelectorAll('.ligne-produit').length > 1) {
            ligne.remove();
        } else {
            alert('Il faut au moins une ligne.');
        }
    }
    window.supprimerLigne = supprimerLigne;

    // ---- Initialisation de la première ligne ----
    $('.select-produit').each(function() {
        if ($(this).val()) {
            mettreAJourLots(this);
        }
    });

    // ---- Modal Nouveau bon ----
    const bonModal = new bootstrap.Modal(document.getElementById('bonModal'));
    $('#addBtn').on('click', function() {
        $('#bonForm')[0].reset();
        $('#bonForm input[name="date_bon"]').val(new Date().toISOString().split('T')[0]);
        $('#lignesContainer .ligne-produit:not(:first)').remove();
        const first = $('#lignesContainer .ligne-produit:first');
        first.find('.select-produit').val('');
        first.find('.select-lot').html('<option value="">Unité</option>');
        first.find('.unite-affichage').val('');
        first.find('.quantite').val(1);
        first.find('.prix-unitaire').val('0');
        first.find('.total-ligne').val('0');
        first.find('.supprimer-ligne').hide();
        bonModal.show();
    });

    $('#boutiqueSelect').on('change', function() {
        const adresse = $(this).find(':selected').data('adresse') || '';
        $('#lieuLivraison').val(adresse);
        document.querySelectorAll('.ligne-produit').forEach(function(ligne) {
            const qteInput = ligne.querySelector('.quantite');
            if (qteInput) mettreAJourPrix(qteInput);
        });
    });

    // ---- Modale Validation (style inventaire) ----
    function openModal(id) {
        document.getElementById(id).classList.add('show');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }
    window.openModal = openModal;
    window.closeModal = closeModal;

    $('#validationBtn').on('click', function() {
        openModal('validationModal');
    });

    // Fermeture de la modale au clic sur l'overlay
    document.querySelectorAll('.modal-overlay').forEach(m => {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });

    // ---- Recherche AJAX ----
    function rechercher(page) {
        page = page || 1;
        var search = $('#searchInput').val();
        var client = $('#clientFilter').val();
        var boutique = $('#boutiqueFilter').val();
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                ajax: 1,
                search: search,
                client_filter: client,
                boutique_filter: boutique,
                page: page
            },
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' bon(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
                $('.page-link').off('click').on('click', function(e) {
                    e.preventDefault();
                    var p = $(this).data('page');
                    if (p) rechercher(p);
                });
            },
            error: function() { alert('Erreur lors de la recherche.'); }
        });
    }

    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#clientFilter, #boutiqueFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#filterBtn').on('click', function() { rechercher(1); });
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#clientFilter, #boutiqueFilter').selectpicker('val', '');
        rechercher(1);
    });

    // Auto-fermeture des alertes
    setTimeout(function() { $('.alert.alert-success').alert('close'); }, 5000);
});
</script>
</body>
</html>