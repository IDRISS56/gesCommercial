<?php
ob_start();
require 'databases/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: utilisateur/login');
    exit;
}

$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: utilisateur/login');
    exit;
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$roles = ['Superviseur', 'Proprietaire', 'Caisse', 'Vendeur', 'Administrateur'];
$sexes = ['Masculin', 'Feminin'];
$etats = ['Actif', 'Inactif'];

function generateUserId($pdo) {
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
            if (empty($matricule)) $errors[] = 'Le matricule est requis.';
            if (empty($nom_prenom)) $errors[] = 'Le nom est requis.';
            if (empty($login)) $errors[] = 'Le login est requis.';
            if (empty($role)) $errors[] = 'Le rôle est requis.';
            
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
                                $sql = "UPDATE utilisateur SET matricule=?, nom_prenom=?, date_naissance=?, lieu_naissance=?, sexe=?, login=?, telephone=?, email=?, profession=?, nationalite=?, ville=?, adresse=?, boutique_id=?, role=?, date_saisie=?, etat=? WHERE id = ?";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$matricule, $nom_prenom, $date_naissance, $lieu_naissance, $sexe, $login, $telephone, $email, $profession, $nationalite, $ville, $adresse, $boutique_id, $role, $date_saisie, $etat, $oldId]);
                            } else {
                                $sql = "UPDATE utilisateur SET matricule=?, nom_prenom=?, date_naissance=?, lieu_naissance=?, sexe=?, login=?, mdp=?, telephone=?, email=?, profession=?, nationalite=?, ville=?, adresse=?, boutique_id=?, role=?, date_saisie=?, etat=? WHERE id = ?";
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

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

function getTableContent($pdo, $search, $filtres, $page, $perPage = 20) {
    $sql = "SELECT u.*, b.nom_boutique
            FROM utilisateur u
            LEFT JOIN boutique b ON u.boutique_id = b.code_boutique
            WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (u.id LIKE ? OR u.matricule LIKE ? OR u.nom_prenom LIKE ? OR u.login LIKE ? OR u.email LIKE ? OR u.telephone LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    
    if (!empty($filtres['boutique'])) { $sql .= " AND u.boutique_id = ?"; $params[] = $filtres['boutique']; }
    if (!empty($filtres['role'])) { $sql .= " AND u.role = ?"; $params[] = $filtres['role']; }
    if (!empty($filtres['etat'])) { $sql .= " AND u.etat = ?"; $params[] = $filtres['etat']; }
    
    $countSql = "SELECT COUNT(*) FROM (" . $sql . ") as count_table";
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
    if (empty($users)):
    ?>
    <tr><td colspan="10" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>Aucun utilisateur trouvé</td></tr>
    <?php else: foreach ($users as $u): ?>
    <tr>
        <td class="td-bold"><?= e($u['id']) ?></td>
        <td><?= e($u['matricule']) ?></td>
        <td><?= e($u['nom_prenom']) ?></td>
        <td><?= e($u['login']) ?></td>
        <td><?= e($u['telephone'] ?? '—') ?></td>
        <td><?= e($u['email'] ?? '—') ?></td>
        <td><?= e($u['role']) ?></td>
        <td><?= e($u['nom_boutique'] ?? '—') ?></td>
        <td><span class="status-badge <?= $u['etat'] === 'Actif' ? 'on' : 'off' ?>"><span class="sdot"></span><?= e($u['etat']) ?></span></td>
        <td class="text-end">
            <div class="d-inline-flex gap-1">
                <button type="button" class="act-btn e editBtn" data-id="<?= e($u['id']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                <button type="button" class="act-btn d deleteBtn" data-id="<?= e($u['id']) ?>" data-nom="<?= e($u['nom_prenom']) ?>" title="Supprimer"><i class="bi bi-trash"></i></button>
                <?php else: ?>
                <button type="button" class="act-btn" style="color:#94a3b8;cursor:not-allowed;" title="Vous ne pouvez pas vous supprimer"><i class="bi bi-trash"></i></button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; endif;
    $tableHtml = ob_get_clean();
    
    ob_start();
    if ($totalPages > 1):
    ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
        <span class="text-muted small">Affichage de <?= (($page - 1) * $perPage + 1) ?> à <?= min($page * $perPage, $total) ?> sur <?= $total ?></span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $page - 1 ?>"><i class="bi bi-chevron-left"></i></a></li>
            <?php
            $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
            if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>'; if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
            for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>"><a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a></li>
            <?php endfor;
            if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $totalPages . '">' . $totalPages . '</a></li>'; }
            ?>
            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $page + 1 ?>"><i class="bi bi-chevron-right"></i></a></li>
        </ul></nav>
    </div>
    <?php endif;
    $paginationHtml = ob_get_clean();
    
    return ['table' => $tableHtml, 'pagination' => $paginationHtml, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages];
}

$search = trim($_POST['search'] ?? '');
$filtres = ['boutique' => trim($_POST['boutique'] ?? ''), 'role' => trim($_POST['role'] ?? ''), 'etat' => trim($_POST['etat'] ?? '')];
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

$totalUsers = $pdo->query("SELECT COUNT(*) FROM utilisateur")->fetchColumn();
$actifs = $pdo->query("SELECT COUNT(*) FROM utilisateur WHERE etat = 'Actif'")->fetchColumn();
$inactifs = $pdo->query("SELECT COUNT(*) FROM utilisateur WHERE etat = 'Inactif'")->fetchColumn();
$vendeurs = $pdo->query("SELECT COUNT(*) FROM utilisateur WHERE role = 'Vendeur'")->fetchColumn();
$caisses = $pdo->query("SELECT COUNT(*) FROM utilisateur WHERE role = 'Caisse'")->fetchColumn();
$superviseurs = $pdo->query("SELECT COUNT(*) FROM utilisateur WHERE role = 'Superviseur'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion des utilisateurs</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --color-primary: #4f46e5; --color-primary-dark: #3730a3; --color-primary-soft: #eef2ff;
    --color-success: #10b981; --color-success-soft: #d1fae5;
    --color-warning: #f59e0b; --color-warning-soft: #fef3c7;
    --color-danger: #ef4444; --color-danger-soft: #fee2e2;
    --color-info: #0891b2; --color-info-soft: #cffafe;
    --color-purple: #8b5cf6; --color-purple-soft: #ede9fe;
    --color-gray-50: #f8fafc; --color-gray-100: #f1f5f9; --color-gray-200: #e2e8f0;
    --color-gray-300: #cbd5e1; --color-gray-400: #94a3b8; --color-gray-500: #64748b;
    --color-gray-600: #475569; --color-gray-700: #334155; --color-gray-800: #1e293b;
    --color-gray-900: #0f172a;
    --bg-body: #f1f5f9; --bg-surface: #ffffff; --border-color: #e2e8f0;
    --text-primary: #0f172a; --text-secondary: #334155; --text-tertiary: #64748b;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06); --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.06);
    --radius-sm: 10px; --radius-md: 14px;
    --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg-body); color: var(--text-primary); min-height: 100vh; font-size: 14px; padding: 24px 20px; }
