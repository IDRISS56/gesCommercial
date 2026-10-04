<?php
// views/produit/tranches.php – Configuration des tranches de prix de vente par quantité.
// On peut définir des tranches pour un produit SANS les activer : tant que
// « Appliquer les tranches » n'est pas coché, seul produit.prix_produit compte.
// Les mêmes tranches valent pour toutes les boutiques ; quantités en unités de base.
require 'databases/database.php';
require_once 'includes/prix_tranche.php';

if (!function_exists('e')) {
    function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt')) {
    function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }
}

$stmtUser = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmtUser->execute([$_SESSION['user_id'] ?? '']);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$action = $_POST['action'] ?? '';

// ============================================================
// AJAX : enregistrer les tranches d'un produit (+ activation)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['sauver', 'basculer'], true)) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    try {
        if (($_POST['csrf_token'] ?? '') !== $csrf_token) throw new Exception('Token de sécurité invalide.');
        $codeProduit = trim($_POST['produit_id'] ?? '');
        $stmtP = $pdo->prepare("SELECT code_produit, titre_produit, prix_produit, prix_fournisseur FROM produit WHERE code_produit = ?");
        $stmtP->execute([$codeProduit]);
        $produit = $stmtP->fetch(PDO::FETCH_ASSOC);
        $libellesOk = libellesTranche($pdo);
        if (!$produit) throw new Exception('Produit introuvable.');
        $prixBase = floatval($produit['prix_produit']);
        $prixAchat = floatval($produit['prix_fournisseur']);

        if ($action === 'sauver') {
            $brut = json_decode($_POST['tranches'] ?? '[]', true);
            if (!is_array($brut)) throw new Exception('Tranches invalides.');
            $v = validerTranches($brut, $prixBase, $prixAchat, $libellesOk);
            if (!$v['ok']) throw new Exception($v['erreur']);
            $tranches = $v['lignes'];
            $active = (($_POST['active'] ?? '0') === '1') ? 1 : 0;
        } else { // basculer : on garde les tranches enregistrées, on change seulement l'état
            $stmtT = $pdo->prepare("SELECT quantite_min, prix_unitaire, libelle_tranche FROM prix_tranche WHERE produit_id = ? ORDER BY quantite_min");
            $stmtT->execute([$codeProduit]);
            $tranches = $stmtT->fetchAll(PDO::FETCH_ASSOC);
            $active = (($_POST['active'] ?? '0') === '1') ? 1 : 0;
            if ($active) {
                // Les prix ont pu changer depuis l'enregistrement : on revalide avant d'activer.
                $v = validerTranches($tranches, $prixBase, $prixAchat, $libellesOk);
                if (!$v['ok']) throw new Exception($v['erreur']);
            }
        }

        if ($active) {
            if (empty($tranches)) throw new Exception("Ajoutez au moins une tranche avant d'activer.");
            if ($prixBase <= 0 && prixDetailDefini($tranches) === null) throw new Exception("Ce produit n'a ni prix de vente ni prix détail : définissez l'un des deux avant d'activer les tranches.");
        }

        $pdo->beginTransaction();
        if ($action === 'sauver') {
            $pdo->prepare("DELETE FROM prix_tranche WHERE produit_id = ?")->execute([$codeProduit]);
            $ins = $pdo->prepare("INSERT INTO prix_tranche (produit_id, quantite_min, prix_unitaire, libelle_tranche) VALUES (?, ?, ?, ?)");
            foreach ($tranches as $t) $ins->execute([$codeProduit, $t['quantite_min'], $t['prix_unitaire'], $t['libelle_tranche']]);
        }
        $pdo->prepare("UPDATE produit SET tranche_active = ? WHERE code_produit = ?")->execute([$active, $codeProduit]);
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => $active
            ? 'Tranches enregistrées et appliquées aux ventes.'
            : 'Tranches enregistrées (non appliquées : seul le prix de vente du produit compte).']);
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
    }
    exit;
}

