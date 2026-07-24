<?php
// index.php – Gestion du compte client et retours SAV
// Démarrer la session AVANT toute sortie
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require_once 'databases/database.php';

// Vérifier l'utilisateur
$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

// ---- VÉRIFICATION ET AJOUT DES COLONNES MANQUANTES DANS `commande` ----
try {
    $cols = $pdo->query("SHOW COLUMNS FROM commande")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reference_liee', $cols)) {
        $pdo->exec("ALTER TABLE commande ADD COLUMN reference_liee VARCHAR(100) DEFAULT NULL");
    }
    if (!in_array('montant_rembourse', $cols)) {
        $pdo->exec("ALTER TABLE commande ADD COLUMN montant_rembourse DECIMAL(12,2) DEFAULT 0");
    }
    if (!in_array('motif_retour', $cols)) {
        $pdo->exec("ALTER TABLE commande ADD COLUMN motif_retour TEXT DEFAULT NULL");
    }
    if (!in_array('type_retour', $cols)) {
        $pdo->exec("ALTER TABLE commande ADD COLUMN type_retour VARCHAR(50) DEFAULT NULL");
    }
} catch (PDOException $e) {
    // Ignorer
}

// ---- AJOUT DES NOUVEAUX STATUTS (s'ils n'existent pas) ----
try {
    $pdo->exec("INSERT IGNORE INTO statut (code_statut, titre_statut, type_statut, symbole_statut, etat_statut)
                VALUES 
                ('008', 'Transfert sortie', 'sortie', '', 'Actif'),
                ('009', 'Transfert entree', 'entree', '', 'Actif'),
                ('010', 'Retour SAV', 'sortie', '', 'Actif'),
                ('011', 'Achat fournisseur', 'entree', '', 'Actif'),
                ('012', 'Vente client', 'sortie', '', 'Actif')");
} catch (PDOException $e) {
    // Les statuts existent déjà
}

// ---- FONCTIONS UTILITAIRES ----
function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}

// ---- CSRF ----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---- RÉCUPÉRATION DES LISTES ----
$clients = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Client' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$produits = $pdo->query("SELECT code_produit, titre_produit FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

// ---- PRÉCHARGEMENT DES LOTS POUR JAVASCRIPT (pour le modal retour) ----
$lotsParProduit = [];
$stmtLots = $pdo->query("SELECT produit_id, code_lot_produit, titre_lot, unites_par_lot FROM lot_produit WHERE etat_lot = 'Actif'");
while ($lot = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
    $lotsParProduit[$lot['produit_id']][] = $lot;
}

// ---- VARIABLES ----
$message = '';
$messageType = '';
$ongletActif = $_POST['onglet'] ?? 'compte';

