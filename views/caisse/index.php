<?php
ob_start();
function sendJson($data){
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json');
echo json_encode($data); exit;
}
require __DIR__ . '/../../databases/database.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../utilisateur/login'); exit; }
$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { session_destroy(); header('Location: ../utilisateur/login'); exit; }
if (empty($_SESSION['csrf_token'])) {
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$message = '';
$messageType = '';
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// FONCTIONS UTILITAIRES
// ============================================================
function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDec($n) { return number_format((float)$n, 0, ',', ' '); }
function generateCaisseId($pdo) {
$prefix = 'CS-' . date('Ymd') . '-';
$stmt = $pdo->prepare("SELECT caisse_id FROM caisse WHERE caisse_id LIKE ? ORDER BY caisse_id DESC LIMIT 1");
$stmt->execute([$prefix . '%']);
$last = $stmt->fetchColumn();
if ($last) {
$num = (int)substr($last, strrpos($last, '-') + 1) + 1;
} else {
$num = 1;
}
return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// ============================================================
// LISTE (AJAX)
// ============================================================
function getTableContent($pdo, $search, $boutique_filter, $page, $perPage = 12) {
$sql = "SELECT c.*, b.nom_boutique
FROM caisse c
LEFT JOIN boutique b ON b.code_boutique = c.boutique_id
WHERE 1=1";
$params = [];
if (!empty($search)) {
$sql .= " AND (c.caisse_id LIKE ? OR c.nom_caisse LIKE ?)";
$like = '%' . $search . '%';
$params[] = $like; $params[] = $like;
}
if (!empty($boutique_filter)) {
$sql .= " AND c.boutique_id = ?";
$params[] = $boutique_filter;
}
$countSql = str_replace("SELECT c.*, b.nom_boutique", "SELECT COUNT(*)", $sql);
$stmt = $pdo->prepare($countSql); $stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = ceil($total / $perPage);
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
$sql .= " ORDER BY c.caisse_id DESC LIMIT " . (($page - 1) * $perPage) . ", $perPage";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
if (empty($rows)):
?>
<div class="col-12">
<div class="empty-state">
<i class="bi bi-cash-stack d-block mb-2" style="font-size:56px;opacity:.2;"></i>
<h5 class="text-dark">Aucune caisse trouvée</h5>
<p class="small mb-0">Les caisses apparaîtront ici dès leur création.</p>
</div>
</div>
<?php else: foreach ($rows as $c):
$estOuverte = ($c['statut'] === 'Actif');
$stmtJ = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'OUVERTE'");
$stmtJ->execute([$c['caisse_id']]);
$journeeOuverte = $stmtJ->fetchColumn() > 0;
?>
<div class="col-12 col-md-6 col-lg-4 col-xl-3 caisse-item"
data-boutique="<?= e($c['boutique_id'] ?? '') ?>"
data-statut="<?= e($c['statut']) ?>"
data-id="<?= e($c['caisse_id']) ?>">
<div class="caisse-card <?= $estOuverte ? 'active' : 'inactive' ?> <?= $journeeOuverte ? 'has-open-day' : '' ?>">
<div class="cc-top">
<div>
<div class="cc-code"><?= e($c['caisse_id']) ?></div>
<div class="cc-boutique"><i class="bi bi-shop"></i> <?= e($c['nom_boutique'] ?? 'Aucune boutique') ?></div>
</div>
<div class="cc-solde"><?= fmtDec($c['solde']) ?><small>FCFA</small></div>
</div>
<div class="cc-middle">
<div class="cc-nom">
<i class="bi bi-cash-register"></i>
<span><?= e($c['nom_caisse']) ?></span>
</div>
<div class="cc-badges">
<?php if ($journeeOuverte): ?>
<span class="badge-pill bg-success-subtle text-success">
<i class="bi bi-circle-fill" style="font-size:6px;"></i> Journée ouverte
</span>
<?php else: ?>
<span class="badge-pill bg-<?= $estOuverte ? 'success' : 'secondary' ?>-subtle text-<?= $estOuverte ? 'success' : 'secondary' ?>">
<i class="bi bi-<?= $estOuverte ? 'check-circle-fill' : 'x-circle-fill' ?>" style="font-size:8px;"></i>
<?= $estOuverte ? 'Actif' : 'Inactif' ?>
</span>
<?php endif; ?>
</div>
</div>
<div class="cc-bottom">
<button class="icon-btn view voir-caisse" data-id="<?= e($c['caisse_id']) ?>" data-tooltip="Voir détails" title="Voir détails"><i class="bi bi-eye"></i></button>
<button class="icon-btn edit editBtn" data-id="<?= e($c['caisse_id']) ?>" data-tooltip="Modifier" title="Modifier"><i class="bi bi-pencil"></i></button>
<button class="icon-btn delete deleteBtn" data-id="<?= e($c['caisse_id']) ?>" data-nom="<?= e($c['nom_caisse']) ?>" data-tooltip="Supprimer" title="Supprimer"><i class="bi bi-trash"></i></button>
</div>
</div>
</div>
<?php endforeach; endif;
$tableHtml = ob_get_clean();

ob_start();
if ($totalPages > 1):
?>
<div class="pagination-wrap">
<small class="text-muted" id="totalCount"><?= $total ?> caisse(s) — Page <?= $page ?> / <?= max(1, $totalPages) ?></small>
<nav>
<ul class="pagination pagination-sm mb-0">
<?php for ($i = 1; $i <= $totalPages; $i++): ?>
<li class="page-item <?= $i == $page ? 'active' : '' ?>">
<a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
</li>
<?php endfor; ?>
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
'totalPages' => max(1, $totalPages)
];
}

// ============================================================
// TRAITEMENT AJAX
// ============================================================
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
$result = getTableContent($pdo, trim($_POST['search'] ?? ''), trim($_POST['boutique_filter'] ?? ''), (int)($_POST['page'] ?? 1));
sendJson($result);
}

