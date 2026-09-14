<?php
// historique_produit.php – Historique des produits avec stats par catégorie
// ✅ etat_produit = 'Actif'/'Inactif' (champ BDD)
// ✅ statut stock = DISPONIBLE / ALERTE / RUPTURE (calculé dynamiquement depuis stock_produit vs stock_alerte)
ob_start();
require 'databases/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: utilisateur/login');
    exit;
}
$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: utilisateur/login');
    exit;
}
define('USER_BOUTIQUE', $user['boutique_id'] ?? null);
require_once 'fonctions_rapport.php'; // e(), fmt(), + helpers PDF partagés (RapportPDF, creerPdfRapport...)

if (!function_exists('e')) { function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('fmt')) { function fmt($n) { return number_format(floatval($n), 0, ',', ' '); } }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// IMPRESSION PDF (mêmes filtres que la liste affichée à l'écran)
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'pdf') {
    while (ob_get_level() > 0) { ob_end_clean(); }

    $search = trim($_POST['search'] ?? '');
    $categorie_filter = trim($_POST['categorie_filter'] ?? '');
    $statut_filter = trim($_POST['statut_filter'] ?? '');
    $etat_filter = trim($_POST['etat_filter'] ?? '');
    $boutique_filter = trim($_POST['boutique_filter'] ?? '');
    // 'I' = affichage direct dans l'onglet, 'D' = téléchargement forcé (PDF uniquement).
    $modePdf = (isset($_POST['mode']) && $_POST['mode'] === 'D') ? 'D' : 'I';
    // 'pdf' (défaut) ou 'excel'.
    $formatExport = (isset($_POST['format']) && $_POST['format'] === 'excel') ? 'excel' : 'pdf';

    // Toutes les lignes correspondant au filtre (pas de pagination à l'impression) :
    // on réutilise getStockDisponible() avec une "page" volontairement large.
    $resultat = getStockDisponible($pdo, $search, $categorie_filter, $statut_filter, $etat_filter, $boutique_filter, 1, 100000);
    $lignes = $resultat['produits'] ?? [];

    $boutique = null;
    $boutiqueIdEntete = $boutique_filter !== '' ? $boutique_filter : ($user['boutique_id'] ?? '');
    if (!empty($boutiqueIdEntete)) {
        $stmtB = $pdo->prepare("SELECT * FROM boutique WHERE code_boutique = ?");
        $stmtB->execute([$boutiqueIdEntete]);
        $boutique = $stmtB->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $sousTitreParts = [];
    if ($categorie_filter !== '') $sousTitreParts[] = 'Catégorie : ' . $categorie_filter;
    if ($statut_filter !== '') $sousTitreParts[] = 'Stock : ' . ['DISPONIBLE' => 'Disponible', 'ALERTE' => 'Alerte', 'RUPTURE' => 'Rupture'][$statut_filter];
    if ($etat_filter !== '') $sousTitreParts[] = 'État : ' . $etat_filter;
    if (!empty($boutique['nom_boutique'])) $sousTitreParts[] = 'Boutique : ' . $boutique['nom_boutique'];
    $sousTitre = $sousTitreParts ? implode(' — ', $sousTitreParts) : 'Tous les produits';

    $colonnes = [
        ['titre' => 'Code', 'largeur' => 24, 'align' => 'L'],
        ['titre' => 'Produit', 'largeur' => 48, 'align' => 'L'],
        ['titre' => 'Catégorie', 'largeur' => 28, 'align' => 'L'],
        ['titre' => 'Px achat', 'largeur' => 20, 'align' => 'R'],
        ['titre' => 'Px vente', 'largeur' => 20, 'align' => 'R'],
        ['titre' => 'Stock', 'largeur' => 16, 'align' => 'C'],
        ['titre' => 'Statut', 'largeur' => 24, 'align' => 'C'],
        ['titre' => 'État', 'largeur' => 10, 'align' => 'C'],
    ];
    $libellesStatut = ['DISPONIBLE' => 'Disponible', 'ALERTE' => 'Alerte', 'RUPTURE' => 'Rupture'];
    $totalStock = 0;
    foreach ($lignes as $p) {
        $totalStock += (int)$p['stock_affiche'];
    }

    if ($formatExport === 'excel') {
        $xlsx = creerExcelRapport('HISTORIQUE DES PRODUITS', $sousTitre, $boutique, count($colonnes));
        $xlsx->setColumnWidths([16, 32, 20, 12, 12, 10, 14, 10]);
        $xlsx->addHeaderRow(['Code', 'Produit', 'Catégorie', 'Px achat', 'Px vente', 'Stock', 'Statut', 'État']);
        foreach ($lignes as $p) {
            $xlsx->addRow([
                $p['code_produit'],
                $p['titre_produit'],
                $p['categorie_titre'],
                (float)$p['prix_fournisseur'],
                (float)$p['prix_produit'],
                (int)$p['stock_affiche'],
                $libellesStatut[$p['statut_stock']] ?? $p['statut_stock'],
                ($p['etat_produit'] === 'Inactif') ? 'Inactif' : 'Actif',
            ], ['L', 'L', 'L', 'R', 'R', 'C', 'C', 'C']);
        }
        $xlsx->addBlankRow();
        $xlsx->addTotalRow(['TOTAL (' . count($lignes) . ' produit(s))', '', '', '', '', $totalStock, '', ''], ['L', 'L', 'L', 'R', 'R', 'C', 'C', 'C']);
        $xlsx->output('Historique_produits_' . date('Ymd_His') . '.xlsx', $modePdf);
    }

    $pdf = creerPdfRapport('HISTORIQUE DES PRODUITS', $sousTitre, $boutique);
    dessinerEnteteTableauRapport($pdf, $colonnes);
    foreach ($lignes as $i => $p) {
        dessinerLigneTableauRapport($pdf, $colonnes, [
            $p['code_produit'],
            $p['titre_produit'],
            $p['categorie_titre'],
            fmt((float)$p['prix_fournisseur']) . ' F',
            fmt((float)$p['prix_produit']) . ' F',
            (string)(int)$p['stock_affiche'],
            $libellesStatut[$p['statut_stock']] ?? $p['statut_stock'],
            ($p['etat_produit'] === 'Inactif') ? 'Inactif' : 'Actif',
        ], $i);
    }
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(242, 242, 242);
    $pdf->Cell(120, 7, 'TOTAL (' . count($lignes) . ' produit(s))', 0, 0, 'L', true);
    $pdf->Cell(16, 7, (string)$totalStock, 0, 0, 'C', true);
    $pdf->Cell(34, 7, '', 0, 1, 'L', true);
    $pdf->Output($modePdf, 'Historique_produits_' . date('Ymd_His') . '.pdf');
    exit;
}

// ============================================================
// FONCTION : STATISTIQUES PAR CATÉGORIE
// (dispo / alerte / rupture calculés sur le stock, actifs / inactifs sur etat_produit)
// ============================================================
function getStatsCategories($pdo, $boutique_filter = '') {
    if (!empty($boutique_filter)) {
        // Stats basées sur le stock de LA boutique sélectionnée (table stock),
        // pas le total global. Un produit jamais approvisionné dans cette
        // boutique compte pour 0 (LEFT JOIN + COALESCE).
        $sql = "SELECT
            COALESCE(c.titre_categorie, 'Sans catégorie') as categorie,
            c.code_categorie,
            COUNT(DISTINCT p.code_produit) as total_produits,
            COALESCE(SUM(CASE WHEN COALESCE(s.quantite,0) > COALESCE(s.stock_alerte, p.stock_alerte) THEN 1 ELSE 0 END), 0) as produits_disponibles,
            COALESCE(SUM(CASE WHEN COALESCE(s.quantite,0) > 0 AND COALESCE(s.quantite,0) <= COALESCE(s.stock_alerte, p.stock_alerte) THEN 1 ELSE 0 END), 0) as produits_alerte,
            COALESCE(SUM(CASE WHEN COALESCE(s.quantite,0) <= 0 THEN 1 ELSE 0 END), 0) as produits_rupture,
            COALESCE(SUM(CASE WHEN p.etat_produit = 'Inactif' THEN 1 ELSE 0 END), 0) as produits_inactifs,
            COALESCE(SUM(p.prix_fournisseur * COALESCE(s.quantite, 0)), 0) as valeur_achat,
            COALESCE(SUM(p.prix_produit * COALESCE(s.quantite, 0)), 0) as valeur_vente,
            COALESCE(SUM((p.prix_produit - p.prix_fournisseur) * COALESCE(s.quantite, 0)), 0) as marge_beneficiaire,
            COALESCE(SUM(COALESCE(s.quantite, 0)), 0) as stock_total
            FROM produit p
            LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
            LEFT JOIN stock s ON s.produit_id = p.code_produit AND s.boutique_id = ?
            GROUP BY c.code_categorie, c.titre_categorie
            ORDER BY stock_total DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$boutique_filter]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $sql = "SELECT
        COALESCE(c.titre_categorie, 'Sans catégorie') as categorie,
        c.code_categorie,
        COUNT(DISTINCT p.code_produit) as total_produits,
        COALESCE(SUM(CASE WHEN p.stock_produit > p.stock_alerte THEN 1 ELSE 0 END), 0) as produits_disponibles,
        COALESCE(SUM(CASE WHEN p.stock_produit > 0 AND p.stock_produit <= p.stock_alerte THEN 1 ELSE 0 END), 0) as produits_alerte,
        COALESCE(SUM(CASE WHEN p.stock_produit <= 0 THEN 1 ELSE 0 END), 0) as produits_rupture,
        COALESCE(SUM(CASE WHEN p.etat_produit = 'Inactif' THEN 1 ELSE 0 END), 0) as produits_inactifs,
        COALESCE(SUM(p.prix_fournisseur * COALESCE(p.stock_produit, 0)), 0) as valeur_achat,
        COALESCE(SUM(p.prix_produit * COALESCE(p.stock_produit, 0)), 0) as valeur_vente,
        COALESCE(SUM((p.prix_produit - p.prix_fournisseur) * COALESCE(p.stock_produit, 0)), 0) as marge_beneficiaire,
        COALESCE(SUM(COALESCE(p.stock_produit, 0)), 0) as stock_total
        FROM produit p
        LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
        GROUP BY c.code_categorie, c.titre_categorie
        ORDER BY stock_total DESC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// FONCTION : REGROUPE TOUS LES CALCULS DE STATS (bandeau + cartes),
// pour un chargement initial ou un rafraîchissement AJAX filtré par boutique
// ============================================================
function computeStats($pdo, $boutique_filter = '') {
    $stats_categories = getStatsCategories($pdo, $boutique_filter);

    $total_general = [
        'total_produits' => 0, 'valeur_achat' => 0, 'valeur_vente' => 0,
        'marge_beneficiaire' => 0, 'stock_total' => 0,
        'produits_disponibles' => 0, 'produits_alerte' => 0,
        'produits_rupture' => 0, 'produits_inactifs' => 0,
    ];
    foreach ($stats_categories as $sc) {
        $total_general['total_produits'] += (int)$sc['total_produits'];
        $total_general['valeur_achat'] += floatval($sc['valeur_achat']);
        $total_general['valeur_vente'] += floatval($sc['valeur_vente']);
        $total_general['marge_beneficiaire'] += floatval($sc['marge_beneficiaire']);
        $total_general['stock_total'] += (int)$sc['stock_total'];
        $total_general['produits_disponibles'] += (int)$sc['produits_disponibles'];
        $total_general['produits_alerte'] += (int)$sc['produits_alerte'];
        $total_general['produits_rupture'] += (int)$sc['produits_rupture'];
        $total_general['produits_inactifs'] += (int)$sc['produits_inactifs'];
    }
    $pourcentage_marge = $total_general['valeur_vente'] > 0
        ? round(($total_general['marge_beneficiaire'] / $total_general['valeur_vente']) * 100, 2)
        : 0;

    if (!empty($boutique_filter)) {
        $nbDisponibles = (int)$total_general['produits_disponibles'];
        $nbAlerte      = (int)$total_general['produits_alerte'];
        $nbRupture     = (int)$total_general['produits_rupture'];
    } else {
        $nbDisponibles = (int)$pdo->query("SELECT COUNT(*) FROM produit WHERE stock_produit > stock_alerte")->fetchColumn();
        $nbAlerte      = (int)$pdo->query("SELECT COUNT(*) FROM produit WHERE stock_produit > 0 AND stock_produit <= stock_alerte")->fetchColumn();
        $nbRupture     = (int)$pdo->query("SELECT COUNT(*) FROM produit WHERE stock_produit <= 0")->fetchColumn();
    }
    // L'état Actif/Inactif ne dépend pas du stock d'une boutique en particulier
    $nbActifs   = (int)$pdo->query("SELECT COUNT(*) FROM produit WHERE etat_produit <> 'Inactif'")->fetchColumn();
    $nbInactifs = (int)$pdo->query("SELECT COUNT(*) FROM produit WHERE etat_produit = 'Inactif'")->fetchColumn();

    return compact('stats_categories', 'total_general', 'pourcentage_marge', 'nbDisponibles', 'nbAlerte', 'nbRupture', 'nbActifs', 'nbInactifs');
}

// ============================================================
// FONCTION : RENDU HTML DU BANDEAU DE STATS + CARTES CATÉGORIE
// ============================================================
function renderStatsSection($stats, $categorie_filter, $boutique_filter) {
    extract($stats);
    ob_start();
    ?>
    <div class="stats-general">
        <div class="sg-item">
            <span class="sg-label"><i class="bi bi-cube"></i> Total produits</span>
            <span class="sg-value"><?= $total_general['total_produits'] ?></span>
            <span class="sg-sub"><?= count($stats_categories) ?> catégorie(s) · <?= $nbActifs ?> actif(s) · <?= $nbInactifs ?> inactif(s)</span>
        </div>
        <div class="sg-item dispo">
            <span class="sg-label"><i class="bi bi-check-circle-fill"></i> Disponibles</span>
            <span class="sg-value"><?= $nbDisponibles ?></span>
            <span class="sg-sub">stock &gt; seuil d'alerte</span>
        </div>
        <div class="sg-item alerte">
            <span class="sg-label"><i class="bi bi-exclamation-triangle-fill"></i> En alerte</span>
            <span class="sg-value"><?= $nbAlerte ?></span>
            <span class="sg-sub">0 &lt; stock ≤ seuil</span>
        </div>
        <div class="sg-item rupture">
            <span class="sg-label"><i class="bi bi-x-circle-fill"></i> En rupture</span>
            <span class="sg-value"><?= $nbRupture ?></span>
            <span class="sg-sub">stock ≤ 0</span>
        </div>
        <div class="sg-item stock">
            <span class="sg-label"><i class="bi bi-boxes"></i> Stock total</span>
            <span class="sg-value"><?= fmt($total_general['stock_total']) ?></span>
            <span class="sg-sub">unités en stock</span>
        </div>
        <div class="sg-item achat">
            <span class="sg-label"><i class="bi bi-cart-dash"></i> Valeur d'achat</span>
            <span class="sg-value"><?= fmt($total_general['valeur_achat']) ?> F</span>
            <span class="sg-sub">prix fournisseur × stock</span>
        </div>
        <div class="sg-item vente">
            <span class="sg-label"><i class="bi bi-cart-plus"></i> Valeur de vente</span>
            <span class="sg-value"><?= fmt($total_general['valeur_vente']) ?> F</span>
            <span class="sg-sub">prix vente × stock</span>
        </div>
        <div class="sg-item marge">
            <span class="sg-label"><i class="bi bi-graph-up-arrow"></i> Marge bénéficiaire</span>
            <span class="sg-value"><?= fmt($total_general['marge_beneficiaire']) ?> F</span>
            <span class="sg-sub"><?= $pourcentage_marge ?>% de marge globale</span>
        </div>
    </div>

    <?php if (!empty($stats_categories)): ?>
    <div class="stats-grid">
        <?php foreach ($stats_categories as $sc):
            $marge_pct = $sc['valeur_vente'] > 0 ? round(($sc['marge_beneficiaire'] / $sc['valeur_vente']) * 100, 1) : 0;
            $isActive = ($categorie_filter === $sc['categorie']) ? 'active' : '';
        ?>
        <div class="cat-card <?= $isActive ?>" onclick="filterByCategory('<?= e($sc['categorie']) ?>')">
            <div class="cat-card-head">
                <div class="cat-card-title">
                    <div class="cat-icon"><i class="bi bi-tag-fill"></i></div>
                    <div>
                        <div class="cat-name"><?= e($sc['categorie']) ?></div>
                        <div class="cat-count"><?= (int)$sc['total_produits'] ?> produit(s)</div>
                    </div>
                </div>
                <span class="cat-badge-produits"><?= fmt($sc['stock_total']) ?> u.</span>
            </div>
            <div class="cat-stats">
                <div class="cat-stat achat">
                    <div class="cs-label"><i class="bi bi-cart-dash"></i> Valeur achat</div>
                    <div class="cs-value"><?= fmt($sc['valeur_achat']) ?> F</div>
                </div>
                <div class="cat-stat vente">
                    <div class="cs-label"><i class="bi bi-cart-plus"></i> Valeur vente</div>
                    <div class="cs-value"><?= fmt($sc['valeur_vente']) ?> F</div>
                </div>
                <div class="cat-stat marge">
                    <div class="cs-label"><i class="bi bi-graph-up-arrow"></i> Marge bénéficiaire</div>
                    <div class="cs-value">
                        <span><?= fmt($sc['marge_beneficiaire']) ?> F</span>
                        <span class="marge-pct"><?= $marge_pct ?>%</span>
                    </div>
                </div>
            </div>
            <div class="cat-stock-info">
                <span><i class="bi bi-boxes"></i> État du stock :</span>
                <div class="stock-status">
                    <span title="Disponibles"><span class="dot ok"></span> <?= (int)$sc['produits_disponibles'] ?></span>
                    <span title="En alerte"><span class="dot warn"></span> <?= (int)$sc['produits_alerte'] ?></span>
                    <span title="En rupture"><span class="dot out"></span> <?= (int)$sc['produits_rupture'] ?></span>
                    <span title="Inactifs"><span class="dot inact"></span> <?= (int)$sc['produits_inactifs'] ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// ============================================================
// FONCTION : LISTE DES PRODUITS (filtrée par catégorie, statut stock et état)
// ============================================================
function getStockDisponible($pdo, $search, $categorie_filter, $statut_filter, $etat_filter, $boutique_filter, $page, $perPage = 20) {
    $where = "WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $where .= " AND (p.code_produit LIKE ? OR p.titre_produit LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if (!empty($categorie_filter) && $categorie_filter !== 'Tous') {
        if ($categorie_filter === 'Sans catégorie') {
            $where .= " AND (p.categorie_id IS NULL OR p.categorie_id = '')";
        } else {
            $where .= " AND c.titre_categorie = ?";
            $params[] = $categorie_filter;
        }
    }

    // Filtre par boutique : quand une boutique précise est choisie, le stock
    // affiché et utilisé pour le statut (Disponible/Alerte/Rupture) devient
    // celui de CETTE boutique (table stock), et non plus le total global de
    // produit.stock_produit. Un produit jamais approvisionné dans cette
    // boutique apparaît avec un stock de 0 (LEFT JOIN + COALESCE).
    $boutiqueJoin = '';
    $stockExpr = 'p.stock_produit';
    $alerteExpr = 'p.stock_alerte';
    if (!empty($boutique_filter)) {
        $boutiqueJoin = " LEFT JOIN stock s ON s.produit_id = p.code_produit AND s.boutique_id = ?";
        $stockExpr = 'COALESCE(s.quantite, 0)';
        $alerteExpr = 'COALESCE(s.stock_alerte, p.stock_alerte)';
        // Le paramètre du JOIN doit être ajouté avant les paramètres du WHERE,
        // puisqu'il apparaît plus tôt dans la requête SQL finale.
        array_unshift($params, $boutique_filter);
    }

    // ✅ Statut de stock CALCULÉ (indépendant de etat_produit) — basé sur le
    // stock de la boutique sélectionnée si un filtre boutique est actif.
    if (!empty($statut_filter)) {
        if ($statut_filter === 'RUPTURE') {
            $where .= " AND $stockExpr <= 0";
        } elseif ($statut_filter === 'ALERTE') {
            $where .= " AND $stockExpr > 0 AND $stockExpr <= $alerteExpr";
        } elseif ($statut_filter === 'DISPONIBLE') {
            $where .= " AND $stockExpr > $alerteExpr";
        }
    }

    // ✅ État Actif / Inactif (champ etat_produit)
    if (!empty($etat_filter)) {
        if ($etat_filter === 'Inactif') {
            $where .= " AND p.etat_produit = 'Inactif'";
        } else {
            $where .= " AND p.etat_produit <> 'Inactif'";
        }
    }

    $countSql = "SELECT COUNT(*) FROM produit p LEFT JOIN categorie c ON p.categorie_id = c.code_categorie $boutiqueJoin $where";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPages = max(1, ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    // ✅ statut_stock calculé dans le SELECT pour l'affichage des badges
    //    (sur le stock de la boutique filtrée si applicable, sinon le stock global)
    $sql = "SELECT p.*, COALESCE(c.titre_categorie, 'Sans catégorie') as categorie_titre,
            $stockExpr as stock_affiche,
            $alerteExpr as alerte_affichee,
            CASE
                WHEN $stockExpr <= 0 THEN 'RUPTURE'
                WHEN $stockExpr <= $alerteExpr THEN 'ALERTE'
                ELSE 'DISPONIBLE'
            END as statut_stock
            FROM produit p
            LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
            $boutiqueJoin
            $where
            ORDER BY p.titre_produit ASC
            LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($produits)): ?>
        <tr>
            <td colspan="11" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                Aucun produit trouvé
            </td>
        </tr>
    <?php else: foreach ($produits as $p):
            // Badge de statut de stock (calculé)
            $statut = $p['statut_stock'];
            switch ($statut) {
                case 'DISPONIBLE': $etatClass = 'on';   $etatIcon = 'check-circle-fill';          break;
                case 'ALERTE':     $etatClass = 'warn'; $etatIcon = 'exclamation-triangle-fill';  break;
                default:           $etatClass = 'off';  $etatIcon = 'x-circle-fill';              break;
            }
            // État Actif / Inactif (champ BDD)
            $inactif = ($p['etat_produit'] === 'Inactif');

            $stock = (int)$p['stock_affiche'];
            if ($stock <= 0) $stockClass = 'text-danger fw-bold';
            elseif ($stock <= (int)$p['alerte_affichee']) $stockClass = 'text-warning fw-bold';
            else $stockClass = 'text-success fw-bold';

            $benefice = floatval($p['prix_produit']) - floatval($p['prix_fournisseur']);
            $beneficeClass = $benefice > 0 ? 'text-success' : ($benefice < 0 ? 'text-danger' : 'text-muted');
        ?>
        <tr>
            <td class="td-bold"><?= e($p['code_produit']) ?></td>
            <td class="td-semi"><?= e($p['titre_produit']) ?></td>
            <td>
                <?php if (!empty($p['photo'])): ?>
                    <img src="data:<?= e($p['type_photo']) ?>;base64,<?= base64_encode($p['photo']) ?>"
                         alt="<?= e($p['titre_produit']) ?>"
                         style="width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid var(--border-color);">
                <?php else: ?>
                    <div style="width:40px;height:40px;border-radius:8px;background:var(--color-gray-100);display:flex;align-items:center;justify-content:center;color:var(--text-tertiary);">
                        <i class="bi bi-image"></i>
                    </div>
                <?php endif; ?>
            </td>
            <td class="text-end"><?= fmt($p['prix_fournisseur']) ?> F</td>
            <td class="text-end td-bold"><?= fmt($p['prix_produit']) ?> F</td>
            <td class="text-end <?= $beneficeClass ?> fw-bold"><?= fmt($benefice) ?> F</td>
            <td class="text-center"><?= (int)$p['alerte_affichee'] ?></td>
            <td class="text-center">
                <span class="<?= $stockClass ?>"><?= $stock ?></span>
            </td>
            <td>
                <span class="badge-chic bg-primary-subtle text-primary">
                    <i class="bi bi-tag"></i> <?= e($p['categorie_titre']) ?>
                </span>
            </td>
            <td>
                <span class="status-badge <?= $etatClass ?>">
                    <i class="bi bi-<?= $etatIcon ?>"></i>
                    <?= e($statut) ?>
                </span>
            </td>
            <td>
                <?php if ($inactif): ?>
                    <span class="badge-chic bg-secondary-subtle text-secondary"><i class="bi bi-x-circle"></i> Inactif</span>
                <?php else: ?>
                    <span class="badge-chic bg-success-subtle text-success"><i class="bi bi-check-circle"></i> Actif</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif;
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top">
            <span class="text-muted small">
                Affichage de <?= (($page - 1) * $perPage + 1) ?> à <?= min($page * $perPage, $total) ?> sur <?= $total ?>
            </span>
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
                    for ($i = $start; $i <= $end; $i++): ?>
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
        'totalPages' => $totalPages,
        'produits' => $produits, // lignes brutes (utilisées par l'impression PDF, voir plus haut)
    ];
}

// ============================================================
// REQUÊTE AJAX (tout en POST)
// ============================================================
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $categorie = trim($_POST['categorie_filter'] ?? '');
    $statut = trim($_POST['statut_filter'] ?? '');
    $etat = trim($_POST['etat_filter'] ?? '');
    $boutique = trim($_POST['boutique_filter'] ?? '');
    $page = max(1, (int)($_POST['page'] ?? 1));
    $result = getStockDisponible($pdo, $search, $categorie, $statut, $etat, $boutique, $page);
    unset($result['produits']); // lignes brutes réservées à l'impression PDF : jamais renvoyées telles quelles en JSON (contiennent la photo en blob binaire)
    // Les cartes (bandeau + par catégorie) suivent la boutique sélectionnée :
    // recalculées à chaque requête, elles ne changent en pratique que quand
    // ce filtre change (les autres filtres n'affectent que le tableau).
    $result['statsHtml'] = renderStatsSection(computeStats($pdo, $boutique), $categorie, $boutique);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// ============================================================
// DONNÉES POUR LA PAGE
// ============================================================

// Catégories pour le filtre
$categories = $pdo->query("SELECT DISTINCT COALESCE(c.titre_categorie, 'Sans catégorie') as titre
    FROM produit p
    LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
    ORDER BY titre")->fetchAll(PDO::FETCH_COLUMN);

// Boutiques pour le filtre
$boutiquesListe = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

// Données initiales (sans filtre)
$search = '';
$categorie_filter = '';
$statut_filter = '';
$etat_filter = '';
$boutique_filter = '';
$initialData = getStockDisponible($pdo, $search, $categorie_filter, $statut_filter, $etat_filter, $boutique_filter, 1);
$statsData = computeStats($pdo, $boutique_filter);
extract($statsData); // $nbDisponibles, $nbAlerte, $nbRupture, $nbActifs, $nbInactifs (utilisés dans les options des filtres Stock/État)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Historique des produits</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
    --shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.08);
    --radius-sm: 10px;
    --radius-md: 14px;
    --radius-lg: 16px;
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
.W { max-width: 1500px; margin: 0 auto; }

/* ===== EN-TÊTE ===== */
.hdr { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--text-primary); letter-spacing: -0.02em; font-family: 'Outfit', sans-serif; }
.hdr-l p { font-size: 13px; color: var(--text-tertiary); margin-top: 2px; font-weight: 500; }
.hdr-badge { background: var(--color-primary-soft); border: 1px solid #bfdbfe; color: var(--color-primary); padding: 8px 14px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }

/* ===== STATS GÉNÉRALES ===== */
.stats-general {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 24px;
    margin-bottom: 22px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
    animation: fadeUp 0.4s ease both;
}
.stats-general::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--color-primary), var(--color-purple));
}
.sg-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px;
    background: var(--color-gray-50);
    border-radius: 10px;
    border-left: 3px solid var(--color-primary);
}
.sg-item .sg-label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-tertiary); font-weight: 700; display: flex; align-items: center; gap: 6px; }
.sg-item .sg-value { font-size: 22px; font-weight: 800; font-family: 'Outfit', sans-serif; color: var(--text-primary); }
.sg-item .sg-sub { font-size: 10px; color: var(--text-tertiary); font-weight: 500; }
.sg-item.achat { border-left-color: var(--color-danger); }
.sg-item.vente { border-left-color: var(--color-primary); }
.sg-item.marge { border-left-color: var(--color-success); }
.sg-item.stock { border-left-color: var(--color-warning); }
.sg-item.dispo { border-left-color: var(--color-success); }
.sg-item.alerte { border-left-color: var(--color-warning); }
.sg-item.rupture { border-left-color: var(--color-danger); }

