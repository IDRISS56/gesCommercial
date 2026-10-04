<?php
// views/transaction/index.php – Historique des transactions
ob_start();
require  'databases/database.php';

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Boutique(s) auxquelles cet utilisateur a accès : toutes les boutiques
// actives pour Administrateur/Superviseur, uniquement la sienne (+ accès
// supplémentaire éventuel) pour les autres rôles — même mécanisme que le
// reste de l'application (config/authentification.php).
$boutiquesAutoriseesTransaction = getBoutiquesAutorisees($pdo, $_SESSION['role'] ?? null, $_SESSION['boutique_id'] ?? null);

require 'views/transaction/transaction_pdf.php';


$message = '';
$messageType = '';

// ============================================================
// FONCTION : RÉCUPÉRATION DU CONTENU DU TABLEAU
// ============================================================
function getTableContent($pdo, $search, $filtres, $page, $boutiquesAutorisees, $perPage = 25) {
    $placeholdersBqTr = !empty($boutiquesAutorisees) ? implode(',', array_fill(0, count($boutiquesAutorisees), '?')) : "''";
    // Requête adaptée au schéma réel de la table transaction
    $sql = "SELECT 
                t.numero_transaction,
                t.date_transaction,
                t.heure_transaction,
                t.montant_transaction,
                t.frais_transaction,
                t.montant_total,
                t.type_transaction,
                t.objet_transaction,
                t.caisse_id,
                t.facture_id,
                t.mode_reglement,
                t.numero_reglement,
                t.reference_reglement,
                t.utilisateur_id,
                t.etat_transaction,
                c.nom_caisse,
                f.titre_facture,
                f.type_facture,
                ct.nom_prenom_contact,
                u.nom_prenom AS utilisateur_nom
            FROM transaction t
            LEFT JOIN caisse c ON c.caisse_id = t.caisse_id
            LEFT JOIN facture f ON f.numero_facture = t.facture_id
            LEFT JOIN contact ct ON ct.code_contact = f.contact_id
            LEFT JOIN utilisateur u ON u.id = t.utilisateur_id
            WHERE (c.boutique_id IN ($placeholdersBqTr) OR c.boutique_id IS NULL)";
    $params = $boutiquesAutorisees;

    // Recherche textuelle
    if (!empty($search)) {
        $sql .= " AND (
            t.numero_transaction LIKE ? 
            OR t.objet_transaction LIKE ? 
            OR t.numero_reglement LIKE ?
            OR t.reference_reglement LIKE ?
            OR ct.nom_prenom_contact LIKE ?
            OR f.titre_facture LIKE ?
            OR c.nom_caisse LIKE ?
            OR u.nom_prenom LIKE ?
        )";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 8; $i++) $params[] = $like;
    }

    // Filtres
    if (!empty($filtres['type'])) {
        $sql .= " AND t.type_transaction = ?";
        $params[] = $filtres['type'];
    }
    if (!empty($filtres['mode_reglement'])) {
        $sql .= " AND t.mode_reglement = ?";
        $params[] = $filtres['mode_reglement'];
    }
    if (!empty($filtres['etat'])) {
        $sql .= " AND t.etat_transaction = ?";
        $params[] = $filtres['etat'];
    }
    if (!empty($filtres['caisse'])) {
        $sql .= " AND t.caisse_id = ?";
        $params[] = $filtres['caisse'];
    }
    if (!empty($filtres['boutique'])) {
        $sql .= " AND c.boutique_id = ?";
        $params[] = $filtres['boutique'];
    }
    if (!empty($filtres['utilisateur'])) {
        $sql .= " AND t.utilisateur_id = ?";
        $params[] = $filtres['utilisateur'];
    }
    if (!empty($filtres['facture'])) {
        $sql .= " AND t.facture_id = ?";
        $params[] = $filtres['facture'];
    }
    if (!empty($filtres['objet'])) {
        $sql .= " AND t.objet_transaction LIKE ?";
        $params[] = '%' . $filtres['objet'] . '%';
    }
    if (!empty($filtres['date_debut'])) {
        $sql .= " AND t.date_transaction >= ?";
        $params[] = $filtres['date_debut'];
    }
    if (!empty($filtres['date_fin'])) {
        $sql .= " AND t.date_transaction <= ?";
        $params[] = $filtres['date_fin'];
    }

    // Calcul des totaux pour les stats
    $countSql = str_replace(
        "SELECT 
                t.numero_transaction,
                t.date_transaction,
                t.heure_transaction,
                t.montant_transaction,
                t.frais_transaction,
                t.montant_total,
                t.type_transaction,
                t.objet_transaction,
                t.caisse_id,
                t.facture_id,
                t.mode_reglement,
                t.numero_reglement,
                t.reference_reglement,
                t.utilisateur_id,
                t.etat_transaction,
                c.nom_caisse,
                f.titre_facture,
                f.type_facture,
                ct.nom_prenom_contact,
                u.nom_prenom AS utilisateur_nom",
        "SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN t.type_transaction='Entree' THEN t.montant_total ELSE 0 END), 0) as total_entrees,
            COALESCE(SUM(CASE WHEN t.type_transaction='Sortie' THEN t.montant_total ELSE 0 END), 0) as total_sorties,
            COALESCE(SUM(t.montant_total), 0) as total_general",
        $sql
    );
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = (int)$stats['total'];
    $totalPages = max(1, ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    // Requête paginée
    $sql .= " ORDER BY t.date_transaction DESC, t.heure_transaction DESC, t.numero_transaction DESC LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Génération HTML du tableau
    ob_start();
    if (empty($transactions)):
?>
    <tr>
        <td colspan="11" class="text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
            Aucune transaction trouvée
        </td>
    </tr>
<?php else: ?>
    <?php foreach ($transactions as $tr): ?>
    <?php
        $isEntree = ($tr['type_transaction'] === 'Entree');
        $typeLabel = $isEntree ? 'Entrée' : 'Sortie';
        $typeClass = $isEntree ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
        $etatClass = '';
        $etatIcon = '';
        switch ($tr['etat_transaction']) {
            case 'Succes': $etatClass = 'on'; $etatIcon = 'check-circle-fill'; break;
            case 'Echec': $etatClass = 'off'; $etatIcon = 'x-circle-fill'; break;
            case 'En attente': $etatClass = 'warn'; $etatIcon = 'clock-fill'; break;
            case 'Annulee': $etatClass = 'off'; $etatIcon = 'arrow-counterclockwise'; break;
            default: $etatClass = 'off'; $etatIcon = 'question-circle-fill';
        }
        $peutAnnuler = ($tr['etat_transaction'] === 'Succes')
            && (($tr['objet_transaction'] ?? '') !== 'Vente comptoir')
            && in_array($_SESSION['role'] ?? '', ['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse'], true);
    ?>
    <tr>
        <td class="td-bold">
            <small class="text-muted d-block"><?= e($tr['numero_transaction']) ?></small>
        </td>
        <td><?= date('d/m/Y', strtotime($tr['date_transaction'])) ?></td>
        <td class="text-muted"><?= substr($tr['heure_transaction'], 0, 5) ?></td>
        <td>
            <span class="badge <?= $typeClass ?>" style="font-size:10px;font-weight:700;">
                <i class="bi bi-<?= $isEntree ? 'arrow-down-left' : 'arrow-up-right' ?>"></i>
                <?= $typeLabel ?>
            </span>
        </td>
        <td class="text-end td-bold" style="<?= $isEntree ? 'color:#10b981' : 'color:#ef4444' ?>">
            <?= $isEntree ? '+' : '-' ?><?= fmt($tr['montant_total']) ?> F
        </td>
        <td>
            <span class="mode-badge">
                <?php
                    $modeIcons = [
                        'Espèce' => 'cash',
                        'Virement' => 'bank',
                        'Carte' => 'credit-card',
                        'Mobile money' => 'phone',
                        'Chèque' => 'receipt'
                    ];
                    $icon = $modeIcons[$tr['mode_reglement']] ?? 'wallet2';
                ?>
                <i class="bi bi-<?= $icon ?>"></i>
                <?= e($tr['mode_reglement'] ?? '—') ?>
            </span>
            <?php if (!empty($tr['numero_reglement'])): ?>
                <small class="d-block text-muted" style="font-size:10px;">N°: <?= e($tr['numero_reglement']) ?></small>
            <?php endif; ?>
        </td>
        <td>
            <?php if (!empty($tr['nom_prenom_contact'])): ?>
                <strong><?= e($tr['nom_prenom_contact']) ?></strong>
            <?php elseif (!empty($tr['titre_facture'])): ?>
                <span class="text-muted"><?= e($tr['titre_facture']) ?></span>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
            <?php if (!empty($tr['objet_transaction'])): ?>
                <small class="d-block text-muted" style="font-size:10px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($tr['objet_transaction']) ?>">
                    <?= e($tr['objet_transaction']) ?>
                </small>
            <?php endif; ?>
        </td>
        <td>
            <small><?= e($tr['nom_caisse'] ?? '—') ?></small>
        </td>
        <td>
            <small><?= e($tr['utilisateur_nom'] ?? '—') ?></small>
        </td>
        <td>
            <span class="status-badge <?= $etatClass ?>">
                <i class="bi bi-<?= $etatIcon ?>"></i>
                <?= e($tr['etat_transaction']) ?>
            </span>
            <?php if ($tr['etat_transaction'] === 'Annulee' && !empty($tr['motif_annulation'])): ?>
                <small class="d-block text-muted" style="font-size:10px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($tr['motif_annulation']) ?>">
                    <?= e($tr['motif_annulation']) ?>
                </small>
            <?php endif; ?>
        </td>
        <td class="text-end" style="white-space:nowrap;">
            <button class="act-btn view" data-code="<?= e($tr['numero_transaction']) ?>" title="Voir le détail">
                <i class="bi bi-eye"></i>
            </button>
            <?php if ($peutAnnuler): ?>
            <button class="act-btn delete btn-annuler-transaction" data-code="<?= e($tr['numero_transaction']) ?>" title="Annuler cette transaction">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
<?php endif;
    $tableHtml = ob_get_clean();

    // Pagination
    ob_start();
    if ($totalPages > 1):
?>
    <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
        <span class="text-muted small">
            Affichage de <?= (($page - 1) * $perPage + 1) ?> à <?= min($page * $perPage, $total) ?> sur <?= $total ?>
        </span>
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

    return [
        'table' => $tableHtml,
        'pagination' => $paginationHtml,
        'total' => $total,
        'page' => $page,
        'totalPages' => $totalPages,
        'total_entrees' => floatval($stats['total_entrees'] ?? 0),
        'total_sorties' => floatval($stats['total_sorties'] ?? 0),
        'total_general' => floatval($stats['total_general'] ?? 0)
    ];
}

