<?php
// sortie_stock.php – Enregistrement d'une sortie de stock (vente, transfert sortant, etc.)
// Design identique à index.php (boutiques)
// Utilise UNIQUEMENT les statuts de sortie réservés (excluant les ajustements)
// Structure BDD : stock(produit_id, boutique_id, quantite, stock_alerte)
//                 commande(numero_commande, produit_id, statut_id, date_commande, heure_commande,
//                          prix_achat, prix_commande, quantite_commande, montant_commande,
//                          utilisateur_id, boutique_id, etat_commande)
//                 statut(code_statut, titre_statut, type_statut, symbole_statut, etat_statut)
// ✅ etat_produit = 'Actif'/'Inactif' : la sortie ne modifie PLUS etat_produit,
//    elle met seulement à jour les stocks (la disponibilité est calculée ailleurs).
ob_start();
require 'databases/database.php';
require 'databases/id_generator.php';

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
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$message = '';
$messageType = '';

// (la génération du numéro se fait désormais directement via
// genererEtInsererIdSequence() au moment de l'insertion, voir plus bas)

// ===== STATUTS DE SORTIE RÉSERVÉS (exclure les ajustements) =====
// Codes réservés pour les mouvements standards :
// 008,009,010,011(achat),012(vente),016,017(transferts)
// On ne garde que ceux de type 'SORTIE'
$statutsSortieReserves = $pdo->query("
    SELECT code_statut, titre_statut
    FROM statut
    WHERE type_statut = 'SORTIE'
    AND code_statut IN ('008','009','010','012','016','017')
    AND etat_statut = 'Actif'
    ORDER BY titre_statut
")->fetchAll(PDO::FETCH_ASSOC);

// Récupération des listes pour les selects
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = array_values(array_filter($boutiques, function ($b) use ($boutiquesAutorisees) {
    return in_array($b['code_boutique'], $boutiquesAutorisees, true);
}));

// ✅ Liste des produits : l'ancien filtre "etat_produit IN ('DISPONIBLE','ALERTE')"
// ne fonctionne plus (enum devenu 'Actif'/'Inactif'). On le remplace par son
// équivalent calculé sur le stock : uniquement les produits ayant du stock
// global (> 0), actifs en premier, avec mention "(inactif)" le cas échéant.
// NB : pour exclure totalement les produits inactifs des sorties, ajoutez
//      "AND etat_produit <> 'Inactif'" dans la requête ci-dessous.
$produits = $pdo->query("SELECT code_produit, titre_produit, etat_produit
    FROM produit
    WHERE stock_produit > 0
    ORDER BY CASE WHEN etat_produit = 'Inactif' THEN 1 ELSE 0 END, titre_produit")->fetchAll(PDO::FETCH_ASSOC);

// Traitement du formulaire de sortie
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'sortie' && !isset($_POST['ajax'])) {
    $csrf_post = $_POST['csrf_token'] ?? '';
    if (empty($csrf_post) || $csrf_post !== $csrf_token) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {
        $produitId    = trim($_POST['produit_id'] ?? '');
        $boutiqueId   = trim($_POST['boutique_id'] ?? '');
        $statutId     = trim($_POST['statut_id'] ?? '');
        $quantite     = intval($_POST['quantite'] ?? 0);
        $prixVente    = floatval($_POST['prix_vente'] ?? 0);

        // Vérifier que le statut choisi est bien dans la liste autorisée
        $statutsAutorises = array_column($statutsSortieReserves, 'code_statut');
        if (!in_array($statutId, $statutsAutorises)) {
            $message = 'Motif de sortie non autorisé.';
            $messageType = 'warning';
        } elseif (empty($produitId) || empty($boutiqueId) || empty($statutId) || $quantite <= 0) {
            $message = 'Veuillez sélectionner un produit, une boutique, un motif et saisir une quantité valide (> 0).';
            $messageType = 'warning';
        } elseif (!in_array($boutiqueId, $boutiquesAutorisees, true)) {
            $message = "Vous n'êtes pas autorisé à saisir une sortie de stock pour cette boutique.";
            $messageType = 'danger';
        } else {
            // Vérification préliminaire (message rapide, hors transaction) : simplement
            // pour éviter d'ouvrir une transaction si le produit n'existe pas du tout
            // dans cette boutique. Le contrôle qui compte (avec verrou) est refait
            // juste après, à l'intérieur de la transaction.
            $stmt = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ?");
            $stmt->execute([$produitId, $boutiqueId]);
            $stockActuel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$stockActuel) {
                $message = "Ce produit n'est pas présent dans cette boutique.";
                $messageType = 'danger';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Contrôle de stock verrouillé (FOR UPDATE) : verrouille la ligne
                    // jusqu'au commit/rollback pour empêcher deux sorties simultanées
                    // de faire passer le stock sous zéro.
                    $stmtLock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                    $stmtLock->execute([$produitId, $boutiqueId]);
                    $quantiteDisponible = $stmtLock->fetchColumn();
                    $quantiteDisponible = ($quantiteDisponible === false) ? 0 : (int) $quantiteDisponible;
                    if ($quantite > $quantiteDisponible) {
                        throw new Exception("Stock disponible insuffisant. Disponible : $quantiteDisponible, demandé : $quantite.");
                    }

                    // Récupérer le prix fournisseur (coût) du produit
                    $stmtPrix = $pdo->prepare("SELECT prix_fournisseur, prix_produit FROM produit WHERE code_produit = ?");
                    $stmtPrix->execute([$produitId]);
                    $prod = $stmtPrix->fetch(PDO::FETCH_ASSOC);
                    $prixAchat = (float)($prod['prix_fournisseur'] ?? 0);
                    $prixUnitaire = $prixVente > 0 ? $prixVente : (float)($prod['prix_produit'] ?? 0);
                    $montantCommande = $prixUnitaire * $quantite;

                    // 1) Mise à jour du stock boutique
                    $stmtUpdateStock = $pdo->prepare("UPDATE stock SET quantite = quantite - ? WHERE produit_id = ? AND boutique_id = ?");
                    $stmtUpdateStock->execute([$quantite, $produitId, $boutiqueId]);

                    // 2) Mise à jour du stock global produit
                    $stmtUpdateProd = $pdo->prepare("UPDATE produit SET stock_produit = GREATEST(0, stock_produit - ?) WHERE code_produit = ?");
                    $stmtUpdateProd->execute([$quantite, $produitId]);

                    // ✅ etat_produit est désormais 'Actif'/'Inactif' (décision manuelle
                    // gérée dans la fiche produit) : on ne le modifie PLUS ici.
                    // La disponibilité (rupture / alerte / disponible) est calculée
                    // dynamiquement depuis stock_produit vs stock_alerte sur les
                    // pages de lecture (historique, ventes, etc.).

                    // 3) Enregistrement du mouvement dans la table commande
                    // Génération + insertion avec réessai automatique en cas de
                    // collision entre deux sorties de stock simultanées (voir
                    // databases/id_generator.php).
                    $numeroCommande = genererEtInsererIdSequence($pdo, 'SC', function (string $numero) use (
                        $pdo, $produitId, $statutId, $prixAchat, $prixUnitaire, $quantite, $montantCommande, $user, $boutiqueId
                    ) {
                        $stmtInsert = $pdo->prepare("
                            INSERT INTO commande
                            (numero_commande, produit_id, statut_id, date_commande, heure_commande,
                             prix_achat, prix_commande, quantite_commande, montant_commande,
                             utilisateur_id, boutique_id, etat_commande)
                            VALUES (?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, 'VALIDEE')
                        ");
                        $stmtInsert->execute([
                            $numero,
                            $produitId,
                            $statutId,
                            $prixAchat,
                            $prixUnitaire,
                            $quantite,
                            $montantCommande,
                            $user['id'],
                            $boutiqueId
                        ]);
                    }, 4);

                    $pdo->commit();

                    // Libellé du motif
                    $libelleMotif = '';
                    foreach ($statutsSortieReserves as $s) {
                        if ($s['code_statut'] === $statutId) { $libelleMotif = $s['titre_statut']; break; }
                    }
                    $message = "Sortie enregistrée (n° $numeroCommande) : $quantite unité(s) — motif « $libelleMotif ».";
                    $messageType = 'success';
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $message = "Erreur : " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}

// AJAX : récupérer le stock d'un produit dans une boutique
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $produitId  = $_POST['produit_id'] ?? '';
    $boutiqueId = $_POST['boutique_id'] ?? '';
    $response = ['success' => false, 'quantite' => 0, 'disponible' => 0];
    if (!empty($produitId) && !empty($boutiqueId) && in_array($boutiqueId, $boutiquesAutorisees, true)) {
        $stmt = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ?");
        $stmt->execute([$produitId, $boutiqueId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $response['success']    = true;
            $response['quantite']   = (int)$row['quantite'];
            $response['disponible'] = (int)$row['quantite'];
        }
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Historique des sorties (30 derniers mouvements avec statuts réservés), restreint aux boutiques autorisées
$codesReserves = array_column($statutsSortieReserves, 'code_statut');
$codesIn = "'" . implode("','", $codesReserves) . "'";
if (!empty($boutiquesAutorisees)) {
    $placeholders = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $stmtHistS = $pdo->prepare("
        SELECT c.numero_commande, c.produit_id, c.boutique_id, c.statut_id,
               s.titre_statut, s.type_statut,
               c.quantite_commande, c.prix_commande, c.montant_commande,
               c.date_commande, c.heure_commande, c.etat_commande,
               p.titre_produit, b.nom_boutique
        FROM commande c
        LEFT JOIN statut s ON c.statut_id = s.code_statut
        LEFT JOIN produit p ON c.produit_id = p.code_produit
        LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
        WHERE c.statut_id IN ($codesIn) AND c.boutique_id IN ($placeholders)
        ORDER BY c.date_commande DESC, c.heure_commande DESC
        LIMIT 30
    ");
    $stmtHistS->execute($boutiquesAutorisees);
    $historique = $stmtHistS->fetchAll(PDO::FETCH_ASSOC);

    // Statistiques (sur les statuts réservés), restreintes aux boutiques autorisées
    $stmtT = $pdo->prepare("SELECT COUNT(*) FROM commande c WHERE c.statut_id IN ($codesIn) AND c.boutique_id IN ($placeholders)");
    $stmtT->execute($boutiquesAutorisees);
    $totalSorties = $stmtT->fetchColumn();

    $stmtJ = $pdo->prepare("SELECT COUNT(*) FROM commande c WHERE c.statut_id IN ($codesIn) AND c.date_commande = CURDATE() AND c.boutique_id IN ($placeholders)");
    $stmtJ->execute($boutiquesAutorisees);
    $sortiesAujour = $stmtJ->fetchColumn();

    $stmtS = $pdo->prepare("SELECT COUNT(*) FROM commande c WHERE c.statut_id IN ($codesIn) AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND c.boutique_id IN ($placeholders)");
    $stmtS->execute($boutiquesAutorisees);
    $sortiesSemaine = $stmtS->fetchColumn();

    $stmtV = $pdo->prepare("SELECT COALESCE(SUM(c.montant_commande),0) FROM commande c WHERE c.statut_id IN ($codesIn) AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND c.boutique_id IN ($placeholders)");
    $stmtV->execute($boutiquesAutorisees);
    $valeurSorties = $stmtV->fetchColumn();
} else {
    $historique = [];
    $totalSorties = 0;
    $sortiesAujour = 0;
    $sortiesSemaine = 0;
    $valeurSorties = 0;
}

$stats = [
    ['success', 'arrow-down-circle', 'Total sorties', $totalSorties, false],
    ['info',    'calendar-check',    "Aujourd'hui",   $sortiesAujour, false],
    ['warning', 'calendar-week',     '7 derniers jours', $sortiesSemaine, false],
    ['purple',  'cash-stack',        'Valeur 30 j (FCFA)', number_format($valeurSorties, 0, ',', ' '), true],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sortie de stock</title>
<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<!-- Bootstrap SelectPicker -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ===== DESIGN IDENTIQUE À index.php (BOUTIQUES) ===== */
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
    --color-gray-500: #64748b;
    --color-gray-700: #334155;
    --color-gray-900: #0f172a;
    --bg-surface: #ffffff;
    --bg-page: #f8fafc;
    --border-color: #e2e8f0;
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-tertiary: #64748b;
    --radius-sm: 10px;
    --radius-md: 14px;
    --shadow-sm: 0 1px 3px rgba(15, 23, 42, .04), 0 1px 2px rgba(15, 23, 42, .06);
    --shadow-md: 0 4px 12px rgba(15, 23, 42, .08);
    --transition-base: all .2s ease;
}
* { box-sizing: border-box; }
body {
    font-family: 'Inter', sans-serif;
    background: var(--bg-page);
    color: var(--text-primary);
    margin: 0;
    padding: 24px;
}
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 22px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-header h1 {
    font-family: 'Outfit', sans-serif;
    font-size: 26px;
    font-weight: 800;
    margin: 0;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
}
.page-header h1 i { color: var(--color-primary); }
.page-header p {
    margin: 4px 0 0 0;
    color: var(--text-tertiary);
    font-size: 13px;
}
.header-badge {
    background: var(--color-primary-soft);
    color: var(--color-primary);
    padding: 8px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
/* Stats cards */
.stat-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 14px 16px;
    transition: var(--transition-base);
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.stat-label {
    font-size: 10px; font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.stat-value {
    font-size: 18px; font-weight: 800;
    color: var(--text-primary);
    font-family: 'Outfit', sans-serif;
    line-height: 1;
}
/* Form / table wrap */
.data-table-wrap {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    animation: fadeUp .4s ease both;
}
.data-table-wrap .section-title {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border-color);
    background: var(--color-gray-50);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}
.data-table-wrap .section-title h5 {
    margin: 0;
    font-family: 'Outfit', sans-serif;
    font-weight: 700;
    font-size: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text-primary);
}
.data-table-wrap .section-title h5 i { color: var(--color-primary); }
.form-section { padding: 22px; }
.form-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 6px;
}
.form-control, .form-select {
    border: 1px solid var(--color-gray-200);
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 14px;
    transition: var(--transition-base);
}
.form-control:focus, .form-select:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}
/* Stock info */
.stock-info {
    background: var(--color-gray-50);
    border: 1px dashed var(--color-gray-200);
    border-radius: var(--radius-sm);
    padding: 14px 18px;
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
    margin-top: 8px;
}
.stock-info .item { font-size: 13px; }
.stock-info .item .label {
    font-weight: 600;
    color: var(--text-tertiary);
    margin-right: 6px;
}
.stock-info .item strong {
    font-weight: 800;
    color: var(--text-primary);
    font-family: 'Outfit', sans-serif;
}
/* Submit button */
.btn-submit {
    background: var(--color-primary);
    color: #fff;
    border: none;
    padding: 11px 22px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: var(--transition-base);
    cursor: pointer;
}
.btn-submit:hover { background: var(--color-primary-dark); transform: translateY(-1px); }
/* Table */
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
.table tbody td {
    padding: 12px 14px;
    font-size: 13px;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.table tbody tr:hover { background: var(--color-gray-50); }
.td-bold { font-weight: 700; color: var(--text-primary); }
.td-mono { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--text-tertiary); }
.badge-sortie {
    background: var(--color-danger-soft);
    color: var(--color-danger);
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}
.badge-valide {
    background: var(--color-success-soft);
    color: var(--color-success);
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
}
.empty-row td {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-tertiary);
}
.empty-row i { font-size: 40px; opacity: .4; display: block; margin-bottom: 8px; }
/* Selectpicker override */
.bootstrap-select .dropdown-toggle {
    border: 1px solid var(--color-gray-200) !important;
    border-radius: 8px !important;
    background: #fff !important;
    padding: 8px 12px !important;
    font-size: 14px !important;
    color: var(--text-primary) !important;
}
.bootstrap-select .dropdown-toggle:focus {
    border-color: var(--color-primary) !important;
    box-shadow: 0 0 0 3px var(--color-primary-soft) !important;
}
/* Alert */
.alert {
    border: none;
    border-radius: var(--radius-sm);
    padding: 14px 18px;
    font-size: 14px;
    font-weight: 500;
}
.alert-success { background: var(--color-success-soft); color: #065f46; }
.alert-danger  { background: var(--color-danger-soft);  color: #991b1b; }
.alert-warning { background: var(--color-warning-soft); color: #92400e; }
.alert-info    { background: var(--color-info-soft);    color: #0e7490; }
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
@media (max-width: 768px) {
    body { padding: 14px; }
    .page-header h1 { font-size: 20px; }
    .stock-info { gap: 12px; }
}
</style>
</head>
<body>
<!-- En-tête -->
<div class="page-header">
    <div>
        <h1><i class="bi bi-box-arrow-up"></i> Sortie de stock</h1>
        <p>Enregistrer une sortie de stock (vente, transfert sortant, etc.)</p>
    </div>
    <div class="header-badge">
        <i class="bi bi-arrow-down-circle"></i>
        <?= count($historique) ?> dernières sorties
    </div>
</div>

<!-- Messages -->
<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'error' ? 'danger' : $messageType ?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : ($messageType === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?> me-2"></i>
    <?= $message ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Statistiques -->
<div class="row g-3 mb-4">
    <?php
    $colorMap = [
        'success' => ['var(--color-success-soft)', 'var(--color-success)'],
        'info'    => ['var(--color-info-soft)',    'var(--color-info)'],
        'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
        'danger'  => ['var(--color-danger-soft)',  'var(--color-danger)'],
        'purple'  => ['var(--color-purple-soft)',  'var(--color-purple)'],
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

<!-- Formulaire de sortie -->
<div class="data-table-wrap mb-4">
    <div class="section-title">
        <h5><i class="bi bi-pencil-square"></i> Saisie d'une sortie</h5>
    </div>
    <div class="form-section">
        <form method="post" id="sortieForm">
            <input type="hidden" name="action" value="sortie">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Boutique <span class="text-danger">*</span></label>
                    <select name="boutique_id" id="boutique_id" class="selectpicker form-select" data-live-search="true" required>
                        <option value="">-- Sélectionner une boutique --</option>
                        <?php foreach ($boutiques as $b): ?>
                        <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Produit <span class="text-danger">*</span></label>
                    <select name="produit_id" id="produit_id" class="selectpicker form-select" data-live-search="true" required>
                        <option value="">-- Sélectionner un produit --</option>
                        <?php foreach ($produits as $p): ?>
                        <option value="<?= e($p['code_produit']) ?>">
                            <?= e($p['titre_produit']) ?> (<?= e($p['code_produit']) ?>)<?= ($p['etat_produit'] === 'Inactif') ? ' (inactif)' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Seuls les produits ayant un stock global &gt; 0 sont proposés.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Motif de sortie <span class="text-danger">*</span></label>
                    <select name="statut_id" id="statut_id" class="selectpicker form-select" data-live-search="true" required>
                        <option value="">-- Sélectionner un motif --</option>
                        <?php foreach ($statutsSortieReserves as $s): ?>
                        <option value="<?= e($s['code_statut']) ?>"><?= e($s['titre_statut']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Seuls les motifs de sortie standards sont proposés.</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Quantité <span class="text-danger">*</span></label>
                    <input type="number" name="quantite" id="quantite" class="form-control" min="1" value="1" required>
                </div>
                <div class="col-md-9">
                    <label class="form-label">Prix unitaire de sortie (FCFA) <small class="text-muted">(optionnel, par défaut prix du produit)</small></label>
                    <input type="number" name="prix_vente" id="prix_vente" class="form-control" min="0" step="0.01" value="0" placeholder="Laisser à 0 pour utiliser le prix du produit">
                </div>
                <div class="col-12">
                    <div id="stockInfo" class="stock-info">
                        <div class="item"><span class="label">Stock actuel en boutique :</span> <strong id="currentQty">—</strong></div>
                        <div class="item"><span class="label">Disponible :</span> <strong id="currentDispo">—</strong></div>
                    </div>
                </div>
                <div class="col-12 mt-2">
                    <button type="submit" class="btn-submit">
                        <i class="bi bi-arrow-down-circle"></i> Valider la sortie
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Historique des sorties -->
<div class="data-table-wrap">
    <div class="section-title">
        <h5><i class="bi bi-clock-history"></i> Historique des sorties (30 dernières)</h5>
        <span class="text-muted small"><?= count($historique) ?> mouvements</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>N° commande</th>
                    <th>Produit</th>
                    <th>Boutique</th>
                    <th>Motif</th>
                    <th>Qté</th>
                    <th>Prix unit.</th>
                    <th>Montant</th>
                    <th>État</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($historique)): ?>
                <tr class="empty-row">
                    <td colspan="9">
                        <i class="bi bi-inbox"></i>
                        Aucune sortie enregistrée.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($historique as $h): ?>
                <tr>
                    <td class="td-mono"><?= e($h['numero_commande']) ?></td>
                    <td>
                        <div class="td-bold"><?= e($h['titre_produit'] ?? $h['produit_id']) ?></div>
                        <div class="td-mono"><?= e($h['produit_id']) ?></div>
                    </td>
                    <td><?= e($h['nom_boutique'] ?? $h['boutique_id']) ?></td>
                    <td><span class="badge-sortie"><?= e($h['titre_statut'] ?? $h['statut_id']) ?></span></td>
                    <td class="td-bold"><?= (int)$h['quantite_commande'] ?></td>
                    <td><?= number_format((float)$h['prix_commande'], 0, ',', ' ') ?> F</td>
                    <td class="td-bold"><?= number_format((float)$h['montant_commande'], 0, ',', ' ') ?> F</td>
                    <td><span class="badge-valide"><?= e($h['etat_commande'] ?? 'VALIDEE') ?></span></td>
                    <td class="td-mono"><?= e($h['date_commande']) ?> <?= e($h['heure_commande']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal d'alerte (remplace window.alert()) -->
<div class="modal fade" id="alertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Attention</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="alertModalMsg"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker();

    // Mise à jour des infos de stock en AJAX
    function updateStockInfo() {
        var produit  = $('#produit_id').val();
        var boutique = $('#boutique_id').val();
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
                        var max = Math.max(0, data.disponible);
                        $('#quantite').attr('max', max);
                    } else {
                        $('#currentQty').text('—');
                        $('#currentDispo').text('—');
                        $('#quantite').removeAttr('max');
                    }
                }
            });
        } else {
            $('#currentQty').text('—');
            $('#currentDispo').text('—');
        }
    }
    $('#produit_id, #boutique_id').on('changed.bs.select', updateStockInfo);

    function showAlertModal(msg) {
        $('#alertModalMsg').text(msg);
        new bootstrap.Modal(document.getElementById('alertModal')).show();
    }

    // Validation avant soumission
    $('#sortieForm').on('submit', function(e) {
        var dispo = parseInt($('#currentDispo').text()) || 0;
        var qty   = parseInt($('#quantite').val()) || 0;
        if (qty > dispo) {
            e.preventDefault();
            showAlertModal('La quantité demandée (' + qty + ') dépasse le stock disponible (' + dispo + ').');
            return false;
        }
    });
});
</script>
</body>
</html>