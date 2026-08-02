<?php
// dashboard.php – Tableau de bord entièrement en POST

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require 'databases/database.php';

$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}
function fmtK($n)
{
    $n = floatval($n);
    if ($n >= 1e6) return round($n / 1e6, 1) . 'M';
    if ($n >= 1e3) return round($n / 1e3, 0) . 'k';
    return $n;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$periode = $_POST['periode'] ?? 'mois';
$valJour = $_POST['date_jour'] ?? date('Y-m-d');
$valSemaine = $_POST['date_semaine'] ?? date('Y-\WW');
$valMois = $_POST['date_mois'] ?? date('Y-m');
$valTrimestre = $_POST['date_trimestre'] ?? date('Y') . '-T' . ceil(date('n') / 3);
$valSemestre = $_POST['date_semestre'] ?? date('Y') . '-S' . (date('n') <= 6 ? 1 : 2);
$valAnnee = $_POST['date_annee'] ?? date('Y');

$periode = in_array($periode, ['jour', 'semaine', 'mois', 'trimestre', 'semestre', 'annuel']) ? $periode : 'mois';
$valJour = date('Y-m-d', strtotime($valJour)) ?: date('Y-m-d');
if (!preg_match('/^(\d{4})-W(\d{2})$/', $valSemaine)) $valSemaine = date('Y-\WW');
if (!preg_match('/^\d{4}-\d{2}$/', $valMois)) $valMois = date('Y-m');
if (!preg_match('/^\d{4}-T[1-4]$/', $valTrimestre)) $valTrimestre = date('Y') . '-T' . ceil(date('n') / 3);
if (!preg_match('/^\d{4}-S[1-2]$/', $valSemestre)) $valSemestre = date('Y') . '-S' . (date('n') <= 6 ? 1 : 2);
$valAnnee = (is_numeric($valAnnee) && strlen($valAnnee) == 4) ? $valAnnee : date('Y');

$date_debut = $date_fin = $date_debut_prev = $date_fin_prev = null;
$libelle_periode = $libelle_periode_prev = '';
$moisFR = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];

switch ($periode) {
    case 'jour':
        $date_debut = $valJour;
        $date_fin = $valJour;
        $date_debut_prev = date('Y-m-d', strtotime($valJour . ' -1 day'));
        $date_fin_prev = $date_debut_prev;
        $libelle_periode = date('d/m/Y', strtotime($date_debut));
        $libelle_periode_prev = "veille";
        break;
    case 'semaine':
        if (preg_match('/^(\d{4})-W(\d{1,2})$/', $valSemaine, $m)) {
            $d = new DateTime();
            $d->setISODate($m[1], $m[2]);
            $date_debut = $d->format('Y-m-d');
            $date_fin = $d->modify('+6 days')->format('Y-m-d');
            $sem_prev = $m[2] - 1;
            $an_prev = $m[1];
            if ($sem_prev < 1) {
                $sem_prev = 52;
                $an_prev--;
            }
            $dp = new DateTime();
            $dp->setISODate($an_prev, $sem_prev);
            $date_debut_prev = $dp->format('Y-m-d');
            $date_fin_prev = $dp->modify('+6 days')->format('Y-m-d');
        } else {
            $j = date('N');
            $date_debut = date('Y-m-d', strtotime('-' . ($j - 1) . ' days'));
            $date_fin = date('Y-m-d', strtotime('+' . (7 - $j) . ' days'));
            $date_debut_prev = date('Y-m-d', strtotime($date_debut . ' -7 days'));
            $date_fin_prev = date('Y-m-d', strtotime($date_fin . ' -7 days'));
        }
        $libelle_periode = date('d/m', strtotime($date_debut)) . " – " . date('d/m/Y', strtotime($date_fin));
        $libelle_periode_prev = "sem. préc.";
        break;
    case 'mois':
        $date_debut = $valMois . '-01';
        $date_fin = date('Y-m-t', strtotime($date_debut));
        $date_debut_prev = date('Y-m-01', strtotime($date_debut . ' -1 month'));
        $date_fin_prev = date('Y-m-t', strtotime($date_debut_prev));
        $libelle_periode = $moisFR[intval(date('n', strtotime($date_debut)))] . " " . date('Y', strtotime($date_debut));
        $libelle_periode_prev = $moisFR[intval(date('n', strtotime($date_debut_prev)))] . " " . date('Y', strtotime($date_debut_prev));
        break;
    case 'trimestre':
        if (preg_match('/^(\d{4})-T([1-4])$/', $valTrimestre, $m)) {
            $t = $m[2];
            $an = $m[1];
            $mois_deb = ($t - 1) * 3 + 1;
            $mois_fin = $t * 3;
            $date_debut = $an . '-' . str_pad($mois_deb, 2, '0', STR_PAD_LEFT) . '-01';
            $date_fin = date('Y-m-t', strtotime($an . '-' . str_pad($mois_fin, 2, '0', STR_PAD_LEFT) . '-01'));
            $t_prev = $t - 1;
            $an_prev = $an;
            if ($t_prev < 1) {
                $t_prev = 4;
                $an_prev--;
            }
            $md_prev = ($t_prev - 1) * 3 + 1;
            $mf_prev = $t_prev * 3;
            $date_debut_prev = $an_prev . '-' . str_pad($md_prev, 2, '0', STR_PAD_LEFT) . '-01';
            $date_fin_prev = date('Y-m-t', strtotime($an_prev . '-' . str_pad($mf_prev, 2, '0', STR_PAD_LEFT) . '-01'));
        }
        $libelle_periode = "T" . explode('-T', $valTrimestre)[1] . " " . explode('-', $valTrimestre)[0];
        $libelle_periode_prev = "trim. préc.";
        break;
    case 'semestre':
        if (preg_match('/^(\d{4})-S([1-2])$/', $valSemestre, $m)) {
            $s = $m[2];
            $an = $m[1];
            if ($s == 1) {
                $date_debut = $an . '-01-01';
                $date_fin = $an . '-06-30';
                $date_debut_prev = ($an - 1) . '-07-01';
                $date_fin_prev = ($an - 1) . '-12-31';
            } else {
                $date_debut = $an . '-07-01';
                $date_fin = $an . '-12-31';
                $date_debut_prev = $an . '-01-01';
                $date_fin_prev = $an . '-06-30';
            }
        }
        $libelle_periode = "S" . explode('-S', $valSemestre)[1] . " " . explode('-', $valSemestre)[0];
        $libelle_periode_prev = "sem. préc.";
        break;
    case 'annuel':
        $date_debut = $valAnnee . '-01-01';
        $date_fin = $valAnnee . '-12-31';
        $date_debut_prev = ($valAnnee - 1) . '-01-01';
        $date_fin_prev = ($valAnnee - 1) . '-12-31';
        $libelle_periode = "Année $valAnnee";
        $libelle_periode_prev = "Année " . ($valAnnee - 1);
        break;
    default:
        $date_debut = date('Y-m-01');
        $date_fin = date('Y-m-t');
        $date_debut_prev = date('Y-m-01', strtotime('-1 month'));
        $date_fin_prev = date('Y-m-t', strtotime('-1 month'));
        $libelle_periode = "Mois en cours";
        $libelle_periode_prev = "mois préc.";
}

