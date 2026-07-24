<?php
// taxe.php
// CRUD pour la table taxe – avec Bootstrap SelectPicker

require_once 'databases/database.php';

// --- Récupération des listes pour les selects ---
// Types de taxe (on peut les définir en dur ou les récupérer)
$types_taxe = ['TVA', 'Remise', 'Autre'];
// On peut aussi les récupérer depuis la base pour avoir les valeurs existantes
$types_from_db = $pdo->query("SELECT DISTINCT type_taxe FROM taxe ORDER BY type_taxe")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($types_from_db)) {
    $types_taxe = $types_from_db;
}
$etats_taxe = ['Actif', 'Inactif'];

// --- Traitement des actions POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';

if ($action === 'add' || $action === 'edit') {
    $code = trim($_POST['code_taxe'] ?? '');
    $titre = trim($_POST['titre_taxe'] ?? '');
    $taux = trim(str_replace(',', '.', $_POST['taux_taxe'] ?? 0));
    $type = trim($_POST['type_taxe'] ?? '');
    $etat = trim($_POST['etat_taxe'] ?? 'Actif');

    $errors = [];
    if (empty($code)) $errors[] = 'Le code taxe est requis.';
    if (empty($titre)) $errors[] = 'Le titre est requis.';
    if (!is_numeric($taux) || $taux < 0) $errors[] = 'Le taux doit être un nombre positif.';
    if (empty($type)) $errors[] = 'Le type est requis.';

    if (empty($errors)) {
        try {
            if ($action === 'add') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM taxe WHERE code_taxe = ?");
                $stmt->execute([$code]);
                if ($stmt->fetchColumn() > 0) {
                    $message = "Ce code taxe existe déjà.";
                    $messageType = 'warning';
                } else {
                    $sql = "INSERT INTO taxe (code_taxe, titre_taxe, taux_taxe, type_taxe, etat_taxe)
                            VALUES (?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$code, $titre, $taux, $type, $etat]);
                    $message = "Taxe « $titre » ajoutée avec succès.";
                    $messageType = 'success';
                }
            } elseif ($action === 'edit') {
                $oldCode = $_POST['old_code'] ?? $code;
                $sql = "UPDATE taxe SET code_taxe=?, titre_taxe=?, taux_taxe=?, type_taxe=?, etat_taxe=?
                        WHERE code_taxe = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$code, $titre, $taux, $type, $etat, $oldCode]);
                $message = "Taxe « $titre » mise à jour.";
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

