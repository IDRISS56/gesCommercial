<?php
// stock_disponible.php – Historique des produits avec stats par catégorie
ob_start();
require 'databases/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: utilisateur/login');
    exit;
}

$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: utilisateur/login');
    exit;
}

define('USER_BOUTIQUE', $user['boutique_id'] ?? null);

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// FONCTION : STATISTIQUES PAR CATÉGORIE
// ============================================================
function getStatsCategories($pdo) {
    $sql = "SELECT 
                COALESCE(c.titre_categorie, 'Sans catégorie') as categorie,
                c.code_categorie,
                COUNT(DISTINCT p.code_produit) as total_produits,
                COALESCE(SUM(CASE WHEN p.etat_produit = 'DISPONIBLE' THEN 1 ELSE 0 END), 0) as produits_disponibles,
                COALESCE(SUM(CASE WHEN p.etat_produit = 'ALERTE' THEN 1 ELSE 0 END), 0) as produits_alerte,
                COALESCE(SUM(CASE WHEN p.etat_produit = 'RUPTURE' THEN 1 ELSE 0 END), 0) as produits_rupture,
                COALESCE(SUM(p.prix_fournisseur * COALESCE(p.stock_produit, 0)), 0) as valeur_achat,
                COALESCE(SUM(p.prix_produit * COALESCE(p.stock_produit, 0)), 0) as valeur_vente,
                COALESCE(SUM((p.prix_produit - p.prix_fournisseur) * COALESCE(p.stock_produit, 0)), 0) as marge_beneficiaire,
                COALESCE(SUM(COALESCE(p.stock_produit, 0)), 0) as stock_total
            FROM produit p
            LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
            GROUP BY c.code_categorie, c.titre_categorie
            ORDER BY stock_total DESC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// FONCTION : LISTE DES PRODUITS (filtrée par catégorie)
// ============================================================
function getStockDisponible($pdo, $search, $categorie_filter, $page, $perPage = 20) {
    $sql = "SELECT p.*, COALESCE(c.titre_categorie, 'Sans catégorie') as categorie_titre
            FROM produit p
            LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
            WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (p.code_produit LIKE ? OR p.titre_produit LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($categorie_filter) && $categorie_filter !== 'Tous') {
        if ($categorie_filter === 'Sans catégorie') {
            $sql .= " AND (p.categorie_id IS NULL OR p.categorie_id = '')";
        } else {
            $sql .= " AND c.titre_categorie = ?";
            $params[] = $categorie_filter;
        }
    }

    $countSql = str_replace("SELECT p.*, COALESCE(c.titre_categorie, 'Sans catégorie') as categorie_titre", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPages = max(1, ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    $sql .= " ORDER BY p.titre_produit ASC LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($produits)):
?>
    <tr>
        <td colspan="9" class="text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
            Aucun produit trouvé
        </td>
    </tr>
<?php else: foreach ($produits as $p):
    $etatClass = '';
    $etatIcon = '';
    switch ($p['etat_produit']) {
        case 'DISPONIBLE': $etatClass = 'on'; $etatIcon = 'check-circle-fill'; break;
        case 'ALERTE': $etatClass = 'warn'; $etatIcon = 'exclamation-triangle-fill'; break;
        case 'RUPTURE': $etatClass = 'off'; $etatIcon = 'x-circle-fill'; break;
    }
    $stock = (int)$p['stock_produit'];
    $stockClass = '';
    if ($stock <= 0) $stockClass = 'text-danger fw-bold';
    elseif ($stock <= (int)$p['stock_alerte']) $stockClass = 'text-warning fw-bold';
    else $stockClass = 'text-success fw-bold';
    $benefice = floatval($p['prix_produit']) - floatval($p['prix_fournisseur']);
    $beneficeClass = $benefice > 0 ? 'text-success' : ($benefice < 0 ? 'text-danger' : 'text-muted');
?>
    <tr>
        <td class="td-bold"><?= e($p['code_produit']) ?></td>
        <td class="td-semi"><?= e($p['titre_produit']) ?></td>
        <td>
            <?php if (!empty($p['photo'])): ?>
                <img src="data:<?= e($p['type_photo']) ?>;base64,<?= base64_encode($p['photo']) ?>" 
                     alt="<?= e($p['titre_produit']) ?>" 
                     style="width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid var(--brd);">
            <?php else: ?>
                <div style="width:40px;height:40px;border-radius:8px;background:var(--bg);display:flex;align-items:center;justify-content:center;color:var(--lt);">
                    <i class="bi bi-image"></i>
                </div>
            <?php endif; ?>
        </td>
        <td class="text-end"><?= fmt($p['prix_fournisseur']) ?> F</td>
        <td class="text-end td-bold"><?= fmt($p['prix_produit']) ?> F</td>
        <td class="text-end <?= $beneficeClass ?> fw-bold"><?= fmt($benefice) ?> F</td>
        <td class="text-center"><?= (int)$p['stock_alerte'] ?></td>
        <td class="text-center">
            <span class="<?= $stockClass ?>"><?= $stock ?></span>
        </td>
        <td>
            <span class="badge bg-light text-dark border" style="font-size:10px;">
                <i class="bi bi-tag"></i> <?= e($p['categorie_titre']) ?>
            </span>
        </td>
        <td>
            <span class="status-badge <?= $etatClass ?>">
                <i class="bi bi-<?= $etatIcon ?>"></i>
                <?= e($p['etat_produit']) ?>
            </span>
        </td>
    </tr>
<?php endforeach; endif;
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1):
?>
    <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
        <span class="text-muted small">
            Affichage de <?= (($page - 1) * $perPage + 1) ?> à <?= min($page * $perPage, $total) ?> sur <?= $total ?>
        </span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="#" data-page="<?= $page - 1 ?>"><i class="bi bi-chevron-left"></i></a>
                </li>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                if ($start > 1) {
                    echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                    if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                }
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor;
                if ($end < $totalPages) {
                    if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $totalPages . '">' . $totalPages . '</a></li>';
                }
                ?>
                <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="#" data-page="<?= $page + 1 ?>"><i class="bi bi-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
    </div>
<?php endif;
    $paginationHtml = ob_get_clean();

    return [
        'table' => $tableHtml,
        'pagination' => $paginationHtml,
        'total' => $total,
        'page' => $page,
        'totalPages' => $totalPages
    ];
}

// ============================================================
// REQUÊTE AJAX (tout en POST)
// ============================================================
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $categorie = trim($_POST['categorie_filter'] ?? '');
    $page = max(1, (int)($_POST['page'] ?? 1));
    $result = getStockDisponible($pdo, $search, $categorie, $page);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// ============================================================
// DONNÉES POUR LA PAGE
// ============================================================
$stats_categories = getStatsCategories($pdo);

// Totaux généraux
$total_general = [
    'total_produits' => 0,
    'valeur_achat' => 0,
    'valeur_vente' => 0,
    'marge_beneficiaire' => 0,
    'stock_total' => 0
];
foreach ($stats_categories as $sc) {
    $total_general['total_produits'] += (int)$sc['total_produits'];
    $total_general['valeur_achat'] += floatval($sc['valeur_achat']);
    $total_general['valeur_vente'] += floatval($sc['valeur_vente']);
    $total_general['marge_beneficiaire'] += floatval($sc['marge_beneficiaire']);
    $total_general['stock_total'] += (int)$sc['stock_total'];
}
$pourcentage_marge = $total_general['valeur_vente'] > 0 
    ? round(($total_general['marge_beneficiaire'] / $total_general['valeur_vente']) * 100, 2) 
    : 0;

// Catégories pour le filtre
$categories = $pdo->query("SELECT DISTINCT COALESCE(c.titre_categorie, 'Sans catégorie') as titre 
                           FROM produit p 
                           LEFT JOIN categorie c ON p.categorie_id = c.code_categorie 
                           ORDER BY titre")->fetchAll(PDO::FETCH_COLUMN);

// Données initiales (sans filtre)
$search = '';
$categorie_filter = '';
$initialData = getStockDisponible($pdo, $search, $categorie_filter, 1);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des produits</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --b: #2563eb; --bd: #1d4ed8; --bl: #eff6ff; --bb: #bfdbfe;
            --bg: #f1f5f9; --w: #fff; --dk: #0f172a; --mt: #64748b;
            --lt: #94a3b8; --brd: #e2e8f0; --dng: #ef4444; --dngl: #fef2f2;
            --suc: #10b981; --sucl: #ecfdf5; --sucb: #a7f3d0;
            --wrn: #f59e0b; --wrnl: #fffbeb; --wrnb: #fde68a;
            --pur: #8b5cf6; --purl: #f5f3ff; --purb: #ddd6fe;
            --R: 16px; --Rs: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--dk); min-height: 100vh; padding: 28px 20px; }
        .W { max-width: 1500px; margin: 0 auto; }
        .hdr { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--dk); letter-spacing: -0.02em; font-family: 'Outfit', sans-serif; }
        .hdr-l p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
        .hdr-badge { background: var(--bl); border: 1px solid var(--bb); color: var(--b); padding: 8px 14px; border-radius: var(--Rs); font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }

        /* ===== CARTE GÉNÉRALE (style blanc comme les cartes catégories) ===== */
        .stats-general { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 24px; margin-bottom: 22px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.04); position: relative; overflow: hidden; }
        .stats-general::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--b), var(--pur)); }
        .sg-item { display: flex; flex-direction: column; gap: 4px; padding: 12px; background: var(--bg); border-radius: 10px; border-left: 3px solid var(--b); }
        .sg-item .sg-label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--mt); font-weight: 700; display: flex; align-items: center; gap: 6px; }
        .sg-item .sg-value { font-size: 22px; font-weight: 800; font-family: 'Outfit', sans-serif; color: var(--dk); }
        .sg-item .sg-sub { font-size: 10px; color: var(--lt); font-weight: 500; }
        .sg-item.achat { border-left-color: var(--dng); }
        .sg-item.vente { border-left-color: var(--b); }
        .sg-item.marge { border-left-color: var(--suc); }
        .sg-item.stock { border-left-color: var(--wrn); }

        /* ===== CARTES CATÉGORIES ===== */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .cat-card { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 18px; transition: all .2s; position: relative; overflow: hidden; cursor: pointer; }
        .cat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(15, 23, 42, .08); border-color: var(--bb); }
        .cat-card.active { border-color: var(--b); box-shadow: 0 0 0 3px var(--bl); }
        .cat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--b), var(--pur)); }
        .cat-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .cat-card-title { display: flex; align-items: center; gap: 10px; }
        .cat-icon { width: 42px; height: 42px; border-radius: 10px; background: var(--bl); color: var(--b); display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .cat-name { font-size: 14px; font-weight: 700; color: var(--dk); font-family: 'Outfit', sans-serif; }
        .cat-count { font-size: 11px; color: var(--mt); font-weight: 600; }
        .cat-badge-produits { background: var(--bl); color: var(--b); padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .cat-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .cat-stat { background: var(--bg); border-radius: 8px; padding: 10px 12px; }
        .cat-stat .cs-label { font-size: 10px; color: var(--mt); text-transform: uppercase; letter-spacing: .04em; font-weight: 600; margin-bottom: 2px; }
        .cat-stat .cs-value { font-size: 14px; font-weight: 800; color: var(--dk); font-family: 'Outfit', sans-serif; }
        .cat-stat.achat .cs-value { color: var(--dng); }
        .cat-stat.vente .cs-value { color: var(--b); }
        .cat-stat.marge { grid-column: 1 / -1; background: var(--sucl); }
        .cat-stat.marge .cs-value { color: var(--suc); display: flex; align-items: center; justify-content: space-between; }
        .marge-pct { font-size: 11px; background: var(--suc); color: #fff; padding: 2px 8px; border-radius: 12px; font-weight: 700; }
        .cat-stock-info { margin-top: 10px; display: flex; align-items: center; justify-content: space-between; font-size: 11px; color: var(--mt); padding-top: 10px; border-top: 1px dashed var(--brd); }
        .stock-status { display: flex; gap: 10px; }
        .stock-status span { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; }
        .stock-status .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .dot.ok { background: var(--suc); }
        .dot.warn { background: var(--wrn); }
        .dot.out { background: var(--dng); }

        /* ===== FILTRES ===== */
        .pbar { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 16px 20px; margin-bottom: 22px; }
        .prow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .prow label { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .03em; }
        .prow input, .prow select { padding: 9px 12px; border: 1.5px solid var(--brd); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--dk); font-family: 'Inter', sans-serif; transition: all .2s; }
        .prow input:focus, .prow select:focus { border-color: var(--b); background: #fff; box-shadow: 0 0 0 3px var(--bl); outline: none; }
        .prow input[type="text"] { flex: 1; min-width: 220px; }
        .btn-go { background: var(--b); color: #fff; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; border: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all .2s; }
        .btn-go:hover { background: var(--bd); color: #fff; }
        .btn-go-outline { background: transparent; color: var(--mt); border: 1.5px solid var(--brd); padding: 9px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all .2s; }
        .btn-go-outline:hover { background: var(--bg); color: var(--dk); }

        /* ===== TABLEAU HISTORIQUE ===== */
        .data-table-wrap { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); animation: fadeUp .4s ease both; }
        .table-header { background: var(--bg); border-bottom: 1px solid var(--brd); padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; }
        .table-header h5 { font-family: 'Outfit', sans-serif; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px; }
        table { margin: 0; font-size: 13px; }
        table thead th { background: var(--bg); color: var(--mt); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; padding: 10px 14px; border-bottom: 1px solid var(--brd); white-space: nowrap; }
        table tbody td { padding: 11px 14px; border-bottom: 1px solid var(--brd); vertical-align: middle; }
        table tbody tr:hover { background: var(--bl); }
        table tbody tr:last-child td { border-bottom: none; }
        .td-bold { color: var(--dk) !important; font-weight: 700; font-family: 'Outfit', sans-serif; }
        .td-semi { color: var(--dk) !important; font-weight: 500; }
        .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; letter-spacing: .02em; }
        .status-badge.on { background: var(--sucl); color: #059669; border: 1px solid var(--sucb); }
        .status-badge.off { background: var(--dngl); color: #dc2626; border: 1px solid #fecaca; }
        .status-badge.warn { background: var(--wrnl); color: #b45309; border: 1px solid var(--wrnb); }
        .pagination .page-link { border: 1px solid var(--brd); color: var(--mt); font-size: 12px; font-weight: 600; padding: 6px 12px; }
        .pagination .page-item.active .page-link { background: var(--b); color: #fff; border-color: var(--b); }
        .bootstrap-select .dropdown-toggle { background: var(--bg) !important; border: 1.5px solid var(--brd) !important; border-radius: 8px !important; font-size: 13px !important; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width:700px) { 
            body { padding: 14px; } 
            .hdr { flex-direction: column; align-items: flex-start; } 
            .prow { flex-direction: column; align-items: stretch; } 
            .prow .btn-go { width: 100%; justify-content: center; }
            .stats-general { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1><i class="bi bi-clock-history text-primary me-2"></i>Historique des produits</h1>
            <p>Vue d'ensemble du catalogue par catégorie et suivi des stocks</p>
        </div>
        <div>
            <span class="hdr-badge"><i class="bi bi-cube"></i> <?= $initialData['total'] ?> produits</span>
        </div>
    </div>

    <!-- ===== CARTE GÉNÉRALE (style blanc) ===== -->
    <div class="stats-general">
        <div class="sg-item">
            <span class="sg-label"><i class="bi bi-cube"></i> Total produits</span>
            <span class="sg-value"><?= $total_general['total_produits'] ?></span>
            <span class="sg-sub"><?= count($stats_categories) ?> catégorie(s)</span>
        </div>
        <div class="sg-item stock">
            <span class="sg-label"><i class="bi bi-boxes"></i> Stock total</span>
            <span class="sg-value"><?= fmt($total_general['stock_total']) ?></span>
            <span class="sg-sub">unités en stock</span>
        </div>
        <div class="sg-item achat">
            <span class="sg-label"><i class="bi bi-cart-dash"></i> Valeur d'achat</span>
            <span class="sg-value"><?= fmt($total_general['valeur_achat']) ?> F</span>
            <span class="sg-sub">prix fournisseur × stock</span>
        </div>
        <div class="sg-item vente">
            <span class="sg-label"><i class="bi bi-cart-plus"></i> Valeur de vente</span>
            <span class="sg-value"><?= fmt($total_general['valeur_vente']) ?> F</span>
            <span class="sg-sub">prix vente × stock</span>
        </div>
        <div class="sg-item marge">
            <span class="sg-label"><i class="bi bi-graph-up-arrow"></i> Marge bénéficiaire</span>
            <span class="sg-value"><?= fmt($total_general['marge_beneficiaire']) ?> F</span>
            <span class="sg-sub"><?= $pourcentage_marge ?>% de marge globale</span>
        </div>
    </div>

    <!-- ===== CARTES PAR CATÉGORIE (cliquables) ===== -->
    <?php if (!empty($stats_categories)): ?>
    <div class="stats-grid">
        <?php foreach ($stats_categories as $sc): 
            $marge_pct = $sc['valeur_vente'] > 0 ? round(($sc['marge_beneficiaire'] / $sc['valeur_vente']) * 100, 1) : 0;
            $isActive = ($categorie_filter === $sc['categorie']) ? 'active' : '';
        ?>
        <div class="cat-card <?= $isActive ?>" onclick="filterByCategory('<?= e($sc['categorie']) ?>')">
            <div class="cat-card-head">
                <div class="cat-card-title">
                    <div class="cat-icon"><i class="bi bi-tag-fill"></i></div>
                    <div>
                        <div class="cat-name"><?= e($sc['categorie']) ?></div>
                        <div class="cat-count"><?= (int)$sc['total_produits'] ?> produit(s)</div>
                    </div>
                </div>
                <span class="cat-badge-produits"><?= fmt($sc['stock_total']) ?> u.</span>
            </div>
            <div class="cat-stats">
                <div class="cat-stat achat">
                    <div class="cs-label"><i class="bi bi-cart-dash"></i> Valeur achat</div>
                    <div class="cs-value"><?= fmt($sc['valeur_achat']) ?> F</div>
                </div>
                <div class="cat-stat vente">
                    <div class="cs-label"><i class="bi bi-cart-plus"></i> Valeur vente</div>
                    <div class="cs-value"><?= fmt($sc['valeur_vente']) ?> F</div>
                </div>
                <div class="cat-stat marge">
                    <div class="cs-label"><i class="bi bi-graph-up-arrow"></i> Marge bénéficiaire</div>
                    <div class="cs-value">
                        <span><?= fmt($sc['marge_beneficiaire']) ?> F</span>
                        <span class="marge-pct"><?= $marge_pct ?>%</span>
                    </div>
                </div>
            </div>
            <div class="cat-stock-info">
                <span><i class="bi bi-boxes"></i> État du stock :</span>
                <div class="stock-status">
                    <span><span class="dot ok"></span> <?= (int)$sc['produits_disponibles'] ?></span>
                    <span><span class="dot warn"></span> <?= (int)$sc['produits_alerte'] ?></span>
                    <span><span class="dot out"></span> <?= (int)$sc['produits_rupture'] ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ===== FILTRES ===== -->
    <form id="searchForm" class="pbar" method="post" onsubmit="return false;">
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="categorie_filter" id="categorieFilterHidden" value="<?= e($categorie_filter) ?>">
        <div class="prow">
            <label><i class="bi bi-search"></i> Rechercher</label>
            <input type="text" name="search" id="searchInput" placeholder="Code ou titre du produit...">
            <label><i class="bi bi-tag"></i> Catégorie</label>
            <select name="categorie_filter_select" id="categorieFilterSelect" class="selectpicker" data-live-search="true" data-live-search-placeholder="Toutes les catégories...">
                <option value="">Toutes</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= ($categorie_filter === $cat) ? 'selected' : '' ?>><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
            <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
        </div>
    </form>

    <!-- ===== TABLEAU HISTORIQUE ===== -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="table-header">
            <h5>
                <i class="bi bi-list-ul text-primary"></i>
                Historique des produits
                <?php if (!empty($categorie_filter)): ?>
                    <span class="badge bg-primary ms-2"><?= e($categorie_filter) ?></span>
                <?php endif; ?>
            </h5>
            <span class="text-muted small" id="totalCount">
                <?= $initialData['total'] ?> produit(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Titre</th>
                        <th>Photo</th>
                        <th class="text-end">Prix fourn.</th>
                        <th class="text-end">Prix vente</th>
                        <th class="text-end">Bénéfice</th>
                        <th class="text-center">Stock alerte</th>
                        <th class="text-center">Stock</th>
                        <th>Catégorie</th>
                        <th>État</th>
                    </tr>
                </thead>
                <tbody id="tableBody"><?= $initialData['table'] ?></tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $initialData['pagination'] ?></div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker();

    // ============================================================
    // RECHERCHE AJAX (tout en POST)
    // ============================================================
    function rechercher(page) {
        page = page || 1;
        var formData = $('#searchForm').serialize() + '&page=' + page;
        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' produit(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
            },
            error: function(xhr) { 
                console.error(xhr.responseText);
                alert('Erreur lors de la recherche.'); 
            }
        });
    }

    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 400);
    });

    $('#categorieFilterSelect').on('changed.bs.select', function() {
        var val = $(this).val() || '';
        $('#categorieFilterHidden').val(val);
        rechercher(1);
        updateActiveCard(val);
    });

    $('#filterBtn').on('click', function() { rechercher(1); });

    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#categorieFilterSelect').selectpicker('val', '');
        $('#categorieFilterHidden').val('');
        rechercher(1);
        updateActiveCard('');
    });

    // Pagination
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        if (page && page >= 1) rechercher(page);
    });

    // ============================================================
    // FILTRAGE PAR CARTE CATÉGORIE
    // ============================================================
    window.filterByCategory = function(categorie) {
        $('#categorieFilterHidden').val(categorie);
        $('#categorieFilterSelect').selectpicker('val', categorie);
        rechercher(1);
        updateActiveCard(categorie);
    };

    function updateActiveCard(categorie) {
        $('.cat-card').removeClass('active');
        if (categorie) {
            $('.cat-card').each(function() {
                var cardName = $(this).find('.cat-name').text().trim();
                if (cardName === categorie) {
                    $(this).addClass('active');
                }
            });
        }
    }
});
</script>
</body>
</html>