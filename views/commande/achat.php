<?php
// views/achats/index.php – Gestion des bons de commande fournisseurs (design dashboard)
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

function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---- LISTES ----
$fournisseurs = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Fournisseur' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique, adresse_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$produits = $pdo->query("SELECT code_produit, titre_produit, prix_produit, prix_fournisseur FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);

// ---- PRÉCHARGEMENT DES LOTS ----
$lotsParProduit = [];
$stmtLots = $pdo->query("SELECT produit_id, code_lot_produit, titre_lot, unites_par_lot FROM lot_produit WHERE etat_lot = 'Actif'");
while ($lot = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
    $lotsParProduit[$lot['produit_id']][] = $lot;
}

// ---- PRIX FOURNISSEUR ----
$prixFournisseur = [];
foreach ($produits as $p) {
    $prixFournisseur[$p['code_produit']] = $p['prix_fournisseur'] ?? $p['prix_produit'] ?? 0;
}

// ---- TRAITEMENT DU FORMULAIRE ----
$message = '';
$messageType = '';
$bonData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'valider_bon') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        $fournisseurId = $_POST['fournisseur_id'] ?? '';
        $dateBon = $_POST['date_bon'] ?? date('Y-m-d');
        $boutiqueId = $_POST['boutique_id'] ?? '';
        $dateLivraison = $_POST['date_livraison'] ?? null;
        $numBon = 'BC-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $produitsPost = $_POST['produit_id'] ?? [];
        $lots = $_POST['lot_id'] ?? [];
        $quantites = $_POST['quantite'] ?? [];
        $prixUnitaires = $_POST['prix_unitaire'] ?? [];
        $totalBon = 0;

        if (empty($fournisseurId) || empty($boutiqueId) || empty($produitsPost)) {
            $message = "Veuillez sélectionner un fournisseur, une boutique et au moins un produit.";
            $messageType = 'error';
        } else {
            $errors = false;
            $lignesValides = [];
            foreach ($produitsPost as $index => $produitId) {
                if (empty($produitId)) continue;
                $quantite = intval($quantites[$index] ?? 0);
                $prix = floatval($prixUnitaires[$index] ?? 0);
                if ($quantite <= 0 || $prix < 0) {
                    $errors = true;
                    $message = "Vérifiez les quantités et prix unitaires (positifs).";
                    $messageType = 'error';
                    break;
                }
                $lotId = $lots[$index] ?? null;
                $facteur = 1;
                $uniteAff = 'Unité';
                if (!empty($lotId)) {
                    $stmtLot = $pdo->prepare("SELECT unites_par_lot, titre_lot FROM lot_produit WHERE code_lot_produit = ? AND produit_id = ?");
                    $stmtLot->execute([$lotId, $produitId]);
                    $lotData = $stmtLot->fetch(PDO::FETCH_ASSOC);
                    if ($lotData) {
                        $facteur = intval($lotData['unites_par_lot'] ?: 1);
                        $uniteAff = $lotData['titre_lot'];
                    }
                }
                $quantiteBase = $quantite * $facteur;
                $totalLigne = $quantiteBase * $prix;
                $totalBon += $totalLigne;
                $lignesValides[] = [
                    'produit_id' => $produitId,
                    'lot_id' => $lotId,
                    'quantite_saisie' => $quantite,
                    'facteur' => $facteur,
                    'quantite_base' => $quantiteBase,
                    'prix_unitaire' => $prix,
                    'total_ligne' => $totalLigne,
                    'unite_affichage' => $uniteAff
                ];
            }

            if (!$errors && !empty($lignesValides)) {
                try {
                    $pdo->beginTransaction();
                    foreach ($lignesValides as $ligne) {
                        $numCommandeUnique = $numBon . '-' . date('His') . rand(100, 999);
                        $stmt = $pdo->prepare("INSERT INTO commande 
                            (numero_commande, produit_id, contact_id, statut_id, date_commande, heure_commande, 
                             prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id, 
                             boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion,
                             reference_liee, date_livraison_recue)
                            VALUES (?, ?, ?, 'Achat', ?, CURTIME(), ?, ?, ?, ?, ?, ?, 'Valider', ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $numCommandeUnique,
                            $ligne['produit_id'],
                            $fournisseurId,
                            $dateBon,
                            $ligne['prix_unitaire'],
                            0,
                            $ligne['quantite_base'],
                            $ligne['total_ligne'],
                            $user['id'],
                            $boutiqueId,
                            $ligne['lot_id'],
                            $ligne['unite_affichage'],
                            $ligne['facteur'],
                            $numBon,
                            $dateLivraison
                        ]);

                        // Mise à jour du stock en unité de base (par boutique)
                        $pdo->prepare("UPDATE stock_boutique SET quantite = quantite + ? WHERE produit_id = ? AND boutique_id = ?")
                            ->execute([$ligne['quantite_base'], $ligne['produit_id'], $boutiqueId]);

                        // Mise à jour du stock global du produit (unité de base)
                        $pdo->prepare("UPDATE produit SET stock_produit = COALESCE(stock_produit,0) + ? WHERE code_produit = ?")
                            ->execute([$ligne['quantite_base'], $ligne['produit_id']]);

                        // ---- Mise à jour du stock de lots pour cette boutique ----
                        if (!empty($ligne['lot_id'])) {
                            $stmtLotUpdate = $pdo->prepare("
                                INSERT INTO stock_boutique (produit_id, boutique_id, lot_produit_id, quantite_lot)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE 
                                    lot_produit_id = VALUES(lot_produit_id),
                                    quantite_lot = quantite_lot + VALUES(quantite_lot)
                            ");
                            $stmtLotUpdate->execute([$ligne['produit_id'], $boutiqueId, $ligne['lot_id'], $ligne['quantite_saisie']]);
                        }
                    }
                    $pdo->commit();
                    $message = "Bon de commande $numBon enregistré avec " . count($lignesValides) . " ligne(s).";
                    $messageType = 'success';
                    $bonData = [
                        'num' => $numBon,
                        'date' => $dateBon,
                        'fournisseur' => $fournisseurId,
                        'boutique' => $boutiqueId,
                        'date_livraison' => $dateLivraison,
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

// ---- IMPRESSION PDF (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'print_bon_pdf') {
    $bonId = $_POST['bon_id'] ?? '';
    if (empty($bonId)) die("Numéro de bon manquant.");

    $stmt = $pdo->prepare("
        SELECT c.*, p.titre_produit, 
               ct.nom_prenom_contact AS fournisseur_nom, ct.telephone_contact AS fournisseur_tel, 
               ct.email_contact AS fournisseur_email, ct.adresse_contact AS fournisseur_adresse,
               b.nom_boutique, b.adresse_boutique, b.telephone_boutique, b.email_boutique, b.ville_boutique, b.pays_boutique
        FROM commande c
        LEFT JOIN produit p ON c.produit_id = p.code_produit
        LEFT JOIN contact ct ON c.contact_id = ct.code_contact
        LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
        WHERE c.reference_liee = ? AND c.statut_id = 'Achat' AND c.etat_commande = 'Valider'
        ORDER BY c.numero_commande
    ");
    $stmt->execute([$bonId]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($lignes)) die("Aucune ligne trouvée.");
    $bonInfo = $lignes[0];
    $totalBon = array_sum(array_column($lignes, 'montant_commande'));

    // ---- PDF (le même design professionnel) ----
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
    $pdf->Cell(297, 10, $toLatin('BON DE COMMANDE'), 0, 1, 'C');

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Rect(200, $yStart + 5, 70, 8, 'F');
    $pdf->Text(205, $yStart + 11, $toLatin('N° ' . $bonId));

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text(200, $yStart + 20, $toLatin('Date du bon : ' . date('d/m/Y', strtotime($bonInfo['date_commande']))));
    $pdf->Text(200, $yStart + 26, $toLatin('Livraison souhaitée : ' . (!empty($bonInfo['date_livraison_recue']) ? date('d/m/Y', strtotime($bonInfo['date_livraison_recue'])) : '—')));

    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(10, 42, 287, 42);
    $yBlocks = 48;
    $drawAddressBlock = function ($pdf, $x, $y, $w, $title, $name, $address, $phone, $email) use ($toLatin, $blueDark, $grayBg) {
        $h = 38;
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
        'DESTINATAIRE',
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
        'FOURNISSEUR',
        $bonInfo['fournisseur_nom'] ?? '',
        $bonInfo['fournisseur_adresse'] ?? '',
        $bonInfo['fournisseur_tel'] ?? '',
        $bonInfo['fournisseur_email'] ?? ''
    );

    $colWidths = [22, 122, 28, 18, 22, 28, 37];
    $headers = ['RÉF.', 'DÉSIGNATION', 'LOT/UNITÉ', 'QTÉ (lots)', 'QTÉ (base)', 'P.U. (FCFA)', 'MONTANT (FCFA)'];
    $headerH = 8;
    $rowH = 8;
    $yTable = 100;

    $drawTableHeader = function () use ($pdf, $colWidths, $headers, $headerH, $toLatin, $blueDark, &$yTable) {
        $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 7);
        $x = 10;
        foreach ($headers as $i => $h) {
            $label = $toLatin($h);
            $pdf->Rect($x, $yTable, $colWidths[$i], $headerH, 'F');
            $pdf->Text($x + ($colWidths[$i] / 2) - ($pdf->GetStringWidth($label) / 2), $yTable + 5.5, $label);
            $x += $colWidths[$i];
        }
    };
    $drawTableHeader();

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 8);
    $yCurrent = $yTable + $headerH;

    foreach ($lignes as $ligne) {
        if ($yCurrent + $rowH > 200) {
            $pdf->AddPage();
            $yTable = 20;
            $yCurrent = $yTable + $headerH;
            $drawTableHeader();
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 8);
        }

        $ref = $ligne['produit_id'];
        $des = substr($ligne['titre_produit'] ?? $ligne['produit_id'], 0, 60);
        $unite = $ligne['unite_affichage'] ?? 'Unité';
        $facteur = intval($ligne['facteur_conversion'] ?: 1);
        $qtLots = $ligne['quantite_commande'] / max(1, $facteur);
        $qtBase = $ligne['quantite_commande'];
        $pu = (float)$ligne['prix_achat'];
        $totalLigne = (float)$ligne['montant_commande'];

        $data = [$ref, $des, $unite, number_format($qtLots, 0), $qtBase, number_format($pu, 0, ',', ' '), number_format($totalLigne, 0, ',', ' ')];
        $x = 10;
        foreach ($data as $i => $val) {
            $align = ($i >= 3 && $i != 4) ? 'C' : (($i >= 5) ? 'R' : 'L');
            $label = $toLatin((string)$val);
            $txtX = ($align == 'R') ? $x + $colWidths[$i] - 2 - $pdf->GetStringWidth($label) : (($align == 'C') ? $x + ($colWidths[$i] / 2) - ($pdf->GetStringWidth($label) / 2) : $x + 1);
            $pdf->Rect($x, $yCurrent, $colWidths[$i], $rowH, 'D');
            $pdf->Text($txtX, $yCurrent + 5.5, $label);
            $x += $colWidths[$i];
        }
        $yCurrent += $rowH;
    }

    $yAfterLines = $yCurrent + 5;
    $pageHeight = 210;
    $marginBottom = 15;
    if ($yAfterLines + 50 > $pageHeight - $marginBottom) {
        $pdf->AddPage();
        $yAfterLines = 20;
    }

    $yObs = $yAfterLines;
    $wObs = 170;
    $hObs = 28;
    $pdf->SetDrawColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect(10, $yObs, $wObs, $hObs, 'D');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(12, $yObs + 6, $toLatin('Observations :'));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(12, $yObs + 9);
    $pdf->MultiCell($wObs - 4, 5, $toLatin("Merci de votre confiance.\nVeuillez respecter les délais de livraison."), 0, 'L');

    $xTot = 10 + $wObs + 10;
    $wTot = 287 - $xTot;
    $hTot = 7;
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Rect($xTot, $yObs, $wTot, $hTot, 'D');
    $pdf->Text($xTot + 2, $yObs + 5, $toLatin('TOTAL HT'));
    $pdf->SetXY($xTot, $yObs + 5);
    $pdf->Cell($wTot - 2, 0, number_format($totalBon, 0, ',', ' '), 0, 0, 'R');

    $pdf->SetFillColor($blueLight[0], $blueLight[1], $blueLight[2]);
    $pdf->Rect($xTot, $yObs + $hTot, $wTot, $hTot + 2, 'FD');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text($xTot + 2, $yObs + $hTot + 5.5, $toLatin('NET À PAYER'));
    $pdf->SetXY($xTot, $yObs + $hTot + 5.5);
    $pdf->Cell($wTot - 2, 0, number_format($totalBon, 0, ',', ' '), 0, 0, 'R');

    $ySig = $yObs + $hObs + 8;
    if ($ySig + 26 > $pageHeight - $marginBottom) {
        $pdf->AddPage();
        $ySig = 20;
    }
    $hSig = 26;
    $wSig = 133;
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Rect(10, $ySig, $wSig, $hSig, 'D');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(12, $ySig + 5, $toLatin('Le Destinataire'));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text(12, $ySig + 10, $toLatin('Nom et Signature'));

    $pdf->SetDrawColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Rect(60, $ySig + 5, 70, $hSig - 6, 'D');
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(63, $ySig + 9, $toLatin($bonInfo['nom_boutique'] ?? ''));
    $pdf->SetFont('Arial', '', 6);
    $pdf->Text(63, $ySig + 13, $toLatin($bonInfo['adresse_boutique'] ?? ''));
    $pdf->Text(63, $ySig + 17, $toLatin('Tél. : ' . ($bonInfo['telephone_boutique'] ?? '')));

    $xClient = 10 + $wSig + 21;
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Rect($xClient, $ySig, $wSig, $hSig, 'D');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text($xClient + 2, $ySig + 5, $toLatin('Le Fournisseur'));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text($xClient + 2, $ySig + 10, $toLatin('Nom et Signature'));

    while (ob_get_level()) ob_end_clean();
    $pdf->Output('I', 'Bon_' . $bonId . '.pdf');
    exit;
}

// ---- RÉCUPÉRATION DE L'HISTORIQUE ----
function getBons($pdo, $search, $fournisseur_filter, $boutique_filter, $page, $perPage = 10)
{
    $sql = "SELECT 
                reference_liee AS bon_id,
                MAX(date_commande) AS date_bon,
                MAX(ct.nom_prenom_contact) AS fournisseur,
                MAX(ct.telephone_contact) AS fournisseur_tel,
                MAX(ct.email_contact) AS fournisseur_email,
                MAX(ct.adresse_contact) AS fournisseur_adresse,
                MAX(boutique_id) AS boutique_id,
                MAX(date_livraison_recue) AS date_livraison,
                COUNT(*) AS nb_lignes,
                SUM(montant_commande) AS total_bon
            FROM commande c
            LEFT JOIN contact ct ON c.contact_id = ct.code_contact
            WHERE statut_id = 'Achat' 
              AND etat_commande = 'Valider'
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
    if (!empty($fournisseur_filter)) {
        $sql .= " AND ct.code_contact = ?";
        $params[] = $fournisseur_filter;
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
                   facteur_conversion, prix_achat, montant_commande
            FROM commande
            WHERE reference_liee = ? AND statut_id = 'Achat'
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
$fournisseur_filter = trim($_POST['fournisseur_filter'] ?? '');
$boutique_filter = trim($_POST['boutique_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

$bonsData = getBons($pdo, $search, $fournisseur_filter, $boutique_filter, $page, 10);

// ---- AJAX ----
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $fournisseur_filter = trim($_POST['fournisseur_filter'] ?? '');
    $boutique_filter = trim($_POST['boutique_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    $data = getBons($pdo, $search, $fournisseur_filter, $boutique_filter, $page, 10);
    ob_start();
    if (empty($data['bons'])): ?>
        <tr>
            <td colspan="8" class="text-center py-5 text-muted"><i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>Aucun bon trouvé</td>
        </tr>
        <?php else: foreach ($data['bons'] as $bon): ?>
            <tr>
                <td class="td-bold"><?= e($bon['bon_id']) ?></td>
                <td><?= e($bon['date_bon']) ?></td>
                <td><?= e($bon['fournisseur'] ?? '—') ?></td>
                <td><?= $bon['nb_lignes'] ?></td>
                <td><strong><?= fmt($bon['total_bon']) ?> F</strong></td>
                <td><?= e($bon['adresse_boutique'] ?? '—') ?></td>
                <td><?= e($bon['date_livraison'] ?? '—') ?></td>
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
        <?php endforeach;
    endif;
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
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des bons de commande</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
    <style>
        /* ===== STYLE DASHBOARD ===== */
        :root {
            --color-primary: #4f46e5;
            --color-primary-dark: #3730a3;
            --color-primary-soft: #eef2ff;
            --color-success: #10b981;
            --color-success-soft: #d1fae5;
            --color-warning: #f59e0b;
            --color-warning-soft: #fef3c7;
            --color-danger: #ef4444;
            --color-danger-soft: #fee2e2;
            --color-gray-50: #f8fafc;
            --color-gray-100: #f1f5f9;
            --color-gray-200: #e2e8f0;
            --color-gray-300: #cbd5e1;
            --color-gray-400: #94a3b8;
            --color-gray-500: #64748b;
            --color-gray-600: #475569;
            --color-gray-700: #334155;
            --color-gray-800: #1e293b;
            --color-gray-900: #0f172a;
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --bg-muted: #f8fafc;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-tertiary: #64748b;
            --text-quaternary: #94a3b8;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.08);
            --radius-sm: 10px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            padding: 30px 20px;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .container-crud {
            max-width: 1400px;
            margin: 0 auto;
        }

        .data-table-wrap {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            min-width: 900px;
            margin-bottom: 0;
        }

        .table> :not(caption)>*>* {
            padding: 8px 12px;
        }

        .table thead th {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-quaternary);
            background: var(--bg-muted);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background var(--transition-base);
        }

        .table tbody tr:hover {
            background: var(--color-primary-soft);
        }

        .table tbody td {
            vertical-align: middle;
            color: var(--text-secondary);
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .td-bold {
            font-weight: 700;
            color: var(--text-primary) !important;
        }

        .td-semi {
            font-weight: 500;
            color: var(--text-primary) !important;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 12px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .status-badge .sdot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-badge.on {
            background: var(--color-success-soft);
            color: #059669;
        }

        .status-badge.off {
            background: var(--color-danger-soft);
            color: #dc2626;
        }

        .act-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-quaternary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-base);
            font-size: 0.8rem;
        }

        .act-btn:hover {
            transform: scale(1.1);
        }

        .act-btn.v:hover {
            color: var(--color-primary);
            background: var(--color-primary-soft);
            border-color: rgba(79, 70, 229, 0.15);
        }

        .act-btn.e:hover {
            color: var(--color-warning);
            background: var(--color-warning-soft);
            border-color: rgba(245, 158, 11, 0.15);
        }

        .act-btn.d:hover {
            color: var(--color-danger);
            background: var(--color-danger-soft);
            border-color: rgba(239, 68, 68, 0.15);
        }

        .search-inline {
            display: flex;
            align-items: center;
            background: var(--bg-muted);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0 12px;
            height: 42px;
            min-width: 160px;
            transition: all var(--transition-base);
        }

        .search-inline:focus-within {
            border-color: var(--color-primary);
            background: var(--bg-surface);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.08);
        }

        .search-inline i {
            color: var(--text-quaternary);
            font-size: 0.8rem;
        }

        .search-inline input,
        .search-inline select {
            background: none;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: inherit;
            width: 100%;
            margin-left: 8px;
        }

        .search-inline select {
            padding-right: 20px;
            cursor: pointer;
        }

        .search-inline input::placeholder {
            color: var(--text-quaternary);
        }

        .btn-primary {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--color-primary-dark);
            border-color: var(--color-primary-dark);
            color: #fff;
        }

        .btn-outline-secondary {
            color: var(--text-secondary);
            border-color: var(--border-color);
        }

        .btn-outline-secondary:hover {
            background: var(--color-gray-100);
            border-color: var(--color-gray-300);
        }

        .modal-content {
            border-radius: var(--radius-md);
            border: none;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-muted);
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
            background: var(--bg-muted);
        }

        .page-heading h2 {
            font-weight: 800;
        }

        .text-tertiary {
            color: var(--text-tertiary);
        }

        .pagination .page-link {
            color: var(--color-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin: 0 2px;
            padding: 6px 14px;
            font-weight: 500;
        }

        .pagination .page-link:hover {
            background: var(--color-primary-soft);
            border-color: var(--color-primary);
        }

        .pagination .page-item.active .page-link {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: #fff;
        }

        .pagination .page-item.disabled .page-link {
            color: var(--text-quaternary);
            border-color: var(--border-color);
        }

        .bootstrap-select .dropdown-toggle .filter-option {
            color: var(--text-primary);
        }

        .bootstrap-select .dropdown-menu {
            border-radius: var(--radius-sm);
            border-color: var(--border-color);
        }

        .bootstrap-select .dropdown-menu .bs-searchbox input {
            border-radius: 6px;
            border: 1px solid var(--border-color);
            padding: 8px 12px;
        }

        .bootstrap-select .dropdown-menu .bs-searchbox input:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.08);
        }

        .ligne-produit {
            background: var(--bg-muted);
            padding: 15px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            margin-bottom: 10px;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .data-table-wrap {
            animation: fadeUp .4s ease both;
        }

        @media (max-width:768px) {
            body {
                padding: 10px;
            }

            .table thead th,
            .table tbody td {
                font-size: 0.65rem;
                padding: 4px 6px;
            }

            .table .act-btn {
                width: 26px;
                height: 26px;
                font-size: 0.65rem;
            }

            .status-badge {
                font-size: 0.6rem;
                padding: 0 8px;
            }

            .page-heading h2 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>

<body>
    <div class="container-crud">
        <!-- En-tête -->
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
            <div class="page-heading">
                <h2 class="fw-800 mb-0">Gestion des bons de commande</h2>
                <p class="text-tertiary mt-1">Suivez vos commandes fournisseurs</p>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau bon</button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= e($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Barre de recherche -->
        <div class="bg-light p-3 rounded-3 mb-3 border">
            <form id="searchForm" method="post" onsubmit="return false;">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="page" id="pageInput" value="<?= e($page) ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="searchInput" class="form-label fw-semibold small">Recherche</label>
                        <div class="search-inline" style="min-width:100%; height:42px;">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search" id="searchInput" placeholder="N° bon, fournisseur..." value="<?= e($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="fournisseurFilter" class="form-label fw-semibold small">Fournisseur</label>
                        <select name="fournisseur_filter" id="fournisseurFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un fournisseur...">
                            <option value="">Tous</option>
                            <?php foreach ($fournisseurs as $f): ?>
                                <option value="<?= e($f['code_contact']) ?>" <?= ($fournisseur_filter == $f['code_contact']) ? 'selected' : '' ?>><?= e($f['nom_prenom_contact']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="boutiqueFilter" class="form-label fw-semibold small">Boutique</label>
                        <select name="boutique_filter" id="boutiqueFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
                            <option value="">Toutes</option>
                            <?php foreach ($boutiques as $b): ?>
                                <option value="<?= e($b['code_boutique']) ?>" <?= ($boutique_filter == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-primary w-100" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-outline-secondary w-100" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tableau -->
        <div class="data-table-wrap" id="tableWrapper">
            <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
                <h5 class="mb-0 fw-bold">Liste des bons</h5>
                <span class="text-muted small" id="totalCount"><?= $bonsData['total'] ?? 0 ?> bon(s) - Page <?= $bonsData['page'] ?? 1 ?> / <?= max(1, $bonsData['totalPages'] ?? 1) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>N° bon</th>
                            <th>Date</th>
                            <th>Fournisseur</th>
                            <th>Articles</th>
                            <th>Total</th>
                            <th>Lieu de livraison</th>
                            <th>Date livraison</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (empty($bonsData['bons'])): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted"><i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>Aucun bon trouvé</td>
                            </tr>
                            <?php else: foreach ($bonsData['bons'] as $bon): ?>
                                <tr>
                                    <td class="td-bold"><?= e($bon['bon_id']) ?></td>
                                    <td><?= e($bon['date_bon']) ?></td>
                                    <td><?= e($bon['fournisseur'] ?? '—') ?></td>
                                    <td><?= $bon['nb_lignes'] ?></td>
                                    <td><strong><?= fmt($bon['total_bon']) ?> F</strong></td>
                                    <td><?= e($bon['adresse_boutique'] ?? '—') ?></td>
                                    <td><?= e($bon['date_livraison'] ?? '—') ?></td>
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
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="paginationContainer">
                <?php if ($bonsData['totalPages'] > 1): ?>
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

    <!-- Modal Nouveau bon -->
    <div class="modal fade" id="bonModal" tabindex="-1" aria-labelledby="bonModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="bonModalLabel"><i class="bi bi-file-earmark-text text-primary me-2"></i> Nouveau bon de commande</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="bonForm">
                    <input type="hidden" name="action" value="valider_bon">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Fournisseur <span class="text-danger">*</span></label>
                                <select name="fournisseur_id" class="form-select" required>
                                    <option value="">-- Sélectionner --</option>
                                    <?php foreach ($fournisseurs as $f): ?>
                                        <option value="<?= e($f['code_contact']) ?>"><?= e($f['nom_prenom_contact']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Boutique destinataire <span class="text-danger">*</span></label>
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
                                <label class="form-label fw-semibold">Date de livraison souhaitée</label>
                                <input type="date" name="date_livraison" class="form-control">
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
                                                <option value="<?= e($p['code_produit']) ?>" data-prix="<?= $p['prix_fournisseur'] ?>"><?= e($p['titre_produit']) ?></option>
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
                                        <input type="number" name="quantite[]" class="form-control quantite" min="1" value="1" required oninput="calculerLigne(this)">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Prix unitaire (base) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" name="prix_unitaire[]" class="form-control prix-unitaire" min="0" required oninput="calculerLigne(this)">
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
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x"></i> Annuler</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Valider le bon</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.selectpicker').selectpicker('destroy');
            $('.selectpicker').selectpicker();

            // ---- Données injectées par PHP ----
            const lotsParProduit = <?= json_encode($lotsParProduit) ?>;
            const prixFournisseur = <?= json_encode($prixFournisseur) ?>;

            // ---- Mise à jour des lots, prix unitaire, unité affichée ----
            function mettreAJourLotsEtPrix(selectProduit) {
                const ligne = selectProduit.closest('.ligne-produit');
                const lotSelect = ligne.querySelector('.select-lot');
                const uniteAff = ligne.querySelector('.unite-affichage');
                const prixInput = ligne.querySelector('.prix-unitaire');
                const produitId = selectProduit.value;

                // Mise à jour des lots
                const lots = lotsParProduit[produitId] || [];
                let options = '<option value="">Unité</option>';
                lots.forEach(lot => {
                    options += `<option value="${lot.code_lot_produit}" data-unite="${lot.titre_lot}" data-facteur="${lot.unites_par_lot}">${lot.titre_lot}</option>`;
                });
                lotSelect.innerHTML = options;

                // Mise à jour du prix unitaire (prix fournisseur)
                if (produitId && prixFournisseur[produitId] !== undefined) {
                    prixInput.value = prixFournisseur[produitId];
                } else {
                    prixInput.value = '';
                }

                // Mise à jour de l'unité affichée
                const selectedLot = lotSelect.options[lotSelect.selectedIndex];
                uniteAff.value = selectedLot ? (selectedLot.text || 'Unité') : 'Unité';

                // Recalculer le total
                calculerLigne(prixInput);
            }

            // ---- Calcul du total ligne (quantité * facteur * prix) ----
            function calculerLigne(element) {
                const ligne = element.closest('.ligne-produit');
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

            // ---- Événement sur le select produit ----
            $(document).on('change', '.select-produit', function() {
                mettreAJourLotsEtPrix(this);
            });

            // ---- Événement sur le select lot ----
            $(document).on('change', '.select-lot', function() {
                const ligne = this.closest('.ligne-produit');
                const uniteAff = ligne.querySelector('.unite-affichage');
                const selected = this.options[this.selectedIndex];
                uniteAff.value = selected ? (selected.text || 'Unité') : 'Unité';
                const prixInput = ligne.querySelector('.prix-unitaire');
                calculerLigne(prixInput);
            });

            // ---- Événement sur quantité ou prix unitaire ----
            $(document).on('input', '.quantite, .prix-unitaire', function() {
                calculerLigne(this);
            });

            // ---- Ajouter une ligne ----
            function ajouterLigne() {
                const container = document.getElementById('lignesContainer');
                const original = container.querySelector('.ligne-produit');
                const clone = original.cloneNode(true);
                // Réinitialiser les champs
                clone.querySelector('.select-produit').value = '';
                clone.querySelector('.select-lot').innerHTML = '<option value="">Unité</option>';
                clone.querySelector('.unite-affichage').value = '';
                clone.querySelector('.quantite').value = 1;
                clone.querySelector('.prix-unitaire').value = '';
                clone.querySelector('.total-ligne').value = '0';
                clone.querySelector('.supprimer-ligne').style.display = 'inline-block';
                container.appendChild(clone);
            }
            window.ajouterLigne = ajouterLigne;

            // ---- Supprimer une ligne ----
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
                    mettreAJourLotsEtPrix(this);
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
                first.find('.prix-unitaire').val('');
                first.find('.total-ligne').val('0');
                first.find('.supprimer-ligne').hide();
                bonModal.show();
            });

            // Mise à jour du lieu de livraison
            $('#boutiqueSelect').on('change', function() {
                const adresse = $(this).find(':selected').data('adresse') || '';
                $('#lieuLivraison').val(adresse);
            });

            // ---- Recherche et filtres AJAX ----
            function rechercher(page) {
                page = page || 1;
                var search = $('#searchInput').val();
                var fournisseur = $('#fournisseurFilter').val();
                var boutique = $('#boutiqueFilter').val();
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        ajax: 1,
                        search: search,
                        fournisseur_filter: fournisseur,
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
                    error: function() {
                        alert('Erreur lors de la recherche.');
                    }
                });
            }

            var searchTimeout = null;
            $('#searchInput').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });
            $('#fournisseurFilter, #boutiqueFilter').on('changed.bs.select', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });
            $('#filterBtn').on('click', function() {
                rechercher(1);
            });
            $('#resetBtn').on('click', function() {
                $('#searchInput').val('');
                $('#fournisseurFilter').selectpicker('val', '');
                $('#boutiqueFilter').selectpicker('val', '');
                rechercher(1);
            });
        });
    </script>
</body>

</html>