// Suppression
if (isset($_POST['btn_supprimer']) && $_POST['btn_supprimer'] == '1') {
    $code = $_POST['sai_supprimer_id'] ?? '';
    if (!empty($code)) {
        try {
            $stmt = $pdo->prepare("SELECT titre_taxe FROM taxe WHERE code_taxe = ?");
            $stmt->execute([$code]);
            $titre = $stmt->fetchColumn();
            $stmt = $pdo->prepare("DELETE FROM taxe WHERE code_taxe = ?");
            $stmt->execute([$code]);
            $message = "Taxe « $titre » supprimée.";
            $messageType = 'danger';
        } catch (PDOException $e) {
            $message = "Erreur : " . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// --- AJAX pour le tableau ---
function getTableContent($pdo, $search, $filtres, $page, $perPage = 20)
{
    $sql = "SELECT * FROM taxe WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (code_taxe LIKE ? OR titre_taxe LIKE ? OR type_taxe LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($filtres['type'])) {
        $sql .= " AND type_taxe = ?";
        $params[] = $filtres['type'];
    }
    if (!empty($filtres['etat'])) {
        $sql .= " AND etat_taxe = ?";
        $params[] = $filtres['etat'];
    }

    $countSql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $sql .= " ORDER BY code_taxe LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $taxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($taxes)): ?>
        <tr>
            <td colspan="6" class="text-center py-5 text-muted">
                <i class="fas fa-inbox fa-2x d-block mb-2 opacity-50"></i>
                Aucune taxe trouvée
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($taxes as $t): ?>
            <tr>
                <td class="td-bold"><?= htmlspecialchars($t['code_taxe']) ?></td>
                <td><?= htmlspecialchars($t['titre_taxe']) ?></td>
                <td><?= htmlspecialchars($t['taux_taxe']) ?> %</td>
                <td><?= htmlspecialchars($t['type_taxe']) ?></td>
                <td>
                    <span class="status-badge <?= $t['etat_taxe'] === 'Actif' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= htmlspecialchars($t['etat_taxe']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn v viewBtn" data-code="<?= htmlspecialchars($t['code_taxe']) ?>" title="Voir"><i class="fas fa-eye"></i></button>
                        <button class="act-btn e editBtn" data-code="<?= htmlspecialchars($t['code_taxe']) ?>" title="Modifier"><i class="fas fa-pen"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= htmlspecialchars($t['code_taxe']) ?>" data-nom="<?= htmlspecialchars($t['titre_taxe']) ?>" title="Supprimer"><i class="fas fa-trash"></i></button>
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
                        <a class="page-link" href="#" data-page="<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
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
                        <a class="page-link" href="#" data-page="<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
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

// --- AJAX pour le tableau ---
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $filtres = [
        'type' => trim($_POST['type'] ?? ''),
        'etat' => trim($_POST['etat'] ?? '')
    ];
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $filtres, $page);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// --- AJAX pour voir une taxe (détail) ---
if (isset($_POST['ajax_view']) && $_POST['ajax_view'] == '1') {
    $code = trim($_POST['code'] ?? '');
    if (empty($code)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Code non spécifié']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM taxe WHERE code_taxe = ?");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'code_taxe' => $row['code_taxe'],
                'titre_taxe' => $row['titre_taxe'],
                'taux_taxe' => $row['taux_taxe'],
                'type_taxe' => $row['type_taxe'],
                'etat_taxe' => $row['etat_taxe']
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Taxe non trouvée']);
        }
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$filtres = [
    'type' => trim($_POST['type'] ?? ''),
    'etat' => trim($_POST['etat'] ?? '')
];
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $filtres, $page);

$editTaxe = null;
if ($action === 'edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM taxe WHERE code_taxe = ?");
    $stmt->execute([$code]);
    $editTaxe = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des taxes</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Bootstrap SelectPicker (CSS) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* === Styles identiques aux autres CRUD === */
        :root {
            --color-primary: #4f46e5;
            --color-primary-dark: #3730a3;
            --color-primary-soft: #eef2ff;
            --color-success: #10b981;
            --color-success-soft: #d1fae5;
            --color-warning: #f59e0b;
            --color-warning-soft: #fef3c7;
            --color-danger: #ef4444;
            --color-danger-soft: #fee2e2;
            --color-gray-50: #f8fafc;
            --color-gray-100: #f1f5f9;
            --color-gray-200: #e2e8f0;
            --color-gray-300: #cbd5e1;
            --color-gray-400: #94a3b8;
            --color-gray-500: #64748b;
            --color-gray-600: #475569;
            --color-gray-700: #334155;
            --color-gray-800: #1e293b;
            --color-gray-900: #0f172a;
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --bg-muted: #f8fafc;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-tertiary: #64748b;
            --text-quaternary: #94a3b8;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.08);
            --radius-sm: 10px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            padding: 30px 20px;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .container-crud {
            max-width: 1400px;
            margin: 0 auto;
        }

        .data-table-wrap {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .table> :not(caption)>*>* {
            padding: 12px 18px;
        }

        .table thead th {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-quaternary);
            background: var(--bg-muted);
            border-bottom: 1px solid var(--border-color);
        }

        .table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background var(--transition-base);
        }

        .table tbody tr:hover {
            background: var(--color-primary-soft);
        }

        .table tbody td {
            vertical-align: middle;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .td-bold {
            color: var(--text-primary) !important;
            font-weight: 700;
        }

        .td-semi {
            color: var(--text-primary) !important;
            font-weight: 500;
        }

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

        .status-badge .sdot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-badge.on {
            background: var(--color-success-soft);
            color: #059669;
        }

        .status-badge.off {
            background: var(--color-danger-soft);
            color: #dc2626;
        }

        .act-btn {
            width: 34px;
            height: 34px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-quaternary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-base);
            cursor: pointer;
        }

        .act-btn:hover {
            transform: scale(1.1);
        }

        .act-btn.v:hover {
            color: var(--color-primary);
            background: var(--color-primary-soft);
            border-color: rgba(79, 70, 229, 0.15);
        }

        .act-btn.e:hover {
            color: var(--color-warning);
            background: var(--color-warning-soft);
            border-color: rgba(245, 158, 11, 0.15);
        }

        .act-btn.d:hover {
            color: var(--color-danger);
            background: var(--color-danger-soft);
            border-color: rgba(239, 68, 68, 0.15);
        }

        .search-inline {
            display: flex;
            align-items: center;
            background: var(--bg-muted);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0 16px;
            height: 42px;
            min-width: 200px;
            transition: all var(--transition-base);
        }

        .search-inline:focus-within {
            border-color: var(--color-primary);
            background: var(--bg-surface);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.08);
        }

        .search-inline i {
            color: var(--text-quaternary);
            font-size: 0.8rem;
        }

        .search-inline input,
        .search-inline select {
            background: none;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: inherit;
            width: 100%;
            margin-left: 10px;
        }

        .search-inline select {
            padding-right: 20px;
            cursor: pointer;
        }

        .search-inline input::placeholder {
            color: var(--text-quaternary);
        }

        .btn-primary {
            background: var(--color-primary);
            border-color: var(--color-primary);
        }

        .btn-primary:hover {
            background: var(--color-primary-dark);
            border-color: var(--color-primary-dark);
        }

        .btn-outline-secondary {
            color: var(--text-secondary);
            border-color: var(--border-color);
        }

        .btn-outline-secondary:hover {
            background: var(--color-gray-100);
            border-color: var(--color-gray-300);
        }

        .modal-content {
            border-radius: var(--radius-md);
            border: none;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-muted);
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
            background: var(--bg-muted);
        }

        .page-heading h2 {
            font-weight: 800;
        }

        .text-tertiary {
            color: var(--text-tertiary);
        }

        .pagination .page-link {
            color: var(--color-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin: 0 2px;
            padding: 6px 14px;
            font-weight: 500;
        }

        .pagination .page-link:hover {
            background: var(--color-primary-soft);
            border-color: var(--color-primary);
        }

        .pagination .page-item.active .page-link {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: #fff;
        }

        .pagination .page-item.disabled .page-link {
            color: var(--text-quaternary);
            border-color: var(--border-color);
        }

        .bootstrap-select .dropdown-toggle .filter-option {
            color: var(--text-primary);
        }

        .bootstrap-select .dropdown-menu {
            border-radius: var(--radius-sm);
            border-color: var(--border-color);
        }

        .bootstrap-select .dropdown-menu .bs-searchbox input {
            border-radius: 6px;
            border: 1px solid var(--border-color);
            padding: 8px 12px;
        }

        .bootstrap-select .dropdown-menu .bs-searchbox input:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.08);
        }
    </style>
