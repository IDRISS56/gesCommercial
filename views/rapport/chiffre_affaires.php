<?php
require 'databases/database.php';
require 'fonctions_rapport.php';
$page_title = "Chiffre d'Affaires";

// Filtres - LIRE AUSSI $_POST pour l'AJAX
$periodesValides = ['journalier', 'hebdomadaire', 'mensuel', 'trimestriel', 'semestriel', 'annuel'];
$periode = $_GET['periode'] ?? $_POST['periode'] ?? 'journalier';
if (!in_array($periode, $periodesValides, true)) {
    $periode = 'journalier';
}

// Valeur par défaut = la période "en cours" selon le type choisi
function valeurParDefaut($periode) {
    switch ($periode) {
        case 'hebdomadaire': return date('o') . '-W' . date('W');
        case 'mensuel':      return date('Y-m');
        case 'trimestriel':  return date('Y') . '-T' . (int)ceil(date('n') / 3);
        case 'semestriel':   return date('Y') . '-S' . (date('n') <= 6 ? 1 : 2);
        case 'annuel':       return date('Y');
        default:             return date('Y-m-d');
    }
}

$valeur = $_GET['valeur'] ?? $_POST['valeur'] ?? valeurParDefaut($periode);

// Construction de la clause WHERE en fonction de la période
$where = "1=1";
if ($periode === 'journalier' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
    $where = "DATE(c.date_commande) = '$valeur'";
} elseif ($periode === 'hebdomadaire' && preg_match('/^(\d{4})-W(\d{2})$/', $valeur, $m)) {
    $anneeSemaine = $m[1] . $m[2];
    $where = "YEARWEEK(c.date_commande, 3) = '$anneeSemaine'";
} elseif ($periode === 'mensuel' && preg_match('/^\d{4}-\d{2}$/', $valeur)) {
    $where = "DATE_FORMAT(c.date_commande, '%Y-%m') = '$valeur'";
} elseif ($periode === 'trimestriel' && preg_match('/^(\d{4})-T([1-4])$/', $valeur, $m)) {
    $where = "YEAR(c.date_commande) = '{$m[1]}' AND QUARTER(c.date_commande) = '{$m[2]}'";
} elseif ($periode === 'semestriel' && preg_match('/^(\d{4})-S([1-2])$/', $valeur, $m)) {
    $moisDebut = ($m[2] == '1') ? 1 : 7;
    $moisFin = ($m[2] == '1') ? 6 : 12;
    $where = "YEAR(c.date_commande) = '{$m[1]}' AND MONTH(c.date_commande) BETWEEN $moisDebut AND $moisFin";
} elseif ($periode === 'annuel' && preg_match('/^\d{4}$/', $valeur)) {
    $where = "YEAR(c.date_commande) = '$valeur'";
} else {
    $valeur = valeurParDefaut($periode);
    switch ($periode) {
        case 'hebdomadaire':
            preg_match('/^(\d{4})-W(\d{2})$/', $valeur, $m);
            $where = "YEARWEEK(c.date_commande, 3) = '{$m[1]}{$m[2]}'";
            break;
        case 'mensuel':
            $where = "DATE_FORMAT(c.date_commande, '%Y-%m') = '$valeur'";
            break;
        case 'trimestriel':
            preg_match('/^(\d{4})-T([1-4])$/', $valeur, $m);
            $where = "YEAR(c.date_commande) = '{$m[1]}' AND QUARTER(c.date_commande) = '{$m[2]}'";
            break;
        case 'semestriel':
            preg_match('/^(\d{4})-S([1-2])$/', $valeur, $m);
            $moisDebut = ($m[2] == '1') ? 1 : 7;
            $moisFin = ($m[2] == '1') ? 6 : 12;
            $where = "YEAR(c.date_commande) = '{$m[1]}' AND MONTH(c.date_commande) BETWEEN $moisDebut AND $moisFin";
            break;
        case 'annuel':
            $where = "YEAR(c.date_commande) = '$valeur'";
            break;
        default:
            $where = "DATE(c.date_commande) = '$valeur'";
    }
}

// Données pour graphique
$caMensuel = $pdo->query("SELECT DATE_FORMAT(date_commande,'%Y-%m') AS mois, COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0) AS ca FROM commande WHERE statut_id='012' AND etat_commande NOT IN ('En attente','Annulé') AND date_commande >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY mois ORDER BY mois ASC")->fetchAll(PDO::FETCH_ASSOC);

