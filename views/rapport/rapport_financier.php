<?php
// rapport_financier.php
// Fusion de : compte_tresorerie.php, situation_clients.php, facture_clients.php,
// facture_fournisseur.php, resume_achats.php + onglet "Soldes"
require 'databases/database.php';
require 'fonctions_rapport.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}
$stmtU = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmtU->execute([$_SESSION['user_id']]);
$user = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}
if (!in_array($user['role'], ['Administrateur', 'Superviseur', 'Proprietaire'], true)) {
    http_response_code(403);
    die("Accès non autorisé à ce rapport.");
}

if (!function_exists('e')) {
    function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt')) {
    function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }
}

// ==========================================================
// PAGINATION
// ==========================================================
function paginer($pdo, $sql, $countSql, $params, $page, $perPage, $rowRenderer, $colspan) {
    $page = max(1, (int)$page);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPages = (int)ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare($sql . " LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($rows)) {
        echo '<tr><td colspan="' . $colspan . '" class="empty-cell"><i class="bi bi-inbox d-block mb-2" style="font-size:3rem;opacity:.2;"></i><div class="text-muted small">Aucune donnée disponible</div></td></tr>';
    } else {
        foreach ($rows as $row) echo $rowRenderer($row);
    }
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top">
        <span class="text-muted small">Affichage de <?= ($offset + 1) ?> à <?= min($offset + $perPage, $total) ?> sur <?= $total ?></span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $page - 1 ?>"><i class="bi bi-chevron-left"></i></a></li>
                <?php
                $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
                if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>'; if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
                for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>"><a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a></li>
                <?php endfor;
                if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $totalPages . '">' . $totalPages . '</a></li>'; }
                ?>
                <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $page + 1 ?>"><i class="bi bi-chevron-right"></i></a></li>
            </ul>
        </nav>
    </div>
    <?php endif;
    $paginationHtml = ob_get_clean();
    return compact('tableHtml', 'paginationHtml', 'total', 'page', 'totalPages');
}

function badgeEtatTransaction($type) {
    if ($type === 'Encaissement') return ['bg-success-subtle text-success', 'Encaissement'];
    if ($type === 'Paiement') return ['bg-info-subtle text-info', 'Paiement'];
    if ($type === 'Sortie') return ['bg-danger-subtle text-danger', 'Décaissement'];
    return ['bg-primary-subtle text-primary', $type];
}

