<?php
ob_start(); // Capture toute sortie parasite (BOM, espaces, etc.)

// Fonction utilitaire pour envoyer une réponse JSON propre
function sendJson($data)
{
    // Supprimer tous les buffers de sortie actifs
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// views/caisse/index.php – Gestion des caisses (design dashboard)
require __DIR__ . '/../../databases/database.php';
session_start();
// Vérification session
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
function fmtDec($n)
{
    return number_format(floatval($n), 2, ',', ' ');
}
// --- Génération d'ID auto pour caisse ---
function generateCaisseId($pdo)
{
    $date = date('Ymd');
    $prefix = 'C-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM caisse WHERE code_caisse LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}
// --- Token CSRF ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
// --- Récupération des utilisateurs pour les select ---
$utilisateurs = $pdo->query("SELECT id, nom_prenom, login FROM utilisateur ORDER BY nom_prenom")->fetchAll(PDO::FETCH_ASSOC);
// --- Traitement des actions POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add' || $action === 'edit')) {
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'danger';
    } else {
        $code = trim($_POST['code_caisse'] ?? '');
        $titre = trim($_POST['titre_caisse'] ?? '');
        $solde_virtuel = floatval(str_replace(',', '.', $_POST['solde_virtuel'] ?? 0));
        $solde_physique = floatval(str_replace(',', '.', $_POST['solde_physique'] ?? 0));
        $utilisateur_id = trim($_POST['utilisateur_id'] ?? '');
        $etat = trim($_POST['etat_caisse'] ?? 'Actif');
        $errors = [];
        if (empty($code)) $errors[] = 'Le code caisse est requis.';
        if (empty($titre)) $errors[] = 'Le titre est requis.';
        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    $code = generateCaisseId($pdo);
                    $sql = "INSERT INTO caisse (code_caisse, titre_caisse, solde_virtuel, solde_physique, utilisateur_id, etat_caisse)
                            VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$code, $titre, $solde_virtuel, $solde_physique, $utilisateur_id, $etat]);
                    $message = "Caisse « $titre » ajoutée avec succès. ID : $code";
                    $messageType = 'success';
                } elseif ($action === 'edit') {
                    $oldCode = $_POST['old_code'] ?? $code;
                    $sql = "UPDATE caisse SET titre_caisse=?, solde_virtuel=?, solde_physique=?, utilisateur_id=?, etat_caisse=?
                            WHERE code_caisse = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$titre, $solde_virtuel, $solde_physique, $utilisateur_id, $etat, $oldCode]);
                    $message = "Caisse « $titre » mise à jour.";
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
// Suppression
if (isset($_POST['btn_supprimer']) && $_POST['btn_supprimer'] == '1') {
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'danger';
    } else {
        $code = $_POST['sai_supprimer_id'] ?? '';
        if (!empty($code)) {
            try {
                $stmt = $pdo->prepare("SELECT titre_caisse FROM caisse WHERE code_caisse = ?");
                $stmt->execute([$code]);
                $titre = $stmt->fetchColumn();
                $stmt = $pdo->prepare("DELETE FROM caisse WHERE code_caisse = ?");
                $stmt->execute([$code]);
                $message = "Caisse « $titre » supprimée.";
                $messageType = 'danger';
            } catch (PDOException $e) {
                $message = "Erreur : " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}
// --- Fonction de génération du tableau (AJAX) ---
function getTableContent($pdo, $search, $utilisateur_filter, $page, $perPage = 20)
{
    $sql = "SELECT c.*, u.nom_prenom AS utilisateur_nom
            FROM caisse c
            LEFT JOIN utilisateur u ON c.utilisateur_id = u.id
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (c.code_caisse LIKE ? OR c.titre_caisse LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($utilisateur_filter)) {
        $sql .= " AND c.utilisateur_id = ?";
        $params[] = $utilisateur_filter;
    }
    $countSql = str_replace("SELECT c.*, u.nom_prenom AS utilisateur_nom", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    $sql .= " ORDER BY c.code_caisse LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $caisses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ob_start();
    if (empty($caisses)): ?>
        <tr>
            <td colspan="7" class="text-center py-5 text-muted">
                <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>
                Aucune caisse trouvée
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($caisses as $c): ?>
            <tr>
                <td class="td-bold"><?= e($c['code_caisse']) ?></td>
                <td class="td-semi"><?= e($c['titre_caisse']) ?></td>
                <td><?= fmtDec($c['solde_virtuel']) ?></td>
                <td><?= fmtDec($c['solde_physique']) ?></td>
                <td><?= e($c['utilisateur_nom'] ?? '—') ?></td>
                <td>
                    <span class="status-badge <?= $c['etat_caisse'] === 'Actif' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= e($c['etat_caisse']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <!-- Bouton "Voir" supprimé -->
                        <button class="act-btn e editBtn" data-code="<?= e($c['code_caisse']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= e($c['code_caisse']) ?>" data-nom="<?= e($c['titre_caisse']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash3"></i></button>
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
// --- AJAX pour le tableau ---
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $utilisateur_filter = trim($_POST['utilisateur_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $utilisateur_filter, $page);
    sendJson($result);
}
// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$utilisateur_filter = trim($_POST['utilisateur_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $utilisateur_filter, $page);

// Chargement des données pour l'édition (action load_edit)
$editCaisse = null;
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM caisse WHERE code_caisse = ?");
    $stmt->execute([$code]);
    $editCaisse = $stmt->fetch(PDO::FETCH_ASSOC);
}
$newId = generateCaisseId($pdo);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des caisses</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons & Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap SelectPicker -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
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
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            padding: 30px 20px;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .container-crud { max-width: 1400px; margin: 0 auto; }
        .data-table-wrap {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .table > :not(caption) > * > * { padding: 12px 18px; }
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
        .table tbody tr:hover { background: var(--color-primary-soft); }
        .table tbody td {
            vertical-align: middle;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        .td-bold { color: var(--text-primary) !important; font-weight: 700; }
        .td-semi { color: var(--text-primary) !important; font-weight: 500; }
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
            width: 6px; height: 6px;
            border-radius: 50%;
            background: currentColor;
        }
        .status-badge.on  { background: var(--color-success-soft); color: #059669; }
        .status-badge.off { background: var(--color-danger-soft);  color: #dc2626; }
        .act-btn {
            width: 34px; height: 34px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-quaternary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-base);
        }
        .act-btn:hover { transform: scale(1.1); }
        .act-btn.e:hover { color: var(--color-warning); background: var(--color-warning-soft); border-color: rgba(245, 158, 11, .15); }
        .act-btn.d:hover { color: var(--color-danger);  background: var(--color-danger-soft);  border-color: rgba(239, 68, 68, .15); }
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
            box-shadow: 0 0 0 4px rgba(79, 70, 229, .08);
        }
        .search-inline i { color: var(--text-quaternary); font-size: 0.8rem; }
        .search-inline input, .search-inline select {
            background: none; border: none; outline: none;
            color: var(--text-primary);
            font-size: 0.85rem; font-family: inherit;
            width: 100%; margin-left: 10px;
        }
        .search-inline select { padding-right: 20px; cursor: pointer; }
        .search-inline input::placeholder { color: var(--text-quaternary); }
        .btn-primary { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
        .btn-primary:hover { background: var(--color-primary-dark); border-color: var(--color-primary-dark); color: #fff; }
        .btn-outline-secondary { color: var(--text-secondary); border-color: var(--border-color); }
        .btn-outline-secondary:hover { background: var(--color-gray-100); border-color: var(--color-gray-300); }
        .modal-content { border-radius: var(--radius-md); border: none; box-shadow: var(--shadow-lg); }
        .modal-header { border-bottom: 1px solid var(--border-color); background: var(--bg-muted); }
        .modal-footer { border-top: 1px solid var(--border-color); background: var(--bg-muted); }
        .page-heading h2 { font-weight: 800; }
        .text-tertiary { color: var(--text-tertiary); }
        .pagination .page-link {
            color: var(--color-primary);
            border: 1px solid var(--border-color);
            border-radius: 6px; margin: 0 2px;
            padding: 6px 14px; font-weight: 500;
        }
        .pagination .page-link:hover { background: var(--color-primary-soft); border-color: var(--color-primary); }
        .pagination .page-item.active .page-link { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
        .pagination .page-item.disabled .page-link { color: var(--text-quaternary); border-color: var(--border-color); }
        .bootstrap-select .dropdown-toggle .filter-option { color: var(--text-primary); }
        .bootstrap-select .dropdown-menu { border-radius: var(--radius-sm); border-color: var(--border-color); }
        .bootstrap-select .dropdown-menu .bs-searchbox input { border-radius: 6px; border: 1px solid var(--border-color); padding: 8px 12px; }
        .bootstrap-select .dropdown-menu .bs-searchbox input:focus { border-color: var(--color-primary); box-shadow: 0 0 0 4px rgba(79, 70, 229, .08); }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        .data-table-wrap { animation: fadeUp .4s ease both; }
        @media (max-width:700px) {
            body { padding: 14px; }
            .search-inline { min-width: auto; }
        }
    </style>
</head>
<body>
<div class="container-crud">
    <!-- En-tête -->
    <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
        <div class="page-heading">
            <h2 class="fw-800 mb-0">Gestion des caisses</h2>
            <p class="text-tertiary mt-1">Gérez les caisses et leurs soldes</p>
        </div>
        <div>
            <button class="btn btn-primary btn-sm" id="addBtn"><i class="bi bi-plus-circle"></i> Nouvelle caisse</button>
        </div>
    </div>
    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <!-- Barre de recherche / filtres -->
    <div class="bg-light p-3 rounded-3 mb-3 border">
        <form id="searchForm" method="post" onsubmit="return false;">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="searchInput" class="form-label fw-semibold small">Recherche</label>
                    <div class="search-inline" style="min-width:100%;">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" id="searchInput" placeholder="Code, titre..." value="<?= e($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label for="utilisateurFilter" class="form-label fw-semibold small">Utilisateur</label>
                    <select name="utilisateur_filter" id="utilisateurFilter" class="selectpicker form-control"
                            data-live-search="true"
                            data-live-search-placeholder="Rechercher un utilisateur..."
                            data-none-selected-text="Tous">
                        <option value="">Tous</option>
                        <?php foreach ($utilisateurs as $u): ?>
                            <option value="<?= e($u['id']) ?>" <?= ($utilisateur_filter == $u['id']) ? 'selected' : '' ?>>
                                <?= e($u['nom_prenom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-primary w-100" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-secondary w-100" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
                </div>
            </div>
        </form>
    </div>
    <!-- Table -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold">Liste des caisses</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> caisse(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Titre</th>
                    <th>Solde virtuel</th>
                    <th>Solde physique</th>
                    <th>Utilisateur</th>
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
<div class="modal fade" id="caisseModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-cash-register text-primary me-2"></i> Nouvelle caisse</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form method="post" id="caisseForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_code" id="oldCode" value="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <!-- Identification -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-tag me-1"></i> Identification</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="code_caisse" class="form-label fw-semibold">Code caisse</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="code_caisse" name="code_caisse" readonly value="<?= e($editCaisse['code_caisse'] ?? $newId) ?>">
                            </div>
                            <div class="form-text">ID généré automatiquement</div>
                        </div>
                        <div class="col-md-6">
                            <label for="titre_caisse" class="form-label fw-semibold">Titre <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-cash-register"></i></span>
                                <input type="text" class="form-control" id="titre_caisse" name="titre_caisse" required placeholder="Nom de la caisse" value="<?= e($editCaisse['titre_caisse'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <!-- Soldes -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-coins me-1"></i> Soldes</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="solde_virtuel" class="form-label fw-semibold">Solde virtuel</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-wallet2"></i></span>
                                <input type="number" step="0.01" class="form-control" id="solde_virtuel" name="solde_virtuel" placeholder="0.00" value="<?= e($editCaisse['solde_virtuel'] ?? '0') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="solde_physique" class="form-label fw-semibold">Solde physique</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-cash-stack"></i></span>
                                <input type="number" step="0.01" class="form-control" id="solde_physique" name="solde_physique" placeholder="0.00" value="<?= e($editCaisse['solde_physique'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <!-- Utilisateur -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-person me-1"></i> Utilisateur responsable</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="utilisateur_id" class="form-label fw-semibold">Utilisateur</label>
                            <select class="selectpicker form-control" id="utilisateur_id" name="utilisateur_id"
                                    data-live-search="true"
                                    data-live-search-placeholder="Rechercher un utilisateur..."
                                    data-none-selected-text="=== Faites votre choix ===">
                                <option value="">Aucun</option>
                                <?php foreach ($utilisateurs as $u): ?>
                                    <option value="<?= e($u['id']) ?>" <?= (isset($editCaisse) && $editCaisse['utilisateur_id'] == $u['id']) ? 'selected' : '' ?>>
                                        <?= e($u['nom_prenom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <!-- État -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-toggle-on me-1"></i> Statut</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="etat_caisse" class="form-label fw-semibold">État</label>
                            <select class="form-select" id="etat_caisse" name="etat_caisse">
                                <option value="Actif"   <?= (isset($editCaisse) && $editCaisse['etat_caisse'] === 'Actif')   ? 'selected' : '' ?>>Actif</option>
                                <option value="Inactif" <?= (isset($editCaisse) && $editCaisse['etat_caisse'] === 'Inactif') ? 'selected' : '' ?>>Inactif</option>
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
                <p class="text-danger mb-4">Supprimer la caisse <strong id="deleteNomCaisse"></strong> ?<br>Cette action est irréversible.</p>
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
<script>
$(document).ready(function() {
    // Initialisation des selectpicker
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();
    const caisseModal = new bootstrap.Modal(document.getElementById('caisseModal'));
    // --- Ajout ---
    $('#addBtn').on('click', function() {
        $('#formAction').val('add');
        $('#oldCode').val('');
        $('#modalTitle').html('<i class="bi bi-cash-register text-primary me-2"></i> Nouvelle caisse');
        $('#caisseForm')[0].reset();
        $('#code_caisse').prop('readonly', true);
        $('#code_caisse').val('<?= $newId ?>');
        $('#solde_virtuel, #solde_physique').val('0');
        $('#etat_caisse').val('Actif');
        $('#utilisateur_id').selectpicker('val', '');
        caisseModal.show();
    });
    // --- Édition (chargement via formulaire POST) ---
    $(document).on('click', '.editBtn', function() {
        const code = $(this).data('code');
        $('#actionField').val('load_edit');  // action modifiée
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
                $('#totalCount').text(data.total + ' caisse(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
                $('.page-link').off('click').on('click', function(e) {
                    e.preventDefault();
                    var p = $(this).data('page');
                    if (p) rechercher(p);
                });
                $('.selectpicker').selectpicker('refresh');
            },
            error: function(xhr, status, error) {
                console.error('Statut :', status);
                console.error('Réponse brute :', xhr.responseText);
                alert('Erreur lors de la recherche (code ' + xhr.status + '). Voir console pour détails.');
            }
        });
    }
    // Auto-submit
    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#utilisateurFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#filterBtn').on('click', function() { rechercher(1); });
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#utilisateurFilter').selectpicker('val', '');
        rechercher(1);
    });
    // Pagination initiale
    $('.page-link').on('click', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        if (page) rechercher(page);
    });
    // --- Suppression ---
    $(document).on('click', '.deleteBtn', function() {
        const code = $(this).data('code');
        const nom = $(this).data('nom');
        $('#deleteNomCaisse').text(nom);
        $('#deleteFormId').val(code);
    });
    $('#confirmDeleteBtn').on('click', function() { $('#deleteForm').submit(); });
    // Auto-fermeture des alertes
    setTimeout(function() { $('.alert').alert('close'); }, 5000);
    // --- Si édition via POST (chargement des données) ---
    <?php if (isset($editCaisse) && $action === 'load_edit'): ?>
    $(function() {
        $('#formAction').val('edit');
        $('#oldCode').val('<?= e($editCaisse['code_caisse']) ?>');
        $('#modalTitle').html('<i class="bi bi-cash-register text-primary me-2"></i> Modifier la caisse');
        $('#code_caisse').prop('readonly', true);
        $('#utilisateur_id').selectpicker('refresh');
        caisseModal.show();
    });
    <?php endif; ?>
});
</script>
</body>
</html>