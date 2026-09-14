<?php
// Extrait de vente.php (découpage du fichier — voir audit technique) :
// fonctions utilitaires d'affichage (badges, rendu de carte facture,
// pagination) + chargement des données et statistiques (KPI, catégories)
// consommées par le gabarit HTML de vente.php.

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

/**
 * Rendu HTML d'une carte "facture". Extrait dans une fonction réutilisable
 * pour servir à la fois le chargement initial ET les rafraîchissements
 * AJAX paginés (voir getFacturesListe ci-dessous).
 */
function renderFactureCard(array $row, int $delaiSuppressionJours, bool $estSuperviseurOuAdmin): string {
    $etatBadge = getEtatBadge($row['etat_facture']);
    $isValidee = (strtolower($row['statut_facture']) === 'validee');
    $isPaidValidee = in_array(strtolower($row['etat_facture'] ?? ''), ['payee', 'payee cash']) && $isValidee;
    $ageJours = (strtotime(date('Y-m-d')) - strtotime($row['date_facture'])) / 86400;
    $joursRestants = (int) ceil($delaiSuppressionJours - $ageJours);
    $peutSupprimer = ($_SESSION['role'] === 'Administrateur' || $_SESSION['role'] === 'Superviseur');

    ob_start();
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

                <?php if ($peutSupprimer): ?>
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
    <?php
    return ob_get_clean();
}

/**
 * Liste paginée des factures/bons de vente (remplace le chargement de
 * TOUTES les factures en une seule requête, sans limite — même problème
 * que celui déjà corrigé sur facture/bon_livraison.php).
 */
function getFacturesListe(PDO $pdo, array $boutiquesAutorisees, array $filtres, int $page, int $perPage = 24): array {
    $delaiSuppressionJours = 7;
    $estSuperviseurOuAdmin = in_array($_SESSION['role'] ?? '', ['Administrateur', 'Superviseur'], true);

    if (empty($boutiquesAutorisees)) {
        return ['html' => '', 'pagination' => '', 'total' => 0, 'page' => 1, 'totalPages' => 1];
    }

    $inPh = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $where = "WHERE c.type_contact = 'Client' AND f.categorie_facture = 'Bon'
              AND EXISTS (SELECT 1 FROM commande cm WHERE cm.facture_id = f.numero_facture AND cm.boutique_id IN ($inPh))";
    $params = $boutiquesAutorisees;

    if (!empty($filtres['client'])) {
        $where .= " AND f.contact_id = ?";
        $params[] = $filtres['client'];
    }
    if (!empty($filtres['etat'])) {
        $where .= " AND f.etat_facture = ?";
        $params[] = $filtres['etat'];
    }
    if (!empty($filtres['statut'])) {
        $where .= " AND f.statut_facture = ?";
        $params[] = $filtres['statut'];
    }

    $baseSql = "FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact $where";

    $stmtCount = $pdo->prepare("SELECT COUNT(*) $baseSql");
    $stmtCount->execute($params);
    $total = (int) $stmtCount->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    if ($page < 1) $page = 1;

    $sql = "SELECT f.*, c.nom_prenom_contact
            $baseSql
            ORDER BY f.date_facture DESC
            LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($factures)) {
        $html = '<div class="col-12"><div class="bg-white border border-dashed rounded-3 p-5 text-center text-muted">'
              . '<i class="bi bi-inbox d-block mb-2" style="font-size:56px;opacity:.2;"></i>'
              . '<h5 class="text-dark">Aucune facture trouvée</h5>'
              . '<p class="small mb-0">Les factures apparaîtront ici dès leur création.</p></div></div>';
    } else {
        $html = '';
        foreach ($factures as $row) {
            $html .= renderFactureCard($row, $delaiSuppressionJours, $estSuperviseurOuAdmin);
        }
    }

    ob_start();
    if ($totalPages > 1):
    ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light rounded-3 mt-2">
        <span class="text-muted small">Affichage de <?= (($page - 1) * $perPage + 1) ?> à <?= min($page * $perPage, $total) ?> sur <?= $total ?></span>
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
                for ($i = $start; $i <= $end; $i++):
                ?>
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

    return ['html' => $html, 'pagination' => $paginationHtml, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages];
}

