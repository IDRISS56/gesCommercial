<?php
// views/transaction/transaction_pdf.php – génération du reçu PDF pour une
// transaction de l'historique (views/transaction/index.php), déclenchée par
// les boutons Imprimer/Partager du modal "Voir".
//
// Deux mises en page selon le type de transaction :
//  - "Vente comptoir" (aucune vraie facture, aucun client garanti) : ticket
//    listant les produits vendus (reconstitués depuis `commande`, valable
//    aussi pour l'historique déjà en base).
//  - Tout le reste (règlement client/fournisseur, avance, décaissement) :
//    reçu de paiement listant la/les facture(s) couvertes par ce règlement,
//    via la table transaction_facture (uniquement pour les règlements
//    enregistrés après la mise en place de cette table — repli sur
//    transaction.facture_id/montant_applique_facture pour les anciens).
//
// Inclus tel quel dans views/transaction/index.php ; partage sa portée
// ($pdo, $csrf_token déjà définis avant l'include).

if (isset($_POST['action']) && $_POST['action'] === 'pdf') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    require_once 'librairies/fpdf/fpdf.php';
    $csrfPdf = $_POST['csrf_token'] ?? '';
    if (empty($csrfPdf) || $csrfPdf !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die("Token de sécurité invalide.");
    }
    $numTransPdf = trim($_POST['numero'] ?? '');
    if (empty($numTransPdf)) die("Numéro de transaction manquant.");

    try {
        $stmtT = $pdo->prepare("SELECT t.*, c.nom_caisse, c.boutique_id,
                u.nom_prenom AS utilisateur_nom,
                ct.nom_prenom_contact, ct.telephone_contact, ct.email_contact, ct.adresse_contact, ct.solde_contact
            FROM transaction t
            LEFT JOIN caisse c ON c.caisse_id = t.caisse_id
            LEFT JOIN utilisateur u ON u.id = t.utilisateur_id
            LEFT JOIN contact ct ON ct.code_contact = t.contact_id
            WHERE t.numero_transaction = ?");
        $stmtT->execute([$numTransPdf]);
        $tr = $stmtT->fetch(PDO::FETCH_ASSOC);
        if (!$tr) die("Transaction introuvable.");

        $boutique_id = $tr['boutique_id'] ?? null;
        if (!empty($boutique_id) && !in_array($boutique_id, $boutiquesAutoriseesTransaction, true)) {
            http_response_code(403);
            die("Accès refusé : cette transaction appartient à une autre boutique.");
        }
        $boutique = null;
        if (!empty($boutique_id)) {
            $stmtB = $pdo->prepare("SELECT * FROM boutique WHERE code_boutique = ?");
            $stmtB->execute([$boutique_id]);
            $boutique = $stmtB->fetch(PDO::FETCH_ASSOC);
        }

        $estVenteComptoir = ($tr['objet_transaction'] ?? '') === 'Vente comptoir';
        $lignesComptoir = [];
        $lignesFactures = [];

        if ($estVenteComptoir) {
            $stmtL = $pdo->prepare("SELECT cmd.*, p.titre_produit, p.code_produit AS reference_produit
                FROM commande cmd LEFT JOIN produit p ON p.code_produit = cmd.produit_id
                WHERE cmd.facture_id = ?");
            $stmtL->execute([$tr['facture_id']]);
            $lignesComptoir = $stmtL->fetchAll(PDO::FETCH_ASSOC);
            // Repli : les tickets créés avant l'ajout de transaction.contact_id
            // n'ont pas de client sur la transaction elle-même, mais chaque
            // ligne `commande` du ticket garde son contact_id.
            if (empty($tr['nom_prenom_contact']) && !empty($lignesComptoir)) {
                $contactIdLigne = $lignesComptoir[0]['contact_id'] ?? null;
                if (!empty($contactIdLigne)) {
                    $stmtC = $pdo->prepare("SELECT nom_prenom_contact, telephone_contact, email_contact, adresse_contact FROM contact WHERE code_contact = ?");
                    $stmtC->execute([$contactIdLigne]);
                    $cInfo = $stmtC->fetch(PDO::FETCH_ASSOC);
                    if ($cInfo) {
                        $tr['nom_prenom_contact'] = $cInfo['nom_prenom_contact'];
                        $tr['telephone_contact'] = $cInfo['telephone_contact'];
                        $tr['email_contact'] = $cInfo['email_contact'];
                        $tr['adresse_contact'] = $cInfo['adresse_contact'];
                    }
                }
            }
        } else {
            $stmtLF = $pdo->prepare("SELECT * FROM transaction_facture WHERE numero_transaction = ? ORDER BY id ASC");
            $stmtLF->execute([$numTransPdf]);
            $lignesFactures = $stmtLF->fetchAll(PDO::FETCH_ASSOC);

            // Repli pour une transaction enregistrée avant la mise en place de
            // transaction_facture : on ne connaît que l'unique facture liée
            // (transaction.facture_id), avec le reste ACTUEL de la facture
            // (peut avoir évolué depuis si d'autres paiements sont arrivés
            // après celui-ci — au mieux, non garanti historiquement exact).
            if (empty($lignesFactures) && !empty($tr['facture_id'])) {
                $stmtF = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ?");
                $stmtF->execute([$tr['facture_id']]);
                $factureRepli = $stmtF->fetch(PDO::FETCH_ASSOC);
                if ($factureRepli) {
                    $montantAppliqueRepli = $tr['montant_applique_facture'];
                    if ($montantAppliqueRepli === null) $montantAppliqueRepli = $tr['montant_transaction'];
                    $resteApresRepli = floatval($factureRepli['reste']);
                    $lignesFactures[] = [
                        'numero_facture' => $factureRepli['numero_facture'],
                        'montant_applique' => $montantAppliqueRepli,
                        'reste_avant' => $resteApresRepli + floatval($montantAppliqueRepli),
                        'reste_apres' => $resteApresRepli,
                        '__date_facture' => $factureRepli['date_facture'],
                        '__montant_ttc' => $factureRepli['montant_ttc'],
                    ];
                }
            }
        }
    } catch (Exception $e) {
        http_response_code(500);
        die("Erreur lors de la génération du reçu : " . $e->getMessage());
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
    $nomBoutique = $boutique['nom_boutique'] ?? '';
    $adresseBoutique = trim(($boutique['adresse_boutique'] ?? '') . (!empty($boutique['quartier_boutique']) ? ', ' . $boutique['quartier_boutique'] : '') . (!empty($boutique['ville_boutique']) ? ', ' . $boutique['ville_boutique'] : ''));
    $telBoutique = $boutique['telephone_boutique'] ?? '';

    class PdfRecu extends FPDF {
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

    $pdf = new PdfRecu('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(10, 10, 10);

    $vert  = [91, 161, 64];
    $navy  = [21, 61, 122];
    $grey  = [242, 242, 242];
    $bordC = [190, 190, 190];

    $estEntree = ($tr['type_transaction'] ?? '') === 'Entree';
    $titreDoc = $estVenteComptoir ? 'TICKET DE VENTE' : ($estEntree ? 'REÇU DE PAIEMENT' : 'REÇU DE DÉCAISSEMENT');

    // ---------- En-tête ----------
    $pdf->SetTextColor($navy[0], $navy[1], $navy[2]);
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetXY(10, 12);
    $pdf->Cell(130, 10, $titreDoc, 0, 1, 'L');
    if ($logoTmpPath) {
        $pdf->Image($logoTmpPath, 155, 8, 45);
        unlink($logoTmpPath);
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetX(10);
    $pdf->Cell(130, 6, 'N° ' . $tr['numero_transaction'], 0, 1, 'L');
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->SetX(10);
    $dateAffichee = ($tr['date_transaction'] ?? '') ? date('d/m/Y', strtotime($tr['date_transaction'])) : '';
    $pdf->Cell(130, 5, 'Date : ' . $dateAffichee . ($tr['heure_transaction'] ? ' à ' . substr($tr['heure_transaction'], 0, 5) : ''), 0, 1, 'L');
    if (!empty($tr['nom_prenom_contact'])) {
        $pdf->SetX(10);
        $pdf->Cell(130, 5, ($estVenteComptoir ? 'Client : ' : ($estEntree ? 'Client : ' : 'Fournisseur : ')) . $tr['nom_prenom_contact'], 0, 1, 'L');
    }
    if (!empty($tr['mode_reglement'])) {
        $pdf->SetX(10);
        $pdf->Cell(130, 5, 'Méthode de paiement : ' . $tr['mode_reglement'], 0, 1, 'L');
    }
    $pdf->SetX(10);
    $pdf->Cell(130, 5, 'Montant : ' . number_format(floatval($tr['montant_transaction']), 0, ',', ' ') . ' F', 0, 1, 'L');
    $pdf->Ln(6);

    if ($estVenteComptoir) {
        // ---------- Ticket : liste des produits ----------
        $pdf->SetFillColor($vert[0], $vert[1], $vert[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(85, 7, 'DÉSIGNATION', 0, 0, 'L', true);
        $pdf->Cell(30, 7, 'QTÉ', 0, 0, 'C', true);
        $pdf->Cell(35, 7, 'P.U. (FCFA)', 0, 0, 'R', true);
        $pdf->Cell(40, 7, 'MONTANT (FCFA)', 0, 1, 'R', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor($bordC[0], $bordC[1], $bordC[2]);
        $pdf->SetFont('Arial', '', 9);
        $totalTicket = 0;
        foreach ($lignesComptoir as $l) {
            $montantLigne = floatval($l['montant_commande']);
            $totalTicket += $montantLigne;
            $designation = $l['titre_produit'] ?? $l['produit_id'];
            $x = $pdf->GetX(); $y = $pdf->GetY();
            $nb = max(1, ceil(strlen($designation) / 45));
            $rowH = 6 * $nb;
            if ($pdf->GetY() + $rowH > 270) $pdf->AddPage();
            $pdf->MultiCell(85, 6, $designation, 1, 'L');
            $pdf->SetXY($x + 85, $y);
            $pdf->Cell(30, $rowH, $l['quantite_commande'], 1, 0, 'C');
            $pdf->Cell(35, $rowH, number_format($l['prix_commande'], 0, ',', ' '), 1, 0, 'R');
            $pdf->Cell(40, $rowH, number_format($montantLigne, 0, ',', ' '), 1, 1, 'R');
        }
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(150, 7, 'TOTAL PAYÉ', 0, 0, 'R');
        $pdf->Cell(40, 7, number_format(floatval($tr['montant_transaction']), 0, ',', ' ') . ' F', 0, 1, 'R');
    } else {
        // ---------- Reçu de paiement : factures couvertes ----------
        if (!empty($lignesFactures)) {
            $pdf->SetFillColor($navy[0], $navy[1], $navy[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 8.5);
            $pdf->Cell(45, 7, 'NUMÉRO DE FACTURE', 0, 0, 'C', true);
            $pdf->Cell(35, 7, 'MONTANT INITIAL', 0, 0, 'C', true);
            $pdf->Cell(35, 7, 'MONTANT DÛ AVANT', 0, 0, 'C', true);
            $pdf->Cell(35, 7, 'MONTANT PAYÉ', 0, 0, 'C', true);
            $pdf->Cell(40, 7, 'SOLDE APRÈS', 0, 1, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetDrawColor($bordC[0], $bordC[1], $bordC[2]);
            $pdf->SetFont('Arial', '', 8.5);
            $totalPayeFactures = 0;
            $totalResteApres = 0;
            foreach ($lignesFactures as $lf) {
                $montantInitial = $lf['__montant_ttc'] ?? null;
                if ($montantInitial === null) {
                    $stmtMI = $pdo->prepare("SELECT montant_ttc FROM facture WHERE numero_facture = ?");
                    $stmtMI->execute([$lf['numero_facture']]);
                    $montantInitial = $stmtMI->fetchColumn();
                }
                $totalPayeFactures += floatval($lf['montant_applique']);
                $totalResteApres += floatval($lf['reste_apres']);
                $pdf->Cell(45, 6, $lf['numero_facture'], 1, 0, 'L');
                $pdf->Cell(35, 6, number_format(floatval($montantInitial), 0, ',', ' '), 1, 0, 'R');
                $pdf->Cell(35, 6, number_format(floatval($lf['reste_avant']), 0, ',', ' '), 1, 0, 'R');
                $pdf->Cell(35, 6, number_format(floatval($lf['montant_applique']), 0, ',', ' '), 1, 0, 'R');
                $pdf->Cell(40, 6, number_format(floatval($lf['reste_apres']), 0, ',', ' '), 1, 1, 'R');
            }
            $pdf->Ln(4);
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(140, 6, 'Montant versé sur ces factures', 0, 0, 'L');
            $pdf->Cell(50, 6, number_format($totalPayeFactures, 0, ',', ' ') . ' F', 0, 1, 'R');
            $pdf->Cell(140, 6, 'Solde restant sur ces factures après ce paiement', 0, 0, 'L');
            $pdf->Cell(50, 6, number_format($totalResteApres, 0, ',', ' ') . ' F', 0, 1, 'R');
        } else {
            $pdf->SetFont('Arial', '', 10);
            $pdf->MultiCell(190, 6, "Ce montant ne concerne aucune facture précise : il a été enregistré comme " . ($estEntree ? "avance sur le compte du client." : "avance ou décaissement sur le compte du fournisseur."), 0, 'L');
        }

        if (isset($tr['solde_contact']) && $tr['solde_contact'] !== null) {
            $pdf->Ln(3);
            $solde = floatval($tr['solde_contact']);
            if (abs($solde) < 0.01) {
                $libelleSolde = 'Solde total ' . ($estEntree ? 'client' : 'fournisseur') . ' — Solde nul';
            } elseif ($solde > 0) {
                $libelleSolde = 'Solde total ' . ($estEntree ? 'client' : 'fournisseur') . ' — doit encore ' . number_format($solde, 0, ',', ' ') . ' F';
            } else {
                $libelleSolde = 'Solde total ' . ($estEntree ? 'client' : 'fournisseur') . ' — en avance de ' . number_format(abs($solde), 0, ',', ' ') . ' F';
            }
            $pdf->SetFont('Arial', 'B', 9.5);
            $pdf->Cell(190, 6, $libelleSolde, 0, 1, 'L');
        }
    }

    $pdf->Ln(10);
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(190, 4, 'Enregistré par ' . ($tr['utilisateur_nom'] ?? '—') . ($tr['nom_caisse'] ? ' — Caisse : ' . $tr['nom_caisse'] : ''), 0, 1, 'L');
    if ($nomBoutique) {
        $pdf->Cell(190, 4, $nomBoutique . ($adresseBoutique ? ' — ' . $adresseBoutique : '') . ($telBoutique ? ' — Tel: ' . $telBoutique : ''), 0, 1, 'L');
    }

    $modePdf = (isset($_POST['mode']) && $_POST['mode'] === 'D') ? 'D' : 'I';
    $pdf->Output($modePdf, ($estVenteComptoir ? 'Ticket_' : 'Recu_') . $tr['numero_transaction'] . '.pdf');
    exit;
}