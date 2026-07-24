<?php
// produit.php
// CRUD produit avec photos, datalist remplacé par Bootstrap SelectPicker pour les catégories

ob_start(); // capture tout octet parasite (BOM, espaces) émis par ce fichier ou les fichiers inclus
require_once 'databases/database.php';

session_start();
// Vérifier la session
if (!isset($_SESSION['user_id'])) {
    header('Location: utilisateur/login');
    exit;
}
// Vérifier que l'utilisateur existe toujours
$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: utilisateur/login');
    exit;
}

/**
 * Enregistre un mouvement de stock et met à jour le solde de la boutique.
 * Point d'entrée unique pour toute variation de stock : jamais d'écriture
 * directe dans stock_boutique ailleurs dans le code.
 */
function enregistrerMouvementStock(
    PDO $pdo,
    string $produit_id,
    string $boutique_id,
    string $type_mouvement,
    int $quantite,
    float $prix_unitaire,
    ?string $reference_document,
    string $utilisateur_id,
    ?string $commentaire = null,
    ?string $code_transfert = null,
    ?string $boutique_partenaire = null
): array {
    $typesEntree = ['ENTREE_ACHAT', 'ENTREE_TRANSFERT', 'ENTREE_RETOUR_CLIENT', 'ENTREE_INVENTAIRE'];
    $typesSortie = ['SORTIE_VENTE', 'SORTIE_TRANSFERT', 'SORTIE_PERTE', 'SORTIE_INVENTAIRE'];

    if (!in_array($type_mouvement, array_merge($typesEntree, $typesSortie), true)) {
        throw new Exception("Type de mouvement inconnu : $type_mouvement");
    }
    if ($quantite <= 0) {
        throw new Exception("La quantité doit être positive.");
    }

    $dejaEnTransaction = $pdo->inTransaction();
    if (!$dejaEnTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT quantite FROM stock_boutique WHERE produit_id = ? AND boutique_id = ? FOR UPDATE"
        );
        $stmt->execute([$produit_id, $boutique_id]);
        $ligne = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ligne === false) {
            $pdo->prepare(
                "INSERT INTO stock_boutique (produit_id, boutique_id, quantite) VALUES (?, ?, 0)"
            )->execute([$produit_id, $boutique_id]);
            $stock_avant = 0;
        } else {
            $stock_avant = (int) $ligne['quantite'];
        }

        $sens = in_array($type_mouvement, $typesEntree, true) ? 1 : -1;
        $stock_apres = $stock_avant + ($sens * $quantite);

        if ($stock_apres < 0) {
            throw new Exception(
                "Stock insuffisant pour $produit_id dans la boutique $boutique_id " .
                    "(disponible : $stock_avant, demandé : $quantite)."
            );
        }

        $jour = date('Ymd');
        $stmtCode = $pdo->prepare("SELECT COUNT(*) FROM mouvement_stock WHERE code_mouvement LIKE ?");
        $stmtCode->execute(["MV-$jour-%"]);
        $code_mouvement = sprintf('MV-%s-%03d', $jour, ((int) $stmtCode->fetchColumn()) + 1);

        $pdo->prepare(
            "INSERT INTO mouvement_stock
                (code_mouvement, produit_id, boutique_id, type_mouvement, quantite,
                 stock_avant, stock_apres, prix_unitaire, reference_document,
                 code_transfert, boutique_partenaire, utilisateur_id, commentaire, etat)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'VALIDE')"
        )->execute([
            $code_mouvement,
            $produit_id,
            $boutique_id,
            $type_mouvement,
            $quantite,
            $stock_avant,
            $stock_apres,
            $prix_unitaire,
            $reference_document,
            $code_transfert,
            $boutique_partenaire,
            $utilisateur_id,
            $commentaire,
        ]);

        $pdo->prepare(
            "UPDATE stock_boutique SET quantite = ? WHERE produit_id = ? AND boutique_id = ?"
        )->execute([$stock_apres, $produit_id, $boutique_id]);

        if (!$dejaEnTransaction) {
            $pdo->commit();
        }

        return ['code_mouvement' => $code_mouvement, 'stock_avant' => $stock_avant, 'stock_apres' => $stock_apres];
    } catch (Exception $e) {
        if (!$dejaEnTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// --- Récupération des catégories pour les select ---
$categories = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie ORDER BY titre_categorie")->fetchAll(PDO::FETCH_ASSOC);

// --- Boutiques actives (pour l'initialisation du stock à la création) ---
$boutiquesActives = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$boutiquePrincipale = $boutiquesActives[0]['code_boutique'] ?? null;

// --- Traitement des actions POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !(isset($_POST['ajax']) && $_POST['ajax'] == '1')) {
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {

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
    $benefice = 0;
    if (is_numeric($prix_fournisseur) && is_numeric($prix_produit)) {
        $benefice = $prix_produit - $prix_fournisseur;
    }

    // Gestion de la photo
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
                    $sql = "INSERT INTO produit (code_produit, titre_produit, prix_fournisseur, prix_produit, benefice_produit, stock_alerte, categorie_id, description_produit, photo, type_photo, etat_produit)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$code, $titre, $prix_fournisseur, $prix_produit, $benefice, $stock_alerte, $categorie_id, $description, $photo, $type_photo, $etat]);

                    // Crée une ligne de stock à 0 dans chaque boutique active,
                    // pour que le produit apparaisse dans les écrans de stock.
                    $insSb = $pdo->prepare(
                        "INSERT INTO stock_boutique (produit_id, boutique_id, quantite, stock_alerte) VALUES (?, ?, 0, ?)"
                    );
                    foreach ($boutiquesActives as $b) {
                        $insSb->execute([$code, $b['code_boutique'], $stock_alerte]);
                    }

                    // Si une quantité initiale a été saisie, elle passe par un
                    // mouvement ENTREE_INVENTAIRE (jamais une écriture directe).
                    if (is_numeric($stock_initial) && (float) $stock_initial > 0 && !empty($boutique_initiale)) {
                        enregistrerMouvementStock(
                            $pdo,
                            $code,
                            $boutique_initiale,
                            'ENTREE_INVENTAIRE',
                            (int) $stock_initial,
                            (float) ($prix_fournisseur ?: 0),
                            null,
                            $_SESSION['user_id'] ?? '1',
                            'Stock initial à la création du produit'
                        );
                    }

                    $message = "Produit « $titre » ajouté avec succès.";
                    $messageType = 'success';
                }
            } elseif ($action === 'edit') {
                $oldCode = $_POST['old_code'] ?? $code;
                if ($photo !== null) {
                    $sql = "UPDATE produit SET code_produit=?, titre_produit=?, prix_fournisseur=?, prix_produit=?, benefice_produit=?, stock_alerte=?, categorie_id=?, description_produit=?, photo=?, type_photo=?, etat_produit=?
                            WHERE code_produit = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$code, $titre, $prix_fournisseur, $prix_produit, $benefice, $stock_alerte, $categorie_id, $description, $photo, $type_photo, $etat, $oldCode]);
                } else {
                    $sql = "UPDATE produit SET code_produit=?, titre_produit=?, prix_fournisseur=?, prix_produit=?, benefice_produit=?, stock_alerte=?, categorie_id=?, description_produit=?, etat_produit=?
                            WHERE code_produit = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$code, $titre, $prix_fournisseur, $prix_produit, $benefice, $stock_alerte, $categorie_id, $description, $etat, $oldCode]);
                }
                // Si le code produit change, les lignes stock_boutique le suivent.
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

    } // fin vérification CSRF
}

