<?php
// dashboard_vendeur.php – Tableau de bord dédié au rôle Vendeur

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

$whereVente = "c.statut_id='012' AND c.etat_commande='VALIDEE' AND c.utilisateur_id = ?";

// - CA & VENTES AUJOURD'HUI -
$stmt = $pdo->prepare("SELECT COUNT(*) AS nb, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca
    FROM commande c WHERE $whereVente AND c.date_commande = CURDATE()");
$stmt->execute([USER_ID]);
$jour = $stmt->fetch(PDO::FETCH_ASSOC);

// - CA & VENTES CETTE SEMAINE -
$stmt = $pdo->prepare("SELECT COUNT(*) AS nb, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca
    FROM commande c WHERE $whereVente AND YEARWEEK(c.date_commande, 3) = YEARWEEK(CURDATE(), 3)");
$stmt->execute([USER_ID]);
$semaine = $stmt->fetch(PDO::FETCH_ASSOC);

// - CA & VENTES CE MOIS -
$stmt = $pdo->prepare("SELECT COUNT(*) AS nb, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca
    FROM commande c WHERE $whereVente AND DATE_FORMAT(c.date_commande,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')");
$stmt->execute([USER_ID]);
$mois = $stmt->fetch(PDO::FETCH_ASSOC);

$panierMoyenMois = $mois['nb'] > 0 ? $mois['ca'] / $mois['nb'] : 0;

// - ÉVOLUTION 7 DERNIERS JOURS -
$stmt = $pdo->prepare("SELECT DATE_FORMAT(c.date_commande,'%Y-%m-%d') AS jour, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca
    FROM commande c WHERE $whereVente AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY jour ORDER BY jour ASC");
$stmt->execute([USER_ID]);
$evolutionBrute = $stmt->fetchAll(PDO::FETCH_ASSOC);
$evolutionMap = [];
foreach ($evolutionBrute as $r) $evolutionMap[$r['jour']] = floatval($r['ca']);
$joursFR = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mer','Thu'=>'Jeu','Fri'=>'Ven','Sat'=>'Sam','Sun'=>'Dim'];
$evolution7j = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $evolution7j[] = ['label' => $joursFR[date('D', strtotime($d))], 'ca' => $evolutionMap[$d] ?? 0];
}

// - TOP PRODUITS VENDUS (30 derniers jours) -
$stmt = $pdo->prepare("SELECT p.titre_produit, SUM(c.quantite_commande) AS qte, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca
    FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit
    WHERE $whereVente AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY c.produit_id ORDER BY ca DESC LIMIT 5");
$stmt->execute([USER_ID]);
$topProduits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// - DERNIÈRES VENTES -
$stmt = $pdo->prepare("SELECT c.numero_commande, c.date_commande, c.heure_commande, c.quantite_commande, c.montant_commande, p.titre_produit, ct.nom_prenom_contact
    FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit LEFT JOIN contact ct ON c.contact_id = ct.code_contact
    WHERE $whereVente ORDER BY c.date_commande DESC, c.heure_commande DESC LIMIT 10");
$stmt->execute([USER_ID]);
$dernieresVentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$maxCa7j = max(array_merge(array_column($evolution7j, 'ca'), [1]));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tableau de bord — Vendeur</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --b: #2563eb; --bd: #1d4ed8; --bl: #eff6ff; --bb: #bfdbfe;
    --bg: #f1f5f9; --w: #fff; --dk: #0f172a; --mt: #64748b; --lt: #94a3b8; --brd: #e2e8f0;
    --suc: #10b981; --sucl: #ecfdf5; --sucb: #a7f3d0;
    --wrn: #f59e0b; --wrnl: #fffbeb;
    --prp: #8b5cf6; --prpl: #f5f3ff;
    --tl: #0891b2; --tll: #ecfeff;
    --R: 16px; --Rs: 10px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--dk); min-height: 100vh; line-height: 1.5; }
.W { max-width: 1100px; margin: 0 auto; padding: 28px 20px 52px; }
h1, h2, h3 { font-family: 'Outfit', sans-serif; font-weight: 800; letter-spacing: -0.02em; }
.hdr { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 22px; }
.hdr h1 { font-size: 25px; }
.hdr p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
.badge-role { background: var(--bl); border: 1px solid var(--bb); color: var(--b); padding: 8px 14px; border-radius: var(--Rs); font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }

.btn-action { display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; border-radius: var(--Rs); font-weight: 700; font-size: 13px; text-decoration: none; transition: all .2s; border: none; cursor: pointer; }
.btn-action.success { background: var(--suc); color: #fff; }
.btn-action.success:hover { background: #059669; transform: translateY(-1px); }
.actions-row { margin-bottom: 24px; }

.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
.kpi { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 18px 20px; position: relative; overflow: hidden; }
.kpi::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.kpi.kb::before { background: var(--b); } .kpi.kg::before { background: var(--suc); }
.kpi.kp::before { background: var(--prp); } .kpi.kt::before { background: var(--tl); }
.kr { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.ki { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
.ki.ib { background: var(--bl); color: var(--b); } .ki.ig { background: var(--sucl); color: var(--suc); }
.ki.ip { background: var(--prpl); color: var(--prp); } .ki.it { background: var(--tll); color: var(--tl); }
.klb { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .3px; }
.kv { font-size: 22px; font-weight: 900; color: var(--dk); letter-spacing: -0.03em; }
.kv small { font-size: 12px; color: var(--mt); font-weight: 600; }

.grid2 { display: grid; grid-template-columns: 1.3fr 1fr; gap: 20px; margin-bottom: 20px; }
.card { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 22px; margin-bottom: 20px; }
.card h3 { font-size: 15px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

.chart-bars { display: flex; align-items: flex-end; gap: 10px; height: 160px; padding-top: 10px; }
.chart-bar-wrap { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
.chart-bar { width: 100%; max-width: 34px; background: linear-gradient(180deg, var(--b), var(--bd)); border-radius: 6px 6px 0 0; transition: height .3s; min-height: 3px; }
.chart-bar-val { font-size: 9px; color: var(--mt); font-weight: 700; margin-bottom: 4px; }
.chart-bar-lbl { font-size: 10px; color: var(--mt); font-weight: 600; margin-top: 6px; }

.top-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--brd); }
.top-item:last-child { border-bottom: none; }
.top-name { font-size: 13px; font-weight: 700; }
.top-qte { font-size: 11px; color: var(--mt); }
.top-ca { font-size: 13px; font-weight: 800; color: var(--b); }

table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th { text-align: left; padding: 8px 10px; font-size: 10px; text-transform: uppercase; color: var(--mt); font-weight: 700; border-bottom: 2px solid var(--brd); }
tbody td { padding: 10px; border-bottom: 1px solid var(--brd); }
tbody tr:hover { background: var(--bl); }
.empty { text-align: center; padding: 30px; color: var(--lt); }
@media(max-width:800px){ .kpis { grid-template-columns: repeat(2,1fr); } .grid2 { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div>
            <h1><i class="bi bi-person-badge"></i> Bonjour, <?= e(explode(' ', $user['nom_prenom'])[0]) ?></h1>
            <p>Votre performance de vente en un coup d'œil</p>
        </div>
        <span class="badge-role"><i class="bi bi-award"></i> Vendeur</span>
    </div>

    <div class="actions-row">
        <a href="../publics/vente" class="btn-action success"><i class="bi bi-cart-plus"></i> Nouvelle vente</a>
    </div>

    <div class="kpis">
        <div class="kpi kb">
            <div class="kr"><div class="ki ib"><i class="bi bi-cash-stack"></i></div><div class="klb">CA aujourd'hui</div></div>
            <div class="kv"><?= fmt($jour['ca']) ?> F <small>(<?= (int)$jour['nb'] ?> vente<?= $jour['nb'] > 1 ? 's' : '' ?>)</small></div>
        </div>
        <div class="kpi kg">
            <div class="kr"><div class="ki ig"><i class="bi bi-calendar-week"></i></div><div class="klb">CA cette semaine</div></div>
            <div class="kv"><?= fmt($semaine['ca']) ?> F <small>(<?= (int)$semaine['nb'] ?>)</small></div>
        </div>
        <div class="kpi kp">
            <div class="kr"><div class="ki ip"><i class="bi bi-calendar-month"></i></div><div class="klb">CA ce mois</div></div>
            <div class="kv"><?= fmt($mois['ca']) ?> F <small>(<?= (int)$mois['nb'] ?>)</small></div>
        </div>
        <div class="kpi kt">
            <div class="kr"><div class="ki it"><i class="bi bi-basket"></i></div><div class="klb">Panier moyen (mois)</div></div>
            <div class="kv"><?= fmt($panierMoyenMois) ?> F</div>
        </div>
    </div>

    <div class="grid2">
        <div class="card">
            <h3><i class="bi bi-graph-up-arrow"></i> Mes ventes — 7 derniers jours</h3>
            <div class="chart-bars">
                <?php foreach ($evolution7j as $jourData):
                    $hauteur = $maxCa7j > 0 ? max(4, round(($jourData['ca'] / $maxCa7j) * 130)) : 4;
                ?>
                <div class="chart-bar-wrap">
                    <div class="chart-bar-val"><?= $jourData['ca'] > 0 ? number_format($jourData['ca']/1000, 0) . 'k' : '' ?></div>
                    <div class="chart-bar" style="height: <?= $hauteur ?>px;"></div>
                    <div class="chart-bar-lbl"><?= $jourData['label'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card">
            <h3><i class="bi bi-star"></i> Top produits (30 j)</h3>
            <?php if (empty($topProduits)): ?>
                <div class="empty">Aucune vente sur cette période</div>
            <?php else: foreach ($topProduits as $p): ?>
                <div class="top-item">
                    <div><div class="top-name"><?= e($p['titre_produit'] ?? 'N/A') ?></div><div class="top-qte"><?= (int)$p['qte'] ?> unité(s)</div></div>
                    <div class="top-ca"><?= fmt($p['ca']) ?> F</div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="card">
        <h3><i class="bi bi-clock-history"></i> Mes dernières ventes</h3>
        <table>
            <thead><tr><th>N° Commande</th><th>Date</th><th>Client</th><th>Produit</th><th>Qté</th><th>Montant</th></tr></thead>
            <tbody>
            <?php if (empty($dernieresVentes)): ?>
                <tr><td colspan="6" class="empty"><i class="bi bi-inbox d-block mb-2" style="font-size:2rem;opacity:.4;"></i>Aucune vente enregistrée</td></tr>
            <?php else: foreach ($dernieresVentes as $v): ?>
                <tr>
                    <td><?= e($v['numero_commande']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($v['date_commande'] . ' ' . $v['heure_commande'])) ?></td>
                    <td><?= e($v['nom_prenom_contact'] ?? '—') ?></td>
                    <td><?= e($v['titre_produit'] ?? '—') ?></td>
                    <td><?= (int)$v['quantite_commande'] ?></td>
                    <td><strong><?= fmt($v['montant_commande']) ?> F</strong></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>