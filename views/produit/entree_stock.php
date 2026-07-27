<?php
// stock_entree.php – Gestion des entrées de stock (réapprovisionnement, achats)
// Design identique à vente.php

ob_start();
require 'databases/database.php';
require 'databases/stock_functions.php';

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

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}

// --- Récupération des boutiques actives ---
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

// --- Récupération des produits actifs ---
$produits = $pdo->query("SELECT code_produit, titre_produit FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);

// --- Statut fixe pour l'entrée de stock ---
$statut_entree = '006'; // '006' = Stock d'entrée (vous pouvez changer si nécessaire)

// --- Traitement du formulaire ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'entree') {
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {
        $produitId = trim($_POST['produit_id'] ?? '');
        $boutiqueId = trim($_POST['boutique_id'] ?? '');
        $quantite = intval($_POST['quantite'] ?? 0);
        $prixAchat = floatval(str_replace(',', '.', $_POST['prix_achat'] ?? 0));
        $commentaire = trim($_POST['commentaire'] ?? '');

        if (empty($produitId) || empty($boutiqueId) || $quantite <= 0) {
            $message = 'Veuillez sélectionner un produit, une boutique et saisir une quantité valide (> 0).';
            $messageType = 'error';
        } elseif ($commentaire === '') {
            $message = 'Un commentaire est obligatoire pour justifier cette entrée (traçabilité).';
            $messageType = 'error';
        } else {
            try {
                // Récupérer le prix fournisseur si non renseigné
                if ($prixAchat <= 0) {
                    $stmtPrix = $pdo->prepare("SELECT prix_fournisseur FROM produit WHERE code_produit = ?");
                    $stmtPrix->execute([$produitId]);
                    $prixAchat = (float) ($stmtPrix->fetchColumn() ?: 0);
                }

                // Enregistrer le mouvement de stock avec le statut fixe
                $resultat = enregistrerMouvementStock(
                    $pdo,
                    $produitId,
                    $boutiqueId,
                    $statut_entree,
                    $quantite,
                    $prixAchat,
                    null,
                    $user['id'],
                    $commentaire
                );

                // Mettre à jour le stock_produit dans la table produit (pour compatibilité)
                $stmtUpdateProd = $pdo->prepare("
                    UPDATE produit SET stock_produit = (
                        SELECT COALESCE(SUM(quantite), 0) FROM stock_boutique WHERE produit_id = ?
                    ) WHERE code_produit = ?
                ");
                $stmtUpdateProd->execute([$produitId, $produitId]);

                $message = "Entrée de stock enregistrée : $quantite unité(s) ajoutées. Nouveau stock : {$resultat['stock_apres']}.";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Erreur : " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// --- Historique des entrées de stock (statut fixe) ---
$historique = $pdo->query("
    SELECT c.numero_commande, c.produit_id, c.boutique_id, c.statut_id, s.titre_statut, s.type_statut,
           c.quantite_commande, c.stock_avant, c.stock_apres, c.commentaire, c.date_commande, c.heure_commande,
           c.prix_achat, c.montant_commande
    FROM commande c
    LEFT JOIN statut s ON c.statut_id = s.code_statut
    WHERE c.statut_id = '$statut_entree'
    ORDER BY c.date_commande DESC, c.heure_commande DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// --- AJAX pour afficher le stock actuel ---
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $produitId = $_POST['produit_id'] ?? '';
    $boutiqueId = $_POST['boutique_id'] ?? '';
    $response = ['success' => false, 'quantite' => 0, 'reserve' => 0, 'disponible' => 0, 'prix' => 0];
    if (!empty($produitId) && !empty($boutiqueId)) {
        // Récupération du stock
        $stmt = $pdo->prepare("SELECT quantite, quantite_reservee FROM stock_boutique WHERE produit_id = ? AND boutique_id = ?");
        $stmt->execute([$produitId, $boutiqueId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $response['success'] = true;
            $response['quantite'] = (int)$row['quantite'];
            $response['reserve'] = (int)$row['quantite_reservee'];
            $response['disponible'] = (int)$row['quantite'] - (int)$row['quantite_reservee'];
        }
        // Récupération du prix fournisseur
        $stmtPrix = $pdo->prepare("SELECT prix_fournisseur FROM produit WHERE code_produit = ?");
        $stmtPrix->execute([$produitId]);
        $response['prix'] = (float) ($stmtPrix->fetchColumn() ?: 0);
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrée de stock</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Bootstrap SelectPicker (CSS) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ===== STYLE DASHBOARD (identique à vente.php) ===== */
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

        .product-ref {
            background: var(--bl);
            border: 1px solid var(--bb);
            border-radius: var(--Rs);
            padding: 10px 14px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }
        .product-ref .ref-name { font-size: 15px; font-weight: 700; color: var(--bd); }
        .product-ref .ref-stock { font-size: 12px; color: var(--mt); }

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

        .badge-lot {
            background: var(--bl);
            color: var(--bd);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--bb);
        }

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

        /* Ajout pour l'affichage du stock actuel */
        .stock-info {
            background: var(--bg);
            border-radius: var(--Rs);
            padding: 12px 16px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .stock-info .item {
            font-size: 14px;
        }
        .stock-info .item strong {
            font-weight: 700;
            color: var(--dk);
        }
        .stock-info .item .label {
            font-weight: 600;
            color: var(--mt);
            margin-right: 4px;
        }
        .badge-entree {
            background: var(--sucl);
            color: #065f46;
            border: 1px solid var(--sucb);
        }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1>Entrée de stock</h1>
            <p>Réapprovisionnement, achats, retours fournisseur, etc.</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-box-arrow-in-down"></i> <?= count($historique) ?> dernières entrées</div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Formulaire d'entrée -->
    <div class="data-table-wrap mb-4">
        <div class="p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;"><i class="bi bi-box-arrow-in-down me-2"></i> Nouvelle entrée de stock</h5>
        </div>
        <div class="p-4">
            <div class="product-ref">
                <span class="ref-name"><i class="bi bi-info-circle"></i>  <strong>Stock d'entrée</strong></span>
                <span class="ref-stock">Un commentaire est obligatoire pour la traçabilité.</span>
            </div>

            <form method="post" id="entreeForm">
                <input type="hidden" name="action" value="entree">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="produit_id" class="form-label fw-semibold">Produit <span class="text-danger">*</span></label>
                        <select name="produit_id" id="produit_id" class="form-select selectpicker" data-live-search="true" required>
                            <option value="">-- Choisir --</option>
                            <?php foreach ($produits as $p): ?>
                                <option value="<?= htmlspecialchars($p['code_produit']) ?>"><?= htmlspecialchars($p['titre_produit']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="boutique_id" class="form-label fw-semibold">Boutique <span class="text-danger">*</span></label>
                        <select name="boutique_id" id="boutique_id" class="form-select" required>
                            <option value="">-- Choisir --</option>
                            <?php foreach ($boutiques as $b): ?>
                                <option value="<?= htmlspecialchars($b['code_boutique']) ?>"><?= htmlspecialchars($b['nom_boutique']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="quantite" class="form-label fw-semibold">Quantité <span class="text-danger">*</span></label>
                        <input type="number" name="quantite" id="quantite" class="form-control" min="1" step="1" required>
                    </div>
                    <div class="col-md-6">
                        <label for="prix_achat" class="form-label fw-semibold">Prix d'achat unitaire</label>
                        <input type="number" step="0.01" name="prix_achat" id="prix_achat" class="form-control" placeholder="0.00">
                        <div class="form-text">Laissez vide pour utiliser le prix fournisseur du produit.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="commentaire" class="form-label fw-semibold">Commentaire <span class="text-danger">*</span></label>
                        <input type="text" name="commentaire" id="commentaire" class="form-control" placeholder="Motif précis de l'entrée (obligatoire)" required>
                    </div>
                    <div class="col-md-12">
                        <div id="stockInfo" class="stock-info">
                            <div class="item"><span class="label">Stock actuel :</span> <strong id="currentQty">—</strong></div>
                            <div class="item"><span class="label">Réservé :</span> <strong id="currentReserve">—</strong></div>
                            <div class="item"><span class="label">Disponible :</span> <strong id="currentDispo">—</strong></div>
                            <div class="item"><span class="label">Nouveau stock :</span> <strong id="newStock">—</strong></div>
                        </div>
                    </div>
                    <div class="col-md-12 mt-3">
                        <button type="submit" class="btn-go w-100" style="justify-content:center;"><i class="bi bi-save"></i> Enregistrer l'entrée</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Historique des entrées -->
    <div class="data-table-wrap">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;"><i class="bi bi-clock-history me-2"></i> Historique des entrées (30 dernières)</h5>
            <span class="text-muted small"><?= count($historique) ?> mouvements</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Produit</th>
                        <th>Boutique</th>
                        <th>Qté</th>
                        <th>Prix unitaire</th>
                        <th>Total</th>
                        <th>Stock avant</th>
                        <th>Stock après</th>
                        <th>Commentaire</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historique)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">Aucune entrée de stock enregistrée.</td></tr>
                    <?php else: ?>
                        <?php foreach ($historique as $h): ?>
                            <tr>
                                <td class="td-semi"><?= htmlspecialchars($h['numero_commande']) ?></td>
                                <td><?= htmlspecialchars($h['produit_id']) ?></td>
                                <td><?= htmlspecialchars($h['boutique_id']) ?></td>
                                <td><?= (int)$h['quantite_commande'] ?></td>
                                <td><?= fmt($h['prix_achat'] ?? 0) ?> F</td>
                                <td><?= fmt($h['montant_commande'] ?? 0) ?> F</td>
                                <td><?= (int)$h['stock_avant'] ?></td>
                                <td><?= (int)$h['stock_apres'] ?></td>
                                <td><?= htmlspecialchars($h['commentaire']) ?></td>
                                <td><?= htmlspecialchars($h['date_commande']) ?> <?= htmlspecialchars($h['heure_commande']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- SCRIPTS -->
<!-- ========================================================= -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    // --- Mise à jour des infos de stock en AJAX ---
    function updateStockInfo() {
        var produit = $('#produit_id').val();
        var boutique = $('#boutique_id').val();
        var qty = parseInt($('#quantite').val()) || 0;

        if (produit && boutique) {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    ajax: 1,
                    produit_id: produit,
                    boutique_id: boutique
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        $('#currentQty').text(data.quantite);
                        $('#currentReserve').text(data.reserve);
                        var dispo = data.quantite - data.reserve;
                        $('#currentDispo').text(dispo);
                        var nouveau = data.quantite + qty;
                        $('#newStock').text(nouveau);
                        // Si un prix d'achat est renseigné, on le pré-remplit avec le prix fournisseur
                        if (data.prix > 0 && $('#prix_achat').val() == '') {
                            $('#prix_achat').val(data.prix);
                        }
                    } else {
                        $('#currentQty').text('—');
                        $('#currentReserve').text('—');
                        $('#currentDispo').text('—');
                        $('#newStock').text('—');
                    }
                },
                error: function() {
                    // ignore
                }
            });
        } else {
            $('#currentQty').text('—');
            $('#currentReserve').text('—');
            $('#currentDispo').text('—');
            $('#newStock').text('—');
        }
    }

    // Déclencher la mise à jour à chaque changement des sélecteurs ou de la quantité
    $('#produit_id, #boutique_id').on('change', updateStockInfo);
    $('#quantite').on('input', updateStockInfo);

    // Auto-fermeture des alertes
    setTimeout(function() { $('.alert').alert('close'); }, 5000);
});
</script>
</body>
</html>