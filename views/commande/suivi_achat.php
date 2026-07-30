<?php
// views/achats/suivi_fournisseur.php – Historique des bons de commande fournisseur
// Design dashboard identique à vente.php

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require 'databases/database.php';
require 'librairies/fpdf/fpdf.php';

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

// === CORRECTION : déclaration des variables pour éviter les warnings ===
$message = '';
$messageType = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---- LISTES POUR FILTRES ----
$fournisseurs = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Fournisseur' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

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
            WHERE statut_id = '011' 
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
            WHERE reference_liee = ? AND statut_id = '011'
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

// ---- AJAX POUR LA RECHERCHE ----
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    ob_start();
    try {
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
                        <input type="hidden" name="action" value="print_bon_commande">
                        <input type="hidden" name="bon_id" value="<?= e($bon['bon_id']) ?>">
                        <button type="submit" class="act-btn" title="Bon de commande" style="color:#2563eb; border:none; background:transparent; padding:0; width:34px; height:34px;">
                            <i class="bi bi-file-earmark-text"></i>
                        </button>
                    </form>
                    <form method="POST" style="display:inline-block;">
                        <input type="hidden" name="action" value="print_bon_livraison">
                        <input type="hidden" name="bon_id" value="<?= e($bon['bon_id']) ?>">
                        <button type="submit" class="act-btn" title="Bon de livraison" style="color:#dc3545; border:none; background:transparent; padding:0; width:34px; height:34px;">
                            <i class="bi bi-truck"></i>
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

        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['table' => $tableHtml, 'pagination' => $paginationHtml, 'total' => $data['total'], 'page' => $data['page'], 'totalPages' => $data['totalPages']]);
    } catch (\Throwable $e) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ---- IMPRESSION PDF (BON DE COMMANDE OU BON DE LIVRAISON) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['print_bon_commande', 'print_bon_livraison'])) {
    while (ob_get_level()) ob_end_clean();

    $bonId = $_POST['bon_id'] ?? '';
    $type = $_POST['action'] === 'print_bon_commande' ? 'commande' : 'livraison';
    if (empty($bonId)) die("Numéro de bon manquant.");

    if (!class_exists('FPDF')) {
        require_once 'librairies/fpdf/fpdf.php';
    }

    // ---- Récupération des données (incluant le logo de la boutique) ----
    $stmt = $pdo->prepare("
        SELECT c.*, p.titre_produit,
               ct.nom_prenom_contact AS fournisseur_nom, ct.telephone_contact AS fournisseur_tel,
               ct.email_contact AS fournisseur_email, ct.adresse_contact AS fournisseur_adresse,
               b.nom_boutique, b.adresse_boutique, b.telephone_boutique, b.email_boutique,
               b.ville_boutique, b.pays_boutique,
               b.logo, b.type_logo
        FROM commande c
        LEFT JOIN produit p ON c.produit_id = p.code_produit
        LEFT JOIN contact ct ON c.contact_id = ct.code_contact
        LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
        WHERE c.reference_liee = ? AND c.statut_id = '011' AND c.etat_commande != 'Annulé'
        ORDER BY c.numero_commande
    ");
    $stmt->execute([$bonId]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($lignes)) die("Aucune ligne trouvée.");
    $bonInfo = $lignes[0];
    $totalBon = array_sum(array_column($lignes, 'montant_commande'));

    // ---- Gestion du logo (fichier temporaire) ----
    $logoPath = null;
    if (!empty($bonInfo['logo'])) {
        $tmp = tmpfile();
        if ($tmp !== false) {
            fwrite($tmp, $bonInfo['logo']);
            $meta = stream_get_meta_data($tmp);
            $logoPath = $meta['uri'];
        }
    }

    // ---- CRÉATION DU PDF (portrait) ----
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);
    $blueDark = [0, 51, 102];
    $blueLight = [240, 245, 255];
    $grayBg = [245, 245, 245];
    $toLatin = function ($chaine) {
        return mb_convert_encoding($chaine, 'ISO-8859-1', 'UTF-8');
    };

    $yStart = 10;
    $titre = $type === 'commande' ? 'BON DE COMMANDE' : 'BON DE LIVRAISON';

    // ---- TITRE À GAUCHE, LOGO À DROITE ----
    $pdf->SetFont('Arial', 'B', 22);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->SetXY(10, $yStart);
    $pdf->Cell(0, 10, $toLatin($titre), 0, 0, 'L');

    if ($logoPath && !empty($bonInfo['type_logo'])) {
        $imageType = strtoupper(str_replace('image/', '', $bonInfo['type_logo']));
        $pdf->Image($logoPath, 175, $yStart, 25, 0, $imageType);
    }

    // ---- LIGNE DE SÉPARATION ----
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(10, $yStart + 18, 200, $yStart + 18);

    // ---- INFORMATIONS SOUS LE TITRE (N°, Date, Facture, Statut) ----
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(10, $yStart + 22);
    $pdf->Cell(0, 6, $toLatin('N° ' . $bonId), 0, 1, 'L');

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(10, $yStart + 28);
    $pdf->Cell(0, 6, $toLatin('Date : ' . date('d/m/Y', strtotime($bonInfo['date_commande']))), 0, 1, 'L');

    if (!empty($bonInfo['date_livraison_recue'])) {
        $pdf->SetXY(10, $yStart + 34);
        $pdf->Cell(0, 6, $toLatin('Livraison souhaitée : ' . date('d/m/Y', strtotime($bonInfo['date_livraison_recue']))), 0, 1, 'L');
    }

    $pdf->SetXY(10, $yStart + 40);
    $pdf->Cell(0, 6, $toLatin('Statut : ' . $bonInfo['etat_commande']), 0, 1, 'L');

    // ---- LIGNE SUPPLEMENTAIRE ----
    $pdf->Line(10, $yStart + 46, 200, $yStart + 46);

    // ---- BLOCS EXPÉDITEUR (FOURNISSEUR) / DESTINATAIRE (BOUTIQUE) ----
    $yBlocks = $yStart + 52;
    $drawAddressBlock = function ($pdf, $x, $y, $w, $title, $name, $address, $phone, $email) use ($toLatin, $blueDark, $grayBg) {
        $h = 36;
        $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->Rect($x, $y, 35, 6, 'F');
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
    $wBlock = (200 - 10) / 2 - 5;

    // EXPÉDITEUR = FOURNISSEUR
    $drawAddressBlock(
        $pdf,
        10,
        $yBlocks,
        $wBlock,
        'EXPÉDITEUR (FOURNISSEUR)',
        $bonInfo['fournisseur_nom'] ?? '',
        $bonInfo['fournisseur_adresse'] ?? '',
        $bonInfo['fournisseur_tel'] ?? '',
        $bonInfo['fournisseur_email'] ?? ''
    );

    // DESTINATAIRE = BOUTIQUE
    $drawAddressBlock(
        $pdf,
        10 + $wBlock + 10,
        $yBlocks,
        $wBlock,
        'DESTINATAIRE (BOUTIQUE)',
        $bonInfo['nom_boutique'] ?? '',
        ($bonInfo['adresse_boutique'] ?? '') . ', ' . ($bonInfo['ville_boutique'] ?? '') . ', ' . ($bonInfo['pays_boutique'] ?? ''),
        $bonInfo['telephone_boutique'] ?? '',
        $bonInfo['email_boutique'] ?? ''
    );

    // ---- TABLEAU SELON LE TYPE ----
    if ($type === 'commande') {
        // BON DE COMMANDE (portrait)
        $colWidths = [18, 62, 18, 14, 18, 18, 22];
        $headers = ['RÉF.', 'DÉSIGNATION', 'LOT/UNITÉ', 'QTÉ (lots)', 'QTÉ (base)', 'P.U. (FCFA)', 'MONTANT (FCFA)'];
        $headerH = 7;
        $rowH = 7;
        $yTable = 115; // position ajustée

        $drawTableHeader = function () use ($pdf, $colWidths, $headers, $headerH, $toLatin, $blueDark, &$yTable) {
            $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 6.5);
            $x = 10;
            foreach ($headers as $i => $h) {
                $label = $toLatin($h);
                $pdf->Rect($x, $yTable, $colWidths[$i], $headerH, 'F');
                $pdf->Text($x + ($colWidths[$i] / 2) - ($pdf->GetStringWidth($label) / 2), $yTable + 5, $label);
                $x += $colWidths[$i];
            }
            $yTable += $headerH;
        };
        $drawTableHeader();

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 7);
        $yCurrent = $yTable;

        foreach ($lignes as $ligne) {
            if ($yCurrent + $rowH > 270) {
                $pdf->AddPage();
                $yTable = 20;
                $yCurrent = $yTable;
                $drawTableHeader();
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('Arial', '', 7);
            }

            $ref = $ligne['produit_id'];
            $des = substr($ligne['titre_produit'] ?? $ligne['produit_id'], 0, 30);
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
                $pdf->Text($txtX, $yCurrent + 5, $label);
                $x += $colWidths[$i];
            }
            $yCurrent += $rowH;
        }

        // ---- TOTAUX, OBSERVATIONS, SIGNATURES ----
        $yAfterLines = $yCurrent + 5;
        $pageHeight = 297;
        $marginBottom = 15;
        if ($yAfterLines + 45 > $pageHeight - $marginBottom) {
            $pdf->AddPage();
            $yAfterLines = 20;
        }

        $yObs = $yAfterLines;
        $wObs = 120;
        $hObs = 26;
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

        $xTot = 10 + $wObs + 8;
        $wTot = 200 - $xTot;
        $hTot = 6;
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Rect($xTot, $yObs, $wTot, $hTot, 'D');
        $pdf->Text($xTot + 2, $yObs + 5, $toLatin('TOTAL HT'));
        $pdf->SetXY($xTot, $yObs + 5);
        $pdf->Cell($wTot - 2, 0, number_format($totalBon, 0, ',', ' '), 0, 0, 'R');

        $pdf->SetFillColor($blueLight[0], $blueLight[1], $blueLight[2]);
        $pdf->Rect($xTot, $yObs + $hTot, $wTot, $hTot + 2, 'FD');
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->Text($xTot + 2, $yObs + $hTot + 5, $toLatin('NET À PAYER'));
        $pdf->SetXY($xTot, $yObs + $hTot + 5);
        $pdf->Cell($wTot - 2, 0, number_format($totalBon, 0, ',', ' '), 0, 0, 'R');

        $ySig = $yObs + $hObs + 6;
        if ($ySig + 24 > $pageHeight - $marginBottom) {
            $pdf->AddPage();
            $ySig = 20;
        }
        $hSig = 24;
        $wSig = 90;
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Rect(10, $ySig, $wSig, $hSig, 'D');
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->Text(12, $ySig + 5, $toLatin('Le Destinataire'));
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Text(12, $ySig + 10, $toLatin('Nom et Signature'));

        $pdf->SetDrawColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->Rect(50, $ySig + 5, 45, $hSig - 6, 'D');
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->Text(52, $ySig + 9, $toLatin($bonInfo['nom_boutique'] ?? ''));
        $pdf->SetFont('Arial', '', 6);
        $pdf->Text(52, $ySig + 13, $toLatin($bonInfo['adresse_boutique'] ?? ''));
        $pdf->Text(52, $ySig + 17, $toLatin('Tél. : ' . ($bonInfo['telephone_boutique'] ?? '')));

        $xClient = 10 + $wSig + 10;
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Rect($xClient, $ySig, $wSig, $hSig, 'D');
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->Text($xClient + 2, $ySig + 5, $toLatin('Le Fournisseur'));
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Text($xClient + 2, $ySig + 10, $toLatin('Nom et Signature'));

    } else {
        // ---- BON DE LIVRAISON (portrait, sans prix) ----
        $colWidths = [14, 55, 18, 14, 14, 18, 18, 18, 12];
        $headers = ['RÉF.', 'DÉSIGNATION', 'LOT/UNITÉ', 'QTÉ (lots)', 'QTÉ (base)', 'LIVREUR', 'CONTRÔLEUR', 'RESPONSABLE', 'VISA'];
        $headerH = 7;
        $rowH = 7;
        $yTable = 115;

        $drawTableHeader = function () use ($pdf, $colWidths, $headers, $headerH, $toLatin, $blueDark, &$yTable) {
            $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 6);
            $x = 10;
            foreach ($headers as $i => $h) {
                $label = $toLatin($h);
                $pdf->Rect($x, $yTable, $colWidths[$i], $headerH, 'F');
                $pdf->Text($x + ($colWidths[$i] / 2) - ($pdf->GetStringWidth($label) / 2), $yTable + 5, $label);
                $x += $colWidths[$i];
            }
            $yTable += $headerH;
        };
        $drawTableHeader();

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 6.5);
        $yCurrent = $yTable;
        $totalLots = 0;
        $totalBase = 0;

        foreach ($lignes as $ligne) {
            if ($yCurrent + $rowH > 270) {
                $pdf->AddPage();
                $yTable = 20;
                $drawTableHeader();
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('Arial', '', 6.5);
                $yCurrent = $yTable;
            }

            $ref = $ligne['produit_id'];
            $des = substr($ligne['titre_produit'] ?? $ligne['produit_id'], 0, 40);
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
                $pdf->Text($txtX, $yCurrent + 5, $label);
                $x += $colWidths[$i];
            }
            $yCurrent += $rowH;
        }

        // Ligne des totaux
        if ($yCurrent + $rowH > 270) {
            $pdf->AddPage();
            $yTable = 20;
            $drawTableHeader();
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 6.5);
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
            $pdf->Text($txtX, $yCurrent + 5, $label);
            $x += $colWidths[$i];
        }
        $yCurrent += $rowH;
    }

    // ---- FIN DU PDF : nettoyage et envoi ----
    while (ob_get_level()) ob_end_clean();
    $pdf->Output('I', $type . '_' . $bonId . '.pdf');
    exit;
}