// Requête de base pour le tableau
$sqlBase = "SELECT DATE_FORMAT(c.date_commande, '%Y-%m-%d') AS date,
COUNT(*) AS nb_ventes,
COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca
FROM commande c
WHERE c.statut_id='012' AND c.etat_commande NOT IN ('En attente','Annulé') AND $where
GROUP BY DATE_FORMAT(c.date_commande, '%Y-%m-%d')
ORDER BY c.date_commande DESC";

// Pagination
$perPage = 20;
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

$countSql = "SELECT COUNT(DISTINCT DATE_FORMAT(c.date_commande, '%Y-%m-%d'))
FROM commande c
WHERE c.statut_id='012' AND c.etat_commande NOT IN ('En attente','Annulé') AND $where";
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

// Tableau HTML
ob_start();
if (empty($lignes)): ?>
<tr>
    <td colspan="3" class="text-center py-5 text-muted">
        <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>
        Aucune donnée pour cette période
    </td>
</tr>
<?php else: foreach ($lignes as $row): ?>
<tr>
    <td><?= htmlspecialchars($row['date']) ?></td>
    <td><?= (int)$row['nb_ventes'] ?></td>
    <td><strong><?= number_format((float)$row['ca'], 0, ',', ' ') ?> F</strong></td>
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

// Totaux
$totaux = $pdo->query("SELECT COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0) AS total_ca, COUNT(*) AS total_nb FROM commande c WHERE c.statut_id='012' AND c.etat_commande NOT IN ('En attente','Annulé') AND $where")->fetch(PDO::FETCH_ASSOC);
$total_ca = $totaux['total_ca'];
$total_nb = $totaux['total_nb'];
$moyenne = $total_nb ? $total_ca / $total_nb : 0;

if (!function_exists('e')) {
    function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt')) {
    function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }
}

// === GÉNÉRATION DES OPTIONS POUR CHAQUE PÉRIODE (stockées en JS) ===
$optionsParPeriode = [];

// Journalier : 30 derniers jours
$optionsParPeriode['journalier'] = [];
for ($i = 0; $i < 30; $i++) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $optionsParPeriode['journalier'][] = [
        'value' => $d,
        'label' => date('d/m/Y', strtotime($d))
    ];
}

// Hebdomadaire : 12 dernières semaines
$optionsParPeriode['hebdomadaire'] = [];
for ($i = 0; $i < 12; $i++) {
    $ts = strtotime("-$i weeks");
    $val = date('o', $ts) . '-W' . date('W', $ts);
    $lundi = date('d/m', strtotime('monday this week', $ts));
    $dimanche = date('d/m', strtotime('sunday this week', $ts));
    $optionsParPeriode['hebdomadaire'][] = [
        'value' => $val,
        'label' => 'Sem. ' . date('W', $ts) . ' (' . $lundi . ' - ' . $dimanche . ')'
    ];
}

// Mensuel : 12 derniers mois
$optionsParPeriode['mensuel'] = [];
for ($i = 0; $i < 12; $i++) {
    $d = date('Y-m', strtotime("-$i months"));
    $optionsParPeriode['mensuel'][] = [
        'value' => $d,
        'label' => date('m/Y', strtotime($d . '-01'))
    ];
}

// Trimestriel : 8 derniers trimestres
$optionsParPeriode['trimestriel'] = [];
$totalTrimestres = ((int)date('Y')) * 4 + (int)ceil(date('n') / 3) - 1;
for ($i = 0; $i < 8; $i++) {
    $t = $totalTrimestres - $i;
    $annee = intdiv($t, 4);
    $trimestre = ($t % 4) + 1;
    $val = "$annee-T$trimestre";
    $optionsParPeriode['trimestriel'][] = [
        'value' => $val,
        'label' => 'T' . $trimestre . ' ' . $annee
    ];
}

// Semestriel : 6 derniers semestres
$optionsParPeriode['semestriel'] = [];
$totalSemestres = ((int)date('Y')) * 2 + (date('n') <= 6 ? 0 : 1);
for ($i = 0; $i < 6; $i++) {
    $s = $totalSemestres - $i;
    $annee = intdiv($s, 2);
    $semestre = ($s % 2) + 1;
    $val = "$annee-S$semestre";
    $optionsParPeriode['semestriel'][] = [
        'value' => $val,
        'label' => 'S' . $semestre . ' ' . $annee . ' (' . ($semestre == 1 ? 'Jan-Juin' : 'Juil-Déc') . ')'
    ];
}

