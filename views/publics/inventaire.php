<?php
// inventaire.php – Gestion de l'inventaire avec lots (conditionnements)
require_once 'databases/database.php';

// --- Gestion de session (à décommenter si besoin) ---
session_start();
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

// --- Utilitaires ---
function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}

// --- CSRF ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Traitement des actions POST ---
$message = '';
$messageType = 'success';
$filter = $_POST['filter'] ?? 'all';
$search = trim($_POST['search'] ?? '');
$page = max(1, intval($_POST['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$baseWhere = "WHERE p.etat_produit = 'Actif'";
$where = $baseWhere;

if ($filter === 'zero') {
    // Stock = 0 (total des lots ou stock_produit)
    $where .= " AND (COALESCE((SELECT SUM(quantite) FROM lot_produit WHERE produit_id = p.code_produit AND etat_lot = 'Actif'), p.stock_produit, 0) = 0)";
} elseif ($filter === 'alert') {
    $where .= " AND (COALESCE((SELECT SUM(quantite) FROM lot_produit WHERE produit_id = p.code_produit AND etat_lot = 'Actif'), p.stock_produit, 0) > 0)
                AND (COALESCE((SELECT SUM(quantite) FROM lot_produit WHERE produit_id = p.code_produit AND etat_lot = 'Actif'), p.stock_produit, 0) <= p.stock_alerte)";
} elseif ($filter === 'ok') {
    $where .= " AND (COALESCE((SELECT SUM(quantite) FROM lot_produit WHERE produit_id = p.code_produit AND etat_lot = 'Actif'), p.stock_produit, 0) > p.stock_alerte)";
}

if (!empty($search)) {
    $where .= " AND (p.code_produit LIKE :search OR p.titre_produit LIKE :search)";
}

// --- Badges avec vrai stock (lots) ---
$badgeCounts = [];
foreach (['zero', 'alert', 'ok'] as $f) {
    $w = $baseWhere;
    if ($f === 'zero') {
        $w .= " AND (COALESCE((SELECT SUM(quantite) FROM lot_produit WHERE produit_id = p.code_produit AND etat_lot = 'Actif'), p.stock_produit, 0) = 0)";
    } elseif ($f === 'alert') {
        $w .= " AND (COALESCE((SELECT SUM(quantite) FROM lot_produit WHERE produit_id = p.code_produit AND etat_lot = 'Actif'), p.stock_produit, 0) > 0)
                AND (COALESCE((SELECT SUM(quantite) FROM lot_produit WHERE produit_id = p.code_produit AND etat_lot = 'Actif'), p.stock_produit, 0) <= p.stock_alerte)";
    } elseif ($f === 'ok') {
        $w .= " AND (COALESCE((SELECT SUM(quantite) FROM lot_produit WHERE produit_id = p.code_produit AND etat_lot = 'Actif'), p.stock_produit, 0) > p.stock_alerte)";
    }
    $s = $pdo->prepare("SELECT COUNT(*) FROM produit p $w");
    $s->execute();
    $badgeCounts[$f] = $s->fetchColumn();
}
$totalAll = array_sum($badgeCounts); // simplification

// --- Requête principale avec stock_lots ---
$sql = "SELECT p.*, c.titre_categorie,
               COALESCE((SELECT SUM(quantite) FROM lot_produit WHERE produit_id = p.code_produit AND etat_lot = 'Actif'), p.stock_produit, 0) AS stock_reel,
               (SELECT COUNT(*) FROM lot_produit WHERE produit_id = p.code_produit AND etat_lot = 'Actif') AS nb_lots
        FROM produit p 
        LEFT JOIN categorie c ON p.categorie_id = c.code_categorie 
        $where
        ORDER BY p.titre_produit ASC
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
if (!empty($search)) {
    $stmt->bindValue(':search', '%' . $search . '%');
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total pour pagination
$total = ($filter === 'all') ? $totalAll : ($badgeCounts[$filter] ?? 0);
$totalPages = ($limit > 0) ? ceil($total / $limit) : 1;

// --- Traitement des actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        $action = $_POST['action'];
        $code = $_POST['code_produit'] ?? '';

        // Gestion des lots
        if ($action === 'ajouter_lot') {
            $produit_id = trim($_POST['produit_id'] ?? '');
            $titre_lot = trim($_POST['titre_lot'] ?? '');
            $unites_par_lot = intval($_POST['unites_par_lot'] ?? 1);
            $quantite = intval($_POST['quantite_lot'] ?? 0);
            $etat_lot = trim($_POST['etat_lot'] ?? 'Actif');

            if (empty($produit_id) || empty($titre_lot) || $unites_par_lot <= 0 || $quantite < 0) {
                $message = "Tous les champs sont requis et valides.";
                $messageType = 'error';
            } else {
                try {
                    $check = $pdo->prepare("SELECT COUNT(*) FROM lot_produit WHERE produit_id = ? AND titre_lot = ?");
                    $check->execute([$produit_id, $titre_lot]);
                    if ($check->fetchColumn() > 0) {
                        $message = "Un lot avec ce titre existe déjà.";
                        $messageType = 'error';
                    } else {
                        $code_lot = 'LOT-' . date('YmdHis') . rand(100, 999);
                        $stmt = $pdo->prepare("INSERT INTO lot_produit (code_lot_produit, produit_id, titre_lot, unites_par_lot, quantite, etat_lot)
                                                VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$code_lot, $produit_id, $titre_lot, $unites_par_lot, $quantite, $etat_lot]);
                        $message = "Conditionnement « $titre_lot » ajouté avec succès.";
                        $messageType = 'success';
                    }
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'error';
                }
            }
        } elseif ($action === 'modifier_lot') {
            $code_lot = trim($_POST['code_lot'] ?? '');
            $titre_lot = trim($_POST['titre_lot'] ?? '');
            $unites_par_lot = intval($_POST['unites_par_lot'] ?? 1);
            $etat_lot = trim($_POST['etat_lot'] ?? 'Actif');

            if (empty($code_lot) || empty($titre_lot) || $unites_par_lot <= 0) {
                $message = "Tous les champs sont requis.";
                $messageType = 'error';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE lot_produit SET titre_lot = ?, unites_par_lot = ?, etat_lot = ? WHERE code_lot_produit = ?");
                    $stmt->execute([$titre_lot, $unites_par_lot, $etat_lot, $code_lot]);
                    $message = "Conditionnement mis à jour.";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'error';
                }
            }
        } elseif ($action === 'supprimer_lot') {
            $code_lot = trim($_POST['code_lot'] ?? '');
            if (!empty($code_lot)) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM lot_produit WHERE code_lot_produit = ?");
                    $stmt->execute([$code_lot]);
                    $message = "Conditionnement supprimé.";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'error';
                }
            }
        } elseif ($action === 'ajuster_lot') {
            // Ajuster la quantité d'un lot
            $code_lot = trim($_POST['code_lot'] ?? '');
            $quantite = intval($_POST['quantite'] ?? 0);
            if (!empty($code_lot) && $quantite >= 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE lot_produit SET quantite = ? WHERE code_lot_produit = ?");
                    $stmt->execute([$quantite, $code_lot]);
                    // Si quantité = 0, on peut mettre l'état Inactif? (optionnel)
                    $message = "Quantité du lot mise à jour.";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'error';
                }
            } else {
                $message = "Quantité invalide.";
                $messageType = 'error';
            }
        }

        // Gestion du stock (fallback : agit sur stock_produit si pas de lots)
        elseif ($action === 'update_stock') {
            $quantite = intval($_POST['quantite'] ?? 0);
            $type = $_POST['type'] ?? 'add';
            if ($code && $quantite > 0) {
                // On regarde si ce produit a des lots actifs
                $checkLots = $pdo->prepare("SELECT COUNT(*) FROM lot_produit WHERE produit_id = ? AND etat_lot = 'Actif'");
                $checkLots->execute([$code]);
                $hasLots = $checkLots->fetchColumn() > 0;
                if ($hasLots) {
                    $message = "Ce produit a des lots. Utilisez la gestion des lots pour ajuster les quantités.";
                    $messageType = 'warning';
                } else {
                    // Pas de lots : on agit sur stock_produit
                    $check = $pdo->prepare("SELECT COALESCE(stock_produit, 0) FROM produit WHERE code_produit = :code");
                    $check->execute(['code' => $code]);
                    $stockActuel = intval($check->fetchColumn() ?? 0);
                    if ($type === 'remove' && $stockActuel - $quantite < 0) {
                        $message = "Stock insuffisant (actuel : $stockActuel).";
                        $messageType = 'error';
                    } else {
                        $signe = ($type === 'add') ? '+' : '-';
                        $sqlUp = "UPDATE produit SET stock_produit = COALESCE(stock_produit, 0) $signe :qte WHERE code_produit = :code";
                        $stmtUp = $pdo->prepare($sqlUp);
                        $stmtUp->execute(['qte' => $quantite, 'code' => $code]);
                        $message = "Stock mis à jour.";
                        $messageType = 'success';
                    }
                }
            } else {
                $message = "Quantité invalide.";
                $messageType = 'error';
            }
        } elseif ($action === 'update_info') {
            $prix_vente = floatval($_POST['prix_produit'] ?? 0);
            $prix_fournisseur = floatval($_POST['prix_fournisseur'] ?? 0);
            $stock_alerte = intval($_POST['stock_alerte'] ?? 0);
            if ($code) {
                $sqlUp = "UPDATE produit SET prix_produit = :pv, prix_fournisseur = :pf, stock_alerte = :sa WHERE code_produit = :code";
                $stmtUp = $pdo->prepare($sqlUp);
                $stmtUp->execute(['pv' => $prix_vente, 'pf' => $prix_fournisseur, 'sa' => $stock_alerte, 'code' => $code]);
                $message = "Informations mises à jour.";
                $messageType = 'success';
            }
        }
    }
}

