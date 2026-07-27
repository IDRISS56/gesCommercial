<?php
// prix.php – CRUD pour la table prix avec gestion des conditionnements (lot_produit)
// Design dashboard identique aux autres pages
ob_start(); // capture tout octet parasite (BOM, espaces) émis par ce fichier ou les fichiers inclus
require 'databases/database.php';

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
// Fonctions
function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}
// Génération d'ID pour prix
function generatePrixId($pdo)
{
    $date = date('Ymd');
    $prefix = 'PRIX-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM prix WHERE code_prix LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}
// --- Récupération des listes ---
$produits = $pdo->query("SELECT code_produit, titre_produit FROM produit ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
// Récupérer tous les lots
$lots = $pdo->query("SELECT code_lot_produit, produit_id, titre_lot, unites_par_lot FROM lot_produit WHERE etat_lot = 'Actif' ORDER BY titre_lot")->fetchAll(PDO::FETCH_ASSOC);
$titres_prix = ['DETAILS', 'DEMI-GROS', 'GROS'];
$etats_prix = ['Actif', 'Inactif'];
// --- Traitement des actions POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF (sauf pour actions AJAX en lecture seule)
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        if (!isset($_POST['ajax'])) {
            $message = 'Token de sécurité invalide.';
            $messageType = 'danger';
        }
    } else {
        if ($action === 'add' || $action === 'edit') {
            $code = trim($_POST['code_prix'] ?? '');
            $produit_id = trim($_POST['produit_id'] ?? '');
            $boutique_id = trim($_POST['boutique_id'] ?? '') ?: null;
            $lot_id = trim($_POST['lot_id'] ?? '') ?: null;
            $titre = trim($_POST['titre_prix'] ?? '');
            $qte_min = (int)($_POST['quantite_min'] ?? 1);
            $qte_max = trim($_POST['quantite_max'] ?? '');
            $qte_max = ($qte_max === '') ? null : (int)$qte_max;
            $prix_unitaire = (float)str_replace(',', '.', $_POST['prix_unitaire'] ?? 0);
            $etat = trim($_POST['etat_prix'] ?? 'Actif');
            $errors = [];
            if (empty($code)) $errors[] = 'Le code prix est requis.';
            if (empty($produit_id)) $errors[] = 'Le produit est requis.';
            if (empty($titre)) $errors[] = 'Le type de prix est requis.';
            if ($qte_min < 1) $errors[] = 'La quantité minimum doit être au moins 1.';
            if ($qte_max !== null && $qte_max < $qte_min) $errors[] = 'La quantité maximum doit être supérieure ou égale à la quantité minimum.';
            if ($prix_unitaire <= 0) $errors[] = 'Le prix unitaire doit être supérieur à 0.';
            if (empty($errors)) {
                try {
                    if ($action === 'add') {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM prix WHERE code_prix = ?");
                        $stmt->execute([$code]);
                        if ($stmt->fetchColumn() > 0) {
                            $message = "Ce code prix existe déjà.";
                            $messageType = 'warning';
                        } else {
                            $sql = "INSERT INTO prix (code_prix, produit_id, boutique_id, lot_id, titre_prix, quantite_min, quantite_max, prix_unitaire, etat_prix)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$code, $produit_id, $boutique_id, $lot_id, $titre, $qte_min, $qte_max, $prix_unitaire, $etat]);
                            $message = "Prix « $code » ajouté avec succès.";
                            $messageType = 'success';
                        }
                    } elseif ($action === 'edit') {
                        $oldCode = $_POST['old_code'] ?? $code;
                        $sql = "UPDATE prix SET code_prix=?, produit_id=?, boutique_id=?, lot_id=?, titre_prix=?, quantite_min=?, quantite_max=?, prix_unitaire=?, etat_prix=?
                                WHERE code_prix = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$code, $produit_id, $boutique_id, $lot_id, $titre, $qte_min, $qte_max, $prix_unitaire, $etat, $oldCode]);
                        $message = "Prix « $code » mis à jour.";
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
        if ($action === 'delete' && isset($_POST['btn_supprimer']) && $_POST['btn_supprimer'] == '1') {
            $code = $_POST['sai_supprimer_id'] ?? '';
            if (!empty($code)) {
                try {
                    $stmt = $pdo->prepare("SELECT code_prix FROM prix WHERE code_prix = ?");
                    $stmt->execute([$code]);
                    $num = $stmt->fetchColumn();
                    $stmt = $pdo->prepare("DELETE FROM prix WHERE code_prix = ?");
                    $stmt->execute([$code]);
                    $message = "Prix « $num » supprimé.";
                    $messageType = 'danger';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}
// Générer token CSRF
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];
// --- Fonction pour le contenu du tableau (AJAX et initial) ---
function getTableContent($pdo, $search, $filtres, $page, $perPage = 20)
{
    $sql = "SELECT p.*, pr.titre_produit, b.nom_boutique, lp.titre_lot AS lot_libelle
            FROM prix p
            LEFT JOIN produit pr ON p.produit_id = pr.code_produit
            LEFT JOIN boutique b ON p.boutique_id = b.code_boutique
            LEFT JOIN lot_produit lp ON p.lot_id = lp.code_lot_produit
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (p.code_prix LIKE ? OR pr.titre_produit LIKE ? OR b.nom_boutique LIKE ? OR lp.titre_lot LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($filtres['produit'])) {
        $sql .= " AND p.produit_id = ?";
        $params[] = $filtres['produit'];
    }
    if (!empty($filtres['boutique'])) {
        $sql .= " AND p.boutique_id = ?";
        $params[] = $filtres['boutique'];
    }
    if (!empty($filtres['titre_prix'])) {
        $sql .= " AND p.titre_prix = ?";
        $params[] = $filtres['titre_prix'];
    }
    if (!empty($filtres['etat_prix'])) {
        $sql .= " AND p.etat_prix = ?";
        $params[] = $filtres['etat_prix'];
    }
    $countSql = str_replace("SELECT p.*, pr.titre_produit, b.nom_boutique, lp.titre_lot AS lot_libelle", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    if ($page < 1) $page = 1;
    $sql .= " ORDER BY p.code_prix LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $prixs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ob_start();
    if (empty($prixs)): ?>
        <tr>
            <td colspan="8" class="text-center py-5 text-muted">
                <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>
                Aucun prix trouvé
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($prixs as $row):
            $fourchette = $row['quantite_min'];
            if ($row['quantite_max'] !== null) $fourchette .= ' - ' . $row['quantite_max'];
            else $fourchette .= ' +';
            $lotLabel = ($row['lot_id'] === null) ? 'Unité de base' : ($row['lot_libelle'] ?? '—');
            ?>
            <tr>
                <td class="td-bold"><?= e($row['code_prix']) ?></td>
                <td><?= e($row['titre_produit'] ?? $row['produit_id']) ?></td>
                <td><?= e($row['nom_boutique'] ?? 'Global') ?></td>
                <td><?= e($lotLabel) ?></td>
                <td><?= e($row['titre_prix']) ?></td>
                <td><?= $fourchette ?></td>
                <td><?= number_format((float)$row['prix_unitaire'], 2) ?></td>
                <td>
                    <span class="status-badge <?= $row['etat_prix'] === 'Actif' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= e($row['etat_prix']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <!-- Bouton "Voir" supprimé -->
                        <button class="act-btn e editBtn" data-code="<?= e($row['code_prix']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= e($row['code_prix']) ?>" data-nom="<?= e($row['code_prix']) ?>" title="Supprimer"><i class="bi bi-trash3"></i></button>
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
// --- AJAX pour le tableau ---
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $filtres = [
        'produit'    => trim($_POST['produit'] ?? ''),
        'boutique'   => trim($_POST['boutique'] ?? ''),
        'titre_prix' => trim($_POST['titre_prix'] ?? ''),
        'etat_prix'  => trim($_POST['etat_prix'] ?? '')
    ];
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $filtres, $page);
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$filtres = [
    'produit'    => trim($_POST['produit'] ?? ''),
    'boutique'   => trim($_POST['boutique'] ?? ''),
    'titre_prix' => trim($_POST['titre_prix'] ?? ''),
    'etat_prix'  => trim($_POST['etat_prix'] ?? '')
];
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $filtres, $page);
$editPrix = null;
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM prix WHERE code_prix = ?");
    $stmt->execute([$code]);
    $editPrix = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des prix</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
    <style>
        /* === STYLES === */
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
            --suc: #10b981;
            --sucl: #ecfdf5;
            --wrn: #f59e0b;
            --wrnl: #fffbeb;
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
            display: flex; align-items: flex-end; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; margin-bottom: 20px;
        }
        .hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--dk); letter-spacing: -0.02em; }
        .hdr-l p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
        .hdr-r { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .hdr-badge {
            background: var(--bl); border: 1px solid var(--bb); color: var(--b);
            padding: 8px 14px; border-radius: var(--Rs); font-size: 12px; font-weight: 700;
            display: flex; align-items: center; gap: 6px;
        }
        .pbar {
            background: var(--w); border: 1px solid var(--brd); border-radius: var(--R);
            padding: 16px 20px; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .prow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .prow label {
            font-size: 11px; font-weight: 600; color: var(--mt);
            letter-spacing: .03em; text-transform: uppercase;
        }
        .prow input, .prow select {
            padding: 7px 10px; border: 1.5px solid var(--brd); border-radius: 8px;
            font-size: 13px; font-weight: 500; color: var(--dk); background: var(--bg);
            font-family: 'Inter', sans-serif; transition: all .2s;
        }
        .prow input:focus, .prow select:focus {
            border-color: var(--b); background: #fff; box-shadow: 0 0 0 3px var(--bl); outline: none;
        }
        .prow select {
            appearance: none; padding-right: 32px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .btn-go {
            background: var(--b); color: #fff; padding: 7px 16px; border-radius: 8px;
            font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 5px;
            box-shadow: 0 2px 4px rgba(37,99,235,.2); transition: background .15s; border: none; cursor: pointer;
        }
        .btn-go:hover { background: var(--bd); }
        .btn-go-outline {
            background: transparent; color: var(--mt); border: 1.5px solid var(--brd);
            padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 600;
            transition: all .2s; cursor: pointer;
        }
        .btn-go-outline:hover { background: var(--bg); border-color: var(--lt); }
        .data-table-wrap {
            background: var(--w); border: 1px solid var(--brd); border-radius: var(--R);
            overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .table > :not(caption) > * > * { padding: 12px 18px; }
        .table thead th {
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.8px; color: var(--lt); background: var(--bg); border-bottom: 1px solid var(--brd);
        }
        .table tbody tr { border-bottom: 1px solid var(--brd); transition: background .2s; }
        .table tbody tr:hover { background: var(--bl); }
        .table tbody td { vertical-align: middle; color: var(--dk); font-size: 0.85rem; }
        .td-bold { color: var(--dk) !important; font-weight: 700; }
        .status-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 14px;
            border-radius: 999px; font-size: 0.73rem; font-weight: 700; text-transform: capitalize;
        }
        .status-badge .sdot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .status-badge.on { background: var(--sucl); color: #059669; }
        .status-badge.off { background: var(--dngl); color: #dc2626; }
        .act-btn {
            width: 34px; height: 34px; border-radius: 6px; border: 1px solid transparent;
            background: transparent; color: var(--lt); display: inline-flex; align-items: center;
            justify-content: center; transition: all .2s;
        }
        .act-btn:hover { transform: scale(1.1); }
        .act-btn.e:hover { color: var(--wrn); background: var(--wrnl); border-color: rgba(245,158,11,.15); }
        .act-btn.d:hover { color: var(--dng); background: var(--dngl); border-color: rgba(239,68,68,.15); }
        .pagination .page-link {
            color: var(--b); border: 1px solid var(--brd); border-radius: 6px;
            margin: 0 2px; padding: 6px 14px; font-weight: 500;
        }
        .pagination .page-link:hover { background: var(--bl); border-color: var(--b); }
        .pagination .page-item.active .page-link { background: var(--b); border-color: var(--b); color: #fff; }
        .pagination .page-item.disabled .page-link { color: var(--lt); border-color: var(--brd); }
        .modal-content { border-radius: var(--R); border: none; box-shadow: 0 12px 40px rgba(15,23,42,.08); }
        .modal-header { border-bottom: 1px solid var(--brd); background: var(--bg); }
        .modal-footer { border-top: 1px solid var(--brd); background: var(--bg); }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        .data-table-wrap { animation: fadeUp .4s ease both; }
        @media (max-width:700px) {
            body { padding: 14px; }
            .hdr { flex-direction: column; align-items: flex-start; }
            .prow { flex-direction: column; align-items: stretch; }
            .prow .btn-go { width: 100%; justify-content: center; }
        }
        .bootstrap-select .dropdown-toggle .filter-option { color: var(--dk); }
        .bootstrap-select .dropdown-menu { border-radius: var(--Rs); border-color: var(--brd); }
        .bootstrap-select .dropdown-menu .bs-searchbox input {
            border-radius: 6px; border: 1px solid var(--brd); padding: 8px 12px;
        }
        .bootstrap-select .dropdown-menu .bs-searchbox input:focus {
            border-color: var(--b); box-shadow: 0 0 0 3px var(--bl);
        }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1>Gestion des prix</h1>
            <p>Définissez les paliers de prix par produit, boutique et conditionnement</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-tags"></i> <?= $initialData['total'] ?> prix</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau prix</button>
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
                <input type="text" name="search" id="searchInput" placeholder="Code, produit, boutique, lot..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                <label for="produitFilter">Produit</label>
                <select name="produit" id="produitFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Tous">
                    <option value="">Tous</option>
                    <?php foreach ($produits as $p): ?>
                        <option value="<?= e($p['code_produit']) ?>" <?= ($filtres['produit'] == $p['code_produit']) ? 'selected' : '' ?>><?= e($p['titre_produit']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="boutiqueFilter">Boutique</label>
                <select name="boutique" id="boutiqueFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Toutes">
                    <option value="">Toutes</option>
                    <?php foreach ($boutiques as $b): ?>
                        <option value="<?= e($b['code_boutique']) ?>" <?= ($filtres['boutique'] == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="titrePrixFilter">Type</label>
                <select name="titre_prix" id="titrePrixFilter" class="selectpicker">
                    <option value="">Tous</option>
                    <?php foreach ($titres_prix as $t): ?>
                        <option value="<?= e($t) ?>" <?= ($filtres['titre_prix'] == $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="etatPrixFilter">État</label>
                <select name="etat_prix" id="etatPrixFilter" class="selectpicker">
                    <option value="">Tous</option>
                    <?php foreach ($etats_prix as $e): ?>
                        <option value="<?= e($e) ?>" <?= ($filtres['etat_prix'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
            </div>
        </form>
    </div>
    <!-- Table -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des prix</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> prix - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Produit</th>
                    <th>Boutique</th>
                    <th>Lot / Unité</th>
                    <th>Type</th>
                    <th>Quantités</th>
                    <th>Prix unitaire</th>
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
<div class="modal fade" id="prixModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-tag text-primary me-2"></i> Nouveau prix</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="prixForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_code" id="oldCode" value="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <!-- Code -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="code_prix" class="form-label fw-semibold">Code prix <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="code_prix" name="code_prix" required placeholder="PRIX_001" value="<?= e($editPrix['code_prix'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="produit_id" class="form-label fw-semibold">Produit <span class="text-danger">*</span></label>
                            <select class="form-select selectpicker" id="produit_id" name="produit_id" data-live-search="true" data-live-search-placeholder="Rechercher un produit..." required>
                                <option value="">=== Choisissez ===</option>
                                <?php foreach ($produits as $p): ?>
                                    <option value="<?= e($p['code_produit']) ?>" <?= (isset($editPrix) && $editPrix['produit_id'] == $p['code_produit']) ? 'selected' : '' ?>><?= e($p['titre_produit']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <!-- Boutique, lot, type -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="boutique_id" class="form-label fw-semibold">Boutique (optionnel)</label>
                            <select class="form-select selectpicker" id="boutique_id" name="boutique_id" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
                                <option value="">Global (aucune boutique)</option>
                                <?php foreach ($boutiques as $b): ?>
                                    <option value="<?= e($b['code_boutique']) ?>" <?= (isset($editPrix) && $editPrix['boutique_id'] == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="lot_id" class="form-label fw-semibold">Lot / Unité</label>
                            <select class="form-select" id="lot_id" name="lot_id">
                                <option value="">Unité de base</option>
                            </select>
                            <div class="form-text small text-muted">Les lots se chargent automatiquement après sélection du produit.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="titre_prix" class="form-label fw-semibold">Type de prix <span class="text-danger">*</span></label>
                            <select class="form-select" id="titre_prix" name="titre_prix" required>
                                <option value="">=== Choisissez ===</option>
                                <?php foreach ($titres_prix as $t): ?>
                                    <option value="<?= e($t) ?>" <?= (isset($editPrix) && $editPrix['titre_prix'] == $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <!-- Paliers -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="quantite_min" class="form-label fw-semibold">Quantité minimum <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="quantite_min" name="quantite_min" min="1" required value="<?= e($editPrix['quantite_min'] ?? 1) ?>">
                            <div class="form-text small text-muted">Nombre de lots (ou unités) minimum.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="quantite_max" class="form-label fw-semibold">Quantité maximum</label>
                            <input type="number" class="form-control" id="quantite_max" name="quantite_max" min="1" placeholder="Illimité" value="<?= e($editPrix['quantite_max'] ?? '') ?>">
                            <div class="form-text small text-muted">Laissez vide pour illimité.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="prix_unitaire" class="form-label fw-semibold">Prix unitaire <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" id="prix_unitaire" name="prix_unitaire" placeholder="0.00" required value="<?= e($editPrix['prix_unitaire'] ?? '0') ?>">
                            <div class="form-text small text-muted">Prix par lot (si lot choisi) ou par unité.</div>
                        </div>
                    </div>
                    <!-- État -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="etat_prix" class="form-label fw-semibold">État</label>
                            <select class="form-select" id="etat_prix" name="etat_prix">
                                <?php foreach ($etats_prix as $e): ?>
                                    <option value="<?= e($e) ?>" <?= (isset($editPrix) && $editPrix['etat_prix'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
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
                <p class="text-danger mb-4">Supprimer le prix <strong id="deleteNomPrix"></strong> ?<br>Cette action est irréversible.</p>
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
<!-- ===== SCRIPTS ===== -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function() {
    // Initialisation des selectpicker
    $('.selectpicker').selectpicker('destroy').selectpicker();
    // ---- PRÉCHARGEMENT DES LOTS EN JAVASCRIPT ----
    var allLots = <?= json_encode($lots) ?>;
    // Fonction pour filtrer et mettre à jour le select natif des lots
    function updateLotSelect(produitId, selectedLotId) {
        var lotSelect = $('#lot_id');
        lotSelect.empty();
        lotSelect.append('<option value="">Unité de base</option>');
        if (produitId) {
            var lotsFiltres = allLots.filter(function(lot) {
                return String(lot.produit_id) === String(produitId);
            });
            lotsFiltres.forEach(function(lot) {
                lotSelect.append('<option value="' + lot.code_lot_produit + '">' + lot.titre_lot + ' (' + lot.unites_par_lot + ' unités)</option>');
            });
        }
        lotSelect.val(selectedLotId || '');
    }
    $('#produit_id').on('change', function() {
        var produitId = $(this).val();
        updateLotSelect(produitId, null);
    });
    // --- Ajout ---
    $('#addBtn').on('click', function(e) {
        e.preventDefault();
        $('#formAction').val('add');
        $('#oldCode').val('');
        $('#modalTitle').html('<i class="bi bi-tag text-primary me-2"></i> Nouveau prix');
        $('#prixForm')[0].reset();
        $('#code_prix').prop('readonly', false);
        $('#quantite_min').val(1);
        $('#quantite_max').val('');
        $('#prix_unitaire').val('0');
        $('#titre_prix').val('');
        $('#etat_prix').val('Actif');
        $('#produit_id, #boutique_id').selectpicker('val', '');
        updateLotSelect('', null);
        $('.selectpicker').selectpicker('refresh');
        new bootstrap.Modal(document.getElementById('prixModal')).show();
    });
    // --- Édition (via POST) ---
    $(document).on('click', '.editBtn', function(e) {
        e.preventDefault();
        $('#actionField').val('load_edit');
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
                $('#totalCount').text(data.total + ' prix - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
                $('.page-link').off('click').on('click', function(e) {
                    e.preventDefault();
                    var p = $(this).data('page');
                    if (p) rechercher(p);
                });
                $('.selectpicker').selectpicker('refresh');
            },
            error: function() {
                alert('Erreur lors de la recherche.');
            }
        });
    }
    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#produitFilter, #boutiqueFilter, #titrePrixFilter, #etatPrixFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });
    $('#filterBtn').on('click', function() { rechercher(1); });
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#produitFilter, #boutiqueFilter, #titrePrixFilter, #etatPrixFilter').selectpicker('val', '');
        rechercher(1);
    });
    $('.page-link').on('click', function(e) {
        e.preventDefault();
        var p = $(this).data('page');
        if (p) rechercher(p);
    });
    // --- Suppression ---
    $(document).on('click', '.deleteBtn', function(e) {
        e.preventDefault();
        $('#deleteNomPrix').text($(this).data('nom'));
        $('#deleteFormId').val($(this).data('code'));
        new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
    });
    $('#confirmDeleteBtn').on('click', function() { $('#deleteForm').submit(); });
    // Auto-fermeture alertes
    setTimeout(function() { $('.alert').alert('close'); }, 5000);
    // --- Édition via POST (chargement et pré-sélection) ---
    <?php if (isset($editPrix) && $action === 'load_edit'): ?>
    $(function() {
        $('#formAction').val('edit');
        $('#oldCode').val('<?= e($editPrix['code_prix']) ?>');
        $('#modalTitle').html('<i class="bi bi-tag text-primary me-2"></i> Modifier le prix');
        $('#code_prix').prop('readonly', true);
        var produitId = '<?= e($editPrix['produit_id']) ?>';
        var lotId = '<?= e($editPrix['lot_id']) ?>';
        $('#produit_id').selectpicker('val', produitId);
        $('#boutique_id').selectpicker('val', '<?= e($editPrix['boutique_id']) ?>');
        updateLotSelect(produitId, lotId);
        $('#titre_prix').val('<?= e($editPrix['titre_prix']) ?>');
        $('#quantite_min').val('<?= e($editPrix['quantite_min']) ?>');
        $('#quantite_max').val('<?= e($editPrix['quantite_max']) ?>');
        $('#prix_unitaire').val('<?= e($editPrix['prix_unitaire']) ?>');
        $('#etat_prix').val('<?= e($editPrix['etat_prix']) ?>');
        $('.selectpicker').selectpicker('refresh');
        new bootstrap.Modal(document.getElementById('prixModal')).show();
    });
    <?php endif; ?>
});
</script>
</body>
</html>