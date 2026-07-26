<?php
ob_start();
// views/contact/index.php – Gestion des contacts (design vente)
require __DIR__ . '/../../databases/database.php';

session_start();
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

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}

function generateContactId($pdo) {
    $date = date('Ymd');
    $prefix = 'CT-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contact WHERE code_contact LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

$types_contact = ['Client', 'Fournisseur', 'Autre'];
$statuts_contact = ['Particulier', 'Société', 'Association', 'Autre'];
$etats = ['Actif', 'Inactif'];

$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {
        if ($action === 'add' || $action === 'edit') {
            $code = trim($_POST['code_contact'] ?? '');
            $nom = trim($_POST['nom_prenom_contact'] ?? '');
            $telephone = trim($_POST['telephone_contact'] ?? '');
            $email = trim($_POST['email_contact'] ?? '');
            $type = trim($_POST['type_contact'] ?? '');
            $statut = trim($_POST['statut_contact'] ?? '');
            $solde = floatval(str_replace(',', '.', $_POST['solde_contact'] ?? 0));
            $solde_min = floatval(str_replace(',', '.', $_POST['solde_minimum'] ?? 0));
            $solde_max = floatval(str_replace(',', '.', $_POST['solde_maximum'] ?? 0));
            $adresse = trim($_POST['adresse_contact'] ?? '');
            $etat = trim($_POST['etat_contact'] ?? 'Actif');

            $errors = [];
            if (empty($nom)) $errors[] = 'Le nom est requis.';
            if (empty($type)) $errors[] = 'Le type est requis.';
            if (empty($statut)) $errors[] = 'Le statut est requis.';

            if (empty($errors)) {
                try {
                    if ($action === 'add') {
                        $code = generateContactId($pdo);
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM contact WHERE code_contact = ?");
                        $stmt->execute([$code]);
                        if ($stmt->fetchColumn() > 0) {
                            $message = "Ce code contact existe déjà.";
                            $messageType = 'warning';
                        } else {
                            $sql = "INSERT INTO contact (code_contact, nom_prenom_contact, telephone_contact, email_contact, type_contact, statut_contact, solde_contact, solde_minimum, solde_maximum, adresse_contact, etat_contact)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$code, $nom, $telephone, $email, $type, $statut, $solde, $solde_min, $solde_max, $adresse, $etat]);
                            $message = "Contact « $nom » ajouté avec succès. ID : $code";
                            $messageType = 'success';
                        }
                    } elseif ($action === 'edit') {
                        $oldCode = $_POST['old_code'] ?? $code;
                        $sql = "UPDATE contact SET code_contact=?, nom_prenom_contact=?, telephone_contact=?, email_contact=?, type_contact=?, statut_contact=?, solde_contact=?, solde_minimum=?, solde_maximum=?, adresse_contact=?, etat_contact=?
                                WHERE code_contact = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$code, $nom, $telephone, $email, $type, $statut, $solde, $solde_min, $solde_max, $adresse, $etat, $oldCode]);
                        $message = "Contact « $nom » mis à jour.";
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

        if ($action === 'delete' && isset($_POST['btn_supprimer']) && $_POST['btn_supprimer'] == '1') {
            $code = $_POST['sai_supprimer_id'] ?? '';
            if (!empty($code)) {
                try {
                    $stmt = $pdo->prepare("SELECT nom_prenom_contact FROM contact WHERE code_contact = ?");
                    $stmt->execute([$code]);
                    $nom = $stmt->fetchColumn();
                    $stmt = $pdo->prepare("DELETE FROM contact WHERE code_contact = ?");
                    $stmt->execute([$code]);
                    $message = "Contact « $nom » supprimé.";
                    $messageType = 'danger';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

function getTableContent($pdo, $search, $type_filter, $page, $perPage = 20) {
    $sql = "SELECT * FROM contact WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (code_contact LIKE ? OR nom_prenom_contact LIKE ? OR telephone_contact LIKE ? OR email_contact LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($type_filter)) {
        $sql .= " AND type_contact = ?";
        $params[] = $type_filter;
    }

    $countSql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $sql .= " ORDER BY nom_prenom_contact LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($contacts)): ?>
        <tr>
            <td colspan="11" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                Aucun contact trouvé
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($contacts as $c): ?>
            <tr>
                <td class="td-bold"><?= e($c['code_contact']) ?></td>
                <td><?= e($c['nom_prenom_contact']) ?></td>
                <td><?= e($c['telephone_contact'] ?? '—') ?></td>
                <td><?= e($c['email_contact'] ?? '—') ?></td>
                <td><?= e($c['type_contact']) ?></td>
                <td><?= e($c['statut_contact']) ?></td>
                <td><?= number_format((float)$c['solde_contact'], 0, ',', ' ') ?></td>
                <td><?= number_format((float)$c['solde_minimum'], 0, ',', ' ') ?></td>
                <td><?= number_format((float)$c['solde_maximum'], 0, ',', ' ') ?></td>
                <td>
                    <span class="status-badge <?= $c['etat_contact'] === 'Actif' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= e($c['etat_contact']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn e editBtn" data-code="<?= e($c['code_contact']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= e($c['code_contact']) ?>" data-nom="<?= e($c['nom_prenom_contact']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
            <span class="text-muted small">Affichage de <?= (($page - 1) * $perPage + 1) ?> à <?= min($page * $perPage, $total) ?> sur <?= $total ?></span>
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
        'table'      => $tableHtml,
        'pagination' => $paginationHtml,
        'total'      => $total,
        'page'       => $page,
        'totalPages' => $totalPages
    ];
}

if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $type_filter = trim($_POST['type_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $type_filter, $page);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

$search = trim($_POST['search'] ?? '');
$type_filter = trim($_POST['type_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $type_filter, $page);

$editContact = null;
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM contact WHERE code_contact = ?");
    $stmt->execute([$code]);
    $editContact = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des contacts</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Bootstrap SelectPicker (CSS) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ===== STYLE DASHBOARD (repris de vente.php) ===== */
        :root {
            --b: #2563eb;
            --bd: #1d4ed8;
            --bl: #eff6ff;
            --bb: #bfdbfe;
            --bg: #f1f5f9;
            --w: #fff;
            --dk: #0f172a;
            --mt: #64748b;
            --lt: #94a3b8;
            --brd: #e2e8f0;
            --dng: #ef4444;
            --dngl: #fef2f2;
            --dngb: #fecaca;
            --suc: #10b981;
            --sucl: #ecfdf5;
            --sucb: #a7f3d0;
            --wrn: #f59e0b;
            --wrnl: #fffbeb;
            --wrnb: #fde68a;
            --prp: #8b5cf6;
            --prpl: #f5f3ff;
            --prpb: #e9d5ff;
            --tl: #0891b2;
            --tll: #ecfeff;
            --tlb: #cffafe;
            --R: 16px;
            --Rs: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg);
            color: var(--dk);
            min-height: 100vh;
            line-height: 1.5;
            padding: 28px 20px;
        }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .W { max-width: 1400px; margin: 0 auto; }
        .hdr {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--dk); letter-spacing: -0.02em; }
        .hdr-l p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
        .hdr-r {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .hdr-badge {
            background: var(--bl);
            border: 1px solid var(--bb);
            color: var(--b);
            padding: 8px 14px;
            border-radius: var(--Rs);
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .pbar {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            padding: 16px 20px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .prow {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .prow label {
            font-size: 11px;
            font-weight: 600;
            color: var(--mt);
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .prow input, .prow select {
            padding: 7px 10px;
            border: 1.5px solid var(--brd);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: var(--dk);
            background: var(--bg);
            font-family: 'Inter', sans-serif;
            transition: all .2s;
        }
        .prow input:focus, .prow select:focus {
            border-color: var(--b);
            background: #fff;
            box-shadow: 0 0 0 3px var(--bl);
            outline: none;
        }
        .prow select {
            appearance: none;
            padding-right: 32px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }
        .btn-go {
            background: var(--b);
            color: #fff;
            padding: 7px 16px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 4px rgba(37,99,235,.2);
            transition: background .15s;
            border: none;
            cursor: pointer;
        }
        .btn-go:hover { background: var(--bd); }
        .btn-go-outline {
            background: transparent;
            color: var(--mt);
            border: 1.5px solid var(--brd);
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s;
            cursor: pointer;
        }
        .btn-go-outline:hover {
            background: var(--bg);
            border-color: var(--lt);
        }

        .data-table-wrap {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .table>:not(caption)>*>* { padding: 12px 18px; }
        .table thead th {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--lt);
            background: var(--bg);
            border-bottom: 1px solid var(--brd);
        }
        .table tbody tr {
            border-bottom: 1px solid var(--brd);
            transition: background .2s;
        }
        .table tbody tr:hover { background: var(--bl); }
        .table tbody td {
            vertical-align: middle;
            color: var(--dk);
            font-size: 0.85rem;
        }
        .td-bold { color: var(--dk) !important; font-weight: 700; }
        .td-semi { color: var(--dk) !important; font-weight: 500; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            border-radius: 999px;
            font-size: 0.73rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .status-badge .sdot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .status-badge.on { background: var(--sucl); color: #059669; }
        .status-badge.off { background: var(--dngl); color: #dc2626; }

        .act-btn {
            width: 34px;
            height: 34px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--lt);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all .2s;
        }
        .act-btn:hover { transform: scale(1.1); }
        .act-btn.e:hover { color: var(--wrn); background: var(--wrnl); border-color: rgba(245,158,11,.15); }
        .act-btn.d:hover { color: var(--dng); background: var(--dngl); border-color: rgba(239,68,68,.15); }

        .pagination .page-link {
            color: var(--b);
            border: 1px solid var(--brd);
            border-radius: 6px;
            margin: 0 2px;
            padding: 6px 14px;
            font-weight: 500;
        }
        .pagination .page-link:hover { background: var(--bl); border-color: var(--b); }
        .pagination .page-item.active .page-link { background: var(--b); border-color: var(--b); color: #fff; }
        .pagination .page-item.disabled .page-link { color: var(--lt); border-color: var(--brd); }

        .modal-content {
            border-radius: var(--R);
            border: none;
            box-shadow: 0 12px 40px rgba(15,23,42,.08);
        }
        .modal-header { border-bottom: 1px solid var(--brd); background: var(--bg); }
        .modal-footer { border-top: 1px solid var(--brd); background: var(--bg); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .data-table-wrap { animation: fadeUp .4s ease both; }

        @media (max-width:700px) {
            body { padding: 14px; }
            .hdr { flex-direction: column; align-items: flex-start; }
            .prow { flex-direction: column; align-items: stretch; }
            .prow .btn-go { width: 100%; justify-content: center; }
        }
        .bootstrap-select .dropdown-toggle .filter-option { color: var(--dk); }
        .bootstrap-select .dropdown-menu {
            border-radius: var(--Rs);
            border-color: var(--brd);
        }
        .bootstrap-select .dropdown-menu .bs-searchbox input {
            border-radius: 6px;
            border: 1px solid var(--brd);
            padding: 8px 12px;
        }
        .bootstrap-select .dropdown-menu .bs-searchbox input:focus {
            border-color: var(--b);
            box-shadow: 0 0 0 3px var(--bl);
        }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1>Gestion des contacts</h1>
            <p>Gérez vos clients, fournisseurs et autres contacts</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-people"></i> <?= $initialData['total'] ?? 0 ?> contacts</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau contact</button>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Barre de recherche / filtres -->
    <div class="pbar">
        <form id="searchForm" method="post" onsubmit="return false;">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
            <div class="prow">
                <label for="searchInput"><i class="bi bi-search"></i> Recherche</label>
                <input type="text" name="search" id="searchInput" placeholder="Code, nom, téléphone, email..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                <label for="typeFilter">Type</label>
                <select name="type_filter" id="typeFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un type...">
                    <option value="">Tous</option>
                    <?php foreach ($types_contact as $t): ?>
                        <option value="<?= e($t) ?>" <?= ($type_filter == $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
            </div>
        </form>
    </div>

    <!-- Tableau -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des contacts</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> contact(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Nom</th>
                        <th>Téléphone</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Statut</th>
                        <th>Solde</th>
                        <th>Solde min</th>
                        <th>Solde max</th>
                        <th>État</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?= $initialData['table'] ?>
                </tbody>
            </table>
        </div>
        <div id="paginationContainer">
            <?= $initialData['pagination'] ?>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL FORMULAIRE (ajout/modification) -->
<!-- ========================================================= -->
<div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-people-fill text-primary me-2"></i> Nouveau contact</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form method="post" id="contactForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_code" id="oldCode" value="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <!-- Identification -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-tag me-1"></i> Identification</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="code_contact" class="form-label fw-semibold">Code contact</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="code_contact" name="code_contact" readonly value="<?= e($editContact['code_contact'] ?? generateContactId($pdo)) ?>">
                            </div>
                            <div class="form-text">ID généré automatiquement</div>
                        </div>
                        <div class="col-md-6">
                            <label for="nom_prenom_contact" class="form-label fw-semibold">Nom et prénom <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="nom_prenom_contact" name="nom_prenom_contact" required placeholder="Jean Dupont" value="<?= e($editContact['nom_prenom_contact'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Coordonnées -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-phone me-1"></i> Coordonnées</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="telephone_contact" class="form-label fw-semibold">Téléphone</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                <input type="text" class="form-control" id="telephone_contact" name="telephone_contact" placeholder="+225 05..." value="<?= e($editContact['telephone_contact'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="email_contact" class="form-label fw-semibold">Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" id="email_contact" name="email_contact" placeholder="contact@example.com" value="<?= e($editContact['email_contact'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Type et statut -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-tags me-1"></i> Type et statut</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="type_contact" class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="type_contact" name="type_contact" required>
                                <option value="">=== Faites votre choix ===</option>
                                <?php foreach ($types_contact as $t): ?>
                                    <option value="<?= e($t) ?>" <?= (isset($editContact) && $editContact['type_contact'] == $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="statut_contact" class="form-label fw-semibold">Statut <span class="text-danger">*</span></label>
                            <select class="form-select" id="statut_contact" name="statut_contact" required>
                                <option value="">=== Faites votre choix ===</option>
                                <?php foreach ($statuts_contact as $s): ?>
                                    <option value="<?= e($s) ?>" <?= (isset($editContact) && $editContact['statut_contact'] == $s) ? 'selected' : '' ?>><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Soldes -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-coins me-1"></i> Gestion des soldes</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label for="solde_contact" class="form-label fw-semibold">Solde</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-wallet2"></i></span>
                                <input type="number" step="0.01" class="form-control" id="solde_contact" name="solde_contact" placeholder="0.00" value="<?= e($editContact['solde_contact'] ?? 0) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="solde_minimum" class="form-label fw-semibold">Solde minimum</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-arrow-down"></i></span>
                                <input type="number" step="0.01" class="form-control" id="solde_minimum" name="solde_minimum" placeholder="0.00" value="<?= e($editContact['solde_minimum'] ?? 0) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="solde_maximum" class="form-label fw-semibold">Solde maximum</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-arrow-up"></i></span>
                                <input type="number" step="0.01" class="form-control" id="solde_maximum" name="solde_maximum" placeholder="0.00" value="<?= e($editContact['solde_maximum'] ?? 0) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Adresse -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-geo-alt me-1"></i> Adresse</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="adresse_contact" class="form-label fw-semibold">Adresse</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-house"></i></span>
                                <input type="text" class="form-control" id="adresse_contact" name="adresse_contact" placeholder="Adresse complète" value="<?= e($editContact['adresse_contact'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- État -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-toggle-on me-1"></i> Statut</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="etat_contact" class="form-label fw-semibold">État</label>
                            <select class="form-select" id="etat_contact" name="etat_contact">
                                <?php foreach ($etats as $e): ?>
                                    <option value="<?= e($e) ?>" <?= (isset($editContact) && $editContact['etat_contact'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x"></i> Annuler</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn"><i class="bi bi-save"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODALE : CONFIRMATION SUPPRESSION -->
<!-- ========================================================= -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                <h5 class="modal-title mb-2" style="font-weight: 600; color: var(--dark);">Confirmer la suppression</h5>
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer le contact <strong id="deleteNomContact"></strong> ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" style="border-radius: 10px;" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn" style="border-radius: 10px; min-width: 120px;"><i class="bi bi-trash3 me-1"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire caché suppression -->
<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="btn_supprimer" value="1">
    <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>

<!-- Formulaire caché pour action edit -->
<form method="post" id="actionForm">
    <input type="hidden" name="action" id="actionField">
    <input type="hidden" name="edit_code" id="editCodeField">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>

<!-- ========================================================= -->
<!-- SCRIPTS -->
<!-- ========================================================= -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

<script>
$(document).ready(function() {
    // --- Initialisation des selectpicker ---
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    const contactModal = new bootstrap.Modal(document.getElementById('contactModal'));

    // --- Ajout ---
    $('#addBtn').on('click', function(e) {
        e.preventDefault();
        $('#formAction').val('add');
        $('#oldCode').val('');
        $('#modalTitle').html('<i class="bi bi-people-fill text-primary me-2"></i> Nouveau contact');
        $('#contactForm')[0].reset();
        $('#code_contact').prop('readonly', true);
        $('#code_contact').val('<?= generateContactId($pdo) ?>');
        $('#nom_prenom_contact').val('');
        $('#telephone_contact').val('');
        $('#email_contact').val('');
        $('#type_contact').val('');
        $('#statut_contact').val('');
        $('#solde_contact').val('0');
        $('#solde_minimum').val('0');
        $('#solde_maximum').val('0');
        $('#adresse_contact').val('');
        $('#etat_contact').val('Actif');
        contactModal.show();
    });

    // --- Édition ---
    $(document).on('click', '.editBtn', function(e) {
        e.preventDefault();
        const code = $(this).data('code');
        $('#actionField').val('load_edit');
        $('#editCodeField').val(code);
        $('#actionForm').submit();
    });

    // --- Fonction de recherche AJAX ---
    function rechercher(page) {
        page = page || 1;
        var formData = $('#searchForm').serialize();
        formData += '&page=' + page;
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' contact(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
                $('.page-link').off('click').on('click', function(e) {
                    e.preventDefault();
                    var p = $(this).data('page');
                    if (p) rechercher(p);
                });
                $('.selectpicker').selectpicker('refresh');
            },
            error: function() {
                alert('Erreur lors de la recherche.');
            }
        });
    }

    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });

    $('#typeFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });

    $('#filterBtn').on('click', function() { rechercher(1); });
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#typeFilter').selectpicker('val', '');
        rechercher(1);
    });

    // --- Gestion suppression ---
    $(document).on('click', '.deleteBtn', function(e) {
        e.preventDefault();
        const code = $(this).data('code');
        const nom = $(this).data('nom');
        $('#deleteNomContact').text(nom);
        $('#deleteFormId').val(code);
        $('#deleteConfirmModal').modal('show');
    });

    $('#confirmDeleteBtn').on('click', function() {
        $('#deleteForm').submit();
    });

    // Auto-fermeture des alertes
    setTimeout(function() { $('.alert').alert('close'); }, 5000);

    // --- Si édition via POST ---
    <?php if (isset($editContact) && $action === 'load_edit'): ?>
        $(function() {
            $('#formAction').val('edit');
            $('#oldCode').val('<?= e($editContact['code_contact']) ?>');
            $('#modalTitle').html('<i class="bi bi-people-fill text-primary me-2"></i> Modifier le contact');
            $('#code_contact').prop('readonly', true);
            $('.selectpicker').selectpicker('refresh');
            contactModal.show();
        });
    <?php endif; ?>
});
</script>
</body>
</html>