$stmtClients = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE etat_contact = 'Actif' AND type_contact = 'Client' ORDER BY nom_prenom_contact ASC");
$clients = $stmtClients->fetchAll(PDO::FETCH_ASSOC);
// KPI résumé : restreints aux boutiques autorisées (toutes pour
// Administrateur/Superviseur, la ou les boutiques de l'utilisateur sinon —
// via les lignes commande de chaque facture, comme pour le listing).
if (empty($boutiquesAutorisees)) {
    $totalFactures = $payees = $partielles = $impayees = 0;
    $totalMontant = $totalReste = 0;
} else {
    $inPhKpiVente = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $whereBKpiVente = "AND EXISTS (SELECT 1 FROM commande cm WHERE cm.facture_id = f.numero_facture AND cm.boutique_id IN ($inPhKpiVente))";

    $stmtKpi1 = $pdo->prepare("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' $whereBKpiVente");
    $stmtKpi1->execute($boutiquesAutorisees);
    $totalFactures = $stmtKpi1->fetchColumn();

    $stmtKpi2 = $pdo->prepare("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' AND f.etat_facture IN ('Payee', 'Payee cash') $whereBKpiVente");
    $stmtKpi2->execute($boutiquesAutorisees);
    $payees = $stmtKpi2->fetchColumn();

    $stmtKpi3 = $pdo->prepare("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' AND f.etat_facture = 'Partielle' $whereBKpiVente");
    $stmtKpi3->execute($boutiquesAutorisees);
    $partielles = $stmtKpi3->fetchColumn();

    $stmtKpi4 = $pdo->prepare("SELECT COUNT(*) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' AND f.etat_facture = 'Impayee' $whereBKpiVente");
    $stmtKpi4->execute($boutiquesAutorisees);
    $impayees = $stmtKpi4->fetchColumn();

    $stmtKpi5 = $pdo->prepare("SELECT SUM(f.montant_ttc) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' $whereBKpiVente");
    $stmtKpi5->execute($boutiquesAutorisees);
    $totalMontant = $stmtKpi5->fetchColumn() ?? 0;

    $stmtKpi6 = $pdo->prepare("SELECT SUM(f.reste) FROM facture f INNER JOIN contact c ON f.contact_id = c.code_contact WHERE c.type_contact = 'Client' $whereBKpiVente");
    $stmtKpi6->execute($boutiquesAutorisees);
    $totalReste = $stmtKpi6->fetchColumn() ?? 0;
}

// - Catégories actives (pour l'ajout de nouvelles lignes lors de la
//   modification d'un bon de commande) : même logique que entree_stock.php -
// Remarque : la liste des PRODUITS n'est plus préchargée en entier ici (elle
// pouvait peser plusieurs centaines de Ko de JSON embarqué dans la page, pour
// un catalogue qui ne fait que grandir). Le panneau d'ajout de ligne récupère
// désormais les produits d'une catégorie à la demande via l'action AJAX
// 'produits_par_categorie' (voir plus bas), au moment où l'utilisateur choisit
// une catégorie — au lieu de tout charger dès l'ouverture de la page.
$categoriesVente = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie WHERE etat_categorie='ACTIF' ORDER BY titre_categorie")->fetchAll(PDO::FETCH_ASSOC);

// Catégories autorisées par boutique (restriction optionnelle, voir
// config/authentification.php::getCategoriesAutoriseesBoutique). Un bon est
// déjà rattaché à une boutique précise : on filtre le sélecteur de catégorie
// du panneau d'ajout de ligne en fonction de cette boutique.
$categoriesAutoriseesParBoutiqueVente = [];
foreach ($boutiquesAutorisees as $bId) {
    $categoriesAutoriseesParBoutiqueVente[$bId] = getCategoriesAutoriseesBoutique($pdo, $userInfoVente['role'] ?? null, $bId);
}