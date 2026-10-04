<?php
// rapport_financier.php
// Fusion de : compte_tresorerie.php, situation_clients.php, facture_clients.php,
// facture_fournisseur.php, resume_achats.php + onglet "Soldes"
require 'databases/database.php';
require 'fonctions_rapport.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}
$stmtU = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmtU->execute([$_SESSION['user_id']]);
$user = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Rôles autorisés à corriger manuellement un solde client/fournisseur.
$roleNormalise = strtolower(strtr(trim((string)$user['role']), ['é' => 'e', 'É' => 'e', 'è' => 'e', 'È' => 'e']));
$peutAjusterSolde = in_array($roleNormalise, ['administrateur', 'superviseur', 'proprietaire'], true);
// if (!in_array($user['role'], ['Administrateur', 'Superviseur', 'Proprietaire'], true)) {
//     http_response_code(403);
//     die("Accès non autorisé à ce rapport.");
// }
// Boutiques que cet utilisateur a le droit de consulter (toutes pour
// Administrateur/Superviseur ; sa boutique + exceptions pour les autres).
// NB : l'onglet "Soldes clients/fournisseurs" reste volontairement global —
// le solde d'un contact (contact.solde_contact) est une donnée globale au
// contact, pas rattachée à une boutique, et le scoper donnerait une image
// fausse de sa dette/avance réelle.
$boutiquesAutorisees = getBoutiquesAutorisees($pdo, $user['role'], $user['boutique_id'] ?? null);

if (!function_exists('e')) {
    function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt')) {
    function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }
}

