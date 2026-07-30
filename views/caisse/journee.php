<?php
// views/caisse/journee.php - Ouverture / fermeture de caisse (table `journees_caisse`)
require __DIR__ . '/../../databases/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}
$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { session_destroy(); header('Location: ../utilisateur/login'); exit; }

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDec($n) { return number_format(floatval($n), 2, ',', ' '); }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

/**
 * Recalcule les totaux (entrées/sorties/nb transactions/solde théorique)
 * d'une journée de caisse à partir de la table `transaction`.
 * C'est la SEULE table qui trace les entrées/sorties : on ne fait
 * confiance à aucun autre compteur.
 */
function recalculerJournee($pdo, $journee)
{
    $stmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN type_transaction = 'Entree' THEN montant_total ELSE 0 END), 0) AS total_entrees,
            COALESCE(SUM(CASE WHEN type_transaction = 'Sortie' THEN montant_total ELSE 0 END), 0) AS total_sorties,
            COUNT(*) AS nb
        FROM `transaction`
        WHERE caisse_id = ?
          AND etat_transaction = 'Succes'
          AND date_transaction >= ?
          AND (? IS NULL OR date_transaction <= ?)");
    $dateFin = $journee['date_fermeture'] ? substr($journee['date_fermeture'], 0, 10) : null;
    $stmt->execute([$journee['caisse_id'], substr($journee['date_ouverture'], 0, 10), $dateFin, $dateFin]);
    $tot = $stmt->fetch(PDO::FETCH_ASSOC);

    $soldeTheorique = floatval($journee['solde_ouverture']) + floatval($tot['total_entrees']) - floatval($tot['total_sorties']);

    return [
        'total_entrees' => $tot['total_entrees'],
        'total_sorties' => $tot['total_sorties'],
        'nombre_transactions' => $tot['nb'],
        'solde_theorique' => $soldeTheorique
    ];
}

$message = '';
$messageType = '';

// ------------------------------------------------------------------
// OUVERTURE D'UNE CAISSE
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ouvrir') {
    if (($_POST['csrf_token'] ?? '') !== $csrf_token) {
        $message = "Token de sécurité invalide."; $messageType = 'danger';
    } else {
        $caisse_id = trim($_POST['caisse_id'] ?? '');
        $solde_ouverture = floatval(str_replace(',', '.', $_POST['solde_ouverture'] ?? 0));
        if (empty($caisse_id)) {
            $message = "Veuillez choisir une caisse."; $messageType = 'warning';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM caisses WHERE caisse_id = ? FOR UPDATE");
                $stmt->execute([$caisse_id]);
                $c = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$c) throw new Exception("Caisse introuvable.");
                if ($c['statut'] === 'ouverte') throw new Exception("Cette caisse a déjà une journée ouverte.");

                $id = 'JC-' . date('YmdHis') . rand(100, 999);
                $pdo->prepare("INSERT INTO journees_caisse
                        (id, caisse_id, date_journee, date_ouverture, solde_ouverture, total_entrees, total_sorties, nombre_transactions,
                         boutique_id, id_utilisateur_ouverture, statut)
                    VALUES (?, ?, CURDATE(), NOW(), ?, 0, 0, 0, ?, ?, 'ouverte')")
                    ->execute([$id, $caisse_id, $solde_ouverture, $c['boutique_id'], $user['id']]);

                $pdo->prepare("UPDATE caisses SET statut = 'ouverte', solde_actuel = ? WHERE caisse_id = ?")
                    ->execute([$solde_ouverture, $caisse_id]);

                $pdo->commit();
                $message = "Caisse « {$c['nom_caisse']} » ouverte avec un fonds de départ de " . fmtDec($solde_ouverture) . " F.";
                $messageType = 'success';
            } catch (Exception $ex) {
                $pdo->rollBack();
                $message = "Erreur : " . $ex->getMessage(); $messageType = 'danger';
            }
        }
    }
}

