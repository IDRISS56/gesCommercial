<?php
// dashboard_proprietaire.php – Tableau de bord limité à UNE boutique
// Design aligné sur dashboard_caisse.php

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require 'databases/database.php';

if (!function_exists('e')) {
    function e($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('fmt')) {
    function fmt($n) {
        return number_format(floatval($n), 0, ',', ' ');
    }
}

$stmtU = $pdo->prepare("SELECT * FROM utilisateur WHERE id = ?");
$stmtU->execute([$_SESSION['user_id']]);
$user = $stmtU->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: ../utilisateur/login');
    exit;
}

if (!defined('USER_BOUTIQUE')) {
    define('USER_BOUTIQUE', $user['boutique_id'] ?? null);
}

// Boutiques autorisées
$boutiquesAutorisees = array_map(
    'strval',
    (array) getBoutiquesAutorisees($pdo, $user['role'] ?? '', USER_BOUTIQUE)
);

$boutiqueActuelle = trim((string)($_POST['boutique_id'] ?? ''));

if ($boutiqueActuelle === '') {
    $boutiqueActuelle = USER_BOUTIQUE !== null ? (string) USER_BOUTIQUE : null;
}

if ($boutiqueActuelle === null || !in_array($boutiqueActuelle, $boutiquesAutorisees, true)) {
    $boutiqueActuelle = $boutiquesAutorisees[0] ?? null;
}

// Nom de la boutique
$nomBoutique = '—';
if ($boutiqueActuelle !== null && $boutiqueActuelle !== '') {
    $stmtNomBoutique = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
    $stmtNomBoutique->execute([$boutiqueActuelle]);
    $nomBoutique = $stmtNomBoutique->fetchColumn() ?: '—';
}

$aujourdhui = date('Y-m-d');
$debutMois = date('Y-m-01');
$finMois = date('Y-m-t');
$debutMoisPrec = date('Y-m-01', strtotime('-1 month'));
$finMoisPrec = date('Y-m-t', strtotime('-1 month'));