// ============================================================
// DONNÉES ET STATISTIQUES
// ============================================================
$initialData = getTableContent($pdo, '', '', 1);
$editCaisse = null;

$totalCaisses = $pdo->query("SELECT COUNT(*) FROM caisse")->fetchColumn();
$caissesActives = $pdo->query("SELECT COUNT(*) FROM caisse WHERE statut = 'Actif'")->fetchColumn();
$caissesInactives = $pdo->query("SELECT COUNT(*) FROM caisse WHERE statut = 'Inactif'")->fetchColumn();
$soldeTotal = $pdo->query("SELECT SUM(solde) FROM caisse")->fetchColumn() ?? 0;
$journeesOuvertes = $pdo->query("SELECT COUNT(DISTINCT caisse_id) FROM journees_caisse WHERE statut = 'OUVERTE'")->fetchColumn();
$nbBoutiques = count($boutiques);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$action = $_POST['action'] ?? '';
if ($action === 'load_edit' && isset($_POST['edit_id'])) {
$stmt = $pdo->prepare("SELECT * FROM caisse WHERE caisse_id = ?");
$stmt->execute([$_POST['edit_id']]);
$editCaisse = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (isset($_POST['btn_enregistrer'])) {
$token = $_POST['csrf_token'] ?? '';
$oldId = $_POST['old_caisse_id'] ?? '';
if ($token !== $csrf_token) {
$message = "Token invalide."; $messageType = 'danger';
} else {
$nom_caisse = trim($_POST['nom_caisse'] ?? '');
$boutique_id = trim($_POST['boutique_id'] ?? '') ?: null;
$solde = floatval(str_replace(',', '.', $_POST['solde'] ?? 0));
$statut = trim($_POST['statut'] ?? 'Actif');
$errors = [];
if (empty($nom_caisse)) $errors[] = 'Le nom est requis.';
if (!in_array($statut, ['Actif', 'Inactif'])) $errors[] = 'Statut invalide.';
if (empty($errors)) {
try {
if (empty($oldId)) {
$newId = generateCaisseId($pdo);
$pdo->prepare("INSERT INTO caisse (caisse_id, nom_caisse, solde, boutique_id, statut) VALUES (?, ?, ?, ?, ?)")
->execute([$newId, $nom_caisse, $solde, $boutique_id, $statut]);
$message = "Caisse « $nom_caisse » créée avec succès."; $messageType = 'success';
} else {
$pdo->prepare("UPDATE caisse SET nom_caisse=?, boutique_id=?, solde=?, statut=? WHERE caisse_id = ?")
->execute([$nom_caisse, $boutique_id, $solde, $statut, $oldId]);
$message = "Caisse « $nom_caisse » mise à jour."; $messageType = 'success';
}
} catch (PDOException $e) {
$message = "Erreur : " . $e->getMessage(); $messageType = 'danger';
}
} else {
$message = implode('<br>', $errors); $messageType = 'warning';
}
}
$initialData = getTableContent($pdo, '', '', 1);
}
if (isset($_POST['btn_supprimer']) && $_POST['btn_supprimer'] == '1') {
$token = $_POST['csrf_token'] ?? '';
if ($token !== $csrf_token) {
$message = "Token invalide."; $messageType = 'danger';
} else {
$caisse_id = $_POST['sai_supprimer_id'] ?? '';
if (!empty($caisse_id)) {
try {
$stmt = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'OUVERTE'");
$stmt->execute([$caisse_id]);
if ($stmt->fetchColumn() > 0) {
$message = "Impossible : une journée est ouverte sur cette caisse."; $messageType = 'warning';
} else {
$stmt = $pdo->prepare("SELECT nom_caisse FROM caisse WHERE caisse_id = ?");
$stmt->execute([$caisse_id]);
$nom = $stmt->fetchColumn();
$pdo->prepare("DELETE FROM caisse WHERE caisse_id = ?")->execute([$caisse_id]);
$message = "Caisse « $nom » supprimée."; $messageType = 'danger';
}
} catch (PDOException $e) {
$message = "Erreur : " . $e->getMessage(); $messageType = 'danger';
}
}
}
$initialData = getTableContent($pdo, '', '', 1);
}
}
$newCode = generateCaisseId($pdo);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion des caisses</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
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
--shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.08);
--radius-sm: 10px;
--radius-md: 14px;
--transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
font-family: 'Inter', sans-serif;
background: var(--bg-body);
color: var(--text-primary);
min-height: 100vh;
font-size: 14px;
padding: 24px 20px;
}
h1, h2, h3, h4, h5, h6 {
font-family: 'Outfit', sans-serif;
font-weight: 700;
letter-spacing: -0.02em;
}
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
::-webkit-scrollbar-track { background: transparent; }
.W { max-width: 1400px; margin: 0 auto; }

