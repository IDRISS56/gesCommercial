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

// views/visiteurs/index.php – Gestion des visiteurs
session_start();
if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax']) || isset($_POST['action'])) {
        sendJson(['error' => 'Non authentifié']);
    }
    header('Location: ../utilisateur/login');
    exit;
}

require_once 'databases/database.php';

$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    if (isset($_POST['ajax']) || isset($_POST['action'])) {
        sendJson(['error' => 'Utilisateur inactif']);
    }
    header('Location: ../utilisateur/login');
    exit;
}

function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---- TRAITEMENT DES ACTIONS AJAX (édition uniquement) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'get_visiteur_edit') {
        if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
            sendJson(['error' => 'ID invalide']);
        }
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("SELECT * FROM visiteur WHERE id = ?");
            $stmt->execute([$id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$data) {
                sendJson(['error' => 'Visiteur non trouvé']);
            }
            sendJson($data);
        } catch (Exception $e) {
            sendJson(['error' => $e->getMessage()]);
        }
    }
}

// ---- TRAITEMENT DES FORMULAIRES (ajout, modif, suppression) ----
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        $action = $_POST['action'];
        if ($action === 'ajouter_visiteur') {
            $date_connexion = $_POST['date_connexion'] ?? date('Y-m-d');
            $heure_connexion = $_POST['heure_connexion'] ?? date('H:i:s');
            $date_deconnexion = $_POST['date_deconnexion'] ?? null;
            $heure_deconnexion = $_POST['heure_deconnexion'] ?? null;
            $reference = trim($_POST['reference'] ?? '');
            $etat_connexion = trim($_POST['etat_connexion'] ?? '');
            $duree_date = 0;
            $duree_heure = '00:00:00';
            if ($date_deconnexion && $heure_deconnexion) {
                $debut = new DateTime($date_connexion . ' ' . $heure_connexion);
                $fin = new DateTime($date_deconnexion . ' ' . $heure_deconnexion);
                $diff = $debut->diff($fin);
                $duree_date = $diff->days;
                $duree_heure = $diff->format('%H:%I:%S');
            }
            $stmt = $pdo->prepare("INSERT INTO visiteur (date_connexion, heure_connexion, date_deconnexion, heure_deconnexion, duree_date, duree_heure, reference, etat_connexion) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$date_connexion, $heure_connexion, $date_deconnexion, $heure_deconnexion, $duree_date, $duree_heure, $reference, $etat_connexion]);
            $message = "Visiteur enregistré avec succès.";
            $messageType = 'success';
        } elseif ($action === 'modifier_visiteur') {
            $id = (int)$_POST['id'];
            $date_connexion = $_POST['date_connexion'];
            $heure_connexion = $_POST['heure_connexion'];
            $date_deconnexion = $_POST['date_deconnexion'] ?? null;
            $heure_deconnexion = $_POST['heure_deconnexion'] ?? null;
            $reference = trim($_POST['reference'] ?? '');
            $etat_connexion = trim($_POST['etat_connexion'] ?? '');
            $duree_date = 0;
            $duree_heure = '00:00:00';
            if ($date_deconnexion && $heure_deconnexion) {
                $debut = new DateTime($date_connexion . ' ' . $heure_connexion);
                $fin = new DateTime($date_deconnexion . ' ' . $heure_deconnexion);
                $diff = $debut->diff($fin);
                $duree_date = $diff->days;
                $duree_heure = $diff->format('%H:%I:%S');
            }
            $stmt = $pdo->prepare("UPDATE visiteur SET date_connexion=?, heure_connexion=?, date_deconnexion=?, heure_deconnexion=?, duree_date=?, duree_heure=?, reference=?, etat_connexion=? WHERE id=?");
            $stmt->execute([$date_connexion, $heure_connexion, $date_deconnexion, $heure_deconnexion, $duree_date, $duree_heure, $reference, $etat_connexion, $id]);
            $message = "Visiteur modifié avec succès.";
            $messageType = 'success';
        } elseif ($action === 'supprimer_visiteur') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM visiteur WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Visiteur supprimé.";
            $messageType = 'success';
        }
    }
}

