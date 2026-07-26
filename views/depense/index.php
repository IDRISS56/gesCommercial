<?php
ob_start(); // Capture toute sortie parasite (BOM, espaces, etc.)

// depense.php – Gestion des dépenses (design vente)
// CRUD pour la table depense – avec Bootstrap SelectPicker

require_once 'databases/database.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}


// --- Récupération des listes pour les selects ---
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$utilisateurs = $pdo->query("SELECT id, nom_prenom FROM utilisateur WHERE etat = 'Actif' ORDER BY nom_prenom")->fetchAll(PDO::FETCH_ASSOC);

// --- Traitement des actions POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';

if ($action === 'add' || $action === 'edit') {
    $code = trim($_POST['code_depense'] ?? '');
    $titre = trim($_POST['titre_depense'] ?? '');
    $boutique_id = trim($_POST['boutique_id'] ?? '');
    $utilisateur_id = trim($_POST['utilisateur_id'] ?? '');
    $montant = trim(str_replace(',', '.', $_POST['montant_depense'] ?? '0'));
    $date_depense = trim($_POST['date_depense'] ?? date('Y-m-d H:i:s'));
    $description = trim($_POST['description_depense'] ?? '');
    $etat = trim($_POST['etat_depense'] ?? 'VALIDE');

    $errors = [];
    if (empty($code)) $errors[] = 'Le code dépense est requis.';
    if (empty($titre)) $errors[] = 'Le titre est requis.';
    if (!is_numeric($montant) || $montant < 0) $errors[] = 'Le montant doit être un nombre positif.';

    if (empty($errors)) {
        try {
            if ($action === 'add') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM depense WHERE code_depense = ?");
                $stmt->execute([$code]);
                if ($stmt->fetchColumn() > 0) {
                    $message = "Ce code dépense existe déjà.";
                    $messageType = 'warning';
                } else {
                    $sql = "INSERT INTO depense (code_depense, titre_depense, boutique_id, utilisateur_id, montant_depense, date_depense, description_depense, etat_depense)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$code, $titre, $boutique_id ?: null, $utilisateur_id ?: null, $montant, $date_depense, $description, $etat]);
                    $message = "Dépense « $titre » ajoutée avec succès.";
                    $messageType = 'success';
                }
            } elseif ($action === 'edit') {
                $oldCode = $_POST['old_code'] ?? $code;
                $sql = "UPDATE depense SET code_depense=?, titre_depense=?, boutique_id=?, utilisateur_id=?, montant_depense=?, date_depense=?, description_depense=?, etat_depense=?
                        WHERE code_depense = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$code, $titre, $boutique_id ?: null, $utilisateur_id ?: null, $montant, $date_depense, $description, $etat, $oldCode]);
                $message = "Dépense « $titre » mise à jour.";
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
            $stmt = $pdo->prepare("SELECT titre_depense FROM depense WHERE code_depense = ?");
            $stmt->execute([$code]);
            $titre = $stmt->fetchColumn();
            $stmt = $pdo->prepare("DELETE FROM depense WHERE code_depense = ?");
            $stmt->execute([$code]);
            $message = "Dépense « $titre » supprimée.";
            $messageType = 'danger';
        } catch (PDOException $e) {
            $message = "Erreur : " . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// --- Fonction de génération du tableau (AJAX) ---
function getTableContent($pdo, $search, $boutique_filter, $utilisateur_filter, $etat_filter, $page, $perPage = 20)
{
    $sql = "SELECT d.*, b.nom_boutique, u.nom_prenom 
            FROM depense d
            LEFT JOIN boutique b ON d.boutique_id = b.code_boutique
            LEFT JOIN utilisateur u ON d.utilisateur_id = u.id
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (d.code_depense LIKE ? OR d.titre_depense LIKE ? OR d.description_depense LIKE ? OR b.nom_boutique LIKE ? OR u.nom_prenom LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 5; $i++) $params[] = $like;
    }
    if (!empty($boutique_filter)) {
        $sql .= " AND d.boutique_id = ?";
        $params[] = $boutique_filter;
    }
    if (!empty($utilisateur_filter)) {
        $sql .= " AND d.utilisateur_id = ?";
        $params[] = $utilisateur_filter;
    }
    if (!empty($etat_filter)) {
        $sql .= " AND d.etat_depense = ?";
        $params[] = $etat_filter;
    }

    $countSql = str_replace("SELECT d.*, b.nom_boutique, u.nom_prenom", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $sql .= " ORDER BY d.date_depense DESC, d.code_depense LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $depenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($depenses)): ?>
        <tr>
            <td colspan="9" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                Aucune dépense trouvée
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($depenses as $d):
            $date = new DateTime($d['date_depense']);
            $dateFormatee = $date->format('d/m/Y H:i');
        ?>
            <tr>
                <td class="td-bold"><?= htmlspecialchars($d['code_depense']) ?></td>
                <td class="td-semi"><?= htmlspecialchars($d['titre_depense']) ?></td>
                <td><?= htmlspecialchars($d['nom_boutique'] ?? '—') ?></td>
                <td><?= htmlspecialchars($d['nom_prenom'] ?? '—') ?></td>
                <td><?= number_format((float)$d['montant_depense'], 2) ?></td>
                <td><?= $dateFormatee ?></td>
                <td><?= htmlspecialchars($d['description_depense'] ?? '') ?></td>
                <td>
                    <span class="status-badge <?= $d['etat_depense'] === 'VALIDE' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= htmlspecialchars($d['etat_depense']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn e editBtn" data-code="<?= htmlspecialchars($d['code_depense']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= htmlspecialchars($d['code_depense']) ?>" data-nom="<?= htmlspecialchars($d['titre_depense']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash"></i></button>
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
        'table' => $tableHtml,
        'pagination' => $paginationHtml,
        'total' => $total,
        'page' => $page,
        'totalPages' => $totalPages
    ];
}

