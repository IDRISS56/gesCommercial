<?php
// views/transferts/index.php – Gestion des transferts de stock
// Adapté à la structure BDD : stock, commande, lot, statut (008/009)
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}
require dirname(__DIR__, 2) . '/databases/database.php';

$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// - LISTES POUR LES FILTRES ET LE FORMULAIRE -
$produits = $pdo->query("SELECT code_produit, titre_produit FROM produit WHERE etat_produit <> 'RUPTURE' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$lots = $pdo->query("SELECT code_lot, libelle FROM lot ORDER BY libelle")->fetchAll(PDO::FETCH_ASSOC);

// - TRAITEMENT DU FORMULAIRE DE TRANSFERT -
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transferer_stock') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        $produitId = trim($_POST['produit_id'] ?? '');
        $sourceId = trim($_POST['boutique_source'] ?? '');
        $destId = trim($_POST['boutique_dest'] ?? '');
        $quantite = (int)($_POST['quantite'] ?? 0);
        $lotId = !empty($_POST['lot_id']) ? trim($_POST['lot_id']) : null;
        $dateTransfert = $_POST['date_transfert'] ?? date('Y-m-d');

        if (empty($produitId) || empty($sourceId) || empty($destId) || $quantite <= 0 || $sourceId === $destId) {
            $message = "Veuillez remplir tous les champs correctement (source ≠ destination).";
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                // Vérification du stock source (avec verrouillage)
                $stmtLockSrc = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                $stmtLockSrc->execute([$produitId, $sourceId]);
                $ligneSrc = $stmtLockSrc->fetch(PDO::FETCH_ASSOC);
                $stockAvantSrc = $ligneSrc ? (int)$ligneSrc['quantite'] : 0;

                if ($stockAvantSrc < $quantite) {
                    throw new Exception("Stock insuffisant dans la boutique source (disponible : $stockAvantSrc).");
                }
                $stockApresSrc = $stockAvantSrc - $quantite;

                // Code transfert unique (préfixe commun pour lier source et destination)
                $codeTransfert = 'TRF-' . date('YmdHis') . rand(100, 999);

                // Récupérer le libellé du lot si choisi
                $libelleLot = 'Unité';
                $produitsParLot = 1;
                if ($lotId) {
                    $stmtLot = $pdo->prepare("SELECT libelle FROM lot WHERE code_lot = ?");
                    $stmtLot->execute([$lotId]);
                    $lib = $stmtLot->fetchColumn();
                    if ($lib) {
                        $libelleLot = $lib;
                        $produitsParLot = 1; // Valeur par défaut (informatif)
                    }
                }

                // INSERT ligne SORTIE (statut 008)
                $numSortie = $codeTransfert . '-S';
                $stmt = $pdo->prepare("INSERT INTO commande 
                    (numero_commande, produit_id, lot_id, produits_par_lot, statut_id, 
                     date_commande, heure_commande, prix_achat, prix_commande, 
                     quantite_commande, montant_commande, utilisateur_id, boutique_id, etat_commande)
                    VALUES (?, ?, ?, ?, '008', ?, CURTIME(), 0, 0, ?, 0, ?, ?, 'VALIDEE')");
                $stmt->execute([
                    $numSortie, $produitId, $lotId, $produitsParLot,
                    $dateTransfert, $quantite, $user['id'], $sourceId
                ]);

                // Décrémenter stock source
                $pdo->prepare("UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                    ->execute([$stockApresSrc, $produitId, $sourceId]);

                // INSERT ligne ENTREE (statut 009)
                $numEntree = $codeTransfert . '-D';
                $stmt = $pdo->prepare("INSERT INTO commande 
                    (numero_commande, produit_id, lot_id, produits_par_lot, statut_id, 
                     date_commande, heure_commande, prix_achat, prix_commande, 
                     quantite_commande, montant_commande, utilisateur_id, boutique_id, etat_commande)
                    VALUES (?, ?, ?, ?, '009', ?, CURTIME(), 0, 0, ?, 0, ?, ?, 'VALIDEE')");
                $stmt->execute([
                    $numEntree, $produitId, $lotId, $produitsParLot,
                    $dateTransfert, $quantite, $user['id'], $destId
                ]);

                // Incrémenter stock destination (créer si n'existe pas)
                $stmtCheckDest = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                $stmtCheckDest->execute([$produitId, $destId]);
                $ligneDest = $stmtCheckDest->fetch(PDO::FETCH_ASSOC);
                
                if ($ligneDest === false) {
                    $pdo->prepare("INSERT INTO stock (produit_id, boutique_id, quantite) VALUES (?, ?, ?)")
                        ->execute([$produitId, $destId, $quantite]);
                } else {
                    $stockApresDest = (int)$ligneDest['quantite'] + $quantite;
                    $pdo->prepare("UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                        ->execute([$stockApresDest, $produitId, $destId]);
                }

                $pdo->commit();
                $message = "Transfert effectué ! Réf : $codeTransfert — $quantite unité(s) transférée(s).";
                $messageType = 'success';
            } catch (Exception $ex) {
                $pdo->rollBack();
                $message = "Erreur : " . $ex->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// - FONCTION DE RÉCUPÉRATION DE L'HISTORIQUE -
function getTransferts($pdo, $search, $produit_filter, $boutique_filter, $page, $perPage = 10) {
    // On extrait le préfixe commun (sans -S ou -D) pour regrouper
    $sql = "SELECT
        SUBSTRING(c.numero_commande, 1, LENGTH(c.numero_commande) - 2) AS transfert_id,
        MAX(c.date_commande) AS date_transfert,
        MAX(c.produit_id) AS produit_id,
        MAX(CASE WHEN c.statut_id = '008' THEN c.boutique_id END) AS boutique_source,
        MAX(CASE WHEN c.statut_id = '009' THEN c.boutique_id END) AS boutique_dest,
        SUM(CASE WHEN c.statut_id = '008' THEN c.quantite_commande ELSE 0 END) AS quantite_transferee
        FROM commande c
        WHERE c.statut_id IN ('008', '009')
        AND c.etat_commande = 'VALIDEE'
        AND c.numero_commande LIKE 'TRF-%'";

    $params = [];
    if (!empty($search)) {
        $sql .= " AND (c.numero_commande LIKE ? OR c.produit_id IN (SELECT code_produit FROM produit WHERE titre_produit LIKE ?))";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($produit_filter)) {
        $sql .= " AND c.produit_id = ?";
        $params[] = $produit_filter;
    }
    if (!empty($boutique_filter)) {
        $sql .= " AND c.boutique_id = ?";
        $params[] = $boutique_filter;
    }
    $sql .= " GROUP BY SUBSTRING(c.numero_commande, 1, LENGTH(c.numero_commande) - 2)";
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

    // Enrichir avec les noms
    foreach ($transferts as &$t) {
        if ($t['boutique_source']) {
            $stmt = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
            $stmt->execute([$t['boutique_source']]);
            $t['nom_source'] = $stmt->fetchColumn() ?: '—';
        } else {
            $t['nom_source'] = '—';
        }
        if ($t['boutique_dest']) {
            $stmt = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
            $stmt->execute([$t['boutique_dest']]);
            $t['nom_dest'] = $stmt->fetchColumn() ?: '—';
        } else {
            $t['nom_dest'] = '—';
        }
        if ($t['produit_id']) {
            $stmt = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
            $stmt->execute([$t['produit_id']]);
            $t['titre_produit'] = $stmt->fetchColumn() ?: '—';
        } else {
            $t['titre_produit'] = '—';
        }
    }
    unset($t);

    return [
        'transferts' => $transferts,
        'total' => $total,
        'page' => $page,
        'totalPages' => $totalPages
    ];
}

// - GESTION DES REQUÊTES AJAX -
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    header('Content-Type: application/json');
    $search = trim($_POST['search'] ?? '');
    $produit_filter = trim($_POST['produit_filter'] ?? '');
    $boutique_filter = trim($_POST['boutique_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;

    $data = getTransferts($pdo, $search, $produit_filter, $boutique_filter, $page, 10);

    ob_start();
    if (empty($data['transferts'])): ?>
        <tr><td colspan="7" class="text-center py-5 text-muted">
            <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2.5rem;"></i>
            <div class="fw-semibold">Aucun transfert</div>
            <div class="small">Les transferts apparaîtront ici dès leur création.</div>
        </td></tr>
    <?php else:
        foreach ($data['transferts'] as $t): ?>
            <tr>
                <td><span class="ref-chic"><?= e($t['transfert_id']) ?></span></td>
                <td><span class="date-chic"><i class="bi bi-calendar3"></i> <?= date('d/m/Y', strtotime($t['date_transfert'])) ?></span></td>
                <td class="fw-semibold"><?= e($t['titre_produit']) ?></td>
                <td><span class="badge-chic source"><span class="dot"></span> <?= e($t['nom_source']) ?></span></td>
                <td><span class="badge-chic dest"><span class="dot"></span> <?= e($t['nom_dest']) ?></span></td>
                <td class="text-center"><span class="qty-chic"><?= (int)$t['quantite_transferee'] ?></span></td>
                <td class="text-center"><span class="statut-chic valide"><i class="bi bi-check-circle-fill"></i> Validé</span></td>
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
                    for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= ($i == $data['page']) ? 'active' : '' ?>">
                            <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor;
                    if ($end < $data['totalPages']) {
                        if ($end < $data['totalPages'] - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $data['totalPages'] . '">' . $data['totalPages'] . '</a></li>';
                    } ?>
                    <li class="page-item <?= ($data['page'] >= $data['totalPages']) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $data['page'] + 1 ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif;
    $paginationHtml = ob_get_clean();

    echo json_encode([
        'table' => $tableHtml,
        'pagination' => $paginationHtml,
        'total' => $data['total'],
        'page' => $data['page'],
        'totalPages' => $data['totalPages']
    ]);
    exit;
}

// - DONNÉES INITIALES -
$search = trim($_GET['search'] ?? '');
$produit_filter = trim($_GET['produit_filter'] ?? '');
$boutique_filter = trim($_GET['boutique_filter'] ?? '');
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$transfertsData = getTransferts($pdo, $search, $produit_filter, $boutique_filter, $page, 10);

// - STATISTIQUES -
$totalTransferts = $transfertsData['total'];
$totalUnites = 0;
foreach ($transfertsData['transferts'] as $t) {
    $totalUnites += (int)$t['quantite_transferee'];
}
$transfertsAujourdhui = $pdo->query("SELECT COUNT(DISTINCT SUBSTRING(numero_commande, 1, LENGTH(numero_commande) - 2)) 
    FROM commande WHERE statut_id IN ('008','009') AND etat_commande='VALIDEE' 
    AND numero_commande LIKE 'TRF-%' AND date_commande = CURDATE()")->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des transferts de stock</title>
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
            --color-gray-500: #64748b;
            --color-gray-700: #334155;
            --color-gray-800: #1e293b;
            --color-gray-900: #0f172a;
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-tertiary: #64748b;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.06);
            --shadow-lg: 0 12px 40px rgba(0,0,0,0.08);
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

        /* ===== STATS ===== */
        .stat-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            transition: var(--transition-base);
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; flex-shrink: 0;
        }
        .stat-label {
            font-size: 10px; font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-value {
            font-size: 18px; font-weight: 800;
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
            line-height: 1;
        }

        /* ===== BADGES ===== */
        .badge-chic {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 999px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }
        .badge-chic .dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: currentColor;
            animation: pulse 2s infinite;
        }
        .badge-chic.source { background: #fef3c7; color: #92400e; }
        .badge-chic.dest { background: #d1fae5; color: #065f46; }
        .badge-chic.valide { background: #dbeafe; color: #1e40af; }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* ===== TABLEAU ===== */
        .data-table-wrap {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .data-table-wrap .table {
            margin: 0;
            font-size: 13px;
        }
        .data-table-wrap .table thead th {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: var(--text-tertiary);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 14px 16px;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }
        .data-table-wrap .table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-primary);
            vertical-align: middle;
        }
        .data-table-wrap .table tbody tr {
            transition: background .15s ease;
            animation: fadeUp .4s ease both;
        }
        .data-table-wrap .table tbody tr:hover { background: #f8fafc; }
        .data-table-wrap .table tbody tr:last-child td { border-bottom: none; }

        .ref-chic {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 12px;
            color: var(--color-primary-dark);
            background: var(--color-primary-soft);
            padding: 3px 8px;
            border-radius: 6px;
        }
        .date-chic {
            font-size: 12px;
            color: var(--text-tertiary);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .qty-chic {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            padding: 4px 10px;
            background: var(--color-gray-100);
            color: var(--text-primary);
            border-radius: 8px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
        }
        .statut-chic {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .statut-chic.valide { background: var(--color-success-soft); color: #065f46; }

        /* ===== BOUTONS ===== */
        .btn-chic {
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            letter-spacing: -0.01em;
        }
        .btn-chic::before {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            width: 0; height: 0;
            background: rgba(255,255,255,0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width .4s, height .4s;
        }
        .btn-chic:hover::before { width: 300px; height: 300px; }
        .btn-chic i, .btn-chic span { position: relative; z-index: 1; }

        .btn-chic-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }
        .btn-chic-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
        }
        .btn-chic-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid var(--border-color);
        }
        .btn-chic-secondary:hover { background: var(--color-gray-200); color: var(--text-primary); }

        /* ===== MODAL CHIC ===== */
        .modal-chic .modal-content {
            border: none;
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15);
            overflow: hidden;
            animation: modalSlideIn .4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-chic .modal-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%);
            color: #fff;
            border: none;
            padding: 22px 28px;
            position: relative;
            overflow: hidden;
        }
        .modal-chic .modal-header::before {
            content: '';
            position: absolute;
            top: -50%; right: -20%;
            width: 200px; height: 200px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            border-radius: 50%;
        }
        .modal-chic .modal-title {
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 1;
        }
        .modal-chic .modal-title i {
            font-size: 22px;
            background: rgba(255,255,255,0.15);
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(10px);
        }
        .modal-chic .btn-close {
            filter: invert(1);
            opacity: 0.7;
            position: relative;
            z-index: 1;
            transition: all .2s;
        }
        .modal-chic .btn-close:hover { opacity: 1; transform: rotate(90deg); }
        .modal-chic .modal-body {
            padding: 28px;
            max-height: 70vh;
            overflow-y: auto;
            background: #f8fafc;
        }
        .modal-chic .modal-footer {
            background: #fff;
            border-top: 1px solid var(--border-color);
            padding: 18px 28px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
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

        /* ===== FORM ===== */
        .form-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-tertiary);
            margin-bottom: 6px;
        }
        .form-control, .form-select {
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            transition: all .2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px var(--color-primary-soft);
        }

        /* ===== ALERTS ===== */
        .alert {
            border: none;
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: var(--color-success-soft); color: #065f46; }
        .alert-danger { background: var(--color-danger-soft); color: #991b1b; }

        /* ===== PAGINATION ===== */
        .pagination .page-link {
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 600;
            padding: 6px 12px;
            margin: 0 2px;
            border-radius: 6px !important;
        }
        .pagination .page-item.active .page-link {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: #fff;
        }
        .pagination .page-link:hover {
            background: var(--color-primary-soft);
            color: var(--color-primary-dark);
            border-color: var(--color-primary);
        }

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
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1">
                <i class="bi bi-arrow-left-right text-primary me-2"></i>Gestion des transferts de stock
            </h1>
            <p class="text-muted small mb-0">Suivez vos mouvements de stock entre boutiques</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                <i class="bi bi-arrow-left-right"></i> <?= $totalTransferts ?> transfert(s)
            </span>
            <button class="btn-chic btn-chic-primary" id="addBtn">
                <i class="bi bi-plus-circle"></i>
                <span>Nouveau transfert</span>
            </button>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-<?= $messageType === 'error' ? 'exclamation-triangle-fill' : 'check-circle-fill' ?>"></i>
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['primary', 'arrow-left-right', 'Total transferts', $totalTransferts, ''],
            ['info', 'calendar-check', "Aujourd'hui", $transfertsAujourdhui, ''],
            ['success', 'box-seam', 'Unités transférées', fmt($totalUnites), ''],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'info'    => ['var(--color-info-soft)', 'var(--color-info)'],
        ];
        foreach ($stats as $s):
            $bg = $colorMap[$s[0]][0];
            $fg = $colorMap[$s[0]][1];
        ?>
        <div class="col-6 col-md-4">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background: <?= $bg ?>; color: <?= $fg ?>;">
                    <i class="bi bi-<?= $s[1] ?>"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-label"><?= $s[2] ?></div>
                    <div class="stat-value text-truncate"><?= $s[3] ?>
                        <?php if ($s[4]): ?><small class="text-muted ms-1" style="font-size:11px;"><?= $s[4] ?></small><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filtres -->
    <div class="bg-white border rounded-3 p-3 mb-4 shadow-sm">
        <form id="searchForm" onsubmit="return false;">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <label for="searchInput" class="text-uppercase small fw-bold text-muted mb-0">
                    <i class="bi bi-search"></i> Recherche
                </label>
                <input type="text" id="searchInput" class="form-control" style="max-width:220px;" placeholder="Référence, produit...">

                <label for="produitFilter" class="text-uppercase small fw-bold text-muted mb-0">
                    <i class="bi bi-box-seam"></i> Produit
                </label>
                <select id="produitFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un produit...">
                    <option value="">Tous les produits</option>
                    <?php foreach ($produits as $p): ?>
                        <option value="<?= e($p['code_produit']) ?>"><?= e($p['titre_produit']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="boutiqueFilter" class="text-uppercase small fw-bold text-muted mb-0">
                    <i class="bi bi-shop"></i> Boutique
                </label>
                <select id="boutiqueFilter" class="selectpicker" data-live-search="true">
                    <option value="">Toutes les boutiques</option>
                    <?php foreach ($boutiques as $b): ?>
                        <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="button" class="btn-chic btn-chic-primary" id="filterBtn">
                    <i class="bi bi-funnel"></i><span>Filtrer</span>
                </button>
                <button type="button" class="btn-chic btn-chic-secondary" id="resetBtn">
                    <i class="bi bi-arrow-counterclockwise"></i><span>Réinitialiser</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Tableau -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">
                <i class="bi bi-list-ul text-primary me-2"></i>Historique des transferts
            </h5>
            <span class="text-muted small" id="totalCount">
                <?= $transfertsData['total'] ?> transfert(s) - Page <?= $transfertsData['page'] ?> / <?= max(1, $transfertsData['totalPages']) ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Date</th>
                        <th>Produit</th>
                        <th>Source</th>
                        <th>Destination</th>
                        <th class="text-center">Qté</th>
                        <th class="text-center">Statut</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if (empty($transfertsData['transferts'])): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2.5rem;"></i>
                            <div class="fw-semibold">Aucun transfert</div>
                            <div class="small">Les transferts apparaîtront ici dès leur création.</div>
                        </td></tr>
                    <?php else:
                        foreach ($transfertsData['transferts'] as $t): ?>
                            <tr>
                                <td><span class="ref-chic"><?= e($t['transfert_id']) ?></span></td>
                                <td><span class="date-chic"><i class="bi bi-calendar3"></i> <?= date('d/m/Y', strtotime($t['date_transfert'])) ?></span></td>
                                <td class="fw-semibold"><?= e($t['titre_produit']) ?></td>
                                <td><span class="badge-chic source"><span class="dot"></span> <?= e($t['nom_source']) ?></span></td>
                                <td><span class="badge-chic dest"><span class="dot"></span> <?= e($t['nom_dest']) ?></span></td>
                                <td class="text-center"><span class="qty-chic"><?= (int)$t['quantite_transferee'] ?></span></td>
                                <td class="text-center"><span class="statut-chic valide"><i class="bi bi-check-circle-fill"></i> Validé</span></td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
        <div id="paginationContainer">
            <?php if ($transfertsData['totalPages'] > 1): ?>
                <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
                    <span class="text-muted small">
                        Affichage de <?= (($transfertsData['page'] - 1) * 10 + 1) ?> à <?= min($transfertsData['page'] * 10, $transfertsData['total']) ?> sur <?= $transfertsData['total'] ?>
                    </span>
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
                            for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item <?= ($i == $transfertsData['page']) ? 'active' : '' ?>">
                                    <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor;
                            if ($end < $transfertsData['totalPages']) {
                                if ($end < $transfertsData['totalPages'] - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $transfertsData['totalPages'] . '">' . $transfertsData['totalPages'] . '</a></li>';
                            } ?>
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
<div class="modal fade modal-chic" id="transfertModal" tabindex="-1" aria-labelledby="transfertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="transfertModalLabel">
                    <i class="bi bi-arrow-left-right"></i>
                    <span>Nouveau transfert de stock</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="transfertForm">
                <input type="hidden" name="action" value="transferer_stock">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Produit <span class="text-danger">*</span></label>
                            <select name="produit_id" class="form-select" required>
                                <option value="">-- Sélectionner un produit --</option>
                                <?php foreach ($produits as $p): ?>
                                    <option value="<?= e($p['code_produit']) ?>"><?= e($p['titre_produit']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Lot / Unité</label>
                            <select name="lot_id" class="form-select">
                                <option value="">Unité</option>
                                <?php foreach ($lots as $l): ?>
                                    <option value="<?= e($l['code_lot']) ?>"><?= e($l['libelle']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantité (unités) <span class="text-danger">*</span></label>
                            <input type="number" name="quantite" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date du transfert</label>
                            <input type="date" name="date_transfert" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <hr class="my-4" style="border-color: var(--border-color);">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">
                                <i class="bi bi-box-arrow-right text-warning"></i> Boutique source <span class="text-danger">*</span>
                            </label>
                            <select name="boutique_source" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($boutiques as $b): ?>
                                    <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                <i class="bi bi-box-arrow-in-down text-success"></i> Boutique destination <span class="text-danger">*</span>
                            </label>
                            <select name="boutique_dest" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($boutiques as $b): ?>
                                    <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-chic btn-chic-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i><span>Annuler</span>
                    </button>
                    <button type="submit" class="btn-chic btn-chic-primary">
                        <i class="bi bi-check-lg"></i><span>Effectuer le transfert</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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
$(document).ready(function() {
    $('.selectpicker').selectpicker();

    const toastEl = document.getElementById('toastMsg');
    const toast = new bootstrap.Toast(toastEl, { delay: 2500 });

    function showToast(msg, type = 'success') {
        const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-primary' };
        const icons  = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        $('#toastBody').html(`<i class="bi ${icons[type]} me-2"></i>${msg}`);
        toastEl.className = `toast align-items-center text-white border-0 ${colors[type]}`;
        toast.show();
    }

    // - Modal Nouveau transfert -
    const transfertModal = new bootstrap.Modal(document.getElementById('transfertModal'));
    $('#addBtn').on('click', function() {
        $('#transfertForm')[0].reset();
        $('#transfertForm input[name="date_transfert"]').val(new Date().toISOString().split('T')[0]);
        transfertModal.show();
    });

    // - Recherche et filtres AJAX -
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
                bindPagination();
            },
            error: function() {
                showToast('Erreur de communication', 'error');
            }
        });
    }

    function bindPagination() {
        $('#paginationContainer .page-link').off('click').on('click', function(e) {
            e.preventDefault();
            var p = $(this).data('page');
            if (p && !$(this).parent().hasClass('disabled')) rechercher(p);
        });
    }
    bindPagination();

    $('#filterBtn').on('click', function() { rechercher(1); });
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#produitFilter').selectpicker('val', '');
        $('#boutiqueFilter').selectpicker('val', '');
        rechercher(1);
        showToast('Filtres réinitialisés', 'info');
    });

    $('#searchInput').on('keyup', function(e) {
        if (e.key === 'Enter') rechercher(1);
    });
});
</script>
</body>
</html>