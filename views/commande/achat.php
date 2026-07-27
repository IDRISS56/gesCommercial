<?php
// views/achats/achat_fournisseur.php – Création de bons de commande fournisseur + réception directe
// Design dashboard identique à vente.php

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require 'databases/database.php';

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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---- LISTES ----
$fournisseurs = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Fournisseur' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique, adresse_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$produits = $pdo->query("SELECT code_produit, titre_produit, prix_fournisseur FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);

// ---- FACTURES FOURNISSEUR EXISTANTES ----
$facturesFournisseur = $pdo->query("
    SELECT numero_facture, titre_facture, date_facture, montant_ttc, contact_id
    FROM facture 
    WHERE type_facture = 'Fournisseur' AND etat_facture != 'Annulée'
    ORDER BY date_facture DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ---- PRÉCHARGEMENT DES LOTS ----
$lotsParProduit = [];
$stmtLots = $pdo->query("SELECT produit_id, code_lot_produit, titre_lot, unites_par_lot FROM lot_produit WHERE etat_lot = 'Actif'");
while ($lot = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
    $lotsParProduit[$lot['produit_id']][] = $lot;
}

// ---- PRIX FOURNISSEUR ----
$prixFournisseur = [];
foreach ($produits as $p) {
    $prixFournisseur[$p['code_produit']] = $p['prix_fournisseur'] ?? 0;
}

// ---- TRAITEMENT DU FORMULAIRE DE CRÉATION DE BON ----
$message = '';
$messageType = '';
$bonData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'valider_bon') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'error';
    } else {
        $fournisseurId = $_POST['fournisseur_id'] ?? '';
        $dateBon = $_POST['date_bon'] ?? date('Y-m-d');
        $boutiqueId = $_POST['boutique_id'] ?? '';
        $dateLivraison = $_POST['date_livraison'] ?? null;
        $factureId = $_POST['facture_id'] ?? ''; // peut être vide
        $numBon = 'BC-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $produitsPost = $_POST['produit_id'] ?? [];
        $lots = $_POST['lot_id'] ?? [];
        $quantites = $_POST['quantite'] ?? [];
        $prixUnitaires = $_POST['prix_unitaire'] ?? [];
        $totalBon = 0;

        if (empty($fournisseurId) || empty($boutiqueId) || empty($produitsPost)) {
            $message = "Veuillez sélectionner un fournisseur, une boutique et au moins un produit.";
            $messageType = 'error';
        } elseif (empty($dateLivraison) || $dateLivraison < date('Y-m-d')) {
            $message = "La date de livraison ne peut pas être antérieure à aujourd'hui.";
            $messageType = 'error';
        } else {
            $errors = false;
            $lignesValides = [];
            foreach ($produitsPost as $index => $produitId) {
                if (empty($produitId)) continue;
                $quantite = intval($quantites[$index] ?? 0);
                $prix = floatval($prixUnitaires[$index] ?? 0);
                if ($quantite <= 0 || $prix < 0) {
                    $errors = true;
                    $message = "Vérifiez les quantités et prix unitaires (positifs).";
                    $messageType = 'error';
                    break;
                }
                $lotId = $lots[$index] ?? null;
                $facteur = 1;
                $uniteAff = 'Unité';
                if (!empty($lotId)) {
                    $stmtLot = $pdo->prepare("SELECT unites_par_lot, titre_lot FROM lot_produit WHERE code_lot_produit = ? AND produit_id = ?");
                    $stmtLot->execute([$lotId, $produitId]);
                    $lotData = $stmtLot->fetch(PDO::FETCH_ASSOC);
                    if ($lotData) {
                        $facteur = intval($lotData['unites_par_lot'] ?: 1);
                        $uniteAff = $lotData['titre_lot'];
                    }
                }
                $quantiteBase = $quantite * $facteur;
                $totalLigne = $quantiteBase * $prix;
                $totalBon += $totalLigne;
                $lignesValides[] = [
                    'produit_id' => $produitId,
                    'lot_id' => $lotId,
                    'quantite_saisie' => $quantite,
                    'facteur' => $facteur,
                    'quantite_base' => $quantiteBase,
                    'prix_unitaire' => $prix,
                    'total_ligne' => $totalLigne,
                    'unite_affichage' => $uniteAff
                ];
            }

            if (!$errors && !empty($lignesValides)) {
                try {
                    $pdo->beginTransaction();

                    // Si aucune facture sélectionnée, on en crée une automatiquement
                    if (empty($factureId)) {
                        $numFacture = 'FAC-FOUR-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        $taxe = 0;
                        $montant_ht = $totalBon;
                        $montant_ttc = $montant_ht * (1 + $taxe / 100);
                        $stmtFact = $pdo->prepare("INSERT INTO facture 
                            (numero_facture, titre_facture, type_facture, categorie_facture, 
                             date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, 
                             contact_id, utilisateur_id, etat_facture)
                            VALUES (?, ?, 'Fournisseur', 'Achat', ?, ?, ?, 0, ?, ?, ?, ?, ?, 'En attente')");
                        $stmtFact->execute([
                            $numFacture,
                            'Facture fournisseur ' . $numFacture,
                            date('Y-m-d'),
                            $montant_ht,
                            $taxe,
                            $montant_ttc,
                            $montant_ttc,
                            $montant_ttc,
                            $fournisseurId,
                            $user['id']
                        ]);
                        $factureId = $numFacture;
                    }

                    // Enregistrement des lignes de commande
                    foreach ($lignesValides as $ligne) {
                        $numCommandeUnique = $numBon . '-' . date('His') . rand(100, 999);
                        $stmt = $pdo->prepare("INSERT INTO commande 
                            (numero_commande, produit_id, contact_id, facture_id, statut_id, date_commande, heure_commande, 
                             prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id, 
                             boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion,
                             reference_liee, date_livraison_recue)
                            VALUES (?, ?, ?, ?, '011', ?, CURTIME(), ?, ?, ?, ?, ?, ?, 'En attente', ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $numCommandeUnique,
                            $ligne['produit_id'],
                            $fournisseurId,
                            $factureId,
                            $dateBon,
                            $ligne['prix_unitaire'],
                            0,
                            $ligne['quantite_base'],
                            $ligne['total_ligne'],
                            $user['id'],
                            $boutiqueId,
                            $ligne['lot_id'],
                            $ligne['unite_affichage'],
                            $ligne['facteur'],
                            $numBon,
                            $dateLivraison
                        ]);
                    }

                    $pdo->commit();

                    // Mise à jour du statut de la facture si elle vient d'être créée
                    if (isset($numFacture)) {
                        $pdo->prepare("UPDATE facture SET etat_facture = 'En attente' WHERE numero_facture = ?")
                            ->execute([$numFacture]);
                    }

                    $message = "Bon de commande $numBon enregistré avec " . count($lignesValides) . " ligne(s). Facture liée : $factureId.";
                    $messageType = 'success';
                    $bonData = [
                        'num' => $numBon,
                        'date' => $dateBon,
                        'fournisseur' => $fournisseurId,
                        'boutique' => $boutiqueId,
                        'date_livraison' => $dateLivraison,
                        'facture' => $factureId,
                        'lignes' => $lignesValides,
                        'total' => $totalBon
                    ];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
    }
}

// ---- TRAITEMENT RÉCEPTION / ANNULATION (sans modale, directement dans la page) ----
$receptionMessage = '';
$receptionMessageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reception'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $receptionMessage = "Token de sécurité invalide.";
        $receptionMessageType = 'error';
    } else {
        $reference = $_POST['reference_liee'] ?? '';
        $action = $_POST['action_reception'];

        $stmtLignes = $pdo->prepare("SELECT * FROM commande WHERE reference_liee = ? AND statut_id = '011' AND etat_commande = 'En attente'");
        $stmtLignes->execute([$reference]);
        $lignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);

        if (empty($lignes)) {
            $receptionMessage = "Ce bon n'a plus de ligne en attente.";
            $receptionMessageType = 'error';
        } elseif ($action === 'recevoir') {
            try {
                $pdo->beginTransaction();
                $fournisseurId = null;
                $factureId = null;
                foreach ($lignes as $ligne) {
                    if ($fournisseurId === null) $fournisseurId = $ligne['contact_id'];
                    if ($factureId === null) $factureId = $ligne['facture_id'];
                    $qte = (int) $ligne['quantite_commande'];
                    $produitId = $ligne['produit_id'];
                    $boutiqueId = $ligne['boutique_id'];

                    $stmtLock = $pdo->prepare("SELECT quantite FROM stock_boutique WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                    $stmtLock->execute([$produitId, $boutiqueId]);
                    $ligneStock = $stmtLock->fetch(PDO::FETCH_ASSOC);
                    $stockAvant = $ligneStock ? (int) $ligneStock['quantite'] : 0;
                    $stockApres = $stockAvant + $qte;

                    if ($ligneStock === false) {
                        $pdo->prepare("INSERT INTO stock_boutique (produit_id, boutique_id, quantite) VALUES (?, ?, 0)")
                            ->execute([$produitId, $boutiqueId]);
                    }

                    $pdo->prepare("UPDATE stock_boutique SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                        ->execute([$stockApres, $produitId, $boutiqueId]);

                    $pdo->prepare("UPDATE produit SET stock_produit = COALESCE(stock_produit,0) + ? WHERE code_produit = ?")
                        ->execute([$qte, $produitId]);

                    if (!empty($ligne['lot_produit_id'])) {
                        $pdo->prepare("
                            INSERT INTO stock_boutique (produit_id, boutique_id, lot_produit_id, quantite_lot)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                lot_produit_id = VALUES(lot_produit_id),
                                quantite_lot = quantite_lot + VALUES(quantite_lot)
                        ")->execute([$produitId, $boutiqueId, $ligne['lot_produit_id'], $ligne['quantite_commande'] / max(1, (int)$ligne['facteur_conversion'])]);
                    }

                    $pdo->prepare("UPDATE commande SET etat_commande = 'Reçu', stock_avant = ?, stock_apres = ?,
                            date_reception_reelle = CURDATE(), date_validation = NOW(), utilisateur_validation_id = ?
                        WHERE numero_commande = ?")
                        ->execute([$stockAvant, $stockApres, $user['id'], $ligne['numero_commande']]);
                }

                // Mettre à jour la facture
                if ($factureId) {
                    $pdo->prepare("UPDATE facture SET etat_facture = 'Validée' WHERE numero_facture = ?")
                        ->execute([$factureId]);
                }

                $pdo->commit();
                $receptionMessage = "Bon $reference réceptionné : stock mis à jour. Facture $factureId validée.";
                $receptionMessageType = 'success';
            } catch (Exception $ex) {
                $pdo->rollBack();
                $receptionMessage = "Erreur lors de la réception : " . $ex->getMessage();
                $receptionMessageType = 'error';
            }
        } elseif ($action === 'annuler') {
            // Récupérer la facture associée
            $stmtFact = $pdo->prepare("SELECT facture_id FROM commande WHERE reference_liee = ? AND statut_id = '011' LIMIT 1");
            $stmtFact->execute([$reference]);
            $factureId = $stmtFact->fetchColumn();

            $pdo->prepare("UPDATE commande SET etat_commande = 'Annulé', date_validation = NOW(), utilisateur_validation_id = ?
                    WHERE reference_liee = ? AND statut_id = '011' AND etat_commande = 'En attente'")
                ->execute([$user['id'], $reference]);

            if ($factureId) {
                $pdo->prepare("UPDATE facture SET etat_facture = 'Annulée' WHERE numero_facture = ?")
                    ->execute([$factureId]);
            }

            $receptionMessage = "Bon $reference annulé. Facture $factureId annulée.";
            $receptionMessageType = 'success';
        }
    }
}

// ---- RÉCUPÉRATION DES BONS EN ATTENTE (affichage direct) ----
$bonsEnAttente = $pdo->query("
    SELECT c.reference_liee, c.contact_id, c.boutique_id, c.date_commande, c.date_livraison_recue, c.facture_id,
           ct.nom_prenom_contact, b.nom_boutique,
           COUNT(*) as nb_lignes, SUM(c.montant_commande) as montant_total
    FROM commande c
    LEFT JOIN contact ct ON c.contact_id = ct.code_contact
    LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
    WHERE c.statut_id = '011' AND c.etat_commande = 'En attente'
    GROUP BY c.reference_liee, c.contact_id, c.boutique_id, c.date_commande, c.date_livraison_recue, c.facture_id, ct.nom_prenom_contact, b.nom_boutique
    ORDER BY c.date_commande DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achat fournisseur</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
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
        .act-btn.v:hover { color: var(--b); background: var(--bl); border-color: rgba(37,99,235,.15); }
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
        .bootstrap-select .dropdown-menu { border-radius: var(--Rs); border-color: var(--brd); }
        .bootstrap-select .dropdown-menu .bs-searchbox input {
            border-radius: 6px; border: 1px solid var(--brd); padding: 8px 12px;
        }
        .bootstrap-select .dropdown-menu .bs-searchbox input:focus {
            border-color: var(--b); box-shadow: 0 0 0 3px var(--bl);
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
        }
        .btn-success { background: var(--suc); color: white; }
        .btn-success:hover { background: #059669; }
        .btn-outline-danger {
            background: transparent;
            color: var(--dng);
            border: 1px solid var(--dng);
        }
        .btn-outline-danger:hover { background: var(--dngl); }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1>Achat fournisseur</h1>
            <p>Création de bons de commande et réception des marchandises</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-file-earmark-plus"></i> Nouveau bon</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouveau bon</button>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
            <?= e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($receptionMessage): ?>
        <div class="alert alert-<?= $receptionMessageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
            <?= e($receptionMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ===== LISTE DES BONS EN ATTENTE ===== -->
    <div class="data-table-wrap mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;"><i class="bi bi-clock-history me-2"></i> Bons en attente de réception</h5>
            <span class="text-muted small"><?= count($bonsEnAttente) ?> bon(s) en attente</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Fournisseur</th>
                        <th>Boutique</th>
                        <th>Date commande</th>
                        <th>Livraison prévue</th>
                        <th>Facture</th>
                        <th>Lignes</th>
                        <th>Montant</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bonsEnAttente)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                <i class="bi bi-check-circle fs-1 d-block mb-2 opacity-50"></i>
                                Aucun bon en attente de réception.
                            </td>
                        </tr>
                    <?php else: foreach ($bonsEnAttente as $bon): ?>
                        <tr>
                            <td class="td-bold"><?= e($bon['reference_liee']) ?></td>
                            <td><?= e($bon['nom_prenom_contact']) ?></td>
                            <td><?= e($bon['nom_boutique']) ?></td>
                            <td><?= e($bon['date_commande']) ?></td>
                            <td><?= e($bon['date_livraison_recue']) ?></td>
                            <td><?= e($bon['facture_id']) ?></td>
                            <td><?= (int)$bon['nb_lignes'] ?></td>
                            <td><strong><?= fmt($bon['montant_total']) ?> F</strong></td>
                            <td class="text-end" style="white-space:nowrap;">
                                <form method="post" class="d-inline" onsubmit="return confirm('Confirmer la réception de ce bon ? Le stock sera mis à jour.');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="reference_liee" value="<?= e($bon['reference_liee']) ?>">
                                    <input type="hidden" name="action_reception" value="recevoir">
                                    <button class="btn-sm btn-success"><i class="bi-check2"></i> Reçu</button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Annuler ce bon ? Aucun stock n\'a été impacté.');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="reference_liee" value="<?= e($bon['reference_liee']) ?>">
                                    <input type="hidden" name="action_reception" value="annuler">
                                    <button class="btn-sm btn-outline-danger"><i class="bi-x-lg"></i> Annuler</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ===== MODAL NOUVEAU BON ===== -->
    <div class="modal fade" id="bonModal" tabindex="-1" aria-labelledby="bonModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="bonModalLabel"><i class="bi bi-file-earmark-text text-primary me-2"></i> Nouveau bon de commande fournisseur</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="bonForm">
                    <input type="hidden" name="action" value="valider_bon">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Fournisseur <span class="text-danger">*</span></label>
                                <select name="fournisseur_id" class="form-select" required>
                                    <option value="">-- Sélectionner --</option>
                                    <?php foreach ($fournisseurs as $f): ?>
                                        <option value="<?= e($f['code_contact']) ?>"><?= e($f['nom_prenom_contact']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Boutique destinataire <span class="text-danger">*</span></label>
                                <select name="boutique_id" id="boutiqueSelect" class="form-select" required>
                                    <option value="">-- Sélectionner --</option>
                                    <?php foreach ($boutiques as $b): ?>
                                        <option value="<?= e($b['code_boutique']) ?>" data-adresse="<?= e($b['adresse_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Date du bon</label>
                                <input type="date" name="date_bon" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">N° (auto)</label>
                                <input type="text" class="form-control" value="BC-<?= date('Y') ?>-XXXX" disabled>
                            </div>
                        </div>
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Lieu de livraison (adresse boutique)</label>
                                <input type="text" name="lieu_livraison" id="lieuLivraison" class="form-control" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Date de livraison souhaitée</label>
                                <input type="date" name="date_livraison" class="form-control" min="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="row g-3 mt-2">
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">Facture associée (optionnel)</label>
                                <select name="facture_id" class="form-select">
                                    <option value="">-- Aucune (création automatique) --</option>
                                    <?php foreach ($facturesFournisseur as $f): ?>
                                        <option value="<?= e($f['numero_facture']) ?>">
                                            <?= e($f['numero_facture']) ?> - <?= e($f['titre_facture']) ?> (<?= e($f['date_facture']) ?>) - <?= fmt($f['montant_ttc']) ?> F
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text small">Si vous sélectionnez une facture, elle sera liée à ce bon.</div>
                            </div>
                        </div>

                        <hr>

                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-box me-1"></i> Lignes de commande</h6>
                        <div id="lignesContainer">
                            <div class="ligne-produit" data-index="0">
                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Produit <span class="text-danger">*</span></label>
                                        <select name="produit_id[]" class="form-select select-produit" required>
                                            <option value="">-- Choisir --</option>
                                            <?php foreach ($produits as $p): ?>
                                                <option value="<?= e($p['code_produit']) ?>" data-prix="<?= $p['prix_fournisseur'] ?>"><?= e($p['titre_produit']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Lot/unité</label>
                                        <select name="lot_id[]" class="form-select select-lot">
                                            <option value="">Unité</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Unité affichée</label>
                                        <input type="text" name="unite_affichage[]" class="form-control unite-affichage" readonly placeholder="Auto">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label fw-semibold">Qté (lots)</label>
                                        <input type="number" name="quantite[]" class="form-control quantite" min="1" value="1" required oninput="calculerLigne(this)">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Prix unitaire (base) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" name="prix_unitaire[]" class="form-control prix-unitaire" min="0" required oninput="calculerLigne(this)">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Total ligne</label>
                                        <input type="text" name="total_ligne[]" class="form-control total-ligne" readonly value="0">
                                    </div>
                                    <div class="col-md-auto d-flex align-items-end">
                                        <button type="button" class="btn btn-danger btn-sm supprimer-ligne" onclick="supprimerLigne(this)" style="display:none;"><i class="bi bi-trash3"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary btn-ajouter" onclick="ajouterLigne()"><i class="bi bi-plus"></i> Ajouter une ligne</button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x"></i> Annuler</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Valider le bon</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Formulaires cachés pour actions -->
    <form method="post" id="actionForm" style="display:none;">
        <input type="hidden" name="action" id="actionField">
        <input type="hidden" name="edit_numero" id="editNumeroField">
        <input type="hidden" name="view_numero" id="viewNumeroField">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    // ---- Données injectées par PHP ----
    const lotsParProduit = <?= json_encode($lotsParProduit) ?>;
    const prixFournisseur = <?= json_encode($prixFournisseur) ?>;

    // ---- Mise à jour des lots, prix unitaire, unité affichée ----
    function mettreAJourLotsEtPrix(selectProduit) {
        const ligne = selectProduit.closest('.ligne-produit');
        const lotSelect = ligne.querySelector('.select-lot');
        const uniteAff = ligne.querySelector('.unite-affichage');
        const prixInput = ligne.querySelector('.prix-unitaire');
        const produitId = selectProduit.value;

        const lots = lotsParProduit[produitId] || [];
        let options = '<option value="">Unité</option>';
        lots.forEach(lot => {
            options += `<option value="${lot.code_lot_produit}" data-unite="${lot.titre_lot}" data-facteur="${lot.unites_par_lot}">${lot.titre_lot}</option>`;
        });
        lotSelect.innerHTML = options;

        if (produitId && prixFournisseur[produitId] !== undefined) {
            prixInput.value = prixFournisseur[produitId];
        } else {
            prixInput.value = '';
        }

        const selectedLot = lotSelect.options[lotSelect.selectedIndex];
        uniteAff.value = selectedLot ? (selectedLot.text || 'Unité') : 'Unité';
        calculerLigne(prixInput);
    }

    function calculerLigne(element) {
        const ligne = element.closest('.ligne-produit');
        const quantite = parseFloat(ligne.querySelector('.quantite').value) || 0;
        const prix = parseFloat(ligne.querySelector('.prix-unitaire').value) || 0;
        const lotSelect = ligne.querySelector('.select-lot');
        const selectedOption = lotSelect.options[lotSelect.selectedIndex];
        let facteur = 1;
        if (selectedOption && selectedOption.dataset.facteur) {
            facteur = parseFloat(selectedOption.dataset.facteur) || 1;
        }
        const quantiteBase = quantite * facteur;
        const total = quantiteBase * prix;
        ligne.querySelector('.total-ligne').value = total.toFixed(0);
    }

    $(document).on('change', '.select-produit', function() {
        mettreAJourLotsEtPrix(this);
    });

    $(document).on('change', '.select-lot', function() {
        const ligne = this.closest('.ligne-produit');
        const uniteAff = ligne.querySelector('.unite-affichage');
        const selected = this.options[this.selectedIndex];
        uniteAff.value = selected ? (selected.text || 'Unité') : 'Unité';
        const prixInput = ligne.querySelector('.prix-unitaire');
        calculerLigne(prixInput);
    });

    $(document).on('input', '.quantite, .prix-unitaire', function() {
        calculerLigne(this);
    });

    // ---- Ajouter une ligne ----
    function ajouterLigne() {
        const container = document.getElementById('lignesContainer');
        const original = container.querySelector('.ligne-produit');
        const clone = original.cloneNode(true);
        clone.querySelector('.select-produit').value = '';
        clone.querySelector('.select-lot').innerHTML = '<option value="">Unité</option>';
        clone.querySelector('.unite-affichage').value = '';
        clone.querySelector('.quantite').value = 1;
        clone.querySelector('.prix-unitaire').value = '';
        clone.querySelector('.total-ligne').value = '0';
        clone.querySelector('.supprimer-ligne').style.display = 'inline-block';
        container.appendChild(clone);
    }
    window.ajouterLigne = ajouterLigne;

    function supprimerLigne(btn) {
        const ligne = btn.closest('.ligne-produit');
        if (document.querySelectorAll('.ligne-produit').length > 1) {
            ligne.remove();
        } else {
            alert('Il faut au moins une ligne.');
        }
    }
    window.supprimerLigne = supprimerLigne;

    // ---- Initialisation première ligne ----
    $('.select-produit').each(function() {
        if ($(this).val()) {
            mettreAJourLotsEtPrix(this);
        }
    });

    // ---- Modal Nouveau bon ----
    const bonModal = new bootstrap.Modal(document.getElementById('bonModal'));
    $('#addBtn').on('click', function() {
        $('#bonForm')[0].reset();
        $('#bonForm input[name="date_bon"]').val(new Date().toISOString().split('T')[0]);
        $('#lignesContainer .ligne-produit:not(:first)').remove();
        const first = $('#lignesContainer .ligne-produit:first');
        first.find('.select-produit').val('');
        first.find('.select-lot').html('<option value="">Unité</option>');
        first.find('.unite-affichage').val('');
        first.find('.quantite').val(1);
        first.find('.prix-unitaire').val('');
        first.find('.total-ligne').val('0');
        first.find('.supprimer-ligne').hide();
        bonModal.show();
    });

    $('#boutiqueSelect').on('change', function() {
        const adresse = $(this).find(':selected').data('adresse') || '';
        $('#lieuLivraison').val(adresse);
    });

    // Auto-fermeture des alertes
    setTimeout(function() { $('.alert').alert('close'); }, 5000);
});
</script>
</body>
</html>