// ============================================================
// ANNULATION D'UNE TRANSACTION (avec trace complète)
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'annuler_transaction') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    if (($_POST['csrf_token'] ?? '') !== $csrf_token) {
        echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
        exit;
    }
    // Annuler une transaction déplace de l'argent (caisse, solde client/fournisseur,
    // facture) : réservé aux rôles qui peuvent déjà supprimer une facture payée.
    if (!in_array($_SESSION['role'] ?? '', ['Administrateur', 'Superviseur'], true)) {
        echo json_encode(['success' => false, 'message' => "Seul un administrateur ou un superviseur peut annuler une transaction."]);
        exit;
    }

    $numero = trim($_POST['numero'] ?? '');
    $motif = trim($_POST['motif'] ?? '');
    if ($numero === '') {
        echo json_encode(['success' => false, 'message' => 'Transaction manquante.']);
        exit;
    }
    if ($motif === '') {
        echo json_encode(['success' => false, 'message' => "Le motif d'annulation est obligatoire (ex. : mauvais montant, mauvaise facture)."]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM transaction WHERE numero_transaction = ? FOR UPDATE");
        $stmt->execute([$numero]);
        $tr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tr) throw new Exception("Transaction introuvable.");
        if ($tr['etat_transaction'] !== 'Succes') {
            throw new Exception("Seule une transaction réussie peut être annulée (état actuel : " . $tr['etat_transaction'] . ").");
        }
        // Une vente comptoir a d'autres effets (stock notamment) qui ne sont pas
        // réversibles depuis cet écran : on la laisse de côté ici plutôt que de
        // ne réverser que l'argent et laisser le stock incohérent.
        if (($tr['objet_transaction'] ?? '') === 'Vente comptoir') {
            throw new Exception("Les ventes comptoir ne peuvent pas être annulées depuis cet écran (elles affectent aussi le stock).");
        }

        $montant = floatval($tr['montant_transaction']);

        // 1) Réversion du solde de caisse (toujours calculable de façon fiable).
        $stmtCaisse = $pdo->prepare("SELECT * FROM caisse WHERE caisse_id = ? FOR UPDATE");
        $stmtCaisse->execute([$tr['caisse_id']]);
        $caisse = $stmtCaisse->fetch(PDO::FETCH_ASSOC);
        if ($caisse) {
            $nouveauSolde = ($tr['type_transaction'] === 'Entree')
                ? floatval($caisse['solde']) - $montant
                : floatval($caisse['solde']) + $montant;
            $pdo->prepare("UPDATE caisse SET solde = ? WHERE caisse_id = ?")->execute([$nouveauSolde, $tr['caisse_id']]);
        }

        // 2) Réversion du solde du contact (client/fournisseur), quand connu.
        //    Exclut explicitement les décaissements directs (dépenses) : ce type
        //    d'opération ne touche jamais solde_contact à la création (seule la
        //    caisse est impactée) — le reverser ici créditerait le contact à tort.
        $estDecaissementDirect = (strpos($tr['objet_transaction'] ?? '', 'Décaissement fournisseur (dépense)') === 0);
        // Nouvelles dépenses (menu Dépenses) : identifiées par la table `depense`,
        // pas par le texte de l'objet. Elles n'ont jamais de contact.
        if (!$estDecaissementDirect) {
            $stmtDep = $pdo->prepare("SELECT COUNT(*) FROM depense WHERE numero_transaction = ?");
            $stmtDep->execute([$numero]);
            $estDecaissementDirect = ($stmtDep->fetchColumn() > 0);
        }
        if (!empty($tr['contact_id']) && !$estDecaissementDirect) {
            $pdo->prepare("SELECT solde_contact FROM contact WHERE code_contact = ? FOR UPDATE")->execute([$tr['contact_id']]);
            $pdo->prepare("UPDATE contact SET solde_contact = solde_contact + ? WHERE code_contact = ?")->execute([$montant, $tr['contact_id']]);
        }

        // 3) Réversion de la facture DIRECTEMENT liée : on utilise le montant
        //    exact qui lui avait été appliqué, mémorisé à la création de cette
        //    transaction (voir reglement_client.php / reglement_fournisseur.php),
        //    pas le montant total de la transaction (qui a pu déborder sur
        //    d'autres factures — voir avertissement plus bas).
        $avertissementSurplus = null;
        if (!empty($tr['facture_id']) && $tr['montant_applique_facture'] !== null) {
            $montantAppliqueFacture = floatval($tr['montant_applique_facture']);
            $stmtF = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? FOR UPDATE");
            $stmtF->execute([$tr['facture_id']]);
            $facture = $stmtF->fetch(PDO::FETCH_ASSOC);
            if ($facture) {
                $nouvelleAvance = max(0, round(floatval($facture['avance']) - $montantAppliqueFacture, 2));
                $nouveauReste = round(floatval($facture['montant_ttc']) - $nouvelleAvance, 2);
                if ($nouveauReste < 0) $nouveauReste = 0;
                $nouvelEtat = ($nouvelleAvance <= 0) ? 'Impayee' : (($nouveauReste <= 0) ? 'Payee' : 'Partielle');
                $pdo->prepare("UPDATE facture SET avance = ?, reste = ?, etat_facture = ? WHERE numero_facture = ?")
                    ->execute([$nouvelleAvance, $nouveauReste, $nouvelEtat, $tr['facture_id']]);
            }
            if (round($montant - $montantAppliqueFacture, 2) > 0) {
                $avertissementSurplus = "Cette transaction avait aussi couvert "
                    . number_format($montant - $montantAppliqueFacture, 0, ',', ' ')
                    . " F sur d'autres factures du même contact (ou en avance) : elles n'ont PAS été modifiées automatiquement, seul le solde global du contact a été réajusté. Vérifiez-les si besoin.";
            }
        }

        // 4) Marquer la transaction comme annulée — la trace demandée.
        $libelleAnnulePar = trim(($_SESSION['nom_prenom'] ?? ''));
        $pdo->prepare("UPDATE transaction SET etat_transaction = 'Annulee', annule_par = ?, date_annulation = NOW(), motif_annulation = ? WHERE numero_transaction = ?")
            ->execute([$libelleAnnulePar, $motif, $numero]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Transaction annulée.', 'warning' => $avertissementSurplus]);
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
    }
    exit;
}

