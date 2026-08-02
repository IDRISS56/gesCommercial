<?php
ob_start();
require 'databases/database.php';

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

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function generateBoutiqueId($pdo) {
    $date = date('Ymd');
    $prefix = 'B-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM boutique WHERE code_boutique LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
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
            $etat = trim($_POST['etat_boutique'] ?? 'Actif');
            
            $logo = null;
            $type_logo = null;
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $logoData = file_get_contents($_FILES['logo']['tmp_name']);
                if ($logoData !== false) {
                    $logo = $logoData;
                    $type_logo = $_FILES['logo']['type'];
                }
            }
            
            if (!empty($latitude) && !is_numeric($latitude)) {
                $message = 'La latitude doit être un nombre valide.';
                $messageType = 'warning';
            } elseif (!empty($longitude) && !is_numeric($longitude)) {
                $message = 'La longitude doit être un nombre valide.';
                $messageType = 'warning';
            } else {
                $errors = [];
                if (empty($nom)) $errors[] = 'Le nom est requis.';
                if (empty($pays)) $errors[] = 'Le pays est requis.';
                
                if (empty($errors)) {
                    try {
                        if ($action === 'add') {
                            if (empty($code)) $code = generateBoutiqueId($pdo);
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM boutique WHERE code_boutique = ?");
                            $stmt->execute([$code]);
                            if ($stmt->fetchColumn() > 0) {
                                $message = "Ce code boutique existe déjà.";
                                $messageType = 'warning';
                            } else {
                                $sql = "INSERT INTO boutique (code_boutique, nom_boutique, telephone_boutique, email_boutique, pays_boutique, ville_boutique, quartier_boutique, adresse_boutique, latitude, longitude, etat_boutique, logo, type_logo)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$code, $nom, $telephone, $email, $pays, $ville, $quartier, $adresse, $latitude ?: null, $longitude ?: null, $etat, $logo, $type_logo]);
                                $message = "Boutique « $nom » ajoutée avec succès. Code : $code";
                                $messageType = 'success';
                            }
                        } elseif ($action === 'edit') {
                            $oldCode = $_POST['old_code'] ?? $code;
                            if ($logo !== null) {
                                $sql = "UPDATE boutique SET nom_boutique=?, telephone_boutique=?, email_boutique=?, pays_boutique=?, ville_boutique=?, quartier_boutique=?, adresse_boutique=?, latitude=?, longitude=?, etat_boutique=?, logo=?, type_logo=? WHERE code_boutique = ?";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$nom, $telephone, $email, $pays, $ville, $quartier, $adresse, $latitude ?: null, $longitude ?: null, $etat, $logo, $type_logo, $oldCode]);
                            } else {
                                $sql = "UPDATE boutique SET nom_boutique=?, telephone_boutique=?, email_boutique=?, pays_boutique=?, ville_boutique=?, quartier_boutique=?, adresse_boutique=?, latitude=?, longitude=?, etat_boutique=? WHERE code_boutique = ?";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$nom, $telephone, $email, $pays, $ville, $quartier, $adresse, $latitude ?: null, $longitude ?: null, $etat, $oldCode]);
                            }
                            $message = "Boutique « $nom » mise à jour.";
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

