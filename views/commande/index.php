<?php
// index.php – Gestion du compte client et retours SAV (design vente)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// ---- VÉRIFICATION DES STATUTS ----
try {
    $pdo->exec("INSERT IGNORE INTO statut (code_statut, titre_statut, type_statut, symbole_statut, etat_statut)
                VALUES 
                ('008', 'Transfert sortie', 'sortie', '', 'Actif'),
                ('009', 'Transfert entree', 'entree', '', 'Actif'),
                ('010', 'Retour SAV', 'sortie', '', 'Actif'),
                ('011', 'Achat fournisseur', 'entree', '', 'Actif'),
                ('012', 'Vente client', 'sortie', '', 'Actif')");
} catch (PDOException $e) {}

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

// ---- LISTES ----
$clients = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Client' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$produits = $pdo->query("SELECT code_produit, titre_produit FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

$lotsParProduit = [];
$stmtLots = $pdo->query("SELECT produit_id, code_lot_produit, titre_lot, unites_par_lot FROM lot_produit WHERE etat_lot = 'Actif'");
while ($lot = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
    $lotsParProduit[$lot['produit_id']][] = $lot;
}

$message = '';
$messageType = '';
$ongletActif = $_POST['onglet'] ?? 'compte';

// ---- TRAITEMENT POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        $action = $_POST['action'];
        $user_id = $_SESSION['user_id'];

        if ($action === 'enregistrer_retour') {
            $clientId = $_POST['client_id'] ?? '';
            $produitId = $_POST['produit_id'] ?? '';
            $factureId = $_POST['facture_id'] ?? '';
            $quantiteSaisie = intval($_POST['quantite'] ?? 0);
            $lotId = $_POST['lot_id_retour'] ?? null;
            $typeRetour = $_POST['type_retour'] ?? 'Retour';
            $motif = trim($_POST['motif'] ?? '');
            $dateRetour = $_POST['date_retour'] ?? date('Y-m-d');
            $montantRembourse = floatval($_POST['montant_rembourse'] ?? 0);
            $boutiqueId = $_POST['boutique_id'] ?? '';
            $remiseEnStock = !in_array($typeRetour, ['Défectueux', 'Rebut'], true);

            if (empty($clientId) || empty($produitId) || $quantiteSaisie <= 0 || empty($boutiqueId)) {
                $message = "Client, produit, boutique et quantité sont requis.";
                $messageType = 'error';
            } else {
                $facteur = 1;
                $unite = 'Unité';
                if (!empty($lotId)) {
                    $stmt = $pdo->prepare("SELECT unites_par_lot, titre_lot FROM lot_produit WHERE code_lot_produit = ? AND produit_id = ?");
                    $stmt->execute([$lotId, $produitId]);
                    $info = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($info) {
                        $facteur = intval($info['unites_par_lot']);
                        $unite = $info['titre_lot'];
                    }
                }
                $quantiteBase = $quantiteSaisie * $facteur;

                try {
                    $pdo->beginTransaction();
                    $codeRetour = 'RET-' . date('YmdHis') . rand(100, 999);

                    $stmt = $pdo->prepare("INSERT INTO commande 
                        (numero_commande, produit_id, contact_id, facture_id, statut_id, date_commande, heure_commande,
                         prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id,
                         boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion,
                         date_livraison_recue, reference_liee, montant_rembourse, motif_retour, type_retour)
                        VALUES (?, ?, ?, ?, '010', ?, CURTIME(), 0, 0, ?, ?, ?, ?, 'Valider', ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $codeRetour,
                        $produitId,
                        $clientId,
                        $factureId,
                        $dateRetour,
                        $quantiteBase,
                        $montantRembourse,
                        $user_id,
                        $boutiqueId,
                        $lotId,
                        $unite,
                        $facteur,
                        null,
                        $codeRetour,
                        $montantRembourse,
                        $motif,
                        $typeRetour
                    ]);

                    if ($remiseEnStock) {
                        $stmtCheck = $pdo->prepare("SELECT quantite FROM stock_boutique WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                        $stmtCheck->execute([$produitId, $boutiqueId]);
                        $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $nouvelleQuantite = (int)$row['quantite'] + $quantiteBase;
                            $pdo->prepare("UPDATE stock_boutique SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                                ->execute([$nouvelleQuantite, $produitId, $boutiqueId]);
                        } else {
                            $pdo->prepare("INSERT INTO stock_boutique (produit_id, boutique_id, quantite) VALUES (?, ?, ?)")
                                ->execute([$produitId, $boutiqueId, $quantiteBase]);
                        }
                        $pdo->prepare("UPDATE produit SET stock_produit = (
                                SELECT COALESCE(SUM(quantite), 0) FROM stock_boutique WHERE produit_id = ?
                            ) WHERE code_produit = ?")
                            ->execute([$produitId, $produitId]);
                    }

                    $pdo->commit();
                    $message = "Retour enregistré sous la référence $codeRetour. Qté : $quantiteSaisie lot(s) ($quantiteBase unités)."
                        . ($remiseEnStock ? " Remis en stock." : " Non remis en stock (rebut).");
                    $messageType = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
    }
}

// ---- RÉCUPÉRATION DES RETOURS ----
$retours = $pdo->query("SELECT c.numero_commande, c.date_commande, c.produit_id, c.quantite_commande, 
                               c.montant_rembourse, c.motif_retour, c.type_retour,
                               ct.nom_prenom_contact as client, p.titre_produit
                        FROM commande c
                        LEFT JOIN contact ct ON c.contact_id = ct.code_contact
                        LEFT JOIN produit p ON c.produit_id = p.code_produit
                        WHERE c.statut_id = '010'
                        ORDER BY c.date_commande DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

// ---- INFOS CLIENT SI RECHERCHE ----
$clientInfo = null;
$facturesClient = [];
$transactionsClient = [];
$soldeClient = 0;
if (isset($_POST['client_id_recherche']) && !empty($_POST['client_id_recherche'])) {
    $clientId = $_POST['client_id_recherche'];
    $stmt = $pdo->prepare("SELECT * FROM contact WHERE code_contact = ?");
    $stmt->execute([$clientId]);
    $clientInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($clientInfo) {
        $facturesClient = $pdo->prepare("SELECT * FROM facture WHERE contact_id = ? ORDER BY date_facture DESC");
        $facturesClient->execute([$clientId]);
        $facturesClient = $facturesClient->fetchAll();
        $transactionsClient = $pdo->prepare("SELECT * FROM transaction WHERE contact_id = ? ORDER BY date_transaction DESC");
        $transactionsClient->execute([$clientId]);
        $transactionsClient = $transactionsClient->fetchAll();
        foreach ($facturesClient as $f) {
            $soldeClient += floatval($f['avance']) - floatval($f['montant_ttc']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion avancée – Compte client & Retours SAV</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

        .card {
            background: var(--w);
            border-radius: var(--R);
            border: 1px solid var(--brd);
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
            margin-bottom: 20px;
        }
        .card-header {
            background: var(--bg);
            border-bottom: 1px solid var(--brd);
            padding: 14px 20px;
            font-weight: 700;
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-body { padding: 20px; }

        .nav-tabs .nav-link {
            color: var(--mt);
            font-weight: 600;
            border: 1px solid transparent;
            background: var(--w);
            border-radius: var(--Rs) var(--Rs) 0 0;
            padding: 10px 18px;
        }
        .nav-tabs .nav-link.active {
            color: var(--b);
            border-color: var(--brd) var(--brd) var(--w);
            border-bottom: 2px solid var(--b);
            background: var(--w);
        }
        .nav-tabs .nav-link:hover {
            border-color: var(--brd);
            background: var(--bl);
        }

        .table th {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--lt);
            background: var(--bg);
            border-bottom: 1px solid var(--brd);
        }
        .table tbody tr { border-bottom: 1px solid var(--brd); }
        .table tbody tr:hover { background: var(--bl); }
        .table tbody td { vertical-align: middle; color: var(--dk); font-size: 0.85rem; }

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
        .status-badge.warning { background: var(--wrnl); color: #92400e; }
        .status-badge.off { background: var(--dngl); color: #dc2626; }

        .badge-warning { background: var(--wrnl); color: #92400e; }

        .modal-content { border-radius: var(--R); border: none; box-shadow: 0 12px 40px rgba(15,23,42,.12); }
        .modal-header { border-bottom: 1px solid var(--brd); background: var(--bg); }
        .modal-footer { border-top: 1px solid var(--brd); background: var(--bg); }

        .empty-state { text-align: center; padding: 40px 20px; color: var(--lt); }
        .empty-state i { font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.4; }

        @media (max-width:700px) {
            body { padding: 14px; }
            .hdr { flex-direction: column; align-items: flex-start; }
            .prow { flex-direction: column; align-items: stretch; }
            .prow .btn-go { width: 100%; justify-content: center; }
            .table thead th, .table tbody td { font-size: 0.65rem; padding: 4px 6px; }
        }
    </style>
</head>

<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1>Gestion avancée</h1>
            <p>Compte client & retours SAV</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-people"></i> <?= count($clients) ?> clients</div>
            <div class="hdr-badge"><i class="bi bi-arrow-counterclockwise"></i> <?= count($retours) ?> retours</div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="onglets" role="tablist">
        <li class="nav-item">
            <button class="nav-link <?= $ongletActif === 'compte' ? 'active' : '' ?>" id="tab-compte" data-bs-toggle="tab" data-bs-target="#compte" type="button" role="tab">
                <i class="bi bi-person-circle me-1"></i>Compte client
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $ongletActif === 'retours' ? 'active' : '' ?>" id="tab-retours" data-bs-toggle="tab" data-bs-target="#retours" type="button" role="tab">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Retours SAV
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- ==================== ONGLET COMPTE CLIENT ==================== -->
        <div class="tab-pane fade <?= $ongletActif === 'compte' ? 'show active' : '' ?>" id="compte" role="tabpanel">
            <div class="card">
                <div class="card-header"><i class="bi bi-person-circle"></i> Suivi client</div>
                <div class="card-body">
                    <form method="POST" id="formRechercheClient">
                        <input type="hidden" name="onglet" value="compte">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <div class="prow" style="margin-bottom:0;">
                            <label for="client_id_recherche"><i class="bi bi-search"></i> Client</label>
                            <select name="client_id_recherche" class="form-select" onchange="this.form.submit()" style="flex:1; min-width:180px;">
                                <option value="">-- Choisir --</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= e($c['code_contact']) ?>" <?= (isset($_POST['client_id_recherche']) && $_POST['client_id_recherche'] == $c['code_contact']) ? 'selected' : '' ?>><?= e($c['nom_prenom_contact']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-go"><i class="bi bi-search"></i> Afficher</button>
                        </div>
                    </form>

                    <?php if ($clientInfo): ?>
                        <hr>
                        <div class="d-flex flex-wrap justify-content-between align-items-center">
                            <div>
                                <h5><?= e($clientInfo['nom_prenom_contact']) ?></h5>
                                <small><?= e($clientInfo['code_contact']) ?></small>
                            </div>
                            <div class="text-end">
                                <div class="fs-4 fw-bold <?= $soldeClient < 0 ? 'text-danger' : 'text-success' ?>"><?= fmt($soldeClient) ?> F</div>
                                <small>Solde</small>
                            </div>
                        </div>

                        <h6 class="mt-4"><i class="bi bi-receipt me-1"></i> Factures</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>N°</th>
                                        <th>Date</th>
                                        <th>Montant TTC</th>
                                        <th>Avance</th>
                                        <th>Reste</th>
                                        <th>État</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($facturesClient)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-3">Aucune facture</td></tr>
                                    <?php else: foreach ($facturesClient as $f): ?>
                                        <tr>
                                            <td class="td-bold"><?= e($f['numero_facture']) ?></td>
                                            <td><?= e($f['date_facture']) ?></td>
                                            <td><?= fmt($f['montant_ttc']) ?></td>
                                            <td><?= fmt($f['avance']) ?></td>
                                            <td><?= fmt($f['reste']) ?></td>
                                            <td>
                                                <span class="status-badge <?= $f['etat_facture'] === 'Payer cash' || $f['etat_facture'] === 'Payée' ? 'on' : 'warning' ?>">
                                                    <span class="sdot"></span><?= e($f['etat_facture']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <h6 class="mt-4"><i class="bi bi-arrow-left-right me-1"></i> Transactions</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Montant</th>
                                        <th>Mode</th>
                                        <th>État</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($transactionsClient)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">Aucune transaction</td></tr>
                                    <?php else: foreach ($transactionsClient as $tr): ?>
                                        <tr>
                                            <td><?= e($tr['date_transaction']) ?></td>
                                            <td><?= e($tr['type_transaction']) ?></td>
                                            <td><?= fmt($tr['montant_transaction']) ?></td>
                                            <td><?= e($tr['mode_reglement']) ?></td>
                                            <td><span class="status-badge on"><span class="sdot"></span><?= e($tr['etat_transaction']) ?></span></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php elseif (isset($_POST['client_id_recherche']) && !empty($_POST['client_id_recherche'])): ?>
                        <div class="alert alert-warning mt-3">Client introuvable.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ==================== ONGLET RETOURS SAV ==================== -->
        <div class="tab-pane fade <?= $ongletActif === 'retours' ? 'show active' : '' ?>" id="retours" role="tabpanel">
            <div class="card">
                <div class="card-header">
                    <span><i class="bi bi-arrow-counterclockwise"></i> Historique des retours SAV</span>
                    <button class="btn-go" id="addRetourBtn" style="background:var(--suc);"><i class="bi bi-plus-circle"></i> Nouveau retour</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Réf.</th>
                                    <th>Client</th>
                                    <th>Produit</th>
                                    <th>Qté (base)</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Montant remb.</th>
                                    <th>Motif</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($retours)): ?>
                                    <tr><td colspan="8" class="empty-state"><i class="bi bi-inbox"></i> Aucun retour.</td></tr>
                                <?php else: foreach ($retours as $r): ?>
                                    <tr>
                                        <td class="td-bold"><?= e($r['numero_commande']) ?></td>
                                        <td><?= e($r['client'] ?? '—') ?></td>
                                        <td><?= e($r['titre_produit'] ?? '—') ?></td>
                                        <td><?= $r['quantite_commande'] ?></td>
                                        <td><span class="badge-warning" style="padding:2px 10px;border-radius:999px;font-weight:600;"><?= e($r['type_retour']) ?></span></td>
                                        <td><?= e($r['date_commande']) ?></td>
                                        <td><?= fmt($r['montant_rembourse']) ?></td>
                                        <td><?= e($r['motif_retour']) ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== MODAL : Nouveau retour SAV ==================== -->
<div class="modal fade" id="retourModal" tabindex="-1" aria-labelledby="retourModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="retourModalLabel"><i class="bi bi-arrow-counterclockwise text-primary me-2"></i> Enregistrer un retour / SAV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="retourForm">
                <input type="hidden" name="action" value="enregistrer_retour">
                <input type="hidden" name="onglet" value="retours">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Client <span class="text-danger">*</span></label>
                            <select name="client_id" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= e($c['code_contact']) ?>"><?= e($c['nom_prenom_contact']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Produit <span class="text-danger">*</span></label>
                            <select name="produit_id" id="produitRetour" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($produits as $p): ?>
                                    <option value="<?= e($p['code_produit']) ?>"><?= e($p['titre_produit']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Lot / Unité</label>
                            <select name="lot_id_retour" id="lotRetour" class="form-select">
                                <option value="">Unité</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Boutique (stock impacté) <span class="text-danger">*</span></label>
                            <select name="boutique_id" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($boutiques as $b): ?>
                                    <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Quantité (lots) <span class="text-danger">*</span></label>
                            <input type="number" name="quantite" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Type de retour <span class="text-danger">*</span></label>
                            <select name="type_retour" class="form-select" required>
                                <option value="Retour">Retour (remis en stock)</option>
                                <option value="Echange">Échange (remis en stock)</option>
                                <option value="Remboursement">Remboursement (remis en stock)</option>
                                <option value="Défectueux">Défectueux (non remis en stock)</option>
                                <option value="Rebut">Rebut (non remis en stock)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Montant remboursé</label>
                            <input type="number" step="0.01" name="montant_rembourse" class="form-control" min="0" value="0">
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Date du retour</label>
                            <input type="date" name="date_retour" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Facture associée (optionnelle)</label>
                            <input type="text" name="facture_id" class="form-control" placeholder="N° de facture">
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Motif</label>
                            <textarea name="motif" class="form-control" rows="2" placeholder="Raison du retour"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x"></i> Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer le retour</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ---- PRÉCHARGEMENT DES LOTS ----
    const lotsParProduit = <?= json_encode($lotsParProduit) ?>;

    function chargerLotsRetour(produitId) {
        const select = document.getElementById('lotRetour');
        if (!produitId) {
            select.innerHTML = '<option value="">Unité</option>';
            return;
        }
        const lots = lotsParProduit[produitId] || [];
        let options = '<option value="">Unité</option>';
        lots.forEach(lot => {
            options += `<option value="${lot.code_lot_produit}">${lot.titre_lot}</option>`;
        });
        select.innerHTML = options;
    }

    document.addEventListener('change', function(e) {
        if (e.target.id === 'produitRetour') {
            chargerLotsRetour(e.target.value);
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const modal = new bootstrap.Modal(document.getElementById('retourModal'));
        document.getElementById('addRetourBtn').addEventListener('click', function() {
            document.getElementById('retourForm').reset();
            document.getElementById('lotRetour').innerHTML = '<option value="">Unité</option>';
            modal.show();
        });
    });
</script>
</body>
</html>