// ---- TRAITEMENT DES ACTIONS POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        $action = $_POST['action'];
        $user_id = $_SESSION['user_id'];

        // =============================================================
        // RETOUR SAV (statut 010)
        // =============================================================
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
            // Un retour "Défectueux"/"Rebut" ne revient pas en stock vendable ; les autres si.
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

                    // Verrou + calcul stock_avant/apres, uniquement si le retour est remis en stock
                    $stockAvant = null;
                    $stockApres = null;
                    $stockAvantLot = null;
                    $stockApresLot = null;
                    if ($remiseEnStock) {
                        // On va chercher la ligne de stock boutique (avec verrou)
                        $stmtLock = $pdo->prepare("SELECT quantite, quantite_lot FROM stock_boutique WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                        $stmtLock->execute([$produitId, $boutiqueId]);
                        $row = $stmtLock->fetch(PDO::FETCH_ASSOC);
                        if (!$row) {
                            // Si pas de ligne, on crée une ligne avec 0
                            $pdo->prepare("INSERT INTO stock_boutique (produit_id, boutique_id, quantite, quantite_lot) VALUES (?, ?, 0, 0)")
                                ->execute([$produitId, $boutiqueId]);
                            $stockAvant = 0;
                            $stockAvantLot = 0;
                        } else {
                            $stockAvant = (int)$row['quantite'];
                            $stockAvantLot = (int)$row['quantite_lot'];
                        }
                        $stockApres = $stockAvant + $quantiteBase;
                        $stockApresLot = $stockAvantLot + ($lotId ? $quantiteSaisie : 0);
                    }

                    // Insertion de la commande de retour
                    $codeRetour = 'RET-' . date('YmdHis') . rand(100, 999);
                    $stmt = $pdo->prepare("INSERT INTO commande 
                        (numero_commande, produit_id, contact_id, statut_id, date_commande, heure_commande,
                         prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id,
                         boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion,
                         montant_rembourse, motif_retour, type_retour, stock_avant, stock_apres, sens_mouvement)
                        VALUES (?, ?, ?, '010', ?, CURTIME(), 0, 0, ?, ?, ?, ?, 'Valider', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $codeRetour,
                        $produitId,
                        $clientId,
                        $dateRetour,
                        $quantiteBase,
                        $montantRembourse,
                        $user_id,
                        $boutiqueId,
                        $lotId,
                        $unite,
                        $facteur,
                        $montantRembourse,
                        $motif,
                        $typeRetour,
                        $stockAvant,
                        $stockApres,
                        $remiseEnStock ? 'ENTREE' : null
                    ]);

                    // Mise à jour du stock si remis en stock
                    if ($remiseEnStock) {
                        // Mise à jour de la quantité de base
                        $pdo->prepare("UPDATE stock_boutique SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                            ->execute([$stockApres, $produitId, $boutiqueId]);

                        // Si un lot est sélectionné, on met à jour le lot
                        if (!empty($lotId)) {
                            // On utilise INSERT ... ON DUPLICATE KEY UPDATE pour gérer le lot
                            $stmtLotUpdate = $pdo->prepare("
                                INSERT INTO stock_boutique (produit_id, boutique_id, lot_produit_id, quantite_lot)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE 
                                    lot_produit_id = VALUES(lot_produit_id),
                                    quantite_lot = quantite_lot + VALUES(quantite_lot)
                            ");
                            $stmtLotUpdate->execute([$produitId, $boutiqueId, $lotId, $quantiteSaisie]);
                        }

                        // Mise à jour du stock global produit
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
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion avancée – Compte client & Retours SAV</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            padding: 20px;
        }

        .container-crud {
            max-width: 1400px;
            margin: auto;
        }

        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
        }

        .card-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 15px 20px;
            font-weight: 600;
        }

        .card-body {
            padding: 20px;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
        }

        .btn-primary {
            background: #4f46e5;
            border-color: #4f46e5;
        }

        .btn-primary:hover {
            background: #3730a3;
            border-color: #3730a3;
        }

        .nav-tabs .nav-link {
            color: #334155;
            font-weight: 600;
            border: 1px solid transparent;
            background: #fff;
        }

        .nav-tabs .nav-link.active {
            color: #4f46e5;
            border-color: #e2e8f0 #e2e8f0 #fff;
            border-bottom: 2px solid #4f46e5;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            border-radius: 999px;
            font-size: 0.73rem;
            font-weight: 700;
        }

        .status-badge.on {
            background: #d1fae5;
            color: #059669;
        }

        .status-badge.warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .table th {
            background: #f8fafc;
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }

        .btn-ajouter {
            margin-top: 10px;
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .table thead th,
            .table tbody td {
                font-size: 0.65rem;
                padding: 4px 6px;
            }
        }
    </style>
</head>

<body>
    <div class="container-crud">
        <h2 class="mb-4"><i class="fas fa-cogs me-2"></i>Gestion avancée</h2>

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
                    <i class="fas fa-user-circle me-1"></i>Compte client
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link <?= $ongletActif === 'retours' ? 'active' : '' ?>" id="tab-retours" data-bs-toggle="tab" data-bs-target="#retours" type="button" role="tab">
                    <i class="fas fa-undo-alt me-1"></i>Retours SAV
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- ==================== ONGLET COMPTE CLIENT ==================== -->
            <div class="tab-pane fade <?= $ongletActif === 'compte' ? 'show active' : '' ?>" id="compte" role="tabpanel">
                <div class="card">
                    <div class="card-header"><i class="fas fa-user-circle"></i> Suivi client</div>
                    <div class="card-body">
                        <form method="POST" id="formRechercheClient">
                            <input type="hidden" name="onglet" value="compte">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Client</label>
                                    <select name="client_id_recherche" class="form-select" onchange="this.form.submit()">
                                        <option value="">-- Choisir --</option>
                                        <?php foreach ($clients as $c): ?>
                                            <option value="<?= e($c['code_contact']) ?>" <?= (isset($_POST['client_id_recherche']) && $_POST['client_id_recherche'] == $c['code_contact']) ? 'selected' : '' ?>><?= e($c['nom_prenom_contact']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Afficher</button>
                                </div>
                            </div>
                        </form>
                        <?php
                        if (isset($_POST['client_id_recherche']) && !empty($_POST['client_id_recherche'])) {
                            $clientId = $_POST['client_id_recherche'];
                            $stmt = $pdo->prepare("SELECT * FROM contact WHERE code_contact = ?");
                            $stmt->execute([$clientId]);
                            $client = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($client) {
                                $factures = $pdo->prepare("SELECT * FROM facture WHERE contact_id = ? ORDER BY date_facture DESC");
                                $factures->execute([$clientId]);
                                $factures = $factures->fetchAll();
                                $transactions = $pdo->prepare("SELECT * FROM transaction WHERE contact_id = ? ORDER BY date_transaction DESC");
                                $transactions->execute([$clientId]);
                                $transactions = $transactions->fetchAll();
                                $solde = 0;
                                foreach ($factures as $f) {
                                    $solde += floatval($f['avance']) - floatval($f['montant_ttc']);
                                }
                        ?>
                                <hr>
                                <div class="d-flex flex-wrap justify-content-between">
                                    <div>
                                        <h5><?= e($client['nom_prenom_contact']) ?></h5><small><?= e($client['code_contact']) ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fs-4 fw-bold <?= $solde < 0 ? 'text-danger' : 'text-success' ?>"><?= fmt($solde) ?> F</div><small>Solde</small>
                                    </div>
                                </div>
                                <h6 class="mt-4">Factures</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
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
                                            <?php if (empty($factures)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-muted text-center">Aucune facture</td>
                                                </tr>
                                                <?php else: foreach ($factures as $f): ?>
                                                    <tr>
                                                        <td><?= e($f['numero_facture']) ?></td>
                                                        <td><?= e($f['date_facture']) ?></td>
                                                        <td><?= fmt($f['montant_ttc']) ?></td>
                                                        <td><?= fmt($f['avance']) ?></td>
                                                        <td><?= fmt($f['reste']) ?></td>
                                                        <td><span class="status-badge <?= $f['etat_facture'] === 'Payer cash' ? 'on' : 'warning' ?>"><?= e($f['etat_facture']) ?></span></td>
                                                    </tr>
                                            <?php endforeach;
                                            endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <h6 class="mt-4">Transactions</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
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
                                            <?php if (empty($transactions)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-muted text-center">Aucune transaction</td>
                                                </tr>
                                                <?php else: foreach ($transactions as $tr): ?>
                                                    <tr>
                                                        <td><?= e($tr['date_transaction']) ?></td>
                                                        <td><?= e($tr['type_transaction']) ?></td>
                                                        <td><?= fmt($tr['montant_transaction']) ?></td>
                                                        <td><?= e($tr['mode_reglement']) ?></td>
                                                        <td><span class="status-badge on"><?= e($tr['etat_transaction']) ?></span></td>
                                                    </tr>
                                            <?php endforeach;
                                            endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                        <?php } else {
                                echo '<div class="alert alert-warning mt-3">Client introuvable.</div>';
                            }
                        } ?>
                    </div>
                </div>
            </div>

            <!-- ==================== ONGLET RETOURS SAV (avec modal) ==================== -->
            <div class="tab-pane fade <?= $ongletActif === 'retours' ? 'show active' : '' ?>" id="retours" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-undo-alt"></i> Historique des retours SAV</span>
                        <button class="btn btn-primary btn-sm" id="addRetourBtn"><i class="fas fa-plus"></i> Nouveau retour</button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
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
                                        <tr>
                                            <td colspan="8" class="empty-state"><i class="fas fa-inbox"></i> Aucun retour.</td>
                                        </tr>
                                        <?php else: foreach ($retours as $r): ?>
                                            <tr>
                                                <td><?= e($r['numero_commande']) ?></td>
                                                <td><?= e($r['client'] ?? '—') ?></td>
                                                <td><?= e($r['titre_produit'] ?? '—') ?></td>
                                                <td><?= $r['quantite_commande'] ?></td>
                                                <td><span class="badge badge-warning"><?= e($r['type_retour']) ?></span></td>
                                                <td><?= e($r['date_commande']) ?></td>
                                                <td><?= fmt($r['montant_rembourse']) ?></td>
                                                <td><?= e($r['motif_retour']) ?></td>
                                            </tr>
                                    <?php endforeach;
                                    endif; ?>
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
                    <h5 class="modal-title fw-bold" id="retourModalLabel"><i class="fas fa-undo-alt text-primary me-2"></i> Enregistrer un retour / SAV</h5>
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
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Montant remboursé</label>
                                <input type="number" step="0.01" name="montant_rembourse" class="form-control" min="0" value="0">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Date du retour</label>
                                <input type="date" name="date_retour" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Facture associée (optionnelle)</label>
                                <input type="text" name="facture_id" class="form-control" placeholder="N° de facture">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Motif</label>
                                <textarea name="motif" class="form-control" rows="2" placeholder="Raison du retour"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Annuler</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer le retour</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ---- PRÉCHARGEMENT DES LOTS (injectés par PHP) ----
        const lotsParProduit = <?= json_encode($lotsParProduit) ?>;

        // ---- Fonction pour remplir le select des lots dans le modal ----
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

        // ---- Écouter le changement de produit dans le modal ----
        document.addEventListener('change', function(e) {
            if (e.target.id === 'produitRetour') {
                chargerLotsRetour(e.target.value);
            }
        });

        // ---- Initialisation du modal ----
        document.addEventListener('DOMContentLoaded', function() {
            const modal = new bootstrap.Modal(document.getElementById('retourModal'));
            document.getElementById('addRetourBtn').addEventListener('click', function() {
                // Réinitialiser le formulaire
                document.getElementById('retourForm').reset();
                document.getElementById('lotRetour').innerHTML = '<option value="">Unité</option>';
                modal.show();
            });
        });
    </script>
</body>

</html>