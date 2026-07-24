<?php
// views/transferts/index.php – Gestion des transferts de stock (design dashboard)
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require_once 'databases/database.php';

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

        // Validation de base
        if (empty($produitId) || empty($sourceId) || empty($destId) || $quantiteSaisie <= 0 || $sourceId === $destId) {
            $message = "Vérifiez les champs : produit, boutiques différentes, quantité > 0.";
            $messageType = 'error';
        } else {
            // Récupération des infos du lot (si fourni)
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
                    // Si le lot n'existe pas pour ce produit, on ignore le lot
                    $lotId = null;
                }
            }
            $quantiteBase = $quantiteSaisie * $facteur;

            try {
                $pdo->beginTransaction();

                // ---- 1. Vérification et mise à jour du stock source (avec verrou) ----
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

                // ---- 2. Création de la référence commune ----
                $codeTransfert = 'TRF-' . date('YmdHis') . rand(100, 999);

                // ---- 3. Insertion de la sortie (statut 008) ----
                $num1 = $codeTransfert . '-S';
                $stmt = $pdo->prepare("INSERT INTO commande 
                    (numero_commande, produit_id, statut_id, date_commande, heure_commande,
                     prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id,
                     boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion,
                     reference_liee, montant_rembourse, motif_retour, type_retour)
                    VALUES (?, ?, '008', ?, CURTIME(), 0, 0, ?, 0, ?, ?, 'Valider', ?, ?, ?, ?, 0, '', '')");
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
                    $codeTransfert
                ]);

                // ---- 4. Mise à jour du stock source ----
                $pdo->prepare("UPDATE stock_boutique SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                    ->execute([$stockApresSrc, $produitId, $sourceId]);

                // ---- 5. Vérification / création du stock destination ----
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

                // ---- 6. Insertion de l'entrée (statut 009) ----
                $num2 = $codeTransfert . '-D';
                $stmt = $pdo->prepare("INSERT INTO commande 
                    (numero_commande, produit_id, statut_id, date_commande, heure_commande,
                     prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id,
                     boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion,
                     reference_liee, montant_rembourse, motif_retour, type_retour)
                    VALUES (?, ?, '009', ?, CURTIME(), 0, 0, ?, 0, ?, ?, 'Valider', ?, ?, ?, ?, 0, '', '')");
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
                    $codeTransfert
                ]);

                // ---- 7. Mise à jour du stock destination ----
                $pdo->prepare("UPDATE stock_boutique SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                    ->execute([$stockApresDest, $produitId, $destId]);

                // ---- 8. Mise à jour du stock global (dans la transaction) ----
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
    // Construction de la requête de base : on veut regrouper par reference_liee
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

    // Filtre recherche (référence ou produit)
    if (!empty($search)) {
        $sql .= " AND (c.reference_liee LIKE ? OR c.produit_id IN (SELECT code_produit FROM produit WHERE titre_produit LIKE ?))";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    // Filtre produit
    if (!empty($produit_filter)) {
        $sql .= " AND c.produit_id = ?";
        $params[] = $produit_filter;
    }
    // Filtre boutique : on filtre sur les lignes dont la boutique source ou destination correspond
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

    // Pagination
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
        // Boutique source
        if ($t['boutique_source']) {
            $stmt = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
            $stmt->execute([$t['boutique_source']]);
            $t['nom_source'] = $stmt->fetchColumn();
        } else {
            $t['nom_source'] = '—';
        }
        // Boutique destination
        if ($t['boutique_dest']) {
            $stmt = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
            $stmt->execute([$t['boutique_dest']]);
            $t['nom_dest'] = $stmt->fetchColumn();
        } else {
            $t['nom_dest'] = '—';
        }
        // Produit
        if ($t['produit_id']) {
            $stmt = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
            $stmt->execute([$t['produit_id']]);
            $t['titre_produit'] = $stmt->fetchColumn();
        } else {
            $t['titre_produit'] = '—';
        }
        // Quantité totale transférée (on prend la sortie, ou l'entrée si sortie nulle)
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
// 1. Filtrage / Pagination
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $produit_filter = trim($_POST['produit_filter'] ?? '');
    $boutique_filter = trim($_POST['boutique_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;

    $data = getTransferts($pdo, $search, $produit_filter, $boutique_filter, $page, 10);

    // Génération du tableau HTML
    ob_start();
    if (empty($data['transferts'])): ?>
        <tr>
            <td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>Aucun transfert</td>
        </tr>
        <?php else: foreach ($data['transferts'] as $t): ?>
            <tr>
                <td class="td-bold"><?= e($t['transfert_id']) ?></td>
                <td><?= e($t['date_transfert']) ?></td>
                <td><?= e($t['titre_produit'] ?? $t['produit_id']) ?></td>
                <td><?= e($t['nom_source']) ?></td>
                <td><?= e($t['nom_dest']) ?></td>
                <td><?= $t['quantite'] ?></td>
                <td class="text-end">
                    <button class="act-btn v viewBtn" data-transfert="<?= e($t['transfert_id']) ?>" title="Détail"><i class="bi bi-eye"></i></button>
                </td>
            </tr>
        <?php endforeach;
    endif;
    $tableHtml = ob_get_clean();

    // Pagination
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

// 2. Détail d'un transfert
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_transfert_detail') {
    $transfertId = trim($_POST['transfert_id'] ?? '');
    if ($transfertId) {
        $stmt = $pdo->prepare("
            SELECT produit_id, quantite_commande, unite_affichage, 
                   facteur_conversion, boutique_id, statut_id
            FROM commande
            WHERE reference_liee = ? AND statut_id IN ('008','009')
            ORDER BY statut_id DESC
        ");
        $stmt->execute([$transfertId]);
        $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lignes as &$ligne) {
            // Produit
            $stmtProd = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
            $stmtProd->execute([$ligne['produit_id']]);
            $ligne['titre_produit'] = $stmtProd->fetchColumn() ?: $ligne['produit_id'];
            // Boutique
            $stmtBout = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
            $stmtBout->execute([$ligne['boutique_id']]);
            $ligne['nom_boutique'] = $stmtBout->fetchColumn() ?: $ligne['boutique_id'];
            // Type
            $ligne['type_mouvement'] = ($ligne['statut_id'] == '008') ? 'Sortie' : 'Entrée';
            $ligne['badge_class'] = ($ligne['statut_id'] == '008') ? 'bg-danger' : 'bg-success';
        }
        unset($ligne);
        header('Content-Type: application/json');
        echo json_encode($lignes);
        exit;
    }
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

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
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

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            min-width: 700px;
            margin-bottom: 0;
        }

        .table> :not(caption)>*>* {
            padding: 8px 12px;
        }

        .table thead th {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-quaternary);
            background: var(--bg-muted);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
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
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .td-bold {
            font-weight: 700;
            color: var(--text-primary) !important;
        }

        .act-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-quaternary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-base);
            font-size: 0.8rem;
        }

        .act-btn:hover {
            transform: scale(1.1);
        }

        .act-btn.v:hover {
            color: var(--color-primary);
            background: var(--color-primary-soft);
            border-color: rgba(79, 70, 229, 0.15);
        }

        .search-inline {
            display: flex;
            align-items: center;
            background: var(--bg-muted);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0 12px;
            height: 42px;
            min-width: 160px;
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
            margin-left: 8px;
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
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--color-primary-dark);
            border-color: var(--color-primary-dark);
            color: #fff;
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

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .data-table-wrap {
            animation: fadeUp .4s ease both;
        }

        @media (max-width:768px) {
            body {
                padding: 10px;
            }

            .table thead th,
            .table tbody td {
                font-size: 0.65rem;
                padding: 4px 6px;
            }

            .table .act-btn {
                width: 26px;
                height: 26px;
                font-size: 0.65rem;
            }

            .page-heading h2 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>

<body>
    <div class="container-crud">
        <!-- En-tête -->
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
            <div class="page-heading">
                <h2 class="fw-800 mb-0">Gestion des transferts de stock</h2>
                <p class="text-tertiary mt-1">Suivez vos mouvements entre boutiques</p>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau transfert</button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= e($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Barre de recherche -->
        <div class="bg-light p-3 rounded-3 mb-3 border">
            <form id="searchForm" method="post" onsubmit="return false;">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="page" id="pageInput" value="<?= e($page) ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="searchInput" class="form-label fw-semibold small">Recherche</label>
                        <div class="search-inline" style="min-width:100%; height:42px;">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search" id="searchInput" placeholder="Réf., produit..." value="<?= e($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="produitFilter" class="form-label fw-semibold small">Produit</label>
                        <select name="produit_filter" id="produitFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un produit...">
                            <option value="">Tous</option>
                            <?php foreach ($produits as $p): ?>
                                <option value="<?= e($p['code_produit']) ?>" <?= ($produit_filter == $p['code_produit']) ? 'selected' : '' ?>><?= e($p['titre_produit']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="boutiqueFilter" class="form-label fw-semibold small">Boutique (source ou destination)</label>
                        <select name="boutique_filter" id="boutiqueFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher une boutique...">
                            <option value="">Toutes</option>
                            <?php foreach ($boutiques as $b): ?>
                                <option value="<?= e($b['code_boutique']) ?>" <?= ($boutique_filter == $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-primary w-100" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-outline-secondary w-100" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tableau -->
        <div class="data-table-wrap" id="tableWrapper">
            <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
                <h5 class="mb-0 fw-bold">Historique des transferts</h5>
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
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (empty($transfertsData['transferts'])): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-inbox d-block mb-2 opacity-50" style="font-size:2rem;"></i>Aucun transfert</td>
                            </tr>
                            <?php else: foreach ($transfertsData['transferts'] as $t): ?>
                                <tr>
                                    <td class="td-bold"><?= e($t['transfert_id']) ?></td>
                                    <td><?= e($t['date_transfert']) ?></td>
                                    <td><?= e($t['titre_produit'] ?? $t['produit_id']) ?></td>
                                    <td><?= e($t['nom_source']) ?></td>
                                    <td><?= e($t['nom_dest']) ?></td>
                                    <td><?= $t['quantite'] ?></td>
                                    <td class="text-end">
                                        <button class="act-btn v viewBtn" data-transfert="<?= e($t['transfert_id']) ?>" title="Détail"><i class="bi bi-eye"></i></button>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
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

    <!-- Modal Nouveau transfert -->
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

    <!-- Modal Détail -->
    <div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-eye text-primary me-2"></i> Détail du transfert</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Produit</th>
                                    <th>Unité</th>
                                    <th>Quantité (base)</th>
                                    <th>Type</th>
                                    <th>Boutique</th>
                                </tr>
                            </thead>
                            <tbody id="detailLignes"></tbody>
                        </table>
                    </div>
                </div>
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

            // ---- Données des lots (injectées depuis PHP) ----
            const lotsParProduit = <?= json_encode($lotsParProduit) ?>;

            // ---- Mise à jour des lots en fonction du produit sélectionné ----
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

            // ---- Événement sur le select produit dans le modal ----
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

            // ---- Détail AJAX ----
            function voirDetailTransfert(transfertId) {
                fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'action=get_transfert_detail&transfert_id=' + encodeURIComponent(transfertId)
                    })
                    .then(r => r.json())
                    .then(data => {
                        let html = '';
                        data.forEach(ligne => {
                            html += `<tr>
                    <td>${ligne.titre_produit}</td>
                    <td>${ligne.unite_affichage || 'Unité'}</td>
                    <td>${ligne.quantite_commande}</td>
                    <td><span class="badge ${ligne.badge_class}">${ligne.type_mouvement}</span></td>
                    <td>${ligne.nom_boutique}</td>
                </tr>`;
                        });
                        document.getElementById('detailLignes').innerHTML = html;
                        new bootstrap.Modal(document.getElementById('detailModal')).show();
                    })
                    .catch(err => alert('Erreur: ' + err));
            }

            $(document).on('click', '.viewBtn', function() {
                const transfertId = $(this).data('transfert');
                voirDetailTransfert(transfertId);
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
                        // Ré-attacher les événements de pagination
                        $('.page-link').off('click').on('click', function(e) {
                            e.preventDefault();
                            var p = $(this).data('page');
                            if (p) rechercher(p);
                        });
                        // Ré-attacher les événements des boutons de détail (les nouveaux boutons)
                        $('.viewBtn').off('click').on('click', function() {
                            const id = $(this).data('transfert');
                            voirDetailTransfert(id);
                        });
                    },
                    error: function() {
                        alert('Erreur lors de la recherche.');
                    }
                });
            }

            // Déclencheurs avec debounce
            var searchTimeout = null;
            $('#searchInput').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });
            $('#produitFilter, #boutiqueFilter').on('changed.bs.select', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });
            $('#filterBtn').on('click', function() {
                rechercher(1);
            });
            $('#resetBtn').on('click', function() {
                $('#searchInput').val('');
                $('#produitFilter').selectpicker('val', '');
                $('#boutiqueFilter').selectpicker('val', '');
                rechercher(1);
            });

            // Gestion initiale des clics sur les liens de pagination (pour la première charge)
            $(document).on('click', '.page-link', function(e) {
                e.preventDefault();
                var p = $(this).data('page');
                if (p) rechercher(p);
            });
        });
    </script>
</body>

</html>