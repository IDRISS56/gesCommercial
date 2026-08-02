<?php
// ==========================================
// 1. INITIALISATION & CONNEXION
// ==========================================
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

// ==========================================
// 2. DONNÉES DE BASE
// ==========================================
$categories = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie WHERE etat_categorie='ACTIF' ORDER BY titre_categorie")->fetchAll(PDO::FETCH_ASSOC);
$boutiquesActives = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$boutiquePrincipale = $boutiquesActives[0]['code_boutique'] ?? null;

// Statistiques globales
$totalProduits   = $pdo->query("SELECT COUNT(*) FROM produit")->fetchColumn() ?? 0;
$enRupture       = $pdo->query("SELECT COUNT(*) FROM produit WHERE etat_produit = 'RUPTURE'")->fetchColumn() ?? 0;
$enAlerte        = $pdo->query("SELECT COUNT(*) FROM produit WHERE etat_produit = 'ALERTE'")->fetchColumn() ?? 0;
$disponibles     = $pdo->query("SELECT COUNT(*) FROM produit WHERE etat_produit = 'DISPONIBLE'")->fetchColumn() ?? 0;
$valeurStock     = $pdo->query("SELECT COALESCE(SUM(prix_fournisseur * stock_produit), 0) FROM produit")->fetchColumn() ?? 0;

