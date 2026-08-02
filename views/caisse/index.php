<?php
ob_start();
function sendJson($data){
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data); exit;
}

require __DIR__ . '/../../databases/database.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../utilisateur/login'); exit; }

$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { session_destroy(); header('Location: ../utilisateur/login'); exit; }

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$message = '';
$messageType = '';

// Boutiques pour filtre/select
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// FONCTIONS UTILITAIRES
// ============================================================
function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDec($n) { return number_format((float)$n, 0, ',', ' '); }
function generateCaisseId($pdo) {
    $prefix = 'CS-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT caisse_id FROM caisse WHERE caisse_id LIKE ? ORDER BY caisse_id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    if ($last) {
        $num = (int)substr($last, strrpos($last, '-') + 1) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// ============================================================
// LISTE (AJAX)
// ============================================================
function getTableContent($pdo, $search, $boutique_filter, $page, $perPage = 20) {
    $sql = "SELECT c.*, b.nom_boutique
            FROM caisse c
            LEFT JOIN boutique b ON b.code_boutique = c.boutique_id
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (c.caisse_id LIKE ? OR c.nom_caisse LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like;
    }
    if (!empty($boutique_filter)) {
        $sql .= " AND c.boutique_id = ?";
        $params[] = $boutique_filter;
    }

    $countSql = str_replace("SELECT c.*, b.nom_boutique", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql); $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $sql .= " ORDER BY c.caisse_id LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($rows)):
?>
    <tr><td colspan="7" class="text-center py-5 text-muted">Aucune caisse trouvée</td></tr>
<?php else: foreach ($rows as $c):
    $estOuverte = ($c['statut'] === 'Actif');
    // Vérifier s'il y a une journée ouverte
    $stmtJ = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'OUVERTE'");
    $stmtJ->execute([$c['caisse_id']]);
    $journeeOuverte = $stmtJ->fetchColumn() > 0;
?>
    <tr>
        <td class="td-bold"><?= e($c['caisse_id']) ?></td>
        <td class="td-semi"><?= e($c['nom_caisse']) ?></td>
        <td class="text-end td-bold"><?= fmtDec($c['solde']) ?> F</td>
        <td><?= e($c['nom_boutique'] ?? '—') ?></td>
        <td>
            <?php if ($journeeOuverte): ?>
                <span class="status-badge status-active"><i class="bi bi-circle-fill"></i> Journée ouverte</span>
            <?php else: ?>
                <span class="status-badge <?= $estOuverte ? 'status-active' : 'status-inactive' ?>">
                    <i class="bi bi-<?= $estOuverte ? 'check-circle' : 'x-circle' ?>"></i>
                    <?= $estOuverte ? 'Actif' : 'Inactif' ?>
                </span>
            <?php endif; ?>
        </td>
        <td class="text-end">
            <button class="btn-icon editBtn" data-id="<?= e($c['caisse_id']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
            <button class="btn-icon deleteBtn" data-id="<?= e($c['caisse_id']) ?>" data-nom="<?= e($c['nom_caisse']) ?>" title="Supprimer"><i class="bi bi-trash3"></i></button>
        </td>
    </tr>
<?php endforeach; endif;
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1):
?>
    <div class="d-flex flex-wrap justify-content-between align-items-center p-3 border-top" style="background:var(--bg);">
        <small class="text-muted" id="totalCount"><?= $total ?> caisse(s) — Page <?= $page ?> / <?= max(1, $totalPages) ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?> <?= $i == $page ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
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
        'totalPages' => max(1, $totalPages)
    ];
}

// ============================================================
// TRAITEMENT AJAX (RECHERCHE)
// ============================================================
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $result = getTableContent($pdo, trim($_POST['search'] ?? ''), trim($_POST['boutique_filter'] ?? ''), (int)($_POST['page'] ?? 1));
    sendJson($result);
}

