<?php
// views/transaction/index.php – Gestion des transactions
require __DIR__ . '/../../databases/database.php';
session_start();

// Vérifier la session
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

// Vérifier que l'utilisateur existe toujours
$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

// Fonctions utilitaires
function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}

// --- Fonction pour générer un ID automatique ---
function generateTransactionId($pdo)
{
    $date = date('Ymd');
    $prefix = 'TRX-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transaction WHERE numero_transaction LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// --- Récupération des listes ---
$contacts = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$factures = $pdo->query("SELECT numero_facture, titre_facture FROM facture ORDER BY numero_facture")->fetchAll(PDO::FETCH_ASSOC);
$utilisateurs = $pdo->query("SELECT id, nom_prenom FROM utilisateur WHERE etat = 'Actif' ORDER BY nom_prenom")->fetchAll(PDO::FETCH_ASSOC);

// Définitions des listes
$types_transaction = ['Encaissement', 'Décaissement', 'Virement', 'Transfert'];
$modes_reglement = ['Espece', 'Cheque', 'Virement bancaire', 'Mobile money', 'Carte'];
$etats_transaction = ['Succes', 'Echec', 'En attente', 'Annule'];

// --- Traitement des actions POST ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {
        if ($action === 'add' || $action === 'edit') {
            $numero = trim($_POST['numero_transaction'] ?? '');
            $date = trim($_POST['date_transaction'] ?? date('Y-m-d'));
            $heure = trim($_POST['heure_transaction'] ?? date('H:i'));
            $montant = floatval(str_replace(',', '.', $_POST['montant_transaction'] ?? 0));
            $frais = floatval(str_replace(',', '.', $_POST['frais_transaction'] ?? 0));
            $montant_total = floatval(str_replace(',', '.', $_POST['montant_total'] ?? 0));
            $type = trim($_POST['type_transaction'] ?? '');
            $objet = trim($_POST['objet_transaction'] ?? '');
            $contact_id = trim($_POST['contact_id'] ?? '');
            $facture_id = trim($_POST['facture_id'] ?? '');
            $mode_reglement = trim($_POST['mode_reglement'] ?? '');
            $numero_reglement = trim($_POST['numero_reglement'] ?? '');
            $reference_reglement = trim($_POST['reference_reglement'] ?? '');
            $valider_par = trim($_POST['valider_par'] ?? $_SESSION['user_id']);
            $etat = trim($_POST['etat_transaction'] ?? 'Succes');

            // Calcul du montant total si non fourni
            if ($montant_total == 0 && $montant > 0) {
                $montant_total = $montant + $frais;
            }

            $errors = [];
            if (empty($type)) $errors[] = 'Le type est requis.';
            if (empty($mode_reglement)) $errors[] = 'Le mode de règlement est requis.';
            if ($montant <= 0) $errors[] = 'Le montant doit être supérieur à 0.';

            if (empty($errors)) {
                try {
                    if ($action === 'add') {
                        $numero = generateTransactionId($pdo);

                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transaction WHERE numero_transaction = ?");
                        $stmt->execute([$numero]);
                        if ($stmt->fetchColumn() > 0) {
                            $message = "Ce numéro de transaction existe déjà.";
                            $messageType = 'warning';
                        } else {
                            $sql = "INSERT INTO transaction (numero_transaction, date_transaction, heure_transaction, montant_transaction, frais_transaction, montant_total, type_transaction, objet_transaction, contact_id, facture_id, mode_reglement, numero_reglement, reference_reglement, valider_par, etat_transaction)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$numero, $date, $heure, $montant, $frais, $montant_total, $type, $objet, $contact_id, $facture_id, $mode_reglement, $numero_reglement, $reference_reglement, $valider_par, $etat]);
                            $message = "Transaction n°$numero ajoutée avec succès.";
                            $messageType = 'success';
                        }
                    } elseif ($action === 'edit') {
                        $oldNumero = $_POST['old_numero'] ?? $numero;
                        $sql = "UPDATE transaction SET numero_transaction=?, date_transaction=?, heure_transaction=?, montant_transaction=?, frais_transaction=?, montant_total=?, type_transaction=?, objet_transaction=?, contact_id=?, facture_id=?, mode_reglement=?, numero_reglement=?, reference_reglement=?, valider_par=?, etat_transaction=?
                                WHERE numero_transaction = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$numero, $date, $heure, $montant, $frais, $montant_total, $type, $objet, $contact_id, $facture_id, $mode_reglement, $numero_reglement, $reference_reglement, $valider_par, $etat, $oldNumero]);
                        $message = "Transaction n°$numero mise à jour.";
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
        if ($action === 'delete' && isset($_POST['btn_supprimer']) && $_POST['btn_supprimer'] == '1') {
            $numero = $_POST['sai_supprimer_id'] ?? '';
            if (!empty($numero)) {
                try {
                    $stmt = $pdo->prepare("SELECT numero_transaction FROM transaction WHERE numero_transaction = ?");
                    $stmt->execute([$numero]);
                    $num = $stmt->fetchColumn();
                    $stmt = $pdo->prepare("DELETE FROM transaction WHERE numero_transaction = ?");
                    $stmt->execute([$numero]);
                    $message = "Transaction n°$num supprimée.";
                    $messageType = 'danger';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}

// Générer un token CSRF
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// --- AJAX pour le tableau ---
function getTableContent($pdo, $search, $filtres, $page, $perPage = 20)
{
    $sql = "SELECT t.*, c.nom_prenom_contact, f.titre_facture, u.nom_prenom AS valideur_nom
            FROM transaction t
            LEFT JOIN contact c ON t.contact_id = c.code_contact
            LEFT JOIN facture f ON t.facture_id = f.numero_facture
            LEFT JOIN utilisateur u ON t.valider_par = u.id
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (t.numero_transaction LIKE ? OR t.objet_transaction LIKE ? OR c.nom_prenom_contact LIKE ? OR f.titre_facture LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 4; $i++) $params[] = $like;
    }
    if (!empty($filtres['type'])) {
        $sql .= " AND t.type_transaction = ?";
        $params[] = $filtres['type'];
    }
    if (!empty($filtres['mode_reglement'])) {
        $sql .= " AND t.mode_reglement = ?";
        $params[] = $filtres['mode_reglement'];
    }
    if (!empty($filtres['etat'])) {
        $sql .= " AND t.etat_transaction = ?";
        $params[] = $filtres['etat'];
    }
    if (!empty($filtres['contact'])) {
        $sql .= " AND t.contact_id = ?";
        $params[] = $filtres['contact'];
    }
    if (!empty($filtres['facture'])) {
        $sql .= " AND t.facture_id = ?";
        $params[] = $filtres['facture'];
    }
    if (!empty($filtres['date_debut'])) {
        $sql .= " AND t.date_transaction >= ?";
        $params[] = $filtres['date_debut'];
    }
    if (!empty($filtres['date_fin'])) {
        $sql .= " AND t.date_transaction <= ?";
        $params[] = $filtres['date_fin'];
    }

    $countSql = str_replace("SELECT t.*, c.nom_prenom_contact, f.titre_facture, u.nom_prenom AS valideur_nom", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $sql .= " ORDER BY t.date_transaction DESC, t.heure_transaction DESC LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($transactions)): ?>
        <tr>
            <td colspan="12" class="text-center py-5 text-muted">
                <i class="fas fa-inbox fa-2x d-block mb-2 opacity-50"></i>
                Aucune transaction trouvée
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($transactions as $tr): ?>
            <tr>
                <td class="td-bold"><?= e($tr['numero_transaction']) ?></td>
                <td><?= date('d/m/Y', strtotime($tr['date_transaction'])) ?></td>
                <td><?= substr($tr['heure_transaction'], 0, 5) ?></td>
                <td><?= e($tr['type_transaction']) ?></td>
                <td><?= number_format((float)$tr['montant_total'], 0, ',', ' ') ?></td>
                <td><?= e($tr['mode_reglement']) ?></td>
                <td><?= e($tr['nom_prenom_contact'] ?? '—') ?></td>
                <td><?= e($tr['titre_facture'] ?? '—') ?></td>
                <td><?= e($tr['valideur_nom'] ?? '—') ?></td>
                <td>
                    <span class="status-badge <?= $tr['etat_transaction'] === 'Succes' ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= e($tr['etat_transaction']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn v viewBtn" data-code="<?= e($tr['numero_transaction']) ?>" title="Voir"><i class="fas fa-eye"></i></button>
                        <button class="act-btn e editBtn" data-code="<?= e($tr['numero_transaction']) ?>" title="Modifier"><i class="fas fa-pen"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= e($tr['numero_transaction']) ?>" data-nom="n°<?= e($tr['numero_transaction']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="fas fa-trash"></i></button>
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

// --- AJAX pour le tableau ---
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $filtres = [
        'type' => trim($_POST['type'] ?? ''),
        'mode_reglement' => trim($_POST['mode_reglement'] ?? ''),
        'etat' => trim($_POST['etat'] ?? ''),
        'contact' => trim($_POST['contact'] ?? ''),
        'facture' => trim($_POST['facture'] ?? ''),
        'date_debut' => trim($_POST['date_debut'] ?? ''),
        'date_fin' => trim($_POST['date_fin'] ?? '')
    ];
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $filtres, $page);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// --- AJAX pour voir une transaction ---
if (isset($_POST['ajax_view']) && $_POST['ajax_view'] == '1') {
    $numero = trim($_POST['code'] ?? '');
    if (empty($numero)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Numéro non spécifié']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT t.*, c.nom_prenom_contact, f.titre_facture, u.nom_prenom AS valideur_nom
                               FROM transaction t
                               LEFT JOIN contact c ON t.contact_id = c.code_contact
                               LEFT JOIN facture f ON t.facture_id = f.numero_facture
                               LEFT JOIN utilisateur u ON t.valider_par = u.id
                               WHERE t.numero_transaction = ?");
        $stmt->execute([$numero]);
        $tr = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tr) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'numero_transaction' => $tr['numero_transaction'],
                'date_transaction' => date('d/m/Y', strtotime($tr['date_transaction'])),
                'heure_transaction' => substr($tr['heure_transaction'], 0, 5),
                'montant_transaction' => number_format((float)$tr['montant_transaction'], 0, ',', ' '),
                'frais_transaction' => number_format((float)$tr['frais_transaction'], 0, ',', ' '),
                'montant_total' => number_format((float)$tr['montant_total'], 0, ',', ' '),
                'type_transaction' => $tr['type_transaction'],
                'objet_transaction' => $tr['objet_transaction'] ?? '—',
                'contact' => $tr['nom_prenom_contact'] ?? '—',
                'facture' => $tr['titre_facture'] ?? '—',
                'mode_reglement' => $tr['mode_reglement'],
                'numero_reglement' => $tr['numero_reglement'] ?? '—',
                'reference_reglement' => $tr['reference_reglement'] ?? '—',
                'valideur' => $tr['valideur_nom'] ?? '—',
                'etat_transaction' => $tr['etat_transaction']
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Transaction non trouvée']);
        }
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- Affichage initial ---
$search = trim($_POST['search'] ?? '');
$filtres = [
    'type' => trim($_POST['type'] ?? ''),
    'mode_reglement' => trim($_POST['mode_reglement'] ?? ''),
    'etat' => trim($_POST['etat'] ?? ''),
    'contact' => trim($_POST['contact'] ?? ''),
    'facture' => trim($_POST['facture'] ?? ''),
    'date_debut' => trim($_POST['date_debut'] ?? ''),
    'date_fin' => trim($_POST['date_fin'] ?? '')
];
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $filtres, $page);

$editTransaction = null;
if ($action === 'edit' && isset($_POST['edit_code'])) {
    $numero = $_POST['edit_code'];
    $stmt = $pdo->prepare("SELECT * FROM transaction WHERE numero_transaction = ?");
    $stmt->execute([$numero]);
    $editTransaction = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des transactions</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Bootstrap SelectPicker (CSS) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* === Styles identiques === */
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

        .calcul-auto {
            font-size: 0.75rem;
            color: var(--color-primary);
            font-weight: 600;
            margin-top: 4px;
        }
    </style>
</head>

<body>
    <div class="container-crud">

        <!-- En-tête -->
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
            <div class="page-heading">
                <h2 class="fw-800 mb-0">Gestion des transactions</h2>
                <p class="text-tertiary mt-1">Suivez l'ensemble des encaissements et décaissements</p>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="fas fa-plus"></i> Nouvelle transaction</button>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Barre de recherche et filtres -->
        <div class="bg-light p-3 rounded-3 mb-3 border">
            <form id="searchForm" method="post" onsubmit="return false;">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="page" id="pageInput" value="<?= $page ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label for="searchInput" class="form-label fw-semibold small">Recherche</label>
                        <div class="search-inline" style="min-width:100%;">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" id="searchInput" placeholder="N°, objet, contact..." value="<?= e($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label for="typeFilter" class="form-label fw-semibold small">Type</label>
                        <select name="type" id="typeFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un type...">
                            <option value="">Tous</option>
                            <?php foreach ($types_transaction as $t): ?>
                                <option value="<?= e($t) ?>" <?= ($filtres['type'] == $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="modeReglementFilter" class="form-label fw-semibold small">Mode de règlement</label>
                        <select name="mode_reglement" id="modeReglementFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un mode...">
                            <option value="">Tous</option>
                            <?php foreach ($modes_reglement as $m): ?>
                                <option value="<?= e($m) ?>" <?= ($filtres['mode_reglement'] == $m) ? 'selected' : '' ?>><?= e($m) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="etatFilter" class="form-label fw-semibold small">État</label>
                        <select name="etat" id="etatFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un état...">
                            <option value="">Tous</option>
                            <?php foreach ($etats_transaction as $e): ?>
                                <option value="<?= e($e) ?>" <?= ($filtres['etat'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="contactFilter" class="form-label fw-semibold small">Contact</label>
                        <select name="contact" id="contactFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un contact...">
                            <option value="">Tous</option>
                            <?php foreach ($contacts as $c): ?>
                                <option value="<?= e($c['code_contact']) ?>" <?= ($filtres['contact'] == $c['code_contact']) ? 'selected' : '' ?>><?= e($c['nom_prenom_contact']) ?></option>
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
                <h5 class="mb-0 fw-bold">Liste des transactions</h5>
                <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?> transaction(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>N°</th>
                            <th>Date</th>
                            <th>Heure</th>
                            <th>Type</th>
                            <th>Montant total</th>
                            <th>Mode règlement</th>
                            <th>Contact</th>
                            <th>Facture</th>
                            <th>Validé par</th>
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
    <div class="modal fade" id="transactionModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-exchange-alt text-primary me-2"></i> Nouvelle transaction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post" id="transactionForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="old_numero" id="oldNumero" value="">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="modal-body">
                        <!-- Numéro, date, heure -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-hashtag me-1"></i> Identification</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="numero_transaction" class="form-label fw-semibold">N° transaction</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                                    <input type="text" class="form-control" id="numero_transaction" name="numero_transaction" readonly value="<?= e($editTransaction['numero_transaction'] ?? generateTransactionId($pdo)) ?>">
                                </div>
                                <div class="form-text">ID généré automatiquement</div>
                            </div>
                            <div class="col-md-4">
                                <label for="date_transaction" class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                    <input type="date" class="form-control" id="date_transaction" name="date_transaction" required value="<?= e($editTransaction['date_transaction'] ?? date('Y-m-d')) ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="heure_transaction" class="form-label fw-semibold">Heure</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                    <input type="time" class="form-control" id="heure_transaction" name="heure_transaction" value="<?= e($editTransaction['heure_transaction'] ?? date('H:i')) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Type et objet -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-tag me-1"></i> Détails</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="type_transaction" class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="type_transaction" name="type_transaction" required>
                                    <option value="">=== Faites votre choix ===</option>
                                    <?php foreach ($types_transaction as $t): ?>
                                        <option value="<?= e($t) ?>" <?= (isset($editTransaction) && $editTransaction['type_transaction'] == $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="objet_transaction" class="form-label fw-semibold">Objet</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-pencil-alt"></i></span>
                                    <input type="text" class="form-control" id="objet_transaction" name="objet_transaction" placeholder="Paiement facture..." value="<?= e($editTransaction['objet_transaction'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Montants -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-coins me-1"></i> Montants</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="montant_transaction" class="form-label fw-semibold">Montant <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-dollar-sign"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="montant_transaction" name="montant_transaction" placeholder="0.00" value="<?= e($editTransaction['montant_transaction'] ?? 0) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="frais_transaction" class="form-label fw-semibold">Frais</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-percent"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="frais_transaction" name="frais_transaction" placeholder="0.00" value="<?= e($editTransaction['frais_transaction'] ?? 0) ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="montant_total" class="form-label fw-semibold">Montant total</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calculator"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="montant_total" name="montant_total" placeholder="0.00" readonly value="<?= e($editTransaction['montant_total'] ?? 0) ?>">
                                </div>
                                <div class="calcul-auto">Calculé automatiquement (Montant + Frais)</div>
                            </div>
                        </div>

                        <!-- Associations -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-link me-1"></i> Associations</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="contact_id" class="form-label fw-semibold">Contact</label>
                                <select class="selectpicker form-control" id="contact_id" name="contact_id" data-live-search="true" data-live-search-placeholder="Rechercher un contact...">
                                    <option value="">=== Aucun ===</option>
                                    <?php foreach ($contacts as $c): ?>
                                        <option value="<?= e($c['code_contact']) ?>" <?= (isset($editTransaction) && $editTransaction['contact_id'] == $c['code_contact']) ? 'selected' : '' ?>><?= e($c['nom_prenom_contact']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="facture_id" class="form-label fw-semibold">Facture</label>
                                <select class="selectpicker form-control" id="facture_id" name="facture_id" data-live-search="true" data-live-search-placeholder="Rechercher une facture...">
                                    <option value="">=== Aucune ===</option>
                                    <?php foreach ($factures as $f): ?>
                                        <option value="<?= e($f['numero_facture']) ?>" <?= (isset($editTransaction) && $editTransaction['facture_id'] == $f['numero_facture']) ? 'selected' : '' ?>><?= e($f['numero_facture'] . ' - ' . $f['titre_facture']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Règlement -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-credit-card me-1"></i> Règlement</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="mode_reglement" class="form-label fw-semibold">Mode de règlement <span class="text-danger">*</span></label>
                                <select class="form-select" id="mode_reglement" name="mode_reglement" required>
                                    <option value="">=== Faites votre choix ===</option>
                                    <?php foreach ($modes_reglement as $m): ?>
                                        <option value="<?= e($m) ?>" <?= (isset($editTransaction) && $editTransaction['mode_reglement'] == $m) ? 'selected' : '' ?>><?= e($m) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="numero_reglement" class="form-label fw-semibold">Numéro de règlement</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                                    <input type="text" class="form-control" id="numero_reglement" name="numero_reglement" placeholder="N° chèque..." value="<?= e($editTransaction['numero_reglement'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="reference_reglement" class="form-label fw-semibold">Référence</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-qrcode"></i></span>
                                    <input type="text" class="form-control" id="reference_reglement" name="reference_reglement" placeholder="Réf. externe" value="<?= e($editTransaction['reference_reglement'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Validateur et état -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-user-check me-1"></i> Validation</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="valider_par" class="form-label fw-semibold">Validé par</label>
                                <select class="selectpicker form-control" id="valider_par" name="valider_par" data-live-search="true" data-live-search-placeholder="Rechercher un utilisateur...">
                                    <option value="">=== Aucun ===</option>
                                    <?php foreach ($utilisateurs as $u): ?>
                                        <option value="<?= e($u['id']) ?>" <?= (isset($editTransaction) && $editTransaction['valider_par'] == $u['id']) ? 'selected' : '' ?>><?= e($u['nom_prenom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="etat_transaction" class="form-label fw-semibold">État</label>
                                <select class="form-select" id="etat_transaction" name="etat_transaction">
                                    <?php foreach ($etats_transaction as $e): ?>
                                        <option value="<?= e($e) ?>" <?= (isset($editTransaction) && $editTransaction['etat_transaction'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                                    <?php endforeach; ?>
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
                    <h5 class="modal-title fw-bold" id="viewModalLabel"><i class="fas fa-eye text-primary me-2"></i> Détails de la transaction</h5>
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
                    <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer la transaction <strong id="deleteNomTransaction"></strong> ?<br>Cette action est irréversible.</p>
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
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="btn_supprimer" value="1">
        <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
    </form>

    <!-- Formulaire caché pour action edit -->
    <form method="post" id="actionForm">
        <input type="hidden" name="action" id="actionField">
        <input type="hidden" name="edit_code" id="editCodeField">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
    </form>

    <!-- =========================================================
SCRIPTS
========================================================= -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

    <script>
        $(document).ready(function() {
            // --- Initialisation des selectpicker ---
            $('.selectpicker').selectpicker('destroy');
            $('.selectpicker').selectpicker();

            // --- Calcul automatique du montant total ---
            function calculerMontantTotal() {
                const montant = parseFloat($('#montant_transaction').val()) || 0;
                const frais = parseFloat($('#frais_transaction').val()) || 0;
                const total = montant + frais;
                $('#montant_total').val(total.toFixed(2));
            }

            $('#montant_transaction, #frais_transaction').on('input', function() {
                calculerMontantTotal();
            });

            // --- Ouvrir modal Ajout ---
            $('#addBtn').on('click', function(e) {
                e.preventDefault();
                $('#formAction').val('add');
                $('#oldNumero').val('');
                $('#modalTitle').html('<i class="fas fa-exchange-alt text-primary me-2"></i> Nouvelle transaction');

                // Réinitialiser les champs
                $('#transactionForm')[0].reset();
                $('#numero_transaction').prop('readonly', true);
                $('#numero_transaction').val('<?= generateTransactionId($pdo) ?>');
                $('#date_transaction').val('<?= date('Y-m-d') ?>');
                $('#heure_transaction').val('<?= date('H:i') ?>');
                $('#montant_transaction').val('0');
                $('#frais_transaction').val('0');
                $('#montant_total').val('0');
                $('#type_transaction').val('');
                $('#objet_transaction').val('');
                $('#mode_reglement').val('');
                $('#numero_reglement').val('');
                $('#reference_reglement').val('');
                $('#etat_transaction').val('Succes');

                // Réinitialiser les selectpicker
                $('#contact_id, #facture_id, #valider_par').selectpicker('val', '');

                var modalEl = document.getElementById('transactionModal');
                var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.show();
            });

            // --- Édition ---
            $(document).on('click', '.editBtn', function(e) {
                e.preventDefault();
                const code = $(this).data('code');
                $('#actionField').val('edit');
                $('#editCodeField').val(code);
                $('#actionForm').submit();
            });

            // --- Vue ---
            $(document).on('click', '.viewBtn', function(e) {
                e.preventDefault();
                const code = $(this).data('code');
                $('#viewModal').modal('hide');
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        ajax_view: '1',
                        code: code
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#viewModalLabel').text('Transaction ' + response.numero_transaction);
                            const fields = [
                                ['N° transaction', response.numero_transaction],
                                ['Date', response.date_transaction],
                                ['Heure', response.heure_transaction],
                                ['Type', response.type_transaction],
                                ['Objet', response.objet_transaction],
                                ['Montant', response.montant_transaction + ' FCFA'],
                                ['Frais', response.frais_transaction + ' FCFA'],
                                ['Montant total', response.montant_total + ' FCFA'],
                                ['Mode de règlement', response.mode_reglement],
                                ['N° règlement', response.numero_reglement],
                                ['Référence', response.reference_reglement],
                                ['Contact', response.contact],
                                ['Facture', response.facture],
                                ['Validé par', response.valideur],
                                ['État', response.etat_transaction]
                            ];
                            let html = '';
                            fields.forEach(([label, value]) => {
                                let val = value || '—';
                                html += '<div class="col-sm-6"><div class="bg-light p-3 rounded-3 border"><div class="text-muted small text-uppercase fw-bold">' + label + '</div><div class="fw-semibold">' + val + '</div></div></div>';
                            });
                            $('#viewGrid').html(html);
                            $('#viewModal').modal('show');
                        } else {
                            alert('Erreur : ' + (response.message || 'Transaction non trouvée'));
                        }
                    },
                    error: function() {
                        alert('Erreur lors de la récupération des données.');
                    }
                });
            });

            // --- Fonction de recherche AJAX ---
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
                        $('#totalCount').text(data.total + ' transaction(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
                        $('.page-link').off('click').on('click', function(e) {
                            e.preventDefault();
                            var p = $(this).data('page');
                            if (p) rechercher(p);
                        });
                        $('.selectpicker').selectpicker('refresh');
                    },
                    error: function() {
                        alert('Erreur lors de la recherche.');
                    }
                });
            }

            // Auto-submit pour les champs
            var searchTimeout = null;
            $('#searchInput, #date_debut, #date_fin').on('input change', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });

            // Pour les selectpicker du filtre
            $('#typeFilter, #modeReglementFilter, #etatFilter, #contactFilter, #factureFilter').on('changed.bs.select', function() {
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
                $('#date_debut').val('');
                $('#date_fin').val('');
                $('#typeFilter, #modeReglementFilter, #etatFilter, #contactFilter, #factureFilter').selectpicker('val', '');
                rechercher(1);
            });

            // Pagination initiale
            $('.page-link').on('click', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                if (page) rechercher(page);
            });

            // --- Gestion suppression ---
            $(document).on('click', '.deleteBtn', function(e) {
                e.preventDefault();
                const code = $(this).data('code');
                const nom = $(this).data('nom');
                $('#deleteNomTransaction').text(nom);
                $('#deleteFormId').val(code);
                $('#deleteConfirmModal').modal('show');
            });
            $('#confirmDeleteBtn').on('click', function() {
                $('#deleteForm').submit();
            });

            // Auto-fermeture des alertes
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);

            // --- Si édition via POST ---
            <?php if (isset($editTransaction) && $action === 'edit'): ?>
                $(function() {
                    $('#formAction').val('edit');
                    $('#oldNumero').val('<?= e($editTransaction['numero_transaction']) ?>');
                    $('#modalTitle').html('<i class="fas fa-exchange-alt text-primary me-2"></i> Modifier la transaction');
                    $('#numero_transaction').prop('readonly', true);
                    $('.selectpicker').selectpicker('refresh');
                    var modalEl = document.getElementById('transactionModal');
                    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                    modal.show();
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>