</head>

<body>
    <div class="container-crud">

        <!-- En-tête -->
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
            <div class="page-heading">
                <h2 class="fw-800 mb-0">Gestion des taxes</h2>
                <p class="text-tertiary mt-1">Définissez les taxes, remises et autres taux applicables</p>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="fas fa-plus"></i> Nouvelle taxe</button>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Barre de recherche et filtres -->
        <div class="bg-light p-3 rounded-3 mb-3 border">
            <form id="searchForm" method="post" onsubmit="return false;">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="searchInput" class="form-label fw-semibold small">Recherche</label>
                        <div class="search-inline" style="min-width:100%;">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" id="searchInput" placeholder="Code, titre, type..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="typeFilter" class="form-label fw-semibold small">Type</label>
                        <select name="type" id="typeFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un type...">
                            <option value="">Tous</option>
                            <?php foreach ($types_taxe as $t): ?>
                                <option value="<?= htmlspecialchars($t) ?>" <?= ($filtres['type'] == $t) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="etatFilter" class="form-label fw-semibold small">État</label>
                        <select name="etat" id="etatFilter" class="selectpicker form-control">
                            <option value="">Tous</option>
                            <?php foreach ($etats_taxe as $e): ?>
                                <option value="<?= $e ?>" <?= ($filtres['etat'] == $e) ? 'selected' : '' ?>><?= $e ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-primary w-100" id="filterBtn"><i class="fas fa-filter"></i> Filtrer</button>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-outline-secondary w-100" id="resetBtn"><i class="fas fa-undo"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="data-table-wrap" id="tableWrapper">
            <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
                <h5 class="mb-0 fw-bold">Liste des taxes</h5>
                <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> taxe(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Titre</th>
                            <th>Taux (%)</th>
                            <th>Type</th>
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

    <!-- =========================================================
MODAL FORMULAIRE (ajout/modification)
========================================================= -->
    <div class="modal fade" id="taxeModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-percent text-primary me-2"></i> Nouvelle taxe</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post" id="taxeForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="old_code" id="oldCode" value="">
                    <div class="modal-body">
                        <!-- Code et titre -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-hashtag me-1"></i> Identification</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="code_taxe" class="form-label fw-semibold">Code taxe <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                                    <input type="text" class="form-control" id="code_taxe" name="code_taxe" required placeholder="TAX001" value="<?= htmlspecialchars($editTaxe['code_taxe'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="titre_taxe" class="form-label fw-semibold">Titre <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-heading"></i></span>
                                    <input type="text" class="form-control" id="titre_taxe" name="titre_taxe" required placeholder="TVA 18%" value="<?= htmlspecialchars($editTaxe['titre_taxe'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Taux et type -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-sliders-h me-1"></i> Détails</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="taux_taxe" class="form-label fw-semibold">Taux (%) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-percent"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="taux_taxe" name="taux_taxe" placeholder="0.00" required value="<?= htmlspecialchars($editTaxe['taux_taxe'] ?? '0') ?>">
                                </div>
                                <div class="form-text">Saisir la valeur en pourcentage (ex: 18 pour 18%).</div>
                            </div>
                            <div class="col-md-6">
                                <label for="type_taxe" class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="type_taxe" name="type_taxe" required>
                                    <option value="">=== Faites votre choix ===</option>
                                    <?php foreach ($types_taxe as $t): ?>
                                        <option value="<?= htmlspecialchars($t) ?>" <?= (isset($editTaxe) && $editTaxe['type_taxe'] == $t) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Exemples : TVA, Remise, Autre</div>
                            </div>
                        </div>

                        <!-- État -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-toggle-on me-1"></i> Statut</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="etat_taxe" class="form-label fw-semibold">État</label>
                                <select class="form-select" id="etat_taxe" name="etat_taxe">
                                    <?php foreach ($etats_taxe as $e): ?>
                                        <option value="<?= $e ?>" <?= (isset($editTaxe) && $editTaxe['etat_taxe'] == $e) ? 'selected' : '' ?>><?= $e ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Annuler</button>
                        <button type="submit" class="btn btn-primary" id="saveBtn"><i class="fas fa-save"></i> Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =========================================================
MODAL : VUE DÉTAIL
========================================================= -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:600px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="viewModalLabel">Détails de la taxe</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3" id="viewGrid"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
MODAL : CONFIRMATION SUPPRESSION
========================================================= -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 16px;">
                <div class="modal-body text-center p-4">
                    <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                    <h5 class="modal-title mb-2" style="font-weight: 600; color: var(--dark);">Confirmer la suppression</h5>
                    <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer la taxe <strong id="deleteNomTaxe"></strong> ?<br>Cette action est irréversible.</p>
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
        <input type="hidden" name="btn_supprimer" value="1">
        <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
    </form>

    <!-- Formulaire caché pour action edit -->
    <form method="post" id="actionForm">
        <input type="hidden" name="action" id="actionField">
        <input type="hidden" name="edit_code" id="editCodeField">
    </form>

    <!-- =========================================================
SCRIPTS
========================================================= -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Bootstrap SelectPicker JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

    <script>
        $(document).ready(function() {
            // --- Initialisation de tous les selectpicker ---
            $('.selectpicker').selectpicker('destroy');
            $('.selectpicker').selectpicker();

            // --- Ouvrir modal Ajout ---
            $('#addBtn').on('click', function(e) {
                e.preventDefault();
                $('#formAction').val('add');
                $('#oldCode').val('');
                $('#modalTitle').html('<i class="fas fa-percent text-primary me-2"></i> Nouvelle taxe');
                $('#taxeForm')[0].reset();
                $('#code_taxe').prop('readonly', false);
                // Réinitialiser les champs
                $('#code_taxe').val('');
                $('#titre_taxe').val('');
                $('#taux_taxe').val('0');
                $('#type_taxe').val('');
                $('#etat_taxe').val('Actif');
                var modalEl = document.getElementById('taxeModal');
                var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.show();
            });

            // --- Édition (via formulaire POST) ---
            $(document).on('click', '.editBtn', function(e) {
                e.preventDefault();
                const code = $(this).data('code');
                $('#actionField').val('edit');
                $('#editCodeField').val(code);
                $('#actionForm').submit();
            });

            // --- Vue en AJAX ---
            $(document).on('click', '.viewBtn', function(e) {
                e.preventDefault();
                const code = $(this).data('code');
                $('#viewModal').modal('hide');
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        ajax_view: '1',
                        code: code
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#viewModalLabel').text('Taxe ' + response.titre_taxe);
                            const fields = [
                                ['Code', response.code_taxe],
                                ['Titre', response.titre_taxe],
                                ['Taux', response.taux_taxe + ' %'],
                                ['Type', response.type_taxe],
                                ['État', response.etat_taxe]
                            ];
                            let html = '';
                            fields.forEach(([label, value]) => {
                                let val = value || '—';
                                html += '<div class="col-sm-6"><div class="bg-light p-3 rounded-3 border"><div class="text-muted small text-uppercase fw-bold">' + label + '</div><div class="fw-semibold">' + val + '</div></div></div>';
                            });
                            $('#viewGrid').html(html);
                            $('#viewModal').modal('show');
                        } else {
                            alert('Erreur : ' + (response.message || 'Taxe non trouvée'));
                        }
                    },
                    error: function() {
                        alert('Erreur lors de la récupération des données.');
                    }
                });
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
                        $('#totalCount').text(data.total + ' taxe(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
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

            // Auto-submit pour le champ recherche
            var searchTimeout = null;
            $('#searchInput').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });

            // Pour les selectpicker du filtre
            $('#typeFilter, #etatFilter').on('changed.bs.select', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });

            // Bouton Filtrer
            $('#filterBtn').on('click', function() {
                rechercher(1);
            });

            // Réinitialisation
            $('#resetBtn').on('click', function() {
                $('#searchInput').val('');
                $('#typeFilter, #etatFilter').selectpicker('val', '');
                rechercher(1);
            });

            // Pagination initiale
            $('.page-link').on('click', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                if (page) rechercher(page);
            });

            // --- Gestion suppression ---
            $(document).on('click', '.deleteBtn', function(e) {
                e.preventDefault();
                const code = $(this).data('code');
                const nom = $(this).data('nom');
                $('#deleteNomTaxe').text(nom);
                $('#deleteFormId').val(code);
                $('#deleteConfirmModal').modal('show');
            });
            $('#confirmDeleteBtn').on('click', function() {
                $('#deleteForm').submit();
            });

            // Auto-fermeture des alertes
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);

            // --- Si édition via POST ---
            <?php if (isset($editTaxe) && $action === 'edit'): ?>
                $(function() {
                    $('#formAction').val('edit');
                    $('#oldCode').val('<?= htmlspecialchars($editTaxe['code_taxe']) ?>');
                    $('#modalTitle').html('<i class="fas fa-percent text-primary me-2"></i> Modifier la taxe');
                    $('#code_taxe').prop('readonly', true);
                    var modalEl = document.getElementById('taxeModal');
                    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                    modal.show();
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>