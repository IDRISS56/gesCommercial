<?php
ob_start();
function sendJson($data)
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// views/caisse/index.php - Gestion des registres de caisse (table `caisses`)
require __DIR__ . '/../../databases/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDec($n) { return number_format(floatval($n), 2, ',', ' '); }

function generateCaisseId($pdo)
{
    $date = date('Ymd');
    $prefix = 'CS-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM caisses WHERE code_caisse LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';

// ------------------------------------------------------------------
// AJOUT / MODIFICATION
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add' || $action === 'edit')) {
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'danger';
    } else {
        $nom_caisse      = trim($_POST['nom_caisse'] ?? '');
        $type_caisse     = trim($_POST['type_caisse'] ?? 'Principale');
        $plafond_maximum = floatval(str_replace(',', '.', $_POST['plafond_maximum'] ?? 0));
        $boutique_id     = trim($_POST['boutique_id'] ?? '') ?: null;
        $solde_actuel    = floatval(str_replace(',', '.', $_POST['solde_actuel'] ?? 0));

        $errors = [];
        if (empty($nom_caisse)) $errors[] = 'Le nom de la caisse est requis.';
        if ($plafond_maximum <= 0) $errors[] = 'Le plafond doit être supérieur à 0.';

        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    $code = generateCaisseId($pdo);
                    $caisse_id = $code; // code_caisse sert aussi d'identifiant lisible
                    $sql = "INSERT INTO caisses (caisse_id, code_caisse, nom_caisse, type_caisse, solde_actuel, plafond_maximum, boutique_id, statut)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'fermee')";
                    $pdo->prepare($sql)->execute([$caisse_id, $code, $nom_caisse, $type_caisse, $solde_actuel, $plafond_maximum, $boutique_id]);
                    $message = "Caisse « $nom_caisse » créée avec succès. Code : $code";
                    $messageType = 'success';
                } elseif ($action === 'edit') {
                    $oldId = $_POST['old_caisse_id'] ?? '';
                    $sql = "UPDATE caisses SET nom_caisse=?, type_caisse=?, plafond_maximum=?, boutique_id=?
                            WHERE caisse_id = ?";
                    $pdo->prepare($sql)->execute([$nom_caisse, $type_caisse, $plafond_maximum, $boutique_id, $oldId]);
                    $message = "Caisse « $nom_caisse » mise à jour.";
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = "Erreur : " . $e->getMessage();
                $messageType = 'danger';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'warning';
        }
    }
}

