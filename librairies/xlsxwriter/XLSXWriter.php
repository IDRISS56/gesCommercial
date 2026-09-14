<?php
/**
 * librairies/xlsxwriter/XLSXWriter.php
 *
 * Générateur minimaliste de fichiers .xlsx, sans dépendance externe (juste
 * l'extension PHP ZipArchive, activée par défaut sur la quasi-totalité des
 * hébergements). Écrit sur le même principe que FPDF déjà utilisé dans ce
 * projet pour les PDF : une seule classe, un seul fichier, à inclure
 * directement.
 *
 * Usage :
 *   $xlsx = new XLSXWriter('Nom de la feuille');
 *   $xlsx->setColumnWidths([25, 40, 15, 15]);
 *   $xlsx->addTitleRow('RAPPORT FINANCIER', 4);
 *   $xlsx->addSubtitleRow('Factures clients — Impayées', 4);
 *   $xlsx->addBlankRow();
 *   $xlsx->addHeaderRow(['N° Facture', 'Client', 'Montant', 'État']);
 *   foreach ($lignes as $l) {
 *       $xlsx->addRow([$l['numero'], $l['client'], $l['montant'], $l['etat']], ['L','L','R','C']);
 *   }
 *   $xlsx->addBlankRow();
 *   $xlsx->addTotalRow(['TOTAL', '', $total, ''], ['L','L','R','C']);
 *   $xlsx->output('Rapport.xlsx', 'I'); // 'I' = affichage/téléchargement direct, 'D' = forcé en pièce jointe
 *
 * Toutes les colonnes sont "bien espacées" comme les PDF : largeurs de
 * colonnes définies explicitement, en-têtes en gras avec fond bleu marine
 * (mêmes couleurs que les PDF), ligne de total grisée, alignement par
 * colonne (L = gauche, C = centre, R = droite).
 */

