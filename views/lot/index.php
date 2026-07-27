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

// views/lot_produit/index.php – Gestion des lots de produits (design dashboard)
require __DIR__ . '/../../databases/database.php';


// Vérification session + utilisateur actif
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

// Fonctions utilitaires
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}

// Génération d'ID pour lot_produit
function generateLotId($pdo) {
    $date = date('Ymd');
    $prefix = 'LP-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lot_produit WHERE code_lot_produit LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// États possibles
$etats = ['Actif', 'Inactif'];

// Récupération des produits pour les listes déroulantes
$produits = [];
$stmt = $pdo->query("SELECT code_produit, titre_produit FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit");
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Traitement des actions POST (ajout, modification, suppression) ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token_post = $_POST['csrf_token'] ?? '';

// Génération du token CSRF (doit être fait avant toute vérification)
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($csrf_token_post) || $csrf_token_post !== $csrf_token) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {
        // Ajout ou modification (soumission du formulaire principal)
        if ($action === 'add' || $action === 'edit') {
            $code = trim($_POST['code_lot_produit'] ?? '');
            $produit_id = trim($_POST['produit_id'] ?? '');
            $titre_lot = trim($_POST['titre_lot'] ?? '');
            $unites_par_lot = intval($_POST['unites_par_lot'] ?? 1);
            $etat_lot = trim($_POST['etat_lot'] ?? 'Actif');

            $errors = [];
            if (empty($produit_id)) $errors[] = 'Le produit est requis.';
            if (empty($titre_lot)) $errors[] = 'Le titre du lot est requis.';
            if ($unites_par_lot < 1) $errors[] = 'Le nombre d\'unités doit être au moins 1.';

            if (empty($errors)) {
                try {
                    if ($action === 'add') {
                        $code = generateLotId($pdo);
                        // Vérifier unicité du code
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lot_produit WHERE code_lot_produit = ?");
                        $stmt->execute([$code]);
                        if ($stmt->fetchColumn() > 0) {
                            $message = "Ce code lot existe déjà.";
                            $messageType = 'warning';
                        } else {
                            $sql = "INSERT INTO lot_produit (code_lot_produit, produit_id, titre_lot, unites_par_lot, etat_lot)
                                    VALUES (?, ?, ?, ?, ?)";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$code, $produit_id, $titre_lot, $unites_par_lot, $etat_lot]);
                            $message = "Lot « $titre_lot » ajouté avec succès. ID : $code";
                            $messageType = 'success';
                        }
                    } elseif ($action === 'edit') {
                        $oldCode = $_POST['old_code'] ?? $code;
                        // Vérifier que le code n'est pas déjà utilisé par un autre lot
                        if ($oldCode !== $code) {
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lot_produit WHERE code_lot_produit = ? AND code_lot_produit != ?");
                            $stmt->execute([$code, $oldCode]);
                            if ($stmt->fetchColumn() > 0) {
                                $message = "Ce code lot est déjà utilisé par un autre lot.";
                                $messageType = 'warning';
                            } else {
                                $sql = "UPDATE lot_produit SET code_lot_produit=?, produit_id=?, titre_lot=?, unites_par_lot=?, etat_lot=?
                                        WHERE code_lot_produit = ?";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$code, $produit_id, $titre_lot, $unites_par_lot, $etat_lot, $oldCode]);
                                $message = "Lot « $titre_lot » mis à jour.";
                                $messageType = 'success';
                            }
                        } else {
                            $sql = "UPDATE lot_produit SET produit_id=?, titre_lot=?, unites_par_lot=?, etat_lot=?
                                    WHERE code_lot_produit = ?";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$produit_id, $titre_lot, $unites_par_lot, $etat_lot, $oldCode]);
                            $message = "Lot « $titre_lot » mis à jour.";
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
            $code = $_POST['sai_supprimer_id'] ?? '';
            if (!empty($code)) {
                try {
                    $stmt = $pdo->prepare("SELECT titre_lot FROM lot_produit WHERE code_lot_produit = ?");
                    $stmt->execute([$code]);
                    $titre = $stmt->fetchColumn();
                    $stmt = $pdo->prepare("DELETE FROM lot_produit WHERE code_lot_produit = ?");
                    $stmt->execute([$code]);
                    $message = "Lot « $titre » supprimé.";
                    $messageType = 'danger';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}

// --- Fonction pour récupérer le contenu du tableau (AJAX et initial) ---
function getTableContent($pdo, $search, $etat_filter, $page, $perPage = 20) {
    $sql = "SELECT lp.code_lot_produit, lp.produit_id, lp.titre_lot, lp.unites_par_lot, lp.etat_lot, p.titre_produit
            FROM lot_produit lp
            LEFT JOIN produit p ON lp.produit_id = p.code_produit
            WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (lp.code_lot_produit LIKE ? OR lp.titre_lot LIKE ? OR p.titre_produit LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($etat_filter)) {
        $sql .= " AND lp.etat_lot = ?";
        $params[] = $etat_filter;
    }

    // Compter le total
    $countSql = str_replace(
        "SELECT lp.code_lot_produit, lp.produit_id, lp.titre_lot, lp.unites_par_lot, lp.etat_lot, p.titre_produit",
        "SELECT COUNT(*)",
        $sql
    );
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    if ($page < 1) $page = 1;

    $sql .= " ORDER BY p.titre_produit, lp.titre_lot LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Génération HTML du tableau
    ob_start();
    if (empty($lots)): ?>
        <tr>
            <td colspan="6" class="text-center py-5 text-muted">
                <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>
                Aucun lot trouvé
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($lots as $l): ?>
            <tr>
                <td class="td-bold"><?= e($l['code_lot_produit']) ?></td>
                <td><?= e($l['titre_produit'] ?? '—') ?></td>
                <td><?= e($l['titre_lot']) ?></td>
                <td><?= (int)$l['unites_par_lot'] ?></td>
                <td>
                    <span class="status-badge <?= $l['etat_lot'] === 'Actif' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= e($l['etat_lot']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn e editBtn" data-code="<?= e($l['code_lot_produit']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= e($l['code_lot_produit']) ?>" data-nom="<?= e($l['titre_lot']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash3"></i></button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    $tableHtml = ob_get_clean();

    // Pagination
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
        'table'      => $tableHtml,
        'pagination' => $paginationHtml,
        'total'      => $total,
        'page'       => $page,
        'totalPages' => $totalPages
    ];
}

// --- Gestion des requêtes AJAX (recherche, filtrage, pagination) ---
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $etat_filter = trim($_POST['etat_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $etat_filter, $page);
    sendJson($result);
}

// --- Affichage initial (chargement de la page) ---
$search = trim($_POST['search'] ?? '');
$etat_filter = trim($_POST['etat_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;

// On appelle getTableContent et on s'assure qu'elle retourne un tableau avec toutes les clés
$initialData = getTableContent($pdo, $search, $etat_filter, $page);

// Par sécurité, on vérifie les clés et on les initialise si absentes
$defaultKeys = ['table', 'pagination', 'total', 'page', 'totalPages'];
foreach ($defaultKeys as $key) {
    if (!isset($initialData[$key])) {
        $initialData[$key] = ($key === 'table' || $key === 'pagination') ? '' : 0;
    }
}

// Récupération du lot à modifier si on vient de l'action 'load_edit'
$editLot = null;
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM lot_produit WHERE code_lot_produit = ?");
    $stmt->execute([$code]);
    $editLot = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des lots de produits</title>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Bootstrap 5 (CSS seulement) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap SelectPicker -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
    <style>
        /* ===== STYLE DASHBOARD ===== */
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

        .W {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0;
        }
        .hdr {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .hdr-l h1 {
            font-size: 26px;
            font-weight: 800;
            color: var(--dk);
            letter-spacing: -0.02em;
        }
        .hdr-l p {
            font-size: 13px;
            color: var(--mt);
            margin-top: 2px;
            font-weight: 500;
        }
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
        .sec {
            font-size: 11px;
            font-weight: 700;
            color: var(--mt);
            letter-spacing: .06em;
            text-transform: uppercase;
            margin: 24px 0 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .sec::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--brd);
        }
        .sec i { font-size: 14px; color: var(--b); }

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
        .table>:not(caption)>*>* {
            padding: 12px 18px;
        }
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
        .table tbody tr:hover {
            background: var(--bl);
        }
        .table tbody td {
            vertical-align: middle;
            color: var(--dk);
            font-size: 0.85rem;
        }
        .td-bold {
            color: var(--dk) !important;
            font-weight: 700;
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
            background: var(--sucl);
            color: #059669;
        }
        .status-badge.off {
            background: var(--dngl);
            color: #dc2626;
        }

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
        .pagination .page-link:hover {
            background: var(--bl);
            border-color: var(--b);
        }
        .pagination .page-item.active .page-link {
            background: var(--b);
            border-color: var(--b);
            color: #fff;
        }
        .pagination .page-item.disabled .page-link {
            color: var(--lt);
            border-color: var(--brd);
        }

        .modal-content {
            border-radius: var(--R);
            border: none;
            box-shadow: 0 12px 40px rgba(15,23,42,.08);
        }
        .modal-header {
            border-bottom: 1px solid var(--brd);
            background: var(--bg);
        }
        .modal-footer {
            border-top: 1px solid var(--brd);
            background: var(--bg);
        }

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
        .bootstrap-select .dropdown-toggle .filter-option {
            color: var(--dk);
        }
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
            <h1>Gestion des lots</h1>
            <p>Conditionnements (carton, palette, boîte) par produit</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-boxes"></i> <?= $initialData['total'] ?> lot(s)</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau lot</button>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Barre de recherche / filtres -->
    <div class="pbar">
        <form id="searchForm" method="post" onsubmit="return false;">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="page" id="pageInput" value="<?= $initialData['page'] ?>">
            <div class="prow">
                <label for="searchInput"><i class="bi bi-search"></i> Recherche</label>
                <input type="text" name="search" id="searchInput" placeholder="Code, lot, produit..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                <label for="etatFilter">État</label>
                <select name="etat_filter" id="etatFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Tous">
                    <option value="">Tous</option>
                    <?php foreach ($etats as $e): ?>
                        <option value="<?= e($e) ?>" <?= ($etat_filter == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
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
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des lots</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> lot(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Produit</th>
                        <th>Titre du lot</th>
                        <th>Unités par lot</th>
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

<!-- ===== MODAL FORMULAIRE ===== -->
<div class="modal fade" id="lotModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-boxes text-primary me-2"></i> Nouveau lot</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="lotForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_code" id="oldCode" value="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="code_lot_produit" class="form-label fw-semibold">Code lot</label>
                            <input type="text" class="form-control" id="code_lot_produit" name="code_lot_produit" readonly value="<?= e($editLot['code_lot_produit'] ?? generateLotId($pdo)) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="titre_lot" class="form-label fw-semibold">Titre du lot <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="titre_lot" name="titre_lot" required placeholder="Ex: Carton, Palette, Boîte" value="<?= e($editLot['titre_lot'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="produit_id" class="form-label fw-semibold">Produit <span class="text-danger">*</span></label>
                            <select class="form-select selectpicker" id="produit_id" name="produit_id" data-live-search="true" data-live-search-placeholder="Choisir un produit..." required>
                                <option value="">=== Choisissez ===</option>
                                <?php foreach ($produits as $p): ?>
                                    <option value="<?= e($p['code_produit']) ?>" <?= (isset($editLot) && $editLot['produit_id'] == $p['code_produit']) ? 'selected' : '' ?>><?= e($p['titre_produit']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="unites_par_lot" class="form-label fw-semibold">Unités par lot <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="unites_par_lot" name="unites_par_lot" required min="1" value="<?= e($editLot['unites_par_lot'] ?? 1) ?>">
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="etat_lot" class="form-label fw-semibold">État</label>
                            <select class="form-select" id="etat_lot" name="etat_lot">
                                <?php foreach ($etats as $e): ?>
                                    <option value="<?= e($e) ?>" <?= (isset($editLot) && $editLot['etat_lot'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn"><i class="bi bi-save"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL SUPPRESSION ===== -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius:16px;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:3rem;"></i></div>
                <h5 class="modal-title mb-2" style="font-weight:600;">Confirmer la suppression</h5>
                <p class="text-danger mb-4">Supprimer le lot <strong id="deleteNomLot"></strong> ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash3 me-1"></i> Supprimer</button>
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
    <input type="hidden" name="edit_code" id="editCodeField">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
</form>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

<script>
$(document).ready(function() {
    // Initialisation des selectpicker
    $('.selectpicker').selectpicker('destroy').selectpicker();

    // --- Ajout ---
    $('#addBtn').on('click', function(e) {
        e.preventDefault();
        $('#formAction').val('add');
        $('#oldCode').val('');
        $('#modalTitle').html('<i class="bi bi-boxes text-primary me-2"></i> Nouveau lot');
        $('#lotForm')[0].reset();
        $('#code_lot_produit').prop('readonly', true).val('<?= generateLotId($pdo) ?>');
        $('#titre_lot').val('');
        $('#produit_id').selectpicker('val', '');
        $('#unites_par_lot').val('1');
        $('#etat_lot').val('Actif');
        new bootstrap.Modal(document.getElementById('lotModal')).show();
    });

    // --- Édition (chargement via formulaire POST) ---
    $(document).on('click', '.editBtn', function(e) {
        e.preventDefault();
        $('#actionField').val('load_edit');  // action modifiée
        $('#editCodeField').val($(this).data('code'));
        $('#actionForm').submit();
    });

    // --- Fonction de recherche AJAX ---
    function rechercher(page) {
        page = page || 1;
        var formData = $('#searchForm').serialize() + '&page=' + page;
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' lot(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
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

    // Auto-recherche au fil de la saisie (delay 300ms)
    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            rechercher(1);
        }, 300);
    });

    // Changement du filtre état
    $('#etatFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            rechercher(1);
        }, 300);
    });

    // Bouton Filtrer
    $('#filterBtn').on('click', function() {
        rechercher(1);
    });

    // Bouton Réinitialiser
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#etatFilter').selectpicker('val', '');
        rechercher(1);
    });

    // Gestion des clics sur la pagination initiale
    $('.page-link').on('click', function(e) {
        e.preventDefault();
        var p = $(this).data('page');
        if (p) rechercher(p);
    });

    // --- Suppression ---
    $(document).on('click', '.deleteBtn', function(e) {
        e.preventDefault();
        $('#deleteNomLot').text($(this).data('nom'));
        $('#deleteFormId').val($(this).data('code'));
        new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
    });

    $('#confirmDeleteBtn').on('click', function() {
        $('#deleteForm').submit();
    });

    // Auto-fermeture des alertes
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);

    // --- Si édition via POST (chargement des données) ---
    <?php if (isset($editLot) && $action === 'load_edit'): ?>
        $(function() {
            $('#formAction').val('edit');
            $('#oldCode').val('<?= e($editLot['code_lot_produit']) ?>');
            $('#modalTitle').html('<i class="bi bi-boxes text-primary me-2"></i> Modifier le lot');
            $('#code_lot_produit').prop('readonly', true).val('<?= e($editLot['code_lot_produit']) ?>');
            $('#titre_lot').val('<?= e($editLot['titre_lot']) ?>');
            $('#produit_id').selectpicker('val', '<?= e($editLot['produit_id']) ?>');
            $('#unites_par_lot').val('<?= (int)$editLot['unites_par_lot'] ?>');
            $('#etat_lot').val('<?= e($editLot['etat_lot']) ?>');
            $('.selectpicker').selectpicker('refresh');
            new bootstrap.Modal(document.getElementById('lotModal')).show();
        });
    <?php endif; ?>
});
</script>
</body>
</html>