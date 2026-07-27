<?php
// views/transferts/index.php – Gestion des transferts de stock (design vente)

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require 'databases/database.php';

$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---- LISTES POUR LES FILTRES ET LE FORMULAIRE ----
$produits = $pdo->query("SELECT code_produit, titre_produit FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

// ---- PRÉCHARGEMENT DES LOTS POUR LE JAVASCRIPT ----
$lotsParProduit = [];
$stmtLots = $pdo->query("SELECT produit_id, code_lot_produit, titre_lot, unites_par_lot FROM lot_produit WHERE etat_lot = 'Actif'");
while ($lot = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
    $lotsParProduit[$lot['produit_id']][] = $lot;
}

// ---- TRAITEMENT DU FORMULAIRE DE TRANSFERT ----
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transferer_stock') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        $produitId = trim($_POST['produit_id'] ?? '');
        $sourceId  = trim($_POST['boutique_source'] ?? '');
        $destId    = trim($_POST['boutique_dest'] ?? '');
        $quantiteSaisie = (int)($_POST['quantite'] ?? 0);
        $lotId     = !empty($_POST['lot_id']) ? trim($_POST['lot_id']) : null;
        $dateTransfert = $_POST['date_transfert'] ?? date('Y-m-d');

        if (empty($produitId) || empty($sourceId) || empty($destId) || $quantiteSaisie <= 0 || $sourceId === $destId) {
            $message = "Vérifiez les champs : produit, boutiques différentes, quantité > 0.";
            $messageType = 'error';
        } else {
            $facteur = 1;
            $unite = 'Unité';
            if (!empty($lotId)) {
                $stmt = $pdo->prepare("SELECT unites_par_lot, titre_lot FROM lot_produit WHERE code_lot_produit = ? AND produit_id = ?");
                $stmt->execute([$lotId, $produitId]);
                $info = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($info) {
                    $facteur = (int)$info['unites_par_lot'];
                    $unite = $info['titre_lot'];
                } else {
                    $lotId = null;
                }
            }
            $quantiteBase = $quantiteSaisie * $facteur;

            try {
                $pdo->beginTransaction();

                $stmtLockSrc = $pdo->prepare("SELECT quantite FROM stock_boutique WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                $stmtLockSrc->execute([$produitId, $sourceId]);
                $ligneSrc = $stmtLockSrc->fetch(PDO::FETCH_ASSOC);
                if ($ligneSrc === false) {
                    throw new Exception("Le produit n'existe pas dans la boutique source.");
                }
                $stockAvantSrc = (int)$ligneSrc['quantite'];
                if ($stockAvantSrc < $quantiteBase) {
                    throw new Exception("Stock insuffisant dans la boutique source (disponible : $stockAvantSrc, demandé : $quantiteBase).");
                }
                $stockApresSrc = $stockAvantSrc - $quantiteBase;

                $codeTransfert = 'TRF-' . date('YmdHis') . rand(100, 999);

                $num1 = $codeTransfert . '-S';
                $stmt = $pdo->prepare("INSERT INTO commande 
                    (numero_commande, produit_id, statut_id, date_commande, heure_commande,
                     prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id,
                     boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion,
                     reference_liee, montant_rembourse, motif_retour, type_retour, stock_avant, stock_apres)
                    VALUES (?, ?, '008', ?, CURTIME(), 0, 0, ?, 0, ?, ?, 'Valider', ?, ?, ?, ?, 0, '', '', ?, ?)");
                $stmt->execute([
                    $num1,
                    $produitId,
                    $dateTransfert,
                    $quantiteBase,
                    $user['id'],
                    $sourceId,
                    $lotId,
                    $unite,
                    $facteur,
                    $codeTransfert,
                    $stockAvantSrc,
                    $stockApresSrc
                ]);

                $pdo->prepare("UPDATE stock_boutique SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                    ->execute([$stockApresSrc, $produitId, $sourceId]);

                $stmtLockDest = $pdo->prepare("SELECT quantite FROM stock_boutique WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                $stmtLockDest->execute([$produitId, $destId]);
                $ligneDest = $stmtLockDest->fetch(PDO::FETCH_ASSOC);
                if ($ligneDest === false) {
                    $pdo->prepare("INSERT INTO stock_boutique (produit_id, boutique_id, quantite) VALUES (?, ?, 0)")
                        ->execute([$produitId, $destId]);
                    $stockAvantDest = 0;
                } else {
                    $stockAvantDest = (int)$ligneDest['quantite'];
                }
                $stockApresDest = $stockAvantDest + $quantiteBase;

                $num2 = $codeTransfert . '-D';
                $stmt = $pdo->prepare("INSERT INTO commande 
                    (numero_commande, produit_id, statut_id, date_commande, heure_commande,
                     prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id,
                     boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion,
                     reference_liee, montant_rembourse, motif_retour, type_retour, stock_avant, stock_apres)
                    VALUES (?, ?, '009', ?, CURTIME(), 0, 0, ?, 0, ?, ?, 'Valider', ?, ?, ?, ?, 0, '', '', ?, ?)");
                $stmt->execute([
                    $num2,
                    $produitId,
                    $dateTransfert,
                    $quantiteBase,
                    $user['id'],
                    $destId,
                    $lotId,
                    $unite,
                    $facteur,
                    $codeTransfert,
                    $stockAvantDest,
                    $stockApresDest
                ]);

                $pdo->prepare("UPDATE stock_boutique SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                    ->execute([$stockApresDest, $produitId, $destId]);

                $pdo->prepare("UPDATE produit SET stock_produit = (
                        SELECT COALESCE(SUM(quantite), 0) FROM stock_boutique WHERE produit_id = ?
                    ) WHERE code_produit = ?")
                    ->execute([$produitId, $produitId]);

                $pdo->commit();
                $message = "Transfert effectué ! Réf : $codeTransfert — $quantiteSaisie lot(s) ($quantiteBase unités) de {$sourceId} vers {$destId}.";
                $messageType = 'success';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Erreur : " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// ---- FONCTION DE RÉCUPÉRATION DE L'HISTORIQUE ----
function getTransferts($pdo, $search, $produit_filter, $boutique_filter, $page, $perPage = 10)
{
    $sql = "SELECT 
                c.reference_liee AS transfert_id,
                MAX(c.date_commande) AS date_transfert,
                MAX(c.produit_id) AS produit_id,
                MAX(CASE WHEN c.statut_id = '008' THEN c.boutique_id END) AS boutique_source,
                MAX(CASE WHEN c.statut_id = '009' THEN c.boutique_id END) AS boutique_dest,
                SUM(CASE WHEN c.statut_id = '008' THEN c.quantite_commande ELSE 0 END) AS quantite_sortie,
                SUM(CASE WHEN c.statut_id = '009' THEN c.quantite_commande ELSE 0 END) AS quantite_entree
            FROM commande c
            WHERE c.statut_id IN ('008', '009') 
              AND c.etat_commande = 'Valider'
              AND c.reference_liee IS NOT NULL
              AND c.reference_liee != ''
    ";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (c.reference_liee LIKE ? OR c.produit_id IN (SELECT code_produit FROM produit WHERE titre_produit LIKE ?))";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($produit_filter)) {
        $sql .= " AND c.produit_id = ?";
        $params[] = $produit_filter;
    }
    if (!empty($boutique_filter)) {
        $sql .= " AND (c.boutique_id = ? OR c.reference_liee IN (
                    SELECT sub.reference_liee FROM commande sub 
                    WHERE sub.statut_id IN ('008', '009') AND sub.boutique_id = ? AND sub.reference_liee IS NOT NULL
                ))";
        $params[] = $boutique_filter;
        $params[] = $boutique_filter;
    }

    $sql .= " GROUP BY c.reference_liee";
    $sql .= " ORDER BY date_transfert DESC";

    $countSql = "SELECT COUNT(*) FROM (" . $sql . ") AS sub";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = max(1, ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $sql .= " LIMIT $perPage OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transferts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($transferts as &$t) {
        if ($t['boutique_source']) {
            $stmt = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
            $stmt->execute([$t['boutique_source']]);
            $t['nom_source'] = $stmt->fetchColumn();
        } else {
            $t['nom_source'] = '—';
        }
        if ($t['boutique_dest']) {
            $stmt = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
            $stmt->execute([$t['boutique_dest']]);
            $t['nom_dest'] = $stmt->fetchColumn();
        } else {
            $t['nom_dest'] = '—';
        }
        if ($t['produit_id']) {
            $stmt = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
            $stmt->execute([$t['produit_id']]);
            $t['titre_produit'] = $stmt->fetchColumn();
        } else {
            $t['titre_produit'] = '—';
        }
        $t['quantite'] = $t['quantite_sortie'] ?: $t['quantite_entree'];
    }
    unset($t);

    return [
        'transferts' => $transferts,
        'total' => $total,
        'page' => $page,
        'totalPages' => $totalPages
    ];
}

// ---- GESTION DES REQUÊTES AJAX ----
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $produit_filter = trim($_POST['produit_filter'] ?? '');
    $boutique_filter = trim($_POST['boutique_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;

    $data = getTransferts($pdo, $search, $produit_filter, $boutique_filter, $page, 10);

    ob_start();
    if (empty($data['transferts'])): ?>
        <tr>
            <td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>Aucun transfert</td>
        </tr>
        <?php else: foreach ($data['transferts'] as $t): ?>
            <tr>
                <td class="td-bold"><?= e($t['transfert_id']) ?></td>
                <td><?= e($t['date_transfert']) ?></td>
                <td><?= e($t['titre_produit'] ?? $t['produit_id']) ?></td>
                <td><?= e($t['nom_source']) ?></td>
                <td><?= e($t['nom_dest']) ?></td>
                <td><?= $t['quantite'] ?></td>
            </tr>
        <?php endforeach;
    endif;
    $tableHtml = ob_get_clean();

    ob_start();
    if ($data['totalPages'] > 1): ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
            <span class="text-muted small">Affichage de <?= (($data['page'] - 1) * 10 + 1) ?> à <?= min($data['page'] * 10, $data['total']) ?> sur <?= $data['total'] ?></span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($data['page'] <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $data['page'] - 1 ?>"><i class="bi bi-chevron-left"></i></a>
                    </li>
                    <?php
                    $start = max(1, $data['page'] - 2);
                    $end = min($data['totalPages'], $data['page'] + 2);
                    if ($start > 1) {
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                        if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?= ($i == $data['page']) ? 'active' : '' ?>">
                            <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor;
                    if ($end < $data['totalPages']) {
                        if ($end < $data['totalPages'] - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $data['totalPages'] . '">' . $data['totalPages'] . '</a></li>';
                    }
                    ?>
                    <li class="page-item <?= ($data['page'] >= $data['totalPages']) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $data['page'] + 1 ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
<?php endif;
    $paginationHtml = ob_get_clean();

    header('Content-Type: application/json');
    echo json_encode([
        'table' => $tableHtml,
        'pagination' => $paginationHtml,
        'total' => $data['total'],
        'page' => $data['page'],
        'totalPages' => $data['totalPages']
    ]);
    exit;
}

// ---- PARAMÈTRES INITIAUX POUR L'AFFICHAGE ----
$search = trim($_POST['search'] ?? '');
$produit_filter = trim($_POST['produit_filter'] ?? '');
$boutique_filter = trim($_POST['boutique_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$transfertsData = getTransferts($pdo, $search, $produit_filter, $boutique_filter, $page, 10);
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des transferts de stock</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
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
            <h1>Gestion des transferts de stock</h1>
            <p>Suivez vos mouvements entre boutiques</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-arrow-left-right"></i> <?= $transfertsData['total'] ?? 0 ?> transfert(s)</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau transfert</button>
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
            <input type="hidden" name="page" id="pageInput" value="<?= e($page) ?>">
            <div class="prow">
                <label for="searchInput"><i class="bi bi-search"></i> Recherche</label>
                <input type="text" name="search" id="searchInput" placeholder="Réf., produit..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                <label for="produitFilter">Produit</label>
                <select name="produit_filter" id="produitFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un produit...">
                    <option value="">Tous</option>
                    <?php foreach ($produits as $p): ?>
                        <option value="<?= e($p['code_produit']) ?>" <?= ($produit_filter == $p['code_produit']) ? 'selected' : '' ?>><?= e($p['titre_produit']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="boutiqueFilter">Boutique (source ou dest.)</label>
                <select name="boutique_filter" id="boutiqueFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
                    <option value="">Toutes</option>
                    <?php foreach ($boutiques as $b): ?>
                        <option value="<?= e($b['code_boutique']) ?>" <?= ($boutique_filter == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
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
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Historique des transferts</h5>
            <span class="text-muted small" id="totalCount"><?= $transfertsData['total'] ?? 0 ?> transfert(s) - Page <?= $transfertsData['page'] ?? 1 ?> / <?= max(1, $transfertsData['totalPages'] ?? 1) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Réf.</th>
                        <th>Date</th>
                        <th>Produit</th>
                        <th>Source</th>
                        <th>Destination</th>
                        <th>Qté (base)</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if (empty($transfertsData['transferts'])): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>Aucun transfert</td>
                        </tr>
                    <?php else: foreach ($transfertsData['transferts'] as $t): ?>
                        <tr>
                            <td class="td-bold"><?= e($t['transfert_id']) ?></td>
                            <td><?= e($t['date_transfert']) ?></td>
                            <td><?= e($t['titre_produit'] ?? $t['produit_id']) ?></td>
                            <td><?= e($t['nom_source']) ?></td>
                            <td><?= e($t['nom_dest']) ?></td>
                            <td><?= $t['quantite'] ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div id="paginationContainer">
            <?php if ($transfertsData['totalPages'] > 1): ?>
                <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
                    <span class="text-muted small">Affichage de <?= (($transfertsData['page'] - 1) * 10 + 1) ?> à <?= min($transfertsData['page'] * 10, $transfertsData['total']) ?> sur <?= $transfertsData['total'] ?></span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= ($transfertsData['page'] <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="#" data-page="<?= $transfertsData['page'] - 1 ?>"><i class="bi bi-chevron-left"></i></a>
                            </li>
                            <?php
                            $start = max(1, $transfertsData['page'] - 2);
                            $end = min($transfertsData['totalPages'], $transfertsData['page'] + 2);
                            if ($start > 1) {
                                echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                                if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                            }
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <li class="page-item <?= ($i == $transfertsData['page']) ? 'active' : '' ?>">
                                    <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor;
                            if ($end < $transfertsData['totalPages']) {
                                if ($end < $transfertsData['totalPages'] - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $transfertsData['totalPages'] . '">' . $transfertsData['totalPages'] . '</a></li>';
                            }
                            ?>
                            <li class="page-item <?= ($transfertsData['page'] >= $transfertsData['totalPages']) ? 'disabled' : '' ?>">
                                <a class="page-link" href="#" data-page="<?= $transfertsData['page'] + 1 ?>"><i class="bi bi-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL NOUVEAU TRANSFERT -->
<!-- ========================================================= -->
<div class="modal fade" id="transfertModal" tabindex="-1" aria-labelledby="transfertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="transfertModalLabel"><i class="bi bi-arrow-left-right text-primary me-2"></i> Nouveau transfert de stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="transfertForm">
                <input type="hidden" name="action" value="transferer_stock">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Produit <span class="text-danger">*</span></label>
                            <select name="produit_id" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($produits as $p): ?>
                                    <option value="<?= e($p['code_produit']) ?>"><?= e($p['titre_produit']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Lot/Unité</label>
                            <select name="lot_id" id="lotTransfert" class="form-select">
                                <option value="">Unité</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Quantité (lots) <span class="text-danger">*</span></label>
                            <input type="number" name="quantite" class="form-control" min="1" value="1" required>
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Boutique source <span class="text-danger">*</span></label>
                            <select name="boutique_source" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($boutiques as $b): ?>
                                    <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Boutique destination <span class="text-danger">*</span></label>
                            <select name="boutique_dest" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($boutiques as $b): ?>
                                    <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Date du transfert</label>
                            <input type="date" name="date_transfert" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x"></i> Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Effectuer le transfert</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
    $(document).ready(function() {
        // Initialisation des selectpicker
        $('.selectpicker').selectpicker('destroy');
        $('.selectpicker').selectpicker();

        // ---- Données des lots ----
        const lotsParProduit = <?= json_encode($lotsParProduit) ?>;

        function mettreAJourLots(selectProduit) {
            const produitId = selectProduit.value;
            const lotSelect = document.getElementById('lotTransfert');
            const lots = lotsParProduit[produitId] || [];
            let options = '<option value="">Unité</option>';
            lots.forEach(lot => {
                options += `<option value="${lot.code_lot_produit}" data-unite="${lot.titre_lot}" data-facteur="${lot.unites_par_lot}">${lot.titre_lot}</option>`;
            });
            lotSelect.innerHTML = options;
        }

        $(document).on('change', 'select[name="produit_id"]', function() {
            mettreAJourLots(this);
        });

        // ---- Modal Nouveau transfert ----
        const transfertModal = new bootstrap.Modal(document.getElementById('transfertModal'));
        $('#addBtn').on('click', function() {
            $('#transfertForm')[0].reset();
            $('#transfertForm input[name="date_transfert"]').val(new Date().toISOString().split('T')[0]);
            document.getElementById('lotTransfert').innerHTML = '<option value="">Unité</option>';
            transfertModal.show();
        });

        // ---- Recherche et filtres AJAX ----
        function rechercher(page) {
            page = page || 1;
            var search = $('#searchInput').val();
            var produit = $('#produitFilter').val();
            var boutique = $('#boutiqueFilter').val();
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    ajax: 1,
                    search: search,
                    produit_filter: produit,
                    boutique_filter: boutique,
                    page: page
                },
                dataType: 'json',
                success: function(data) {
                    $('#tableBody').html(data.table);
                    $('#paginationContainer').html(data.pagination);
                    $('#totalCount').text(data.total + ' transfert(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
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
        $('#produitFilter, #boutiqueFilter').on('changed.bs.select', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() { rechercher(1); }, 300);
        });
        $('#filterBtn').on('click', function() { rechercher(1); });
        $('#resetBtn').on('click', function() {
            $('#searchInput').val('');
            $('#produitFilter').selectpicker('val', '');
            $('#boutiqueFilter').selectpicker('val', '');
            rechercher(1);
        });

        // Pagination initiale
        $(document).on('click', '.page-link', function(e) {
            e.preventDefault();
            var p = $(this).data('page');
            if (p) rechercher(p);
        });
    });
</script>
</body>

</html>