// ---- RÉCUPÉRATION DES VISITEURS AVEC FILTRES ----
function getVisiteurs($pdo, $search, $etat_filter, $page, $perPage = 10)
{
    $sql = "SELECT * FROM visiteur WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (reference LIKE ? OR etat_connexion LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($etat_filter)) {
        $sql .= " AND etat_connexion = ?";
        $params[] = $etat_filter;
    }
    $sql .= " ORDER BY date_connexion DESC, heure_connexion DESC";
    $countSql = "SELECT COUNT(*) FROM (" . $sql . ") AS sub";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $sql .= " LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $visiteurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return ['visiteurs' => $visiteurs, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages];
}

$search = trim($_POST['search'] ?? '');
$etat_filter = trim($_POST['etat_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$data = getVisiteurs($pdo, $search, $etat_filter, $page, 10);

// ---- AJAX (recherche/pagination) ----
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $data = getVisiteurs($pdo, $search, $etat_filter, $page, 10);
    ob_start();
    if (empty($data['visiteurs'])): ?>
        <tr>
            <td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>Aucun visiteur trouvé</td>
        </tr>
        <?php else: foreach ($data['visiteurs'] as $v): ?>
            <tr>
                <td><?= e($v['id']) ?></td>
                <td><?= e($v['date_connexion']) . ' ' . e($v['heure_connexion']) ?></td>
                <td><?= e($v['date_deconnexion']) ? e($v['date_deconnexion'] . ' ' . $v['heure_deconnexion']) : '—' ?></td>
                <td><?php $duree = $v['duree_date'] > 0 ? $v['duree_date'] . 'j ' : '';
                    $duree .= $v['duree_heure'] ?? '00:00:00';
                    echo e($duree); ?></td>
                <td><?= e($v['reference']) ?></td>
                <td><span class="status-badge <?= strtolower($v['etat_connexion']) == 'terminee' ? 'off' : 'on' ?>"><span class="sdot"></span> <?= e($v['etat_connexion'] ?? '—') ?></span></td>
                <td class="text-end">
                    <!-- Bouton Voir supprimé -->
                    <button class="act-btn e editBtn" data-id="<?= e($v['id']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Supprimer ce visiteur ?');">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="supprimer_visiteur">
                        <input type="hidden" name="id" value="<?= e($v['id']) ?>">
                        <button type="submit" class="act-btn d" title="Supprimer"><i class="bi bi-trash3"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach;
    endif;
    $tableHtml = ob_get_clean();
    ob_start();
    if ($data['totalPages'] > 1): ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
            <span class="text-muted small">Affichage de <?= (($data['page'] - 1) * 10 + 1) ?> à <?= min($data['page'] * 10, $data['total']) ?> sur <?= $data['total'] ?></span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($data['page'] <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $data['page'] - 1 ?>"><i class="bi bi-chevron-left"></i></a></li>
                    <?php $start = max(1, $data['page'] - 2);
                    $end = min($data['totalPages'], $data['page'] + 2);
                    if ($start > 1) {
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                        if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= ($i == $data['page']) ? 'active' : '' ?>"><a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a></li>
                    <?php endfor;
                    if ($end < $data['totalPages']) {
                        if ($end < $data['totalPages'] - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $data['totalPages'] . '">' . $data['totalPages'] . '</a></li>';
                    } ?>
                    <li class="page-item <?= ($data['page'] >= $data['totalPages']) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $data['page'] + 1 ?>"><i class="bi bi-chevron-right"></i></a></li>
                </ul>
            </nav>
        </div>
<?php endif;
    $paginationHtml = ob_get_clean();
    sendJson(['table' => $tableHtml, 'pagination' => $paginationHtml, 'total' => $data['total'], 'page' => $data['page'], 'totalPages' => $data['totalPages']]);
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des visiteurs</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* === STYLES (identiques à l'exemple) === */
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

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            min-width: 900px;
            margin-bottom: 0;
        }

        .table> :not(caption)>*>* {
            padding: 8px 12px;
        }

        .table thead th {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-quaternary);
            background: var(--bg-muted);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
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
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 12px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
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
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-quaternary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-base);
            font-size: 0.8rem;
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
            padding: 0 12px;
            height: 42px;
            min-width: 160px;
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
            margin-left: 8px;
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

        @media (max-width:768px) {
            body {
                padding: 10px;
            }

            .table thead th,
            .table tbody td {
                font-size: 0.65rem;
                padding: 4px 6px;
            }

            .table .act-btn {
                width: 26px;
                height: 26px;
                font-size: 0.65rem;
            }

            .status-badge {
                font-size: 0.6rem;
                padding: 0 8px;
            }

            .page-heading h2 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>

<body>
    <div class="container-crud">
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
            <div class="page-heading">
                <h2 class="fw-800 mb-0">Gestion des visiteurs</h2>
                <p class="text-tertiary mt-1">Suivi des connexions / déconnexions</p>
            </div>
            <div><button class="btn btn-primary btn-sm" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau visiteur</button></div>
        </div>
        <?php if ($message): ?><div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <div class="bg-light p-3 rounded-3 mb-3 border">
            <form id="searchForm" method="post" onsubmit="return false;">
                <input type="hidden" name="ajax" value="1"><input type="hidden" name="page" id="pageInput" value="<?= e($page) ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Recherche</label>
                        <div class="search-inline" style="min-width:100%; height:42px;"><i class="bi bi-search"></i><input type="text" name="search" id="searchInput" placeholder="Référence, état..." value="<?= e($search) ?>"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">État</label>
                        <select name="etat_filter" id="etatFilter" class="form-select">
                            <option value="">Tous</option>
                            <option value="Terminée" <?= ($etat_filter == 'Terminée') ? 'selected' : '' ?>>Terminée</option>
                            <option value="En cours" <?= ($etat_filter == 'En cours') ? 'selected' : '' ?>>En cours</option>
                            <option value="Annulée" <?= ($etat_filter == 'Annulée') ? 'selected' : '' ?>>Annulée</option>
                        </select>
                    </div>
                    <div class="col-md-2"><button type="button" class="btn btn-primary w-100" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button></div>
                    <div class="col-md-1"><button type="button" class="btn btn-outline-secondary w-100" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button></div>
                </div>
            </form>
        </div>
        <div class="data-table-wrap" id="tableWrapper">
            <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
                <h5 class="mb-0 fw-bold">Liste des visiteurs</h5>
                <span class="text-muted small" id="totalCount"><?= $data['total'] ?? 0 ?> visiteur(s) - Page <?= $data['page'] ?? 1 ?> / <?= max(1, $data['totalPages'] ?? 1) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Connexion</th>
                            <th>Déconnexion</th>
                            <th>Durée</th>
                            <th>Référence</th>
                            <th>État</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (empty($data['visiteurs'])): ?><tr>
                                <td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>Aucun visiteur trouvé</td>
                            </tr>
                            <?php else: foreach ($data['visiteurs'] as $v): ?>
                                <tr>
                                    <td><?= e($v['id']) ?></td>
                                    <td><?= e($v['date_connexion']) . ' ' . e($v['heure_connexion']) ?></td>
                                    <td><?= e($v['date_deconnexion']) ? e($v['date_deconnexion'] . ' ' . $v['heure_deconnexion']) : '—' ?></td>
                                    <td><?php $duree = $v['duree_date'] > 0 ? $v['duree_date'] . 'j ' : '';
                                        $duree .= $v['duree_heure'] ?? '00:00:00';
                                        echo e($duree); ?></td>
                                    <td><?= e($v['reference']) ?></td>
                                    <td><span class="status-badge <?= strtolower($v['etat_connexion']) == 'terminee' ? 'off' : 'on' ?>"><span class="sdot"></span> <?= e($v['etat_connexion'] ?? '—') ?></span></td>
                                    <td class="text-end">
                                        <button class="act-btn e editBtn" data-id="<?= e($v['id']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Supprimer ce visiteur ?');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="action" value="supprimer_visiteur">
                                            <input type="hidden" name="id" value="<?= e($v['id']) ?>">
                                            <button type="submit" class="act-btn d" title="Supprimer"><i class="bi bi-trash3"></i></button>
                                        </form>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="paginationContainer"><?php if ($data['totalPages'] > 1): ?><div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light"><span class="text-muted small">Affichage de <?= (($data['page'] - 1) * 10 + 1) ?> à <?= min($data['page'] * 10, $data['total']) ?> sur <?= $data['total'] ?></span>
                        <nav>
                            <ul class="pagination pagination-sm mb-0"><?php $start = max(1, $data['page'] - 2);
                                                                        $end = min($data['totalPages'], $data['page'] + 2);
                                                                        if ($start > 1) {
                                                                            echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                                                                            if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                                                        }
                                                                        for ($i = $start; $i <= $end; $i++): ?><li class="page-item <?= ($i == $data['page']) ? 'active' : '' ?>"><a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a></li><?php endfor;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        if ($end < $data['totalPages']) {
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            if ($end < $data['totalPages'] - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $data['totalPages'] . '">' . $data['totalPages'] . '</a></li>';
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        } ?><li class="page-item <?= ($data['page'] >= $data['totalPages']) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $data['page'] + 1 ?>"><i class="bi bi-chevron-right"></i></a></li>
                            </ul>
                        </nav>
                    </div><?php endif; ?></div>
        </div>
    </div>

    <!-- Modal Ajouter/Modifier -->
    <div class="modal fade" id="visiteurModal" tabindex="-1" aria-labelledby="visiteurModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="visiteurModalLabel"><i class="bi bi-person text-primary me-2"></i> <span id="modalTitle">Nouveau visiteur</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="visiteurForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" id="formAction" value="ajouter_visiteur">
                    <input type="hidden" name="id" id="editId" value="">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label fw-semibold">Date de connexion</label><input type="date" name="date_connexion" id="date_connexion" class="form-control" required></div>
                            <div class="col-md-6"><label class="form-label fw-semibold">Heure de connexion</label><input type="time" name="heure_connexion" id="heure_connexion" class="form-control" required></div>
                            <div class="col-md-6"><label class="form-label fw-semibold">Date de déconnexion</label><input type="date" name="date_deconnexion" id="date_deconnexion" class="form-control"></div>
                            <div class="col-md-6"><label class="form-label fw-semibold">Heure de déconnexion</label><input type="time" name="heure_deconnexion" id="heure_deconnexion" class="form-control"></div>
                            <div class="col-md-6"><label class="form-label fw-semibold">Référence</label><input type="text" name="reference" id="reference" class="form-control" placeholder="Ex: VIS001"></div>
                            <div class="col-md-6"><label class="form-label fw-semibold">État</label><select name="etat_connexion" id="etat_connexion" class="form-select">
                                    <option value="En cours">En cours</option>
                                    <option value="Terminée">Terminée</option>
                                    <option value="Annulée">Annulée</option>
                                </select></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x"></i> Annuler</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            const visiteurModal = new bootstrap.Modal(document.getElementById('visiteurModal'));

            $('#addBtn').on('click', function() {
                $('#modalTitle').text('Nouveau visiteur');
                $('#formAction').val('ajouter_visiteur');
                $('#editId').val('');
                $('#visiteurForm')[0].reset();
                const now = new Date();
                $('#date_connexion').val(now.toISOString().split('T')[0]);
                $('#heure_connexion').val(now.toTimeString().slice(0, 5));
                $('#date_deconnexion').val('');
                $('#heure_deconnexion').val('');
                $('#reference').val('');
                $('#etat_connexion').val('En cours');
                visiteurModal.show();
            });

            $(document).on('click', '.editBtn', function() {
                const id = $(this).data('id');
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        action: 'get_visiteur_edit',
                        id: id
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.error) {
                            alert(data.error);
                            return;
                        }
                        $('#modalTitle').text('Modifier le visiteur #' + data.id);
                        $('#formAction').val('modifier_visiteur');
                        $('#editId').val(data.id);
                        $('#date_connexion').val(data.date_connexion);
                        $('#heure_connexion').val(data.heure_connexion);
                        $('#date_deconnexion').val(data.date_deconnexion);
                        $('#heure_deconnexion').val(data.heure_deconnexion);
                        $('#reference').val(data.reference);
                        $('#etat_connexion').val(data.etat_connexion);
                        visiteurModal.show();
                    },
                    error: function(xhr) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            alert(resp.error || 'Erreur');
                        } catch (e) {
                            alert('Erreur lors du chargement des données.');
                        }
                    }
                });
            });

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
                        etat_filter: etat,
                        page: page
                    },
                    dataType: 'json',
                    success: function(data) {
                        $('#tableBody').html(data.table);
                        $('#paginationContainer').html(data.pagination);
                        $('#totalCount').text(data.total + ' visiteur(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
                        $('.page-link').off('click').on('click', function(e) {
                            e.preventDefault();
                            var p = $(this).data('page');
                            if (p) rechercher(p);
                        });
                    },
                    error: function() {
                        alert('Erreur lors de la recherche.');
                    }
                });
            }
            var searchTimeout = null;
            $('#searchInput').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });
            $('#etatFilter').on('change', function() {
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
                $('#etatFilter').val('');
                rechercher(1);
            });
        });
    </script>
</body>

</html>