// ==========================================================
// ONGLET 1 : TRÉSORERIE
// ==========================================================
function chargerTresorerie($pdo, $page) {
    $sql = "SELECT date_transaction, heure_transaction, montant_transaction, montant_total, type_transaction, objet_transaction, mode_reglement, etat_transaction
            FROM transaction
            WHERE type_transaction IN ('Encaissement','Paiement','Sortie') AND etat_transaction IN ('Succes','Valide')
            ORDER BY date_transaction DESC, heure_transaction DESC";
    $countSql = "SELECT COUNT(*) FROM transaction WHERE type_transaction IN ('Encaissement','Paiement','Sortie') AND etat_transaction IN ('Succes','Valide')";
    $renderer = function ($row) {
        [$cls, $label] = badgeEtatTransaction($row['type_transaction']);
        $montant = $row['type_transaction'] === 'Sortie' ? (float)$row['montant_total'] : (float)$row['montant_transaction'];
        return '<tr>'
            . '<td class="fw-semibold">' . e($row['date_transaction']) . ' ' . e($row['heure_transaction']) . '</td>'
            . '<td><span class="badge-chic ' . $cls . '"><span class="dot"></span> ' . e($label) . '</span></td>'
            . '<td class="fw-bold text-primary">' . fmt($montant) . ' F</td>'
            . '<td>' . e($row['objet_transaction'] ?? '—') . '</td>'
            . '<td>' . e($row['mode_reglement'] ?? '—') . '</td>'
            . '<td><span class="badge-chic bg-success-subtle text-success"><span class="dot"></span> ' . e($row['etat_transaction']) . '</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, [], $page, 20, $renderer, 6);
}

// ==========================================================
// ONGLET 2 : TRANSACTIONS CLIENTS
// ==========================================================
function chargerTransactionsClients($pdo, $page) {
    $sql = "SELECT t.numero_transaction, t.date_transaction, t.heure_transaction, t.montant_transaction, t.type_transaction, t.mode_reglement, t.etat_transaction, ct.nom_prenom_contact AS client, f.numero_facture
            FROM transaction t
            LEFT JOIN facture f ON t.facture_id = f.numero_facture
            LEFT JOIN contact ct ON f.contact_id = ct.code_contact
            WHERE ct.type_contact = 'CLIENT' OR t.facture_id IS NULL
            ORDER BY t.date_transaction DESC, t.heure_transaction DESC";
    $countSql = "SELECT COUNT(*) FROM transaction t LEFT JOIN facture f ON t.facture_id=f.numero_facture LEFT JOIN contact ct ON f.contact_id=ct.code_contact WHERE ct.type_contact='CLIENT' OR t.facture_id IS NULL";
    $renderer = function ($row) {
        [$cls, $label] = badgeEtatTransaction($row['type_transaction']);
        return '<tr>'
            . '<td class="fw-bold">' . e($row['numero_transaction']) . '</td>'
            . '<td>' . date('d/m/Y H:i', strtotime($row['date_transaction'] . ' ' . $row['heure_transaction'])) . '</td>'
            . '<td class="fw-semibold">' . e($row['client'] ?? '—') . '</td>'
            . '<td class="fw-bold text-primary">' . fmt((float)$row['montant_transaction']) . ' F</td>'
            . '<td><span class="badge-chic ' . $cls . '"><span class="dot"></span> ' . e($label) . '</span></td>'
            . '<td>' . e($row['mode_reglement'] ?? '—') . '</td>'
            . '<td>' . e($row['numero_facture'] ?? '—') . '</td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, [], $page, 20, $renderer, 7);
}

// ==========================================================
// ONGLET 3 : FACTURES CLIENTS
// ==========================================================
function chargerFacturesClients($pdo, $page) {
    $sql = "SELECT f.numero_facture, f.date_facture, f.montant_ttc, f.avance, f.reste, f.etat_facture, ct.nom_prenom_contact AS client
            FROM facture f JOIN contact ct ON f.contact_id = ct.code_contact
            WHERE ct.type_contact = 'CLIENT' ORDER BY f.date_facture DESC";
    $countSql = "SELECT COUNT(*) FROM facture f JOIN contact ct ON f.contact_id=ct.code_contact WHERE ct.type_contact='CLIENT'";
    $renderer = function ($row) {
        $etat = $row['etat_facture'];
        $badge = ($etat === 'Payée' || $etat === 'Payer cash') ? 'bg-success-subtle text-success' : (($etat === 'Partielle') ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger');
        return '<tr>'
            . '<td class="fw-bold">' . e($row['numero_facture']) . '</td>'
            . '<td class="fw-semibold">' . e($row['client']) . '</td>'
            . '<td>' . date('d/m/Y', strtotime($row['date_facture'])) . '</td>'
            . '<td class="fw-bold text-primary">' . fmt((float)$row['montant_ttc']) . ' F</td>'
            . '<td>' . fmt((float)$row['avance']) . ' F</td>'
            . '<td class="fw-semibold text-danger">' . fmt((float)$row['reste']) . ' F</td>'
            . '<td><span class="badge-chic ' . $badge . '"><span class="dot"></span> ' . e($etat) . '</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, [], $page, 20, $renderer, 7);
}

// ==========================================================
// ONGLET 4 : FACTURES FOURNISSEURS
// ==========================================================
function chargerFacturesFournisseurs($pdo, $page) {
    $sql = "SELECT f.numero_facture, f.date_facture, f.montant_ttc, f.avance, f.reste, f.etat_facture, ct.nom_prenom_contact AS fournisseur
            FROM facture f JOIN contact ct ON f.contact_id = ct.code_contact
            WHERE ct.type_contact = 'FOURNISSEUR' ORDER BY f.date_facture DESC";
    $countSql = "SELECT COUNT(*) FROM facture f JOIN contact ct ON f.contact_id=ct.code_contact WHERE ct.type_contact='FOURNISSEUR'";
    $renderer = function ($row) {
        $etat = $row['etat_facture'];
        $badge = ($etat === 'Payée' || $etat === 'Payer cash') ? 'bg-success-subtle text-success' : (($etat === 'Partielle') ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger');
        return '<tr>'
            . '<td class="fw-bold">' . e($row['numero_facture']) . '</td>'
            . '<td class="fw-semibold">' . e($row['fournisseur']) . '</td>'
            . '<td>' . date('d/m/Y', strtotime($row['date_facture'])) . '</td>'
            . '<td class="fw-bold text-primary">' . fmt((float)$row['montant_ttc']) . ' F</td>'
            . '<td>' . fmt((float)$row['avance']) . ' F</td>'
            . '<td class="fw-semibold text-danger">' . fmt((float)$row['reste']) . ' F</td>'
            . '<td><span class="badge-chic ' . $badge . '"><span class="dot"></span> ' . e($etat) . '</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, [], $page, 20, $renderer, 7);
}

// ==========================================================
// ONGLET 5 : RÉSUMÉ DES ACHATS
// ==========================================================
function chargerAchats($pdo, $page) {
    $sql = "SELECT c.numero_commande, c.date_commande, c.prix_achat, c.quantite_commande, c.montant_commande, c.etat_commande,
            p.titre_produit, ct.nom_prenom_contact AS fournisseur, b.nom_boutique, cat.titre_categorie
            FROM commande c
            LEFT JOIN produit p ON c.produit_id = p.code_produit
            LEFT JOIN contact ct ON c.contact_id = ct.code_contact
            LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
            LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
            WHERE c.statut_id='011' AND c.etat_commande NOT IN ('En attente','Annulé')
            ORDER BY c.date_commande DESC, c.heure_commande DESC";
    $countSql = "SELECT COUNT(*) FROM commande WHERE statut_id='011' AND etat_commande NOT IN ('En attente','Annulé')";
    $renderer = function ($row) {
        $etat = $row['etat_commande'];
        $badge = ($etat === 'Reçu' || $etat === 'Validé' || $etat === 'VALIDEE') ? 'bg-success-subtle text-success' : (($etat === 'En attente') ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger');
        return '<tr>'
            . '<td class="fw-bold">' . e($row['numero_commande']) . '</td>'
            . '<td>' . date('d/m/Y', strtotime($row['date_commande'])) . '</td>'
            . '<td class="fw-semibold">' . e($row['fournisseur'] ?? '—') . '</td>'
            . '<td>' . e($row['titre_produit'] ?? '—') . '</td>'
            . '<td>' . e($row['titre_categorie'] ?? '—') . '</td>'
            . '<td class="text-center">' . (int)$row['quantite_commande'] . '</td>'
            . '<td class="text-end">' . fmt((float)$row['prix_achat']) . ' F</td>'
            . '<td class="text-end fw-bold text-primary">' . fmt((float)$row['montant_commande']) . ' F</td>'
            . '<td>' . e($row['nom_boutique'] ?? '—') . '</td>'
            . '<td><span class="badge-chic ' . $badge . '"><span class="dot"></span> ' . e($etat) . '</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, [], $page, 20, $renderer, 10);
}

// ==========================================================
// ONGLET 6 : SOLDES CLIENTS & FOURNISSEURS
// ==========================================================
function chargerSoldes($pdo, $type, $page) {
    $sql = "SELECT ct.code_contact, ct.nom_prenom_contact, ct.telephone_contact,
            COUNT(f.numero_facture) AS nb_factures,
            COALESCE(SUM(f.reste),0) AS solde_du,
            MAX(f.date_facture) AS derniere_facture
            FROM contact ct
            JOIN facture f ON f.contact_id = ct.code_contact
            WHERE ct.type_contact = :type AND f.reste > 0
            GROUP BY ct.code_contact ORDER BY solde_du DESC";
    $countSql = "SELECT COUNT(*) FROM (SELECT ct.code_contact FROM contact ct JOIN facture f ON f.contact_id=ct.code_contact WHERE ct.type_contact = :type AND f.reste > 0 GROUP BY ct.code_contact) x";
    $renderer = function ($row) {
        return '<tr>'
            . '<td class="fw-bold">' . e($row['nom_prenom_contact']) . '</td>'
            . '<td>' . e($row['telephone_contact'] ?? '—') . '</td>'
            . '<td class="text-center">' . (int)$row['nb_factures'] . '</td>'
            . '<td class="text-end fw-bold text-danger">' . fmt((float)$row['solde_du']) . ' F</td>'
            . '<td>' . ($row['derniere_facture'] ? date('d/m/Y', strtotime($row['derniere_facture'])) : '—') . '</td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, [':type' => $type], $page, 20, $renderer, 5);
}

// ==========================================================
// DISPATCHER AJAX
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $tab = $_POST['tab'] ?? 'tresorerie';
    $page = (int)($_POST['page'] ?? 1);
    switch ($tab) {
        case 'transactions_clients': $res = chargerTransactionsClients($pdo, $page); break;
        case 'factures_clients':     $res = chargerFacturesClients($pdo, $page); break;
        case 'factures_fournisseurs':$res = chargerFacturesFournisseurs($pdo, $page); break;
        case 'achats':                $res = chargerAchats($pdo, $page); break;
        case 'soldes_clients':        $res = chargerSoldes($pdo, 'CLIENT', $page); break;
        case 'soldes_fournisseurs':   $res = chargerSoldes($pdo, 'FOURNISSEUR', $page); break;
        default:                      $res = chargerTresorerie($pdo, $page); break;
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['table' => $res['tableHtml'], 'pagination' => $res['paginationHtml'], 'total' => $res['total']]);
    exit;
}

// ==========================================================
// CHARGEMENT INITIAL
// ==========================================================
$resTresorerie      = chargerTresorerie($pdo, 1);
$resTransClients    = chargerTransactionsClients($pdo, 1);
$resFactClients     = chargerFacturesClients($pdo, 1);
$resFactFourn       = chargerFacturesFournisseurs($pdo, 1);
$resAchats          = chargerAchats($pdo, 1);
$resSoldesClients   = chargerSoldes($pdo, 'CLIENT', 1);
$resSoldesFourn     = chargerSoldes($pdo, 'FOURNISSEUR', 1);

// Stats globales
$encaissements = (float)$pdo->query("SELECT COALESCE(SUM(CAST(montant_transaction AS DECIMAL(12,2))),0) FROM transaction WHERE type_transaction IN ('Encaissement','Paiement') AND etat_transaction IN ('Succes','Valide')")->fetchColumn();
$decais        = (float)$pdo->query("SELECT COALESCE(SUM(CAST(montant_total AS DECIMAL(12,2))),0) FROM transaction WHERE type_transaction='Sortie' AND etat_transaction IN ('Succes','Valide')")->fetchColumn();
$solde_caisse  = (float)$pdo->query("SELECT COALESCE(SUM(solde),0) FROM caisse WHERE statut='Actif'")->fetchColumn();
$total_achats  = (float)$pdo->query("SELECT COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0) FROM commande WHERE statut_id='011' AND etat_commande NOT IN ('En attente','Annulé')")->fetchColumn();
$totalCreances = (float)$pdo->query("SELECT COALESCE(SUM(f.reste),0) FROM facture f JOIN contact ct ON f.contact_id=ct.code_contact WHERE ct.type_contact='CLIENT' AND f.reste > 0")->fetchColumn();
$totalDettes   = (float)$pdo->query("SELECT COALESCE(SUM(f.reste),0) FROM facture f JOIN contact ct ON f.contact_id=ct.code_contact WHERE ct.type_contact='FOURNISSEUR' AND f.reste > 0")->fetchColumn();
$solde_net     = $encaissements - $decais;

// Graphique : évolution achats (12 derniers mois)
$evolAchats = $pdo->query("SELECT DATE_FORMAT(date_commande,'%Y-%m') AS mois, COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0) AS total FROM commande WHERE statut_id='011' AND etat_commande NOT IN ('En attente','Annulé') AND date_commande >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY mois ORDER BY mois ASC")->fetchAll(PDO::FETCH_ASSOC);

// Graphique : répartition trésorerie
$repartitionTreso = $pdo->query("SELECT type_transaction, COALESCE(SUM(CAST(montant_transaction AS DECIMAL(12,2))),0) AS total FROM transaction WHERE type_transaction IN ('Encaissement','Paiement','Sortie') AND etat_transaction IN ('Succes','Valide') GROUP BY type_transaction")->fetchAll(PDO::FETCH_ASSOC);

$onglet = $_GET['onglet'] ?? 'tresorerie';
$ongletsValides = ['tresorerie', 'transactions_clients', 'factures_clients', 'factures_fournisseurs', 'achats', 'soldes'];
if (!in_array($onglet, $ongletsValides, true)) $onglet = 'tresorerie';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapport Financier</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

/* ===== STATS ===== */
.stat-card {
    background: var(--bg-surface); border: 1px solid var(--border-color);
    border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base);
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

/* ===== CHART CARDS ===== */
.chart-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    transition: var(--transition-base);
}
.chart-card:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--color-gray-300);
}
.chart-card h4 {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-tertiary);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.chart-card h4 i { font-size: 16px; }

/* ===== REPORT CARDS ===== */
.report-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 20px 24px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    transition: var(--transition-base);
}
.report-card:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--color-gray-300);
}
.report-card h3 {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    border-bottom: 2px solid var(--color-gray-100);
    padding-bottom: 12px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.report-card h3 i {
    font-size: 18px;
    color: var(--color-primary);
}

/* ===== TABLES ===== */
.table-wrapper {
    overflow-x: auto;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    background: var(--bg-surface);
}
table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
}
thead {
    background: linear-gradient(135deg, var(--color-gray-50) 0%, var(--color-gray-100) 100%);
}
th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 700;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-tertiary);
    border-bottom: 2px solid var(--border-color);
}
td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--color-gray-100);
    color: var(--text-primary);
    vertical-align: middle;
}
tbody tr { transition: background 0.15s; }
tbody tr:hover { background: var(--color-primary-soft); }
tbody tr:last-child td { border-bottom: none; }
.empty-cell {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-tertiary);
}

