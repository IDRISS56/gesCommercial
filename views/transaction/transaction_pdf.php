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

        // Dépense (menu Dépenses) : pas de fournisseur ni de facture, on imprime
        // la catégorie et le motif. Table absente (migration pas encore passée) = ignoré.
        $depenseInfo = null;
        try {
            $stmtDp = $pdo->prepare("SELECT d.motif, d.categorie
                FROM depense d
                WHERE d.numero_transaction = ?");
            $stmtDp->execute([$numTransPdf]);
            $depenseInfo = $stmtDp->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $eDp) { $depenseInfo = null; }

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

    // =====================================================================
    // REÇU DE PAIEMENT / DÉCAISSEMENT / DÉPENSE : page cadrée sur son contenu
    // (largeur fixe 200 mm, hauteur ajustée au contenu — pas de format A4).
    // Le dessin est exécuté deux fois : une première fois pour mesurer la
    // hauteur nécessaire, une seconde sur une page à cette hauteur exacte.
    // =====================================================================
    if (!$estVenteComptoir) {
        $estEntree = ($tr['type_transaction'] ?? '') === 'Entree';
        $navy      = [21, 61, 122];
        $libTiers  = $estEntree ? 'Client' : 'Fournisseur';
        $fmtCfa    = function ($v) { return number_format(floatval($v), 0, ',', ' ') . ' CFA'; };
        $titreRecu = $depenseInfo ? 'Reçu de dépense' : ($estEntree ? 'Reçu de paiement' : 'Reçu de décaissement');

        // Factures couvertes, enrichies de la date et du montant initial
        $lignesRecu = [];
        $totalPaye = 0;
        $totalReste = 0;
        if (!$depenseInfo) {
            foreach ($lignesFactures as $lf) {
                $dateF = $lf['__date_facture'] ?? null;
                $ttc   = $lf['__montant_ttc'] ?? null;
                if ($dateF === null || $ttc === null) {
                    $stmtMI = $pdo->prepare("SELECT date_facture, montant_ttc FROM facture WHERE numero_facture = ?");
                    $stmtMI->execute([$lf['numero_facture']]);
                    $fInfo = $stmtMI->fetch(PDO::FETCH_ASSOC) ?: [];
                    if ($dateF === null) $dateF = $fInfo['date_facture'] ?? null;
                    if ($ttc === null)   $ttc   = $fInfo['montant_ttc'] ?? 0;
                }
                $totalPaye  += floatval($lf['montant_applique']);
                $totalReste += floatval($lf['reste_apres']);
                $lignesRecu[] = [
                    'date'    => $dateF ? date('d/m/Y', strtotime($dateF)) : '',
                    'numero'  => $lf['numero_facture'],
                    'initial' => floatval($ttc),
                    'du'      => floatval($lf['reste_avant']),
                    'paye'    => floatval($lf['montant_applique']),
                    'solde'   => floatval($lf['reste_apres']),
                ];
            }
        }
        $nbFactures = count($lignesRecu);
        $plur = $nbFactures > 1;

        // Bandeau de statut + phrase d'explication
        if ($depenseInfo) {
            $statut = 'DÉPENSE ENREGISTRÉE'; $couleurStatut = $navy;
            $phraseStatut = 'Cette dépense a été payée depuis la caisse.';
        } elseif ($nbFactures > 0) {
            if ($totalReste <= 0.01) {
                $statut = $plur ? 'FACTURES SOLDÉES' : 'FACTURE SOLDÉE'; $couleurStatut = [22, 163, 74];
                $phraseStatut = $plur ? 'Ces factures sont entièrement réglées.' : 'Cette facture est entièrement réglée.';
            } else {
                $statut = 'PAIEMENT PARTIEL'; $couleurStatut = [217, 119, 6];
                $phraseStatut = 'Il reste ' . $fmtCfa($totalReste) . ' à payer sur ' . ($plur ? 'ces factures.' : 'cette facture.');
            }
        } else {
            $statut = $estEntree ? 'AVANCE SUR COMPTE' : 'DÉCAISSEMENT'; $couleurStatut = $navy;
            $phraseStatut = 'Ce montant ne concerne aucune facture précise : il a été enregistré comme ' . ($estEntree ? 'avance sur le compte du client.' : 'avance ou décaissement sur le compte du fournisseur.');
        }

        // Solde global du tiers
        $soldeLabel = null; $soldeValeur = null; $soldePhrase = null;
        if (!$depenseInfo && isset($tr['solde_contact']) && $tr['solde_contact'] !== null) {
            $solde = floatval($tr['solde_contact']);
            $nomTiers = strtoupper($libTiers);
            if (abs($solde) < 0.01) {
                $soldeLabel = "SOLDE TOTAL $nomTiers - Solde nul"; $soldeValeur = '0 CFA';
                $soldePhrase = 'Solde global du ' . strtolower($libTiers) . ' : aucun montant restant entre le ' . strtolower($libTiers) . ' et la société.';
            } elseif ($solde > 0) {
                $soldeLabel = "SOLDE TOTAL $nomTiers - Doit encore"; $soldeValeur = $fmtCfa($solde);
                $soldePhrase = 'Solde global du ' . strtolower($libTiers) . ' : il reste ' . $fmtCfa($solde) . ' à régler.';
            } else {
                $soldeLabel = "SOLDE TOTAL $nomTiers - En avance"; $soldeValeur = $fmtCfa(abs($solde));
                $soldePhrase = 'Solde global du ' . strtolower($libTiers) . ' : en avance de ' . $fmtCfa(abs($solde)) . '.';
            }
        }

        $memo = implode(', ', array_column($lignesRecu, 'numero'));
        $dateAffichee = ($tr['date_transaction'] ?? '') ? date('d/m/Y', strtotime($tr['date_transaction'])) : '';

        $dessiner = function ($pdf) use ($tr, $titreRecu, $libTiers, $fmtCfa, $lignesRecu, $totalPaye, $totalReste,
                                        $statut, $couleurStatut, $phraseStatut, $soldeLabel, $soldeValeur, $soldePhrase,
                                        $memo, $dateAffichee, $depenseInfo, $navy, $logoTmpPath, $nomBoutique,
                                        $adresseBoutique, $telBoutique) {
            $L = 8; $W = 184; $R = $L + $W;
            $c8 = function ($s) { $x = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string)$s); return $x !== false ? $x : $s; };
            $pdf->SetMargins($L, 8, $L);
            $pdf->SetAutoPageBreak(false);
            $pdf->SetLineWidth(0.3);
            $pdf->SetDrawColor(40, 40, 40);

            // Logo (haut droit)
            if ($logoTmpPath && is_file($logoTmpPath)) {
                $sz = @getimagesize($logoTmpPath);
                $lw = ($sz && $sz[1] > 0) ? min(38, 14 * $sz[0] / $sz[1]) : 28;
                $pdf->Image($logoTmpPath, $R - $lw, 8, $lw);
            }

            // Titre
            $pdf->SetTextColor(50, 50, 50);
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->SetXY($L, 8);
            $pdf->Cell(140, 9, $titreRecu . ' : ' . $tr['numero_transaction'], 0, 1, 'L');
            $pdf->SetTextColor(0, 0, 0);

            // Champ "libellé : valeur" (renvoie le Y suivant)
            $champ = function ($x, $y, $largeur, $lab, $val) use ($pdf, $c8) {
                $pdf->SetXY($x, $y);
                $pdf->SetFont('Arial', 'B', 9);
                $lw = $pdf->GetStringWidth($c8($lab . ' ')) + 1;
                $pdf->Cell($lw, 5, $lab, 0, 0, 'L');
                $pdf->SetFont('Arial', '', 9);
                $pdf->MultiCell($largeur - $lw, 5, $val, 0, 'L');
                return $pdf->GetY() + 0.5;
            };

            $y0 = 20;
            $yG = $champ($L, $y0, 92, 'Date de paiement :', $dateAffichee . ($tr['heure_transaction'] ? ' à ' . substr($tr['heure_transaction'], 0, 5) : ''));
            if (!empty($tr['nom_prenom_contact'])) $yG = $champ($L, $yG, 92, $libTiers . ' :', $tr['nom_prenom_contact']);
            $yG = $champ($L, $yG, 92, 'Montant du règlement :', $fmtCfa($tr['montant_transaction']));

            $xD = $L + 100; $wD = $W - 100;
            $yD = $y0;
            if (!empty($tr['mode_reglement']))      $yD = $champ($xD, $yD, $wD, 'Méthode de paiement :', $tr['mode_reglement']);
            if (!empty($tr['reference_reglement'])) $yD = $champ($xD, $yD, $wD, 'Référence :', $tr['reference_reglement']);
            if ($depenseInfo) {
                $yD = $champ($xD, $yD, $wD, 'Catégorie :', $depenseInfo['categorie'] ?? '-');
            } elseif ($memo !== '') {
                $yD = $champ($xD, $yD, $wD, 'Mémo :', $memo);
            }
            $y = max($yG, $yD) + 2;

            // Bandeau de statut
            $pdf->SetXY($L, $y);
            $pdf->SetFillColor($couleurStatut[0], $couleurStatut[1], $couleurStatut[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell($W, 10, $statut, 0, 1, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $y = $pdf->GetY() + 3;

            // Phrase d'état
            $pdf->SetXY($L, $y);
            $pdf->SetFont('Arial', '', 8.5);
            $pdf->MultiCell($W, 4.5, $phraseStatut, 0, 'L');
            $y = $pdf->GetY() + 2;

            if ($depenseInfo) {
                // Motif de la dépense
                $pdf->SetXY($L, $y);
                $pdf->SetFont('Arial', '', 9.5);
                $pdf->MultiCell($W, 6, 'Motif : ' . $depenseInfo['motif'], 1, 'L');
                $y = $pdf->GetY() + 4;
            } elseif (!empty($lignesRecu)) {
                // Tableau des factures
                $cols = [29, 43, 28, 28, 28, 28];
                $heads = ['DATE DE FACTURE', 'NUMÉRO DE FACTURE', 'MONTANT INITIAL', 'MONTANT DÛ', 'MONTANT PAYÉ', 'SOLDE FACTURE'];
                $pdf->SetFillColor(232, 232, 232);
                $pdf->SetFont('Arial', 'B', 7.5);
                $pdf->SetXY($L, $y);
                foreach ($heads as $i => $h) {
                    $pdf->Cell($cols[$i], 8, $h, 1, $i === 5 ? 1 : 0, $i < 2 ? 'L' : 'R', $i === 5);
                }
                foreach ($lignesRecu as $lr) {
                    $pdf->SetX($L);
                    $pdf->SetFont('Arial', '', 8);
                    $pdf->Cell($cols[0], 7, $lr['date'], 1, 0, 'L');
                    $pdf->Cell($cols[1], 7, $lr['numero'], 1, 0, 'L');
                    $pdf->Cell($cols[2], 7, $fmtCfa($lr['initial']), 1, 0, 'R');
                    $pdf->Cell($cols[3], 7, $fmtCfa($lr['du']), 1, 0, 'R');
                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->Cell($cols[4], 7, $fmtCfa($lr['paye']), 1, 0, 'R');
                    $pdf->Cell($cols[5], 7, $fmtCfa($lr['solde']), 1, 1, 'R', true);
                }
                $y = $pdf->GetY() + 4;
            }

            // Bloc des totaux (à droite)
            if ($depenseInfo) {
                $totaux = [['MONTANT DÉPENSÉ', $fmtCfa($tr['montant_transaction'])]];
            } elseif (!empty($lignesRecu)) {
                $totaux = [
                    ["MONTANT VERSÉ AUJOURD'HUI", $fmtCfa($totalPaye)],
                    [count($lignesRecu) > 1 ? 'SOLDE DE CES FACTURES APRÈS CE PAIEMENT' : 'SOLDE DE CETTE FACTURE APRÈS CE PAIEMENT', $fmtCfa($totalReste)],
                ];
            } else {
                $totaux = [['MONTANT ENREGISTRÉ', $fmtCfa($tr['montant_transaction'])]];
            }
            if ($soldeLabel !== null) $totaux[] = [$soldeLabel, $soldeValeur];
            $xT = $R - 122;
            $pdf->SetFillColor(232, 232, 232);
            $pdf->SetXY($xT, $y);
            foreach ($totaux as $t) {
                $pdf->SetX($xT);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->Cell(80, 7, $t[0], 1, 0, 'L');
                $pdf->Cell(42, 7, $t[1], 1, 1, 'R', true);
            }
            $y = $pdf->GetY() + 4;

            // Explication simple
            if (!$depenseInfo) {
                $pdf->SetXY($L + 2, $y + 2);
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->Cell($W - 4, 5, 'Explication simple du règlement', 0, 1, 'L');
                $pdf->SetFont('Arial', '', 8);
                $lignesExpl = [];
                if (!empty($lignesRecu)) {
                    $lignesExpl[] = ($totalReste <= 0.01)
                        ? 'Après ce règlement, ' . (count($lignesRecu) > 1 ? 'ces factures sont entièrement soldées.' : 'cette facture est entièrement soldée.')
                        : 'Après ce règlement, il reste ' . $fmtCfa($totalReste) . ' à payer sur ' . (count($lignesRecu) > 1 ? 'ces factures.' : 'cette facture.');
                }
                if ($soldePhrase) $lignesExpl[] = $soldePhrase;
                if (empty($lignesExpl)) $lignesExpl[] = $phraseStatut;
                foreach ($lignesExpl as $le) {
                    $pdf->SetX($L + 2);
                    $pdf->MultiCell($W - 4, 4.5, $le, 0, 'L');
                }
                $yFinBox = $pdf->GetY() + 2;
                $pdf->Rect($L, $y, $W, $yFinBox - $y);
                $y = $yFinBox + 3;
            }

            // Pied
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->SetTextColor(120, 120, 120);
            $pdf->SetXY($L, $y);
            $pdf->Cell($W, 4, 'Enregistré par ' . ($tr['utilisateur_nom'] ?? '-') . ($tr['nom_caisse'] ? ' - Caisse : ' . $tr['nom_caisse'] : ''), 0, 1, 'L');
            if ($nomBoutique) {
                $pdf->SetX($L);
                $pdf->Cell($W, 4, $nomBoutique . ($adresseBoutique ? ' - ' . $adresseBoutique : '') . ($telBoutique ? ' - Tel: ' . $telBoutique : ''), 0, 1, 'L');
            }
            return $pdf->GetY();
        };

        // Passe 1 : mesure de la hauteur — Passe 2 : page à la hauteur exacte
        $pdfMesure = new PdfRecu('P', 'mm', [200, 2000]);
        $pdfMesure->AddPage();
        $yFin = $dessiner($pdfMesure);

        // FPDF normalise les formats personnalisés (petit côté = largeur en portrait) :
        // on choisit l'orientation pour que la largeur reste toujours 200 mm.
        $hPage = max(90, ceil($yFin + 8));
        $pdf = new PdfRecu($hPage <= 200 ? 'L' : 'P', 'mm', [200, $hPage]);
        $pdf->AddPage();
        $dessiner($pdf);
        if ($logoTmpPath && is_file($logoTmpPath)) unlink($logoTmpPath);

        $modePdf = (isset($_POST['mode']) && $_POST['mode'] === 'D') ? 'D' : 'I';
        $nomFichier = preg_replace('/[^A-Za-z0-9_-]+/', '-', $tr['numero_transaction']);
        $pdf->Output($modePdf, 'Recu_' . $nomFichier . '.pdf');
        exit;
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
    $titreDoc = 'TICKET DE VENTE';

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
    }

    $pdf->Ln(10);
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(190, 4, 'Enregistré par ' . ($tr['utilisateur_nom'] ?? '—') . ($tr['nom_caisse'] ? ' — Caisse : ' . $tr['nom_caisse'] : ''), 0, 1, 'L');
    if ($nomBoutique) {
        $pdf->Cell(190, 4, $nomBoutique . ($adresseBoutique ? ' — ' . $adresseBoutique : '') . ($telBoutique ? ' — Tel: ' . $telBoutique : ''), 0, 1, 'L');
    }

    $modePdf = (isset($_POST['mode']) && $_POST['mode'] === 'D') ? 'D' : 'I';
    $pdf->Output($modePdf, 'Ticket_' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $tr['numero_transaction']) . '.pdf');
    exit;
}