if (!class_exists('XLSXWriter')) {
    class XLSXWriter
    {
        private string $sheetName;
        private array $rows = [];       // chaque ligne : ['cells' => [...], 'aligns' => [...], 'style' => 'title'|'subtitle'|'header'|'normal'|'total']
        private array $colWidths = [];  // largeurs de colonnes (en "caractères", unité Excel standard)
        private int $nbColonnesFusionTitre = 1;

        public function __construct(string $sheetName = 'Feuille1')
        {
            // Excel limite les noms de feuille à 31 caractères et interdit
            // certains caractères spéciaux.
            $sheetName = preg_replace('/[\\\\\/\?\*\[\]\:]/', ' ', $sheetName);
            $this->sheetName = mb_substr($sheetName, 0, 31);
        }

        public function setColumnWidths(array $widths): void
        {
            $this->colWidths = $widths;
        }

        public function addTitleRow(string $texte, int $nbColonnesFusion = 1): void
        {
            $this->nbColonnesFusionTitre = max($this->nbColonnesFusionTitre, $nbColonnesFusion);
            $this->rows[] = ['cells' => [$texte], 'aligns' => ['L'], 'style' => 'title', 'merge' => $nbColonnesFusion];
        }

        public function addSubtitleRow(string $texte, int $nbColonnesFusion = 1): void
        {
            $this->rows[] = ['cells' => [$texte], 'aligns' => ['L'], 'style' => 'subtitle', 'merge' => $nbColonnesFusion];
        }

        public function addBlankRow(): void
        {
            $this->rows[] = ['cells' => [], 'aligns' => [], 'style' => 'normal'];
        }

        public function addHeaderRow(array $cells): void
        {
            $this->rows[] = ['cells' => $cells, 'aligns' => array_fill(0, count($cells), 'C'), 'style' => 'header'];
        }

        /**
         * @param array $cells  Valeurs de la ligne (texte ou nombre).
         * @param array $aligns Alignement par colonne : 'L', 'C' ou 'R'. Par défaut 'L' partout.
         */
        public function addRow(array $cells, array $aligns = []): void
        {
            if (empty($aligns)) $aligns = array_fill(0, count($cells), 'L');
            $this->rows[] = ['cells' => $cells, 'aligns' => $aligns, 'style' => 'normal'];
        }

        public function addTotalRow(array $cells, array $aligns = []): void
        {
            if (empty($aligns)) $aligns = array_fill(0, count($cells), 'L');
            $this->rows[] = ['cells' => $cells, 'aligns' => $aligns, 'style' => 'total'];
        }

        private function colLettre(int $index): string
        {
            // 0 -> A, 1 -> B, ... 25 -> Z, 26 -> AA, ...
            $lettre = '';
            $index++;
            while ($index > 0) {
                $mod = ($index - 1) % 26;
                $lettre = chr(65 + $mod) . $lettre;
                $index = intdiv($index - 1, 26);
            }
            return $lettre;
        }

        private function xmlEscape(string $s): string
        {
            return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        // Index de style (cellXfs, voir buildStylesXml) selon style de ligne + alignement.
        private function styleIndex(string $style, string $align): int
        {
            $alignIdx = ['L' => 0, 'C' => 1, 'R' => 2][$align] ?? 0;
            $base = ['title' => 10, 'subtitle' => 13, 'header' => 1, 'normal' => 4, 'total' => 7][$style] ?? 4;
            if ($style === 'title' || $style === 'subtitle') return $base; // toujours aligné à gauche
            return $base + $alignIdx;
        }

        private function buildSheetXml(): string
        {
            $nbCols = max(1, count($this->colWidths) ?: (max(array_map(fn($r) => count($r['cells']), $this->rows ?: [['cells' => []]]))));

            $colsXml = '<cols>';
            for ($i = 0; $i < $nbCols; $i++) {
                $w = $this->colWidths[$i] ?? 18;
                $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $colsXml .= '</cols>';

            $rowsXml = '';
            $mergesXml = '';
            $rIdx = 0;
            foreach ($this->rows as $row) {
                $rIdx++;
                $cellsXml = '';
                $cells = $row['cells'];
                $aligns = $row['aligns'];

                if (empty($cells)) {
                    // Ligne vide : une seule cellule vide suffit à créer l'espacement.
                    $rowsXml .= '<row r="' . $rIdx . '"></row>';
                    continue;
                }

                foreach ($cells as $i => $val) {
                    $ref = $this->colLettre($i) . $rIdx;
                    $align = $aligns[$i] ?? 'L';
                    $s = $this->styleIndex($row['style'], $align);

                    if (is_numeric($val) && $row['style'] !== 'title' && $row['style'] !== 'subtitle') {
                        $cellsXml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $val . '</v></c>';
                    } else {
                        $texte = $this->xmlEscape((string) $val);
                        $cellsXml .= '<c r="' . $ref . '" t="inlineStr" s="' . $s . '"><is><t xml:space="preserve">' . $texte . '</t></is></c>';
                    }
                }

                $hauteur = ($row['style'] === 'title') ? ' ht="26" customHeight="1"' : (($row['style'] === 'subtitle') ? ' ht="18" customHeight="1"' : '');
                $rowsXml .= '<row r="' . $rIdx . '"' . $hauteur . '>' . $cellsXml . '</row>';

                if (($row['style'] === 'title' || $row['style'] === 'subtitle') && !empty($row['merge']) && $row['merge'] > 1) {
                    $finLettre = $this->colLettre($row['merge'] - 1);
                    $mergesXml .= '<mergeCell ref="A' . $rIdx . ':' . $finLettre . $rIdx . '"/>';
                }
            }

            $mergeCellsBlock = $mergesXml !== '' ? ('<mergeCells count="' . substr_count($mergesXml, '<mergeCell') . '">' . $mergesXml . '</mergeCells>') : '';

            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . $colsXml
                . '<sheetData>' . $rowsXml . '</sheetData>'
                . $mergeCellsBlock
                . '</worksheet>';
        }

        private function buildStylesXml(): string
        {
            // Couleurs alignées sur le modèle PDF déjà utilisé (bandeau bleu marine).
            $navy = '1F3D7A';
            $greyFill = 'F2F2F2';

            // cellXfs, dans l'ordre : chaque bloc de 3 = [gauche, centre, droite] pour un même style visuel.
            // index 0 : défaut
            // 1-3 : header (blanc gras sur fond navy)
            // 4-6 : normal
            // 7-9 : total (gras, fond gris clair)
            // 10-12 : title (gros, gras, bleu marine) — seul l'alignement gauche est utilisé
            // 13-15 : subtitle (italique gris)
            $xfs = [];
            $xfs[] = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'; // 0 défaut

            foreach (['left', 'center', 'right'] as $al) { // 1-3 header
                $xfs[] = '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="' . $al . '" vertical="center"/></xf>';
            }
            foreach (['left', 'center', 'right'] as $al) { // 4-6 normal
                $xfs[] = '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="' . $al . '" vertical="center"/></xf>';
            }
            foreach (['left', 'center', 'right'] as $al) { // 7-9 total
                $xfs[] = '<xf numFmtId="3" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="' . $al . '" vertical="center"/></xf>';
            }
            $xfs[] = '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'; // 10 title
            $xfs[] = $xfs[10]; // 11 (inutilisé mais garde les index alignés par blocs de 3)
            $xfs[] = $xfs[10]; // 12
            $xfs[] = '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'; // 13 subtitle
            $xfs[] = $xfs[13]; // 14
            $xfs[] = $xfs[13]; // 15

            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<numFmts count="1"><numFmt numFmtId="3" formatCode="#,##0"/></numFmts>'
                . '<fonts count="5">'
                . '<font><sz val="10"/><name val="Calibri"/></font>' // 0 normal
                . '<font><sz val="10"/><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' // 1 header
                . '<font><sz val="10"/><b/><name val="Calibri"/></font>' // 2 total
                . '<font><sz val="16"/><b/><color rgb="FF' . $navy . '"/><name val="Calibri"/></font>' // 3 title
                . '<font><sz val="10"/><i/><color rgb="FF595959"/><name val="Calibri"/></font>' // 4 subtitle
                . '</fonts>'
                . '<fills count="4">'
                . '<fill><patternFill patternType="none"/></fill>'
                . '<fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $navy . '"/><bgColor indexed="64"/></patternFill></fill>' // 2 header
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $greyFill . '"/><bgColor indexed="64"/></patternFill></fill>' // 3 total
                . '</fills>'
                . '<borders count="2">'
                . '<border><left/><right/><top/><bottom/><diagonal/></border>'
                . '<border><left style="thin"><color rgb="FFDDDDDD"/></left><right style="thin"><color rgb="FFDDDDDD"/></right><top style="thin"><color rgb="FFDDDDDD"/></top><bottom style="thin"><color rgb="FFDDDDDD"/></bottom><diagonal/></border>'
                . '</borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                . '</styleSheet>';
        }

        /**
         * Génère le fichier et l'envoie directement au navigateur (comme
         * FPDF::Output). $mode : 'I' = affichage/ouverture directe (le
         * navigateur ouvre Excel/LibreOffice ou télécharge selon sa
         * configuration), 'D' = téléchargement forcé en pièce jointe.
         */
        public function output(string $filename, string $mode = 'I'): void
        {
            $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
            $zip = new ZipArchive();
            $zip->open($tmpFile, ZipArchive::OVERWRITE);

            $zip->addFromString('[Content_Types].xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>');

            $zip->addFromString('_rels/.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>');

            $zip->addFromString('xl/_rels/workbook.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>');

            $zip->addFromString('xl/workbook.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . $this->xmlEscape($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
                . '</workbook>');

            $zip->addFromString('xl/styles.xml', $this->buildStylesXml());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->buildSheetXml());
            $zip->close();

            $contenu = file_get_contents($tmpFile);
            unlink($tmpFile);

            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: ' . ($mode === 'D' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($contenu));
            header('Cache-Control: max-age=0');
            echo $contenu;
            exit;
        }
    }
}