// ============================================================
// LISTE (recherche + filtre catégorie + pagination)
// ============================================================
// Filtres envoyés en POST : les règles du .htaccess (sans drapeau QSA) suppriment la chaîne de
// requête d'une URL du type /produit/tranches?q=..., donc un formulaire GET n'arriverait jamais ici.
$src       = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
$q         = trim($src['q'] ?? '');
$fCat      = trim($src['categorie'] ?? '');
$fEtat     = trim($src['etat'] ?? '');   // '' | 'actif' | 'defini' (avec tranches)
$page      = max(1, intval($src['page'] ?? 1));
$parPage   = 20;
$erreurMigration = '';
$produits = []; $total = 0; $tranchesParProduit = [];
$libellesTranche = libellesTranche($pdo);
$stTotal = $stActifs = $stDefinis = 0;

$categories = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie WHERE etat_categorie='ACTIF' ORDER BY titre_categorie")->fetchAll(PDO::FETCH_ASSOC);

try {
    $where = " WHERE 1=1"; $params = [];
    if ($q !== '')     { $where .= " AND (p.titre_produit LIKE ? OR p.code_produit LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
    if ($fCat !== '')  { $where .= " AND p.categorie_id = ?"; $params[] = $fCat; }
    if ($fEtat === 'actif')  $where .= " AND p.tranche_active = 1";
    if ($fEtat === 'defini') $where .= " AND EXISTS (SELECT 1 FROM prix_tranche t WHERE t.produit_id = p.code_produit)";

    // Statistiques globales (indépendantes des filtres)
    $stTotal   = (int) $pdo->query("SELECT COUNT(*) FROM produit")->fetchColumn();
    $stActifs  = (int) $pdo->query("SELECT COUNT(*) FROM produit WHERE tranche_active = 1")->fetchColumn();
    $stDefinis = (int) $pdo->query("SELECT COUNT(DISTINCT produit_id) FROM prix_tranche")->fetchColumn();

    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM produit p" . $where);
    $stmtC->execute($params);
    $total = (int) $stmtC->fetchColumn();
    $pages = max(1, (int) ceil($total / $parPage));
    $page = min($page, $pages);

    $stmtL = $pdo->prepare("SELECT p.code_produit, p.titre_produit, p.prix_produit, p.prix_fournisseur, p.tranche_active,
                                   COALESCE(c.titre_categorie, 'Autre') AS categorie
                            FROM produit p LEFT JOIN categorie c ON c.code_categorie = p.categorie_id"
                            . $where . " ORDER BY p.tranche_active DESC, p.titre_produit ASC LIMIT $parPage OFFSET " . (($page - 1) * $parPage));
    $stmtL->execute($params);
    $produits = $stmtL->fetchAll(PDO::FETCH_ASSOC);

    if ($produits) {
        $codes = array_column($produits, 'code_produit');
        $in = implode(',', array_fill(0, count($codes), '?'));
        $stmtT = $pdo->prepare("SELECT produit_id, quantite_min, prix_unitaire, libelle_tranche FROM prix_tranche WHERE produit_id IN ($in) ORDER BY quantite_min");
        $stmtT->execute($codes);
        foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $tranchesParProduit[$t['produit_id']][] = ['quantite_min' => (int) $t['quantite_min'], 'prix_unitaire' => (float) $t['prix_unitaire'], 'libelle_tranche' => (string) $t['libelle_tranche']];
        }
    }
} catch (Exception $ex) {
    $erreurMigration = "Les tranches de prix ne sont pas encore installées : exécutez d'abord databases/migration_tranches_prix.sql. (" . $ex->getMessage() . ")";
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tranches de prix</title>
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
    --color-success-soft: #ecfdf5;
    --color-warning: #f59e0b;
    --color-warning-soft: #fffbeb;
    --color-danger: #ef4444;
    --color-danger-soft: #fef2f2;
    --color-info: #3b82f6;
    --color-info-soft: #eff6ff;
    --color-purple: #8b5cf6;
    --color-purple-soft: #f5f3ff;
    --color-gray-100: #f1f5f9;
    --color-gray-200: #e2e8f0;
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-tertiary: #64748b;
    --bg-surface: #ffffff;
    --bg-page: #f8fafc;
    --border-color: #e2e8f0;
    --radius-sm: 10px;
    --radius-md: 14px;
    --radius-lg: 20px;
}
* { box-sizing: border-box; }
body {
    font-family: 'Inter', -apple-system, sans-serif;
    background: var(--bg-page);
    color: var(--text-primary);
    min-height: 100vh;
    padding: 28px 20px;
}
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
.W { max-width: 1400px; margin: 0 auto; }

