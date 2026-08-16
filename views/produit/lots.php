<?php
// lots.php – Configuration des lots (table `lot`)
// Règle métier : on choisit d'abord une CATÉGORIE, ce qui filtre la liste des
// PRODUITS, avant de configurer un lot (même principe que entree_stock.php).
// Structure (modal, recherche/pagination AJAX, toasts) alignée sur index.php (boutiques).
ob_start();
require 'databases/database.php';

// ==========================================
// SÉCURITÉ : utilisateur connecté & actif
// ==========================================
if (!isset($_SESSION['user_id'])) {
    header('Location: utilisateur/login');
    exit;
}
$stmtUser = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmtUser->execute([$_SESSION['user_id']]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: utilisateur/login');
    exit;
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function generateLotId($pdo) {
    do {
        $code = 'LOT-' . date('Ymd') . '-' . str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT 1 FROM lot WHERE code_lot = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());
    return $code;
}

// Types de lot autorisés (doit rester synchronisé avec l'ENUM de la table `lot`)
$libellesLot = ['Unité', 'Boîte', 'Carton', 'Bidon', 'Palette'];

// - Catégories actives -
$categories = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie WHERE etat_categorie='ACTIF' ORDER BY titre_categorie")->fetchAll(PDO::FETCH_ASSOC);

// - Produits (avec leur catégorie, pour le filtrage JS) -
$produits = $pdo->query("SELECT code_produit, titre_produit, categorie_id, etat_produit
    FROM produit
    ORDER BY CASE WHEN etat_produit = 'Inactif' THEN 1 ELSE 0 END, titre_produit")->fetchAll(PDO::FETCH_ASSOC);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ==========================================
// LISTE DES LOTS (recherche + pagination), même principe que getTableContent() de index.php
// ==========================================
function getLotsTableContent($pdo, $search, $filtres, $page, $perPage = 20) {
    $sql = "SELECT l.code_lot, l.libelle, l.unites_par_lot, l.prix_lot, l.cout_lot, l.quantite, l.etat_lot,
                   p.code_produit, p.titre_produit, p.prix_produit, p.prix_fournisseur, COALESCE(c.titre_categorie,'Autre') AS titre_categorie
            FROM lot l
            LEFT JOIN produit p ON l.produit_id = p.code_produit
            LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
            WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (p.titre_produit LIKE ? OR c.titre_categorie LIKE ? OR l.libelle LIKE ? OR l.code_lot LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 4; $i++) $params[] = $like;
    }

    if (!empty($filtres['etat'])) {
        $sql .= " AND l.etat_lot = ?";
        $params[] = $filtres['etat'];
    }

    if (!empty($filtres['categorie'])) {
        $sql .= " AND p.categorie_id = ?";
        $params[] = $filtres['categorie'];
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM (" . $sql . ") AS t");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = (int)ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    if ($page < 1) $page = 1;

    $sql .= " ORDER BY FIELD(l.etat_lot,'Actif','Inactif'), p.titre_produit, l.libelle LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($lots)):
    ?>
    <tr><td colspan="9" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>Aucun lot trouvé</td></tr>
    <?php else: foreach ($lots as $l): ?>
    <tr>
        <td class="td-bold"><?= e($l['titre_produit'] ?? $l['code_produit']) ?></td>
        <td><?= e($l['titre_categorie']) ?></td>
        <td><?= e($l['libelle']) ?></td>
        <td><?= (int)$l['unites_par_lot'] ?></td>
        <td>
            <?php if ($l['prix_lot'] !== null): ?>
                <span class="fw-bold text-success"><?= number_format((float)$l['prix_lot'], 0, ',', ' ') ?> F</span>
            <?php else: ?>
                <span class="text-muted small">— (<?= number_format((float)$l['prix_produit'] * (int)$l['unites_par_lot'], 0, ',', ' ') ?> F au prix unitaire)</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($l['cout_lot'] !== null): ?>
                <span class="fw-bold text-primary"><?= number_format((float)$l['cout_lot'], 0, ',', ' ') ?> F</span>
            <?php else: ?>
                <span class="text-muted small">— (<?= number_format((float)$l['prix_fournisseur'] * (int)$l['unites_par_lot'], 0, ',', ' ') ?> F au prix fourn.)</span>
            <?php endif; ?>
        </td>
        <td><?= (int)$l['quantite'] ?></td>
        <td>
            <span class="status-badge <?= $l['etat_lot'] === 'Actif' ? 'on' : 'off' ?>">
                <span class="sdot"></span><?= e($l['etat_lot']) ?>
            </span>
        </td>
        <td class="text-end">
            <div class="d-inline-flex gap-1">
                <button type="button" class="act-btn e editLotBtn" data-code="<?= e($l['code_lot']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                <button type="button" class="act-btn d toggleLotBtn" data-code="<?= e($l['code_lot']) ?>" data-produit="<?= e($l['titre_produit'] ?? $l['code_produit']) ?>" title="Activer/Désactiver"><i class="bi bi-power"></i></button>
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

// ==========================================
// AJAX — recherche / pagination (liste)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1' && !isset($_POST['action'])) {
    $search = trim($_POST['search'] ?? '');
    $filtres = ['etat' => trim($_POST['etat'] ?? ''), 'categorie' => trim($_POST['categorie'] ?? '')];
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getLotsTableContent($pdo, $search, $filtres, $page);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}

// ==========================================
// AJAX — actions d'écriture / lecture ponctuelle
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    $token = $_POST['csrf_token'] ?? '';

    try {
        // ----- Lots existants pour un produit (affichés dès le choix du produit) -----
        if ($action === 'get_lots_produit') {
            $produitId = trim($_POST['produit_id'] ?? '');
            if ($produitId === '') { echo json_encode(['success' => false, 'message' => 'Produit manquant.']); exit; }
            $stmt = $pdo->prepare("SELECT code_lot, libelle, unites_par_lot, prix_lot, cout_lot, quantite, etat_lot FROM lot WHERE produit_id = ? ORDER BY FIELD(etat_lot,'Actif','Inactif'), libelle");
            $stmt->execute([$produitId]);
            $stmtStock = $pdo->prepare("SELECT stock_produit FROM produit WHERE code_produit = ?");
            $stmtStock->execute([$produitId]);
            $stockProduit = (int) $stmtStock->fetchColumn();
            echo json_encode(['success' => true, 'lots' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'stock_produit' => $stockProduit]);
            exit;
        }

        // ----- Détails d'un lot pour le modal d'édition -----
        if ($action === 'get_lot') {
            $codeLot = trim($_POST['code_lot'] ?? '');
            if ($codeLot === '') { echo json_encode(['success' => false, 'message' => 'Lot manquant.']); exit; }
            $stmt = $pdo->prepare("SELECT l.code_lot, l.libelle, l.unites_par_lot, l.prix_lot, l.cout_lot, l.quantite, l.etat_lot,
                                           p.titre_produit, p.stock_produit, p.prix_produit, p.prix_fournisseur, COALESCE(c.titre_categorie,'Autre') AS titre_categorie
                                    FROM lot l
                                    LEFT JOIN produit p ON l.produit_id = p.code_produit
                                    LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
                                    WHERE l.code_lot = ?");
            $stmt->execute([$codeLot]);
            $lot = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lot) { echo json_encode(['success' => false, 'message' => 'Lot introuvable.']); exit; }
            echo json_encode(['success' => true, 'lot' => $lot]);
            exit;
        }

        // Actions ci-dessous = écriture -> CSRF obligatoire
        if ($token !== $csrf_token) {
            echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
            exit;
        }

        // ----- Créer / configurer un lot -----
        if ($action === 'create_lot') {
            $categorieId = trim($_POST['categorie_id'] ?? '');
            $produitId = trim($_POST['produit_id'] ?? '');
            $libelle = trim($_POST['libelle'] ?? '');
            $unitesParLot = intval($_POST['unites_par_lot'] ?? 0);
            $prixLotRaw = trim($_POST['prix_lot'] ?? '');
            $prixLot = ($prixLotRaw === '') ? null : round((float)str_replace(',', '.', $prixLotRaw), 2);
            $coutLotRaw = trim($_POST['cout_lot'] ?? '');
            $coutLot = ($coutLotRaw === '') ? null : round((float)str_replace(',', '.', $coutLotRaw), 2);

            if ($categorieId === '' || $produitId === '') {
                throw new Exception("Veuillez d'abord choisir une catégorie, puis un produit.");
            }
            if (!in_array($libelle, $libellesLot, true)) {
                throw new Exception("Type de lot invalide.");
            }
            if ($unitesParLot < 1) {
                throw new Exception("Le nombre d'unités par lot doit être supérieur ou égal à 1.");
            }
            if ($prixLot !== null && $prixLot < 0) {
                throw new Exception("Le prix du lot ne peut pas être négatif.");
            }
            if ($coutLot !== null && $coutLot < 0) {
                throw new Exception("Le coût du lot ne peut pas être négatif.");
            }

            $stmtProd = $pdo->prepare("SELECT categorie_id, titre_produit, stock_produit FROM produit WHERE code_produit = ?");
            $stmtProd->execute([$produitId]);
            $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);
            if (!$prod) {
                throw new Exception("Produit introuvable.");
            }
            if ((string)($prod['categorie_id'] ?? '') !== $categorieId) {
                throw new Exception("Le produit sélectionné n'appartient pas à la catégorie choisie.");
            }

            // La quantité disponible n'est jamais saisie manuellement : elle est
            // toujours reprise directement du stock du produit (table `produit`).
            $quantite = max(0, (int) $prod['stock_produit']);

            // Un même produit peut avoir plusieurs types de lot (Boîte, Carton, ...)
            // mais pas deux fois le même type actif : on met à jour au lieu de dupliquer.
            $stmtExist = $pdo->prepare("SELECT code_lot, quantite FROM lot WHERE produit_id = ? AND libelle = ? AND etat_lot = 'Actif'");
            $stmtExist->execute([$produitId, $libelle]);
            $existant = $stmtExist->fetch(PDO::FETCH_ASSOC);

            if ($existant) {
                $stmt = $pdo->prepare("UPDATE lot SET unites_par_lot = ?, prix_lot = ?, cout_lot = ?, quantite = ? WHERE code_lot = ?");
                $stmt->execute([$unitesParLot, $prixLot, $coutLot, $quantite, $existant['code_lot']]);
                echo json_encode([
                    'success' => true,
                    'message' => "Lot « $libelle » déjà configuré pour ce produit : quantité resynchronisée avec le stock ($quantite).",
                    'code_lot' => $existant['code_lot']
                ]);
                exit;
            }

            $codeLot = generateLotId($pdo);
            $stmt = $pdo->prepare("INSERT INTO lot (code_lot, libelle, unites_par_lot, prix_lot, cout_lot, produit_id, quantite, etat_lot) VALUES (?, ?, ?, ?, ?, ?, ?, 'Actif')");
            $stmt->execute([$codeLot, $libelle, $unitesParLot, $prixLot, $coutLot, $produitId, $quantite]);

            echo json_encode([
                'success' => true,
                'message' => "Lot « $libelle » configuré pour « {$prod['titre_produit']} » (N° $codeLot).",
                'code_lot' => $codeLot
            ]);
            exit;
        }

        // ----- Modifier un lot existant -----
        if ($action === 'update_lot') {
            $codeLot = trim($_POST['code_lot'] ?? '');
            $unitesParLot = intval($_POST['unites_par_lot'] ?? 0);
            $prixLotRaw = trim($_POST['prix_lot'] ?? '');
            $prixLot = ($prixLotRaw === '') ? null : round((float)str_replace(',', '.', $prixLotRaw), 2);
            $coutLotRaw = trim($_POST['cout_lot'] ?? '');
            $coutLot = ($coutLotRaw === '') ? null : round((float)str_replace(',', '.', $coutLotRaw), 2);

            if ($codeLot === '') throw new Exception("Lot manquant.");
            if ($unitesParLot < 1) throw new Exception("Le nombre d'unités par lot doit être supérieur ou égal à 1.");
            if ($prixLot !== null && $prixLot < 0) throw new Exception("Le prix du lot ne peut pas être négatif.");
            if ($coutLot !== null && $coutLot < 0) throw new Exception("Le coût du lot ne peut pas être négatif.");

            // La quantité n'est jamais modifiée à la main : on la resynchronise
            // avec le stock actuel du produit (table `produit`).
            $stmtLot = $pdo->prepare("SELECT produit_id FROM lot WHERE code_lot = ?");
            $stmtLot->execute([$codeLot]);
            $produitId = $stmtLot->fetchColumn();
            if ($produitId === false) throw new Exception("Lot introuvable.");

            $stmtStock = $pdo->prepare("SELECT stock_produit FROM produit WHERE code_produit = ?");
            $stmtStock->execute([$produitId]);
            $quantite = max(0, (int) $stmtStock->fetchColumn());

            $stmt = $pdo->prepare("UPDATE lot SET unites_par_lot = ?, prix_lot = ?, cout_lot = ?, quantite = ? WHERE code_lot = ?");
            $stmt->execute([$unitesParLot, $prixLot, $coutLot, $quantite, $codeLot]);

            // Un lot réapprovisionné (quantite > 0) redevient automatiquement actif.
            $pdo->prepare("UPDATE lot SET etat_lot = 'Actif' WHERE code_lot = ? AND quantite > 0")->execute([$codeLot]);

            echo json_encode(['success' => true, 'message' => 'Lot mis à jour (quantité resynchronisée avec le stock : ' . $quantite . ').']);
            exit;
        }

        // ----- Activer / désactiver un lot -----
        if ($action === 'toggle_lot') {
            $codeLot = trim($_POST['code_lot'] ?? '');
            if ($codeLot === '') throw new Exception("Lot manquant.");
            $stmt = $pdo->prepare("SELECT etat_lot FROM lot WHERE code_lot = ?");
            $stmt->execute([$codeLot]);
            $etatActuel = $stmt->fetchColumn();
            if ($etatActuel === false) throw new Exception("Lot introuvable.");
            $nouvelEtat = ($etatActuel === 'Actif') ? 'Inactif' : 'Actif';
            $pdo->prepare("UPDATE lot SET etat_lot = ? WHERE code_lot = ?")->execute([$nouvelEtat, $codeLot]);
            echo json_encode(['success' => true, 'message' => "Lot " . strtolower($nouvelEtat) . ".", 'etat' => $nouvelEtat]);
            exit;
        }

        throw new Exception('Action inconnue.');
    } catch (Exception $ex) {
        echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        exit;
    }
}