// ============================================================
// REQUÊTE AJAX : DÉTAIL D'UNE TRANSACTION
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'get_detail') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $numero = trim($_POST['numero'] ?? '');
    $stmt = $pdo->prepare("SELECT t.*, c.nom_caisse, c.boutique_id, f.titre_facture, f.type_facture, f.categorie_facture,
                                   ct.nom_prenom_contact, ct.telephone_contact, u.nom_prenom AS utilisateur_nom
                            FROM transaction t
                            LEFT JOIN caisse c ON c.caisse_id = t.caisse_id
                            LEFT JOIN facture f ON f.numero_facture = t.facture_id
                            LEFT JOIN contact ct ON ct.code_contact = f.contact_id
                            LEFT JOIN utilisateur u ON u.id = t.utilisateur_id
                            WHERE t.numero_transaction = ?");
    $stmt->execute([$numero]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) {
        echo json_encode(['success' => false, 'message' => 'Transaction introuvable.']);
        exit;
    }
    if (!empty($t['boutique_id']) && !in_array($t['boutique_id'], $boutiquesAutoriseesTransaction, true)) {
        echo json_encode(['success' => false, 'message' => 'Vous n\'avez pas accès à cette transaction (autre boutique).']);
        exit;
    }
    echo json_encode(['success' => true, 'transaction' => $t]);
    exit;
}

