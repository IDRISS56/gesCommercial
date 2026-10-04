<?php
// ajustement_stock.php – Ajustement de stock (pertes, corrections, etc.)
// Design aligné sur vente.php - Adapté à la structure BDD actuelle
// SelectPicker activé sur tous les selects
// ✅ etat_produit = 'Actif'/'Inactif' : l'ajustement ne modifie PLUS etat_produit,
//    il met seulement à jour stock_produit (la disponibilité est calculée ailleurs).
ob_start();
require 'databases/database.php';
require_once 'includes/stock_lignes.php';

// ==========================================
// SÉCURITÉ : utilisateur connecté & actif
// ==========================================
if (!isset($_SESSION['user_id'])) {
    header('Location: utilisateur/login');
    exit;
}
$stmtUser = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmtUser->execute([$_SESSION['user_id']]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: utilisateur/login');
    exit;
}

// ==========================================
// BOUTIQUES AUTORISÉES POUR CET UTILISATEUR
// Même logique centralisée que vente_comptoir.php (getBoutiquesAutorisees) :
// le filtrage ne se base que sur la boutique à laquelle l'utilisateur est
// réellement rattaché (boutique_id), avec les mêmes règles/exceptions que
// partout ailleurs dans l'application. On évite ainsi une deuxième version
// de la règle qui pourrait diverger de celle de la vente comptoir.
// ==========================================
$boutiquesAutorisees = getBoutiquesAutorisees($pdo, $user['role'] ?? '', $user['boutique_id'] ?? null);