// ==========================================================
// IMPRESSION PDF (mêmes filtres que l'onglet actif à l'écran)
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] === 'pdf') {
    while (ob_get_level() > 0) { ob_end_clean(); }

    $section = $_POST['section'] ?? '';
    $etatFiltre = trim($_POST['etat_filtre'] ?? '');
    $contactFiltre = trim($_POST['contact_filtre'] ?? '');
    $tri = (trim($_POST['tri'] ?? '') === 'ancien') ? 'ancien' : 'recent';
    // 'I' = affichage direct dans l'onglet, 'D' = téléchargement forcé (PDF uniquement).
    $modePdf = (isset($_POST['mode']) && $_POST['mode'] === 'D') ? 'D' : 'I';
    // 'pdf' (défaut) ou 'excel'.
    $formatExport = (isset($_POST['format']) && $_POST['format'] === 'excel') ? 'excel' : 'pdf';

    $stmtB = $pdo->prepare("SELECT * FROM boutique WHERE code_boutique = ?");
    $stmtB->execute([$user['boutique_id'] ?? '']);
    $boutique = $stmtB->fetch(PDO::FETCH_ASSOC) ?: null;

    $libelleEtat = ['Payee' => 'Payées', 'Partielle' => 'Partielles', 'Impayee' => 'Impayées'][$etatFiltre] ?? 'Tous états';

    switch ($section) {
        case 'transactions_clients': {
            $whereB = empty($boutiquesAutorisees) ? " AND 1=0" : " AND ca.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";
            $sql = "SELECT t.numero_transaction, t.date_transaction, t.heure_transaction, t.montant_transaction, t.type_transaction, t.mode_reglement, f.numero_facture, ct.nom_prenom_contact AS client
                    FROM transaction t
                    LEFT JOIN facture f ON t.facture_id = f.numero_facture
                    LEFT JOIN contact ct ON f.contact_id = ct.code_contact
                    LEFT JOIN caisse ca ON t.caisse_id = ca.caisse_id
                    WHERE (ct.type_contact = 'CLIENT' OR t.facture_id IS NULL)$whereB
                    ORDER BY t.date_transaction DESC, t.heure_transaction DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($boutiquesAutorisees);
            $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $colonnes = [
                ['titre' => 'N° Transaction', 'largeur' => 30, 'align' => 'L'],
                ['titre' => 'Date', 'largeur' => 30, 'align' => 'C'],
                ['titre' => 'Client', 'largeur' => 45, 'align' => 'L'],
                ['titre' => 'Montant', 'largeur' => 25, 'align' => 'R'],
                ['titre' => 'Type', 'largeur' => 20, 'align' => 'C'],
                ['titre' => 'Mode', 'largeur' => 20, 'align' => 'C'],
                ['titre' => 'N° Facture', 'largeur' => 20, 'align' => 'L'],
            ];
            $total = 0;
            foreach ($lignes as $row) {
                $total += (float)$row['montant_transaction'];
            }

            if ($formatExport === 'excel') {
                $xlsx = creerExcelRapport('RAPPORT FINANCIER', 'Transactions clients', $boutique, count($colonnes));
                $xlsx->setColumnWidths([22, 18, 30, 16, 12, 14, 16]);
                $xlsx->addHeaderRow(['N° Transaction', 'Date', 'Client', 'Montant', 'Type', 'Mode', 'N° Facture']);
                foreach ($lignes as $row) {
                    $xlsx->addRow([
                        $row['numero_transaction'],
                        date('d/m/Y H:i', strtotime($row['date_transaction'] . ' ' . $row['heure_transaction'])),
                        $row['client'] ?? '—',
                        (float)$row['montant_transaction'],
                        $row['type_transaction'],
                        $row['mode_reglement'] ?? '—',
                        $row['numero_facture'] ?? '—',
                    ], ['L', 'C', 'L', 'R', 'C', 'C', 'L']);
                }
                $xlsx->addBlankRow();
                $xlsx->addTotalRow(['TOTAL (' . count($lignes) . ' transaction(s))', '', '', $total, '', '', ''], ['L', 'C', 'L', 'R', 'C', 'C', 'L']);
                $xlsx->output('Transactions_clients_' . date('Ymd_His') . '.xlsx', $modePdf);
            }

            $pdf = creerPdfRapport('RAPPORT FINANCIER', 'Transactions clients', $boutique);
            dessinerEnteteTableauRapport($pdf, $colonnes);
            foreach ($lignes as $i => $row) {
                dessinerLigneTableauRapport($pdf, $colonnes, [
                    $row['numero_transaction'],
                    date('d/m/Y H:i', strtotime($row['date_transaction'] . ' ' . $row['heure_transaction'])),
                    $row['client'] ?? '—',
                    fmt((float)$row['montant_transaction']) . ' F',
                    $row['type_transaction'],
                    $row['mode_reglement'] ?? '—',
                    $row['numero_facture'] ?? '—',
                ], $i);
            }
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetFillColor(242, 242, 242);
            $pdf->Cell(105, 7, 'TOTAL (' . count($lignes) . ' transaction(s))', 0, 0, 'L', true);
            $pdf->Cell(25, 7, fmt($total) . ' F', 0, 0, 'R', true);
            $pdf->Cell(60, 7, '', 0, 1, 'L', true);
            $pdf->Output($modePdf, 'Transactions_clients_' . date('Ymd_His') . '.pdf');
            exit;
        }

        case 'factures_clients':
        case 'factures_fournisseurs': {
            $typeContact = ($section === 'factures_clients') ? 'Client' : 'Fournisseur';
            $whereB = empty($boutiquesAutorisees) ? " AND 1=0" : " AND EXISTS (SELECT 1 FROM commande cm WHERE cm.facture_id = f.numero_facture AND cm.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . "))";
            $params = $boutiquesAutorisees;
            $whereExtra = '';
            if ($etatFiltre === 'Payee') {
                $whereExtra .= " AND f.etat_facture IN ('Payee','Payee cash')";
            } elseif (in_array($etatFiltre, ['Partielle', 'Impayee'], true)) {
                $whereExtra .= " AND f.etat_facture = ?";
                $params[] = $etatFiltre;
            }
            if ($contactFiltre !== '') {
                $whereExtra .= " AND f.contact_id = ?";
                $params[] = $contactFiltre;
            }
            $orderBy = ($tri === 'ancien') ? "ORDER BY f.date_facture ASC" : "ORDER BY f.date_facture DESC";
            $sql = "SELECT f.numero_facture, f.date_facture, f.montant_ttc, f.avance, f.reste, f.etat_facture, ct.nom_prenom_contact AS contact
                    FROM facture f JOIN contact ct ON f.contact_id = ct.code_contact
                    WHERE ct.type_contact = '$typeContact'$whereB$whereExtra $orderBy";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $libelleColonneContact = ($typeContact === 'Client') ? 'Client' : 'Fournisseur';
            $colonnes = [
                ['titre' => 'N° Facture', 'largeur' => 28, 'align' => 'L'],
                ['titre' => $libelleColonneContact, 'largeur' => 42, 'align' => 'L'],
                ['titre' => 'Date', 'largeur' => 22, 'align' => 'C'],
                ['titre' => 'Montant TTC', 'largeur' => 27, 'align' => 'R'],
                ['titre' => 'Avance', 'largeur' => 25, 'align' => 'R'],
                ['titre' => 'Reste', 'largeur' => 25, 'align' => 'R'],
                ['titre' => 'État', 'largeur' => 21, 'align' => 'C'],
            ];
            $libellesEtat = ['Payee' => 'Payée', 'Payee cash' => 'Payée cash', 'Partielle' => 'Partielle', 'Impayee' => 'Impayée'];
            $totalTtc = 0; $totalAvance = 0; $totalReste = 0;
            foreach ($lignes as $row) {
                $totalTtc += (float)$row['montant_ttc'];
                $totalAvance += (float)$row['avance'];
                $totalReste += (float)$row['reste'];
            }
            $nomFichier = ($typeContact === 'Client') ? 'Factures_clients_' : 'Factures_fournisseurs_';

            if ($formatExport === 'excel') {
                $xlsx = creerExcelRapport('RAPPORT FINANCIER', 'Factures ' . strtolower($typeContact) . 's — ' . $libelleEtat, $boutique, count($colonnes));
                $xlsx->setColumnWidths([16, 26, 12, 16, 14, 14, 12]);
                $xlsx->addHeaderRow(['N° Facture', $libelleColonneContact, 'Date', 'Montant TTC', 'Avance', 'Reste', 'État']);
                foreach ($lignes as $row) {
                    $xlsx->addRow([
                        $row['numero_facture'],
                        $row['contact'],
                        date('d/m/Y', strtotime($row['date_facture'])),
                        (float)$row['montant_ttc'],
                        (float)$row['avance'],
                        (float)$row['reste'],
                        $libellesEtat[$row['etat_facture']] ?? $row['etat_facture'],
                    ], ['L', 'L', 'C', 'R', 'R', 'R', 'C']);
                }
                $xlsx->addBlankRow();
                $xlsx->addTotalRow(['TOTAL (' . count($lignes) . ' facture(s))', '', '', $totalTtc, $totalAvance, $totalReste, ''], ['L', 'L', 'C', 'R', 'R', 'R', 'C']);
                $xlsx->output($nomFichier . date('Ymd_His') . '.xlsx', $modePdf);
            }

            $pdf = creerPdfRapport('RAPPORT FINANCIER', 'Factures ' . strtolower($typeContact) . 's — ' . $libelleEtat, $boutique);
            dessinerEnteteTableauRapport($pdf, $colonnes);
            foreach ($lignes as $i => $row) {
                dessinerLigneTableauRapport($pdf, $colonnes, [
                    $row['numero_facture'],
                    $row['contact'],
                    date('d/m/Y', strtotime($row['date_facture'])),
                    fmt((float)$row['montant_ttc']) . ' F',
                    fmt((float)$row['avance']) . ' F',
                    fmt((float)$row['reste']) . ' F',
                    $libellesEtat[$row['etat_facture']] ?? $row['etat_facture'],
                ], $i);
            }
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetFillColor(242, 242, 242);
            $pdf->Cell(70, 7, 'TOTAL (' . count($lignes) . ' facture(s))', 0, 0, 'L', true);
            $pdf->Cell(27, 7, fmt($totalTtc) . ' F', 0, 0, 'R', true);
            $pdf->Cell(25, 7, fmt($totalAvance) . ' F', 0, 0, 'R', true);
            $pdf->Cell(25, 7, fmt($totalReste) . ' F', 0, 0, 'R', true);
            $pdf->Cell(21, 7, '', 0, 1, 'L', true);
            $pdf->Output($modePdf, $nomFichier . date('Ymd_His') . '.pdf');
            exit;
        }

        case 'soldes_clients':
        case 'soldes_fournisseurs': {
            $type = ($section === 'soldes_clients') ? 'CLIENT' : 'FOURNISSEUR';
            $whereExtra = '';
            $params = [':type' => $type];
            if ($contactFiltre !== '') {
                $whereExtra = " AND ct.code_contact = :contact";
                $params[':contact'] = $contactFiltre;
            }
            $sql = "SELECT ct.nom_prenom_contact, ct.telephone_contact, ct.solde_contact,
                    (SELECT COUNT(*) FROM facture f2 WHERE f2.contact_id = ct.code_contact AND f2.reste > 0) AS nb_factures,
                    (SELECT MAX(f3.date_facture) FROM facture f3 WHERE f3.contact_id = ct.code_contact) AS derniere_facture
                    FROM contact ct
                    WHERE ct.type_contact = :type AND ct.solde_contact <> 0$whereExtra
                    ORDER BY ct.solde_contact DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $libelleType = ($type === 'CLIENT') ? 'Clients' : 'Fournisseurs';
            $colonnes = [
                ['titre' => strtoupper($libelleType === 'Clients' ? 'Client' : 'Fournisseur'), 'largeur' => 55, 'align' => 'L'],
                ['titre' => 'Téléphone', 'largeur' => 32, 'align' => 'L'],
                ['titre' => 'Factures dues', 'largeur' => 25, 'align' => 'C'],
                ['titre' => 'Solde', 'largeur' => 35, 'align' => 'R'],
                ['titre' => 'Dernière facture', 'largeur' => 33, 'align' => 'C'],
            ];
            $totalDoit = 0; $totalAvance = 0;
            $soldesTxt = [];
            foreach ($lignes as $idx => $row) {
                $solde = (float)$row['solde_contact'];
                if ($solde > 0) { $totalDoit += $solde; $soldesTxt[$idx] = 'Doit ' . fmt($solde) . ' F'; }
                else { $totalAvance += -$solde; $soldesTxt[$idx] = 'Avance ' . fmt(-$solde) . ' F'; }
            }
            $nomFichier = ($type === 'CLIENT') ? 'Soldes_clients_' : 'Soldes_fournisseurs_';

            if ($formatExport === 'excel') {
                $xlsx = creerExcelRapport('RAPPORT FINANCIER', 'Soldes — ' . $libelleType, $boutique, count($colonnes));
                $xlsx->setColumnWidths([28, 18, 14, 20, 16]);
                $xlsx->addHeaderRow([strtoupper($libelleType === 'Clients' ? 'Client' : 'Fournisseur'), 'Téléphone', 'Factures dues', 'Solde', 'Dernière facture']);
                foreach ($lignes as $idx => $row) {
                    $xlsx->addRow([
                        $row['nom_prenom_contact'],
                        $row['telephone_contact'] ?? '—',
                        (int)$row['nb_factures'],
                        $soldesTxt[$idx],
                        $row['derniere_facture'] ? date('d/m/Y', strtotime($row['derniere_facture'])) : '—',
                    ], ['L', 'L', 'C', 'R', 'C']);
                }
                $xlsx->addBlankRow();
                $xlsx->addTotalRow(['TOTAL (' . count($lignes) . ' ' . strtolower($libelleType) . ')', '', '', 'Doit ' . fmt($totalDoit) . ' F / Avance ' . fmt($totalAvance) . ' F', ''], ['L', 'L', 'C', 'R', 'C']);
                $xlsx->output($nomFichier . date('Ymd_His') . '.xlsx', $modePdf);
            }

            $pdf = creerPdfRapport('RAPPORT FINANCIER', 'Soldes — ' . $libelleType, $boutique);
            dessinerEnteteTableauRapport($pdf, $colonnes);
            foreach ($lignes as $i => $row) {
                dessinerLigneTableauRapport($pdf, $colonnes, [
                    $row['nom_prenom_contact'],
                    $row['telephone_contact'] ?? '—',
                    (string)(int)$row['nb_factures'],
                    $soldesTxt[$i],
                    $row['derniere_facture'] ? date('d/m/Y', strtotime($row['derniere_facture'])) : '—',
                ], $i);
            }
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetFillColor(242, 242, 242);
            $pdf->Cell(112, 7, 'TOTAL (' . count($lignes) . ' ' . strtolower($libelleType) . ')', 0, 0, 'L', true);
            $pdf->Cell(35, 7, 'Doit ' . fmt($totalDoit) . ' F / Avance ' . fmt($totalAvance) . ' F', 0, 0, 'R', true);
            $pdf->Cell(33, 7, '', 0, 1, 'L', true);
            $pdf->Output($modePdf, $nomFichier . date('Ymd_His') . '.pdf');
            exit;
        }

        default:
            die("Section de rapport inconnue.");
    }
}

// ==========================================================
// PAGINATION
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
        echo '<tr><td colspan="' . $colspan . '" class="empty-cell"><i class="bi bi-inbox d-block mb-2" style="font-size:3rem;opacity:.2;"></i><div class="text-muted small">Aucune donnée disponible</div></td></tr>';
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