// ============================================================
// REQUÊTE AJAX (tout en POST)
// ============================================================
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $filtres = [
        'type' => trim($_POST['type'] ?? ''),
        'mode_reglement' => trim($_POST['mode_reglement'] ?? ''),
        'etat' => trim($_POST['etat'] ?? ''),
        'caisse' => trim($_POST['caisse'] ?? ''),
        'boutique' => trim($_POST['boutique'] ?? ''),
        'utilisateur' => trim($_POST['utilisateur'] ?? ''),
        'facture' => trim($_POST['facture'] ?? ''),
        'objet' => trim($_POST['objet'] ?? ''),
        'date_debut' => trim($_POST['date_debut'] ?? ''),
        'date_fin' => trim($_POST['date_fin'] ?? '')
    ];
    if (!empty($filtres['boutique']) && !in_array($filtres['boutique'], $boutiquesAutoriseesTransaction, true)) {
        $filtres['boutique'] = '';
    }
    $page = max(1, (int)($_POST['page'] ?? 1));
    $result = getTableContent($pdo, $search, $filtres, $page, $boutiquesAutoriseesTransaction);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// ============================================================
// DONNÉES POUR LES FILTRES
// ============================================================
$placeholdersBqTr2 = !empty($boutiquesAutoriseesTransaction) ? implode(',', array_fill(0, count($boutiquesAutoriseesTransaction), '?')) : "''";

$stmtCaisses = $pdo->prepare("SELECT caisse_id, nom_caisse FROM caisse WHERE (boutique_id IN ($placeholdersBqTr2) OR boutique_id IS NULL) ORDER BY nom_caisse");
$stmtCaisses->execute($boutiquesAutoriseesTransaction);
$caisses = $stmtCaisses->fetchAll(PDO::FETCH_ASSOC);

$stmtUtil = $pdo->prepare("SELECT id, nom_prenom FROM utilisateur WHERE etat = 'Actif' AND (boutique_id IN ($placeholdersBqTr2) OR boutique_id IS NULL) ORDER BY nom_prenom");
$stmtUtil->execute($boutiquesAutoriseesTransaction);
$utilisateurs = $stmtUtil->fetchAll(PDO::FETCH_ASSOC);