h1, h2, h3, h4, h5, h6 { font-family: 'Outfit', sans-serif; font-weight: 700; letter-spacing: -0.02em; }
.W { max-width: 1400px; margin: 0 auto; }

/* ===== STATS ===== */
.stat-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base); }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

/* ===== TABLE ===== */
.data-table-wrap { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-sm); overflow: hidden; box-shadow: var(--shadow-sm); animation: fadeUp .4s ease both; }
.table { margin: 0; }
.table thead th { background: var(--color-gray-100); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; padding: 12px 14px; border-bottom: 2px solid var(--border-color); }
.table tbody tr { border-bottom: 1px solid var(--border-color); transition: background .2s; }
.table tbody tr:hover { background: var(--color-primary-soft); }
.table tbody td { padding: 12px 14px; vertical-align: middle; color: var(--text-primary); font-size: 13px; }
.td-bold { color: var(--text-primary) !important; font-weight: 700; }

/* ===== STATUS BADGE ===== */
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
.status-badge .sdot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: pulse 2s infinite; }
.status-badge.on { background: var(--color-success-soft); color: #065f46; }
.status-badge.off { background: var(--color-danger-soft); color: #991b1b; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

/* ===== ACTION BUTTONS ===== */
.act-btn { width: 32px; height: 32px; border-radius: 6px; border: 1.5px solid transparent; background: transparent; display: inline-flex; align-items: center; justify-content: center; transition: all .2s; font-size: 14px; cursor: pointer; padding: 0; }
.act-btn:hover { transform: scale(1.1); }
.act-btn.e { color: var(--color-warning); border-color: rgba(245, 158, 11, 0.2); }
.act-btn.e:hover { color: #b45309; background: var(--color-warning-soft); border-color: var(--color-warning); }
.act-btn.d { color: var(--color-danger); border-color: rgba(239, 68, 68, 0.2); }
.act-btn.d:hover { color: #b91c1c; background: var(--color-danger-soft); border-color: var(--color-danger); }

/* ===== BUTTONS ===== */
.btn-chic { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; letter-spacing: -0.01em; }
.btn-chic::before { content: ''; position: absolute; top: 50%; left: 50%; width: 0; height: 0; background: rgba(255,255,255,0.3); border-radius: 50%; transform: translate(-50%, -50%); transition: width .4s, height .4s; }
.btn-chic:hover::before { width: 300px; height: 300px; }
.btn-chic i { font-size: 15px; position: relative; z-index: 1; }
.btn-chic span { position: relative; z-index: 1; }
.btn-chic-primary { background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); color: #fff; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
.btn-chic-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4); }
.btn-go-outline { background: transparent; color: var(--text-tertiary); border: 1.5px solid var(--border-color); padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; transition: all .2s; cursor: pointer; }
.btn-go-outline:hover { background: var(--color-gray-100); border-color: var(--color-gray-300); }

/* ===== MODAL CHIC - STRUCTURE CORRIGÉE ===== */
.modal-chic .modal-content {
    border: none !important;
    border-radius: 20px !important;
    box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15) !important;
    overflow: hidden !important;
    animation: modalSlideIn .4s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex !important;
    flex-direction: column !important;
    max-height: 90vh !important;
}
@keyframes modalSlideIn { from { opacity: 0; transform: translateY(30px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
.modal-chic .modal-header {
    background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%);
    color: #fff; border: none; padding: 22px 28px; position: relative; overflow: hidden; flex-shrink: 0 !important;
}
.modal-chic .modal-header::before { content: ''; position: absolute; top: -50%; right: -20%; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%; }
.modal-chic .modal-title { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
.modal-chic .modal-title i { font-size: 22px; background: rgba(255,255,255,0.15); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.modal-chic .btn-close { filter: invert(1); opacity: 0.7; position: relative; z-index: 1; }
.modal-chic .btn-close:hover { opacity: 1; transform: rotate(90deg); }

.modal-chic .modal-body {
    padding: 28px !important;
    overflow-y: auto !important;
    background: #f8fafc !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
}

/* ===== MODAL FOOTER - FORCÉ VISIBLE ===== */
.modal-chic .modal-footer {
    background: #ffffff !important;
    border-top: 2px solid var(--border-color) !important;
    padding: 18px 28px !important;
    display: flex !important;
    gap: 10px !important;
    justify-content: flex-end !important;
    flex-wrap: wrap !important;
    flex-shrink: 0 !important;
    visibility: visible !important;
    opacity: 1 !important;
    z-index: 10 !important;
    position: relative !important;
}

/* ===== FORM ===== */
.form-label { font-size: 10px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.form-control, .form-select { border-radius: 10px; border: 1.5px solid var(--border-color); padding: 10px 14px; font-size: 13px; transition: all .2s; }
.form-control:focus, .form-select:focus { border-color: var(--color-primary); box-shadow: 0 0 0 3px var(--color-primary-soft); }

.bootstrap-select .dropdown-toggle { background: #fff !important; border: 1.5px solid var(--border-color) !important; border-radius: 8px !important; min-width: 220px; }
.bootstrap-select .dropdown-toggle:focus { border-color: var(--color-primary) !important; box-shadow: 0 0 0 3px var(--color-primary-soft) !important; }

@keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

@media (max-width: 700px) {
    .bootstrap-select, .bootstrap-select .dropdown-toggle { width: 100% !important; min-width: 0 !important; }
}
</style>
</head>
<body>
<div class="W">
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-people-fill text-primary me-2"></i>Gestion des utilisateurs</h1>
            <p class="text-muted small mb-0">Gérez les comptes utilisateurs et leurs rôles</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2"><i class="bi bi-people"></i> <?= $totalUsers ?> utilisateur(s)</span>
            <button type="button" class="btn-chic btn-chic-primary" id="addUserBtn"><i class="bi bi-person-plus-fill"></i><span>Nouvel utilisateur</span></button>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show mb-4" role="alert" style="border-radius:var(--radius-sm);border:none;padding:16px 20px;font-size:13px;font-weight:500;">
        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : ($messageType === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?> me-2"></i>
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['primary', 'people-fill', 'Total utilisateurs', $totalUsers, ''],
            ['success', 'check-circle-fill', 'Actifs', $actifs, ''],
            ['danger', 'x-circle-fill', 'Inactifs', $inactifs, ''],
            ['info', 'person-badge', 'Vendeurs', $vendeurs, ''],
            ['warning', 'cash-coin', 'Caisses', $caisses, ''],
            ['purple', 'shield-check', 'Superviseurs', $superviseurs, ''],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
            'danger' => ['var(--color-danger-soft)', 'var(--color-danger)'],
            'info' => ['var(--color-info-soft)', 'var(--color-info)'],
            'purple' => ['var(--color-purple-soft)', 'var(--color-purple)'],
        ];
        foreach ($stats as $s): $bg = $colorMap[$s[0]][0]; $fg = $colorMap[$s[0]][1]; ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background: <?= $bg ?>; color: <?= $fg ?>;"><i class="bi bi-<?= $s[1] ?>"></i></div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-label"><?= $s[2] ?></div>
                    <div class="stat-value text-truncate"><?= $s[3] ?><?php if ($s[4]): ?><small class="text-muted ms-1" style="font-size:11px;"><?= $s[4] ?></small><?php endif; ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="bg-white border rounded-3 p-3 mb-4 shadow-sm">
        <form id="searchForm" method="post">
            <input type="hidden" name="action" value="search">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-search"></i> Recherche</label>
                <input type="text" name="search" id="searchInput" class="form-control" placeholder="ID, nom, login..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                
                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-shop"></i> Boutique</label>
                <select name="boutique" id="boutiqueFilter" class="selectpicker" data-live-search="true">
                    <option value="">Toutes</option>
                    <?php foreach ($boutiques as $b): ?>
                    <option value="<?= e($b['code_boutique']) ?>" <?= ($filtres['boutique'] == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-person-badge"></i> Rôle</label>
                <select name="role" id="roleFilter" class="selectpicker" data-live-search="true">
                    <option value="">Tous</option>
                    <?php foreach ($roles as $r): ?>
                    <option value="<?= e($r) ?>" <?= ($filtres['role'] == $r) ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-toggle-on"></i> État</label>
                <select name="etat" id="etatFilter" class="selectpicker">
                    <option value="">Tous</option>
                    <?php foreach ($etats as $e): ?>
                    <option value="<?= e($e) ?>" <?= ($filtres['etat'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn-chic btn-chic-primary"><i class="bi bi-funnel"></i><span>Filtrer</span></button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <div class="data-table-wrap">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold">Liste des utilisateurs</h5>
            <span class="text-muted small"><?= $initialData['total'] ?> utilisateur(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr>
                    <th>ID</th><th>Matricule</th><th>Nom</th><th>Login</th><th>Téléphone</th><th>Email</th><th>Rôle</th><th>Boutique</th><th>État</th><th class="text-end">Actions</th>
                </tr></thead>
                <tbody><?= $initialData['table'] ?></tbody>
            </table>
        </div>
        <?= $initialData['pagination'] ?>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL FORMULAIRE - STRUCTURE CORRIGÉE -->
<!-- ========================================================= -->
<div class="modal fade modal-chic" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <!-- HEADER -->
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">
                    <i class="bi bi-person-plus-fill"></i>
                    <span id="modalTitleText">Nouvel utilisateur</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <!-- FORM englobe body + footer -->
            <form method="post" id="userForm" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_id" id="formOldId" value="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <!-- BODY -->
                <div class="modal-body">
                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-primary);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-person-fill"></i> Identité
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Matricule <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="matricule" name="matricule" required value="<?= e($editUser['matricule'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nom complet <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nom_prenom" name="nom_prenom" required value="<?= e($editUser['nom_prenom'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Login <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="text" class="form-control" id="login" name="login" required value="<?= e($editUser['login'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6" id="mdpFieldWrapper">
                            <label class="form-label">Mot de passe</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control" id="mdp" name="mdp" placeholder="Laisser vide = login">
                            </div>
                        </div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-info);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-address-book"></i> Coordonnées
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Téléphone</label>
                            <input type="text" class="form-control" id="telephone" name="telephone" value="<?= e($editUser['telephone'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?= e($editUser['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sexe</label>
                            <select class="form-select" id="sexe" name="sexe">
                                <option value="">=== Choisir ===</option>
                                <?php foreach ($sexes as $s): ?>
                                <option value="<?= e($s) ?>" <?= (isset($editUser) && $editUser['sexe'] == $s) ? 'selected' : '' ?>><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-purple);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-info-circle"></i> Informations personnelles
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4"><label class="form-label">Date de naissance</label><input type="date" class="form-control" id="date_naissance" name="date_naissance" value="<?= e($editUser['date_naissance'] ?? '') ?>"></div>
                        <div class="col-md-4"><label class="form-label">Lieu de naissance</label><input type="text" class="form-control" id="lieu_naissance" name="lieu_naissance" value="<?= e($editUser['lieu_naissance'] ?? '') ?>"></div>
                        <div class="col-md-4"><label class="form-label">Nationalité</label><input type="text" class="form-control" id="nationalite" name="nationalite" value="<?= e($editUser['nationalite'] ?? '') ?>"></div>
                        <div class="col-md-4"><label class="form-label">Profession</label><input type="text" class="form-control" id="profession" name="profession" value="<?= e($editUser['profession'] ?? '') ?>"></div>
                        <div class="col-md-4"><label class="form-label">Ville</label><input type="text" class="form-control" id="ville" name="ville" value="<?= e($editUser['ville'] ?? '') ?>"></div>
                        <div class="col-md-4"><label class="form-label">Adresse</label><input type="text" class="form-control" id="adresse" name="adresse" value="<?= e($editUser['adresse'] ?? '') ?>"></div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-warning);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-shop"></i> Association boutique
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Boutique</label>
                            <select class="selectpicker form-control" id="boutique_id" name="boutique_id" data-live-search="true">
                                <option value="">=== Aucune ===</option>
                                <?php foreach ($boutiques as $b): ?>
                                <option value="<?= e($b['code_boutique']) ?>" <?= (isset($editUser) && $editUser['boutique_id'] == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="boutiqueRequiredMsg" class="text-danger small mt-1" style="display:none;">La boutique est obligatoire pour ce rôle.</div>
                        </div>
                        <div class="col-md-6"><label class="form-label">Date de saisie</label><input type="date" class="form-control" id="date_saisie" name="date_saisie" value="<?= e($editUser['date_saisie'] ?? date('Y-m-d')) ?>"></div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-success);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-shield-check"></i> Rôle et statut
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Rôle <span class="text-danger">*</span></label>
                            <select class="form-select" id="role" name="role" required onchange="toggleBoutiqueRequired(this.value)">
                                <option value="">=== Choisir ===</option>
                                <?php foreach ($roles as $r): ?>
                                <option value="<?= e($r) ?>" <?= (isset($editUser) && $editUser['role'] == $r) ? 'selected' : '' ?>><?= e($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">État</label>
                            <select class="form-select" id="etat" name="etat">
                                <?php foreach ($etats as $e): ?>
                                <option value="<?= e($e) ?>" <?= (isset($editUser) && $editUser['etat'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- ===== FOOTER - TOUJOURS VISIBLE ===== -->
                <div class="modal-footer">
                    <button type="button" class="btn-chic" style="background:var(--color-gray-100);color:var(--text-secondary);" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i><span>Annuler</span>
                    </button>
                    <button type="submit" class="btn-chic btn-chic-primary">
                        <i class="bi bi-check-lg"></i><span>Enregistrer</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal confirmation suppression -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Confirmer la suppression</h5>
                <p class="text-muted small mb-4">Êtes-vous sûr de vouloir supprimer <strong id="deleteNomUser" class="text-danger"></strong> ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger rounded-3" id="confirmDeleteBtn"><i class="bi bi-trash3 me-1"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaires cachés -->
<form id="deleteForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="btn_supprimer" value="1">
    <input type="hidden" name="sai_supprimer_id" id="deleteIdField" value="">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>
<form id="actionForm" method="post" style="display:none;">
    <input type="hidden" name="action" id="actionField" value="">
    <input type="hidden" name="edit_id" id="editIdField" value="">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>

<!-- Toast -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:2000;">
    <div id="toastMsg" class="toast align-items-center text-white border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-semibold" id="toastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker();
    
    const userModalEl = document.getElementById('userModal');
    const userModal = new bootstrap.Modal(userModalEl);
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    const toastEl = document.getElementById('toastMsg');
    const toast = new bootstrap.Toast(toastEl, { delay: 2500 });
    
    function showToast(msg, type = 'success') {
        const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-primary' };
        const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        $('#toastBody').html(`<i class="bi ${icons[type]} me-2"></i>${msg}`);
        toastEl.className = `toast align-items-center text-white border-0 ${colors[type]}`;
        toast.show();
    }
    
    setTimeout(function() { $('.alert').alert('close'); }, 5000);
    
    <?php if ($message): ?>
    showToast('<?= addslashes($message) ?>', '<?= $messageType === 'success' ? 'success' : ($messageType === 'danger' ? 'error' : 'info') ?>');
    <?php endif; ?>
    
    window.toggleBoutiqueRequired = function(role) {
        if (role === 'Vendeur' || role === 'Caisse') $('#boutiqueRequiredMsg').show();
        else $('#boutiqueRequiredMsg').hide();
    };
    
    <?php if ($editUser): ?>
    toggleBoutiqueRequired('<?= e($editUser['role']) ?>');
    <?php else: ?>
    toggleBoutiqueRequired('');
    <?php endif; ?>
    
    // ===== BOUTON NOUVEL UTILISATEUR =====
    $('#addUserBtn').click(function() {
        $('#formAction').val('add');
        $('#formOldId').val('');
        $('#modalTitle i').attr('class', 'bi bi-person-plus-fill');
        $('#modalTitleText').text('Nouvel utilisateur');
        $('#userForm')[0].reset();
        $('#date_saisie').val('<?= date('Y-m-d') ?>');
        $('#etat').val('Actif');
        $('#boutique_id').selectpicker('val', '');
        $('#boutiqueRequiredMsg').hide();
        $('#mdpFieldWrapper').show();
        userModal.show();
    });
    
    // ===== BOUTON MODIFIER - OUVERTURE DU MODAL =====
    $(document).on('click', '.editBtn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const id = $(this).data('id');
        $('#actionField').val('load_edit');
        $('#editIdField').val(id);
        $('#actionForm').submit();
    });
    
    // ===== OUVRIR LE MODAL AUTOMATIQUEMENT APRÈS CHARGEMENT DES DONNÉES D'ÉDITION =====
    <?php if (isset($editUser) && $action === 'load_edit'): ?>
    $(function() {
        $('#formAction').val('edit');
        $('#formOldId').val('<?= e($editUser['id']) ?>');
        $('#modalTitle i').attr('class', 'bi bi-pencil-square');
        $('#modalTitleText').text('Modifier l\'utilisateur');
        $('#mdpFieldWrapper').hide();
        $('#boutique_id').selectpicker('val', '<?= e($editUser['boutique_id'] ?? '') ?>');
        toggleBoutiqueRequired('<?= e($editUser['role']) ?>');
        
        // Ouvrir le modal
        setTimeout(function() {
            userModal.show();
        }, 100);
    });
    <?php endif; ?>
    
    // ===== SUPPRESSION =====
    let userIdToDelete = null;
    $(document).on('click', '.deleteBtn', function() {
        userIdToDelete = $(this).data('id');
        const nom = $(this).data('nom');
        $('#deleteNomUser').text(nom);
        $('#deleteIdField').val(userIdToDelete);
        deleteModal.show();
    });
    
    $('#confirmDeleteBtn').click(function() {
        $('#deleteForm').submit();
    });
    
    // ===== RECHERCHE ET FILTRES =====
    $('#resetBtn').click(function() {
        $('#searchInput').val('');
        $('#boutiqueFilter').selectpicker('val', '');
        $('#roleFilter').selectpicker('val', '');
        $('#etatFilter').selectpicker('val', '');
        $('#searchForm').submit();
    });
    
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        const page = $(this).data('page');
        if (page && !$(this).parent().hasClass('disabled')) {
            $('#pageInput').val(page);
            $('#searchForm').submit();
        }
    });
});
</script>
</body>
</html>