function badgeEtatTransaction($type) {
    if ($type === 'Entree') return ['bg-success-subtle text-success', 'Entrée'];
    if ($type === 'Sortie') return ['bg-danger-subtle text-danger', 'Sortie'];
    return ['bg-primary-subtle text-primary', $type];
}

// ==========================================================
// ONGLET 1 : TRÉSORERIE
// ==========================================================
function chargerTresorerie($pdo, $page, $boutiquesAutorisees) {
    $whereB = empty($boutiquesAutorisees) ? " AND 1=0" : " AND ca.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";
    $sql = "SELECT t.date_transaction, t.heure_transaction, t.montant_transaction, t.montant_total, t.type_transaction, t.objet_transaction, t.mode_reglement, t.etat_transaction
            FROM transaction t
            LEFT JOIN caisse ca ON t.caisse_id = ca.caisse_id
            WHERE t.type_transaction IN ('Entree','Sortie') AND t.etat_transaction IN ('Succes','Valide')$whereB
            ORDER BY t.date_transaction DESC, t.heure_transaction DESC";
    $countSql = "SELECT COUNT(*) FROM transaction t LEFT JOIN caisse ca ON t.caisse_id = ca.caisse_id WHERE t.type_transaction IN ('Entree','Sortie') AND t.etat_transaction IN ('Succes','Valide')$whereB";
    $renderer = function ($row) {
        [$cls, $label] = badgeEtatTransaction($row['type_transaction']);
        $montant = $row['type_transaction'] === 'Sortie' ? (float)$row['montant_total'] : (float)$row['montant_transaction'];
        return '<tr>'
            . '<td class="fw-semibold">' . e($row['date_transaction']) . ' ' . e($row['heure_transaction']) . '</td>'
            . '<td><span class="badge-chic ' . $cls . '"><span class="dot"></span> ' . e($label) . '</span></td>'
            . '<td class="fw-bold text-primary">' . fmt($montant) . ' F</td>'
            . '<td>' . e($row['objet_transaction'] ?? '—') . '</td>'
            . '<td>' . e($row['mode_reglement'] ?? '—') . '</td>'
            . '<td><span class="badge-chic bg-success-subtle text-success"><span class="dot"></span> ' . e($row['etat_transaction']) . '</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, $boutiquesAutorisees, $page, 20, $renderer, 6);
}

// ==========================================================
// ONGLET 2 : TRANSACTIONS CLIENTS
// ==========================================================
function chargerTransactionsClients($pdo, $page, $boutiquesAutorisees) {
    $whereB = empty($boutiquesAutorisees) ? " AND 1=0" : " AND ca.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";
    $sql = "SELECT t.numero_transaction, t.date_transaction, t.heure_transaction, t.montant_transaction, t.type_transaction, t.mode_reglement, t.etat_transaction, ct.nom_prenom_contact AS client, f.numero_facture
            FROM transaction t
            LEFT JOIN facture f ON t.facture_id = f.numero_facture
            LEFT JOIN contact ct ON f.contact_id = ct.code_contact
            LEFT JOIN caisse ca ON t.caisse_id = ca.caisse_id
            WHERE (ct.type_contact = 'CLIENT' OR t.facture_id IS NULL)$whereB
            ORDER BY t.date_transaction DESC, t.heure_transaction DESC";
    $countSql = "SELECT COUNT(*) FROM transaction t LEFT JOIN facture f ON t.facture_id=f.numero_facture LEFT JOIN contact ct ON f.contact_id=ct.code_contact LEFT JOIN caisse ca ON t.caisse_id = ca.caisse_id WHERE (ct.type_contact='CLIENT' OR t.facture_id IS NULL)$whereB";
    $renderer = function ($row) {
        [$cls, $label] = badgeEtatTransaction($row['type_transaction']);
        return '<tr>'
            . '<td class="fw-bold">' . e($row['numero_transaction']) . '</td>'
            . '<td>' . date('d/m/Y H:i', strtotime($row['date_transaction'] . ' ' . $row['heure_transaction'])) . '</td>'
            . '<td class="fw-semibold">' . e($row['client'] ?? '—') . '</td>'
            . '<td class="fw-bold text-primary">' . fmt((float)$row['montant_transaction']) . ' F</td>'
            . '<td><span class="badge-chic ' . $cls . '"><span class="dot"></span> ' . e($label) . '</span></td>'
            . '<td>' . e($row['mode_reglement'] ?? '—') . '</td>'
            . '<td>' . e($row['numero_facture'] ?? '—') . '</td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, $boutiquesAutorisees, $page, 20, $renderer, 7);
}

// ==========================================================
// ONGLET 3 : FACTURES CLIENTS
// ==========================================================
function chargerFacturesClients($pdo, $page, $boutiquesAutorisees, $etatFiltre = '', $clientFiltre = '', $tri = 'recent') {
    $whereB = empty($boutiquesAutorisees) ? " AND 1=0" : " AND EXISTS (SELECT 1 FROM commande cm WHERE cm.facture_id = f.numero_facture AND cm.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . "))";
    $paramsExtra = $boutiquesAutorisees;
    $whereExtra = '';
    if ($etatFiltre === 'Payee') {
        $whereExtra .= " AND f.etat_facture IN ('Payee','Payee cash')";
    } elseif (in_array($etatFiltre, ['Partielle', 'Impayee'], true)) {
        $whereExtra .= " AND f.etat_facture = ?";
        $paramsExtra[] = $etatFiltre;
    }
    if ($clientFiltre !== '') {
        $whereExtra .= " AND f.contact_id = ?";
        $paramsExtra[] = $clientFiltre;
    }
    $orderBy = ($tri === 'ancien') ? "ORDER BY f.date_facture ASC" : "ORDER BY f.date_facture DESC";
    $sql = "SELECT f.numero_facture, f.date_facture, f.montant_ttc, f.avance, f.reste, f.etat_facture, ct.nom_prenom_contact AS client
            FROM facture f JOIN contact ct ON f.contact_id = ct.code_contact
            WHERE ct.type_contact = 'Client'$whereB$whereExtra $orderBy";
    $countSql = "SELECT COUNT(*) FROM facture f JOIN contact ct ON f.contact_id=ct.code_contact WHERE ct.type_contact='Client'$whereB$whereExtra";
    $renderer = function ($row) {
        $etat = $row['etat_facture'];
        $badge = in_array($etat, ['Payee', 'Payee cash'], true) ? 'bg-success-subtle text-success' : (($etat === 'Partielle') ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger');
        $libelleEtat = ['Payee' => 'Payée', 'Payee cash' => 'Payée cash', 'Partielle' => 'Partielle', 'Impayee' => 'Impayée'][$etat] ?? $etat;
        return '<tr>'
            . '<td class="fw-bold">' . e($row['numero_facture']) . '</td>'
            . '<td class="fw-semibold">' . e($row['client']) . '</td>'
            . '<td>' . date('d/m/Y', strtotime($row['date_facture'])) . '</td>'
            . '<td class="fw-bold text-primary">' . fmt((float)$row['montant_ttc']) . ' F</td>'
            . '<td>' . fmt((float)$row['avance']) . ' F</td>'
            . '<td class="fw-semibold text-danger">' . fmt((float)$row['reste']) . ' F</td>'
            . '<td><span class="badge-chic ' . $badge . '"><span class="dot"></span> ' . e($libelleEtat) . '</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, $paramsExtra, $page, 20, $renderer, 7);
}

// ==========================================================
// ONGLET 4 : FACTURES FOURNISSEURS
// ==========================================================
function chargerFacturesFournisseurs($pdo, $page, $boutiquesAutorisees, $etatFiltre = '', $fournisseurFiltre = '', $tri = 'recent') {
    $whereB = empty($boutiquesAutorisees) ? " AND 1=0" : " AND EXISTS (SELECT 1 FROM commande cm WHERE cm.facture_id = f.numero_facture AND cm.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . "))";
    $paramsExtra = $boutiquesAutorisees;
    $whereExtra = '';
    if ($etatFiltre === 'Payee') {
        $whereExtra .= " AND f.etat_facture IN ('Payee','Payee cash')";
    } elseif (in_array($etatFiltre, ['Partielle', 'Impayee'], true)) {
        $whereExtra .= " AND f.etat_facture = ?";
        $paramsExtra[] = $etatFiltre;
    }
    if ($fournisseurFiltre !== '') {
        $whereExtra .= " AND f.contact_id = ?";
        $paramsExtra[] = $fournisseurFiltre;
    }
    $orderBy = ($tri === 'ancien') ? "ORDER BY f.date_facture ASC" : "ORDER BY f.date_facture DESC";
    $sql = "SELECT f.numero_facture, f.date_facture, f.montant_ttc, f.avance, f.reste, f.etat_facture, ct.nom_prenom_contact AS fournisseur
            FROM facture f JOIN contact ct ON f.contact_id = ct.code_contact
            WHERE ct.type_contact = 'Fournisseur'$whereB$whereExtra $orderBy";
    $countSql = "SELECT COUNT(*) FROM facture f JOIN contact ct ON f.contact_id=ct.code_contact WHERE ct.type_contact='Fournisseur'$whereB$whereExtra";
    $renderer = function ($row) {
        $etat = $row['etat_facture'];
        $badge = in_array($etat, ['Payee', 'Payee cash'], true) ? 'bg-success-subtle text-success' : (($etat === 'Partielle') ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger');
        $libelleEtat = ['Payee' => 'Payée', 'Payee cash' => 'Payée cash', 'Partielle' => 'Partielle', 'Impayee' => 'Impayée'][$etat] ?? $etat;
        return '<tr>'
            . '<td class="fw-bold">' . e($row['numero_facture']) . '</td>'
            . '<td class="fw-semibold">' . e($row['fournisseur']) . '</td>'
            . '<td>' . date('d/m/Y', strtotime($row['date_facture'])) . '</td>'
            . '<td class="fw-bold text-primary">' . fmt((float)$row['montant_ttc']) . ' F</td>'
            . '<td>' . fmt((float)$row['avance']) . ' F</td>'
            . '<td class="fw-semibold text-danger">' . fmt((float)$row['reste']) . ' F</td>'
            . '<td><span class="badge-chic ' . $badge . '"><span class="dot"></span> ' . e($libelleEtat) . '</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, $paramsExtra, $page, 20, $renderer, 7);
}

// ==========================================================
// ONGLET 5 : RÉSUMÉ DES ACHATS
// ==========================================================
function chargerAchats($pdo, $page, $boutiquesAutorisees) {
    $whereB = empty($boutiquesAutorisees) ? " AND 1=0" : " AND c.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";
    $sql = "SELECT c.numero_commande, c.date_commande, c.prix_achat, c.quantite_commande, c.montant_commande, c.etat_commande,
            p.titre_produit, ct.nom_prenom_contact AS fournisseur, b.nom_boutique, cat.titre_categorie
            FROM commande c
            LEFT JOIN produit p ON c.produit_id = p.code_produit
            LEFT JOIN contact ct ON c.contact_id = ct.code_contact
            LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
            LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
            WHERE c.statut_id='011' AND c.etat_commande NOT IN ('En attente','Annulé')$whereB
            ORDER BY c.date_commande DESC, c.heure_commande DESC";
    $countSql = "SELECT COUNT(*) FROM commande c WHERE c.statut_id='011' AND c.etat_commande NOT IN ('En attente','Annulé')$whereB";
    $renderer = function ($row) {
        $etat = $row['etat_commande'];
        $badge = ($etat === 'Reçu' || $etat === 'Validé' || $etat === 'VALIDEE') ? 'bg-success-subtle text-success' : (($etat === 'En attente') ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger');
        return '<tr>'
            . '<td class="fw-bold">' . e($row['numero_commande']) . '</td>'
            . '<td>' . date('d/m/Y', strtotime($row['date_commande'])) . '</td>'
            . '<td class="fw-semibold">' . e($row['fournisseur'] ?? '—') . '</td>'
            . '<td>' . e($row['titre_produit'] ?? '—') . '</td>'
            . '<td>' . e($row['titre_categorie'] ?? '—') . '</td>'
            . '<td class="text-center">' . (int)$row['quantite_commande'] . '</td>'
            . '<td class="text-end">' . fmt((float)$row['prix_achat']) . ' F</td>'
            . '<td class="text-end fw-bold text-primary">' . fmt((float)$row['montant_commande']) . ' F</td>'
            . '<td>' . e($row['nom_boutique'] ?? '—') . '</td>'
            . '<td><span class="badge-chic ' . $badge . '"><span class="dot"></span> ' . e($etat) . '</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, $boutiquesAutorisees, $page, 20, $renderer, 10);
}

// ==========================================================
// ONGLET 6 : SOLDES CLIENTS & FOURNISSEURS
// ==========================================================
// Basé sur contact.solde_contact (solde global du contact) :
//   solde_contact > 0 => le contact doit ce montant
//   solde_contact < 0 => le contact est en avance (avoir)
// On liste tout contact dont le solde n'est pas à zéro, dans les deux sens.
function chargerSoldes($pdo, $type, $page, $contactFiltre = '', $peutAjuster = false) {
    $whereExtra = '';
    $params = [':type' => $type];
    if ($contactFiltre !== '') {
        $whereExtra = " AND ct.code_contact = :contact";
        $params[':contact'] = $contactFiltre;
    }
    $sql = "SELECT ct.code_contact, ct.nom_prenom_contact, ct.telephone_contact, ct.solde_contact,
            (SELECT COUNT(*) FROM facture f2 WHERE f2.contact_id = ct.code_contact AND f2.reste > 0) AS nb_factures,
            (SELECT MAX(f3.date_facture) FROM facture f3 WHERE f3.contact_id = ct.code_contact) AS derniere_facture
            FROM contact ct
            WHERE ct.type_contact = :type AND ct.solde_contact <> 0$whereExtra
            ORDER BY ct.solde_contact DESC";
    $countSql = "SELECT COUNT(*) FROM contact ct WHERE ct.type_contact = :type AND ct.solde_contact <> 0$whereExtra";
    $renderer = function ($row) use ($peutAjuster) {
        $solde = (float)$row['solde_contact'];
        if ($solde > 0) {
            $soldeHtml = '<span class="text-danger fw-bold">Doit ' . fmt($solde) . ' F</span>';
        } else {
            $soldeHtml = '<span class="text-success fw-bold">En avance de ' . fmt(-$solde) . ' F</span>';
        }
        return '<tr>'
            . '<td class="fw-bold">' . e($row['nom_prenom_contact']) . '</td>'
            . '<td>' . e($row['telephone_contact'] ?? '—') . '</td>'
            . '<td class="text-center">' . (int)$row['nb_factures'] . '</td>'
            . '<td class="text-end">' . $soldeHtml . '</td>'
            . '<td>' . ($row['derniere_facture'] ? date('d/m/Y', strtotime($row['derniere_facture'])) : '—') . '</td>'
            . ($peutAjuster
                ? '<td class="text-end"><button type="button" class="btn btn-outline-primary btn-sm btn-ajuster-solde" data-code="' . e($row['code_contact']) . '" data-nom="' . e($row['nom_prenom_contact']) . '" data-solde="' . e($solde) . '"><i class="bi bi-sliders"></i> Ajuster</button></td>'
                : '')
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, $params, $page, 20, $renderer, $peutAjuster ? 6 : 5);
}

// ==========================================================
// AJUSTEMENT MANUEL D'UN SOLDE CONTACT (sans passer par la base)
// ==========================================================
// Modifie uniquement contact.solde_contact (factures, transactions et caisse
// ne sont pas touchées). Réservé aux rôles définis dans $peutAjusterSolde.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajuster_solde') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $repondre = function ($ok, $message) {
        echo json_encode(['ok' => $ok, 'message' => $message]);
        exit;
    };
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        $repondre(false, "Session expirée : rechargez la page puis réessayez.");
    }
    if (!$peutAjusterSolde) {
        http_response_code(403);
        $repondre(false, "Vous n'avez pas le droit de corriger un solde.");
    }
    $codeContact = trim($_POST['code_contact'] ?? '');
    $sens = $_POST['sens'] ?? '';
    $brut = str_replace(',', '.', str_replace([' ', "\xc2\xa0"], '', (string)($_POST['montant'] ?? '0')));
    if ($codeContact === '') $repondre(false, "Contact manquant.");
    if (!in_array($sens, ['doit', 'avance', 'solde'], true)) $repondre(false, "Choisissez le type de solde.");
    if ($sens !== 'solde' && (!is_numeric($brut) || (float)$brut <= 0)) $repondre(false, "Saisissez un montant supérieur à 0.");
    $montant = ($sens === 'solde') ? 0.0 : round((float)$brut, 2);
    if ($montant > 999999999999) $repondre(false, "Montant trop grand.");
    $nouveau = ($sens === 'doit') ? $montant : (($sens === 'avance') ? -$montant : 0.0);

    $stmtAj = $pdo->prepare("UPDATE contact SET solde_contact = ? WHERE code_contact = ? AND UPPER(type_contact) IN ('CLIENT','FOURNISSEUR')");
    $stmtAj->execute([$nouveau, $codeContact]);
    $repondre(true, "Solde corrigé.");
}

// ==========================================================
// DISPATCHER AJAX
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $tab = $_POST['tab'] ?? 'tresorerie';
    $page = (int)($_POST['page'] ?? 1);
    $etatFiltreAjax = trim($_POST['etat_filtre'] ?? '');
    $contactFiltreAjax = trim($_POST['contact_filtre'] ?? '');
    $triAjax = (trim($_POST['tri'] ?? '') === 'ancien') ? 'ancien' : 'recent';
    switch ($tab) {
        case 'transactions_clients': $res = chargerTransactionsClients($pdo, $page, $boutiquesAutorisees); break;
        case 'factures_clients':     $res = chargerFacturesClients($pdo, $page, $boutiquesAutorisees, $etatFiltreAjax, $contactFiltreAjax, $triAjax); break;
        case 'factures_fournisseurs':$res = chargerFacturesFournisseurs($pdo, $page, $boutiquesAutorisees, $etatFiltreAjax, $contactFiltreAjax, $triAjax); break;
        case 'achats':                $res = chargerAchats($pdo, $page, $boutiquesAutorisees); break;
        case 'soldes_clients':        $res = chargerSoldes($pdo, 'CLIENT', $page, $contactFiltreAjax, $peutAjusterSolde); break;
        case 'soldes_fournisseurs':   $res = chargerSoldes($pdo, 'FOURNISSEUR', $page, $contactFiltreAjax, $peutAjusterSolde); break;
        default:                      $res = chargerTresorerie($pdo, $page, $boutiquesAutorisees); break;
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'table' => $res['tableHtml'],
        'pagination' => $res['paginationHtml'],
        'total' => $res['total']
    ]);
    exit;
}

