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
    if (!isset($_POST['id']) || empty($_POST['id'])) die("ID de facture manquant.");
    $id = $_POST['id'];
    try {
        $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact, c.adresse_contact, c.telephone_contact AS tel_contact, c.email_contact
            FROM facture f LEFT JOIN contact c ON f.contact_id = c.code_contact
            WHERE f.numero_facture = ?");
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$facture) die("Facture introuvable.");

        $stmtCmd = $pdo->prepare("SELECT c.*, p.titre_produit, p.code_produit AS reference_produit, l.libelle AS libelle_lot
            FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit
            LEFT JOIN lot l ON c.lot_id = l.code_lot
            WHERE c.facture_id = ?");
        $stmtCmd->execute([$id]);
        $commandes = $stmtCmd->fetchAll(PDO::FETCH_ASSOC);

        $boutique_id = $commandes[0]['boutique_id'] ?? null;
        if (empty($boutique_id) && !empty($facture['utilisateur_id'])) {
            $stmtU = $pdo->prepare("SELECT boutique_id FROM utilisateur WHERE id = ?");
            $stmtU->execute([$facture['utilisateur_id']]);
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
    $estValidee = ($facture['statut_facture'] ?? '') === 'Validee';
    $titreDoc = strtoupper($estValidee ? 'Facture client' : 'Bon de commande');

    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 22);
    $pdf->SetXY(10, 12);
    $pdf->Cell(130, 12, $titreDoc, 0, 1, 'L');
    if ($logoTmpPath) {
        $pdf->Image($logoTmpPath, 155, 8, 45);
        unlink($logoTmpPath);
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetX(10);
    $pdf->Cell(130, 6, 'N° ' . $facture['numero_facture'], 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetX(10);
    $pdf->Cell(130, 5, 'Date : ' . date('d/m/Y', strtotime($facture['date_facture'])), 0, 1, 'L');
    $pdf->SetX(10);
    $pdf->Cell(130, 5, 'Statut : ' . ($facture['statut_facture'] ?? ''), 0, 1, 'L');
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

    $clientNom = $facture['nom_prenom_contact'] ?? 'N/C';
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetXY(110, $yBoxes);
    $pdf->MultiCell(90, 5.5, $clientNom, 0, 'L', true);
    $yDestAfterNom = $pdf->GetY();
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(110, $yDestAfterNom);
    $pdf->MultiCell(90, 5.5, ($facture['adresse_contact'] ?? '') . "\nTel: " . ($facture['tel_contact'] ?? '') . "\nEmail: " . ($facture['email_contact'] ?? ''), 0, 'L', true);
    $yAfterDest = $pdf->GetY();
    $pdf->SetY(max($yAfterExp, $yAfterDest) + 6);

    $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(20, 7, 'REF.', 0, 0, 'C', true);
    $pdf->Cell(55, 7, 'DESIGNATION', 0, 0, 'C', true);
    $pdf->Cell(25, 7, 'LOT/UNITE', 0, 0, 'C', true);
    $pdf->Cell(40, 7, 'QUANTITE', 0, 0, 'C', true);
    $pdf->Cell(25, 7, 'P.U.(FCFA)', 0, 0, 'C', true);
    $pdf->Cell(25, 7, 'MONTANT(FCFA)', 0, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor($border[0], $border[1], $border[2]);
    $pdf->SetFont('Arial', '', 9);
    $total_ht = 0;
    foreach ($commandes as $cmd) {
        $montant_ligne = $cmd['quantite_commande'] * $cmd['prix_commande'];
        $total_ht += $montant_ligne;
        $ref = $cmd['reference_produit'] ?? '';
        $designation = $cmd['titre_produit'] ?? '';
        $nbLines = max(1, ceil(strlen($designation) / 25));
        $rowHeight = 7 * $nbLines;
        if ($pdf->GetY() + $rowHeight > 270) $pdf->AddPage();
        $produitsParLot = intval($cmd['produits_par_lot'] ?? 0);
        $libelleLot = !empty($cmd['libelle_lot']) ? $cmd['libelle_lot'] : ($produitsParLot > 0 ? $produitsParLot . '/lot' : 'Unite');
        if ($produitsParLot > 0) {
            $nombreLots = intdiv($cmd['quantite_commande'], $produitsParLot);
            $reste = $cmd['quantite_commande'] % $produitsParLot;
            $qteAffichee = $reste > 0 ? ($nombreLots . ' lot(s) et ' . $reste . ' produit(s)') : ($nombreLots . ' lot(s)');
        } else {
            $qteAffichee = (string)$cmd['quantite_commande'];
        }
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->MultiCell(20, 7, $ref, 1, 'L');
        $pdf->SetXY($x + 20, $y); $pdf->MultiCell(55, 7, $designation, 1, 'L');
        $pdf->SetXY($x + 75, $y); $pdf->MultiCell(25, 7, $libelleLot, 1, 'C');
        $pdf->SetXY($x + 100, $y);
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->Cell(40, $rowHeight, $qteAffichee, 1, 0, 'C');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(25, $rowHeight, number_format($cmd['prix_commande'], 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell(25, $rowHeight, number_format($montant_ligne, 0, ',', ' '), 1, 1, 'R');
    }
    $pdf->Ln(6);

    $taxe = $facture['taxe'] ?? ($total_ht * 0.18);
    $remise = $facture['remise'] ?? 0;
    $ttc = $facture['montant_ttc'] ?? ($total_ht + $taxe - $remise);
    if ($estValidee) {
        $lignesTotaux = [['TOTAL HT', $total_ht], ['TVA (18%)', $taxe], ['REMISE', $remise]];
    } else {
        $lignesTotaux = [['TOTAL HT', $total_ht]];
        if ($taxe > 0) $lignesTotaux[] = ['TVA (18%)', $taxe];
        if ($remise > 0) $lignesTotaux[] = ['REMISE', $remise];
    }
    $yBloc = $pdf->GetY();
    $obsWidth = 100; $obsHeight = 32;
    $pdf->SetDrawColor($border[0], $border[1], $border[2]);
    $pdf->Rect(10, $yBloc, $obsWidth, $obsHeight);
    $pdf->SetXY(12, $yBloc + 2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($obsWidth - 4, 5, 'Observations :', 0, 1, 'L');
    $pdf->SetX(12);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell($obsWidth - 4, 5, "Merci de votre confiance.\nVeuillez respecter les délais de livraison.", 0, 'L');
    $totWidth = 80; $totX = 200 - $totWidth;
    $rowH = 7;
    $nbRows = count($lignesTotaux) + 1;
    $pdf->SetDrawColor($navy[0], $navy[1], $navy[2]);
    $pdf->Rect($totX, $yBloc, $totWidth, $rowH * $nbRows);
    $pdf->SetFont('Arial', '', 9.5);
    $curY = $yBloc;
    foreach ($lignesTotaux as $ligne) {
        $pdf->SetXY($totX, $curY);
        $pdf->Cell($totWidth - 30, $rowH, ' ' . $ligne[0], 'B', 0, 'L');
        $pdf->Cell(30, $rowH, number_format($ligne[1], 0, ',', ' ') . ' FCFA', 'B', 1, 'R');
        $curY += $rowH;
    }
    $pdf->SetXY($totX, $curY);
    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($totWidth - 30, $rowH + 1, ' NET A PAYER (TTC)', 0, 0, 'L');
    $pdf->Cell(30, $rowH + 1, number_format($ttc, 0, ',', ' ') . ' FCFA', 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($yBloc + max($obsHeight, $rowH * $nbRows) + 10);

    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(90, 6, 'Le vendeur', 0, 0, 'L');
    $pdf->Cell(10);
    $pdf->Cell(90, 6, 'Le client', 0, 1, 'L');
    $pdf->Cell(90, 6, 'Nom et Signature', 0, 0, 'L');
    $pdf->Cell(10);
    $pdf->Cell(90, 6, 'Nom et Signature', 0, 1, 'L');
    $ySign = $pdf->GetY() + 2;
    $pdf->SetDrawColor($navy[0], $navy[1], $navy[2]);
    $pdf->Rect(10, $ySign, 90, 20);
    $pdf->Rect(110, $ySign, 90, 20);
    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetXY(12, $ySign + 2);
    $pdf->Cell(86, 5, $nomBoutique, 0, 1, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetX(12);
    $pdf->Cell(86, 4, $adresseBoutique, 0, 1, 'L');
    $pdf->SetX(12);
    $pdf->Cell(86, 4, 'Tel: ' . $telBoutique, 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Output('I', ($estValidee ? 'Facture_' : 'Bon_de_commande_') . $id . '.pdf');
    exit;
}

// ==========================================
// 3. TRAITEMENT DES ACTIONS (AJAX / POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'validate_facture') {
        header('Content-Type: application/json');
        $id = $_POST['id'] ?? '';
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'ID manquant']); exit; }
        try {
            $stmt = $pdo->prepare("UPDATE facture SET statut_facture = 'Validee' WHERE numero_facture = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Facture validée']);
        } catch (Exception $e) {
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
            $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM facture WHERE numero_facture = ?");
            $stmt->execute([$id]);
            $pdo->commit();
            echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Facture supprimée']);
        } catch (Exception $e) {
            $pdo->rollBack();
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
        $stmt2 = $pdo->prepare("SELECT c.*, p.titre_produit FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit WHERE c.facture_id = ?");
        $stmt2->execute([$id]);
        $isLocked = in_array(strtolower($facture['etat_facture'] ?? ''), ['payee', 'payee cash'])
            && strtolower($facture['statut_facture'] ?? '') === 'validee';
        echo json_encode([
            'facture' => $facture,
            'commandes' => $stmt2->fetchAll(PDO::FETCH_ASSOC),
            'is_locked' => $isLocked
        ]);
        exit;
    }

    if ($action === 'update_facture') {
        $facture_id = $_POST['facture_id'];
        $checkStmt = $pdo->prepare("SELECT etat_facture, statut_facture FROM facture WHERE numero_facture = ?");
        $checkStmt->execute([$facture_id]);
        $fData = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $isLocked = $fData && (in_array(strtolower($fData['etat_facture']), ['payee', 'payee cash'])
            && strtolower($fData['statut_facture']) === 'validee');
        if ($isLocked) {
            echo json_encode(['success' => false, 'error' => 'Facture validée et payée, modification impossible.']);
            exit;
        }
        $avance = floatval($_POST['avance']);
        $reste = floatval($_POST['reste']);
        $commandes_data = json_decode($_POST['commandes'], true);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE facture SET avance = ?, reste = ? WHERE numero_facture = ?")->execute([$avance, $reste, $facture_id]);
            foreach ($commandes_data as $cmd) {
                if ($cmd['supprimer']) {
                    $pdo->prepare("DELETE FROM commande WHERE numero_commande = ?")->execute([$cmd['id']]);
                } else {
                    $montant = $cmd['quantite'] * $cmd['prix'];
                    $produits_par_lot = max(1, intval($cmd['produits_par_lot'] ?? 1));
                    $pdo->prepare("UPDATE commande SET quantite_commande = ?, prix_commande = ?, produits_par_lot = ?, montant_commande = ? WHERE numero_commande = ?")
                        ->execute([$cmd['quantite'], $cmd['prix'], $produits_par_lot, $montant, $cmd['id']]);
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// ==========================================
// 4. DONNÉES ET STATISTIQUES
// ==========================================
function getEtatBadge($etat) {
    $etatLower = strtolower($etat);
    if ($etatLower === 'payee' || $etatLower === 'payee cash') return ['success', 'check-circle-fill'];
    if ($etatLower === 'partielle') return ['warning', 'hourglass-split'];
    if ($etatLower === 'impayee') return ['danger', 'x-circle-fill'];
    return ['secondary', 'question-circle'];
}

function getStatutBadge($statut) {
    return strtolower($statut) === 'validee' ? 'primary' : 'secondary';
}

$stmtClients = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE etat_contact = 'Actif' AND type_contact = 'Client' ORDER BY nom_prenom_contact ASC");
$clients = $stmtClients->fetchAll(PDO::FETCH_ASSOC);
$totalFactures = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client'")->fetchColumn();
$payees = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' AND f.etat_facture IN ('Payee', 'Payee cash')")->fetchColumn();
$partielles = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' AND f.etat_facture = 'Partielle'")->fetchColumn();
$impayees = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' AND f.etat_facture = 'Impayee'")->fetchColumn();
$totalMontant = $pdo->query("SELECT SUM(f.montant_ttc) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client'")->fetchColumn() ?? 0;
$totalReste = $pdo->query("SELECT SUM(f.reste) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client'")->fetchColumn() ?? 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Factures</title>
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

        /* ===== CARTES FACTURES (compactes - 4 par ligne) ===== */
        .facture-card {
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
        .facture-card:hover {
            border-color: var(--color-primary);
            box-shadow: 0 4px 12px rgba(79, 70, 229, .12);
            transform: translateY(-2px);
        }
        .facture-card.validated {
            border-color: var(--color-success);
            background: #f0fdf4;
        }
        .facture-card.validated::after {
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
        .fc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
        .fc-number { font-size: 11px; font-weight: 700; color: var(--color-primary-dark); font-family: 'Outfit', sans-serif; }
        .facture-card.validated .fc-number { color: #059669; }
        .fc-amount { font-size: 14px; font-weight: 800; color: var(--color-primary); font-family: 'Outfit', sans-serif; line-height: 1; }
        .fc-amount small { font-size: 9px; color: var(--text-tertiary); margin-left: 2px; }
        .fc-middle { flex: 1; display: flex; flex-direction: column; gap: 3px; margin: 4px 0; }
        .fc-client { font-size: 11px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fc-client i { color: var(--color-primary); font-size: 11px; flex-shrink: 0; }
        .fc-date { font-size: 9px; color: var(--text-tertiary); display: flex; align-items: center; gap: 3px; }
        .fc-badges { display: flex; gap: 3px; flex-wrap: wrap; margin-top: 2px; }
        .badge-pill { display: inline-flex; align-items: center; gap: 2px; padding: 1px 6px; border-radius: 999px; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .fc-bottom { border-top: 1px dashed var(--border-color); padding-top: 6px; display: flex; justify-content: flex-end; align-items: center; gap: 4px; }

        /* Boutons icônes outline */
        .icon-btn {
            width: 26px; height: 26px; border-radius: 5px; border: 1.5px solid transparent;
            background: transparent; display: inline-flex; align-items: center; justify-content: center;
            transition: all .2s; font-size: 12px; cursor: pointer; padding: 0; position: relative;
        }
        .icon-btn:hover { transform: scale(1.1); }
        .icon-btn.view { color: var(--color-primary); border-color: rgba(79, 70, 229, 0.2); }
        .icon-btn.view:hover { color: var(--color-primary-dark); background: var(--color-primary-soft); border-color: var(--color-primary); }
        .icon-btn.validate { color: var(--color-success); border-color: rgba(16, 185, 129, 0.2); }
        .icon-btn.validate:hover { color: #059669; background: var(--color-success-soft); border-color: var(--color-success); }
        .icon-btn.validate.validated { color: var(--color-success); background: var(--color-success-soft); border-color: var(--color-success); cursor: default; opacity: 0.7; }
        .icon-btn.validate.validated:hover { transform: none; }
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
        .modal-chic .modal-footer { background: #fff; border-top: 1px solid var(--border-color); padding: 18px 28px; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }

        /* Sections chic */
        .detail-section-chic { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 20px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); transition: all .2s; }
        .detail-section-chic:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.06); border-color: #cbd5e1; }
        .detail-section-title-chic { font-size: 11px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.8px; padding-bottom: 10px; border-bottom: 2px solid #f1f5f9; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .detail-section-title-chic.info i { color: #3b82f6; }
        .detail-section-title-chic.money i { color: #10b981; }
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
        .edit-header-chic h2 i { color: #3b82f6; font-size: 22px; }
        .btn-retour-chic { background: #f1f5f9; color: #475569; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border-color); transition: all .2s; }
        .btn-retour-chic:hover { background: var(--color-gray-200); color: #1e293b; }
        .edit-table-chic { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; border-radius: 10px; overflow: hidden; border: 1px solid var(--border-color); }
        .edit-table-chic thead th { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; padding: 12px 14px; border-bottom: 2px solid var(--border-color); }
        .edit-table-chic tbody td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; color: #1e293b; background: #fff; }
        .edit-table-chic tbody tr:hover td { background: #f8fafc; }
        .edit-table-chic tbody tr:last-child td { border-bottom: none; }
        .btn-delete-chic { width: 32px; height: 32px; border-radius: 8px; border: 1px solid #fecaca; background: #fff; color: var(--color-danger); display: inline-flex; align-items: center; justify-content: center; transition: all .2s; }
        .btn-delete-chic:hover { background: #fee2e2; border-color: var(--color-danger); transform: scale(1.1); }
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
        .facture-card.deleting { animation: fadeOut .4s ease forwards; }
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
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-receipt text-primary me-2"></i>Gestion des Factures</h1>
            <p class="text-muted small mb-0">Suivez et gérez toutes vos factures clients en un coup d'œil</p>
        </div>
        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
            <i class="bi bi-file-earmark-text"></i> <?= $totalFactures ?> facture(s)
        </span>
    </div>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['primary', 'file-earmark-text', 'Total factures', $totalFactures, ''],
            ['success', 'check-circle-fill', 'Payées', $payees, ''],
            ['warning', 'hourglass-split', 'Partielles', $partielles, ''],
            ['danger', 'x-circle-fill', 'Impayées', $impayees, ''],
            ['purple', 'cash-coin', 'Total TTC', number_format($totalMontant, 0, ',', ' '), ' FCFA'],
            ['info', 'wallet2', 'Reste à payer', number_format($totalReste, 0, ',', ' '), ' FCFA'],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
            'danger' => ['var(--color-danger-soft)', 'var(--color-danger)'],
            'purple' => ['var(--color-purple-soft)', 'var(--color-purple)'],
            'info' => ['var(--color-info-soft)', 'var(--color-info)'],
        ];
        foreach ($stats as $s):
            $bg = $colorMap[$s[0]][0];
            $fg = $colorMap[$s[0]][1];
        ?>
        <div class="col-6 col-md-4 col-xl-2">
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
                <label for="clientFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-person"></i> Client</label>
                <select id="clientFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un client...">
                    <option value="">Tous les clients</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= htmlspecialchars($client['code_contact']) ?>"><?= htmlspecialchars($client['nom_prenom_contact']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="etatFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-cash-coin"></i> État</label>
                <select id="etatFilter" class="selectpicker">
                    <option value="">Tous les états</option>
                    <option value="Impayee">Impayée</option>
                    <option value="Partielle">Partielle</option>
                    <option value="Payee cash">Payée cash</option>
                    <option value="Payee">Payée</option>
                </select>
                <label for="statutFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-check2-square"></i> Statut</label>
                <select id="statutFilter" class="selectpicker">
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

    <!-- Liste des factures -->
    <div class="row g-3" id="facturesGrid">
        <?php
        $sql = "SELECT f.*, c.nom_prenom_contact FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' ORDER BY f.date_facture DESC";
        $factures = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (empty($factures)):
        ?>
        <div class="col-12">
            <div class="bg-white border border-dashed rounded-3 p-5 text-center text-muted">
                <i class="bi bi-inbox d-block mb-2" style="font-size:56px;opacity:.2;"></i>
                <h5 class="text-dark">Aucune facture trouvée</h5>
                <p class="small mb-0">Les factures apparaîtront ici dès leur création.</p>
            </div>
        </div>
        <?php else: foreach($factures as $row):
            $etatBadge = getEtatBadge($row['etat_facture']);
            $isValidee = (strtolower($row['statut_facture']) === 'validee');
        ?>
        <div class="col-12 col-md-6 col-lg-4 col-xl-3 facture-item"
             data-client="<?= htmlspecialchars($row['contact_id'] ?? '') ?>"
             data-etat="<?= htmlspecialchars($row['etat_facture'] ?? '') ?>"
             data-statut="<?= htmlspecialchars($row['statut_facture'] ?? '') ?>"
             data-id="<?= htmlspecialchars($row['numero_facture']) ?>">
            <div class="facture-card <?= $isValidee ? 'validated' : '' ?>">
                <div class="fc-top">
                    <div>
                        <div class="fc-number"><?= htmlspecialchars($row['numero_facture']) ?></div>
                        <div class="fc-date"><i class="bi bi-calendar3"></i> <?= date('d/m/Y', strtotime($row['date_facture'])) ?></div>
                    </div>
                    <div class="fc-amount"><?= number_format($row['montant_ttc'], 0, ',', ' ') ?><small>FCFA</small></div>
                </div>
                <div class="fc-middle">
                    <div class="fc-client">
                        <i class="bi bi-person"></i>
                        <span><?= htmlspecialchars($row['nom_prenom_contact'] ?? 'N/C') ?></span>
                    </div>
                    <div class="fc-badges">
                        <span class="badge-pill bg-<?= $etatBadge[0] ?>-subtle text-<?= $etatBadge[0] ?>">
                            <i class="bi bi-<?= $etatBadge[1] ?>" style="font-size:8px;"></i> <?= $row['etat_facture'] ?>
                        </span>
                        <span class="badge-pill bg-<?= getStatutBadge($row['statut_facture']) ?>-subtle text-<?= getStatutBadge($row['statut_facture']) ?>">
                            <i class="bi bi-<?= $isValidee ? 'check2' : 'clock' ?>" style="font-size:8px;"></i> <?= $row['statut_facture'] ?>
                        </span>
                    </div>
                </div>
                <div class="fc-bottom">
                    <button class="icon-btn view voir-facture" data-id="<?= $row['numero_facture'] ?>" data-tooltip="Voir détails" title="Voir détails"><i class="bi bi-eye"></i></button>
                    <?php if (!$isValidee): ?>
                        <button class="icon-btn validate valider-facture" data-id="<?= $row['numero_facture'] ?>" data-tooltip="Valider" title="Valider"><i class="bi bi-check2-circle"></i></button>
                    <?php else: ?>
                        <button class="icon-btn validate validated" disabled data-tooltip="Validée" title="Validée"><i class="bi bi-check-circle-fill"></i></button>
                    <?php endif; ?>
                    <button class="icon-btn delete supprimer-facture" data-id="<?= $row['numero_facture'] ?>" data-tooltip="Supprimer" title="Supprimer"><i class="bi bi-trash"></i></button>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- Modal détails chic -->
<div class="modal fade modal-chic" id="factureModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt-cutoff"></i><span id="modalTitleText">Détails de la facture</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="factureDetails">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Chargement...</span></div>
                    <p class="mt-3 text-muted small">Chargement des détails...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-chic btn-chic-modifier" id="btnModifier"><i class="bi bi-pencil-square"></i><span>Modifier</span></button>
                <button class="btn-chic btn-chic-imprimer" id="btnImprimer"><i class="bi bi-printer-fill"></i><span>Imprimer</span></button>
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
                <p class="text-muted small mb-4">Êtes-vous sûr de vouloir supprimer la facture <strong id="deleteFactureId" class="text-danger"></strong> ?<br>Cette action est irréversible.</p>
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
        <h2><i class="bi bi-pencil-square"></i> Modification de la facture</h2>
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
    let factureToDelete = null;

    function showToast(msg, type = 'success') {
        const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-primary' };
        const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        $('#toastBody').html(`<i class="bi ${icons[type]} me-2"></i>${msg}`);
        toastEl.className = `toast align-items-center text-white border-0 ${colors[type]}`;
        toast.show();
    }

    // Valider facture
    $(document).on('click', '.valider-facture', function(e) {
        e.stopPropagation();
        const btn = $(this), id = btn.data('id'), card = btn.closest('.facture-card');
        if (!confirm('Valider la facture ' + id + ' ?')) return;
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i>');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'validate_facture', id: id },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    showToast('Facture ' + id + ' validée');
                    card.addClass('validated');
                    card.find('.fc-badges .badge-pill').last().removeClass('bg-secondary-subtle text-secondary').addClass('bg-primary-subtle text-primary').html('<i class="bi bi-check2" style="font-size:8px;"></i> VALIDEE');
                    card.find('.fc-number').css('color', '#059669');
                    btn.replaceWith('<button class="icon-btn validate validated" disabled data-tooltip="Validée" title="Validée"><i class="bi bi-check-circle-fill"></i></button>');
                } else {
                    showToast('Erreur : ' + (resp.error|| 'Inconnue'), 'error');
                    btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i>');
                }
            },
            error: function() {
                showToast('Erreur de communication', 'error');
                btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i>');
            }
        });
    });

    // Supprimer facture
    $(document).on('click', '.supprimer-facture', function(e) {
        e.stopPropagation();
        factureToDelete = $(this).data('id');
        $('#deleteFactureId').text(factureToDelete);
        deleteModal.show();
    });

    $('#confirmDeleteBtn').on('click', function() {
        if (!factureToDelete) return;
        const btn = $(this);
        const id = factureToDelete;
        const cardItem = $('.facture-item[data-id="' + id + '"]');
        const card = cardItem.find('.facture-card');
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Suppression...');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'delete_facture', id: id },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    deleteModal.hide();
                    showToast('Facture ' + id + ' supprimée', 'success');
                    card.addClass('deleting');
                    setTimeout(function() {
                        cardItem.fadeOut(300, function() {
                            $(this).remove();
                            if ($('.facture-item').length === 0) {
                                $('#facturesGrid').html(`<div class="col-12"><div class="bg-white border border-dashed rounded-3 p-5 text-center text-muted"><i class="bi bi-inbox d-block mb-2" style="font-size:56px;opacity:.2;"></i><h5 class="text-dark">Aucune facture trouvée</h5><p class="small mb-0">Les factures apparaîtront ici dès leur création.</p></div></div>`);
                            }
                        });
                    }, 400);
                    factureToDelete = null;
                    btn.prop('disabled', false).html('<i class="bi bi-trash3 me-1"></i> Supprimer');
                } else {
                    showToast('Erreur : ' + (resp.error|| 'Inconnue'), 'error');
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
        const selClient = $('#clientFilter').val();
        const selEtat = $('#etatFilter').val();
        const selStatut = $('#statutFilter').val();
        let count = 0;
        $('.facture-item').each(function() {
            const matchClient = (selClient === '' || String($(this).data('client')) === String(selClient));
            const matchEtat = (selEtat === '' || String($(this).data('etat')) === String(selEtat));
            const matchStatut = (selStatut === '' || String($(this).data('statut')) === String(selStatut));
            if (matchClient && matchEtat && matchStatut) {
                $(this).show();
                count++;
            } else {
                $(this).hide();
            }
        });
        showToast(count + ' facture(s) affichée(s)', 'info');
    });

    $('#resetBtn').on('click', function() {
        $('#clientFilter').selectpicker('val', '');
        $('#etatFilter').selectpicker('val', '');
        $('#statutFilter').selectpicker('val', '');
        $('.facture-item').show();
        showToast('Filtres réinitialisés', 'info');
    });

    // Voir détails
    $(document).on('click', '.voir-facture', function(e) {
        e.stopPropagation();
        const id = $(this).data('id');
        $('#modalTitleText').text('Facture ' + id);
        $('#factureDetails').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted small">Chargement...</p></div>');
        $('#btnModifier').prop('disabled', false).removeClass('disabled').css('opacity', '1').attr('title', 'Modifier');
        $('#factureModal').modal('show');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'get_details', id: id },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#factureDetails').html('<div class="alert alert-danger">' + data.error + '</div>');
                    return;
                }
                const f = data.facture;
                const etatColor = f.etat_facture === 'PAYEE' || f.etat_facture === 'Payee' || f.etat_facture === 'Payee cash' ? 'success' : (f.etat_facture === 'PARTIELLE' || f.etat_facture === 'Partielle' ? 'warning' : 'danger');
                const statutColor = (f.statut_facture === 'VALIDEE' || f.statut_facture === 'Validee') ? 'primary' : 'secondary';
                let html = `<div class="detail-section-chic">
                    <div class="detail-section-title-chic info"><i class="bi bi-info-circle-fill"></i> INFORMATIONS GÉNÉRALES</div>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">N° FACTURE</div><div class="fw-bold" style="color:#1e293b;font-size:15px;">${f.numero_facture}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">DATE</div><div class="fw-semibold">${new Date(f.date_facture).toLocaleDateString('fr-FR')}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">CLIENT</div><div class="fw-semibold">${f.nom_prenom_contact|| 'N/C'}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">TYPE</div><div class="fw-semibold">${f.type_facture}</div></div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <span class="badge-chic ${etatColor}"><span class="dot"></span> ${f.etat_facture}</span>
                        <span class="badge-chic ${statutColor}"><span class="dot"></span> ${f.statut_facture}</span>
                    </div>
                </div>
                <div class="detail-section-chic">
                    <div class="detail-section-title-chic money"><i class="bi bi-cash-stack"></i> MONTANTS</div>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">MONTANT HT</div><div class="fw-semibold">${Number(f.montant_ht).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">TAXE</div><div class="fw-semibold">${Number(f.taxe).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">REMISE</div><div class="fw-semibold">${Number(f.remise).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">MONTANT TTC</div><div class="fw-bold" style="color:#10b981;font-size:18px;font-family:'Outfit',sans-serif;">${Number(f.montant_ttc).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">AVANCE</div><div class="fw-bold text-success">${Number(f.avance).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">RESTE</div><div class="fw-bold text-danger">${Number(f.reste).toLocaleString('fr-FR')} FCFA</div></div>
                    </div>
                </div>`;
                if (data.commandes.length > 0) {
                    html += `<div class="detail-section-chic">
                        <div class="detail-section-title-chic box"><i class="bi bi-box-seam-fill"></i> LIGNES DE LA FACTURE (${data.commandes.length})</div>
                        <div class="table-responsive"><table class="modal-table"><thead><tr><th>Produit</th><th class="text-center">Qté</th><th class="text-center">Produits/lot</th><th class="text-center">Nb lots à livrer</th><th class="text-end">Prix unit.</th><th class="text-end">Montant</th></tr></thead><tbody>`;
                    data.commandes.forEach(c => {
                        const ppl = parseInt(c.produits_par_lot) || 1;
                        const qte = parseInt(c.quantite_commande) || 0;
                        const nbLots = Math.floor(qte / ppl);
                        const resteUnites = qte % ppl;
                        const nbLotsText = resteUnites > 0 ? `${nbLots} + ${resteUnites} unité(s)` : `${nbLots}`;
                        html += `<tr><td class="fw-semibold">${c.titre_produit}</td><td class="text-center">${qte}</td><td class="text-center">${ppl}</td><td class="text-center fw-bold">${nbLotsText}</td><td class="text-end">${Number(c.prix_commande).toLocaleString('fr-FR')} FCFA</td><td class="text-end fw-bold">${Number(c.montant_commande).toLocaleString('fr-FR')} FCFA</td></tr>`;
                    });
                    html += '</tbody></table></div></div>';
                }
                $('#factureDetails').html(html).data('facture-id', f.numero_facture);
                if (data.is_locked) {
                    $('#btnModifier').prop('disabled', true).addClass('disabled').css('opacity', '0.5').attr('title', 'Facture validée et payée, modification impossible');
                }
            },
            error: function() {
                $('#factureDetails').html('<div class="alert alert-danger">Erreur de chargement.</div>');
            }
        });
    });

    $('#btnModifier').click(function() {
        if ($(this).prop('disabled')) return;
        const id = $('#factureDetails').data('facture-id');
        if (id) {
            $('#factureModal').modal('hide');
            chargerEdition(id);
        }
    });

    function submitPdfPost(id, targetSelf) {
        const params = new URLSearchParams(window.location.search);
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = baseUrl;
        if (targetSelf) form.target = '_self';
        const addField = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = name; input.value = value;
            form.appendChild(input);
        };
        params.forEach((value, key) => addField(key, value));
        addField('action', 'pdf');
        addField('id', id);
        document.body.appendChild(form);
        form.submit();
        form.remove();
    }

    $('#btnImprimer').click(function() {
        const id = $('#factureDetails').data('facture-id');
        if (id) submitPdfPost(id, true);
    });

    $('#btnPartager').click(async function() {
        const id = $('#factureDetails').data('facture-id');
        if (!id) return;
        const btn = $(this);
        const originalHtml = btn.html();
        const params = new URLSearchParams(window.location.search);
        params.set('action', 'pdf');
        params.set('id', id);
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i><span>Préparation...</span>');
        try {
            const resp = await fetch(baseUrl, { method: 'POST', body: params });
            if (!resp.ok) throw new Error('pdf_fetch_failed');
            const blob = await resp.blob();
            if (blob.type && blob.type.indexOf('pdf') === -1) throw new Error('not_a_pdf');
            const file = new File([blob], 'facture-' + id + '.pdf', { type: 'application/pdf' });
            if (window.isSecureContext && navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                await navigator.share({
                    files: [file],
                    title: 'Facture N°' + id,
                    text: 'Bonjour, voici votre facture N°' + id
                });
                btn.prop('disabled', false).html(originalHtml);
                return;
            }
        } catch (e) {
            if (e && e.name === 'AbortError') {
                btn.prop('disabled', false).html(originalHtml);
                return;
            }
        }
        btn.prop('disabled', false).html(originalHtml);
        window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent('Bonjour, voici votre facture N°' + id), '_blank');
    });

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
                    $('#editContent').html('<div class="alert alert-warning"><i class="bi bi-lock-fill me-2"></i>Cette facture est validée et payée. Elle ne peut plus être modifiée.</div>');
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
        const f = data.facture;
        let html = `<div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">MONTANT TTC</label>
                <input type="text" class="form-control form-control-lg" id="edit_montant_ttc" value="${Number(f.montant_ttc).toLocaleString('fr-FR')}" readonly style="background:#f8fafc;font-weight:700;color:#10b981;">
            </div>
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">AVANCE</label>
                <input type="number" class="form-control form-control-lg" id="edit_avance" value="${f.avance}" step="100" style="font-weight:600;">
            </div>
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">RESTE</label>
                <input type="number" class="form-control form-control-lg" id="edit_reste" value="${f.reste}" step="100" readonly style="background:#f8fafc;font-weight:700;color:#ef4444;">
            </div>
        </div>
        <h6 class="mb-3 fw-bold" style="color:#1e293b;display:flex;align-items:center;gap:8px;"><i class="bi bi-box-seam-fill text-warning"></i> Lignes de commande</h6>
        <div class="table-responsive">
            <table class="edit-table-chic" id="editLignesTable">
                <thead><tr><th>Produit</th><th class="text-center">Qté</th><th class="text-center">Produits/lot</th><th class="text-center">Nb lots à livrer</th><th class="text-end">Prix unit.</th><th class="text-end">Montant</th><th class="text-center">Action</th></tr></thead>
                <tbody>`;
        data.commandes.forEach(c => {
            const ppl = parseInt(c.produits_par_lot) || 1;
            const nbLots = Math.floor((parseInt(c.quantite_commande) || 0) / ppl);
            html += `<tr class="ligne-commande" data-id="${c.numero_commande}">
                <td class="fw-semibold">${c.titre_produit}</td>
                <td class="text-center"><input type="number" class="form-control form-control-sm qte" value="${c.quantite_commande}" min="0" style="width:80px;display:inline-block;text-align:center;"></td>
                <td class="text-center"><input type="number" class="form-control form-control-sm produits-par-lot" value="${ppl}" min="1" style="width:90px;display:inline-block;text-align:center;"></td>
                <td class="text-center nb-lots-ligne fw-bold">${nbLots}</td>
                <td class="text-end"><input type="number" class="form-control form-control-sm prix" value="${c.prix_commande}" min="0" style="width:120px;display:inline-block;text-align:right;"></td>
                <td class="text-end montant-ligne fw-bold">${Number(c.montant_commande).toLocaleString('fr-FR')}</td>
                <td class="text-center"><button class="btn-delete-chic supprimer-ligne" title="Supprimer"><i class="bi bi-trash3"></i></button></td>
            </tr>`;
        });
        html += `</tbody></table></div>
        <div class="mt-4 d-flex gap-2 justify-content-end">
            <button class="btn-action-chic annuler" id="btnAnnulerEdit"><i class="bi bi-x-lg"></i> Annuler</button>
            <button class="btn-action-chic enregistrer" id="btnEnregistrerEdit"><i class="bi bi-check-lg"></i> Enregistrer</button>
        </div>`;
        $('#editContent').html(html).data('facture-id', f.numero_facture);
        $('#edit_avance').on('input', function() {
            const total = parseFloat($('#edit_montant_ttc').val().replace(/\s/g, '')) || 0;
            const avance = parseFloat($(this).val()) || 0;
            $('#edit_reste').val(Math.max(0, total - avance));
        });
        $(document).on('change', '#editLignesTable .qte, #editLignesTable .prix', function() {
            const row = $(this).closest('tr');
            const montant = (parseFloat(row.find('.qte').val()) || 0) * (parseFloat(row.find('.prix').val()) || 0);
            row.find('.montant-ligne').text(montant.toLocaleString('fr-FR'));
        });
        $(document).on('change', '#editLignesTable .qte, #editLignesTable .produits-par-lot', function() {
            const row = $(this).closest('tr');
            const qte = parseFloat(row.find('.qte').val()) || 0;
            const ppl = Math.max(1, parseFloat(row.find('.produits-par-lot').val()) || 1);
            row.find('.nb-lots-ligne').text(Math.floor(qte / ppl));
        });
        $(document).on('click', '#editLignesTable .supprimer-ligne', function() {
            if (confirm('Supprimer cette ligne ?')) $(this).closest('tr').remove();
        });
        $('#btnAnnulerEdit').click(function() {
            $('#editSection').hide();
            $('#editContent').empty();
            location.reload();
        });
        $('#btnEnregistrerEdit').click(function() {
            const commandes = [];
            $('#editLignesTable tbody tr').each(function() {
                const row = $(this);
                const qte = parseFloat(row.find('.qte').val()) || 0;
                commandes.push({
                    id: row.data('id'),
                    quantite: qte,
                    produits_par_lot: Math.max(1, parseInt(row.find('.produits-par-lot').val()) || 1),
                    prix: parseFloat(row.find('.prix').val()) || 0,
                    supprimer: qte === 0
                });
            });
            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: {
                    action: 'update_facture',
                    facture_id: $('#editContent').data('facture-id'),
                    avance: parseFloat($('#edit_avance').val()) || 0,
                    reste: parseFloat($('#edit_reste').val()) || 0,
                    commandes: JSON.stringify(commandes)
                },
                success: function(resp) {
                    if (resp.success) {
                        showToast('Facture mise à jour');
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
        location.reload();
    });
});
</script>
</body>
</html>