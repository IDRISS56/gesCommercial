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

// notification.php
// CRUD pour la table notification – avec Bootstrap SelectPicker

require_once 'databases/database.php';

// --- Récupération des listes pour les selects (utilisateurs) ---
$utilisateurs = $pdo->query("SELECT id, nom_prenom FROM utilisateur WHERE etat = 'Actif' ORDER BY nom_prenom")->fetchAll(PDO::FETCH_ASSOC);

// --- Traitement des actions POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';

if ($action === 'add' || $action === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $objet = trim($_POST['objet'] ?? '');
    $titre = trim($_POST['titre'] ?? '');
    $text = trim($_POST['text'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $user = trim($_POST['user'] ?? '');
    $fichier = trim($_POST['fichier'] ?? '');

    $errors = [];
    if (empty($titre)) $errors[] = 'Le titre est requis.';
    if (empty($text)) $errors[] = 'Le texte est requis.';
    if (empty($date)) $errors[] = 'La date est requise.';

    if (empty($errors)) {
        try {
            if ($action === 'add') {
                $sql = "INSERT INTO notification (objet, titre, text, date, user, fichier)
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$objet, $titre, $text, $date, $user, $fichier]);
                $message = "Notification « $titre » ajoutée avec succès.";
                $messageType = 'success';
            } elseif ($action === 'edit') {
                $oldId = (int)($_POST['old_id'] ?? $id);
                $sql = "UPDATE notification SET objet=?, titre=?, text=?, date=?, user=?, fichier=?
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$objet, $titre, $text, $date, $user, $fichier, $oldId]);
                $message = "Notification « $titre » mise à jour.";
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
    $id = (int)($_POST['sai_supprimer_id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT titre FROM notification WHERE id = ?");
            $stmt->execute([$id]);
            $titre = $stmt->fetchColumn();
            $stmt = $pdo->prepare("DELETE FROM notification WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Notification « $titre » supprimée.";
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
    $sql = "SELECT n.*, u.nom_prenom AS user_nom
            FROM notification n
            LEFT JOIN utilisateur u ON n.user = u.id
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (n.objet LIKE ? OR n.titre LIKE ? OR n.text LIKE ? OR u.nom_prenom LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 4; $i++) $params[] = $like;
    }
    if (!empty($filtres['user'])) {
        $sql .= " AND n.user = ?";
        $params[] = $filtres['user'];
    }
    if (!empty($filtres['date_debut'])) {
        $sql .= " AND n.date >= ?";
        $params[] = $filtres['date_debut'];
    }
    if (!empty($filtres['date_fin'])) {
        $sql .= " AND n.date <= ?";
        $params[] = $filtres['date_fin'];
    }

    $countSql = str_replace("SELECT n.*, u.nom_prenom AS user_nom", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $sql .= " ORDER BY n.date DESC, n.id DESC LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($notifications)): ?>
        <tr>
            <td colspan="6" class="text-center py-5 text-muted">
                <i class="fas fa-inbox fa-2x d-block mb-2 opacity-50"></i>
                Aucune notification trouvée
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($notifications as $notif): ?>
            <tr>
                <td class="td-bold"><?= htmlspecialchars($notif['id']) ?></td>
                <td><?= htmlspecialchars($notif['objet'] ?? '') ?></td>
                <td><?= htmlspecialchars($notif['titre']) ?></td>
                <td><?= htmlspecialchars(substr($notif['text'], 0, 100)) . (strlen($notif['text']) > 100 ? '...' : '') ?></td>
                <td><?= date('d/m/Y H:i', strtotime($notif['date'])) ?></td>
                <td><?= htmlspecialchars($notif['user_nom'] ?? $notif['user']) ?></td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn v viewBtn" data-id="<?= $notif['id'] ?>" title="Voir"><i class="fas fa-eye"></i></button>
                        <button class="act-btn e editBtn" data-id="<?= $notif['id'] ?>" title="Modifier"><i class="fas fa-pen"></i></button>
                        <button class="act-btn d deleteBtn" data-id="<?= $notif['id'] ?>" data-nom="<?= htmlspecialchars($notif['titre']) ?>" title="Supprimer"><i class="fas fa-trash"></i></button>
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
        'user' => trim($_POST['user'] ?? ''),
        'date_debut' => trim($_POST['date_debut'] ?? ''),
        'date_fin' => trim($_POST['date_fin'] ?? '')
    ];
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $filtres, $page);
    sendJson($result);
}

// --- AJAX pour voir une notification ---
if (isset($_POST['ajax_view']) && $_POST['ajax_view'] == '1') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        sendJson(['success' => false, 'message' => 'ID non spécifié']);
    }
    try {
        $stmt = $pdo->prepare("SELECT n.*, u.nom_prenom AS user_nom
                               FROM notification n
                               LEFT JOIN utilisateur u ON n.user = u.id
                               WHERE n.id = ?");
        $stmt->execute([$id]);
        $notif = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($notif) {
            sendJson([
                'success' => true,
                'id' => $notif['id'],
                'objet' => $notif['objet'] ?? '',
                'titre' => $notif['titre'],
                'text' => $notif['text'],
                'date' => date('d/m/Y H:i', strtotime($notif['date'])),
                'user_nom' => $notif['user_nom'] ?? $notif['user'],
                'fichier' => $notif['fichier'] ?? ''
            ]);
        } else {
            sendJson(['success' => false, 'message' => 'Notification non trouvée']);
        }
    } catch (PDOException $e) {
        sendJson(['success' => false, 'message' => $e->getMessage()]);
    }
}

// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$filtres = [
    'user' => trim($_POST['user'] ?? ''),
    'date_debut' => trim($_POST['date_debut'] ?? ''),
    'date_fin' => trim($_POST['date_fin'] ?? '')
];
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $filtres, $page);