// ============================================================
// AJOUT / MODIFICATION
// ============================================================
$initialData = getTableContent($pdo, '', '', 1);
$editCaisse = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'load_edit' && isset($_POST['edit_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM caisse WHERE caisse_id = ?");
        $stmt->execute([$_POST['edit_id']]);
        $editCaisse = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (isset($_POST['btn_enregistrer'])) {
        $token = $_POST['csrf_token'] ?? '';
        $oldId = $_POST['old_caisse_id'] ?? '';

        if ($token !== $csrf_token) {
            $message = "Token invalide."; $messageType = 'danger';
        } else {
            $nom_caisse = trim($_POST['nom_caisse'] ?? '');
            $boutique_id = trim($_POST['boutique_id'] ?? '') ?: null;
            $solde = floatval(str_replace(',', '.', $_POST['solde'] ?? 0));
            $statut = trim($_POST['statut'] ?? 'Actif');

            $errors = [];
            if (empty($nom_caisse)) $errors[] = 'Le nom est requis.';
            if (!in_array($statut, ['Actif', 'Inactif'])) $errors[] = 'Statut invalide.';

            if (empty($errors)) {
                try {
                    if (empty($oldId)) {
                        // AJOUT
                        $newId = generateCaisseId($pdo);
                        $pdo->prepare("INSERT INTO caisse (caisse_id, nom_caisse, solde, boutique_id, statut) VALUES (?, ?, ?, ?, ?)")
                            ->execute([$newId, $nom_caisse, $solde, $boutique_id, $statut]);
                        $message = "Caisse « $nom_caisse » créée avec succès."; $messageType = 'success';
                    } else {
                        // MODIFICATION
                        $pdo->prepare("UPDATE caisse SET nom_caisse=?, boutique_id=?, solde=?, statut=? WHERE caisse_id = ?")
                            ->execute([$nom_caisse, $boutique_id, $solde, $statut, $oldId]);
                        $message = "Caisse « $nom_caisse » mise à jour."; $messageType = 'success';
                    }
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage(); $messageType = 'danger';
                }
            } else {
                $message = implode('<br>', $errors); $messageType = 'warning';
            }
        }
        $initialData = getTableContent($pdo, '', '', 1);
    }

    // ============================================================
    // SUPPRESSION
    // ============================================================
    if (isset($_POST['btn_supprimer']) && $_POST['btn_supprimer'] == '1') {
        $token = $_POST['csrf_token'] ?? '';
        if ($token !== $csrf_token) {
            $message = "Token invalide."; $messageType = 'danger';
        } else {
            $caisse_id = $_POST['sai_supprimer_id'] ?? '';
            if (!empty($caisse_id)) {
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'OUVERTE'");
                    $stmt->execute([$caisse_id]);
                    if ($stmt->fetchColumn() > 0) {
                        $message = "Impossible : une journée est ouverte sur cette caisse."; $messageType = 'warning';
                    } else {
                        $stmt = $pdo->prepare("SELECT nom_caisse FROM caisse WHERE caisse_id = ?");
                        $stmt->execute([$caisse_id]);
                        $nom = $stmt->fetchColumn();
                        $pdo->prepare("DELETE FROM caisse WHERE caisse_id = ?")->execute([$caisse_id]);
                        $message = "Caisse « $nom » supprimée."; $messageType = 'danger';
                    }
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage(); $messageType = 'danger';
                }
            }
        }
        $initialData = getTableContent($pdo, '', '', 1);
    }
}