// Générer un token CSRF pour les prochains formulaires
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// --- Fonction de génération du tableau (AJAX) ---
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

    $sql = "SELECT p.*, COALESCE(SUM(sb.quantite), 0) AS stock_total
            FROM produit p
            LEFT JOIN stock_boutique sb ON sb.produit_id = p.code_produit
            $where
            GROUP BY p.code_produit
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
                            style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-color);">
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($p['prix_fournisseur']) ?></td>
                <td><?= htmlspecialchars($p['prix_produit']) ?></td>
                <td><?= htmlspecialchars($p['benefice_produit']) ?></td>
                <td><?= htmlspecialchars($p['stock_alerte']) ?></td>
                <td>
                    <?php $enAlerte = (int) $p['stock_total'] <= (int) $p['stock_alerte']; ?>
                    <span class="<?= $enAlerte ? 'text-danger fw-bold' : '' ?>"><?= (int) $p['stock_total'] ?></span>
                </td>
                <td><?= htmlspecialchars($p['categorie_id']) ?></td>
                <td>
                    <span class="status-badge <?= $p['etat_produit'] === 'Actif' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= htmlspecialchars($p['etat_produit']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <!-- Bouton Voir supprimé -->
                        <button class="act-btn e editBtn" data-code="<?= htmlspecialchars($p['code_produit']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= htmlspecialchars($p['code_produit']) ?>" data-nom="<?= htmlspecialchars($p['titre_produit']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash"></i></button>
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
    $search = trim($_POST['search'] ?? '');
    $categorie_filter = trim($_POST['categorie_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $categorie_filter, $page);
    while (ob_get_level()) {
        ob_end_clean();
    }
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

/** Stock total (toutes boutiques) d'un produit. */
function getStockTotal(PDO $pdo, string $produit_id): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantite), 0) FROM stock_boutique WHERE produit_id = ?");
    $stmt->execute([$produit_id]);
    return (int) $stmt->fetchColumn();
}

