<?php
ob_start(); // Début du buffer pour éviter toute sortie parasite

// views/boutique/index.php – Gestion des boutiques (design dashboard)
require 'databases/database.php';
session_start();

// Vérifier la session
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

// Vérifier que l'utilisateur existe et est actif
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

// --- Génération d'ID automatique ---
function generateBoutiqueId($pdo)
{
    $date = date('Ymd');
    $prefix = 'B-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM boutique WHERE code_boutique LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// --- Token CSRF ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Traitement des actions POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$token = $_POST['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($token) || $token !== $csrf_token) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {
        if ($action === 'add' || $action === 'edit') {
            $code = trim($_POST['code_boutique'] ?? '');
            $nom = trim($_POST['nom_boutique'] ?? '');
            $telephone = trim($_POST['telephone_boutique'] ?? '');
            $email = trim($_POST['email_boutique'] ?? '');
            $pays = trim($_POST['pays_boutique'] ?? '');
            $ville = trim($_POST['ville_boutique'] ?? '');
            $quartier = trim($_POST['quartier_boutique'] ?? '');
            $adresse = trim($_POST['adresse_boutique'] ?? '');
            $latitude = trim($_POST['latitude'] ?? '');
            $longitude = trim($_POST['longitude'] ?? '');
            $couleur = trim($_POST['couleur'] ?? '');
            $etat = trim($_POST['etat_boutique'] ?? 'Actif');

            // Validation des coordonnées
            if (!empty($latitude) && !is_numeric($latitude)) {
                $message = 'La latitude doit être un nombre valide.';
                $messageType = 'warning';
            } elseif (!empty($longitude) && !is_numeric($longitude)) {
                $message = 'La longitude doit être un nombre valide.';
                $messageType = 'warning';
            } else {
                $errors = [];
                if (empty($nom)) $errors[] = '';
                if (empty($pays)) $errors[] = '';
                if (empty($ville)) $errors[] = '';

                if (empty($errors)) {
                    try {
                        if ($action === 'add') {
                            $code = generateBoutiqueId($pdo);
                            $sql = "INSERT INTO boutique (code_boutique, nom_boutique, telephone_boutique, email_boutique, pays_boutique, ville_boutique, quartier_boutique, adresse_boutique, latitude, longitude, couleur, etat_boutique)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$code, $nom, $telephone, $email, $pays, $ville, $quartier, $adresse, $latitude, $longitude, $couleur, $etat]);
                            $message = "Boutique « $nom » ajoutée avec succès. ID : $code";
                            $messageType = 'success';
                        } elseif ($action === 'edit') {
                            $oldCode = $_POST['old_code'] ?? $code;
                            $sql = "UPDATE boutique SET code_boutique=?, nom_boutique=?, telephone_boutique=?, email_boutique=?, pays_boutique=?, ville_boutique=?, quartier_boutique=?, adresse_boutique=?, latitude=?, longitude=?, couleur=?, etat_boutique=?
                                    WHERE code_boutique = ?";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$code, $nom, $telephone, $email, $pays, $ville, $quartier, $adresse, $latitude, $longitude, $couleur, $etat, $oldCode]);
                            $message = "Boutique « $nom » mise à jour.";
                            $messageType = 'success';
                        }
                    } catch (PDOException $e) {
                        $message = "Erreur : " . $e->getMessage();
                        $messageType = 'danger';
                    }
                } else {
                    $message = implode('<br>', $errors);
                }
            }
        }

        // Suppression
        if ($action === 'delete' && isset($_POST['btn_supprimer']) && $_POST['btn_supprimer'] == '1') {
            $code = $_POST['sai_supprimer_id'] ?? '';
            if (!empty($code)) {
                try {
                    $stmt = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
                    $stmt->execute([$code]);
                    $nom = $stmt->fetchColumn();
                    $stmt = $pdo->prepare("DELETE FROM boutique WHERE code_boutique = ?");
                    $stmt->execute([$code]);
                    $message = "Boutique « $nom » supprimée.";
                    $messageType = 'danger';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}

// --- Fonction AJAX pour le tableau ---
function getTableContent($pdo, $search, $filtres, $page, $perPage = 20)
{
    $sql = "SELECT * FROM boutique WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (code_boutique LIKE ? OR nom_boutique LIKE ? OR ville_boutique LIKE ? OR pays_boutique LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 4; $i++) $params[] = $like;
    }
    if (!empty($filtres['etat'])) {
        $sql .= " AND etat_boutique = ?";
        $params[] = $filtres['etat'];
    }

    $countSql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $sql .= " ORDER BY code_boutique LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $boutiques = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($boutiques)): ?>
        <tr>
            <td colspan="11" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                Aucune boutique trouvée
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($boutiques as $b): ?>
            <tr>
                <td class="td-bold"><?= e($b['code_boutique']) ?></td>
                <td><?= e($b['nom_boutique']) ?></td>
                <td><?= e($b['telephone_boutique'] ?? '—') ?></td>
                <td><?= e($b['email_boutique'] ?? '—') ?></td>
                <td><?= e($b['pays_boutique']) ?></td>
                <td><?= e($b['ville_boutique']) ?></td>
                <td><?= e($b['quartier_boutique'] ?? '—') ?></td>
                <td>
                    <?php if (!empty($b['couleur'])): ?>
                        <span class="color-preview" style="background-color: <?= e($b['couleur']) ?>; display:inline-block; width:20px; height:20px; border-radius:4px; border:1px solid #e2e8f0;"></span>
                        <?= e($b['couleur']) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <span class="status-badge <?= $b['etat_boutique'] === 'Actif' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= e($b['etat_boutique']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn e editBtn" data-code="<?= e($b['code_boutique']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= e($b['code_boutique']) ?>" data-nom="<?= e($b['nom_boutique']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash"></i></button>
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
    $filtres = ['etat' => trim($_POST['etat'] ?? '')];
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $filtres, $page);
    // Nettoyer le buffer avant d'envoyer du JSON
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$filtres = ['etat' => trim($_POST['etat'] ?? '')];
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $filtres, $page);

// Chargement des données pour l'édition (action load_edit)
$editBoutique = null;
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM boutique WHERE code_boutique = ?");
    $stmt->execute([$code]);
    $editBoutique = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des boutiques</title>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap SelectPicker -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <style>
        /* ===== STYLE DASHBOARD (identique aux autres pages) ===== */
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

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
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
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--color-primary-dark);
            border-color: var(--color-primary-dark);
            color: #fff;
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

        /* Carte */
        #map {
            height: 300px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            margin-bottom: 12px;
            background: #eef2ff;
        }

        .color-preview {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            vertical-align: middle;
        }

        /* Animation */
        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .data-table-wrap {
            animation: fadeUp .4s ease both;
        }

        @media (max-width:700px) {
            body {
                padding: 14px;
            }

            .search-inline {
                min-width: auto;
            }
        }
    </style>