// ==========================================================
// CHARGEMENT INITIAL
// ==========================================================
// Listes pour les filtres "Client" / "Fournisseur" des onglets factures
$clientsListeRapport = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Client' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$fournisseursListeRapport = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Fournisseur' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);

$resTresorerie      = chargerTresorerie($pdo, 1, $boutiquesAutorisees);
$resTransClients    = chargerTransactionsClients($pdo, 1, $boutiquesAutorisees);
$resFactClients     = chargerFacturesClients($pdo, 1, $boutiquesAutorisees);
$resFactFourn       = chargerFacturesFournisseurs($pdo, 1, $boutiquesAutorisees);
$resAchats          = chargerAchats($pdo, 1, $boutiquesAutorisees);
$resSoldesClients   = chargerSoldes($pdo, 'CLIENT', 1, '', $peutAjusterSolde);
$resSoldesFourn     = chargerSoldes($pdo, 'FOURNISSEUR', 1, '', $peutAjusterSolde);

// Stats globales (restreintes aux boutiques autorisées, sauf créances/dettes
// qui restent globales au contact — voir commentaire plus haut)
$whereBK = empty($boutiquesAutorisees) ? " AND 1=0" : " AND ca.boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";
$whereBC = empty($boutiquesAutorisees) ? " AND 1=0" : " AND boutique_id IN (" . implode(',', array_fill(0, count($boutiquesAutorisees), '?')) . ")";

