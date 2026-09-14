<?php
// fonctions_rapport.php
require 'databases/database.php';

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}

function fmtDec($n) {
    return number_format(floatval($n), 2, ',', ' ');
}

// ============================================================
// GÉNÉRATION PDF DES RAPPORTS (mêmes codes couleur/police que le
// modèle déjà utilisé pour le bon de livraison — voir
// views/facture/bon_livraison.php) — centralisé ici pour être
// partagé par rapport_financier.php, historique_produit.php et
// mouvement_stock.php sans dupliquer la classe FPDF dans chacun.
// ============================================================
if (!class_exists('RapportPDF')) {
    require_once 'librairies/fpdf/fpdf.php';
    require_once 'librairies/xlsxwriter/XLSXWriter.php';

    class RapportPDF extends FPDF {
        function Header() {}
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(140, 140, 140);
            $this->Cell(0, 8, $this->txt('Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
        }
        private function txt($s) {
            $s = (string)$s;
            $conv = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
            return $conv !== false ? $conv : $s;
        }
        function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '') {
            parent::Cell($w, $h, $this->txt($txt), $border, $ln, $align, $fill, $link);
        }
        function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false) {
            parent::MultiCell($w, $h, $this->txt($txt), $border, $align, $fill);
        }
    }
}

/**
 * Prépare une nouvelle page RapportPDF (portrait A4) avec l'en-tête standard
 * (bandeau bleu marine + logo/coordonnées boutique si disponibles), au même
 * style que le bon de livraison. Retourne l'objet $pdf prêt à recevoir le
 * tableau du rapport.
 *
 * @param string     $titre     Titre principal (ex. "RAPPORT FINANCIER")
 * @param string     $sousTitre Ligne secondaire (ex. "Factures clients — Impayées")
 * @param array|null $boutique  Ligne de la table boutique (nom_boutique, adresse_boutique, ...) ou null
 */