// Indicateurs

// --- Indicateurs de stock (corrigés) ---
// Récupération du stock disponible par produit à partir de `stock`
$sqlStock = "
    SELECT 
        p.code_produit,
        p.stock_alerte,
        COALESCE(s.quantite, 0) AS stock_disponible
    FROM produit p
    LEFT JOIN stock s ON s.produit_id = p.code_produit
    GROUP BY p.code_produit
";
$stmtStock = $pdo->query($sqlStock);
$stockData = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

$stockNul = 0;
$stockAlerte = 0;
$stockOk = 0;
$totalProduits = 0;
foreach ($stockData as $row) {
    $totalProduits++;
    $dispo = (int)$row['stock_disponible'];
    $alerte = (int)$row['stock_alerte'];
    if ($dispo == 0) {
        $stockNul++;
    } elseif ($dispo <= $alerte) {
        $stockAlerte++;
    } else {
        $stockOk++;
    }
}

// Nombre total de clients actifs
$stmt = $pdo->query("SELECT COUNT(*) FROM contact WHERE type_contact='Client' AND etat_contact='Actif'");
$totalClients = intval($stmt->fetchColumn());

// Solde des caisses ouvertes (table `caisse`, colonne `solde`)
$stmt = $pdo->query("SELECT COALESCE(SUM(solde),0) FROM caisse WHERE statut='Ouverte'");
$soldeCaisse = floatval($stmt->fetchColumn() ?? 0);

