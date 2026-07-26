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

// views/utilisateur/index.php – Gestion des utilisateurs (design vente)
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

function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$roles = ['Superviseur', 'Proprietaire', 'Caisse', 'Vendeur', 'Admin'];
$sexes = ['Masculin', 'Feminin'];
$etats = ['Actif', 'Inactif'];

function generateUserId($pdo)
{
    $date = date('Ymd');
    $prefix = 'USR-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE id LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

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
            $id = trim($_POST['id'] ?? '');
            $matricule = trim($_POST['matricule'] ?? '');
            $nom_prenom = trim($_POST['nom_prenom'] ?? '');
            $date_naissance = trim($_POST['date_naissance'] ?? '');
            $lieu_naissance = trim($_POST['lieu_naissance'] ?? '');
            $sexe = trim($_POST['sexe'] ?? '');
            $login = trim($_POST['login'] ?? '');
            $mdp = trim($_POST['mdp'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $profession = trim($_POST['profession'] ?? '');
            $nationalite = trim($_POST['nationalite'] ?? '');
            $ville = trim($_POST['ville'] ?? '');
            $adresse = trim($_POST['adresse'] ?? '');
            $boutique_id = trim($_POST['boutique_id'] ?? '') ?: null;
            $role = trim($_POST['role'] ?? '');
            $date_saisie = trim($_POST['date_saisie'] ?? date('Y-m-d'));
            $etat = trim($_POST['etat'] ?? 'Actif');

            $errors = [];
            if (empty($matricule)) $errors[] = '';
            if (empty($nom_prenom)) $errors[] = '';
            if (empty($login)) $errors[] = '';
            if (empty($role)) $errors[] = '';

            if (($role === 'Vendeur' || $role === 'Caisse') && empty($boutique_id)) {
                $errors[] = "La boutique est obligatoire pour le rôle $role.";
            }

            if (empty($errors)) {
                try {
                    if ($action === 'add') {
                        $id = generateUserId($pdo);
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE matricule = ? OR login = ?");
                        $stmt->execute([$matricule, $login]);
                        if ($stmt->fetchColumn() > 0) {
                            $message = "Ce matricule ou login existe déjà.";
                            $messageType = 'warning';
                        } else {
                            if (empty($mdp)) $mdp = $login;
                            $sql = "INSERT INTO utilisateur (id, matricule, nom_prenom, date_naissance, lieu_naissance, sexe, login, mdp, telephone, email, profession, nationalite, ville, adresse, boutique_id, role, date_saisie, etat)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$id, $matricule, $nom_prenom, $date_naissance, $lieu_naissance, $sexe, $login, $mdp, $telephone, $email, $profession, $nationalite, $ville, $adresse, $boutique_id, $role, $date_saisie, $etat]);
                            $message = "Utilisateur « $nom_prenom » ajouté avec succès. ID : $id";
                            $messageType = 'success';
                        }
                    } elseif ($action === 'edit') {
                        $oldId = $_POST['old_id'] ?? $id;
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE (matricule = ? OR login = ?) AND id != ?");
                        $stmt->execute([$matricule, $login, $oldId]);
                        if ($stmt->fetchColumn() > 0) {
                            $message = "Ce matricule ou login est déjà utilisé par un autre utilisateur.";
                            $messageType = 'warning';
                        } else {
                            if (empty($mdp)) {
                                $sql = "UPDATE utilisateur SET matricule=?, nom_prenom=?, date_naissance=?, lieu_naissance=?, sexe=?, login=?, telephone=?, email=?, profession=?, nationalite=?, ville=?, adresse=?, boutique_id=?, role=?, date_saisie=?, etat=?
                                        WHERE id = ?";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$matricule, $nom_prenom, $date_naissance, $lieu_naissance, $sexe, $login, $telephone, $email, $profession, $nationalite, $ville, $adresse, $boutique_id, $role, $date_saisie, $etat, $oldId]);
                            } else {
                                $sql = "UPDATE utilisateur SET matricule=?, nom_prenom=?, date_naissance=?, lieu_naissance=?, sexe=?, login=?, mdp=?, telephone=?, email=?, profession=?, nationalite=?, ville=?, adresse=?, boutique_id=?, role=?, date_saisie=?, etat=?
                                        WHERE id = ?";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$matricule, $nom_prenom, $date_naissance, $lieu_naissance, $sexe, $login, $mdp, $telephone, $email, $profession, $nationalite, $ville, $adresse, $boutique_id, $role, $date_saisie, $etat, $oldId]);
                            }
                            $message = "Utilisateur « $nom_prenom » mis à jour.";
                            $messageType = 'success';
                        }
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
            $id = $_POST['sai_supprimer_id'] ?? '';
            if (!empty($id) && $id !== $_SESSION['user_id']) {
                try {
                    $stmt = $pdo->prepare("SELECT nom_prenom FROM utilisateur WHERE id = ?");
                    $stmt->execute([$id]);
                    $nom = $stmt->fetchColumn();
                    $stmt = $pdo->prepare("DELETE FROM utilisateur WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = "Utilisateur « $nom » supprimé.";
                    $messageType = 'danger';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'danger';
                }
            } else {
                $message = "Vous ne pouvez pas supprimer votre propre compte.";
                $messageType = 'warning';
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

function getTableContent($pdo, $search, $filtres, $page, $perPage = 20)
{
    $sql = "SELECT u.*, b.nom_boutique
            FROM utilisateur u
            LEFT JOIN boutique b ON u.boutique_id = b.code_boutique
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (u.id LIKE ? OR u.matricule LIKE ? OR u.nom_prenom LIKE ? OR u.login LIKE ? OR u.email LIKE ? OR u.telephone LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 6; $i++) $params[] = $like;
    }
    if (!empty($filtres['boutique'])) {
        $sql .= " AND u.boutique_id = ?";
        $params[] = $filtres['boutique'];
    }
    if (!empty($filtres['role'])) {
        $sql .= " AND u.role = ?";
        $params[] = $filtres['role'];
    }
    if (!empty($filtres['etat'])) {
        $sql .= " AND u.etat = ?";
        $params[] = $filtres['etat'];
    }

    $countSql = str_replace("SELECT u.*, b.nom_boutique", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $sql .= " ORDER BY u.nom_prenom LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($users)): ?>
        <tr>
            <td colspan="10" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                Aucun utilisateur trouvé
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($users as $u): ?>
            <tr>
                <td class="td-bold"><?= e($u['id']) ?></td>
                <td><?= e($u['matricule']) ?></td>
                <td><?= e($u['nom_prenom']) ?></td>
                <td><?= e($u['login']) ?></td>
                <td><?= e($u['telephone'] ?? '—') ?></td>
                <td><?= e($u['email'] ?? '—') ?></td>
                <td><?= e($u['role']) ?></td>
                <td><?= e($u['nom_boutique'] ?? '—') ?></td>
                <td>
                    <span class="status-badge <?= $u['etat'] === 'Actif' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= e($u['etat']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn e editBtn" data-id="<?= e($u['id']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                            <button class="act-btn d deleteBtn" data-id="<?= e($u['id']) ?>" data-nom="<?= e($u['nom_prenom']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash"></i></button>
                        <?php else: ?>
                            <button class="act-btn" style="color:#94a3b8;cursor:not-allowed;" title="Vous ne pouvez pas vous supprimer"><i class="bi bi-trash"></i></button>
                        <?php endif; ?>
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

if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $filtres = [
        'boutique' => trim($_POST['boutique'] ?? ''),
        'role' => trim($_POST['role'] ?? ''),
        'etat' => trim($_POST['etat'] ?? '')
    ];
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $filtres, $page);
    sendJson($result);
}

$search = trim($_POST['search'] ?? '');
$filtres = [
    'boutique' => trim($_POST['boutique'] ?? ''),
    'role' => trim($_POST['role'] ?? ''),
    'etat' => trim($_POST['etat'] ?? '')
];
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $filtres, $page);

$editUser = null;
if ($action === 'load_edit' && isset($_POST['edit_id'])) {
    $id = $_POST['edit_id'];
    $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE id = ?");
    $stmt->execute([$id]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des utilisateurs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
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
            <h1>Gestion des utilisateurs</h1>
            <p>Gérez les comptes utilisateurs et leurs rôles</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-people"></i> <?= $initialData['total'] ?? 0 ?> utilisateur(s)</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouvel utilisateur</button>
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
                <input type="text" name="search" id="searchInput" placeholder="ID, nom, login, email..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                <label for="boutiqueFilter">Boutique</label>
                <select name="boutique" id="boutiqueFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
                    <option value="">Toutes</option>
                    <?php foreach ($boutiques as $b): ?>
                        <option value="<?= e($b['code_boutique']) ?>" <?= ($filtres['boutique'] == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="roleFilter">Rôle</label>
                <select name="role" id="roleFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un rôle...">
                    <option value="">Tous</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= e($r) ?>" <?= ($filtres['role'] == $r) ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="etatFilter">État</label>
                <select name="etat" id="etatFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un état...">
                    <option value="">Tous</option>
                    <?php foreach ($etats as $e): ?>
                        <option value="<?= e($e) ?>" <?= ($filtres['etat'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
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
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des utilisateurs</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> utilisateur(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Matricule</th>
                        <th>Nom & Prénom</th>
                        <th>Login</th>
                        <th>Téléphone</th>
                        <th>Email</th>
                        <th>Rôle</th>
                        <th>Boutique</th>
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
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-person text-primary me-2"></i> Nouvel utilisateur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form method="post" id="userForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_id" id="oldId" value="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <!-- Identification -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-id-card me-1"></i> Identification</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="id" class="form-label fw-semibold">ID <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="id" name="id" required readonly value="<?= e($editUser['id'] ?? generateUserId($pdo)) ?>">
                            </div>
                            <div class="form-text">ID généré automatiquement</div>
                        </div>
                        <div class="col-md-6">
                            <label for="matricule" class="form-label fw-semibold">Matricule <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-id-badge"></i></span>
                                <input type="text" class="form-control" id="matricule" name="matricule" required placeholder="EMP001" value="<?= e($editUser['matricule'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="nom_prenom" class="form-label fw-semibold">Nom et prénom <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="nom_prenom" name="nom_prenom" required placeholder="Jean Dupont" value="<?= e($editUser['nom_prenom'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="login" class="form-label fw-semibold">Login <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="text" class="form-control" id="login" name="login" required placeholder="jdupont" value="<?= e($editUser['login'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Mot de passe -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-lock me-1"></i> Sécurité</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="mdp" class="form-label fw-semibold">Mot de passe</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password" class="form-control" id="mdp" name="mdp" placeholder="Laisser vide pour ne pas modifier">
                            </div>
                            <div class="form-text">Si vide, le mot de passe actuel est conservé (en édition).</div>
                        </div>
                        <div class="col-md-6">
                            <label for="role" class="form-label fw-semibold">Rôle <span class="text-danger">*</span></label>
                            <select class="form-select" id="role" name="role" required onchange="toggleBoutiqueRequired(this.value)">
                                <option value="">=== Faites votre choix ===</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= e($r) ?>" <?= (isset($editUser) && $editUser['role'] == $r) ? 'selected' : '' ?>><?= e($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Coordonnées -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-address-book me-1"></i> Coordonnées</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label for="telephone" class="form-label fw-semibold">Téléphone</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                <input type="text" class="form-control" id="telephone" name="telephone" placeholder="+225 05..." value="<?= e($editUser['telephone'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="email" class="form-label fw-semibold">Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" placeholder="user@example.com" value="<?= e($editUser['email'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="sexe" class="form-label fw-semibold">Sexe</label>
                            <select class="form-select" id="sexe" name="sexe">
                                <option value="">=== Choisir ===</option>
                                <?php foreach ($sexes as $s): ?>
                                    <option value="<?= e($s) ?>" <?= (isset($editUser) && $editUser['sexe'] == $s) ? 'selected' : '' ?>><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Informations personnelles -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-info-circle me-1"></i> Informations personnelles</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label for="date_naissance" class="form-label fw-semibold">Date de naissance</label>
                            <input type="date" class="form-control" id="date_naissance" name="date_naissance" value="<?= e($editUser['date_naissance'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="lieu_naissance" class="form-label fw-semibold">Lieu de naissance</label>
                            <input type="text" class="form-control" id="lieu_naissance" name="lieu_naissance" placeholder="Ville" value="<?= e($editUser['lieu_naissance'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="nationalite" class="form-label fw-semibold">Nationalité</label>
                            <input type="text" class="form-control" id="nationalite" name="nationalite" placeholder="Nationalité" value="<?= e($editUser['nationalite'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label for="profession" class="form-label fw-semibold">Profession</label>
                            <input type="text" class="form-control" id="profession" name="profession" placeholder="Profession" value="<?= e($editUser['profession'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="ville" class="form-label fw-semibold">Ville</label>
                            <input type="text" class="form-control" id="ville" name="ville" placeholder="Ville" value="<?= e($editUser['ville'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="adresse" class="form-label fw-semibold">Adresse</label>
                            <input type="text" class="form-control" id="adresse" name="adresse" placeholder="Adresse" value="<?= e($editUser['adresse'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Association boutique -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-store me-1"></i> Association boutique</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="boutique_id" class="form-label fw-semibold">Boutique</label>
                            <select class="selectpicker form-control" id="boutique_id" name="boutique_id" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
                                <option value="">=== Aucune ===</option>
                                <?php foreach ($boutiques as $b): ?>
                                    <option value="<?= e($b['code_boutique']) ?>" <?= (isset($editUser) && $editUser['boutique_id'] == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="boutiqueRequiredMsg" class="text-danger small" style="display:none;">La boutique est obligatoire pour ce rôle.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="date_saisie" class="form-label fw-semibold">Date de saisie</label>
                            <input type="date" class="form-control" id="date_saisie" name="date_saisie" value="<?= e($editUser['date_saisie'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>

                    <!-- État -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-toggle-on me-1"></i> Statut</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="etat" class="form-label fw-semibold">État</label>
                            <select class="form-select" id="etat" name="etat">
                                <?php foreach ($etats as $e): ?>
                                    <option value="<?= e($e) ?>" <?= (isset($editUser) && $editUser['etat'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
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
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer l'utilisateur <strong id="deleteNomUser"></strong> ?<br>Cette action est irréversible.</p>
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
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="btn_supprimer" value="1">
    <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>

<form method="post" id="actionForm">
    <input type="hidden" name="action" id="actionField">
    <input type="hidden" name="edit_id" id="editIdField">
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

    window.toggleBoutiqueRequired = function(role) {
        const requiredRoles = ['Vendeur', 'Caisse'];
        const msg = document.getElementById('boutiqueRequiredMsg');
        const select = document.getElementById('boutique_id');
        if (requiredRoles.includes(role)) {
            msg.style.display = 'block';
            select.setAttribute('required', 'required');
        } else {
            msg.style.display = 'none';
            select.removeAttribute('required');
        }
        $('#boutique_id').selectpicker('refresh');
    };

    // --- Ajout ---
    const userModal = new bootstrap.Modal(document.getElementById('userModal'));

    $('#addBtn').on('click', function(e) {
        e.preventDefault();
        $('#formAction').val('add');
        $('#oldId').val('');
        $('#modalTitle').html('<i class="bi bi-person text-primary me-2"></i> Nouvel utilisateur');

        $('#userForm')[0].reset();
        $('#id').prop('readonly', true);
        $('#matricule').val('');
        $('#nom_prenom').val('');
        $('#login').val('');
        $('#mdp').val('');
        $('#telephone').val('');
        $('#email').val('');
        $('#profession').val('');
        $('#nationalite').val('');
        $('#ville').val('');
        $('#adresse').val('');
        $('#date_naissance').val('');
        $('#lieu_naissance').val('');
        $('#date_saisie').val('<?= date('Y-m-d') ?>');
        $('#role').val('');
        $('#sexe').val('');
        $('#etat').val('Actif');
        $('#boutique_id').selectpicker('val', '');
        $('#boutiqueRequiredMsg').hide();

        userModal.show();
    });

    // --- Édition ---
    $(document).on('click', '.editBtn', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        $('#actionField').val('load_edit');
        $('#editIdField').val(id);
        $('#actionForm').submit();
    });

    // --- Recherche AJAX ---
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
                $('#totalCount').text(data.total + ' utilisateur(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
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

    $('#boutiqueFilter, #roleFilter, #etatFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });

    $('#filterBtn').on('click', function() { rechercher(1); });
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#boutiqueFilter, #roleFilter, #etatFilter').selectpicker('val', '');
        rechercher(1);
    });

    // Pagination initiale
    $('.page-link').on('click', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        if (page) rechercher(page);
    });

    // --- Suppression ---
    $(document).on('click', '.deleteBtn', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        const nom = $(this).data('nom');
        $('#deleteNomUser').text(nom);
        $('#deleteFormId').val(id);
        $('#deleteConfirmModal').modal('show');
    });
    $('#confirmDeleteBtn').on('click', function() {
        $('#deleteForm').submit();
    });

    // Auto-fermeture des alertes
    setTimeout(function() { $('.alert').alert('close'); }, 5000);

    // --- Si édition via POST ---
    <?php if (isset($editUser) && $action === 'load_edit'): ?>
        $(function() {
            $('#formAction').val('edit');
            $('#oldId').val('<?= e($editUser['id']) ?>');
            $('#modalTitle').html('<i class="bi bi-person text-primary me-2"></i> Modifier l\'utilisateur');
            $('#id').prop('readonly', true);
            toggleBoutiqueRequired('<?= e($editUser['role']) ?>');
            $('.selectpicker').selectpicker('refresh');
            userModal.show();
        });
    <?php endif; ?>
});
</script>
</body>
</html>