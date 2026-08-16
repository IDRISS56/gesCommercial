<?php
// ==========================================
// 1. CONNEXION À LA BASE DE DONNÉES
// ==========================================
require 'databases/database.php';
require 'librairies/fpdf/fpdf.php';

// ==========================================
// 1bis. SÉCURITÉ : utilisateur connecté & actif + CSRF
// ==========================================
// requirePermission() (appelé par le contrôleur avant l'include de cette vue)
// vérifie déjà le rôle, mais ne définit ni USER_ID ni le jeton CSRF : on le
// fait ici, comme dans devis.php et les autres écrans de facturation, pour
// que les actions destructrices (suppression, modification, validation)
// exigent une session valide ET un jeton CSRF correct.
$isAjaxVente = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_POST['action']);
if (!isset($_SESSION['user_id'])) {
    if ($isAjaxVente) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Session expirée.']); exit; }
    header('Location: utilisateur/login');
    exit;
}
$stmtUserVente = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmtUserVente->execute([$_SESSION['user_id']]);
$userInfoVente = $stmtUserVente->fetch(PDO::FETCH_ASSOC);
if (!$userInfoVente) {
    if ($isAjaxVente) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Utilisateur invalide.']); exit; }
    session_destroy();
    header('Location: utilisateur/login');
    exit;
}
if (!defined('USER_ID')) define('USER_ID', $_SESSION['user_id']);
if (!defined('USER_BOUTIQUE')) define('USER_BOUTIQUE', $userInfoVente['boutique_id'] ?? null);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ==========================================
// 2. GÉNÉRATION DU PDF (mode Portrait)
// ==========================================
if (isset($_POST['action']) && $_POST['action'] === 'pdf') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $csrfPdf = $_POST['csrf_token'] ?? '';
    if (empty($csrfPdf) || $csrfPdf !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die("Token de sécurité invalide.");
    }
    if (!isset($_POST['id']) || empty($_POST['id'])) die("ID de facture manquant.");
    $id = $_POST['id'];
    try {
        $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact, c.adresse_contact, c.telephone_contact AS tel_contact, c.email_contact
            FROM facture f LEFT JOIN contact c ON f.contact_id = c.code_contact
            WHERE f.numero_facture = ?");
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$facture) die("Facture introuvable.");

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
    $titreDoc = strtoupper($estValidee ? 'Facture client' : 'Bon de commande');

    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 22);
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
    $pdf->Cell(130, 5, 'Statut : ' . ($facture['statut_facture'] ?? ''), 0, 1, 'L');
    $pdf->Ln(16);
    // $pdf->SetDrawColor(0, 0, 0);
    // $pdf->SetLineWidth(0.3);
    // $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    // $pdf->Ln(6);

    $yBandeau = $pdf->GetY();
    $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(10, $yBandeau);
    $pdf->Cell(90, 7, '  EXPEDITEUR', 0, 0, 'L', true);
    $pdf->SetXY(110, $yBandeau);
    $pdf->Cell(90, 7, '  DESTINATAIRE', 0, 0, 'L', true);
    $yBoxes = $yBandeau + 7;
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetFillColor($grey[0], $grey[1], $grey[2]);
    $pdf->SetXY(10, $yBoxes);
    $pdf->MultiCell(90, 5.5, $nomBoutique, 0, 'L', true);
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(90, 3.3, 'Distribution de Pièces Détachées de Motos et Moto', 0, 'L', true);
    $yExpAfterNom = $pdf->GetY();
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(10, $yExpAfterNom);
    $pdf->MultiCell(90, 5.5, $adresseBoutique . "\nTel: " . $telBoutique . "\nEmail: " . $emailBoutique, 0, 'L', true);
    $yAfterExp = $pdf->GetY();

    $clientNom = $facture['nom_prenom_contact'] ?? 'N/C';
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetXY(110, $yBoxes);
    $pdf->MultiCell(90, 5.5, $clientNom, 0, 'L', true);
    $yDestAfterNom = $pdf->GetY();
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(110, $yDestAfterNom);
    $pdf->MultiCell(90, 5.5, ($facture['adresse_contact'] ?? '') . "\nTel: " . ($facture['tel_contact'] ?? '') . "\nEmail: " . ($facture['email_contact'] ?? ''), 0, 'L', true);
    $yAfterDest = $pdf->GetY();
    $pdf->SetY(max($yAfterExp, $yAfterDest) + 6);

    $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(20, 7, 'REF.', 0, 0, 'C', true);
    $pdf->Cell(60, 7, 'DESIGNATION', 0, 0, 'C', true);
    $pdf->Cell(25, 7, 'QUANTITE', 0, 0, 'C', true);
    $pdf->Cell(20, 7, 'CARTON', 0, 0, 'C', true);
    $pdf->Cell(30, 7, 'P.U.(FCFA)', 0, 0, 'C', true);
    $pdf->Cell(35, 7, 'MONTANT(FCFA)', 0, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor($border[0], $border[1], $border[2]);
    $pdf->SetFont('Arial', '', 9);
    $total_ht = 0;
    foreach ($commandes as $cmd) {
        $montant_ligne = $cmd['quantite_commande'] * $cmd['prix_commande'];
        $total_ht += $montant_ligne;
        $ref = $cmd['reference_produit'] ?? '';
        $designation = $cmd['titre_produit'] ?? '';
        $nbLines = max(1, ceil(strlen($designation) / 25));
        $rowHeight = 7 * $nbLines;
        if ($pdf->GetY() + $rowHeight > 270) $pdf->AddPage();
        // Quantité = nombre d'unités (pièces) livrées, toujours. Carton = nombre
        // de cartons complets, uniquement si un lot a réellement été configuré
        // à la vente (produits_par_lot > 1) ; vide sinon (produit vendu à la pièce).
        $produitsParLot = intval($cmd['produits_par_lot'] ?? 1);
        $carton = $produitsParLot > 1 ? intdiv($cmd['quantite_commande'], $produitsParLot) : '';
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->MultiCell(20, 7, $ref, 1, 'L');
        $pdf->SetXY($x + 20, $y); $pdf->MultiCell(60, 7, $designation, 1, 'L');
        $pdf->SetXY($x + 80, $y);
        $pdf->Cell(25, $rowHeight, $cmd['quantite_commande'], 1, 0, 'C');
        $pdf->Cell(20, $rowHeight, $carton, 1, 0, 'C');
        // "P.U." imprimé : le prix DU LOT tel que saisi à la vente si cette ligne
        // en a un (colonne dédiée prix_lot_ligne, jamais recalculé), sinon le
        // prix/unité classique. Le montant reste toujours qté(unités) × prix/unité.
        $aPrixLot = isset($cmd['prix_lot_ligne']) && $cmd['prix_lot_ligne'] !== null && $cmd['prix_lot_ligne'] !== '';
        $prixUnitImprime = $aPrixLot ? $cmd['prix_lot_ligne'] : $cmd['prix_commande'];
        $pdf->Cell(30, $rowHeight, number_format($prixUnitImprime, 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell(35, $rowHeight, number_format($montant_ligne, 0, ',', ' '), 1, 1, 'R');
    }
    $pdf->Ln(6);

    $taxe = $facture['taxe'] ?? ($total_ht * 0.18);
    $remise = $facture['remise'] ?? 0;
    $ttc = $facture['montant_ttc'] ?? ($total_ht + $taxe - $remise);
    $lignesTotaux = [['TOTAL HT', $total_ht], ['TVA (18%)', $taxe], ['REMISE', $remise]];
    $yBloc = $pdf->GetY();
    $obsWidth = 100; $obsHeight = 32;
    // $pdf->SetDrawColor($border[0], $border[1], $border[2]);
    // $pdf->Rect(10, $yBloc, $obsWidth, $obsHeight);
    // $pdf->SetXY(12, $yBloc + 2);
    // $pdf->SetFont('Arial', 'B', 9);
    // $pdf->Cell($obsWidth - 4, 5, 'Observations :', 0, 1, 'L');
    // $pdf->SetX(12);
    // $pdf->SetFont('Arial', '', 9);
    // $pdf->MultiCell($obsWidth - 4, 5, "Merci de votre confiance.\nVeuillez respecter les délais de livraison.", 0, 'L');
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
    $pdf->Cell($totWidth - 30, $rowH + 1, ' NET A PAYER (TTC)', 0, 0, 'L');
    $pdf->Cell(30, $rowH + 1, number_format($ttc, 0, ',', ' ') . ' FCFA', 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($yBloc + max($obsHeight, $rowH * $nbRows) + 10);

    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(90, 6, 'Le vendeur', 0, 0, 'L');
    $pdf->Cell(10);
    $pdf->Cell(90, 6, 'Le client', 0, 1, 'L');
    $pdf->Cell(90, 6, 'Nom et Signature', 0, 0, 'L');
    $pdf->Cell(10);
    $pdf->Cell(90, 6, 'Nom et Signature', 0, 1, 'L');
    $ySign = $pdf->GetY() + 2;
    $pdf->SetDrawColor($navy[0], $navy[1], $navy[2]);
    $pdf->Rect(10, $ySign, 90, 20);
    $pdf->Rect(110, $ySign, 90, 20);
    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetXY(12, $ySign + 2);
    $pdf->Cell(86, 5, $nomBoutique, 0, 1, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetX(12);
    $pdf->Cell(86, 4, $adresseBoutique, 0, 1, 'L');
    $pdf->SetX(12);
    $pdf->Cell(86, 4, 'Tel: ' . $telBoutique, 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $modePdf = (isset($_POST['mode']) && $_POST['mode'] === 'D') ? 'D' : 'I';
    $pdf->Output($modePdf, ($estValidee ? 'Facture_' : 'Bon_de_commande_') . $id . '.pdf');
    exit;
}

// ==========================================
// 3. TRAITEMENT DES ACTIONS (AJAX / POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    header('Content-Type: application/json');
    $csrfCheck = $_POST['csrf_token'] ?? '';
    if (empty($csrfCheck) || $csrfCheck !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Token de sécurité invalide.']);
        exit;
    }

    if ($action === 'validate_facture') {
        $id = $_POST['id'] ?? '';
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'ID manquant']); exit; }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT contact_id, avance, reste, statut_facture, categorie_facture, reference_id FROM facture WHERE numero_facture = ? FOR UPDATE");
            $stmt->execute([$id]);
            $f = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$f) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'Facture introuvable']); exit; }

            $stmt = $pdo->prepare("UPDATE facture SET statut_facture = 'Validee' WHERE numero_facture = ? AND statut_facture <> 'Validee'");
            $stmt->execute([$id]);

            $numBL = null;

            if ($stmt->rowCount() > 0) {
                // Le crédit devient effectif au moment de la validation (le Bon en attente
                // n'engageait encore aucune dette). Si le client est déjà en avance
                // (solde_contact < 0), cette avance couvre directement ce qui reste dû :
                // totalement -> Payée, partiellement -> Partielle, sans avance -> Impayée
                // inchangée. Le reste non couvert s'ajoute au solde/dette du client.
                $resteInitial = floatval($f['reste']);
                if ($resteInitial != 0) {
                    $stmtSoldeC = $pdo->prepare("SELECT solde_contact FROM contact WHERE code_contact = ? FOR UPDATE");
                    $stmtSoldeC->execute([$f['contact_id']]);
                    $soldeAvantC = floatval($stmtSoldeC->fetchColumn());
                    $avanceDispoC = max(0, -$soldeAvantC);

                    $avanceUtilisee = min($avanceDispoC, $resteInitial);
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

                // ---- CRÉATION DU BON DE LIVRAISON ----
                // C'est la validation du bon de commande, et uniquement elle, qui déclenche
                // la génération du bon de livraison (le devis ne le fait plus à la transformation).
                if ($f['categorie_facture'] === 'Bon') {
                    $stmtLignes = $pdo->prepare("SELECT * FROM commande WHERE facture_id = ?");
                    $stmtLignes->execute([$id]);
                    $lignesBon = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);

                    // ---- RÉSERVATION DU STOCK (bons créés SANS passer par un devis) ----
                    // Un bon transformé depuis un devis (reference_id = DEV-...) a déjà
                    // réservé son stock à la transformation. Un bon créé directement depuis
                    // la vente au comptoir (reference_id NULL) n'a encore touché ni le stock
                    // ni les lots : c'est ici, à la validation, qu'on vérifie et décrémente.
                    if (empty($f['reference_id']) && !empty($lignesBon)) {
                        foreach ($lignesBon as $l) {
                            $boutiqueLigne = $l['boutique_id'] ?: null;
                            if (empty($boutiqueLigne) || empty($l['produit_id'])) {
                                throw new Exception("Ligne de bon incomplète (produit ou boutique manquant) : validation impossible.");
                            }

                            $stmtStockLock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                            $stmtStockLock->execute([$l['produit_id'], $boutiqueLigne]);
                            $dispo = $stmtStockLock->fetchColumn();
                            $dispo = ($dispo === false) ? 0 : (int) $dispo;
                            if ($dispo < (int) $l['quantite_commande']) {
                                $stmtNom = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
                                $stmtNom->execute([$l['produit_id']]);
                                $nomProd = $stmtNom->fetchColumn() ?: $l['produit_id'];
                                throw new Exception("Stock insuffisant pour « $nomProd » : disponible $dispo, demandé {$l['quantite_commande']}.");
                            }

                            if (!empty($l['lot_id'])) {
                                $stmtLotLock = $pdo->prepare("SELECT quantite FROM lot WHERE code_lot = ? FOR UPDATE");
                                $stmtLotLock->execute([$l['lot_id']]);
                                $qteLot = $stmtLotLock->fetchColumn();
                                $qteLot = ($qteLot === false) ? 0 : (int) $qteLot;
                                if ($qteLot < (int) $l['quantite_commande']) {
                                    throw new Exception("Stock de lot insuffisant pour le lot {$l['lot_id']} : disponible $qteLot, demandé {$l['quantite_commande']}.");
                                }
                            }
                        }

                        foreach ($lignesBon as $l) {
                            $boutiqueLigne = $l['boutique_id'];
                            $pdo->prepare("UPDATE stock SET quantite = GREATEST(0, quantite - ?) WHERE produit_id = ? AND boutique_id = ?")
                                ->execute([$l['quantite_commande'], $l['produit_id'], $boutiqueLigne]);
                            $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) - ? AS CHAR) WHERE code_produit = ?")
                                ->execute([$l['quantite_commande'], $l['produit_id']]);
                            $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                            WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                            WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                            ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                                ->execute([$l['produit_id']]);

                            if (!empty($l['lot_id'])) {
                                // Le lot reste Actif même à quantité 0 : un lot déjà
                                // configuré ne doit jamais être désactivé ni supprimé
                                // automatiquement.
                                $pdo->prepare("UPDATE lot SET quantite = quantite - ? WHERE code_lot = ? AND quantite >= ?")
                                    ->execute([$l['quantite_commande'], $l['lot_id'], $l['quantite_commande']]);
                            }
                        }
                    }

                    if (!empty($lignesBon)) {
                        $numBL = 'BL-' . date('Ymd') . '-' . str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);

                        $pdo->prepare("INSERT INTO bon_livraison(code_bon, date_livraison, facture_id, adresse_livraison, transporteur, statut, commentaire)
                                       VALUES (?, CURDATE(), ?, NULL, NULL, 'En attente', NULL)")
                            ->execute([$numBL, $id]);

                        $numBase = date('dmYHis');

                        foreach ($lignesBon as $i => $l) {
                            $numCmd = $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '-BL';

                            $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, prix_lot_ligne, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                                           VALUES (?, ?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?, 'EN ATTENTE')")
                                ->execute([$numCmd, $l['produit_id'], $l['lot_id'], $l['contact_id'], $numBL, $l['prix_achat'], $l['prix_commande'], $l['prix_lot_ligne'], $l['quantite_commande'], $l['produits_par_lot'], $l['montant_commande'], $l['utilisateur_id'], $l['boutique_id']]);
                        }
                    }
                }
            }

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Facture validée' . ($numBL ? ' (bon de livraison ' . $numBL . ' généré)' : ''),
                'bon_livraison' => $numBL
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_facture') {
        $id = $_POST['id'] ?? '';
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'ID manquant']); exit; }
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? FOR UPDATE");
            $stmt->execute([$id]);
            $facture = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$facture) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'Facture introuvable']); exit; }

            // ---- DEUX RÉGIMES DE SUPPRESSION ----
            // 1) CORRECTION D'ERREUR (facture pas encore payée+validée) : suppression
            //    ouverte aux rôles habituels de cet écran, avec restitution complète
            //    du stock, annulation de l'encaissement caisse et de la créance.
            // 2) NETTOYAGE D'ARCHIVE (facture déjà payée ET validée) : la vente est
            //    définitivement conclue — la supprimer ne doit PLUS toucher au stock
            //    ni à la caisse (le produit est physiquement sorti, l'argent a été
            //    réellement encaissé). Réservé au Superviseur/Administrateur, et
            //    seulement après un délai de sécurité pour ne pas purger une vente
            //    du jour par erreur.
            $isPaidValidee = in_array(strtolower($facture['etat_facture'] ?? ''), ['payee', 'payee cash'])
                && strtolower($facture['statut_facture'] ?? '') === 'validee';

            if ($isPaidValidee) {
                $delaiJours = 7;
                $roleUtilisateur = $_SESSION['role'] ?? '';
                if (!in_array($roleUtilisateur, ['Administrateur', 'Superviseur'], true)) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Seul un Superviseur (ou Administrateur) peut supprimer une facture déjà validée et payée.']);
                    exit;
                }
                $ageJours = (strtotime(date('Y-m-d')) - strtotime($facture['date_facture'])) / 86400;
                if ($ageJours < $delaiJours) {
                    $pdo->rollBack();
                    $reste = (int)ceil($delaiJours - $ageJours);
                    echo json_encode(['success' => false, 'error' => "Cette facture ne peut être supprimée que $delaiJours jours après son émission (encore $reste jour(s) à attendre)."]);
                    exit;
                }

                // Nettoyage pur : on ne touche NI au stock NI à la caisse. On ne fait
                // que retirer la créance si, par exception, un reste subsistait.
                if (floatval($facture['reste']) != 0) {
                    $pdo->prepare("UPDATE contact SET solde_contact = solde_contact - ? WHERE code_contact = ?")
                        ->execute([floatval($facture['reste']), $facture['contact_id']]);
                }

                $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$id]);
                $stmt = $pdo->prepare("DELETE FROM facture WHERE numero_facture = ?");
                $stmt->execute([$id]);
                $pdo->commit();
                echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Facture archivée supprimée (nettoyage) — stock et caisse non modifiés.']);
                exit;
            }

            // ---- CORRECTION D'ERREUR : restitution complète ----
            // 1. RESTITUTION DU STOCK réservé/vendu par chaque ligne de la facture
            // (toute vente ou tout bon décrémente le stock à la création : le
            // supprimer sans le restituer désynchronise définitivement le stock).
            // Si une ligne ancienne n'a pas de boutique_id (import/donnée hors du
            // flux normal), on ne devine PAS où restituer : on le signale plutôt
            // que de désynchroniser silencieusement le stock.
            $lignesIgnorees = [];
            $stmtLignes = $pdo->prepare("SELECT produit_id, quantite_commande, boutique_id FROM commande WHERE facture_id = ?");
            $stmtLignes->execute([$id]);
            foreach ($stmtLignes->fetchAll(PDO::FETCH_ASSOC) as $l) {
                if (empty($l['boutique_id']) || empty($l['produit_id'])) {
                    $lignesIgnorees[] = $l['produit_id'] ?: '?';
                    continue;
                }
                $pdo->prepare("UPDATE stock SET quantite = quantite + ? WHERE produit_id = ? AND boutique_id = ?")
                    ->execute([$l['quantite_commande'], $l['produit_id'], $l['boutique_id']]);
                $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) + ? AS CHAR) WHERE code_produit = ?")
                    ->execute([$l['quantite_commande'], $l['produit_id']]);
                $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                    ->execute([$l['produit_id']]);
            }

            // 2. ANNULATION DES ENCAISSEMENTS CAISSE liés à cette facture (une
            // suppression ne doit jamais laisser un encaissement fantôme dans la caisse).
            $stmtTr = $pdo->prepare("SELECT numero_transaction, caisse_id, montant_transaction FROM transaction WHERE facture_id = ? AND etat_transaction = 'Succes' FOR UPDATE");
            $stmtTr->execute([$id]);
            foreach ($stmtTr->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $pdo->prepare("UPDATE caisse SET solde = solde - ? WHERE caisse_id = ?")
                    ->execute([$t['montant_transaction'], $t['caisse_id']]);
                $pdo->prepare("UPDATE transaction SET etat_transaction = 'Annulee' WHERE numero_transaction = ?")
                    ->execute([$t['numero_transaction']]);
            }

            // 3. ANNULATION DE LA CRÉANCE CLIENT si la facture avait été validée avec
            // un reste dû (validate_facture avait ajouté ce reste à solde_contact).
            if (strtolower($facture['statut_facture']) === 'validee' && floatval($facture['reste']) != 0) {
                $pdo->prepare("UPDATE contact SET solde_contact = solde_contact - ? WHERE code_contact = ?")
                    ->execute([floatval($facture['reste']), $facture['contact_id']]);
            }

            $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM facture WHERE numero_facture = ?");
            $stmt->execute([$id]);
            $pdo->commit();

            $message = 'Facture supprimée, stock et caisse rétablis.';
            if (!empty($lignesIgnorees)) {
                $message = 'Facture supprimée. ATTENTION : le stock n\'a pas pu être restitué pour ' . count($lignesIgnorees) . ' ligne(s) sans boutique associée (' . implode(', ', $lignesIgnorees) . ') — vérifiez le stock manuellement.';
            }
            echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => $message, 'lignes_non_restituees' => $lignesIgnorees]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_details') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact FROM facture f LEFT JOIN contact c ON f.contact_id = c.code_contact WHERE f.numero_facture = ?");
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$facture) { echo json_encode(['error' => 'Facture introuvable']); exit; }
        $stmt2 = $pdo->prepare("SELECT c.*, p.titre_produit, l.libelle AS libelle_lot FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit LEFT JOIN lot l ON c.lot_id = l.code_lot WHERE c.facture_id = ?");
        $stmt2->execute([$id]);
        $commandesDetail = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $isLocked = in_array(strtolower($facture['etat_facture'] ?? ''), ['payee', 'payee cash'])
            && strtolower($facture['statut_facture'] ?? '') === 'validee';

        // Boutique de la facture (pour vérifier le stock des nouvelles lignes
        // ajoutées lors de la modification) : celle des lignes existantes, sinon
        // celle de l'utilisateur qui a créé la facture.
        $boutiqueFacture = $commandesDetail[0]['boutique_id'] ?? null;
        if (empty($boutiqueFacture) && !empty($facture['utilisateur_id'])) {
            $stmtUb = $pdo->prepare("SELECT boutique_id FROM utilisateur WHERE id = ?");
            $stmtUb->execute([$facture['utilisateur_id']]);
            $boutiqueFacture = $stmtUb->fetchColumn() ?: null;
        }

        echo json_encode([
            'facture' => $facture,
            'commandes' => $commandesDetail,
            'is_locked' => $isLocked,
            'boutique_id' => $boutiqueFacture
        ]);
        exit;
    }

    if ($action === 'check_stock_produit') {
        // Vérifie le stock disponible d'un produit dans une boutique donnée,
        // utilisé lors de l'ajout d'une nouvelle ligne à un bon en modification.
        $produitId = trim($_POST['produit_id'] ?? '');
        $boutiqueId = trim($_POST['boutique_id'] ?? '');
        $response = ['success' => false, 'disponible' => 0, 'prix' => 0, 'titre' => '', 'lots' => [], 'saisie_par_carton' => 0];
        if ($produitId !== '' && $boutiqueId !== '') {
            $stmtS = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ?");
            $stmtS->execute([$produitId, $boutiqueId]);
            $dispo = $stmtS->fetchColumn();
            $stmtP = $pdo->prepare("SELECT titre_produit, prix_produit, saisie_par_carton FROM produit WHERE code_produit = ?");
            $stmtP->execute([$produitId]);
            $rowP = $stmtP->fetch(PDO::FETCH_ASSOC);
            if ($rowP) {
                $response['success'] = true;
                $response['disponible'] = (int) ($dispo !== false ? $dispo : 0);
                $response['prix'] = (float) $rowP['prix_produit'];
                $response['titre'] = $rowP['titre_produit'];
                $response['saisie_par_carton'] = (int) $rowP['saisie_par_carton'];

                // Prix de lot éventuellement configurés pour ce produit (menu
                // "Configuration des lots") : permet de pré-remplir le prix de
                // la ligne sans que la caisse ait à le connaître par cœur.
                $stmtLots = $pdo->prepare("SELECT libelle, unites_par_lot, prix_lot FROM lot WHERE produit_id = ? AND etat_lot = 'Actif'");
                $stmtLots->execute([$produitId]);
                $response['lots'] = $stmtLots->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        echo json_encode($response);
        exit;
    }

    if ($action === 'update_facture') {
        $facture_id = $_POST['facture_id'];
        $checkStmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? FOR UPDATE");
        $checkStmt->execute([$facture_id]);
        $fData = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fData) { echo json_encode(['success' => false, 'error' => 'Facture introuvable.']); exit; }
        $isLocked = in_array(strtolower($fData['etat_facture']), ['payee', 'payee cash'])
            && strtolower($fData['statut_facture']) === 'validee';
        if ($isLocked) {
            echo json_encode(['success' => false, 'error' => 'Facture validée et payée, modification impossible.']);
            exit;
        }
        $avance = floatval($_POST['avance']);
        $reste = floatval($_POST['reste']);
        $ancienReste = floatval($fData['reste']);
        $commandes_data = json_decode($_POST['commandes'], true) ?: [];
        $nouvelles_lignes_data = json_decode($_POST['nouvelles_lignes'] ?? '[]', true) ?: [];
        $lignesIgnorees = [];
        $lignesAjoutees = 0;
        try {
            $pdo->beginTransaction();

            foreach ($commandes_data as $cmd) {
                $stmtCmd = $pdo->prepare("SELECT produit_id, quantite_commande, boutique_id FROM commande WHERE numero_commande = ? FOR UPDATE");
                $stmtCmd->execute([$cmd['id']]);
                $ligneActuelle = $stmtCmd->fetch(PDO::FETCH_ASSOC);
                if (!$ligneActuelle) continue;
                $boutique_id = $ligneActuelle['boutique_id'];
                $produit_id = $ligneActuelle['produit_id'];
                $ancienneQte = (int)$ligneActuelle['quantite_commande'];
                $quantiteVaChanger = $cmd['supprimer'] ? ($ancienneQte != 0) : ((max(0, intval($cmd['quantite']))) != $ancienneQte);
                if (empty($boutique_id) && $quantiteVaChanger) {
                    $lignesIgnorees[] = $produit_id ?: '?';
                }

                if ($cmd['supprimer']) {
                    // Ligne retirée de la facture : la quantité vendue/réservée retourne au stock
                    if (!empty($boutique_id)) {
                        $pdo->prepare("UPDATE stock SET quantite = quantite + ? WHERE produit_id = ? AND boutique_id = ?")
                            ->execute([$ancienneQte, $produit_id, $boutique_id]);
                        $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) + ? AS CHAR) WHERE code_produit = ?")
                            ->execute([$ancienneQte, $produit_id]);
                    }
                    $pdo->prepare("DELETE FROM commande WHERE numero_commande = ?")->execute([$cmd['id']]);
                } else {
                    $nouvelleQte = max(0, intval($cmd['quantite']));
                    $delta = $nouvelleQte - $ancienneQte; // >0 : on vend/réserve plus ; <0 : on restitue

                    if ($delta > 0 && !empty($boutique_id)) {
                        $stmtStock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                        $stmtStock->execute([$produit_id, $boutique_id]);
                        $dispo = (int)($stmtStock->fetchColumn() ?: 0);
                        if ($dispo < $delta) {
                            throw new Exception("Stock insuffisant pour augmenter la quantité de ce produit (disponible $dispo, demandé $delta de plus).");
                        }
                    }
                    if ($delta != 0 && !empty($boutique_id)) {
                        $pdo->prepare("UPDATE stock SET quantite = quantite - ? WHERE produit_id = ? AND boutique_id = ?")
                            ->execute([$delta, $produit_id, $boutique_id]);
                        $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) - ? AS CHAR) WHERE code_produit = ?")
                            ->execute([$delta, $produit_id]);
                    }
                    $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                    WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                    WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                    ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                        ->execute([$produit_id]);

                    $montant = $nouvelleQte * $cmd['prix'];
                    $produits_par_lot = max(1, intval($cmd['produits_par_lot'] ?? 1));
                    $prixLotLigne = (isset($cmd['prix_lot']) && $cmd['prix_lot'] !== null && $cmd['prix_lot'] !== '') ? round((float)$cmd['prix_lot'], 2) : null;
                    $pdo->prepare("UPDATE commande SET quantite_commande = ?, prix_commande = ?, prix_lot_ligne = ?, produits_par_lot = ?, montant_commande = ? WHERE numero_commande = ?")
                        ->execute([$nouvelleQte, $cmd['prix'], $prixLotLigne, $produits_par_lot, $montant, $cmd['id']]);
                }
            }

            // ---- AJOUT DE NOUVELLES LIGNES (produits ajoutés pendant la modification) ----
            if (!empty($nouvelles_lignes_data)) {
                // Statut / contact à réutiliser : ceux d'une ligne existante du même
                // bon (pour rester cohérent avec le reste du document). À défaut, on
                // retombe sur un statut de vente standard. Le lot, lui, est
                // configurable indépendamment pour chaque nouvelle ligne (voir
                // achat.php pour le même principe).
                $stmtRef = $pdo->prepare("SELECT statut_id, etat_commande FROM commande WHERE facture_id = ? LIMIT 1");
                $stmtRef->execute([$facture_id]);
                $refLigne = $stmtRef->fetch(PDO::FETCH_ASSOC);
                $statutRef = $refLigne['statut_id'] ?? '012';
                $etatRef = $refLigne['etat_commande'] ?? 'EN ATTENTE';
                $libellesLotValides = ['Boîte', 'Palette', 'Carton', 'Bidon', 'Unité'];
                $numBase = date('dmYHis');

                foreach ($nouvelles_lignes_data as $i => $nl) {
                    $produitId = trim($nl['produit_id'] ?? '');
                    $boutiqueId = trim($nl['boutique_id'] ?? '');
                    $quantite = max(0, intval($nl['quantite'] ?? 0));
                    $prix = floatval($nl['prix'] ?? 0);
                    $produitsParLot = max(1, intval($nl['produits_par_lot'] ?? 1));

                    if ($produitId === '' || $quantite <= 0) {
                        continue;
                    }
                    if ($boutiqueId === '') {
                        $lignesIgnorees[] = $produitId;
                        continue;
                    }

                    // Vérification du stock de la boutique avant ajout
                    $stmtStockNew = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                    $stmtStockNew->execute([$produitId, $boutiqueId]);
                    $dispoNew = $stmtStockNew->fetchColumn();
                    $dispoNew = ($dispoNew === false) ? 0 : (int) $dispoNew;
                    if ($dispoNew < $quantite) {
                        $stmtNomNew = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
                        $stmtNomNew->execute([$produitId]);
                        $nomProdNew = $stmtNomNew->fetchColumn() ?: $produitId;
                        throw new Exception("Stock insuffisant pour « $nomProdNew » : disponible $dispoNew, demandé $quantite.");
                    }

                    // Décrémente le stock de la boutique
                    $pdo->prepare("UPDATE stock SET quantite = quantite - ? WHERE produit_id = ? AND boutique_id = ?")
                        ->execute([$quantite, $produitId, $boutiqueId]);
                    $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) - ? AS CHAR) WHERE code_produit = ?")
                        ->execute([$quantite, $produitId]);
                    $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                    WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                    WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                    ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                        ->execute([$produitId]);

                    // Configuration de lot (optionnelle, comme dans achat.php) : si
                    // l'utilisateur n'a pas configuré de lot pour cette ligne, on reste
                    // sur le comportement "produit simple" (lot_id NULL) tout en
                    // conservant le produits_par_lot saisi sur la ligne (affichage).
                    $lotConfigure = filter_var($nl['lot_configure'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $libelleLot = in_array($nl['libelle_lot'] ?? '', $libellesLotValides, true) ? $nl['libelle_lot'] : 'Unité';
                    $lot_id = null;
                    if ($lotConfigure) {
                        // Un lot est déjà configuré pour ce produit (même libellé) ?
                        // On le réutilise — le lot ne doit être créé qu'une seule fois,
                        // pas à chaque nouvelle vente du même produit.
                        $stmtLotExist = $pdo->prepare("SELECT code_lot FROM lot WHERE produit_id = ? AND libelle = ? AND etat_lot = 'Actif' LIMIT 1");
                        $stmtLotExist->execute([$produitId, $libelleLot]);
                        $lotExistant = $stmtLotExist->fetchColumn();

                        if ($lotExistant) {
                            $lot_id = $lotExistant;
                        } else {
                            $lot_id = 'LOT-' . date('YmdHis') . rand(100, 999) . '-' . $i;
                            $pdo->prepare("INSERT INTO lot (code_lot, libelle, unites_par_lot, produit_id, quantite, etat_lot)
                                          VALUES (?, ?, ?, ?, ?, 'Actif')")
                                ->execute([$lot_id, $libelleLot, $produitsParLot, $produitId, $quantite]);
                        }
                    }

                    $numCmdNew = $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '-ADD';
                    $montantNew = $quantite * $prix;
                    $prixLotLigne = (isset($nl['prix_lot']) && $nl['prix_lot'] !== null && $nl['prix_lot'] !== '') ? round((float)$nl['prix_lot'], 2) : null;

                    $pdo->prepare("
                        INSERT INTO commande
                        (numero_commande, produit_id, lot_id, produits_par_lot, contact_id, facture_id, statut_id,
                         date_commande, heure_commande, prix_achat, prix_commande, prix_lot_ligne, quantite_commande, montant_commande,
                         utilisateur_id, boutique_id, etat_commande)
                        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $numCmdNew, $produitId, $lot_id, $produitsParLot, $fData['contact_id'], $facture_id, $statutRef,
                        $prix, $prix, $prixLotLigne, $quantite, $montantNew,
                        USER_ID, $boutiqueId, $etatRef
                    ]);

                    $lignesAjoutees++;
                }
            }

            // ---- RECALCUL AUTORITAIRE DU MONTANT DE LA FACTURE ----
            // Le total ne doit jamais être décidé par le client : on le recalcule
            // ici à partir des lignes réellement en base (après ajouts/modifs/
            // suppressions), pour que le montant et le reste changent bien à
            // chaque ajout de ligne. La taxe et la remise sont conservées telles
            // qu'enregistrées sur la facture (valeurs absolues), avec repli sur
            // 18% si aucune taxe n'a été fixée (même logique que la génération PDF).
            $stmtTotalHt = $pdo->prepare("SELECT COALESCE(SUM(montant_commande), 0) FROM commande WHERE facture_id = ?");
            $stmtTotalHt->execute([$facture_id]);
            $totalHtActuel = (float) $stmtTotalHt->fetchColumn();

            $taxeVal = (isset($fData['taxe']) && $fData['taxe'] !== null && $fData['taxe'] !== '')
                ? floatval($fData['taxe'])
                : round($totalHtActuel * 0.18, 2);
            $remiseVal = floatval($fData['remise'] ?? 0);
            $nouveauMontantTtc = round($totalHtActuel + $taxeVal - $remiseVal, 2);

            // Le reste est recalculé à partir du nouveau montant et de l'avance
            // saisie par l'utilisateur (le reste envoyé par le client n'est
            // qu'une prévisualisation, la valeur enregistrée fait foi côté serveur).
            $reste = max(0, round($nouveauMontantTtc - $avance, 2));

            $pdo->prepare("UPDATE facture SET avance = ?, reste = ?, montant_ttc = ? WHERE numero_facture = ?")
                ->execute([$avance, $reste, $nouveauMontantTtc, $facture_id]);

            // Une facture déjà validée engage une créance suivie dans solde_contact
            // (ajoutée par validate_facture) : toute variation du reste doit s'y
            // répercuter à l'identique, sinon le solde du client se désynchronise.
            if (strtolower($fData['statut_facture']) === 'validee') {
                $deltaReste = round($reste - $ancienReste, 2);
                if ($deltaReste != 0) {
                    $pdo->prepare("UPDATE contact SET solde_contact = solde_contact + ? WHERE code_contact = ?")
                        ->execute([$deltaReste, $fData['contact_id']]);
                }
            }

            $pdo->commit();
            $message = 'Facture mise à jour.';
            if ($lignesAjoutees > 0) {
                $message = 'Facture mise à jour (' . $lignesAjoutees . ' nouvelle(s) ligne(s) ajoutée(s)).';
            }
            if (!empty($lignesIgnorees)) {
                $message = 'Facture mise à jour. ATTENTION : le stock n\'a pas été ajusté pour ' . count($lignesIgnorees) . ' ligne(s) sans boutique associée (' . implode(', ', $lignesIgnorees) . ') — vérifiez le stock manuellement.';
            }
            echo json_encode(['success' => true, 'message' => $message, 'lignes_non_ajustees' => $lignesIgnorees]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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

$stmtClients = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE etat_contact = 'Actif' AND type_contact = 'Client' ORDER BY nom_prenom_contact ASC");
$clients = $stmtClients->fetchAll(PDO::FETCH_ASSOC);
$totalFactures = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client'")->fetchColumn();
$payees = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' AND f.etat_facture IN ('Payee', 'Payee cash')")->fetchColumn();
$partielles = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' AND f.etat_facture = 'Partielle'")->fetchColumn();
$impayees = $pdo->query("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' AND f.etat_facture = 'Impayee'")->fetchColumn();
$totalMontant = $pdo->query("SELECT SUM(f.montant_ttc) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client'")->fetchColumn() ?? 0;
$totalReste = $pdo->query("SELECT SUM(f.reste) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client'")->fetchColumn() ?? 0;

// - Catégories actives et produits (pour l'ajout de nouvelles lignes lors de la
//   modification d'un bon de commande) : même logique que entree_stock.php -
$categoriesVente = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie WHERE etat_categorie='ACTIF' ORDER BY titre_categorie")->fetchAll(PDO::FETCH_ASSOC);
$produitsVente = $pdo->query("SELECT code_produit, titre_produit, prix_produit, etat_produit, categorie_id
FROM produit
ORDER BY CASE WHEN etat_produit = 'RUPTURE' THEN 1 ELSE 0 END, titre_produit")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Factures</title>
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

        /* ===== CARTES FACTURES (compactes - 4 par ligne) ===== */
        .facture-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 120px;
            position: relative;
            animation: fadeUp .4s ease both;
        }
        .facture-card:hover {
            border-color: var(--color-primary);
            box-shadow: 0 4px 12px rgba(79, 70, 229, .12);
            transform: translateY(-2px);
        }
        .facture-card.validated {
            border-color: var(--color-success);
            background: #f0fdf4;
        }
        .facture-card.validated::after {
            content: '✓';
            position: absolute;
            top: 6px;
            right: 6px;
            background: var(--color-success);
            color: #fff;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
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

        /* Boutons icônes outline */
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

        /* ===== STATS ===== */
        .stat-card {
            background: var(--bg-surface); border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base);
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

        /* ===== MODAL CHIC ===== */
        .modal-chic .modal-content {
            border: none; border-radius: 20px;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15); overflow: hidden;
            animation: modalSlideIn .4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-chic .modal-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%);
            color: #fff; border: none; padding: 22px 28px; position: relative; overflow: hidden;
        }
        .modal-chic .modal-header::before {
            content: ''; position: absolute; top: -50%; right: -20%; width: 200px; height: 200px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%;
        }
        .modal-chic .modal-title { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
        .modal-chic .modal-title i { font-size: 22px; background: rgba(255,255,255,0.15); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px); }
        .modal-chic .btn-close { filter: invert(1); opacity: 0.7; position: relative; z-index: 1; transition: all .2s; }
        .modal-chic .btn-close:hover { opacity: 1; transform: rotate(90deg); }
        .modal-chic .modal-body { padding: 28px; max-height: 70vh; overflow-y: auto; background: #f8fafc; }
        .modal-chic .modal-footer { background: #fff; border-top: 1px solid var(--border-color); padding: 18px 28px; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }

        /* Sections chic */
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

        /* Boutons chic du modal */
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

        /* Modal table */
        .modal-table { width: 100%; font-size: 13px; }
        .modal-table thead th { background: var(--color-gray-100); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 10px 12px; }
        .modal-table tbody td { padding: 10px 12px; border-bottom: 1px solid var(--border-color); }
        .modal-table tbody tr:hover { background: var(--color-primary-soft); }

        /* ===== SECTION ÉDITION CHIC ===== */
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

        /* ===== SELECTPICKER ===== */
        .bootstrap-select .dropdown-toggle {
            background: #fff !important;
            border: 1.5px solid var(--border-color) !important;
            border-radius: 8px !important;
            min-width: 220px;
        }
        .bootstrap-select .dropdown-toggle:focus {
            border-color: var(--color-primary) !important;
            box-shadow: 0 0 0 3px var(--color-primary-soft) !important;
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .facture-card.deleting { animation: fadeOut .4s ease forwards; }
        @keyframes fadeOut { to { opacity: 0; transform: scale(0.9); } }
        @media (max-width: 700px) {
            .bootstrap-select, .bootstrap-select .dropdown-toggle {
                width: 100% !important;
                min-width: 0 !important;
            }
        }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-receipt text-primary me-2"></i>Gestion des Factures</h1>
            <p class="text-muted small mb-0">Suivez et gérez toutes vos factures clients en un coup d'œil</p>
        </div>
        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
            <i class="bi bi-file-earmark-text"></i> <?= $totalFactures ?> facture(s)
        </span>
    </div>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['primary', 'file-earmark-text', 'Total factures', $totalFactures, ''],
            ['success', 'check-circle-fill', 'Payées', $payees, ''],
            ['warning', 'hourglass-split', 'Partielles', $partielles, ''],
            ['danger', 'x-circle-fill', 'Impayées', $impayees, ''],
            ['purple', 'cash-coin', 'Total TTC', number_format($totalMontant, 0, ',', ' '), ' FCFA'],
            ['info', 'wallet2', 'Reste à payer', number_format($totalReste, 0, ',', ' '), ' FCFA'],
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
                <label for="clientFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-person"></i> Client</label>
                <select id="clientFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un client...">
                    <option value="">Tous les clients</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= htmlspecialchars($client['code_contact']) ?>"><?= htmlspecialchars($client['nom_prenom_contact']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="etatFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-cash-coin"></i> État</label>
                <select id="etatFilter" class="selectpicker">
                    <option value="">Tous les états</option>
                    <option value="Impayee">Impayée</option>
                    <option value="Partielle">Partielle</option>
                    <option value="Payee cash">Payée cash</option>
                    <option value="Payee">Payée</option>
                </select>
                <label for="statutFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-check2-square"></i> Statut</label>
                <select id="statutFilter" class="selectpicker">
                    <option value="">Tous les statuts</option>
                    <option value="En attente">En attente</option>
                    <option value="Validee">Validée</option>
                    <option value="Annule">Annulée</option>
                </select>
                <button type="button" class="btn btn-primary fw-bold" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn btn-outline-secondary fw-semibold" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <!-- Liste des factures -->
    <div class="row g-3" id="facturesGrid">
        <?php
        // Seuls les bons de commande (issus de la transformation d'un devis) apparaissent
        // ici : tant qu'un devis n'est pas transformé, aucun document n'existe dans vente.
        // Un bon de commande est "En attente" puis devient "Facture client" une fois validé
        // (le titre affiché sur le PDF s'adapte déjà au statut, voir plus haut).
        $sql = "SELECT f.*, c.nom_prenom_contact
                FROM facture f
                INNER JOIN contact c ON f.contact_id = c.code_contact
                WHERE c.type_contact = 'Client' AND f.categorie_facture = 'Bon'
                ORDER BY f.date_facture DESC";
        $factures = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $delaiSuppressionJours = 7;
        $estSuperviseurOuAdmin = in_array($_SESSION['role'] ?? '', ['Administrateur', 'Superviseur'], true);
        if (empty($factures)):
        ?>
        <div class="col-12">
            <div class="bg-white border border-dashed rounded-3 p-5 text-center text-muted">
                <i class="bi bi-inbox d-block mb-2" style="font-size:56px;opacity:.2;"></i>
                <h5 class="text-dark">Aucune facture trouvée</h5>
                <p class="small mb-0">Les factures apparaîtront ici dès leur création.</p>
            </div>
        </div>
        <?php else: foreach($factures as $row):
            $etatBadge = getEtatBadge($row['etat_facture']);
            $isValidee = (strtolower($row['statut_facture']) === 'validee');
            $isPaidValidee = in_array(strtolower($row['etat_facture'] ?? ''), ['payee', 'payee cash']) && $isValidee;
            $ageJours = (strtotime(date('Y-m-d')) - strtotime($row['date_facture'])) / 86400;
            $joursRestants = (int)ceil($delaiSuppressionJours - $ageJours);
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
                        <i class="bi bi-person"></i>
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
                    <?php if (!$isValidee): ?>
                        <button class="icon-btn validate valider-facture" data-id="<?= $row['numero_facture'] ?>" data-tooltip="Valider" title="Valider"><i class="bi bi-check2-circle"></i></button>
                    <?php else: ?>
                        <button class="icon-btn validate validated" disabled data-tooltip="Validée" title="Validée"><i class="bi bi-check-circle-fill"></i></button>
                    <?php endif; ?>


                    <?php if ($_SESSION['role'] === 'Administrateur' || $_SESSION['role'] === 'Superviseur'): ?>
                    <?php if (!$isPaidValidee): ?>
                        <button class="icon-btn delete supprimer-facture" data-id="<?= $row['numero_facture'] ?>" data-cleanup="0" data-tooltip="Supprimer" title="Supprimer"><i class="bi bi-trash"></i></button>
                    <?php elseif (!$estSuperviseurOuAdmin): ?>
                        <button class="icon-btn delete" disabled data-tooltip="Réservé au Superviseur" title="Seul un Superviseur peut supprimer une facture payée"><i class="bi bi-lock-fill"></i></button>
                    <?php elseif ($joursRestants > 0): ?>
                        <button class="icon-btn delete" disabled data-tooltip="Délai non écoulé" title="Supprimable dans <?= $joursRestants ?> jour(s)"><i class="bi bi-hourglass-split"></i></button>
                    <?php else: ?>
                        <button class="icon-btn delete supprimer-facture" data-id="<?= $row['numero_facture'] ?>" data-cleanup="1" data-tooltip="Supprimer (archive)" title="Supprimer (nettoyage, sans impact stock/caisse)"><i class="bi bi-trash"></i></button>
                    <?php endif; ?>
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
                <h5 class="modal-title"><i class="bi bi-receipt-cutoff"></i><span id="modalTitleText">Détails de la facture</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="factureDetails">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Chargement...</span></div>
                    <p class="mt-3 text-muted small">Chargement des détails...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-chic btn-chic-modifier" id="btnModifier"><i class="bi bi-pencil-square"></i><span>Modifier</span></button>
                <button class="btn-chic btn-chic-imprimer" id="btnImprimer"><i class="bi bi-printer-fill"></i><span>Imprimer</span></button>
                <button class="btn-chic btn-chic-imprimer" id="btnTelecharger"><i class="bi bi-download"></i><span>Télécharger PDF</span></button>
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
                <p class="text-muted small mb-2">Êtes-vous sûr de vouloir supprimer la facture <strong id="deleteFactureId" class="text-danger"></strong> ?</p>
                <p class="text-muted small mb-4" id="deleteConfirmText"></p>
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
        <h2><i class="bi bi-pencil-square"></i> Modification de la facture</h2>
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
    const CSRF_TOKEN = '<?= $csrf_token ?>';
    // Catégories et produits disponibles pour l'ajout de nouvelles lignes
    // lors de la modification d'un bon de commande (mêmes données que entree_stock.php).
    const CATEGORIES_VENTE = <?= json_encode($categoriesVente, JSON_UNESCAPED_UNICODE) ?>;
    const PRODUITS_VENTE = <?= json_encode($produitsVente, JSON_UNESCAPED_UNICODE) ?>;
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    let factureToDelete = null;

    // Remplace window.confirm() par la modal Bootstrap générique
    const genericConfirmModal = new bootstrap.Modal(document.getElementById('genericConfirmModal'));
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

    // Valider facture
    $(document).on('click', '.valider-facture', function(e) {
        e.stopPropagation();
        const btn = $(this), id = btn.data('id'), card = btn.closest('.facture-card');
        genericConfirm('Valider la facture ' + id + ' ?', function() {
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i>');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'validate_facture', id: id, csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    showToast('Facture ' + id + ' validée');
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

    // Supprimer facture
    $(document).on('click', '.supprimer-facture', function(e) {
        e.stopPropagation();
        factureToDelete = $(this).data('id');
        const isCleanup = $(this).data('cleanup') == 1;
        $('#deleteFactureId').text(factureToDelete);
        $('#deleteConfirmText').text(isCleanup
            ? 'Cette facture est déjà validée et payée. Sa suppression est un nettoyage d\'archive : le stock et la caisse ne seront PAS modifiés. Cette action est irréversible.'
            : 'Cette suppression restituera le stock, annulera l\'encaissement en caisse et la créance éventuelle liés à cette facture. Cette action est irréversible.');
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
            data: { action: 'delete_facture', id: id, csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    deleteModal.hide();
                    showToast(resp.message || ('Facture ' + id + ' supprimée'), (resp.lignes_non_restituees && resp.lignes_non_restituees.length) ? 'info' : 'success');
                    card.addClass('deleting');
                    setTimeout(function() {
                        cardItem.fadeOut(300, function() {
                            $(this).remove();
                            if ($('.facture-item').length === 0) {
                                $('#facturesGrid').html(`<div class="col-12"><div class="bg-white border border-dashed rounded-3 p-5 text-center text-muted"><i class="bi bi-inbox d-block mb-2" style="font-size:56px;opacity:.2;"></i><h5 class="text-dark">Aucune facture trouvée</h5><p class="small mb-0">Les factures apparaîtront ici dès leur création.</p></div></div>`);
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
        const selClient = $('#clientFilter').val();
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
        showToast(count + ' facture(s) affichée(s)', 'info');
    });

    $('#resetBtn').on('click', function() {
        $('#clientFilter').selectpicker('val', '');
        $('#etatFilter').selectpicker('val', '');
        $('#statutFilter').selectpicker('val', '');
        $('.facture-item').show();
        showToast('Filtres réinitialisés', 'info');
    });

    // Voir détails
    $(document).on('click', '.voir-facture', function(e) {
        e.stopPropagation();
        const id = $(this).data('id');
        $('#modalTitleText').text('Facture ' + id);
        $('#factureDetails').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted small">Chargement...</p></div>');
        $('#btnModifier').prop('disabled', false).removeClass('disabled').css('opacity', '1').attr('title', 'Modifier');
        $('#factureModal').modal('show');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'get_details', id: id, csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#factureDetails').html('<div class="alert alert-danger">' + data.error + '</div>');
                    return;
                }
                const f = data.facture;
                const etatColor = f.etat_facture === 'PAYEE' || f.etat_facture === 'Payee' || f.etat_facture === 'Payee cash' ? 'success' : (f.etat_facture === 'PARTIELLE' || f.etat_facture === 'Partielle' ? 'warning' : 'danger');
                const statutColor = (f.statut_facture === 'VALIDEE' || f.statut_facture === 'Validee') ? 'primary' : 'secondary';
                let html = `<div class="detail-section-chic">
                    <div class="detail-section-title-chic info"><i class="bi bi-info-circle-fill"></i> INFORMATIONS GÉNÉRALES</div>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">N° FACTURE</div><div class="fw-bold" style="color:#1e293b;font-size:15px;">${f.numero_facture}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">DATE</div><div class="fw-semibold">${new Date(f.date_facture).toLocaleDateString('fr-FR')}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">CLIENT</div><div class="fw-semibold">${f.nom_prenom_contact|| 'N/C'}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">TYPE</div><div class="fw-semibold">${f.type_facture}</div></div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <span class="badge-chic ${etatColor}"><span class="dot"></span> ${f.etat_facture}</span>
                        <span class="badge-chic ${statutColor}"><span class="dot"></span> ${f.statut_facture}</span>
                    </div>
                </div>
                <div class="detail-section-chic">
                    <div class="detail-section-title-chic money"><i class="bi bi-cash-stack"></i> MONTANTS</div>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">MONTANT HT</div><div class="fw-semibold">${Number(f.montant_ht).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">TAXE</div><div class="fw-semibold">${Number(f.taxe).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">REMISE</div><div class="fw-semibold">${Number(f.remise).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">MONTANT TTC</div><div class="fw-bold" style="color:#10b981;font-size:18px;font-family:'Outfit',sans-serif;">${Number(f.montant_ttc).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">AVANCE</div><div class="fw-bold text-success">${Number(f.avance).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">RESTE</div><div class="fw-bold text-danger">${Number(f.reste).toLocaleString('fr-FR')} FCFA</div></div>
                    </div>
                </div>`;
                if (data.commandes.length > 0) {
                    html += `<div class="detail-section-chic">
                        <div class="detail-section-title-chic box"><i class="bi bi-box-seam-fill"></i> LIGNES DE LA FACTURE (${data.commandes.length})</div>
                        <div class="table-responsive"><table class="modal-table"><thead><tr><th>Produit</th><th class="text-center">Qté</th><th class="text-center">Composition</th><th class="text-end">Prix unit.</th><th class="text-end">Montant</th></tr></thead><tbody>`;
                    data.commandes.forEach(c => {
                        const ppl = parseInt(c.produits_par_lot) || 1;
                        const qte = parseInt(c.quantite_commande) || 0;
                        // On ne parle de "lot" que si un lot a réellement été configuré
                        // (ppl > 1) ; sinon on affiche simplement la quantité en "Produit".
                        let composition;
                        if (ppl > 1) {
                            const nbLots = Math.floor(qte / ppl);
                            const resteUnites = qte % ppl;
                            const lib = c.libelle_lot || 'carton';
                            composition = resteUnites > 0 ? `${nbLots} ${lib}(s) et ${resteUnites} Pièce(s)` : `${nbLots} ${lib}(s)`;
                        } else {
                            composition = `${qte} Pièce(s)`;
                        }
                        // Le "Prix unit." affiché est le prix DU LOT tel qu'il a été saisi
                        // à la vente (colonne dédiée prix_lot_ligne, jamais recalculé —
                        // donc jamais d'arrondi du type "416,67" au lieu de "10 000").
                        // Repli sur prix_commande (prix/unité) pour les lignes à l'unité
                        // ou les anciennes lignes sans prix de lot enregistré.
                        const aPrixLot = c.prix_lot_ligne !== null && c.prix_lot_ligne !== undefined && c.prix_lot_ligne !== '';
                        const prixAffiche = aPrixLot ? c.prix_lot_ligne : c.prix_commande;
                        html += `<tr><td class="fw-semibold">${c.titre_produit}</td><td class="text-center">${qte}</td><td class="text-center fw-bold">${composition}</td><td class="text-end">${Number(prixAffiche).toLocaleString('fr-FR')} FCFA</td><td class="text-end fw-bold">${Number(c.montant_commande).toLocaleString('fr-FR')} FCFA</td></tr>`;
                    });
                    html += '</tbody></table></div></div>';
                }
                $('#factureDetails').html(html).data('facture-id', f.numero_facture);
                if (data.is_locked) {
                    $('#btnModifier').prop('disabled', true).addClass('disabled').css('opacity', '0.5').attr('title', 'Facture validée et payée, modification impossible');
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

    function submitPdfPost(id, targetSelf, action = 'pdf', mode = 'I') {
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
        addField('action', action);
        addField('id', id);
        addField('mode', mode);
        addField('csrf_token', CSRF_TOKEN);
        document.body.appendChild(form);
        form.submit();
        form.remove();
    }

    $('#btnImprimer').click(function() {
        const id = $('#factureDetails').data('facture-id');
        if (id) submitPdfPost(id, true);
    });

    $('#btnTelecharger').click(function() {
        const id = $('#factureDetails').data('facture-id');
        if (id) submitPdfPost(id, false, 'pdf', 'D');
    });

    $('#btnPartager').click(async function() {
        const id = $('#factureDetails').data('facture-id');
        if (!id) return;
        const btn = $(this);
        const originalHtml = btn.html();
        const params = new URLSearchParams(window.location.search);
        params.set('action', 'pdf');
        params.set('id', id);
        params.set('csrf_token', CSRF_TOKEN);
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i><span>Préparation...</span>');
        try {
            const resp = await fetch(baseUrl, { method: 'POST', body: params });
            if (!resp.ok) throw new Error('pdf_fetch_failed');
            const blob = await resp.blob();
            if (blob.type && blob.type.indexOf('pdf') === -1) throw new Error('not_a_pdf');
            const file = new File([blob], 'facture-' + id + '.pdf', { type: 'application/pdf' });
            if (window.isSecureContext && navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                await navigator.share({
                    files: [file],
                    title: 'Facture N°' + id,
                    text: 'Bonjour, voici votre facture N°' + id
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
        window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent('Bonjour, voici votre facture N°' + id), '_blank');
    });

    function chargerEdition(id) {
        $('#editSection').show();
        $('#editContent').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted small">Chargement...</p></div>');
        $('html, body').animate({ scrollTop: $('#editSection').offset().top - 20 }, 400);
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'get_details', id: id, csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#editContent').html('<div class="alert alert-danger">' + data.error + '</div>');
                    return;
                }
                if (data.is_locked) {
                    $('#editContent').html('<div class="alert alert-warning"><i class="bi bi-lock-fill me-2"></i>Cette facture est validée et payée. Elle ne peut plus être modifiée.</div>');
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
        let html = `<div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">MONTANT TTC</label>
                <input type="text" class="form-control form-control-lg" id="edit_montant_ttc" value="${Number(f.montant_ttc).toLocaleString('fr-FR')}" readonly style="background:#f8fafc;font-weight:700;color:#10b981;">
            </div>
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">AVANCE</label>
                <input type="number" class="form-control form-control-lg" id="edit_avance" value="${f.avance}" step="100" style="font-weight:600;">
            </div>
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">RESTE</label>
                <input type="number" class="form-control form-control-lg" id="edit_reste" value="${f.reste}" step="100" readonly style="background:#f8fafc;font-weight:700;color:#ef4444;">
            </div>
        </div>
        <h6 class="mb-3 fw-bold" style="color:#1e293b;display:flex;align-items:center;gap:8px;"><i class="bi bi-box-seam-fill text-warning"></i> Lignes de commande</h6>
        <div class="table-responsive">
            <table class="edit-table-chic" id="editLignesTable">
                <thead><tr><th>Produit</th><th class="text-center">Qté</th><th class="text-center">Produits/carton</th><th class="text-center">Nb cartons à livrer</th><th class="text-end">Prix unit.</th><th class="text-end">Montant</th><th class="text-center">Action</th></tr></thead>
                <tbody>`;
        data.commandes.forEach(c => {
            const ppl = parseInt(c.produits_par_lot) || 1;
            const nbLots = Math.floor((parseInt(c.quantite_commande) || 0) / ppl);
            // prix_lot_ligne (colonne dédiée) dit sans ambiguïté si cette ligne a été
            // vendue par lot : si oui, on affiche/édite directement ce prix de lot
            // (jamais de reconstruction par multiplication, jamais d'arrondi introduit).
            const aPrixLot = c.prix_lot_ligne !== null && c.prix_lot_ligne !== undefined && c.prix_lot_ligne !== '';
            const prixAffiche = aPrixLot ? c.prix_lot_ligne : c.prix_commande;
            html += `<tr class="ligne-commande" data-id="${c.numero_commande}" data-produit="${c.produit_id}" data-prix-est-lot="${aPrixLot ? '1' : '0'}">                <td class="fw-semibold">${c.titre_produit}</td>
                <td class="text-center"><input type="number" class="form-control form-control-sm qte" value="${c.quantite_commande}" min="0" style="width:80px;display:inline-block;text-align:center;"></td>
                <td class="text-center"><input type="number" class="form-control form-control-sm produits-par-lot" value="${ppl}" min="1" style="width:90px;display:inline-block;text-align:center;"></td>
                <td class="text-center nb-lots-ligne fw-bold">${nbLots}</td>
                <td class="text-end"><input type="number" class="form-control form-control-sm prix" value="${prixAffiche}" min="0" style="width:120px;display:inline-block;text-align:right;"></td>
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
                    <label class="form-label small" id="newLigneQteLabel">Quantité</label>
                    <input type="number" id="newLigneQte" class="form-control" min="1" step="1" placeholder="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label small" id="newLignePrixLabel">Prix unit.</label>
                    <input type="number" id="newLignePrix" class="form-control" min="0" step="0.01" placeholder="0.00">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn-action-chic enregistrer w-100" id="btnAjouterLigne"><i class="bi bi-plus-lg"></i> Ajouter</button>
                </div>
            </div>
            <div class="small text-muted mt-2" id="newLigneStockInfo">Stock disponible : —</div>

            <!-- Produit déjà catalogué (menu "Configuration des lots") : on choisit
                 juste le mode de vente, seul le prix du lot reste modifiable ici. -->
            <div class="mt-2" id="newLigneVenteCatalogue" style="display:none;font-size:12px;color:#64748b;">
                Vendre par :
                <select id="newLigneModeVente" style="display:inline-block;width:auto;">
                    <option value="unite">Unité</option>
                </select>
                <span id="newLigneModeVenteLotWrap" style="display:none;margin-left:8px;">
                    <span id="newLigneModeVentePrixLotZone" style="display:none;">
                        Prix du lot pour cette vente : <input type="number" id="newLigneModeVentePrixLot" min="0" step="0.01" style="width:110px;">
                        —
                    </span>
                    <strong id="newLigneModeVenteApercu"></strong>
                </span>
            </div>

            <!-- Produit pas encore catalogué : ancien système, configuration
                 libre (à retirer une fois tous les produits catalogués). -->
            <div class="mt-2" style="font-size:12px;color:#64748b;" id="newLigneVenteAdHoc">
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

            <div id="newLigneLotPrixInfo" class="mt-1" style="display:none;color:#0f766e;font-weight:600;"></div>
        </div>
        <div class="mt-4 d-flex gap-2 justify-content-end">
            <button class="btn-action-chic annuler" id="btnAnnulerEdit"><i class="bi bi-x-lg"></i> Annuler</button>
            <button class="btn-action-chic enregistrer" id="btnEnregistrerEdit"><i class="bi bi-check-lg"></i> Enregistrer</button>
        </div>`;
        $('#editContent').html(html).data('facture-id', f.numero_facture).data('boutique-id', data.boutique_id || '');

        // ------------------------------------------------------------
        // Recalcul en direct du montant TTC et du reste à chaque
        // ajout / modification / suppression de ligne. La taxe et la
        // remise déjà appliquées à la facture sont conservées comme un
        // simple décalage fixe par rapport au total des lignes.
        // ------------------------------------------------------------
        const totalLignesInitial = data.commandes.reduce((s, c) => s + (parseFloat(c.montant_commande) || 0), 0);
        const offsetTaxeRemise = (parseFloat(f.montant_ttc) || 0) - totalLignesInitial;

        function recalculerTotaux() {
            let totalLignes = 0;
            $('#editLignesTable tbody tr').each(function() {
                totalLignes += montantLigne($(this));
            });
            const montantTtc = totalLignes + offsetTaxeRemise;
            $('#edit_montant_ttc').val(montantTtc.toLocaleString('fr-FR'));
            const avance = parseFloat($('#edit_avance').val()) || 0;
            $('#edit_reste').val(Math.max(0, Math.round((montantTtc - avance) * 100) / 100));
        }

        // ------------------------------------------------------------
        // Panneau d'ajout de produit : catégorie -> produit (filtré),
        // avec vérification du stock de la boutique du bon.
        // ------------------------------------------------------------
        const $newCat = $('#newLigneCategorie');
        const $newProd = $('#newLigneProduit');
        CATEGORIES_VENTE.forEach(function(c) {
            $newCat.append($('<option>', { value: c.code_categorie, text: c.titre_categorie }));
        });

        function filtrerProduitsNouvelleLigne() {
            const cat = String($newCat.val() || '').trim();
            $newProd.empty();
            $newProd.append($('<option>', { value: '', text: '-- Choisir un produit --' }));
            if (cat === '') {
                $newProd.prop('disabled', true).attr('title', "-- Choisir d'abord une catégorie --");
            } else {
                PRODUITS_VENTE.forEach(function(p) {
                    if (String(p.categorie_id || '') === cat) {
                        const suffixe = (p.etat_produit === 'RUPTURE') ? ' (rupture)' : '';
                        $newProd.append($('<option>', {
                            value: p.code_produit,
                            text: p.titre_produit + suffixe,
                            'data-prix': p.prix_produit || 0
                        }));
                    }
                });
                $newProd.prop('disabled', false).attr('title', '-- Choisir un produit --');
            }
            if ($newProd.hasClass('bs-select-hidden') || $newProd.data('selectpicker')) { $newProd.selectpicker('destroy'); }
            $newProd.selectpicker();
            $newProd.off('changed.bs.select change').on('changed.bs.select change', function() {
                // ⚠️ NE PAS appeler .selectpicker('refresh') ici : sur cette version
                // de bootstrap-select, refresh() après un destroy()+réinit dynamique
                // duplique les éléments internes du bouton (ex. "ProduitProduitProduit").
                // Le destroy()+réinit fait juste au-dessus suffit déjà à resynchroniser
                // le picker ; on réécrit simplement le texte affiché à la main pour
                // garantir qu'il corresponde bien au produit choisi.
                const texteChoisi = $newProd.find('option:selected').text();
                $newProd.parent().find('.filter-option-inner-inner').text(texteChoisi);
                updateNouvelleLigneStock();
            });
            $('#newLigneStockInfo').text('Stock disponible : —');
        }

        function reinitialiserLabelsQteEtPrix() {
            $('#newLigneQteLabel').text('Quantité');
            $('#newLigneQte').attr('placeholder', '0');
            $('#newLignePrixLabel').text('Prix unit.');
        }

        function appliquerModeVenteProduit(lots) {
            const $sel = $('#newLigneModeVente');
            $sel.find('option:not([value="unite"])').remove();
            if (lots.length > 0) {
                lots.forEach(function(l) {
                    $sel.append(
                        '<option value="' + escHtml(l.libelle) + '" data-unites="' + l.unites_par_lot +
                        '" data-prix-lot="' + (l.prix_lot !== null ? l.prix_lot : '') + '">' +
                        escHtml(l.libelle) + ' — ' + l.unites_par_lot + ' unité(s)</option>'
                    );
                });
                $sel.val('unite');
                $('#newLigneModeVenteLotWrap').hide();
                $('#newLigneVenteCatalogue').show();
                $('#newLigneVenteAdHoc').hide();
                $('#newLigneLotConfigure').prop('checked', false);
                $('#newLigneLotDetails').hide();
            } else {
                $('#newLigneVenteCatalogue').hide();
                $('#newLigneVenteAdHoc').show();
            }
            reinitialiserLabelsQteEtPrix();
            $('#newLigneLotPrixInfo').hide();
        }

        function updateNouvelleLigneStock() {
            const produit = $newProd.val();
            const boutique = $('#editContent').data('boutique-id');
            $newProd.data('dispo', 0);
            $newProd.data('lots', []);
            appliquerModeVenteProduit([]);
            if (!produit || !boutique) {
                $('#newLigneStockInfo').text(boutique ? 'Stock disponible : —' : 'Boutique du bon introuvable : vérifiez le stock manuellement.');
                return;
            }
            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: { action: 'check_stock_produit', produit_id: produit, boutique_id: boutique, csrf_token: CSRF_TOKEN },
                success: function(resp) {
                    if (resp.success) {
                        $newProd.data('dispo', resp.disponible);
                        $newProd.data('prix-unitaire', resp.prix);
                        $newProd.data('lots', resp.lots || []);
                        $newProd.data('saisie-carton', !!resp.saisie_par_carton);
                        $('#newLigneStockInfo').html('Stock disponible : <strong>' + resp.disponible + '</strong>');
                        if (!$('#newLignePrix').val() && resp.prix > 0) $('#newLignePrix').val(resp.prix);
                        appliquerModeVenteProduit(resp.lots || []);
                    } else {
                        $('#newLigneStockInfo').text('Stock disponible : —');
                    }
                }
            });
        }

        $newCat.selectpicker();
        $newCat.on('changed.bs.select change', filtrerProduitsNouvelleLigne);
        filtrerProduitsNouvelleLigne();

        // Quantité déjà ajoutée (mais pas encore enregistrée) pour un produit
        // donné dans cette même session de modification : permet d'ajouter
        // autant de lignes qu'on le souhaite (y compris plusieurs fois le même
        // produit) sans dépasser le stock réel de la boutique.
        function qteDejaAjouteePourProduit(produitId, boutiqueId) {
            let total = 0;
            $('#editLignesTable tbody tr.ligne-nouvelle').each(function() {
                const row = $(this);
                if (String(row.data('produit')) === String(produitId) && String(row.data('boutique')) === String(boutiqueId)) {
                    total += parseFloat(row.find('.qte').val()) || 0;
                }
            });
            return total;
        }

        // ------------------------------------------------------------
        // Configuration de lot pour la nouvelle ligne (même principe que
        // dans achat.php) : optionnelle — si non cochée, comportement
        // "produit simple" (pas de lot, produits_par_lot = 1).
        //
        // Si le lot choisi correspond à un lot du catalogue (menu "Configuration
        // des lots") ayant un prix de lot défini, on suggère automatiquement le
        // prix correspondant. Sinon (produit sans prix de lot configuré, ou
        // caissier qui a modifié "unités par lot" à la main), le prix reste
        // celui du produit — comportement strictement inchangé.
        // ------------------------------------------------------------
        function trouverLotCatalogue(libelle) {
            const lots = $newProd.data('lots') || [];
            return lots.find(l => l.libelle === libelle) || null;
        }

        // ----- Mode "produit catalogué" : structure du lot fixe (venant de
        // "Configuration des lots"). La quantité saisie est TOUJOURS le nombre
        // de pièces. Le prix saisi est le prix unitaire du produit tant qu'aucun
        // prix n'est configuré pour ce lot ; s'il y en a un, le prix saisi
        // devient le prix DU LOT (modifiable, utilisé pour le calcul). -----
        function majModeVenteCatalogue() {
            const $sel = $('#newLigneModeVente');
            const opt = $sel.find('option:selected');
            const libelle = $sel.val();
            if (libelle === 'unite') {
                reinitialiserLabelsQteEtPrix();
                $('#newLigneLotPrixInfo').hide();
                return;
            }
            const unites = parseInt(opt.data('unites')) || 1;
            const enCarton = !!$newProd.data('saisie-carton');
            const qteSaisie = parseInt($('#newLigneQte').val()) || 0;
            const qte = enCarton ? qteSaisie * unites : qteSaisie; // qte = toujours en pièces
            const nbLots = enCarton ? qteSaisie : Math.floor(qte / unites);
            const reste = enCarton ? 0 : qte % unites;
            const prixLotCatalogue = opt.data('prix-lot');
            const aPrixLot = (prixLotCatalogue !== '' && prixLotCatalogue !== undefined && prixLotCatalogue !== null);

            $('#newLigneQteLabel').text(enCarton ? 'Quantité (cartons)' : 'Quantité (pièces)');
            $('#newLigneQte').attr('placeholder', enCarton ? 'Nombre de cartons' : 'Nombre de pièces');
            $('#newLignePrixLabel').text(aPrixLot ? ('Prix du ' + libelle.toLowerCase()) : 'Prix unitaire');
            $('#newLigneModeVenteApercu').text(reste > 0 ? (nbLots + ' ' + libelle + '(s) et ' + reste + ' pièce(s)') : (nbLots + ' ' + libelle + '(s)'));
            $('#newLigneModeVentePrixLotZone').toggle(aPrixLot);

            if (!aPrixLot || qte <= 0) { $('#newLigneLotPrixInfo').hide(); return; }

            let prixLotSaisi = parseFloat($('#newLigneModeVentePrixLot').val());
            if (isNaN(prixLotSaisi)) {
                prixLotSaisi = parseFloat(prixLotCatalogue);
                $('#newLigneModeVentePrixLot').val(prixLotSaisi);
            }

            // Le prix affiché/modifiable dans "Prix du <lot>" EST directement
            // le prix par lot.
            const $prix = $('#newLignePrix');
            if ($prix.val() === '' || parseFloat($prix.val()) === $prix.data('derniere-suggestion')) {
                $prix.val(prixLotSaisi);
                $prix.data('derniere-suggestion', prixLotSaisi);
            }
            const prixUnitEffectif = Math.round(((parseFloat($prix.val()) || 0) / unites) * 100) / 100;
            const total = Math.floor(qte / unites) * (parseFloat($prix.val()) || 0);
            $('#newLigneLotPrixInfo').text(
                qte + ' pièce(s), soit ' + prixUnitEffectif.toLocaleString('fr-FR') + ' F/pièce (dérivé du prix du lot) = ' +
                total.toLocaleString('fr-FR') + ' F.'
            ).show();
        }
        $('#newLigneModeVente').on('change', function() {
            const opt = $(this).find('option:selected');
            if ($(this).val() === 'unite') {
                $('#newLigneModeVenteLotWrap').hide();
                reinitialiserLabelsQteEtPrix();
                $('#newLigneLotPrixInfo').hide();
            } else {
                const prixLotCatalogue = opt.data('prix-lot');
                const aPrixLot = (prixLotCatalogue !== '' && prixLotCatalogue !== undefined && prixLotCatalogue !== null);
                if (aPrixLot) {
                    $('#newLigneModeVentePrixLot').val(prixLotCatalogue);
                    $('#newLignePrix').val('').removeData('derniere-suggestion');
                } else {
                    // Pas de prix de lot configuré : le champ "Prix" reste le
                    // prix unitaire du produit, déjà pré-rempli plus haut.
                    $('#newLigneModeVentePrixLot').val('');
                }
                $('#newLigneModeVenteLotWrap').show();
                majModeVenteCatalogue();
            }
        });
        $('#newLigneModeVentePrixLot').on('input change', majModeVenteCatalogue);

        // ----- Mode "ad-hoc" (produit pas encore catalogué) : configuration
        // libre. La quantité saisie est TOUJOURS le nombre de pièces ; le prix
        // reste le prix unitaire (pas de prix de lot ad-hoc). -----
        let derniereLibelleLotNouvelleLigne = null;
        function majApercuLotNouvelleLigne() {
            const libelle = $('#newLigneLotLibelle').val();
            if (libelle !== derniereLibelleLotNouvelleLigne) {
                derniereLibelleLotNouvelleLigne = libelle;
                const lc = trouverLotCatalogue(libelle);
                if (lc) $('#newLigneLotUnites').val(lc.unites_par_lot);
            }
            const unites = Math.max(2, parseInt($('#newLigneLotUnites').val()) || 2);
            const qte = parseInt($('#newLigneQte').val()) || 0;
            const nbLots = Math.floor(qte / unites);
            const reste = qte % unites;
            $('#newLigneQteLabel').text('Quantité (pièces)');
            $('#newLignePrixLabel').text('Prix unitaire');
            $('#newLigneLotApercu').text(reste > 0 ? (nbLots + ' ' + libelle + '(s) et ' + reste + ' pièce(s)') : (nbLots + ' ' + libelle + '(s)'));
            $('#newLigneLotPrixInfo').hide(); // pas de prix de lot pour ce produit
        }
        $('#newLigneLotConfigure').on('change', function() {
            if (this.checked) { $('#newLigneLotDetails').show(); majApercuLotNouvelleLigne(); }
            else { $('#newLigneLotDetails').hide(); reinitialiserLabelsQteEtPrix(); $('#newLigneLotPrixInfo').hide(); }
        });
        $('#newLigneLotUnites, #newLigneLotLibelle').on('input change', majApercuLotNouvelleLigne);

        // La quantité peut alimenter l'un ou l'autre mode selon celui actif.
        $('#newLigneQte').on('input change', function() {
            if ($('#newLigneVenteCatalogue').is(':visible') && $('#newLigneModeVente').val() !== 'unite') {
                majModeVenteCatalogue();
            } else if ($('#newLigneLotConfigure').is(':checked')) {
                majApercuLotNouvelleLigne();
            }
        });

        $('#btnAjouterLigne').click(function() {
            const produit = $newProd.val();
            const produitTexte = $newProd.find('option:selected').text();
            const boutique = $('#editContent').data('boutique-id');
            const prixSaisi = parseFloat($('#newLignePrix').val()) || 0;

            // Le lot vient soit du mode catalogue (structure fixe), soit du mode
            // ad-hoc (produit pas encore catalogué). Le prix saisi est le prix du
            // lot uniquement si un prix de lot est configuré en catalogue pour ce
            // type ; sinon (ad-hoc, ou lot catalogue sans prix configuré), c'est
            // le prix unitaire classique.
            let lotConfigure, unitesParLot, libelleLot, prixEstLot;
            const modeCatalogueActif = $('#newLigneVenteCatalogue').is(':visible');
            if (modeCatalogueActif && $('#newLigneModeVente').val() !== 'unite') {
                lotConfigure = true;
                const opt = $('#newLigneModeVente').find('option:selected');
                unitesParLot = parseInt(opt.data('unites')) || 2;
                libelleLot = $('#newLigneModeVente').val();
                const prixLotCatalogue = opt.data('prix-lot');
                prixEstLot = (prixLotCatalogue !== '' && prixLotCatalogue !== undefined && prixLotCatalogue !== null);
            } else if (!modeCatalogueActif && $('#newLigneLotConfigure').is(':checked')) {
                lotConfigure = true;
                unitesParLot = Math.max(2, parseInt($('#newLigneLotUnites').val()) || 2);
                libelleLot = $('#newLigneLotLibelle').val() || 'Unité';
                prixEstLot = false;
            } else {
                lotConfigure = false;
                unitesParLot = 1;
                libelleLot = 'Unité';
                prixEstLot = false;
            }

            // Saisie : nombre de CARTONS pour un produit "saisie_par_carton"
            // (fiche produit) tant qu'un mode lot catalogue est actif ; nombre
            // de PIÈCES dans tous les autres cas (comportement standard,
            // inchangé). qte reste TOUJOURS en pièces en interne (payload,
            // contrôle de stock, ligne insérée).
            const enModeCarton = modeCatalogueActif && $('#newLigneModeVente').val() !== 'unite' && !!$newProd.data('saisie-carton');
            const qteSaisie = parseInt($('#newLigneQte').val()) || 0;
            const qte = enModeCarton ? qteSaisie * unitesParLot : qteSaisie;

            if (!produit) { showToast('Choisissez une catégorie puis un produit.', 'error'); return; }
            if (!boutique) { showToast('Boutique du bon introuvable : ajout impossible.', 'error'); return; }
            if (qte <= 0) { showToast('Saisissez une quantité valide (> 0).', 'error'); return; }

            function reinitialiserFormulaireNouvelleLigne() {
                $('#newLigneQte, #newLignePrix').val('');
                $('#newLigneLotConfigure').prop('checked', false);
                $('#newLigneLotDetails').hide();
                $('#newLigneLotUnites').val(2);
                $('#newLigneLotLibelle').val('Unité');
                $('#newLigneModeVente').val('unite');
                $('#newLigneModeVenteLotWrap').hide();
                $('#newLigneModeVentePrixLotZone').hide();
                $('#newLigneModeVentePrixLot').val('');
                $('#newLigneLotPrixInfo').hide();
                appliquerModeVenteProduit([]);
                filtrerProduitsNouvelleLigne();
            }

            // Le produit est-il déjà présent dans ce bon (ligne existante ou déjà
            // ajoutée dans cette session) ? Si oui, on augmente simplement sa
            // quantité au lieu de dupliquer une ligne pour le même produit.
            const ligneExistante = $('#editLignesTable tbody tr').filter(function() {
                return String($(this).data('produit')) === String(produit) && !$(this).hasClass('ligne-a-supprimer');
            }).first();

            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: { action: 'check_stock_produit', produit_id: produit, boutique_id: boutique, csrf_token: CSRF_TOKEN },
                success: function(resp) {
                    if (!resp.success) { showToast('Produit introuvable.', 'error'); return; }
                    const dejaAjoute = qteDejaAjouteePourProduit(produit, boutique);
                    const disponibleRestant = resp.disponible - dejaAjoute;
                    if (disponibleRestant < qte) {
                        showToast('Stock insuffisant pour « ' + resp.titre + ' » : disponible ' + disponibleRestant + (dejaAjoute > 0 ? ' (après ' + dejaAjoute + ' déjà ajouté(s) dans ce bon)' : '') + ', demandé ' + qte + ' unité(s).', 'error');
                        return;
                    }

                    if (ligneExistante.length) {
                        const qteActuelle = parseFloat(ligneExistante.find('.qte').val()) || 0;
                        const nouvelleQte = qteActuelle + qte;
                        ligneExistante.find('.qte').val(nouvelleQte).trigger('change');
                        reinitialiserFormulaireNouvelleLigne();
                        showToast((resp.titre || produitTexte) + ' est déjà dans ce bon : quantité augmentée à ' + nouvelleQte + ' pièce(s) sur la ligne existante.', 'success');
                        return;
                    }

                    const prixFinal = prixSaisi > 0 ? prixSaisi : resp.prix;
                    const nbLotsInitial = Math.floor(qte / unitesParLot);
                    // Montant : nb de lots complets × prix DU LOT si un prix de lot
                    // est configuré, qté (pièces) × prix unitaire sinon.
                    const montant = (prixEstLot && unitesParLot > 1) ? nbLotsInitial * prixFinal : qte * prixFinal;
                    const row = `<tr class="ligne-commande ligne-nouvelle" data-id="" data-produit="${produit}" data-boutique="${boutique}"
                        data-lot-configure="${lotConfigure ? '1' : '0'}" data-prix-est-lot="${prixEstLot ? '1' : '0'}" data-libelle-lot="${escHtml(libelleLot)}">
                        <td class="fw-semibold">${escHtml(resp.titre || produitTexte)} <span class="badge bg-primary-subtle text-primary" style="font-size:9px;">nouveau</span></td>
                        <td class="text-center"><input type="number" class="form-control form-control-sm qte" value="${qte}" min="0" style="width:80px;display:inline-block;text-align:center;"></td>
                        <td class="text-center"><input type="number" class="form-control form-control-sm produits-par-lot" value="${unitesParLot}" min="1" style="width:90px;display:inline-block;text-align:center;"></td>
                        <td class="text-center nb-lots-ligne fw-bold">${nbLotsInitial}</td>
                        <td class="text-end"><input type="number" class="form-control form-control-sm prix" value="${prixFinal}" min="0" style="width:120px;display:inline-block;text-align:right;"></td>
                        <td class="text-end montant-ligne fw-bold">${montant.toLocaleString('fr-FR')}</td>
                        <td class="text-center"><button class="btn-delete-chic supprimer-ligne" title="Supprimer"><i class="bi bi-trash3"></i></button></td>
                    </tr>`;
                    $('#editLignesTable tbody').append(row);
                    // On ne réinitialise QUE le produit / quantité / prix / lot : la
                    // catégorie reste sélectionnée pour enchaîner rapidement l'ajout de
                    // plusieurs lignes (autant qu'on le souhaite) sans tout re-choisir à
                    // chaque fois. On repasse par filtrerProduitsNouvelleLigne()
                    // (reconstruction complète du select) plutôt que 'selectpicker(val, "")' :
                    // sur bootstrap-select 1.14 beta, 'val' seul ne réaffiche pas toujours
                    // correctement le placeholder après une sélection (le nom du produit
                    // choisi restait affiché) — la reconstruction garantit un affichage fiable.
                    reinitialiserFormulaireNouvelleLigne();
                    recalculerTotaux();
                    showToast('Ligne ajoutée : ' + (resp.titre || produitTexte) + '. Vous pouvez continuer à ajouter d\'autres lignes.', 'success');
                },
                error: function() { showToast('Erreur de vérification du stock.', 'error'); }
            });
        });

        $('#edit_avance').on('input', recalculerTotaux);

        // Montant d'une ligne : si le champ "Prix" contient un prix DE LOT (flag
        // data-prix-est-lot, posé uniquement pour les lignes ajoutées via le
        // catalogue) -> montant = nb de cartons complets × prix du lot. Sinon
        // (produit simple ou lot ad-hoc à prix/unité) -> montant = qté × prix/unité,
        // comme toujours. La quantité en base reste toujours en unités dans les deux cas.
        function montantLigne(row) {
            const qte = parseFloat(row.find('.qte').val()) || 0;
            const ppl = Math.max(1, parseFloat(row.find('.produits-par-lot').val()) || 1);
            const prix = parseFloat(row.find('.prix').val()) || 0;
            const prixEstLot = row.data('prix-est-lot') == 1 || row.data('prix-est-lot') === '1';
            if (prixEstLot && ppl > 1) return Math.floor(qte / ppl) * prix;
            return qte * prix;
        }
        $(document).on('change', '#editLignesTable .qte, #editLignesTable .prix', function() {
            const row = $(this).closest('tr');
            row.find('.montant-ligne').text(montantLigne(row).toLocaleString('fr-FR'));
            recalculerTotaux();
        });
        $(document).on('change', '#editLignesTable .qte, #editLignesTable .produits-par-lot', function() {
            const row = $(this).closest('tr');
            const qte = parseFloat(row.find('.qte').val()) || 0;
            const ppl = Math.max(1, parseFloat(row.find('.produits-par-lot').val()) || 1);
            row.find('.nb-lots-ligne').text(Math.floor(qte / ppl));
            row.find('.montant-ligne').text(montantLigne(row).toLocaleString('fr-FR'));
            recalculerTotaux();
        });
        $(document).on('click', '#editLignesTable .supprimer-ligne', function() {
            const row = $(this).closest('tr');
            const estNouvelle = row.hasClass('ligne-nouvelle');
            genericConfirm('Supprimer cette ligne ?', function() {
                if (estNouvelle) {
                    // Ligne pas encore enregistrée en base : on peut la retirer
                    // complètement du tableau, elle ne sera jamais envoyée au serveur.
                    row.remove();
                } else {
                    // Ligne existante : on ne la retire PAS du DOM, sinon elle
                    // n'est plus envoyée au serveur et n'est donc jamais supprimée
                    // en base. On la marque à quantité 0 (convention déjà gérée
                    // côté serveur : quantité 0 = suppression + restitution du
                    // stock) et on la grise, avec possibilité d'annuler.
                    row.data('qte-avant-suppression', row.find('.qte').val());
                    row.data('prix-avant-suppression', row.find('.prix').val());
                    row.find('.qte').val(0);
                    row.addClass('ligne-a-supprimer');
                    row.css({ opacity: 0.5, textDecoration: 'line-through' });
                    row.find('input').prop('disabled', true);
                    row.find('.montant-ligne').text('0');
                    row.find('.nb-lots-ligne').text('0');
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
            const ppl = Math.max(1, parseFloat(row.find('.produits-par-lot').val()) || 1);
            row.find('.nb-lots-ligne').text(Math.floor(qte / ppl));
            row.find('.montant-ligne').text(montantLigne(row).toLocaleString('fr-FR'));
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
        // Le serveur (comme l'historique de toutes les lignes déjà enregistrées)
        // attend toujours un prix/unité. Pour une ligne où "Prix" contient un
        // prix DE LOT (flag data-prix-est-lot), on convertit ici seulement,
        // jamais à l'écran.
        function prixUnitePourEnvoi(row) {
            const prix = parseFloat(row.find('.prix').val()) || 0;
            const ppl = Math.max(1, parseFloat(row.find('.produits-par-lot').val()) || 1);
            const prixEstLot = row.data('prix-est-lot') == 1 || row.data('prix-est-lot') === '1';
            return (prixEstLot && ppl > 1) ? Math.round((prix / ppl) * 100) / 100 : prix;
        }
        // Le prix du lot TEL QUE SAISI (sans division), à conserver tel quel en
        // base pour un affichage futur sans reconstruction ni erreur d'arrondi.
        // null si la ligne n'est pas vendue par lot.
        function prixLotPourEnvoi(row) {
            const prixEstLot = row.data('prix-est-lot') == 1 || row.data('prix-est-lot') === '1';
            return prixEstLot ? (parseFloat(row.find('.prix').val()) || 0) : null;
        }

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
                            produits_par_lot: Math.max(1, parseInt(row.find('.produits-par-lot').val()) || 1),
                            prix: prixUnitePourEnvoi(row),
                            prix_lot: prixLotPourEnvoi(row),
                            lot_configure: row.data('lot-configure') == 1 || row.data('lot-configure') === '1',
                            libelle_lot: row.data('libelle-lot') || 'Unité'
                        });
                    }
                } else {
                    commandes.push({
                        id: id,
                        quantite: qte,
                        produits_par_lot: Math.max(1, parseInt(row.find('.produits-par-lot').val()) || 1),
                        prix: prixUnitePourEnvoi(row),
                        prix_lot: prixLotPourEnvoi(row),
                        supprimer: qte === 0
                    });
                }
            });
            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: {
                    action: 'update_facture',
                    facture_id: $('#editContent').data('facture-id'),
                    avance: parseFloat($('#edit_avance').val()) || 0,
                    reste: parseFloat($('#edit_reste').val()) || 0,
                    commandes: JSON.stringify(commandes),
                    nouvelles_lignes: JSON.stringify(nouvellesLignes),
                    csrf_token: CSRF_TOKEN
                },
                success: function(resp) {
                    if (resp.success) {
                        showToast(resp.message || 'Facture mise à jour', (resp.lignes_non_ajustees && resp.lignes_non_ajustees.length) ? 'info' : 'success');
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