<?php
ob_start();

// Fonction utilitaire pour envoyer une réponse JSON propre
function sendJson($data)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// notification.php – Gestion des notifications (design vente)
require_once 'databases/database.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}


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
            <td colspan="7" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
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
                        <button class="act-btn v viewBtn" data-id="<?= $notif['id'] ?>" title="Voir"><i class="bi bi-eye"></i></button>
                        <button class="act-btn e editBtn" data-id="<?= $notif['id'] ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-id="<?= $notif['id'] ?>" data-nom="<?= htmlspecialchars($notif['titre']) ?>" title="Supprimer"><i class="bi bi-trash"></i></button>
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
        .act-btn.v:hover { color: var(--b); background: var(--bl); border-color: rgba(37,99,235,.15); }
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
            <h1>Gestion des notifications</h1>
            <p>Consultez et gérez vos notifications</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-bell"></i> <?= $initialData['total'] ?? 0 ?> notification(s)</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouvelle notification</button>
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
                <input type="text" name="search" id="searchInput" placeholder="Objet, titre, texte..." value="<?= htmlspecialchars($search) ?>" style="flex:1; min-width:150px;">
                <label for="userFilter">Utilisateur</label>
                <select name="user" id="userFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un utilisateur...">
                    <option value="">Tous</option>
                    <?php foreach ($utilisateurs as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($filtres['user'] == $u['id']) ? 'selected' : '' ?>><?= htmlspecialchars($u['nom_prenom']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="date_debut">Début</label>
                <input type="date" name="date_debut" id="date_debut" value="<?= htmlspecialchars($filtres['date_debut']) ?>" style="width:auto;">
                <label for="date_fin">Fin</label>
                <input type="date" name="date_fin" id="date_fin" value="<?= htmlspecialchars($filtres['date_fin']) ?>" style="width:auto;">
                <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
            </div>
        </form>
    </div>

    <!-- Tableau -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des notifications</h5>
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

<!-- ========================================================= -->
<!-- MODAL FORMULAIRE (ajout/modification) -->
<!-- ========================================================= -->
<div class="modal fade" id="notificationModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-bell text-primary me-2"></i> Nouvelle notification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form method="post" id="notificationForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_id" id="oldId" value="">
                <div class="modal-body">
                    <!-- Identification -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-hash me-1"></i> Identification</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="id" class="form-label fw-semibold">ID</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="id" name="id" readonly value="<?= htmlspecialchars($editNotification['id'] ?? '') ?>">
                            </div>
                            <div class="form-text">L'ID est généré automatiquement</div>
                        </div>
                        <div class="col-md-6">
                            <label for="titre" class="form-label fw-semibold">Titre <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-heading"></i></span>
                                <input type="text" class="form-control" id="titre" name="titre" placeholder="Titre de la notification" value="<?= htmlspecialchars($editNotification['titre'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Objet et date -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-tag me-1"></i> Détails</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="objet" class="form-label fw-semibold">Objet</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-tag"></i></span>
                                <input type="text" class="form-control" id="objet" name="objet" placeholder="Objet (optionnel)" value="<?= htmlspecialchars($editNotification['objet'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="date" class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                <input type="datetime-local" class="form-control" id="date" name="date" value="<?= isset($editNotification) ? date('Y-m-d\TH:i', strtotime($editNotification['date'])) : date('Y-m-d\TH:i') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Texte -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-align-left me-1"></i> Texte</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="text" class="form-label fw-semibold">Contenu <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-pencil"></i></span>
                                <textarea class="form-control" id="text" name="text" rows="5" placeholder="Contenu de la notification..." required><?= htmlspecialchars($editNotification['text'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Utilisateur et fichier -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-person me-1"></i> Association</h6>
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
                                <span class="input-group-text"><i class="bi bi-file"></i></span>
                                <input type="text" class="form-control" id="fichier" name="fichier" placeholder="Nom du fichier joint" value="<?= htmlspecialchars($editNotification['fichier'] ?? '') ?>">
                            </div>
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
<!-- MODALE : VUE DÉTAIL -->
<!-- ========================================================= -->
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

<!-- ========================================================= -->
<!-- MODALE : CONFIRMATION SUPPRESSION -->
<!-- ========================================================= -->
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

<!-- Formulaires cachés -->
<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="btn_supprimer" value="1">
    <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
</form>

<form method="post" id="actionForm">
    <input type="hidden" name="action" id="actionField">
    <input type="hidden" name="edit_id" id="editIdField">
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

    const notificationModal = new bootstrap.Modal(document.getElementById('notificationModal'));
    const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));

    // --- Ajout ---
    $('#addBtn').on('click', function(e) {
        e.preventDefault();
        $('#formAction').val('add');
        $('#oldId').val('');
        $('#modalTitle').html('<i class="bi bi-bell text-primary me-2"></i> Nouvelle notification');
        $('#notificationForm')[0].reset();
        $('#id').val('');

        $('#titre').val('');
        $('#objet').val('');
        $('#text').val('');
        $('#fichier').val('');

        var now = new Date();
        var offset = now.getTimezoneOffset() * 60000;
        var localISOTime = (new Date(Date.now() - offset)).toISOString().slice(0, 16);
        $('#date').val(localISOTime);

        $('#user').selectpicker('val', '');
        notificationModal.show();
    });

    // --- Édition ---
    $(document).on('click', '.editBtn', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        $('#actionField').val('load_edit');
        $('#editIdField').val(id);
        $('#actionForm').submit();
    });

    // --- Vue en AJAX ---
    $(document).on('click', '.viewBtn', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
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
                    viewModal.show();
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

    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });

    $('#userFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });

    $('#date_debut, #date_fin').on('change', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });

    $('#filterBtn').on('click', function() { rechercher(1); });
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
    setTimeout(function() { $('.alert').alert('close'); }, 5000);

    // --- Si édition via POST ---
    <?php if (isset($editNotification) && $action === 'load_edit'): ?>
        $(function() {
            $('#formAction').val('edit');
            $('#oldId').val('<?= $editNotification['id'] ?>');
            $('#modalTitle').html('<i class="bi bi-bell text-primary me-2"></i> Modifier la notification');
            $('#id').val('<?= $editNotification['id'] ?>');
            $('.selectpicker').selectpicker('refresh');
            notificationModal.show();
        });
    <?php endif; ?>
});
</script>
</body>
</html>