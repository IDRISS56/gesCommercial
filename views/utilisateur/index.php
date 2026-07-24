<?php
// Activation du buffer (sera vidé par sendJson)
ob_start();

// Fonction utilitaire pour envoyer une réponse JSON propre
// Vide TOUS les buffers de sortie (quel que soit le niveau)
function sendJson($data)
{
    // Supprimer tous les buffers de sortie actifs
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // Envoyer l'en-tête JSON
    header('Content-Type: application/json');
    // Encoder et envoyer les données
    echo json_encode($data);
    // Terminer l'exécution
    exit;
}

// views/utilisateur/index.php – Gestion des utilisateurs
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

// --- Récupération des listes pour les selects ---
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$roles = ['Superviseur', 'Proprietaire', 'Caisse', 'Vendeur', 'Admin'];
$sexes = ['Masculin', 'Feminin'];
$etats = ['Actif', 'Inactif'];

// --- Fonction pour générer un ID automatique ---
function generateUserId($pdo)
{
    $date = date('Ymd');
    $prefix = 'USR-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE id LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// --- Traitement des actions POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

// Vérifier le token CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {
        // Traitement des actions
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
            if (empty($matricule)) $errors[] = "";
            if (empty($nom_prenom)) $errors[] = "";
            if (empty($login)) $errors[] = "";
            if (empty($role)) $errors[] = "";

            // Vérifier que la boutique est obligatoire pour Vendeur et Caisse
            if (($role === 'Vendeur' || $role === 'Caisse') && empty($boutique_id)) {
                $errors[] = "La boutique est obligatoire pour le rôle $role.";
            }

            if (empty($errors)) {
                try {
                    if ($action === 'add') {
                        // Générer l'ID automatiquement
                        $id = generateUserId($pdo);
                        // Vérifier l'unicité du matricule et du login
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
                        // Vérifier l'unicité du matricule et du login
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

        // Suppression
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

// Générer un token CSRF
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// --- AJAX pour le tableau ---
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
            <td colspan="11" class="text-center py-5 text-muted">
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
                        <!-- Bouton Voir supprimé -->
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

// --- AJAX pour le tableau ---
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

// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$filtres = [
    'boutique' => trim($_POST['boutique'] ?? ''),
    'role' => trim($_POST['role'] ?? ''),
    'etat' => trim($_POST['etat'] ?? '')
];
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $filtres, $page);

// Chargement des données pour l'édition (action load_edit)
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
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

        .btn-delete-self {
            color: #94a3b8;
            cursor: not-allowed;
            opacity: 0.5;
        }
    </style>
</head>

<body>
    <div class="container-crud">

        <!-- En-tête -->
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
            <div class="page-heading">
                <h2 class="fw-800 mb-0">Gestion des utilisateurs</h2>
                <p class="text-tertiary mt-1">Gérez les comptes utilisateurs et leurs rôles</p>
            </div>
            <div>
                <a href="profile.php" class="btn btn-outline-secondary btn-sm me-2"><i class="fas fa-user"></i> Mon profil</a>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="fas fa-plus"></i> Nouvel utilisateur</button>
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
                            <input type="text" name="search" id="searchInput" placeholder="ID, nom, login, email..." value="<?= e($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label for="boutiqueFilter" class="form-label fw-semibold small">Boutique</label>
                        <select name="boutique" id="boutiqueFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
                            <option value="">Toutes</option>
                            <?php foreach ($boutiques as $b): ?>
                                <option value="<?= e($b['code_boutique']) ?>" <?= ($filtres['boutique'] == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="roleFilter" class="form-label fw-semibold small">Rôle</label>
                        <select name="role" id="roleFilter" class="selectpicker form-control">
                            <option value="">Tous</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= e($r) ?>" <?= ($filtres['role'] == $r) ? 'selected' : '' ?>><?= e($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="etatFilter" class="form-label fw-semibold small">État</label>
                        <select name="etat" id="etatFilter" class="selectpicker form-control">
                            <option value="">Tous</option>
                            <?php foreach ($etats as $e): ?>
                                <option value="<?= e($e) ?>" <?= ($filtres['etat'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
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
                <h5 class="mb-0 fw-bold">Liste des utilisateurs</h5>
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

    <!-- =========================================================
MODAL FORMULAIRE (ajout/modification)
========================================================= -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-user text-primary me-2"></i> Nouvel utilisateur</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post" id="userForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="old_id" id="oldId" value="">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="modal-body">
                        <!-- Identification -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-id-card me-1"></i> Identification</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="id" class="form-label fw-semibold">ID <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                                    <input type="text" class="form-control" id="id" name="id" required readonly value="<?= e($editUser['id'] ?? generateUserId($pdo)) ?>">
                                </div>
                                <div class="form-text">ID généré automatiquement</div>
                            </div>
                            <div class="col-md-6">
                                <label for="matricule" class="form-label fw-semibold">Matricule <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-id-badge"></i></span>
                                    <input type="text" class="form-control" id="matricule" name="matricule" required placeholder="EMP001" value="<?= e($editUser['matricule'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="nom_prenom" class="form-label fw-semibold">Nom et prénom <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="nom_prenom" name="nom_prenom" required placeholder="Jean Dupont" value="<?= e($editUser['nom_prenom'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="login" class="form-label fw-semibold">Login <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-sign-in-alt"></i></span>
                                    <input type="text" class="form-control" id="login" name="login" required placeholder="jdupont" value="<?= e($editUser['login'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Mot de passe -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-lock me-1"></i> Sécurité</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="mdp" class="form-label fw-semibold">Mot de passe</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-key"></i></span>
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
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-address-card me-1"></i> Coordonnées</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="telephone" class="form-label fw-semibold">Téléphone</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="text" class="form-control" id="telephone" name="telephone" placeholder="+225 05..." value="<?= e($editUser['telephone'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="email" class="form-label fw-semibold">Email</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
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
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-info-circle me-1"></i> Informations personnelles</h6>
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
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-store me-1"></i> Association boutique</h6>
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
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-toggle-on me-1"></i> Statut</h6>
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
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Annuler</button>
                        <button type="submit" class="btn btn-primary" id="saveBtn"><i class="fas fa-save"></i> Enregistrer</button>
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

    <!-- Formulaire caché suppression -->
    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="btn_supprimer" value="1">
        <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
    </form>

    <!-- Formulaire caché pour action edit (chargement) -->
    <form method="post" id="actionForm">
        <input type="hidden" name="action" id="actionField">
        <input type="hidden" name="edit_id" id="editIdField">
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

            // --- Fonction pour afficher/masquer le message boutique obligatoire ---
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
                // Rafraîchir le selectpicker
                $('#boutique_id').selectpicker('refresh');
            };

            // --- Ouvrir modal Ajout ---
            $('#addBtn').on('click', function(e) {
                e.preventDefault();
                $('#formAction').val('add');
                $('#oldId').val('');
                $('#modalTitle').html('<i class="fas fa-user text-primary me-2"></i> Nouvel utilisateur');

                // Réinitialiser les champs
                $('#userForm')[0].reset();
                $('#id').prop('readonly', true);
                // L'ID est déjà pré-rempli par PHP, pas besoin d'ajax
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

                var modalEl = document.getElementById('userModal');
                var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.show();
            });

            // --- Édition (chargement via formulaire POST) ---
            $(document).on('click', '.editBtn', function(e) {
                e.preventDefault();
                const id = $(this).data('id');
                $('#actionField').val('load_edit');
                $('#editIdField').val(id);
                $('#actionForm').submit();
            });

            // --- Fonction de recherche AJAX ---
            function rechercher(page) {
                page = page || 1;
                var formData = $('#searchForm').serialize();
                formData += '&page=' + page;
                $.ajax({
                    url: window.location.href, // URL courante
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

            // Auto-submit pour le champ recherche
            var searchTimeout = null;
            $('#searchInput').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });

            // Pour les selectpicker du filtre
            $('#boutiqueFilter, #roleFilter, #etatFilter').on('changed.bs.select', function() {
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
                $('#boutiqueFilter, #roleFilter, #etatFilter').selectpicker('val', '');
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
                $('#deleteNomUser').text(nom);
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
            <?php if (isset($editUser) && $action === 'load_edit'): ?>
                $(function() {
                    $('#formAction').val('edit');
                    $('#oldId').val('<?= e($editUser['id']) ?>');
                    $('#modalTitle').html('<i class="fas fa-user text-primary me-2"></i> Modifier l\'utilisateur');
                    $('#id').prop('readonly', true);
                    toggleBoutiqueRequired('<?= e($editUser['role']) ?>');
                    $('.selectpicker').selectpicker('refresh');
                    var modalEl = document.getElementById('userModal');
                    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                    modal.show();
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>