// Commandes validées de la période (etat_commande = 'VALIDEE')
// statut_id = '012' = ligne de VENTE (le '011' correspond aux lignes d'ACHAT
// fournisseur qui partagent désormais la même table `commande` et ne doivent
// jamais entrer dans les statistiques de chiffre d'affaires ci-dessous).
$stmt = $pdo->prepare("SELECT COUNT(*) FROM commande WHERE etat_commande='VALIDEE' AND statut_id='012' AND date_commande BETWEEN ? AND ?");
$stmt->execute([$date_debut, $date_fin]);
$cmdPeriode = intval($stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT SUM(CAST(montant_commande AS DECIMAL(12,2))) FROM commande WHERE etat_commande='VALIDEE' AND statut_id='012' AND date_commande BETWEEN ? AND ?");
$stmt->execute([$date_debut, $date_fin]);
$caPeriode = floatval($stmt->fetchColumn() ?? 0);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM commande WHERE etat_commande='VALIDEE' AND statut_id='012' AND date_commande BETWEEN ? AND ?");
$stmt->execute([$date_debut_prev, $date_fin_prev]);
$cmdPrev = intval($stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT SUM(CAST(montant_commande AS DECIMAL(12,2))) FROM commande WHERE etat_commande='VALIDEE' AND statut_id='012' AND date_commande BETWEEN ? AND ?");
$stmt->execute([$date_debut_prev, $date_fin_prev]);
$caPrev = floatval($stmt->fetchColumn() ?? 0);

// Commandes en attente (etat_commande = 'EN ATTENTE') — ventes uniquement
$stmt = $pdo->prepare("SELECT COUNT(*) FROM commande WHERE etat_commande='EN ATTENTE' AND statut_id='012' AND date_commande BETWEEN ? AND ?");
$stmt->execute([$date_debut, $date_fin]);
$cmdAttente = intval($stmt->fetchColumn() ?? 0);

$panierMoyen = $cmdPeriode > 0 ? round($caPeriode / $cmdPeriode) : 0;
$panierPrev = $cmdPrev > 0 ? round($caPrev / $cmdPrev) : 0;
$deltaPanier = $panierMoyen - $panierPrev;
$deltaCmd = $cmdPeriode - $cmdPrev;
$deltaCA = $caPeriode - $caPrev;
$pctCA = $caPrev > 0 ? round(($deltaCA / $caPrev) * 100, 1) : ($caPeriode > 0 ? 100 : 0);

// Modes de règlement
$stmt = $pdo->prepare("SELECT t.mode_reglement, SUM(CAST(t.montant_transaction AS DECIMAL(12,2))) AS total
                       FROM commande c
                       INNER JOIN transaction t ON c.facture_id = t.facture_id
                       WHERE c.etat_commande='VALIDEE' AND c.statut_id='012' AND t.type_transaction='Entree' AND c.date_commande BETWEEN ? AND ?
                       GROUP BY t.mode_reglement
                       ORDER BY total DESC");
$stmt->execute([$date_debut, $date_fin]);
$reglementData = $stmt->fetchAll(PDO::FETCH_ASSOC);
$regLabels = [];
$regValues = [];
$regColorMap = ['Espece' => '#2563eb', 'Mobile' => '#8b5cf6', 'Cheque' => '#f59e0b', 'Carte' => '#10b981', 'Virement' => '#0891b2'];
$regLabelMap = ['Espece' => 'Espèces', 'Mobile' => 'Mobile', 'Cheque' => 'Chèque', 'Carte' => 'Carte', 'Virement' => 'Virement', 'Autre' => 'Autre'];
foreach ($reglementData as $r) {
    $key = $r['mode_reglement'] ?: 'Autre';
    $regLabels[] = $regLabelMap[$key] ?? $key;
    $regValues[] = floatval($r['total']);
}

// Top 5 produits vendus
$stmt = $pdo->prepare("SELECT produit_id, SUM(CAST(quantite_commande AS UNSIGNED)) as total_qte
                       FROM commande
                       WHERE etat_commande='VALIDEE' AND statut_id='012' AND date_commande BETWEEN ? AND ?
                       GROUP BY produit_id ORDER BY total_qte DESC LIMIT 5");
$stmt->execute([$date_debut, $date_fin]);
$topProduits = $stmt->fetchAll(PDO::FETCH_ASSOC);
$topDetails = [];
foreach ($topProduits as $row) {
    $s = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit=?");
    $s->execute([$row['produit_id']]);
    $topDetails[] = ['titre' => $s->fetchColumn() ?: 'Inconnu', 'total_qte' => $row['total_qte']];
}
$maxQte = !empty($topDetails) ? max(array_column($topDetails, 'total_qte')) : 1;

// Activité récente
$stmt = $pdo->prepare("SELECT c.numero_commande, c.date_commande, c.heure_commande, c.contact_id, c.produit_id, CAST(c.montant_commande AS DECIMAL(12,2)) as montant, c.etat_commande
                       FROM commande c
                       WHERE c.etat_commande='VALIDEE' AND c.statut_id='012' AND c.date_commande BETWEEN ? AND ?
                       ORDER BY c.date_commande DESC, c.heure_commande DESC LIMIT 8");
$stmt->execute([$date_debut, $date_fin]);
$activite = $stmt->fetchAll(PDO::FETCH_ASSOC);
$actDetails = [];
foreach ($activite as $a) {
    $s = $pdo->prepare("SELECT nom_prenom_contact FROM contact WHERE code_contact=?");
    $s->execute([$a['contact_id']]);
    $client = $s->fetchColumn() ?: $a['contact_id'];
    $s2 = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit=?");
    $s2->execute([$a['produit_id']]);
    $prod = $s2->fetchColumn() ?: $a['produit_id'];
    $actDetails[] = ['numero' => $a['numero_commande'], 'date' => $a['date_commande'], 'heure' => $a['heure_commande'], 'client' => $client, 'produit' => $prod, 'montant' => $a['montant'], 'etat' => $a['etat_commande']];
}

// Évolution des ventes
$debutObj = new DateTime($date_debut);
$finObj = new DateTime($date_fin);
$nbJours = $debutObj->diff($finObj)->days + 1;
if ($nbJours <= 31) $groupBy = "DATE(date_commande)";
elseif ($nbJours <= 93) $groupBy = "WEEK(date_commande)";
else $groupBy = "DATE_FORMAT(date_commande, '%Y-%m')";
$stmt = $pdo->prepare("SELECT $groupBy as periode, SUM(CAST(montant_commande AS DECIMAL(12,2))) as total_ventes
                       FROM commande
                       WHERE etat_commande='VALIDEE' AND statut_id='012' AND date_commande BETWEEN ? AND ?
                       GROUP BY periode ORDER BY periode ASC");
$stmt->execute([$date_debut, $date_fin]);
$evolution = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chartLabels = [];
$chartData = [];
foreach ($evolution as $row) {
    if ($groupBy === "DATE(date_commande)") $chartLabels[] = date('d/m', strtotime($row['periode']));
    elseif ($groupBy === "WEEK(date_commande)") $chartLabels[] = "S" . $row['periode'];
    else $chartLabels[] = date('m/Y', strtotime($row['periode'] . '-01'));
    $chartData[] = floatval($row['total_ventes']);
}
$spPct = function ($v, $t) {
    return $t > 0 ? round(($v / $t) * 100) : 0;
};
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord — <?= e($libelle_periode) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --prpb: #e9d5ff;
            --tl: #0891b2;
            --tll: #ecfeff;
            --tlb: #cffafe;
            --R: 16px;
            --Rs: 10px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg);
            color: var(--bd);
            min-height: 100vh;
            line-height: 1.5;
        }

        ::-webkit-scrollbar {
            width: 5px;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .W {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 28px 52px;
        }

        .hdr {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .hdr-l h1 {
            font-size: 26px;
            font-weight: 800;
            color: var(--dk);
            letter-spacing: -0.02em;
        }
        .hdr-l p {
            font-size: 13px;
            color: var(--mt);
            margin-top: 2px;
            font-weight: 500;
        }
        .hdr-r {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .hdr-badge {
            background: var(--bl);
            border: 1px solid var(--bb);
            color: var(--b);
            padding: 8px 14px;
            border-radius: var(--Rs);
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .hdr-solde {
            background: var(--sucl);
            border: 1px solid var(--sucb);
            color: #065f46;
            padding: 8px 14px;
            border-radius: var(--Rs);
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pbar {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            padding: 16px 20px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .ptabs {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .ptab {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            color: var(--mt);
            background: var(--bg);
            border: 1.5px solid var(--brd);
            transition: all .2s;
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }
        .ptab:hover {
            color: var(--b);
            border-color: var(--bb);
            background: var(--bl);
        }
        .ptab.on {
            background: var(--b);
            color: #fff;
            border-color: var(--b);
            box-shadow: 0 2px 8px rgba(37,99,235,.25);
        }
        .ptab i { font-size: 13px; }

        .prow {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .prow label {
            font-size: 11px;
            font-weight: 600;
            color: var(--mt);
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .prow input, .prow select {
            padding: 7px 10px;
            border: 1.5px solid var(--brd);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: var(--dk);
            background: var(--bg);
            font-family: 'Inter', sans-serif;
            transition: all .2s;
        }
        .prow input:focus, .prow select:focus {
            border-color: var(--b);
            background: #fff;
            box-shadow: 0 0 0 3px var(--bl);
            outline: none;
        }
        .prow select {
            appearance: none;
            padding-right: 32px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }
        .btn-go {
            background: var(--b);
            color: #fff;
            padding: 7px 16px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 4px rgba(37,99,235,.2);
            transition: background .15s;
            border: none;
            cursor: pointer;
        }
        .btn-go:hover { background: var(--bd); }

        .plbl {
            font-size: 12px;
            color: var(--lt);
            font-weight: 500;
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .plbl i { color: var(--b); }

        .sec {
            font-size: 11px;
            font-weight: 700;
            color: var(--mt);
            letter-spacing: .06em;
            text-transform: uppercase;
            margin: 24px 0 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .sec::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--brd);
        }
        .sec i { font-size: 14px; color: var(--b); }

        .kpis {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
        }
        .kpi {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            padding: 16px 18px;
            position: relative;
            overflow: hidden;
            transition: all .2s;
        }
        .kpi:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(15,23,42,.07);
        }
        .kpi::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: var(--R) var(--R) 0 0;
        }
        .kpi.kb::before { background: var(--b); }
        .kpi.kg::before { background: var(--suc); }
        .kpi.kp::before { background: var(--prp); }
        .kpi.ko::before { background: var(--wrn); }
        .kpi.kt::before { background: var(--tl); }

        .kr {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
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
        .ki.ip { background: var(--prpl); color: var(--prp); }
        .ki.io { background: var(--wrnl); color: var(--wrn); }
        .ki.it { background: var(--tll); color: var(--tl); }

        .klb { font-size: 11px; font-weight: 600; color: var(--mt); }
        .kv {
            font-size: 24px;
            font-weight: 900;
            color: var(--dk);
            letter-spacing: -0.03em;
            line-height: 1;
            margin-bottom: 5px;
        }
        .kv.sm { font-size: 18px; }

        .kd {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 5px;
        }
        .kd.u { background: var(--sucl); color: var(--suc); }
        .kd.d { background: var(--dngl); color: var(--dng); }
        .kd.n { background: #f1f5f9; color: var(--mt); }
        .kd i { font-size: 12px; }
        .ks { font-size: 10px; color: var(--lt); margin-left: 2px; }

        .stock-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 280px;
            gap: 12px;
        }
        .stk {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all .2s;
        }
        .stk:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(15,23,42,.05);
        }
        .stk-r {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
        }
        .stk-r.sr { background: var(--dngl); color: var(--dng); border: 2px solid var(--dngb); }
        .stk-r.so { background: var(--wrnl); color: #92400e; border: 2px solid var(--wrnb); }
        .stk-r.sg { background: var(--sucl); color: #065f46; border: 2px solid var(--sucb); }

        .stk-i { flex: 1; }
        .stk-v { font-size: 15px; font-weight: 800; color: var(--dk); }
        .stk-l { font-size: 11px; font-weight: 600; color: var(--mt); }
        .stk-bar {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }
        .stk-f {
            height: 100%;
            border-radius: 2px;
            transition: width .5s;
        }
        .stk-f.sr { background: var(--dng); }
        .stk-f.so { background: var(--wrn); }
        .stk-f.sg { background: var(--suc); }

        .ch-row {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 12px;
        }
        .dt-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .cd {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            overflow: hidden;
            transition: box-shadow .2s;
        }
        .cd:hover { box-shadow: 0 4px 12px rgba(15,23,42,.04); }
        .ci { padding: 18px 20px; }

        .ch {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--brd);
        }
        .ct {
            font-size: 14px;
            font-weight: 700;
            color: var(--dk);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ct i { color: var(--b); font-size: 16px; }
        .cb {
            font-size: 11px;
            font-weight: 600;
            color: var(--b);
            background: var(--bl);
            padding: 3px 10px;
            border-radius: 6px;
            border: 1px solid var(--bb);
        }

        .chbx { height: 260px; position: relative; }
        .pibx { height: 260px; position: relative; }
        .sdbx { height: 200px; position: relative; }

        .ebx {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: var(--lt);
        }
        .ebx i { font-size: 36px; opacity: .1; color: var(--b); margin-bottom: 6px; }
        .ebx p { font-size: 12px; }

        .bl { display: flex; flex-direction: column; gap: 10px; }
        .bi-rk {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 2px;
        }
        .rk {
            width: 22px;
            height: 22px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
            color: var(--b);
            background: var(--bl);
        }
        .rk.g { background: var(--sucl); color: #065f46; }
        .bi-l {
            font-size: 12px;
            font-weight: 600;
            color: var(--dk);
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .bi-n { font-size: 11px; font-weight: 700; color: var(--b); }
        .bi-tk {
            height: 5px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 3px;
        }
        .bi-fl {
            height: 100%;
            border-radius: 3px;
            transition: width .5s;
        }
        .bi-fl.bf { background: linear-gradient(90deg, var(--b), #60a5fa); }
        .bi-fl.bfg { background: linear-gradient(90deg, var(--suc), #6ee7b7); }

        .acts { display: flex; flex-direction: column; gap: 0; }
        .act {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid var(--brd);
        }
        .act:last-child { border-bottom: none; }
        .adot {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }
        .adot.av { background: var(--sucl); color: var(--suc); }
        .adot.aw { background: var(--wrnl); color: var(--wrn); }
        .abdy { flex: 1; min-width: 0; }
        .at {
            font-size: 11px;
            font-weight: 600;
            color: var(--dk);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ad { font-size: 10px; color: var(--mt); margin-top: 1px; }
        .aa { font-size: 10px; font-weight: 700; color: var(--b); margin-top: 1px; }
        .atm {
            font-size: 10px;
            color: var(--lt);
            font-weight: 500;
            white-space: nowrap;
            flex-shrink: 0;
            align-self: center;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .kpi, .stk, .cd {
            animation: fadeUp .4s ease both;
        }
        .kpi:nth-child(2) { animation-delay: .05s; }
        .kpi:nth-child(3) { animation-delay: .1s; }
        .kpi:nth-child(4) { animation-delay: .15s; }
        .kpi:nth-child(5) { animation-delay: .2s; }

        @media (max-width:1100px) {
            .kpis { grid-template-columns: repeat(3, 1fr); }
            .stock-row { grid-template-columns: repeat(2, 1fr); }
            .ch-row, .dt-row { grid-template-columns: 1fr; }
        }
        @media (max-width:700px) {
            .W { padding: 14px; }
            .kpis { grid-template-columns: repeat(2, 1fr); }
            .stock-row { grid-template-columns: 1fr; }
            .hdr { flex-direction: column; align-items: flex-start; }
            .prow { flex-direction: column; align-items: stretch; }
            .plbl { margin-left: 0; }
        }
        @media (max-width:480px) {
            .kpis { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>
    <div class="W">
        <div class="hdr">
            <div class="hdr-l">
                <h1>Tableau de bord</h1>
                <p>Vue d'ensemble de l'activité commerciale en temps réel</p>
            </div>
            <div class="hdr-r">
                <div class="hdr-badge"><i class="bi bi-calendar-check-fill"></i> <?= e($libelle_periode) ?></div>
                <div class="hdr-solde"><i class="bi bi-safe-fill"></i> Caisse : <?= fmt($soldeCaisse) ?> F</div>
            </div>
        </div>

        <form method="POST" id="pForm">
            <input type="hidden" name="periode" id="hP" value="<?= e($periode) ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <div class="pbar">
                <div class="ptabs">
                    <button type="submit" name="periode" value="jour" class="ptab <?= $periode === 'jour' ? 'on' : '' ?>"><i class="bi bi-calendar-date"></i> Jour</button>
                    <button type="submit" name="periode" value="semaine" class="ptab <?= $periode === 'semaine' ? 'on' : '' ?>"><i class="bi bi-calendar-week"></i> Semaine</button>
                    <button type="submit" name="periode" value="mois" class="ptab <?= $periode === 'mois' ? 'on' : '' ?>"><i class="bi bi-calendar-month"></i> Mois</button>
                    <button type="submit" name="periode" value="trimestre" class="ptab <?= $periode === 'trimestre' ? 'on' : '' ?>"><i class="bi bi-calendar3"></i> Trimestre</button>
                    <button type="submit" name="periode" value="semestre" class="ptab <?= $periode === 'semestre' ? 'on' : '' ?>"><i class="bi bi-calendar2"></i> Semestre</button>
                    <button type="submit" name="periode" value="annuel" class="ptab <?= $periode === 'annuel' ? 'on' : '' ?>"><i class="bi bi-calendar-fill"></i> Année</button>
                </div>
                <div class="prow">
                    <?php if ($periode === 'jour'): ?>
                        <label>Date</label><input type="date" name="date_jour" value="<?= e($valJour) ?>">
                    <?php elseif ($periode === 'semaine'): ?>
                        <label>Semaine</label><input type="week" name="date_semaine" value="<?= e($valSemaine) ?>">
                    <?php elseif ($periode === 'mois'): ?>
                        <label>Mois</label><input type="month" name="date_mois" value="<?= e($valMois) ?>">
                    <?php elseif ($periode === 'trimestre'): ?>
                        <label>Trimestre</label><select name="date_trimestre">
                            <?php $ac = date('Y');
                            for ($a = $ac - 2; $a <= $ac + 1; $a++) {
                                for ($t = 1; $t <= 4; $t++) {
                                    $v = "$a-T$t";
                                    echo "<option value=\"$v\"" . ($v == $valTrimestre ? ' selected' : '') . ">T$t $a</option>";
                                }
                            } ?>
                        </select>
                    <?php elseif ($periode === 'semestre'): ?>
                        <label>Semestre</label><select name="date_semestre">
                            <?php $ac = date('Y');
                            for ($a = $ac - 2; $a <= $ac + 1; $a++) {
                                for ($s = 1; $s <= 2; $s++) {
                                    $v = "$a-S$s";
                                    echo "<option value=\"$v\"" . ($v == $valSemestre ? ' selected' : '') . ">S$s $a</option>";
                                }
                            } ?>
                        </select>
                    <?php elseif ($periode === 'annuel'): ?>
                        <label>Année</label><select name="date_annee">
                            <?php $ac = date('Y');
                            for ($a = $ac - 3; $a <= $ac + 1; $a++) {
                                echo "<option value=\"$a\"" . ($a == $valAnnee ? ' selected' : '') . ">$a</option>";
                            } ?>
                        </select>
                    <?php endif; ?>
                    <button type="submit" class="btn-go"><i class="bi bi-arrow-right-circle-fill"></i> Appliquer</button>
                    <div class="plbl"><i class="bi bi-calendar-check"></i> <?= e($libelle_periode) ?></div>
                </div>
            </div>
        </form>

        <div class="sec"><i class="bi bi-speedometer2"></i> Indicateurs de la période</div>
        <div class="kpis">
            <div class="kpi kb">
                <div class="kr">
                    <div class="ki ib"><i class="bi bi-receipt-cutoff"></i></div>
                    <div class="klb">Commandes</div>
                </div>
                <div class="kv"><?= $cmdPeriode ?></div>
                <?php if ($deltaCmd > 0): ?>
                    <span class="kd u"><i class="bi bi-arrow-up-short"></i>+<?= abs($deltaCmd) ?></span><span class="ks">vs <?= e($libelle_periode_prev) ?></span>
                <?php elseif ($deltaCmd < 0): ?>
                    <span class="kd d"><i class="bi bi-arrow-down-short"></i><?= abs($deltaCmd) ?></span><span class="ks">vs <?= e($libelle_periode_prev) ?></span>
                <?php else: ?>
                    <span class="kd n"><i class="bi bi-dash"></i>Stable</span>
                <?php endif; ?>
            </div>
            <div class="kpi kp">
                <div class="kr">
                    <div class="ki ip"><i class="bi bi-cash-stack"></i></div>
                    <div class="klb">Chiffre d'affaires</div>
                </div>
                <div class="kv sm"><?= fmtK($caPeriode) ?> F</div>
                <?php if ($deltaCA > 0): ?>
                    <span class="kd u"><i class="bi bi-arrow-up-short"></i>+<?= fmtK(abs($deltaCA)) ?> F</span><span class="ks">(+<?= $pctCA ?>%)</span>
                <?php elseif ($deltaCA < 0): ?>
                    <span class="kd d"><i class="bi bi-arrow-down-short"></i><?= fmtK(abs($deltaCA)) ?> F</span><span class="ks">(<?= $pctCA ?>%)</span>
                <?php else: ?>
                    <span class="kd n"><i class="bi bi-dash"></i>Stable</span>
                <?php endif; ?>
            </div>
            <div class="kpi kt">
                <div class="kr">
                    <div class="ki it"><i class="bi bi-cart4"></i></div>
                    <div class="klb">Panier moyen</div>
                </div>
                <div class="kv sm"><?= fmt($panierMoyen) ?> F</div>
                <?php if ($deltaPanier > 0): ?>
                    <span class="kd u"><i class="bi bi-arrow-up-short"></i>+<?= fmt(abs($deltaPanier)) ?> F</span>
                <?php elseif ($deltaPanier < 0): ?>
                    <span class="kd d"><i class="bi bi-arrow-down-short"></i><?= fmt(abs($deltaPanier)) ?> F</span>
                <?php else: ?>
                    <span class="kd n"><i class="bi bi-dash"></i>Stable</span>
                <?php endif; ?>
            </div>
            <div class="kpi kg">
                <div class="kr">
                    <div class="ki ig"><i class="bi bi-people-fill"></i></div>
                    <div class="klb">Clients actifs</div>
                </div>
                <div class="kv"><?= $totalClients ?></div>
                <span class="kd n"><i class="bi bi-dash"></i>Total</span><span class="ks">enregistrés</span>
            </div>
            <div class="kpi ko">
                <div class="kr">
                    <div class="ki io"><i class="bi bi-hourglass-split"></i></div>
                    <div class="klb">En attente</div>
                </div>
                <div class="kv"><?= $cmdAttente ?></div>
                <span class="kd n"><i class="bi bi-dash"></i>Sur la période</span>
            </div>
        </div>

        <div class="sec"><i class="bi bi-box-seam-fill"></i> État des stocks</div>
        <div class="stock-row">
            <div class="stk">
                <div class="stk-r sr"><?= $stockNul ?></div>
                <div class="stk-i">
                    <div class="stk-v"><?= $stockNul ?> produits</div>
                    <div class="stk-l">Stock nul — Rupture</div>
                    <div class="stk-bar">
                        <div class="stk-f sr" style="width:<?= $spPct($stockNul, $totalProduits) ?>%"></div>
                    </div>
                </div>
            </div>
            <div class="stk">
                <div class="stk-r so"><?= $stockAlerte ?></div>
                <div class="stk-i">
                    <div class="stk-v"><?= $stockAlerte ?> produits</div>
                    <div class="stk-l">En alerte — Seuil critique</div>
                    <div class="stk-bar">
                        <div class="stk-f so" style="width:<?= $spPct($stockAlerte, $totalProduits) ?>%"></div>
                    </div>
                </div>
            </div>
            <div class="stk">
                <div class="stk-r sg"><?= $stockOk ?></div>
                <div class="stk-i">
                    <div class="stk-v"><?= $stockOk ?> produits</div>
                    <div class="stk-l">Stock suffisant — OK</div>
                    <div class="stk-bar">
                        <div class="stk-f sg" style="width:<?= $spPct($stockOk, $totalProduits) ?>%"></div>
                    </div>
                </div>
            </div>
            <div class="cd">
                <div class="ci">
                    <div class="ch">
                        <div class="ct"><i class="bi bi-pie-chart-fill"></i> Distribution</div>
                        <div class="cb"><?= $totalProduits ?> produits</div>
                    </div>
                    <div class="sdbx"><canvas id="stockPie"></canvas></div>
                </div>
            </div>
        </div>

        <div class="sec"><i class="bi bi-bar-chart-line-fill"></i> Analyse commerciale</div>
        <div class="ch-row">
            <div class="cd">
                <div class="ci">
                    <div class="ch">
                        <div class="ct"><i class="bi bi-graph-up"></i> Évolution des ventes</div>
                        <div class="cb"><?= e($libelle_periode) ?></div>
                    </div>
                    <?php if (empty($chartData)): ?>
                        <div class="ebx"><i class="bi bi-bar-chart"></i><p>Aucune donnée pour cette période.</p></div>
                    <?php else: ?>
                        <div class="chbx"><canvas id="vChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="cd">
                <div class="ci">
                    <div class="ch">
                        <div class="ct"><i class="bi bi-credit-card-2-front-fill"></i> Modes de règlement</div>
                        <div class="cb"><?= fmtK($caPeriode) ?> F</div>
                    </div>
                    <?php if (empty($regValues)): ?>
                        <div class="ebx"><i class="bi bi-pie-chart"></i><p>Aucune donnée de règlement.</p></div>
                    <?php else: ?>
                        <div class="pibx"><canvas id="pieChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="sec"><i class="bi bi-list-check"></i> Détails</div>
        <div class="dt-row">
            <div class="cd">
                <div class="ci">
                    <div class="ch">
                        <div class="ct"><i class="bi bi-trophy-fill"></i> Top 5 produits vendus</div>
                        <div class="cb"><?= $cmdPeriode ?> commandes</div>
                    </div>
                    <?php if (empty($topDetails)): ?>
                        <div class="ebx" style="padding:20px"><i class="bi bi-trophy"></i><p>Aucune vente.</p></div>
                    <?php else: ?>
                        <div class="bl">
                            <?php foreach ($topDetails as $i => $item): ?>
                                <div>
                                    <div class="bi-rk">
                                        <div class="rk <?= $i === 0 ? 'g' : '' ?>"><?= $i + 1 ?></div>
                                        <span class="bi-l"><?= e($item['titre']) ?></span>
                                        <span class="bi-n"><?= $item['total_qte'] ?> vendus</span>
                                    </div>
                                    <div class="bi-tk">
                                        <div class="bi-fl <?= $i === 0 ? 'bfg' : 'bf' ?>" style="width:<?= round(($item['total_qte'] / $maxQte) * 100) ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="cd">
                <div class="ci">
                    <div class="ch">
                        <div class="ct"><i class="bi bi-lightning-fill"></i> Activité récente</div>
                        <div class="cb"><?= e($libelle_periode) ?></div>
                    </div>
                    <?php if (empty($actDetails)): ?>
                        <div class="ebx" style="padding:20px"><i class="bi bi-clock-history"></i><p>Aucune activité.</p></div>
                    <?php else: ?>
                        <div class="acts">
                            <?php foreach ($actDetails as $a): ?>
                                <?php $isV = $a['etat'] === 'VALIDEE'; ?>
                                <div class="act">
                                    <div class="adot <?= $isV ? 'av' : 'aw' ?>"><i class="bi <?= $isV ? 'bi-check-circle-fill' : 'bi-hourglass' ?>"></i></div>
                                    <div class="abdy">
                                        <div class="at"><?= e($a['client']) ?></div>
                                        <div class="ad"><?= e($a['produit']) ?></div>
                                        <?php if ($a['montant'] > 0): ?>
                                            <div class="aa"><?= fmt($a['montant']) ?> F</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="atm"><?= e($a['date']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
        const centerPlugin = {
            id: 'centerText',
            afterDraw(chart) {
                const opts = chart.config.options.plugins.centerText;
                if (!opts) return;
                const { ctx, chartArea: { left, right, top, bottom } } = chart;
                const cx = (left + right) / 2, cy = (top + bottom) / 2;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = '800 20px Inter';
                ctx.fillStyle = '#0f172a';
                ctx.fillText(opts.line1, cx, cy - 8);
                ctx.font = '600 11px Inter';
                ctx.fillStyle = '#64748b';
                ctx.fillText(opts.line2, cx, cy + 12);
                ctx.restore();
            }
        };
        Chart.register(centerPlugin);
        <?php if (!empty($chartData)): ?>
            const cx = document.getElementById('vChart').getContext('2d');
            const gr = cx.createLinearGradient(0, 0, 0, 260);
            gr.addColorStop(0, 'rgba(37,99,235,.85)');
            gr.addColorStop(1, 'rgba(37,99,235,.25)');
            new Chart(cx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [{
                        data: <?= json_encode($chartData) ?>,
                        backgroundColor: gr,
                        hoverBackgroundColor: 'rgba(30,64,175,1)',
                        borderRadius: 7,
                        borderSkipped: false,
                        barPercentage: .5,
                        categoryPercentage: .7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            titleFont: { family: 'Inter', size: 12, weight: '700' },
                            bodyFont: { family: 'Inter', size: 11, weight: '600' },
                            padding: 10,
                            cornerRadius: 8,
                            displayColors: false,
                            callbacks: {
                                label: c => new Intl.NumberFormat('fr-FR').format(c.raw) + ' F'
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { font: { family: 'Inter', size: 11, weight: '600' }, color: '#64748b' },
                            border: { display: false }
                        },
                        y: {
                            grid: { color: '#f1f5f9' },
                            ticks: {
                                font: { family: 'Inter', size: 10, weight: '500' },
                                color: '#94a3b8',
                                callback: v => {
                                    if (v >= 1e6) return (v / 1e6).toFixed(1) + 'M';
                                    if (v >= 1e3) return (v / 1e3).toFixed(0) + 'k';
                                    return v;
                                }
                            },
                            border: { display: false },
                            beginAtZero: true
                        }
                    },
                    animation: { duration: 700, easing: 'easeOutQuart' }
                }
            });
        <?php endif; ?>
        <?php if (!empty($regValues)): ?>
            const rl = <?= json_encode($regLabels) ?>;
            const rv = <?= json_encode($regValues) ?>;
            const rcMap = <?= json_encode($regColorMap) ?>;
            const rllMap = <?= json_encode($regLabelMap) ?>;
            const rc = rl.map(l => {
                const key = Object.keys(rllMap).find(k => rllMap[k] === l) || l;
                return rcMap[key] || '#64748b';
            });
            new Chart(document.getElementById('pieChart'), {
                type: 'doughnut',
                data: {
                    labels: rl,
                    datasets: [{
                        data: rv,
                        backgroundColor: rc,
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
                        centerText: {
                            line1: '<?= fmtK($caPeriode) ?> F',
                            line2: 'CA total'
                        },
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 9,
                                boxHeight: 9,
                                borderRadius: 2,
                                useBorderRadius: true,
                                padding: 12,
                                font: { family: 'Inter', size: 11, weight: '600' },
                                color: '#64748b'
                            }
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            titleFont: { family: 'Inter', size: 12, weight: '700' },
                            bodyFont: { family: 'Inter', size: 11, weight: '600' },
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: {
                                label: c => {
                                    const total = c.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = total > 0 ? ((c.raw / total) * 100).toFixed(1) : 0;
                                    return new Intl.NumberFormat('fr-FR').format(c.raw) + ' F (' + pct + '%)';
                                }
                            }
                        }
                    },
                    animation: { animateRotate: true, duration: 700 }
                }
            });
        <?php endif; ?>
        new Chart(document.getElementById('stockPie'), {
            type: 'doughnut',
            data: {
                labels: ['Rupture', 'Alerte', 'OK'],
                datasets: [{
                    data: [<?= $stockNul ?>, <?= $stockAlerte ?>, <?= $stockOk ?>],
                    backgroundColor: ['#ef4444', '#f59e0b', '#10b981'],
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
                    centerText: {
                        line1: '<?= $totalProduits ?>',
                        line2: 'produits'
                    },
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 9,
                            boxHeight: 9,
                            borderRadius: 2,
                            useBorderRadius: true,
                            padding: 10,
                            font: { family: 'Inter', size: 10, weight: '600' },
                            color: '#64748b'
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: c => {
                                const total = <?= $totalProduits ?>;
                                const pct = total > 0 ? ((c.raw / total) * 100).toFixed(0) : 0;
                                return c.label + ' : ' + c.raw + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                animation: { animateRotate: true, duration: 700 }
            }
        });
    </script>
</body>

</html>