function creerPdfRapport($titre, $sousTitre, $boutique = null) {
    $pdf = new RapportPDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(10, 10, 10);

    $navy = [21, 61, 122];

    $logoTmpPath = null;
    if (!empty($boutique['logo'])) {
        $mime = $boutique['type_logo'] ?? 'image/png';
        $ext = 'png';
        if (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) $ext = 'jpg';
        elseif (strpos($mime, 'gif') !== false) $ext = 'gif';
        $logoTmpPath = sys_get_temp_dir() . '/logo_rapport_' . uniqid() . '.' . $ext;
        file_put_contents($logoTmpPath, $boutique['logo']);
    }

    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->SetXY(10, 12);
    $pdf->Cell(130, 10, $titre, 0, 1, 'L');
    if ($logoTmpPath) {
        $pdf->Image($logoTmpPath, 165, 8, 35);
        unlink($logoTmpPath);
    }
    $pdf->SetTextColor(90, 90, 90);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetX(10);
    $pdf->Cell(130, 6, $sousTitre, 0, 1, 'L');

    if (!empty($boutique['nom_boutique'])) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetX(10);
        $pdf->Cell(130, 5, $boutique['nom_boutique'], 0, 1, 'L');
    }
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetX(10);
    $pdf->Cell(130, 5, 'Édité le ' . date('d/m/Y à H:i'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    return $pdf;
}

/**
 * Équivalent Excel de creerPdfRapport() : prépare un classeur XLSXWriter
 * avec le même bandeau titre/sous-titre/boutique/date que le PDF, pour que
 * les deux exports restent visuellement cohérents. Le tableau de données
 * (en-têtes, lignes, total) est ajouté ensuite par l'appelant.
 *
 * @param string     $titre       Titre principal (ex. "RAPPORT FINANCIER")
 * @param string     $sousTitre   Ligne secondaire (ex. "Factures clients — Impayées")
 * @param array|null $boutique    Ligne de la table boutique, ou null
 * @param int        $nbColonnes  Nombre de colonnes du tableau qui suivra (pour la fusion du titre)
 */
function creerExcelRapport($titre, $sousTitre, $boutique = null, $nbColonnes = 5) {
    $nomFeuille = $boutique['nom_boutique'] ?? $titre;
    $xlsx = new XLSXWriter($nomFeuille);
    $xlsx->addTitleRow($titre, $nbColonnes);
    $sousTitreComplet = $sousTitre;
    if (!empty($boutique['nom_boutique'])) {
        $sousTitreComplet .= '  —  ' . $boutique['nom_boutique'];
    }
    $sousTitreComplet .= '  —  Édité le ' . date('d/m/Y à H:i');
    $xlsx->addSubtitleRow($sousTitreComplet, $nbColonnes);
    $xlsx->addBlankRow();
    return $xlsx;
}

/**
 * Dessine l'en-tête (bandeau bleu marine) d'un tableau de rapport, avec les
 * largeurs de colonnes fournies, puis positionne le curseur pour les lignes.
 *
 * @param RapportPDF $pdf
 * @param array      $colonnes Liste de ['titre' => ..., 'largeur' => ..., 'align' => 'L'|'C'|'R']
 */
function dessinerEnteteTableauRapport($pdf, array $colonnes) {
    $navy = [21, 61, 122];
    $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    foreach ($colonnes as $i => $col) {
        $dernier = ($i === array_key_last($colonnes));
        $pdf->Cell($col['largeur'], 7, $col['titre'], 0, $dernier ? 1 : 0, 'C', true);
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 8);
}

/**
 * Dessine une ligne de tableau de rapport (alternance de fond légère toutes
 * les 2 lignes pour la lisibilité), avec gestion du saut de page automatique
 * en réaffichant l'en-tête sur la nouvelle page.
 *
 * @param RapportPDF $pdf
 * @param array      $colonnes Même structure que dessinerEnteteTableauRapport
 * @param array      $valeurs  Valeurs déjà formatées, dans le même ordre que $colonnes
 * @param int        $index    Index de ligne (pour l'alternance de fond)
 */
function dessinerLigneTableauRapport($pdf, array $colonnes, array $valeurs, int $index) {
    if ($pdf->GetY() > 275) {
        $pdf->AddPage();
        dessinerEnteteTableauRapport($pdf, $colonnes);
    }
    $fill = ($index % 2 === 1);
    if ($fill) { $pdf->SetFillColor(245, 247, 250); } else { $pdf->SetFillColor(255, 255, 255); }
    foreach ($colonnes as $i => $col) {
        $dernier = ($i === array_key_last($colonnes));
        $pdf->Cell($col['largeur'], 6, $valeurs[$i] ?? '', 0, $dernier ? 1 : 0, $col['align'] ?? 'L', true);
    }
}

/**
 * Génère un tableau paginé avec AJAX
 */
function renderPaginatedTable($pdo, $sql, $countSql, $params, $page, $perPage = 20, $rowCallback = null) {
    $page = max(1, (int)$page);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $offset = ($page - 1) * $perPage;
    $sql .= " LIMIT $offset, $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($rows)) {
        echo '<tr><td colspan="99" class="empty-cell">Aucune donnée.</td></tr>';
    } else {
        if ($rowCallback) {
            foreach ($rows as $row) {
                echo $rowCallback($row);
            }
        } else {
            // fallback : affichage simple des colonnes (on prend les clés de la première ligne)
            $cols = array_keys($rows[0] ?? []);
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($cols as $col) {
                    echo '<td>'.e($row[$col] ?? '').'</td>';
                }
                echo '</tr>';
            }
        }
    }
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
            <span class="text-muted small">Affichage de <?= ($offset+1) ?> à <?= min($page*$perPage, $total) ?> sur <?= $total ?></span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $page-1 ?>"><i class="bi bi-chevron-left"></i></a>
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
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="'.$totalPages.'">'.$totalPages.'</a></li>';
                    }
                    ?>
                    <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $page+1 ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif;
    $paginationHtml = ob_get_clean();

    return [
        'table'   => $tableHtml,
        'pagination' => $paginationHtml,
        'total'   => $total,
        'page'    => $page,
        'totalPages' => $totalPages
    ];
}