// ------------------------------------------------------------------
// FERMETURE D'UNE CAISSE
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fermer') {
    if (($_POST['csrf_token'] ?? '') !== $csrf_token) {
        $message = "Token de sécurité invalide."; $messageType = 'danger';
    } else {
        $journee_id = trim($_POST['journee_id'] ?? '');
        $solde_physique = floatval(str_replace(',', '.', $_POST['solde_physique'] ?? 0));
        $observations = trim($_POST['observations'] ?? '');
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM journees_caisse WHERE id = ? FOR UPDATE");
            $stmt->execute([$journee_id]);
            $j = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$j) throw new Exception("Journée introuvable.");
            if ($j['statut'] === 'fermee') throw new Exception("Cette journée est déjà fermée.");

            $j['date_fermeture'] = null; // on calcule les totaux jusqu'à maintenant
            $tot = recalculerJournee($pdo, $j);
            $ecart = $solde_physique - $tot['solde_theorique'];

            $pdo->prepare("UPDATE journees_caisse SET
                    date_fermeture = NOW(), solde_theorique = ?, solde_physique = ?, ecart_solde = ?,
                    total_entrees = ?, total_sorties = ?, nombre_transactions = ?,
                    id_utilisateur_fermeture = ?, observations = ?, statut = 'fermee'
                WHERE id = ?")
                ->execute([
                    $tot['solde_theorique'], $solde_physique, $ecart,
                    $tot['total_entrees'], $tot['total_sorties'], $tot['nombre_transactions'],
                    $user['id'], $observations, $journee_id
                ]);

            $pdo->prepare("UPDATE caisses SET statut = 'fermee', solde_actuel = ? WHERE caisse_id = ?")
                ->execute([$solde_physique, $j['caisse_id']]);

            $pdo->commit();
            $message = "Caisse fermée. Solde théorique : " . fmtDec($tot['solde_theorique']) . " F — Solde compté : " . fmtDec($solde_physique) . " F — Écart : " . fmtDec($ecart) . " F.";
            $messageType = ($ecart == 0) ? 'success' : 'warning';
        } catch (Exception $ex) {
            $pdo->rollBack();
            $message = "Erreur : " . $ex->getMessage(); $messageType = 'danger';
        }
    }
}

// ------------------------------------------------------------------
// DONNÉES POUR L'AFFICHAGE
// ------------------------------------------------------------------
// Caisses fermées (peuvent être ouvertes)
$caissesFermees = $pdo->query("SELECT * FROM caisses WHERE statut = 'fermee' ORDER BY nom_caisse")->fetchAll(PDO::FETCH_ASSOC);

