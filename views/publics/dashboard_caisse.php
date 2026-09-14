<?php
// dashboard_caisse.php – Tableau de bord dédié au rôle Caisse (caissier)

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require dirname(__DIR__, 2) . '/databases/database.php';

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }

define('USER_ID', $_SESSION['user_id']);

$stmtU = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmtU->execute([USER_ID]);
$user = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$user) { session_destroy(); header('Location: ../utilisateur/login'); exit; }

// - CAISSE OUVERTE DANS LA/LES BOUTIQUE(S) DE CET UTILISATEUR -
// Peu importe QUI l'a ouverte (le caissier lui-même, un Superviseur ou un
// Administrateur) : dès qu'une caisse de sa boutique est ouverte, le
// caissier doit pouvoir l'utiliser directement, sans avoir à l'ouvrir
// lui-même. Restreint à sa boutique (et exceptions éventuelles) via
// getBoutiquesAutorisees, pour ne jamais afficher/utiliser par erreur une
// caisse d'une autre boutique.
$boutiquesAutorisees = getBoutiquesAutorisees($pdo, $user['role'] ?? null, $user['boutique_id'] ?? null);
$caisseOuverte = null;
$nbCaissesOuvertes = 0;
if (!empty($boutiquesAutorisees)) {
    $inPhDC = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $stmtCaisse = $pdo->prepare("SELECT DISTINCT c.caisse_id, c.nom_caisse, c.solde, jc.date_ouverture, jc.solde_ouverture, jc.total_entrees, jc.total_sorties, jc.nombre_transactions
        FROM caisse c
        JOIN journees_caisse jc ON jc.caisse_id = c.caisse_id AND jc.statut = 'OUVERTE'
        WHERE c.statut = 'Actif' AND c.boutique_id IN ($inPhDC)
        ORDER BY jc.date_ouverture DESC");
    $stmtCaisse->execute($boutiquesAutorisees);
    $caissesOuvertesListe = $stmtCaisse->fetchAll(PDO::FETCH_ASSOC);
    $nbCaissesOuvertes = count($caissesOuvertesListe);
    $caisseOuverte = $caissesOuvertesListe[0] ?? null;
}

$today = date('Y-m-d');
$encaisseJour = 0; $decaisseJour = 0; $nbTransactionsJour = 0; $dernieresTransactions = [];

if ($caisseOuverte) {
    $caisseId = $caisseOuverte['caisse_id'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CAST(montant_transaction AS DECIMAL(12,2))),0) FROM transaction
        WHERE caisse_id = ? AND type_transaction = 'Entree' AND etat_transaction IN ('Succes','Valide') AND date_transaction = ?");
    $stmt->execute([$caisseId, $today]);
    $encaisseJour = floatval($stmt->fetchColumn());

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CAST(montant_total AS DECIMAL(12,2))),0) FROM transaction
        WHERE caisse_id = ? AND type_transaction = 'Sortie' AND etat_transaction IN ('Succes','Valide') AND date_transaction = ?");
    $stmt->execute([$caisseId, $today]);
    $decaisseJour = floatval($stmt->fetchColumn());

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transaction WHERE caisse_id = ? AND etat_transaction IN ('Succes','Valide') AND date_transaction = ?");
    $stmt->execute([$caisseId, $today]);
    $nbTransactionsJour = intval($stmt->fetchColumn());

    $stmt = $pdo->prepare("SELECT numero_transaction, date_transaction, heure_transaction, montant_transaction, montant_total, type_transaction, objet_transaction, mode_reglement
        FROM transaction WHERE caisse_id = ? AND date_transaction = ? ORDER BY heure_transaction DESC LIMIT 15");
    $stmt->execute([$caisseId, $today]);
    $dernieresTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$soldeNetJour = $encaisseJour - $decaisseJour;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tableau de bord — Caisse</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --b: #2563eb; --bd: #1d4ed8; --bl: #eff6ff; --bb: #bfdbfe;
    --bg: #f1f5f9; --w: #fff; --dk: #0f172a; --mt: #64748b; --lt: #94a3b8; --brd: #e2e8f0;
    --dng: #ef4444; --dngl: #fef2f2; --dngb: #fecaca;
    --suc: #10b981; --sucl: #ecfdf5; --sucb: #a7f3d0;
    --wrn: #f59e0b; --wrnl: #fffbeb; --wrnb: #fde68a;
    --prp: #8b5cf6; --prpl: #f5f3ff;
    --tl: #0891b2; --tll: #ecfeff;
    --R: 16px; --Rs: 10px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--dk); min-height: 100vh; line-height: 1.5; }
