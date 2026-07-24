<?php
// views/contact/index.php – Gestion des contacts
require __DIR__ . '/../../databases/database.php';
session_start();

// Vérifier la session
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

// Vérifier que l'utilisateur existe toujours
$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

// Fonctions utilitaires
function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}

// --- Fonction pour générer un ID automatique ---
function generateContactId($pdo)
{
    $date = date('Ymd');
    $prefix = 'CT-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contact WHERE code_contact LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// --- Types et statuts ---
$types_contact = ['Client', 'Fournisseur', 'Autre'];
$statuts_contact = ['Particulier', 'Société', 'Association', 'Autre'];
$etats = ['Actif', 'Inactif'];

// --- Traitement des actions POST ---
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

        // Suppression
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

// Générer un token CSRF
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// --- AJAX pour le tableau ---
function getTableContent($pdo, $search, $type_filter, $page, $perPage = 20)
{
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
                <i class="fas fa-inbox fa-2x d-block mb-2 opacity-50"></i>
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
                        <button class="act-btn v viewBtn" data-code="<?= e($c['code_contact']) ?>" title="Voir"><i class="fas fa-eye"></i></button>
                        <button class="act-btn e editBtn" data-code="<?= e($c['code_contact']) ?>" title="Modifier"><i class="fas fa-pen"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= e($c['code_contact']) ?>" data-nom="<?= e($c['nom_prenom_contact']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="fas fa-trash"></i></button>
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

// --- AJAX ---
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $type_filter = trim($_POST['type_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $type_filter, $page);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// --- AJAX pour voir un contact ---
if (isset($_POST['ajax_view']) && $_POST['ajax_view'] == '1') {
    $code = trim($_POST['code'] ?? '');
    if (empty($code)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Code non spécifié']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM contact WHERE code_contact = ?");
        $stmt->execute([$code]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($c) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'code_contact' => $c['code_contact'],
                'nom_prenom_contact' => $c['nom_prenom_contact'],
                'telephone_contact' => $c['telephone_contact'] ?? '—',
                'email_contact' => $c['email_contact'] ?? '—',
                'type_contact' => $c['type_contact'],
                'statut_contact' => $c['statut_contact'],
                'solde_contact' => number_format((float)$c['solde_contact'], 0, ',', ' '),
                'solde_minimum' => number_format((float)$c['solde_minimum'], 0, ',', ' '),
                'solde_maximum' => number_format((float)$c['solde_maximum'], 0, ',', ' '),
                'adresse_contact' => $c['adresse_contact'] ?? '—',
                'etat_contact' => $c['etat_contact']
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Contact non trouvé']);
        }
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$type_filter = trim($_POST['type_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $type_filter, $page);

$editContact = null;
if ($action === 'edit' && isset($_POST['edit_code'])) {
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
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Bootstrap SelectPicker (CSS) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* === Styles identiques aux autres pages === */
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
                <h2 class="fw-800 mb-0">Gestion des contacts</h2>
                <p class="text-tertiary mt-1">Gérez vos clients, fournisseurs et autres contacts</p>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="fas fa-plus"></i> Nouveau contact</button>
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
                            <input type="text" name="search" id="searchInput" placeholder="Code, nom, téléphone, email..." value="<?= e($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="typeFilter" class="form-label fw-semibold small">Type</label>
                        <select name="type_filter" id="typeFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un type...">
                            <option value="">Tous</option>
                            <?php foreach ($types_contact as $t): ?>
                                <option value="<?= e($t) ?>" <?= ($type_filter == $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-primary w-100" id="filterBtn"><i class="fas fa-filter"></i> Filtrer</button>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-outline-secondary w-100" id="resetBtn"><i class="fas fa-undo"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="data-table-wrap" id="tableWrapper">
            <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
                <h5 class="mb-0 fw-bold">Liste des contacts</h5>
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

    <!-- =========================================================
MODAL FORMULAIRE (ajout/modification)
========================================================= -->
    <div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-address-book text-primary me-2"></i> Nouveau contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post" id="contactForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="old_code" id="oldCode" value="">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="modal-body">
                        <!-- Identification -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-tag me-1"></i> Identification</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="code_contact" class="form-label fw-semibold">Code contact</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                                    <input type="text" class="form-control" id="code_contact" name="code_contact" readonly value="<?= e($editContact['code_contact'] ?? generateContactId($pdo)) ?>">
                                </div>
                                <div class="form-text">ID généré automatiquement</div>
                            </div>
                            <div class="col-md-6">
                                <label for="nom_prenom_contact" class="form-label fw-semibold">Nom et prénom <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="nom_prenom_contact" name="nom_prenom_contact" required placeholder="Jean Dupont" value="<?= e($editContact['nom_prenom_contact'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Coordonnées -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-phone me-1"></i> Coordonnées</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="telephone_contact" class="form-label fw-semibold">Téléphone</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="text" class="form-control" id="telephone_contact" name="telephone_contact" placeholder="+225 05..." value="<?= e($editContact['telephone_contact'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="email_contact" class="form-label fw-semibold">Email</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control" id="email_contact" name="email_contact" placeholder="contact@example.com" value="<?= e($editContact['email_contact'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Type et statut -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-tags me-1"></i> Type et statut</h6>
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
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-coins me-1"></i> Gestion des soldes</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="solde_contact" class="form-label fw-semibold">Solde</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-wallet"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="solde_contact" name="solde_contact" placeholder="0.00" value="<?= e($editContact['solde_contact'] ?? 0) ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="solde_minimum" class="form-label fw-semibold">Solde minimum</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-arrow-down"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="solde_minimum" name="solde_minimum" placeholder="0.00" value="<?= e($editContact['solde_minimum'] ?? 0) ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="solde_maximum" class="form-label fw-semibold">Solde maximum</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-arrow-up"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="solde_maximum" name="solde_maximum" placeholder="0.00" value="<?= e($editContact['solde_maximum'] ?? 0) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Adresse -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-map-pin me-1"></i> Adresse</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-12">
                                <label for="adresse_contact" class="form-label fw-semibold">Adresse</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-home"></i></span>
                                    <input type="text" class="form-control" id="adresse_contact" name="adresse_contact" placeholder="Adresse complète" value="<?= e($editContact['adresse_contact'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- État -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-toggle-on me-1"></i> Statut</h6>
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
                    <h5 class="modal-title fw-bold" id="viewModalLabel"><i class="fas fa-eye text-primary me-2"></i> Détails du contact</h5>
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

    <!-- =========================================================
SCRIPTS
========================================================= -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

    <script>
        $(document).ready(function() {
            // --- Initialisation des selectpicker ---
            $('.selectpicker').selectpicker('destroy');
            $('.selectpicker').selectpicker();

            // --- Ouvrir modal Ajout ---
            $('#addBtn').on('click', function(e) {
                e.preventDefault();
                $('#formAction').val('add');
                $('#oldCode').val('');
                $('#modalTitle').html('<i class="fas fa-address-book text-primary me-2"></i> Nouveau contact');

                // Réinitialiser les champs
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

                var modalEl = document.getElementById('contactModal');
                var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.show();
            });

            // --- Édition ---
            $(document).on('click', '.editBtn', function(e) {
                e.preventDefault();
                const code = $(this).data('code');
                $('#actionField').val('edit');
                $('#editCodeField').val(code);
                $('#actionForm').submit();
            });

            // --- Vue ---
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
                            $('#viewModalLabel').text('Contact ' + response.nom_prenom_contact);
                            const fields = [
                                ['Code', response.code_contact],
                                ['Nom', response.nom_prenom_contact],
                                ['Téléphone', response.telephone_contact],
                                ['Email', response.email_contact],
                                ['Type', response.type_contact],
                                ['Statut', response.statut_contact],
                                ['Solde', response.solde_contact + ' FCFA'],
                                ['Solde minimum', response.solde_minimum + ' FCFA'],
                                ['Solde maximum', response.solde_maximum + ' FCFA'],
                                ['Adresse', response.adresse_contact],
                                ['État', response.etat_contact]
                            ];
                            let html = '';
                            fields.forEach(([label, value]) => {
                                let val = value || '—';
                                html += '<div class="col-sm-6"><div class="bg-light p-3 rounded-3 border"><div class="text-muted small text-uppercase fw-bold">' + label + '</div><div class="fw-semibold">' + val + '</div></div></div>';
                            });
                            $('#viewGrid').html(html);
                            $('#viewModal').modal('show');
                        } else {
                            alert('Erreur : ' + (response.message || 'Contact non trouvé'));
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

            // Auto-submit pour le champ recherche
            var searchTimeout = null;
            $('#searchInput').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });

            // Pour les selectpicker du filtre
            $('#typeFilter').on('changed.bs.select', function() {
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
                $('#typeFilter').selectpicker('val', '');
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
                $('#deleteNomContact').text(nom);
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
            <?php if (isset($editContact) && $action === 'edit'): ?>
                $(function() {
                    $('#formAction').val('edit');
                    $('#oldCode').val('<?= e($editContact['code_contact']) ?>');
                    $('#modalTitle').html('<i class="fas fa-address-book text-primary me-2"></i> Modifier le contact');
                    $('#code_contact').prop('readonly', true);
                    $('.selectpicker').selectpicker('refresh');
                    var modalEl = document.getElementById('contactModal');
                    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                    modal.show();
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>