// --- Récupération des lots pour la modale (via AJAX ou POST) ---
$lots_produit = [];
$produit_selected = '';
if (isset($_POST['ajax_lots']) && $_POST['ajax_lots'] == '1' && isset($_POST['produit_id'])) {
    $produit_selected = $_POST['produit_id'];
    $stmt = $pdo->prepare("SELECT * FROM lot_produit WHERE produit_id = ? ORDER BY titre_lot");
    $stmt->execute([$produit_selected]);
    $lots_produit = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Retourner le HTML pour la liste des lots (utilisé par AJAX)
    ob_start();
    if (empty($lots_produit)): ?>
        <p class="text-muted text-center" style="padding:16px 0;">Aucun conditionnement pour ce produit.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Titre</th>
                    <th>Unités/lot</th>
                    <th>Quantité</th>
                    <th>État</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lots_produit as $lot): ?>
                    <tr>
                        <td><?= e($lot['titre_lot']) ?></td>
                        <td><?= $lot['unites_par_lot'] ?></td>
                        <td>
                            <form method="POST" style="display:inline-flex; gap:4px; align-items:center;">
                                <input type="hidden" name="action" value="ajuster_lot">
                                <input type="hidden" name="code_lot" value="<?= e($lot['code_lot_produit']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="number" name="quantite" value="<?= $lot['quantite'] ?>" min="0" style="width:60px; padding:2px 4px; border-radius:4px; border:1px solid #ccc;">
                                <button type="submit" class="btn btn-sm btn-success" title="Mettre à jour"><i class="bi bi-check-lg"></i></button>
                            </form>
                        </td>
                        <td><span class="badge <?= $lot['etat_lot'] === 'Actif' ? 'bg-success' : 'bg-secondary' ?>"><?= e($lot['etat_lot']) ?></span></td>
                        <td class="text-end">
                            <div class="lot-actions">
                                <button class="btn btn-sm btn-warning" onclick="editLot('<?= e($lot['code_lot_produit']) ?>', '<?= e($lot['titre_lot']) ?>', <?= $lot['unites_par_lot'] ?>, '<?= e($lot['etat_lot']) ?>')">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="supprimer_lot">
                                    <input type="hidden" name="code_lot" value="<?= e($lot['code_lot_produit']) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Supprimer ce conditionnement ?')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