$newCode = generateCaisseId($pdo);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des caisses</title>
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
            --R: 16px; --Rs: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--dk); min-height: 100vh; padding: 28px 20px; }
        .W { max-width: 1300px; margin: 0 auto; }
        .hdr { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--dk); letter-spacing: -0.02em; font-family: 'Outfit', sans-serif; }
        .hdr-l p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
        .hdr-badge { background: var(--bl); border: 1px solid var(--bb); color: var(--b); padding: 8px 14px; border-radius: var(--Rs); font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .btn-go { background: var(--b); color: #fff; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; border: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all .2s; }
        .btn-go:hover { background: var(--bd); color: #fff; }
        .btn-go-outline { background: transparent; color: var(--mt); border: 1.5px solid var(--brd); padding: 9px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all .2s; }
        .btn-go-outline:hover { background: var(--bg); color: var(--dk); }
        .pbar { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 16px 20px; margin-bottom: 22px; }
        .prow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .prow label { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .03em; }
        .prow input, .prow select { flex: 1; min-width: 180px; padding: 9px 12px; border: 1.5px solid var(--brd); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--dk); font-family: 'Inter', sans-serif; transition: all .2s; }
        .prow input:focus, .prow select:focus { border-color: var(--b); background: #fff; box-shadow: 0 0 0 3px var(--bl); outline: none; }
        .data-table-wrap { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        table { margin: 0; font-size: 13px; }
        table thead th { background: var(--bg); color: var(--mt); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 12px 16px; border-bottom: 1px solid var(--brd); }
        table tbody td { padding: 14px 16px; border-bottom: 1px solid var(--brd); vertical-align: middle; }
        table tbody tr:hover { background: var(--bl); }
        .td-bold { color: var(--dk) !important; font-weight: 700; }
        .td-semi { color: var(--dk) !important; font-weight: 500; }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: .02em; }
        .status-active { background: var(--sucl); color: var(--suc); border: 1px solid var(--sucb); }
        .status-inactive { background: var(--bg); color: var(--lt); border: 1px solid var(--brd); }
        .btn-icon { background: transparent; border: 1px solid var(--brd); color: var(--mt); width: 32px; height: 32px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s; }
        .btn-icon:hover { background: var(--bl); color: var(--b); border-color: var(--bb); }
        .btn-icon.deleteBtn:hover { background: var(--dngl); color: var(--dng); border-color: #fecaca; }
        .pagination .page-link { border: 1px solid var(--brd); color: var(--mt); font-size: 12px; font-weight: 600; padding: 6px 12px; }
        .pagination .page-item.active .page-link { background: var(--b); color: #fff; border-color: var(--b); }
        .modal-content { border-radius: var(--R); border: none; box-shadow: 0 12px 40px rgba(15,23,42,.08); }
        .modal-header { border-bottom: 1px solid var(--brd); background: var(--bg); }
        .modal-footer { border-top: 1px solid var(--brd); background: var(--bg); }
        .modal-title { font-family: 'Outfit', sans-serif; font-weight: 700; }
        .form-label { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .03em; }
        .form-control, .form-select { padding: 9px 12px; border: 1.5px solid var(--brd); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--dk); font-family: 'Inter', sans-serif; }
        .form-control:focus, .form-select:focus { border-color: var(--b); background: #fff; box-shadow: 0 0 0 3px var(--bl); outline: none; }
        .bootstrap-select .dropdown-toggle { padding: 9px 12px; border: 1.5px solid var(--brd); border-radius: 8px; font-size: 13px; background: var(--bg); }
        .bootstrap-select .dropdown-toggle:focus { border-color: var(--b); box-shadow: 0 0 0 3px var(--bl); }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width:700px) { body { padding: 14px; } .hdr { flex-direction: column; align-items: flex-start; } .prow { flex-direction: column; align-items: stretch; } }
    </style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div class="hdr-l">
            <h1><i class="bi bi-cash-stack text-primary me-2"></i>Gestion des caisses</h1>
            <p>Créez et gérez les caisses de votre commerce</p>
        </div>
        <div>
            <span class="hdr-badge"><i class="bi bi-database"></i> <?= count($boutiques) ?> boutique(s)</span>
            <button class="btn-go ms-2" id="addBtn"><i class="bi bi-plus-lg"></i> Nouvelle caisse</button>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <?= $message ?>
        <button class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="pbar">
        <form id="searchForm" class="prow">
            <label><i class="bi bi-search"></i> Rechercher</label>
            <input type="text" id="searchInput" placeholder="Code ou nom de caisse...">
            <label><i class="bi bi-shop"></i> Boutique</label>
            <select id="boutiqueFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Toutes les boutiques...">
                <option value="">Toutes les boutiques</option>
                <?php foreach ($boutiques as $b): ?>
                    <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
            <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
        </form>
    </div>

    <div class="data-table-wrap">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Nom</th>
                        <th class="text-end">Solde</th>
                        <th>Boutique</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody"><?= $initialData['table'] ?></tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $initialData['pagination'] ?></div>
    </div>
</div>

<!-- Modal Ajout/Modification -->
<div class="modal fade" id="caisseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_caisse_id" id="oldCaisseId" value="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="bi bi-cash-register text-primary me-2"></i>Nouvelle caisse</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Code (auto)</label>
                        <input type="text" class="form-control" id="code_caisse" value="<?= e($newCode) ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nom de la caisse *</label>
                        <input type="text" class="form-control" name="nom_caisse" id="nom_caisse" required placeholder="Ex: Caisse Principale">
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Solde actuel</label>
                            <input type="number" step="0.01" class="form-control" name="solde" id="solde" value="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Statut</label>
                            <select class="form-select" name="statut" id="statut">
                                <option value="Actif">Actif</option>
                                <option value="Inactif">Inactif</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label">Boutique</label>
                        <select class="form-select selectpicker" name="boutique_id" id="boutique_id" data-live-search="true">
                            <option value="">Aucune</option>
                            <?php foreach ($boutiques as $b): ?>
                                <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="btn_enregistrer" class="btn btn-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Suppression -->
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(function(){
    $('.selectpicker').selectpicker();
    const modal = new bootstrap.Modal(document.getElementById('caisseModal'));
    const delModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));

    $('#addBtn').on('click', function(){
        $('#formAction').val('add');
        $('#oldCaisseId').val('');
        $('#modalTitle').html('<i class="bi bi-cash-register text-primary me-2"></i>Nouvelle caisse');
        $('#code_caisse').val('<?= e($newCode) ?>');
        $('#nom_caisse').val('');
        $('#solde').val('0').prop('readonly', false);
        $('#statut').val('Actif');
        $('#boutique_id').selectpicker('val', '');
        modal.show();
    });

    $(document).on('click', '.editBtn', function(){
        const id = $(this).data('id');
        $('#editIdField').val(id);
        $('#editLoadForm').submit();
    });

    $(document).on('click', '.deleteBtn', function(){
        const id = $(this).data('id');
        const nom = $(this).data('nom');
        $('#deleteFormId').val(id);
        $('#deleteNom').text(nom);
        delModal.show();
    });

    $('#confirmDeleteBtn').on('click', function(){ $('#deleteForm').submit(); });

    function rechercher(page){
        var formData = 'ajax=1&search=' + encodeURIComponent($('#searchInput').val())
            + '&boutique_filter=' + encodeURIComponent($('#boutiqueFilter').val()) + '&page=' + page;
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(data){
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' caisse(s) — Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
            },
            error: function(xhr){ console.error(xhr.responseText); alert('Erreur AJAX'); }
        });
    }

    var searchTimeout = null;
    $('#searchInput').on('input', function(){
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function(){ rechercher(1); }, 300);
    });
    $('#boutiqueFilter').on('changed.bs.select', function(){
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function(){ rechercher(1); }, 300);
    });
    $('#filterBtn').on('click', function(){ rechercher(1); });
    $('#resetBtn').on('click', function(){
        $('#searchInput').val('');
        $('#boutiqueFilter').selectpicker('val', '');
        rechercher(1);
    });

    $(document).on('click', '.page-link', function(e){
        e.preventDefault();
        rechercher($(this).data('page'));
    });

    setTimeout(()=>$('.alert').alert('close'), 5000);

    <?php if ($editCaisse): ?>
    $(function(){
        $('#formAction').val('edit');
        $('#oldCaisseId').val('<?= e($editCaisse['caisse_id']) ?>');
        $('#modalTitle').html('<i class="bi bi-pencil text-primary me-2"></i>Modifier la caisse');
        $('#code_caisse').val('<?= e($editCaisse['caisse_id']) ?>');
        $('#nom_caisse').val('<?= e($editCaisse['nom_caisse']) ?>');
        $('#solde').val('<?= e($editCaisse['solde']) ?>');
        $('#boutique_id').selectpicker('val', '<?= e($editCaisse['boutique_id']) ?>');
        $('#statut').val('<?= e($editCaisse['statut']) ?>');
        modal.show();
    });
    <?php endif; ?>
});
</script>
</body>
</html>