function getTableContent($pdo, $search, $filtres, $page, $perPage = 20) {
    $sql = "SELECT code_boutique, nom_boutique, telephone_boutique, email_boutique, pays_boutique, ville_boutique, quartier_boutique, adresse_boutique, latitude, longitude, etat_boutique, logo, type_logo FROM boutique WHERE 1=1";
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
    
    $countSql = str_replace("SELECT code_boutique, nom_boutique, telephone_boutique, email_boutique, pays_boutique, ville_boutique, quartier_boutique, adresse_boutique, latitude, longitude, etat_boutique, logo, type_logo", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    
    $sql .= " ORDER BY nom_boutique LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $boutiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ob_start();
    if (empty($boutiques)):
    ?>
    <tr><td colspan="10" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>Aucune boutique trouvée</td></tr>
    <?php else: foreach ($boutiques as $b): ?>
    <tr>
        <td class="td-bold"><?= e($b['code_boutique']) ?></td>
        <td><?= e($b['nom_boutique']) ?></td>
        <td>
            <?php if (!empty($b['type_logo'])): ?>
            <img src="data:<?= e($b['type_logo']) ?>;base64,<?= base64_encode($b['logo'] ?? '') ?>" alt="Logo" style="width:40px;height:40px;object-fit:contain;border-radius:6px;border:1px solid var(--border-color);">
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        <td><?= e($b['telephone_boutique'] ?? '—') ?></td>
        <td><?= e($b['email_boutique'] ?? '—') ?></td>
        <td><?= e($b['pays_boutique']) ?></td>
        <td><?= e($b['ville_boutique'] ?? '—') ?></td>
        <td><?= e($b['quartier_boutique'] ?? '—') ?></td>
        <td>
            <span class="status-badge <?= $b['etat_boutique'] === 'Actif' ? 'on' : 'off' ?>">
                <span class="sdot"></span>
                <?= e($b['etat_boutique']) ?>
            </span>
        </td>
        <td class="text-end">
            <div class="d-inline-flex gap-1">
                <button class="act-btn e editBtn" data-code="<?= e($b['code_boutique']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                <button class="act-btn d deleteBtn" data-code="<?= e($b['code_boutique']) ?>" data-nom="<?= e($b['nom_boutique']) ?>" title="Supprimer"><i class="bi bi-trash"></i></button>
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

// Gestion AJAX
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $filtres = ['etat' => trim($_POST['etat'] ?? '')];
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $filtres, $page);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

$search = trim($_POST['search'] ?? '');
$filtres = ['etat' => trim($_POST['etat'] ?? '')];
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

$initialData = getTableContent($pdo, $search, $filtres, $page);

$editBoutique = null;
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM boutique WHERE code_boutique = ?");
    $stmt->execute([$code]);
    $editBoutique = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Statistiques
$totalBoutiques = $pdo->query("SELECT COUNT(*) FROM boutique")->fetchColumn();
$actifs = $pdo->query("SELECT COUNT(*) FROM boutique WHERE etat_boutique = 'Actif'")->fetchColumn();
$inactifs = $pdo->query("SELECT COUNT(*) FROM boutique WHERE etat_boutique = 'Inactif'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion des boutiques</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
    --color-info: #0891b2;
    --color-info-soft: #cffafe;
    --color-purple: #8b5cf6;
    --color-purple-soft: #ede9fe;
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
    --border-color: #e2e8f0;
    --text-primary: #0f172a;
    --text-secondary: #334155;
    --text-tertiary: #64748b;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
    --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.06);
    --radius-sm: 10px;
    --radius-md: 14px;
    --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg-body); color: var(--text-primary); min-height: 100vh; font-size: 14px; padding: 24px 20px; }
h1, h2, h3, h4, h5, h6 { font-family: 'Outfit', sans-serif; font-weight: 700; letter-spacing: -0.02em; }
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
::-webkit-scrollbar-track { background: transparent; }
.W { max-width: 1400px; margin: 0 auto; }

.stat-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base); }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

.data-table-wrap { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-sm); overflow: hidden; box-shadow: var(--shadow-sm); animation: fadeUp .4s ease both; }
.table { margin: 0; }
.table thead th { background: var(--color-gray-100); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; padding: 12px 14px; border-bottom: 2px solid var(--border-color); }
.table tbody tr { border-bottom: 1px solid var(--border-color); transition: background .2s; }
.table tbody tr:hover { background: var(--color-primary-soft); }
.table tbody td { padding: 12px 14px; vertical-align: middle; color: var(--text-primary); font-size: 13px; }
.td-bold { color: var(--text-primary) !important; font-weight: 700; }