.W { max-width: 1100px; margin: 0 auto; padding: 28px 20px 52px; }
h1, h2, h3 { font-family: 'Outfit', sans-serif; font-weight: 800; letter-spacing: -0.02em; }
.hdr { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 22px; }
.hdr h1 { font-size: 25px; color: var(--dk); }
.hdr p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
.badge-caisse { padding: 8px 14px; border-radius: var(--Rs); font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
.badge-caisse.ouverte { background: var(--sucl); border: 1px solid var(--sucb); color: #065f46; }
.badge-caisse.fermee { background: var(--dngl); border: 1px solid var(--dngb); color: #991b1b; }

.alert-ferme { background: var(--wrnl); border: 1px solid var(--wrnb); border-radius: var(--R); padding: 24px; text-align: center; margin-bottom: 24px; }
.alert-ferme i { font-size: 40px; color: var(--wrn); margin-bottom: 10px; display: block; }
.alert-ferme h3 { font-size: 17px; margin-bottom: 6px; }
.alert-ferme p { color: var(--mt); font-size: 13px; margin-bottom: 16px; }

.btn-action { display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; border-radius: var(--Rs); font-weight: 700; font-size: 13px; text-decoration: none; transition: all .2s; border: none; cursor: pointer; }
.btn-action.primary { background: var(--b); color: #fff; }
.btn-action.primary:hover { background: var(--bd); transform: translateY(-1px); }
.btn-action.success { background: var(--suc); color: #fff; }
.btn-action.success:hover { background: #059669; transform: translateY(-1px); }
.actions-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }

.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
.kpi { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 18px 20px; position: relative; overflow: hidden; }
.kpi::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.kpi.kb::before { background: var(--b); } .kpi.kg::before { background: var(--suc); }
.kpi.kd::before { background: var(--dng); } .kpi.kt::before { background: var(--tl); }
.kr { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.ki { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
.ki.ib { background: var(--bl); color: var(--b); } .ki.ig { background: var(--sucl); color: var(--suc); }
.ki.id { background: var(--dngl); color: var(--dng); } .ki.it { background: var(--tll); color: var(--tl); }
.klb { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .3px; }
.kv { font-size: 24px; font-weight: 900; color: var(--dk); letter-spacing: -0.03em; }

.card { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 22px; margin-bottom: 20px; }
.card h3 { font-size: 15px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th { text-align: left; padding: 8px 10px; font-size: 10px; text-transform: uppercase; color: var(--mt); font-weight: 700; border-bottom: 2px solid var(--brd); }
tbody td { padding: 10px; border-bottom: 1px solid var(--brd); }
tbody tr:hover { background: var(--bl); }
.badge-type { padding: 3px 9px; border-radius: 14px; font-size: 10px; font-weight: 700; }
.badge-type.in { background: var(--sucl); color: #065f46; }
.badge-type.out { background: var(--dngl); color: #991b1b; }
.empty { text-align: center; padding: 30px; color: var(--lt); }
@media(max-width:800px){ .kpis { grid-template-columns: repeat(2,1fr); } }
</style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div>
            <h1><i class="bi bi-cash-coin"></i> Bonjour, <?= e(explode(' ', $user['nom_prenom'])[0]) ?></h1>
            <p>Voici la situation de votre caisse aujourd'hui</p>
        </div>
        <?php if ($caisseOuverte): ?>
            <span class="badge-caisse ouverte"><i class="bi bi-unlock-fill"></i> Caisse "<?= e($caisseOuverte['nom_caisse']) ?>" ouverte<?= $nbCaissesOuvertes > 1 ? ' (+' . ($nbCaissesOuvertes - 1) . ' autre(s))' : '' ?></span>
        <?php else: ?>
            <span class="badge-caisse fermee"><i class="bi bi-lock-fill"></i> Aucune caisse ouverte</span>
        <?php endif; ?>
    </div>

    <?php if (!$caisseOuverte): ?>
        <div class="alert-ferme">
            <i class="bi bi-exclamation-triangle"></i>
            <h3>Vous n'avez pas de caisse ouverte</h3>
            <p>Ouvrez votre caisse pour commencer à enregistrer des ventes et des paiements.</p>
            <a href="../caisse/journee" class="btn-action primary"><i class="bi bi-unlock"></i> Ouvrir ma caisse</a>
        </div>
    <?php else: ?>
        <div class="actions-row">
            <a href="../publics/vente" class="btn-action success"><i class="bi bi-cart-plus"></i> Nouvelle vente</a>
            <a href="../caisse/journee" class="btn-action primary"><i class="bi bi-cash-stack"></i> Gérer ma caisse</a>
        </div>

        <div class="kpis">
            <div class="kpi kb">
                <div class="kr"><div class="ki ib"><i class="bi bi-wallet2"></i></div><div class="klb">Solde caisse actuel</div></div>
                <div class="kv"><?= fmt($caisseOuverte['solde']) ?> F</div>
            </div>
            <div class="kpi kg">
                <div class="kr"><div class="ki ig"><i class="bi bi-arrow-down-circle"></i></div><div class="klb">Encaissé aujourd'hui</div></div>
                <div class="kv"><?= fmt($encaisseJour) ?> F</div>
            </div>
            <div class="kpi kd">
                <div class="kr"><div class="ki id"><i class="bi bi-arrow-up-circle"></i></div><div class="klb">Décaissé aujourd'hui</div></div>
                <div class="kv"><?= fmt($decaisseJour) ?> F</div>
            </div>
            <div class="kpi kt">
                <div class="kr"><div class="ki it"><i class="bi bi-receipt"></i></div><div class="klb">Transactions du jour</div></div>
                <div class="kv"><?= $nbTransactionsJour ?></div>
            </div>
        </div>

        <div class="card">
            <h3><i class="bi bi-clock-history"></i> Mes transactions d'aujourd'hui</h3>
            <table>
                <thead><tr><th>Heure</th><th>Type</th><th>Objet</th><th>Mode</th><th>Montant</th></tr></thead>
                <tbody>
                <?php if (empty($dernieresTransactions)): ?>
                    <tr><td colspan="5" class="empty"><i class="bi bi-inbox d-block mb-2" style="font-size:2rem;opacity:.4;"></i>Aucune transaction pour l'instant</td></tr>
                <?php else: foreach ($dernieresTransactions as $t):
                    $isEntree = ($t['type_transaction'] === 'Entree');
                    $montant = $isEntree ? $t['montant_transaction'] : $t['montant_total'];
                ?>
                    <tr>
                        <td><?= e($t['heure_transaction']) ?></td>
                        <td><span class="badge-type <?= $isEntree ? 'in' : 'out' ?>"><?= $isEntree ? 'Encaissement' : 'Décaissement' ?></span></td>
                        <td><?= e($t['objet_transaction'] ?? '—') ?></td>
                        <td><?= e($t['mode_reglement'] ?? '—') ?></td>
                        <td><strong><?= fmt($montant) ?> F</strong></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>