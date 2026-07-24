<?php
// depense.php
// CRUD pour la table depense – avec Bootstrap SelectPicker (modèle identique à contact.php)

require_once 'databases/database.php';
// --- Récupération des listes pour les selects ---
$boutiques = [];
$stmt = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique");
$boutiques = $stmt->fetchAll(PDO::FETCH_ASSOC);

$utilisateurs = [];
$stmt = $pdo->query("SELECT id, nom_prenom FROM utilisateur WHERE etat = 'Actif' ORDER BY nom_prenom");
$utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                <i class="fas fa-inbox fa-2x d-block mb-2 opacity-50"></i>
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
                        <button class="act-btn v viewBtn" data-code="<?= htmlspecialchars($d['code_depense']) ?>" title="Voir"><i class="fas fa-eye"></i></button>
                        <button class="act-btn e editBtn" data-code="<?= htmlspecialchars($d['code_depense']) ?>" title="Modifier"><i class="fas fa-pen"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= htmlspecialchars($d['code_depense']) ?>" data-nom="<?= htmlspecialchars($d['titre_depense']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="fas fa-trash"></i></button>
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
                        <a class="page-link" href="#" data-page="<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
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
                        <a class="page-link" href="#" data-page="<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
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

$editDepense = null;
if ($action === 'edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM depense WHERE code_depense = ?");
    $stmt->execute([$code]);
    $editDepense = $stmt->fetch(PDO::FETCH_ASSOC);
}

$viewDepense = null;
if ($action === 'view' && isset($_POST['view_code'])) {
    $code = $_POST['view_code'];
    $stmt = $pdo->prepare("SELECT d.*, b.nom_boutique, u.nom_prenom 
                           FROM depense d
                           LEFT JOIN boutique b ON d.boutique_id = b.code_boutique
                           LEFT JOIN utilisateur u ON d.utilisateur_id = u.id
                           WHERE d.code_depense = ?");
    $stmt->execute([$code]);
    $viewDepense = $stmt->fetch(PDO::FETCH_ASSOC);
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
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Bootstrap SelectPicker (CSS) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* === Styles identiques à contact.php === */
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

        .act-btn.v:hover {
            color: var(--color-primary);
            background: var(--color-primary-soft);
            border-color: rgba(79, 70, 229, 0.15);
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
    </style>
</head>

<body>
    <div class="container-crud">

        <!-- En-tête -->
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
            <div class="page-heading">
                <h2 class="fw-800 mb-0">Gestion des dépenses</h2>
                <p class="text-tertiary mt-1">Suivez toutes vos dépenses par boutique et utilisateur</p>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="fas fa-plus"></i> Nouvelle dépense</button>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Barre de recherche avec SelectPicker -->
        <div class="bg-light p-3 rounded-3 mb-3 border">
            <form id="searchForm" method="post" onsubmit="return false;">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="searchInput" class="form-label fw-semibold small">Recherche</label>
                        <div class="search-inline" style="min-width:100%; height:42px;">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" id="searchInput" placeholder="Code, titre, description..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label for="boutiqueFilter" class="form-label fw-semibold small">Boutique</label>
                        <select name="boutique_filter" id="boutiqueFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
                            <option value="">Toutes</option>
                            <?php foreach ($boutiques as $b): ?>
                                <option value="<?= htmlspecialchars($b['code_boutique']) ?>" <?= ($boutique_filter == $b['code_boutique']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['nom_boutique']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="utilisateurFilter" class="form-label fw-semibold small">Utilisateur</label>
                        <select name="utilisateur_filter" id="utilisateurFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un utilisateur...">
                            <option value="">Tous</option>
                            <?php foreach ($utilisateurs as $u): ?>
                                <option value="<?= htmlspecialchars($u['id']) ?>" <?= ($utilisateur_filter == $u['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['nom_prenom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="etatFilter" class="form-label fw-semibold small">État</label>
                        <select name="etat_filter" id="etatFilter" class="selectpicker form-control">
                            <option value="">Tous</option>
                            <?php foreach ($etats_depense as $e): ?>
                                <option value="<?= $e ?>" <?= ($etat_filter == $e) ? 'selected' : '' ?>><?= $e ?></option>
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
                <h5 class="mb-0 fw-bold">Liste des dépenses</h5>
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

    <!-- =========================================================
MODAL FORMULAIRE (ajout/modification)
========================================================= -->
    <div class="modal fade" id="depenseModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-money-bill-wave text-primary me-2"></i> Nouvelle dépense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post" id="depenseForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="old_code" id="oldCode" value="">
                    <div class="modal-body">
                        <!-- Identification -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-tag me-1"></i> Identification</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="code_depense" class="form-label fw-semibold">Code dépense <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                                    <input type="text" class="form-control" id="code_depense" name="code_depense" required placeholder="DEP001" value="<?= htmlspecialchars($editDepense['code_depense'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="titre_depense" class="form-label fw-semibold">Titre <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-heading"></i></span>
                                    <input type="text" class="form-control" id="titre_depense" name="titre_depense" placeholder="Achat fournitures" value="<?= htmlspecialchars($editDepense['titre_depense'] ?? '') ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Association boutique / utilisateur -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-store me-1"></i> Association</h6>
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
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-coins me-1"></i> Montant & date</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="montant_depense" class="form-label fw-semibold">Montant <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-dollar-sign"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="montant_depense" name="montant_depense" placeholder="0.00" value="<?= htmlspecialchars($editDepense['montant_depense'] ?? '0') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="date_depense" class="form-label fw-semibold">Date et heure</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                    <input type="datetime-local" class="form-control" id="date_depense" name="date_depense" value="<?= isset($editDepense) ? date('Y-m-d\TH:i', strtotime($editDepense['date_depense'])) : date('Y-m-d\TH:i') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Description -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-align-left me-1"></i> Description</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-12">
                                <label for="description_depense" class="form-label fw-semibold">Description</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-pencil-alt"></i></span>
                                    <textarea class="form-control" id="description_depense" name="description_depense" rows="3" placeholder="Détails de la dépense..."><?= htmlspecialchars($editDepense['description_depense'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- État -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-toggle-on me-1"></i> Statut</h6>
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
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Annuler</button>
                        <button type="submit" class="btn btn-primary" id="saveBtn"><i class="fas fa-save"></i> Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =========================================================
MODAL : VUE DÉTAIL
========================================================= -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:600px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="viewModalLabel"><i class="fas fa-eye text-primary me-2"></i> Détails de la dépense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3" id="viewGrid"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
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

    <!-- Formulaire caché pour actions view/edit -->
    <form method="post" id="actionForm">
        <input type="hidden" name="action" id="actionField">
        <input type="hidden" name="edit_code" id="editCodeField">
        <input type="hidden" name="view_code" id="viewCodeField">
    </form>

    <!-- =========================================================
SCRIPTS
========================================================= -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Bootstrap SelectPicker JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

    <script>
        $(document).ready(function() {
            // --- Initialisation de tous les selectpicker ---
            // destroy avant init : évite le doublon visuel si ce contenu est
            // rechargé dans le DOM sans rechargement complet de la page.
            $('.selectpicker').selectpicker('destroy');
            $('.selectpicker').selectpicker();

            const depenseModal = new bootstrap.Modal(document.getElementById('depenseModal'));
            const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));

            // --- Ajout ---
            $('#addBtn').on('click', function() {
                $('#formAction').val('add');
                $('#oldCode').val('');
                $('#modalTitle').text('Nouvelle dépense');
                $('#depenseForm')[0].reset();
                $('#code_depense').prop('readonly', false);

                // Vidage manuel : reset() ne suffit pas, car ces champs ont leur
                // value/selected écrit par PHP côté serveur (pré-remplis lors d'une
                // édition précédente) et reset() les restaure au lieu de les vider.
                $('#code_depense').val('');
                $('#titre_depense').val('');
                $('#montant_depense').val('0');
                $('#description_depense').val('');
                $('#etat_depense').val('VALIDE');

                // Remettre la date actuelle
                var now = new Date();
                var offset = now.getTimezoneOffset() * 60000;
                var localISOTime = (new Date(Date.now() - offset)).toISOString().slice(0, 16);
                $('#date_depense').val(localISOTime);

                // Réinitialiser uniquement les selects du formulaire, pas ceux des
                // filtres de recherche (qui partagent aussi la classe .selectpicker)
                $('#boutique_id, #utilisateur_id').selectpicker('val', '');
                depenseModal.show();
            });

            // --- Édition ---
            $(document).on('click', '.editBtn', function() {
                const code = $(this).data('code');
                $('#actionField').val('edit');
                $('#editCodeField').val(code);
                $('#actionForm').submit();
            });

            // --- Vue ---
            $(document).on('click', '.viewBtn', function() {
                const code = $(this).data('code');
                $('#actionField').val('view');
                $('#viewCodeField').val(code);
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

            // Auto-submit à la saisie pour le champ texte
            var searchTimeout = null;
            $('#searchInput').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });

            // Pour les selectpicker du filtre
            $('#boutiqueFilter, #utilisateurFilter, #etatFilter').on('changed.bs.select', function() {
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
                $('#boutiqueFilter').selectpicker('val', '');
                $('#utilisateurFilter').selectpicker('val', '');
                $('#etatFilter').selectpicker('val', '');
                rechercher(1);
            });

            // Pagination initiale
            $('.page-link').on('click', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                if (page) rechercher(page);
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
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);

            // --- Si édition via POST ---
            <?php if (isset($editDepense) && $action === 'edit'): ?>
                $(function() {
                    $('#formAction').val('edit');
                    $('#oldCode').val('<?= htmlspecialchars($editDepense['code_depense']) ?>');
                    $('#modalTitle').text('Modifier la dépense');
                    $('#code_depense').prop('readonly', true);
                    depenseModal.show();
                });
            <?php endif; ?>

            // --- Si vue via POST ---
            <?php if (isset($viewDepense) && $action === 'view'): ?>
                $(function() {
                    $('#viewModalLabel').text('Détails de la dépense - <?= htmlspecialchars($viewDepense['titre_depense'] ?? $viewDepense['code_depense']) ?>');
                    const fields = [
                        ['Code', '<?= htmlspecialchars($viewDepense['code_depense']) ?>'],
                        ['Titre', '<?= htmlspecialchars($viewDepense['titre_depense']) ?>'],
                        ['Boutique', '<?= htmlspecialchars($viewDepense['nom_boutique'] ?? '—') ?>'],
                        ['Utilisateur', '<?= htmlspecialchars($viewDepense['nom_prenom'] ?? '—') ?>'],
                        ['Montant', '<?= number_format((float)$viewDepense['montant_depense'], 2) ?>'],
                        ['Date', '<?= isset($viewDepense['date_depense']) ? date('d/m/Y H:i', strtotime($viewDepense['date_depense'])) : '—' ?>'],
                        ['Description', '<?= htmlspecialchars($viewDepense['description_depense'] ?? '') ?>'],
                        ['État', '<?= htmlspecialchars($viewDepense['etat_depense']) ?>']
                    ];
                    let html = '';
                    fields.forEach(([l, v]) => {
                        let val = v || '—';
                        html += `<div class="col-sm-6"><div class="bg-light p-3 rounded-3 border"><div class="text-muted small text-uppercase fw-bold">${l}</div><div class="fw-semibold">${val}</div></div></div>`;
                    });
                    $('#viewGrid').html(html);
                    viewModal.show();
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>