// ------------------------------------------------------------------
// SUPPRESSION
// ------------------------------------------------------------------
if (isset($_POST['btn_supprimer']) && $_POST['btn_supprimer'] == '1') {
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'danger';
    } else {
        $caisse_id = $_POST['sai_supprimer_id'] ?? '';
        if (!empty($caisse_id)) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'ouverte'");
                $stmt->execute([$caisse_id]);
                if ($stmt->fetchColumn() > 0) {
                    $message = "Impossible de supprimer : une journée de caisse est actuellement ouverte sur ce registre.";
                    $messageType = 'warning';
                } else {
                    $stmt = $pdo->prepare("SELECT nom_caisse FROM caisses WHERE caisse_id = ?");
                    $stmt->execute([$caisse_id]);
                    $nom = $stmt->fetchColumn();
                    $pdo->prepare("DELETE FROM caisses WHERE caisse_id = ?")->execute([$caisse_id]);
                    $message = "Caisse « $nom » supprimée.";
                    $messageType = 'danger';
                }
            } catch (PDOException $e) {
                $message = "Erreur : " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// ------------------------------------------------------------------
// LISTE (avec recherche + pagination, réponse AJAX)
// ------------------------------------------------------------------
function getTableContent($pdo, $search, $boutique_filter, $page, $perPage = 20)
{
    $sql = "SELECT cs.*, b.nom_boutique
            FROM caisses cs
            LEFT JOIN boutique b ON b.code_boutique = cs.boutique_id
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (cs.code_caisse LIKE ? OR cs.nom_caisse LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($boutique_filter)) {
        $sql .= " AND cs.boutique_id = ?";
        $params[] = $boutique_filter;
    }
    $countSql = str_replace("SELECT cs.*, b.nom_boutique", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    $sql .= " ORDER BY cs.code_caisse LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($rows)): ?>
        <tr><td colspan="7" class="text-center py-5 text-muted">Aucune caisse trouvée</td></tr>
    <?php else: foreach ($rows as $c): ?>
        <tr>
            <td class="fw-bold"><?= e($c['code_caisse']) ?></td>
            <td class="fw-semibold"><?= e($c['nom_caisse']) ?></td>
            <td><?= e($c['type_caisse']) ?></td>
            <td class="text-end"><?= fmtDec($c['solde_actuel']) ?> F</td>
            <td class="text-end"><?= fmtDec($c['plafond_maximum']) ?> F</td>
            <td><?= e($c['nom_boutique'] ?? '—') ?></td>
            <td>
                <span class="badge <?= $c['statut'] === 'ouverte' ? 'bg-success' : 'bg-secondary' ?>">
                    <?= $c['statut'] === 'ouverte' ? 'Ouverte' : 'Fermée' ?>
                </span>
            </td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-primary editBtn" data-id="<?= e($c['caisse_id']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger deleteBtn" data-id="<?= e($c['caisse_id']) ?>" data-nom="<?= e($c['nom_caisse']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash3"></i></button>
            </td>
        </tr>
    <?php endforeach; endif;
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center p-3 border-top bg-light">
            <span class="text-muted small">Page <?= $page ?> / <?= $totalPages ?> (<?= $total ?> caisse(s))</span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif;
    $paginationHtml = ob_get_clean();

    return ['table' => $tableHtml, 'pagination' => $paginationHtml, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages];
}

if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $result = getTableContent($pdo, trim($_POST['search'] ?? ''), trim($_POST['boutique_filter'] ?? ''), (int)($_POST['page'] ?? 1));
    sendJson($result);
}

$initialData = getTableContent($pdo, '', '', 1);

$editCaisse = null;
if ($action === 'load_edit' && isset($_POST['edit_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM caisses WHERE caisse_id = ?");
    $stmt->execute([$_POST['edit_id']]);
    $editCaisse = $stmt->fetch(PDO::FETCH_ASSOC);
}
$newCode = generateCaisseId($pdo);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion des caisses</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{background:#f1f5f9;font-family:'Segoe UI',sans-serif;padding:24px;}
.card-app{border:none;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
</style>
</head>
<body>
<div class="container-fluid" style="max-width:1300px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-0"><i class="bi bi-cash-register text-primary"></i> Gestion des caisses</h4>
            <p class="text-muted small mb-0">Registres de caisse (table <code>caisses</code>). Pour ouvrir/fermer une journée, utilisez le menu "Ouverture / Fermeture de caisse".</p>
        </div>
        <button class="btn btn-primary" id="addBtn"><i class="bi bi-plus-lg"></i> Nouvelle caisse</button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show"><?= $message ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card card-app mb-3">
        <div class="card-body py-2">
            <form id="searchForm" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <input type="text" class="form-control" id="searchInput" name="search" placeholder="Rechercher (code, nom)...">
                </div>
                <div class="col-md-4">
                    <select class="form-select" id="boutiqueFilter" name="boutique_filter">
                        <option value="">Toutes les boutiques</option>
                        <?php foreach ($boutiques as $b): ?>
                            <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-app">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th><th>Nom</th><th>Type</th><th class="text-end">Solde actuel</th>
                        <th class="text-end">Plafond</th><th>Boutique</th><th>Statut</th><th></th>
                    </tr>
                </thead>
                <tbody id="tableBody"><?= $initialData['table'] ?></tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $initialData['pagination'] ?></div>
    </div>
</div>

<!-- MODALE AJOUT/EDITION -->
<div class="modal fade" id="caisseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="caisseForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Nouvelle caisse</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="old_caisse_id" id="oldCaisseId" value="">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="mb-3">
                        <label class="form-label">Code caisse</label>
                        <input type="text" class="form-control" id="code_caisse" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nom de la caisse *</label>
                        <input type="text" class="form-control" name="nom_caisse" id="nom_caisse" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type_caisse" id="type_caisse">
                            <option value="Principale">Principale</option>
                            <option value="Secondaire">Secondaire</option>
                            <option value="Mobile">Mobile</option>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Solde de départ</label>
                            <input type="number" step="0.01" class="form-control" name="solde_actuel" id="solde_actuel" value="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Plafond maximum *</label>
                            <input type="number" step="0.01" class="form-control" name="plafond_maximum" id="plafond_maximum" value="1000000" required>
                        </div>
                    </div>
                    <div class="mb-3 mt-2">
                        <label class="form-label">Boutique</label>
                        <select class="form-select" name="boutique_id" id="boutique_id">
                            <option value="">Aucune</option>
                            <?php foreach ($boutiques as $b): ?>
                                <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE SUPPRESSION -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:2.5rem;"></i>
                <p class="mt-3">Supprimer la caisse <strong id="deleteNom"></strong> ?</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button class="btn btn-danger" id="confirmDeleteBtn">Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="btn_supprimer" value="1">
    <input type="hidden" name="sai_supprimer_id" id="deleteFormId">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>
<form method="post" id="editLoadForm" style="display:none;">
    <input type="hidden" name="action" value="load_edit">
    <input type="hidden" name="edit_id" id="editIdField">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function(){
    const modal = new bootstrap.Modal(document.getElementById('caisseModal'));

    $('#addBtn').on('click', function(){
        $('#formAction').val('add');
        $('#oldCaisseId').val('');
        $('#modalTitle').text('Nouvelle caisse');
        $('#caisseForm')[0].reset();
        $('#code_caisse').val('<?= e($newCode) ?>');
        modal.show();
    });

    $(document).on('click', '.editBtn', function(){
        $('#editIdField').val($(this).data('id'));
        $('#editLoadForm').submit();
    });

    $(document).on('click', '.deleteBtn', function(){
        $('#deleteFormId').val($(this).data('id'));
        $('#deleteNom').text($(this).data('nom'));
    });
    $('#confirmDeleteBtn').on('click', function(){ $('#deleteForm').submit(); });

    function rechercher(page){
        page = page || 1;
        $.ajax({
            url: window.location.href, method:'POST',
            data: $('#searchForm').serialize() + '&ajax=1&page=' + page,
            dataType:'json',
            success: function(data){
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
            }
        });
    }
    let t=null;
    $('#searchInput').on('input', function(){ clearTimeout(t); t=setTimeout(()=>rechercher(1),300); });
    $('#boutiqueFilter').on('change', function(){ rechercher(1); });
    $(document).on('click', '.page-link', function(e){ e.preventDefault(); rechercher($(this).data('page')); });

    setTimeout(()=>$('.alert').alert('close'), 5000);

    <?php if ($editCaisse): ?>
    $(function(){
        $('#formAction').val('edit');
        $('#oldCaisseId').val('<?= e($editCaisse['caisse_id']) ?>');
        $('#modalTitle').text('Modifier la caisse');
        $('#code_caisse').val('<?= e($editCaisse['code_caisse']) ?>');
        $('#nom_caisse').val('<?= e($editCaisse['nom_caisse']) ?>');
        $('#type_caisse').val('<?= e($editCaisse['type_caisse']) ?>');
        $('#solde_actuel').val('<?= e($editCaisse['solde_actuel']) ?>').prop('readonly', true);
        $('#plafond_maximum').val('<?= e($editCaisse['plafond_maximum']) ?>');
        $('#boutique_id').val('<?= e($editCaisse['boutique_id']) ?>');
        modal.show();
    });
    <?php endif; ?>
});
</script>
</body>
</html>