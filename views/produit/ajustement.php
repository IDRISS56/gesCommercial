<?php
// ajustement_stock.php – Ajustement de stock (pertes, corrections, etc.)
// Design aligné sur vente.php

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

require 'databases/stock_functions.php';

// --- Statuts d'ajustement ---
$statutsReserves = ['008', '009', '010', '011', '012', '016', '017'];
$statutsAjustement = $pdo->query("
    SELECT code_statut, titre_statut, type_statut
    FROM statut
    WHERE etat_statut = 'Actif'
      AND LOWER(type_statut) IN ('entree', 'sortie')
      AND code_statut NOT IN ('" . implode("','", $statutsReserves) . "')
    ORDER BY type_statut, titre_statut
")->fetchAll(PDO::FETCH_ASSOC);

$produitsList = $pdo->query("SELECT code_produit, titre_produit FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);
$boutiquesList = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

// --- Traitement POST (ajustement uniquement) ---
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else if ($action === 'ajustement') {
        $produitId = $_POST['produit_id_ajust'] ?? '';
        $boutiqueId = $_POST['boutique_id_ajust'] ?? '';
        $statutId = $_POST['statut_id_ajust'] ?? '';
        $quantite = intval($_POST['quantite_ajust'] ?? 0);
        $commentaire = trim($_POST['commentaire_ajust'] ?? '');

        $statutsCodes = array_column($statutsAjustement, 'code_statut');
        if (empty($produitId) || empty($boutiqueId) || !in_array($statutId, $statutsCodes) || $quantite <= 0) {
            $message = "Veuillez renseigner le produit, la boutique, le motif et une quantité positive.";
            $messageType = 'error';
        } elseif ($commentaire === '') {
            $message = "Un commentaire est obligatoire pour justifier ce mouvement (traçabilité).";
            $messageType = 'error';
        } else {
            try {
                $stmtPrix = $pdo->prepare("SELECT prix_fournisseur FROM produit WHERE code_produit = ?");
                $stmtPrix->execute([$produitId]);
                $prixUnitaire = (float) ($stmtPrix->fetchColumn() ?: 0);

                $resultat = enregistrerMouvementStock(
                    $pdo,
                    $produitId,
                    $boutiqueId,
                    $statutId,
                    $quantite,
                    $prixUnitaire,
                    null,
                    $user['id'],
                    $commentaire
                );

                $message = "Mouvement {$resultat['numero_commande']} ({$resultat['titre_statut']}) enregistré : stock passé de {$resultat['stock_avant']} à {$resultat['stock_apres']}.";
                $messageType = 'success';
            } catch (Exception $ex) {
                $message = "Erreur : " . $ex->getMessage();
                $messageType = 'error';
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// --- Historique des ajustements (30 derniers) ---
$historiqueAjustements = $pdo->query("
    SELECT c.numero_commande, c.produit_id, c.boutique_id, c.statut_id, s.titre_statut, s.type_statut,
           c.quantite_commande, c.stock_avant, c.stock_apres, c.commentaire, c.date_commande, c.heure_commande
    FROM commande c
    LEFT JOIN statut s ON c.statut_id = s.code_statut
    WHERE c.statut_id NOT IN ('008','009','010','011','012','016','017')
    ORDER BY c.date_commande DESC, c.heure_commande DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajustement de stock</title>
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

        .badge-lot {
            background: var(--bl);
            color: var(--bd);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--bb);
        }
        .badge-lot.empty {
            background: #f1f5f9;
            color: var(--lt);
            border-color: var(--brd);
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
        .btn-success {
            background: var(--suc);
            color: white;
            padding: 8px 16px;
            border-radius: var(--Rs);
            font-weight: 600;
            font-size: 14px;
            border: none;
            transition: background 0.2s;
            cursor: pointer;
        }
        .btn-success:hover { background: #059669; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .data-table-wrap { animation: fadeUp .4s ease both; }

        @media (max-width:700px) {
            body { padding: 14px; }
            .hdr { flex-direction: column; align-items: flex-start; }
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
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1>Ajustement de stock</h1>
            <p>Corrections, pertes, inventaires – traçabilité obligatoire</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-clipboard2-pulse"></i> <?= count($historiqueAjustements) ?> derniers mouvements</div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Formulaire d'ajustement -->
    <div class="data-table-wrap mb-4">
        <div class="p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;"><i class="bi bi-pencil-square me-2"></i> Nouvel ajustement</h5>
        </div>
        <div class="p-4">
            <div class="product-ref">
                <span class="ref-name"><i class="bi bi-info-circle"></i> Motifs configurables dans <strong>Configurations &gt; Statuts</strong></span>
                <span class="ref-stock">Les mouvements sont tracés avec commentaire obligatoire.</span>
            </div>

            <form method="post" id="ajustForm">
                <input type="hidden" name="action" value="ajustement">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="produit_id_ajust" class="form-label fw-semibold">Produit <span class="text-danger">*</span></label>
                        <select name="produit_id_ajust" id="produit_id_ajust" class="form-select" required>
                            <option value="">-- Choisir --</option>
                            <?php foreach ($produitsList as $p): ?>
                                <option value="<?= htmlspecialchars($p['code_produit']) ?>"><?= htmlspecialchars($p['titre_produit']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="boutique_id_ajust" class="form-label fw-semibold">Boutique <span class="text-danger">*</span></label>
                        <select name="boutique_id_ajust" id="boutique_id_ajust" class="form-select" required>
                            <option value="">-- Choisir --</option>
                            <?php foreach ($boutiquesList as $b): ?>
                                <option value="<?= htmlspecialchars($b['code_boutique']) ?>"><?= htmlspecialchars($b['nom_boutique']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="statut_id_ajust" class="form-label fw-semibold">Motif (statut) <span class="text-danger">*</span></label>
                        <select name="statut_id_ajust" id="statut_id_ajust" class="form-select" required>
                            <option value="">-- Choisir --</option>
                            <?php foreach ($statutsAjustement as $s): ?>
                                <option value="<?= htmlspecialchars($s['code_statut']) ?>">
                                    <?= htmlspecialchars($s['titre_statut']) ?> (<?= strtolower($s['type_statut']) === 'entree' ? 'Entrée' : 'Sortie' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="quantite_ajust" class="form-label fw-semibold">Quantité <span class="text-danger">*</span></label>
                        <input type="number" name="quantite_ajust" id="quantite_ajust" class="form-control" min="1" required>
                    </div>
                    <div class="col-md-9">
                        <label for="commentaire_ajust" class="form-label fw-semibold">Commentaire <span class="text-danger">*</span></label>
                        <input type="text" name="commentaire_ajust" id="commentaire_ajust" class="form-control" placeholder="Motif précis du mouvement (obligatoire)" required>
                    </div>
                    <div class="col-md-12 mt-3">
                        <button type="submit" class="btn-success w-100"><i class="bi bi-save"></i> Enregistrer l'ajustement</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Historique -->
    <div class="data-table-wrap">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;"><i class="bi bi-clock-history me-2"></i> Historique des ajustements (30 derniers)</h5>
            <span class="text-muted small"><?= count($historiqueAjustements) ?> mouvements</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Produit</th>
                        <th>Boutique</th>
                        <th>Motif</th>
                        <th>Qté</th>
                        <th>Avant</th>
                        <th>Après</th>
                        <th>Commentaire</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historiqueAjustements)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Aucun ajustement enregistré.</td></tr>
                    <?php else: ?>
                        <?php foreach ($historiqueAjustements as $h): ?>
                            <tr>
                                <td class="td-semi"><?= htmlspecialchars($h['numero_commande']) ?></td>
                                <td><?= htmlspecialchars($h['produit_id']) ?></td>
                                <td><?= htmlspecialchars($h['boutique_id']) ?></td>
                                <td><?= htmlspecialchars($h['titre_statut'] ?? $h['statut_id']) ?></td>
                                <td><?= (int)$h['quantite_commande'] ?></td>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function() {
    // Initialisation des selectpicker si besoin
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    // Auto-fermeture des alertes
    setTimeout(function() { $('.alert').alert('close'); }, 5000);
});
</script>
</body>
</html>