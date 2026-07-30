<?php
// produit.php – CRUD produit (avec gestion du stock_produit)
// Design aligné sur vente.php
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

// --- Récupération des catégories ---
$categories = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie ORDER BY titre_categorie")->fetchAll(PDO::FETCH_ASSOC);

// --- Boutiques actives (pour le stock initial) ---
$boutiquesActives = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$boutiquePrincipale = $boutiquesActives[0]['code_boutique'] ?? null;

// --- Traitement POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !(isset($_POST['ajax']) && $_POST['ajax'] == '1')) {
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {
        // CRUD add/edit
        if ($action === 'add' || $action === 'edit') {
            $code = trim($_POST['code_produit'] ?? '');
            $titre = trim($_POST['titre_produit'] ?? '');
            $prix_fournisseur = trim($_POST['prix_fournisseur'] ?? '');
            $prix_produit = trim($_POST['prix_produit'] ?? '');
            $stock_alerte = trim($_POST['stock_alerte'] ?? '10');
            $stock_initial = trim($_POST['stock_initial'] ?? '0');
            $boutique_initiale = trim($_POST['boutique_initiale'] ?? '');
            $categorie_id = trim($_POST['categorie_id'] ?? '');
            $description = trim($_POST['description_produit'] ?? '');
            $etat = trim($_POST['etat_produit'] ?? 'Actif');
            $stock_produit = trim($_POST['stock_produit'] ?? 0); // NOUVEAU

            $benefice = 0;
            if (is_numeric($prix_fournisseur) && is_numeric($prix_produit)) {
                $benefice = $prix_produit - $prix_fournisseur;
            }

            $photo = null;
            $type_photo = null;
            if (isset($_FILES['photo_produit']) && $_FILES['photo_produit']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['photo_produit']['tmp_name'];
                $fileType = $_FILES['photo_produit']['type'];
                $photo = file_get_contents($fileTmpPath);
                $type_photo = $fileType;
            }

            $errors = [];
            if (empty($code)) $errors[] = 'Le code produit est requis.';

            if (empty($errors)) {
                try {
                    if ($action === 'add') {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM produit WHERE code_produit = ?");
                        $stmt->execute([$code]);
                        if ($stmt->fetchColumn() > 0) {
                            $message = "Ce code produit existe déjà.";
                            $messageType = 'warning';
                        } else {
                            $sql = "INSERT INTO produit (code_produit, titre_produit, prix_fournisseur, prix_produit, benefice_produit, stock_alerte, categorie_id, description_produit, photo, type_photo, etat_produit, stock_produit)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$code, $titre, $prix_fournisseur, $prix_produit, $benefice, $stock_alerte, $categorie_id, $description, $photo, $type_photo, $etat, $stock_produit]);

                            // Initialisation du stock dans toutes les boutiques
                            $insSb = $pdo->prepare("INSERT INTO stock_boutique (produit_id, boutique_id, quantite, stock_alerte) VALUES (?, ?, 0, ?)");
                            foreach ($boutiquesActives as $b) {
                                $insSb->execute([$code, $b['code_boutique'], $stock_alerte]);
                            }

                            // Si stock initial > 0, on crée un mouvement d'entrée (statut 006 = ENTREE_INVENTAIRE)
                            if (is_numeric($stock_initial) && (float) $stock_initial > 0 && !empty($boutique_initiale)) {
                                require_once 'databases/stock_functions.php';
                                enregistrerMouvementStock(
                                    $pdo,
                                    $code,
                                    $boutique_initiale,
                                    '006',
                                    (int) $stock_initial,
                                    (float) ($prix_fournisseur ?: 0),
                                    null,
                                    $_SESSION['user_id'] ?? '1',
                                    'Stock initial à la création du produit'
                                );
                                // Mettre à jour stock_produit en ajoutant la quantité initiale
                                $pdo->prepare("UPDATE produit SET stock_produit = stock_produit + ? WHERE code_produit = ?")
                                    ->execute([(int) $stock_initial, $code]);
                            }

                            $message = "Produit « $titre » ajouté avec succès.";
                            $messageType = 'success';
                        }
                    } elseif ($action === 'edit') {
                        $oldCode = $_POST['old_code'] ?? $code;
                        if ($photo !== null) {
                            $sql = "UPDATE produit SET code_produit=?, titre_produit=?, prix_fournisseur=?, prix_produit=?, benefice_produit=?, stock_alerte=?, categorie_id=?, description_produit=?, photo=?, type_photo=?, etat_produit=?, stock_produit=?
                                    WHERE code_produit = ?";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$code, $titre, $prix_fournisseur, $prix_produit, $benefice, $stock_alerte, $categorie_id, $description, $photo, $type_photo, $etat, $stock_produit, $oldCode]);
                        } else {
                            $sql = "UPDATE produit SET code_produit=?, titre_produit=?, prix_fournisseur=?, prix_produit=?, benefice_produit=?, stock_alerte=?, categorie_id=?, description_produit=?, etat_produit=?, stock_produit=?
                                    WHERE code_produit = ?";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$code, $titre, $prix_fournisseur, $prix_produit, $benefice, $stock_alerte, $categorie_id, $description, $etat, $stock_produit, $oldCode]);
                        }
                        if ($oldCode !== $code) {
                            $pdo->prepare("UPDATE stock_boutique SET produit_id = ? WHERE produit_id = ?")->execute([$code, $oldCode]);
                        }
                        $message = "Produit « $titre » mis à jour.";
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
            $code = $_POST['sai_supprimer_id'] ?? '';
            if (!empty($code)) {
                try {
                    $stmt = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
                    $stmt->execute([$code]);
                    $titre = $stmt->fetchColumn();
                    $stmt = $pdo->prepare("DELETE FROM produit WHERE code_produit = ?");
                    $stmt->execute([$code]);
                    $message = "Produit « $titre » supprimé.";
                    $messageType = 'danger';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// --- Fonction table (AJAX) ---
function getTableContent($pdo, $search, $categorie_filter, $page, $perPage = 20)
{
    $where = "WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $where .= " AND (p.code_produit LIKE ? OR p.titre_produit LIKE ? OR p.description_produit LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($categorie_filter)) {
        $where .= " AND p.categorie_id = ?";
        $params[] = $categorie_filter;
    }

    $countSql = "SELECT COUNT(*) FROM produit p $where";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    // On récupère directement stock_produit (plus de jointure avec stock_boutique)
    $sql = "SELECT p.*
            FROM produit p
            $where
            ORDER BY p.code_produit LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($produits)): ?>
        <tr>
            <td colspan="11" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                Aucun produit trouvé
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($produits as $p): ?>
            <tr>
                <td class="td-bold"><?= htmlspecialchars($p['code_produit']) ?></td>
                <td class="td-semi"><?= htmlspecialchars($p['titre_produit']) ?></td>
                <td>
                    <?php if (!empty($p['photo'])): ?>
                        <img src="data:<?= htmlspecialchars($p['type_photo'] ?? 'image/jpeg') ?>;base64,<?= base64_encode($p['photo']) ?>"
                            alt="<?= htmlspecialchars($p['titre_produit']) ?>"
                            style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; border: 1px solid var(--brd);">
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($p['prix_fournisseur']) ?></td>
                <td><?= htmlspecialchars($p['prix_produit']) ?></td>
                <td><?= htmlspecialchars($p['benefice_produit']) ?></td>
                <td><?= htmlspecialchars($p['stock_alerte']) ?></td>
                <td>
                    <?php $enAlerte = (int) $p['stock_produit'] <= (int) $p['stock_alerte']; ?>
                    <span class="<?= $enAlerte ? 'text-danger fw-bold' : '' ?>"><?= (int) $p['stock_produit'] ?></span>
                </td>
                <td><?= htmlspecialchars($p['categorie_id']) ?></td>
                <td>
                    <span class="status-badge <?= $p['etat_produit'] === 'Actif' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= htmlspecialchars($p['etat_produit']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn e editBtn" data-code="<?= htmlspecialchars($p['code_produit']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= htmlspecialchars($p['code_produit']) ?>" data-nom="<?= htmlspecialchars($p['titre_produit']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash"></i></button>
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

// --- AJAX ---
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $categorie_filter = trim($_POST['categorie_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $categorie_filter, $page);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$categorie_filter = trim($_POST['categorie_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $categorie_filter, $page);

function getStockTotal(PDO $pdo, string $produit_id): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantite), 0) FROM stock_boutique WHERE produit_id = ?");
    $stmt->execute([$produit_id]);
    return (int) $stmt->fetchColumn();
}

$editProduit = null;
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM produit WHERE code_produit = ?");
    $stmt->execute([$code]);
    $editProduit = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editProduit) {
        $editProduit['stock_total'] = getStockTotal($pdo, $code);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des produits</title>
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
            <h1>Gestion des produits</h1>
            <p>Créer, modifier et suivre votre catalogue</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-cube"></i> <?= $initialData['total'] ?? 0 ?> produits</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau produit</button>
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
                <input type="text" name="search" id="searchInput" placeholder="Code, titre, description..." value="<?= htmlspecialchars($search) ?>" style="flex:1; min-width:150px;">
                <label for="categorieFilter">Catégorie</label>
                <select name="categorie_filter" id="categorieFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher une catégorie...">
                    <option value="">Toutes</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['code_categorie']) ?>" <?= ($categorie_filter == $cat['code_categorie']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['titre_categorie']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <!-- Tableau -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des produits</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> produit(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Titre</th>
                        <th>Photo</th>
                        <th>Prix fourn.</th>
                        <th>Prix vente</th>
                        <th>Bénéfice</th>
                        <th>Stock alerte</th>
                        <th>Stock (produit)</th>   <!-- Colonne modifiée -->
                        <th>Catégorie</th>
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
<div class="modal fade" id="produitModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-cube text-primary me-2"></i> Nouveau produit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form method="post" id="produitForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_code" id="oldCode" value="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="modal-body">
                    <!-- Identification -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-tag me-1"></i> Identification</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="code_produit" class="form-label fw-semibold">Code produit <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="code_produit" name="code_produit" required placeholder="P001" value="<?= htmlspecialchars($editProduit['code_produit'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="titre_produit" class="form-label fw-semibold">Titre du produit</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-cube"></i></span>
                                <input type="text" class="form-control" id="titre_produit" name="titre_produit" placeholder="Nom du produit" value="<?= htmlspecialchars($editProduit['titre_produit'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Prix -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-coins me-1"></i> Prix et bénéfice</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label for="prix_fournisseur" class="form-label fw-semibold">Prix fournisseur</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-currency-dollar"></i></span>
                                <input type="text" class="form-control" id="prix_fournisseur" name="prix_fournisseur" placeholder="0.00" value="<?= htmlspecialchars($editProduit['prix_fournisseur'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="prix_produit" class="form-label fw-semibold">Prix de vente</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-tag"></i></span>
                                <input type="text" class="form-control" id="prix_produit" name="prix_produit" placeholder="0.00" value="<?= htmlspecialchars($editProduit['prix_produit'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Bénéfice estimé</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-graph-up-arrow"></i></span>
                                <input type="text" class="form-control" id="benefice_estime" readonly placeholder="calculé automatiquement" value="<?= isset($editProduit['benefice_produit']) ? $editProduit['benefice_produit'] : '' ?>">
                            </div>
                            <div class="form-text">Calculé automatiquement (vente - fournisseur)</div>
                        </div>
                    </div>

                    <!-- Gestion de stock -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-boxes me-1"></i> Gestion de stock</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="stock_alerte" class="form-label fw-semibold">Stock d'alerte</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-exclamation-triangle"></i></span>
                                <input type="text" class="form-control" id="stock_alerte" name="stock_alerte" placeholder="10" value="<?= htmlspecialchars($editProduit['stock_alerte'] ?? '10') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="stock_produit" class="form-label fw-semibold">Stock total (produit)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-cubes"></i></span>
                                <input type="number" class="form-control" id="stock_produit" name="stock_produit" step="1" min="0" value="<?= htmlspecialchars($editProduit['stock_produit'] ?? 0) ?>">
                            </div>
                            <div class="form-text">Stock global du produit (mis à jour automatiquement lors des mouvements).</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4" id="blocStockInitial">
                        <div class="col-md-6">
                            <label for="stock_initial" class="form-label fw-semibold">Quantité initiale (à la création)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-cubes"></i></span>
                                <input type="text" class="form-control" id="stock_initial" name="stock_initial" placeholder="0">
                            </div>
                            <div class="form-text">Génère un mouvement ENTREE_INVENTAIRE à la création et ajoute au stock_produit.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="boutique_initiale" class="form-label fw-semibold">Boutique</label>
                            <select class="selectpicker form-control" id="boutique_initiale" name="boutique_initiale" data-live-search="true">
                                <?php foreach ($boutiquesActives as $b): ?>
                                    <option value="<?= htmlspecialchars($b['code_boutique']) ?>" <?= ($b['code_boutique'] === $boutiquePrincipale) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['nom_boutique']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Catégorie -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-list me-1"></i> Catégorie</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="categorie_id" class="form-label fw-semibold">Catégorie</label>
                            <select class="selectpicker form-control" id="categorie_id" name="categorie_id" data-live-search="true" data-live-search-placeholder="Rechercher une catégorie...">
                                <option value="">=== Faites votre choix ===</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['code_categorie']) ?>" <?= (isset($editProduit) && $editProduit['categorie_id'] == $cat['code_categorie']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['titre_categorie']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="description_produit" class="form-label fw-semibold">Description</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-text-left"></i></span>
                                <textarea class="form-control" id="description_produit" name="description_produit" rows="3" placeholder="Description détaillée..."><?= htmlspecialchars($editProduit['description_produit'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Photo -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-image me-1"></i> Photo</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <input type="file" class="form-control" id="photo_produit" name="photo_produit" accept="image/*">
                            <div id="photoPreviewContainer" class="mt-2">
                                <?php if (isset($editProduit) && !empty($editProduit['photo'])): ?>
                                    <img src="data:<?= htmlspecialchars($editProduit['type_photo'] ?? 'image/jpeg') ?>;base64,<?= base64_encode($editProduit['photo']) ?>" class="preview-img" alt="Aperçu">
                                <?php else: ?>
                                    <div class="img-placeholder"><i class="bi bi-image fs-1"></i></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- État -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-toggle-on me-1"></i> Statut</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="etat_produit" class="form-label fw-semibold">État</label>
                            <select class="form-select" id="etat_produit" name="etat_produit">
                                <option value="Actif" <?= (isset($editProduit) && $editProduit['etat_produit'] === 'Actif') ? 'selected' : '' ?>>Actif</option>
                                <option value="Inactif" <?= (isset($editProduit) && $editProduit['etat_produit'] === 'Inactif') ? 'selected' : '' ?>>Inactif</option>
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
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer le produit <strong id="deleteNomProduit"></strong> ?<br>Cette action est irréversible.</p>
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
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
</form>

<form method="post" id="actionForm">
    <input type="hidden" name="action" id="actionField">
    <input type="hidden" name="edit_code" id="editCodeField">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
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
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    const produitModal = new bootstrap.Modal(document.getElementById('produitModal'));

    $('#addBtn').on('click', function() {
        $('#formAction').val('add');
        $('#oldCode').val('');
        $('#modalTitle').text('Nouveau produit');
        $('#produitForm')[0].reset();
        $('#code_produit').prop('readonly', false);
        $('#benefice_estime').val('');
        $('#photoPreviewContainer').html('<div class="img-placeholder"><i class="bi bi-image fs-1"></i></div>');

        $('#code_produit').val('');
        $('#titre_produit').val('');
        $('#prix_fournisseur').val('');
        $('#prix_produit').val('');
        $('#stock_alerte').val('10');
        $('#stock_produit').val(0);
        $('#stock_initial').val('');
        $('#description_produit').val('');
        $('#etat_produit').val('Actif');

        $('#blocStockInitial').show();
        // On n'affiche pas de blocStockTotal car on modifie directement stock_produit

        $('#categorie_id').selectpicker('val', '');
        $('#boutique_initiale').selectpicker('destroy').selectpicker();
        produitModal.show();
    });

    $(document).on('click', '.editBtn', function() {
        const code = $(this).data('code');
        $('#actionField').val('load_edit');
        $('#editCodeField').val(code);
        $('#actionForm').submit();
    });

    function calculBenefice() {
        const four = parseFloat($('#prix_fournisseur').val()) || 0;
        const vente = parseFloat($('#prix_produit').val()) || 0;
        $('#benefice_estime').val((vente - four).toFixed(2));
    }
    $('#prix_fournisseur, #prix_produit').on('input', calculBenefice);

    $('#photo_produit').on('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#photoPreviewContainer').html('<img src="' + e.target.result + '" class="preview-img" alt="Aperçu">');
            };
            reader.readAsDataURL(file);
        } else {
            $('#photoPreviewContainer').html('<div class="img-placeholder"><i class="bi bi-image fs-1"></i></div>');
        }
    });

    // =========================================================
    // FONCTION DE RECHERCHE AJAX
    // =========================================================
    function rechercher(page) {
        page = page || 1;
        var search = $('#searchInput').val();
        var categorie = $('#categorieFilter').val();
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                ajax: 1,
                search: search,
                categorie_filter: categorie,
                page: page
            },
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' produit(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
            },
            error: function() {
                alert('Erreur lors de la recherche.');
            }
        });
    }

    // =========================================================
    // GESTION DE LA PAGINATION AVEC DÉLÉGATION D'ÉVÉNEMENTS
    // =========================================================
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        var p = $(this).data('page');
        if (p) rechercher(p);
    });

    // =========================================================
    // ÉVÉNEMENTS SUR LES CHAMPS DE RECHERCHE/FILTRES
    // =========================================================
    var searchTimeout = null;
    $('#searchInput').on('input', function() {
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
        $('#categorieFilter').selectpicker('val', '');
        rechercher(1);
    });

    // =========================================================
    // SUPPRESSION
    // =========================================================
    $(document).on('click', '.deleteBtn', function() {
        const code = $(this).data('code');
        const nom = $(this).data('nom');
        $('#deleteNomProduit').text(nom);
        $('#deleteFormId').val(code);
    });

    $('#confirmDeleteBtn').on('click', function() {
        $('#deleteForm').submit();
    });

    setTimeout(function() { $('.alert').alert('close'); }, 5000);

    // =========================================================
    // ÉDITION (chargement du modal via POST)
    // =========================================================
    <?php if (isset($editProduit) && $action === 'load_edit'): ?>
        $(function() {
            $('#formAction').val('edit');
            $('#oldCode').val('<?= htmlspecialchars($editProduit['code_produit']) ?>');
            $('#modalTitle').text('Modifier le produit');
            $('#code_produit').prop('readonly', true);
            $('#blocStockInitial').hide();
            // Le champ stock_produit est déjà rempli
            $('#categorie_id').selectpicker('destroy').selectpicker();
            produitModal.show();
        });
    <?php endif; ?>
});
</script>
</body>
</html>