if ($boutiqueActuelle !== null && $boutiqueActuelle !== '') {

    // CA du jour
    $stmt = $pdo->prepare("
        SELECT COUNT(*), COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0)
        FROM commande
        WHERE etat_commande = 'VALIDEE'
          AND statut_id = '012'
          AND boutique_id = ?
          AND date_commande = ?
    ");
    $stmt->execute([$boutiqueActuelle, $aujourdhui]);
    [$nbVentesJour, $caJour] = $stmt->fetch(PDO::FETCH_NUM);

    // CA du mois en cours
    $stmt = $pdo->prepare("
        SELECT COUNT(*), COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0)
        FROM commande
        WHERE etat_commande = 'VALIDEE'
          AND statut_id = '012'
          AND boutique_id = ?
          AND date_commande BETWEEN ? AND ?
    ");
    $stmt->execute([$boutiqueActuelle, $debutMois, $finMois]);
    [$nbVentesMois, $caMois] = $stmt->fetch(PDO::FETCH_NUM);

    // CA du mois précédent
    $stmt->execute([$boutiqueActuelle, $debutMoisPrec, $finMoisPrec]);
    [$nbVentesMoisPrec, $caMoisPrec] = $stmt->fetch(PDO::FETCH_NUM);

    $panierMoyen = $nbVentesMois > 0 ? round($caMois / $nbVentesMois) : 0;
    $evolutionCA = $caMoisPrec > 0
        ? round((($caMois - $caMoisPrec) / $caMoisPrec) * 100, 1)
        : ($caMois > 0 ? 100 : 0);

    // Commandes en attente
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM commande
        WHERE etat_commande = 'EN ATTENTE'
          AND statut_id = '012'
          AND boutique_id = ?
    ");
    $stmt->execute([$boutiqueActuelle]);
    $cmdAttente = intval($stmt->fetchColumn());

    // Solde caisse ouverte
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde),0)
        FROM caisse c
        WHERE c.statut = 'Actif'
          AND c.boutique_id = ?
          AND EXISTS (
              SELECT 1
              FROM journees_caisse jc
              WHERE jc.caisse_id = c.caisse_id
                AND jc.statut = 'OUVERTE'
          )
    ");
    $stmt->execute([$boutiqueActuelle]);
    $soldeCaisse = floatval($stmt->fetchColumn() ?? 0);

    // Stock
    $stmt = $pdo->prepare("
        SELECT s.quantite, s.stock_alerte
        FROM stock s
        WHERE s.boutique_id = ?
    ");
    $stmt->execute([$boutiqueActuelle]);
    $stockRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stockRupture = 0;
    $stockAlerte = 0;
    $stockOk = 0;

    foreach ($stockRows as $r) {
        $q = (int) $r['quantite'];
        $seuil = (int) $r['stock_alerte'];

        if ($q <= 0) {
            $stockRupture++;
        } elseif ($q <= $seuil) {
            $stockAlerte++;
        } else {
            $stockOk++;
        }
    }

    // Produits en alerte / rupture
    $stmt = $pdo->prepare("
        SELECT p.titre_produit, s.quantite, s.stock_alerte
        FROM stock s
        JOIN produit p ON p.code_produit = s.produit_id
        WHERE s.boutique_id = ?
          AND s.quantite <= s.stock_alerte
        ORDER BY s.quantite ASC
        LIMIT 6
    ");
    $stmt->execute([$boutiqueActuelle]);
    $produitsAlerte = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Dernières ventes
    $stmt = $pdo->prepare("
        SELECT c.numero_commande,
               c.date_commande,
               c.heure_commande,
               c.contact_id,
               c.produit_id,
               CAST(c.montant_commande AS DECIMAL(12,2)) as montant,
               c.etat_commande
        FROM commande c
        WHERE c.statut_id = '012'
          AND c.boutique_id = ?
        ORDER BY c.date_commande DESC, c.heure_commande DESC
        LIMIT 8
    ");
    $stmt->execute([$boutiqueActuelle]);
    $activite = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $actDetails = [];

    foreach ($activite as $a) {
        $s = $pdo->prepare("SELECT nom_prenom_contact FROM contact WHERE code_contact = ?");
        $s->execute([$a['contact_id']]);
        $client = $s->fetchColumn() ?: $a['contact_id'];

        $s2 = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
        $s2->execute([$a['produit_id']]);
        $prod = $s2->fetchColumn() ?: $a['produit_id'];

        $actDetails[] = [
            'numero' => $a['numero_commande'],
            'date' => $a['date_commande'],
            'client' => $client,
            'produit' => $prod,
            'montant' => $a['montant'],
            'etat' => $a['etat_commande']
        ];
    }

} else {
    $nbVentesJour = 0;
    $caJour = 0;
    $nbVentesMois = 0;
    $caMois = 0;
    $panierMoyen = 0;
    $evolutionCA = 0;
    $cmdAttente = 0;
    $soldeCaisse = 0;
    $stockRupture = 0;
    $stockAlerte = 0;
    $stockOk = 0;
    $produitsAlerte = [];
    $actDetails = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php if (file_exists(__DIR__ . '/includes/pwa_head.php')) include __DIR__ . '/includes/pwa_head.php'; ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord — <?= e($nomBoutique) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --b: #2563eb;
            --bd: #1d4ed8;
            --bl: #eff6ff;
            --bb: #bfdbfe;

            --bg: #f1f5f9;
            --w: #fff;
            --dk: #0f172a;
            --mt: #64748b;
            --lt: #94a3b8;
            --brd: #e2e8f0;

            --dng: #ef4444;
            --dngl: #fef2f2;
            --dngb: #fecaca;

            --suc: #10b981;
            --sucl: #ecfdf5;
            --sucb: #a7f3d0;

            --wrn: #f59e0b;
            --wrnl: #fffbeb;
            --wrnb: #fde68a;

            --prp: #8b5cf6;
            --prpl: #f5f3ff;

            --tl: #0891b2;
            --tll: #ecfeff;

            --R: 16px;
            --Rs: 10px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--dk);
            min-height: 100vh;
            line-height: 1.5;
        }

        .W {
            max-width: 1100px;
            margin: 0 auto;
            padding: 28px 20px 52px;
        }

        h1, h2, h3 {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .hdr {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 22px;
        }

        .hdr h1 {
            font-size: 25px;
            color: var(--dk);
        }

        .hdr p {
            font-size: 13px;
            color: var(--mt);
            margin-top: 2px;
            font-weight: 500;
        }

        .hdr-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .badge-boutique {
            padding: 8px 14px;
            border-radius: var(--Rs);
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--bl);
            border: 1px solid var(--bb);
            color: var(--bd);
        }

        .boutique-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .select-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--mt);
        }

        .select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            min-width: 220px;
            padding: 10px 38px 10px 14px;
            border: 1px solid var(--brd);
            border-radius: var(--Rs);
            background-color: var(--w);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.203 4h9.594c.858 0 1.318 1.013.752 1.658L8.753 11.14a.997.997 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
            font: 600 13px 'Inter', sans-serif;
            color: var(--dk);
            cursor: pointer;
            outline: none;
        }

        .select:focus {
            border-color: var(--b);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
        }

        .alert-ferme {
            background: var(--wrnl);
            border: 1px solid var(--wrnb);
            border-radius: var(--R);
            padding: 24px;
            text-align: center;
            margin-bottom: 24px;
        }

        .alert-ferme i {
            font-size: 40px;
            color: var(--wrn);
            margin-bottom: 10px;
            display: block;
        }

        .alert-ferme h3 {
            font-size: 17px;
            margin-bottom: 6px;
        }

        .alert-ferme p {
            color: var(--mt);
            font-size: 13px;
            margin-bottom: 16px;
        }

        .kpis {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .kpi {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            padding: 18px 20px;
            position: relative;
            overflow: hidden;
        }

        .kpi::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }

        .kpi.kb::before { background: var(--b); }
        .kpi.kg::before { background: var(--suc); }
        .kpi.kd::before { background: var(--dng); }
        .kpi.kt::before { background: var(--tl); }
        .kpi.kp::before { background: var(--prp); }

        .kr {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .ki {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .ki.ib { background: var(--bl); color: var(--b); }
        .ki.ig { background: var(--sucl); color: var(--suc); }
        .ki.id { background: var(--dngl); color: var(--dng); }
        .ki.it { background: var(--tll); color: var(--tl); }
        .ki.ip { background: var(--prpl); color: var(--prp); }

        .klb {
            font-size: 11px;
            font-weight: 600;
            color: var(--mt);
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .kv {
            font-size: 24px;
            font-weight: 900;
            color: var(--dk);
            letter-spacing: -0.03em;
        }

        .kpi-sub {
            font-size: 12px;
            color: var(--mt);
            font-weight: 600;
            margin-top: 5px;
        }

        .evol {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
        }

        .evol.pos {
            background: var(--sucl);
            color: #065f46;
        }

        .evol.neg {
            background: var(--dngl);
            color: #991b1b;
        }

        .grid {
            display: grid;
            grid-template-columns: 360px minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }

        .card {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            padding: 22px;
            margin-bottom: 20px;
        }

        .card h3 {
            font-size: 15px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        thead th {
            text-align: left;
            padding: 8px 10px;
            font-size: 10px;
            text-transform: uppercase;
            color: var(--mt);
            font-weight: 700;
            border-bottom: 2px solid var(--brd);
            white-space: nowrap;
        }

        tbody td {
            padding: 10px;
            border-bottom: 1px solid var(--brd);
            vertical-align: middle;
        }

        tbody tr:hover {
            background: var(--bl);
        }

        .text-end {
            text-align: right;
        }

        .text-danger {
            color: var(--dng);
            font-weight: 800;
        }

        .text-warning {
            color: var(--wrn);
            font-weight: 800;
        }

        .text-success {
            color: var(--suc);
            font-weight: 800;
        }

        .badge-etat {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .3px;
            white-space: nowrap;
            display: inline-block;
            background: var(--bg);
            color: var(--mt);
        }

        .badge-etat.etat-VALIDEE {
            background: var(--sucl);
            color: #065f46;
        }

        .badge-etat.etat-EN-ATTENTE {
            background: var(--wrnl);
            color: #92400e;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: var(--lt);
        }

        .empty i {
            display: block;
            font-size: 2rem;
            opacity: .4;
            margin-bottom: 8px;
        }

        .stock-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 11px 0;
            border-bottom: 1px solid var(--brd);
            font-size: 13px;
        }

        .stock-row:last-child {
            border-bottom: none;
        }

        .stock-count {
            font-size: 18px;
            font-weight: 900;
        }

        .dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }

        .dot.danger { background: var(--dng); }
        .dot.warning { background: var(--wrn); }
        .dot.success { background: var(--suc); }

        .mini-list {
            margin-top: 18px;
            border-top: 1px solid var(--brd);
            padding-top: 14px;
        }

        .mini-title {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--mt);
            font-weight: 800;
            letter-spacing: .5px;
            margin-bottom: 10px;
        }

        .product-line {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 10px;
            margin-bottom: 6px;
            background: var(--bg);
            font-size: 12px;
        }

        .product-line span:first-child {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .product-line span:last-child {
            font-weight: 800;
            white-space: nowrap;
        }

        @media(max-width: 900px) {
            .kpis {
                grid-template-columns: repeat(2, 1fr);
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .hdr {
                align-items: flex-start;
            }
        }

        @media(max-width: 520px) {
            .kpis {
                grid-template-columns: 1fr;
            }

            .hdr-right {
                width: 100%;
            }

            .select {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="W">

    <div class="hdr">
        <div>
            <h1><i class="bi bi-shop"></i> Tableau de bord</h1>
            <p>Vue Propriétaire · <?= e($nomBoutique) ?></p>
        </div>

        <div class="hdr-right">
            <span class="badge-boutique">
                <i class="bi bi-shop-window"></i> <?= e($nomBoutique) ?>
            </span>

            <?php if (count($boutiquesAutorisees) > 1): ?>
                <form method="post" class="boutique-form">
                    <label class="select-label" for="boutique_id">Boutique</label>
                    <select name="boutique_id" id="boutique_id" class="select" onchange="this.form.submit()">
                        <?php
                        $stmtN = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
                        foreach ($boutiquesAutorisees as $bid):
                            $stmtN->execute([$bid]);
                            $nb = $stmtN->fetchColumn();
                        ?>
                            <option value="<?= e($bid) ?>" <?= $bid === $boutiqueActuelle ? 'selected' : '' ?>>
                                <?= e($nb) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$boutiqueActuelle): ?>

        <div class="alert-ferme">
            <i class="bi bi-shop-window"></i>
            <h3>Aucune boutique accessible</h3>
            <p>Impossible de déterminer la boutique associée à votre compte.</p>
        </div>

    <?php else: ?>

        <div class="kpis">
            <div class="kpi kb">
                <div class="kr">
                    <div class="ki ib"><i class="bi bi-cash-coin"></i></div>
                    <div class="klb">Ventes du jour</div>
                </div>
                <div class="kv"><?= fmt($caJour) ?> F</div>
                <div class="kpi-sub"><?= (int) $nbVentesJour ?> vente(s)</div>
            </div>

            <div class="kpi kp">
                <div class="kr">
                    <div class="ki ip"><i class="bi bi-graph-up-arrow"></i></div>
                    <div class="klb">Ventes du mois</div>
                </div>
                <div class="kv"><?= fmt($caMois) ?> F</div>
                <div class="kpi-sub">
                    <span class="evol <?= $evolutionCA >= 0 ? 'pos' : 'neg' ?>">
                        <i class="bi bi-arrow-<?= $evolutionCA >= 0 ? 'up' : 'down' ?>"></i>
                        <?= abs($evolutionCA) ?>%
                    </span>
                    vs mois préc.
                </div>
            </div>

            <div class="kpi kt">
                <div class="kr">
                    <div class="ki it"><i class="bi bi-wallet2"></i></div>
                    <div class="klb">Panier moyen (mois)</div>
                </div>
                <div class="kv"><?= fmt($panierMoyen) ?> F</div>
                <div class="kpi-sub"><?= (int) $cmdAttente ?> vente(s) en attente</div>
            </div>

            <div class="kpi kg">
                <div class="kr">
                    <div class="ki ig"><i class="bi bi-safe2"></i></div>
                    <div class="klb">Solde caisse ouverte</div>
                </div>
                <div class="kv"><?= fmt($soldeCaisse) ?> F</div>
                <div class="kpi-sub">Caisse active</div>
            </div>
        </div>

        <div class="grid">

            <div class="card">
                <h3><i class="bi bi-boxes"></i> État du stock</h3>

                <div class="stock-row">
                    <span><span class="dot danger"></span> En rupture</span>
                    <strong class="stock-count text-danger"><?= (int) $stockRupture ?></strong>
                </div>

                <div class="stock-row">
                    <span><span class="dot warning"></span> En alerte</span>
                    <strong class="stock-count text-warning"><?= (int) $stockAlerte ?></strong>
                </div>

                <div class="stock-row">
                    <span><span class="dot success"></span> Stock correct</span>
                    <strong class="stock-count text-success"><?= (int) $stockOk ?></strong>
                </div>

                <?php if ($produitsAlerte): ?>
                    <div class="mini-list">
                        <div class="mini-title">Produits à surveiller</div>

                        <?php foreach ($produitsAlerte as $p): ?>
                            <div class="product-line">
                                <span><?= e($p['titre_produit']) ?></span>
                                <span class="<?= $p['quantite'] <= 0 ? 'text-danger' : 'text-warning' ?>">
                                    <?= (int) $p['quantite'] ?> / <?= (int) $p['stock_alerte'] ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3><i class="bi bi-receipt"></i> Ventes récentes</h3>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Produit</th>
                                <th class="text-end">Montant</th>
                                <th>État</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$actDetails): ?>
                                <tr>
                                    <td colspan="6" class="empty">
                                        <i class="bi bi-inbox"></i>
                                        Aucune vente enregistrée pour le moment.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($actDetails as $a): ?>
                                    <?php
                                        $dateFmt = '—';
                                        if (!empty($a['date'])) {
                                            $ts = strtotime($a['date']);
                                            if ($ts) {
                                                $dateFmt = date('d/m/Y', $ts);
                                            }
                                        }

                                        $etatSlug = strtoupper(str_replace(' ', '-', trim((string) $a['etat'])));
                                        $etatClass = in_array($etatSlug, ['VALIDEE', 'EN-ATTENTE'], true)
                                            ? 'etat-' . $etatSlug
                                            : 'etat-default';
                                    ?>
                                    <tr>
                                        <td><?= e($a['numero']) ?></td>
                                        <td><?= e($dateFmt) ?></td>
                                        <td><?= e($a['client']) ?></td>
                                        <td><?= e($a['produit']) ?></td>
                                        <td class="text-end"><strong><?= fmt($a['montant']) ?> F</strong></td>
                                        <td>
                                            <span class="badge-etat <?= e($etatClass) ?>">
                                                <?= e($a['etat']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    <?php endif; ?>

</div>
</body>
</html>