// ---- AFFICHAGE INITIAL ----
$search = trim($_POST['search'] ?? '');
$fournisseur_filter = trim($_POST['fournisseur_filter'] ?? '');
$boutique_filter = trim($_POST['boutique_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

$bonsData = getBons($pdo, $search, $fournisseur_filter, $boutique_filter, $page, 10);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi des bons fournisseurs</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
    <style>
        /* ===== STYLE DASHBOARD (identique à vente.php) ===== */
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
        .bootstrap-select .dropdown-menu { border-radius: var(--Rs); border-color: var(--brd); }
        .bootstrap-select .dropdown-menu .bs-searchbox input {
            border-radius: 6px; border: 1px solid var(--brd); padding: 8px 12px;
        }
        .bootstrap-select .dropdown-menu .bs-searchbox input:focus {
            border-color: var(--b); box-shadow: 0 0 0 3px var(--bl);
        }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1>Suivi des bons fournisseurs</h1>
            <p>Historique complet de vos commandes d'achat</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-receipt"></i> <?= $bonsData['total'] ?? 0 ?> bons</div>
        </div>
    </div>

    <!-- Messages (correction : variables déclarées en début de fichier) -->
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
            <input type="hidden" name="page" id="pageInput" value="<?= e($page) ?>">
            <div class="prow">
                <label for="searchInput"><i class="bi bi-search"></i> Recherche</label>
                <input type="text" name="search" id="searchInput" placeholder="N° bon, fournisseur..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                <label for="fournisseurFilter">Fournisseur</label>
                <select name="fournisseur_filter" id="fournisseurFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un fournisseur...">
                    <option value="">Tous</option>
                    <?php foreach ($fournisseurs as $f): ?>
                        <option value="<?= e($f['code_contact']) ?>" <?= ($fournisseur_filter == $f['code_contact']) ? 'selected' : '' ?>><?= e($f['nom_prenom_contact']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="boutiqueFilter">Boutique</label>
                <select name="boutique_filter" id="boutiqueFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
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
                            <td class="text-end" style="white-space:nowrap;">
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="action" value="print_bon_commande">
                                    <input type="hidden" name="bon_id" value="<?= e($bon['bon_id']) ?>">
                                    <button type="submit" class="act-btn" title="Bon de commande" style="color:#2563eb; border:none; background:transparent; padding:0; width:34px; height:34px;">
                                        <i class="bi bi-file-earmark-text"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="action" value="print_bon_livraison">
                                    <input type="hidden" name="bon_id" value="<?= e($bon['bon_id']) ?>">
                                    <button type="submit" class="act-btn" title="Bon de livraison" style="color:#dc3545; border:none; background:transparent; padding:0; width:34px; height:34px;">
                                        <i class="bi bi-truck"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    // ---- Fonction de recherche AJAX ----
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
            error: function(xhr) {
                console.error('Réponse brute :', xhr.status, xhr.responseText);
                alert('Erreur lors de la recherche. Détail dans la console (F12).');
            }
        });
    }

    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#fournisseurFilter, #boutiqueFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#filterBtn').on('click', function() { rechercher(1); });
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#fournisseurFilter').selectpicker('val', '');
        $('#boutiqueFilter').selectpicker('val', '');
        rechercher(1);
    });

    // Auto-fermeture des alertes
    setTimeout(function() { $('.alert').alert('close'); }, 5000);
});
</script>
</body>
</html>