.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
.status-badge .sdot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: pulse 2s infinite; }
.status-badge.on { background: var(--color-success-soft); color: #065f46; }
.status-badge.off { background: var(--color-danger-soft); color: #991b1b; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

.act-btn { width: 32px; height: 32px; border-radius: 6px; border: 1.5px solid transparent; background: transparent; display: inline-flex; align-items: center; justify-content: center; transition: all .2s; font-size: 14px; cursor: pointer; padding: 0; }
.act-btn:hover { transform: scale(1.1); }
.act-btn.e { color: var(--color-warning); border-color: rgba(245, 158, 11, 0.2); }
.act-btn.e:hover { color: #b45309; background: var(--color-warning-soft); border-color: var(--color-warning); }
.act-btn.d { color: var(--color-danger); border-color: rgba(239, 68, 68, 0.2); }
.act-btn.d:hover { color: #b91c1c; background: var(--color-danger-soft); border-color: var(--color-danger); }

.btn-chic { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; letter-spacing: -0.01em; }
.btn-chic::before { content: ''; position: absolute; top: 50%; left: 50%; width: 0; height: 0; background: rgba(255,255,255,0.3); border-radius: 50%; transform: translate(-50%, -50%); transition: width .4s, height .4s; }
.btn-chic:hover::before { width: 300px; height: 300px; }
.btn-chic i { font-size: 15px; position: relative; z-index: 1; }
.btn-chic span { position: relative; z-index: 1; }
.btn-chic-primary { background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); color: #fff; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
.btn-chic-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4); }
.btn-go-outline { background: transparent; color: var(--text-tertiary); border: 1.5px solid var(--border-color); padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; transition: all .2s; cursor: pointer; }
.btn-go-outline:hover { background: var(--color-gray-100); border-color: var(--color-gray-300); }

.modal-chic .modal-content { border: none !important; border-radius: 20px !important; box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15) !important; overflow: hidden !important; animation: modalSlideIn .4s cubic-bezier(0.16, 1, 0.3, 1); display: flex !important; flex-direction: column !important; max-height: 90vh !important; }
@keyframes modalSlideIn { from { opacity: 0; transform: translateY(30px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
.modal-chic .modal-header { background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%); color: #fff; border: none; padding: 22px 28px; position: relative; overflow: hidden; flex-shrink: 0 !important; }
.modal-chic .modal-header::before { content: ''; position: absolute; top: -50%; right: -20%; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%; }
.modal-chic .modal-title { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
.modal-chic .modal-title i { font-size: 22px; background: rgba(255,255,255,0.15); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.modal-chic .btn-close { filter: invert(1); opacity: 0.7; position: relative; z-index: 1; }
.modal-chic .btn-close:hover { opacity: 1; transform: rotate(90deg); }
.modal-chic .modal-body { padding: 28px !important; overflow-y: auto !important; background: #f8fafc !important; flex: 1 1 auto !important; min-height: 0 !important; }
.modal-chic .modal-footer { background: #ffffff !important; border-top: 2px solid var(--border-color) !important; padding: 18px 28px !important; display: flex !important; gap: 10px !important; justify-content: flex-end !important; flex-wrap: wrap !important; flex-shrink: 0 !important; }

.form-label { font-size: 10px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.form-control, .form-select { border-radius: 10px; border: 1.5px solid var(--border-color); padding: 10px 14px; font-size: 13px; transition: all .2s; }
.form-control:focus, .form-select:focus { border-color: var(--color-primary); box-shadow: 0 0 0 3px var(--color-primary-soft); }

#map { height: 300px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 12px; background: var(--color-primary-soft); }

.bootstrap-select .dropdown-toggle { background: #fff !important; border: 1.5px solid var(--border-color) !important; border-radius: 8px !important; min-width: 220px; }
.bootstrap-select .dropdown-toggle:focus { border-color: var(--color-primary) !important; box-shadow: 0 0 0 3px var(--color-primary-soft) !important; }

.logo-preview { max-width: 120px; max-height: 80px; border-radius: 6px; border: 1px solid var(--border-color); object-fit: contain; background: white; }

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
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-shop text-primary me-2"></i>Gestion des boutiques</h1>
            <p class="text-muted small mb-0">Gérez vos points de vente et localisez-les sur la carte</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                <i class="bi bi-shop"></i> <?= $totalBoutiques ?> boutique(s)
            </span>
            <button type="button" class="btn-chic btn-chic-primary" id="addBtn">
                <i class="bi bi-plus-circle"></i>
                <span>Nouvelle boutique</span>
            </button>
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
            ['primary', 'shop', 'Total boutiques', $totalBoutiques, ''],
            ['success', 'check-circle-fill', 'Actives', $actifs, ''],
            ['danger', 'x-circle-fill', 'Inactives', $inactifs, ''],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'danger' => ['var(--color-danger-soft)', 'var(--color-danger)'],
        ];
        foreach ($stats as $s): $bg = $colorMap[$s[0]][0]; $fg = $colorMap[$s[0]][1]; ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background: <?= $bg ?>; color: <?= $fg ?>;">
                    <i class="bi bi-<?= $s[1] ?>"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-label"><?= $s[2] ?></div>
                    <div class="stat-value text-truncate"><?= $s[3] ?><?php if ($s[4]): ?><small class="text-muted ms-1" style="font-size:11px;"><?= $s[4] ?></small><?php endif; ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="bg-white border rounded-3 p-3 mb-4 shadow-sm">
        <form id="searchForm" method="post" onsubmit="return false;">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-search"></i> Recherche</label>
                <input type="text" id="searchInput" class="form-control" placeholder="Code, nom, ville, pays..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                
                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-toggle-on"></i> État</label>
                <select id="etatFilter" class="selectpicker">
                    <option value="">Tous</option>
                    <option value="Actif" <?= ($filtres['etat'] == 'Actif') ? 'selected' : '' ?>>Actif</option>
                    <option value="Inactif" <?= ($filtres['etat'] == 'Inactif') ? 'selected' : '' ?>>Inactif</option>
                </select>

                <button type="button" class="btn-chic btn-chic-primary" id="filterBtn"><i class="bi bi-funnel"></i><span>Filtrer</span></button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold">Liste des boutiques</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> boutique(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Nom</th>
                        <th>Logo</th>
                        <th>Téléphone</th>
                        <th>Email</th>
                        <th>Pays</th>
                        <th>Ville</th>
                        <th>Quartier</th>
                        <th>État</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?= $initialData['table'] ?>
                </tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $initialData['pagination'] ?></div>
    </div>
</div>

<form id="deleteForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="btn_supprimer" value="1">
    <input type="hidden" name="sai_supprimer_id" id="deleteCodeField" value="">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>
<form id="actionForm" method="post" style="display:none;">
    <input type="hidden" name="action" id="actionField" value="">
    <input type="hidden" name="edit_code" id="editCodeField" value="">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>

<div class="modal fade modal-chic" id="boutiqueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">
                    <i class="bi bi-shop"></i>
                    <span id="modalTitleText">Nouvelle boutique</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="boutiqueForm" enctype="multipart/form-data" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_code" id="formOldCode" value="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-primary);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-tag-fill"></i> Identification
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Code boutique</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="code_boutique" name="code_boutique" readonly value="<?= e($editBoutique['code_boutique'] ?? generateBoutiqueId($pdo)) ?>">
                            </div>
                            <div class="form-text" style="font-size:11px;color:var(--text-tertiary);margin-top:4px;">Généré automatiquement</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nom de la boutique <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-shop"></i></span>
                                <input type="text" class="form-control" id="nom_boutique" name="nom_boutique" required placeholder="Ma boutique" value="<?= e($editBoutique['nom_boutique'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-info);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-telephone-fill"></i> Contact
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Téléphone</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                <input type="text" class="form-control" id="telephone_boutique" name="telephone_boutique" placeholder="+225 05..." value="<?= e($editBoutique['telephone_boutique'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" id="email_boutique" name="email_boutique" placeholder="boutique@example.com" value="<?= e($editBoutique['email_boutique'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-purple);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-geo-alt-fill"></i> Localisation
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Pays <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="pays_boutique" name="pays_boutique" placeholder="Côte d'Ivoire" value="<?= e($editBoutique['pays_boutique'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ville</label>
                            <input type="text" class="form-control" id="ville_boutique" name="ville_boutique" placeholder="Abidjan" value="<?= e($editBoutique['ville_boutique'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quartier</label>
                            <input type="text" class="form-control" id="quartier_boutique" name="quartier_boutique" placeholder="Cocody" value="<?= e($editBoutique['quartier_boutique'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Adresse</label>
                            <input type="text" class="form-control" id="adresse_boutique" name="adresse_boutique" placeholder="Rue des commerçants" value="<?= e($editBoutique['adresse_boutique'] ?? '') ?>">
                        </div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-success);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-map-fill"></i> Position GPS
                    </h6>
                    <div id="map"></div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Latitude</label>
                            <input type="text" class="form-control" id="latitude" name="latitude" placeholder="5.35995" value="<?= e($editBoutique['latitude'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Longitude</label>
                            <input type="text" class="form-control" id="longitude" name="longitude" placeholder="-3.99995" value="<?= e($editBoutique['longitude'] ?? '') ?>">
                        </div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-warning);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-gear-fill"></i> Statut
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">État</label>
                            <select class="form-select" id="etat_boutique" name="etat_boutique">
                                <option value="Actif" <?= (isset($editBoutique) && $editBoutique['etat_boutique'] === 'Actif') ? 'selected' : '' ?>>Actif</option>
                                <option value="Inactif" <?= (isset($editBoutique) && $editBoutique['etat_boutique'] === 'Inactif') ? 'selected' : '' ?>>Inactif</option>
                            </select>
                        </div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-danger);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-image-fill"></i> Logo
                    </h6>
                    <div class="row g-3">
                        <div class="col-12">
                            <?php if (isset($editBoutique) && !empty($editBoutique['logo'])): ?>
                            <div class="mb-2">
                                <img src="data:<?= e($editBoutique['type_logo'] ?? 'image/png') ?>;base64,<?= base64_encode($editBoutique['logo']) ?>" alt="Logo actuel" class="logo-preview">
                                <div class="form-text" style="font-size:11px;color:var(--text-tertiary);margin-top:4px;">Logo actuel. Téléchargez une nouvelle image pour le remplacer.</div>
                            </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="logo" name="logo" accept="image/*">
                            <div class="form-text" style="font-size:11px;color:var(--text-tertiary);margin-top:4px;">Formats : JPEG, PNG, GIF (max 2 Mo).</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-chic" style="background:var(--color-gray-100);color:var(--text-secondary);" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i>
                        <span>Annuler</span>
                    </button>
                    <button type="submit" class="btn-chic btn-chic-primary">
                        <i class="bi bi-check-lg"></i>
                        <span>Enregistrer</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Confirmer la suppression</h5>
                <p class="text-muted small mb-4">Êtes-vous sûr de vouloir supprimer la boutique <strong id="deleteNomBoutique" class="text-danger"></strong> ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger rounded-3" id="confirmDeleteBtn">
                        <i class="bi bi-trash3 me-1"></i> Supprimer
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

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
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker();
    
    const boutiqueModalEl = document.getElementById('boutiqueModal');
    const boutiqueModal = new bootstrap.Modal(boutiqueModalEl);
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    const toastEl = document.getElementById('toastMsg');
    const toast = new bootstrap.Toast(toastEl, { delay: 2500 });
    
    let map = null;
    let marker = null;
    
    function initMap(lat, lng) {
        if (map) { map.remove(); map = null; marker = null; }
        const defaultLat = (typeof lat === 'number' && !isNaN(lat)) ? lat : 5.35995;
        const defaultLng = (typeof lng === 'number' && !isNaN(lng)) ? lng : -3.99995;
        map = L.map('map').setView([defaultLat, defaultLng], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);
        marker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(map);
        marker.on('dragend', function(e) {
            const pos = marker.getLatLng();
            $('#latitude').val(pos.lat.toFixed(6));
            $('#longitude').val(pos.lng.toFixed(6));
        });
        map.on('click', function(e) {
            marker.setLatLng(e.latlng);
            $('#latitude').val(e.latlng.lat.toFixed(6));
            $('#longitude').val(e.latlng.lng.toFixed(6));
        });
    }
    
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
    
    function rechercher(page) {
        page = page || 1;
        var search = $('#searchInput').val();
        var etat = $('#etatFilter').val();
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: { ajax: 1, search: search, etat: etat, page: page },
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' boutique(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
            }
        });
    }
    
    let searchTimeout;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#etatFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#filterBtn').on('click', function() { rechercher(1); });
    $('#resetBtn').click(function() {
        $('#searchInput').val('');
        $('#etatFilter').selectpicker('val', '');
        rechercher(1);
    });
    
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        const page = $(this).data('page');
        if (page && !$(this).parent().hasClass('disabled')) rechercher(page);
    });
    
    $('#addBtn').click(function() {
        $('#formAction').val('add');
        $('#formOldCode').val('');
        $('#modalTitle i').attr('class', 'bi bi-shop');
        $('#modalTitleText').text('Nouvelle boutique');
        $('#boutiqueForm')[0].reset();
        $('#code_boutique').val('<?= generateBoutiqueId($pdo) ?>');
        $('#etat_boutique').val('Actif');
        boutiqueModal.show();
    });
    
    boutiqueModalEl.addEventListener('shown.bs.modal', function() {
        setTimeout(function() {
            if (map) map.invalidateSize();
            else initMap(5.35995, -3.99995);
        }, 100);
    });
    
    $(document).on('click', '.editBtn', function(e) {
        e.preventDefault();
        const code = $(this).data('code');
        $('#actionField').val('load_edit');
        $('#editCodeField').val(code);
        $('#actionForm').submit();
    });
    
    let boutiqueCodeToDelete = null;
    $(document).on('click', '.deleteBtn', function() {
        boutiqueCodeToDelete = $(this).data('code');
        const nom = $(this).data('nom');
        $('#deleteNomBoutique').text(nom);
        $('#deleteCodeField').val(boutiqueCodeToDelete);
        deleteModal.show();
    });
    
    $('#confirmDeleteBtn').click(function() { $('#deleteForm').submit(); });
    
    <?php if (isset($editBoutique) && $action === 'load_edit'): ?>
    $(function() {
        $('#formAction').val('edit');
        $('#formOldCode').val('<?= e($editBoutique['code_boutique']) ?>');
        $('#modalTitle i').attr('class', 'bi bi-pencil-square');
        $('#modalTitleText').text('Modifier la boutique');
        boutiqueModal.show();
        boutiqueModalEl.addEventListener('shown.bs.modal', function onShown() {
            const lat = parseFloat('<?= e($editBoutique['latitude'] ?? '5.35995') ?>') || 5.35995;
            const lng = parseFloat('<?= e($editBoutique['longitude'] ?? '-3.99995') ?>') || -3.99995;
            initMap(lat, lng);
            boutiqueModalEl.removeEventListener('shown.bs.modal', onShown);
        }, { once: true });
        $('.selectpicker').selectpicker('refresh');
    });
    <?php endif; ?>
});
</script>
</body>
</html>