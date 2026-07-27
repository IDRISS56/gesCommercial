<?php
require 'databases/database.php';
require 'fonctions_rapport.php';

$page_title = "Mouvement de Stock";

// Graphique entrées/sorties mensuelles
$mouvementsMois = $pdo->query("SELECT DATE_FORMAT(date_commande,'%Y-%m') AS mois, SUM(CASE WHEN statut_id IN ('011','009','010','006') THEN quantite_commande ELSE 0 END) AS entrees, SUM(CASE WHEN statut_id IN ('012','008','007','001','002','003','004') THEN quantite_commande ELSE 0 END) AS sorties FROM commande WHERE etat_commande NOT IN ('En attente','Annulé') AND date_commande >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY mois ORDER BY mois ASC")->fetchAll(PDO::FETCH_ASSOC);

// Requête de base pour le tableau (avec pagination manuelle)
$sqlBase = "SELECT c.numero_commande, c.date_commande, c.quantite_commande, c.statut_id, c.etat_commande,
                   p.titre_produit,
                   b.nom_boutique,
                   CASE WHEN c.statut_id IN ('011','009','010','006') THEN 'ENTRÉE' ELSE 'SORTIE' END AS type_mvt
            FROM commande c
            JOIN produit p ON c.produit_id = p.code_produit
            LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
            WHERE c.etat_commande NOT IN ('En attente','Annulé')
            ORDER BY c.date_commande DESC, c.heure_commande DESC";

// Pagination
$perPage = 20;
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

// Compter le total
$countSql = "SELECT COUNT(*) FROM commande c WHERE c.etat_commande NOT IN ('En attente','Annulé')";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute();
$total = $stmtCount->fetchColumn();
$totalPages = ceil($total / $perPage);
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = $sqlBase . " LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Construction du tableau HTML
ob_start();
if (empty($mouvements)): ?>
    <tr>
        <td colspan="7" class="text-center py-5 text-muted">
            <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>
            Aucun mouvement trouvé
        </td>
    </tr>
<?php else: foreach ($mouvements as $row): ?>
    <tr>
        <td><?= htmlspecialchars($row['numero_commande']) ?></td>
        <td><?= htmlspecialchars($row['date_commande']) ?></td>
        <td><?= htmlspecialchars($row['titre_produit']) ?></td>
        <td><?= htmlspecialchars($row['nom_boutique'] ?? '—') ?></td>
        <td><?= (int)$row['quantite_commande'] ?></td>
        <td>
            <?php if ($row['type_mvt'] === 'ENTRÉE'): ?>
                <span class="badge badge-success">ENTRÉE</span>
            <?php else: ?>
                <span class="badge badge-danger">SORTIE</span>
            <?php endif; ?>
        </td>
        <td>
            <?php
            $etat = $row['etat_commande'];
            if ($etat === 'Valider' || $etat === 'Validé') {
                echo '<span class="badge badge-success">' . htmlspecialchars($etat) . '</span>';
            } elseif ($etat === 'Reçu') {
                echo '<span class="badge badge-info">Reçu</span>';
            } else {
                echo '<span class="badge badge-danger">' . htmlspecialchars($etat) . '</span>';
            }
            ?>
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mouvement de Stock</title>
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
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #e0f2fe; color: #075985; }
        .empty-cell { text-align: center; padding: 30px 16px; color: #94a3b8; }
        .pagination .page-link { color: #1e40af; border: 1px solid #e2e8f0; border-radius: 6px; margin: 0 2px; padding: 6px 14px; }
        .pagination .page-link:hover { background: #dbeafe; }
        .pagination .page-item.active .page-link { background: #1e40af; border-color: #1e40af; color: #fff; }
        .pagination .page-item.disabled .page-link { color: #94a3b8; }
        .header-badge { background: #eff6ff; border: 1px solid #bfdbfe; color: #2563eb; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; }
    </style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div><h1>Mouvement de Stock</h1><p>Traçabilité des entrées/sorties</p></div>
        <div class="header-badge"><i class="bi bi-arrow-left-right"></i> <?= $total ?> mouvements</div>
    </div>

    <div class="chart-card">
        <h4><i class="bi bi-bar-chart"></i> Entrées / Sorties mensuelles</h4>
        <canvas id="chartStock" height="150"></canvas>
    </div>

    <div class="report-card">
        <h3><i class="bi bi-list-ul me-2"></i> Derniers mouvements <span class="text-muted small"><?= $total ?> lignes</span></h3>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>N°</th><th>Date</th><th>Produit</th><th>Boutique</th><th>Quantité</th><th>Type</th><th>État</th></tr></thead>
                <tbody id="tableBody"><?= $tableHtml ?></tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $paginationHtml ?></div>
    </div>
</div>

<form id="filterForm" style="display:none;"><input type="hidden" name="page" id="pageInput" value="<?= $page ?>"></form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    const ctx = document.getElementById('chartStock')?.getContext('2d');
    if (ctx) {
        const data = <?= json_encode($mouvementsMois) ?>;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(d => d.mois),
                datasets: [
                    { label: 'Entrées', data: data.map(d => parseInt(d.entrees) || 0), backgroundColor: 'rgba(5,150,105,0.7)', borderRadius: 4 },
                    { label: 'Sorties', data: data.map(d => parseInt(d.sorties) || 0), backgroundColor: 'rgba(220,38,38,0.7)', borderRadius: 4 }
                ]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
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