// ==========================================
// 3. TRAITEMENT CRUD (POST)
// ==========================================
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !((isset($_POST['ajax']) && $_POST['ajax'] == '1'))) {
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {
        if ($action === 'add' || $action === 'edit') {
            $code              = trim($_POST['code_produit'] ?? '');
            $titre             = trim($_POST['titre_produit'] ?? '');
            $prix_fournisseur  = floatval($_POST['prix_fournisseur'] ?? 0);
            $prix_produit      = floatval($_POST['prix_produit'] ?? 0);
            $stock_alerte      = intval($_POST['stock_alerte'] ?? 10);
            $stock_produit     = intval($_POST['stock_produit'] ?? 0);
            $categorie_id      = trim($_POST['categorie_id'] ?? '');
            $description       = trim($_POST['description_produit'] ?? '');
            $oldCode           = trim($_POST['old_code'] ?? $code);

            $benefice = $prix_produit - $prix_fournisseur;

            // Calcul automatique de l'état
            if ($stock_produit <= 0) $etat = 'RUPTURE';
            elseif ($stock_produit <= $stock_alerte) $etat = 'ALERTE';
            else $etat = 'DISPONIBLE';

            $photo = null;
            $type_photo = null;
            if (isset($_FILES['photo_produit']) && $_FILES['photo_produit']['error'] === UPLOAD_ERR_OK) {
                $photo = file_get_contents($_FILES['photo_produit']['tmp_name']);
                $type_photo = $_FILES['photo_produit']['type'];
            }

            $errors = [];
            if (empty($code)) $errors[] = 'Le code produit est requis.';
            if (empty($titre)) $errors[] = 'Le titre est requis.';

            if (empty($errors)) {
                try {
                    if ($action === 'add') {
                        $check = $pdo->prepare("SELECT COUNT(*) FROM produit WHERE code_produit = ?");
                        $check->execute([$code]);
                        if ($check->fetchColumn() > 0) {
                            $message = "Ce code produit existe déjà.";
                            $messageType = 'warning';
                        } else {
                            $sql = "INSERT INTO produit 
                                    (code_produit, titre_produit, prix_fournisseur, prix_produit, benefice_produit, 
                                     stock_alerte, stock_produit, categorie_id, description_produit, photo, type_photo, etat_produit)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $pdo->prepare($sql)->execute([
                                $code, $titre, $prix_fournisseur, $prix_produit, $benefice,
                                $stock_alerte, $stock_produit, $categorie_id, $description, $photo, $type_photo, $etat
                            ]);

                            // Initialisation du stock dans chaque boutique
                            $insS = $pdo->prepare("INSERT INTO stock (produit_id, boutique_id, quantite, stock_alerte) VALUES (?, ?, 0, ?)");
                            foreach ($boutiquesActives as $b) {
                                $insS->execute([$code, $b['code_boutique'], $stock_alerte]);
                            }

                            // Stock initial réparti sur la boutique choisie
                            if ($stock_produit > 0 && !empty($_POST['boutique_initiale'])) {
                                $pdo->prepare("UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                                    ->execute([$stock_produit, $code, $_POST['boutique_initiale']]);
                            }
                            $message = "Produit « $titre » ajouté avec succès.";
                            $messageType = 'success';
                        }
                    } else {
                        // EDIT
                        if ($photo !== null) {
                            $sql = "UPDATE produit SET code_produit=?, titre_produit=?, prix_fournisseur=?, prix_produit=?, 
                                    benefice_produit=?, stock_alerte=?, stock_produit=?, categorie_id=?, description_produit=?, 
                                    photo=?, type_photo=?, etat_produit=? WHERE code_produit = ?";
                            $pdo->prepare($sql)->execute([
                                $code, $titre, $prix_fournisseur, $prix_produit, $benefice,
                                $stock_alerte, $stock_produit, $categorie_id, $description,
                                $photo, $type_photo, $etat, $oldCode
                            ]);
                        } else {
                            $sql = "UPDATE produit SET code_produit=?, titre_produit=?, prix_fournisseur=?, prix_produit=?, 
                                    benefice_produit=?, stock_alerte=?, stock_produit=?, categorie_id=?, description_produit=?, 
                                    etat_produit=? WHERE code_produit = ?";
                            $pdo->prepare($sql)->execute([
                                $code, $titre, $prix_fournisseur, $prix_produit, $benefice,
                                $stock_alerte, $stock_produit, $categorie_id, $description, $etat, $oldCode
                            ]);
                        }
                        if ($oldCode !== $code) {
                            $pdo->prepare("UPDATE stock SET produit_id = ? WHERE produit_id = ?")->execute([$code, $oldCode]);
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
                    $titre = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
                    $titre->execute([$code]);
                    $titre = $titre->fetchColumn();
                    $pdo->prepare("DELETE FROM stock WHERE produit_id = ?")->execute([$code]);
                    $pdo->prepare("DELETE FROM produit WHERE code_produit = ?")->execute([$code]);
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

// ==========================================
// 4. FONCTION TABLE (AJAX)
// ==========================================
function getTableContent($pdo, $search, $categorie_filter, $etat_filter, $page, $perPage = 20) {
    $where = "WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $where .= " AND (p.code_produit LIKE ? OR p.titre_produit LIKE ? OR p.description_produit LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if (!empty($categorie_filter)) {
        $where .= " AND p.categorie_id = ?";
        $params[] = $categorie_filter;
    }
    if (!empty($etat_filter)) {
        $where .= " AND p.etat_produit = ?";
        $params[] = $etat_filter;
    }

    $countSql = "SELECT COUNT(*) FROM produit p $where";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = max(1, ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    $sql = "SELECT p.*, c.titre_categorie 
            FROM produit p 
            LEFT JOIN categorie c ON p.categorie_id = c.code_categorie 
            $where 
            ORDER BY p.code_produit 
            LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($produits)): ?>
        <tr><td colspan="10" class="text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>Aucun produit trouvé
        </td></tr>
    <?php else: ?>
        <?php foreach ($produits as $p): ?>
            <?php
                $etatBadgeClass = ['RUPTURE'=>'danger','ALERTE'=>'warning','DISPONIBLE'=>'success'][$p['etat_produit']] ?? 'secondary';
                $etatIcon = ['RUPTURE'=>'x-circle-fill','ALERTE'=>'exclamation-triangle-fill','DISPONIBLE'=>'check-circle-fill'][$p['etat_produit']] ?? 'question-circle';
            ?>
            <tr class="produit-item" data-etat="<?= htmlspecialchars($p['etat_produit']) ?>">
                <td class="td-bold"><?= htmlspecialchars($p['code_produit']) ?></td>
                <td class="td-semi"><?= htmlspecialchars($p['titre_produit']) ?></td>
                <td>
                    <?php if (!empty($p['photo'])): ?>
                        <img src="data:<?= htmlspecialchars($p['type_photo'] ?? 'image/jpeg') ?>;base64,<?= base64_encode($p['photo']) ?>"
                             alt="<?= htmlspecialchars($p['titre_produit']) ?>"
                             style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid var(--border-color);">
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= number_format($p['prix_fournisseur'], 0, ',', ' ') ?></td>
                <td><?= number_format($p['prix_produit'], 0, ',', ' ') ?></td>
                <td class="text-success fw-bold"><?= number_format($p['benefice_produit'], 0, ',', ' ') ?></td>
                <td><span class="<?= ($p['stock_produit'] <= $p['stock_alerte']) ? 'text-danger fw-bold' : '' ?>"><?= (int)$p['stock_produit'] ?></span></td>
                <td><?= htmlspecialchars($p['titre_categorie'] ?? '—') ?></td>
                <td>
                    <span class="badge-pill bg-<?= $etatBadgeClass ?>-subtle text-<?= $etatBadgeClass ?>">
                        <i class="bi bi-<?= $etatIcon ?>" style="font-size:8px;"></i> <?= htmlspecialchars($p['etat_produit'] ?: 'RUPTURE') ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="icon-btn edit editBtn" data-code="<?= htmlspecialchars($p['code_produit']) ?>" data-tooltip="Modifier" title="Modifier">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="icon-btn delete deleteBtn" data-code="<?= htmlspecialchars($p['code_produit']) ?>" 
                                data-nom="<?= htmlspecialchars($p['titre_produit']) ?>" data-tooltip="Supprimer" title="Supprimer">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
        <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page - 1 ?>"><i class="bi bi-chevron-left"></i></a>
            </li>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                    <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page + 1 ?>"><i class="bi bi-chevron-right"></i></a>
            </li>
        </ul></nav>
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

// ==========================================
// 5. ROUTAGE AJAX
// ==========================================
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $categorie_filter = trim($_POST['categorie_filter'] ?? '');
    $etat_filter = trim($_POST['etat_filter'] ?? '');
    $page = max(1, intval($_POST['page'] ?? 1));
    $result = getTableContent($pdo, $search, $categorie_filter, $etat_filter, $page);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// ==========================================
// 6. DONNÉES INITIALES
// ==========================================
$search = trim($_POST['search'] ?? '');
$categorie_filter = trim($_POST['categorie_filter'] ?? '');
$etat_filter = trim($_POST['etat_filter'] ?? '');
$page = max(1, intval($_POST['page'] ?? 1));
$initialData = getTableContent($pdo, $search, $categorie_filter, $etat_filter, $page);

// Chargement pour édition
$editProduit = null;
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
    $stmt = $pdo->prepare("SELECT * FROM produit WHERE code_produit = ?");
    $stmt->execute([$_POST['edit_code']]);
    $editProduit = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion des Produits</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ===== VARIABLES (cohérent avec vente.php) ===== */
:root {
    --color-primary: #4f46e5;
    --color-primary-dark: #3730a3;
    --color-primary-soft: #eef2ff;
    --color-success: #10b981;
    --color-success-soft: #ecfdf5;
    --color-warning: #f59e0b;
    --color-warning-soft: #fffbeb;
    --color-danger: #ef4444;
    --color-danger-soft: #fef2f2;
    --color-info: #3b82f6;
    --color-info-soft: #eff6ff;
    --color-purple: #8b5cf6;
    --color-purple-soft: #f5f3ff;
    --color-gray-100: #f1f5f9;
    --color-gray-200: #e2e8f0;
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-tertiary: #64748b;
    --bg-surface: #ffffff;
    --bg-page: #f8fafc;
    --border-color: #e2e8f0;
    --radius-sm: 10px;
    --radius-md: 14px;
    --radius-lg: 20px;
}
* { box-sizing: border-box; }
body {
    font-family: 'Inter', -apple-system, sans-serif;
    background: var(--bg-page);
    color: var(--text-primary);
    min-height: 100vh;
    padding: 28px 20px;
}
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
.W { max-width: 1400px; margin: 0 auto; }

/* ===== STATS CARDS ===== */
.stat-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 18px;
    transition: all .2s;
}
.stat-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.06); border-color: #cbd5e1; }
.stat-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .5px; }
.stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; }

/* ===== TABLE ===== */
.data-table-wrap {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.table { margin: 0; font-size: .88rem; }
.table thead th {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    color: var(--text-tertiary);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    padding: 14px;
    border-bottom: 2px solid var(--border-color);
}
.table tbody td {
    padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.table tbody tr { transition: background .2s; }
.table tbody tr:hover { background: var(--color-primary-soft); }
.td-bold { font-weight: 700; color: var(--text-primary); }
.td-semi { font-weight: 500; }

/* ===== BADGES ===== */
.badge-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 999px;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .3px;
}

/* ===== BOUTONS ICONES ===== */
.icon-btn {
    width: 32px; height: 32px; border-radius: 6px;
    border: 1.5px solid transparent;
    background: transparent;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all .15s; position: relative;
}
.icon-btn.edit { color: var(--color-primary); border-color: rgba(79,70,229,.2); }
.icon-btn.edit:hover { background: var(--color-primary-soft); border-color: var(--color-primary); }
.icon-btn.delete { color: var(--color-danger); border-color: rgba(239,68,68,.2); }
.icon-btn.delete:hover { background: var(--color-danger-soft); border-color: var(--color-danger); }
.icon-btn::before {
    content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%;
    transform: translateX(-50%); background: var(--text-primary); color: #fff;
    padding: 4px 8px; border-radius: 5px; font-size: 10px; white-space: nowrap;
    opacity: 0; pointer-events: none; transition: opacity .15s;
}
.icon-btn:hover::before { opacity: 1; }

/* ===== BOUTONS PRINCIPAUX ===== */
.btn-go {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
    color: #fff; border: none; padding: 10px 18px; border-radius: 8px;
    font-weight: 600; font-size: 13px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all .2s; box-shadow: 0 4px 12px rgba(79,70,229,.25);
}
.btn-go:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79,70,229,.4); }
.btn-go-outline {
    background: #fff; color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 10px 18px; border-radius: 8px;
    font-weight: 600; font-size: 13px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px; transition: all .2s;
}
.btn-go-outline:hover { background: var(--color-gray-100); color: var(--text-primary); }

/* ===== FILTRES ===== */
.pbar {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 18px 22px;
    margin-bottom: 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.prow { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
.prow label { font-size: 10px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: 4px; }
.prow input, .prow select {
    border: 1px solid var(--border-color);
    border-radius: 8px; padding: 9px 12px;
    font-size: 13px; background: #fff;
    transition: all .15s;
}
.prow input:focus, .prow select:focus {
    outline: none; border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}

/* ===== MODAL CHIC ===== */
.modal-chic .modal-content {
    border: none; border-radius: var(--radius-lg);
    box-shadow: 0 25px 60px rgba(15,23,42,.15); overflow: hidden;
    display: flex !important; flex-direction: column !important; max-height: 90vh !important;
}
.modal-chic .modal-header {
    background: #334155;
    color: #fff; padding: 20px 28px; border: none;
    flex-shrink: 0 !important;
}
.modal-chic .modal-title { font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 18px; display: flex; align-items: center; gap: 10px; }
.modal-chic .modal-body { padding: 28px !important; overflow-y: auto !important; background: #f8fafc !important; flex: 1 1 auto !important; min-height: 0 !important; }
.modal-chic .modal-footer { background: #ffffff !important; border-top: 2px solid var(--border-color) !important; padding: 18px 28px !important; display: flex !important; gap: 10px !important; justify-content: flex-end !important; flex-wrap: wrap !important; flex-shrink: 0 !important; }
.section-title {
    font-size: 11px; font-weight: 700; color: var(--text-tertiary);
    text-transform: uppercase; letter-spacing: .8px;
    padding-bottom: 10px; border-bottom: 2px solid #f1f5f9;
    margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
}
.section-title.ident i { color: var(--color-primary); }
.section-title.price i { color: var(--color-success); }
.section-title.stock i { color: var(--color-warning); }
.section-title.cat i { color: var(--color-info); }

.form-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
.form-control, .form-select {
    border: 1px solid var(--border-color);
    border-radius: 8px; padding: 9px 12px;
    font-size: 13px;
}
.form-control:focus, .form-select:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}

/* Preview image */
.img-placeholder {
    width: 100%; height: 140px;
    background: var(--color-gray-100);
    border: 2px dashed var(--border-color);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: var(--text-tertiary);
}
.preview-img { width: 100%; height: 140px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border-color); }

/* Toast */
.toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }

/* Pagination */
.page-link {
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
    font-size: 12px; font-weight: 600;
    padding: 6px 12px;
}
.page-item.active .page-link {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: #fff;
}

/* ===== BADGE COMPTEUR (style catégorie) ===== */
.hdr-badge-chic {
    background: #e0e7ff;
    border: 1.5px solid #a5b4fc;
    color: #3730a3;
    padding: 10px 18px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-family: 'Outfit', sans-serif;
    box-shadow: 0 2px 6px rgba(99, 102, 241, 0.15);
    transition: all .2s;
}
.hdr-badge-chic i {
    font-size: 15px;
    color: #4f46e5;
}

/* ===== BOUTON NOUVEAU PRODUIT (style violet indigo) ===== */
.btn-go-chic {
    background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
    color: #fff;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
    transition: all .2s;
    font-family: 'Outfit', sans-serif;
}
.btn-go-chic:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(79, 70, 229, 0.5);
}
.btn-go-chic i {
    font-size: 16px;
    background: rgba(255, 255, 255, 0.2);
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* Animations */
@keyframes fadeUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.table tbody tr { animation: fadeUp .3s ease both; }

@media (max-width: 700px) {
    .bootstrap-select, .bootstrap-select .dropdown-toggle { width: 100% !important; min-width: 0 !important; }
}
</style>
</head>
<body>
<div class="W">

    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1" style="font-family:'Outfit',sans-serif;">
                <i class="bi bi-box-seam text-primary me-2"></i>Gestion des Produits
            </h1>
            <p class="text-muted small mb-0">Créer, modifier et suivre votre catalogue de produits</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge-chic">
                <i class="bi bi-box-seam"></i>
                <?= number_format($initialData['total'] ?? 0, 0, ',', ' ') ?> produit(s)
            </div>
            <button class="btn-go-chic" id="addBtn">
                <i class="bi bi-plus"></i>
                Nouveau produit
            </button>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['success', 'box-seam-fill', 'Disponibles', number_format($disponibles, 0, ',', ' '), 'produits'],
            ['warning', 'exclamation-triangle-fill', 'En alerte', number_format($enAlerte, 0, ',', ' '), 'produits'],
            ['danger', 'x-circle-fill', 'En rupture', number_format($enRupture, 0, ',', ' '), 'produits'],
            ['info', 'cash-stack', 'Valeur stock', number_format($valeurStock, 0, ',', ' '), 'FCFA'],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
            'danger'  => ['var(--color-danger-soft)',  'var(--color-danger)'],
            'info'    => ['var(--color-info-soft)',    'var(--color-info)'],
        ];
        foreach ($stats as $s):
            $bg = $colorMap[$s[0]][0]; $fg = $colorMap[$s[0]][1];
        ?>
        <div class="col-6 col-md-3">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $fg ?>;">
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

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : ($messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'danger')) ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filtres -->
    <div class="pbar">
        <form id="searchForm" onsubmit="return false;">
            <div class="prow">
                <div style="flex:1;min-width:180px;">
                    <label><i class="bi bi-search"></i> Recherche</label>
                    <input type="text" id="searchInput" placeholder="Code, titre, description..." value="<?= htmlspecialchars($search) ?>" style="width:100%;">
                </div>
                <div style="min-width:180px;">
                    <label><i class="bi bi-tag"></i> Catégorie</label>
                    <select id="categorieFilter" class="selectpicker" data-live-search="true" data-style="btn-sm" data-container="body">
                        <option value="">Toutes</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['code_categorie']) ?>" <?= ($categorie_filter == $cat['code_categorie']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['titre_categorie']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="min-width:150px;">
                    <label><i class="bi bi-funnel"></i> État</label>
                    <select id="etatFilter" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="DISPONIBLE">Disponible</option>
                        <option value="ALERTE">En alerte</option>
                        <option value="RUPTURE">En rupture</option>
                    </select>
                </div>
                <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <!-- Tableau -->
    <div class="data-table-wrap">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des produits</h5>
            <span class="text-muted small" id="totalCount">
                <?= $initialData['total'] ?> produit(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?>
            </span>
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
                        <th>Stock</th>
                        <th>Catégorie</th>
                        <th>État</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody"><?= $initialData['table'] ?></tbody>
            </table>
        </div>
        <div id="paginationContainer" class="p-3"><?= $initialData['pagination'] ?></div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL FORMULAIRE -->
<!-- ========================================== -->
<div class="modal fade modal-chic" id="produitModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-box-seam"></i><span id="modalTitle">Nouveau produit</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="produitForm" method="post" enctype="multipart/form-data" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="old_code" id="oldCode" value="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <!-- Identification -->
                    <div class="section-title ident"><i class="bi bi-tag-fill"></i> IDENTIFICATION</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Code produit <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="code_produit" name="code_produit" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Titre <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="titre_produit" name="titre_produit" required>
                        </div>
                    </div>

                    <!-- Prix -->
                    <div class="section-title price"><i class="bi bi-coins"></i> PRIX ET BÉNÉFICE</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Prix fournisseur</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-currency-dollar"></i></span>
                                <input type="number" step="0.01" class="form-control" id="prix_fournisseur" name="prix_fournisseur" value="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prix de vente</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-tag"></i></span>
                                <input type="number" step="0.01" class="form-control" id="prix_produit" name="prix_produit" value="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bénéfice estimé</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-graph-up-arrow"></i></span>
                                <input type="text" class="form-control" id="benefice_estime" readonly style="background:#ecfdf5;color:#10b981;font-weight:700;">
                            </div>
                        </div>
                    </div>

                    <!-- Stock -->
                    <div class="section-title stock"><i class="bi bi-boxes"></i> GESTION DE STOCK</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Stock d'alerte</label>
                            <input type="number" class="form-control" id="stock_alerte" name="stock_alerte" value="10">
                        </div>
                        <div class="col-md-4" id="blocStockInitial">
                            <label class="form-label">Stock initial</label>
                            <input type="number" class="form-control" id="stock_initial" name="stock_produit" value="0">
                            <small class="text-muted">Stock global du produit</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Boutique initiale</label>
                            <select class="form-select" id="boutique_initiale" name="boutique_initiale">
                                <?php foreach ($boutiquesActives as $b): ?>
                                    <option value="<?= htmlspecialchars($b['code_boutique']) ?>" <?= ($b['code_boutique'] === $boutiquePrincipale) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['nom_boutique']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Catégorie -->
                    <div class="section-title cat"><i class="bi bi-list"></i> CATÉGORIE & DESCRIPTION</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label class="form-label">Catégorie</label>
                            <select class="form-select" id="categorie_id" name="categorie_id">
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['code_categorie']) ?>"><?= htmlspecialchars($cat['titre_categorie']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="description_produit" name="description_produit" rows="3"></textarea>
                        </div>
                    </div>

                    <!-- Photo -->
                    <div class="section-title ident"><i class="bi bi-image"></i> PHOTO</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label class="form-label">Photo du produit</label>
                            <input type="file" class="form-control" id="photo_produit" name="photo_produit" accept="image/*">
                            <div id="photoPreviewContainer" class="mt-2">
                                <div class="img-placeholder"><i class="bi bi-image fs-1"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- MODAL FOOTER OPTIMISÉ -->
                <div class="modal-footer">
                    <button type="button" class="btn-go-outline" data-bs-dismiss="modal">
                        <i class="bi bi-x"></i> Annuler
                    </button>
                    <button type="submit" class="btn-go">
                        <i class="bi bi-save"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL SUPPRESSION -->
<!-- ========================================== -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div style="width:64px;height:64px;border-radius:50%;background:var(--color-danger-soft);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <i class="bi bi-trash3 text-danger" style="font-size:28px;"></i>
                </div>
                <h5 class="fw-bold mb-2">Confirmer la suppression</h5>
                <p class="text-muted mb-3">Voulez-vous vraiment supprimer le produit <strong id="deleteNomProduit"></strong> ?</p>
                <form id="deleteForm" method="post">
                    <input type="hidden" name="btn_supprimer" value="1">
                    <input type="hidden" name="sai_supprimer_id" id="deleteFormId">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash3"></i> Supprimer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast-container">
    <div id="liveToast" class="toast align-items-center text-white border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script>
const baseUrl = window.location.pathname;
const produitModal = new bootstrap.Modal(document.getElementById('produitModal'));
const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
const toastEl = document.getElementById('liveToast');
const toast = new bootstrap.Toast(toastEl, { delay: 3000 });

function showToast(msg, type = 'success') {
    const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-primary', warning: 'bg-warning' };
    const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill', warning: 'bi-exclamation-circle-fill' };
    $('#toastBody').html(`<i class="bi ${icons[type]} me-2"></i>${msg}`);
    toastEl.className = `toast align-items-center text-white border-0 ${colors[type]}`;
    toast.show();
}

function calculBenefice() {
    const four = parseFloat($('#prix_fournisseur').val()) || 0;
    const vente = parseFloat($('#prix_produit').val()) || 0;
    $('#benefice_estime').val((vente - four).toLocaleString('fr-FR'));
}
$('#prix_fournisseur, #prix_produit').on('input', calculBenefice);

$('#photo_produit').on('change', function() {
    const file = this.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = e => $('#photoPreviewContainer').html(`<img src="${e.target.result}" class="preview-img">`);
        reader.readAsDataURL(file);
    } else {
        $('#photoPreviewContainer').html('<div class="img-placeholder"><i class="bi bi-image fs-1"></i></div>');
    }
});

// Recherche AJAX
function rechercher(page = 1) {
    $.ajax({
        url: baseUrl,
        method: 'POST',
        data: {
            ajax: 1,
            search: $('#searchInput').val(),
            categorie_filter: $('#categorieFilter').val(),
            etat_filter: $('#etatFilter').val(),
            page: page
        },
        dataType: 'json',
        success: function(data) {
            $('#tableBody').html(data.table);
            $('#paginationContainer').html(data.pagination);
            $('#totalCount').text(`${data.total} produit(s) - Page ${data.page} / ${Math.max(1, data.totalPages)}`);
        },
        error: () => showToast('Erreur de recherche', 'error')
    });
}

$('#filterBtn').on('click', () => rechercher(1));
$('#resetBtn').on('click', function() {
    $('#searchInput').val('');
    $('#categorieFilter').val('').selectpicker('refresh');
    $('#etatFilter').val('');
    rechercher(1);
    showToast('Filtres réinitialisés', 'info');
});
$('#searchInput').on('keyup', function() { clearTimeout(window._t); window._t = setTimeout(() => rechercher(1), 300); });

// Pagination
$(document).on('click', '.page-link', function(e) {
    e.preventDefault();
    const page = $(this).data('page');
    if (page && page >= 1) rechercher(page);
});

// Ajout
$('#addBtn').on('click', function() {
    $('#formAction').val('add');
    $('#oldCode').val('');
    $('#modalTitle').text('Nouveau produit');
    $('#produitForm')[0].reset();
    $('#code_produit').prop('readonly', false);
    $('#benefice_estime').val('');
    $('#photoPreviewContainer').html('<div class="img-placeholder"><i class="bi bi-image fs-1"></i></div>');
    $('#stock_alerte').val(10);
    $('#blocStockInitial').show();
    $('.selectpicker').selectpicker('refresh');
    produitModal.show();
});

// Édition
$(document).on('click', '.editBtn', function() {
    const code = $(this).data('code');
    $.post(baseUrl, { action: 'load_edit', edit_code: code }, function(html) {
        // On soumet un formulaire caché pour récupérer les données
        const form = $('<form>', { method: 'post', action: baseUrl, style: 'display:none' });
        form.append($('<input>', { name: 'action', value: 'load_edit' }));
        form.append($('<input>', { name: 'edit_code', value: code }));
        $('body').append(form);
        form.submit();
    });
});

// Pré-remplissage si édition (côté serveur)
<?php if ($editProduit): ?>
$(function() {
    const p = <?= json_encode($editProduit) ?>;
    $('#formAction').val('edit');
    $('#oldCode').val(p.code_produit);
    $('#modalTitle').text('Modifier le produit');
    $('#code_produit').val(p.code_produit);
    $('#titre_produit').val(p.titre_produit);
    $('#prix_fournisseur').val(p.prix_fournisseur);
    $('#prix_produit').val(p.prix_produit);
    $('#stock_alerte').val(p.stock_alerte);
    $('#stock_initial').val(p.stock_produit);
    $('#categorie_id').val(p.categorie_id);
    $('#description_produit').val(p.description_produit);
    $('#benefice_estime').val((parseFloat(p.prix_produit) - parseFloat(p.prix_fournisseur)).toLocaleString('fr-FR'));
    if (p.photo) {
        $('#photoPreviewContainer').html(`<img src="data:${p.type_photo||'image/jpeg'};base64,${p.photo}" class="preview-img">`);
    }
    $('.selectpicker').selectpicker('refresh');
    produitModal.show();
});
<?php endif; ?>

// Suppression
$(document).on('click', '.deleteBtn', function() {
    const code = $(this).data('code');
    const nom = $(this).data('nom');
    $('#deleteNomProduit').text(nom);
    $('#deleteFormId').val(code);
    deleteModal.show();
});

// Auto-close alertes
setTimeout(() => $('.alert').alert('close'), 5000);

// Init selectpicker
$(document).ready(() => $('.selectpicker').selectpicker());
</script>
</body>
</html>