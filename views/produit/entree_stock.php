<?php
// entree_stock.php – Gestion des entrées de stock (achats, réapprovisionnement)
// Design identique à index.php – Structure conforme à gescommercial.sql

ob_start();
require 'databases/database.php';

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

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}

function generateCommandeId($pdo) {
    $prefix = 'ENT-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT numero_commande FROM commande WHERE numero_commande LIKE ? ORDER BY numero_commande DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    if ($last) {
        $num = (int)substr($last, strrpos($last, '-') + 1) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad($num, 5, '0', STR_PAD_LEFT);
}

// - Récupération des boutiques actives -
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

// - Récupération des produits (tous, sauf RUPTURE si souhaité) -
$produits = $pdo->query("SELECT code_produit, titre_produit, prix_fournisseur FROM produit ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);

// - Statut fixe pour l'entrée de stock (011 = Achat / ENTREE dans la table statut) -
$statut_entree = '011';

// - Traitement du formulaire -
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
            $messageType = 'danger';
        } elseif ($commentaire === '') {
            $message = 'Un commentaire est obligatoire pour justifier cette entrée (traçabilité).';
            $messageType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();

                // Récupérer le prix fournisseur si non renseigné
                if ($prixAchat <= 0) {
                    $stmtPrix = $pdo->prepare("SELECT prix_fournisseur FROM produit WHERE code_produit = ?");
                    $stmtPrix->execute([$produitId]);
                    $prixAchat = (float) ($stmtPrix->fetchColumn() ?: 0);
                }

                // Récupérer le stock actuel (stock_avant)
                $stmtStock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ?");
                $stmtStock->execute([$produitId, $boutiqueId]);
                $stockAvant = (int) ($stmtStock->fetchColumn() ?: 0);
                $stockApres = $stockAvant + $quantite;
                $montantCommande = $prixAchat * $quantite;

                // Générer le numéro de commande
                $numeroCommande = generateCommandeId($pdo);

                // Insérer dans la table commande (statut 011 = Achat/ENTREE)
                $stmtCmd = $pdo->prepare("
                    INSERT INTO commande 
                    (numero_commande, produit_id, lot_id, produits_par_lot, contact_id, facture_id, statut_id, 
                     date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, montant_commande, 
                     utilisateur_id, boutique_id, etat_commande)
                    VALUES (?, ?, NULL, 1, NULL, NULL, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, 'VALIDEE')
                ");
                $stmtCmd->execute([
                    $numeroCommande, $produitId, $statut_entree,
                    $prixAchat, $prixAchat, $quantite, $montantCommande,
                    $user['id'], $boutiqueId
                ]);

                // Mettre à jour la table stock (INSERT ON DUPLICATE KEY UPDATE)
                $stmtStockUp = $pdo->prepare("
                    INSERT INTO stock (produit_id, boutique_id, quantite, stock_alerte)
                    VALUES (?, ?, ?, 10)
                    ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)
                ");
                $stmtStockUp->execute([$produitId, $boutiqueId, $quantite]);

                // Mettre à jour le stock_produit global dans la table produit
                $pdo->prepare("UPDATE produit SET stock_produit = stock_produit + ? WHERE code_produit = ?")
                    ->execute([$quantite, $produitId]);

                // Mettre à jour l'état du produit si nécessaire
                $pdo->prepare("UPDATE produit SET etat_produit = 'DISPONIBLE' WHERE code_produit = ? AND etat_produit = 'RUPTURE'")
                    ->execute([$produitId]);

                $pdo->commit();

                $message = "Entrée de stock enregistrée (N° $numeroCommande) : $quantite unité(s) ajoutées. Stock : $stockAvant → $stockApres.";
                $messageType = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Erreur : " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// - Historique des entrées de stock (statut 011 = Achat) -
$historique = $pdo->query("
    SELECT 
        c.numero_commande, 
        c.produit_id, 
        c.boutique_id, 
        c.statut_id, 
        s.titre_statut, 
        s.type_statut,
        c.quantite_commande, 
        c.prix_achat, 
        c.prix_commande,
        c.montant_commande,
        c.date_commande, 
        c.heure_commande,
        c.etat_commande,
        p.titre_produit,
        b.nom_boutique
    FROM commande c
    LEFT JOIN statut s ON c.statut_id = s.code_statut
    LEFT JOIN produit p ON c.produit_id = p.code_produit
    LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
    WHERE c.statut_id = '$statut_entree'
    ORDER BY c.date_commande DESC, c.heure_commande DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// - Statistiques -
$totalEntrees = count($historique);
$quantiteTotale = array_sum(array_column($historique, 'quantite_commande'));
$valeurTotale = array_sum(array_column($historique, 'montant_commande'));

// - AJAX pour afficher le stock actuel -
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $produitId = $_POST['produit_id'] ?? '';
    $boutiqueId = $_POST['boutique_id'] ?? '';
    $response = ['success' => false, 'quantite' => 0, 'disponible' => 0, 'prix' => 0];

    if (!empty($produitId) && !empty($boutiqueId)) {
        // Récupération du stock (pas de quantite_reservee dans cette structure)
        $stmt = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ?");
        $stmt->execute([$produitId, $boutiqueId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $response['success'] = true;
            $response['quantite'] = (int)$row['quantite'];
            $response['disponible'] = (int)$row['quantite'];
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Bootstrap SelectPicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    
    <!-- Google Fonts -->
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
            --color-gray-400: #94a3b8;
            --color-gray-500: #64748b;
            --color-gray-600: #475569;
            --color-gray-700: #334155;
            --color-gray-800: #1e293b;
            --color-gray-900: #0f172a;
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-tertiary: #64748b;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.06);
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

        .stat-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            transition: var(--transition-base);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .stat-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
            line-height: 1;
        }

        .data-table-wrap {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            animation: fadeUp .4s ease both;
        }

        .table { margin: 0; }

        .table thead th {
            background: var(--color-gray-100);
            color: var(--text-tertiary);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 12px 14px;
            border-bottom: 2px solid var(--border-color);
        }

        .table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background .2s;
        }

        .table tbody tr:hover {
            background: var(--color-primary-soft);
        }

        .table tbody td {
            padding: 12px 14px;
            vertical-align: middle;
            color: var(--text-primary);
            font-size: 13px;
        }

        .td-bold {
            color: var(--text-primary) !important;
            font-weight: 700;
        }

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
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width .4s, height .4s;
        }

        .btn-chic:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-chic i { font-size: 15px; position: relative; z-index: 1; }
        .btn-chic span { position: relative; z-index: 1; }

        .btn-chic-success {
            background: linear-gradient(135deg, var(--color-success) 0%, #059669 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-chic-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
            color: #fff;
        }

        .form-label {
            font-size: 10px;
            font-weight: 700;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 1.5px solid var(--border-color);
            padding: 10px 14px;
            font-size: 13px;
            transition: all .2s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px var(--color-primary-soft);
        }

        .stock-info {
            background: var(--color-gray-50);
            border-radius: var(--radius-sm);
            padding: 16px;
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            margin-top: 12px;
            border: 1px solid var(--border-color);
        }

        .stock-info .item { font-size: 13px; }

        .stock-info .item strong {
            font-weight: 700;
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
        }

        .stock-info .item .label {
            font-weight: 600;
            color: var(--text-tertiary);
            margin-right: 4px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-entree {
            background: var(--color-success-soft);
            color: #065f46;
            border: 1px solid var(--color-success);
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .bootstrap-select .dropdown-toggle {
            background: #fff !important;
            border: 1.5px solid var(--border-color) !important;
            border-radius: 10px !important;
        }

        .bootstrap-select .dropdown-toggle:focus {
            border-color: var(--color-primary) !important;
            box-shadow: 0 0 0 3px var(--color-primary-soft) !important;
        }

        .bootstrap-select .dropdown-menu {
            border-radius: var(--radius-sm);
            border-color: var(--border-color);
            box-shadow: var(--shadow-md);
        }

        @media (max-width: 700px) {
            body { padding: 14px; }
            .stock-info { flex-direction: column; gap: 12px; }
        }
    </style>
</head>
<body>
    <div class="W">
        <!-- En-tête -->
        <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
            <div>
                <h1 class="h3 fw-bold mb-1">
                    <i class="bi bi-box-arrow-in-down text-primary me-2"></i>Entrée de stock
                </h1>
                <p class="text-muted small mb-0">Réapprovisionnement, achats, retours fournisseur, etc.</p>
            </div>
            <div class="d-flex gap-2">
                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-3 py-2">
                    <i class="bi bi-box-arrow-in-down"></i> <?= $totalEntrees ?> entrées
                </span>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show mb-4" role="alert" style="border-radius:var(--radius-sm);border:none;padding:16px 20px;font-size:13px;font-weight:500;">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : ($messageType === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?> me-2"></i>
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="row g-3 mb-4">
            <?php
            $stats = [
                ['primary', 'box-arrow-in-down', 'Total entrées', $totalEntrees, 'mouvements'],
                ['success', 'boxes', 'Quantité totale', $quantiteTotale, 'unités'],
                ['info', 'currency-dollar', 'Valeur totale', fmt($valeurTotale), 'FCFA']
            ];
            $colorMap = [
                'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
                'success' => ['var(--color-success-soft)', 'var(--color-success)'],
                'info' => ['var(--color-info-soft)', 'var(--color-info)'],
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
                                <?php if ($s[4]): ?>
                                    <small class="text-muted ms-1" style="font-size:11px;"><?= $s[4] ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Formulaire d'entrée -->
        <div class="data-table-wrap mb-4">
            <div class="p-3 border-bottom bg-light">
                <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">
                    <i class="bi bi-plus-circle me-2 text-primary"></i>Nouvelle entrée de stock
                </h5>
            </div>
            <div class="p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge-entree">
                        <i class="bi bi-info-circle"></i>
                        <strong>Statut Achat (011)</strong>
                    </span>
                    <span class="text-muted small">Un commentaire est obligatoire pour la traçabilité.</span>
                </div>

                <form method="post" id="entreeForm">
                    <input type="hidden" name="action" value="entree">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="produit_id" class="form-label">Produit <span class="text-danger">*</span></label>
                            <select name="produit_id" id="produit_id" class="form-select selectpicker" data-live-search="true" required>
                                <option value="">-- Choisir un produit --</option>
                                <?php foreach ($produits as $p): ?>
                                    <option value="<?= htmlspecialchars($p['code_produit']) ?>">
                                        <?= htmlspecialchars($p['titre_produit']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="boutique_id" class="form-label">Boutique <span class="text-danger">*</span></label>
                            <select name="boutique_id" id="boutique_id" class="form-select selectpicker" data-live-search="true" required>
                                <option value="">-- Choisir une boutique --</option>
                                <?php foreach ($boutiques as $b): ?>
                                    <option value="<?= htmlspecialchars($b['code_boutique']) ?>">
                                        <?= htmlspecialchars($b['nom_boutique']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="quantite" class="form-label">Quantité <span class="text-danger">*</span></label>
                            <input type="number" name="quantite" id="quantite" class="form-control" min="1" step="1" required placeholder="0">
                        </div>

                        <div class="col-md-4">
                            <label for="prix_achat" class="form-label">Prix d'achat unitaire</label>
                            <input type="number" step="0.01" name="prix_achat" id="prix_achat" class="form-control" placeholder="0.00">
                            <div class="form-text" style="font-size:11px;color:var(--text-tertiary);margin-top:4px;">
                                Laissez vide pour utiliser le prix fournisseur du produit.
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="commentaire" class="form-label">Commentaire <span class="text-danger">*</span></label>
                            <input type="text" name="commentaire" id="commentaire" class="form-control" placeholder="Motif précis de l'entrée (obligatoire)" required>
                        </div>

                        <div class="col-12">
                            <div id="stockInfo" class="stock-info">
                                <div class="item">
                                    <span class="label">Stock actuel :</span>
                                    <strong id="currentQty">—</strong>
                                </div>
                                <div class="item">
                                    <span class="label">Disponible :</span>
                                    <strong id="currentDispo">—</strong>
                                </div>
                                <div class="item">
                                    <span class="label">Nouveau stock :</span>
                                    <strong id="newStock">—</strong>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 mt-3">
                            <button type="submit" class="btn-chic btn-chic-success w-100" style="justify-content:center;">
                                <i class="bi bi-save"></i>
                                <span>Enregistrer l'entrée</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Historique des entrées -->
        <div class="data-table-wrap">
            <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
                <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">
                    <i class="bi bi-clock-history me-2 text-info"></i>Historique des entrées (30 dernières)
                </h5>
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
                            <th>État</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historique)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                    Aucune entrée de stock enregistrée.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($historique as $h): ?>
                                <tr>
                                    <td class="td-bold"><?= htmlspecialchars($h['numero_commande']) ?></td>
                                    <td><?= htmlspecialchars($h['titre_produit'] ?? $h['produit_id']) ?></td>
                                    <td><?= htmlspecialchars($h['nom_boutique'] ?? '—') ?></td>
                                    <td><span class="badge bg-success-subtle text-success"><?= (int)$h['quantite_commande'] ?></span></td>
                                    <td><?= fmt($h['prix_achat'] ?? 0) ?> F</td>
                                    <td class="td-bold"><?= fmt($h['montant_commande'] ?? 0) ?> F</td>
                                    <td>
                                        <?php if (($h['etat_commande'] ?? '') === 'VALIDEE'): ?>
                                            <span class="badge bg-primary-subtle text-primary">Validée</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning">En attente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($h['date_commande']) ?>
                                            <br><?= htmlspecialchars($h['heure_commande']) ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
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

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('.selectpicker').selectpicker();

            const toastEl = document.getElementById('toastMsg');
            const toast = new bootstrap.Toast(toastEl, { delay: 3500 });

            function showToast(msg, type = 'success') {
                const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-primary' };
                const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
                $('#toastBody').html(`<i class="bi ${icons[type]} me-2"></i>${msg}`);
                toastEl.className = `toast align-items-center text-white border-0 ${colors[type]}`;
                toast.show();
            }

            <?php if ($message): ?>
                showToast('<?= addslashes($message) ?>', '<?= $messageType === 'success' ? 'success' : ($messageType === 'danger' ? 'error' : 'info') ?>');
            <?php endif; ?>

            // Mise à jour des infos de stock en AJAX
            function updateStockInfo() {
                var produit = $('#produit_id').val();
                var boutique = $('#boutique_id').val();
                var qty = parseInt($('#quantite').val()) || 0;

                if (produit && boutique) {
                    $.ajax({
                        url: window.location.href,
                        method: 'POST',
                        data: { ajax: 1, produit_id: produit, boutique_id: boutique },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success) {
                                $('#currentQty').text(data.quantite);
                                $('#currentDispo').text(data.disponible);
                                $('#newStock').text(data.quantite + qty);
                                if (data.prix > 0 && $('#prix_achat').val() == '') {
                                    $('#prix_achat').val(data.prix);
                                }
                            } else {
                                $('#currentQty, #currentDispo, #newStock').text('—');
                            }
                        }
                    });
                } else {
                    $('#currentQty, #currentDispo, #newStock').text('—');
                }
            }

            $('#produit_id, #boutique_id').on('changed.bs.select', updateStockInfo);
            $('#quantite').on('input', updateStockInfo);

            setTimeout(function() { $('.alert').alert('close'); }, 5000);
        });
    </script>
</body>
</html>