// Chargement des données pour l'édition (action load_edit)
$editNotification = null;
if ($action === 'load_edit' && isset($_POST['edit_id'])) {
    $id = (int)$_POST['edit_id'];
    $stmt = $pdo->prepare("SELECT * FROM notification WHERE id = ?");
    $stmt->execute([$id]);
    $editNotification = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des notifications</title>
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
                <h2 class="fw-800 mb-0">Gestion des notifications</h2>
                <p class="text-tertiary mt-1">Consultez et gérez vos notifications</p>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="fas fa-plus"></i> Nouvelle notification</button>
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
                    <div class="col-md-3">
                        <label for="searchInput" class="form-label fw-semibold small">Recherche</label>
                        <div class="search-inline" style="min-width:100%;">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" id="searchInput" placeholder="Objet, titre, texte..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label for="userFilter" class="form-label fw-semibold small">Utilisateur</label>
                        <select name="user" id="userFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un utilisateur...">
                            <option value="">Tous</option>
                            <?php foreach ($utilisateurs as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= ($filtres['user'] == $u['id']) ? 'selected' : '' ?>><?= htmlspecialchars($u['nom_prenom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="date_debut" class="form-label fw-semibold small">Date début</label>
                        <div class="search-inline" style="min-width:100%;">
                            <i class="fas fa-calendar"></i>
                            <input type="date" name="date_debut" id="date_debut" value="<?= htmlspecialchars($filtres['date_debut']) ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label for="date_fin" class="form-label fw-semibold small">Date fin</label>
                        <div class="search-inline" style="min-width:100%;">
                            <i class="fas fa-calendar"></i>
                            <input type="date" name="date_fin" id="date_fin" value="<?= htmlspecialchars($filtres['date_fin']) ?>">
                        </div>
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
                <h5 class="mb-0 fw-bold">Liste des notifications</h5>
                <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> notification(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Objet</th>
                            <th>Titre</th>
                            <th>Texte</th>
                            <th>Date</th>
                            <th>Utilisateur</th>
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
    <div class="modal fade" id="notificationModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-bell text-primary me-2"></i> Nouvelle notification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post" id="notificationForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="old_id" id="oldId" value="">
                    <div class="modal-body">
                        <!-- ID (lecture seule) -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-hashtag me-1"></i> Identification</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="id" class="form-label fw-semibold">ID</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                                    <input type="text" class="form-control" id="id" name="id" readonly value="<?= htmlspecialchars($editNotification['id'] ?? '') ?>">
                                </div>
                                <div class="form-text">L'ID est généré automatiquement</div>
                            </div>
                            <div class="col-md-6">
                                <label for="titre" class="form-label fw-semibold">Titre <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-heading"></i></span>
                                    <input type="text" class="form-control" id="titre" name="titre" placeholder="Titre de la notification" value="<?= htmlspecialchars($editNotification['titre'] ?? '') ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Objet et date -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-tag me-1"></i> Détails</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="objet" class="form-label fw-semibold">Objet</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-tag"></i></span>
                                    <input type="text" class="form-control" id="objet" name="objet" placeholder="Objet (optionnel)" value="<?= htmlspecialchars($editNotification['objet'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="date" class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                    <input type="datetime-local" class="form-control" id="date" name="date" value="<?= isset($editNotification) ? date('Y-m-d\TH:i', strtotime($editNotification['date'])) : date('Y-m-d\TH:i') ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Texte (long) -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-align-left me-1"></i> Texte</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-12">
                                <label for="text" class="form-label fw-semibold">Contenu <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-pencil-alt"></i></span>
                                    <textarea class="form-control" id="text" name="text" rows="5" placeholder="Contenu de la notification..." required><?= htmlspecialchars($editNotification['text'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Utilisateur et fichier -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-user me-1"></i> Association</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="user" class="form-label fw-semibold">Utilisateur</label>
                                <select class="selectpicker form-control" id="user" name="user" data-live-search="true" data-live-search-placeholder="Rechercher un utilisateur...">
                                    <option value="">=== Aucun ===</option>
                                    <?php foreach ($utilisateurs as $u): ?>
                                        <option value="<?= $u['id'] ?>" <?= (isset($editNotification) && $editNotification['user'] == $u['id']) ? 'selected' : '' ?>><?= htmlspecialchars($u['nom_prenom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="fichier" class="form-label fw-semibold">Fichier (nom)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-file"></i></span>
                                    <input type="text" class="form-control" id="fichier" name="fichier" placeholder="Nom du fichier joint" value="<?= htmlspecialchars($editNotification['fichier'] ?? '') ?>">
                                </div>
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
                    <h5 class="modal-title fw-bold" id="viewModalLabel">Détails de la notification</h5>
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
                    <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer la notification <strong id="deleteNomNotification"></strong> ?<br>Cette action est irréversible.</p>
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

    <!-- Formulaire caché pour action edit (chargement) -->
    <form method="post" id="actionForm">
        <input type="hidden" name="action" id="actionField">
        <input type="hidden" name="edit_id" id="editIdField">
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
            // destroy avant init : évite le doublon visuel si ce contenu est
            // rechargé dans le DOM sans rechargement complet de la page.
            $('.selectpicker').selectpicker('destroy');
            $('.selectpicker').selectpicker();

            // --- Ouvrir modal Ajout ---
            $('#addBtn').on('click', function(e) {
                e.preventDefault();
                $('#formAction').val('add');
                $('#oldId').val('');
                $('#modalTitle').html('<i class="fas fa-bell text-primary me-2"></i> Nouvelle notification');
                $('#notificationForm')[0].reset();
                $('#id').val('');

                // Vidage manuel : reset() ne suffit pas, car ces champs ont leur
                // value écrite par PHP côté serveur (pré-remplis lors d'une édition
                // précédente) et reset() les restaure au lieu de les vider.
                $('#titre').val('');
                $('#objet').val('');
                $('#text').val('');
                $('#fichier').val('');

                // Date par défaut
                var now = new Date();
                var offset = now.getTimezoneOffset() * 60000;
                var localISOTime = (new Date(Date.now() - offset)).toISOString().slice(0, 16);
                $('#date').val(localISOTime);

                // Réinitialiser uniquement le select du formulaire, pas celui du
                // filtre de recherche (qui partage aussi la classe .selectpicker)
                $('#user').selectpicker('val', '');
                var modalEl = document.getElementById('notificationModal');
                var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.show();
            });

            // --- Édition (chargement via formulaire POST) ---
            $(document).on('click', '.editBtn', function(e) {
                e.preventDefault();
                const id = $(this).data('id');
                $('#actionField').val('load_edit');  // action modifiée
                $('#editIdField').val(id);
                $('#actionForm').submit();
            });

            // --- Vue en AJAX ---
            $(document).on('click', '.viewBtn', function(e) {
                e.preventDefault();
                const id = $(this).data('id');
                $('#viewModal').modal('hide');
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        ajax_view: '1',
                        id: id
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#viewModalLabel').text('Notification #' + response.id);
                            const fields = [
                                ['ID', response.id],
                                ['Objet', response.objet],
                                ['Titre', response.titre],
                                ['Texte', response.text],
                                ['Date', response.date],
                                ['Utilisateur', response.user_nom],
                                ['Fichier', response.fichier]
                            ];
                            let html = '';
                            fields.forEach(([label, value]) => {
                                let val = value || '—';
                                html += '<div class="col-sm-6"><div class="bg-light p-3 rounded-3 border"><div class="text-muted small text-uppercase fw-bold">' + label + '</div><div class="fw-semibold">' + val + '</div></div></div>';
                            });
                            $('#viewGrid').html(html);
                            $('#viewModal').modal('show');
                        } else {
                            alert('Erreur : ' + (response.message || 'Notification non trouvée'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Statut :', status);
                        console.error('Réponse brute :', xhr.responseText);
                        alert('Erreur lors de la récupération des données (code ' + xhr.status + '). Voir console pour détails.');
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
                        $('#totalCount').text(data.total + ' notification(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
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

            // Auto-submit pour le champ recherche
            var searchTimeout = null;
            $('#searchInput').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });

            // Pour les selectpicker du filtre
            $('#userFilter').on('changed.bs.select', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });

            // Champs date
            $('#date_debut, #date_fin').on('change', function() {
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
                $('#userFilter').selectpicker('val', '');
                $('#date_debut').val('');
                $('#date_fin').val('');
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
                const id = $(this).data('id');
                const nom = $(this).data('nom');
                $('#deleteNomNotification').text(nom);
                $('#deleteFormId').val(id);
                $('#deleteConfirmModal').modal('show');
            });
            $('#confirmDeleteBtn').on('click', function() {
                $('#deleteForm').submit();
            });

            // Auto-fermeture des alertes
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);

            // --- Si édition via POST (chargement des données) ---
            <?php if (isset($editNotification) && $action === 'load_edit'): ?>
                $(function() {
                    $('#formAction').val('edit');
                    $('#oldId').val('<?= $editNotification['id'] ?>');
                    $('#modalTitle').html('<i class="fas fa-bell text-primary me-2"></i> Modifier la notification');
                    $('#id').val('<?= $editNotification['id'] ?>');
                    $('.selectpicker').selectpicker('refresh');
                    var modalEl = document.getElementById('notificationModal');
                    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                    modal.show();
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>