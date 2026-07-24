<?php
// ============================================================
// PAGE 4 : Finances, Performance & Historique
// "Le Pilotage Strategique" — Bleu — FCFA
// ============================================================
require_once 'databases/database.php';
session_start();



function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}

// ── Référentiels ──
$boutiques = $pdo->query(
    "SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique='Actif' ORDER BY nom_boutique"
)->fetchAll(PDO::FETCH_ASSOC);

$vendeurs = $pdo->query(
    "SELECT id, nom_prenom, role FROM utilisateur WHERE etat='Actif' ORDER BY nom_prenom"
)->fetchAll(PDO::FETCH_ASSOC);

$periodes = [
    'today'    => "Aujourd'hui",
    'week'     => 'Cette semaine',
    'month'    => 'Ce mois',
    'quarter'  => 'Ce trimestre',
    'semester' => 'Ce semestre',
    'year'     => 'Cette annee',
    'custom'   => 'Personnalisee'
];

$periode    = $_GET['periode']    ?? 'month';
$boutique   = $_GET['boutique']   ?? '';
$vendeur    = $_GET['vendeur']    ?? '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin   = $_GET['date_fin']   ?? '';

function getDateCondition($periode, $date_debut, $date_fin, $alias = 'c', $col = 'date_commande')
{
    $today   = date('Y-m-d');
    $colFull = $alias . '.' . $col;
    switch ($periode) {
        case 'today':
            return "DATE($colFull)='$today'";
        case 'week':
            return "YEARWEEK($colFull,1)=YEARWEEK('$today',1)";
        case 'month':
            return "DATE_FORMAT($colFull,'%Y-%m')=DATE_FORMAT('$today','%Y-%m')";
        case 'quarter':
            return "QUARTER($colFull)=QUARTER('$today') AND YEAR($colFull)=YEAR('$today')";
        case 'semester':
            return "CEIL(MONTH($colFull)/6)=CEIL(MONTH('$today')/6) AND YEAR($colFull)=YEAR('$today')";
        case 'year':
            return "YEAR($colFull)=YEAR('$today')";
        case 'custom':
            if (!empty($date_debut) && !empty($date_fin)) {
                return "$colFull BETWEEN '$date_debut' AND '$date_fin'";
            }
            return "1=1";
        default:
            return "1=1";
    }
}

$dateCondition  = getDateCondition($periode, $date_debut, $date_fin);
$filtreBoutique = !empty($boutique) ? "AND c.boutique_id='$boutique'" : '';
$filtreVendeur  = !empty($vendeur)  ? "AND c.utilisateur_id='$vendeur'" : '';
$today = date('Y-m-d');

// ── KPI principaux ──
$stats = $pdo->query(
    "SELECT COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) as ca,"
        . "COALESCE(SUM(CAST(c.prix_achat AS DECIMAL(12,2))*c.quantite_commande),0) as cout,"
        . "COUNT(DISTINCT c.numero_commande) as nb "
        . "FROM commande c "
        . "WHERE c.statut_id IN ('ST002','012','Vente') "
        . "AND c.etat_commande='Valider' "
        . "AND DATE(c.date_commande)='$today' "
        . $filtreBoutique . " " . $filtreVendeur
)->fetch(PDO::FETCH_ASSOC);

$ca_jour     = $stats['ca']   ?? 0;
$cout_jour   = $stats['cout'] ?? 0;
$nb_ventes   = $stats['nb']   ?? 0;
$marge_brute = $ca_jour - $cout_jour;

$achats_jour = $pdo->query(
    "SELECT COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) "
        . "FROM commande c "
        . "WHERE c.statut_id IN ('ST001','011','Achat') "
        . "AND c.etat_commande='Valider' "
        . "AND DATE(c.date_commande)='$today'"
)->fetchColumn() ?: 0;

$depenses_jour = $pdo->query(
    "SELECT COALESCE(SUM(montant_depense),0) "
        . "FROM depense "
        . "WHERE DATE(date_depense)='$today' AND etat_depense='VALIDE'"
)->fetchColumn() ?: 0;

$benefice_net = $ca_jour - $cout_jour - $depenses_jour;

// ── KPI secondaires ──
$tva_coll = $pdo->query(
    "SELECT COALESCE(SUM(CAST(taxe AS DECIMAL(12,2))),0) "
        . "FROM facture WHERE type_facture='VENTE' AND DATE(date_facture)='$today'"
)->fetchColumn() ?: 0;

$tva_ded = $pdo->query(
    "SELECT COALESCE(SUM(CAST(taxe AS DECIMAL(12,2))),0) "
        . "FROM facture WHERE type_facture='ACHAT' AND DATE(date_facture)='$today'"
)->fetchColumn() ?: 0;

$solde_v = $pdo->query(
    "SELECT COALESCE(SUM(CAST(solde_virtuel AS DECIMAL(12,2))),0) "
        . "FROM caisse WHERE etat_caisse='Ouverte'"
)->fetchColumn() ?: 0;

$solde_p = $pdo->query(
    "SELECT COALESCE(SUM(CAST(solde_physique AS DECIMAL(12,2))),0) "
        . "FROM caisse WHERE etat_caisse='Ouverte'"
)->fetchColumn() ?: 0;

$ecart_c = $solde_p - $solde_v;

// ── Alertes ──
$creances = $pdo->query(
    "SELECT COALESCE(SUM(CAST(reste AS DECIMAL(12,2))),0) "
        . "FROM facture WHERE type_facture='VENTE' AND CAST(reste AS DECIMAL(12,2))>0"
)->fetchColumn() ?: 0;

$nb_creances = $pdo->query(
    "SELECT COUNT(*) FROM facture WHERE type_facture='VENTE' AND CAST(reste AS DECIMAL(12,2))>0"
)->fetchColumn() ?: 0;

$dettes = $pdo->query(
    "SELECT COALESCE(SUM(CAST(f.reste AS DECIMAL(12,2))),0) "
        . "FROM facture f JOIN contact ct ON f.contact_id=ct.code_contact "
        . "WHERE ct.type_contact='FOURNISSEUR' AND CAST(f.reste AS DECIMAL(12,2))>0"
)->fetchColumn() ?: 0;

$nb_dettes = $pdo->query(
    "SELECT COUNT(*) "
        . "FROM facture f JOIN contact ct ON f.contact_id=ct.code_contact "
        . "WHERE ct.type_contact='FOURNISSEUR' AND CAST(f.reste AS DECIMAL(12,2))>0"
)->fetchColumn() ?: 0;