$stmtEnc = $pdo->prepare("SELECT COALESCE(SUM(CAST(t.montant_transaction AS DECIMAL(12,2))),0) FROM transaction t LEFT JOIN caisse ca ON t.caisse_id = ca.caisse_id WHERE t.type_transaction = 'Entree' AND t.etat_transaction IN ('Succes','Valide')$whereBK");
$stmtEnc->execute($boutiquesAutorisees);
$encaissements = (float)$stmtEnc->fetchColumn();

$stmtDec = $pdo->prepare("SELECT COALESCE(SUM(CAST(t.montant_total AS DECIMAL(12,2))),0) FROM transaction t LEFT JOIN caisse ca ON t.caisse_id = ca.caisse_id WHERE t.type_transaction='Sortie' AND t.etat_transaction IN ('Succes','Valide')$whereBK");
$stmtDec->execute($boutiquesAutorisees);
$decais = (float)$stmtDec->fetchColumn();

$stmtCaisse = $pdo->prepare("SELECT COALESCE(SUM(solde),0) FROM caisse WHERE statut='Actif'$whereBC");
$stmtCaisse->execute($boutiquesAutorisees);
$solde_caisse = (float)$stmtCaisse->fetchColumn();

$stmtTA = $pdo->prepare("SELECT COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0) FROM commande WHERE statut_id='011' AND etat_commande NOT IN ('En attente','Annulé')$whereBC");
$stmtTA->execute($boutiquesAutorisees);
$total_achats = (float)$stmtTA->fetchColumn();
// Créances / dettes / avances : basées sur le solde global du contact
// (contact.solde_contact), qui reflète tout crédit accordé ET tout trop-perçu,
// pas seulement le reste des factures individuellement impayées.
$totalCreances       = (float)$pdo->query("SELECT COALESCE(SUM(solde_contact),0) FROM contact WHERE type_contact='CLIENT' AND solde_contact > 0")->fetchColumn();
$totalAvancesClients = (float)$pdo->query("SELECT COALESCE(SUM(-solde_contact),0) FROM contact WHERE type_contact='CLIENT' AND solde_contact < 0")->fetchColumn();
$totalDettes             = (float)$pdo->query("SELECT COALESCE(SUM(solde_contact),0) FROM contact WHERE type_contact='FOURNISSEUR' AND solde_contact > 0")->fetchColumn();
$totalAvancesFournisseurs = (float)$pdo->query("SELECT COALESCE(SUM(-solde_contact),0) FROM contact WHERE type_contact='FOURNISSEUR' AND solde_contact < 0")->fetchColumn();
$solde_net     = $encaissements - $decais;

// Graphique : évolution achats (12 derniers mois)
$stmtEvol = $pdo->prepare("SELECT DATE_FORMAT(date_commande,'%Y-%m') AS mois, COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0) AS total FROM commande WHERE statut_id='011' AND etat_commande NOT IN ('En attente','Annulé') AND date_commande >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)$whereBC GROUP BY mois ORDER BY mois ASC");
$stmtEvol->execute($boutiquesAutorisees);
$evolAchats = $stmtEvol->fetchAll(PDO::FETCH_ASSOC);

// Graphique : répartition trésorerie
// Répartition trésorerie : les types réellement enregistrés par l'application
// sont uniquement 'Entree' (vente comptoir, règlement client) et 'Sortie'
// (règlement/décaissement fournisseur) — 'Encaissement'/'Paiement' n'ont jamais
// existé en base, ce qui faisait que le graphique ne montrait quasiment rien.
$stmtRepart = $pdo->prepare("SELECT t.type_transaction, COALESCE(SUM(CAST(t.montant_transaction AS DECIMAL(12,2))),0) AS total FROM transaction t LEFT JOIN caisse ca ON t.caisse_id = ca.caisse_id WHERE t.type_transaction IN ('Entree','Sortie') AND t.etat_transaction IN ('Succes','Valide')$whereBK GROUP BY t.type_transaction");
$stmtRepart->execute($boutiquesAutorisees);
$repartitionTreso = $stmtRepart->fetchAll(PDO::FETCH_ASSOC);

$onglet = $_GET['onglet'] ?? 'tresorerie';
$ongletsValides = ['tresorerie', 'transactions_clients', 'factures_clients', 'factures_fournisseurs', 'achats', 'soldes'];
if (!in_array($onglet, $ongletsValides, true)) $onglet = 'tresorerie';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapport Financier</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
.chart-card .chart-wrap {
    position: relative;
    height: 190px;
}

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

