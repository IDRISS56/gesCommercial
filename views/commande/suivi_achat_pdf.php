<?php
// Extrait de suivi_achat.php (découpage du fichier — voir audit technique) :
// génération du PDF (bon de commande fournisseur / facture d'achat) au format
// portrait. Inclus tel quel ; partage sa portée avec le fichier appelant.

// ==========================================
// 2. GÉNÉRATION DU PDF (mode Portrait)
// Pour un achat fournisseur : EXPEDITEUR = le fournisseur (qui a livré la
// marchandise), DESTINATAIRE = notre boutique (qui l'a reçue).
// ==========================================
if (isset($_POST['action']) && $_POST['action'] === 'pdf') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!isset($_POST['id']) || empty($_POST['id'])) die("ID d'achat manquant.");
    $id = $_POST['id'];
    try {
        $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact, c.adresse_contact, c.telephone_contact AS tel_contact, c.email_contact
            FROM facture f LEFT JOIN contact c ON f.contact_id = c.code_contact
            WHERE f.numero_facture = ? AND f.type_facture = 'Fournisseur'");
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$facture) die("Achat introuvable.");

        $stmtCmd = $pdo->prepare("SELECT c.*, p.titre_produit, p.code_produit AS reference_produit, l.libelle AS libelle_lot
            FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit
            LEFT JOIN lot l ON c.lot_id = l.code_lot
            WHERE c.facture_id = ?");
        $stmtCmd->execute([$id]);
        $commandes = $stmtCmd->fetchAll(PDO::FETCH_ASSOC);

        // Sécurité : un utilisateur ne peut imprimer que les achats d'une
        // boutique à laquelle il a accès.
        $boutiquesLignesPdf = array_unique(array_filter(array_column($commandes, 'boutique_id')));
        if (!empty($boutiquesLignesPdf) && !array_intersect($boutiquesLignesPdf, $boutiquesAutorisees)) {
            die("Accès refusé : cet achat appartient à une autre boutique.");
        }

        $boutique_id = $commandes[0]['boutique_id'] ?? null;
        if (empty($boutique_id) && !empty($facture['utilisateur_id'])) {
            $stmtU = $pdo->prepare("SELECT boutique_id FROM utilisateur WHERE id = ?");
            $stmtU->execute([$facture['utilisateur_id']]);
            $boutique_id = $stmtU->fetchColumn() ?: null;
        }
        $boutique = null;
        if (!empty($boutique_id)) {
            $stmtB = $pdo->prepare("SELECT * FROM boutique WHERE code_boutique = ?");
            $stmtB->execute([$boutique_id]);
            $boutique = $stmtB->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        http_response_code(500);
        die("Erreur lors de la génération du PDF : " . $e->getMessage());
    }

    $logoTmpPath = null;
    if (!empty($boutique['logo'])) {
        $mime = $boutique['type_logo'] ?? 'image/png';
        $ext = 'png';
        if (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) $ext = 'jpg';
        elseif (strpos($mime, 'gif') !== false) $ext = 'gif';
        $logoTmpPath = sys_get_temp_dir() . '/logo_' . $boutique_id . '_' . uniqid() . '.' . $ext;
        file_put_contents($logoTmpPath, $boutique['logo']);
    }

    $nomBoutique = $boutique['nom_boutique'] ?? 'Ets Dankan';
    $adresseBoutique = trim(($boutique['adresse_boutique'] ?? '') . (!empty($boutique['quartier_boutique']) ? ', ' . $boutique['quartier_boutique'] : '') . (!empty($boutique['ville_boutique']) ? ', ' . $boutique['ville_boutique'] : ''));
    $telBoutique = $boutique['telephone_boutique'] ?? '';
    $emailBoutique = $boutique['email_boutique'] ?? '';

    class PDF extends FPDF {
        function Header() {}
        function Footer() {}
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
        // Calcule le nombre RÉEL de lignes qu'occupera un MultiCell(w, ..., txt)
        // une fois converti dans l'encodage imprimé — remplace l'estimation
        // approximative par nombre de caractères (strlen/25) qui sous-évaluait
        // la hauteur de certaines lignes et causait un chevauchement de texte
        // avec la ligne suivante du tableau.
        function NbLines($w, $txt) {
            $txt = $this->txt($txt);
            $cw = &$this->CurrentFont['cw'];
            if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
            $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
            $s = str_replace("\r", '', $txt);
            $nb = strlen($s);
            if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
            $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
            while ($i < $nb) {
                $c = $s[$i];
                if ($c == "\n") {
                    $i++; $sep = -1; $j = $i; $l = 0; $nl++;
                    continue;
                }
                if ($c == ' ') $sep = $i;
                $l += $cw[$c] ?? 500;
                if ($l > $wmax) {
                    if ($sep == -1) {
                        if ($i == $j) $i++;
                    } else {
                        $i = $sep + 1;
                    }
                    $sep = -1; $j = $i; $l = 0; $nl++;
                } else {
                    $i++;
                }
            }
            return $nl;
        }
    }

    $pdf = new PDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(10, 10, 10);

    $navy   = [21, 61, 122];
    $grey   = [242, 242, 242];
    $border = [190, 190, 190];
    $estValidee = ($facture['statut_facture'] ?? '') === 'Validee';
    $titreDoc = strtoupper($estValidee ? 'Facture fournisseur' : 'Bon de commande');

    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetXY(10, 12);
    $pdf->Cell(130, 12, $titreDoc, 0, 1, 'L');
    if ($logoTmpPath) {
        $pdf->Image($logoTmpPath, 155, 8, 45);
        unlink($logoTmpPath);
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetX(10);
    $pdf->Cell(130, 6, 'N° ' . $facture['numero_facture'], 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetX(10);
    $pdf->Cell(130, 5, 'Date : ' . date('d/m/Y', strtotime($facture['date_facture'])), 0, 1, 'L');
    $pdf->SetX(10);
    $pdf->Cell(130, 5, 'Statut : ' . ($facture['statut_facture'] ?? '') . '   |   Reglement : ' . ($facture['etat_facture'] ?? ''), 0, 1, 'L');
    $pdf->Ln(16);

    // EXPEDITEUR = fournisseur / DESTINATAIRE = notre boutique (réception)
    $yBandeau = $pdf->GetY();
    $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(10, $yBandeau);
    $pdf->Cell(90, 7, '  FOURNISSEUR', 0, 0, 'L', true);
    $pdf->SetXY(110, $yBandeau);
    $pdf->Cell(90, 7, '  RECU PAR (notre boutique)', 0, 0, 'L', true);
    $yBoxes = $yBandeau + 7;
    $pdf->SetTextColor(0, 0, 0);

    $fournisseurNom = $facture['nom_prenom_contact'] ?? 'N/C';
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetFillColor($grey[0], $grey[1], $grey[2]);
    $pdf->SetXY(10, $yBoxes);
    $pdf->MultiCell(90, 5.5, $fournisseurNom, 0, 'L', true);
    $yFournAfterNom = $pdf->GetY();
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(10, $yFournAfterNom);
    $pdf->MultiCell(90, 5.5, ($facture['adresse_contact'] ?? '') . "\nTel: " . ($facture['tel_contact'] ?? '') . "\nEmail: " . ($facture['email_contact'] ?? ''), 0, 'L', true);
    $yAfterFourn = $pdf->GetY();

    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetXY(110, $yBoxes);
    $pdf->MultiCell(90, 5.5, $nomBoutique, 0, 'L', true);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetXY(110, $pdf->GetY());
    $pdf->MultiCell(90, 3.3, 'Distribution de Pièces Détachées de Motos et Moto', 0, 'L', true);
    $yBoutAfterNom = $pdf->GetY();
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(110, $yBoutAfterNom);
    $pdf->MultiCell(90, 5.5, $adresseBoutique . "\nTel: " . $telBoutique . "\nEmail: " . $emailBoutique, 0, 'L', true);
    $yAfterBout = $pdf->GetY();
    $pdf->SetY(max($yAfterFourn, $yAfterBout) + 6);

    $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(20, 7, 'REF.', 0, 0, 'C', true);
    $pdf->Cell(87, 7, 'DESIGNATION', 0, 0, 'C', true);
    $pdf->Cell(15, 7, 'QTE', 0, 0, 'C', true);
    $pdf->Cell(15, 7, 'CARTON', 0, 0, 'C', true);
    $pdf->Cell(25, 7, 'P.A.(FCFA)', 0, 0, 'C', true);
    $pdf->Cell(28, 7, 'MONTANT(FCFA)', 0, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor($border[0], $border[1], $border[2]);
    $pdf->SetFont('Arial', '', 9);
    $total_ht = 0;
    foreach ($commandes as $cmd) {
        $montant_ligne = $cmd['quantite_commande'] * $cmd['prix_achat'];
        $total_ht += $montant_ligne;
        $ref = $cmd['reference_produit'] ?? '';
        $designation = $cmd['titre_produit'] ?? '';
        $nbLines = max(1, $pdf->NbLines(20, $ref), $pdf->NbLines(87, $designation));
        $rowHeight = 7 * $nbLines;
        if ($pdf->GetY() + $rowHeight > 270) $pdf->AddPage();
        // Quantité = nombre d'unités (pièces) achetées, toujours. Carton = nombre
        // de cartons complets, uniquement si un lot a réellement été configuré
        // à l'achat (produits_par_lot > 1) ; vide sinon (produit acheté à la pièce).
        $produitsParLot = intval($cmd['produits_par_lot'] ?? 1);
        $carton = $produitsParLot > 1 ? intdiv($cmd['quantite_commande'], $produitsParLot) : '';
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->MultiCell(20, 7, $ref, 1, 'L');
        $pdf->SetXY($x + 20, $y); $pdf->MultiCell(87, 7, $designation, 1, 'L');
        $pdf->SetXY($x + 107, $y);
        $pdf->Cell(15, $rowHeight, $cmd['quantite_commande'], 1, 0, 'C');
        $pdf->Cell(15, $rowHeight, $carton, 1, 0, 'C');
        // Prix imprimé : le coût DU LOT tel que saisi à l'achat si cette ligne en
        // a un (colonne dédiée prix_lot_ligne, jamais recalculé), sinon le
        // prix/unité classique. Le montant reste toujours qté(unités) × prix/unité.
        $aPrixLot = isset($cmd['prix_lot_ligne']) && $cmd['prix_lot_ligne'] !== null && $cmd['prix_lot_ligne'] !== '';
        $prixUnitImprime = $aPrixLot ? $cmd['prix_lot_ligne'] : $cmd['prix_achat'];
        $pdf->Cell(25, $rowHeight, number_format($prixUnitImprime, 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell(28, $rowHeight, number_format($montant_ligne, 0, ',', ' '), 1, 1, 'R');
    }
    $pdf->Ln(6);

    $montantTTC = $facture['montant_ttc'] ?? $total_ht;
    $reste = $facture['reste'] ?? $montantTTC;
    $avance = $facture['avance'] ?? 0;
    $taxe = floatval($facture['taxe'] ?? 0);
    $remise = floatval($facture['remise'] ?? 0);

    $obsWidth = 100; $obsHeight = 26;
    $lignesTotaux = [['TOTAL HT', $total_ht], ['TVA', $taxe], ['REMISE', $remise], ['AVANCE VERSEE', $avance]];
    $totWidth = 80; $totX = 200 - $totWidth;
    $rowH = 7;
    $nbRows = count($lignesTotaux) + 1;

    // ⚠️ Le bloc totaux + signatures est positionné avec des coordonnées Y
    // absolues (calculées à partir d'un seul $yBloc de départ), pas avec le flux
    // normal de FPDF. Si on le laisse démarrer trop bas sur la page, le saut de
    // page automatique de FPDF (SetAutoPageBreak) se déclenche cellule par
    // cellule sans jamais recalculer ces positions absolues par rapport au
    // nouveau haut de page : chaque ligne de total / case de signature peut
    // alors atterrir sur sa propre page, presque vide (bug à l'origine du bon
    // de commande étalé sur 7 pages au lieu de 2). On vérifie donc la place
    // disponible en une fois, pour tout le bloc, et on saute de page AVANT de
    // commencer à le dessiner si besoin — il reste ainsi toujours groupé.
    $hauteurTotaux = max($obsHeight, $rowH * $nbRows);
    $hauteurBlocComplet = $hauteurTotaux + 10 + 6 + 6 + 2 + 20;
    if ($pdf->GetY() + $hauteurBlocComplet > 282) $pdf->AddPage();

    $yBloc = $pdf->GetY();
    $pdf->SetDrawColor($navy[0], $navy[1], $navy[2]);
    $pdf->Rect($totX, $yBloc, $totWidth, $rowH * $nbRows);
    $pdf->SetFont('Arial', '', 9.5);
    $curY = $yBloc;
    foreach ($lignesTotaux as $ligne) {
        $pdf->SetXY($totX, $curY);
        $pdf->Cell($totWidth - 30, $rowH, ' ' . $ligne[0], 'B', 0, 'L');
        $pdf->Cell(30, $rowH, number_format($ligne[1], 0, ',', ' ') . ' FCFA', 'B', 1, 'R');
        $curY += $rowH;
    }
    $pdf->SetXY($totX, $curY);
    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($totWidth - 30, $rowH + 1, ' RESTE DU', 0, 0, 'L');
    $pdf->Cell(30, $rowH + 1, number_format($reste, 0, ',', ' ') . ' FCFA', 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($yBloc + $hauteurTotaux + 10);

    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(90, 6, 'Le fournisseur', 0, 0, 'L');
    $pdf->Cell(10);
    $pdf->Cell(90, 6, 'Reception (notre boutique)', 0, 1, 'L');
    $pdf->Cell(90, 6, 'Nom et Signature', 0, 0, 'L');
    $pdf->Cell(10);
    $pdf->Cell(90, 6, 'Nom et Signature', 0, 1, 'L');
    $ySign = $pdf->GetY() + 2;
    $pdf->SetDrawColor($navy[0], $navy[1], $navy[2]);
    $pdf->Rect(10, $ySign, 90, 20);
    $pdf->Rect(110, $ySign, 90, 20);
    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetXY(112, $ySign + 2);
    $pdf->Cell(86, 5, $nomBoutique, 0, 1, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetX(112);
    $pdf->Cell(86, 4, $adresseBoutique, 0, 1, 'L');
    $pdf->SetX(112);
    $pdf->Cell(86, 4, 'Tel: ' . $telBoutique, 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Output('I', ($estValidee ? 'Facture_fournisseur_' : 'Bon_fournisseur_') . $id . '.pdf');
    exit;
}