/** Détail du stock par boutique pour un produit (pour l'affichage en vue). */
function getStockParBoutique(PDO $pdo, string $produit_id): array
{
    $stmt = $pdo->prepare(
        "SELECT b.nom_boutique, sb.quantite
         FROM stock_boutique sb
         JOIN boutique b ON b.code_boutique = sb.boutique_id
         WHERE sb.produit_id = ?
         ORDER BY b.nom_boutique"
    );
    $stmt->execute([$produit_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        /* Variables et styles */
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

        .preview-img {
            max-width: 80px;
            max-height: 80px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .img-placeholder {
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-muted);
            border-radius: 8px;
            border: 1px dashed var(--border-color);
            color: var(--text-quaternary);
            font-size: 0.8rem;
        }

        /* Ajustement pour SelectPicker */
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
                <h2 class="fw-800 mb-0">Gestion des produits</h2>
                <p class="text-tertiary mt-1">Créer, modifier et suivre votre catalogue</p>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau produit</button>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Barre de recherche avec SelectPicker pour catégorie -->
        <div class="bg-light p-3 rounded-3 mb-3 border">
            <form id="searchForm" method="post" onsubmit="return false;">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="searchInput" class="form-label fw-semibold small">Recherche</label>
                        <div class="search-inline" style="min-width:100%; height:42px;">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search" id="searchInput" placeholder="Code, titre, description..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="categorieFilter" class="form-label fw-semibold small">Catégorie</label>
                        <select name="categorie_filter" id="categorieFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher une catégorie...">
                            <option value="">Toutes</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['code_categorie']) ?>" <?= ($categorie_filter == $cat['code_categorie']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['titre_categorie']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-primary w-100" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-outline-secondary w-100" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="data-table-wrap" id="tableWrapper">
            <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
                <h5 class="mb-0 fw-bold">Liste des produits</h5>
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
                            <th>Stock</th>
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

    <!-- =========================================================
MODAL FORMULAIRE (ajout/modification)
========================================================= -->
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

                        <!-- Stock -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-boxes me-1"></i> Gestion de stock</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="stock_alerte" class="form-label fw-semibold">Stock d'alerte</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-exclamation-triangle"></i></span>
                                    <input type="text" class="form-control" id="stock_alerte" name="stock_alerte" placeholder="10" value="<?= htmlspecialchars($editProduit['stock_alerte'] ?? '10') ?>">
                                </div>
                            </div>
                            <div class="col-md-6" id="blocStockTotal" style="display:none;">
                                <label class="form-label fw-semibold">Stock actuel (toutes boutiques)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-cubes"></i></span>
                                    <input type="text" class="form-control" readonly value="<?= (int) ($editProduit['stock_total'] ?? 0) ?>">
                                </div>
                                <div class="form-text">Non modifiable ici. Passez par un mouvement d'inventaire pour corriger une quantité.</div>
                            </div>
                        </div>
                        <div class="row g-3 mb-4" id="blocStockInitial">
                            <div class="col-md-6">
                                <label for="stock_initial" class="form-label fw-semibold">Quantité initiale</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-cubes"></i></span>
                                    <input type="text" class="form-control" id="stock_initial" name="stock_initial" placeholder="0">
                                </div>
                                <div class="form-text">Génère un mouvement ENTREE_INVENTAIRE à la création.</div>
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

                        <!-- Catégorie avec SelectPicker -->
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

    <!-- =========================================================
MODAL : CONFIRMATION SUPPRESSION
========================================================= -->
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

    <!-- Formulaire caché suppression -->
    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="btn_supprimer" value="1">
        <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    </form>

    <!-- Formulaire caché pour action edit -->
    <form method="post" id="actionForm">
        <input type="hidden" name="action" id="actionField">
        <input type="hidden" name="edit_code" id="editCodeField">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    </form>

    <!-- =========================================================
SCRIPTS
========================================================= -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Bootstrap SelectPicker JS (après jQuery et Bootstrap) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

    <script>
        $(document).ready(function() {
            // --- Initialisation de tous les selectpicker ---
            // destroy avant init : évite le doublon visuel si ce contenu est
            // rechargé dans le DOM sans rechargement complet de la page.
            $('.selectpicker').selectpicker('destroy');
            $('.selectpicker').selectpicker();

            const produitModal = new bootstrap.Modal(document.getElementById('produitModal'));

            // --- Ajout ---
            $('#addBtn').on('click', function() {
                $('#formAction').val('add');
                $('#oldCode').val('');
                $('#modalTitle').text('Nouveau produit');
                $('#produitForm')[0].reset();
                $('#code_produit').prop('readonly', false);
                $('#benefice_estime').val('');
                $('#photoPreviewContainer').html('<div class="img-placeholder"><i class="bi bi-image fs-1"></i></div>');

                // Vidage manuel : reset() ne suffit pas, car ces champs ont leur
                // value/selected écrite par PHP côté serveur (pré-remplis lors d'une
                // édition précédente) et reset() les restaure au lieu de les vider.
                $('#code_produit').val('');
                $('#titre_produit').val('');
                $('#prix_fournisseur').val('');
                $('#prix_produit').val('');
                $('#stock_alerte').val('10');
                $('#stock_initial').val('');
                $('#description_produit').val('');
                $('#etat_produit').val('Actif');

                // À la création : on demande une quantité initiale + boutique.
                // En édition : le stock ne se touche plus depuis ce formulaire.
                $('#blocStockInitial').show();
                $('#blocStockTotal').hide();

                // Réinitialiser les selectpicker pour qu'ils reviennent à l'état par défaut
                $('#categorie_id').selectpicker('val', '');
                $('#boutique_initiale').selectpicker('destroy').selectpicker();
                produitModal.show();
            });

            // --- Édition ---
            $(document).on('click', '.editBtn', function() {
                const code = $(this).data('code');
                $('#actionField').val('load_edit');
                $('#editCodeField').val(code);
                $('#actionForm').submit();
            });

            // --- Calcul bénéfice ---
            function calculBenefice() {
                const four = parseFloat($('#prix_fournisseur').val()) || 0;
                const vente = parseFloat($('#prix_produit').val()) || 0;
                $('#benefice_estime').val((vente - four).toFixed(2));
            }
            $('#prix_fournisseur, #prix_produit').on('input', calculBenefice);

            // --- Aperçu photo ---
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

            // --- Fonction de recherche AJAX ---
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
                        // Réattacher les événements de pagination
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

            // Pour le selectpicker de catégorie, on écoute l'événement 'changed.bs.select'
            $('#categorieFilter').on('changed.bs.select', function() {
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
                $('#categorieFilter').selectpicker('val', '');
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
                $('#deleteNomProduit').text(nom);
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
            <?php if (isset($editProduit) && $action === 'load_edit'): ?>
                $(function() {
                    $('#formAction').val('edit');
                    $('#oldCode').val('<?= htmlspecialchars($editProduit['code_produit']) ?>');
                    $('#modalTitle').text('Modifier le produit');
                    $('#code_produit').prop('readonly', true);
                    $('#blocStockInitial').hide();
                    $('#blocStockTotal').show();
                    // La catégorie est déjà sélectionnée via l'attribut selected dans le select, on rafraîchit le selectpicker
                    $('#categorie_id').selectpicker('destroy').selectpicker();
                    produitModal.show();
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>