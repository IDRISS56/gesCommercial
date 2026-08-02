<?php
// ==========================================
// 1. CONNEXION À LA BASE DE DONNÉES
// ==========================================
require 'databases/database.php';
require 'librairies/fpdf/fpdf.php';

// ==========================================
// 2. GÉNÉRATION DU PDF (mode Portrait)
// ==========================================
if (isset($_POST['action']) && $_POST['action'] === 'pdf') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!isset($_POST['id']) || empty($_POST['id'])) die("ID de bon manquant.");
    $id = $_POST['id'];
    try {
        $stmt = $pdo->prepare("SELECT bl.*, f.montant_ttc, f.etat_facture, f.statut_facture, f.utilisateur_id,
            c.nom_prenom_contact, c.adresse_contact, c.telephone_contact AS tel_contact, c.email_contact
            FROM bon_livraison bl
            LEFT JOIN facture f ON f.numero_facture = bl.facture_id
            LEFT JOIN contact c ON c.code_contact = f.contact_id
            WHERE bl.code_bon = ?");
        $stmt->execute([$id]);
        $bon = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bon) die("Bon introuvable.");

        $stmtCmd = $pdo->prepare("SELECT c.*, p.titre_produit, p.code_produit AS reference_produit, l.libelle AS libelle_lot
            FROM commande c
            LEFT JOIN produit p ON c.produit_id = p.code_produit
            LEFT JOIN lot l ON c.lot_id = l.code_lot
            WHERE c.facture_id = ?");
        $stmtCmd->execute([$bon['facture_id']]);
        $commandes = $stmtCmd->fetchAll(PDO::FETCH_ASSOC);

        $boutique_id = $commandes[0]['boutique_id'] ?? null;
        if (empty($boutique_id) && !empty($bon['utilisateur_id'] ?? null)) {
            $stmtU = $pdo->prepare("SELECT boutique_id FROM utilisateur WHERE id = ?");
            $stmtU->execute([$bon['utilisateur_id']]);
            $boutique_id = $stmtU->fetchColumn() ?: null;
        }
        $boutique = null;
        if (!empty($boutique_id)) {
            $stmtB = $pdo->prepare("SELECT * FROM boutique WHERE code_boutique = ?");
            $stmtB->execute([$boutique_id]);
            $boutique = $stmtB->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        http_response_code(500);
        die("Erreur lors de la génération du PDF : " . $e->getMessage());
    }

    $logoTmpPath = null;
    if (!empty($boutique['logo'])) {
        $mime = $boutique['type_logo'] ?? 'image/png';
        $ext = 'png';
        if (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) $ext = 'jpg';
        elseif (strpos($mime, 'gif') !== false) $ext = 'gif';
        $logoTmpPath = sys_get_temp_dir() . '/logo_' . $boutique_id . '_' . uniqid() . '.' . $ext;
        file_put_contents($logoTmpPath, $boutique['logo']);
    }

    $nomBoutique = $boutique['nom_boutique'] ?? 'Ets Dankan';
    $adresseBoutique = trim(($boutique['adresse_boutique'] ?? '') . (!empty($boutique['quartier_boutique']) ? ', ' . $boutique['quartier_boutique'] : '') . (!empty($boutique['ville_boutique']) ? ', ' . $boutique['ville_boutique'] : ''));
    $telBoutique = $boutique['telephone_boutique'] ?? '';
    $emailBoutique = $boutique['email_boutique'] ?? '';

    class PDF extends FPDF {
        function Header() {}
        function Footer() {}
        private function txt($s) {
            $s = (string)$s;
            $conv = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
            return $conv !== false ? $conv : $s;
        }
        function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '') {
            parent::Cell($w, $h, $this->txt($txt), $border, $ln, $align, $fill, $link);
        }
        function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false) {
            parent::MultiCell($w, $h, $this->txt($txt), $border, $align, $fill);
        }
    }

    $pdf = new PDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(10, 10, 10);

    $navy   = [21, 61, 122];
    $grey   = [242, 242, 242];
    $border = [190, 190, 190];

    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 22);
    $pdf->SetXY(10, 12);
    $pdf->Cell(130, 12, 'BON DE LIVRAISON', 0, 1, 'L');
    if ($logoTmpPath) {
        $pdf->Image($logoTmpPath, 155, 8, 45);
        unlink($logoTmpPath);
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetX(10);
    $pdf->Cell(130, 6, 'N° ' . $bon['code_bon'], 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetX(10);
    $pdf->Cell(130, 5, 'Date : ' . date('d/m/Y', strtotime($bon['date_livraison'])), 0, 1, 'L');
    $pdf->SetX(10);
    $pdf->Cell(130, 5, 'Facture : ' . ($bon['facture_id'] ?? ''), 0, 1, 'L');
    $pdf->SetX(10);
    $pdf->Cell(130, 5, 'Statut : ' . ($bon['statut'] ?? ''), 0, 1, 'L');
    $pdf->Ln(4);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(6);

    $yBandeau = $pdf->GetY();
    $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(10, $yBandeau);
    $pdf->Cell(90, 7, '  EXPEDITEUR', 0, 0, 'L', true);
    $pdf->SetXY(110, $yBandeau);
    $pdf->Cell(90, 7, '  DESTINATAIRE', 0, 0, 'L', true);
    $yBoxes = $yBandeau + 7;
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetFillColor($grey[0], $grey[1], $grey[2]);
    $pdf->SetXY(10, $yBoxes);
    $pdf->MultiCell(90, 5.5, $nomBoutique, 0, 'L', true);
    $yExpAfterNom = $pdf->GetY();
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(10, $yExpAfterNom);
    $pdf->MultiCell(90, 5.5, $adresseBoutique . "\nTel: " . $telBoutique . "\nEmail: " . $emailBoutique, 0, 'L', true);
    $yAfterExp = $pdf->GetY();

    $clientNom = $bon['nom_prenom_contact'] ?? 'N/C';
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetXY(110, $yBoxes);
    $pdf->MultiCell(90, 5.5, $clientNom, 0, 'L', true);
    $yDestAfterNom = $pdf->GetY();
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(110, $yDestAfterNom);
    $pdf->MultiCell(90, 5.5, ($bon['adresse_livraison'] ?? $bon['adresse_contact'] ?? '') . "\nTel: " . ($bon['tel_contact'] ?? '') . "\nEmail: " . ($bon['email_contact'] ?? ''), 0, 'L', true);
    $yAfterDest = $pdf->GetY();
    $pdf->SetY(max($yAfterExp, $yAfterDest) + 6);

    $widths = ['ref' => 18, 'design' => 40, 'lot' => 18, 'qte' => 32, 'livreur' => 18, 'controleur' => 20, 'responsable' => 24, 'visa' => 20];
    $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($widths['ref'], 7, 'REF.', 0, 0, 'C', true);
    $pdf->Cell($widths['design'], 7, 'DESIGNATION', 0, 0, 'C', true);
    $pdf->Cell($widths['lot'], 7, 'LOT/UNITE', 0, 0, 'C', true);
    $pdf->Cell($widths['qte'], 7, 'QUANTITE', 0, 0, 'C', true);
    $pdf->Cell($widths['livreur'], 7, 'LIVREUR', 0, 0, 'C', true);
    $pdf->Cell($widths['controleur'], 7, 'CONTROLEUR', 0, 0, 'C', true);
    $pdf->Cell($widths['responsable'], 7, 'RESPONSABLE', 0, 0, 'C', true);
    $pdf->Cell($widths['visa'], 7, 'VISA', 0, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor($border[0], $border[1], $border[2]);
    $pdf->SetFont('Arial', '', 8);
    $totalBase = 0;
    foreach ($commandes as $cmd) {
        $ref = $cmd['reference_produit'] ?? '';
        $designation = $cmd['titre_produit'] ?? '';
        $produitsParLot = intval($cmd['produits_par_lot'] ?? 0);
        $libelleLot = !empty($cmd['libelle_lot']) ? $cmd['libelle_lot'] : ($produitsParLot > 0 ? $produitsParLot . '/lot' : 'Unite');
        if ($produitsParLot > 0) {
            $nombreLots = intdiv($cmd['quantite_commande'], $produitsParLot);
            $reste = $cmd['quantite_commande'] % $produitsParLot;
            $qteAffichee = $reste > 0 ? ($nombreLots . ' lot(s) et ' . $reste . ' produit(s)') : ($nombreLots . ' lot(s)');
        } else {
            $qteAffichee = (string)$cmd['quantite_commande'];
        }
        $totalBase += $cmd['quantite_commande'];
        $nbLines = max(1, ceil(strlen($designation) / 22));
        $rowHeight = 6 * $nbLines;
        if ($pdf->GetY() + $rowHeight > 270) $pdf->AddPage();
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->MultiCell($widths['ref'], 6, $ref, 1, 'L');
        $pdf->SetXY($x + $widths['ref'], $y); $pdf->MultiCell($widths['design'], 6, $designation, 1, 'L');
        $pdf->SetXY($x + $widths['ref'] + $widths['design'], $y); $pdf->MultiCell($widths['lot'], 6, $libelleLot, 1, 'C');
        $pdf->SetXY($x + $widths['ref'] + $widths['design'] + $widths['lot'], $y);
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->Cell($widths['qte'], $rowHeight, $qteAffichee, 1, 0, 'C');
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($widths['livreur'], $rowHeight, '', 1, 0, 'C');
        $pdf->Cell($widths['controleur'], $rowHeight, '', 1, 0, 'C');
        $pdf->Cell($widths['responsable'], $rowHeight, '', 1, 0, 'C');
        $pdf->Cell($widths['visa'], $rowHeight, '', 1, 1, 'C');
    }
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor($grey[0], $grey[1], $grey[2]);
    $pdf->Cell($widths['ref'] + $widths['design'] + $widths['lot'], 7, 'TOTAUX', 1, 0, 'L', true);
    $pdf->Cell($widths['qte'], 7, $totalBase, 1, 0, 'C', true);
    $pdf->Cell($widths['livreur'], 7, '', 1, 0, 'C', true);
    $pdf->Cell($widths['controleur'], 7, '', 1, 0, 'C', true);
    $pdf->Cell($widths['responsable'], 7, '', 1, 0, 'C', true);
    $pdf->Cell($widths['visa'], 7, '', 1, 1, 'C', true);
    if (!empty($bon['commentaire'])) {
        $pdf->Ln(6);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 5, 'Observations :', 0, 1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $bon['commentaire'], 0, 'L');
    }
    $pdf->Output('I', 'Bon_Livraison_' . $id . '.pdf');
    exit;
}

// ==========================================
// 3. TRAITEMENT DES ACTIONS (AJAX / POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'get_details') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("SELECT bl.*, f.montant_ttc, f.etat_facture, f.statut_facture,
            c.nom_prenom_contact, c.adresse_contact, c.telephone_contact AS tel_contact, c.email_contact
            FROM bon_livraison bl
            LEFT JOIN facture f ON f.numero_facture = bl.facture_id
            LEFT JOIN contact c ON c.code_contact = f.contact_id
            WHERE bl.code_bon = ?");
        $stmt->execute([$id]);
        $bon = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bon) { echo json_encode(['error' => 'Bon introuvable']); exit; }
        $isLocked = false;
        if ($bon['statut_facture'] === 'Validee' && in_array(strtolower($bon['etat_facture']), ['payee', 'payee cash'])) {
            $isLocked = true;
        }
        $stmt2 = $pdo->prepare("SELECT c.*, p.titre_produit
            FROM commande c
            LEFT JOIN produit p ON c.produit_id = p.code_produit
            WHERE c.facture_id = ?");
        $stmt2->execute([$id]);
        echo json_encode([
            'bon' => $bon,
            'commandes' => $stmt2->fetchAll(PDO::FETCH_ASSOC),
            'is_locked' => $isLocked
        ]);
        exit;
    }

    if ($action === 'update_bon') {
        header('Content-Type: application/json');
        $bon_id = $_POST['bon_id'] ?? '';
        $checkStmt = $pdo->prepare("SELECT bl.statut_facture, bl.etat_facture
            FROM bon_livraison bl
            LEFT JOIN facture f ON f.numero_facture = bl.facture_id
            WHERE bl.code_bon = ?");
        $checkStmt->execute([$bon_id]);
        $bData = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $isLocked = $bData && $bData['statut_facture'] === 'Validee'
            && in_array(strtolower($bData['etat_facture']), ['payee', 'payee cash']);
        if ($isLocked) {
            echo json_encode(['success' => false, 'error' => 'Ce bon est lié à une facture validée et payée, modification impossible.']);
            exit;
        }
        $adresse = trim($_POST['adresse_livraison'] ?? '');
        $transporteur = trim($_POST['transporteur'] ?? '');
        $statut = trim($_POST['statut'] ?? 'En préparation');
        $commentaire = trim($_POST['commentaire'] ?? '');
        try {
            $pdo->prepare("UPDATE bon_livraison
                SET adresse_livraison = ?, transporteur = ?, statut = ?, commentaire = ?
                WHERE code_bon = ?")
                ->execute([$adresse, $transporteur, $statut, $commentaire, $bon_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_bon') {
        header('Content-Type: application/json');
        $id = $_POST['id'] ?? '';
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'ID manquant']); exit; }
        $checkStmt = $pdo->prepare("SELECT bl.statut_facture, bl.etat_facture
            FROM bon_livraison bl
            LEFT JOIN facture f ON f.numero_facture = bl.facture_id
            WHERE bl.code_bon = ?");
        $checkStmt->execute([$id]);
        $bData = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $isLocked = $bData && $bData['statut_facture'] === 'Validee'
            && in_array(strtolower($bData['etat_facture']), ['payee', 'payee cash']);
        if ($isLocked) {
            echo json_encode(['success' => false, 'error' => 'Impossible de supprimer un bon lié à une facture validée et payée.']);
            exit;
        }
        try {
            $pdo->prepare("DELETE FROM bon_livraison WHERE code_bon = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Bon supprimé']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// ==========================================
// 4. DONNÉES ET STATISTIQUES
// ==========================================
function getStatutBadge($statut) {
    return match($statut) {
        'En préparation' => ['warning', 'hourglass-split'],
        'Expédié' => ['primary', 'truck'],
        'Livré' => ['success', 'check-circle-fill'],
        default => ['secondary', 'question-circle']
    };
}

function getEtatFactureBadge($etat) {
    $etatLower = strtolower($etat);
    if ($etatLower === 'payee' || $etatLower === 'payee cash') return ['success', 'check-circle-fill'];
    if ($etatLower === 'partielle') return ['warning', 'hourglass-split'];
    if ($etatLower === 'impayee') return ['danger', 'x-circle-fill'];
    return ['secondary', 'question-circle'];
}

$bons = $pdo->query("SELECT bl.*, f.montant_ttc, f.etat_facture, f.statut_facture, c.nom_prenom_contact
    FROM bon_livraison bl
    LEFT JOIN facture f ON f.numero_facture = bl.facture_id
    LEFT JOIN contact c ON c.code_contact = f.contact_id
    ORDER BY bl.date_livraison DESC")->fetchAll(PDO::FETCH_ASSOC);
$totalBons = count($bons);
$enPreparation = count(array_filter($bons, fn($b) => $b['statut'] === 'En préparation'));
$expedies = count(array_filter($bons, fn($b) => $b['statut'] === 'Expédié'));
$livres = count(array_filter($bons, fn($b) => $b['statut'] === 'Livré'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bons de Livraison</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
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
            --color-info: #0891b2;
            --color-info-soft: #cffafe;
            --color-purple: #8b5cf6;
            --color-purple-soft: #ede9fe;
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
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-tertiary: #64748b;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.08);
            --radius-sm: 10px;
            --radius-md: 14px;
            --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            min-height: 100vh;
            font-size: 14px;
            padding: 24px 20px;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-track { background: transparent; }
        .W { max-width: 1400px; margin: 0 auto; }

        /* ===== CARTES BONS DE LIVRAISON (compactes - 4 par ligne) ===== */
        .bon-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 120px;
            position: relative;
            animation: fadeUp .4s ease both;
        }
        .bon-card:hover {
            border-color: var(--color-primary);
            box-shadow: 0 4px 12px rgba(79, 70, 229, .12);
            transform: translateY(-2px);
        }
        .bon-card.livre {
            border-color: var(--color-success);
            background: #f0fdf4;
        }
        .bon-card.livre::after {
            content: '✓';
            position: absolute;
            top: 6px;
            right: 6px;
            background: var(--color-success);
            color: #fff;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        .bc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
        .bc-number { font-size: 11px; font-weight: 700; color: var(--color-primary-dark); font-family: 'Outfit', sans-serif; }
        .bon-card.livre .bc-number { color: #059669; }
        .bc-date { font-size: 9px; color: var(--text-tertiary); display: flex; align-items: center; gap: 3px; margin-top: 1px; }
        .bc-middle { flex: 1; display: flex; flex-direction: column; gap: 3px; margin: 4px 0; }
        .bc-client { font-size: 11px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bc-client i { color: var(--color-primary); font-size: 11px; flex-shrink: 0; }
        .bc-address { font-size: 9px; color: var(--text-tertiary); display: flex; align-items: center; gap: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bc-address i { color: var(--color-purple); font-size: 9px; flex-shrink: 0; }
        .bc-badges { display: flex; gap: 3px; flex-wrap: wrap; margin-top: 2px; }
        .badge-pill { display: inline-flex; align-items: center; gap: 2px; padding: 1px 6px; border-radius: 999px; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .bc-bottom { border-top: 1px dashed var(--border-color); padding-top: 6px; display: flex; justify-content: flex-end; align-items: center; gap: 4px; }

        /* Boutons icônes */
        .icon-btn {
            width: 26px; height: 26px; border-radius: 5px; border: 1.5px solid transparent;
            background: transparent; display: inline-flex; align-items: center; justify-content: center;
            transition: all .2s; font-size: 12px; cursor: pointer; padding: 0; position: relative;
        }
        .icon-btn:hover { transform: scale(1.1); }
        .icon-btn.view { color: var(--color-primary); border-color: rgba(79, 70, 229, 0.2); }
        .icon-btn.view:hover { color: var(--color-primary-dark); background: var(--color-primary-soft); border-color: var(--color-primary); }
        .icon-btn.edit { color: var(--color-warning); border-color: rgba(245, 158, 11, 0.2); }
        .icon-btn.edit:hover { color: #b45309; background: var(--color-warning-soft); border-color: var(--color-warning); }
        .icon-btn.pdf { color: var(--color-danger); border-color: rgba(239, 68, 68, 0.2); }
        .icon-btn.pdf:hover { color: #b91c1c; background: var(--color-danger-soft); border-color: var(--color-danger); }
        .icon-btn.delete { color: var(--color-danger); border-color: rgba(239, 68, 68, 0.2); }
        .icon-btn.delete:hover { color: #b91c1c; background: var(--color-danger-soft); border-color: var(--color-danger); }
        .icon-btn::before {
            content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%;
            transform: translateX(-50%); background: var(--color-gray-800); color: #fff;
            padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 600;
            white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity .2s; z-index: 10;
        }
        .icon-btn:hover::before { opacity: 1; }

        /* ===== STATS ===== */
        .stat-card {
            background: var(--bg-surface); border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base);
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

        /* ===== MODAL CHIC ===== */
        .modal-chic .modal-content {
            border: none; border-radius: 20px;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15); overflow: hidden;
            animation: modalSlideIn .4s cubic-bezier(0.16, 1, 0.3, 1);
            max-height: 90vh;
        }
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-chic .modal-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%);
            color: #fff; border: none; padding: 22px 28px; position: relative; overflow: hidden;
        }
        .modal-chic .modal-header::before {
            content: ''; position: absolute; top: -50%; right: -20%; width: 200px; height: 200px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%;
        }
        .modal-chic .modal-title { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
        .modal-chic .modal-title i { font-size: 22px; background: rgba(255,255,255,0.15); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px); }
        .modal-chic .btn-close { filter: invert(1); opacity: 0.7; position: relative; z-index: 1; transition: all .2s; }
        .modal-chic .btn-close:hover { opacity: 1; transform: rotate(90deg); }
        .modal-chic .modal-body { padding: 28px; max-height: 70vh; overflow-y: auto; background: #f8fafc; }
        .modal-chic .modal-footer { background: #fff; border-top: 1px solid var(--border-color); padding: 18px 28px; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; flex-shrink: 0; }

        /* Sections chic */
        .detail-section-chic { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 20px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); transition: all .2s; }
        .detail-section-chic:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.06); border-color: #cbd5e1; }
        .detail-section-title-chic { font-size: 11px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.8px; padding-bottom: 10px; border-bottom: 2px solid #f1f5f9; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .detail-section-title-chic.info i { color: #3b82f6; }
        .detail-section-title-chic.delivery i { color: #8b5cf6; }
        .detail-section-title-chic.box i { color: #f59e0b; }
        .badge-chic { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 2px 4px rgba(0,0,0,0.06); }
        .badge-chic.success { background: #d1fae5; color: #065f46; }
        .badge-chic.warning { background: #fef3c7; color: #92400e; }
        .badge-chic.danger { background: #fee2e2; color: #991b1b; }
        .badge-chic.primary { background: #dbeafe; color: #1e40af; }
        .badge-chic.secondary { background: #f1f5f9; color: #475569; }
        .badge-chic .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        /* Boutons chic du modal */
        .btn-chic { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; letter-spacing: -0.01em; }
        .btn-chic::before { content: ''; position: absolute; top: 50%; left: 50%; width: 0; height: 0; background: rgba(255,255,255,0.3); border-radius: 50%; transform: translate(-50%, -50%); transition: width .4s, height .4s; }
        .btn-chic:hover::before { width: 300px; height: 300px; }
        .btn-chic i { font-size: 15px; position: relative; z-index: 1; }
        .btn-chic span { position: relative; z-index: 1; }
        .btn-chic-modifier { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #fff; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); }
        .btn-chic-modifier:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4); }
        .btn-chic-imprimer { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); color: #fff; box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3); }
        .btn-chic-imprimer:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(6, 182, 212, 0.4); }
        .btn-chic-partager { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .btn-chic-partager:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); }
        .btn-chic-fermer { background: linear-gradient(135deg, #64748b 0%, #475569 100%); color: #fff; box-shadow: 0 4px 12px rgba(100, 116, 139, 0.25); }
        .btn-chic-fermer:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(100, 116, 139, 0.35); }

        /* Modal table */
        .modal-table { width: 100%; font-size: 13px; }
        .modal-table thead th { background: var(--color-gray-100); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 10px 12px; }
        .modal-table tbody td { padding: 10px 12px; border-bottom: 1px solid var(--border-color); }
        .modal-table tbody tr:hover { background: var(--color-primary-soft); }

        /* ===== SECTION ÉDITION CHIC ===== */
        .edit-section-chic { display: none; background: #fff; border: 1px solid var(--border-color); border-radius: 16px; padding: 28px; margin-top: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); }
        .edit-header-chic { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
        .edit-header-chic h2 { font-size: 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .edit-header-chic h2 i { color: #8b5cf6; font-size: 22px; }
        .btn-retour-chic { background: #f1f5f9; color: #475569; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border-color); transition: all .2s; }
        .btn-retour-chic:hover { background: var(--color-gray-200); color: #1e293b; }
        .edit-form-chic .form-label { font-size: 10px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .edit-form-chic .form-control, .edit-form-chic .form-select { border-radius: 10px; border: 1.5px solid var(--border-color); padding: 10px 14px; font-size: 13px; transition: all .2s; }
        .edit-form-chic .form-control:focus, .edit-form-chic .form-select:focus { border-color: var(--color-primary); box-shadow: 0 0 0 3px var(--color-primary-soft); }
        .btn-action-chic { padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s; }
        .btn-action-chic.annuler { background: #f1f5f9; color: #475569; border: 1px solid var(--border-color); }
        .btn-action-chic.annuler:hover { background: var(--color-gray-200); }
        .btn-action-chic.enregistrer { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #fff; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        .btn-action-chic.enregistrer:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4); }

        /* ===== SELECTPICKER ===== */
        .bootstrap-select .dropdown-toggle {
            background: #fff !important;
            border: 1.5px solid var(--border-color) !important;
            border-radius: 8px !important;
            min-width: 220px;
        }
        .bootstrap-select .dropdown-toggle:focus {
            border-color: var(--color-primary) !important;
            box-shadow: 0 0 0 3px var(--color-primary-soft) !important;
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .bon-item.deleting { animation: fadeOut .4s ease forwards; }
        @keyframes fadeOut { to { opacity: 0; transform: scale(0.9); } }
        @media (max-width: 700px) {
            .bootstrap-select, .bootstrap-select .dropdown-toggle {
                width: 100% !important;
                min-width: 0 !important;
            }
        }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-truck text-primary me-2"></i>Bons de Livraison</h1>
            <p class="text-muted small mb-0">Gérez et suivez toutes vos livraisons en un coup d'œil</p>
        </div>
        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
            <i class="bi bi-file-earmark-text"></i> <?= $totalBons ?> bon(s)
        </span>
    </div>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['primary', 'file-earmark-text', 'Total bons', $totalBons, ''],
            ['warning', 'hourglass-split', 'En préparation', $enPreparation, ''],
            ['info', 'truck', 'Expédiés', $expedies, ''],
            ['success', 'check-circle-fill', 'Livrés', $livres, ''],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
            'danger' => ['var(--color-danger-soft)', 'var(--color-danger)'],
            'info' => ['var(--color-info-soft)', 'var(--color-info)'],
            'purple' => ['var(--color-purple-soft)', 'var(--color-purple)'],
        ];
        foreach ($stats as $s):
            $bg = $colorMap[$s[0]][0];
            $fg = $colorMap[$s[0]][1];
        ?>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background: <?= $bg ?>; color: <?= $fg ?>;">
                    <i class="bi bi-<?= $s[1] ?>"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-label"><?= $s[2] ?></div>
                    <div class="stat-value text-truncate"><?= $s[3] ?><?php if ($s[4]): ?><small class="text-muted ms-1" style="font-size:11px;"><?= $s[4] ?></small><?php endif; ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filtres -->
    <div class="bg-white border rounded-3 p-3 mb-4 shadow-sm">
        <form id="searchForm" onsubmit="return false;">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <label for="statutFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-check2-square"></i> Statut</label>
                <select id="statutFilter" class="selectpicker">
                    <option value="">Tous les statuts</option>
                    <option value="En préparation">En préparation</option>
                    <option value="Expédié">Expédié</option>
                    <option value="Livré">Livré</option>
                    <option value="Annulé">Annulé</option>
                </select>
                <label for="etatFactureFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-cash-coin"></i> État facture</label>
                <select id="etatFactureFilter" class="selectpicker">
                    <option value="">Tous les états</option>
                    <option value="Impayee">Impayée</option>
                    <option value="Partielle">Partielle</option>
                    <option value="Payee cash">Payée cash</option>
                    <option value="Payee">Payée</option>
                </select>
                <label for="statutFactureFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-check2-all"></i> Statut facture</label>
                <select id="statutFactureFilter" class="selectpicker">
                    <option value="">Tous les statuts</option>
                    <option value="En attente">En attente</option>
                    <option value="Validee">Validée</option>
                    <option value="Annule">Annulée</option>
                </select>
                <button type="button" class="btn btn-primary fw-bold" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn btn-outline-secondary fw-semibold" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <!-- Liste des bons -->
    <div class="row g-3" id="bonsGrid">
        <?php if (empty($bons)): ?>
        <div class="col-12">
            <div class="bg-white border border-dashed rounded-3 p-5 text-center text-muted">
                <i class="bi bi-inbox d-block mb-2" style="font-size:56px;opacity:.2;"></i>
                <h5 class="text-dark">Aucun bon de livraison</h5>
                <p class="small mb-0">Les bons de livraison apparaîtront ici dès leur création.</p>
            </div>
        </div>
        <?php else: foreach($bons as $bon):
            $statutBadge = getStatutBadge($bon['statut']);
            $isLivre = ($bon['statut'] === 'Livré');
            $etatFactureBadge = getEtatFactureBadge($bon['etat_facture'] ?? 'Impayee');
            $isLocked = ($bon['statut_facture'] === 'Validee' && in_array(strtolower($bon['etat_facture']), ['payee', 'payee cash']));
        ?>
        <div class="col-12 col-md-6 col-lg-4 col-xl-3 bon-item"
             data-statut="<?= htmlspecialchars($bon['statut']) ?>"
             data-etat-facture="<?= htmlspecialchars($bon['etat_facture'] ?? '') ?>"
             data-statut-facture="<?= htmlspecialchars($bon['statut_facture'] ?? '') ?>"
             data-id="<?= htmlspecialchars($bon['code_bon']) ?>">
            <div class="bon-card <?= $isLivre ? 'livre' : '' ?>">
                <div class="bc-top">
                    <div>
                        <div class="bc-number"><?= htmlspecialchars($bon['code_bon']) ?></div>
                        <div class="bc-date"><i class="bi bi-calendar3"></i> <?= date('d/m/Y', strtotime($bon['date_livraison'])) ?></div>
                    </div>
                    <?php if (!empty($bon['montant_ttc'])): ?>
                    <div class="fc-amount" style="font-size:14px;font-weight:800;color:var(--color-primary);font-family:'Outfit',sans-serif;line-height:1;">
                        <?= number_format($bon['montant_ttc'], 0, ',', ' ') ?><small style="font-size:9px;color:var(--text-tertiary);margin-left:2px;">FCFA</small>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="bc-middle">
                    <div class="bc-client">
                        <i class="bi bi-person"></i>
                        <span><?= htmlspecialchars($bon['nom_prenom_contact'] ?? 'N/C') ?></span>
                    </div>
                    <?php if (!empty($bon['adresse_livraison'])): ?>
                    <div class="bc-address" title="<?= htmlspecialchars($bon['adresse_livraison']) ?>">
                        <i class="bi bi-geo-alt"></i>
                        <span><?= htmlspecialchars($bon['adresse_livraison']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="bc-badges">
                        <span class="badge-pill bg-<?= $statutBadge[0] ?>-subtle text-<?= $statutBadge[0] ?>">
                            <i class="bi bi-<?= $statutBadge[1] ?>" style="font-size:8px;"></i> <?= htmlspecialchars($bon['statut']) ?>
                        </span>
                        <?php if (!empty($bon['etat_facture'])): ?>
                        <span class="badge-pill bg-<?= $etatFactureBadge[0] ?>-subtle text-<?= $etatFactureBadge[0] ?>">
                            <i class="bi bi-<?= $etatFactureBadge[1] ?>" style="font-size:8px;"></i> <?= $bon['etat_facture'] ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($bon['transporteur'])): ?>
                        <span class="badge-pill bg-info-subtle text-info">
                            <i class="bi bi-truck" style="font-size:8px;"></i> <?= htmlspecialchars($bon['transporteur']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="bc-bottom">
                    <button class="icon-btn view voir-bon" data-id="<?= $bon['code_bon'] ?>" data-tooltip="Voir détails" title="Voir détails"><i class="bi bi-eye"></i></button>
                    <?php if (!$isLocked): ?>
                    <button class="icon-btn edit modifier-bon" data-id="<?= $bon['code_bon'] ?>" data-tooltip="Modifier" title="Modifier"><i class="bi bi-pencil-square"></i></button>
                    <?php else: ?>
                    <button class="icon-btn edit" disabled data-tooltip="Verrouillé" title="Verrouillé (facture payée)" style="opacity:0.4;cursor:not-allowed;"><i class="bi bi-lock-fill"></i></button>
                    <?php endif; ?>
                    <button class="icon-btn pdf pdf-bon" data-id="<?= $bon['code_bon'] ?>" data-tooltip="PDF" title="PDF"><i class="bi bi-file-pdf"></i></button>
                    <?php if (!$isLocked): ?>
                    <button class="icon-btn delete supprimer-bon" data-id="<?= $bon['code_bon'] ?>" data-tooltip="Supprimer" title="Supprimer"><i class="bi bi-trash"></i></button>
                    <?php else: ?>
                    <button class="icon-btn delete" disabled data-tooltip="Verrouillé" title="Verrouillé (facture payée)" style="opacity:0.4;cursor:not-allowed;"><i class="bi bi-lock-fill"></i></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- Modal détails chic -->
<div class="modal fade modal-chic" id="bonModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-truck"></i><span id="modalTitleText">Détails du bon</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bonDetails">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visuellement-hidden">Chargement...</span></div>
                    <p class="mt-3 text-muted small">Chargement des détails...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-chic btn-chic-modifier" id="btnModifier"><i class="bi bi-pencil-square"></i><span>Modifier</span></button>
                <button class="btn-chic btn-chic-imprimer" id="btnImprimer"><i class="bi bi-printer-fill"></i><span>Imprimer PDF</span></button>
                <button class="btn-chic btn-chic-partager" id="btnPartager"><i class="bi bi-whatsapp"></i><span>Partager</span></button>
                <button class="btn-chic btn-chic-fermer" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i><span>Fermer</span></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal confirmation suppression -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Confirmer la suppression</h5>
                <p class="text-muted small mb-4">Êtes-vous sûr de vouloir supprimer le bon <strong id="deleteBonId" class="text-danger"></strong> ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger rounded-3" id="confirmDeleteBtn"><i class="bi bi-trash3 me-1"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Section édition chic -->
<div id="editSection" class="edit-section-chic">
    <div class="edit-header-chic">
        <h2><i class="bi bi-pencil-square"></i> Modification du bon de livraison</h2>
        <button class="btn-retour-chic" id="btnRetourListe"><i class="bi bi-arrow-left"></i> Retour</button>
    </div>
    <div id="editContent"></div>
</div>

<!-- Toast -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:2000;">
    <div id="toastMsg" class="toast align-items-center text-white border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-semibold" id="toastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker();
    const toastEl = document.getElementById('toastMsg');
    const toast = new bootstrap.Toast(toastEl, { delay: 2500 });
    const baseUrl = window.location.pathname;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    let bonToDelete = null;

    function showToast(msg, type = 'success') {
        const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-primary' };
        const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        $('#toastBody').html(`<i class="bi ${icons[type]} me-2"></i>${msg}`);
        toastEl.className = `toast align-items-center text-white border-0 ${colors[type]}`;
        toast.show();
    }

    // Voir détails
    $(document).on('click', '.voir-bon', function(e) {
        e.stopPropagation();
        const id = $(this).data('id');
        $('#modalTitleText').text('Bon ' + id);
        $('#bonDetails').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted small">Chargement...</p></div>');
        $('#btnModifier').prop('disabled', false).removeClass('disabled').css('opacity', '1').attr('title', 'Modifier');
        $('#bonModal').modal('show');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'get_details', id: id },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#bonDetails').html('<div class="alert alert-danger">' + data.error + '</div>');
                    return;
                }
                const b = data.bon;
                const statutColor = b.statut === 'Livré' ? 'success' : (b.statut === 'Expédié' ? 'primary' : 'warning');
                let html = `<div class="detail-section-chic">
                    <div class="detail-section-title-chic info"><i class="bi bi-info-circle-fill"></i> INFORMATIONS GÉNÉRALES</div>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">N° BON</div><div class="fw-bold" style="color:#1e293b;font-size:15px;">${b.code_bon}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">DATE</div><div class="fw-semibold">${new Date(b.date_livraison).toLocaleDateString('fr-FR')}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">CLIENT</div><div class="fw-semibold">${b.nom_prenom_contact || 'N/C'}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">FACTURE ASSOCIÉE</div><div class="fw-semibold">${b.facture_id || 'Aucune'}</div></div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <span class="badge-chic ${statutColor}"><span class="dot"></span> ${b.statut}</span>
                        ${b.etat_facture ? `<span class="badge-chic ${b.etat_facture === 'Payee' || b.etat_facture === 'Payee cash' ? 'success' : (b.etat_facture === 'Partielle' ? 'warning' : 'danger')}"><span class="dot"></span> ${b.etat_facture}</span>` : ''}
                    </div>
                </div>
                <div class="detail-section-chic">
                    <div class="detail-section-title-chic delivery"><i class="bi bi-geo-alt-fill"></i> LIVRAISON</div>
                    <div class="row g-3">
                        <div class="col-12"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">ADRESSE DE LIVRAISON</div><div class="fw-semibold">${b.adresse_livraison || 'Non renseignée'}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">TRANSPORTEUR</div><div class="fw-semibold">${b.transporteur || 'Non renseigné'}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">COMMENTAIRE</div><div class="fst-italic text-muted">${b.commentaire || 'Aucun'}</div></div>
                    </div>
                </div>`;
                if (data.commandes && data.commandes.length > 0) {
                    let total = 0;
                    html += `<div class="detail-section-chic">
                        <div class="detail-section-title-chic box"><i class="bi bi-box-seam-fill"></i> PRODUITS À LIVRER (${data.commandes.length})</div>
                        <div class="table-responsive"><table class="modal-table"><thead><tr>
                            <th>Produit</th><th class="text-center">Qté</th><th class="text-end">Prix unit.</th><th class="text-end">Montant</th></tr></thead><tbody>`;
                    data.commandes.forEach(c => {
                        const montant = (parseFloat(c.quantite_commande) || 0) * (parseFloat(c.prix_commande) || 0);
                        total += montant;
                        html += `<tr><td class="fw-semibold">${c.titre_produit || 'N/A'}</td><td class="text-center">${c.quantite_commande}</td><td class="text-end">${Number(c.prix_commande).toLocaleString('fr-FR')} FCFA</td><td class="text-end fw-bold">${montant.toLocaleString('fr-FR')} FCFA</td></tr>`;
                    });
                    html += `</tbody><tfoot><tr style="background:var(--color-gray-100);"><th colspan="3" class="text-end text-uppercase" style="font-size:11px;">Total</th><th class="text-end fw-bold" style="color:var(--color-primary);">${total.toLocaleString('fr-FR')} FCFA</th></tr></tfoot></table></div></div>`;
                }
                $('#bonDetails').html(html).data('bon-id', b.code_bon);
                if (data.is_locked) {
                    $('#btnModifier').prop('disabled', true).addClass('disabled').css('opacity', '0.5').attr('title', 'Bon lié à une facture validée et payée, modification impossible');
                }
            },
            error: function() {
                $('#bonDetails').html('<div class="alert alert-danger">Erreur de chargement.</div>');
            }
        });
    });

    // Boutons du modal
    $('#btnModifier').click(function() {
        if ($(this).prop('disabled')) return;
        const id = $('#bonDetails').data('bon-id');
        if (id) {
            $('#bonModal').modal('hide');
            chargerEdition(id);
        }
    });

    $('#btnImprimer').click(function() {
        const id = $('#bonDetails').data('bon-id');
        if (id) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = baseUrl;
            form.target = '_self';
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'action'; input.value = 'pdf';
            form.appendChild(input);
            const input2 = document.createElement('input');
            input2.type = 'hidden'; input2.name = 'id'; input2.value = id;
            form.appendChild(input2);
            document.body.appendChild(form);
            form.submit();
            form.remove();
        }
    });

    $('#btnPartager').click(async function() {
        const id = $('#bonDetails').data('bon-id');
        if (!id) return;
        const btn = $(this);
        const originalHtml = btn.html();
        const params = new URLSearchParams({ action: 'pdf', id: id });
        let raisonRepli = null;
        if (!window.isSecureContext) {
            raisonRepli = "Le partage natif exige HTTPS (site actuellement en HTTP)";
        } else if (!navigator.share) {
            raisonRepli = "Ce navigateur ne supporte pas le partage natif";
        } else if (!navigator.canShare) {
            raisonRepli = "Ce navigateur ne supporte pas le partage de fichiers";
        }
        if (raisonRepli) console.warn('Partage natif indisponible :', raisonRepli);
        if (window.isSecureContext && navigator.share && navigator.canShare) {
            try {
                btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i><span>Préparation...</span>');
                const resp = await fetch(baseUrl, { method: 'POST', body: params });
                if (!resp.ok) throw new Error('pdf_fetch_failed');
                const blob = await resp.blob();
                if (blob.type && blob.type.indexOf('pdf') === -1) throw new Error('not_a_pdf');
                const file = new File([blob], 'bon-livraison-' + id + '.pdf', { type: 'application/pdf' });
                if (!navigator.canShare({ files: [file] })) {
                    raisonRepli = "Cet appareil ne peut pas partager de fichier PDF";
                } else {
                    await navigator.share({
                        files: [file],
                        title: 'Bon de livraison N°' + id,
                        text: 'Bonjour, voici votre bon de livraison N°' + id
                    });
                    btn.prop('disabled', false).html(originalHtml);
                    return;
                }
            } catch (e) {
                btn.prop('disabled', false).html(originalHtml);
                if (e && e.name === 'AbortError') return;
                if (e && e.name === 'NotAllowedError') raisonRepli = "Le navigateur a refusé le partage (délai trop long)";
                else if (e && e.message === 'pdf_fetch_failed') raisonRepli = "Échec du téléchargement du PDF";
                else if (e && e.message === 'not_a_pdf') raisonRepli = "Le fichier reçu n'est pas un PDF valide";
                else raisonRepli = "Erreur inattendue lors du partage natif";
                console.warn('Partage natif indisponible :', raisonRepli, e);
            }
        }
        btn.prop('disabled', false).html(originalHtml);
        if (raisonRepli) showToast('Partage natif indisponible (' + raisonRepli + '), ouverture de WhatsApp Web à la place', 'info');
        window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent('Bonjour, voici votre bon de livraison N°' + id), '_blank');
    });

    // PDF direct depuis la carte
    $(document).on('click', '.pdf-bon', function(e) {
        e.stopPropagation();
        const id = $(this).data('id');
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = baseUrl;
        form.target = '_self';
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = 'action'; input.value = 'pdf';
        form.appendChild(input);
        const input2 = document.createElement('input');
        input2.type = 'hidden'; input2.name = 'id'; input2.value = id;
        form.appendChild(input2);
        document.body.appendChild(form);
        form.submit();
        form.remove();
    });

    // Supprimer bon
    $(document).on('click', '.supprimer-bon', function(e) {
        e.stopPropagation();
        bonToDelete = $(this).data('id');
        $('#deleteBonId').text(bonToDelete);
        deleteModal.show();
    });

    $('#confirmDeleteBtn').on('click', function() {
        if (!bonToDelete) return;
        const btn = $(this);
        const id = bonToDelete;
        const cardItem = $('.bon-item[data-id="' + id + '"]');
        const card = cardItem.find('.bon-card');
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Suppression...');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'delete_bon', id: id },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    deleteModal.hide();
                    showToast('Bon ' + id + ' supprimé', 'success');
                    card.addClass('deleting');
                    setTimeout(function() {
                        cardItem.fadeOut(300, function() {
                            $(this).remove();
                            if ($('.bon-item').length === 0) {
                                $('#bonsGrid').html(`<div class="col-12"><div class="bg-white border border-dashed rounded-3 p-5 text-center text-muted"><i class="bi bi-inbox d-block mb-2" style="font-size:56px;opacity:.2;"></i><h5 class="text-dark">Aucun bon de livraison</h5><p class="small mb-0">Les bons de livraison apparaîtront ici dès leur création.</p></div></div>`);
                            }
                        });
                    }, 400);
                    bonToDelete = null;
                    btn.prop('disabled', false).html('<i class="bi bi-trash3 me-1"></i> Supprimer');
                } else {
                    showToast('Erreur : ' + (resp.error || 'Inconnue'), 'error');
                    btn.prop('disabled', false).html('<i class="bi bi-trash3 me-1"></i> Supprimer');
                }
            },
            error: function() {
                showToast('Erreur de communication', 'error');
                btn.prop('disabled', false).html('<i class="bi bi-trash3 me-1"></i> Supprimer');
            }
        });
    });

    // Filtres
    $('#filterBtn').on('click', function() {
        const selStatut = $('#statutFilter').val();
        const selEtatFacture = $('#etatFactureFilter').val();
        const selStatutFacture = $('#statutFactureFilter').val();
        let count = 0;
        $('.bon-item').each(function() {
            const matchStatut = (selStatut === '' || String($(this).data('statut')) === String(selStatut));
            const matchEtatFacture = (selEtatFacture === '' || String($(this).data('etat-facture')) === String(selEtatFacture));
            const matchStatutFacture = (selStatutFacture === '' || String($(this).data('statut-facture')) === String(selStatutFacture));
            if (matchStatut && matchEtatFacture && matchStatutFacture) {
                $(this).show();
                count++;
            } else {
                $(this).hide();
            }
        });
        showToast(count + ' bon(s) affiché(s)', 'info');
    });

    $('#resetBtn').on('click', function() {
        $('#statutFilter').selectpicker('val', '');
        $('#etatFactureFilter').selectpicker('val', '');
        $('#statutFactureFilter').selectpicker('val', '');
        $('.bon-item').show();
        showToast('Filtres réinitialisés', 'info');
    });

    // Modifier bon
    function chargerEdition(id) {
        $('#editSection').show();
        $('#editContent').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted small">Chargement...</p></div>');
        $('html, body').animate({ scrollTop: $('#editSection').offset().top - 20 }, 400);
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'get_details', id: id },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#editContent').html('<div class="alert alert-danger">' + data.error + '</div>');
                    return;
                }
                if (data.is_locked) {
                    $('#editContent').html('<div class="alert alert-warning"><i class="bi bi-lock-fill me-2"></i>Ce bon est lié à une facture validée et payée. Il ne peut plus être modifié.</div>');
                    return;
                }
                afficherFormulaireEdition(data);
            },
            error: function() {
                $('#editContent').html('<div class="alert alert-danger">Erreur de chargement.</div>');
            }
        });
    }

    function afficherFormulaireEdition(data) {
        const b = data.bon;
        let html = `<form id="editBonForm" class="edit-form-chic">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Adresse de livraison</label>
                    <textarea class="form-control" id="edit_adresse" rows="2">${b.adresse_livraison || ''}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Transporteur</label>
                    <input type="text" class="form-control" id="edit_transporteur" value="${b.transporteur || ''}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Statut</label>
                    <select class="form-select" id="edit_statut">
                        <option value="En préparation" ${b.statut === 'En préparation' ? 'selected' : ''}>En préparation</option>
                        <option value="Expédié" ${b.statut === 'Expédié' ? 'selected' : ''}>Expédié</option>
                        <option value="Livré" ${b.statut === 'Livré' ? 'selected' : ''}>Livré</option>
                        <option value="Annulé" ${b.statut === 'Annulé' ? 'selected' : ''}>Annulé</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Commentaire</label>
                    <textarea class="form-control" id="edit_commentaire" rows="3">${b.commentaire || ''}</textarea>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2 justify-content-end">
                <button type="button" class="btn-action-chic annuler" id="btnAnnulerEdit"><i class="bi bi-x-lg"></i> Annuler</button>
                <button type="submit" class="btn-action-chic enregistrer"><i class="bi bi-check-lg"></i> Enregistrer</button>
            </div>
        </form>`;
        $('#editContent').html(html).data('bon-id', b.code_bon);
        $('#btnAnnulerEdit').click(function() {
            $('#editSection').hide();
            $('#editContent').empty();
        });
        $('#editBonForm').on('submit', function(e) {
            e.preventDefault();
            const formData = {
                action: 'update_bon',
                bon_id: $('#editContent').data('bon-id'),
                adresse_livraison: $('#edit_adresse').val(),
                transporteur: $('#edit_transporteur').val(),
                statut: $('#edit_statut').val(),
                commentaire: $('#edit_commentaire').val()
            };
            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: formData,
                success: function(resp) {
                    if (resp.success) {
                        showToast('Bon mis à jour');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast('Erreur : ' + (resp.error || 'Inconnue'), 'error');
                    }
                },
                error: function() {
                    showToast('Erreur de communication', 'error');
                }
            });
        });
    }

    $('#btnRetourListe').click(function() {
        $('#editSection').hide();
        $('#editContent').empty();
    });
});
</script>
</body>
</html>