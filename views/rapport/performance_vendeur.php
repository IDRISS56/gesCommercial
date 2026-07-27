<?php
require 'databases/database.php';
require 'fonctions_rapport.php';

$page_title = "Performance des Vendeurs";

// Stats globales
$stats = $pdo->query("SELECT COUNT(DISTINCT u.id) AS nb_vendeurs, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca_total FROM utilisateur u LEFT JOIN commande c ON u.id=c.utilisateur_id AND c.statut_id='012' AND c.etat_commande NOT IN ('En attente','Annulé') WHERE u.etat='Actif' AND u.role IN ('VENDEUR','CAISSIER','Admin')")->fetch(PDO::FETCH_ASSOC);
$nbVendeurs = $stats['nb_vendeurs'] ?? 0;
$caTotal = $stats['ca_total'] ?? 0;

// Requête de base pour le tableau (pagination manuelle)
$sqlBase = "SELECT u.id, u.nom_prenom, u.role,
                   COUNT(DISTINCT c.numero_commande) AS nb_ventes,
                   COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca_total,
                   COALESCE(SUM(CAST(c.prix_achat AS DECIMAL(12,2)) * c.quantite_commande),0) AS cout_total,
                   COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2)) - (CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2)) * c.quantite_commande)),0) AS marge_totale,
                   MAX(c.date_commande) AS derniere_vente
            FROM utilisateur u
            LEFT JOIN commande c ON u.id = c.utilisateur_id AND c.statut_id='012' AND c.etat_commande NOT IN ('En attente','Annulé')
            WHERE u.etat='Actif' AND u.role IN ('VENDEUR','CAISSIER','Admin')
            GROUP BY u.id
            ORDER BY ca_total DESC";

// Pagination
$perPage = 20;
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

// Compter le total de vendeurs
$countSql = "SELECT COUNT(DISTINCT u.id) FROM utilisateur u WHERE u.etat='Actif' AND u.role IN ('VENDEUR','CAISSIER','Admin')";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute();
$total = $stmtCount->fetchColumn();
$totalPages = ceil($total / $perPage);
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = $sqlBase . " LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$vendeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Construction du tableau HTML
ob_start();
if (empty($vendeurs)): ?>
    <tr>
        <td colspan="6" class="text-center py-5 text-muted">
            <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>
            Aucun vendeur trouvé
        </td>
    </tr>
<?php else: foreach ($vendeurs as $row): ?>
    <tr>
        <td class="td-bold"><?= htmlspecialchars($row['nom_prenom']) ?></td>
        <td><?= htmlspecialchars($row['role']) ?></td>
        <td><?= (int)$row['nb_ventes'] ?></td>
        <td><strong><?= number_format((float)$row['ca_total'], 0, ',', ' ') ?> F</strong></td>
        <td><?= number_format((float)$row['marge_totale'], 0, ',', ' ') ?> F</td>
        <td><?= $row['derniere_vente'] ? date('d/m/Y', strtotime($row['derniere_vente'])) : '—' ?></td>
    </tr>
<?php endforeach; endif;
$tableHtml = ob_get_clean();

// Pagination HTML
ob_start();
if ($totalPages > 1): ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
        <span class="text-muted small">Affichage de <?= ($offset + 1) ?> à <?= min($offset + $perPage, $total) ?> sur <?= $total ?></span>
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

// Gestion AJAX
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'table' => $tableHtml,
        'pagination' => $paginationHtml,
        'total' => $total,
        'page' => $page,
        'totalPages' => $totalPages
    ]);
    exit;
}