// Journées actuellement ouvertes, avec totaux recalculés en direct
$stmt = $pdo->query("SELECT jc.*, cs.nom_caisse, cs.code_caisse, u.nom_prenom AS ouvert_par
        FROM journees_caisse jc
        JOIN caisses cs ON cs.caisse_id = jc.caisse_id
        LEFT JOIN utilisateur u ON u.id = jc.id_utilisateur_ouverture
        WHERE jc.statut = 'ouverte'
        ORDER BY jc.date_ouverture DESC");
$journeesOuvertes = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($journeesOuvertes as &$j) {
    $j = array_merge($j, recalculerJournee($pdo, $j));
}
unset($j);

// Historique des 30 dernières journées fermées
$stmt = $pdo->query("SELECT jc.*, cs.nom_caisse, uo.nom_prenom AS ouvert_par, uf.nom_prenom AS ferme_par
        FROM journees_caisse jc
        JOIN caisses cs ON cs.caisse_id = jc.caisse_id
        LEFT JOIN utilisateur uo ON uo.id = jc.id_utilisateur_ouverture
        LEFT JOIN utilisateur uf ON uf.id = jc.id_utilisateur_fermeture
        WHERE jc.statut = 'fermee'
        ORDER BY jc.date_fermeture DESC
        LIMIT 30");
$historique = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ouverture / fermeture de caisse</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{background:#f1f5f9;font-family:'Segoe UI',sans-serif;padding:24px;}
.card-app{border:none;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.stat{font-size:22px;font-weight:800;}
</style>
</head>
<body>
<div class="container-fluid" style="max-width:1300px;">
    <h4 class="fw-bold mb-3"><i class="bi bi-door-open text-primary"></i> Ouverture / Fermeture de caisse</h4>

    <?php if ($message): ?>
        <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show"><?= e($message) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- CAISSES OUVERTES -->
    <?php if (empty($journeesOuvertes)): ?>
        <div class="alert alert-info">Aucune caisse n'est actuellement ouverte.</div>
    <?php else: foreach ($journeesOuvertes as $j): ?>
        <div class="card card-app mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="mb-0"><i class="bi bi-cash-register text-success"></i> <?= e($j['nom_caisse']) ?> <span class="badge bg-success ms-2">Ouverte</span></h5>
                        <small class="text-muted">Ouverte le <?= date('d/m/Y H:i', strtotime($j['date_ouverture'])) ?> par <?= e($j['ouvert_par'] ?? '—') ?></small>
                    </div>
                    <button class="btn btn-outline-danger btn-sm fermerBtn"
                        data-journee="<?= e($j['id']) ?>"
                        data-caisse="<?= e($j['nom_caisse']) ?>"
                        data-theorique="<?= e($j['solde_theorique']) ?>">
                        <i class="bi bi-door-closed"></i> Fermer cette caisse
                    </button>
                </div>
                <div class="row text-center g-2">
                    <div class="col-6 col-md-3"><div class="stat"><?= fmtDec($j['solde_ouverture']) ?></div><small class="text-muted">Solde d'ouverture (F)</small></div>
                    <div class="col-6 col-md-3"><div class="stat text-success"><?= fmtDec($j['total_entrees']) ?></div><small class="text-muted">Entrées (F)</small></div>
                    <div class="col-6 col-md-3"><div class="stat text-danger"><?= fmtDec($j['total_sorties']) ?></div><small class="text-muted">Sorties (F)</small></div>
                    <div class="col-6 col-md-3"><div class="stat text-primary"><?= fmtDec($j['solde_theorique']) ?></div><small class="text-muted">Solde théorique (F)</small></div>
                </div>
                <div class="text-center mt-2"><small class="text-muted"><?= (int)$j['nombre_transactions'] ?> transaction(s) enregistrée(s)</small></div>
            </div>
        </div>
    <?php endforeach; endif; ?>

    <!-- OUVRIR UNE CAISSE -->
    <?php if (!empty($caissesFermees)): ?>
    <div class="card card-app mb-4">
        <div class="card-header bg-white fw-bold"><i class="bi bi-door-open"></i> Ouvrir une caisse</div>
        <div class="card-body">
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="ouvrir">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="col-md-5">
                    <label class="form-label">Caisse à ouvrir</label>
                    <select class="form-select" name="caisse_id" required>
                        <option value="">-- Choisir --</option>
                        <?php foreach ($caissesFermees as $c): ?>
                            <option value="<?= e($c['caisse_id']) ?>"><?= e($c['nom_caisse']) ?> (<?= e($c['code_caisse']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fonds de caisse au départ (F)</label>
                    <input type="number" step="0.01" class="form-control" name="solde_ouverture" value="0" required>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-success w-100"><i class="bi bi-check-lg"></i> Ouvrir la caisse</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- HISTORIQUE -->
    <div class="card card-app">
        <div class="card-header bg-white fw-bold"><i class="bi bi-clock-history"></i> Historique des journées de caisse</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Caisse</th><th>Date</th><th>Ouverture</th><th>Fermeture</th>
                        <th class="text-end">Théorique</th><th class="text-end">Physique</th><th class="text-end">Écart</th>
                        <th>Ouvert par</th><th>Fermé par</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($historique)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Aucune journée clôturée pour le moment.</td></tr>
                <?php else: foreach ($historique as $h): ?>
                    <tr>
                        <td><?= e($h['nom_caisse']) ?></td>
                        <td><?= date('d/m/Y', strtotime($h['date_journee'])) ?></td>
                        <td><?= date('H:i', strtotime($h['date_ouverture'])) ?></td>
                        <td><?= $h['date_fermeture'] ? date('H:i', strtotime($h['date_fermeture'])) : '—' ?></td>
                        <td class="text-end"><?= fmtDec($h['solde_theorique']) ?></td>
                        <td class="text-end"><?= fmtDec($h['solde_physique']) ?></td>
                        <td class="text-end <?= $h['ecart_solde'] != 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= fmtDec($h['ecart_solde']) ?></td>
                        <td><?= e($h['ouvert_par'] ?? '—') ?></td>
                        <td><?= e($h['ferme_par'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODALE FERMETURE -->
<div class="modal fade" id="fermerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="fermer">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="journee_id" id="journeeIdField">
                <div class="modal-header">
                    <h5 class="modal-title">Fermer <span id="caisseNomField"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Solde théorique calculé : <strong id="theoriqueField"></strong> F</p>
                    <div class="mb-3">
                        <label class="form-label">Solde physique compté (F) *</label>
                        <input type="number" step="0.01" class="form-control" name="solde_physique" id="solde_physique_input" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Observations</label>
                        <textarea class="form-control" name="observations" rows="2" placeholder="Ex : écart justifié par..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-danger">Confirmer la fermeture</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function(){
    const modal = new bootstrap.Modal(document.getElementById('fermerModal'));
    $('.fermerBtn').on('click', function(){
        $('#journeeIdField').val($(this).data('journee'));
        $('#caisseNomField').text($(this).data('caisse'));
        $('#theoriqueField').text(Number($(this).data('theorique')).toLocaleString('fr-FR', {minimumFractionDigits:2}));
        $('#solde_physique_input').val($(this).data('theorique'));
        modal.show();
    });
    setTimeout(()=>$('.alert').alert('close'), 6000);
});
</script>
</body>
</html>