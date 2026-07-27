<?php
ob_start(); // capture tout octet parasite (BOM, espaces) émis par ce fichier ou les fichiers inclus
// views/transaction/index.php – Gestion des transactions (design vente)
require __DIR__ . '/../../databases/database.php';


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

function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}

function generateTransactionId($pdo)
{
    $date = date('Ymd');
    $prefix = 'TRX-' . $date . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transaction WHERE numero_transaction LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = intval($stmt->fetchColumn()) + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

$contacts = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$factures = $pdo->query("SELECT numero_facture, titre_facture FROM facture ORDER BY numero_facture")->fetchAll(PDO::FETCH_ASSOC);
$utilisateurs = $pdo->query("SELECT id, nom_prenom FROM utilisateur WHERE etat = 'Actif' ORDER BY nom_prenom")->fetchAll(PDO::FETCH_ASSOC);

$types_transaction = ['Encaissement', 'Décaissement', 'Virement', 'Transfert'];
$modes_reglement = ['Espece', 'Cheque', 'Virement bancaire', 'Mobile money', 'Carte'];
$etats_transaction = ['Succes', 'Echec', 'En attente', 'Annule'];

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

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

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
            <td colspan="11" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
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
                        <button class="act-btn e editBtn" data-code="<?= e($tr['numero_transaction']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-code="<?= e($tr['numero_transaction']) ?>" data-nom="n°<?= e($tr['numero_transaction']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash"></i></button>
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
        'table' => $tableHtml,
        'pagination' => $paginationHtml,
        'total' => $total,
        'page' => $page,
        'totalPages' => $totalPages
    ];
}

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
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

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
if ($action === 'load_edit' && isset($_POST['edit_code'])) {
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

        .calcul-auto {
            font-size: 0.75rem;
            color: var(--b);
            font-weight: 600;
            margin-top: 4px;
        }
    </style>
</head>

<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1>Gestion des transactions</h1>
            <p>Suivez l'ensemble des encaissements et décaissements</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-arrow-left-right"></i> <?= $initialData['total'] ?? 0 ?> transaction(s)</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouvelle transaction</button>
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
                <input type="text" name="search" id="searchInput" placeholder="N°, objet, contact..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                <label for="typeFilter">Type</label>
                <select name="type" id="typeFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un type...">
                    <option value="">Tous</option>
                    <?php foreach ($types_transaction as $t): ?>
                        <option value="<?= e($t) ?>" <?= ($filtres['type'] == $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="modeReglementFilter">Mode</label>
                <select name="mode_reglement" id="modeReglementFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un mode...">
                    <option value="">Tous</option>
                    <?php foreach ($modes_reglement as $m): ?>
                        <option value="<?= e($m) ?>" <?= ($filtres['mode_reglement'] == $m) ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="etatFilter">État</label>
                <select name="etat" id="etatFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un état...">
                    <option value="">Tous</option>
                    <?php foreach ($etats_transaction as $e): ?>
                        <option value="<?= e($e) ?>" <?= ($filtres['etat'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="contactFilter">Contact</label>
                <select name="contact" id="contactFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un contact...">
                    <option value="">Tous</option>
                    <?php foreach ($contacts as $c): ?>
                        <option value="<?= e($c['code_contact']) ?>" <?= ($filtres['contact'] == $c['code_contact']) ? 'selected' : '' ?>><?= e($c['nom_prenom_contact']) ?></option>
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
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des transactions</h5>
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

<!-- ========================================================= -->
<!-- MODAL FORMULAIRE (ajout/modification) -->
<!-- ========================================================= -->
<div class="modal fade" id="transactionModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-arrow-left-right text-primary me-2"></i> Nouvelle transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form method="post" id="transactionForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="old_numero" id="oldNumero" value="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <!-- Numéro, date, heure -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-hash me-1"></i> Identification</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label for="numero_transaction" class="form-label fw-semibold">N° transaction</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="numero_transaction" name="numero_transaction" readonly value="<?= e($editTransaction['numero_transaction'] ?? generateTransactionId($pdo)) ?>">
                            </div>
                            <div class="form-text">ID généré automatiquement</div>
                        </div>
                        <div class="col-md-4">
                            <label for="date_transaction" class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                <input type="date" class="form-control" id="date_transaction" name="date_transaction" required value="<?= e($editTransaction['date_transaction'] ?? date('Y-m-d')) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="heure_transaction" class="form-label fw-semibold">Heure</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-clock"></i></span>
                                <input type="time" class="form-control" id="heure_transaction" name="heure_transaction" value="<?= e($editTransaction['heure_transaction'] ?? date('H:i')) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Type et objet -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-tag me-1"></i> Détails</h6>
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
                                <span class="input-group-text"><i class="bi bi-pencil"></i></span>
                                <input type="text" class="form-control" id="objet_transaction" name="objet_transaction" placeholder="Paiement facture..." value="<?= e($editTransaction['objet_transaction'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Montants -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-coins me-1"></i> Montants</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label for="montant_transaction" class="form-label fw-semibold">Montant <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-currency-dollar"></i></span>
                                <input type="number" step="0.01" class="form-control" id="montant_transaction" name="montant_transaction" placeholder="0.00" value="<?= e($editTransaction['montant_transaction'] ?? 0) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="frais_transaction" class="form-label fw-semibold">Frais</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-percent"></i></span>
                                <input type="number" step="0.01" class="form-control" id="frais_transaction" name="frais_transaction" placeholder="0.00" value="<?= e($editTransaction['frais_transaction'] ?? 0) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="montant_total" class="form-label fw-semibold">Montant total</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calculator"></i></span>
                                <input type="number" step="0.01" class="form-control" id="montant_total" name="montant_total" placeholder="0.00" readonly value="<?= e($editTransaction['montant_total'] ?? 0) ?>">
                            </div>
                            <div class="calcul-auto">Calculé automatiquement (Montant + Frais)</div>
                        </div>
                    </div>

                    <!-- Associations -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-link me-1"></i> Associations</h6>
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
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-credit-card me-1"></i> Règlement</h6>
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
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="numero_reglement" name="numero_reglement" placeholder="N° chèque..." value="<?= e($editTransaction['numero_reglement'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="reference_reglement" class="form-label fw-semibold">Référence</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-qr-code"></i></span>
                                <input type="text" class="form-control" id="reference_reglement" name="reference_reglement" placeholder="Réf. externe" value="<?= e($editTransaction['reference_reglement'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Validateur et état -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-person-check me-1"></i> Validation</h6>
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

<!-- ========================================================= -->
<!-- SCRIPTS -->
<!-- ========================================================= -->
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
        const transactionModal = new bootstrap.Modal(document.getElementById('transactionModal'));

        $('#addBtn').on('click', function(e) {
            e.preventDefault();
            $('#formAction').val('add');
            $('#oldNumero').val('');
            $('#modalTitle').html('<i class="bi bi-arrow-left-right text-primary me-2"></i> Nouvelle transaction');

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

            $('#contact_id, #facture_id, #valider_par').selectpicker('val', '');
            transactionModal.show();
        });

        // --- Édition ---
        $(document).on('click', '.editBtn', function(e) {
            e.preventDefault();
            const code = $(this).data('code');
            $('#actionField').val('load_edit');
            $('#editCodeField').val(code);
            $('#actionForm').submit();
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

        var searchTimeout = null;
        $('#searchInput, #date_debut, #date_fin').on('input change', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() { rechercher(1); }, 300);
        });

        $('#typeFilter, #modeReglementFilter, #etatFilter, #contactFilter, #factureFilter').on('changed.bs.select', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() { rechercher(1); }, 300);
        });

        $('#filterBtn').on('click', function() { rechercher(1); });
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
        setTimeout(function() { $('.alert').alert('close'); }, 5000);

        // --- Si édition via POST ---
        <?php if (isset($editTransaction) && $action === 'load_edit'): ?>
            $(function() {
                $('#formAction').val('edit');
                $('#oldNumero').val('<?= e($editTransaction['numero_transaction']) ?>');
                $('#modalTitle').html('<i class="bi bi-arrow-left-right text-primary me-2"></i> Modifier la transaction');
                $('#numero_transaction').prop('readonly', true);
                $('.selectpicker').selectpicker('refresh');
                transactionModal.show();
            });
        <?php endif; ?>
    });
</script>
</body>

</html>