/* ===== SUB-NAV (Soldes) ===== */
.sub-nav { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.sub-nav button {
    background: var(--color-gray-100);
    border: 1.5px solid var(--border-color);
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.sub-nav button:hover {
    background: var(--color-primary-soft);
    border-color: var(--color-primary);
    color: var(--color-primary);
}
.sub-nav button.active {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
    color: white;
    border-color: var(--color-primary);
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
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
    .sub-nav { flex-direction: column; }
}

/* ===== COMBOBOX DE RECHERCHE CLIENT/FOURNISSEUR (remplace bootstrap-select
   pour ces filtres, dont la synchro de valeur n'était pas fiable) ===== */
.contact-combo { position: relative; }
.contact-combo-input {
    width: 100%;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%236b7280'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    background-size: 13px;
    padding-right: 28px;
}
.contact-combo-menu {
    display: none;
    position: absolute;
    top: calc(100% + 2px);
    left: 0;
    right: 0;
    z-index: 1000;
    max-height: 260px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #dde1e8;
    border-radius: 8px;
    box-shadow: 0 8px 20px rgba(0,0,0,.10);
    padding: 4px;
}
.contact-combo-item {
    padding: 7px 10px;
    border-radius: 6px;
    font-size: .875rem;
    cursor: pointer;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.contact-combo-item:hover, .contact-combo-item.active {
    background: #eef2ff;
    color: #4338ca;
}
.contact-combo-empty {
    padding: 8px 10px;
    font-size: .8rem;
    color: #9ca3af;
}
</style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-bank text-primary me-2"></i>Rapport Financier</h1>
            <p class="text-muted small mb-0">Trésorerie, créances, dettes et achats</p>
        </div>
        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
            <i class="bi bi-wallet2"></i> Solde net: <?= fmt($solde_net) ?> F
        </span>
    </div>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['success', 'arrow-down-circle', 'Encaissements', fmt($encaissements) . ' F', ''],
            ['danger', 'arrow-up-circle', 'Décaissements', fmt($decais) . ' F', ''],
            ['info', 'cash-stack', 'Solde caisse', fmt($solde_caisse) . ' F', ''],
            ['warning', 'exclamation-triangle', 'Créances clients', fmt($totalCreances) . ' F', ''],
            ['purple', 'truck', 'Dettes fournisseurs', fmt($totalDettes) . ' F', ''],
            ['primary', 'bag', 'Total achats', fmt($total_achats) . ' F', ''],
            ['success', 'piggy-bank', 'Avances clients', fmt($totalAvancesClients) . ' F', ''],
            ['success', 'piggy-bank', 'Avances chez fournisseurs', fmt($totalAvancesFournisseurs) . ' F', ''],
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

    <!-- Onglets -->
    <ul class="nav nav-tabs" id="rapportTabs" role="tablist">
        <li class="nav-item"><button class="nav-link <?= $onglet=='tresorerie'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-tresorerie" type="button" data-tab="tresorerie"><i class="bi bi-wallet2"></i> Trésorerie</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='transactions_clients'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-transactions_clients" type="button" data-tab="transactions_clients"><i class="bi bi-arrow-left-right"></i> Transactions clients</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='factures_clients'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-factures_clients" type="button" data-tab="factures_clients"><i class="bi bi-receipt"></i> Factures clients</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='factures_fournisseurs'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-factures_fournisseurs" type="button" data-tab="factures_fournisseurs"><i class="bi bi-receipt-cutoff"></i> Factures fournisseurs</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='achats'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-achats" type="button" data-tab="achats"><i class="bi bi-bag"></i> Résumé des achats</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='soldes'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-soldes" type="button" data-tab="soldes"><i class="bi bi-scale"></i> Soldes</button></li>
    </ul>

    <div class="tab-content">
        <!-- ONGLET 1 : TRÉSORERIE -->
        <div class="tab-pane fade <?= $onglet=='tresorerie'?'show active':'' ?>" id="pane-tresorerie">
            <div class="chart-card">
                <h4><i class="bi bi-pie-chart"></i> Répartition trésorerie</h4>
                <div class="chart-wrap">
                    <canvas id="chartTreso"></canvas>
                </div>
            </div>
            <div class="report-card">
                <h3><i class="bi bi-clock-history"></i> Mouvements de trésorerie <span class="text-muted small ms-2"><?= $resTresorerie['total'] ?> lignes</span></h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Date & Heure</th><th>Type</th><th>Montant</th><th>Objet</th><th>Mode</th><th>État</th></tr></thead>
                        <tbody id="tbody-tresorerie"><?= $resTresorerie['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-tresorerie"><?= $resTresorerie['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 2 : TRANSACTIONS CLIENTS -->
        <div class="tab-pane fade <?= $onglet=='transactions_clients'?'show active':'' ?>" id="pane-transactions_clients">
            <div class="report-card">
                <h3 class="d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-arrow-left-right"></i> Transactions clients</span>
                    <button type="button" class="btn btn-outline-primary btn-sm btn-imprimer-rapport" data-section="transactions_clients" data-mode="I">
                        <i class="bi bi-printer"></i> Imprimer
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-imprimer-rapport" data-section="transactions_clients" data-mode="D">
                        <i class="bi bi-download"></i> Télécharger
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm btn-exporter-excel" data-section="transactions_clients">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                </h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>N° Transaction</th><th>Date</th><th>Client</th><th>Montant</th><th>Type</th><th>Mode</th><th>N° Facture</th></tr></thead>
                        <tbody id="tbody-transactions_clients"><?= $resTransClients['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-transactions_clients"><?= $resTransClients['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 3 : FACTURES CLIENTS -->
        <div class="tab-pane fade <?= $onglet=='factures_clients'?'show active':'' ?>" id="pane-factures_clients">
            <div class="report-card">
                <h3><i class="bi bi-receipt"></i> Factures clients</h3>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3 filtres-factures" data-tab="factures_clients">
                    <select class="form-select form-select-sm filtre-etat" style="width:auto;">
                        <option value="">Tous les états</option>
                        <option value="Payee">Payée</option>
                        <option value="Partielle">Partielle</option>
                        <option value="Impayee">Impayée</option>
                    </select>
                    <div class="contact-combo" style="width:220px;">
                        <select class="contact-combo-source" style="display:none;">
                            <?php foreach ($clientsListeRapport as $cl): ?>
                                <option value="<?= e($cl['code_contact']) ?>"><?= e($cl['nom_prenom_contact']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="form-control form-control-sm contact-combo-input" placeholder="Tous les clients" autocomplete="off">
                        <input type="hidden" class="filtre-contact" value="">
                        <div class="contact-combo-menu"></div>
                    </div>
                    <select class="form-select form-select-sm filtre-tri" style="width:auto;">
                        <option value="recent">Plus récentes d'abord</option>
                        <option value="ancien">Plus anciennes d'abord</option>
                    </select>
                    <button type="button" class="btn btn-primary btn-sm btn-appliquer-filtre">
                        <i class="bi bi-funnel"></i> Filtrer
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm btn-imprimer-rapport" data-section="factures_clients" data-mode="I">
                        <i class="bi bi-printer"></i> Imprimer
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-imprimer-rapport" data-section="factures_clients" data-mode="D">
                        <i class="bi bi-download"></i> Télécharger
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm btn-exporter-excel" data-section="factures_clients">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>N° Facture</th><th>Client</th><th>Date</th><th>Montant TTC</th><th>Avance</th><th>Reste</th><th>État</th></tr></thead>
                        <tbody id="tbody-factures_clients"><?= $resFactClients['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-factures_clients"><?= $resFactClients['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 4 : FACTURES FOURNISSEURS -->
        <div class="tab-pane fade <?= $onglet=='factures_fournisseurs'?'show active':'' ?>" id="pane-factures_fournisseurs">
            <div class="report-card">
                <h3><i class="bi bi-receipt-cutoff"></i> Factures fournisseurs</h3>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3 filtres-factures" data-tab="factures_fournisseurs">
                    <select class="form-select form-select-sm filtre-etat" style="width:auto;">
                        <option value="">Tous les états</option>
                        <option value="Payee">Payée</option>
                        <option value="Partielle">Partielle</option>
                        <option value="Impayee">Impayée</option>
                    </select>
                    <div class="contact-combo" style="width:220px;">
                        <select class="contact-combo-source" style="display:none;">
                            <?php foreach ($fournisseursListeRapport as $fo): ?>
                                <option value="<?= e($fo['code_contact']) ?>"><?= e($fo['nom_prenom_contact']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="form-control form-control-sm contact-combo-input" placeholder="Tous les fournisseurs" autocomplete="off">
                        <input type="hidden" class="filtre-contact" value="">
                        <div class="contact-combo-menu"></div>
                    </div>
                    <select class="form-select form-select-sm filtre-tri" style="width:auto;">
                        <option value="recent">Plus récentes d'abord</option>
                        <option value="ancien">Plus anciennes d'abord</option>
                    </select>
                    <button type="button" class="btn btn-primary btn-sm btn-appliquer-filtre">
                        <i class="bi bi-funnel"></i> Filtrer
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm btn-imprimer-rapport" data-section="factures_fournisseurs" data-mode="I">
                        <i class="bi bi-printer"></i> Imprimer
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-imprimer-rapport" data-section="factures_fournisseurs" data-mode="D">
                        <i class="bi bi-download"></i> Télécharger
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm btn-exporter-excel" data-section="factures_fournisseurs">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>N° Facture</th><th>Fournisseur</th><th>Date</th><th>Montant TTC</th><th>Avance</th><th>Reste</th><th>État</th></tr></thead>
                        <tbody id="tbody-factures_fournisseurs"><?= $resFactFourn['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-factures_fournisseurs"><?= $resFactFourn['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 5 : RÉSUMÉ DES ACHATS -->
        <div class="tab-pane fade <?= $onglet=='achats'?'show active':'' ?>" id="pane-achats">
            <div class="chart-card">
                <h4><i class="bi bi-graph-up-arrow"></i> Évolution des achats (12 derniers mois)</h4>
                <canvas id="chartAchats" height="90"></canvas>
            </div>
            <div class="report-card">
                <h3><i class="bi bi-bag"></i> Détail des achats</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>N° Commande</th><th>Date</th><th>Fournisseur</th><th>Produit</th><th>Catégorie</th><th>Qté</th><th>Prix achat</th><th>Montant</th><th>Boutique</th><th>État</th></tr></thead>
                        <tbody id="tbody-achats"><?= $resAchats['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-achats"><?= $resAchats['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 6 : SOLDES -->
        <div class="tab-pane fade <?= $onglet=='soldes'?'show active':'' ?>" id="pane-soldes">
            <div class="sub-nav">
                <button type="button" class="active" data-solde="clients"><i class="bi bi-arrow-down-circle"></i> Clients — doivent <?= fmt($totalCreances) ?> F / avance <?= fmt($totalAvancesClients) ?> F</button>
                <button type="button" data-solde="fournisseurs"><i class="bi bi-arrow-up-circle"></i> Fournisseurs — on doit <?= fmt($totalDettes) ?> F / avance <?= fmt($totalAvancesFournisseurs) ?> F</button>
            </div>
            <div class="report-card" id="card-soldes_clients">
                <h3><i class="bi bi-people"></i> Qui doit de l'argent (factures non soldées)</h3>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3 filtres-soldes" data-tab="soldes_clients">
                    <div class="contact-combo" style="width:260px;">
                        <select class="contact-combo-source" style="display:none;">
                            <?php foreach ($clientsListeRapport as $cl): ?>
                                <option value="<?= e($cl['code_contact']) ?>"><?= e($cl['nom_prenom_contact']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="form-control form-control-sm contact-combo-input" placeholder="Tous les clients" autocomplete="off">
                        <input type="hidden" class="filtre-contact" value="">
                        <div class="contact-combo-menu"></div>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm btn-appliquer-filtre">
                        <i class="bi bi-funnel"></i> Filtrer
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm btn-imprimer-rapport" data-section="soldes_clients" data-mode="I">
                        <i class="bi bi-printer"></i> Imprimer
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-imprimer-rapport" data-section="soldes_clients" data-mode="D">
                        <i class="bi bi-download"></i> Télécharger
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm btn-exporter-excel" data-section="soldes_clients">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Client</th><th>Téléphone</th><th>Nb factures dues</th><th>Solde</th><th>Dernière facture</th><?php if ($peutAjusterSolde): ?><th></th><?php endif; ?></tr></thead>
                        <tbody id="tbody-soldes_clients"><?= $resSoldesClients['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-soldes_clients"><?= $resSoldesClients['paginationHtml'] ?></div>
            </div>
            <div class="report-card" id="card-soldes_fournisseurs" style="display:none;">
                <h3><i class="bi bi-truck"></i> Ce qu'on doit aux fournisseurs</h3>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3 filtres-soldes" data-tab="soldes_fournisseurs">
                    <div class="contact-combo" style="width:260px;">
                        <select class="contact-combo-source" style="display:none;">
                            <?php foreach ($fournisseursListeRapport as $fo): ?>
                                <option value="<?= e($fo['code_contact']) ?>"><?= e($fo['nom_prenom_contact']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="form-control form-control-sm contact-combo-input" placeholder="Tous les fournisseurs" autocomplete="off">
                        <input type="hidden" class="filtre-contact" value="">
                        <div class="contact-combo-menu"></div>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm btn-appliquer-filtre">
                        <i class="bi bi-funnel"></i> Filtrer
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm btn-imprimer-rapport" data-section="soldes_fournisseurs" data-mode="I">
                        <i class="bi bi-printer"></i> Imprimer
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-imprimer-rapport" data-section="soldes_fournisseurs" data-mode="D">
                        <i class="bi bi-download"></i> Télécharger
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm btn-exporter-excel" data-section="soldes_fournisseurs">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Fournisseur</th><th>Téléphone</th><th>Nb factures dues</th><th>Solde</th><th>Dernière facture</th><?php if ($peutAjusterSolde): ?><th></th><?php endif; ?></tr></thead>
                        <tbody id="tbody-soldes_fournisseurs"><?= $resSoldesFourn['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-soldes_fournisseurs"><?= $resSoldesFourn['paginationHtml'] ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($peutAjusterSolde): ?>
<!-- Modal : ajuster un solde -->
<div class="modal fade" id="modalAjusterSolde" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-sliders"></i> Ajuster le solde</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ajCode">
                <div class="mb-1"><strong id="ajNom"></strong></div>
                <div class="mb-3 text-muted small">Solde actuel : <span id="ajActuel"></span></div>
                <div class="mb-2">
                    <label class="form-label small mb-1">Le solde doit être</label>
                    <select class="form-select form-select-sm" id="ajSens">
                        <option value="doit">Doit (dette)</option>
                        <option value="avance">En avance (avoir)</option>
                        <option value="solde">Soldé (0)</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1">Montant (F)</label>
                    <input type="text" class="form-control form-control-sm" id="ajMontant" inputmode="decimal" autocomplete="off">
                </div>
                <div class="small mb-2">Nouveau solde : <strong id="ajApercu"></strong></div>
                <div class="alert alert-danger py-2 small d-none mb-0" id="ajErreur"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary btn-sm" id="ajEnregistrer"><i class="bi bi-check2"></i> Enregistrer</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function () {
    $('.selectpicker').selectpicker();

    // ===== Combobox de recherche client/fournisseur (fait maison) =====
    // Remplace bootstrap-select pour ces filtres : simple input texte +
    // liste filtrée en JS, valeur stockée dans un input caché ".filtre-contact"
    // qu'on contrôle nous-mêmes à 100% (fiable même avec des centaines
    // d'options, contrairement au select natif qui devient long à parcourir).
    function initContactCombo($wrap) {
        var options = [];
        $wrap.find('.contact-combo-source option').each(function () {
            options.push({ value: $(this).val(), label: $(this).text() });
        });
        var $input = $wrap.find('.contact-combo-input');
        var $hidden = $wrap.find('.filtre-contact');
        var $menu = $wrap.find('.contact-combo-menu');
        var MAX_AFFICHES = 100;

        function render(filtre) {
            var f = (filtre || '').toLowerCase().trim();
            var matches = f === '' ? options : options.filter(function (o) {
                return o.label.toLowerCase().indexOf(f) !== -1;
            });
            if (matches.length === 0) {
                $menu.html('<div class="contact-combo-empty">Aucun résultat</div>');
            } else {
                var html = '';
                matches.slice(0, MAX_AFFICHES).forEach(function (o) {
                    html += '<div class="contact-combo-item" data-value="' + o.value + '">' + o.label + '</div>';
                });
                if (matches.length > MAX_AFFICHES) {
                    html += '<div class="contact-combo-empty">+ ' + (matches.length - MAX_AFFICHES) + ' autre(s) — affinez la recherche</div>';
                }
                $menu.html(html);
            }
            $menu.show();
        }

        $input.on('focus', function () { render($input.val()); });
        $input.on('input', function () {
            $hidden.val(''); // toute frappe invalide la sélection tant qu'on n'a pas re-cliqué une option
            render($input.val());
        });
        $input.on('keydown', function (e) {
            if (e.key === 'Escape') { $menu.hide(); $input.trigger('blur'); }
        });
        $menu.on('mousedown', '.contact-combo-item', function (e) {
            e.preventDefault(); // empêche le blur de l'input de fermer le menu avant le clic
            $hidden.val($(this).data('value'));
            $input.val($(this).text());
            $menu.hide();
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest($wrap).length) $menu.hide();
        });
        $input.on('blur', function () {
            // Si le texte tapé ne correspond à aucune sélection valide, on
            // revient à "tous" plutôt que de laisser un texte trompeur.
            setTimeout(function () {
                if ($hidden.val() === '') $input.val('');
            }, 150);
        });
    }
    $('.contact-combo').each(function () { initContactCombo($(this)); });

    // Mémoriser l'onglet actif
    $('#rapportTabs button').on('shown.bs.tab', function (e) {
        var tab = $(e.target).data('tab');
        var url = new URL(window.location.href);
        url.searchParams.set('onglet', tab);
        history.replaceState(null, '', url);
    });

    // Sous-onglets Créances / Dettes
    $('.sub-nav button').on('click', function () {
        $('.sub-nav button').removeClass('active');
        $(this).addClass('active');
        var solde = $(this).data('solde');
        $('#card-soldes_clients').toggle(solde === 'clients');
        $('#card-soldes_fournisseurs').toggle(solde === 'fournisseurs');
    });

    // Graphique répartition trésorerie
    <?php
        $labelsTreso = [];
        $colorsTreso = [];
        $dataTreso = [];
        $colorParType = ['Entree' => '#10b981', 'Sortie' => '#ef4444'];
        $labelParType = ['Entree' => 'Entrées', 'Sortie' => 'Sorties'];
        foreach ($repartitionTreso as $r) {
            $labelsTreso[] = $labelParType[$r['type_transaction']] ?? $r['type_transaction'];
            $colorsTreso[] = $colorParType[$r['type_transaction']] ?? '#64748b';
            $dataTreso[] = floatval($r['total']);
        }
    ?>
    <?php if (!empty($repartitionTreso)): ?>
    var ctxTreso = document.getElementById('chartTreso')?.getContext('2d');
    if (ctxTreso) {
        new Chart(ctxTreso, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($labelsTreso) ?>,
                datasets: [{
                    data: <?= json_encode($dataTreso) ?>,
                    backgroundColor: <?= json_encode($colorsTreso) ?>,
                    borderWidth: 0,
                    borderRadius: 4,
                    spacing: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { family: 'Inter', size: 11, weight: '600' }, color: '#64748b', padding: 12 }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    // Graphique évolution achats
    <?php if (!empty($evolAchats)): ?>
    var ctxAchats = document.getElementById('chartAchats')?.getContext('2d');
    if (ctxAchats) {
        new Chart(ctxAchats, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($evolAchats, 'mois')) ?>,
                datasets: [{
                    label: 'Achats (FCFA)',
                    data: <?= json_encode(array_map(fn($r) => floatval($r['total']), $evolAchats)) ?>,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.08)',
                    tension: 0.3,
                    fill: true,
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointBackgroundColor: '#ef4444'
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } }
            }
        });
    }
    <?php endif; ?>

    // Pagination AJAX
    // On garde une requête en vol par onglet pour pouvoir l'annuler : sans ça,
    // deux requêtes successives (ex. un clic rapide, ou l'ancien filtrage
    // automatique) pouvaient se chevaucher et la plus ancienne, si elle
    // revenait après la plus récente, écrasait le résultat filtré par un
    // résultat obsolète (moins filtré).
    var requetesEnCours = {};
    function chargerPage(tab, page) {
        var data = { ajax: 1, tab: tab, page: page };
        var $filtres = $('.filtres-factures[data-tab="' + tab + '"]');
        if ($filtres.length) {
            data.etat_filtre = $filtres.find('.filtre-etat').val() || '';
            data.contact_filtre = $filtres.find('.filtre-contact').val() || '';
            data.tri = $filtres.find('.filtre-tri').val() || 'recent';
        }
        var $filtresSoldes = $('.filtres-soldes[data-tab="' + tab + '"]');
        if ($filtresSoldes.length) {
            data.contact_filtre = $filtresSoldes.find('.filtre-contact').val() || '';
        }
        if (requetesEnCours[tab]) {
            requetesEnCours[tab].abort();
        }
        requetesEnCours[tab] = $.post(window.location.pathname, data, function (res) {
            $('#tbody-' + tab).html(res.table);
            $('#pagination-' + tab).html(res.pagination);
        }, 'json').always(function () {
            requetesEnCours[tab] = null;
        });
    }
    $('.tab-content').on('click', '.pagination .page-link', function (e) {
        e.preventDefault();
        var page = $(this).data('page');
        if (!page || $(this).closest('li').hasClass('disabled')) return;
        var tbody = $(this).closest('.report-card').find('tbody').attr('id').replace('tbody-', '');
        chargerPage(tbody, page);
    });
    // Le filtrage ne se déclenche plus automatiquement au changement d'un
    // select (état / client / tri) : uniquement via le bouton "Filtrer"
    // ci-dessous. Cela évite les requêtes concurrentes qui pouvaient
    // s'écraser entre elles et faire réapparaître des résultats non filtrés.
    $('.tab-content').on('click', '.btn-appliquer-filtre', function () {
        var tab = $(this).closest('.filtres-factures, .filtres-soldes').data('tab');
        chargerPage(tab, 1);
    });

    // ---- Ajustement manuel d'un solde (sans passer par la base) ----
    var CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
    function fmtF(n) { return Math.round(Math.abs(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
    function texteSolde(s) {
        if (s > 0) return 'Doit ' + fmtF(s) + ' F';
        if (s < 0) return 'En avance de ' + fmtF(s) + ' F';
        return 'Soldé (0 F)';
    }
    function montantSaisi() {
        var v = parseFloat(String($('#ajMontant').val()).replace(/[\s\u00a0]/g, '').replace(',', '.'));
        return isNaN(v) ? 0 : v;
    }
    function majApercu() {
        var sens = $('#ajSens').val();
        $('#ajMontant').prop('disabled', sens === 'solde');
        var m = sens === 'solde' ? 0 : montantSaisi();
        $('#ajApercu').text(texteSolde(sens === 'doit' ? m : (sens === 'avance' ? -m : 0)));
    }
    $('#ajSens').on('change', majApercu);
    $('#ajMontant').on('input', majApercu);
    $('.tab-content').on('click', '.btn-ajuster-solde', function () {
        var $b = $(this), solde = parseFloat($b.data('solde')) || 0;
        $('#ajCode').val($b.data('code'));
        $('#ajNom').text($b.data('nom'));
        $('#ajActuel').text(texteSolde(solde));
        $('#ajSens').val(solde > 0 ? 'doit' : (solde < 0 ? 'avance' : 'solde'));
        $('#ajMontant').val(solde === 0 ? '' : Math.abs(solde));
        $('#ajErreur').addClass('d-none');
        majApercu();
        new bootstrap.Modal(document.getElementById('modalAjusterSolde')).show();
    });
    $('#ajEnregistrer').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(window.location.pathname, {
            action: 'ajuster_solde', csrf_token: CSRF_TOKEN, code_contact: $('#ajCode').val(),
            sens: $('#ajSens').val(), montant: $('#ajMontant').val()
        }, function (res) {
            if (res.ok) {
                // On recharge la page pour rafraîchir aussi les totaux en haut de l'onglet.
                sessionStorage.setItem('soldeVue', $('.sub-nav button.active').data('solde') || 'clients');
                location.reload();
            } else {
                $('#ajErreur').text(res.message).removeClass('d-none');
                $btn.prop('disabled', false);
            }
        }, 'json').fail(function (xhr) {
            var msg = 'Erreur de communication avec le serveur.';
            try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
            $('#ajErreur').text(msg).removeClass('d-none');
            $btn.prop('disabled', false);
        });
    });
    var vueSoldes = sessionStorage.getItem('soldeVue');
    if (vueSoldes) {
        sessionStorage.removeItem('soldeVue');
        $('.sub-nav button[data-solde="' + vueSoldes + '"]').trigger('click');
    }

    // ---- Impression / téléchargement PDF (mêmes filtres que l'onglet imprimé) ----
    function imprimerRapport(section, mode, format) {
        var data = { action: 'pdf', section: section, mode: mode || 'I', format: format || 'pdf' };
        var $filtres = $('.filtres-factures[data-tab="' + section + '"]');
        if ($filtres.length) {
            data.etat_filtre = $filtres.find('.filtre-etat').val() || '';
            data.contact_filtre = $filtres.find('.filtre-contact').val() || '';
            data.tri = $filtres.find('.filtre-tri').val() || 'recent';
        }
        var $filtresSoldes = $('.filtres-soldes[data-tab="' + section + '"]');
        if ($filtresSoldes.length) {
            data.contact_filtre = $filtresSoldes.find('.filtre-contact').val() || '';
        }
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
    $('.tab-content').on('click', '.btn-imprimer-rapport', function () {
        imprimerRapport($(this).data('section'), $(this).data('mode'), 'pdf');
    });
    $('.tab-content').on('click', '.btn-exporter-excel', function () {
        // Toujours en téléchargement direct ('D') : un .xlsx affiché "inline"
        // n'a pas de sens dans un navigateur, contrairement à un PDF.
        imprimerRapport($(this).data('section'), 'D', 'excel');
    });
});
</script>
</body>
</html>