<?php
require 'databases/database.php';
require 'fonctions_rapport.php';

$page_title = "Résumé des Achats";

// Stats
$totauxAchats = $pdo->query("SELECT COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0) AS total, COUNT(*) AS nb FROM commande WHERE statut_id='011' AND etat_commande NOT IN ('En attente','Annulé')")->fetch(PDO::FETCH_ASSOC);
$total_achats = $totauxAchats['total'];
$nb_achats = $totauxAchats['nb'];

// Graphiques
$evolAchats = $pdo->query("SELECT DATE_FORMAT(date_commande,'%Y-%m') AS mois, COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0) AS total FROM commande WHERE statut_id='011' AND etat_commande NOT IN ('En attente','Annulé') AND date_commande >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY mois ORDER BY mois ASC")->fetchAll(PDO::FETCH_ASSOC);
$catAchats = $pdo->query("SELECT cat.titre_categorie, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS total FROM commande c LEFT JOIN produit p ON c.produit_id=p.code_produit LEFT JOIN categorie cat ON p.categorie_id=cat.code_categorie WHERE c.statut_id='011' AND c.etat_commande NOT IN ('En attente','Annulé') GROUP BY cat.code_categorie ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);

// Requête de base pour le tableau (pagination manuelle)
$sqlBase = "SELECT c.numero_commande, c.date_commande, c.prix_achat, c.quantite_commande, c.montant_commande, c.etat_commande, c.unite_affichage, c.date_livraison_recue,
                   p.titre_produit, ct.nom_prenom_contact AS fournisseur, b.nom_boutique, cat.titre_categorie
            FROM commande c
            LEFT JOIN produit p ON c.produit_id = p.code_produit
            LEFT JOIN contact ct ON c.contact_id = ct.code_contact
            LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
            LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
            WHERE c.statut_id='011' AND c.etat_commande NOT IN ('En attente','Annulé')
            ORDER BY c.date_commande DESC, c.heure_commande DESC";

// Pagination
$perPage = 20;
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

// Compter le total
$countSql = "SELECT COUNT(*) FROM commande WHERE statut_id='011' AND etat_commande NOT IN ('En attente','Annulé')";
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
        <td colspan="11" class="text-center py-5 text-muted">
            <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>
            Aucun achat trouvé
        </td>
    </tr>
<?php else: foreach ($lignes as $row): ?>
    <tr>
        <td class="td-bold"><?= htmlspecialchars($row['numero_commande']) ?></td>
        <td><?= date('d/m/Y', strtotime($row['date_commande'])) ?></td>
        <td><?= htmlspecialchars($row['fournisseur'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['titre_produit'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['titre_categorie'] ?? '—') ?></td>
        <td><?= (int)$row['quantite_commande'] ?></td>
        <td><?= number_format((float)$row['prix_achat'], 0, ',', ' ') ?> F</td>
        <td><strong><?= number_format((float)$row['montant_commande'], 0, ',', ' ') ?> F</strong></td>
        <td><?= htmlspecialchars($row['nom_boutique'] ?? '—') ?></td>
        <td><?= $row['date_livraison_recue'] ? date('d/m/Y', strtotime($row['date_livraison_recue'])) : '—' ?></td>
        <td>
            <?php
            $etat = $row['etat_commande'];
            if ($etat === 'Reçu' || $etat === 'Validé') {
                $badge = 'badge-success';
            } elseif ($etat === 'En attente') {
                $badge = 'badge-warning';
            } else {
                $badge = 'badge-danger';
            }
            ?>
            <span class="badge <?= $badge ?>"><?= htmlspecialchars($etat) ?></span>
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
    <title>Résumé des Achats</title>
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
        .stat-mini .value { font-size: 1.4rem; font-weight: 800; color: #d97706; }
        .stat-mini .label { font-size: 0.72rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; margin-top: 4px; }
        .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .chart-card { background: white; border-radius: 10px; padding: 16px; border: 1px solid #e2e8f0; }
        .chart-card h4 { font-size: 0.9rem; font-weight: 700; margin-bottom: 12px; }
        .chart-card canvas { height: 250px !important; } /* Augmentation de la hauteur */
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
        .empty-cell { text-align: center; padding: 30px 16px; color: #94a3b8; }
        .pagination .page-link { color: #1e40af; border: 1px solid #e2e8f0; border-radius: 6px; margin: 0 2px; padding: 6px 14px; }
        .pagination .page-link:hover { background: #dbeafe; }
        .pagination .page-item.active .page-link { background: #1e40af; border-color: #1e40af; color: #fff; }
        .pagination .page-item.disabled .page-link { color: #94a3b8; }
        .header-badge { background: #eff6ff; border: 1px solid #bfdbfe; color: #2563eb; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .td-bold { font-weight: 700; color: #0f172a; }
        @media(max-width:768px){ .charts-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div><h1>Résumé des Achats</h1><p>Suivi des approvisionnements</p></div>
        <div class="header-badge"><i class="bi bi-cart-check"></i> Total : <?= fmt($total_achats) ?> F</div>
    </div>

    <div class="stats-mini">
        <div class="stat-mini"><div class="value"><?= fmt($total_achats) ?> F</div><div class="label">Total Achats</div></div>
        <div class="stat-mini"><div class="value"><?= $nb_achats ?></div><div class="label">Nombre Achats</div></div>
        <div class="stat-mini"><div class="value"><?= fmt($nb_achats ? $total_achats/$nb_achats : 0) ?> F</div><div class="label">Panier Moyen</div></div>
    </div>

    <div class="charts-grid">
        <div class="chart-card">
            <h4><i class="bi bi-graph-down"></i> Évolution des Achats (12 mois)</h4>
            <canvas id="chartEvol" height="250"></canvas> <!-- Hauteur augmentée -->
        </div>
        <div class="chart-card">
            <h4><i class="bi bi-pie-chart"></i> Répartition par Catégorie</h4>
            <canvas id="chartCat" height="250"></canvas>
        </div>
    </div>

    <div class="report-card">
        <h3><i class="bi bi-list-ul me-2"></i> Détail des Achats <span class="text-muted small"><?= $total ?> lignes</span></h3>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>N°</th><th>Date</th><th>Fournisseur</th><th>Produit</th><th>Cat.</th><th>Qte</th><th>P.U.</th><th>Montant</th><th>Boutique</th><th>Livr.</th><th>État</th></tr></thead>
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
    // Graphiques
    const ctx1 = document.getElementById('chartEvol')?.getContext('2d');
    if (ctx1) {
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($evolAchats, 'mois')) ?>,
                datasets: [{
                    label: 'Achats (FCFA)',
                    data: <?= json_encode(array_map(fn($r) => floatval($r['total']), $evolAchats)) ?>,
                    borderColor: '#d97706',
                    backgroundColor: 'rgba(217,119,6,0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: {
                        beginAtZero: true,
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
    const ctx2 = document.getElementById('chartCat')?.getContext('2d');
    if (ctx2) {
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($catAchats, 'titre_categorie')) ?>,
                datasets: [{
                    data: <?= json_encode(array_map(fn($r) => floatval($r['total']), $catAchats)) ?>,
                    backgroundColor: ['#2563eb','#d97706','#059669','#7c3aed','#dc2626','#0891b2','#f59e0b']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } }, cutout: '55%' }
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