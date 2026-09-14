<?php
// mouvement_stock.php
// Redesigné avec le style de rapport_commercial.php
require 'databases/database.php';
require 'fonctions_rapport.php';

if (!function_exists('e')) {
function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt')) {
function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }
}
// ==========================================================
// FILTRE BOUTIQUE
// ==========================================================
// Boutiques que cet utilisateur a le droit de consulter (toutes pour
// Administrateur/Superviseur ; sa boutique + exceptions pour les autres).
$boutiquesAutorisees = getBoutiquesAutorisees($pdo, $_SESSION['role'] ?? null, $_SESSION['boutique_id'] ?? null);
$boutiqueId = trim($_GET['boutique_id'] ?? $_POST['boutique_id'] ?? '');
if ($boutiqueId !== '' && !in_array($boutiqueId, $boutiquesAutorisees, true)) $boutiqueId = '';
if (empty($boutiquesAutorisees)) {
    $boutiques = [];
} else {
    $inPhRap = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $stmtBRap = $pdo->prepare("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' AND code_boutique IN ($inPhRap) ORDER BY nom_boutique");
    $stmtBRap->execute($boutiquesAutorisees);
    $boutiques = $stmtBRap->fetchAll(PDO::FETCH_ASSOC);
}
// Clause SQL + valeurs à fusionner dans chaque requête de ce rapport pour ne
// jamais dépasser les boutiques autorisées, quel que soit le filtre choisi.
$boutiquesAutoriseesSql = empty($boutiquesAutorisees) ? '1=0' : 'IN (' . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ')';
// ==========================================================
// FONCTION DE PAGINATION PARTAGÉE
// ==========================================================
function paginer($pdo, $sql, $countSql, $params, $page, $perPage, $rowRenderer, $colspan) {
$page = max(1, (int)$page);
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare($sql . " LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
ob_start();
if (empty($rows)) {
echo '<tr><td colspan="' . $colspan . '" class="empty-cell"><i class="bi bi-inbox d-block mb-2" style="font-size:3rem;opacity:.2;"></i><div class="text-muted small">Aucune donnée</div></td></tr>';
} else {
foreach ($rows as $row) echo $rowRenderer($row);
}
$tableHtml = ob_get_clean();
ob_start();
if ($totalPages > 1): ?>
<div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top">
<span class="text-muted small">Affichage de <?= ($offset + 1) ?> à <?= min($offset + $perPage, $total) ?> sur <?= $total ?></span>
<nav>
<ul class="pagination pagination-sm mb-0">
<li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $page - 1 ?>"><i class="bi bi-chevron-left"></i></a></li>
<?php
$start = max(1, $page - 2); $end = min($totalPages, $page + 2);
if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>'; if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
for ($i = $start; $i <= $end; $i++): ?>
<li class="page-item <?= ($i == $page) ? 'active' : '' ?>"><a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a></li>
<?php endfor;
if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $totalPages . '">' . $totalPages . '</a></li>'; }
?>
<li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $page + 1 ?>"><i class="bi bi-chevron-right"></i></a></li>
</ul>
</nav>
</div>
<?php endif;
$paginationHtml = ob_get_clean();
return compact('tableHtml', 'paginationHtml', 'total', 'page', 'totalPages');
}
// ==========================================================
// ONGLET 1 : MOUVEMENTS DE STOCK
// ==========================================================
function chargerMouvements($pdo, $page, $boutiquesAutorisees, $boutiqueId) {
$whereBoutiqueMvt = empty($boutiquesAutorisees) ? " AND 1=0" : " AND c.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";
$paramsMvt = $boutiquesAutorisees;
if ($boutiqueId !== '') { $whereBoutiqueMvt .= " AND c.boutique_id = ?"; $paramsMvt[] = $boutiqueId; }
$sql = "SELECT c.numero_commande, c.date_commande, c.quantite_commande, c.statut_id, c.etat_commande,
p.titre_produit, b.nom_boutique,
CASE WHEN c.statut_id IN ('011','009','010','006') THEN 'ENTRÉE' ELSE 'SORTIE' END AS type_mvt
FROM commande c
JOIN produit p ON c.produit_id = p.code_produit
LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
WHERE c.etat_commande NOT IN ('En attente','Annulé')$whereBoutiqueMvt
ORDER BY c.date_commande DESC, c.heure_commande DESC";
$countSql = "SELECT COUNT(*) FROM commande c WHERE c.etat_commande NOT IN ('En attente','Annulé')$whereBoutiqueMvt";
$renderer = function ($row) {
$badgeMvt = $row['type_mvt'] === 'ENTRÉE' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
$etat = $row['etat_commande'];
$etatLower = strtolower($etat);
if ($etatLower === 'validé' || $etatLower === 'valider' || $etatLower === 'validee') {
$badgeEtat = 'bg-success-subtle text-success';
} elseif ($etatLower === 'reçu' || $etatLower === 'recu') {
$badgeEtat = 'bg-info-subtle text-info';
} else {
$badgeEtat = 'bg-secondary-subtle text-secondary';
}
return '<tr>'
. '<td class="fw-bold">' . e($row['numero_commande']) . '</td>'
. '<td>' . date('d/m/Y', strtotime($row['date_commande'])) . '</td>'
. '<td>' . e($row['titre_produit']) . '</td>'
. '<td>' . e($row['nom_boutique'] ?? '—') . '</td>'
. '<td class="text-center fw-semibold">' . (int)$row['quantite_commande'] . '</td>'
. '<td class="text-center"><span class="badge-chic ' . $badgeMvt . '"><span class="dot"></span> ' . e($row['type_mvt']) . '</span></td>'
. '<td class="text-center"><span class="badge-chic ' . $badgeEtat . '"><span class="dot"></span> ' . e($etat) . '</span></td>'
. '</tr>';
};
return paginer($pdo, $sql, $countSql, $paramsMvt, $page, 20, $renderer, 7);
}
// ==========================================================
// ONGLET 2 : VALORISATION DU STOCK
// ==========================================================
function chargerValorisation($pdo, $page, $boutiqueId, $boutiquesAutorisees) {
$params = [];
$whereBoutique = empty($boutiquesAutorisees) ? " AND 1=0" : " AND s.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";
foreach ($boutiquesAutorisees as $bid) $params[] = $bid;
if ($boutiqueId !== '') {
$whereBoutique .= " AND s.boutique_id = ?";
$params[] = $boutiqueId;
}
$sql = "SELECT s.produit_id, s.boutique_id, s.quantite, s.stock_alerte AS alerte_boutique,
p.titre_produit, p.prix_fournisseur, p.prix_produit, p.stock_alerte, p.etat_produit,
cat.titre_categorie, b.nom_boutique,
(s.quantite * p.prix_fournisseur) AS valeur_achat,
(s.quantite * p.prix_produit) AS valeur_vente
FROM stock s
JOIN produit p ON s.produit_id = p.code_produit
LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
LEFT JOIN boutique b ON s.boutique_id = b.code_boutique
WHERE s.quantite > 0$whereBoutique
ORDER BY valeur_achat DESC";
$countSql = "SELECT COUNT(*) FROM stock s WHERE s.quantite > 0$whereBoutique";
$renderer = function ($row) {
$etat = $row['etat_produit'];
if ($etat === 'DISPONIBLE') {
$badge = 'bg-success-subtle text-success';
} elseif ($etat === 'ALERTE') {
$badge = 'bg-warning-subtle text-warning';
} else {
$badge = 'bg-danger-subtle text-danger';
}
return '<tr>'
. '<td class="fw-bold">' . e($row['titre_produit']) . '</td>'
. '<td>' . e($row['titre_categorie'] ?? '—') . '</td>'
. '<td>' . e($row['nom_boutique'] ?? '—') . '</td>'
. '<td class="text-center fw-semibold">' . (int)$row['quantite'] . '</td>'
. '<td class="text-end">' . fmt((float)$row['prix_fournisseur']) . ' F</td>'
. '<td class="text-end fw-bold text-primary">' . fmt((float)$row['valeur_achat']) . ' F</td>'
. '<td class="text-end fw-semibold text-success">' . fmt((float)$row['valeur_vente']) . ' F</td>'
. '<td class="text-center"><span class="badge-chic ' . $badge . '"><span class="dot"></span> ' . e($etat) . '</span></td>'
. '</tr>';
};
return paginer($pdo, $sql, $countSql, $params, $page, 20, $renderer, 8);
}
// ==========================================================
// DISPATCHER AJAX
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] == '1') {
$tab = $_POST['tab'] ?? 'mouvements';
$page = (int)($_POST['page'] ?? 1);
$boutiqueIdAjax = trim($_POST['boutique_id'] ?? '');
if ($boutiqueIdAjax !== '' && !in_array($boutiqueIdAjax, $boutiquesAutorisees, true)) $boutiqueIdAjax = '';
if ($tab === 'valorisation') {
$res = chargerValorisation($pdo, $page, $boutiqueIdAjax, $boutiquesAutorisees);
} else {
$res = chargerMouvements($pdo, $page, $boutiquesAutorisees, $boutiqueIdAjax);
}
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['table' => $res['tableHtml'], 'pagination' => $res['paginationHtml'], 'total' => $res['total']]);
exit;
}
// ==========================================================
// IMPRESSION PDF (mêmes filtres que l'onglet affiché à l'écran)
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] === 'pdf') {
while (ob_get_level() > 0) { ob_end_clean(); }

$tabPdf = $_POST['tab'] ?? 'mouvements';
// 'I' = affichage direct dans l'onglet, 'D' = téléchargement forcé (PDF uniquement).
$modePdf = (isset($_POST['mode']) && $_POST['mode'] === 'D') ? 'D' : 'I';
// 'pdf' (défaut) ou 'excel'.
$formatExport = (isset($_POST['format']) && $_POST['format'] === 'excel') ? 'excel' : 'pdf';
$boutiqueIdPdf = trim($_POST['boutique_id'] ?? '');
if ($boutiqueIdPdf !== '' && !in_array($boutiqueIdPdf, $boutiquesAutorisees, true)) $boutiqueIdPdf = '';

$boutique = null;
$boutiqueIdEntete = $boutiqueIdPdf !== '' ? $boutiqueIdPdf : ($_SESSION['boutique_id'] ?? '');
if (!empty($boutiqueIdEntete)) {
    $stmtBPdf = $pdo->prepare("SELECT * FROM boutique WHERE code_boutique = ?");
    $stmtBPdf->execute([$boutiqueIdEntete]);
    $boutique = $stmtBPdf->fetch(PDO::FETCH_ASSOC) ?: null;
}
$sousTitreBoutique = !empty($boutique['nom_boutique']) ? ('Boutique : ' . $boutique['nom_boutique']) : 'Toutes boutiques';

if ($tabPdf === 'valorisation') {
    // Même requête que chargerValorisation(), sans pagination.
    $params = [];
    $whereBoutique = empty($boutiquesAutorisees) ? " AND 1=0" : " AND s.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";
    foreach ($boutiquesAutorisees as $bid) $params[] = $bid;
    if ($boutiqueIdPdf !== '') { $whereBoutique .= " AND s.boutique_id = ?"; $params[] = $boutiqueIdPdf; }
    $sql = "SELECT s.produit_id, s.boutique_id, s.quantite,
            p.titre_produit, p.prix_fournisseur, p.prix_produit, p.etat_produit,
            cat.titre_categorie, b.nom_boutique,
            (s.quantite * p.prix_fournisseur) AS valeur_achat,
            (s.quantite * p.prix_produit) AS valeur_vente
            FROM stock s
            JOIN produit p ON s.produit_id = p.code_produit
            LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
            LEFT JOIN boutique b ON s.boutique_id = b.code_boutique
            WHERE s.quantite > 0$whereBoutique
            ORDER BY valeur_achat DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $colonnes = [
        ['titre' => 'Produit', 'largeur' => 42, 'align' => 'L'],
        ['titre' => 'Catégorie', 'largeur' => 26, 'align' => 'L'],
        ['titre' => 'Boutique', 'largeur' => 26, 'align' => 'L'],
        ['titre' => 'Qté', 'largeur' => 14, 'align' => 'C'],
        ['titre' => 'Px achat', 'largeur' => 20, 'align' => 'R'],
        ['titre' => 'Val. achat', 'largeur' => 24, 'align' => 'R'],
        ['titre' => 'Val. vente', 'largeur' => 24, 'align' => 'R'],
        ['titre' => 'État', 'largeur' => 14, 'align' => 'C'],
    ];
    $libellesEtat = ['DISPONIBLE' => 'Dispo.', 'ALERTE' => 'Alerte', 'RUPTURE' => 'Rupture'];
    $totalQte = 0; $totalValeurAchat = 0; $totalValeurVente = 0;
    foreach ($lignes as $row) {
        $totalQte += (int)$row['quantite'];
        $totalValeurAchat += (float)$row['valeur_achat'];
        $totalValeurVente += (float)$row['valeur_vente'];
    }

    if ($formatExport === 'excel') {
        $xlsx = creerExcelRapport('VALORISATION DU STOCK', $sousTitreBoutique, $boutique, count($colonnes));
        $xlsx->setColumnWidths([30, 18, 18, 10, 12, 14, 14, 12]);
        $xlsx->addHeaderRow(['Produit', 'Catégorie', 'Boutique', 'Qté', 'Px achat', 'Val. achat', 'Val. vente', 'État']);
        foreach ($lignes as $row) {
            $xlsx->addRow([
                $row['titre_produit'],
                $row['titre_categorie'] ?? '—',
                $row['nom_boutique'] ?? '—',
                (int)$row['quantite'],
                (float)$row['prix_fournisseur'],
                (float)$row['valeur_achat'],
                (float)$row['valeur_vente'],
                $libellesEtat[$row['etat_produit']] ?? $row['etat_produit'],
            ], ['L', 'L', 'L', 'C', 'R', 'R', 'R', 'C']);
        }
        $xlsx->addBlankRow();
        $xlsx->addTotalRow(['TOTAL (' . count($lignes) . ' ligne(s))', '', '', $totalQte, '', $totalValeurAchat, $totalValeurVente, ''], ['L', 'L', 'L', 'C', 'R', 'R', 'R', 'C']);
        $xlsx->output('Valorisation_stock_' . date('Ymd_His') . '.xlsx', $modePdf);
    }

    $pdf = creerPdfRapport('VALORISATION DU STOCK', $sousTitreBoutique, $boutique);
    dessinerEnteteTableauRapport($pdf, $colonnes);
    foreach ($lignes as $i => $row) {
        dessinerLigneTableauRapport($pdf, $colonnes, [
            $row['titre_produit'],
            $row['titre_categorie'] ?? '—',
            $row['nom_boutique'] ?? '—',
            (string)(int)$row['quantite'],
            fmt((float)$row['prix_fournisseur']) . ' F',
            fmt((float)$row['valeur_achat']) . ' F',
            fmt((float)$row['valeur_vente']) . ' F',
            $libellesEtat[$row['etat_produit']] ?? $row['etat_produit'],
        ], $i);
    }
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(242, 242, 242);
    $pdf->Cell(96, 7, 'TOTAL (' . count($lignes) . ' ligne(s))', 0, 0, 'L', true);
    $pdf->Cell(14, 7, (string)$totalQte, 0, 0, 'C', true);
    $pdf->Cell(20, 7, '', 0, 0, 'L', true);
    $pdf->Cell(24, 7, fmt($totalValeurAchat) . ' F', 0, 0, 'R', true);
    $pdf->Cell(24, 7, fmt($totalValeurVente) . ' F', 0, 0, 'R', true);
    $pdf->Cell(14, 7, '', 0, 1, 'L', true);
    $pdf->Output($modePdf, 'Valorisation_stock_' . date('Ymd_His') . '.pdf');
    exit;
}

// Onglet "mouvements" (même requête que chargerMouvements(), sans pagination).
$whereBoutiqueMvt = empty($boutiquesAutorisees) ? " AND 1=0" : " AND c.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";
$paramsMvt = $boutiquesAutorisees;
if ($boutiqueIdPdf !== '') { $whereBoutiqueMvt .= " AND c.boutique_id = ?"; $paramsMvt[] = $boutiqueIdPdf; }
$sql = "SELECT c.numero_commande, c.date_commande, c.quantite_commande, c.statut_id, c.etat_commande,
        p.titre_produit, b.nom_boutique,
        CASE WHEN c.statut_id IN ('011','009','010','006') THEN 'ENTRÉE' ELSE 'SORTIE' END AS type_mvt
        FROM commande c
        JOIN produit p ON c.produit_id = p.code_produit
        LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
        WHERE c.etat_commande NOT IN ('En attente','Annulé')$whereBoutiqueMvt
        ORDER BY c.date_commande DESC, c.heure_commande DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($paramsMvt);
$lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$colonnes = [
    ['titre' => 'N° Mouvement', 'largeur' => 32, 'align' => 'L'],
    ['titre' => 'Date', 'largeur' => 22, 'align' => 'C'],
    ['titre' => 'Produit', 'largeur' => 48, 'align' => 'L'],
    ['titre' => 'Boutique', 'largeur' => 30, 'align' => 'L'],
    ['titre' => 'Qté', 'largeur' => 15, 'align' => 'C'],
    ['titre' => 'Type', 'largeur' => 20, 'align' => 'C'],
    ['titre' => 'État', 'largeur' => 23, 'align' => 'C'],
];
$totalEntrees = 0; $totalSorties = 0;
foreach ($lignes as $row) {
    $qte = (int)$row['quantite_commande'];
    if ($row['type_mvt'] === 'ENTRÉE') { $totalEntrees += $qte; } else { $totalSorties += $qte; }
}

if ($formatExport === 'excel') {
    $xlsx = creerExcelRapport('MOUVEMENTS DE STOCK', $sousTitreBoutique, $boutique, count($colonnes));
    $xlsx->setColumnWidths([20, 14, 32, 20, 10, 12, 16]);
    $xlsx->addHeaderRow(['N° Mouvement', 'Date', 'Produit', 'Boutique', 'Qté', 'Type', 'État']);
    foreach ($lignes as $row) {
        $qte = (int)$row['quantite_commande'];
        $xlsx->addRow([
            $row['numero_commande'],
            date('d/m/Y', strtotime($row['date_commande'])),
            $row['titre_produit'],
            $row['nom_boutique'] ?? '—',
            $qte,
            $row['type_mvt'],
            $row['etat_commande'],
        ], ['L', 'C', 'L', 'L', 'C', 'C', 'C']);
    }
    $xlsx->addBlankRow();
    $xlsx->addTotalRow(['TOTAL (' . count($lignes) . ' mouvement(s))', '', '', '', '', 'E:' . $totalEntrees . ' / S:' . $totalSorties, ''], ['L', 'C', 'L', 'L', 'C', 'C', 'C']);
    $xlsx->output('Mouvements_stock_' . date('Ymd_His') . '.xlsx', $modePdf);
}

$pdf = creerPdfRapport('MOUVEMENTS DE STOCK', $sousTitreBoutique, $boutique);
dessinerEnteteTableauRapport($pdf, $colonnes);
foreach ($lignes as $i => $row) {
    $qte = (int)$row['quantite_commande'];
    dessinerLigneTableauRapport($pdf, $colonnes, [
        $row['numero_commande'],
        date('d/m/Y', strtotime($row['date_commande'])),
        $row['titre_produit'],
        $row['nom_boutique'] ?? '—',
        (string)$qte,
        $row['type_mvt'],
        $row['etat_commande'],
    ], $i);
}
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(242, 242, 242);
$pdf->Cell(102, 7, 'TOTAL (' . count($lignes) . ' mouvement(s))', 0, 0, 'L', true);
$pdf->Cell(15, 7, '', 0, 0, 'L', true);
$pdf->Cell(20, 7, 'E:' . $totalEntrees . ' / S:' . $totalSorties, 0, 0, 'C', true);
$pdf->Cell(23, 7, '', 0, 1, 'L', true);
$pdf->Output($modePdf, 'Mouvements_stock_' . date('Ymd_His') . '.pdf');
exit;
}
// ==========================================================
// CHARGEMENT INITIAL
// ==========================================================
$resMouvements = chargerMouvements($pdo, 1, $boutiquesAutorisees, $boutiqueId);
$resValorisation = chargerValorisation($pdo, 1, $boutiqueId, $boutiquesAutorisees);
// Graphique : entrées/sorties mensuelles (12 derniers mois)
if (empty($boutiquesAutorisees)) {
    $mouvementsMois = [];
} else {
    $inPhMois = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $stmtMois = $pdo->prepare("SELECT DATE_FORMAT(date_commande,'%Y-%m') AS mois,
SUM(CASE WHEN statut_id IN ('011','009','010','006') THEN quantite_commande ELSE 0 END) AS entrees,
SUM(CASE WHEN statut_id IN ('012','008','007','001','002','003','004') THEN quantite_commande ELSE 0 END) AS sorties
FROM commande WHERE etat_commande NOT IN ('En attente','Annulé') AND date_commande >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND boutique_id IN ($inPhMois)
GROUP BY mois ORDER BY mois ASC");
    $stmtMois->execute($boutiquesAutorisees);
    $mouvementsMois = $stmtMois->fetchAll(PDO::FETCH_ASSOC);
}
// Stats de valorisation globale
$paramsVal = $boutiquesAutorisees;
$whereBoutiqueVal = empty($boutiquesAutorisees) ? " AND 1=0" : " AND s.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";
if ($boutiqueId !== '') { $whereBoutiqueVal .= " AND s.boutique_id = ?"; $paramsVal[] = $boutiqueId; }
$stmt = $pdo->prepare("SELECT COALESCE(SUM(s.quantite * p.prix_fournisseur),0) AS valeur_achat,
COALESCE(SUM(s.quantite * p.prix_produit),0) AS valeur_vente,
COALESCE(SUM(s.quantite),0) AS qte_totale,
COUNT(DISTINCT s.produit_id) AS nb_produits
FROM stock s JOIN produit p ON s.produit_id = p.code_produit
WHERE s.quantite > 0$whereBoutiqueVal");
$stmt->execute($paramsVal);
$valGlobale = $stmt->fetch(PDO::FETCH_ASSOC);
$valeurAchatTotale = (float)$valGlobale['valeur_achat'];
$valeurVenteTotale = (float)$valGlobale['valeur_vente'];
$margePotentielle = $valeurVenteTotale - $valeurAchatTotale;
$nbProduits = (int)$valGlobale['nb_produits'];
// Répartition de la valeur du stock par catégorie
$stmt = $pdo->prepare("SELECT cat.titre_categorie, COALESCE(SUM(s.quantite * p.prix_fournisseur),0) AS valeur
FROM stock s JOIN produit p ON s.produit_id = p.code_produit
LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
WHERE s.quantite > 0$whereBoutiqueVal
GROUP BY cat.code_categorie ORDER BY valeur DESC");
$stmt->execute($paramsVal);
$valeurParCategorie = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Produits en rupture / alerte
if (empty($boutiquesAutorisees)) {
    $nbRupture = 0; $nbAlerte = 0;
} else {
    $inPhKpi = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $stmtRupt = $pdo->prepare("SELECT COUNT(*) FROM stock s WHERE s.boutique_id IN ($inPhKpi) AND s.quantite <= 0");
    $stmtRupt->execute($boutiquesAutorisees);
    $nbRupture = (int)$stmtRupt->fetchColumn();
    $stmtAlerte = $pdo->prepare("SELECT COUNT(*) FROM stock s WHERE s.boutique_id IN ($inPhKpi) AND s.quantite > 0 AND s.quantite <= s.stock_alerte");
    $stmtAlerte->execute($boutiquesAutorisees);
    $nbAlerte = (int)$stmtAlerte->fetchColumn();
}
$onglet = $_GET['onglet'] ?? 'mouvements';
if (!in_array($onglet, ['mouvements', 'valorisation'], true)) $onglet = 'mouvements';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapport Stock</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
/* ===== STATS ===== */
.stat-card {
background: var(--bg-surface); border: 1px solid var(--border-color);
border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base);
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }
/* ===== FILTRES ===== */
.filters-section {
background: var(--bg-surface);
border: 1px solid var(--border-color);
border-radius: var(--radius-sm);
padding: 16px;
margin-bottom: 20px;
box-shadow: var(--shadow-sm);
}
.filters-section label {
font-size: 10px;
font-weight: 700;
text-transform: uppercase;
color: var(--text-tertiary);
letter-spacing: 0.5px;
margin-bottom: 4px;
display: flex;
align-items: center;
gap: 4px;
}
.filters-section .form-select {
border: 1.5px solid var(--border-color);
border-radius: 8px;
font-size: 13px;
padding: 8px 12px;
background: #fff;
color: var(--text-primary);
}
.filters-section .form-select:focus {
border-color: var(--color-primary);
box-shadow: 0 0 0 3px var(--color-primary-soft);
}
.btn-filter {
background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
color: #fff;
border: none;
padding: 10px 20px;
border-radius: 8px;
font-weight: 600;
font-size: 13px;
display: inline-flex;
align-items: center;
gap: 6px;
transition: all 0.25s;
box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}
.btn-filter:hover {
transform: translateY(-2px);
box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
}
.btn-reset {
background: var(--color-gray-100);
color: var(--text-secondary);
border: 1px solid var(--border-color);
padding: 10px 20px;
border-radius: 8px;
font-weight: 600;
font-size: 13px;
display: inline-flex;
align-items: center;
gap: 6px;
transition: all 0.2s;
text-decoration: none;
}
.btn-reset:hover {
background: var(--color-gray-200);
color: var(--text-primary);
}
/* ===== ONGLETS ===== */
.nav-tabs {
border-bottom: 2px solid var(--border-color);
margin-bottom: 20px;
flex-wrap: wrap;
gap: 4px;
}
.nav-tabs .nav-link {
border: none;
color: var(--text-tertiary);
font-weight: 600;
font-size: 13px;
padding: 12px 18px;
border-radius: 8px 8px 0 0;
transition: all 0.2s;
display: flex;
align-items: center;
gap: 6px;
}
.nav-tabs .nav-link:hover {
color: var(--color-primary);
background: var(--color-primary-soft);
}
.nav-tabs .nav-link.active {
color: var(--color-primary);
background: var(--color-primary-soft);
border-bottom: 2px solid var(--color-primary);
margin-bottom: -2px;
}
/* ===== CHART CARDS ===== */
.chart-card {
background: var(--bg-surface);
border: 1px solid var(--border-color);
border-radius: var(--radius-md);
padding: 20px;
margin-bottom: 20px;
box-shadow: var(--shadow-sm);
transition: var(--transition-base);
}
.chart-card:hover {
box-shadow: var(--shadow-md);
border-color: var(--color-gray-300);
}
.chart-card h4 {
font-size: 12px;
font-weight: 700;
text-transform: uppercase;
letter-spacing: 0.8px;
color: var(--text-tertiary);
margin-bottom: 16px;
display: flex;
align-items: center;
gap: 8px;
}
.chart-card h4 i { font-size: 16px; }
/* ===== REPORT CARDS ===== */
.report-card {
background: var(--bg-surface);
border: 1px solid var(--border-color);
border-radius: var(--radius-md);
padding: 20px 24px;
margin-bottom: 20px;
box-shadow: var(--shadow-sm);
transition: var(--transition-base);
}
.report-card:hover {
box-shadow: var(--shadow-md);
border-color: var(--color-gray-300);
}
.report-card h3 {
font-size: 14px;
font-weight: 700;
color: var(--text-primary);
border-bottom: 2px solid var(--color-gray-100);
padding-bottom: 12px;
margin-bottom: 16px;
display: flex;
align-items: center;
gap: 8px;
}
.report-card h3 i {
font-size: 18px;
color: var(--color-primary);
}
/* ===== TABLES ===== */
.table-wrapper {
overflow-x: auto;
border-radius: 10px;
border: 1px solid var(--border-color);
background: var(--bg-surface);
}
table {
width: 100%;
border-collapse: separate;
border-spacing: 0;
font-size: 13px;
}
thead {
background: linear-gradient(135deg, var(--color-gray-50) 0%, var(--color-gray-100) 100%);
}
th {
padding: 12px 14px;
text-align: left;
font-weight: 700;
font-size: 10px;
text-transform: uppercase;
letter-spacing: 0.8px;
color: var(--text-tertiary);
border-bottom: 2px solid var(--border-color);
}
td {
padding: 12px 14px;
border-bottom: 1px solid var(--color-gray-100);
color: var(--text-primary);
vertical-align: middle;
}
tbody tr { transition: background 0.15s; }
tbody tr:hover { background: var(--color-primary-soft); }
tbody tr:last-child td { border-bottom: none; }
.empty-cell {
text-align: center;
padding: 40px 20px;
color: var(--text-tertiary);
}
/* ===== PAGINATION ===== */
.pagination { gap: 4px; }
.pagination .page-link {
color: var(--color-primary);
border: 1px solid var(--border-color);
border-radius: 6px;
padding: 6px 12px;
font-size: 12px;
font-weight: 600;
transition: all 0.2s;
}
.pagination .page-link:hover {
background: var(--color-primary-soft);
border-color: var(--color-primary);
color: var(--color-primary-dark);
}
.pagination .page-item.active .page-link {
background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
border-color: var(--color-primary);
color: #fff;
box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
}
.pagination .page-item.disabled .page-link {
color: var(--text-tertiary);
background: var(--color-gray-50);
}
/* ===== BADGES ===== */
.badge-chic {
display: inline-flex;
align-items: center;
gap: 6px;
padding: 5px 12px;
border-radius: 999px;
font-size: 10px;
font-weight: 700;
text-transform: uppercase;
letter-spacing: 0.5px;
box-shadow: 0 2px 4px rgba(0,0,0,0.06);
}
.badge-chic .dot {
width: 6px;
height: 6px;
border-radius: 50%;
background: currentColor;
animation: pulse 2s infinite;
}
@keyframes pulse {
0%, 100% { opacity: 1; }
50% { opacity: 0.5; }
}
/* ===== SELECTPICKER ===== */
.bootstrap-select .dropdown-toggle {
background: #fff !important;
border: 1.5px solid var(--border-color) !important;
border-radius: 8px !important;
font-size: 13px;
padding: 8px 12px;
}
.bootstrap-select .dropdown-toggle:focus {
border-color: var(--color-primary) !important;
box-shadow: 0 0 0 3px var(--color-primary-soft) !important;
}
.bootstrap-select .dropdown-menu {
border-radius: 8px;
border-color: var(--border-color);
box-shadow: var(--shadow-lg);
}
/* ===== ANIMATIONS ===== */
@keyframes fadeUp {
from { opacity: 0; transform: translateY(12px); }
to { opacity: 1; transform: translateY(0); }
}
.chart-card, .report-card, .stat-card {
animation: fadeUp 0.4s ease both;
}
@media (max-width: 768px) {
.filters-section .d-flex {
flex-direction: column;
align-items: stretch;
}
.bootstrap-select, .bootstrap-select .dropdown-toggle {
width: 100% !important;
}
}
</style>
</head>
<body>
<div class="W">
<!-- En-tête -->
<div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
<div>
<h1 class="h3 fw-bold mb-1"><i class="bi bi-boxes text-primary me-2"></i>Rapport Stock</h1>
<p class="text-muted small mb-0">Mouvements et valorisation du stock actuel</p>
</div>
<span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
<i class="bi bi-cash-coin"></i> Valeur : <?= fmt($valeurAchatTotale) ?> F
</span>
</div>
<!-- Statistiques -->
<div class="row g-3 mb-4">
<?php
$stats = [
['primary', 'cash-coin', 'Stock (achat)', fmt($valeurAchatTotale) . ' F', ''],
['success', 'graph-up', 'Stock (vente)', fmt($valeurVenteTotale) . ' F', ''],
['purple', 'piggy-bank', 'Marge potentielle', fmt($margePotentielle) . ' F', ''],
['info', 'box-seam', 'Produits en stock', $nbProduits, ''],
['warning', 'exclamation-triangle', 'En alerte', $nbAlerte, ''],
['danger', 'x-circle', 'En rupture', $nbRupture, ''],
];
$colorMap = [
'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
'success' => ['var(--color-success-soft)', 'var(--color-success)'],
'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
'danger'  => ['var(--color-danger-soft)', 'var(--color-danger)'],
'purple'  => ['var(--color-purple-soft)', 'var(--color-purple)'],
'info'    => ['var(--color-info-soft)', 'var(--color-info)'],
];
foreach ($stats as $s):
$bg = $colorMap[$s[0]][0];
$fg = $colorMap[$s[0]][1];
?>
<div class="col-6 col-md-4 col-xl-2">
<div class="stat-card d-flex align-items-center gap-3 h-100">
<div class="stat-icon" style="background: <?= $bg ?>; color: <?= $fg ?>;">
<i class="bi bi-<?= $s[1] ?>"></i>
</div>
<div class="flex-grow-1 overflow-hidden">
<div class="stat-label"><?= $s[2] ?></div>
<div class="stat-value text-truncate"><?= $s[3] ?><?php if ($s[4]): ?><small class="text-muted ms-1" style="font-size:11px;"><?= $s[4] ?></small><?php endif; ?></div>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<!-- Filtres -->
<div class="filters-section">
<form method="GET" id="filterFormMain">
<input type="hidden" name="onglet" id="ongletInput" value="<?= e($onglet) ?>">
<div class="d-flex flex-wrap align-items-end gap-3">
<div class="flex-grow-1" style="min-width: 220px;">
<label for="boutiqueSelect"><i class="bi bi-shop"></i> Boutique</label>
<select name="boutique_id" id="boutiqueSelect" class="form-select selectpicker" data-live-search="true">
<option value="">Toutes les boutiques</option>
<?php foreach ($boutiques as $b): ?>
<option value="<?= e($b['code_boutique']) ?>" <?= $boutiqueId==$b['code_boutique']?'selected':'' ?>><?= e($b['nom_boutique']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="d-flex gap-2">
<button type="submit" class="btn-filter"><i class="bi bi-funnel"></i> Filtrer</button>
<a href="?onglet=<?= e($onglet) ?>" class="btn-reset"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</a>
<button type="button" class="btn-reset" id="printStockBtn" data-mode="I"><i class="bi bi-printer"></i> Imprimer</button>
<button type="button" class="btn-reset" id="downloadStockBtn" data-mode="D"><i class="bi bi-download"></i> Télécharger</button>
<button type="button" class="btn-reset" id="excelStockBtn"><i class="bi bi-file-earmark-excel"></i> Excel</button>
</div>
</div>
</form>
</div>
<!-- Graphiques -->
<div class="row g-3 mb-4">
<div class="col-lg-6">
<div class="chart-card">
<h4><i class="bi bi-bar-chart"></i> Entrées / Sorties mensuelles (12 mois)</h4>
<canvas id="chartMouvements" height="120"></canvas>
</div>
</div>
<div class="col-lg-6">
<div class="chart-card">
<h4><i class="bi bi-pie-chart"></i> Valeur du stock par catégorie (prix d'achat)</h4>
<canvas id="chartValorisation" height="120"></canvas>
</div>
</div>
</div>
<!-- Onglets -->
<ul class="nav nav-tabs" id="stockTabs" role="tablist">
<li class="nav-item">
<button class="nav-link <?= $onglet=='mouvements'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-mouvements" type="button" data-tab="mouvements">
<i class="bi bi-arrow-left-right"></i> Mouvements de stock
</button>
</li>
<li class="nav-item">
<button class="nav-link <?= $onglet=='valorisation'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-valorisation" type="button" data-tab="valorisation">
<i class="bi bi-cash-stack"></i> Valorisation
</button>
</li>
</ul>
<div class="tab-content">
<!-- ONGLET 1 : MOUVEMENTS -->
<div class="tab-pane fade <?= $onglet=='mouvements'?'show active':'' ?>" id="pane-mouvements">
<div class="report-card">
<h3><i class="bi bi-arrow-left-right"></i> Mouvements de stock <span class="text-muted small ms-2"><?= $resMouvements['total'] ?> lignes</span></h3>
<div class="table-wrapper">
<table>
<thead>
<tr>
<th>N° Commande</th>
<th>Date</th>
<th>Produit</th>
<th>Boutique</th>
<th class="text-center">Quantité</th>
<th class="text-center">Type</th>
<th class="text-center">État</th>
</tr>
</thead>
<tbody id="tbody-mouvements"><?= $resMouvements['tableHtml'] ?></tbody>
</table>
</div>
<div id="pagination-mouvements"><?= $resMouvements['paginationHtml'] ?></div>
</div>
</div>
<!-- ONGLET 2 : VALORISATION -->
<div class="tab-pane fade <?= $onglet=='valorisation'?'show active':'' ?>" id="pane-valorisation">
<div class="report-card">
<h3><i class="bi bi-cash-stack"></i> Valorisation par produit <span class="text-muted small ms-2"><?= $resValorisation['total'] ?> lignes</span></h3>
<div class="table-wrapper">
<table>
<thead>
<tr>
<th>Produit</th>
<th>Catégorie</th>
<th>Boutique</th>
<th class="text-center">Qté en stock</th>
<th class="text-end">Prix achat unit.</th>
<th class="text-end">Valeur (achat)</th>
<th class="text-end">Valeur (vente)</th>
<th class="text-center">État</th>
</tr>
</thead>
<tbody id="tbody-valorisation"><?= $resValorisation['tableHtml'] ?></tbody>
</table>
</div>
<div id="pagination-valorisation"><?= $resValorisation['paginationHtml'] ?></div>
</div>
</div>
</div>
</div>
<!-- Formulaire caché pour la pagination AJAX -->
<form id="filterForm" style="display:none;">
<input type="hidden" name="boutique_id" value="<?= e($boutiqueId) ?>">
</form>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function () {
$('.selectpicker').selectpicker('destroy');
$('.selectpicker').selectpicker();
// Synchroniser le formulaire caché avec les filtres visibles
function syncFilterForm() {
$('#filterForm input[name="boutique_id"]').val($('#boutiqueSelect').val());
}
$('#boutiqueSelect').on('changed.bs.select', syncFilterForm);
// Mémoriser l'onglet actif dans l'URL
$('#stockTabs button').on('shown.bs.tab', function (e) {
var tab = $(e.target).data('tab');
$('#ongletInput').val(tab);
var url = new URL(window.location.href);
url.searchParams.set('onglet', tab);
history.replaceState(null, '', url);
});
// --- Graphiques ---
var ctxMvt = document.getElementById('chartMouvements')?.getContext('2d');
if (ctxMvt) {
var data = <?= json_encode($mouvementsMois) ?>;
new Chart(ctxMvt, {
type: 'bar',
data: {
labels: data.map(d => d.mois),
datasets: [
{
label: 'Entrées',
data: data.map(d => parseInt(d.entrees) || 0),
backgroundColor: 'rgba(16, 185, 129, 0.85)',
borderRadius: 6
},
{
label: 'Sorties',
data: data.map(d => parseInt(d.sorties) || 0),
backgroundColor: 'rgba(239, 68, 68, 0.85)',
borderRadius: 6
}
]
},
options: {
plugins: { legend: { position: 'bottom' } },
scales: {
y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1 } },
x: { grid: { display: false } }
}
}
});
}
var ctxValo = document.getElementById('chartValorisation')?.getContext('2d');
if (ctxValo) {
new Chart(ctxValo, {
type: 'doughnut',
data: {
labels: <?= json_encode(array_map(fn($r) => $r['titre_categorie'] ?? 'Sans catégorie', $valeurParCategorie)) ?>,
datasets: [{
data: <?= json_encode(array_map(fn($r) => floatval($r['valeur']), $valeurParCategorie)) ?>,
backgroundColor: ['#4f46e5','#10b981','#f59e0b','#ef4444','#8b5cf6','#0891b2','#ec4899','#65a30d']
}]
},
options: {
plugins: { legend: { position: 'right' } }
}
});
}
// --- Pagination AJAX par onglet ---
function chargerPage(tab, page) {
syncFilterForm();
var data = $('#filterForm').serialize() + '&ajax=1&tab=' + tab + '&page=' + page;
$.post(window.location.pathname, data, function (res) {
$('#tbody-' + tab).html(res.table);
$('#pagination-' + tab).html(res.pagination);
}, 'json');
}
$('.tab-content').on('click', '.pagination .page-link', function (e) {
e.preventDefault();
var page = $(this).data('page');
if (!page || $(this).closest('li').hasClass('disabled')) return;
var tab = $(this).closest('.tab-pane').attr('id').replace('pane-', '');
chargerPage(tab, page);
});

// ---- Impression / téléchargement PDF (onglet actif + boutique filtrée) ----
function imprimerMouvementStock(mode, format) {
    var tab = $('#stockTabs .nav-link.active').data('tab') || 'mouvements';
    var data = {
        action: 'pdf',
        mode: mode || 'I',
        format: format || 'pdf',
        tab: tab,
        boutique_id: $('#boutiqueSelect').val() || ''
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
$('#printStockBtn').on('click', function() { imprimerMouvementStock('I', 'pdf'); });
$('#downloadStockBtn').on('click', function() { imprimerMouvementStock('D', 'pdf'); });
$('#excelStockBtn').on('click', function() { imprimerMouvementStock('D', 'excel'); });
});
</script>
</body>
</html>