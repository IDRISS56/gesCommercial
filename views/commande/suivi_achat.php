<?php
// ==========================================
// 1. CONNEXION À LA BASE DE DONNÉES
// ==========================================
require 'databases/database.php';
require 'librairies/fpdf/fpdf.php';

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
    $pdf->Cell(75, 7, 'DESIGNATION', 0, 0, 'C', true);
    $pdf->Cell(30, 7, 'QUANTITE', 0, 0, 'C', true);
    $pdf->Cell(30, 7, 'P.A.(FCFA)', 0, 0, 'C', true);
    $pdf->Cell(35, 7, 'MONTANT(FCFA)', 0, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor($border[0], $border[1], $border[2]);
    $pdf->SetFont('Arial', '', 9);
    $total_ht = 0;
    foreach ($commandes as $cmd) {
        $montant_ligne = $cmd['quantite_commande'] * $cmd['prix_achat'];
        $total_ht += $montant_ligne;
        $ref = $cmd['reference_produit'] ?? '';
        $designation = $cmd['titre_produit'] ?? '';
        $nbLines = max(1, ceil(strlen($designation) / 35));
        $rowHeight = 7 * $nbLines;
        if ($pdf->GetY() + $rowHeight > 270) $pdf->AddPage();
        // Quantité affichée : si aucun lot n'a été configuré à l'achat
        // (produits_par_lot <= 1), on parle simplement de "Produit" — on ne mentionne le lot que lorsqu'il a réellement été configuré.
        $produitsParLot = intval($cmd['produits_par_lot'] ?? 1);
        if ($produitsParLot > 1) {
            $libelleLot = !empty($cmd['libelle_lot']) ? $cmd['libelle_lot'] : 'Carton';
            $nombreLots = intdiv($cmd['quantite_commande'], $produitsParLot);
            $resteUnites = $cmd['quantite_commande'] % $produitsParLot;
            $qteAffichee = $resteUnites > 0
                ? ($nombreLots . ' ' . $libelleLot . '(s) et ' . $resteUnites . ' Pièce(s)')
                : ($nombreLots . ' ' . $libelleLot . '(s)');
        } else {
            $qteAffichee = $cmd['quantite_commande'] . ' Pièce(s)';
        }
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->MultiCell(20, 7, $ref, 1, 'L');
        $pdf->SetXY($x + 20, $y); $pdf->MultiCell(75, 7, $designation, 1, 'L');
        $pdf->SetXY($x + 95, $y);
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->Cell(30, $rowHeight, $qteAffichee, 1, 0, 'C');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(30, $rowHeight, number_format($cmd['prix_achat'], 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell(35, $rowHeight, number_format($montant_ligne, 0, ',', ' '), 1, 1, 'R');
    }
    $pdf->Ln(6);

    $montantTTC = $facture['montant_ttc'] ?? $total_ht;
    $reste = $facture['reste'] ?? $montantTTC;
    $avance = $facture['avance'] ?? 0;
    $taxe = floatval($facture['taxe'] ?? 0);
    $remise = floatval($facture['remise'] ?? 0);

    $yBloc = $pdf->GetY();
    $obsWidth = 100; $obsHeight = 26;

    $lignesTotaux = [['TOTAL HT', $total_ht], ['TVA', $taxe], ['REMISE', $remise], ['AVANCE VERSEE', $avance]];
    $totWidth = 80; $totX = 200 - $totWidth;
    $rowH = 7;
    $nbRows = count($lignesTotaux) + 1;
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
    $pdf->SetY($yBloc + max($obsHeight, $rowH * $nbRows) + 10);

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

// ==========================================
// 3. TRAITEMENT DES ACTIONS (AJAX / POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'validate_facture') {
        header('Content-Type: application/json');
        $id = $_POST['id'] ?? '';
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'ID manquant']); exit; }
        try {
            $pdo->beginTransaction();

            $stmtF = $pdo->prepare("SELECT contact_id, avance, reste, statut_facture FROM facture WHERE numero_facture = ? AND type_facture = 'Fournisseur' FOR UPDATE");
            $stmtF->execute([$id]);
            $f = $stmtF->fetch(PDO::FETCH_ASSOC);
            if (!$f) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'Facture introuvable']); exit; }

            $stmt = $pdo->prepare("UPDATE facture SET statut_facture = 'Validee' WHERE numero_facture = ? AND type_facture = 'Fournisseur' AND statut_facture <> 'Validee'");
            $stmt->execute([$id]);
            $factureTouchee = $stmt->rowCount() > 0;

            if ($factureTouchee) {
                // Recharge le solde du fournisseur, exactement comme le fichier vente
                // le fait déjà pour le solde client lors de la validation d'une facture :
                // on ajoute le montant dû (reste) au solde_contact du fournisseur, en
                // tenant compte d'une éventuelle avance déjà versée (solde_contact < 0
                // => le fournisseur nous doit / avance disponible, cf. convention plus
                // haut : solde_contact > 0 => on doit ce montant au fournisseur).
                $resteInitial = floatval($f['reste']);
                if ($resteInitial != 0) {
                    $stmtSoldeF = $pdo->prepare("SELECT solde_contact FROM contact WHERE code_contact = ? FOR UPDATE");
                    $stmtSoldeF->execute([$f['contact_id']]);
                    $soldeAvantF = floatval($stmtSoldeF->fetchColumn());
                    $avanceDispoF = max(0, -$soldeAvantF);
                    $avanceUtilisee = min($avanceDispoF, $resteInitial);
                    $nouveauReste = round($resteInitial - $avanceUtilisee, 2);

                    if ($nouveauReste <= 0) {
                        $nouvelEtat = 'Payee';
                    } elseif ($avanceUtilisee > 0) {
                        $nouvelEtat = 'Partielle';
                    } else {
                        $nouvelEtat = 'Impayee';
                    }

                    $pdo->prepare("UPDATE facture SET etat_facture = ?, avance = avance + ?, reste = ? WHERE numero_facture = ?")
                        ->execute([$nouvelEtat, $avanceUtilisee, $nouveauReste, $id]);
                    $pdo->prepare("UPDATE contact SET solde_contact = solde_contact + ? WHERE code_contact = ?")
                        ->execute([$resteInitial, $f['contact_id']]);
                }
            }

            // Réception physique confirmée : on crédite le stock des lignes qui
            // étaient encore "EN ATTENTE" (celles déjà "VALIDEE" ont déjà été
            // créditées à la commande et ne doivent pas l'être une seconde fois).
            $stmtLignes = $pdo->prepare("SELECT numero_commande, produit_id, boutique_id, quantite_commande FROM commande WHERE facture_id = ? AND etat_commande = 'EN ATTENTE'");
            $stmtLignes->execute([$id]);
            $lignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lignes as $l) {
                if (!empty($l['boutique_id'])) {
                    $pdo->prepare("INSERT INTO stock (produit_id, boutique_id, quantite, stock_alerte)
                                  VALUES (?, ?, ?, 10)
                                  ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)")
                        ->execute([$l['produit_id'], $l['boutique_id'], $l['quantite_commande']]);
                }
                $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) + ? AS CHAR) WHERE code_produit = ?")
                    ->execute([$l['quantite_commande'], $l['produit_id']]);
                $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                    ->execute([$l['produit_id']]);
                $pdo->prepare("UPDATE commande SET etat_commande = 'VALIDEE' WHERE numero_commande = ?")
                    ->execute([$l['numero_commande']]);
            }

            $pdo->commit();
            echo json_encode(['success' => $factureTouchee, 'message' => 'Achat validé, stock réceptionné']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_facture') {
        header('Content-Type: application/json');
        $id = $_POST['id'] ?? '';
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'ID manquant']); exit; }
        try {
            $pdo->beginTransaction();

            // Réajuster le stock : on retire ce que cet achat avait fait entrer,
            // puisque l'achat lui-même est annulé/supprimé. Seules les lignes déjà
            // "VALIDEE" (donc réellement créditées en stock) sont concernées ; les
            // lignes encore "EN ATTENTE" n'ont jamais touché le stock.
            $stmtLignes = $pdo->prepare("SELECT produit_id, boutique_id, quantite_commande FROM commande WHERE facture_id = ? AND etat_commande = 'VALIDEE'");
            $stmtLignes->execute([$id]);
            foreach ($stmtLignes->fetchAll(PDO::FETCH_ASSOC) as $l) {
                if (!empty($l['boutique_id'])) {
                    $stmtLock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                    $stmtLock->execute([$l['produit_id'], $l['boutique_id']]);
                    $stockActuel = $stmtLock->fetchColumn();
                    $stockActuel = ($stockActuel === false) ? 0 : (int) $stockActuel;
                    if ($stockActuel < $l['quantite_commande']) {
                        throw new Exception(
                            "Impossible de supprimer cet achat : le produit {$l['produit_id']} a déjà été partiellement " .
                            "vendu/sorti depuis sa réception (stock actuel $stockActuel, à retirer {$l['quantite_commande']}). " .
                            "Faites d'abord un ajustement de stock si nécessaire."
                        );
                    }
                    $pdo->prepare("UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                        ->execute([$stockActuel - $l['quantite_commande'], $l['produit_id'], $l['boutique_id']]);
                }
                $pdo->prepare("UPDATE produit SET stock_produit = CAST(GREATEST(0, CAST(COALESCE(stock_produit,0) AS SIGNED) - ?) AS CHAR) WHERE code_produit = ?")
                    ->execute([$l['quantite_commande'], $l['produit_id']]);
            }

            $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM facture WHERE numero_facture = ? AND type_facture = 'Fournisseur'");
            $stmt->execute([$id]);
            $pdo->commit();
            echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Achat supprimé, stock réajusté']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_details') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact FROM facture f LEFT JOIN contact c ON f.contact_id = c.code_contact WHERE f.numero_facture = ? AND f.type_facture = 'Fournisseur'");
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$facture) { echo json_encode(['error' => 'Achat introuvable']); exit; }
        $stmt2 = $pdo->prepare("SELECT c.*, p.titre_produit, l.libelle AS libelle_lot FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit LEFT JOIN lot l ON c.lot_id = l.code_lot WHERE c.facture_id = ?");
        $stmt2->execute([$id]);
        $commandesDetail = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $isLocked = in_array(strtolower($facture['etat_facture'] ?? ''), ['payee', 'payee cash'])
            && strtolower($facture['statut_facture'] ?? '') === 'validee';

        // Boutique de réception de cet achat (pour associer correctement les
        // nouvelles lignes ajoutées lors de la modification).
        $boutiqueAchat = $commandesDetail[0]['boutique_id'] ?? null;
        if (empty($boutiqueAchat) && !empty($facture['utilisateur_id'])) {
            $stmtUb = $pdo->prepare("SELECT boutique_id FROM utilisateur WHERE id = ?");
            $stmtUb->execute([$facture['utilisateur_id']]);
            $boutiqueAchat = $stmtUb->fetchColumn() ?: null;
        }

        echo json_encode([
            'facture' => $facture,
            'commandes' => $commandesDetail,
            'is_locked' => $isLocked,
            'boutique_id' => $boutiqueAchat
        ]);
        exit;
    }

    if ($action === 'check_stock_produit') {
        // Simple lecture informative du stock actuel d'un produit dans une
        // boutique (un achat alimente le stock, il n'y a donc rien à bloquer ici,
        // contrairement à une vente).
        $produitId = trim($_POST['produit_id'] ?? '');
        $boutiqueId = trim($_POST['boutique_id'] ?? '');
        $response = ['success' => false, 'disponible' => 0, 'prix' => 0, 'titre' => ''];
        if ($produitId !== '') {
            $dispo = 0;
            if ($boutiqueId !== '') {
                $stmtS = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ?");
                $stmtS->execute([$produitId, $boutiqueId]);
                $d = $stmtS->fetchColumn();
                $dispo = ($d !== false) ? (int) $d : 0;
            }
            $stmtP = $pdo->prepare("SELECT titre_produit, prix_fournisseur FROM produit WHERE code_produit = ?");
            $stmtP->execute([$produitId]);
            $rowP = $stmtP->fetch(PDO::FETCH_ASSOC);
            if ($rowP) {
                $response['success'] = true;
                $response['disponible'] = $dispo;
                $response['prix'] = (float) $rowP['prix_fournisseur'];
                $response['titre'] = $rowP['titre_produit'];
            }
        }
        echo json_encode($response);
        exit;
    }

    // Mise à jour des lignes d'achat uniquement (quantité, prix d'achat).
    // Le règlement (avance/reste/état) n'est PAS modifiable ici : il est géré
    // par votre interface de règlement fournisseur dédiée.
    if ($action === 'update_achat') {
        $facture_id = $_POST['facture_id'];
        $checkStmt = $pdo->prepare("SELECT contact_id, etat_facture, statut_facture FROM facture WHERE numero_facture = ? AND type_facture = 'Fournisseur'");
        $checkStmt->execute([$facture_id]);
        $fData = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fData) { echo json_encode(['success' => false, 'error' => 'Achat introuvable']); exit; }
        $isLocked = (in_array(strtolower($fData['etat_facture']), ['payee', 'payee cash'])
            && strtolower($fData['statut_facture']) === 'validee');
        if ($isLocked) {
            echo json_encode(['success' => false, 'error' => 'Achat déjà réglé intégralement, modification impossible.']);
            exit;
        }
        $commandes_data = json_decode($_POST['commandes'], true);
        $nouvelles_lignes_data = json_decode($_POST['nouvelles_lignes'] ?? '[]', true) ?: [];
        $lignesAjoutees = 0;
        try {
            $pdo->beginTransaction();
            $nouveauTotal = 0;
            foreach ($commandes_data as $cmd) {
                // Réajuster le stock selon la différence de quantité
                $stmtOld = $pdo->prepare("SELECT produit_id, boutique_id, quantite_commande, etat_commande FROM commande WHERE numero_commande = ?");
                $stmtOld->execute([$cmd['id']]);
                $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
                // Le stock n'a été crédité que pour les lignes déjà "VALIDEE"
                // (réceptionnées) ; une ligne encore "EN ATTENTE" n'a jamais
                // touché le stock, donc on ne doit pas y toucher ici non plus.
                $stockDejaCredite = $old && ($old['etat_commande'] === 'VALIDEE');

                if ($cmd['supprimer']) {
                    if ($old) {
                        if ($stockDejaCredite && !empty($old['boutique_id'])) {
                            $stmtLock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                            $stmtLock->execute([$old['produit_id'], $old['boutique_id']]);
                            $stockActuel = $stmtLock->fetchColumn();
                            $stockActuel = ($stockActuel === false) ? 0 : (int) $stockActuel;
                            if ($stockActuel < $old['quantite_commande']) {
                                throw new Exception(
                                    "Impossible de supprimer la ligne {$old['produit_id']} : stock actuel " .
                                    "$stockActuel, insuffisant pour retirer {$old['quantite_commande']}."
                                );
                            }
                            $pdo->prepare("UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                                ->execute([$stockActuel - $old['quantite_commande'], $old['produit_id'], $old['boutique_id']]);
                        }
                        if ($stockDejaCredite) {
                            $pdo->prepare("UPDATE produit SET stock_produit = CAST(GREATEST(0, CAST(COALESCE(stock_produit,0) AS SIGNED) - ?) AS CHAR) WHERE code_produit = ?")
                                ->execute([$old['quantite_commande'], $old['produit_id']]);
                        }
                    }
                    $pdo->prepare("DELETE FROM commande WHERE numero_commande = ?")->execute([$cmd['id']]);
                } else {
                    $montant = $cmd['quantite'] * $cmd['prix'];
                    $nouveauTotal += $montant;
                    if ($old) {
                        $diff = $cmd['quantite'] - $old['quantite_commande'];
                        if ($stockDejaCredite && $diff != 0 && !empty($old['boutique_id'])) {
                            $stmtLock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                            $stmtLock->execute([$old['produit_id'], $old['boutique_id']]);
                            $stockActuel = $stmtLock->fetchColumn();
                            $stockActuel = ($stockActuel === false) ? 0 : (int) $stockActuel;
                            $stockCible = $stockActuel + $diff;
                            if ($stockCible < 0) {
                                throw new Exception(
                                    "Impossible de réduire la quantité de {$old['produit_id']} : stock actuel " .
                                    "$stockActuel, réduction demandée " . abs($diff) . "."
                                );
                            }
                            $pdo->prepare("UPDATE stock SET quantite = ? WHERE produit_id = ? AND boutique_id = ?")
                                ->execute([$stockCible, $old['produit_id'], $old['boutique_id']]);
                        }
                        if ($stockDejaCredite && $diff != 0) {
                            $pdo->prepare("UPDATE produit SET stock_produit = CAST(GREATEST(0, CAST(COALESCE(stock_produit,0) AS SIGNED) + ?) AS CHAR) WHERE code_produit = ?")
                                ->execute([$diff, $old['produit_id']]);
                        }
                    }
                    $pdo->prepare("UPDATE commande SET quantite_commande = ?, prix_achat = ?, montant_commande = ? WHERE numero_commande = ?")
                        ->execute([$cmd['quantite'], $cmd['prix'], $montant, $cmd['id']]);
                }
            }

            // ---- AJOUT DE NOUVELLES LIGNES (produits ajoutés pendant la modification) ----
            if (!empty($nouvelles_lignes_data)) {
                $factureEstValidee = (strtolower($fData['statut_facture'] ?? '') === 'validee');
                // Statut à réutiliser : celui d'une ligne existante du même achat,
                // pour rester cohérent avec le reste du document. Le lot, lui, est
                // configurable indépendamment pour chaque nouvelle ligne (voir
                // achat.php pour le même principe).
                $stmtRef = $pdo->prepare("SELECT statut_id FROM commande WHERE facture_id = ? LIMIT 1");
                $stmtRef->execute([$facture_id]);
                $statutRef = $stmtRef->fetchColumn() ?: '011';
                $libellesLotValides = ['Boîte', 'Palette', 'Carton', 'Bidon', 'Unité'];
                $numBase = date('dmYHis');

                foreach ($nouvelles_lignes_data as $i => $nl) {
                    $produitId = trim($nl['produit_id'] ?? '');
                    $boutiqueId = trim($nl['boutique_id'] ?? '');
                    $quantite = max(0, intval($nl['quantite'] ?? 0));
                    $prix = floatval($nl['prix'] ?? 0);
                    if ($produitId === '' || $quantite <= 0) {
                        continue;
                    }

                    // Configuration de lot (optionnelle, comme dans achat.php) : si
                    // l'utilisateur n'a pas configuré de lot pour cette ligne, on reste
                    // sur le comportement "produit simple" (lot_id NULL, produits_par_lot = 1).
                    $lotConfigure = filter_var($nl['lot_configure'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $unitesParLot = max(2, intval($nl['unites_par_lot'] ?? 2));
                    $libelleLot = in_array($nl['libelle_lot'] ?? '', $libellesLotValides, true) ? $nl['libelle_lot'] : 'Unité';

                    $lot_id = null;
                    $produits_par_lot = 1;
                    if ($lotConfigure) {
                        $lot_id = 'LOT-' . date('YmdHis') . rand(100, 999) . '-' . $i;
                        $produits_par_lot = $unitesParLot;
                        $pdo->prepare("INSERT INTO lot (code_lot, libelle, unites_par_lot, produit_id, quantite, etat_lot)
                                      VALUES (?, ?, ?, ?, ?, 'Actif')")
                            ->execute([$lot_id, $libelleLot, $unitesParLot, $produitId, $quantite]);
                    }

                    $montantNew = $quantite * $prix;
                    $nouveauTotal += $montantNew;
                    // Si l'achat est déjà validé (marchandise déjà réceptionnée), la
                    // nouvelle ligne est considérée reçue immédiatement (comme ses
                    // lignes sœurs déjà VALIDEE) et son stock est crédité tout de
                    // suite — sinon elle ne serait jamais créditée automatiquement,
                    // la validation globale de l'achat ne s'exécutant qu'une fois.
                    // Sinon, elle reste EN ATTENTE comme les autres lignes du bon,
                    // en attendant la validation/réception de l'ensemble.
                    $etatLigne = $factureEstValidee ? 'VALIDEE' : 'EN ATTENTE';
                    $numCmdNew = $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '-ADD';

                    $pdo->prepare("
                        INSERT INTO commande
                        (numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id,
                         date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, produits_par_lot,
                         montant_commande, utilisateur_id, boutique_id, etat_commande)
                        VALUES (?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $numCmdNew, $produitId, $lot_id, $fData['contact_id'], $facture_id, $statutRef,
                        $prix, $prix, $quantite, $produits_par_lot, $montantNew,
                        ($_SESSION['user_id'] ?? null), $boutiqueId, $etatLigne
                    ]);

                    if ($factureEstValidee && !empty($boutiqueId)) {
                        $pdo->prepare("INSERT INTO stock (produit_id, boutique_id, quantite, stock_alerte)
                                      VALUES (?, ?, ?, 10)
                                      ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)")
                            ->execute([$produitId, $boutiqueId, $quantite]);
                        $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) + ? AS CHAR) WHERE code_produit = ?")
                            ->execute([$quantite, $produitId]);
                        $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                        WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                        WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                        ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                            ->execute([$produitId]);
                    }

                    $lignesAjoutees++;
                }
            }
            // Recalcule le montant de la facture ; le reste suit automatiquement
            // (avance inchangée, gérée par l'interface de règlement).
            $stmtF = $pdo->prepare("SELECT avance FROM facture WHERE numero_facture = ?");
            $stmtF->execute([$facture_id]);
            $avanceActuelle = floatval($stmtF->fetchColumn());
            $nouveauReste = max(0, $nouveauTotal - $avanceActuelle);
            $pdo->prepare("UPDATE facture SET montant_ht = ?, montant_ttc = ?, reste = ? WHERE numero_facture = ?")
                ->execute([$nouveauTotal, $nouveauTotal, $nouveauReste, $facture_id]);

            $pdo->commit();
            $message = $lignesAjoutees > 0
                ? 'Achat mis à jour (' . $lignesAjoutees . ' nouvelle(s) ligne(s) ajoutée(s)).'
                : 'Achat mis à jour, stock ajusté';
            echo json_encode(['success' => true, 'message' => $message]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// ==========================================
// 4. DONNÉES ET STATISTIQUES
// ==========================================
function getEtatBadge($etat) {
    $etatLower = strtolower($etat);
    if ($etatLower === 'payee' || $etatLower === 'payee cash') return ['success', 'check-circle-fill'];
    if ($etatLower === 'partielle') return ['warning', 'hourglass-split'];
    if ($etatLower === 'impayee') return ['danger', 'x-circle-fill'];
    return ['secondary', 'question-circle'];
}

function getStatutBadge($statut) {
    return strtolower($statut) === 'validee' ? 'primary' : 'secondary';
}

$stmtFournisseurs = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE etat_contact = 'Actif' AND type_contact = 'Fournisseur' ORDER BY nom_prenom_contact ASC");
$fournisseurs = $stmtFournisseurs->fetchAll(PDO::FETCH_ASSOC);
$totalAchats = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Fournisseur'")->fetchColumn();
$payees = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Fournisseur' AND f.etat_facture IN ('Payee', 'Payee cash')")->fetchColumn();
$partielles = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Fournisseur' AND f.etat_facture = 'Partielle'")->fetchColumn();
$impayees = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Fournisseur' AND f.etat_facture = 'Impayee'")->fetchColumn();
$totalMontant = $pdo->query("SELECT SUM(f.montant_ttc) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Fournisseur'")->fetchColumn() ?? 0;
$totalReste = $pdo->query("SELECT SUM(f.reste) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Fournisseur'")->fetchColumn() ?? 0;

// - Catégories actives et produits (pour l'ajout de nouvelles lignes lors de la
//   modification d'un achat) : même logique que entree_stock.php. On utilise le
//   prix_fournisseur (et non le prix de vente) puisqu'il s'agit d'un achat. -
$categoriesAchat = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie WHERE etat_categorie='ACTIF' ORDER BY titre_categorie")->fetchAll(PDO::FETCH_ASSOC);
$produitsAchat = $pdo->query("SELECT code_produit, titre_produit, prix_fournisseur, etat_produit, categorie_id
FROM produit
ORDER BY CASE WHEN etat_produit = 'RUPTURE' THEN 1 ELSE 0 END, titre_produit")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi des Achats Fournisseur</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
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

        /* ===== CARTES ACHATS (compactes - 4 par ligne) ===== */
        .facture-card {
            background: var(--bg-surface); border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 10px 12px; cursor: pointer;
            transition: all 0.15s ease; display: flex; flex-direction: column;
            justify-content: space-between; min-height: 120px; position: relative;
            animation: fadeUp .4s ease both;
        }
        .facture-card:hover { border-color: var(--color-primary); box-shadow: 0 4px 12px rgba(79, 70, 229, .12); transform: translateY(-2px); }
        .facture-card.validated { border-color: var(--color-success); background: #f0fdf4; }
        .facture-card.validated::after {
            content: '✓'; position: absolute; top: 6px; right: 6px; background: var(--color-success);
            color: #fff; width: 18px; height: 18px; border-radius: 50%; font-size: 10px;
            display: flex; align-items: center; justify-content: center; font-weight: 700;
        }
        .fc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
        .fc-number { font-size: 11px; font-weight: 700; color: var(--color-primary-dark); font-family: 'Outfit', sans-serif; }
        .facture-card.validated .fc-number { color: #059669; }
        .fc-amount { font-size: 14px; font-weight: 800; color: var(--color-primary); font-family: 'Outfit', sans-serif; line-height: 1; }
        .fc-amount small { font-size: 9px; color: var(--text-tertiary); margin-left: 2px; }
        .fc-middle { flex: 1; display: flex; flex-direction: column; gap: 3px; margin: 4px 0; }
        .fc-client { font-size: 11px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fc-client i { color: var(--color-primary); font-size: 11px; flex-shrink: 0; }
        .fc-date { font-size: 9px; color: var(--text-tertiary); display: flex; align-items: center; gap: 3px; }
        .fc-badges { display: flex; gap: 3px; flex-wrap: wrap; margin-top: 2px; }
        .badge-pill { display: inline-flex; align-items: center; gap: 2px; padding: 1px 6px; border-radius: 999px; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .fc-bottom { border-top: 1px dashed var(--border-color); padding-top: 6px; display: flex; justify-content: flex-end; align-items: center; gap: 4px; }

        .icon-btn {
            width: 26px; height: 26px; border-radius: 5px; border: 1.5px solid transparent;
            background: transparent; display: inline-flex; align-items: center; justify-content: center;
            transition: all .2s; font-size: 12px; cursor: pointer; padding: 0; position: relative;
        }
        .icon-btn:hover { transform: scale(1.1); }
        .icon-btn.view { color: var(--color-primary); border-color: rgba(79, 70, 229, 0.2); }
        .icon-btn.view:hover { color: var(--color-primary-dark); background: var(--color-primary-soft); border-color: var(--color-primary); }
        .icon-btn.validate { color: var(--color-success); border-color: rgba(16, 185, 129, 0.2); }
        .icon-btn.validate:hover { color: #059669; background: var(--color-success-soft); border-color: var(--color-success); }
        .icon-btn.validate.validated { color: var(--color-success); background: var(--color-success-soft); border-color: var(--color-success); cursor: default; opacity: 0.7; }
        .icon-btn.validate.validated:hover { transform: none; }
        .icon-btn.delete { color: var(--color-danger); border-color: rgba(239, 68, 68, 0.2); }
        .icon-btn.delete:hover { color: #b91c1c; background: var(--color-danger-soft); border-color: var(--color-danger); }
        .icon-btn::before {
            content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%;
            transform: translateX(-50%); background: var(--color-gray-800); color: #fff;
            padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 600;
            white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity .2s; z-index: 10;
        }
        .icon-btn:hover::before { opacity: 1; }

        .stat-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base); }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

        .modal-chic .modal-content { border: none; border-radius: 20px; box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15); overflow: hidden; animation: modalSlideIn .4s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes modalSlideIn { from { opacity: 0; transform: translateY(30px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-chic .modal-header { background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%); color: #fff; border: none; padding: 22px 28px; position: relative; overflow: hidden; }
        .modal-chic .modal-header::before { content: ''; position: absolute; top: -50%; right: -20%; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%; }
        .modal-chic .modal-title { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
        .modal-chic .modal-title i { font-size: 22px; background: rgba(255,255,255,0.15); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px); }
        .modal-chic .btn-close { filter: invert(1); opacity: 0.7; position: relative; z-index: 1; transition: all .2s; }
        .modal-chic .btn-close:hover { opacity: 1; transform: rotate(90deg); }
        .modal-chic .modal-body { padding: 28px; max-height: 70vh; overflow-y: auto; background: #f8fafc; }
        .modal-chic .modal-footer { background: #fff; border-top: 1px solid var(--border-color); padding: 18px 28px; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }

        .detail-section-chic { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 20px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); transition: all .2s; }
        .detail-section-chic:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.06); border-color: #cbd5e1; }
        .detail-section-title-chic { font-size: 11px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.8px; padding-bottom: 10px; border-bottom: 2px solid #f1f5f9; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .detail-section-title-chic.info i { color: #3b82f6; }
        .detail-section-title-chic.money i { color: #10b981; }
        .detail-section-title-chic.box i { color: #f59e0b; }
        .badge-chic { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 2px 4px rgba(0,0,0,0.06); }
        .badge-chic.success { background: #d1fae5; color: #065f46; }
        .badge-chic.warning { background: #fef3c7; color: #92400e; }
        .badge-chic.danger { background: #fee2e2; color: #991b1b; }
        .badge-chic.primary { background: #dbeafe; color: #1e40af; }
        .badge-chic.secondary { background: #f1f5f9; color: #475569; }
        .badge-chic .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        .btn-chic { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; letter-spacing: -0.01em; }
        .btn-chic::before { content: ''; position: absolute; top: 50%; left: 50%; width: 0; height: 0; background: rgba(255,255,255,0.3); border-radius: 50%; transform: translate(-50%, -50%); transition: width .4s, height .4s; }
        .btn-chic:hover::before { width: 300px; height: 300px; }
        .btn-chic i { font-size: 15px; position: relative; z-index: 1; }
        .btn-chic span { position: relative; z-index: 1; }
        .btn-chic-modifier { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #fff; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); }
        .btn-chic-modifier:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4); }
        .btn-chic-imprimer { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); color: #fff; box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3); }
        .btn-chic-imprimer:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(6, 182, 212, 0.4); }
        .btn-chic-partager { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .btn-chic-partager:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); }
        .btn-chic-fermer { background: linear-gradient(135deg, #64748b 0%, #475569 100%); color: #fff; box-shadow: 0 4px 12px rgba(100, 116, 139, 0.25); }
        .btn-chic-fermer:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(100, 116, 139, 0.35); }

        .modal-table { width: 100%; font-size: 13px; }
        .modal-table thead th { background: var(--color-gray-100); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 10px 12px; }
        .modal-table tbody td { padding: 10px 12px; border-bottom: 1px solid var(--border-color); }
        .modal-table tbody tr:hover { background: var(--color-primary-soft); }

        .edit-section-chic { display: none; background: #fff; border: 1px solid var(--border-color); border-radius: 16px; padding: 28px; margin-top: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); }
        .edit-header-chic { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
        .edit-header-chic h2 { font-size: 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .edit-header-chic h2 i { color: #3b82f6; font-size: 22px; }
        .btn-retour-chic { background: #f1f5f9; color: #475569; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border-color); transition: all .2s; }
        .btn-retour-chic:hover { background: var(--color-gray-200); color: #1e293b; }
        .edit-table-chic { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; border-radius: 10px; overflow: hidden; border: 1px solid var(--border-color); }
        .edit-table-chic thead th { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; padding: 12px 14px; border-bottom: 2px solid var(--border-color); }
        .edit-table-chic tbody td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; color: #1e293b; background: #fff; }
        .edit-table-chic tbody tr:hover td { background: #f8fafc; }
        .edit-table-chic tbody tr:last-child td { border-bottom: none; }
        .btn-delete-chic { width: 32px; height: 32px; border-radius: 8px; border: 1px solid #fecaca; background: #fff; color: var(--color-danger); display: inline-flex; align-items: center; justify-content: center; transition: all .2s; }
        .btn-delete-chic:hover { background: #fee2e2; border-color: var(--color-danger); transform: scale(1.1); }
        .btn-action-chic { padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s; }
        .btn-action-chic.annuler { background: #f1f5f9; color: #475569; border: 1px solid var(--border-color); }
        .btn-action-chic.annuler:hover { background: var(--color-gray-200); }
        .btn-action-chic.enregistrer { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #fff; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        .btn-action-chic.enregistrer:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4); }

        .bootstrap-select .dropdown-toggle { background: #fff !important; border: 1.5px solid var(--border-color) !important; border-radius: 8px !important; min-width: 220px; }
        .bootstrap-select .dropdown-toggle:focus { border-color: var(--color-primary) !important; box-shadow: 0 0 0 3px var(--color-primary-soft) !important; }

        @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        .facture-card.deleting { animation: fadeOut .4s ease forwards; }
        @keyframes fadeOut { to { opacity: 0; transform: scale(0.9); } }
        @media (max-width: 700px) {
            .bootstrap-select, .bootstrap-select .dropdown-toggle { width: 100% !important; min-width: 0 !important; }
        }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-box-arrow-in-down text-primary me-2"></i>Suivi des Achats Fournisseur</h1>
            <p class="text-muted small mb-0">Suivez tous vos achats fournisseur et l'entrée en stock associée</p>
        </div>
        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
            <i class="bi bi-truck"></i> <?= $totalAchats ?> achat(s)
        </span>
    </div>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['primary', 'truck', 'Total achats', $totalAchats, ''],
            ['success', 'check-circle-fill', 'Réglés', $payees, ''],
            ['warning', 'hourglass-split', 'Partiels', $partielles, ''],
            ['danger', 'x-circle-fill', 'Impayés', $impayees, ''],
            ['purple', 'cash-coin', 'Total TTC', number_format($totalMontant, 0, ',', ' '), ' FCFA'],
            ['info', 'wallet2', 'Reste à devoir', number_format($totalReste, 0, ',', ' '), ' FCFA'],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
            'danger' => ['var(--color-danger-soft)', 'var(--color-danger)'],
            'purple' => ['var(--color-purple-soft)', 'var(--color-purple)'],
            'info' => ['var(--color-info-soft)', 'var(--color-info)'],
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
    <div class="bg-white border rounded-3 p-3 mb-4 shadow-sm">
        <form id="searchForm" onsubmit="return false;">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <label for="fournisseurFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-truck"></i> Fournisseur</label>
                <select id="fournisseurFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un fournisseur...">
                    <option value="">Tous les fournisseurs</option>
                    <?php foreach ($fournisseurs as $f): ?>
                        <option value="<?= htmlspecialchars($f['code_contact']) ?>"><?= htmlspecialchars($f['nom_prenom_contact']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="etatFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-cash-coin"></i> État</label>
                <select id="etatFilter" class="selectpicker">
                    <option value="">Tous les états</option>
                    <option value="Impayee">Impayé</option>
                    <option value="Partielle">Partiel</option>
                    <option value="Payee cash">Réglé cash</option>
                    <option value="Payee">Réglé</option>
                </select>
                <label for="statutFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-check2-square"></i> Statut</label>
                <select id="statutFilter" class="selectpicker">
                    <option value="">Tous les statuts</option>
                    <option value="En attente">En attente (Bon)</option>
                    <option value="Validee">Validée (Facture)</option>
                    <option value="Annule">Annulée</option>
                </select>
                <button type="button" class="btn btn-primary fw-bold" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn btn-outline-secondary fw-semibold" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <!-- Liste des achats -->
    <div class="row g-3" id="facturesGrid">
        <?php
        $sql = "SELECT f.*, c.nom_prenom_contact FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Fournisseur' ORDER BY f.date_facture DESC";
        $factures = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (empty($factures)):
        ?>
        <div class="col-12">
            <div class="bg-white border border-dashed rounded-3 p-5 text-center text-muted">
                <i class="bi bi-inbox d-block mb-2" style="font-size:56px;opacity:.2;"></i>
                <h5 class="text-dark">Aucun achat trouvé</h5>
                <p class="small mb-0">Les achats apparaîtront ici dès leur enregistrement.</p>
            </div>
        </div>
        <?php else: foreach($factures as $row):
            $etatBadge = getEtatBadge($row['etat_facture']);
            $isValidee = (strtolower($row['statut_facture']) === 'validee');
        ?>
        <div class="col-12 col-md-6 col-lg-4 col-xl-3 facture-item"
             data-client="<?= htmlspecialchars($row['contact_id'] ?? '') ?>"
             data-etat="<?= htmlspecialchars($row['etat_facture'] ?? '') ?>"
             data-statut="<?= htmlspecialchars($row['statut_facture'] ?? '') ?>"
             data-id="<?= htmlspecialchars($row['numero_facture']) ?>">
            <div class="facture-card <?= $isValidee ? 'validated' : '' ?>">
                <div class="fc-top">
                    <div>
                        <div class="fc-number"><?= htmlspecialchars($row['numero_facture']) ?></div>
                        <div class="fc-date"><i class="bi bi-calendar3"></i> <?= date('d/m/Y', strtotime($row['date_facture'])) ?></div>
                    </div>
                    <div class="fc-amount"><?= number_format($row['montant_ttc'], 0, ',', ' ') ?><small>FCFA</small></div>
                </div>
                <div class="fc-middle">
                    <div class="fc-client">
                        <i class="bi bi-truck"></i>
                        <span><?= htmlspecialchars($row['nom_prenom_contact'] ?? 'N/C') ?></span>
                    </div>
                    <div class="fc-badges">
                        <span class="badge-pill bg-<?= $etatBadge[0] ?>-subtle text-<?= $etatBadge[0] ?>">
                            <i class="bi bi-<?= $etatBadge[1] ?>" style="font-size:8px;"></i> <?= $row['etat_facture'] ?>
                        </span>
                        <span class="badge-pill bg-<?= getStatutBadge($row['statut_facture']) ?>-subtle text-<?= getStatutBadge($row['statut_facture']) ?>">
                            <i class="bi bi-<?= $isValidee ? 'check2' : 'clock' ?>" style="font-size:8px;"></i> <?= $row['statut_facture'] ?>
                        </span>
                    </div>
                </div>
                <div class="fc-bottom">
                    <button class="icon-btn view voir-facture" data-id="<?= $row['numero_facture'] ?>" data-tooltip="Voir détails" title="Voir détails"><i class="bi bi-eye"></i></button>
                    <?php if ($_SESSION['role'] === 'Administrateur' || $_SESSION['role'] === 'Superviseur'): ?>
                    <?php if (!$isValidee): ?>
                        <button class="icon-btn validate valider-facture" data-id="<?= $row['numero_facture'] ?>" data-tooltip="Valider" title="Valider"><i class="bi bi-check2-circle"></i></button>
                    <?php else: ?>
                        <button class="icon-btn validate validated" disabled data-tooltip="Validée" title="Validée"><i class="bi bi-check-circle-fill"></i></button>
                    <?php endif; ?>
                    <button class="icon-btn delete supprimer-facture" data-id="<?= $row['numero_facture'] ?>" data-tooltip="Supprimer" title="Supprimer"><i class="bi bi-trash"></i></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- Modal détails chic -->
<div class="modal fade modal-chic" id="factureModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt-cutoff"></i><span id="modalTitleText">Détails de l'achat</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="factureDetails">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Chargement...</span></div>
                    <p class="mt-3 text-muted small">Chargement des détails...</p>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($_SESSION['role'] === 'Administrateur' || $_SESSION['role'] === 'Superviseur'): ?>
                <button class="btn-chic btn-chic-modifier" id="btnModifier"><i class="bi bi-pencil-square"></i><span>Modifier</span></button>
                <?php endif; ?>
                <button class="btn-chic btn-chic-imprimer" id="btnImprimer"><i class="bi bi-printer-fill"></i><span>Imprimer</span></button>
                <button class="btn-chic btn-chic-partager" hidden id="btnPartager"><i class="bi bi-whatsapp"></i><span>Partager</span></button>
                <button class="btn-chic btn-chic-fermer" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i><span>Fermer</span></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal confirmation suppression -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Confirmer la suppression</h5>
                <p class="text-muted small mb-4">Êtes-vous sûr de vouloir supprimer l'achat <strong id="deleteFactureId" class="text-danger"></strong> ?<br>Le stock entré sera automatiquement retiré.<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger rounded-3" id="confirmDeleteBtn"><i class="bi bi-trash3 me-1"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal confirmation générique (remplace window.confirm()) -->
<div class="modal fade" id="genericConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-question-circle-fill text-primary" style="font-size: 3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Confirmation</h5>
                <p class="text-muted small mb-4" id="genericConfirmMsg"></p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-primary rounded-3" id="genericConfirmOkBtn">Confirmer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Section édition chic -->
<div id="editSection" class="edit-section-chic">
    <div class="edit-header-chic">
        <h2><i class="bi bi-pencil-square"></i> Modification de l'achat</h2>
        <button class="btn-retour-chic" id="btnRetourListe"><i class="bi bi-arrow-left"></i> Retour</button>
    </div>
    <div id="editContent"></div>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function() {
    $('.selectpicker').selectpicker();
    const toastEl = document.getElementById('toastMsg');
    const toast = new bootstrap.Toast(toastEl, { delay: 2500 });
    const baseUrl = window.location.pathname;
    // Catégories et produits disponibles pour l'ajout de nouvelles lignes lors
    // de la modification d'un achat (mêmes données que entree_stock.php).
    const CATEGORIES_ACHAT = <?= json_encode($categoriesAchat, JSON_UNESCAPED_UNICODE) ?>;
    const PRODUITS_ACHAT = <?= json_encode($produitsAchat, JSON_UNESCAPED_UNICODE) ?>;
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    let factureToDelete = null;

    // Remplace window.confirm() par la modal Bootstrap générique
    const genericConfirmModalEl = document.getElementById('genericConfirmModal');
    const genericConfirmModal = new bootstrap.Modal(genericConfirmModalEl);
    function genericConfirm(message, onOk) {
        $('#genericConfirmMsg').text(message);
        const okBtn = document.getElementById('genericConfirmOkBtn');
        const newOkBtn = okBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(newOkBtn, okBtn);
        newOkBtn.addEventListener('click', function() {
            genericConfirmModal.hide();
            onOk();
        });
        genericConfirmModal.show();
    }

    function showToast(msg, type = 'success') {
        const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-primary' };
        const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        $('#toastBody').html(`<i class="bi ${icons[type]} me-2"></i>${msg}`);
        toastEl.className = `toast align-items-center text-white border-0 ${colors[type]}`;
        toast.show();
    }

    // Valider achat
    $(document).on('click', '.valider-facture', function(e) {
        e.stopPropagation();
        const btn = $(this), id = btn.data('id'), card = btn.closest('.facture-card');
        genericConfirm('Confirmer que la marchandise de l\'achat ' + id + ' a été physiquement reçue ? Cette action va créditer le stock.', function() {
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i>');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'validate_facture', id: id },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    showToast('Achat ' + id + ' validé — stock crédité');
                    card.addClass('validated');
                    card.find('.fc-badges .badge-pill').last().removeClass('bg-secondary-subtle text-secondary').addClass('bg-primary-subtle text-primary').html('<i class="bi bi-check2" style="font-size:8px;"></i> VALIDEE');
                    card.find('.fc-number').css('color', '#059669');
                    btn.replaceWith('<button class="icon-btn validate validated" disabled data-tooltip="Validée" title="Validée"><i class="bi bi-check-circle-fill"></i></button>');
                } else {
                    showToast('Erreur : ' + (resp.error|| 'Inconnue'), 'error');
                    btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i>');
                }
            },
            error: function() {
                showToast('Erreur de communication', 'error');
                btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i>');
            }
        });
        });
    });

    // Supprimer achat
    $(document).on('click', '.supprimer-facture', function(e) {
        e.stopPropagation();
        factureToDelete = $(this).data('id');
        $('#deleteFactureId').text(factureToDelete);
        deleteModal.show();
    });

    $('#confirmDeleteBtn').on('click', function() {
        if (!factureToDelete) return;
        const btn = $(this);
        const id = factureToDelete;
        const cardItem = $('.facture-item[data-id="' + id + '"]');
        const card = cardItem.find('.facture-card');
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Suppression...');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'delete_facture', id: id },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    deleteModal.hide();
                    showToast('Achat ' + id + ' supprimé', 'success');
                    card.addClass('deleting');
                    setTimeout(function() {
                        cardItem.fadeOut(300, function() {
                            $(this).remove();
                            if ($('.facture-item').length === 0) {
                                $('#facturesGrid').html(`<div class="col-12"><div class="bg-white border border-dashed rounded-3 p-5 text-center text-muted"><i class="bi bi-inbox d-block mb-2" style="font-size:56px;opacity:.2;"></i><h5 class="text-dark">Aucun achat trouvé</h5><p class="small mb-0">Les achats apparaîtront ici dès leur enregistrement.</p></div></div>`);
                            }
                        });
                    }, 400);
                    factureToDelete = null;
                    btn.prop('disabled', false).html('<i class="bi bi-trash3 me-1"></i> Supprimer');
                } else {
                    showToast('Erreur : ' + (resp.error|| 'Inconnue'), 'error');
                    btn.prop('disabled', false).html('<i class="bi bi-trash3 me-1"></i> Supprimer');
                }
            },
            error: function() {
                showToast('Erreur de communication', 'error');
                btn.prop('disabled', false).html('<i class="bi bi-trash3 me-1"></i> Supprimer');
            }
        });
    });

    // Filtres
    $('#filterBtn').on('click', function() {
        const selClient = $('#fournisseurFilter').val();
        const selEtat = $('#etatFilter').val();
        const selStatut = $('#statutFilter').val();
        let count = 0;
        $('.facture-item').each(function() {
            const matchClient = (selClient === '' || String($(this).data('client')) === String(selClient));
            const matchEtat = (selEtat === '' || String($(this).data('etat')) === String(selEtat));
            const matchStatut = (selStatut === '' || String($(this).data('statut')) === String(selStatut));
            if (matchClient && matchEtat && matchStatut) {
                $(this).show();
                count++;
            } else {
                $(this).hide();
            }
        });
        showToast(count + ' achat(s) affiché(s)', 'info');
    });

    $('#resetBtn').on('click', function() {
        $('#fournisseurFilter').selectpicker('val', '');
        $('#etatFilter').selectpicker('val', '');
        $('#statutFilter').selectpicker('val', '');
        $('.facture-item').show();
        showToast('Filtres réinitialisés', 'info');
    });

    // Voir détails
    $(document).on('click', '.voir-facture', function(e) {
        e.stopPropagation();
        const id = $(this).data('id');
        $('#modalTitleText').text('Achat ' + id);
        $('#factureDetails').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted small">Chargement...</p></div>');
        $('#btnModifier').prop('disabled', false).removeClass('disabled').css('opacity', '1').attr('title', 'Modifier');
        $('#factureModal').modal('show');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'get_details', id: id },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#factureDetails').html('<div class="alert alert-danger">' + data.error + '</div>');
                    return;
                }
                const f = data.facture;
                const etatColor = f.etat_facture === 'Payee' || f.etat_facture === 'Payee cash' ? 'success' : (f.etat_facture === 'Partielle' ? 'warning' : 'danger');
                const statutColor = (f.statut_facture === 'Validee') ? 'primary' : 'secondary';
                let html = `<div class="detail-section-chic">
                    <div class="detail-section-title-chic info"><i class="bi bi-info-circle-fill"></i> INFORMATIONS GÉNÉRALES</div>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">N° ACHAT</div><div class="fw-bold" style="color:#1e293b;font-size:15px;">${f.numero_facture}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">DATE</div><div class="fw-semibold">${new Date(f.date_facture).toLocaleDateString('fr-FR')}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">FOURNISSEUR</div><div class="fw-semibold">${f.nom_prenom_contact|| 'N/C'}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">TYPE DOCUMENT</div><div class="fw-semibold">${f.categorie_facture}</div></div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <span class="badge-chic ${etatColor}"><span class="dot"></span> ${f.etat_facture}</span>
                        <span class="badge-chic ${statutColor}"><span class="dot"></span> ${f.statut_facture}</span>
                    </div>
                </div>
                <div class="detail-section-chic">
                    <div class="detail-section-title-chic money"><i class="bi bi-cash-stack"></i> MONTANTS</div>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">MONTANT TOTAL</div><div class="fw-bold" style="color:#4f46e5;font-size:18px;font-family:'Outfit',sans-serif;">${Number(f.montant_ttc).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">DÉJÀ VERSÉ</div><div class="fw-bold text-success">${Number(f.avance).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-12"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">RESTE DÛ AU FOURNISSEUR</div><div class="fw-bold text-danger">${Number(f.reste).toLocaleString('fr-FR')} FCFA</div></div>
                    </div>
                    <div class="mt-2 small text-muted"><i class="bi bi-info-circle"></i> Le règlement de ce fournisseur se gère depuis votre interface de règlement dédiée.</div>
                </div>`;
                if (data.commandes.length > 0) {
                    html += `<div class="detail-section-chic">
                        <div class="detail-section-title-chic box"><i class="bi bi-box-seam-fill"></i> LIGNES D'ACHAT (${data.commandes.length})</div>
                        <div class="table-responsive"><table class="modal-table"><thead><tr><th>Produit</th><th class="text-center">Qté</th><th class="text-end">Prix d'achat</th><th class="text-end">Montant</th></tr></thead><tbody>`;
                    data.commandes.forEach(c => {
                        const ppl = parseInt(c.produits_par_lot) || 1;
                        let qteTxt;
                        if (ppl > 1) {
                            const nbLots = Math.floor(c.quantite_commande / ppl);
                            const reste = c.quantite_commande % ppl;
                            const lib = c.libelle_lot || 'Lot';
                            qteTxt = reste > 0 ? `${nbLots} ${lib}(s) et ${reste} Pièce(s)` : `${nbLots} ${lib}(s)`;
                        } else {
                            qteTxt = `${c.quantite_commande} Pièce(s)`;
                        }
                        html += `<tr><td class="fw-semibold">${c.titre_produit}</td><td class="text-center">${qteTxt}</td><td class="text-end">${Number(c.prix_achat).toLocaleString('fr-FR')} FCFA</td><td class="text-end fw-bold">${Number(c.montant_commande).toLocaleString('fr-FR')} FCFA</td></tr>`;
                    });
                    html += '</tbody></table></div></div>';
                }
                $('#factureDetails').html(html).data('facture-id', f.numero_facture);
                if (data.is_locked) {
                    $('#btnModifier').prop('disabled', true).addClass('disabled').css('opacity', '0.5').attr('title', 'Achat réglé intégralement, modification impossible');
                }
            },
            error: function() {
                $('#factureDetails').html('<div class="alert alert-danger">Erreur de chargement.</div>');
            }
        });
    });

    $('#btnModifier').click(function() {
        if ($(this).prop('disabled')) return;
        const id = $('#factureDetails').data('facture-id');
        if (id) {
            $('#factureModal').modal('hide');
            chargerEdition(id);
        }
    });

    function submitPdfPost(id, targetSelf) {
        const params = new URLSearchParams(window.location.search);
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = baseUrl;
        if (targetSelf) form.target = '_self';
        const addField = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = name; input.value = value;
            form.appendChild(input);
        };
        params.forEach((value, key) => addField(key, value));
        addField('action', 'pdf');
        addField('id', id);
        document.body.appendChild(form);
        form.submit();
        form.remove();
    }

    $('#btnImprimer').click(function() {
        const id = $('#factureDetails').data('facture-id');
        if (id) submitPdfPost(id, true);
    });

    $('#btnPartager').click(async function() {
        const id = $('#factureDetails').data('facture-id');
        if (!id) return;
        const btn = $(this);
        const originalHtml = btn.html();
        const params = new URLSearchParams(window.location.search);
        params.set('action', 'pdf');
        params.set('id', id);
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i><span>Préparation...</span>');
        try {
            const resp = await fetch(baseUrl, { method: 'POST', body: params });
            if (!resp.ok) throw new Error('pdf_fetch_failed');
            const blob = await resp.blob();
            if (blob.type && blob.type.indexOf('pdf') === -1) throw new Error('not_a_pdf');
            const file = new File([blob], 'achat-' + id + '.pdf', { type: 'application/pdf' });
            if (window.isSecureContext && navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                await navigator.share({
                    files: [file],
                    title: 'Achat N°' + id,
                    text: 'Bon fournisseur N°' + id
                });
                btn.prop('disabled', false).html(originalHtml);
                return;
            }
        } catch (e) {
            if (e && e.name === 'AbortError') {
                btn.prop('disabled', false).html(originalHtml);
                return;
            }
        }
        btn.prop('disabled', false).html(originalHtml);
        window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent('Bon fournisseur N°' + id), '_blank');
    });

    function chargerEdition(id) {
        $('#editSection').show();
        $('#editContent').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted small">Chargement...</p></div>');
        $('html, body').animate({ scrollTop: $('#editSection').offset().top - 20 }, 400);
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'get_details', id: id },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#editContent').html('<div class="alert alert-danger">' + data.error + '</div>');
                    return;
                }
                if (data.is_locked) {
                    $('#editContent').html('<div class="alert alert-warning"><i class="bi bi-lock-fill me-2"></i>Cet achat est réglé intégralement. Il ne peut plus être modifié.</div>');
                    return;
                }
                afficherFormulaireEdition(data);
            },
            error: function() {
                $('#editContent').html('<div class="alert alert-danger">Erreur de chargement.</div>');
            }
        });
    }

    function afficherFormulaireEdition(data) {
        const f = data.facture;
        const avanceFixe = parseFloat(f.avance) || 0;
        let html = `<div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">MONTANT TOTAL</label>
                <input type="text" class="form-control form-control-lg" id="edit_montant_ttc" value="${Number(f.montant_ttc).toLocaleString('fr-FR')}" readonly style="background:#f8fafc;font-weight:700;color:#4f46e5;">
            </div>
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">DÉJÀ VERSÉ</label>
                <input type="text" class="form-control form-control-lg" value="${Number(f.avance).toLocaleString('fr-FR')}" readonly style="background:#f8fafc;font-weight:700;color:#10b981;">
            </div>
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">RESTE DÛ</label>
                <input type="text" class="form-control form-control-lg" id="edit_reste" value="${Number(f.reste).toLocaleString('fr-FR')}" readonly style="background:#f8fafc;font-weight:700;color:#ef4444;">
            </div>
            <div class="col-12"><div class="small text-muted"><i class="bi bi-info-circle"></i> Le règlement (avance/reste) se gère depuis votre interface dédiée. Ici, seules les lignes achetées sont modifiables — le montant total et le reste dû se recalculent automatiquement.</div></div>
        </div>
        <h6 class="mb-3 fw-bold" style="color:#1e293b;display:flex;align-items:center;gap:8px;"><i class="bi bi-box-seam-fill text-warning"></i> Lignes d'achat</h6>
        <div class="table-responsive">
            <table class="edit-table-chic" id="editLignesTable">
                <thead><tr><th>Pièce</th><th class="text-center">Qté</th><th class="text-end">Prix d'achat</th><th class="text-end">Montant</th><th class="text-center">Action</th></tr></thead>
                <tbody>`;
        data.commandes.forEach(c => {
            html += `<tr class="ligne-commande" data-id="${c.numero_commande}">
                <td class="fw-semibold">${c.titre_produit}</td>
                <td class="text-center"><input type="number" class="form-control form-control-sm qte" value="${c.quantite_commande}" min="0" style="width:80px;display:inline-block;text-align:center;"></td>
                <td class="text-end"><input type="number" class="form-control form-control-sm prix" value="${c.prix_achat}" min="0" style="width:120px;display:inline-block;text-align:right;"></td>
                <td class="text-end montant-ligne fw-bold">${Number(c.montant_commande).toLocaleString('fr-FR')}</td>
                <td class="text-center"><button class="btn-delete-chic supprimer-ligne" title="Supprimer"><i class="bi bi-trash3"></i></button></td>
            </tr>`;
        });
        html += `</tbody></table></div>
        <div class="mt-4 p-3" style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;">
            <h6 class="mb-3 fw-bold" style="color:#1e293b;display:flex;align-items:center;gap:8px;"><i class="bi bi-plus-circle text-primary"></i> Ajouter un produit</h6>
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Catégorie</label>
                    <select id="newLigneCategorie" class="form-select selectpicker" data-live-search="true">
                        <option value="">-- Catégorie --</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Produit</label>
                    <select id="newLigneProduit" class="form-select selectpicker" data-live-search="true" disabled title="-- Choisir d'abord une catégorie --">
                        <option value="">-- Produit --</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Quantité</label>
                    <input type="number" id="newLigneQte" class="form-control" min="1" step="1" placeholder="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Prix d'achat</label>
                    <input type="number" id="newLignePrix" class="form-control" min="0" step="0.01" placeholder="0.00">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn-action-chic enregistrer w-100" id="btnAjouterLigne"><i class="bi bi-plus-lg"></i> Ajouter</button>
                </div>
            </div>
            <div class="small text-muted mt-2" id="newLigneStockInfo">Stock actuel : —</div>
            <div class="mt-2" style="font-size:12px;color:#64748b;">
                <label style="cursor:pointer;">
                    <input type="checkbox" id="newLigneLotConfigure"> Configurer un lot pour cette ligne
                </label>
                <span id="newLigneLotDetails" style="display:none;margin-left:10px;">
                    <input type="number" id="newLigneLotUnites" min="2" step="1" value="2" style="width:56px;" title="Unités par lot"> unité(s) par
                    <select id="newLigneLotLibelle" style="display:inline-block;width:auto;">
                        ${['Boîte', 'Palette', 'Carton', 'Bidon', 'Unité'].map(l => `<option value="${l}">${l}</option>`).join('')}
                    </select>
                    — <strong id="newLigneLotApercu"></strong>
                </span>
            </div>
        </div>
        <div class="mt-4 d-flex gap-2 justify-content-end">
            <button class="btn-action-chic annuler" id="btnAnnulerEdit"><i class="bi bi-x-lg"></i> Annuler</button>
            <button class="btn-action-chic enregistrer" id="btnEnregistrerEdit"><i class="bi bi-check-lg"></i> Enregistrer</button>
        </div>`;
        $('#editContent').html(html).data('facture-id', f.numero_facture).data('boutique-id', data.boutique_id || '');

        // ------------------------------------------------------------
        // Recalcul en direct du montant total et du reste (pas de taxe/remise
        // ici : le montant d'un achat = somme brute des lignes).
        // ------------------------------------------------------------
        function recalculerTotaux() {
            let totalLignes = 0;
            $('#editLignesTable tbody tr').each(function() {
                const row = $(this);
                const qte = parseFloat(row.find('.qte').val()) || 0;
                const prix = parseFloat(row.find('.prix').val()) || 0;
                totalLignes += qte * prix;
            });
            $('#edit_montant_ttc').val(totalLignes.toLocaleString('fr-FR'));
            $('#edit_reste').val(Math.max(0, Math.round((totalLignes - avanceFixe) * 100) / 100).toLocaleString('fr-FR'));
        }

        // ------------------------------------------------------------
        // Panneau d'ajout de produit : catégorie -> produit (filtré).
        // ------------------------------------------------------------
        const $newCat = $('#newLigneCategorie');
        const $newProd = $('#newLigneProduit');
        CATEGORIES_ACHAT.forEach(function(c) {
            $newCat.append($('<option>', { value: c.code_categorie, text: c.titre_categorie }));
        });

        function filtrerProduitsNouvelleLigne() {
            const cat = String($newCat.val() || '').trim();
            $newProd.empty();
            $newProd.append($('<option>', { value: '', text: '-- Choisir un produit --' }));
            if (cat === '') {
                $newProd.prop('disabled', true).attr('title', "-- Choisir d'abord une catégorie --");
            } else {
                PRODUITS_ACHAT.forEach(function(p) {
                    if (String(p.categorie_id || '') === cat) {
                        const suffixe = (p.etat_produit === 'RUPTURE') ? ' (rupture)' : '';
                        $newProd.append($('<option>', {
                            value: p.code_produit,
                            text: p.titre_produit + suffixe,
                            'data-prix': p.prix_fournisseur || 0
                        }));
                    }
                });
                $newProd.prop('disabled', false).attr('title', '-- Choisir un produit --');
            }
            if ($newProd.hasClass('bs-select-hidden') || $newProd.data('selectpicker')) { $newProd.selectpicker('destroy'); }
            $newProd.selectpicker();
            $newProd.off('changed.bs.select change').on('changed.bs.select change', function() {
                // Filet de sécurité : sur bootstrap-select 1.14 beta, après une
                // reconstruction dynamique des options, le libellé du bouton ne se
                // resynchronise pas toujours tout seul lors d'une sélection — on
                // force le refresh ET on réécrit le texte affiché à la main.
                $newProd.selectpicker('refresh');
                const texteChoisi = $newProd.find('option:selected').text();
                $newProd.parent().find('.filter-option-inner-inner').text(texteChoisi);
                updateNouvelleLigneStock();
            });
            $('#newLigneStockInfo').text('Stock actuel : —');
        }

        function updateNouvelleLigneStock() {
            const produit = $newProd.val();
            const boutique = $('#editContent').data('boutique-id');
            if (!produit) { $('#newLigneStockInfo').text('Stock actuel : —'); return; }
            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: { action: 'check_stock_produit', produit_id: produit, boutique_id: boutique },
                success: function(resp) {
                    if (resp.success) {
                        $('#newLigneStockInfo').html('Stock actuel : <strong>' + resp.disponible + '</strong>' + (boutique ? '' : ' (boutique de réception inconnue)'));
                        if (!$('#newLignePrix').val() && resp.prix > 0) $('#newLignePrix').val(resp.prix);
                    } else {
                        $('#newLigneStockInfo').text('Stock actuel : —');
                    }
                }
            });
        }

        $newCat.selectpicker();
        $newCat.on('changed.bs.select change', filtrerProduitsNouvelleLigne);
        filtrerProduitsNouvelleLigne();

        // ------------------------------------------------------------
        // Configuration de lot pour la nouvelle ligne (même principe que
        // dans achat.php) : optionnelle — si non cochée, comportement
        // "produit simple" (pas de lot, produits_par_lot = 1).
        // ------------------------------------------------------------
        function majApercuLotNouvelleLigne() {
            const qte = parseInt($('#newLigneQte').val()) || 0;
            const unites = Math.max(2, parseInt($('#newLigneLotUnites').val()) || 2);
            const libelle = $('#newLigneLotLibelle').val();
            const nbLots = Math.floor(qte / unites);
            const reste = qte % unites;
            const apercu = reste > 0 ? `${nbLots} ${libelle}(s) et ${reste} Produit(s)` : `${nbLots} ${libelle}(s)`;
            $('#newLigneLotApercu').text(apercu);
        }
        $('#newLigneLotConfigure').on('change', function() {
            $('#newLigneLotDetails').toggle(this.checked);
            if (this.checked) majApercuLotNouvelleLigne();
        });
        $('#newLigneLotUnites, #newLigneLotLibelle, #newLigneQte').on('input change', majApercuLotNouvelleLigne);

        $('#btnAjouterLigne').click(function() {
            const produit = $newProd.val();
            const produitTexte = $newProd.find('option:selected').text();
            const boutique = $('#editContent').data('boutique-id');
            const qte = parseInt($('#newLigneQte').val()) || 0;
            const prixSaisi = parseFloat($('#newLignePrix').val()) || 0;
            const lotConfigure = $('#newLigneLotConfigure').is(':checked');
            const unitesParLot = Math.max(2, parseInt($('#newLigneLotUnites').val()) || 2);
            const libelleLot = $('#newLigneLotLibelle').val() || 'Unité';

            if (!produit) { showToast('Choisissez une catégorie puis un produit.', 'error'); return; }
            if (qte <= 0) { showToast('Saisissez une quantité valide (> 0).', 'error'); return; }

            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: { action: 'check_stock_produit', produit_id: produit, boutique_id: boutique },
                success: function(resp) {
                    const titreProduit = (resp.success && resp.titre) ? resp.titre : produitTexte;
                    const prixFinal = prixSaisi > 0 ? prixSaisi : (resp.prix || 0);
                    const montant = qte * prixFinal;
                    let sousTitreLot = '';
                    if (lotConfigure) {
                        const nbLots = Math.floor(qte / unitesParLot);
                        const resteLot = qte % unitesParLot;
                        const apercuLot = resteLot > 0 ? `${nbLots} ${libelleLot}(s) et ${resteLot} Produit(s)` : `${nbLots} ${libelleLot}(s)`;
                        sousTitreLot = `<br><span class="small text-muted">Lot : ${unitesParLot} unité(s) par ${libelleLot} — ${apercuLot}</span>`;
                    }
                    const row = `<tr class="ligne-commande ligne-nouvelle" data-id="" data-produit="${produit}" data-boutique="${boutique}"
                        data-lot-configure="${lotConfigure ? '1' : '0'}" data-unites-par-lot="${unitesParLot}" data-libelle-lot="${escHtml(libelleLot)}">
                        <td class="fw-semibold">${escHtml(titreProduit)} <span class="badge bg-primary-subtle text-primary" style="font-size:9px;">nouveau</span>${sousTitreLot}</td>
                        <td class="text-center"><input type="number" class="form-control form-control-sm qte" value="${qte}" min="0" style="width:80px;display:inline-block;text-align:center;"></td>
                        <td class="text-end"><input type="number" class="form-control form-control-sm prix" value="${prixFinal}" min="0" style="width:120px;display:inline-block;text-align:right;"></td>
                        <td class="text-end montant-ligne fw-bold">${montant.toLocaleString('fr-FR')}</td>
                        <td class="text-center"><button class="btn-delete-chic supprimer-ligne" title="Supprimer"><i class="bi bi-trash3"></i></button></td>
                    </tr>`;
                    $('#editLignesTable tbody').append(row);
                    // On ne réinitialise QUE le produit / quantité / prix / lot : la
                    // catégorie reste sélectionnée pour enchaîner rapidement l'ajout de
                    // plusieurs lignes. On repasse par filtrerProduitsNouvelleLigne()
                    // (reconstruction complète) plutôt que 'selectpicker(val, "")', qui
                    // ne réaffiche pas toujours correctement le placeholder sur cette
                    // version du plugin.
                    $('#newLigneQte, #newLignePrix').val('');
                    $('#newLigneLotConfigure').prop('checked', false);
                    $('#newLigneLotDetails').hide();
                    $('#newLigneLotUnites').val(2);
                    $('#newLigneLotLibelle').val('Unité');
                    filtrerProduitsNouvelleLigne();
                    recalculerTotaux();
                    showToast('Ligne ajoutée : ' + titreProduit + '. Vous pouvez continuer à ajouter d\'autres lignes.', 'success');
                },
                error: function() { showToast('Erreur lors de l\'ajout de la ligne.', 'error'); }
            });
        });

        $(document).on('change', '#editLignesTable .qte, #editLignesTable .prix', function() {
            const row = $(this).closest('tr');
            const montant = (parseFloat(row.find('.qte').val()) || 0) * (parseFloat(row.find('.prix').val()) || 0);
            row.find('.montant-ligne').text(montant.toLocaleString('fr-FR'));
            recalculerTotaux();
        });
        $(document).on('click', '#editLignesTable .supprimer-ligne', function() {
            const row = $(this).closest('tr');
            const estNouvelle = row.hasClass('ligne-nouvelle');
            genericConfirm('Supprimer cette ligne ? Le stock correspondant sera retiré.', function() {
                if (estNouvelle) {
                    // Ligne pas encore enregistrée en base : on peut la retirer
                    // complètement, elle ne sera jamais envoyée au serveur.
                    row.remove();
                } else {
                    // Ligne existante : on ne la retire PAS du DOM, sinon elle n'est
                    // plus envoyée au serveur et n'est donc jamais supprimée en base
                    // (le stock qu'elle avait crédité ne serait jamais retiré non plus).
                    // On la marque à quantité 0 (convention déjà gérée côté serveur :
                    // quantité 0 = suppression + retrait du stock), avec possibilité
                    // d'annuler avant l'enregistrement.
                    row.data('qte-avant-suppression', row.find('.qte').val());
                    row.data('prix-avant-suppression', row.find('.prix').val());
                    row.find('.qte').val(0);
                    row.addClass('ligne-a-supprimer');
                    row.css({ opacity: 0.5, textDecoration: 'line-through' });
                    row.find('input').prop('disabled', true);
                    row.find('.montant-ligne').text('0');
                    row.find('.supprimer-ligne')
                        .removeClass('supprimer-ligne').addClass('annuler-suppression-ligne')
                        .attr('title', 'Annuler la suppression')
                        .html('<i class="bi bi-arrow-counterclockwise"></i>');
                }
                recalculerTotaux();
            });
        });
        $(document).on('click', '#editLignesTable .annuler-suppression-ligne', function() {
            const row = $(this).closest('tr');
            row.removeClass('ligne-a-supprimer').css({ opacity: '', textDecoration: '' });
            row.find('input').prop('disabled', false);
            row.find('.qte').val(row.data('qte-avant-suppression') || 0);
            row.find('.prix').val(row.data('prix-avant-suppression') || 0);
            const qte = parseFloat(row.find('.qte').val()) || 0;
            const prix = parseFloat(row.find('.prix').val()) || 0;
            row.find('.montant-ligne').text((qte * prix).toLocaleString('fr-FR'));
            row.find('.annuler-suppression-ligne')
                .removeClass('annuler-suppression-ligne').addClass('supprimer-ligne')
                .attr('title', 'Supprimer')
                .html('<i class="bi bi-trash3"></i>');
            recalculerTotaux();
        });
        $('#btnAnnulerEdit').click(function() {
            $('#editSection').hide();
            $('#editContent').empty();
            location.reload();
        });
        $('#btnEnregistrerEdit').click(function() {
            const commandes = [];
            const nouvellesLignes = [];
            $('#editLignesTable tbody tr').each(function() {
                const row = $(this);
                const qte = parseFloat(row.find('.qte').val()) || 0;
                const id = row.data('id');
                if (row.hasClass('ligne-nouvelle') || !id) {
                    if (qte > 0) {
                        nouvellesLignes.push({
                            produit_id: row.data('produit'),
                            boutique_id: row.data('boutique'),
                            quantite: qte,
                            prix: parseFloat(row.find('.prix').val()) || 0,
                            lot_configure: row.data('lot-configure') == 1 || row.data('lot-configure') === '1',
                            unites_par_lot: row.data('unites-par-lot') || 1,
                            libelle_lot: row.data('libelle-lot') || 'Unité'
                        });
                    }
                } else {
                    commandes.push({
                        id: id,
                        quantite: qte,
                        prix: parseFloat(row.find('.prix').val()) || 0,
                        supprimer: qte === 0
                    });
                }
            });
            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: {
                    action: 'update_achat',
                    facture_id: $('#editContent').data('facture-id'),
                    commandes: JSON.stringify(commandes),
                    nouvelles_lignes: JSON.stringify(nouvellesLignes)
                },
                success: function(resp) {
                    if (resp.success) {
                        showToast(resp.message || 'Achat mis à jour, stock ajusté');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast('Erreur : ' + (resp.error || 'Inconnue'), 'error');
                    }
                },
                error: function() {
                    showToast('Erreur de communication', 'error');
                }
            });
        });
    }

    $('#btnRetourListe').click(function() {
        $('#editSection').hide();
        $('#editContent').empty();
        location.reload();
    });
});
</script>
</body>
</html>