// --- AJAX ---
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    // Nettoyer le buffer avant l'envoi du JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $search = trim($_POST['search'] ?? '');
    $boutique_filter = trim($_POST['boutique_filter'] ?? '');
    $utilisateur_filter = trim($_POST['utilisateur_filter'] ?? '');
    $etat_filter = trim($_POST['etat_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $boutique_filter, $utilisateur_filter, $etat_filter, $page);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$boutique_filter = trim($_POST['boutique_filter'] ?? '');
$utilisateur_filter = trim($_POST['utilisateur_filter'] ?? '');
$etat_filter = trim($_POST['etat_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $boutique_filter, $utilisateur_filter, $etat_filter, $page);

// Chargement des données pour l'édition (action load_edit)
$editDepense = null;
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM depense WHERE code_depense = ?");
    $stmt->execute([$code]);
    $editDepense = $stmt->fetch(PDO::FETCH_ASSOC);
}

$etats_depense = ['VALIDE', 'ANNULE'];
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des dépenses</title>
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
            <h1>Gestion des dépenses</h1>
            <p>Suivez toutes vos dépenses par boutique et utilisateur</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-currency-dollar"></i> <?= $initialData['total'] ?? 0 ?> dépenses</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouvelle dépense</button>
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
                <label for="boutiqueFilter">Boutique</label>
                <select name="boutique_filter" id="boutiqueFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
                    <option value="">Toutes</option>
                    <?php foreach ($boutiques as $b): ?>
                        <option value="<?= htmlspecialchars($b['code_boutique']) ?>" <?= ($boutique_filter == $b['code_boutique']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['nom_boutique']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="utilisateurFilter">Utilisateur</label>
                <select name="utilisateur_filter" id="utilisateurFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un utilisateur...">
                    <option value="">Tous</option>
                    <?php foreach ($utilisateurs as $u): ?>
                        <option value="<?= htmlspecialchars($u['id']) ?>" <?= ($utilisateur_filter == $u['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['nom_prenom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="etatFilter">État</label>
                <select name="etat_filter" id="etatFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un état...">
                    <option value="">Tous</option>
                    <?php foreach ($etats_depense as $e): ?>
                        <option value="<?= $e ?>" <?= ($etat_filter == $e) ? 'selected' : '' ?>><?= $e ?></option>
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
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des dépenses</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> dépense(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Titre</th>
                        <th>Boutique</th>
                        <th>Utilisateur</th>
                        <th>Montant</th>
                        <th>Date</th>
                        <th>Description</th>
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
<div class="modal fade" id="depenseModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-currency-dollar text-primary me-2"></i> Nouvelle dépense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form method="post" id="depenseForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_code" id="oldCode" value="">
                <div class="modal-body">
                    <!-- Identification -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-tag me-1"></i> Identification</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="code_depense" class="form-label fw-semibold">Code dépense <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="code_depense" name="code_depense" required placeholder="DEP001" value="<?= htmlspecialchars($editDepense['code_depense'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="titre_depense" class="form-label fw-semibold">Titre <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-pencil"></i></span>
                                <input type="text" class="form-control" id="titre_depense" name="titre_depense" placeholder="Achat fournitures" value="<?= htmlspecialchars($editDepense['titre_depense'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Association boutique / utilisateur -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-store me-1"></i> Association</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="boutique_id" class="form-label fw-semibold">Boutique</label>
                            <select class="selectpicker form-control" id="boutique_id" name="boutique_id" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
                                <option value="">Aucune</option>
                                <?php foreach ($boutiques as $b): ?>
                                    <option value="<?= htmlspecialchars($b['code_boutique']) ?>" <?= (isset($editDepense) && $editDepense['boutique_id'] == $b['code_boutique']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['nom_boutique']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="utilisateur_id" class="form-label fw-semibold">Utilisateur</label>
                            <select class="selectpicker form-control" id="utilisateur_id" name="utilisateur_id" data-live-search="true" data-live-search-placeholder="Rechercher un utilisateur...">
                                <option value="">Aucun</option>
                                <?php foreach ($utilisateurs as $u): ?>
                                    <option value="<?= htmlspecialchars($u['id']) ?>" <?= (isset($editDepense) && $editDepense['utilisateur_id'] == $u['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['nom_prenom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Montant et date -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-coins me-1"></i> Montant & date</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="montant_depense" class="form-label fw-semibold">Montant <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-currency-dollar"></i></span>
                                <input type="number" step="0.01" class="form-control" id="montant_depense" name="montant_depense" placeholder="0.00" value="<?= htmlspecialchars($editDepense['montant_depense'] ?? '0') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="date_depense" class="form-label fw-semibold">Date et heure</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                <input type="datetime-local" class="form-control" id="date_depense" name="date_depense" value="<?= isset($editDepense) ? date('Y-m-d\TH:i', strtotime($editDepense['date_depense'])) : date('Y-m-d\TH:i') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Description -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-align-left me-1"></i> Description</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="description_depense" class="form-label fw-semibold">Description</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-text-left"></i></span>
                                <textarea class="form-control" id="description_depense" name="description_depense" rows="3" placeholder="Détails de la dépense..."><?= htmlspecialchars($editDepense['description_depense'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- État -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-toggle-on me-1"></i> Statut</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="etat_depense" class="form-label fw-semibold">État</label>
                            <select class="form-select" id="etat_depense" name="etat_depense">
                                <option value="VALIDE" <?= (isset($editDepense) && $editDepense['etat_depense'] === 'VALIDE') ? 'selected' : '' ?>>VALIDE</option>
                                <option value="ANNULE" <?= (isset($editDepense) && $editDepense['etat_depense'] === 'ANNULE') ? 'selected' : '' ?>>ANNULE</option>
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
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer la dépense <strong id="deleteNomDepense"></strong> ?<br>Cette action est irréversible.</p>
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
    <input type="hidden" name="btn_supprimer" value="1">
    <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
</form>

<!-- Formulaire caché pour action edit (chargement) -->
<form method="post" id="actionForm">
    <input type="hidden" name="action" id="actionField">
    <input type="hidden" name="edit_code" id="editCodeField">
</form>

<!-- ========================================================= -->
<!-- SCRIPTS -->
<!-- ========================================================= -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Bootstrap SelectPicker JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

<script>
$(document).ready(function() {
    // --- Initialisation de tous les selectpicker ---
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    const depenseModal = new bootstrap.Modal(document.getElementById('depenseModal'));

    // --- Ajout ---
    $('#addBtn').on('click', function() {
        $('#formAction').val('add');
        $('#oldCode').val('');
        $('#modalTitle').text('Nouvelle dépense');
        $('#depenseForm')[0].reset();
        $('#code_depense').prop('readonly', false);

        $('#code_depense').val('');
        $('#titre_depense').val('');
        $('#montant_depense').val('0');
        $('#description_depense').val('');
        $('#etat_depense').val('VALIDE');

        var now = new Date();
        var offset = now.getTimezoneOffset() * 60000;
        var localISOTime = (new Date(Date.now() - offset)).toISOString().slice(0, 16);
        $('#date_depense').val(localISOTime);

        $('#boutique_id, #utilisateur_id').selectpicker('val', '');
        depenseModal.show();
    });

    // --- Édition (chargement via action load_edit) ---
    $(document).on('click', '.editBtn', function() {
        const code = $(this).data('code');
        $('#actionField').val('load_edit');
        $('#editCodeField').val(code);
        $('#actionForm').submit();
    });

    // --- Fonction de recherche AJAX ---
    function rechercher(page) {
        page = page || 1;
        var search = $('#searchInput').val();
        var boutique = $('#boutiqueFilter').val();
        var utilisateur = $('#utilisateurFilter').val();
        var etat = $('#etatFilter').val();
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                ajax: 1,
                search: search,
                boutique_filter: boutique,
                utilisateur_filter: utilisateur,
                etat_filter: etat,
                page: page
            },
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' dépense(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
                $('.page-link').off('click').on('click', function(e) {
                    e.preventDefault();
                    var p = $(this).data('page');
                    if (p) rechercher(p);
                });
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

    $('#boutiqueFilter, #utilisateurFilter, #etatFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });

    $('#filterBtn').on('click', function() { rechercher(1); });
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#boutiqueFilter, #utilisateurFilter, #etatFilter').selectpicker('val', '');
        rechercher(1);
    });

    // --- Gestion suppression ---
    $(document).on('click', '.deleteBtn', function() {
        const code = $(this).data('code');
        const nom = $(this).data('nom');
        $('#deleteNomDepense').text(nom);
        $('#deleteFormId').val(code);
    });
    $('#confirmDeleteBtn').on('click', function() {
        $('#deleteForm').submit();
    });

    // Auto-fermeture des alertes
    setTimeout(function() { $('.alert').alert('close'); }, 5000);

    // --- Si édition via POST (chargement des données) ---
    <?php if (isset($editDepense) && $action === 'load_edit'): ?>
        $(function() {
            $('#formAction').val('edit');
            $('#oldCode').val('<?= htmlspecialchars($editDepense['code_depense']) ?>');
            $('#modalTitle').text('Modifier la dépense');
            $('#code_depense').prop('readonly', true);
            depenseModal.show();
        });
    <?php endif; ?>
});
</script>
</body>
</html>