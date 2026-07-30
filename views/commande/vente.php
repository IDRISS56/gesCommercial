<?php
// views/commande/vente.php - SUIVI DES VENTES (écran de consultation SEUL)
//
// La création de ventes ne se fait plus ici : elle est centralisée dans
// publics/vente_comptoir.php (menu "Vente au Comptoir"), seul point
// d'entrée autorisé pour créer une vente. Cette page ne fait que lire
// ce qui a déjà été validé au comptoir (table `facture` type Client +
// lignes `commande`) — aucun INSERT / UPDATE / DELETE ici.
ob_start();
function sendJson($data)
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

require __DIR__ . '/../../databases/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}
$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { session_destroy(); header('Location: ../utilisateur/login'); exit; }

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }

// ------------------------------------------------------------------
// LISTE DES VENTES (lecture seule) avec recherche + filtre date + pagination
// ------------------------------------------------------------------
function getVentes($pdo, $search, $dateDebut, $dateFin, $etat, $page, $perPage = 20)
{
    $sql = "SELECT f.numero_facture, f.titre_facture, f.date_facture, f.montant_ht, f.montant_ttc,
                   f.avance, f.reste, f.etat_facture, f.categorie_facture,
                   c.nom_prenom_contact, u.nom_prenom AS vendeur
            FROM facture f
            LEFT JOIN contact c ON c.code_contact = f.contact_id
            LEFT JOIN utilisateur u ON u.id = f.utilisateur_id
            WHERE f.type_facture = 'Client' AND f.categorie_facture <> 'Avoir'";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (f.numero_facture LIKE ? OR c.nom_prenom_contact LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%";
    }
    if (!empty($dateDebut)) { $sql .= " AND f.date_facture >= ?"; $params[] = $dateDebut; }
    if (!empty($dateFin))   { $sql .= " AND f.date_facture <= ?"; $params[] = $dateFin; }
    if (!empty($etat))      { $sql .= " AND f.etat_facture = ?"; $params[] = $etat; }

    $countSql = preg_replace('/^SELECT.*?FROM/s', 'SELECT COUNT(*) FROM', $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPages = max(1, ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    if ($page < 1) $page = 1;

    $sql .= " ORDER BY f.date_facture DESC, f.numero_facture DESC LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($rows)): ?>
        <tr><td colspan="8" class="text-center text-muted py-5">Aucune vente trouvée pour ces critères.</td></tr>
    <?php else: foreach ($rows as $r): ?>
        <tr>
            <td class="fw-bold"><?= e($r['numero_facture']) ?></td>
            <td><?= date('d/m/Y', strtotime($r['date_facture'])) ?></td>
            <td><?= e($r['nom_prenom_contact'] ?? '—') ?></td>
            <td><?= e($r['vendeur'] ?? '—') ?></td>
            <td class="text-end"><?= fmt($r['montant_ttc']) ?> F</td>
            <td class="text-end"><?= fmt($r['avance']) ?> F</td>
            <td class="text-end <?= $r['reste'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= fmt($r['reste']) ?> F</td>
            <td>
                <span class="badge <?= $r['etat_facture'] === 'Payée' || $r['etat_facture'] === 'Payer cash' ? 'bg-success' : ($r['etat_facture'] === 'Partielle' || $r['etat_facture'] === 'Credit' ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                    <?= e($r['etat_facture']) ?>
                </span>
            </td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-primary detailBtn" data-facture="<?= e($r['numero_facture']) ?>">
                    <i class="bi bi-eye"></i> Détail
                </button>
            </td>
        </tr>
    <?php endforeach; endif;
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center p-3 border-top bg-light">
            <span class="text-muted small">Page <?= $page ?> / <?= $totalPages ?> (<?= $total ?> vente(s))</span>
            <nav><ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a></li>
                <?php endfor; ?>
            </ul></nav>
        </div>
    <?php endif;
    $paginationHtml = ob_get_clean();

    // Statistiques globales sur le filtre courant
    $statSql = preg_replace('/^SELECT.*?FROM/s',
        'SELECT COUNT(*) AS nb, COALESCE(SUM(f.montant_ttc),0) AS ca, COALESCE(SUM(f.reste),0) AS impaye FROM', $sql);
    // retirer le LIMIT ajouté plus haut pour les stats globales
    $statSqlBase = "SELECT COUNT(*) AS nb, COALESCE(SUM(f.montant_ttc),0) AS ca, COALESCE(SUM(f.reste),0) AS impaye
                    FROM facture f
                    LEFT JOIN contact c ON c.code_contact = f.contact_id
                    WHERE f.type_facture = 'Client' AND f.categorie_facture <> 'Avoir'";
    $statParams = [];
    if (!empty($search)) { $statSqlBase .= " AND (f.numero_facture LIKE ? OR c.nom_prenom_contact LIKE ?)"; $statParams[]="%$search%"; $statParams[]="%$search%"; }
    if (!empty($dateDebut)) { $statSqlBase .= " AND f.date_facture >= ?"; $statParams[] = $dateDebut; }
    if (!empty($dateFin))   { $statSqlBase .= " AND f.date_facture <= ?"; $statParams[] = $dateFin; }
    if (!empty($etat))      { $statSqlBase .= " AND f.etat_facture = ?"; $statParams[] = $etat; }
    $stmt = $pdo->prepare($statSqlBase);
    $stmt->execute($statParams);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    return ['table' => $tableHtml, 'pagination' => $paginationHtml, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages, 'stats' => $stats];
}

if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $result = getVentes(
        $pdo,
        trim($_POST['search'] ?? ''),
        trim($_POST['date_debut'] ?? ''),
        trim($_POST['date_fin'] ?? ''),
        trim($_POST['etat'] ?? ''),
        (int)($_POST['page'] ?? 1)
    );
    sendJson($result);
}