// - Statuts d'ajustement (exclure les statuts réservés) -
$statutsReserves = ['008', '009', '010', '011', '012', '016', '017'];
$statutsAjustement = $pdo->query("SELECT code_statut, titre_statut, type_statut
FROM statut
WHERE etat_statut = 'Actif'
AND LOWER(type_statut) IN ('entree', 'sortie')
AND code_statut NOT IN ('" . implode("','", $statutsReserves) . "')
ORDER BY type_statut, titre_statut")->fetchAll(PDO::FETCH_ASSOC);

// ✅ Liste des produits avec leur état Actif/Inactif (actifs en premier)
$produitsList = $pdo->query("SELECT code_produit, titre_produit, etat_produit, categorie_id
FROM produit
ORDER BY CASE WHEN etat_produit = 'Inactif' THEN 1 ELSE 0 END, titre_produit")->fetchAll(PDO::FETCH_ASSOC);

$boutiquesList = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$boutiquesList = array_values(array_filter($boutiquesList, function ($b) use ($boutiquesAutorisees) {
    return in_array($b['code_boutique'], $boutiquesAutorisees, true);
}));

// - Catégories actives (le produit se choisit dans la catégorie) et catégories autorisées par boutique
//   (restriction optionnelle, voir config/authentification.php::getCategoriesAutoriseesBoutique ; null = aucune) -
$categories = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie WHERE etat_categorie='ACTIF' ORDER BY titre_categorie")->fetchAll(PDO::FETCH_ASSOC);
$categoriesAutoriseesParBoutique = [];
foreach ($boutiquesList as $b) {
    $categoriesAutoriseesParBoutique[$b['code_boutique']] = getCategoriesAutoriseesBoutique($pdo, $user['role'] ?? null, $b['code_boutique']);
}

// - AJAX : stock actuel et prix d'un produit dans une boutique (saisie multi-lignes) -
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $produitId = $_POST['produit_id'] ?? '';
    $boutiqueId = $_POST['boutique_id'] ?? '';
    $response = ['success' => false, 'quantite' => 0, 'disponible' => 0, 'prix' => 0, 'prix_vente' => 0];
    if (!empty($produitId) && !empty($boutiqueId) && in_array($boutiqueId, $boutiquesAutorisees, true)) {
        $stmt = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ?");
        $stmt->execute([$produitId, $boutiqueId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $response['success'] = true;
            $response['quantite'] = (int) $row['quantite'];
            $response['disponible'] = (int) $row['quantite'];
        }
        $stmtPrix = $pdo->prepare("SELECT prix_fournisseur, prix_produit FROM produit WHERE code_produit = ?");
        $stmtPrix->execute([$produitId]);
        $rowPrix = $stmtPrix->fetch(PDO::FETCH_ASSOC);
        $response['prix'] = (float) ($rowPrix['prix_fournisseur'] ?? 0);
        $response['prix_vente'] = (float) ($rowPrix['prix_produit'] ?? 0);
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// - Traitement POST (ajustement uniquement) -
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else if ($action === 'ajustement') {
        // UNE validation pour TOUTES les lignes saisies (voir includes/stock_lignes.php) :
        // tout ou rien, si une ligne est refusée aucun ajustement n'est enregistré.
        $boutiqueId = trim($_POST['boutique_id'] ?? '');
        $categorieId = trim($_POST['categorie_id'] ?? '');
        $commentaire = trim($_POST['commentaire'] ?? '');
        $lignes = stockLireLignes($_POST['lignes'] ?? []);

        // code_statut => 'entree' | 'sortie' (motifs d'ajustement autorisés)
        $typesStatut = [];
        foreach ($statutsAjustement as $s) {
            $typesStatut[$s['code_statut']] = strtolower($s['type_statut']);
        }

        if ($boutiqueId === '' || $categorieId === '') {
            $message = "Veuillez sélectionner une boutique et une catégorie.";
            $messageType = 'error';
        } elseif (empty($lignes)) {
            $message = "Ajoutez au moins un produit à la liste avant de valider.";
            $messageType = 'error';
        } elseif (count($lignes) > STOCK_LIGNES_MAX) {
            $message = "Trop de lignes dans un même ajustement (maximum " . STOCK_LIGNES_MAX . ").";
            $messageType = 'error';
        } elseif (!in_array($boutiqueId, $boutiquesAutorisees, true)) {
            $message = "Vous n'êtes pas autorisé à effectuer un ajustement pour cette boutique.";
            $messageType = 'error';
        } elseif (($catsAutoriseesAjust = getCategoriesAutoriseesBoutique($pdo, $user['role'] ?? null, $boutiqueId)) !== null && !in_array($categorieId, $catsAutoriseesAjust, true)) {
            $message = "Cette boutique n'est pas autorisée à gérer la catégorie sélectionnée.";
            $messageType = 'error';
        } elseif ($commentaire === '') {
            $message = "Un commentaire est obligatoire pour justifier ce mouvement (traçabilité).";
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                // Produits des lignes : existent, sans doublon, de la catégorie choisie
                $infosProduits = stockChargerProduits($pdo, $lignes, $categorieId);

                $stmtStock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                $stmtInsCmd = $pdo->prepare("INSERT INTO commande
                    (numero_commande, produit_id, lot_id, produits_par_lot, statut_id,
                     date_commande, heure_commande, prix_achat, prix_commande,
                     quantite_commande, montant_commande, utilisateur_id, boutique_id, etat_commande)
                    VALUES (?, ?, NULL, 1, ?, CURDATE(), CURTIME(), ?, 0, ?, ?, ?, ?, 'VALIDEE')");

                $numeroBase = 'AJU-' . date('YmdHis') . rand(100, 999);
                $detailsLignes = [];
                $numeros = [];

                foreach ($lignes as $i => $ligne) {
                    $produitId = $ligne['produit_id'];
                    $titreProduit = $infosProduits[$produitId]['titre_produit'];
                    $statutId = trim((string) ($ligne['statut_id'] ?? ''));
                    $quantite = intval($ligne['quantite'] ?? 0);

                    if (!isset($typesStatut[$statutId])) {
                        throw new Exception("Motif non autorisé pour « $titreProduit ».");
                    }
                    if ($quantite <= 0) {
                        throw new Exception("Quantité invalide pour « $titreProduit » (doit être supérieure à 0).");
                    }
                    $isEntree = ($typesStatut[$statutId] === 'entree');
                    $prixUnitaire = (float) $infosProduits[$produitId]['prix_fournisseur'];

                    $stmtStock->execute([$produitId, $boutiqueId]);
                    $stockActuel = $stmtStock->fetchColumn();
                    $stockAvant = $stockActuel !== false ? (int) $stockActuel : 0;
                    $stockApres = $isEntree ? ($stockAvant + $quantite) : ($stockAvant - $quantite);
                    if (!$isEntree && $stockApres < 0) {
                        throw new Exception("Stock insuffisant pour « $titreProduit ». Stock actuel : $stockAvant, quantité demandée : $quantite");
                    }

                    // Un numéro par ligne (même base pour toute la saisie + rang de la ligne)
                    $numCommande = $numeroBase . sprintf('%02d', $i + 1);
                    $stmtInsCmd->execute([
                        $numCommande, $produitId, $statutId,
                        $prixUnitaire, $quantite, $prixUnitaire * $quantite,
                        $user['id'], $boutiqueId
                    ]);

                    if ($stockActuel === false) {
                        $pdo->prepare("INSERT INTO stock (produit_id, boutique_id, quantite) VALUES (?, ?, ?)")
                            ->execute([$produitId, $boutiqueId, $stockApres]);
                    } else {
                        $pdo->prepare("UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                            ->execute([$stockApres, $produitId, $boutiqueId]);
                    }

                    // ✅ Répercuter le mouvement sur le stock GLOBAL du produit.
                    // NB : etat_produit est désormais 'Actif'/'Inactif' (décision manuelle
                    // gérée dans la fiche produit). On ne le modifie PLUS ici.
                    if ($isEntree) {
                        $pdo->prepare("UPDATE produit SET stock_produit = stock_produit + ? WHERE code_produit = ?")
                            ->execute([$quantite, $produitId]);
                    } else {
                        $pdo->prepare("UPDATE produit SET stock_produit = GREATEST(0, stock_produit - ?) WHERE code_produit = ?")
                            ->execute([$quantite, $produitId]);
                    }

                    $numeros[] = $numCommande;
                    $detailsLignes[] = "$titreProduit : $stockAvant → $stockApres";
                }

                $pdo->commit();
                $message = "Ajustement enregistré : " . count($lignes) . " produit(s). " . implode(' ; ', $detailsLignes)
                    . ". Références : " . $numeros[0] . (count($numeros) > 1 ? ' à ' . end($numeros) : '') . '.';
                $messageType = 'success';
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Erreur : " . $ex->getMessage() . " — aucune ligne n'a été enregistrée.";
                $messageType = 'error';
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// - Historique des ajustements (30 derniers), restreint aux boutiques autorisées -
if (!empty($boutiquesAutorisees)) {
    $placeholders = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $stmtHistAj = $pdo->prepare("SELECT c.numero_commande, c.produit_id, c.boutique_id, c.statut_id,
        s.titre_statut, s.type_statut, c.quantite_commande, c.date_commande, c.heure_commande,
        p.titre_produit, b.nom_boutique
        FROM commande c
        LEFT JOIN statut s ON c.statut_id = s.code_statut
        LEFT JOIN produit p ON c.produit_id = p.code_produit
        LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
        WHERE c.statut_id NOT IN ('008','009','010','011','012','016','017')
        AND c.etat_commande = 'VALIDEE'
        AND c.boutique_id IN ($placeholders)
        ORDER BY c.date_commande DESC, c.heure_commande DESC
        LIMIT 30");
    $stmtHistAj->execute($boutiquesAutorisees);
    $historiqueAjustements = $stmtHistAj->fetchAll(PDO::FETCH_ASSOC);
} else {
    $historiqueAjustements = [];
}

// - Statistiques (mêmes indicateurs que sortie_stock.php, mais sur le périmètre "ajustement"), restreintes aux boutiques autorisées -
$reservesSql = "'008','009','010','011','012','016','017'";
if (!empty($boutiquesAutorisees)) {
    $placeholders = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $stmtT = $pdo->prepare("SELECT COUNT(*) FROM commande c WHERE c.statut_id NOT IN ($reservesSql) AND c.etat_commande = 'VALIDEE' AND c.boutique_id IN ($placeholders)");
    $stmtT->execute($boutiquesAutorisees);
    $totalAjustements = $stmtT->fetchColumn();

    $stmtJ = $pdo->prepare("SELECT COUNT(*) FROM commande c WHERE c.statut_id NOT IN ($reservesSql) AND c.etat_commande = 'VALIDEE' AND c.date_commande = CURDATE() AND c.boutique_id IN ($placeholders)");
    $stmtJ->execute($boutiquesAutorisees);
    $ajustAujourdhui = $stmtJ->fetchColumn();

    $stmtS = $pdo->prepare("SELECT COUNT(*) FROM commande c WHERE c.statut_id NOT IN ($reservesSql) AND c.etat_commande = 'VALIDEE' AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND c.boutique_id IN ($placeholders)");
    $stmtS->execute($boutiquesAutorisees);
    $ajustSemaine = $stmtS->fetchColumn();

    $stmtV = $pdo->prepare("SELECT COALESCE(SUM(c.montant_commande),0) FROM commande c WHERE c.statut_id NOT IN ($reservesSql) AND c.etat_commande = 'VALIDEE' AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND c.boutique_id IN ($placeholders)");
    $stmtV->execute($boutiquesAutorisees);
    $valeurAjust30j = $stmtV->fetchColumn();
} else {
    $totalAjustements = 0;
    $ajustAujourdhui = 0;
    $ajustSemaine = 0;
    $valeurAjust30j = 0;
}

$stats = [
    ['suc', 'arrow-left-right',   'Total mouvements',   $totalAjustements, false],
    ['tl',  'calendar-check',     "Aujourd'hui",        $ajustAujourdhui, false],
    ['wrn', 'calendar-week',      '7 derniers jours',   $ajustSemaine, false],
    ['prp', 'cash-stack',         'Valeur 30 j (FCFA)', number_format($valeurAjust30j, 0, ',', ' '), true],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ajustement de stock</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
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
.hdr-r { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
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
.table > :not(caption) > * > * { padding: 12px 18px; }
.table thead th {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--lt);
    background: var(--bg);
    border-bottom: 1px solid var(--brd);
}
.table tbody tr { border-bottom: 1px solid var(--brd); transition: background .2s; }
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
    border: 1px dashed var(--bb);
    border-radius: var(--Rs);
    padding: 14px 18px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.ref-name { font-size: 13px; font-weight: 600; color: var(--b); display: flex; align-items: center; gap: 6px; }
.ref-stock { font-size: 12px; color: var(--mt); font-weight: 500; }
.form-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--lt);
    margin-bottom: 6px;
}
.form-control, .form-select {
    border: 1.5px solid var(--brd);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    transition: all .2s;
}
.form-control:focus, .form-select:focus {
    border-color: var(--b);
    box-shadow: 0 0 0 3px var(--bl);
    outline: none;
}
.btn-success {
    background: var(--suc);
    color: white;
    padding: 10px 20px;
    border-radius: var(--Rs);
    font-weight: 600;
    font-size: 14px;
    border: none;
    transition: background 0.2s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
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
/* ===== SELECTPICKER CUSTOM ===== */
.bootstrap-select { width: 100% !important; }
.bootstrap-select .dropdown-toggle {
    background: #fff !important;
    border: 1.5px solid var(--brd) !important;
    border-radius: 8px !important;
    padding: 8px 12px !important;
    font-size: 13px !important;
    color: var(--dk) !important;
    font-family: 'Inter', sans-serif !important;
    height: 42px;
    transition: all .2s !important;
}
.bootstrap-select .dropdown-toggle:hover { border-color: var(--lt) !important; }
.bootstrap-select .dropdown-toggle:focus,
.bootstrap-select.open .dropdown-toggle {
    border-color: var(--b) !important;
    box-shadow: 0 0 0 3px var(--bl) !important;
    outline: none !important;
}
.bootstrap-select .dropdown-toggle .filter-option { color: var(--dk) !important; font-weight: 500; }
.bootstrap-select .dropdown-toggle .filter-option.placeholder { color: var(--lt) !important; }
.bootstrap-select .dropdown-toggle .caret { color: var(--lt); }
.bootstrap-select .dropdown-menu {
    border-radius: var(--Rs) !important;
    border: 1px solid var(--brd) !important;
    box-shadow: 0 8px 24px rgba(0,0,0,0.08) !important;
    padding: 6px !important;
    margin-top: 4px !important;
    max-height: 280px !important;
}
.bootstrap-select .dropdown-menu .bs-searchbox {
    padding: 4px;
    margin-bottom: 4px;
    border-bottom: 1px solid var(--brd);
}
.bootstrap-select .dropdown-menu .bs-searchbox input {
    border-radius: 6px !important;
    border: 1.5px solid var(--brd) !important;
    padding: 8px 12px !important;
    font-size: 13px !important;
    font-family: 'Inter', sans-serif !important;
}
.bootstrap-select .dropdown-menu .bs-searchbox input:focus {
    border-color: var(--b) !important;
    box-shadow: 0 0 0 3px var(--bl) !important;
    outline: none !important;
}
.bootstrap-select .dropdown-menu li a {
    border-radius: 6px !important;
    padding: 8px 12px !important;
    font-size: 13px !important;
    color: var(--dk) !important;
    transition: all .15s;
}
.bootstrap-select .dropdown-menu li a:hover,
.bootstrap-select .dropdown-menu li a:focus {
    background: var(--bl) !important;
    color: var(--b) !important;
}
.bootstrap-select .dropdown-menu li.selected a { background: var(--b) !important; color: #fff !important; }
.bootstrap-select .dropdown-menu li.selected a:hover { background: var(--bd) !important; }
.bootstrap-select .dropdown-menu li.no-results {
    color: var(--lt);
    font-style: italic;
    padding: 8px 12px;
    text-align: center;
}
.stat-card {
    background: var(--w);
    border: 1px solid var(--brd);
    border-radius: var(--Rs);
    padding: 16px;
    transition: transform .15s, box-shadow .15s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.06); }
.stat-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.stat-label { font-size: 12px; color: var(--mt); font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
.stat-value { font-size: 20px; font-weight: 800; font-family: 'Outfit', sans-serif; color: var(--dk); }
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
            <div class="hdr-badge">
                <i class="bi bi-clipboard2-pulse"></i> <?= count($historiqueAjustements) ?> derniers mouvements
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <?php
        $colorMap = [
            'suc' => ['var(--sucl)', 'var(--suc)'],
            'tl'  => ['var(--tll)',  'var(--tl)'],
            'wrn' => ['var(--wrnl)', 'var(--wrn)'],
            'prp' => ['var(--prpl)', 'var(--prp)'],
        ];
        foreach ($stats as $s):
            $bg = $colorMap[$s[0]][0]; $fg = $colorMap[$s[0]][1];
        ?>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background: <?= $bg ?>; color: <?= $fg ?>;">
                    <i class="bi bi-<?= $s[1] ?>"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-label"><?= $s[2] ?></div>
                    <div class="stat-value text-truncate"><?= $s[3] ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Formulaire d'ajustement -->
    <div class="data-table-wrap mb-4">
        <div class="p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">
                <i class="bi bi-pencil-square me-2"></i> Nouvel ajustement
            </h5>
        </div>
        <div class="p-4">
            <div class="product-ref">
                <span class="ref-name">
                    <i class="bi bi-info-circle"></i> Motifs configurables dans <strong>Configurations &gt; Statuts</strong>
                </span>
                <span class="ref-stock">Les mouvements sont tracés avec commentaire obligatoire.</span>
            </div>
            <form method="post" id="stockForm" autocomplete="off">
                <input type="hidden" name="action" value="ajustement">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="boutique_id" class="form-label fw-semibold">
                            <i class="bi bi-shop me-1"></i> Boutique <span class="text-danger">*</span>
                        </label>
                        <select name="boutique_id" id="boutique_id" class="selectpicker form-select"
                            data-live-search="true"
                            data-live-search-placeholder="Rechercher une boutique..."
                            data-none-selected-text="-- Choisir une boutique --"
                            data-none-results-text="Aucune boutique trouvée pour {0}"
                            required>
                            <option value="">-- Choisir --</option>
                            <?php foreach ($boutiquesList as $b): ?>
                            <option value="<?= htmlspecialchars($b['code_boutique']) ?>"><?= htmlspecialchars($b['nom_boutique']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="categorie_id" class="form-label fw-semibold">
                            <i class="bi bi-diagram-3 me-1"></i> Catégorie <span class="text-danger">*</span>
                        </label>
                        <select name="categorie_id" id="categorie_id" class="selectpicker form-select"
                            data-live-search="true"
                            data-live-search-placeholder="Rechercher une catégorie..."
                            data-none-selected-text="-- Choisir une catégorie --"
                            data-none-results-text="Aucune catégorie trouvée pour {0}"
                            required>
                            <option value="">-- Choisir --</option>
                            <?php foreach ($categories as $c): ?>
                            <option value="<?= htmlspecialchars($c['code_categorie']) ?>"><?= htmlspecialchars($c['titre_categorie']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="commentaire" class="form-label fw-semibold">
                            <i class="bi bi-chat-left-text me-1"></i> Commentaire <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="commentaire" id="commentaire" class="form-control" placeholder="Motif précis du mouvement (obligatoire)" required>
                    </div>
                </div>

                <!-- Ajout d'un produit à la liste -->
                <div class="sl-panel" id="slPanel">
                    <div class="fw-semibold mb-2"><i class="bi bi-plus-circle me-1 text-primary"></i> Ajouter un produit à cet ajustement</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label for="produit_id" class="form-label fw-semibold"><i class="bi bi-box-seam me-1"></i> Produit</label>
                            <select id="produit_id" class="selectpicker form-select"
                                data-live-search="true"
                                data-live-search-placeholder="Rechercher un produit..."
                                data-none-results-text="Aucun produit trouvé pour {0}"
                                disabled title="-- Choisir d'abord une catégorie --">
                                <option value="">-- Choisir un produit --</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="sl_motif" class="form-label fw-semibold"><i class="bi bi-tags me-1"></i> Motif (statut)</label>
                            <select id="sl_motif" class="selectpicker form-select"
                                data-live-search="true"
                                data-live-search-placeholder="Rechercher un motif..."
                                data-none-selected-text="-- Choisir un motif --"
                                data-none-results-text="Aucun motif trouvé pour {0}">
                                <option value="">-- Choisir --</option>
                                <?php foreach ($statutsAjustement as $s): ?>
                                <option value="<?= htmlspecialchars($s['code_statut']) ?>">
                                    <?= htmlspecialchars($s['titre_statut']) ?> (<?= strtolower($s['type_statut']) === 'entree' ? '↑ Entrée' : '↓ Sortie' ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="sl_qte" class="form-label fw-semibold"><i class="bi bi-hash me-1"></i> Quantité</label>
                            <input type="number" id="sl_qte" class="form-control" min="1" step="1" placeholder="0">
                        </div>
                        <div class="col-md-2">
                            <button type="button" id="slAjouter" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Ajouter</button>
                        </div>
                    </div>
                    <div id="slInfo" class="stock-info">
                        <div class="item"><span class="label">Stock actuel en boutique :</span> <strong data-info="stock">—</strong></div>
                    </div>
                </div>

                <!-- Liste des produits de l'ajustement -->
                <div id="slVide" class="sl-vide">
                    <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                    Aucun produit ajouté. Choisissez la boutique et la catégorie, puis ajoutez autant de produits que nécessaire.
                </div>
                <div id="slTableWrap" class="table-responsive mt-3" style="display:none;">
                    <table class="table table-sm align-middle sl-table mb-0">
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th class="text-center">Stock actuel</th>
                                <th style="min-width:200px;">Motif</th>
                                <th style="width:120px;">Quantité</th>
                                <th class="text-center">Stock après</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="slLignes"></tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <div id="slRecap" class="text-muted small"></div>
                    <button type="submit" id="slValider" class="btn-success" disabled>
                        <i class="bi bi-save"></i> <span>Valider l'ajustement</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Historique -->
    <div class="data-table-wrap">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">
                <i class="bi bi-clock-history me-2"></i> Historique des ajustements (30 derniers)
            </h5>
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
                        <th class="text-center">Qté</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historiqueAjustements)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="bi bi-inbox d-block mb-2" style="font-size:2rem;opacity:.3;"></i>
                            Aucun ajustement enregistré.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($historiqueAjustements as $h):
                        $isEntree = strtolower($h['type_statut'] ?? '') === 'entree';
                    ?>
                    <tr>
                        <td class="td-semi">
                            <span style="background:var(--bl);color:var(--b);padding:3px 8px;border-radius:6px;font-weight:700;font-size:12px;">
                                <?= htmlspecialchars($h['numero_commande']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($h['titre_produit'] ?? $h['produit_id']) ?></td>
                        <td><?= htmlspecialchars($h['nom_boutique'] ?? $h['boutique_id']) ?></td>
                        <td>
                            <span style="background:<?= $isEntree ? 'var(--sucl)' : 'var(--dngl)' ?>;color:<?= $isEntree ? '#065f46' : '#991b1b' ?>;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;">
                                <?= $isEntree ? '↑' : '↓' ?> <?= htmlspecialchars($h['titre_statut'] ?? $h['statut_id']) ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span style="background:var(--bg);padding:4px 10px;border-radius:8px;font-weight:700;">
                                <?= (int)$h['quantite_commande'] ?>
                            </span>
                        </td>
                        <td>
                            <span style="color:var(--mt);font-size:12px;">
                                <i class="bi bi-calendar3"></i>
                                <?= date('d/m/Y', strtotime($h['date_commande'])) ?>
                                <small><?= htmlspecialchars($h['heure_commande']) ?></small>
                            </span>
                        </td>
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
<?php include 'includes/stock_lignes_js.php'; ?>
<script>
$(document).ready(function() {
    // Initialisation des selectpicker (pas de refresh() : bug beta3)
    $('.selectpicker').selectpicker({
        iconBase: 'bi',
        tickIcon: 'bi-check-lg'
    });

    // Saisie multi-lignes : boutique + catégorie, autant de produits que voulu, puis UNE validation.
    StockLignes.init({
        mode: 'ajustement',
        produits: <?= json_encode(stockProduitsPourJs($produitsList), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
        catsParBoutique: <?= json_encode($categoriesAutoriseesParBoutique, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
        motifs: <?= json_encode(array_map(function ($s) {
            return ['code' => (string) $s['code_statut'], 'titre' => (string) $s['titre_statut'], 'type' => strtolower($s['type_statut']) === 'entree' ? 'entree' : 'sortie'];
        }, $statutsAjustement), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
        qteDefaut: '',
        sel: {
            boutique: '#boutique_id', categorie: '#categorie_id', produit: '#produit_id',
            qte: '#sl_qte', motif: '#sl_motif',
            btnAjouter: '#slAjouter', tbody: '#slLignes', vide: '#slVide', tableWrap: '#slTableWrap',
            recap: '#slRecap', submit: '#slValider', form: '#stockForm', panel: '#slPanel', info: '#slInfo'
        }
    });

    // Auto-fermeture des alerts après 5s
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);
});
</script>
</body>
</html>