<?php
ob_start();
// Fonction utilitaire pour envoyer une réponse JSON propre
function sendJson($data)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// taxe.php – Gestion des taxes (design boutique)
require 'databases/database.php';

// --- Récupération des listes pour les selects ---
$types_taxe = ['TVA', 'Remise', 'Autre'];
$types_from_db = $pdo->query("SELECT DISTINCT type_taxe FROM taxe ORDER BY type_taxe")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($types_from_db)) {
    $types_taxe = $types_from_db;
}
$etats_taxe = ['Actif', 'Inactif'];

// --- Traitement des actions POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';

if ($action === 'add' || $action === 'edit') {
    $code = trim($_POST['code_taxe'] ?? '');
    $titre = trim($_POST['titre_taxe'] ?? '');
    $taux = trim(str_replace(',', '.', $_POST['taux_taxe'] ?? 0));
    $type = trim($_POST['type_taxe'] ?? '');
    $etat = trim($_POST['etat_taxe'] ?? 'Actif');

    $errors = [];
    if (empty($code)) $errors[] = 'Le code taxe est requis.';
    if (empty($titre)) $errors[] = 'Le titre est requis.';
    if (!is_numeric($taux) || $taux < 0) $errors[] = 'Le taux doit être un nombre positif.';
    if (empty($type)) $errors[] = 'Le type est requis.';

    if (empty($errors)) {
        try {
            if ($action === 'add') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM taxe WHERE code_taxe = ?");
                $stmt->execute([$code]);
                if ($stmt->fetchColumn() > 0) {
                    $message = "Ce code taxe existe déjà.";
                    $messageType = 'warning';
                } else {
                    $sql = "INSERT INTO taxe (code_taxe, titre_taxe, taux_taxe, type_taxe, etat_taxe)
                            VALUES (?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$code, $titre, $taux, $type, $etat]);
                    $message = "Taxe « $titre » ajoutée avec succès.";
                    $messageType = 'success';
                }
            } elseif ($action === 'edit') {
                $oldCode = $_POST['old_code'] ?? $code;
                $sql = "UPDATE taxe SET code_taxe=?, titre_taxe=?, taux_taxe=?, type_taxe=?, etat_taxe=?
                        WHERE code_taxe = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$code, $titre, $taux, $type, $etat, $oldCode]);
                $message = "Taxe « $titre » mise à jour.";
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
            $stmt = $pdo->prepare("SELECT titre_taxe FROM taxe WHERE code_taxe = ?");
            $stmt->execute([$code]);
            $titre = $stmt->fetchColumn();
            $stmt = $pdo->prepare("DELETE FROM taxe WHERE code_taxe = ?");
            $stmt->execute([$code]);
            $message = "Taxe « $titre » supprimée.";
            $messageType = 'danger';
        } catch (PDOException $e) {
            $message = "Erreur : " . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// --- AJAX pour le tableau ---
function getTableContent($pdo, $search, $filtres, $page, $perPage = 20)
{
    $sql = "SELECT * FROM taxe WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (code_taxe LIKE ? OR titre_taxe LIKE ? OR type_taxe LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($filtres['type'])) {
        $sql .= " AND type_taxe = ?";
        $params[] = $filtres['type'];
    }
    if (!empty($filtres['etat'])) {
        $sql .= " AND etat_taxe = ?";
        $params[] = $filtres['etat'];
    }

    $countSql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $sql .= " ORDER BY code_taxe LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $taxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($taxes)): ?>
        <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>Aucune taxe trouvée</td></tr>
    <?php else: ?>
        <?php foreach ($taxes as $t): ?>
            <tr>
                <td class="td-bold"><?= htmlspecialchars($t['code_taxe']) ?></td>
                <td class="td-semi"><?= htmlspecialchars($t['titre_taxe']) ?></td>
                <td class="td-bold"><?= htmlspecialchars($t['taux_taxe']) ?> %</td>
                <td><?= htmlspecialchars($t['type_taxe']) ?></td>
                <td>
                    <span class="status-badge <?= $t['etat_taxe'] === 'Actif' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= htmlspecialchars($t['etat_taxe']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn e editBtn" data-code="<?= htmlspecialchars($t['code_taxe']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= htmlspecialchars($t['code_taxe']) ?>" data-nom="<?= htmlspecialchars($t['titre_taxe']) ?>" title="Supprimer"><i class="bi bi-trash"></i></button>
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
        'type' => trim($_POST['type'] ?? ''),
        'etat' => trim($_POST['etat'] ?? '')
    ];
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $filtres, $page);
    sendJson($result);
}

// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$filtres = [
    'type' => trim($_POST['type'] ?? ''),
    'etat' => trim($_POST['etat'] ?? '')
];
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $filtres, $page);

// Chargement des données pour l'édition (action load_edit)
$editTaxe = null;
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
    $code = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM taxe WHERE code_taxe = ?");
    $stmt->execute([$code]);
    $editTaxe = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Statistiques
$totalTaxe = $pdo->query("SELECT COUNT(*) FROM taxe")->fetchColumn();
$actives = $pdo->query("SELECT COUNT(*) FROM taxe WHERE etat_taxe = 'Actif'")->fetchColumn();
$inactives = $pdo->query("SELECT COUNT(*) FROM taxe WHERE etat_taxe = 'Inactif'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des taxes</title>
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
        .td-bold { color: var(--text-primary) !important; font-weight: 700; font-family: 'Outfit', sans-serif; }
        .td-semi { color: var(--text-primary) !important; font-weight: 500; }
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
        /* Style spécifique pour les selectpicker */
        .bootstrap-select .dropdown-toggle {
            background: #fff !important;
            border: 1.5px solid var(--border-color) !important;
            border-radius: 8px !important;
            padding: 9px 12px !important;
            font-size: 13px !important;
        }
        .bootstrap-select .dropdown-toggle:focus {
            border-color: var(--color-primary) !important;
            box-shadow: 0 0 0 3px var(--color-primary-soft) !important;
        }
        .bootstrap-select {
            width: 100% !important;
        }
        .bootstrap-select .filter-option {
            color: var(--text-primary) !important;
        }
        
        /* Réduire la taille des selectpicker Type et État */
        #typeFilter, #etatFilter,
        #typeFilter + .bootstrap-select,
        #etatFilter + .bootstrap-select {
            width: 160px !important;
            min-width: 160px !important;
            max-width: 180px !important;
        }

        #typeFilter + .bootstrap-select .dropdown-toggle,
        #etatFilter + .bootstrap-select .dropdown-toggle {
            min-width: 160px !important;
            width: 160px !important;
            padding: 7px 10px !important;
            font-size: 12px !important;
        }

        /* Réduire aussi le champ recherche pour faire de la place */
        #searchInput {
            flex: 1;
            min-width: 180px !important;
            max-width: 280px !important;
        }

        /* Labels plus compacts */
        .bg-white.border.rounded-3.p-3.mb-4.shadow-sm label.text-uppercase {
            font-size: 10px !important;
            white-space: nowrap;
        }

        /* Réduire l'espacement entre les éléments */
        .bg-white.border.rounded-3.p-3.mb-4.shadow-sm .d-flex {
            gap: 10px !important;
        }
        
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
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-percent text-primary me-2"></i>Gestion des taxes</h1>
            <p class="text-muted small mb-0">Définissez les taxes, remises et autres taux applicables</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                <i class="bi bi-percent"></i> <?= $totalTaxe ?> taxe(s)
            </span>
            <button type="button" class="btn-chic btn-chic-primary" id="addBtn">
                <i class="bi bi-plus-circle"></i>
                <span>Nouvelle taxe</span>
            </button>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show mb-4" role="alert" style="border-radius:var(--radius-sm);border:none;padding:16px 20px;font-size:13px;font-weight:500;">
        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : ($messageType === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?> me-2"></i>
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['primary', 'percent', 'Total taxes', $totalTaxe, ''],
            ['success', 'check-circle-fill', 'Actives', $actives, ''],
            ['danger', 'x-circle-fill', 'Inactives', $inactives, ''],
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

    <!-- FILTRES -->
    <div class="bg-white border rounded-3 p-3 mb-4 shadow-sm">
        <form id="searchForm" method="post" onsubmit="return false;">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-search"></i> Recherche</label>
                <input type="text" id="searchInput" class="form-control" placeholder="Code, titre, type..." value="<?= htmlspecialchars($search) ?>" style="flex:1; min-width:150px;">
                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-tag"></i> Type</label>
                <select id="typeFilter" class="form-control selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un type..." data-width="100%">
                    <option value="">Tous</option>
                    <?php foreach ($types_taxe as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= ($filtres['type'] == $t) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-toggle-on"></i> État</label>
                <select id="etatFilter" class="form-control selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un état..." data-width="100%">
                    <option value="">Tous</option>
                    <?php foreach ($etats_taxe as $e): ?>
                        <option value="<?= $e ?>" <?= ($filtres['etat'] == $e) ? 'selected' : '' ?>><?= $e ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-chic btn-chic-primary" id="filterBtn"><i class="bi bi-funnel"></i><span>Filtrer</span></button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des taxes</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> taxe(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Titre</th>
                        <th>Taux (%)</th>
                        <th>Type</th>
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

<!-- Modal Ajout/Édition -->
<div class="modal fade modal-chic" id="taxeModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"><i class="bi bi-percent"></i><span id="modalTitleText">Nouvelle taxe</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <!-- ✅ CORRECTION : ajout du style display:flex; flex-direction:column; flex:1; min-height:0; -->
            <form method="post" id="taxeForm" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_code" id="oldCode" value="">
                <div class="modal-body">
                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-primary);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-hash-fill"></i> Identification
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="code_taxe" class="form-label">Code taxe <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="code_taxe" name="code_taxe" required placeholder="TAX001" value="<?= htmlspecialchars($editTaxe['code_taxe'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="titre_taxe" class="form-label">Titre <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-heading"></i></span>
                                <input type="text" class="form-control" id="titre_taxe" name="titre_taxe" required placeholder="TVA 18%" value="<?= htmlspecialchars($editTaxe['titre_taxe'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-info);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-sliders2-fill"></i> Détails
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="taux_taxe" class="form-label">Taux (%) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-percent"></i></span>
                                <input type="number" step="0.01" class="form-control" id="taux_taxe" name="taux_taxe" placeholder="0.00" required value="<?= htmlspecialchars($editTaxe['taux_taxe'] ?? '0') ?>">
                            </div>
                            <div class="form-text" style="font-size:11px;color:var(--text-tertiary);margin-top:4px;">Saisir la valeur en pourcentage (ex: 18 pour 18%).</div>
                        </div>
                        <div class="col-md-6">
                            <label for="type_taxe" class="form-label">Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="type_taxe" name="type_taxe" required>
                                <option value="">=== Faites votre choix ===</option>
                                <?php foreach ($types_taxe as $t): ?>
                                    <option value="<?= htmlspecialchars($t) ?>" <?= (isset($editTaxe) && $editTaxe['type_taxe'] == $t) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" style="font-size:11px;color:var(--text-tertiary);margin-top:4px;">Exemples : TVA, Remise, Autre</div>
                        </div>
                    </div>

                    <h6 class="text-uppercase fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-warning);display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-toggle-on-fill"></i> Statut
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="etat_taxe" class="form-label">État</label>
                            <select class="form-select" id="etat_taxe" name="etat_taxe">
                                <?php foreach ($etats_taxe as $e): ?>
                                    <option value="<?= $e ?>" <?= (isset($editTaxe) && $editTaxe['etat_taxe'] == $e) ? 'selected' : '' ?>><?= $e ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-chic" style="background:var(--color-gray-100);color:var(--text-secondary);" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i>
                        <span>Annuler</span>
                    </button>
                    <button type="submit" class="btn-chic btn-chic-primary" id="saveBtn">
                        <i class="bi bi-check-lg"></i>
                        <span>Enregistrer</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal de confirmation de suppression -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Confirmer la suppression</h5>
                <p class="text-muted small mb-4">Êtes-vous sûr de vouloir supprimer la taxe <strong id="deleteNomTaxe" class="text-danger"></strong> ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger rounded-3" id="confirmDeleteBtn"><i class="bi bi-trash3 me-1"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal d'alerte (remplace window.alert()) -->
<div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-danger" style="font-size: 3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Erreur</h5>
                <p class="text-muted small mb-4" id="alertModalMsg"></p>
                <button type="button" class="btn btn-primary rounded-3" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Formulaires cachés -->
<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="btn_supprimer" value="1">
    <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
</form>

<form method="post" id="actionForm" style="display:none;">
    <input type="hidden" name="action" id="actionField">
    <input type="hidden" name="edit_code" id="editCodeField">
</form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    const taxeModal = new bootstrap.Modal(document.getElementById('taxeModal'));
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));

    $('#addBtn').on('click', function(e) {
        e.preventDefault();
        $('#formAction').val('add');
        $('#oldCode').val('');
        $('#modalTitle').html('<i class="bi bi-percent"></i><span>Nouvelle taxe</span>');
        $('#taxeForm')[0].reset();
        $('#code_taxe').prop('readonly', false);
        $('#code_taxe').val('');
        $('#titre_taxe').val('');
        $('#taux_taxe').val('0');
        $('#type_taxe').val('');
        $('#etat_taxe').val('Actif');
        taxeModal.show();
    });

    $(document).on('click', '.editBtn', function(e) {
        e.preventDefault();
        const code = $(this).data('code');
        $('#actionField').val('load_edit');
        $('#editCodeField').val(code);
        $('#actionForm').submit();
    });

    function rechercher(page) {
        page = page || 1;
        var formData = $('#searchForm').serialize();
        formData += '&page=' + page;
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' taxe(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
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
                $('#alertModalMsg').text('Erreur lors de la recherche (code ' + xhr.status + '). Voir console pour détails.');
                new bootstrap.Modal(document.getElementById('alertModal')).show();
            }
        });
    }

    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });

    $('#typeFilter, #etatFilter').on('changed.bs.select', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 300);
    });

    $('#filterBtn').on('click', function() { rechercher(1); });

    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#typeFilter, #etatFilter').selectpicker('val', '');
        rechercher(1);
    });

    $('.page-link').on('click', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        if (page) rechercher(page);
    });

    $(document).on('click', '.deleteBtn', function(e) {
        e.preventDefault();
        const code = $(this).data('code');
        const nom = $(this).data('nom');
        $('#deleteNomTaxe').text(nom);
        $('#deleteFormId').val(code);
        $('#deleteConfirmModal').modal('show');
    });

    $('#confirmDeleteBtn').on('click', function() {
        $('#deleteForm').submit();
    });

    setTimeout(function() { $('.alert').alert('close'); }, 5000);

    <?php if (isset($editTaxe) && $action === 'load_edit'): ?>
    $(function() {
        $('#formAction').val('edit');
        $('#oldCode').val('<?= htmlspecialchars($editTaxe['code_taxe']) ?>');
        $('#modalTitle').html('<i class="bi bi-pencil-square"></i><span>Modifier la taxe</span>');
        $('#code_taxe').prop('readonly', true);
        taxeModal.show();
    });
    <?php endif; ?>
});
</script>
</body>
</html>