// ── Charts : Evolution 12 mois ──
$evol = $pdo->query(
    "SELECT DATE_FORMAT(c.date_commande,'%Y-%m') as mois,"
        . "COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) as ca,"
        . "COALESCE(SUM(CAST(c.prix_achat AS DECIMAL(12,2))*c.quantite_commande),0) as cout "
        . "FROM commande c "
        . "WHERE c.statut_id IN ('ST002','012','Vente') "
        . "AND c.etat_commande='Valider' "
        . "AND c.date_commande>=DATE_SUB(CURDATE(),INTERVAL 12 MONTH) "
        . $filtreBoutique . " " . $filtreVendeur . " "
        . "GROUP BY mois ORDER BY mois ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$moisLabels = array_column($evol, 'mois');
$caData     = array_map(fn($r) => floatval($r['ca']), $evol);
$benData    = array_map(fn($r) => floatval($r['ca']) - floatval($r['cout']), $evol);

$depMoisRaw = $pdo->query(
    "SELECT DATE_FORMAT(date_depense,'%Y-%m') as mois,"
        . "COALESCE(SUM(montant_depense),0) as d "
        . "FROM depense "
        . "WHERE etat_depense='VALIDE' AND date_depense>=DATE_SUB(CURDATE(),INTERVAL 12 MONTH) "
        . "GROUP BY mois"
)->fetchAll(PDO::FETCH_ASSOC);
$depMois = array_column($depMoisRaw, 'd', 'mois');
$depData = [];
foreach ($moisLabels as $m) {
    $depData[] = floatval($depMois[$m] ?? 0);
}

// ── Charts : Performance vendeurs ──
$perfV = $pdo->query(
    "SELECT u.nom_prenom,"
        . "COUNT(DISTINCT c.numero_commande) as nb,"
        . "COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) as ca "
        . "FROM commande c JOIN utilisateur u ON c.utilisateur_id=u.id "
        . "WHERE c.statut_id IN ('ST002','012','Vente') "
        . "AND c.etat_commande='Valider' "
        . "AND $dateCondition " . $filtreBoutique . " "
        . "GROUP BY c.utilisateur_id ORDER BY ca DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Charts : Performance boutiques ──
$perfB = $pdo->query(
    "SELECT b.nom_boutique,"
        . "COUNT(DISTINCT c.numero_commande) as nb,"
        . "COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) as ca,"
        . "COALESCE(SUM(CAST(c.prix_achat AS DECIMAL(12,2))*c.quantite_commande),0) as cout "
        . "FROM commande c JOIN boutique b ON c.boutique_id=b.code_boutique "
        . "WHERE c.statut_id IN ('ST002','012','Vente') "
        . "AND c.etat_commande='Valider' "
        . "AND $dateCondition " . $filtreVendeur . " "
        . "GROUP BY c.boutique_id ORDER BY ca DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Tableau : Bilan financier journalier ──
$bilan = $pdo->query(
    "SELECT DATE(c.date_commande) as jour,"
        . "COUNT(DISTINCT c.numero_commande) as nb,"
        . "COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) as ca,"
        . "COALESCE(SUM(CAST(c.prix_achat AS DECIMAL(12,2))*c.quantite_commande),0) as cout "
        . "FROM commande c "
        . "WHERE c.statut_id IN ('ST002','012','Vente') "
        . "AND c.etat_commande='Valider' "
        . "AND $dateCondition " . $filtreBoutique . " " . $filtreVendeur . " "
        . "GROUP BY jour ORDER BY jour DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$depJour = [];
if (!empty($bilan)) {
    $pMin = $bilan[count($bilan) - 1]['jour'];
    $pMax = $bilan[0]['jour'];
    $depJourRaw = $pdo->query(
        "SELECT DATE(date_depense) as jour,"
            . "COALESCE(SUM(montant_depense),0) as t "
            . "FROM depense "
            . "WHERE etat_depense='VALIDE' AND date_depense BETWEEN '$pMin' AND '$pMax' "
            . "GROUP BY jour"
    )->fetchAll(PDO::FETCH_ASSOC);
    $depJour = array_column($depJourRaw, 't', 'jour');
}

// ── Tableau : Dépenses par boutique ──
$depBout = $pdo->query(
    "SELECT b.nom_boutique,"
        . "COUNT(d.code_depense) as nb,"
        . "COALESCE(SUM(d.montant_depense),0) as t "
        . "FROM boutique b "
        . "LEFT JOIN depense d ON b.code_boutique=d.boutique_id AND d.etat_depense='VALIDE' "
        . "WHERE b.etat_boutique='Actif' "
        . "GROUP BY b.code_boutique ORDER BY t DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Tableau : Rapport vendeurs ──
$rappU = $pdo->query(
    "SELECT u.id, u.nom_prenom, u.role,"
        . "COUNT(DISTINCT c.numero_commande) as nb,"
        . "COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) as ca,"
        . "COALESCE(SUM(CAST(c.prix_achat AS DECIMAL(12,2))*c.quantite_commande),0) as cout "
        . "FROM utilisateur u "
        . "LEFT JOIN commande c ON u.id=c.utilisateur_id "
        . "AND c.statut_id IN ('ST002','012','Vente') "
        . "AND c.etat_commande='Valider' "
        . "AND $dateCondition " . $filtreBoutique . " "
        . "WHERE u.etat='Actif' "
        . "GROUP BY u.id ORDER BY ca DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Tableau : Rapport boutiques ──
$rappB = $pdo->query(
    "SELECT b.code_boutique, b.nom_boutique,"
        . "COUNT(DISTINCT c.numero_commande) as nb,"
        . "COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) as ca,"
        . "COALESCE(SUM(CAST(c.prix_achat AS DECIMAL(12,2))*c.quantite_commande),0) as cout "
        . "FROM boutique b "
        . "LEFT JOIN commande c ON b.code_boutique=c.boutique_id "
        . "AND c.statut_id IN ('ST002','012','Vente') "
        . "AND c.etat_commande='Valider' "
        . "AND $dateCondition " . $filtreVendeur . " "
        . "WHERE b.etat_boutique='Actif' "
        . "GROUP BY b.code_boutique ORDER BY ca DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Tableau : Journal des mouvements ──
$journal = $pdo->query(
    "SELECT c.numero_commande as ref,"
        . "c.date_commande as dop,"
        . "c.heure_commande as hop,"
        . "CASE "
        . "WHEN c.statut_id IN ('ST002','012','Vente') THEN 'VENTE' "
        . "WHEN c.statut_id IN ('ST001','011','Achat') THEN 'ACHAT' "
        . "WHEN c.statut_id='008' THEN 'TRANSF. S.' "
        . "WHEN c.statut_id='009' THEN 'TRANSF. E.' "
        . "WHEN c.statut_id='010' THEN 'RETOUR' "
        . "ELSE 'AUTRE' END as type,"
        . "p.titre_produit,"
        . "c.quantite_commande as qte,"
        . "CAST(c.montant_commande AS DECIMAL(12,2)) as mt,"
        . "u.nom_prenom as usr,"
        . "b.nom_boutique as bout,"
        . "c.etat_commande as etat "
        . "FROM commande c "
        . "LEFT JOIN produit p ON c.produit_id=p.code_produit "
        . "LEFT JOIN utilisateur u ON c.utilisateur_id=u.id "
        . "LEFT JOIN boutique b ON c.boutique_id=b.code_boutique "
        . "WHERE $dateCondition " . $filtreBoutique . " " . $filtreVendeur . " "
        . "ORDER BY c.date_commande DESC, c.heure_commande DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

// ── AJAX ──
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $p    = $_POST['periode']    ?? 'month';
    $b    = $_POST['boutique']   ?? '';
    $v    = $_POST['vendeur']    ?? '';
    $dd   = $_POST['date_debut'] ?? '';
    $df   = $_POST['date_fin']   ?? '';
    $type = $_POST['type']       ?? 'financier';

    $dc = getDateCondition($p, $dd, $df);
    $fb = !empty($b) ? "AND c.boutique_id='$b'" : '';
    $fv = !empty($v) ? "AND c.utilisateur_id='$v'" : '';
    $resp = [];

    switch ($type) {

        case 'financier':
            $d = $pdo->query(
                "SELECT DATE(c.date_commande) as jour,"
                    . "COUNT(DISTINCT c.numero_commande) as nb,"
                    . "COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) as ca,"
                    . "COALESCE(SUM(CAST(c.prix_achat AS DECIMAL(12,2))*c.quantite_commande),0) as cout "
                    . "FROM commande c "
                    . "WHERE c.statut_id IN ('ST002','012','Vente') "
                    . "AND c.etat_commande='Valider' AND $dc $fb $fv "
                    . "GROUP BY jour ORDER BY jour DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $h = '';
            if (empty($d)) {
                $h = '<tr><td colspan="7" class="empty-cell">Aucune donnee</td></tr>';
            } else {
                foreach ($d as $r) {
                    $m = floatval($r['ca']) - floatval($r['cout']);
                    $h .= "<tr>"
                        . "<td><strong style='color:var(--primary)'>{$r['jour']}</strong></td>"
                        . "<td>{$r['nb']}</td>"
                        . "<td>" . fmt($r['ca']) . " F</td>"
                        . "<td>" . fmt($r['cout']) . " F</td>"
                        . "<td><span class='badge badge-blue'>" . fmt($m) . " F</span></td>"
                        . "<td>" . fmt(floatval($r['ca']) * 0.2 / 1.2) . " F</td>"
                        . "<td><span class='badge badge-" . ($m >= 0 ? 'success' : 'danger') . "'>" . fmt($m) . " F</span></td>"
                        . "</tr>";
                }
            }
            $resp['table'] = $h;
            $resp['total'] = count($d);
            break;

        case 'utilisateurs':
            $d = $pdo->query(
                "SELECT u.nom_prenom, u.role,"
                    . "COUNT(DISTINCT c.numero_commande) as nb,"
                    . "COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) as ca,"
                    . "COALESCE(SUM(CAST(c.prix_achat AS DECIMAL(12,2))*c.quantite_commande),0) as cout "
                    . "FROM utilisateur u "
                    . "LEFT JOIN commande c ON u.id=c.utilisateur_id "
                    . "AND c.statut_id IN ('ST002','012','Vente') "
                    . "AND c.etat_commande='Valider' AND $dc $fb "
                    . "WHERE u.etat='Actif' "
                    . "GROUP BY u.id ORDER BY ca DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $h = '';
            if (empty($d)) {
                $h = '<tr><td colspan="5" class="empty-cell">Aucune donnee</td></tr>';
            } else {
                foreach ($d as $r) {
                    $m = floatval($r['ca']) - floatval($r['cout']);
                    $h .= "<tr>"
                        . "<td><strong style='color:var(--primary)'>" . e($r['nom_prenom']) . "</strong></td>"
                        . "<td><span class='badge badge-info'>" . e($r['role'] ?? '—') . "</span></td>"
                        . "<td>{$r['nb']}</td>"
                        . "<td><strong>" . fmt($r['ca']) . " F</strong></td>"
                        . "<td><span class='badge badge-" . ($m >= 0 ? 'success' : 'danger') . "'>" . fmt($m) . " F</span></td>"
                        . "</tr>";
                }
            }
            $resp['table'] = $h;
            break;

        case 'boutiques':
            $d = $pdo->query(
                "SELECT b.nom_boutique,"
                    . "COUNT(DISTINCT c.numero_commande) as nb,"
                    . "COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) as ca,"
                    . "COALESCE(SUM(CAST(c.prix_achat AS DECIMAL(12,2))*c.quantite_commande),0) as cout "
                    . "FROM boutique b "
                    . "LEFT JOIN commande c ON b.code_boutique=c.boutique_id "
                    . "AND c.statut_id IN ('ST002','012','Vente') "
                    . "AND c.etat_commande='Valider' AND $dc $fv "
                    . "WHERE b.etat_boutique='Actif' "
                    . "GROUP BY b.code_boutique ORDER BY ca DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $h = '';
            if (empty($d)) {
                $h = '<tr><td colspan="5" class="empty-cell">Aucune donnee</td></tr>';
            } else {
                foreach ($d as $r) {
                    $m = floatval($r['ca']) - floatval($r['cout']);
                    $h .= "<tr>"
                        . "<td><strong style='color:var(--primary)'>" . e($r['nom_boutique']) . "</strong></td>"
                        . "<td>{$r['nb']}</td>"
                        . "<td>" . fmt($r['ca']) . " F</td>"
                        . "<td>" . fmt($r['cout']) . " F</td>"
                        . "<td><span class='badge badge-" . ($m >= 0 ? 'success' : 'danger') . "'>" . fmt($m) . " F</span></td>"
                        . "</tr>";
                }
            }
            $resp['table'] = $h;
            break;

        case 'journal':
            $d = $pdo->query(
                "SELECT c.numero_commande as ref,"
                    . "c.date_commande as dop,"
                    . "c.heure_commande as hop,"
                    . "CASE "
                    . "WHEN c.statut_id IN ('ST002','012','Vente') THEN 'VENTE' "
                    . "WHEN c.statut_id IN ('ST001','011','Achat') THEN 'ACHAT' "
                    . "WHEN c.statut_id='008' THEN 'TRANSF. S.' "
                    . "WHEN c.statut_id='009' THEN 'TRANSF. E.' "
                    . "WHEN c.statut_id='010' THEN 'RETOUR' "
                    . "ELSE 'AUTRE' END as type,"
                    . "p.titre_produit,"
                    . "c.quantite_commande as qte,"
                    . "CAST(c.montant_commande AS DECIMAL(12,2)) as mt,"
                    . "u.nom_prenom as usr,"
                    . "b.nom_boutique as bout,"
                    . "c.etat_commande as etat "
                    . "FROM commande c "
                    . "LEFT JOIN produit p ON c.produit_id=p.code_produit "
                    . "LEFT JOIN utilisateur u ON c.utilisateur_id=u.id "
                    . "LEFT JOIN boutique b ON c.boutique_id=b.code_boutique "
                    . "WHERE $dc $fb $fv "
                    . "ORDER BY c.date_commande DESC LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC);
            $h = '';
            if (empty($d)) {
                $h = '<tr><td colspan="9" class="empty-cell">Aucun mouvement</td></tr>';
            } else {
                $tb = [
                    'VENTE'      => 'badge-success',
                    'ACHAT'      => 'badge-blue',
                    'TRANSF. S.' => 'badge-warning',
                    'TRANSF. E.' => 'badge-info',
                    'RETOUR'     => 'badge-danger'
                ];
                foreach ($d as $r) {
                    $bc = $tb[$r['type']] ?? 'badge-secondary';
                    $h .= "<tr>"
                        . "<td><strong style='color:var(--primary)'>" . e($r['ref']) . "</strong></td>"
                        . "<td>" . e($r['dop']) . " " . substr($r['hop'] ?? '', 0, 5) . "</td>"
                        . "<td><span class='badge $bc'>" . e($r['type']) . "</span></td>"
                        . "<td>" . e($r['titre_produit'] ?? '—') . "</td>"
                        . "<td>" . $r['qte'] . "</td>"
                        . "<td>" . fmt($r['mt']) . " F</td>"
                        . "<td>" . e($r['usr'] ?? '—') . "</td>"
                        . "<td>" . e($r['bout'] ?? '—') . "</td>"
                        . "<td><span class='badge badge-secondary'>" . e($r['etat']) . "</span></td>"
                        . "</tr>";
                }
            }
            $resp['table'] = $h;
            $resp['total'] = count($d);
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($resp);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilotage Strategique</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1e40af;
            --primary-dark: #1e3a8a;
            --primary-deeper: #172554;
            --primary-mid: #2563eb;
            --primary-light: #dbeafe;
            --primary-glow: rgba(30, 64, 175, 0.22);
            --accent: #d97706;
            --accent-glow: rgba(217, 119, 6, 0.18);
            --success: #059669;
            --success-light: #d1fae5;
            --danger: #dc2626;
            --danger-light: #fee2e2;
            --warning: #ea580c;
            --warning-light: #ffedd5;
            --info: #0284c7;
            --info-light: #e0f2fe;
            --purple: #7c3aed;
            --purple-light: #ede9fe;
            --dark: #0f172a;
            --text: #0f172a;
            --text-mid: #475569;
            --text-soft: #94a3b8;
            --body: #f1f5f9;
            --card: #fff;
            --card-alt: #f8fafc;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.04);
            --shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            --shadow-lg: 0 12px 32px rgba(15, 23, 42, 0.12);
            --shadow-xl: 0 20px 48px rgba(15, 23, 42, 0.16);
            --radius: 14px;
            --radius-sm: 8px;
            --radius-xs: 6px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background: var(--body);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 50% at 15% 0%, var(--primary-glow), transparent 60%),
                radial-gradient(ellipse 50% 35% at 85% 90%, var(--accent-glow), transparent 50%),
                linear-gradient(180deg, #f1f5f9, #e2e8f0);
            z-index: -1;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%231e40af' fill-opacity='0.025'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6z'/%3E%3C/g%3E%3C/svg%3E");
            z-index: -1;
            pointer-events: none;
            opacity: 0.6;
        }

        .page-wrapper {
            max-width: 1360px;
            margin: 0 auto;
            padding: 20px 28px 60px;
        }

        /* HEADER */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .page-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-header-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary-mid), var(--primary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.2rem;
            box-shadow: var(--shadow), 0 0 24px var(--primary-glow);
        }

        .page-header-text h1 {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.4px;
        }

        .page-header-text p {
            font-size: 0.78rem;
            color: var(--text-soft);
            margin-top: 2px;
        }

        .page-header-right {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .hdr-badge {
            padding: 8px 16px;
            border-radius: 24px;
            font-size: 0.78rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .hdr-badge.blue {
            background: var(--primary-light);
            border: 1px solid rgba(37, 99, 235, 0.2);
            color: var(--primary);
        }

        .hdr-badge.green {
            background: var(--success-light);
            border: 1px solid rgba(5, 150, 105, 0.2);
            color: var(--success);
        }

        .hdr-badge.red {
            background: var(--danger-light);
            border: 1px solid rgba(220, 38, 38, 0.2);
            color: var(--danger);
        }

        /* ZONE TITLES */
        .zone-title {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-soft);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .zone-title i {
            color: var(--primary-mid);
            font-size: 0.65rem;
        }

        /* PRIMARY CARDS */
        .primary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        .primary-card {
            background: var(--card);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .primary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            border-radius: 2px;
            transform: scaleY(0);
            transition: transform 0.3s;
        }

        .primary-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .primary-card:hover::before {
            transform: scaleY(1);
        }

        .primary-card.ca::before {
            background: linear-gradient(180deg, var(--primary-mid), var(--primary));
        }

        .primary-card.marge::before {
            background: linear-gradient(180deg, var(--success), #047857);
        }

        .primary-card.benef::before {
            background: linear-gradient(180deg, <?= $benefice_net >= 0 ? 'var(--success)' : 'var(--danger)' ?>, <?= $benefice_net >= 0 ? '#047857' : '#be123c' ?>);
        }

        .primary-card.ventes::before {
            background: linear-gradient(180deg, var(--accent), #b45309);
        }

        .pc-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: #fff;
            margin-bottom: 14px;
        }

        .primary-card.ca .pc-icon {
            background: linear-gradient(135deg, var(--primary-mid), var(--primary));
        }

        .primary-card.marge .pc-icon {
            background: linear-gradient(135deg, var(--success), #047857);
        }

        .primary-card.benef .pc-icon {
            background: linear-gradient(135deg, <?= $benefice_net >= 0 ? 'var(--success)' : 'var(--danger)' ?>, <?= $benefice_net >= 0 ? '#047857' : '#be123c' ?>);
        }

        .primary-card.ventes .pc-icon {
            background: linear-gradient(135deg, var(--accent), #b45309);
        }

        .pc-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-soft);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .pc-value {
            font-size: 1.65rem;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -1px;
        }

        .primary-card.ca .pc-value {
            color: var(--primary-mid);
        }

        .primary-card.marge .pc-value {
            color: var(--success);
        }

        .primary-card.benef .pc-value {
            color: <?= $benefice_net >= 0 ? 'var(--success)' : 'var(--danger)' ?>;
        }

        .primary-card.ventes .pc-value {
            color: var(--accent);
        }

        .pc-sub {
            font-size: 0.78rem;
            color: var(--text-soft);
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* SECONDARY CARDS */
        .secondary-row {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            margin-bottom: 24px;
        }

        .secondary-card {
            background: var(--card);
            border-radius: 10px;
            padding: 14px 14px 12px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.25s;
        }

        .secondary-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary-mid);
        }

        .sc-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            color: #fff;
            flex-shrink: 0;
        }

        .sc-icon.blue {
            background: linear-gradient(135deg, var(--primary-mid), var(--primary));
        }

        .sc-icon.teal {
            background: linear-gradient(135deg, #0d9488, #0f766e);
        }

        .sc-icon.rose {
            background: linear-gradient(135deg, #e11d48, #be123c);
        }

        .sc-icon.sky {
            background: linear-gradient(135deg, #0284c7, #0369a1);
        }

        .sc-icon.emerald {
            background: linear-gradient(135deg, var(--success), #047857);
        }

        .sc-icon.violet {
            background: linear-gradient(135deg, var(--purple), #6d28d9);
        }

        .sc-body {
            flex: 1;
            min-width: 0;
        }

        .sc-value {
            font-size: 1rem;
            font-weight: 800;
            color: var(--dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sc-label {
            font-size: 0.68rem;
            color: var(--text-soft);
            font-weight: 500;
            margin-top: 1px;
        }

        /* ALERTS */
        .alerts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 24px;
        }

        .alert-card {
            padding: 16px 20px;
            border-radius: 12px;
            border: 1px solid;
            transition: all 0.3s;
        }

        .alert-card:hover {
            transform: translateY(-2px);
        }

        .alert-card.creances {
            background: linear-gradient(135deg, #fff7ed, #fffbeb);
            border-color: #fed7aa;
        }

        .alert-card.dettes {
            background: linear-gradient(135deg, #fef2f2, #fff1f2);
            border-color: #fecaca;
        }

        .ac-row {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .ac-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: #fff;
        }

        .alert-card.creances .ac-icon {
            background: linear-gradient(135deg, var(--accent), #b45309);
        }

        .alert-card.dettes .ac-icon {
            background: linear-gradient(135deg, var(--danger), #be123c);
        }

        .ac-body {
            flex: 1;
        }

        .ac-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-soft);
        }

        .ac-value {
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .alert-card.creances .ac-value {
            color: var(--accent);
        }

        .alert-card.dettes .ac-value {
            color: var(--danger);
        }

        .ac-sub {
            font-size: 0.72rem;
            color: var(--text-soft);
            margin-top: 4px;
        }

        /* FILTERS */
        .filters-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            padding: 14px 16px;
            background: var(--card-alt);
            border-radius: 10px;
            border: 1px solid var(--border);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 120px;
        }

        .filter-group label {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--text-soft);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .filter-group label i {
            color: var(--primary-mid);
            font-size: 0.6rem;
        }

        .filter-group select,
        .filter-group input[type="date"] {
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-xs);
            font-family: inherit;
            font-size: 0.82rem;
            background: #fff;
            color: var(--text);
            transition: all 0.25s;
            appearance: none;
        }

        .filter-group select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 28px;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary-mid);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .filter-actions {
            display: flex;
            gap: 6px;
            align-items: flex-end;
        }

        .btn-filter {
            padding: 7px 14px;
            background: var(--primary-mid);
            color: #fff;
            border: none;
            border-radius: var(--radius-xs);
            cursor: pointer;
            font-family: inherit;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.25s;
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .btn-filter:hover {
            background: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 3px 10px var(--primary-glow);
        }

        .btn-filter.secondary {
            background: #fff;
            color: var(--text-mid);
            border: 1px solid var(--border);
        }

        .btn-filter.secondary:hover {
            background: var(--card-alt);
            color: var(--dark);
        }

        /* CHARTS */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .charts-grid.three {
            grid-template-columns: repeat(3, 1fr);
        }

        .chart-card {
            background: var(--card-alt);
            border-radius: 10px;
            padding: 16px 18px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .chart-card:hover {
            box-shadow: var(--shadow);
            border-color: var(--primary-mid);
        }

        .chart-card h4 {
            font-size: 0.88rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .chart-card h4 i {
            color: var(--primary-mid);
            font-size: 0.82rem;
        }

        /* TABS */
        .tabs {
            display: flex;
            gap: 4px;
            background: var(--card);
            padding: 8px 10px;
            border-radius: var(--radius) var(--radius) 0 0;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            border-bottom: none;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 9px 18px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-soft);
            border-radius: var(--radius-xs);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tab-btn:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .tab-btn.active {
            background: var(--primary-mid);
            color: #fff;
            box-shadow: 0 3px 10px var(--primary-glow);
        }

        .tab-content {
            display: none;
            padding: 24px 28px 28px;
            background: var(--card);
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            border-top: none;
            margin-bottom: 28px;
            animation: fadeIn 0.3s;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* SECTION TITLE */
        .section-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark);
            margin: 20px 0 14px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary-mid);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .section-title:first-child {
            margin-top: 0;
        }

        .section-title-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title-left i {
            color: var(--primary-mid);
            font-size: 0.85rem;
        }

        .section-actions {
            display: flex;
            gap: 6px;
        }

        .btn-action {
            padding: 6px 12px;
            background: var(--card-alt);
            color: var(--text-mid);
            border: 1px solid var(--border);
            border-radius: var(--radius-xs);
            cursor: pointer;
            font-family: inherit;
            font-size: 0.78rem;
            font-weight: 600;
            transition: all 0.25s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .btn-action:hover {
            background: var(--primary-mid);
            color: #fff;
            border-color: var(--primary-mid);
            box-shadow: 0 2px 8px var(--primary-glow);
        }

        /* TABLES */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid var(--border);
            margin-bottom: 20px;
            background: #fff;
        }

        .table-header {
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
            background: var(--card-alt);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h5 {
            font-size: 0.82rem;
            font-weight: 700;
            margin: 0;
            color: var(--dark);
        }

        .table-header .count {
            font-size: 0.72rem;
            color: var(--text-soft);
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        thead {
            background: linear-gradient(135deg, var(--primary-deeper), var(--primary-dark));
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        th {
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            border-bottom: none;
        }

        td {
            padding: 9px 14px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
            vertical-align: middle;
        }

        tbody tr {
            transition: background 0.15s;
        }

        tbody tr:hover {
            background: var(--primary-light);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tfoot td {
            background: var(--card-alt);
            font-weight: 700;
            color: var(--dark);
            border-top: 2px solid var(--primary-mid);
            border-bottom: none;
        }

        /* BADGES */
        .badge {
            padding: 3px 9px;
            border-radius: 16px;
            font-size: 0.68rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-success {
            background: var(--success-light);
            color: #065f46;
        }

        .badge-warning {
            background: var(--warning-light);
            color: #9a3412;
        }

        .badge-danger {
            background: var(--danger-light);
            color: #991b1b;
        }

        .badge-info {
            background: var(--info-light);
            color: #075985;
        }

        .badge-purple {
            background: var(--purple-light);
            color: #5b21b6;
        }

        .badge-secondary {
            background: #e2e8f0;
            color: #475569;
        }

        .badge-blue {
            background: var(--primary-light);
            color: var(--primary);
        }

        .badge-amber {
            background: #fef3c7;
            color: #92400e;
        }

        /* TOAST */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            background: var(--card);
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 280px;
            animation: toastIn 0.3s;
            border-left: 4px solid;
        }

        .toast.success {
            border-color: var(--success);
        }

        .toast.error {
            border-color: var(--danger);
        }

        .toast.info {
            border-color: var(--primary-mid);
        }

        .toast-icon {
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .toast.success .toast-icon {
            color: var(--success);
        }

        .toast.info .toast-icon {
            color: var(--primary-mid);
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-weight: 700;
            font-size: 0.82rem;
        }

        .toast-message {
            font-size: 0.75rem;
            color: var(--text-soft);
        }

        .toast-close {
            background: none;
            border: none;
            color: var(--text-soft);
            cursor: pointer;
            font-size: 1rem;
        }

        @keyframes toastIn {
            from {
                transform: translateX(120%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes toastOut {
            from {
                opacity: 1;
            }

            to {
                transform: translateX(120%);
                opacity: 0;
            }
        }

        .toast.hiding {
            animation: toastOut 0.3s forwards;
        }

        .empty-cell {
            text-align: center;
            padding: 30px 16px;
            color: var(--text-soft);
            font-size: 0.85rem;
        }

        /* RESPONSIVE */
        @media(max-width:1100px) {
            .primary-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .secondary-row {
                grid-template-columns: repeat(3, 1fr);
            }

            .charts-grid.three {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width:768px) {
            .page-wrapper {
                padding: 12px 10px 40px;
            }

            .primary-row {
                grid-template-columns: 1fr 1fr;
            }

            .secondary-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .tab-content {
                padding: 16px 12px 20px;
            }

            .charts-grid,
            .charts-grid.three,
            .alerts-row {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                gap: 10px;
            }
        }

        @media(max-width:480px) {
            .primary-row {
                grid-template-columns: 1fr;
            }

            .secondary-row {
                grid-template-columns: 1fr;
            }
        }

        @media print {

            .tabs,
            .filters-bar,
            .section-actions,
            .btn-action,
            .toast-container,
            .page-header-right {
                display: none !important;
            }

            .tab-content {
                display: block !important;
                margin: 0;
                box-shadow: none;
                border: none;
                padding: 0;
            }

            body {
                background: #fff;
            }

            body::before,
            body::after {
                display: none;
            }

            .page-wrapper {
                padding: 0;
            }
        }
    </style>
</head>

<body>
    <div class="toast-container" id="toastContainer"></div>
    <div class="page-wrapper">

        <!-- ═══ HEADER ═══ -->
        <header class="page-header">
            <div class="page-header-left">
                <div class="page-header-icon"><i class="fas fa-chart-line"></i></div>
                <div class="page-header-text">
                    <h1>Pilotage Strategique</h1>
                    <p>Finances & Performance — <?= e($periodes[$periode] ?? 'Personnalisee') ?></p>
                </div>
            </div>
            <div class="page-header-right">
                <div class="hdr-badge blue"><i class="fas fa-calendar"></i> <?= e($periodes[$periode] ?? 'Personnalisee') ?></div>
                <div class="hdr-badge <?= $benefice_net >= 0 ? 'green' : 'red' ?>">
                    <i class="fas fa-<?= $benefice_net >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                    <?= fmt($benefice_net) ?> F
                </div>
            </div>
        </header>

        <!-- ═══ ZONE 1 : 4 KPI PRIMAIRES ═══ -->
        <div class="zone-title"><i class="fas fa-star"></i> Indicateurs Principaux</div>
        <section class="primary-row">
            <div class="primary-card ca">
                <div class="pc-icon"><i class="fas fa-coins"></i></div>
                <div class="pc-label">Chiffre d'Affaires</div>
                <div class="pc-value"><?= fmt($ca_jour) ?> F</div>
                <div class="pc-sub"><i class="fas fa-receipt"></i> <?= $nb_ventes ?> ventes aujourd'hui</div>
            </div>
            <div class="primary-card marge">
                <div class="pc-icon"><i class="fas fa-chart-line"></i></div>
                <div class="pc-label">Marge Brute</div>
                <div class="pc-value"><?= fmt($marge_brute) ?> F</div>
                <div class="pc-sub"><i class="fas fa-percentage"></i> CA - Cout achat marchandises</div>
            </div>
            <div class="primary-card benef">
                <div class="pc-icon"><i class="fas fa-<?= $benefice_net >= 0 ? 'check-circle' : 'times-circle' ?>"></i></div>
                <div class="pc-label">Benefice Net</div>
                <div class="pc-value"><?= fmt($benefice_net) ?> F</div>
                <div class="pc-sub"><i class="fas fa-calculator"></i> <?= $benefice_net >= 0 ? 'Marge - Depenses' : 'Deficit' ?></div>
            </div>
            <div class="primary-card ventes">
                <div class="pc-icon"><i class="fas fa-shopping-basket"></i></div>
                <div class="pc-label">Ventes du Jour</div>
                <div class="pc-value"><?= $nb_ventes ?></div>
                <div class="pc-sub"><i class="fas fa-cash-register"></i> Commandes validees</div>
            </div>
        </section>

        <!-- ═══ ZONE 2 : 6 KPI SECONDAIRES ═══ -->
        <div class="zone-title"><i class="fas fa-layer-group"></i> Complements & Fiscalite</div>
        <section class="secondary-row">
            <div class="secondary-card">
                <div class="sc-icon blue"><i class="fas fa-percent"></i></div>
                <div class="sc-body">
                    <div class="sc-value"><?= fmt($tva_coll - $tva_ded) ?> F</div>
                    <div class="sc-label">TVA Net (coll.-deduc.)</div>
                </div>
            </div>
            <div class="secondary-card">
                <div class="sc-icon teal"><i class="fas fa-receipt"></i></div>
                <div class="sc-body">
                    <div class="sc-value"><?= fmt($achats_jour) ?> F</div>
                    <div class="sc-label">Achats du jour</div>
                </div>
            </div>
            <div class="secondary-card">
                <div class="sc-icon rose"><i class="fas fa-money-bill-wave"></i></div>
                <div class="sc-body">
                    <div class="sc-value"><?= fmt($depenses_jour) ?> F</div>
                    <div class="sc-label">Depenses</div>
                </div>
            </div>
            <div class="secondary-card">
                <div class="sc-icon sky"><i class="fas fa-university"></i></div>
                <div class="sc-body">
                    <div class="sc-value"><?= fmt($solde_v) ?> F</div>
                    <div class="sc-label">Caisse virtuelle</div>
                </div>
            </div>
            <div class="secondary-card">
                <div class="sc-icon emerald"><i class="fas fa-wallet"></i></div>
                <div class="sc-body">
                    <div class="sc-value"><?= fmt($solde_p) ?> F</div>
                    <div class="sc-label">Caisse physique</div>
                </div>
            </div>
            <div class="secondary-card">
                <div class="sc-icon <?= $ecart_c >= 0 ? 'violet' : 'rose' ?>">
                    <i class="fas fa-<?= $ecart_c >= 0 ? 'balance-scale' : 'exclamation-triangle' ?>"></i>
                </div>
                <div class="sc-body">
                    <div class="sc-value"><?= fmt(abs($ecart_c)) ?> F</div>
                    <div class="sc-label">Ecart caisse (P-V)</div>
                </div>
            </div>
        </section>

        <!-- ═══ ZONE 3 : ALERTES ═══ -->
        <div class="zone-title"><i class="fas fa-bell"></i> Alertes Financieres</div>
        <section class="alerts-row">
            <div class="alert-card creances">
                <div class="ac-row">
                    <div class="ac-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div class="ac-body">
                        <div class="ac-label">Creances Clients</div>
                        <div class="ac-value"><?= fmt($creances) ?> F</div>
                        <div class="ac-sub"><i class="fas fa-users"></i> <?= $nb_creances ?> factures impayees</div>
                    </div>
                </div>
            </div>
            <div class="alert-card dettes">
                <div class="ac-row">
                    <div class="ac-icon"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="ac-body">
                        <div class="ac-label">Dettes Fournisseurs</div>
                        <div class="ac-value"><?= fmt($dettes) ?> F</div>
                        <div class="ac-sub"><i class="fas fa-truck"></i> <?= $nb_dettes ?> factures a payer</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ FILTRES ═══ -->
        <form class="filters-bar" id="filterForm" method="GET">
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> Periode</label>
                <select name="periode" id="filterPeriode">
                    <?php foreach ($periodes as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $periode === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-store"></i> Boutique</label>
                <select name="boutique" id="filterBoutique">
                    <option value="">Toutes</option>
                    <?php foreach ($boutiques as $bt): ?>
                        <option value="<?= e($bt['code_boutique']) ?>" <?= $boutique === $bt['code_boutique'] ? 'selected' : '' ?>><?= e($bt['nom_boutique']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-user"></i> Vendeur</label>
                <select name="vendeur" id="filterVendeur">
                    <option value="">Tous</option>
                    <?php foreach ($vendeurs as $vd): ?>
                        <option value="<?= e($vd['id']) ?>" <?= $vendeur === $vd['id'] ? 'selected' : '' ?>><?= e($vd['nom_prenom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group" id="customDates" style="<?= $periode === 'custom' ? '' : 'display:none' ?>">
                <label><i class="fas fa-calendar-day"></i> Debut</label>
                <input type="date" name="date_debut" id="filterDateDebut" value="<?= e($date_debut) ?>">
            </div>
            <div class="filter-group" id="customDateFin" style="<?= $periode === 'custom' ? '' : 'display:none' ?>">
                <label><i class="fas fa-calendar-check"></i> Fin</label>
                <input type="date" name="date_fin" id="filterDateFin" value="<?= e($date_fin) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filtrer</button>
                <a href="?" class="btn-filter secondary"><i class="fas fa-redo"></i> Reset</a>
            </div>
        </form>

        <!-- ═══ CHARTS ═══ -->
        <div class="zone-title"><i class="fas fa-chart-bar"></i> Evolution & Performance</div>
        <section class="charts-grid">
            <div class="chart-card">
                <h4><i class="fas fa-chart-area"></i> Evolution CA / Benefice (12 mois)</h4>
                <canvas id="chartEvol" height="220"></canvas>
            </div>
            <div class="chart-card">
                <h4><i class="fas fa-chart-bar"></i> Depenses Mensuelles</h4>
                <canvas id="chartDep" height="220"></canvas>
            </div>
        </section>
        <section class="charts-grid three">
            <div class="chart-card">
                <h4><i class="fas fa-trophy"></i> Top Vendeurs</h4>
                <canvas id="chartPerfV" height="200"></canvas>
            </div>
            <div class="chart-card">
                <h4><i class="fas fa-store-alt"></i> CA par Boutique</h4>
                <canvas id="chartPerfB" height="200"></canvas>
            </div>
            <div class="chart-card">
                <h4><i class="fas fa-money-bill"></i> Depenses par Boutique</h4>
                <canvas id="chartDepBout" height="200"></canvas>
            </div>
        </section>

        <!-- ═══ TABS ═══ -->
        <div class="tabs">
            <button class="tab-btn active" data-tab="tabFinancier"><i class="fas fa-coins"></i> Bilan Financier</button>
            <button class="tab-btn" data-tab="tabUtilisateurs"><i class="fas fa-users"></i> Rapport Vendeurs</button>
            <button class="tab-btn" data-tab="tabBoutiques"><i class="fas fa-store"></i> Rapport Boutiques</button>
            <button class="tab-btn" data-tab="tabJournal"><i class="fas fa-scroll"></i> Journal Mouvements</button>
        </div>

        <!-- ── TAB : BILAN FINANCIER ── -->
        <div class="tab-content active" id="tabFinancier">
            <div class="section-title">
                <div class="section-title-left"><i class="fas fa-table"></i> Detail Journalier</div>
                <div class="section-actions">
                    <button class="btn-action" onclick="exportTable('bilanFinancier')"><i class="fas fa-download"></i> Export</button>
                </div>
            </div>
            <div class="table-wrapper">
                <div class="table-header">
                    <h5>Bilan Financier</h5>
                    <span class="count"><?= count($bilan) ?> jours</span>
                </div>
                <table id="bilanFinancier">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Nb Ventes</th>
                            <th>CA</th>
                            <th>Cout</th>
                            <th>Marge Brute</th>
                            <th>TVA Est.</th>
                            <th>Benefice Net</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyFinancier">
                        <?php if (empty($bilan)): ?>
                            <tr>
                                <td colspan="7" class="empty-cell">Aucune donnee</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $totCa = 0;
                            $totCout = 0;
                            $totNb = 0;
                            $totDep = 0;
                            foreach ($bilan as $r):
                                $m  = floatval($r['ca']) - floatval($r['cout']);
                                $dp = floatval($depJour[$r['jour']] ?? 0);
                                $bn = $m - $dp;
                                $totCa   += floatval($r['ca']);
                                $totCout += floatval($r['cout']);
                                $totNb   += intval($r['nb']);
                                $totDep  += $dp;
                            ?>
                                <tr>
                                    <td><strong style="color:var(--primary)"><?= e($r['jour']) ?></strong></td>
                                    <td><?= $r['nb'] ?></td>
                                    <td><?= fmt($r['ca']) ?> F</td>
                                    <td><?= fmt($r['cout']) ?> F</td>
                                    <td><span class="badge badge-blue"><?= fmt($m) ?> F</span></td>
                                    <td><?= fmt(floatval($r['ca']) * 0.2 / 1.2) ?> F</td>
                                    <td><span class="badge badge-<?= $bn >= 0 ? 'success' : 'danger' ?>"><?= fmt($bn) ?> F</span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($bilan)): ?>
                        <tfoot>
                            <tr>
                                <td><strong>TOTAL</strong></td>
                                <td><?= $totNb ?></td>
                                <td><?= fmt($totCa) ?> F</td>
                                <td><?= fmt($totCout) ?> F</td>
                                <td><span class="badge badge-blue"><?= fmt($totCa - $totCout) ?> F</span></td>
                                <td><?= fmt($totCa * 0.2 / 1.2) ?> F</td>
                                <td><span class="badge badge-<?= ($totCa - $totCout - $totDep) >= 0 ? 'success' : 'danger' ?>"><?= fmt($totCa - $totCout - $totDep) ?> F</span></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- ── TAB : RAPPORT VENDEURS ── -->
        <div class="tab-content" id="tabUtilisateurs">
            <div class="section-title">
                <div class="section-title-left"><i class="fas fa-user-tie"></i> Performance Vendeurs</div>
                <div class="section-actions">
                    <button class="btn-action" onclick="exportTable('tableVendeurs')"><i class="fas fa-download"></i> Export</button>
                </div>
            </div>
            <div class="table-wrapper">
                <div class="table-header">
                    <h5>Rapport Vendeurs</h5>
                    <span class="count"><?= count($rappU) ?> vendeurs</span>
                </div>
                <table id="tableVendeurs">
                    <thead>
                        <tr>
                            <th>Vendeur</th>
                            <th>Role</th>
                            <th>Nb Ventes</th>
                            <th>CA</th>
                            <th>Marge</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyUtilisateurs">
                        <?php if (empty($rappU)): ?>
                            <tr>
                                <td colspan="5" class="empty-cell">Aucune donnee</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rappU as $r):
                                $m = floatval($r['ca']) - floatval($r['cout']);
                            ?>
                                <tr>
                                    <td><strong style="color:var(--primary)"><?= e($r['nom_prenom']) ?></strong></td>
                                    <td><span class="badge badge-info"><?= e($r['role'] ?? '—') ?></span></td>
                                    <td><?= $r['nb'] ?></td>
                                    <td><strong><?= fmt($r['ca']) ?> F</strong></td>
                                    <td><span class="badge badge-<?= $m >= 0 ? 'success' : 'danger' ?>"><?= fmt($m) ?> F</span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── TAB : RAPPORT BOUTIQUES ── -->
        <div class="tab-content" id="tabBoutiques">
            <div class="section-title">
                <div class="section-title-left"><i class="fas fa-store-alt"></i> Performance Boutiques</div>
                <div class="section-actions">
                    <button class="btn-action" onclick="exportTable('tableBoutiques')"><i class="fas fa-download"></i> Export</button>
                </div>
            </div>
            <div class="table-wrapper">
                <div class="table-header">
                    <h5>Rapport Boutiques</h5>
                    <span class="count"><?= count($rappB) ?> boutiques</span>
                </div>
                <table id="tableBoutiques">
                    <thead>
                        <tr>
                            <th>Boutique</th>
                            <th>Nb Ventes</th>
                            <th>CA</th>
                            <th>Cout</th>
                            <th>Marge</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyBoutiques">
                        <?php if (empty($rappB)): ?>
                            <tr>
                                <td colspan="5" class="empty-cell">Aucune donnee</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rappB as $r):
                                $m = floatval($r['ca']) - floatval($r['cout']);
                            ?>
                                <tr>
                                    <td><strong style="color:var(--primary)"><?= e($r['nom_boutique']) ?></strong></td>
                                    <td><?= $r['nb'] ?></td>
                                    <td><?= fmt($r['ca']) ?> F</td>
                                    <td><?= fmt($r['cout']) ?> F</td>
                                    <td><span class="badge badge-<?= $m >= 0 ? 'success' : 'danger' ?>"><?= fmt($m) ?> F</span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="section-title">
                <div class="section-title-left"><i class="fas fa-money-bill-wave"></i> Depenses par Boutique</div>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Boutique</th>
                            <th>Nb Depenses</th>
                            <th>Montant Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($depBout)): ?>
                            <tr>
                                <td colspan="3" class="empty-cell">Aucune donnee</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($depBout as $r): ?>
                                <tr>
                                    <td><strong style="color:var(--primary)"><?= e($r['nom_boutique']) ?></strong></td>
                                    <td><?= $r['nb'] ?></td>
                                    <td><?= fmt($r['t']) ?> F</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── TAB : JOURNAL MOUVEMENTS ── -->
        <div class="tab-content" id="tabJournal">
            <div class="section-title">
                <div class="section-title-left"><i class="fas fa-scroll"></i> Journal des Mouvements</div>
                <div class="section-actions">
                    <button class="btn-action" onclick="exportTable('tableJournal')"><i class="fas fa-download"></i> Export</button>
                </div>
            </div>
            <div class="table-wrapper">
                <div class="table-header">
                    <h5>Journal</h5>
                    <span class="count"><?= count($journal) ?> mouvements</span>
                </div>
                <table id="tableJournal">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Date/Heure</th>
                            <th>Type</th>
                            <th>Produit</th>
                            <th>Qte</th>
                            <th>Montant</th>
                            <th>Vendeur</th>
                            <th>Boutique</th>
                            <th>Etat</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyJournal">
                        <?php if (empty($journal)): ?>
                            <tr>
                                <td colspan="9" class="empty-cell">Aucun mouvement</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $typeBadge = [
                                'VENTE'      => 'badge-success',
                                'ACHAT'      => 'badge-blue',
                                'TRANSF. S.' => 'badge-warning',
                                'TRANSF. E.' => 'badge-info',
                                'RETOUR'     => 'badge-danger'
                            ];
                            foreach ($journal as $r):
                                $bc = $typeBadge[$r['type']] ?? 'badge-secondary';
                            ?>
                                <tr>
                                    <td><strong style="color:var(--primary)"><?= e($r['ref']) ?></strong></td>
                                    <td><?= e($r['dop']) ?> <?= substr($r['hop'] ?? '', 0, 5) ?></td>
                                    <td><span class="badge <?= $bc ?>"><?= e($r['type']) ?></span></td>
                                    <td><?= e($r['titre_produit'] ?? '—') ?></td>
                                    <td><?= $r['qte'] ?></td>
                                    <td><?= fmt($r['mt']) ?> F</td>
                                    <td><?= e($r['usr'] ?? '—') ?></td>
                                    <td><?= e($r['bout'] ?? '—') ?></td>
                                    <td><span class="badge badge-secondary"><?= e($r['etat']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- page-wrapper -->

    <script>
        // ── Toasts ──
        function showToast(title, message, type) {
            type = type || 'info';
            var icons = {
                success: 'fa-check-circle',
                error: 'fa-times-circle',
                info: 'fa-info-circle'
            };
            var container = document.getElementById('toastContainer');
            var toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.innerHTML =
                '<div class="toast-icon"><i class="fas ' + (icons[type] || icons.info) + '"></i></div>' +
                '<div class="toast-content"><div class="toast-title">' + title + '</div><div class="toast-message">' + message + '</div></div>' +
                '<button class="toast-close" onclick="closeToast(this)"><i class="fas fa-times"></i></button>';
            container.appendChild(toast);
            setTimeout(function() {
                closeToastAuto(toast);
            }, 4000);
        }

        function closeToast(btn) {
            var t = btn.closest('.toast');
            t.classList.add('hiding');
            setTimeout(function() {
                t.remove();
            }, 300);
        }

        function closeToastAuto(t) {
            if (!t.parentNode) return;
            t.classList.add('hiding');
            setTimeout(function() {
                t.remove();
            }, 300);
        }

        // ── Tabs ──
        document.querySelectorAll('.tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-btn').forEach(function(b) {
                    b.classList.remove('active');
                });
                document.querySelectorAll('.tab-content').forEach(function(c) {
                    c.classList.remove('active');
                });
                btn.classList.add('active');
                document.getElementById(btn.dataset.tab).classList.add('active');
            });
        });

        // ── Toggle custom dates ──
        var periodeSelect = document.getElementById('filterPeriode');
        periodeSelect.addEventListener('change', function() {
            var show = periodeSelect.value === 'custom';
            document.getElementById('customDates').style.display = show ? '' : 'none';
            document.getElementById('customDateFin').style.display = show ? '' : 'none';
        });

        // ── Export CSV ──
        function exportTable(tableId) {
            var table = document.getElementById(tableId);
            if (!table) return;
            var csv = '';
            table.querySelectorAll('tr').forEach(function(row) {
                var cols = [];
                row.querySelectorAll('th, td').forEach(function(cell) {
                    cols.push(cell.innerText.trim().replace(/\s+/g, ' '));
                });
                csv += cols.join(';') + '\n';
            });
            var blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = tableId + '_' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
            URL.revokeObjectURL(url);
            showToast('Export', 'Fichier CSV genere', 'success');
        }

        // ── Charts ──
        var moisLabels = <?= json_encode($moisLabels) ?>;
        var caData = <?= json_encode($caData) ?>;
        var benData = <?= json_encode($benData) ?>;
        var depData = <?= json_encode($depData) ?>;
        var perfVLabels = <?= json_encode(array_column($perfV, 'nom_prenom')) ?>;
        var perfVData = <?= json_encode(array_map(fn($r) => floatval($r['ca']), $perfV)) ?>;
        var perfBLabels = <?= json_encode(array_column($perfB, 'nom_boutique')) ?>;
        var perfBData = <?= json_encode(array_map(fn($r) => floatval($r['ca']), $perfB)) ?>;
        var depBoutLabels = <?= json_encode(array_column($depBout, 'nom_boutique')) ?>;
        var depBoutData = <?= json_encode(array_map(fn($r) => floatval($r['t']), $depBout)) ?>;
        var boutColors = ['#2563eb', '#059669', '#d97706', '#7c3aed', '#dc2626', '#0284c7', '#0d9488', '#e11d48', '#ea580c', '#475569'];

        // Evolution CA / Benefice / Depenses
        new Chart(document.getElementById('chartEvol'), {
            type: 'line',
            data: {
                labels: moisLabels,
                datasets: [{
                        label: 'CA',
                        data: caData,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,0.12)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 3
                    },
                    {
                        label: 'Benefice',
                        data: benData,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5,150,105,0.12)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 3
                    },
                    {
                        label: 'Depenses',
                        data: depData,
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220,38,38,0.12)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 3
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 16
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString('fr-FR') + ' F';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) {
                                return v.toLocaleString('fr-FR') + ' F';
                            }
                        }
                    }
                }
            }
        });

        // Depenses mensuelles
        new Chart(document.getElementById('chartDep'), {
            type: 'bar',
            data: {
                labels: moisLabels,
                datasets: [{
                    label: 'Depenses',
                    data: depData,
                    backgroundColor: 'rgba(220,38,38,0.7)',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.parsed.y.toLocaleString('fr-FR') + ' F';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) {
                                return v.toLocaleString('fr-FR') + ' F';
                            }
                        }
                    }
                }
            }
        });

        // Top Vendeurs
        new Chart(document.getElementById('chartPerfV'), {
            type: 'bar',
            data: {
                labels: perfVLabels,
                datasets: [{
                    label: 'CA',
                    data: perfVData,
                    backgroundColor: 'rgba(37,99,235,0.75)',
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.parsed.x.toLocaleString('fr-FR') + ' F';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) {
                                return v.toLocaleString('fr-FR') + ' F';
                            }
                        }
                    }
                }
            }
        });

        // CA par Boutique (doughnut)
        new Chart(document.getElementById('chartPerfB'), {
            type: 'doughnut',
            data: {
                labels: perfBLabels,
                datasets: [{
                    data: perfBData,
                    backgroundColor: boutColors,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 12
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.label + ': ' + ctx.parsed.toLocaleString('fr-FR') + ' F';
                            }
                        }
                    }
                }
            }
        });

        // Depenses par Boutique
        new Chart(document.getElementById('chartDepBout'), {
            type: 'bar',
            data: {
                labels: depBoutLabels,
                datasets: [{
                    label: 'Depenses',
                    data: depBoutData,
                    backgroundColor: 'rgba(220,38,38,0.65)',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.parsed.y.toLocaleString('fr-FR') + ' F';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) {
                                return v.toLocaleString('fr-FR') + ' F';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>