// ==========================================
// CHARGEMENT INITIAL DE LA PAGE
// ==========================================
$search = trim($_POST['search'] ?? '');
$filtres = ['etat' => trim($_POST['etat'] ?? ''), 'categorie' => trim($_POST['categorie'] ?? '')];
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

$initialData = getLotsTableContent($pdo, $search, $filtres, $page);

$totalLots = (int)$pdo->query("SELECT COUNT(*) FROM lot")->fetchColumn();
$totalActifs = (int)$pdo->query("SELECT COUNT(*) FROM lot WHERE etat_lot = 'Actif'")->fetchColumn();
$produitsConfigures = (int)$pdo->query("SELECT COUNT(DISTINCT produit_id) FROM lot")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include "includes/pwa_head.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration des lots</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
            --color-gray-100: #f1f5f9;
            --color-gray-300: #cbd5e1;
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-tertiary: #64748b;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,.06);
            --radius-sm: 10px;
            --radius-md: 14px;
            --transition-base: 250ms cubic-bezier(.4,0,.2,1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); min-height:100vh; font-size:14px; padding:24px 20px; }
        h1,h2,h3,h4,h5,h6 { font-family:'Outfit',sans-serif; font-weight:700; letter-spacing:-.02em; }
        ::-webkit-scrollbar { width:6px; height:6px; }
        ::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:3px; }
        .W { max-width:1400px; margin:0 auto; }

        .stat-card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:14px 16px; transition:var(--transition-base); }
        .stat-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
        .stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
        .stat-label { font-size:10px; font-weight:600; color:var(--text-tertiary); text-transform:uppercase; letter-spacing:.5px; }
        .stat-value { font-size:18px; font-weight:800; color:var(--text-primary); font-family:'Outfit',sans-serif; line-height:1; }

        .data-table-wrap { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-sm); overflow:hidden; box-shadow:var(--shadow-sm); animation:fadeUp .4s ease both; }
        .table { margin:0; }
        .table thead th { background:var(--color-gray-100); color:var(--text-tertiary); font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; padding:12px 14px; border-bottom:2px solid var(--border-color); white-space:nowrap; }
        .table tbody tr { border-bottom:1px solid var(--border-color); transition:background .2s; }
        .table tbody tr:hover { background:var(--color-primary-soft); }
        .table tbody td { padding:12px 14px; vertical-align:middle; color:var(--text-primary); font-size:13px; }
        .td-bold { color:var(--text-primary) !important; font-weight:700; }

        .status-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:999px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
        .status-badge .sdot { width:6px; height:6px; border-radius:50%; background:currentColor; animation:pulse 2s infinite; }
        .status-badge.on { background:var(--color-success-soft); color:#065f46; }
        .status-badge.off { background:var(--color-danger-soft); color:#991b1b; }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:.5; } }

        .act-btn { width:32px; height:32px; border-radius:6px; border:1.5px solid transparent; background:transparent; display:inline-flex; align-items:center; justify-content:center; transition:all .2s; font-size:14px; cursor:pointer; padding:0; }
        .act-btn:hover { transform:scale(1.1); }
        .act-btn.e { color:var(--color-warning); border-color:rgba(245,158,11,.2); }
        .act-btn.e:hover { color:#b45309; background:var(--color-warning-soft); border-color:var(--color-warning); }
        .act-btn.d { color:var(--color-danger); border-color:rgba(239,68,68,.2); }
        .act-btn.d:hover { color:#b91c1c; background:var(--color-danger-soft); border-color:var(--color-danger); }

        .btn-chic { padding:10px 18px; border-radius:10px; font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:8px; border:none; cursor:pointer; transition:all .25s cubic-bezier(.4,0,.2,1); position:relative; overflow:hidden; letter-spacing:-.01em; }
        .btn-chic::before { content:''; position:absolute; top:50%; left:50%; width:0; height:0; background:rgba(255,255,255,.3); border-radius:50%; transform:translate(-50%,-50%); transition:width .4s,height .4s; }
        .btn-chic:hover::before { width:300px; height:300px; }
        .btn-chic i { font-size:15px; position:relative; z-index:1; }
        .btn-chic span { position:relative; z-index:1; }
        .btn-chic-primary { background:linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); color:#fff; box-shadow:0 4px 12px rgba(79,70,229,.3); }
        .btn-chic-primary:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(79,70,229,.4); }
        .btn-go-outline { background:transparent; color:var(--text-tertiary); border:1.5px solid var(--border-color); padding:7px 14px; border-radius:8px; font-size:12px; font-weight:600; transition:all .2s; cursor:pointer; }
        .btn-go-outline:hover { background:var(--color-gray-100); border-color:var(--color-gray-300); }

        .modal-chic .modal-content { border:none !important; border-radius:20px !important; box-shadow:0 25px 60px rgba(15,23,42,.15) !important; overflow:hidden !important; animation:modalSlideIn .4s cubic-bezier(.16,1,.3,1); display:flex !important; flex-direction:column !important; max-height:90vh !important; }
        @keyframes modalSlideIn { from { opacity:0; transform:translateY(30px) scale(.96); } to { opacity:1; transform:translateY(0) scale(1); } }
        .modal-chic .modal-header { background:linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%); color:#fff; border:none; padding:22px 28px; position:relative; overflow:hidden; flex-shrink:0 !important; }
        .modal-chic .modal-header::before { content:''; position:absolute; top:-50%; right:-20%; width:200px; height:200px; background:radial-gradient(circle, rgba(255,255,255,.08) 0%, transparent 70%); border-radius:50%; }
        .modal-chic .modal-title { font-size:18px; font-weight:700; display:flex; align-items:center; gap:12px; position:relative; z-index:1; }
        .modal-chic .modal-title i { font-size:22px; background:rgba(255,255,255,.15); width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; }
        .modal-chic .btn-close { filter:invert(1); opacity:.7; position:relative; z-index:1; }
        .modal-chic .btn-close:hover { opacity:1; transform:rotate(90deg); }
        .modal-chic .modal-body { padding:28px !important; overflow-y:auto !important; background:#f8fafc !important; flex:1 1 auto !important; min-height:0 !important; }
        .modal-chic .modal-footer { background:#fff !important; border-top:2px solid var(--border-color) !important; padding:18px 28px !important; display:flex !important; gap:10px !important; justify-content:flex-end !important; flex-wrap:wrap !important; flex-shrink:0 !important; }
        .modal-chic form { display:flex; flex-direction:column; flex:1 1 auto; min-height:0; }

        .form-label { font-size:10px; font-weight:700; color:var(--text-tertiary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }
        .form-control, .form-select { border-radius:10px; border:1.5px solid var(--border-color); padding:10px 14px; font-size:13px; transition:all .2s; }
        .form-control:focus, .form-select:focus { border-color:var(--color-primary); box-shadow:0 0 0 3px var(--color-primary-soft); }

        .bootstrap-select .dropdown-toggle { background:#fff !important; border:1.5px solid var(--border-color) !important; border-radius:10px !important; }
        .bootstrap-select .dropdown-toggle:focus { border-color:var(--color-primary) !important; box-shadow:0 0 0 3px var(--color-primary-soft) !important; }
        .bootstrap-select .dropdown-menu { border-radius:var(--radius-sm); border-color:var(--border-color); box-shadow:var(--shadow-md); }

        .lots-existants { background:var(--color-primary-soft); border:1px solid #c7d2fe; border-radius:10px; padding:10px 14px; font-size:12px; margin-top:10px; display:none; }
        .lots-existants .lot-chip { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #c7d2fe; border-radius:999px; padding:3px 10px; margin:3px 4px 0 0; font-weight:600; color:var(--color-primary-dark); }

        @keyframes fadeUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
        @media (max-width:700px) { body{padding:14px;} }
    </style>
</head>
<body>
<div class="W">
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-box-seam text-primary me-2"></i>Configuration des lots</h1>
            <p class="text-muted small mb-0">Choisissez d'abord une catégorie, puis un produit, pour configurer ses lots (Boîte, Carton, Palette, Bidon, Unité).</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                <i class="bi bi-box-seam"></i> <?= $totalLots ?> lot(s)
            </span>
            <button type="button" class="btn-chic btn-chic-primary" id="addLotBtn">
                <i class="bi bi-plus-circle"></i><span>Nouveau lot</span>
            </button>
        </div>
    </div>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background:var(--color-primary-soft); color:var(--color-primary);"><i class="bi bi-box-seam"></i></div>
                <div><div class="stat-label">Lots configurés</div><div class="stat-value"><?= $totalLots ?></div></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background:var(--color-success-soft); color:var(--color-success);"><i class="bi bi-check-circle"></i></div>
                <div><div class="stat-label">Lots actifs</div><div class="stat-value"><?= $totalActifs ?></div></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background:var(--color-info-soft); color:var(--color-info);"><i class="bi bi-bar-chart"></i></div>
                <div><div class="stat-label">Produits couverts</div><div class="stat-value"><?= $produitsConfigures ?></div></div>
            </div>
        </div>
    </div>

    <!-- Recherche / filtre -->
    <div class="bg-white border rounded-3 p-3 mb-4 shadow-sm">
        <form id="searchForm" onsubmit="return false;">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-search"></i> Recherche</label>
                <input type="text" id="searchInput" class="form-control" placeholder="Produit, catégorie, type de lot..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">

                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-tags"></i> Catégorie</label>
                <select id="categorieFilter" class="selectpicker" data-live-search="true">
                    <option value="">Toutes</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c['code_categorie']) ?>" <?= ($filtres['categorie'] === $c['code_categorie']) ? 'selected' : '' ?>><?= e($c['titre_categorie']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-toggle-on"></i> État</label>
                <select id="etatFilter" class="selectpicker">
                    <option value="">Tous</option>
                    <option value="Actif" <?= ($filtres['etat'] === 'Actif') ? 'selected' : '' ?>>Actif</option>
                    <option value="Inactif" <?= ($filtres['etat'] === 'Inactif') ? 'selected' : '' ?>>Inactif</option>
                </select>

                <button type="button" class="btn-chic btn-chic-primary" id="filterBtn"><i class="bi bi-funnel"></i><span>Filtrer</span></button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <!-- Liste des lots -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold">Lots configurés</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> lot(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th>Produit</th>
                    <th>Catégorie</th>
                    <th>Type de lot</th>
                    <th>Unités/lot</th>
                    <th>Prix du lot</th>
                    <th>Coût du lot</th>
                    <th>Quantité dispo.</th>
                    <th>État</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody id="tableBody"><?= $initialData['table'] ?></tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $initialData['pagination'] ?></div>
    </div>
</div>

<!-- Modal Créer / Modifier un lot -->
<div class="modal fade modal-chic" id="lotModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-box-seam" id="lotModalIcon"></i><span id="lotModalTitleText">Nouveau lot</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="lotForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="lotFormAction" value="create_lot">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="code_lot" id="lotCodeField" value="">

                    <div id="lotCreateFields">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="categorie_id" class="form-label">1. Catégorie <span class="text-danger">*</span></label>
                                <select name="categorie_id" id="categorie_id" class="form-select selectpicker" data-live-search="true">
                                    <option value="">-- Choisir une catégorie --</option>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?= e($c['code_categorie']) ?>"><?= e($c['titre_categorie']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="produit_id" class="form-label">2. Produit <span class="text-danger">*</span></label>
                                <select name="produit_id" id="produit_id" class="form-select selectpicker" data-live-search="true" disabled title="-- Choisir d'abord une catégorie --">
                                    <option value="">-- Choisir un produit --</option>
                                    <?php foreach ($produits as $p): ?>
                                        <option value="<?= e($p['code_produit']) ?>" data-categorie="<?= e($p['categorie_id'] ?? '') ?>">
                                            <?= e($p['titre_produit']) ?><?= ($p['etat_produit'] === 'Inactif') ? ' (inactif)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="lotsExistants" class="lots-existants"></div>
                            </div>
                            <div class="col-md-12">
                                <label for="libelle" class="form-label">3. Type de lot <span class="text-danger">*</span></label>
                                <select name="libelle" id="libelle" class="form-select selectpicker" disabled title="-- Choisir d'abord un produit --">
                                    <?php foreach ($libellesLot as $lib): ?>
                                        <option value="<?= e($lib) ?>"><?= e($lib) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="lotEditInfo" class="mb-3" style="display:none;">
                        <div class="p-3 rounded-3" style="background:var(--color-primary-soft); border:1px solid #c7d2fe;">
                            <div class="small text-muted mb-1">Produit</div>
                            <div class="fw-bold" id="lotEditProduit">—</div>
                            <div class="small text-muted mt-2 mb-1">Type de lot</div>
                            <div class="fw-bold" id="lotEditLibelle">—</div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label for="unites_par_lot" class="form-label">Unités par lot <span class="text-danger">*</span></label>
                            <input type="number" name="unites_par_lot" id="unites_par_lot" class="form-control" min="1" step="1" value="1" required disabled>
                            <div class="form-text" style="font-size:11px;color:var(--text-tertiary);">Ex : 24 pour un carton de 24 pièces.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="prix_lot" class="form-label">Prix du lot <span class="text-muted">(vente, optionnel)</span></label>
                            <input type="number" name="prix_lot" id="prix_lot" class="form-control" min="0" step="0.01" placeholder="Laisser vide = prix unitaire × unités" disabled>
                            <div class="form-text" style="font-size:11px;color:var(--text-tertiary);">Ex : 10000 pour vendre le carton entier à 10 000 FCFA au lieu de 24 × prix unitaire.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="cout_lot" class="form-label">Coût du lot <span class="text-muted">(achat, optionnel)</span></label>
                            <input type="number" name="cout_lot" id="cout_lot" class="form-control" min="0" step="0.01" placeholder="Laisser vide = prix fournisseur × unités" disabled>
                            <div class="form-text" style="font-size:11px;color:var(--text-tertiary);">Ex : 8000 si le fournisseur vend le carton de 24 à 8 000 FCFA.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="quantite" class="form-label">Quantité disponible</label>
                            <input type="text" id="quantite" class="form-control" value="—" readonly tabindex="-1" style="background:var(--color-gray-100);font-weight:700;">
                            <div class="form-text" style="font-size:11px;color:var(--text-tertiary);">Reprise automatiquement du stock du produit — non modifiable ici.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn-chic btn-chic-primary" id="btnSubmitLot">
                        <i class="bi bi-save"></i><span id="btnSubmitLotText">Enregistrer la configuration</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal confirmation activer/désactiver -->
<div class="modal fade" id="toggleConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-power text-warning" style="font-size:3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Changer l'état du lot</h5>
                <p class="text-muted small mb-4">Voulez-vous vraiment changer l'état du lot <strong id="toggleLotNom" class="text-primary"></strong> ?</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-warning rounded-3" id="confirmToggleBtn">
                        <i class="bi bi-power me-1"></i> Confirmer
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
<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker();
    const CSRF = <?= json_encode($csrf_token) ?>;

    const lotModalEl = document.getElementById('lotModal');
    const lotModal = new bootstrap.Modal(lotModalEl);
    const toggleModal = new bootstrap.Modal(document.getElementById('toggleConfirmModal'));
    const toastEl = document.getElementById('toastMsg');
    const toast = new bootstrap.Toast(toastEl, { delay: 2500 });

    // Tant que le modal est caché (display:none), bootstrap-select ne peut pas
    // mesurer/peindre correctement le bouton d'un select : le libellé choisi
    // (catégorie) ne s'affiche pas. Et comme dans entree_stock.php, 'refresh'
    // ne resynchronise pas toujours la liste interne du plugin sur cette
    // version beta — cela duplique le texte du bouton (ex. "CartonCartonCarton").
    // ✅ On applique donc le même pattern DESTROY + RÉINIT qu'entree_stock.php,
    // une fois le modal réellement visible.
    lotModalEl.addEventListener('shown.bs.modal', function() {
        ['#categorie_id', '#produit_id', '#libelle'].forEach(function(sel) {
            var $s = $(sel);
            if ($s.hasClass('bs-select-hidden') || $s.data('selectpicker')) $s.selectpicker('destroy');
            $s.selectpicker();
        });
    });

    function showToast(msg, type) {
        type = type || 'success';
        const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-primary' };
        const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        $('#toastBody').html(`<i class="bi ${icons[type]} me-2"></i>${msg}`);
        toastEl.className = `toast align-items-center text-white border-0 ${colors[type]}`;
        toast.show();
    }

    // --------- Recherche + pagination (liste) ---------
    let currentPage = <?= (int)$initialData['page'] ?>;

    function rechercher(page) {
        page = page || 1;
        currentPage = page;
        const search = $('#searchInput').val();
        const etat = $('#etatFilter').val();
        const categorie = $('#categorieFilter').val();
        $.post(window.location.href, { ajax: 1, search: search, etat: etat, categorie: categorie, page: page }, function(data) {
            $('#tableBody').html(data.table);
            $('#paginationContainer').html(data.pagination);
            $('#totalCount').text(data.total + ' lot(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
            currentPage = data.page;
        }, 'json');
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
    $('#categorieFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#filterBtn').on('click', function() { rechercher(1); });
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#etatFilter').selectpicker('val', '');
        $('#categorieFilter').selectpicker('val', '');
        rechercher(1);
    });
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        const page = $(this).data('page');
        if (page && !$(this).parent().hasClass('disabled')) rechercher(page);
    });

    // --------- Étape 1 : filtrage des produits par catégorie (dans le modal) ---------
    var allProduitOptions = [];
    $('#produit_id option').each(function() {
        allProduitOptions.push({ value: $(this).val(), text: $.trim($(this).text()), categorie: String($(this).attr('data-categorie') || '') });
    });

    function lockStepsAfterProduit(lock) {
        ['#libelle', '#unites_par_lot', '#prix_lot', '#cout_lot'].forEach(function(sel) {
            $(sel).prop('disabled', lock);
        });
        if (lock) $('#quantite').val('—');
        var $libelle = $('#libelle');
        if ($libelle.hasClass('bs-select-hidden') || $libelle.data('selectpicker')) $libelle.selectpicker('destroy');
        $libelle.selectpicker();
    }

    function filterProduitsByCategorie() {
        var cat = String($('#categorie_id').val() || '').trim();
        var $produit = $('#produit_id');
        $produit.empty().append($('<option>', { value: '', text: '-- Choisir un produit --' }));

        if (cat === '') {
            $produit.prop('disabled', true).attr('title', "-- Choisir d'abord une catégorie --");
        } else {
            allProduitOptions.forEach(function(opt) {
                if (opt.value !== '' && opt.categorie === cat) {
                    $produit.append($('<option>', { value: opt.value, text: opt.text }));
                }
            });
            $produit.prop('disabled', false).attr('title', '-- Choisir un produit --');
        }
        if ($produit.hasClass('bs-select-hidden') || $produit.data('selectpicker')) $produit.selectpicker('destroy');
        $produit.selectpicker();

        $('#lotsExistants').hide().empty();
        lockStepsAfterProduit(true);
    }

    // --------- Étape 2 : au choix du produit, afficher les lots déjà configurés
    // et récupérer la quantité disponible directement depuis la table `produit` ---------
    function onProduitChange() {
        var produit = $('#produit_id').val();
        $('#lotsExistants').hide().empty();
        if (!produit) { lockStepsAfterProduit(true); return; }

        lockStepsAfterProduit(false);

        $.post(window.location.href, { action: 'get_lots_produit', produit_id: produit }, function(res) {
            if (!res.success) return;

            // Quantité disponible = stock actuel du produit (lecture seule).
            $('#quantite').val((typeof res.stock_produit !== 'undefined') ? res.stock_produit : '—');

            if (res.lots && res.lots.length) {
                var html = '<strong><i class="bi bi-info-circle me-1"></i>Lots déjà configurés pour ce produit :</strong><br>';
                res.lots.forEach(function(l) {
                    var prixTxt = (l.prix_lot !== null && l.prix_lot !== '') ? Number(l.prix_lot).toLocaleString('fr-FR') + ' F' : 'prix unitaire × qté';
                    var coutTxt = (l.cout_lot !== null && l.cout_lot !== '') ? Number(l.cout_lot).toLocaleString('fr-FR') + ' F' : 'prix fourn. × qté';
                    html += `<span class="lot-chip">${l.libelle} — ${l.unites_par_lot}/lot — vente ${prixTxt} — achat ${coutTxt} — dispo ${l.quantite} <em>(${l.etat_lot})</em></span>`;
                });
                html += '<div class="mt-1 text-muted">Choisir le même type ci-dessous resynchronisera sa quantité avec le stock.</div>';
                $('#lotsExistants').html(html).show();
            }
        }, 'json');
    }

    $('#categorie_id').on('changed.bs.select change', filterProduitsByCategorie);
    $('#produit_id').on('changed.bs.select change', onProduitChange);

    // --------- Ouverture modal : Nouveau lot ---------
    function resetLotModalToCreate() {
        $('#lotForm')[0].reset();
        $('#lotFormAction').val('create_lot');
        $('#lotCodeField').val('');
        $('#lotModalIcon').attr('class', 'bi bi-box-seam');
        $('#lotModalTitleText').text('Nouveau lot');
        $('#btnSubmitLotText').text('Enregistrer la configuration');
        $('#prix_lot').val('');
        $('#cout_lot').val('');

        $('#lotCreateFields').show();
        $('#lotEditInfo').hide();
        $('#categorie_id, #produit_id, #libelle').prop('required', true);

        var $cat = $('#categorie_id');
        $cat.val('');
        if ($cat.hasClass('bs-select-hidden') || $cat.data('selectpicker')) $cat.selectpicker('destroy');
        $cat.selectpicker();

        filterProduitsByCategorie();
    }

    $('#addLotBtn').on('click', function() {
        resetLotModalToCreate();
        lotModal.show();
    });

    // --------- Ouverture modal : Modifier un lot ---------
    $(document).on('click', '.editLotBtn', function() {
        var code = $(this).data('code');
        $.post(window.location.href, { action: 'get_lot', code_lot: code }, function(res) {
            if (!res.success) { showToast(res.message || 'Erreur', 'error'); return; }
            var l = res.lot;

            $('#lotFormAction').val('update_lot');
            $('#lotCodeField').val(l.code_lot);
            $('#lotModalIcon').attr('class', 'bi bi-pencil');
            $('#lotModalTitleText').text('Modifier le lot');
            $('#btnSubmitLotText').text('Enregistrer les modifications');

            $('#lotCreateFields').hide();
            $('#categorie_id, #produit_id, #libelle').prop('required', false);

            $('#lotEditProduit').text(l.titre_produit + ' (' + l.titre_categorie + ')');
            $('#lotEditLibelle').text(l.libelle);
            $('#lotEditInfo').show();

            $('#unites_par_lot').prop('disabled', false).val(l.unites_par_lot);
            $('#prix_lot').prop('disabled', false).val(l.prix_lot !== null ? l.prix_lot : '');
            $('#cout_lot').prop('disabled', false).val(l.cout_lot !== null ? l.cout_lot : '');
            $('#quantite').val((typeof l.stock_produit !== 'undefined') ? l.stock_produit : l.quantite);

            lotModal.show();
        }, 'json').fail(function() {
            showToast('Erreur de connexion.', 'error');
        });
    });

    // --------- Confirmation activer/désactiver ---------
    var codeLotToToggle = null;
    $(document).on('click', '.toggleLotBtn', function() {
        codeLotToToggle = $(this).data('code');
        $('#toggleLotNom').text($(this).data('produit') || '');
        toggleModal.show();
    });

    $('#confirmToggleBtn').on('click', function() {
        if (!codeLotToToggle) return;
        $.post(window.location.href, { action: 'toggle_lot', csrf_token: CSRF, code_lot: codeLotToToggle }, function(res) {
            toggleModal.hide();
            showToast(res.message || (res.success ? 'État modifié.' : 'Erreur'), res.success ? 'success' : 'error');
            if (res.success) rechercher(currentPage);
        }, 'json').fail(function() {
            toggleModal.hide();
            showToast('Erreur de connexion.', 'error');
        });
    });

    // --------- Soumission du formulaire (création / modification) ---------
    $('#lotForm').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#btnSubmitLot');
        $btn.prop('disabled', true);
        $.post(window.location.href, $(this).serialize(), function(res) {
            $btn.prop('disabled', false);
            showToast(res.message || (res.success ? 'Opération réussie.' : 'Erreur'), res.success ? 'success' : 'error');
            if (res.success) {
                lotModal.hide();
                rechercher(currentPage);
            }
        }, 'json').fail(function() {
            $btn.prop('disabled', false);
            showToast('Erreur de connexion.', 'error');
        });
    });

    // État initial du modal (pour la première ouverture)
    filterProduitsByCategorie();

    setTimeout(function() { $('.alert').alert('close'); }, 5000);
});
</script>
</body>
</html>