$stmtFact = $pdo->prepare("SELECT numero_facture, titre_facture FROM facture f
                            WHERE EXISTS (SELECT 1 FROM commande cmd WHERE cmd.facture_id = f.numero_facture AND (cmd.boutique_id IN ($placeholdersBqTr2) OR cmd.boutique_id IS NULL))
                            ORDER BY numero_facture DESC LIMIT 500");
$stmtFact->execute($boutiquesAutoriseesTransaction);
$factures = $stmtFact->fetchAll(PDO::FETCH_ASSOC);

$stmtBoutiquesFiltre = $pdo->prepare("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' AND code_boutique IN ($placeholdersBqTr2) ORDER BY nom_boutique");
$stmtBoutiquesFiltre->execute($boutiquesAutoriseesTransaction);
$boutiquesFiltre = $stmtBoutiquesFiltre->fetchAll(PDO::FETCH_ASSOC);

$modes_reglement = ['Espèce', 'Virement', 'Carte', 'Mobile money', 'Chèque', 'Autres'];
$types_transaction = ['Entree', 'Sortie'];
$etats_transaction = ['Succes', 'Echec', 'En attente', 'Annulee'];

// Données initiales
$search = '';
$filtres = ['type' => '', 'mode_reglement' => '', 'etat' => '', 'caisse' => '', 'boutique' => '', 'utilisateur' => '', 'facture' => '', 'objet' => '', 'date_debut' => '', 'date_fin' => ''];
$initialData = getTableContent($pdo, $search, $filtres, 1, $boutiquesAutoriseesTransaction);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des transactions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --b: #2563eb; --bd: #1d4ed8; --bl: #eff6ff; --bb: #bfdbfe;
            --bg: #f1f5f9; --w: #fff; --dk: #0f172a; --mt: #64748b;
            --lt: #94a3b8; --brd: #e2e8f0; --dng: #ef4444; --dngl: #fef2f2;
            --suc: #10b981; --sucl: #ecfdf5; --sucb: #a7f3d0;
            --wrn: #f59e0b; --wrnl: #fffbeb; --wrnb: #fde68a;
            --R: 16px; --Rs: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--dk); min-height: 100vh; padding: 28px 20px; }
        .W { max-width: 1500px; margin: 0 auto; }
        .hdr { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--dk); letter-spacing: -0.02em; font-family: 'Outfit', sans-serif; }
        .hdr-l p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
        .hdr-badge { background: var(--bl); border: 1px solid var(--bb); color: var(--b); padding: 8px 14px; border-radius: var(--Rs); font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 18px; display: flex; align-items: center; gap: 14px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .stat-icon.entree { background: var(--sucl); color: var(--suc); }
        .stat-icon.sortie { background: var(--dngl); color: var(--dng); }
        .stat-icon.total { background: var(--bl); color: var(--b); }
        .stat-icon.count { background: var(--wrnl); color: var(--wrn); }
        .stat-info .label { font-size: 11px; color: var(--mt); text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
        .stat-info .value { font-size: 20px; font-weight: 800; color: var(--dk); font-family: 'Outfit', sans-serif; }
        .pbar { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 16px 20px; margin-bottom: 22px; }
        .prow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .prow label { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .03em; }
        .prow input, .prow select { padding: 9px 12px; border: 1.5px solid var(--brd); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--dk); font-family: 'Inter', sans-serif; transition: all .2s; }
        .prow input:focus, .prow select:focus { border-color: var(--b); background: #fff; box-shadow: 0 0 0 3px var(--bl); outline: none; }
        .prow input[type="text"] { flex: 1; min-width: 200px; }
        .btn-go { background: var(--b); color: #fff; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; border: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all .2s; }
        .btn-go:hover { background: var(--bd); color: #fff; }
        .btn-go-outline { background: transparent; color: var(--mt); border: 1.5px solid var(--brd); padding: 9px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all .2s; }
        .btn-go-outline:hover { background: var(--bg); color: var(--dk); }
        .data-table-wrap { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); animation: fadeUp .4s ease both; }
        table { margin: 0; font-size: 13px; }
        table thead th { background: var(--bg); color: var(--mt); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 12px 14px; border-bottom: 1px solid var(--brd); white-space: nowrap; }
        table tbody td { padding: 12px 14px; border-bottom: 1px solid var(--brd); vertical-align: middle; }
        table tbody tr:hover { background: var(--bl); }
        .td-bold { color: var(--dk) !important; font-weight: 700; }
        .mode-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; background: var(--bg); border: 1px solid var(--brd); border-radius: 6px; font-size: 11px; font-weight: 600; }
        .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: .02em; }
        .status-badge.on { background: var(--sucl); color: #059669; border: 1px solid var(--sucb); }
        .status-badge.off { background: var(--dngl); color: #dc2626; border: 1px solid #fecaca; }
        .status-badge.warn { background: var(--wrnl); color: #b45309; border: 1px solid var(--wrnb); }
        .act-btn { width: 32px; height: 32px; border-radius: 6px; border: 1px solid var(--brd); background: transparent; color: var(--lt); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s; }
        .act-btn:hover { transform: scale(1.1); }
        .act-btn.view:hover { color: var(--b); background: var(--bl); border-color: var(--bb); }
        .act-btn.delete:hover { color: #dc2626; background: #fef2f2; border-color: #fecaca; }
        .pagination .page-link { border: 1px solid var(--brd); color: var(--mt); font-size: 12px; font-weight: 600; padding: 6px 12px; }
        .pagination .page-item.active .page-link { background: var(--b); color: #fff; border-color: var(--b); }
        .bootstrap-select .dropdown-toggle { background: var(--bg) !important; border: 1.5px solid var(--brd) !important; border-radius: 8px !important; font-size: 13px !important; }
        .modal-content { border-radius: var(--R); border: none; box-shadow: 0 12px 40px rgba(15,23,42,.08); }
        /* Toast (remplace alert() — même composant que vente_comptoir) */
        .toast-msg { position: fixed; top: 20px; right: 20px; background: var(--suc); color: #fff; padding: 12px 20px; border-radius: 10px; font-weight: 600; z-index: 2000; display: none; box-shadow: 0 4px 12px rgba(0,0,0,.15); }
        .toast-msg.error { background: var(--dng); }
        .toast-msg.show { display: block; animation: slideIn .3s ease; }
        @keyframes slideIn { from { transform: translateX(30px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--brd); font-size: 13px; }
        .detail-row:last-child { border-bottom: none; }
        .detail-row .label { color: var(--mt); font-weight: 600; }
        .detail-row .value { color: var(--dk); font-weight: 700; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width:700px) { body { padding: 14px; } .hdr { flex-direction: column; align-items: flex-start; } .prow { flex-direction: column; align-items: stretch; } .prow .btn-go { width: 100%; justify-content: center; } }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1><i class="bi bi-clock-history text-primary me-2"></i>Historique des transactions</h1>
            <p>Suivez l'ensemble des mouvements financiers de votre commerce</p>
        </div>
        <div>
            <span class="hdr-badge"><i class="bi bi-arrow-left-right"></i> <?= $initialData['total'] ?> transaction(s)</span>
        </div>
    </div>

    <!-- Statistiques -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon count"><i class="bi bi-hash"></i></div>
            <div class="stat-info">
                <div class="label">Total transactions</div>
                <div class="value" id="statTotal"><?= $initialData['total'] ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon entree"><i class="bi bi-arrow-down-left"></i></div>
            <div class="stat-info">
                <div class="label">Total entrées</div>
                <div class="value" id="statEntrees" style="color:var(--suc);"><?= fmt($initialData['total_entrees']) ?> F</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon sortie"><i class="bi bi-arrow-up-right"></i></div>
            <div class="stat-info">
                <div class="label">Total sorties</div>
                <div class="value" id="statSorties" style="color:var(--dng);"><?= fmt($initialData['total_sorties']) ?> F</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon total"><i class="bi bi-calculator"></i></div>
            <div class="stat-info">
                <div class="label">Mouvement net</div>
                <div class="value" id="statNet"><?= fmt($initialData['total_entrees'] - $initialData['total_sorties']) ?> F</div>
            </div>
        </div>
    </div>

    <!-- Filtres -->
    <form id="searchForm" class="pbar">
        <input type="hidden" name="ajax" value="1">
        <div class="prow mb-2">
            <label><i class="bi bi-search"></i> Rechercher</label>
            <input type="text" name="search" id="searchInput" placeholder="N°, objet, contact, caisse, référence...">
            <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
            <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
        </div>
        <div class="prow">
            <label>Type</label>
            <select name="type" id="typeFilter" class="selectpicker" data-live-search="true">
                <option value="">Tous</option>
                <?php foreach ($types_transaction as $t): ?>
                    <option value="<?= e($t) ?>"><?= $t === 'Entree' ? 'Entrée' : 'Sortie' ?></option>
                <?php endforeach; ?>
            </select>

            <label>Mode</label>
            <select name="mode_reglement" id="modeFilter" class="selectpicker" data-live-search="true">
                <option value="">Tous</option>
                <?php foreach ($modes_reglement as $m): ?>
                    <option value="<?= e($m) ?>"><?= e($m) ?></option>
                <?php endforeach; ?>
            </select>

            <label>État</label>
            <select name="etat" id="etatFilter" class="selectpicker" data-live-search="true">
                <option value="">Tous</option>
                <?php foreach ($etats_transaction as $e): ?>
                    <option value="<?= e($e) ?>"><?= e($e) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Caisse</label>
            <select name="caisse" id="caisseFilter" class="selectpicker" data-live-search="true">
                <option value="">Toutes</option>
                <?php foreach ($caisses as $c): ?>
                    <option value="<?= e($c['caisse_id']) ?>"><?= e($c['nom_caisse']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Boutique</label>
            <select name="boutique" id="boutiqueFilter" class="selectpicker" data-live-search="true">
                <option value="">Toutes</option>
                <?php foreach ($boutiquesFiltre as $b): ?>
                    <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Utilisateur</label>
            <select name="utilisateur" id="utilisateurFilter" class="selectpicker" data-live-search="true">
                <option value="">Tous</option>
                <?php foreach ($utilisateurs as $u): ?>
                    <option value="<?= e($u['id']) ?>"><?= e($u['nom_prenom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="prow mt-2">
            <label><i class="bi bi-calendar-range"></i> Du</label>
            <input type="date" name="date_debut" id="dateDebut">
            <label>Au</label>
            <input type="date" name="date_fin" id="dateFin">
            <label>Objet</label>
            <input type="text" name="objet" id="objetFilter" placeholder="Rechercher dans l'objet..." style="flex:1;min-width:180px;">
        </div>
    </form>

    <!-- Tableau -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">
                <i class="bi bi-list-ul text-primary me-2"></i>Liste des transactions
            </h5>
            <span class="text-muted small" id="totalCount">
                <?= $initialData['total'] ?> transaction(s) - Page <?= $initialData['page'] ?> / <?= max(1, $initialData['totalPages']) ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>N° Transaction</th>
                        <th>Date</th>
                        <th>Heure</th>
                        <th>Type</th>
                        <th class="text-end">Montant</th>
                        <th>Mode / N°</th>
                        <th>Contact / Objet</th>
                        <th>Caisse</th>
                        <th>Utilisateur</th>
                        <th>État</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody"><?= $initialData['table'] ?></tbody>
            </table>
        </div>
        <div id="paginationContainer"><?= $initialData['pagination'] ?></div>
    </div>
</div>

<!-- Modal Détail -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt text-primary me-2"></i>Détail de la transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailContent">
                <div class="text-center py-4"><i class="bi bi-arrow-repeat spin"></i> Chargement...</div>
            </div>
            <div class="modal-footer" style="position: relative;">
                <button type="button" class="btn btn-outline-secondary rounded-3" id="btnImprimerRecu"><i class="bi bi-printer"></i> Imprimer</button>
                <button type="button" class="btn btn-outline-success rounded-3" id="btnPartagerRecu"><i class="bi bi-whatsapp"></i> Partager</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal annulation d'une transaction (motif obligatoire = trace) -->
<div class="modal fade" id="annulerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body p-4">
                <div class="text-center mb-3">
                    <i class="bi bi-arrow-counterclockwise text-danger" style="font-size:2.5rem;"></i>
                    <h5 class="mt-2 mb-1 fw-bold">Annuler la transaction</h5>
                    <p class="text-muted small mb-0">
                        <strong id="annulerNumero"></strong><br>
                        Le solde de caisse, le solde du contact et les factures liées seront
                        réajustés automatiquement.
                    </p>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Motif de l'annulation <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="annulerMotif" rows="2" placeholder="Ex. : mauvais montant saisi, mauvaise facture sélectionnée..."></textarea>
                </div>
                <div id="annulerErreur" class="alert alert-danger py-2 small mb-3" style="display:none;"></div>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Retour</button>
                    <button type="button" class="btn btn-danger rounded-3" id="confirmAnnulerBtn">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Confirmer l'annulation
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast (remplace alert()) -->
<div class="toast-msg" id="toastMsg"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
// Toast — remplace alert() (même composant que vente_comptoir)
function toast(msg, type = 'success') {
    const t = document.getElementById('toastMsg');
    t.textContent = msg;
    t.className = 'toast-msg show' + (type === 'error' ? ' error' : '');
    setTimeout(() => t.classList.remove('show'), 2500);
}

$(document).ready(function() {
    $('.selectpicker').selectpicker();
    const detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
    const annulerModal = new bootstrap.Modal(document.getElementById('annulerModal'));
    const CSRF_TOKEN_TR = <?= json_encode($csrf_token) ?>;
    const baseUrlTr = window.location.pathname;
    let numeroATransaction = null;

    // Recherche AJAX (tout en POST)
    function rechercher(page) {
        page = page || 1;
        var formData = $('#searchForm').serialize() + '&page=' + page;
        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(data) {
                $('#tableBody').html(data.table);
                $('#paginationContainer').html(data.pagination);
                $('#totalCount').text(data.total + ' transaction(s) - Page ' + data.page + ' / ' + Math.max(1, data.totalPages));
                // Mise à jour des stats
                $('#statTotal').text(data.total);
                $('#statEntrees').text(fmt(data.total_entrees) + ' F');
                $('#statSorties').text(fmt(data.total_sorties) + ' F');
                $('#statNet').text(fmt(data.total_entrees - data.total_sorties) + ' F');
            },
            error: function(xhr) { console.error(xhr.responseText); }
        });
    }

    // Formatage
    function fmt(n) {
        return Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    // Événements filtres
    var searchTimeout = null;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 400);
    });

    $('#typeFilter, #modeFilter, #etatFilter, #caisseFilter, #boutiqueFilter, #utilisateurFilter').on('changed.bs.select', function() {
        rechercher(1);
    });

    $('#dateDebut, #dateFin, #objetFilter').on('change input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { rechercher(1); }, 400);
    });

    $('#filterBtn').on('click', function() { rechercher(1); });

    $('#resetBtn').on('click', function() {
        $('#searchForm')[0].reset();
        $('.selectpicker').selectpicker('val', '');
        rechercher(1);
    });

    // Pagination
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        if (page && page >= 1) rechercher(page);
    });

    // Voir détail
    $(document).on('click', '.act-btn.view', function() {
        const code = $(this).data('code');
        $('#detailContent').html('<div class="text-center py-4"><i class="bi bi-arrow-repeat"></i> Chargement...</div>');
        detailModal.show();
        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            data: { action: 'get_detail', numero: code },
            dataType: 'json',
            success: function(data) {
                if (!data.success) {
                    $('#detailContent').html('<div class="alert alert-danger">' + (data.message || 'Transaction introuvable.') + '</div>');
                    return;
                }
                const t = data.transaction;
                $('#detailContent')
                    .data('numero-transaction', t.numero_transaction)
                    .data('contact-name', t.nom_prenom_contact || '')
                    .data('objet-transaction', t.objet_transaction || '');
                const ligne = (label, valeur) => valeur ? `
                    <tr><td class="text-muted" style="width:40%;">${label}</td><td class="fw-semibold">${valeur}</td></tr>
                ` : '';
                const badgeType = t.type_transaction === 'Entree'
                    ? '<span class="badge bg-success-subtle text-success">Entrée</span>'
                    : '<span class="badge bg-danger-subtle text-danger">Sortie</span>';
                const badgeEtat = t.etat_transaction === 'Succes'
                    ? '<span class="badge bg-success-subtle text-success">Succès</span>'
                    : (t.etat_transaction === 'Echec'
                        ? '<span class="badge bg-danger-subtle text-danger">Échec</span>'
                        : (t.etat_transaction === 'Annulee'
                            ? '<span class="badge bg-secondary-subtle text-secondary">Annulée</span>'
                            : '<span class="badge bg-warning-subtle text-warning">En attente</span>'));
                $('#detailContent').html(`
                    <div class="alert alert-info d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-info-circle"></i> Transaction : <strong>${t.numero_transaction}</strong></span>
                        <span>${badgeType} ${badgeEtat}</span>
                    </div>
                    ${t.etat_transaction === 'Annulee' ? `
                    <div class="alert alert-secondary">
                        <i class="bi bi-arrow-counterclockwise"></i> Annulée par <strong>${t.annule_par || '—'}</strong> le ${t.date_annulation || '—'}<br>
                        Motif : ${t.motif_annulation || '—'}
                    </div>` : ''}
                    <table class="table table-sm">
                        <tbody>
                            ${ligne('Date', (t.date_transaction || '') + ' ' + (t.heure_transaction || ''))}
                            ${ligne('Montant', fmt(t.montant_transaction) + ' F')}
                            ${ligne('Frais', t.frais_transaction && t.frais_transaction != 0 ? fmt(t.frais_transaction) + ' F' : '')}
                            ${ligne('Montant total', fmt(t.montant_total) + ' F')}
                            ${ligne('Objet', t.objet_transaction)}
                            ${ligne('Mode de règlement', t.mode_reglement)}
                            ${ligne('N° règlement', t.numero_reglement)}
                            ${ligne('Référence', t.reference_reglement)}
                            ${ligne('Caisse', t.nom_caisse)}
                            ${ligne('Facture liée', t.facture_id ? (t.facture_id + (t.titre_facture ? ' — ' + t.titre_facture : '')) : '')}
                            ${ligne('Client / Fournisseur', t.nom_prenom_contact ? (t.nom_prenom_contact + (t.telephone_contact ? ' (' + t.telephone_contact + ')' : '')) : '')}
                            ${ligne('Enregistré par', t.utilisateur_nom)}
                        </tbody>
                    </table>
                `);
            },
            error: function() {
                $('#detailContent').html('<div class="alert alert-danger">Erreur lors du chargement du détail.</div>');
            }
        });
    });

    // Annuler une transaction (motif obligatoire = trace)
    $(document).on('click', '.btn-annuler-transaction', function() {
        numeroATransaction = $(this).data('code');
        $('#annulerNumero').text(numeroATransaction);
        $('#annulerMotif').val('');
        $('#annulerErreur').hide().text('');
        annulerModal.show();
    });

    $('#confirmAnnulerBtn').on('click', function() {
        const motif = $('#annulerMotif').val().trim();
        if (!motif) {
            $('#annulerErreur').text("Le motif est obligatoire.").show();
            return;
        }
        const btn = $(this);
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Annulation...');
        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            data: {
                action: 'annuler_transaction',
                numero: numeroATransaction,
                motif: motif,
                csrf_token: <?= json_encode($csrf_token) ?>
            },
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="bi bi-arrow-counterclockwise me-1"></i> Confirmer l\'annulation');
                if (resp.success) {
                    annulerModal.hide();
                    var msg = resp.message || 'Transaction annulée.';
                    if (resp.warning) msg += ' ' + resp.warning;
                    toast(msg);
                    rechercher(1);
                } else {
                    $('#annulerErreur').text(resp.message || 'Erreur inconnue.').show();
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="bi bi-arrow-counterclockwise me-1"></i> Confirmer l\'annulation');
                $('#annulerErreur').text('Erreur de communication avec le serveur.').show();
            }
        });
    });

    // ============================================================
    // IMPRIMER / PARTAGER LE REÇU (même mécanisme hybride que pour
    // les bons de commande : partage natif si disponible, sinon menu
    // de repli WhatsApp/Email/Telegram/Télécharger).
    // ============================================================
    function submitRecuPdf(numero, mode) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = baseUrlTr;
        form.target = '_self';
        const champs = { action: 'pdf', numero: numero, mode: mode, csrf_token: CSRF_TOKEN_TR };
        Object.keys(champs).forEach(function(k) {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = k; input.value = champs[k];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
        form.remove();
    }

    $('#btnImprimerRecu').click(function() {
        const numero = $('#detailContent').data('numero-transaction');
        if (!numero) return;
        submitRecuPdf(numero, 'I');
    });

    function fermerMenuPartageRecu() {
        $('#menuPartageRecuRepli').remove();
        $(document).off('click.menuPartageRecu');
    }

    function ouvrirMenuPartageRecu(btn, blob, numero, nomContact, messageTexte) {
        fermerMenuPartageRecu();
        const url = URL.createObjectURL(blob);
        const nomFichier = 'recu-' + numero + '.pdf';
        const declencherTelechargement = function() {
            const a = document.createElement('a');
            a.href = url; a.download = nomFichier;
            document.body.appendChild(a); a.click(); a.remove();
        };
        const $menu = $(`
            <div id="menuPartageRecuRepli" style="position:absolute; bottom:100%; right:0; margin-bottom:8px; background:#fff; border-radius:12px; box-shadow:0 10px 30px rgba(15,23,42,.2); padding:8px; z-index:2000; min-width:220px;">
                <div class="small text-muted px-2 pb-1" style="font-size:11px;">Le PDF sera téléchargé, à joindre au message</div>
                <button type="button" class="dropdown-item-partage-recu" data-canal="whatsapp" style="display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;padding:8px;border-radius:8px;text-align:left;"><i class="bi bi-whatsapp" style="color:#25D366;"></i> WhatsApp</button>
                <button type="button" class="dropdown-item-partage-recu" data-canal="email" style="display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;padding:8px;border-radius:8px;text-align:left;"><i class="bi bi-envelope-fill" style="color:#3b82f6;"></i> Email</button>
                <button type="button" class="dropdown-item-partage-recu" data-canal="telegram" style="display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;padding:8px;border-radius:8px;text-align:left;"><i class="bi bi-telegram" style="color:#229ED9;"></i> Telegram</button>
                <button type="button" class="dropdown-item-partage-recu" data-canal="telecharger" style="display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;padding:8px;border-radius:8px;text-align:left;"><i class="bi bi-download" style="color:#64748b;"></i> Télécharger seulement</button>
            </div>
        `);
        $menu.find('.dropdown-item-partage-recu').on('mouseenter', function() { $(this).css('background', '#f1f5f9'); }).on('mouseleave', function() { $(this).css('background', 'none'); });
        $menu.on('click', '.dropdown-item-partage-recu', function() {
            const canal = $(this).data('canal');
            declencherTelechargement();
            if (canal === 'whatsapp') {
                window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(messageTexte), '_blank');
            } else if (canal === 'email') {
                window.open('mailto:?subject=' + encodeURIComponent('Reçu N°' + numero) + '&body=' + encodeURIComponent(messageTexte), '_blank');
            } else if (canal === 'telegram') {
                window.open('https://t.me/share/url?url=&text=' + encodeURIComponent(messageTexte), '_blank');
            }
            fermerMenuPartageRecu();
        });
        btn.parent().css('position', 'relative').append($menu);
        setTimeout(function() {
            $(document).on('click.menuPartageRecu', function(e) {
                if (!$(e.target).closest('#menuPartageRecuRepli, #btnPartagerRecu').length) fermerMenuPartageRecu();
            });
        }, 0);
    }

    $('#btnPartagerRecu').click(async function() {
        const numero = $('#detailContent').data('numero-transaction');
        if (!numero) return;
        const nomContact = $('#detailContent').data('contact-name') || '';
        const objet = $('#detailContent').data('objet-transaction') || '';
        const estTicket = objet === 'Vente comptoir';
        const messageTexte = 'Bonjour' + (nomContact ? ' ' + nomContact : '') + ', voici ' + (estTicket ? 'votre ticket' : 'votre reçu de paiement') + ' N°' + numero + '. Le fichier PDF est joint.';
        const btn = $(this);
        const originalHtml = btn.html();
        fermerMenuPartageRecu();
        const params = new URLSearchParams({ action: 'pdf', numero: numero, csrf_token: CSRF_TOKEN_TR });

        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Préparation...');
        let blob;
        try {
            const resp = await fetch(baseUrlTr, { method: 'POST', body: params, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            blob = await resp.blob();
            if (!resp.ok || (blob.type && blob.type.indexOf('pdf') === -1)) {
                let detail = '';
                try { detail = (await blob.text()); } catch (e2) {}
                console.error('Échec de préparation du reçu (partage) — statut HTTP', resp.status, '— réponse :', detail.slice(0, 300));
                throw new Error('pdf_invalide');
            }
        } catch (e) {
            btn.prop('disabled', false).html(originalHtml);
            toast('Impossible de préparer le reçu pour le partage.', 'error');
            return;
        }

        let raisonRepli = null;
        if (!window.isSecureContext) raisonRepli = 'HTTPS requis pour le partage natif';
        else if (!navigator.share) raisonRepli = 'navigateur non compatible avec le partage natif';
        else if (!navigator.canShare) raisonRepli = 'partage de fichiers non supporté';

        if (!raisonRepli) {
            const file = new File([blob], 'recu-' + numero + '.pdf', { type: 'application/pdf' });
            if (navigator.canShare({ files: [file] })) {
                try {
                    await navigator.share({ files: [file], title: 'Reçu N°' + numero, text: messageTexte });
                    btn.prop('disabled', false).html(originalHtml);
                    return;
                } catch (e) {
                    btn.prop('disabled', false).html(originalHtml);
                    if (e && e.name === 'AbortError') return;
                    raisonRepli = 'le partage natif a échoué';
                }
            } else {
                raisonRepli = 'cet appareil ne peut pas partager de fichier PDF';
            }
        }

        btn.prop('disabled', false).html(originalHtml);
        console.warn('Partage natif indisponible (' + raisonRepli + '), affichage des options de repli');
        ouvrirMenuPartageRecu(btn, blob, numero, nomContact, messageTexte);
    });
});
</script>
</body>
</html>