// Fonctions e() et fmt() si elles ne sont pas définies
if (!function_exists('e')) {
    function e($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('fmt')) {
    function fmt($n) {
        return number_format(floatval($n), 0, ',', ' ');
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance des Vendeurs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding: 28px 20px; color: #0f172a; }
        .W { max-width: 1400px; margin: 0 auto; }
        .hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .hdr h1 { font-size: 26px; font-weight: 800; margin: 0; }
        .hdr p { font-size: 13px; color: #64748b; margin: 0; }
        .stats-mini { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 12px; margin-bottom: 20px; }
        .stat-mini { background: white; border-radius: 8px; padding: 14px 16px; border: 1px solid #e2e8f0; text-align: center; }
        .stat-mini .value { font-size: 1.4rem; font-weight: 800; color: #7c3aed; }
        .stat-mini .label { font-size: 0.72rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; margin-top: 4px; }
        .chart-card { background: white; border-radius: 10px; padding: 16px; border: 1px solid #e2e8f0; margin-bottom: 20px; }
        .chart-card h4 { font-size: 0.9rem; font-weight: 700; margin-bottom: 12px; }
        .report-card { background: white; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; padding: 20px 24px; margin-bottom: 20px; }
        .report-card h3 { font-size: 1rem; font-weight: 700; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .table-wrapper { overflow-x: auto; border-radius: 8px; border: 1px solid #e2e8f0; background: white; }
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        thead { background: #1e3a8a; color: white; }
        th { padding: 10px 14px; text-align: left; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.4px; }
        td { padding: 9px 14px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        tbody tr:hover { background: #dbeafe; }
        .badge { padding: 3px 9px; border-radius: 16px; font-size: 0.68rem; font-weight: 700; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-info { background: #e0f2fe; color: #075985; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .empty-cell { text-align: center; padding: 30px 16px; color: #94a3b8; }
        .pagination .page-link { color: #1e40af; border: 1px solid #e2e8f0; border-radius: 6px; margin: 0 2px; padding: 6px 14px; }
        .pagination .page-link:hover { background: #dbeafe; }
        .pagination .page-item.active .page-link { background: #1e40af; border-color: #1e40af; color: #fff; }
        .pagination .page-item.disabled .page-link { color: #94a3b8; }
        .header-badge { background: #eff6ff; border: 1px solid #bfdbfe; color: #2563eb; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .td-bold { font-weight: 700; color: #0f172a; }
        @media(max-width:768px){ .chart-card { padding:12px; } }
    </style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div><h1>Performance des Vendeurs</h1><p>Analyse par vendeur</p></div>
        <div class="header-badge"><i class="bi bi-people"></i> <?= $nbVendeurs ?> vendeurs actifs</div>
    </div>

    <div class="stats-mini">
        <div class="stat-mini"><div class="value"><?= $nbVendeurs ?></div><div class="label">Vendeurs Actifs</div></div>
        <div class="stat-mini"><div class="value"><?= fmt($caTotal) ?> F</div><div class="label">CA Total</div></div>
        <div class="stat-mini"><div class="value"><?= $total ?></div><div class="label">Vendeurs listés</div></div>
    </div>

    <div class="chart-card">
        <h4><i class="bi bi-bar-chart"></i> Top Vendeurs (CA)</h4>
        <canvas id="chartVendeurs" height="150"></canvas>
    </div>

    <div class="report-card">
        <h3><i class="bi bi-list-ul me-2"></i> Détail des Vendeurs <span class="text-muted small"><?= $total ?> lignes</span></h3>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Vendeur</th><th>Rôle</th><th>Nb Ventes</th><th>CA</th><th>Marge</th><th>Dernière Vente</th></tr></thead>
                <tbody id="tableBody"><?= $tableHtml ?></tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $paginationHtml ?></div>
    </div>
</div>

<!-- Formulaire caché pour AJAX -->
<form id="filterForm" style="display:none;">
    <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
</form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Récupération de tous les vendeurs pour le graphique (limité à 50 pour performance)
    const allVendeurs = <?php 
        $all = $pdo->query($sqlBase . " LIMIT 0, 50")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($all);
    ?>;
    const ctx = document.getElementById('chartVendeurs')?.getContext('2d');
    if (ctx && allVendeurs.length) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: allVendeurs.map(v => v.nom_prenom),
                datasets: [{
                    label: 'CA (FCFA)',
                    data: allVendeurs.map(v => parseFloat(v.ca_total)),
                    backgroundColor: 'rgba(124,58,237,0.7)',
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        ticks: {
                            callback: function(value) {
                                if (value >= 1e6) return (value/1e6).toFixed(1) + 'M';
                                if (value >= 1e3) return (value/1e3).toFixed(0) + 'k';
                                return value;
                            }
                        }
                    }
                }
            }
        });
    }

    function recharger(page) {
        $('#pageInput').val(page);
        var fd = new FormData(document.getElementById('filterForm'));
        fd.append('ajax', '1');
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#paginationContainer .page-link').off('click').on('click', function(e) {
                    e.preventDefault();
                    var p = $(this).data('page');
                    if (p) recharger(p);
                });
            },
            error: function() { alert('Erreur lors du chargement des données.'); }
        });
    }
    $('#paginationContainer .page-link').on('click', function(e) {
        e.preventDefault();
        var p = $(this).data('page');
        if (p) recharger(p);
    });
});
</script>
</body>
</html>