// Annuel : 5 dernières années
$optionsParPeriode['annuel'] = [];
for ($i = 0; $i < 5; $i++) {
    $a = date('Y') - $i;
    $optionsParPeriode['annuel'][] = [
        'value' => $a,
        'label' => $a
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chiffre d'Affaires</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding: 28px 20px; color: #0f172a; }
.W { max-width: 1400px; margin: 0 auto; }
.hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.hdr h1 { font-size: 26px; font-weight: 800; margin: 0; }
.hdr p { font-size: 13px; color: #64748b; margin: 0; }
.stats-mini { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 12px; margin-bottom: 20px; }
.stat-mini { background: white; border-radius: 8px; padding: 14px 16px; border: 1px solid #e2e8f0; text-align: center; }
.stat-mini .value { font-size: 1.4rem; font-weight: 800; color: #2563eb; }
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
.empty-cell { text-align: center; padding: 30px 16px; color: #94a3b8; }
.pagination .page-link { color: #1e40af; border: 1px solid #e2e8f0; border-radius: 6px; margin: 0 2px; padding: 6px 14px; }
.pagination .page-link:hover { background: #dbeafe; }
.pagination .page-item.active .page-link { background: #1e40af; border-color: #1e40af; color: #fff; }
.pagination .page-item.disabled .page-link { color: #94a3b8; }
.header-badge { background: #eff6ff; border: 1px solid #bfdbfe; color: #2563eb; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; }
.filters { background: white; border-radius: 10px; padding: 16px; border: 1px solid #e2e8f0; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.filters label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin-bottom: 2px; }
.filters select { padding: 7px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.82rem; background: white; }
.filters .btn-filter { background: #2563eb; color: white; border: none; padding: 7px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; }
.filters .btn-filter:hover { background: #1d4ed8; }
.filters a { padding: 7px 14px; border: 1px solid #e2e8f0; border-radius: 6px; text-decoration: none; color: #475569; }
.filters a:hover { background: #f1f5f9; }
@media(max-width:768px){ .filters { flex-direction: column; align-items: stretch; } }
.bootstrap-select .dropdown-toggle .filter-option { color: #0f172a; }
.bootstrap-select .dropdown-menu { border-radius: 6px; border-color: #e2e8f0; }
.bootstrap-select .dropdown-menu .bs-searchbox input {
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    padding: 8px 12px;
}
.bootstrap-select .dropdown-menu .bs-searchbox input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
</style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div><h1>Chiffre d'Affaires</h1><p>Suivi des ventes</p></div>
        <div class="header-badge"><i class="bi bi-cash-stack"></i> CA : <?= fmt($total_ca) ?> F</div>
    </div>

    <!-- Filtres avec selectpicker -->
    <form method="GET" class="filters" id="filterFormMain">
        <input type="hidden" name="iframe" value="1">
        <div>
            <label>Période</label>
            <select name="periode" id="periodeSelect" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher une période...">
                <option value="journalier" <?= $periode=='journalier'?'selected':'' ?>>Journalier</option>
                <option value="hebdomadaire" <?= $periode=='hebdomadaire'?'selected':'' ?>>Hebdomadaire</option>
                <option value="mensuel" <?= $periode=='mensuel'?'selected':'' ?>>Mensuel</option>
                <option value="trimestriel" <?= $periode=='trimestriel'?'selected':'' ?>>Trimestriel</option>
                <option value="semestriel" <?= $periode=='semestriel'?'selected':'' ?>>Semestriel</option>
                <option value="annuel" <?= $periode=='annuel'?'selected':'' ?>>Annuel</option>
            </select>
        </div>
        <div>
            <label>Valeur</label>
            <select name="valeur" id="valeurSelect" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher une valeur...">
                <?php
                // Options initiales selon la période courante
                foreach ($optionsParPeriode[$periode] as $opt):
                ?>
                <option value="<?= e($opt['value']) ?>" <?= $opt['value']==$valeur?'selected':'' ?>><?= e($opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-filter"><i class="bi bi-funnel"></i> Filtrer</button>
        <a href="?iframe=1&periode=journalier&valeur=<?= date('Y-m-d') ?>" class="btn">Reset</a>
    </form>

    <div class="stats-mini">
        <div class="stat-mini"><div class="value"><?= fmt($total_ca) ?> F</div><div class="label">CA Total</div></div>
        <div class="stat-mini"><div class="value"><?= $total_nb ?></div><div class="label">Nb Ventes</div></div>
        <div class="stat-mini"><div class="value"><?= fmt($moyenne) ?> F</div><div class="label">Panier Moyen</div></div>
    </div>

    <div class="chart-card">
        <h4><i class="bi bi-graph-up-arrow"></i> Évolution du CA (12 mois)</h4>
        <canvas id="chartCA" height="150"></canvas>
    </div>

    <div class="report-card">
        <h3><i class="bi bi-list-ul me-2"></i> Détail des ventes <span class="text-muted small"><?= $total ?> jour(s)</span></h3>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Date</th><th>Nb Ventes</th><th>CA</th></tr></thead>
                <tbody id="tableBody"><?= $tableHtml ?></tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $paginationHtml ?></div>
    </div>
</div>

<!-- Formulaire caché pour AJAX - méthode POST -->
<form id="filterForm" style="display:none;">
    <input type="hidden" name="periode" value="<?= e($periode) ?>">
    <input type="hidden" name="valeur" value="<?= e($valeur) ?>">
    <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
    <input type="hidden" name="iframe" value="1">
</form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function() {
    // === OPTIONS PAR PÉRIODE (générées par PHP) ===
    var optionsParPeriode = <?= json_encode($optionsParPeriode, JSON_UNESCAPED_UNICODE) ?>;

    // Initialisation des selectpicker
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    // Graphique
    const ctx = document.getElementById('chartCA')?.getContext('2d');
    if (ctx) {
        const data = <?= json_encode(array_map(fn($r) => floatval($r['ca']), $caMensuel)) ?>;
        const labels = <?= json_encode(array_column($caMensuel, 'mois')) ?>;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'CA (FCFA)',
                    data: data,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.1)',
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

    // === MISE À JOUR DYNAMIQUE DU SELECT VALEUR SELON LA PÉRIODE ===
    function mettreAJourOptionsValeur(periode, valeurSelectionnee) {
        var $select = $('#valeurSelect');
        var options = optionsParPeriode[periode] || [];

        // 1. Détruire le selectpicker
        $select.selectpicker('destroy');

        // 2. Vider et reconstruire les options
        $select.empty();
        options.forEach(function(opt) {
            var selected = (opt.value === valeurSelectionnee) ? ' selected' : '';
            $select.append('<option value="' + opt.value + '"' + selected + '>' + opt.label + '</option>');
        });

        // 3. Recréer le selectpicker
        $select.selectpicker({
            liveSearch: true,
            liveSearchPlaceholder: 'Rechercher une valeur...'
        });

        // 4. Sélectionner la valeur
        if (valeurSelectionnee) {
            $select.selectpicker('val', valeurSelectionnee);
        }
    }

    // Quand on change la période, on met à jour les options du select Valeur
    $('#periodeSelect').on('changed.bs.select', function(e, clickedIndex, isSelected, previousValue) {
        var nouvellePeriode = $(this).val();
        // Valeur par défaut pour la nouvelle période (première option)
        var valeurParDefaut = optionsParPeriode[nouvellePeriode] ? optionsParPeriode[nouvellePeriode][0].value : '';
        mettreAJourOptionsValeur(nouvellePeriode, valeurParDefaut);
        // Soumettre le formulaire pour appliquer le filtre
        setTimeout(function() {
            document.getElementById('filterFormMain').submit();
        }, 100);
    });

    // Quand on change la valeur, on soumet le formulaire
    $('#valeurSelect').on('changed.bs.select', function() {
        document.getElementById('filterFormMain').submit();
    });

    // Fonction AJAX pour la pagination
    function recharger(page) {
        // Mettre à jour les valeurs cachées du formulaire AJAX avec les valeurs actuelles
        $('#filterForm input[name="periode"]').val($('#periodeSelect').val());
        $('#filterForm input[name="valeur"]').val($('#valeurSelect').val());
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

    // Pagination initiale
    $('#paginationContainer .page-link').on('click', function(e) {
        e.preventDefault();
        var p = $(this).data('page');
        if (p) recharger(p);
    });
});
</script>
</body>
</html>