/* ===== CARTES CAISSES ===== */
.caisse-card {
background: var(--bg-surface);
border: 1px solid var(--border-color);
border-radius: var(--radius-sm);
padding: 14px 16px;
cursor: pointer;
transition: all 0.15s ease;
display: flex;
flex-direction: column;
justify-content: space-between;
min-height: 140px;
position: relative;
animation: fadeUp .4s ease both;
}
.caisse-card:hover {
border-color: var(--color-primary);
box-shadow: 0 4px 12px rgba(79, 70, 229, .12);
transform: translateY(-2px);
}
.caisse-card.active {
border-color: var(--color-success);
background: #f0fdf4;
}
.caisse-card.active::after {
content: '✓';
position: absolute;
top: 6px;
right: 6px;
background: var(--color-success);
color: #fff;
width: 18px;
height: 18px;
border-radius: 50%;
font-size: 10px;
display: flex;
align-items: center;
justify-content: center;
font-weight: 700;
}
.caisse-card.has-open-day {
border-color: var(--color-info);
background: linear-gradient(135deg, #f0fdfa 0%, #ecfeff 100%);
}
.caisse-card.has-open-day::before {
content: '';
position: absolute;
top: 0;
left: 0;
right: 0;
height: 3px;
background: linear-gradient(90deg, var(--color-info), #06b6d4);
border-radius: var(--radius-sm) var(--radius-sm) 0 0;
}
.cc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; }
.cc-code { font-size: 11px; font-weight: 700; color: var(--color-primary-dark); font-family: 'Outfit', sans-serif; }
.caisse-card.active .cc-code { color: #059669; }
.cc-solde { font-size: 16px; font-weight: 800; color: var(--color-primary); font-family: 'Outfit', sans-serif; line-height: 1; }
.cc-solde small { font-size: 9px; color: var(--text-tertiary); margin-left: 2px; }
.cc-middle { flex: 1; display: flex; flex-direction: column; gap: 4px; margin: 6px 0; }
.cc-nom { font-size: 13px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cc-nom i { color: var(--color-primary); font-size: 13px; flex-shrink: 0; }
.cc-boutique { font-size: 10px; color: var(--text-tertiary); display: flex; align-items: center; gap: 3px; }
.cc-boutique i { font-size: 10px; }
.cc-badges { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px; }
.badge-pill { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 999px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
.cc-bottom { border-top: 1px dashed var(--border-color); padding-top: 8px; display: flex; justify-content: flex-end; align-items: center; gap: 6px; }

/* Boutons icônes */
.icon-btn {
width: 28px; height: 28px; border-radius: 6px; border: 1.5px solid transparent;
background: transparent; display: inline-flex; align-items: center; justify-content: center;
transition: all .2s; font-size: 13px; cursor: pointer; padding: 0; position: relative;
}
.icon-btn:hover { transform: scale(1.1); }
.icon-btn.view { color: var(--color-primary); border-color: rgba(79, 70, 229, 0.2); }
.icon-btn.view:hover { color: var(--color-primary-dark); background: var(--color-primary-soft); border-color: var(--color-primary); }
.icon-btn.edit { color: var(--color-warning); border-color: rgba(245, 158, 11, 0.2); }
.icon-btn.edit:hover { color: #b45309; background: var(--color-warning-soft); border-color: var(--color-warning); }
.icon-btn.delete { color: var(--color-danger); border-color: rgba(239, 68, 68, 0.2); }
.icon-btn.delete:hover { color: #b91c1c; background: var(--color-danger-soft); border-color: var(--color-danger); }
.icon-btn::before {
content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%;
transform: translateX(-50%); background: var(--color-gray-800); color: #fff;
padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 600;
white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity .2s; z-index: 10;
}
.icon-btn:hover::before { opacity: 1; }

/* ===== STATS ===== */
.stat-card {
background: var(--bg-surface); border: 1px solid var(--border-color);
border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base);
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

/* ===== EMPTY STATE ===== */
.empty-state {
background: var(--bg-surface);
border: 2px dashed var(--border-color);
border-radius: var(--radius-md);
padding: 50px 20px;
text-align: center;
color: var(--text-tertiary);
}

/* ===== PAGINATION ===== */
.pagination-wrap {
background: var(--bg-surface);
border: 1px solid var(--border-color);
border-radius: var(--radius-sm);
padding: 12px 16px;
margin-top: 16px;
display: flex;
flex-wrap: wrap;
justify-content: space-between;
align-items: center;
gap: 10px;
}
.pagination .page-link {
border: 1px solid var(--border-color);
color: var(--text-tertiary);
font-size: 12px;
font-weight: 600;
padding: 6px 12px;
border-radius: 6px;
margin: 0 2px;
}
.pagination .page-item.active .page-link {
background: var(--color-primary);
color: #fff;
border-color: var(--color-primary);
}

/* ===== MODAL CHIC ===== */
.modal-chic .modal-content {
border: none; border-radius: 20px;
box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15); overflow: hidden;
animation: modalSlideIn .4s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalSlideIn {
from { opacity: 0; transform: translateY(30px) scale(0.96); }
to { opacity: 1; transform: translateY(0) scale(1); }
}
.modal-chic .modal-header {
background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%);
color: #fff; border: none; padding: 22px 28px; position: relative; overflow: hidden;
}
.modal-chic .modal-header::before {
content: ''; position: absolute; top: -50%; right: -20%; width: 200px; height: 200px;
background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%;
}
.modal-chic .modal-title { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
.modal-chic .modal-title i { font-size: 22px; background: rgba(255,255,255,0.15); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px); }
.modal-chic .btn-close { filter: invert(1); opacity: 0.7; position: relative; z-index: 1; transition: all .2s; }
.modal-chic .btn-close:hover { opacity: 1; transform: rotate(90deg); }
.modal-chic .modal-body { padding: 28px; background: #f8fafc; }
.modal-chic .modal-footer { background: #fff; border-top: 1px solid var(--border-color); padding: 18px 28px; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }

/* Sections chic */
.detail-section-chic { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 20px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); transition: all .2s; }
.detail-section-chic:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.06); border-color: #cbd5e1; }
.detail-section-title-chic { font-size: 11px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.8px; padding-bottom: 10px; border-bottom: 2px solid #f1f5f9; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.detail-section-title-chic.info i { color: #3b82f6; }
.detail-section-title-chic.money i { color: #10b981; }

.form-label-chic { font-size: 10px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.form-control-chic {
background: #fff;
border: 1.5px solid var(--border-color);
border-radius: 8px;
padding: 10px 14px;
font-size: 13px;
transition: all .2s;
}
.form-control-chic:focus {
border-color: var(--color-primary);
box-shadow: 0 0 0 3px var(--color-primary-soft);
outline: none;
}

/* Boutons chic */
.btn-chic { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s; position: relative; overflow: hidden; }
.btn-chic i { font-size: 15px; position: relative; z-index: 1; }
.btn-chic span { position: relative; z-index: 1; }
.btn-chic-primary { background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%); color: #fff; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
.btn-chic-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4); }
.btn-chic-danger { background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color: #fff; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
.btn-chic-danger:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4); }
.btn-chic-secondary { background: linear-gradient(135deg, #64748b 0%, #475569 100%); color: #fff; box-shadow: 0 4px 12px rgba(100, 116, 139, 0.25); }
.btn-chic-secondary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(100, 116, 139, 0.35); }

/* ===== BOUTON NOUVELLE CAISSE (style exemple) ===== */
.btn-nouvelle {
    background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
    color: #fff;
    border: none;
    border-radius: 50px;
    padding: 12px 28px;
    font-size: 15px;
    font-weight: 700;
    font-family: 'Outfit', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 6px 20px rgba(79, 70, 229, 0.35);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
    letter-spacing: 0.3px;
}
.btn-nouvelle:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(79, 70, 229, 0.45);
    background: linear-gradient(135deg, #5b52f0 0%, #4338ca 100%);
}
.btn-nouvelle:active {
    transform: translateY(0);
}
.btn-nouvelle i {
    font-size: 18px;
    background: rgba(255, 255, 255, 0.2);
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* ===== BADGE COMPTEUR (style exemple) ===== */
.badge-compteur {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    border: 1px solid #93c5fd;
    border-radius: 50px;
    padding: 12px 24px;
    font-size: 14px;
    font-weight: 700;
    font-family: 'Outfit', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15);
    letter-spacing: 0.3px;
}
.badge-compteur i {
    font-size: 16px;
    color: #3b82f6;
}

/* ===== SELECTPICKER ===== */
.bootstrap-select .dropdown-toggle {
background: #fff !important;
border: 1.5px solid var(--border-color) !important;
border-radius: 8px !important;
min-width: 220px;
}
.bootstrap-select .dropdown-toggle:focus {
border-color: var(--color-primary) !important;
box-shadow: 0 0 0 3px var(--color-primary-soft) !important;
}

/* ===== ANIMATIONS ===== */
@keyframes fadeUp {
from { opacity: 0; transform: translateY(12px); }
to { opacity: 1; transform: translateY(0); }
}
.caisse-item.deleting { animation: fadeOut .4s ease forwards; }
@keyframes fadeOut { to { opacity: 0; transform: scale(0.9); } }

@media (max-width: 700px) {
.bootstrap-select, .bootstrap-select .dropdown-toggle {
width: 100% !important;
min-width: 0 !important;
}
}
</style>
</head>
<body>
<div class="W">
<!-- En-tête -->
<div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1"><i class="bi bi-cash-stack text-primary me-2"></i>Gestion des caisses</h1>
        <p class="text-muted small mb-0">Créez et gérez les caisses de votre commerce</p>
    </div>
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <span class="badge-compteur">
            <i class="bi bi-shop"></i> <?= $totalCaisses ?> caisse(s)
        </span>
        <button class="btn-nouvelle" id="addBtn">
            <i class="bi bi-plus-lg"></i>
            <span>Nouvelle caisse</span>
        </button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
<?= $message ?>
<button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Statistiques -->
<div class="row g-3 mb-4">
<?php
$stats = [
['primary', 'cash-stack', 'Total caisses', $totalCaisses, ''],
['success', 'check-circle-fill', 'Caisses actives', $caissesActives, ''],
['secondary', 'x-circle-fill', 'Caisses inactives', $caissesInactives, ''],
['info', 'calendar-check', 'Journées ouvertes', $journeesOuvertes, ''],
['purple', 'shop', 'Boutiques', $nbBoutiques, ''],
['warning', 'wallet2', 'Solde total', fmtDec($soldeTotal), ' FCFA'],
];
$colorMap = [
'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
'success' => ['var(--color-success-soft)', 'var(--color-success)'],
'secondary' => ['var(--color-gray-100)', 'var(--color-gray-500)'],
'info' => ['var(--color-info-soft)', 'var(--color-info)'],
'purple' => ['var(--color-purple-soft)', 'var(--color-purple)'],
'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
];
foreach ($stats as $s):
$bg = $colorMap[$s[0]][0];
$fg = $colorMap[$s[0]][1];
?>
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

<!-- Filtres -->
<div class="bg-white border rounded-3 p-3 mb-4 shadow-sm">
<form id="searchForm" class="d-flex flex-wrap align-items-center gap-3" onsubmit="return false;">
<label for="searchInput" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-search"></i> Rechercher</label>
<input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Code ou nom..." style="min-width:200px;max-width:250px;">
<label for="boutiqueFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-shop"></i> Boutique</label>
<select id="boutiqueFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Toutes les boutiques...">
<option value="">Toutes les boutiques</option>
<?php foreach ($boutiques as $b): ?>
<option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
<?php endforeach; ?>
</select>
<button type="button" class="btn btn-primary fw-bold" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
<button type="button" class="btn btn-outline-secondary fw-semibold" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
</form>
</div>

<!-- Liste des caisses -->
<div class="row g-3" id="caissesGrid">
<?= $initialData['table'] ?>
</div>
<div id="paginationContainer"><?= $initialData['pagination'] ?></div>
</div>

<!-- Modal Ajout/Modification -->
<div class="modal fade modal-chic" id="caisseModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<form method="post">
<input type="hidden" name="action" id="formAction" value="add">
<input type="hidden" name="old_caisse_id" id="oldCaisseId" value="">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
<div class="modal-header">
<h5 class="modal-title" id="modalTitle"><i class="bi bi-cash-register"></i><span>Nouvelle caisse</span></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="detail-section-chic">
<div class="detail-section-title-chic info"><i class="bi bi-info-circle-fill"></i> INFORMATIONS GÉNÉRALES</div>
<div class="row g-3">
<div class="col-md-6">
<label class="form-label-chic">Code (auto)</label>
<input type="text" class="form-control form-control-chic" id="code_caisse" value="<?= e($newCode) ?>" readonly style="background:#f8fafc;">
</div>
<div class="col-md-6">
<label class="form-label-chic">Nom de la caisse *</label>
<input type="text" class="form-control form-control-chic" name="nom_caisse" id="nom_caisse" required placeholder="Ex: Caisse Principale">
</div>
<div class="col-md-6">
<label class="form-label-chic">Solde actuel</label>
<input type="number" step="0.01" class="form-control form-control-chic" name="solde" id="solde" value="0">
</div>
<div class="col-md-6">
<label class="form-label-chic">Statut</label>
<select class="form-select form-control-chic" name="statut" id="statut">
<option value="Actif">Actif</option>
<option value="Inactif">Inactif</option>
</select>
</div>
<div class="col-12">
<label class="form-label-chic">Boutique</label>
<select class="form-select form-control-chic selectpicker" name="boutique_id" id="boutique_id" data-live-search="true">
<option value="">Aucune</option>
<?php foreach ($boutiques as $b): ?>
<option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
<?php endforeach; ?>
</select>
</div>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn-chic btn-chic-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i><span>Annuler</span></button>
<button type="submit" name="btn_enregistrer" class="btn-chic btn-chic-primary"><i class="bi bi-check-lg"></i><span>Enregistrer</span></button>
</div>
</form>
</div>
</div>
</div>

<!-- Modal Suppression -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-sm">
<div class="modal-content" style="border-radius:16px;border:none;">
<div class="modal-body text-center p-4">
<div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
<h5 class="mb-2 fw-bold">Confirmer la suppression</h5>
<p class="text-muted small mb-4">Êtes-vous sûr de vouloir supprimer la caisse <strong id="deleteNom" class="text-danger"></strong> ?<br>Cette action est irréversible.</p>
<div class="d-flex gap-2 justify-content-center">
<button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
<button type="button" class="btn btn-danger rounded-3" id="confirmDeleteBtn"><i class="bi bi-trash3 me-1"></i> Supprimer</button>
</div>
</div>
</div>
</div>
</div>

<!-- Modal Détails -->
<div class="modal fade modal-chic" id="detailModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="bi bi-cash-register"></i><span>Détails de la caisse</span></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" id="detailContent">
</div>
<div class="modal-footer">
<button type="button" class="btn-chic btn-chic-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i><span>Fermer</span></button>
</div>
</div>
</div>
</div>

<form id="deleteForm" method="POST" style="display:none;">
<input type="hidden" name="btn_supprimer" value="1">
<input type="hidden" name="sai_supprimer_id" id="deleteFormId">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>
<form method="post" id="editLoadForm" style="display:none;">
<input type="hidden" name="action" value="load_edit">
<input type="hidden" name="edit_id" id="editIdField">
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
$(function(){
$('.selectpicker').selectpicker();
const modal = new bootstrap.Modal(document.getElementById('caisseModal'));
const delModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
const detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
const toastEl = document.getElementById('toastMsg');
const toast = new bootstrap.Toast(toastEl, { delay: 2500 });

function showToast(msg, type = 'success') {
const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-primary', warning: 'bg-warning' };
const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill', warning: 'bi-exclamation-circle-fill' };
$('#toastBody').html(`<i class="bi ${icons[type]} me-2"></i>${msg}`);
toastEl.className = `toast align-items-center text-white border-0 ${colors[type]}`;
toast.show();
}

$('#addBtn').on('click', function(){
$('#formAction').val('add');
$('#oldCaisseId').val('');
$('#modalTitle').html('<i class="bi bi-cash-register"></i><span>Nouvelle caisse</span>');
$('#code_caisse').val('<?= e($newCode) ?>');
$('#nom_caisse').val('');
$('#solde').val('0');
$('#statut').val('Actif');
$('#boutique_id').selectpicker('val', '');
modal.show();
});

$(document).on('click', '.voir-caisse', function(e){
e.stopPropagation();
const id = $(this).data('id');
const card = $(this).closest('.caisse-card');
$('#detailContent').html(`
<div class="detail-section-chic">
<div class="detail-section-title-chic info"><i class="bi bi-info-circle-fill"></i> INFORMATIONS</div>
<div class="row g-3">
<div class="col-md-6"><div class="form-label-chic">CODE</div><div class="fw-bold" style="font-size:15px;">${id}</div></div>
<div class="col-md-6"><div class="form-label-chic">NOM</div><div class="fw-semibold">${card.find('.cc-nom span').text()}</div></div>
<div class="col-md-6"><div class="form-label-chic">BOUTIQUE</div><div class="fw-semibold">${card.find('.cc-boutique').text().trim()}</div></div>
<div class="col-md-6"><div class="form-label-chic">STATUT</div><div class="fw-semibold">${card.find('.badge-pill').text().trim()}</div></div>
</div>
</div>
<div class="detail-section-chic">
<div class="detail-section-title-chic money"><i class="bi bi-cash-stack"></i> SOLDE</div>
<div class="text-center">
<div style="font-size:32px;font-weight:800;color:#10b981;font-family:'Outfit',sans-serif;">${card.find('.cc-solde').clone().children().remove().end().text().trim()} <small style="font-size:14px;color:#64748b;">FCFA</small></div>
</div>
</div>
`);
detailModal.show();
});

$(document).on('click', '.editBtn', function(e){
e.stopPropagation();
const id = $(this).data('id');
$('#editIdField').val(id);
$('#editLoadForm').submit();
});

$(document).on('click', '.deleteBtn', function(e){
e.stopPropagation();
const id = $(this).data('id');
const nom = $(this).data('nom');
$('#deleteFormId').val(id);
$('#deleteNom').text(nom);
delModal.show();
});

$('#confirmDeleteBtn').on('click', function(){
const id = $('#deleteFormId').val();
const cardItem = $('.caisse-item[data-id="' + id + '"]');
const card = cardItem.find('.caisse-card');
card.addClass('deleting');
setTimeout(() => { $('#deleteForm').submit(); }, 400);
});

function rechercher(page){
var formData = 'ajax=1&search=' + encodeURIComponent($('#searchInput').val())
+ '&boutique_filter=' + encodeURIComponent($('#boutiqueFilter').val()) + '&page=' + page;
$.ajax({
url: window.location.href,
method: 'POST',
data: formData,
dataType: 'json',
success: function(data){
$('#caissesGrid').html(data.table);
$('#paginationContainer').html(data.pagination);
if (data.total !== undefined) {
$('#totalCount').text(data.total + ' caisse(s) — Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
}
},
error: function(xhr){ console.error(xhr.responseText); showToast('Erreur AJAX', 'error'); }
});
}

var searchTimeout = null;
$('#searchInput').on('input', function(){
clearTimeout(searchTimeout);
searchTimeout = setTimeout(function(){ rechercher(1); }, 300);
});
$('#boutiqueFilter').on('changed.bs.select', function(){
clearTimeout(searchTimeout);
searchTimeout = setTimeout(function(){ rechercher(1); }, 300);
});
$('#filterBtn').on('click', function(){ rechercher(1); showToast('Filtres appliqués', 'info'); });
$('#resetBtn').on('click', function(){
$('#searchInput').val('');
$('#boutiqueFilter').selectpicker('val', '');
rechercher(1);
showToast('Filtres réinitialisés', 'info');
});
$(document).on('click', '.page-link', function(e){
e.preventDefault();
rechercher($(this).data('page'));
});

setTimeout(()=>$('.alert').alert('close'), 5000);

<?php if ($editCaisse): ?>
$(function(){
$('#formAction').val('edit');
$('#oldCaisseId').val('<?= e($editCaisse['caisse_id']) ?>');
$('#modalTitle').html('<i class="bi bi-pencil"></i><span>Modifier la caisse</span>');
$('#code_caisse').val('<?= e($editCaisse['caisse_id']) ?>');
$('#nom_caisse').val('<?= e($editCaisse['nom_caisse']) ?>');
$('#solde').val('<?= e($editCaisse['solde']) ?>');
$('#boutique_id').selectpicker('val', '<?= e($editCaisse['boutique_id']) ?>');
$('#statut').val('<?= e($editCaisse['statut']) ?>');
modal.show();
});
<?php endif; ?>
});
</script>
</body>
</html>