<?php endif;
    $html = ob_get_clean();
    echo $html;
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventaire des produits</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Bootstrap CSS (uniquement pour les modales et quelques éléments) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* ===== STYLES DASHBOARD (identique à l'original) ===== */
        :root {
            --blue-primary: #2563eb;
            --blue-dark: #1d4ed8;
            --blue-light: #eff6ff;
            --blue-border: #bfdbfe;
            --bg-app: #f8fafc;
            --card-white: #ffffff;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --danger-border: #fecaca;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --warning-border: #fde68a;
            --success: #10b981;
            --success-light: #d1fae5;
            --success-border: #a7f3d0;
            --radius: 12px;
            --radius-sm: 8px;
            --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-app);
            color: var(--text-dark);
            min-height: 100vh;
            font-size: 14px;
            padding: 20px;
        }

        button {
            cursor: pointer;
            font-family: inherit;
            border: none;
        }

        input,
        select {
            font-family: inherit;
            outline: none;
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        .page-wrap {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 16px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i {
            color: var(--blue-primary);
            font-size: 26px;
        }

        .page-title small {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-muted);
            margin-left: 8px;
        }

        .page-stats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .stat-badge {
            background: var(--card-white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: var(--shadow-card);
        }

        .stat-badge .num {
            color: var(--text-dark);
            font-weight: 700;
        }

        .stat-badge .num.danger {
            color: var(--danger);
        }

        .stat-badge .num.warning {
            color: var(--warning);
        }

        .stat-badge .num.success {
            color: var(--success);
        }

        .stat-badge i {
            font-size: 14px;
        }

        .search-bar {
            background: var(--card-white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-card);
            flex-wrap: wrap;
        }

        .search-bar .search-input-wrap {
            flex: 1;
            min-width: 200px;
            display: flex;
            align-items: center;
            background: var(--bg-app);
            border: 2px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 0 12px;
            transition: all 0.2s;
        }

        .search-bar .search-input-wrap:focus-within {
            border-color: var(--blue-primary);
            background: white;
            box-shadow: 0 0 0 4px var(--blue-light);
        }

        .search-bar .search-input-wrap i {
            color: var(--text-muted);
            font-size: 16px;
        }

        .search-bar .search-input-wrap input {
            border: none;
            background: transparent;
            padding: 10px 10px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-dark);
            width: 100%;
        }

        .search-bar .search-input-wrap input::placeholder {
            color: var(--text-muted);
        }

        .btn-search {
            background: var(--blue-primary);
            color: white;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
            white-space: nowrap;
        }

        .btn-search:hover {
            background: var(--blue-dark);
        }

        .btn-reset {
            background: white;
            color: var(--text-muted);
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            border: 1px solid var(--border-light);
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-reset:hover {
            background: #f1f5f9;
            color: var(--text-dark);
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-tab {
            background: white;
            border: 2px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            cursor: pointer;
        }

        .filter-tab:hover {
            border-color: var(--blue-border);
            color: var(--blue-primary);
            background: var(--blue-light);
        }

        .filter-tab.active {
            border-color: var(--blue-primary);
            background: var(--blue-light);
            color: var(--blue-dark);
        }

        .filter-tab .badge {
            background: #e2e8f0;
            color: var(--text-muted);
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 700;
        }

        .filter-tab.active .badge {
            background: var(--blue-primary);
            color: white;
        }

        .filter-tab.ft-zero .badge {
            background: var(--danger-light);
            color: var(--danger);
        }

        .filter-tab.ft-zero.active .badge {
            background: var(--danger);
            color: white;
        }

        .filter-tab.ft-zero.active {
            border-color: var(--danger);
            background: var(--danger-light);
            color: var(--danger);
        }

        .filter-tab.ft-alert .badge {
            background: var(--warning-light);
            color: var(--warning);
        }

        .filter-tab.ft-alert.active .badge {
            background: var(--warning);
            color: white;
        }

        .filter-tab.ft-alert.active {
            border-color: var(--warning);
            background: var(--warning-light);
            color: #92400e;
        }

        .filter-tab.ft-ok .badge {
            background: var(--success-light);
            color: var(--success);
        }

        .filter-tab.ft-ok.active .badge {
            background: var(--success);
            color: white;
        }

        .filter-tab.ft-ok.active {
            border-color: var(--success);
            background: var(--success-light);
            color: #065f46;
        }

        .filter-tab i {
            font-size: 16px;
        }

        .table-card {
            background: var(--card-white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            box-shadow: var(--shadow-card);
            overflow: hidden;
        }

        .table-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-light);
        }

        .table-header h5 {
            font-weight: 700;
            font-size: 15px;
            color: var(--text-dark);
            margin: 0;
        }

        .table-header .count {
            font-size: 12px;
            color: var(--text-muted);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        thead th {
            background: var(--blue-primary);
            color: white;
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            letter-spacing: 0.03em;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-light);
            color: var(--text-dark);
            vertical-align: middle;
        }

        tbody tr {
            transition: background 0.15s;
        }

        tbody tr:hover {
            background: var(--blue-light);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .code-prod {
            font-family: monospace;
            font-size: 11px;
            color: var(--text-muted);
        }

        .titre-prod {
            font-weight: 600;
            color: var(--text-dark);
        }

        .stock-danger {
            color: var(--danger);
            font-weight: 700;
        }

        .stock-warning {
            color: var(--warning);
            font-weight: 700;
        }

        .stock-normal {
            color: var(--success);
            font-weight: 600;
        }

        .empty-row td {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
            font-size: 14px;
        }

        .empty-row td i {
            font-size: 40px;
            opacity: 0.15;
            color: var(--blue-primary);
            display: block;
            margin-bottom: 8px;
        }

        .actions-cell {
            display: flex;
            gap: 4px;
            flex-wrap: nowrap;
            justify-content: flex-end;
        }

        .btn-act {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-act-stock {
            background: var(--success-light);
            color: #065f46;
            border: 1px solid var(--success-border);
        }

        .btn-act-stock:hover {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        .btn-act-stock:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .btn-act-edit {
            background: var(--warning-light);
            color: #92400e;
            border: 1px solid var(--warning-border);
        }

        .btn-act-edit:hover {
            background: var(--warning);
            color: white;
            border-color: var(--warning);
        }

        .btn-act-lot {
            background: var(--blue-light);
            color: var(--blue-dark);
            border: 1px solid var(--blue-border);
        }

        .btn-act-lot:hover {
            background: var(--blue-primary);
            color: white;
            border-color: var(--blue-primary);
        }

        .btn-act i {
            font-size: 13px;
        }

        .badge-lot {
            background: var(--blue-light);
            color: var(--blue-dark);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--blue-border);
        }

        .badge-lot.empty {
            background: #f1f5f9;
            color: var(--text-muted);
            border-color: var(--border-light);
        }

        .stock-lots-info {
            font-size: 10px;
            color: var(--text-muted);
            display: block;
            margin-top: 2px;
        }

        .pagination-bar {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            margin-top: 20px;
            flex-wrap: wrap;
            padding: 12px 0;
        }

        .pg-btn {
            background: white;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }

        .pg-btn:hover {
            border-color: var(--blue-primary);
            color: var(--blue-primary);
            background: var(--blue-light);
        }

        .pg-btn.active {
            background: var(--blue-primary);
            color: white;
            border-color: var(--blue-primary);
        }

        .pg-btn.disabled {
            color: #94a3b8;
            background: #f8fafc;
            cursor: not-allowed;
            border-color: var(--border-light);
        }

        /* MODALES */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 16px;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-box {
            background: white;
            border-radius: 16px;
            width: 700px;
            max-width: 100%;
            max-height: 90vh;
            box-shadow: 0 20px 25px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .modal-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .modal-head h3 {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-dark);
            margin: 0;
        }

        .modal-head h3 i {
            color: var(--blue-primary);
        }

        .modal-close {
            background: #f1f5f9;
            font-size: 18px;
            color: var(--text-muted);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: var(--danger-light);
            color: var(--danger);
        }

        .modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-foot {
            padding: 14px 20px;
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
            background: #f8fafc;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            border: 2px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 8px 12px;
            font-size: 14px;
            background: #f8fafc;
            color: var(--text-dark);
            transition: all 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--blue-primary);
            background: white;
            box-shadow: 0 0 0 3px var(--blue-light);
        }

        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }

        .product-ref {
            background: var(--blue-light);
            border: 1px solid var(--blue-border);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .product-ref .ref-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--blue-dark);
        }

        .product-ref .ref-stock {
            font-size: 12px;
            color: var(--text-muted);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: var(--text-dark);
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 14px;
            border: 1px solid var(--border-light);
            transition: all 0.2s;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        .btn-primary {
            background: var(--blue-primary);
            color: white;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 14px;
            transition: background 0.2s;
        }

        .btn-primary:hover {
            background: var(--blue-dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 14px;
            transition: background 0.2s;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 14px;
            transition: background 0.2s;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .lot-list {
            margin-top: 12px;
        }

        .lot-list table {
            font-size: 12px;
        }

        .lot-list table th {
            background: #f8fafc;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 10px;
            border-bottom: 1px solid var(--border-light);
        }

        .lot-list table td {
            padding: 6px 10px;
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
        }

        .lot-list table tr:hover td {
            background: var(--blue-light);
        }

        .lot-actions {
            display: flex;
            gap: 4px;
            justify-content: flex-end;
        }

        .lot-actions .btn-sm {
            padding: 2px 8px;
            font-size: 11px;
            border-radius: 4px;
        }

        .lot-edit-zone {
            display: none;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid var(--border-light);
        }

        .lot-edit-zone.show {
            display: block;
        }

        .lot-edit-zone h6 {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--text-dark);
        }

        .toast-notif {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--text-dark);
            color: white;
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            z-index: 2000;
            display: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            align-items: center;
            gap: 8px;
            max-width: 400px;
        }

        .toast-notif.error {
            background: var(--danger);
        }

        .toast-notif.success {
            background: var(--blue-primary);
        }

        /* responsive */
        @media (max-width: 900px) {
            body {
                padding: 12px;
            }

            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-bar .search-input-wrap {
                min-width: auto;
            }

            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }

            .table-card {
                overflow-x: auto;
            }

            table {
                min-width: 800px;
            }

            .actions-cell {
                flex-wrap: wrap;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .modal-box {
                width: 100%;
                max-height: 100vh;
                border-radius: 12px;
            }
        }

        @media (max-width: 600px) {
            .filter-tabs {
                gap: 4px;
            }

            .filter-tab {
                padding: 6px 10px;
                font-size: 11px;
            }

            .filter-tab .badge {
                font-size: 9px;
                padding: 1px 6px;
            }
        }

        @media print {
            body * {
                visibility: hidden;
            }

            .table-card,
            .table-card * {
                visibility: visible;
            }

            .table-card {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }

            .filter-tabs,
            .search-bar,
            .pagination-bar,
            .page-stats,
            .actions-cell {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <div class="page-wrap">

        <!-- ===== EN-TÊTE ===== -->
        <div class="page-header">
            <div class="page-title">
                <i class="bi bi-box-seam-fill"></i>
                Inventaire des produits
                <small><?= $totalAll ?> produits actifs</small>
            </div>
            <div class="page-stats">
                <div class="stat-badge"><i class="bi bi-exclamation-circle text-danger"></i> Rupture <span class="num danger"><?= $badgeCounts['zero'] ?></span></div>
                <div class="stat-badge"><i class="bi bi-exclamation-triangle text-warning"></i> Alerte <span class="num warning"><?= $badgeCounts['alert'] ?></span></div>
                <div class="stat-badge"><i class="bi bi-check-circle text-success"></i> OK <span class="num success"><?= $badgeCounts['ok'] ?></span></div>
            </div>
        </div>

        <!-- ===== BARRE DE RECHERCHE ===== -->
        <form method="POST" action="" id="mainForm">
            <input type="hidden" name="filter" id="hiddenFilter" value="<?= e($filter) ?>">
            <input type="hidden" name="page" id="hiddenPage" value="<?= e($page) ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

            <div class="search-bar">
                <div class="search-input-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Rechercher par code ou titre..." value="<?= e($search) ?>" id="searchInput">
                </div>
                <button type="submit" class="btn-search"><i class="bi bi-search"></i> Rechercher</button>
                <button type="button" class="btn-reset" onclick="resetSearch()"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>

        <!-- ===== FILTRES ===== -->
        <div class="filter-tabs">
            <form method="POST" style="display:inline;" class="filter-form">
                <input type="hidden" name="filter" value="all">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <button type="submit" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
                    <i class="bi bi-grid-3x3-gap-fill"></i> Tous <span class="badge"><?= $totalAll ?></span>
                </button>
            </form>
            <form method="POST" style="display:inline;" class="filter-form">
                <input type="hidden" name="filter" value="zero">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <button type="submit" class="filter-tab ft-zero <?= $filter === 'zero' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam"></i> Rupture <span class="badge"><?= $badgeCounts['zero'] ?></span>
                </button>
            </form>
            <form method="POST" style="display:inline;" class="filter-form">
                <input type="hidden" name="filter" value="alert">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <button type="submit" class="filter-tab ft-alert <?= $filter === 'alert' ? 'active' : '' ?>">
                    <i class="bi bi-exclamation-triangle-fill"></i> En alerte <span class="badge"><?= $badgeCounts['alert'] ?></span>
                </button>
            </form>
            <form method="POST" style="display:inline;" class="filter-form">
                <input type="hidden" name="filter" value="ok">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <button type="submit" class="filter-tab ft-ok <?= $filter === 'ok' ? 'active' : '' ?>">
                    <i class="bi bi-check-circle-fill"></i> Stock OK <span class="badge"><?= $badgeCounts['ok'] ?></span>
                </button>
            </form>
        </div>

        <!-- ===== TABLEAU ===== -->
        <div class="table-card">
            <div class="table-header">
                <h5>Liste des produits</h5>
                <span class="count"><?= $total ?> produit(s) - Page <?= $page ?> / <?= max(1, $totalPages) ?></span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Produit</th>
                            <th>Prix fourn.</th>
                            <th>Prix vente</th>
                            <th>Stock</th>
                            <th>Alerte</th>
                            <th>Lots</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($produits)): ?>
                            <tr class="empty-row">
                                <td colspan="8">
                                    <i class="bi bi-inbox"></i>
                                    Aucun produit trouvé pour ce filtre.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($produits as $p):
                                $stock = intval($p['stock_reel'] ?? 0);
                                $alerte = intval($p['stock_alerte'] ?? 0);
                                $classeStock = ($stock <= 0) ? 'stock-danger' : (($stock <= $alerte) ? 'stock-warning' : 'stock-normal');
                                $nb_lots = intval($p['nb_lots'] ?? 0);
                            ?>
                                <tr>
                                    <td class="code-prod"><?= e($p['code_produit']) ?></td>
                                    <td class="titre-prod"><?= e($p['titre_produit']) ?></td>
                                    <td><?= number_format((float)($p['prix_fournisseur'] ?? 0), 0, ',', ' ') ?> F</td>
                                    <td style="font-weight:700; color:var(--blue-primary);"><?= number_format((float)($p['prix_produit'] ?? 0), 0, ',', ' ') ?> F</td>
                                    <td class="<?= $classeStock ?>">
                                        <?= $stock ?>
                                        <?php if ($nb_lots > 0): ?>
                                            <span class="stock-lots-info"><i class="bi bi-boxes"></i> réparti en <?= $nb_lots ?> lot(s)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:var(--text-muted);"><?= $alerte ?></td>
                                    <td>
                                        <span class="badge-lot <?= $nb_lots > 0 ? '' : 'empty' ?>">
                                            <i class="bi bi-boxes"></i> <?= $nb_lots ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions-cell" style="justify-content:flex-end;">
                                            <button class="btn-act btn-act-stock" onclick="openStockModal('<?= e($p['code_produit']) ?>', '<?= e($p['titre_produit']) ?>', <?= $stock ?>, <?= $nb_lots ?>)">
                                                <i class="bi bi-plus-minus"></i> Stock
                                            </button>
                                            <button class="btn-act btn-act-edit" onclick="openEditModal('<?= e($p['code_produit']) ?>', '<?= e($p['titre_produit']) ?>', <?= (float)($p['prix_fournisseur'] ?? 0) ?>, <?= (float)($p['prix_produit'] ?? 0) ?>, <?= intval($p['stock_alerte'] ?? 0) ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn-act btn-act-lot" onclick="openLotModal('<?= e($p['code_produit']) ?>', '<?= e($p['titre_produit']) ?>')">
                                                <i class="bi bi-boxes"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ===== PAGINATION ===== -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination-bar">
                <?php if ($page > 1): ?>
                    <button class="pg-btn" onclick="goPage(<?= $page - 1 ?>)"><i class="bi bi-chevron-left"></i> Précédent</button>
                <?php else: ?>
                    <span class="pg-btn disabled"><i class="bi bi-chevron-left"></i> Précédent</span>
                <?php endif; ?>

                <?php
                $startP = max(1, $page - 2);
                $endP = min($totalPages, $page + 2);
                if ($startP > 1) echo '<button class="pg-btn" onclick="goPage(1)">1</button>';
                if ($startP > 2) echo '<span class="pg-btn disabled">…</span>';
                for ($i = $startP; $i <= $endP; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="pg-btn active"><?= $i ?></span>
                    <?php else: ?>
                        <button class="pg-btn" onclick="goPage(<?= $i ?>)"><?= $i ?></button>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($endP < $totalPages - 1) echo '<span class="pg-btn disabled">…</span>'; ?>
                <?php if ($endP < $totalPages) echo '<button class="pg-btn" onclick="goPage(' . $totalPages . ')">' . $totalPages . '</button>'; ?>

                <?php if ($page < $totalPages): ?>
                    <button class="pg-btn" onclick="goPage(<?= $page + 1 ?>)">Suivant <i class="bi bi-chevron-right"></i></button>
                <?php else: ?>
                    <span class="pg-btn disabled">Suivant <i class="bi bi-chevron-right"></i></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- ========================================================= -->
    <!-- MODAL GESTION DES LOTS -->
    <!-- ========================================================= -->
    <div class="modal-overlay" id="lotModal">
        <div class="modal-box">
            <div class="modal-head">
                <h3><i class="bi bi-boxes"></i> Conditionnements</h3>
                <button class="modal-close" onclick="closeModal('lotModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <div class="product-ref">
                    <span class="ref-name" id="lotProduitNom"></span>
                    <span class="ref-stock" id="lotProduitInfo"></span>
                </div>

                <!-- Formulaire d'ajout -->
                <form method="POST" action="" id="lotForm" style="margin-bottom:16px;">
                    <input type="hidden" name="action" value="ajouter_lot">
                    <input type="hidden" name="produit_id" id="lotProduitId">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="row" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
                        <div style="flex:2; min-width:120px;">
                            <label class="form-label small fw-bold">Titre</label>
                            <input type="text" name="titre_lot" class="form-control form-control-sm" placeholder="Ex: Boîte" required>
                        </div>
                        <div style="flex:1; min-width:70px;">
                            <label class="form-label small fw-bold">Unités/lot</label>
                            <input type="number" name="unites_par_lot" class="form-control form-control-sm" min="1" value="1" required>
                        </div>
                        <div style="flex:1; min-width:70px;">
                            <label class="form-label small fw-bold">Quantité</label>
                            <input type="number" name="quantite_lot" class="form-control form-control-sm" min="0" value="0">
                        </div>
                        <div style="flex:1; min-width:100px;">
                            <label class="form-label small fw-bold">État</label>
                            <select name="etat_lot" class="form-select form-select-sm">
                                <option value="Actif">Actif</option>
                                <option value="Inactif">Inactif</option>
                            </select>
                        </div>
                        <div style="flex:0 0 auto;">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Ajouter</button>
                        </div>
                    </div>
                </form>

                <!-- Liste des lots -->
                <div class="lot-list" id="lotListContainer">
                    <p class="text-muted text-center" style="padding:8px 0;">Chargement...</p>
                </div>

                <!-- Zone d'édition -->
                <div class="lot-edit-zone" id="lotEditZone">
                    <h6><i class="bi bi-pencil-square"></i> Modifier le conditionnement</h6>
                    <form method="POST" action="" id="lotEditForm">
                        <input type="hidden" name="action" value="modifier_lot">
                        <input type="hidden" name="code_lot" id="editLotCode">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <div class="row" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
                            <div style="flex:2; min-width:120px;">
                                <label class="form-label small fw-bold">Titre</label>
                                <input type="text" name="titre_lot" id="editLotTitre" class="form-control form-control-sm" required>
                            </div>
                            <div style="flex:1; min-width:70px;">
                                <label class="form-label small fw-bold">Unités/lot</label>
                                <input type="number" name="unites_par_lot" id="editLotUnites" class="form-control form-control-sm" min="1" required>
                            </div>
                            <div style="flex:1; min-width:100px;">
                                <label class="form-label small fw-bold">État</label>
                                <select name="etat_lot" id="editLotEtat" class="form-select form-select-sm">
                                    <option value="Actif">Actif</option>
                                    <option value="Inactif">Inactif</option>
                                </select>
                            </div>
                            <div style="flex:0 0 auto; display:flex; gap:4px;">
                                <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i></button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="cancelEditLot()"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn-secondary" onclick="closeModal('lotModal')">Fermer</button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL AJUSTER STOCK (fallback) -->
    <!-- ========================================================= -->
    <div class="modal-overlay" id="stockModal">
        <div class="modal-box" style="max-width:440px;">
            <div class="modal-head">
                <h3><i class="bi bi-plus-minus"></i> Ajuster le stock</h3>
                <button class="modal-close" onclick="closeModal('stockModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="stockForm">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" name="code_produit" id="stockCode">
                    <input type="hidden" name="filter" value="<?= e($filter) ?>">
                    <input type="hidden" name="search" value="<?= e($search) ?>">
                    <input type="hidden" name="page" value="<?= e($page) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                    <div class="product-ref">
                        <span class="ref-name" id="stockNom"></span>
                        <span class="ref-stock">Stock : <strong id="stockActuel"></strong></span>
                    </div>

                    <div class="form-group">
                        <label>Opération</label>
                        <select name="type" id="typeStock">
                            <option value="add">Ajouter</option>
                            <option value="remove" id="removeOption">Retirer</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label>Quantité</label>
                        <input type="number" name="quantite" id="qteStock" min="1" value="1" required>
                    </div>
                    <div id="stockWarning" style="margin-top:8px; font-size:12px; color:var(--warning); display:none;">
                        <i class="bi bi-info-circle"></i> Ce produit a des lots actifs. Utilisez la gestion des lots pour ajuster les quantités.
                    </div>
                </form>
            </div>
            <div class="modal-foot">
                <button class="btn-secondary" onclick="closeModal('stockModal')">Annuler</button>
                <button class="btn-success" onclick="document.getElementById('stockForm').submit()"><i class="bi bi-check-lg"></i> Appliquer</button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL MODIFIER INFOS -->
    <!-- ========================================================= -->
    <div class="modal-overlay" id="editModal">
        <div class="modal-box" style="max-width:440px;">
            <div class="modal-head">
                <h3><i class="bi bi-pencil-square"></i> Modifier les informations</h3>
                <button class="modal-close" onclick="closeModal('editModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="editForm">
                    <input type="hidden" name="action" value="update_info">
                    <input type="hidden" name="code_produit" id="editCode">
                    <input type="hidden" name="filter" value="<?= e($filter) ?>">
                    <input type="hidden" name="search" value="<?= e($search) ?>">
                    <input type="hidden" name="page" value="<?= e($page) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                    <div class="product-ref">
                        <span class="ref-name" id="editNom"></span>
                    </div>

                    <div class="form-group">
                        <label>Prix fournisseur (F CFA)</label>
                        <input type="number" step="0.01" name="prix_fournisseur" id="editPrixFour" required>
                    </div>

                    <div class="form-group">
                        <label>Prix vente (F CFA)</label>
                        <input type="number" step="0.01" name="prix_produit" id="editPrixVente" required>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label>Seuil d'alerte</label>
                        <input type="number" name="stock_alerte" id="editAlerte" required>
                    </div>
                </form>
            </div>
            <div class="modal-foot">
                <button class="btn-secondary" onclick="closeModal('editModal')">Annuler</button>
                <button class="btn-primary" onclick="document.getElementById('editForm').submit()"><i class="bi bi-check-lg"></i> Enregistrer</button>
            </div>
        </div>
    </div>

    <!-- ===== TOAST ===== -->
    <div class="toast-notif" id="toastMsg"></div>

    <!-- ===== SCRIPTS ===== -->
    <script>
        // ==================== PAGINATION & RECHERCHE ====================
        function goPage(p) {
            document.getElementById('hiddenPage').value = p;
            document.getElementById('mainForm').submit();
        }

        function resetSearch() {
            document.getElementById('searchInput').value = '';
            document.getElementById('hiddenFilter').value = 'all';
            document.getElementById('hiddenPage').value = '1';
            document.getElementById('mainForm').submit();
        }

        // ==================== MODALES ====================
        function openModal(id) {
            document.getElementById(id).classList.add('show');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        // ==================== STOCK ====================
        function openStockModal(code, nom, stockActuel, nbLots) {
            document.getElementById('stockCode').value = code;
            document.getElementById('stockNom').textContent = nom;
            document.getElementById('stockActuel').textContent = stockActuel;
            const removeOpt = document.getElementById('removeOption');
            removeOpt.disabled = (stockActuel == 0);
            removeOpt.textContent = stockActuel == 0 ? 'Retirer (stock nul)' : 'Retirer';
            document.getElementById('typeStock').value = 'add';
            document.getElementById('qteStock').value = '1';
            // Avertissement si lots
            const warn = document.getElementById('stockWarning');
            if (nbLots > 0) {
                warn.style.display = 'block';
            } else {
                warn.style.display = 'none';
            }
            openModal('stockModal');
        }

        // ==================== EDITION ====================
        function openEditModal(code, nom, prixFour, prixVente, alerte) {
            document.getElementById('editCode').value = code;
            document.getElementById('editNom').textContent = nom;
            document.getElementById('editPrixFour').value = prixFour;
            document.getElementById('editPrixVente').value = prixVente;
            document.getElementById('editAlerte').value = alerte;
            openModal('editModal');
        }

        // ==================== GESTION DES LOTS ====================
        function openLotModal(produitId, produitNom) {
            document.getElementById('lotProduitId').value = produitId;
            document.getElementById('lotProduitNom').textContent = produitNom;
            document.getElementById('lotProduitInfo').textContent = '';

            // Charger les lots via AJAX
            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'ajax_lots=1&produit_id=' + encodeURIComponent(produitId) + '&csrf_token=<?= $csrf_token ?>'
                })
                .then(response => response.text())
                .then(html => {
                    document.getElementById('lotListContainer').innerHTML = html;
                })
                .catch(err => {
                    document.getElementById('lotListContainer').innerHTML =
                        '<p class="text-danger text-center">Erreur de chargement.</p>';
                });

            cancelEditLot();
            openModal('lotModal');
        }

        function editLot(code, titre, unites, etat) {
            document.getElementById('editLotCode').value = code;
            document.getElementById('editLotTitre').value = titre;
            document.getElementById('editLotUnites').value = unites;
            document.getElementById('editLotEtat').value = etat;
            document.getElementById('lotEditZone').classList.add('show');
            document.getElementById('lotEditZone').scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }

        function cancelEditLot() {
            document.getElementById('lotEditZone').classList.remove('show');
            document.getElementById('editLotCode').value = '';
            document.getElementById('editLotTitre').value = '';
            document.getElementById('editLotUnites').value = '1';
            document.getElementById('editLotEtat').value = 'Actif';
        }

        // ==================== TOAST ====================
        function toast(msg, type) {
            const t = document.getElementById('toastMsg');
            const icon = type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill';
            t.innerHTML = '<i class="bi ' + icon + '"></i> ' + msg;
            t.className = 'toast-notif ' + type;
            t.style.display = 'flex';
            clearTimeout(t._timer);
            t._timer = setTimeout(() => t.style.display = 'none', 3500);
        }

        <?php if ($message): ?>
            toast("<?= e($message) ?>", "<?= $messageType === 'error' ? 'error' : 'success' ?>");
        <?php endif; ?>

        // ===== FERMETURE DES MODALES AU CLIC SUR OVERLAY =====
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => {
                if (e.target === m) m.classList.remove('show');
            });
        });

        // ===== RECHERCHE AUTO =====
        let searchTimeout = null;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('mainForm').submit();
            }, 400);
        });

        // ===== MISE À JOUR DES LOTS APRÈS AJOUT/SUPPRESSION (reload) =====
        // Les formulaires POST rechargent la page, donc les lots seront mis à jour.
        // Pour rester dans la modale après ajout, on pourrait faire du JS, mais ici on reload.
    </script>

</body>

</html>