.stat-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px; transition: all .2s; }
.stat-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.06); border-color: #cbd5e1; }
.stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .5px; }
.stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; }

.data-table-wrap { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.table { margin: 0; font-size: .88rem; }
.table thead th { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; padding: 14px; border-bottom: 2px solid var(--border-color); }
.table tbody td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.table tbody tr { transition: background .2s; }
.table tbody tr:hover { background: var(--color-primary-soft); }
.td-bold { font-weight: 700; color: var(--text-primary); }

.badge-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.bp-success { background: var(--color-success-soft); color: var(--color-success); }
.bp-muted   { background: var(--color-gray-100); color: var(--text-tertiary); }
.bp-primary { background: var(--color-primary-soft); color: var(--color-primary); }
.bp-purple  { background: var(--color-purple-soft); color: var(--color-purple); }

.icon-btn { width: 32px; height: 32px; border-radius: 6px; border: 1.5px solid transparent; background: transparent; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all .15s; position: relative; }
.icon-btn.edit { color: var(--color-primary); border-color: rgba(79,70,229,.2); }
.icon-btn.edit:hover { background: var(--color-primary-soft); border-color: var(--color-primary); }
.icon-btn.on { color: var(--color-success); border-color: rgba(16,185,129,.25); }
.icon-btn.on:hover { background: var(--color-success-soft); border-color: var(--color-success); }
.icon-btn.off { color: var(--color-danger); border-color: rgba(239,68,68,.2); }
.icon-btn.off:hover { background: var(--color-danger-soft); border-color: var(--color-danger); }
.icon-btn::before { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--text-primary); color: #fff; padding: 4px 8px; border-radius: 5px; font-size: 10px; white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity .15s; z-index: 5; }
.icon-btn:hover::before { opacity: 1; }

.btn-go { background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); color: #fff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .2s; box-shadow: 0 4px 12px rgba(79,70,229,.25); }
.btn-go:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79,70,229,.4); color: #fff; }
.btn-go:disabled { opacity: .6; transform: none; cursor: not-allowed; }
.btn-go-outline { background: #fff; color: var(--text-secondary); border: 1px solid var(--border-color); padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .2s; text-decoration: none; }
.btn-go-outline:hover { background: var(--color-gray-100); color: var(--text-primary); }

.pbar { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px 22px; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.prow { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
.prow label { font-size: 10px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: 4px; }
.prow input, .prow select { border: 1px solid var(--border-color); border-radius: 8px; padding: 9px 12px; font-size: 13px; background: #fff; transition: all .15s; }
.prow input:focus, .prow select:focus { outline: none; border-color: var(--color-primary); box-shadow: 0 0 0 3px var(--color-primary-soft); }

.modal-chic .modal-content { border: none; border-radius: var(--radius-lg); box-shadow: 0 25px 60px rgba(15,23,42,.15); overflow: hidden; display: flex !important; flex-direction: column !important; max-height: 90vh !important; }
.modal-chic .modal-header { background: #334155; color: #fff; padding: 20px 28px; border: none; flex-shrink: 0 !important; }
.modal-chic .modal-title { font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 18px; display: flex; align-items: center; gap: 10px; }
.modal-chic .modal-body { padding: 28px !important; overflow-y: auto !important; background: #f8fafc !important; flex: 1 1 auto !important; min-height: 0 !important; }
.modal-chic .modal-footer { background: #ffffff !important; border-top: 2px solid var(--border-color) !important; padding: 18px 28px !important; display: flex !important; gap: 10px !important; justify-content: flex-end !important; }
.section-title { font-size: 11px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .8px; padding-bottom: 10px; border-bottom: 2px solid #f1f5f9; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.section-title.price i { color: var(--color-success); }
.section-title.tr i { color: var(--color-primary); }
.form-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
.form-control, .form-select { border: 1px solid var(--border-color); border-radius: 8px; padding: 9px 12px; font-size: 13px; }
.form-control:focus, .form-select:focus { border-color: var(--color-primary); box-shadow: 0 0 0 3px var(--color-primary-soft); }
.recap { display: flex; gap: 12px; flex-wrap: wrap; }
.recap div { background: #fff; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; font-size: 12px; color: var(--text-tertiary); }
.recap strong { display: block; font-size: 16px; color: var(--text-primary); font-family: 'Outfit', sans-serif; }
#mLignes select, #mLignes input { min-width: 90px; }

.toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
.page-link { border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 12px; font-weight: 600; padding: 6px 12px; }
.page-item.active .page-link { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }

.hdr-badge-chic { background: #e0e7ff; border: 1.5px solid #a5b4fc; color: #3730a3; padding: 10px 18px; border-radius: 999px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; font-family: 'Outfit', sans-serif; box-shadow: 0 2px 6px rgba(99,102,241,.15); }
.hdr-badge-chic i { font-size: 15px; color: #4f46e5; }

@keyframes fadeUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.table tbody tr { animation: fadeUp .3s ease both; }
@media (max-width: 700px) { .bootstrap-select, .bootstrap-select .dropdown-toggle { width: 100% !important; min-width: 0 !important; } }
</style>
</head>
<body>
<div class="W">

    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1" style="font-family:'Outfit',sans-serif;">
                <i class="bi bi-graph-down-arrow text-primary me-2"></i>Tranches de prix
            </h1>
            <p class="text-muted small mb-0">Prix de vente dégressif selon la quantité (ex. : dès 10 unités 4 500 F, dès 100 unités 4 000 F au lieu de 5 000 F)</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge-chic">
                <i class="bi bi-box-seam"></i>
                <?= number_format($total, 0, ',', ' ') ?> produit(s)
            </div>
        </div>
    </div>

    <?php if ($erreurMigration): ?>
        <div class="alert alert-danger"><?= e($erreurMigration) ?></div>
    <?php else: ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['success', 'check-circle-fill',  'Tranches appliquées', number_format($stActifs, 0, ',', ' '), 'produits'],
            ['info',    'layers-fill',        'Tranches définies',   number_format($stDefinis, 0, ',', ' '), 'produits'],
            ['warning', 'pause-circle-fill',  'Définies, non appliquées', number_format(max(0, $stDefinis - $stActifs), 0, ',', ' '), 'produits'],
            ['primary', 'box-seam',           'Catalogue',           number_format($stTotal, 0, ',', ' '), 'produits'],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
            'info'    => ['var(--color-info-soft)',    'var(--color-info)'],
        ];
        foreach ($stats as $st):
            $bg = $colorMap[$st[0]][0]; $fg = $colorMap[$st[0]][1];
        ?>
        <div class="col-6 col-md-3">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $fg ?>;"><i class="bi bi-<?= $st[1] ?>"></i></div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-label"><?= $st[2] ?></div>
                    <div class="stat-value text-truncate"><?= $st[3] ?> <small class="text-muted ms-1" style="font-size:11px;"><?= $st[4] ?></small></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="alert alert-info py-2 small">
        <i class="bi bi-info-circle"></i> Vous pouvez définir des tranches sans les appliquer : tant que <strong>« Appliquer »</strong> n'est pas activé pour un produit, seul son prix de vente normal est utilisé. Les quantités sont comptées en <strong>unités de base</strong> et les mêmes tranches valent pour toutes les boutiques.
    </div>

    <!-- Filtres -->
    <div class="pbar">
        <form method="post" id="searchForm">
            <input type="hidden" name="page" id="pageInput" value="<?= (int) $page ?>">
            <div class="prow">
                <div style="flex:1;min-width:180px;">
                    <label><i class="bi bi-search"></i> Recherche</label>
                    <input type="text" name="q" placeholder="Nom ou code du produit..." value="<?= e($q) ?>" style="width:100%;">
                </div>
                <div style="min-width:180px;">
                    <label><i class="bi bi-tag"></i> Catégorie</label>
                    <select name="categorie" class="selectpicker" data-live-search="true" data-style="btn-sm" data-container="body">
                        <option value="">Toutes</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= e($c['code_categorie']) ?>" <?= $fCat === $c['code_categorie'] ? 'selected' : '' ?>><?= e($c['titre_categorie']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="min-width:190px;">
                    <label><i class="bi bi-funnel"></i> Afficher</label>
                    <select name="etat" class="form-select form-select-sm">
                        <option value="">Tous les produits</option>
                        <option value="actif" <?= $fEtat === 'actif' ? 'selected' : '' ?>>Tranches appliquées</option>
                        <option value="defini" <?= $fEtat === 'defini' ? 'selected' : '' ?>>Avec des tranches définies</option>
                    </select>
                </div>
                <button type="submit" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <!-- Tableau -->
    <div class="data-table-wrap">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des produits</h5>
            <span class="text-muted small"><?= (int) $total ?> produit(s) - Page <?= (int) $page ?> / <?= (int) $pages ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Catégorie</th>
                        <th class="text-end">Prix d'achat</th>
                        <th class="text-end">Prix de vente</th>
                        <th>Tranches</th>
                        <th>État</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($produits)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Aucun produit.</td></tr>
                <?php else: foreach ($produits as $p):
                    $tr = $tranchesParProduit[$p['code_produit']] ?? [];
                    $actif = intval($p['tranche_active']) === 1; ?>
                    <tr>
                        <td><span class="td-bold"><?= e($p['titre_produit']) ?></span><small class="d-block text-muted"><?= e($p['code_produit']) ?></small></td>
                        <td><?= e($p['categorie']) ?></td>
                        <td class="text-end"><?= fmt($p['prix_fournisseur']) ?></td>
                        <td class="text-end td-bold"><?= fmt($p['prix_produit']) ?>
                            <?php $pd = prixDetailDefini($tr); if ($pd !== null): ?><small class="d-block text-muted fw-normal">Détail : <?= fmt($pd) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if (empty($tr)): ?><span class="text-muted">—</span>
                            <?php else: foreach ($tr as $t): ?>
                                <div class="small"><span class="badge-pill <?= $t['libelle_tranche'] === 'Gros' ? 'bp-purple' : 'bp-primary' ?>"><?= e($t['libelle_tranche'] === LIBELLE_DETAIL ? 'Détail' : $t['libelle_tranche']) ?></span>
                                    <?php if ($t['libelle_tranche'] === LIBELLE_DETAIL): ?>prix unitaire <strong><?= fmt($t['prix_unitaire']) ?></strong>
                                    <?php else: ?>dès <strong><?= (int) $t['quantite_min'] ?></strong> → <strong><?= fmt($t['prix_unitaire']) ?></strong><?php endif; ?></div>
                            <?php endforeach; endif; ?>
                        </td>
                        <td>
                            <span class="badge-pill <?= $actif ? 'bp-success' : 'bp-muted' ?>">
                                <i class="bi bi-<?= $actif ? 'check-circle-fill' : 'pause-circle' ?>"></i> <?= $actif ? 'Appliquées' : 'Non appliquées' ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="icon-btn edit" data-tooltip="Configurer les tranches"
                                    data-code="<?= e($p['code_produit']) ?>" data-titre="<?= e($p['titre_produit']) ?>"
                                    data-base="<?= e($p['prix_produit']) ?>" data-achat="<?= e($p['prix_fournisseur']) ?>"
                                    data-actif="<?= $actif ? 1 : 0 ?>" data-tranches="<?= e(json_encode($tr)) ?>"
                                    onclick="ouvrir(this)"><i class="bi bi-pencil"></i></button>
                            <?php if (!empty($tr)): ?>
                            <button type="button" class="icon-btn <?= $actif ? 'off' : 'on' ?>" data-tooltip="<?= $actif ? 'Désactiver les tranches' : 'Appliquer les tranches' ?>"
                                    onclick="basculer('<?= e($p['code_produit']) ?>', <?= $actif ? 0 : 1 ?>)"><i class="bi bi-<?= $actif ? 'pause-circle' : 'play-circle' ?>"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
        <div class="p-3">
            <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="#" onclick="allerPage(<?= $page - 1 ?>); return false;"><i class="bi bi-chevron-left"></i></a>
                </li>
                <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                    <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                        <a class="page-link" href="#" onclick="allerPage(<?= $i ?>); return false;"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= ($page >= $pages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="#" onclick="allerPage(<?= $page + 1 ?>); return false;"><i class="bi bi-chevron-right"></i></a>
                </li>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ========================================== -->
<!-- MODAL CONFIGURATION DES TRANCHES -->
<!-- ========================================== -->
<div class="modal fade modal-chic" id="modalTranches" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-graph-down-arrow"></i><span id="mTitre"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="section-title price"><i class="bi bi-cash-stack"></i> Prix du produit</div>
                <div class="recap mb-4">
                    <div>Prix d'achat<strong><span id="mAchat"></span> F</strong></div>
                    <div>Prix de vente normal<strong><span id="mBase"></span> F</strong></div>
                </div>

                <div class="section-title tr"><i class="bi bi-layers"></i> Tranches (quantités en unités de base)</div>
                <div class="text-muted small mb-2"><strong>Détail</strong> (facultatif) : prix pour les petites quantités (dès 1 unité). S'il n'est pas défini, le <strong>prix de vente normal</strong> du produit est utilisé. Les autres tranches doivent être moins chères que le prix détail (ou que le prix de vente normal s'il n'y a pas de prix détail).</div>
                <table class="table table-sm align-middle mb-2">
                    <thead><tr><th>Libellé</th><th>À partir de (unités)</th><th>Prix unitaire (F)</th><th></th></tr></thead>
                    <tbody id="mLignes"></tbody>
                </table>
                <button type="button" class="btn-go-outline mb-4" onclick="ajouterLigne()"><i class="bi bi-plus-lg"></i> Ajouter une tranche</button>

                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="mActive">
                    <label class="form-check-label fw-semibold" for="mActive">Appliquer ces tranches aux ventes</label>
                </div>
                <div class="text-muted small">Décoché : les tranches sont enregistrées mais ignorées, seul le prix de vente normal est utilisé.</div>
                <div class="alert alert-danger py-2 small mt-3 d-none" id="mErreur"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-go-outline" data-bs-dismiss="modal"><i class="bi bi-x"></i> Fermer</button>
                <button type="button" class="btn-go" id="mSauver"><i class="bi bi-save"></i> Enregistrer</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast-container">
    <div id="liveToast" class="toast align-items-center text-white border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script>
const CSRF = <?= json_encode($csrf_token) ?>;
const PAGE_URL = window.location.href;
const LIBELLES = <?= json_encode($libellesTranche, JSON_UNESCAPED_UNICODE) ?>; // valeurs de l'ENUM libelle_tranche
let codeCourant = null;

// ----- Filtres (POST) -----
const formFiltre = document.getElementById('searchForm');
if (formFiltre) {
    // Un nouveau filtre repart toujours de la page 1
    formFiltre.addEventListener('submit', () => { document.getElementById('pageInput').value = 1; });
    document.getElementById('resetBtn').addEventListener('click', () => {
        formFiltre.querySelector('[name=q]').value = '';
        formFiltre.querySelector('[name=etat]').value = '';
        const cat = formFiltre.querySelector('[name=categorie]');
        cat.value = '';
        if (window.jQuery && jQuery.fn.selectpicker) jQuery(cat).selectpicker('val', '');
        document.getElementById('pageInput').value = 1;
        formFiltre.submit();
    });
}
function allerPage(n) { document.getElementById('pageInput').value = n; formFiltre.submit(); }
// Recharge la liste avec les filtres et la page en cours (évite l'alerte « renvoyer le formulaire »)
function recharger() { if (formFiltre) formFiltre.submit(); else window.location.reload(); }

function toast(message, ok) {
    const el = document.getElementById('liveToast');
    el.classList.remove('bg-success', 'bg-danger');
    el.classList.add(ok ? 'bg-success' : 'bg-danger');
    document.getElementById('toastBody').textContent = message;
    bootstrap.Toast.getOrCreateInstance(el, { delay: 2500 }).show();
}

async function post(params) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    Object.entries(params).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch(PAGE_URL, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
    return r.json();
}

const DETAIL = 'Detail';
let baseCourante = 0; // prix de vente normal du produit en cours d'édition
function libelleAffiche(l) { return l === DETAIL ? 'Détail' : l; }

// Le prix détail vaut toujours « dès 1 unité » : quantité figée à 1, prix vide = prix de vente normal
function majLigneDetail(tr) {
    const estDetail = tr.querySelector('.t-l').value === DETAIL;
    const q = tr.querySelector('.t-q'), p = tr.querySelector('.t-p');
    if (estDetail) {
        q.value = 1; q.readOnly = true; q.min = 1;
        p.placeholder = 'Vide = ' + Math.round(baseCourante).toLocaleString('fr-FR');
    } else {
        q.readOnly = false; q.min = 2;
        if (q.value === '1') q.value = '';
        p.placeholder = '';
    }
}

function ajouterLigne(q, p, lib) {
    const tbody = document.getElementById('mLignes');
    if (lib === undefined) { // nouvelle ligne : premier libellé pas encore utilisé
        const pris = [...tbody.querySelectorAll('.t-l')].map(s => s.value);
        lib = LIBELLES.find(l => !pris.includes(l)) || LIBELLES[0];
    }
    const tr = document.createElement('tr');
    const options = LIBELLES.map(l => '<option value="' + l.replace(/"/g, '&quot;') + '"' + (l === lib ? ' selected' : '') + '>' + libelleAffiche(l).replace(/</g, '&lt;') + '</option>').join('');
    tr.innerHTML = '<td><select class="form-select form-select-sm t-l">' + options + '</select></td>' +
                   '<td><input type="number" min="2" step="1" class="form-control form-control-sm t-q" value="' + (q ?? '') + '"></td>' +
                   '<td><input type="number" min="0" step="0.01" class="form-control form-control-sm t-p" value="' + (p ?? '') + '"></td>' +
                   '<td><button type="button" class="icon-btn off" data-tooltip="Retirer" onclick="this.closest(\'tr\').remove()"><i class="bi bi-trash"></i></button></td>';
    tbody.appendChild(tr);
    tr.querySelector('.t-l').addEventListener('change', () => majLigneDetail(tr));
    majLigneDetail(tr);
}

function ouvrir(btn) {
    codeCourant = btn.dataset.code;
    document.getElementById('mTitre').textContent = btn.dataset.titre;
    document.getElementById('mAchat').textContent = Math.round(parseFloat(btn.dataset.achat) || 0).toLocaleString('fr-FR');
    document.getElementById('mBase').textContent = Math.round(parseFloat(btn.dataset.base) || 0).toLocaleString('fr-FR');
    baseCourante = parseFloat(btn.dataset.base) || 0;
    document.getElementById('mActive').checked = btn.dataset.actif === '1';
    document.getElementById('mErreur').classList.add('d-none');
    document.getElementById('mLignes').innerHTML = '';
    let tr = [];
    try { tr = JSON.parse(btn.dataset.tranches || '[]'); } catch (e) {}
    if (tr.length) tr.forEach(t => ajouterLigne(t.quantite_min, t.prix_unitaire, t.libelle_tranche)); else ajouterLigne();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTranches')).show();
}

document.getElementById('mSauver').addEventListener('click', async function () {
    const err = document.getElementById('mErreur');
    const lignes = [...document.querySelectorAll('#mLignes tr')].map(tr => ({
        libelle_tranche: tr.querySelector('.t-l').value,
        quantite_min: tr.querySelector('.t-q').value.trim(),
        prix_unitaire: tr.querySelector('.t-p').value.trim()
    }));
    this.disabled = true;
    try {
        const j = await post({ action: 'sauver', produit_id: codeCourant, tranches: JSON.stringify(lignes), active: document.getElementById('mActive').checked ? 1 : 0 });
        if (j.success) { toast(j.message, true); setTimeout(recharger, 900); }
        else { err.textContent = j.message; err.classList.remove('d-none'); }
    } catch (e) { err.textContent = 'Erreur de communication avec le serveur.'; err.classList.remove('d-none'); }
    this.disabled = false;
});

async function basculer(code, active) {
    try {
        const j = await post({ action: 'basculer', produit_id: code, active });
        if (j.success) { toast(j.message, true); setTimeout(recharger, 900); }
        else toast(j.message, false);
    } catch (e) { toast('Erreur de communication avec le serveur.', false); }
}
</script>
</body>
</html>