/* ===== CARTES CATÉGORIES ===== */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}
.cat-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 18px;
    transition: all .2s;
    position: relative;
    overflow: hidden;
    cursor: pointer;
    animation: fadeUp 0.4s ease both;
}
.cat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, .08);
    border-color: #bfdbfe;
}
.cat-card.active {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}
.cat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--color-primary), var(--color-purple));
}
.cat-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.cat-card-title { display: flex; align-items: center; gap: 10px; }
.cat-icon { width: 42px; height: 42px; border-radius: 10px; background: var(--color-primary-soft); color: var(--color-primary); display: flex; align-items: center; justify-content: center; font-size: 20px; }
.cat-name { font-size: 14px; font-weight: 700; color: var(--text-primary); font-family: 'Outfit', sans-serif; }
.cat-count { font-size: 11px; color: var(--text-tertiary); font-weight: 600; }
.cat-badge-produits { background: var(--color-primary-soft); color: var(--color-primary); padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.cat-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.cat-stat { background: var(--color-gray-50); border-radius: 8px; padding: 10px 12px; }
.cat-stat .cs-label { font-size: 10px; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .04em; font-weight: 600; margin-bottom: 2px; display: flex; align-items: center; gap: 4px; }
.cat-stat .cs-value { font-size: 14px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; }
.cat-stat.achat .cs-value { color: var(--color-danger); }
.cat-stat.vente .cs-value { color: var(--color-primary); }
.cat-stat.marge { grid-column: 1 / -1; background: var(--color-success-soft); }
.cat-stat.marge .cs-value { color: var(--color-success); display: flex; align-items: center; justify-content: space-between; }
.marge-pct { font-size: 11px; background: var(--color-success); color: #fff; padding: 2px 8px; border-radius: 12px; font-weight: 700; }
.cat-stock-info { margin-top: 10px; display: flex; align-items: center; justify-content: space-between; font-size: 11px; color: var(--text-tertiary); padding-top: 10px; border-top: 1px dashed var(--border-color); }
.stock-status { display: flex; gap: 10px; }
.stock-status span { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; }
.stock-status .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.dot.ok { background: var(--color-success); }
.dot.warn { background: var(--color-warning); }
.dot.out { background: var(--color-danger); }
.dot.inact { background: var(--color-gray-400); }

/* ===== FILTRES ===== */
.filters-section {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 16px 20px;
    margin-bottom: 22px;
    box-shadow: var(--shadow-sm);
}
.prow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.prow label { font-size: 11px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .03em; display: flex; align-items: center; gap: 4px; }
.prow input, .prow select {
    padding: 9px 12px;
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    background: var(--color-gray-50);
    color: var(--text-primary);
    font-family: 'Inter', sans-serif;
    transition: all .2s;
}
.prow input:focus, .prow select:focus {
    border-color: var(--color-primary);
    background: #fff;
    box-shadow: 0 0 0 3px var(--color-primary-soft);
    outline: none;
}
.prow input[type="text"] { flex: 1; min-width: 220px; }
.btn-go {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
    color: #fff;
    padding: 9px 18px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    transition: all .2s;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}
.btn-go:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4); }
.btn-go-outline {
    background: transparent;
    color: var(--text-tertiary);
    border: 1.5px solid var(--border-color);
    padding: 9px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    transition: all .2s;
}
.btn-success { background:#10b981; color:#fff; border:none; }
.btn-go-outline:hover { background: var(--color-gray-100); color: var(--text-primary); }

/* ===== TABLEAU ===== */
.data-table-wrap {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    animation: fadeUp .4s ease both;
}
.table-header {
    background: var(--color-gray-50);
    border-bottom: 1px solid var(--border-color);
    padding: 14px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.table-header h5 {
    font-family: 'Outfit', sans-serif;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
}
table { margin: 0; font-size: 13px; width: 100%; }
table thead th {
    background: var(--color-gray-50);
    color: var(--text-tertiary);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 10px 14px;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}
table tbody td { padding: 11px 14px; border-bottom: 1px solid var(--border-color); vertical-align: middle; }
table tbody tr:hover { background: var(--color-primary-soft); }
table tbody tr:last-child td { border-bottom: none; }
.td-bold { color: var(--text-primary) !important; font-weight: 700; font-family: 'Outfit', sans-serif; }
.td-semi { color: var(--text-primary) !important; font-weight: 500; }

/* ===== BADGES ===== */
.badge-chic {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .02em;
}
.status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; letter-spacing: .02em; }
.status-badge.on { background: var(--color-success-soft); color: #059669; border: 1px solid #a7f3d0; }
.status-badge.off { background: var(--color-danger-soft); color: #dc2626; border: 1px solid #fecaca; }
.status-badge.warn { background: var(--color-warning-soft); color: #b45309; border: 1px solid #fde68a; }

/* ===== PAGINATION ===== */
.pagination .page-link { border: 1px solid var(--border-color); color: var(--text-tertiary); font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 6px; }
.pagination .page-item.active .page-link { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }

/* ===== SELECTPICKER ===== */
.bootstrap-select .dropdown-toggle {
    background: var(--color-gray-50) !important;
    border: 1.5px solid var(--border-color) !important;
    border-radius: 8px !important;
    font-size: 13px !important;
}
.bootstrap-select .dropdown-toggle:focus {
    border-color: var(--color-primary) !important;
    box-shadow: 0 0 0 3px var(--color-primary-soft) !important;
}

@keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@media (max-width:700px) {
    body { padding: 14px; }
    .hdr { flex-direction: column; align-items: flex-start; }
    .prow { flex-direction: column; align-items: stretch; }
    .prow .btn-go { width: 100%; justify-content: center; }
    .stats-general { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>
<div class="W">

    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1><i class="bi bi-clock-history text-primary me-2"></i>Historique des produits</h1>
            <p>Vue d'ensemble du catalogue par catégorie et suivi des stocks</p>
        </div>
        <div>
            <span class="hdr-badge"><i class="bi bi-cube"></i> <?= $initialData['total'] ?> produits</span>
        </div>
    </div>

    <!-- ===== STATS GÉNÉRALES + CARTES PAR CATÉGORIE (rafraîchies en AJAX selon la boutique) ===== -->
    <div id="statsSection">
        <?= renderStatsSection($statsData, $categorie_filter, $boutique_filter) ?>
    </div>

    <!-- ===== FILTRES ===== -->
    <form id="searchForm" class="filters-section" method="post" onsubmit="return false;">
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="categorie_filter" id="categorieFilterHidden" value="<?= e($categorie_filter) ?>">
        <input type="hidden" name="statut_filter" id="statutFilterHidden" value="<?= e($statut_filter) ?>">
        <input type="hidden" name="etat_filter" id="etatFilterHidden" value="<?= e($etat_filter) ?>">
        <input type="hidden" name="boutique_filter" id="boutiqueFilterHidden" value="<?= e($boutique_filter) ?>">
        <div class="prow">
            <label><i class="bi bi-search"></i> Rechercher</label>
            <input type="text" name="search" id="searchInput" placeholder="Code ou titre du produit...">

            <label><i class="bi bi-tag"></i> Catégorie</label>
            <select name="categorie_filter_select" id="categorieFilterSelect" class="selectpicker" data-live-search="true" data-live-search-placeholder="Toutes les catégories...">
                <option value="">Toutes</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= ($categorie_filter === $cat) ? 'selected' : '' ?>><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>

            <label><i class="bi bi-shop"></i> Boutique</label>
            <select name="boutique_filter_select" id="boutiqueFilterSelect" class="selectpicker" data-live-search="true" data-live-search-placeholder="Toutes les boutiques...">
                <option value="">Toutes les boutiques (stock global)</option>
                <?php foreach ($boutiquesListe as $b): ?>
                    <option value="<?= e($b['code_boutique']) ?>" <?= ($boutique_filter === $b['code_boutique']) ? 'selected' : '' ?>><?= e($b['nom_boutique']) ?></option>
                <?php endforeach; ?>
            </select>

            <label><i class="bi bi-circle-half"></i> Stock</label>
            <select name="statut_filter_select" id="statutFilterSelect" class="selectpicker">
                <option value="">Tous les stocks</option>
                <option value="DISPONIBLE" <?= ($statut_filter === 'DISPONIBLE') ? 'selected' : '' ?>>✅ Disponible (<?= $nbDisponibles ?>)</option>
                <option value="ALERTE" <?= ($statut_filter === 'ALERTE') ? 'selected' : '' ?>>⚠️ Alerte (<?= $nbAlerte ?>)</option>
                <option value="RUPTURE" <?= ($statut_filter === 'RUPTURE') ? 'selected' : '' ?>>❌ Rupture (<?= $nbRupture ?>)</option>
            </select>

            <label><i class="bi bi-toggle-on"></i> État</label>
            <select name="etat_filter_select" id="etatFilterSelect" class="selectpicker">
                <option value="">Tous les états</option>
                <option value="Actif" <?= ($etat_filter === 'Actif') ? 'selected' : '' ?>>Actif (<?= $nbActifs ?>)</option>
                <option value="Inactif" <?= ($etat_filter === 'Inactif') ? 'selected' : '' ?>>Inactif (<?= $nbInactifs ?>)</option>
            </select>

            <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
            <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
            <button type="button" class="btn-go-outline" id="printBtn" data-mode="I"><i class="bi bi-printer"></i> Imprimer</button>
            <button type="button" class="btn-go-outline" id="downloadBtn" data-mode="D"><i class="bi bi-download"></i> Télécharger</button>
            <button type="button" class="btn-go btn-success" id="excelBtn"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        </div>
    </form>

    <!-- ===== TABLEAU ===== -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="table-header">
            <h5>
                <i class="bi bi-list-ul text-primary"></i>
                Historique des produits
                <?php if (!empty($categorie_filter)): ?>
                    <span class="badge-chic bg-primary-subtle text-primary ms-2"><?= e($categorie_filter) ?></span>
                <?php endif; ?>
                <?php if (!empty($boutique_filter)):
                    $nomBoutiqueFiltre = '';
                    foreach ($boutiquesListe as $b) { if ($b['code_boutique'] === $boutique_filter) { $nomBoutiqueFiltre = $b['nom_boutique']; break; } }
                ?>
                    <span class="badge-chic bg-info-subtle text-info ms-2"><i class="bi bi-shop"></i> Stock : <?= e($nomBoutiqueFiltre) ?></span>
                <?php endif; ?>
                <?php if (!empty($statut_filter)): ?>
                    <span class="badge-chic bg-warning-subtle text-warning ms-2"><?= e($statut_filter) ?></span>
                <?php endif; ?>
                <?php if (!empty($etat_filter)): ?>
                    <span class="badge-chic bg-success-subtle text-success ms-2"><?= e($etat_filter) ?></span>
                <?php endif; ?>
            </h5>
            <span class="text-muted small" id="totalCount">
                <?= $initialData['total'] ?> produit(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Titre</th>
                        <th>Photo</th>
                        <th class="text-end">Prix fourn.</th>
                        <th class="text-end">Prix vente</th>
                        <th class="text-end">Bénéfice</th>
                        <th class="text-center">Stock alerte</th>
                        <th class="text-center">Stock</th>
                        <th>Catégorie</th>
                        <th>État stock</th>
                        <th>État</th>
                    </tr>
                </thead>
                <tbody id="tableBody"><?= $initialData['table'] ?></tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $initialData['pagination'] ?></div>
    </div>
</div>

<!-- Modal d'alerte (remplace window.alert()) -->
<div class="modal fade" id="alertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Erreur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="alertModalMsg"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker();

    // ============================================================
    // RECHERCHE AJAX
    // ============================================================
    function rechercher(page, updateStats) {
        page = page || 1;
        var formData = $('#searchForm').serialize() + '&page=' + page;
        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' produit(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
                if (updateStats && data.statsHtml) {
                    $('#statsSection').html(data.statsHtml);
                }
            },
            error: function(xhr) {
                console.error(xhr.responseText);
                $('#alertModalMsg').text('Erreur lors de la recherche.');
                new bootstrap.Modal(document.getElementById('alertModal')).show();
            }
        });
    }

    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 400);
    });

    $('#categorieFilterSelect').on('changed.bs.select', function() {
        var val = $(this).val() || '';
        $('#categorieFilterHidden').val(val);
        rechercher(1);
        updateActiveCard(val);
    });

    $('#boutiqueFilterSelect').on('changed.bs.select', function() {
        var val = $(this).val() || '';
        $('#boutiqueFilterHidden').val(val);
        rechercher(1, true);
    });

    $('#statutFilterSelect').on('changed.bs.select', function() {
        var val = $(this).val() || '';
        $('#statutFilterHidden').val(val);
        rechercher(1);
    });

    $('#etatFilterSelect').on('changed.bs.select', function() {
        var val = $(this).val() || '';
        $('#etatFilterHidden').val(val);
        rechercher(1);
    });

    $('#filterBtn').on('click', function() { rechercher(1); });

    // ✅ Pas de selectpicker('refresh') ici (bug beta3) : 'val' suffit pour resynchroniser
    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#categorieFilterSelect').selectpicker('val', '');
        $('#boutiqueFilterSelect').selectpicker('val', '');
        $('#statutFilterSelect').selectpicker('val', '');
        $('#etatFilterSelect').selectpicker('val', '');
        $('#categorieFilterHidden').val('');
        $('#boutiqueFilterHidden').val('');
        $('#statutFilterHidden').val('');
        $('#etatFilterHidden').val('');
        rechercher(1, true);
        updateActiveCard('');
    });

    // ---- Impression / téléchargement PDF (mêmes filtres que la liste affichée) ----
    function imprimerHistoriqueProduits(mode, format) {
        var data = {
            action: 'pdf',
            mode: mode || 'I',
            format: format || 'pdf',
            search: $('#searchInput').val() || '',
            categorie_filter: $('#categorieFilterHidden').val() || '',
            statut_filter: $('#statutFilterHidden').val() || '',
            etat_filter: $('#etatFilterHidden').val() || '',
            boutique_filter: $('#boutiqueFilterHidden').val() || '',
            csrf_token: <?= json_encode($csrf_token) ?>
        };
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.pathname;
        // Pas de target : le fichier s'ouvre/se télécharge dans le même onglet.
        Object.keys(data).forEach(function (key) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = key; input.value = data[key];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
        form.remove();
    }
    $('#printBtn').on('click', function() { imprimerHistoriqueProduits('I', 'pdf'); });
    $('#downloadBtn').on('click', function() { imprimerHistoriqueProduits('D', 'pdf'); });
    $('#excelBtn').on('click', function() { imprimerHistoriqueProduits('D', 'excel'); });

    // Pagination
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        if (page && page >= 1) rechercher(page);
    });

    // ============================================================
    // FILTRAGE PAR CARTE CATÉGORIE
    // ============================================================
    window.filterByCategory = function(categorie) {
        $('#categorieFilterHidden').val(categorie);
        $('#categorieFilterSelect').selectpicker('val', categorie);
        rechercher(1);
        updateActiveCard(categorie);
    };

    function updateActiveCard(categorie) {
        $('.cat-card').removeClass('active');
        if (categorie) {
            $('.cat-card').each(function() {
                var cardName = $(this).find('.cat-name').text().trim();
                if (cardName === categorie) {
                    $(this).addClass('active');
                }
            });
        }
    }
});
</script>
</body>
</html>