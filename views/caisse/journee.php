<?php
ob_start();
require __DIR__ . '/../../databases/database.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../utilisateur/login'); exit; }

$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { session_destroy(); header('Location: ../utilisateur/login'); exit; }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDec($n) { return number_format((float)$n, 0, ',', ' '); }

function generateJourneeId($pdo) {
    return 'JC-' . date('YmdHis') . rand(100, 999);
}

function recalculerJournee($pdo, $j) {
    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN type_transaction='Entree' THEN montant_total ELSE 0 END), 0) as total_entrees,
        COALESCE(SUM(CASE WHEN type_transaction='Sortie' THEN montant_total ELSE 0 END), 0) as total_sorties,
        COUNT(*) as nombre_transactions
        FROM transaction
        WHERE caisse_id = ? AND date_transaction = ? AND etat_transaction = 'Succes'");
    $stmt->execute([$j['caisse_id'], $j['date_journee']]);
    $tot = $stmt->fetch(PDO::FETCH_ASSOC);
    $solde_theorique = floatval($j['solde_ouverture']) + floatval($tot['total_entrees']) - floatval($tot['total_sorties']);
    return [
        'solde_theorique' => $solde_theorique,
        'total_entrees' => floatval($tot['total_entrees']),
        'total_sorties' => floatval($tot['total_sorties']),
        'nombre_transactions' => (int)$tot['nombre_transactions']
    ];
}

$message = '';
$messageType = '';

// ============================================================
// TRAITEMENT OUVERTURE JOURNÉE
// ============================================================
if (isset($_POST['btn_ouvrir'])) {
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== $csrf_token) {
        $message = "Token invalide."; $messageType = 'danger';
    } else {
        $caisse_id = $_POST['caisse_id'] ?? '';
        $solde_ouverture = floatval(str_replace(',', '.', $_POST['solde_ouverture'] ?? 0));

        if (empty($caisse_id)) {
            $message = "Veuillez sélectionner une caisse."; $messageType = 'warning';
        } else {
            try {
                // Vérifier qu'aucune journée n'est ouverte
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'OUVERTE'");
                $stmt->execute([$caisse_id]);
                if ($stmt->fetchColumn() > 0) {
                    $message = "Une journée est déjà ouverte sur cette caisse."; $messageType = 'warning';
                } else {
                    // Récupérer la caisse
                    $stmt = $pdo->prepare("SELECT * FROM caisse WHERE caisse_id = ?");
                    $stmt->execute([$caisse_id]);
                    $c = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$c) throw new Exception("Caisse introuvable.");

                    $pdo->beginTransaction();
                    $id = generateJourneeId($pdo);
                    $pdo->prepare("INSERT INTO journees_caisse (id, caisse_id, date_journee, date_ouverture, solde_ouverture, total_entrees, total_sorties, nombre_transactions, boutique_id, id_utilisateur_ouverture, statut)
                                   VALUES (?, ?, CURDATE(), NOW(), ?, 0, 0, 0, ?, ?, 'OUVERTE')")
                        ->execute([$id, $caisse_id, $solde_ouverture, $c['boutique_id'], $user['id']]);
                    // Mettre à jour le statut de la caisse
                    $pdo->prepare("UPDATE caisse SET statut = 'Actif' WHERE caisse_id = ?")->execute([$caisse_id]);
                    $pdo->commit();
                    $message = "Caisse « {$c['nom_caisse']} » ouverte avec un fonds de départ de " . fmtDec($solde_ouverture) . " F.";
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Erreur : " . $e->getMessage(); $messageType = 'danger';
            }
        }
    }
}

// ============================================================
// TRAITEMENT FERMETURE JOURNÉE
// ============================================================
if (isset($_POST['btn_fermer'])) {
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== $csrf_token) {
        $message = "Token invalide."; $messageType = 'danger';
    } else {
        $journee_id = $_POST['journee_id'] ?? '';
        $solde_physique = floatval(str_replace(',', '.', $_POST['solde_physique'] ?? 0));
        $observations = trim($_POST['observations'] ?? '');

        try {
            $stmt = $pdo->prepare("SELECT jc.*, c.nom_caisse FROM journees_caisse jc JOIN caisse c ON c.caisse_id = jc.caisse_id WHERE jc.id = ?");
            $stmt->execute([$journee_id]);
            $j = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$j) throw new Exception("Journée introuvable.");
            if ($j['statut'] === 'FERMEE') throw new Exception("Déjà fermée.");

            $j['date_fermeture'] = null;
            $tot = recalculerJournee($pdo, $j);
            $ecart = $solde_physique - $tot['solde_theorique'];

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE journees_caisse SET date_fermeture = NOW(), solde_theorique = ?, solde_physique = ?, ecart_solde = ?, total_entrees = ?, total_sorties = ?, nombre_transactions = ?, id_utilisateur_fermeture = ?, observations = ?, statut = 'FERMEE' WHERE id = ?")
                ->execute([$tot['solde_theorique'], $solde_physique, $ecart, $tot['total_entrees'], $tot['total_sorties'], $tot['nombre_transactions'], $user['id'], $observations, $journee_id]);
            $pdo->prepare("UPDATE caisse SET solde = ? WHERE caisse_id = ?")->execute([$solde_physique, $j['caisse_id']]);
            $pdo->commit();
            $message = "Caisse fermée. Théorique : " . fmtDec($tot['solde_theorique']) . " F | Physique : " . fmtDec($solde_physique) . " F | Écart : " . fmtDec($ecart) . " F";
            $messageType = 'success';
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Erreur : " . $e->getMessage(); $messageType = 'danger';
        }
    }
}