/* ===== PAGINATION ===== */
.pagination { gap: 4px; }
.pagination .page-link {
    color: var(--color-primary);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s;
}
.pagination .page-link:hover {
    background: var(--color-primary-soft);
    border-color: var(--color-primary);
    color: var(--color-primary-dark);
}
.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
    border-color: var(--color-primary);
    color: #fff;
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
}
.pagination .page-item.disabled .page-link {
    color: var(--text-tertiary);
    background: var(--color-gray-50);
}

/* ===== BADGES ===== */
.badge-chic {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.06);
}
.badge-chic .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* ===== ONGLETS ===== */
.nav-tabs {
    border-bottom: 2px solid var(--border-color);
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 4px;
}
.nav-tabs .nav-link {
    border: none;
    color: var(--text-tertiary);
    font-weight: 600;
    font-size: 13px;
    padding: 12px 18px;
    border-radius: 8px 8px 0 0;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.nav-tabs .nav-link:hover {
    color: var(--color-primary);
    background: var(--color-primary-soft);
}
.nav-tabs .nav-link.active {
    color: var(--color-primary);
    background: var(--color-primary-soft);
    border-bottom: 2px solid var(--color-primary);
    margin-bottom: -2px;
}

/* ===== SUB-NAV (Soldes) ===== */
.sub-nav { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.sub-nav button {
    background: var(--color-gray-100);
    border: 1.5px solid var(--border-color);
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.sub-nav button:hover {
    background: var(--color-primary-soft);
    border-color: var(--color-primary);
    color: var(--color-primary);
}
.sub-nav button.active {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
    color: white;
    border-color: var(--color-primary);
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
}

/* ===== ANIMATIONS ===== */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}
.chart-card, .report-card, .stat-card {
    animation: fadeUp 0.4s ease both;
}

@media (max-width: 768px) {
    .sub-nav { flex-direction: column; }
}
</style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-bank text-primary me-2"></i>Rapport Financier</h1>
            <p class="text-muted small mb-0">Trésorerie, créances, dettes et achats</p>
        </div>
        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
            <i class="bi bi-wallet2"></i> Solde net: <?= fmt($solde_net) ?> F
        </span>
    </div>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['success', 'arrow-down-circle', 'Encaissements', fmt($encaissements) . ' F', ''],
            ['danger', 'arrow-up-circle', 'Décaissements', fmt($decais) . ' F', ''],
            ['info', 'cash-stack', 'Solde caisse', fmt($solde_caisse) . ' F', ''],
            ['warning', 'exclamation-triangle', 'Créances clients', fmt($totalCreances) . ' F', ''],
            ['purple', 'truck', 'Dettes fournisseurs', fmt($totalDettes) . ' F', ''],
            ['primary', 'bag', 'Total achats', fmt($total_achats) . ' F', ''],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
            'danger'  => ['var(--color-danger-soft)', 'var(--color-danger)'],
            'purple'  => ['var(--color-purple-soft)', 'var(--color-purple)'],
            'info'    => ['var(--color-info-soft)', 'var(--color-info)'],
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

    <!-- Onglets -->
    <ul class="nav nav-tabs" id="rapportTabs" role="tablist">
        <li class="nav-item"><button class="nav-link <?= $onglet=='tresorerie'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-tresorerie" type="button" data-tab="tresorerie"><i class="bi bi-wallet2"></i> Trésorerie</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='transactions_clients'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-transactions_clients" type="button" data-tab="transactions_clients"><i class="bi bi-arrow-left-right"></i> Transactions clients</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='factures_clients'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-factures_clients" type="button" data-tab="factures_clients"><i class="bi bi-receipt"></i> Factures clients</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='factures_fournisseurs'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-factures_fournisseurs" type="button" data-tab="factures_fournisseurs"><i class="bi bi-receipt-cutoff"></i> Factures fournisseurs</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='achats'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-achats" type="button" data-tab="achats"><i class="bi bi-bag"></i> Résumé des achats</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='soldes'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-soldes" type="button" data-tab="soldes"><i class="bi bi-scale"></i> Soldes</button></li>
    </ul>

    <div class="tab-content">
        <!-- ONGLET 1 : TRÉSORERIE -->
        <div class="tab-pane fade <?= $onglet=='tresorerie'?'show active':'' ?>" id="pane-tresorerie">
            <div class="chart-card">
                <h4><i class="bi bi-pie-chart"></i> Répartition trésorerie</h4>
                <canvas id="chartTreso" height="90"></canvas>
            </div>
            <div class="report-card">
                <h3><i class="bi bi-clock-history"></i> Mouvements de trésorerie <span class="text-muted small ms-2"><?= $resTresorerie['total'] ?> lignes</span></h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Date & Heure</th><th>Type</th><th>Montant</th><th>Objet</th><th>Mode</th><th>État</th></tr></thead>
                        <tbody id="tbody-tresorerie"><?= $resTresorerie['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-tresorerie"><?= $resTresorerie['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 2 : TRANSACTIONS CLIENTS -->
        <div class="tab-pane fade <?= $onglet=='transactions_clients'?'show active':'' ?>" id="pane-transactions_clients">
            <div class="report-card">
                <h3><i class="bi bi-arrow-left-right"></i> Transactions clients</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>N° Transaction</th><th>Date</th><th>Client</th><th>Montant</th><th>Type</th><th>Mode</th><th>N° Facture</th></tr></thead>
                        <tbody id="tbody-transactions_clients"><?= $resTransClients['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-transactions_clients"><?= $resTransClients['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 3 : FACTURES CLIENTS -->
        <div class="tab-pane fade <?= $onglet=='factures_clients'?'show active':'' ?>" id="pane-factures_clients">
            <div class="report-card">
                <h3><i class="bi bi-receipt"></i> Factures clients</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>N° Facture</th><th>Client</th><th>Date</th><th>Montant TTC</th><th>Avance</th><th>Reste</th><th>État</th></tr></thead>
                        <tbody id="tbody-factures_clients"><?= $resFactClients['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-factures_clients"><?= $resFactClients['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 4 : FACTURES FOURNISSEURS -->
        <div class="tab-pane fade <?= $onglet=='factures_fournisseurs'?'show active':'' ?>" id="pane-factures_fournisseurs">
            <div class="report-card">
                <h3><i class="bi bi-receipt-cutoff"></i> Factures fournisseurs</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>N° Facture</th><th>Fournisseur</th><th>Date</th><th>Montant TTC</th><th>Avance</th><th>Reste</th><th>État</th></tr></thead>
                        <tbody id="tbody-factures_fournisseurs"><?= $resFactFourn['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-factures_fournisseurs"><?= $resFactFourn['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 5 : RÉSUMÉ DES ACHATS -->
        <div class="tab-pane fade <?= $onglet=='achats'?'show active':'' ?>" id="pane-achats">
            <div class="chart-card">
                <h4><i class="bi bi-graph-up-arrow"></i> Évolution des achats (12 derniers mois)</h4>
                <canvas id="chartAchats" height="90"></canvas>
            </div>
            <div class="report-card">
                <h3><i class="bi bi-bag"></i> Détail des achats</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>N° Commande</th><th>Date</th><th>Fournisseur</th><th>Produit</th><th>Catégorie</th><th>Qté</th><th>Prix achat</th><th>Montant</th><th>Boutique</th><th>État</th></tr></thead>
                        <tbody id="tbody-achats"><?= $resAchats['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-achats"><?= $resAchats['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 6 : SOLDES -->
        <div class="tab-pane fade <?= $onglet=='soldes'?'show active':'' ?>" id="pane-soldes">
            <div class="sub-nav">
                <button type="button" class="active" data-solde="clients"><i class="bi bi-arrow-down-circle"></i> Créances clients (<?= fmt($totalCreances) ?> F)</button>
                <button type="button" data-solde="fournisseurs"><i class="bi bi-arrow-up-circle"></i> Dettes fournisseurs (<?= fmt($totalDettes) ?> F)</button>
            </div>
            <div class="report-card" id="card-soldes_clients">
                <h3><i class="bi bi-people"></i> Qui doit de l'argent (factures non soldées)</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Client</th><th>Téléphone</th><th>Nb factures dues</th><th>Solde dû</th><th>Dernière facture</th></tr></thead>
                        <tbody id="tbody-soldes_clients"><?= $resSoldesClients['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-soldes_clients"><?= $resSoldesClients['paginationHtml'] ?></div>
            </div>
            <div class="report-card" id="card-soldes_fournisseurs" style="display:none;">
                <h3><i class="bi bi-truck"></i> Ce qu'on doit aux fournisseurs</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Fournisseur</th><th>Téléphone</th><th>Nb factures dues</th><th>Solde dû</th><th>Dernière facture</th></tr></thead>
                        <tbody id="tbody-soldes_fournisseurs"><?= $resSoldesFourn['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-soldes_fournisseurs"><?= $resSoldesFourn['paginationHtml'] ?></div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function () {
    // Mémoriser l'onglet actif
    $('#rapportTabs button').on('shown.bs.tab', function (e) {
        var tab = $(e.target).data('tab');
        var url = new URL(window.location.href);
        url.searchParams.set('onglet', tab);
        history.replaceState(null, '', url);
    });

    // Sous-onglets Créances / Dettes
    $('.sub-nav button').on('click', function () {
        $('.sub-nav button').removeClass('active');
        $(this).addClass('active');
        var solde = $(this).data('solde');
        $('#card-soldes_clients').toggle(solde === 'clients');
        $('#card-soldes_fournisseurs').toggle(solde === 'fournisseurs');
    });

    // Graphique répartition trésorerie
    <?php if (!empty($repartitionTreso)): ?>
    var ctxTreso = document.getElementById('chartTreso')?.getContext('2d');
    if (ctxTreso) {
        new Chart(ctxTreso, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_map(fn($r) => $r['type_transaction'], $repartitionTreso)) ?>,
                datasets: [{
                    data: <?= json_encode(array_map(fn($r) => floatval($r['total']), $repartitionTreso)) ?>,
                    backgroundColor: ['#10b981', '#0891b2', '#ef4444'],
                    borderWidth: 0,
                    borderRadius: 4,
                    spacing: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { family: 'Inter', size: 11, weight: '600' }, color: '#64748b', padding: 12 }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    // Graphique évolution achats
    <?php if (!empty($evolAchats)): ?>
    var ctxAchats = document.getElementById('chartAchats')?.getContext('2d');
    if (ctxAchats) {
        new Chart(ctxAchats, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($evolAchats, 'mois')) ?>,
                datasets: [{
                    label: 'Achats (FCFA)',
                    data: <?= json_encode(array_map(fn($r) => floatval($r['total']), $evolAchats)) ?>,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.08)',
                    tension: 0.3,
                    fill: true,
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointBackgroundColor: '#ef4444'
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } }
            }
        });
    }
    <?php endif; ?>

    // Pagination AJAX
    function chargerPage(tab, page) {
        $.post(window.location.pathname, { ajax: 1, tab: tab, page: page }, function (res) {
            $('#tbody-' + tab).html(res.table);
            $('#pagination-' + tab).html(res.pagination);
        }, 'json');
    }
    $('.tab-content').on('click', '.pagination .page-link', function (e) {
        e.preventDefault();
        var page = $(this).data('page');
        if (!page || $(this).closest('li').hasClass('disabled')) return;
        var tbody = $(this).closest('.report-card').find('tbody').attr('id').replace('tbody-', '');
        chargerPage(tbody, page);
    });
});
</script>
</body>
</html>