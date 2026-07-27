<?php
require 'databases/database.php';
require 'fonctions_rapport.php';

$page_title = "Marge Bénéficiaire";

// Graphique marge par catégorie
$margeCat = $pdo->query("SELECT cat.titre_categorie, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2)) - (CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2)) * c.quantite_commande)),0) AS marge FROM commande c JOIN produit p ON c.produit_id=p.code_produit LEFT JOIN categorie cat ON p.categorie_id=cat.code_categorie WHERE c.statut_id='012' AND c.etat_commande NOT IN ('En attente','Annulé') GROUP BY cat.code_categorie HAVING marge > 0 ORDER BY marge DESC")->fetchAll(PDO::FETCH_ASSOC);

// Requête de base pour le tableau (pagination manuelle)
$sqlBase = "SELECT p.titre_produit, cat.titre_categorie,
                   AVG(CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2))) AS prix_achat_moyen,
                   AVG(CAST(COALESCE(c.prix_commande,0) AS DECIMAL(12,2))) AS prix_vente_moyen,
                   SUM(c.quantite_commande) AS qte_vendue,
                   COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca,
                   COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2)) - (CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2)) * c.quantite_commande)),0) AS marge_totale
            FROM commande c
            JOIN produit p ON c.produit_id = p.code_produit
            LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
            WHERE c.statut_id='012' AND c.etat_commande NOT IN ('En attente','Annulé')
            GROUP BY p.code_produit
            HAVING qte_vendue > 0
            ORDER BY marge_totale DESC";

// Pagination
$perPage = 20;
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

// Compter le total de produits distincts
$countSql = "SELECT COUNT(DISTINCT p.code_produit) FROM commande c JOIN produit p ON c.produit_id=p.code_produit WHERE c.statut_id='012' AND c.etat_commande NOT IN ('En attente','Annulé')";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute();
$total = $stmtCount->fetchColumn();
$totalPages = ceil($total / $perPage);
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = $sqlBase . " LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Construction du tableau HTML
ob_start();
if (empty($lignes)): ?>
    <tr>
        <td colspan="7" class="text-center py-5 text-muted">
            <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>
            Aucune donnée de marge disponible
        </td>
    </tr>
<?php else: foreach ($lignes as $row):
    $prixAchat = (float)$row['prix_achat_moyen'];
    $prixVente = (float)$row['prix_vente_moyen'];
    $margeTotale = (float)$row['marge_totale'];
    $taux = ($prixAchat > 0) ? round(($margeTotale / ($prixAchat * $row['qte_vendue'])) * 100, 1) : 0;
    ?>
    <tr>
        <td class="td-bold"><?= htmlspecialchars($row['titre_produit']) ?></td>
        <td><?= htmlspecialchars($row['titre_categorie'] ?? '—') ?></td>
        <td><?= (int)$row['qte_vendue'] ?></td>
        <td><?= number_format($prixAchat, 0, ',', ' ') ?> F</td>
        <td><?= number_format($prixVente, 0, ',', ' ') ?> F</td>
        <td><strong><?= number_format($margeTotale, 0, ',', ' ') ?> F</strong></td>
        <td>
            <?php
            if ($taux > 20) {
                $badge = 'badge-success';
            } elseif ($taux > 10) {
                $badge = 'badge-warning';
            } else {
                $badge = 'badge-danger';
            }
            ?>
            <span class="badge <?= $badge ?>"><?= $taux ?>%</span>
        </td>
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
    <title>Marge Bénéficiaire</title>
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
        .badge-warning { background: #ffedd5; color: #9a3412; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .empty-cell { text-align: center; padding: 30px 16px; color: #94a3b8; }
        .pagination .page-link { color: #1e40af; border: 1px solid #e2e8f0; border-radius: 6px; margin: 0 2px; padding: 6px 14px; }
        .pagination .page-link:hover { background: #dbeafe; }
        .pagination .page-item.active .page-link { background: #1e40af; border-color: #1e40af; color: #fff; }
        .pagination .page-item.disabled .page-link { color: #94a3b8; }
        .header-badge { background: #eff6ff; border: 1px solid #bfdbfe; color: #2563eb; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .td-bold { font-weight: 700; color: #0f172a; }
    </style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div><h1>Marge Bénéficiaire</h1><p>Analyse des marges par produit</p></div>
        <div class="header-badge"><i class="bi bi-percent"></i> Produits listés : <?= $total ?></div>
    </div>

    <div class="chart-card">
        <h4><i class="bi bi-bar-chart"></i> Marge par Catégorie</h4>
        <canvas id="chartMarge" height="150"></canvas>
    </div>

    <div class="report-card">
        <h3><i class="bi bi-list-ul me-2"></i> Détail des marges <span class="text-muted small"><?= $total ?> produits</span></h3>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Produit</th><th>Catégorie</th><th>Qte Vendue</th><th>Prix Achat</th><th>Prix Vente</th><th>Marge Totale</th><th>Taux</th></tr></thead>
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
    const ctx = document.getElementById('chartMarge')?.getContext('2d');
    if (ctx) {
        const data = <?= json_encode(array_map(fn($r) => floatval($r['marge']), $margeCat)) ?>;
        const labels = <?= json_encode(array_column($margeCat, 'titre_categorie')) ?>;
        new Chart(ctx, {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: 'Marge (F)', data: data, backgroundColor: 'rgba(5,150,105,0.7)', borderRadius: 4 }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: function(v) { if (v >= 1e6) return (v/1e6).toFixed(1) + 'M'; if (v >= 1e3) return (v/1e3).toFixed(0) + 'k'; return v; } } } } }
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