// ============================================================
// DONNÉES POUR L'AFFICHAGE
// ============================================================
// Caisses disponibles (non fermées)
$caissesDisponibles = $pdo->query("SELECT c.*, b.nom_boutique FROM caisse c LEFT JOIN boutique b ON b.code_boutique = c.boutique_id WHERE c.statut != 'Inactif' ORDER BY c.nom_caisse")->fetchAll(PDO::FETCH_ASSOC);

// Journées ouvertes
$stmt = $pdo->query("SELECT jc.*, c.nom_caisse, c.caisse_id as code_caisse, u.nom_prenom AS ouvert_par
                     FROM journees_caisse jc
                     JOIN caisse c ON c.caisse_id = jc.caisse_id
                     LEFT JOIN utilisateur u ON u.id = jc.id_utilisateur_ouverture
                     WHERE jc.statut = 'OUVERTE'
                     ORDER BY jc.date_ouverture DESC");
$journeesOuvertes = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($journeesOuvertes as &$j) { $j = array_merge($j, recalculerJournee($pdo, $j)); }
unset($j);

// Historique des journées fermées
$stmt = $pdo->query("SELECT jc.*, c.nom_caisse, uo.nom_prenom AS ouvert_par, uf.nom_prenom AS ferme_par
                     FROM journees_caisse jc
                     JOIN caisse c ON c.caisse_id = jc.caisse_id
                     LEFT JOIN utilisateur uo ON uo.id = jc.id_utilisateur_ouverture
                     LEFT JOIN utilisateur uf ON uf.id = jc.id_utilisateur_fermeture
                     WHERE jc.statut = 'FERMEE'
                     ORDER BY jc.date_fermeture DESC LIMIT 30");
$historique = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journées de caisse</title>
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
        .W { max-width: 1400px; margin: 0 auto; }
        .hdr { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--dk); letter-spacing: -0.02em; font-family: 'Outfit', sans-serif; }
        .hdr-l p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
        .btn-go { background: var(--b); color: #fff; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; border: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all .2s; }
        .btn-go:hover { background: var(--bd); color: #fff; }
        .card-custom { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 20px; margin-bottom: 22px; }
        .card-custom h3 { font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .status-active { background: var(--sucl); color: var(--suc); border: 1px solid var(--sucb); }
        .status-warn { background: var(--wrnl); color: var(--wrn); border: 1px solid var(--wrnb); }
        .status-inactive { background: var(--bg); color: var(--lt); border: 1px solid var(--brd); }
        table { margin: 0; font-size: 13px; }
        table thead th { background: var(--bg); color: var(--mt); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 12px 10px; border-bottom: 1px solid var(--brd); }
        table tbody td { padding: 12px 10px; border-bottom: 1px solid var(--brd); vertical-align: middle; }
        table tbody tr:hover { background: var(--bl); }
        .td-bold { color: var(--dk) !important; font-weight: 700; }
        .ecart-pos { color: var(--suc); font-weight: 700; }
        .ecart-neg { color: var(--dng); font-weight: 700; }
        .ecart-zero { color: var(--mt); font-weight: 700; }
        .form-control, .form-select { padding: 9px 12px; border: 1.5px solid var(--brd); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--dk); font-family: 'Inter', sans-serif; }
        .form-control:focus, .form-select:focus { border-color: var(--b); background: #fff; box-shadow: 0 0 0 3px var(--bl); outline: none; }
        .form-label { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .03em; }
        .btn-close-custom { background: transparent; border: 1px solid var(--brd); color: var(--mt); padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .btn-close-custom:hover { background: var(--bg); }
    </style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div class="hdr-l">
            <h1><i class="bi bi-calendar-check text-primary me-2"></i>Journées de caisse</h1>
            <p>Ouverture et fermeture des caisses — <?= date('d/m/Y') ?></p>
        </div>
        <div>
            <span class="status-badge status-active"><i class="bi bi-person"></i> <?= e($user['nom_prenom']) ?></span>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <?= $message ?>
        <button class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- OUVERTURE -->
    <div class="card-custom">
        <h3><i class="bi bi-unlock text-success"></i> Ouvrir une journée</h3>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <div class="col-md-5">
                <label class="form-label">Caisse</label>
                <select name="caisse_id" class="form-select selectpicker" data-live-search="true" required>
                    <option value="">-- Sélectionner une caisse --</option>
                    <?php foreach ($caissesDisponibles as $c): ?>
                        <option value="<?= e($c['caisse_id']) ?>"><?= e($c['nom_caisse']) ?> — Solde: <?= fmtDec($c['solde']) ?> F</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Fonds de départ (F)</label>
                <input type="number" step="0.01" name="solde_ouverture" class="form-control" value="0" required>
            </div>
            <div class="col-md-4">
                <button type="submit" name="btn_ouvrir" class="btn-go w-100"><i class="bi bi-play-circle"></i> Ouvrir la journée</button>
            </div>
        </form>
    </div>

    <!-- JOURNÉES OUVERTES -->
    <div class="card-custom">
        <h3><i class="bi bi-clock-history text-primary"></i> Journées en cours</h3>
        <?php if (empty($journeesOuvertes)): ?>
            <p class="text-muted text-center py-3">Aucune journée ouverte</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Caisse</th>
                        <th>Date</th>
                        <th>Ouverture</th>
                        <th class="text-end">Solde ouv.</th>
                        <th class="text-end">Entrées</th>
                        <th class="text-end">Sorties</th>
                        <th class="text-end">Théorique</th>
                        <th>Ouvert par</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($journeesOuvertes as $j): ?>
                    <tr>
                        <td class="td-bold"><?= e($j['nom_caisse']) ?></td>
                        <td><?= date('d/m/Y', strtotime($j['date_journee'])) ?></td>
                        <td><?= date('H:i', strtotime($j['date_ouverture'])) ?></td>
                        <td class="text-end"><?= fmtDec($j['solde_ouverture']) ?> F</td>
                        <td class="text-end text-success"><?= fmtDec($j['total_entrees']) ?> F</td>
                        <td class="text-end text-danger"><?= fmtDec($j['total_sorties']) ?> F</td>
                        <td class="text-end td-bold"><?= fmtDec($j['solde_theorique']) ?> F</td>
                        <td><?= e($j['ouvert_par'] ?? '—') ?></td>
                        <td class="text-end">
                            <button class="btn-go" data-bs-toggle="modal" data-bs-target="#fermerModal<?= e($j['id']) ?>"><i class="bi bi-lock"></i> Fermer</button>
                        </td>
                    </tr>

                    <!-- Modal Fermeture -->
                    <div class="modal fade" id="fermerModal<?= e($j['id']) ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content" style="border-radius:var(--R); border:none;">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="journee_id" value="<?= e($j['id']) ?>">
                                    <div class="modal-header" style="background:var(--bg); border-bottom:1px solid var(--brd);">
                                        <h5 class="modal-title"><i class="bi bi-lock text-warning me-2"></i>Fermer la journée</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="alert alert-info" style="font-size:13px;">
                                            <strong>Caisse :</strong> <?= e($j['nom_caisse']) ?><br>
                                            <strong>Solde théorique calculé :</strong> <?= fmtDec($j['solde_theorique']) ?> F
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Solde physique compté (F) *</label>
                                            <input type="number" step="0.01" name="solde_physique" class="form-control" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Observations</label>
                                            <textarea name="observations" class="form-control" rows="3"></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer" style="background:var(--bg); border-top:1px solid var(--brd);">
                                        <button type="button" class="btn-close-custom" data-bs-dismiss="modal">Annuler</button>
                                        <button type="submit" name="btn_fermer" class="btn-go"><i class="bi bi-check-lg"></i> Confirmer la fermeture</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- HISTORIQUE -->
    <div class="card-custom">
        <h3><i class="bi bi-journal-text text-secondary"></i> Historique (30 dernières)</h3>
        <?php if (empty($historique)): ?>
            <p class="text-muted text-center py-3">Aucune journée clôturée pour le moment.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Caisse</th>
                        <th>Date</th>
                        <th>Ouverture</th>
                        <th>Fermeture</th>
                        <th class="text-end">Théorique</th>
                        <th class="text-end">Physique</th>
                        <th class="text-end">Écart</th>
                        <th>Ouvert par</th>
                        <th>Fermé par</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($historique as $h):
                    $ecartClass = $h['ecart_solde'] > 0 ? 'ecart-pos' : ($h['ecart_solde'] < 0 ? 'ecart-neg' : 'ecart-zero');
                ?>
                    <tr>
                        <td class="td-bold"><?= e($h['nom_caisse']) ?></td>
                        <td><?= date('d/m/Y', strtotime($h['date_journee'])) ?></td>
                        <td><?= date('H:i', strtotime($h['date_ouverture'])) ?></td>
                        <td><?= $h['date_fermeture'] ? date('H:i', strtotime($h['date_fermeture'])) : '—' ?></td>
                        <td class="text-end"><?= fmtDec($h['solde_theorique']) ?> F</td>
                        <td class="text-end"><?= fmtDec($h['solde_physique']) ?> F</td>
                        <td class="text-end <?= $ecartClass ?>"><?= fmtDec($h['ecart_solde']) ?> F</td>
                        <td><?= e($h['ouvert_par'] ?? '—') ?></td>
                        <td><?= e($h['ferme_par'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(function(){
    $('.selectpicker').selectpicker();
    setTimeout(()=>$('.alert').alert('close'), 5000);
});
</script>
</body>
</html>