</head>

<body>
    <div class="container-crud">

        <!-- En-tête -->
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
            <div class="page-heading">
                <h2 class="fw-800 mb-0">Gestion des boutiques</h2>
                <p class="text-tertiary mt-1">Gérez vos points de vente et localisez-les sur la carte</p>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="bi bi-plus-circle"></i> Nouvelle boutique</button>
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
                            <i class="bi bi-search"></i>
                            <input type="text" name="search" id="searchInput" placeholder="Code, nom, ville, pays..." value="<?= e($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="etatFilter" class="form-label fw-semibold small">État</label>
                        <select name="etat" id="etatFilter" class="selectpicker form-control">
                            <option value="">Tous</option>
                            <option value="Actif" <?= ($filtres['etat'] == 'Actif') ? 'selected' : '' ?>>Actif</option>
                            <option value="Inactif" <?= ($filtres['etat'] == 'Inactif') ? 'selected' : '' ?>>Inactif</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-primary w-100" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-outline-secondary w-100" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="data-table-wrap" id="tableWrapper">
            <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
                <h5 class="mb-0 fw-bold">Liste des boutiques</h5>
                <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> boutique(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Nom</th>
                            <th>Téléphone</th>
                            <th>Email</th>
                            <th>Pays</th>
                            <th>Ville</th>
                            <th>Quartier</th>
                            <th>Couleur</th>
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
MODAL FORMULAIRE (ajout/modification) avec carte
========================================================= -->
    <div class="modal fade" id="boutiqueModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-shop text-primary me-2"></i> Nouvelle boutique</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post" id="boutiqueForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="old_code" id="oldCode" value="">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="modal-body">
                        <!-- Identification -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-tag me-1"></i> Identification</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="code_boutique" class="form-label fw-semibold">Code boutique</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                    <input type="text" class="form-control" id="code_boutique" name="code_boutique" readonly value="<?= e($editBoutique['code_boutique'] ?? generateBoutiqueId($pdo)) ?>">
                                </div>
                                <div class="form-text">ID généré automatiquement</div>
                            </div>
                            <div class="col-md-6">
                                <label for="nom_boutique" class="form-label fw-semibold">Nom de la boutique <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-shop"></i></span>
                                    <input type="text" class="form-control" id="nom_boutique" name="nom_boutique" required placeholder="Ma boutique" value="<?= e($editBoutique['nom_boutique'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Coordonnées -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-phone me-1"></i> Contact</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="telephone_boutique" class="form-label fw-semibold">Téléphone</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                    <input type="text" class="form-control" id="telephone_boutique" name="telephone_boutique" placeholder="+225 05..." value="<?= e($editBoutique['telephone_boutique'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="email_boutique" class="form-label fw-semibold">Email</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" class="form-control" id="email_boutique" name="email_boutique" placeholder="boutique@example.com" value="<?= e($editBoutique['email_boutique'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Adresse -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-geo-alt me-1"></i> Localisation</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="pays_boutique" class="form-label fw-semibold">Pays <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="pays_boutique" name="pays_boutique" placeholder="Côte d'Ivoire" value="<?= e($editBoutique['pays_boutique'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="ville_boutique" class="form-label fw-semibold">Ville <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="ville_boutique" name="ville_boutique" placeholder="Abidjan" value="<?= e($editBoutique['ville_boutique'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="quartier_boutique" class="form-label fw-semibold">Quartier</label>
                                <input type="text" class="form-control" id="quartier_boutique" name="quartier_boutique" placeholder="Cocody" value="<?= e($editBoutique['quartier_boutique'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="adresse_boutique" class="form-label fw-semibold">Adresse</label>
                                <input type="text" class="form-control" id="adresse_boutique" name="adresse_boutique" placeholder="Rue des commerçants" value="<?= e($editBoutique['adresse_boutique'] ?? '') ?>">
                            </div>
                        </div>

                        <!-- Carte interactive -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-map me-1"></i> Position GPS</h6>
                        <div id="map"></div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="latitude" class="form-label fw-semibold">Latitude</label>
                                <input type="text" class="form-control" id="latitude" name="latitude" placeholder="5.35995" value="<?= e($editBoutique['latitude'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="longitude" class="form-label fw-semibold">Longitude</label>
                                <input type="text" class="form-control" id="longitude" name="longitude" placeholder="-3.99995" value="<?= e($editBoutique['longitude'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <small class="text-muted">Cliquez sur la carte pour définir la position, ou faites glisser le marqueur.</small>
                            </div>
                        </div>

                        <!-- Personnalisation -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-palette me-1"></i> Personnalisation</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="couleur" class="form-label fw-semibold">Couleur</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-palette"></i></span>
                                    <input type="text" class="form-control" id="couleur" name="couleur" placeholder="#6366f1" value="<?= e($editBoutique['couleur'] ?? '') ?>">
                                    <input type="color" class="form-control form-control-color" id="couleurPicker" value="<?= e($editBoutique['couleur'] ?? '#6366f1') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="etat_boutique" class="form-label fw-semibold">État</label>
                                <select class="form-select" id="etat_boutique" name="etat_boutique">
                                    <option value="Actif" <?= (isset($editBoutique) && $editBoutique['etat_boutique'] === 'Actif') ? 'selected' : '' ?>>Actif</option>
                                    <option value="Inactif" <?= (isset($editBoutique) && $editBoutique['etat_boutique'] === 'Inactif') ? 'selected' : '' ?>>Inactif</option>
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

    <!-- =========================================================
MODAL : CONFIRMATION SUPPRESSION
========================================================= -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius:16px;">
                <div class="modal-body text-center p-4">
                    <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:3rem;"></i></div>
                    <h5 class="modal-title mb-2" style="font-weight:600;">Confirmer la suppression</h5>
                    <p class="text-danger mb-4">Supprimer la boutique <strong id="deleteNomBoutique"></strong> ?<br>Cette action est irréversible.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash3 me-1"></i> Supprimer</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulaires cachés -->
    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="btn_supprimer" value="1">
        <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
    </form>
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
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    <script>
        $(document).ready(function() {
            // --- Initialisation des selectpicker ---
            $('.selectpicker').selectpicker('destroy');
            $('.selectpicker').selectpicker();

            // --- Variables pour la carte ---
            let map = null;
            let marker = null;

            function initMap(lat, lng) {
                if (map) {
                    map.remove();
                    map = null;
                    marker = null;
                }

                const defaultLat = (typeof lat === 'number' && !isNaN(lat)) ? lat : 5.35995;
                const defaultLng = (typeof lng === 'number' && !isNaN(lng)) ? lng : -3.99995;

                map = L.map('map').setView([defaultLat, defaultLng], 13);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                }).addTo(map);

                marker = L.marker([defaultLat, defaultLng], {
                    draggable: true
                }).addTo(map);

                marker.on('dragend', function(e) {
                    const pos = marker.getLatLng();
                    $('#latitude').val(pos.lat.toFixed(6));
                    $('#longitude').val(pos.lng.toFixed(6));
                });

                map.on('click', function(e) {
                    const latlng = e.latlng;
                    marker.setLatLng(latlng);
                    $('#latitude').val(latlng.lat.toFixed(6));
                    $('#longitude').val(latlng.lng.toFixed(6));
                });

                setTimeout(() => {
                    if (map) map.invalidateSize();
                }, 400);
            }

            // --- Ouvrir modal Ajout ---
            $('#addBtn').on('click', function(e) {
                e.preventDefault();
                $('#formAction').val('add');
                $('#oldCode').val('');
                $('#modalTitle').html('<i class="bi bi-shop text-primary me-2"></i> Nouvelle boutique');

                $('#boutiqueForm')[0].reset();
                $('#code_boutique').prop('readonly', true);
                $('#code_boutique').val('<?= generateBoutiqueId($pdo) ?>');
                $('#couleur').val('#6366f1');
                $('#couleurPicker').val('#6366f1');
                $('#latitude').val('');
                $('#longitude').val('');
                $('#etat_boutique').val('Actif');

                const modalEl = document.getElementById('boutiqueModal');
                const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.show();

                modalEl.addEventListener('shown.bs.modal', function onShown() {
                    initMap(5.35995, -3.99995);
                    modalEl.removeEventListener('shown.bs.modal', onShown);
                }, {
                    once: true
                });
            });

            // --- Édition (chargement) ---
            $(document).on('click', '.editBtn', function(e) {
                e.preventDefault();
                const code = $(this).data('code');
                $('#actionField').val('load_edit');
                $('#editCodeField').val(code);
                $('#actionForm').submit();
            });

            // --- Synchronisation couleur ---
            $('#couleurPicker').on('input', function() {
                $('#couleur').val($(this).val());
            });
            $('#couleur').on('input', function() {
                const val = $(this).val().trim();
                if (/^#[0-9a-fA-F]{6}$/.test(val)) {
                    $('#couleurPicker').val(val);
                }
            });

            // --- Toast ---
            function toast(msg, type) {
                const container = $('.container-crud');
                const alertHtml =
                    `<div class="alert alert-${type} alert-dismissible fade show" role="alert">${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
                container.find('.alert').remove();
                container.prepend(alertHtml);
                setTimeout(() => {
                    container.find('.alert').alert('close');
                }, 5000);
            }

            // --- Fonction de recherche AJAX (explicite) ---
            function rechercher(page) {
                page = page || 1;
                var search = $('#searchInput').val();
                var etat = $('#etatFilter').val();

                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        ajax: 1,
                        search: search,
                        etat: etat,
                        page: page
                    },
                    dataType: 'json',
                    success: function(data) {
                        $('#tableBody').html(data.table);
                        $('#paginationContainer').html(data.pagination);
                        $('#totalCount').text(data.total + ' boutique(s) - Page ' + data.page + ' / ' + Math.max(1, data
                            .totalPages));
                        $('.page-link').off('click').on('click', function(e) {
                            e.preventDefault();
                            var p = $(this).data('page');
                            if (p) rechercher(p);
                        });
                        $('.selectpicker').selectpicker('refresh');
                    },
                    error: function(xhr, status, error) {
                        console.error('Erreur AJAX :', status, error);
                        toast('Erreur lors de la recherche.', 'danger');
                    }
                });
            }

            // --- Gestionnaires d'événements ---
            var searchTimeout = null;

            $('#searchInput').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });

            $('#etatFilter').on('changed.bs.select', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });

            $('#filterBtn').on('click', function() {
                rechercher(1);
            });

            $('#resetBtn').on('click', function() {
                $('#searchInput').val('');
                $('#etatFilter').selectpicker('val', '');
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
                $('#deleteNomBoutique').text(nom);
                $('#deleteFormId').val(code);
                new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
            });
            $('#confirmDeleteBtn').on('click', function() {
                $('#deleteForm').submit();
            });

            // Auto-fermeture des alertes
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);

            // --- Si édition via POST (chargement) ---
            <?php if (isset($editBoutique) && $action === 'load_edit'): ?>
                $(function() {
                    $('#formAction').val('edit');
                    $('#oldCode').val('<?= e($editBoutique['code_boutique']) ?>');
                    $('#modalTitle').html('<i class="bi bi-shop text-primary me-2"></i> Modifier la boutique');
                    $('#code_boutique').prop('readonly', true);

                    const modalEl = document.getElementById('boutiqueModal');
                    const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                    modal.show();

                    modalEl.addEventListener('shown.bs.modal', function onShown() {
                        const lat = parseFloat('<?= e($editBoutique['latitude'] ?? '5.35995') ?>') || 5.35995;
                        const lng = parseFloat('<?= e($editBoutique['longitude'] ?? '-3.99995') ?>') || -3.99995;
                        initMap(lat, lng);
                        modalEl.removeEventListener('shown.bs.modal', onShown);
                    }, {
                        once: true
                    });

                    $('.selectpicker').selectpicker('refresh');
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>