// Détail d'une vente (lignes de commande) - lecture seule
if (isset($_POST['detail_facture'])) {
    $numero = $_POST['detail_facture'];
    $stmt = $pdo->prepare("SELECT cm.*, p.titre_produit
            FROM commande cm
            LEFT JOIN produit p ON p.code_produit = cm.produit_id
            WHERE cm.facture_id = ?
            ORDER BY cm.numero_commande");
    $stmt->execute([$numero]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    sendJson(['success' => true, 'lignes' => $lignes]);
}

$initial = getVentes($pdo, '', date('Y-m-01'), date('Y-m-d'), '', 1);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Suivi des ventes</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{background:#f1f5f9;font-family:'Segoe UI',sans-serif;padding:24px;}
.card-app{border:none;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.stat-card{border-radius:14px;padding:16px 20px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.stat-card .val{font-size:24px;font-weight:800;}
</style>
</head>
<body>
<div class="container-fluid" style="max-width:1400px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-0"><i class="bi bi-graph-up text-primary"></i> Suivi des ventes</h4>
            <p class="text-muted small mb-0">
                Écran de consultation uniquement. Pour créer une vente, utilisez
                <strong>Vente au Comptoir</strong>.
            </p>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="stat-card"><div class="val" id="statNb"><?= (int)$initial['stats']['nb'] ?></div><small class="text-muted">Ventes sur la période</small></div>
        </div>
        <div class="col-md-4">
            <div class="stat-card"><div class="val text-success" id="statCa"><?= fmt($initial['stats']['ca']) ?> F</div><small class="text-muted">Chiffre d'affaires</small></div>
        </div>
        <div class="col-md-4">
            <div class="stat-card"><div class="val text-danger" id="statImpaye"><?= fmt($initial['stats']['impaye']) ?> F</div><small class="text-muted">Restant impayé</small></div>
        </div>
    </div>

    <div class="card card-app mb-3">
        <div class="card-body py-2">
            <form id="searchForm" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <input type="text" class="form-control" name="search" id="searchInput" placeholder="N° facture ou client...">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_debut" id="dateDebut" value="<?= date('Y-m-01') ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_fin" id="dateFin" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="etat" id="etatFilter">
                        <option value="">Tous les états</option>
                        <option value="Payée">Payée</option>
                        <option value="Payer cash">Payer cash</option>
                        <option value="Partielle">Partielle</option>
                        <option value="Credit">Crédit</option>
                        <option value="Impayée">Impayée</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="button" id="resetBtn" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-app">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>N° Facture</th><th>Date</th><th>Client</th><th>Vendeur</th>
                        <th class="text-end">Total TTC</th><th class="text-end">Payé</th><th class="text-end">Reste</th><th>État</th><th></th>
                    </tr>
                </thead>
                <tbody id="tableBody"><?= $initial['table'] ?></tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $initial['pagination'] ?></div>
    </div>
</div>

<!-- MODALE DÉTAIL -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Détail de la vente <span id="detailNumero"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm">
                    <thead><tr><th>Produit</th><th class="text-end">Qté</th><th class="text-end">Unité</th><th class="text-end">PU</th><th class="text-end">Montant</th></tr></thead>
                    <tbody id="detailLignes"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function(){
    function rechercher(page){
        page = page || 1;
        $.ajax({
            url: window.location.href, method:'POST',
            data: $('#searchForm').serialize() + '&ajax=1&page=' + page,
            dataType:'json',
            success: function(data){
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#statNb').text(data.stats.nb);
                $('#statCa').text(Number(data.stats.ca).toLocaleString('fr-FR') + ' F');
                $('#statImpaye').text(Number(data.stats.impaye).toLocaleString('fr-FR') + ' F');
            }
        });
    }
    let t=null;
    $('#searchInput').on('input', function(){ clearTimeout(t); t=setTimeout(()=>rechercher(1),300); });
    $('#dateDebut,#dateFin,#etatFilter').on('change', function(){ rechercher(1); });
    $('#resetBtn').on('click', function(){
        $('#searchInput').val('');
        $('#dateDebut').val('<?= date('Y-m-01') ?>');
        $('#dateFin').val('<?= date('Y-m-d') ?>');
        $('#etatFilter').val('');
        rechercher(1);
    });
    $(document).on('click', '.page-link', function(e){ e.preventDefault(); rechercher($(this).data('page')); });

    const detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
    $(document).on('click', '.detailBtn', function(){
        const numero = $(this).data('facture');
        $('#detailNumero').text(numero);
        $.post(window.location.href, { detail_facture: numero }, function(data){
            let html = '';
            (data.lignes || []).forEach(function(l){
                html += '<tr><td>' + (l.titre_produit || l.produit_id) + '</td>' +
                        '<td class="text-end">' + l.quantite_commande + '</td>' +
                        '<td class="text-end">' + (l.unite_affichage || '') + '</td>' +
                        '<td class="text-end">' + Number(l.prix_commande).toLocaleString('fr-FR') + '</td>' +
                        '<td class="text-end">' + Number(l.montant_commande).toLocaleString('fr-FR') + '</td></tr>';
            });
            if (!html) html = '<tr><td colspan="5" class="text-center text-muted">Aucune ligne.</td></tr>';
            $('#detailLignes').html(html);
            detailModal.show();
        }, 'json');
    });
});
</script>
</body>
</html>