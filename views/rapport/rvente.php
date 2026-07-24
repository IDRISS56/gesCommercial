<?php
// ============================================
// PAGE 2 : VENTES, CLIENTS & ENCAISSEMENTS
// "L'Argent qui rentre" — Design Compact Bleu — FCFA
// ============================================

$host = '127.0.0.1';
$db   = 'gescommercial';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Erreur : " . $e->getMessage());
}

// Bilan ventes
$bilanVentes = $pdo->query("
    SELECT c.numero_commande, c.date_commande, c.heure_commande, c.prix_achat, c.prix_commande,
        c.quantite_commande, c.montant_commande, c.etat_commande, c.unite_affichage, c.statut_id, c.facture_id,
        p.titre_produit, p.code_produit, p.prix_fournisseur,
        ct.nom_prenom_contact AS client, ct.code_contact,
        b.nom_boutique, b.code_boutique,
        cat.titre_categorie, cat.code_categorie,
        (CAST(COALESCE(c.montant_commande,'0') AS DECIMAL(12,2))/1.2) AS montant_ht_calc,
        (CAST(COALESCE(c.montant_commande,'0') AS DECIMAL(12,2))-CAST(COALESCE(c.montant_commande,'0') AS DECIMAL(12,2))/1.2) AS tva_calc,
        (CAST(COALESCE(c.montant_commande,'0') AS DECIMAL(12,2))-(CAST(COALESCE(c.prix_achat,'0') AS DECIMAL(12,2))*c.quantite_commande)) AS benefice_calc
    FROM commande c LEFT JOIN produit p ON c.produit_id=p.code_produit
    LEFT JOIN contact ct ON c.contact_id=ct.code_contact LEFT JOIN boutique b ON c.boutique_id=b.code_boutique
    LEFT JOIN categorie cat ON p.categorie_id=cat.code_categorie
    WHERE c.statut_id IN ('ST002','Vente','012') ORDER BY c.date_commande DESC, c.heure_commande DESC
")->fetchAll();

$totauxVentes = $pdo->query("
    SELECT COALESCE(SUM(quantite_commande),0) AS total_qte,
        COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0) AS total_ttc,
        COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))/1.2),0) AS total_ht,
        COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))-CAST(montant_commande AS DECIMAL(12,2))/1.2),0) AS total_tva,
        COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))-(CAST(COALESCE(prix_achat,'0') AS DECIMAL(12,2))*quantite_commande)),0) AS total_benefice,
        COUNT(*) AS nb_ventes FROM commande WHERE statut_id IN ('ST002','Vente','012')
")->fetch();
$totalTTC = $totauxVentes['total_ttc'];
$totalHT = $totauxVentes['total_ht'];
$totalTVA = $totauxVentes['total_tva'];
$totalBenefice = $totauxVentes['total_benefice'];
$totalQte = $totauxVentes['total_qte'];
$nbVentes = $totauxVentes['nb_ventes'];
$panierMoyen = $nbVentes > 0 ? $totalTTC / $nbVentes : 0;
$margeBenefice = $totalTTC > 0 ? ($totalBenefice / $totalTTC) * 100 : 0;

$evolutionCA = $pdo->query("
    SELECT DATE_FORMAT(date_commande,'%Y-%m') AS mois, COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))),0) AS ca, COUNT(*) AS nb_ventes
    FROM commande WHERE statut_id IN ('ST002','Vente','012') AND date_commande IS NOT NULL
    GROUP BY DATE_FORMAT(date_commande,'%Y-%m') ORDER BY mois ASC
")->fetchAll();

$ventesParCategorie = $pdo->query("
    SELECT cat.titre_categorie, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca, SUM(c.quantite_commande) AS qte
    FROM commande c LEFT JOIN produit p ON c.produit_id=p.code_produit LEFT JOIN categorie cat ON p.categorie_id=cat.code_categorie
    WHERE c.statut_id IN ('ST002','Vente','012') GROUP BY cat.code_categorie ORDER BY ca DESC
")->fetchAll();

$topProduitsVendus = $pdo->query("
    SELECT p.code_produit, p.titre_produit, cat.titre_categorie, SUM(c.quantite_commande) AS qte_vendue,
        COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca, COUNT(c.numero_commande) AS nb_ventes
    FROM commande c LEFT JOIN produit p ON c.produit_id=p.code_produit LEFT JOIN categorie cat ON p.categorie_id=cat.code_categorie
    WHERE c.statut_id IN ('ST002','Vente','012') GROUP BY p.code_produit ORDER BY qte_vendue DESC LIMIT 10
")->fetchAll();

$topProduitsRentables = $pdo->query("
    SELECT p.code_produit, p.titre_produit, cat.titre_categorie, SUM(c.quantite_commande) AS qte_vendue,
        COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca,
        COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))-(CAST(COALESCE(c.prix_achat,'0') AS DECIMAL(12,2))*c.quantite_commande)),0) AS benefice
    FROM commande c LEFT JOIN produit p ON c.produit_id=p.code_produit LEFT JOIN categorie cat ON p.categorie_id=cat.code_categorie
    WHERE c.statut_id IN ('ST002','Vente','012') GROUP BY p.code_produit ORDER BY benefice DESC LIMIT 10
")->fetchAll();

$topProduitsMoinsVendus = $pdo->query("
    SELECT p.code_produit, p.titre_produit, cat.titre_categorie, COALESCE(SUM(c.quantite_commande),0) AS qte_vendue,
        COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca
    FROM produit p LEFT JOIN commande c ON p.code_produit=c.produit_id AND c.statut_id IN ('ST002','Vente','012')
    LEFT JOIN categorie cat ON p.categorie_id=cat.code_categorie WHERE p.etat_produit='Actif'
    GROUP BY p.code_produit ORDER BY qte_vendue ASC LIMIT 10
")->fetchAll();

$produitsSansMouvement = $pdo->query("
    SELECT p.code_produit, p.titre_produit, cat.titre_categorie, p.stock_produit, sb.quantite AS stock_actuel
    FROM produit p LEFT JOIN commande c ON p.code_produit=c.produit_id AND c.statut_id IN ('ST002','Vente','012')
    LEFT JOIN categorie cat ON p.categorie_id=cat.code_categorie LEFT JOIN stock_boutique sb ON p.code_produit=sb.produit_id
    WHERE p.etat_produit='Actif' AND c.numero_commande IS NULL GROUP BY p.code_produit
")->fetchAll();

$topClients = $pdo->query("
    SELECT ct.code_contact, ct.nom_prenom_contact, ct.telephone_contact, ct.email_contact, ct.adresse_contact,
        COUNT(c.numero_commande) AS nb_achats, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca_total,
        COALESCE(SUM(c.quantite_commande),0) AS qte_totale, MIN(c.date_commande) AS premier_achat, MAX(c.date_commande) AS dernier_achat
    FROM contact ct LEFT JOIN commande c ON ct.code_contact=c.contact_id AND c.statut_id IN ('ST002','Vente','012')
    WHERE ct.type_contact='CLIENT' GROUP BY ct.code_contact ORDER BY ca_total DESC LIMIT 10
")->fetchAll();

$nouveauxClients = $pdo->query("
    SELECT ct.code_contact, ct.nom_prenom_contact, ct.telephone_contact, ct.email_contact,
        MIN(c.date_commande) AS date_premier_achat, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca_initial
    FROM contact ct INNER JOIN commande c ON ct.code_contact=c.contact_id AND c.statut_id IN ('ST002','Vente','012')
    WHERE ct.type_contact='CLIENT' GROUP BY ct.code_contact HAVING date_premier_achat >= DATE_FORMAT(CURDATE(),'%Y-%m-01') ORDER BY date_premier_achat DESC
")->fetchAll();

$clientsInactifs = $pdo->query("
    SELECT ct.code_contact, ct.nom_prenom_contact, ct.telephone_contact, ct.email_contact,
        MAX(c.date_commande) AS dernier_achat, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca_total,
        DATEDIFF(CURDATE(),MAX(c.date_commande)) AS jours_inactif
    FROM contact ct INNER JOIN commande c ON ct.code_contact=c.contact_id AND c.statut_id IN ('ST002','Vente','012')
    WHERE ct.type_contact='CLIENT' GROUP BY ct.code_contact HAVING jours_inactif>90 ORDER BY jours_inactif DESC
")->fetchAll();

// Creances
$facturesImpayees = $pdo->query("
    SELECT f.numero_facture, f.titre_facture, f.date_facture, f.montant_ht, f.taxe, f.remise, f.montant_ttc,
        f.avance, f.reste, f.etat_facture, f.type_facture, ct.nom_prenom_contact AS client, ct.code_contact,
        DATEDIFF(CURDATE(),f.date_facture) AS jours_retard
    FROM facture f LEFT JOIN contact ct ON f.contact_id=ct.code_contact
    WHERE CAST(f.reste AS DECIMAL(12,2))>0 AND (ct.type_contact='CLIENT' OR f.categorie_facture='CLIENT' OR f.type_facture='VENTE')
    ORDER BY CAST(f.reste AS DECIMAL(12,2)) DESC
")->fetchAll();

$totalCreances = $pdo->query("
    SELECT COALESCE(SUM(CAST(f.reste AS DECIMAL(12,2))),0) AS total FROM facture f LEFT JOIN contact ct ON f.contact_id=ct.code_contact
    WHERE CAST(f.reste AS DECIMAL(12,2))>0 AND (ct.type_contact='CLIENT' OR f.categorie_facture='CLIENT' OR f.type_facture='VENTE')
")->fetch()['total'];

$aging = $pdo->query("
    SELECT SUM(CASE WHEN DATEDIFF(CURDATE(),f.date_facture) BETWEEN 1 AND 30 THEN CAST(f.reste AS DECIMAL(12,2)) ELSE 0 END) AS aging_30,
        SUM(CASE WHEN DATEDIFF(CURDATE(),f.date_facture) BETWEEN 31 AND 60 THEN CAST(f.reste AS DECIMAL(12,2)) ELSE 0 END) AS aging_60,
        SUM(CASE WHEN DATEDIFF(CURDATE(),f.date_facture) BETWEEN 61 AND 90 THEN CAST(f.reste AS DECIMAL(12,2)) ELSE 0 END) AS aging_90,
        SUM(CASE WHEN DATEDIFF(CURDATE(),f.date_facture)>90 THEN CAST(f.reste AS DECIMAL(12,2)) ELSE 0 END) AS aging_plus
    FROM facture f LEFT JOIN contact ct ON f.contact_id=ct.code_contact
    WHERE CAST(f.reste AS DECIMAL(12,2))>0 AND (ct.type_contact='CLIENT' OR f.categorie_facture='CLIENT' OR f.type_facture='VENTE')
")->fetch();

$paiementsParMode = $pdo->query("
    SELECT COALESCE(mode_reglement,'Non specifie') AS mode, COUNT(*) AS nb_transactions,
        COALESCE(SUM(CAST(montant_transaction AS DECIMAL(12,2))),0) AS montant_total
    FROM transaction WHERE type_transaction IN ('Encaissement','Paiement') AND etat_transaction IN ('Succes','Valide')
    GROUP BY mode_reglement ORDER BY montant_total DESC
")->fetchAll();

$totalPaiements = $pdo->query("
    SELECT COALESCE(SUM(CAST(montant_transaction AS DECIMAL(12,2))),0) AS total_montant, COUNT(*) AS nb_transactions
    FROM transaction WHERE type_transaction IN ('Encaissement','Paiement') AND etat_transaction IN ('Succes','Valide')
")->fetch();

$historiquePaiements = $pdo->query("
    SELECT t.numero_transaction, t.date_transaction, t.heure_transaction, t.montant_transaction, t.montant_total,
        t.frais_transaction, t.type_transaction, t.objet_transaction, t.mode_reglement, t.etat_transaction,
        ct.nom_prenom_contact AS client, f.numero_facture
    FROM transaction t LEFT JOIN contact ct ON t.contact_id=ct.code_contact LEFT JOIN facture f ON t.facture_id=f.numero_facture
    WHERE t.type_transaction IN ('Encaissement','Paiement') ORDER BY t.date_transaction DESC, t.heure_transaction DESC LIMIT 100
")->fetchAll();

$categories = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie WHERE etat_categorie='Actif'")->fetchAll();
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique='Actif'")->fetchAll();
$clientsList = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact='CLIENT'")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventes, Clients & Encaissements</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1e40af;
            --primary-dark: #1e3a8a;
            --primary-deeper: #172554;
            --primary-mid: #2563eb;
            --primary-light: #dbeafe;
            --primary-glow: rgba(30, 64, 175, 0.22);
            --primary-glow-soft: rgba(37, 99, 235, 0.12);
            --accent: #d97706;
            --accent-glow: rgba(217, 119, 6, 0.18);
            --success: #059669;
            --success-light: #d1fae5;
            --danger: #dc2626;
            --danger-light: #fee2e2;
            --warning: #ea580c;
            --warning-light: #ffedd5;
            --info: #0284c7;
            --info-light: #e0f2fe;
            --purple: #7c3aed;
            --purple-light: #ede9fe;
            --dark: #0f172a;
            --text: #0f172a;
            --text-mid: #475569;
            --text-soft: #94a3b8;
            --body: #f1f5f9;
            --card: #ffffff;
            --card-alt: #f8fafc;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.04);
            --shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            --shadow-lg: 0 12px 32px rgba(15, 23, 42, 0.12);
            --shadow-xl: 0 20px 48px rgba(15, 23, 42, 0.16);
            --radius: 14px;
            --radius-sm: 8px;
            --radius-xs: 6px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background: var(--body);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(ellipse 80% 50% at 15% 0%, var(--primary-glow), transparent 60%), radial-gradient(ellipse 50% 35% at 85% 90%, var(--accent-glow), transparent 50%), linear-gradient(180deg, #f1f5f9, #e2e8f0);
            z-index: -1;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%231e40af' fill-opacity='0.025'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6z'/%3E%3C/g%3E%3C/svg%3E");
            z-index: -1;
            pointer-events: none;
            opacity: 0.6;
        }

        .page-wrapper {
            max-width: 1360px;
            margin: 0 auto;
            padding: 20px 28px 60px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border);
        }

        .page-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-mid), var(--primary));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            box-shadow: var(--shadow), 0 0 20px var(--primary-glow);
        }

        .page-header-text h1 {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.3px;
        }

        .page-header-text p {
            font-size: 0.75rem;
            color: var(--text-soft);
            margin-top: 1px;
        }

        .page-header-right {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .header-badge {
            background: var(--primary-light);
            border: 1px solid rgba(37, 99, 235, 0.2);
            color: var(--primary);
            padding: 7px 14px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .header-badge.green {
            background: var(--success-light);
            border-color: rgba(5, 150, 105, 0.2);
            color: var(--success);
        }

        .header-badge.red {
            background: var(--danger-light);
            border-color: rgba(220, 38, 38, 0.2);
            color: var(--danger);
        }

        .kpi-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .kpi-card {
            background: var(--card);
            border-radius: 10px;
            padding: 12px 14px 10px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            overflow: hidden;
            cursor: default;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 3px;
            height: 100%;
            background: linear-gradient(180deg, var(--primary-mid), var(--accent));
            transform: scaleY(0);
            transition: transform 0.3s;
        }

        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: var(--primary-mid);
        }

        .kpi-card:hover::before {
            transform: scaleY(1);
        }

        .kpi-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            color: #fff;
            flex-shrink: 0;
        }

        .kpi-icon.blue {
            background: linear-gradient(135deg, var(--primary-mid), var(--primary));
        }

        .kpi-icon.blue-dark {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .kpi-icon.emerald {
            background: linear-gradient(135deg, var(--success), #047857);
        }

        .kpi-icon.amber {
            background: linear-gradient(135deg, var(--accent), #b45309);
        }

        .kpi-icon.rose {
            background: linear-gradient(135deg, #e11d48, #be123c);
        }

        .kpi-icon.violet {
            background: linear-gradient(135deg, var(--purple), #6d28d9);
        }

        .kpi-icon.sky {
            background: linear-gradient(135deg, #0284c7, #0369a1);
        }

        .kpi-body {
            flex: 1;
            min-width: 0;
        }

        .kpi-value {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .kpi-value.blue {
            color: var(--primary-mid);
        }

        .kpi-value.green {
            color: var(--success);
        }

        .kpi-value.red {
            color: var(--danger);
        }

        .kpi-value.orange {
            color: var(--warning);
        }

        .kpi-value.purple {
            color: var(--purple);
        }

        .kpi-label {
            font-size: 0.7rem;
            color: var(--text-soft);
            font-weight: 500;
            margin-top: 1px;
        }

        .kpi-spark {
            font-size: 0.62rem;
            padding: 2px 6px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            margin-top: 3px;
        }

        .kpi-spark.up {
            background: var(--success-light);
            color: var(--success);
        }

        .kpi-spark.down {
            background: var(--danger-light);
            color: var(--danger);
        }

        .filters-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            padding: 14px 16px;
            background: var(--card-alt);
            border-radius: 10px;
            border: 1px solid var(--border);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 120px;
        }

        .filter-group label {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--text-soft);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .filter-group label i {
            color: var(--primary-mid);
            font-size: 0.6rem;
        }

        .filter-group select,
        .filter-group input[type="date"] {
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-xs);
            font-family: inherit;
            font-size: 0.82rem;
            background: #fff;
            color: var(--text);
            transition: all 0.25s;
            appearance: none;
        }

        .filter-group select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 28px;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary-mid);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .filter-actions {
            display: flex;
            gap: 6px;
            align-items: flex-end;
        }

        .btn-filter {
            padding: 7px 14px;
            background: var(--primary-mid);
            color: #fff;
            border: none;
            border-radius: var(--radius-xs);
            cursor: pointer;
            font-family: inherit;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.25s;
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .btn-filter:hover {
            background: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 3px 10px var(--primary-glow);
        }

        .btn-filter.secondary {
            background: #fff;
            color: var(--text-mid);
            border: 1px solid var(--border);
        }

        .btn-filter.secondary:hover {
            background: var(--card-alt);
            color: var(--dark);
        }

        .search-bar {
            position: relative;
            margin-bottom: 14px;
            max-width: 380px;
        }

        .search-bar input {
            width: 100%;
            padding: 9px 14px 9px 38px;
            border: 1px solid var(--border);
            border-radius: var(--radius-xs);
            font-family: inherit;
            font-size: 0.82rem;
            background: #fff;
            color: var(--text);
            transition: all 0.25s;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary-mid);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .search-bar .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-soft);
            font-size: 0.78rem;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .chart-card {
            background: var(--card-alt);
            border-radius: 10px;
            padding: 16px 18px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .chart-card:hover {
            box-shadow: var(--shadow);
            border-color: var(--primary-mid);
        }

        .chart-card h4 {
            font-size: 0.88rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .chart-card h4 i {
            color: var(--primary-mid);
            font-size: 0.82rem;
        }

        .tabs {
            display: flex;
            gap: 4px;
            background: var(--card);
            padding: 8px 10px;
            border-radius: var(--radius) var(--radius) 0 0;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            border-bottom: none;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 9px 18px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-soft);
            border-radius: var(--radius-xs);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tab-btn:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .tab-btn.active {
            background: var(--primary-mid);
            color: #fff;
            box-shadow: 0 3px 10px var(--primary-glow);
        }

        .tab-btn .count-badge {
            background: var(--danger);
            color: #fff;
            font-size: 0.62rem;
            padding: 1px 5px;
            border-radius: 8px;
            font-weight: 700;
        }

        .tab-btn.active .count-badge {
            background: rgba(255, 255, 255, 0.25);
        }

        .tab-content {
            display: none;
            padding: 24px 28px 28px;
            background: var(--card);
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            border-top: none;
            margin-bottom: 28px;
            animation: fadeIn 0.3s;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark);
            margin: 24px 0 14px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary-mid);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .section-title:first-child {
            margin-top: 0;
        }

        .section-title-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title-left i {
            color: var(--primary-mid);
            font-size: 0.85rem;
        }

        .section-actions {
            display: flex;
            gap: 6px;
        }

        .btn-action {
            padding: 6px 12px;
            background: var(--card-alt);
            color: var(--text-mid);
            border: 1px solid var(--border);
            border-radius: var(--radius-xs);
            cursor: pointer;
            font-family: inherit;
            font-size: 0.78rem;
            font-weight: 600;
            transition: all 0.25s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .btn-action:hover {
            background: var(--primary-mid);
            color: #fff;
            border-color: var(--primary-mid);
            box-shadow: 0 2px 8px var(--primary-glow);
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid var(--border);
            margin-bottom: 20px;
            background: #fff;
        }

        .table-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            background: var(--card-alt);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h5 {
            font-size: 0.82rem;
            font-weight: 700;
            margin: 0;
            color: var(--dark);
        }

        .table-header .count {
            font-size: 0.72rem;
            color: var(--text-soft);
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        thead {
            background: linear-gradient(135deg, var(--primary-deeper), var(--primary-dark));
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        th {
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            border-bottom: none;
        }

        td {
            padding: 9px 14px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
            vertical-align: middle;
        }

        tbody tr {
            transition: background 0.15s;
        }

        tbody tr:hover {
            background: var(--primary-light);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tfoot td {
            background: var(--card-alt);
            font-weight: 700;
            color: var(--dark);
            border-top: 2px solid var(--primary-mid);
            border-bottom: none;
        }

        .badge {
            padding: 3px 9px;
            border-radius: 16px;
            font-size: 0.68rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-success {
            background: var(--success-light);
            color: #065f46;
        }

        .badge-warning {
            background: var(--warning-light);
            color: #9a3412;
        }

        .badge-danger {
            background: var(--danger-light);
            color: #991b1b;
        }

        .badge-info {
            background: var(--info-light);
            color: #075985;
        }

        .badge-purple {
            background: var(--purple-light);
            color: #5b21b6;
        }

        .badge-secondary {
            background: #e2e8f0;
            color: #475569;
        }

        .badge-blue {
            background: var(--primary-light);
            color: var(--primary);
        }

        .badge-amber {
            background: #fef3c7;
            color: #92400e;
        }

        .aging-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .aging-card {
            padding: 14px;
            border-radius: 10px;
            text-align: center;
            color: #fff;
            transition: transform 0.2s;
        }

        .aging-card:hover {
            transform: translateY(-3px);
        }

        .aging-card h5 {
            font-size: 0.78rem;
            opacity: 0.9;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .aging-card .number {
            font-size: 1.3rem;
            font-weight: 800;
        }

        .aging-card p {
            font-size: 0.68rem;
            opacity: 0.8;
            margin-top: 4px;
        }

        .aging-30 {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .aging-60 {
            background: linear-gradient(135deg, #f97316, #ea580c);
        }

        .aging-90 {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .aging-plus {
            background: linear-gradient(135deg, #991b1b, #7f1d1d);
        }

        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            font-weight: 700;
            font-size: 0.78rem;
            color: #fff;
        }

        .rank-1 {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
        }

        .rank-2 {
            background: linear-gradient(135deg, #9ca3af, #6b7280);
        }

        .rank-3 {
            background: linear-gradient(135deg, #d97706, #b45309);
        }

        .rank-other {
            background: var(--primary-mid);
        }

        .creances-summary {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: linear-gradient(135deg, var(--danger-light), #fff1f2);
            border-radius: var(--radius-sm);
            border: 1px solid rgba(220, 38, 38, 0.15);
            margin-bottom: 16px;
        }

        .creances-summary i {
            font-size: 1.1rem;
            color: var(--danger);
        }

        .creances-summary .total {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--danger);
        }

        .creances-summary .text {
            font-size: 0.78rem;
            color: var(--text-mid);
        }

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            background: var(--card);
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 280px;
            animation: toastIn 0.3s;
            border-left: 4px solid;
        }

        .toast.success {
            border-color: var(--success);
        }

        .toast.error {
            border-color: var(--danger);
        }

        .toast.warning {
            border-color: var(--warning);
        }

        .toast.info {
            border-color: var(--primary-mid);
        }

        .toast-icon {
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .toast.success .toast-icon {
            color: var(--success);
        }

        .toast.error .toast-icon {
            color: var(--danger);
        }

        .toast.info .toast-icon {
            color: var(--primary-mid);
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-weight: 700;
            font-size: 0.82rem;
        }

        .toast-message {
            font-size: 0.75rem;
            color: var(--text-soft);
        }

        .toast-close {
            background: none;
            border: none;
            color: var(--text-soft);
            cursor: pointer;
            font-size: 1rem;
        }

        @keyframes toastIn {
            from {
                transform: translateX(120%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes toastOut {
            from {
                opacity: 1;
            }

            to {
                transform: translateX(120%);
                opacity: 0;
            }
        }

        .toast.hiding {
            animation: toastOut 0.3s forwards;
        }

        .empty-cell {
            text-align: center;
            padding: 30px 16px;
            color: var(--text-soft);
            font-size: 0.85rem;
        }

        .split-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }

        @media(max-width:1100px) {
            .kpi-row {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media(max-width:768px) {
            .page-wrapper {
                padding: 12px 10px 40px;
            }

            .kpi-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .tab-content {
                padding: 16px 12px 20px;
            }

            .charts-grid,
            .split-row {
                grid-template-columns: 1fr;
            }

            .aging-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .page-header {
                flex-direction: column;
                gap: 10px;
            }
        }

        @media(max-width:480px) {
            .kpi-row {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media print {

            .tabs,
            .filters-bar,
            .section-actions,
            .btn-action,
            .search-bar,
            .toast-container {
                display: none !important;
            }

            .tab-content {
                display: block !important;
                margin: 0;
                box-shadow: none;
                border: none;
                padding: 0;
            }

            body {
                background: #fff;
            }

            body::before,
            body::after {
                display: none;
            }

            .page-wrapper {
                padding: 0;
            }
        }
    </style>
</head>

<body>
    <div class="toast-container" id="toastContainer"></div>
    <div class="page-wrapper">

        <header class="page-header">
            <div class="page-header-left">
                <div class="page-header-icon"><i class="fas fa-cash-register"></i></div>
                <div class="page-header-text">
                    <h1>Bilan des Ventes</h1>
                    <p>Ventes, Clients & Encaissements</p>
                </div>
            </div>
            <div class="page-header-right">
                <div class="header-badge"><i class="fas fa-coins"></i> CA : <?= number_format($totalTTC, 0, ',', ' ') ?> F</div>
                <div class="header-badge green"><i class="fas fa-arrow-up"></i> Benef. : <?= number_format($totalBenefice, 0, ',', ' ') ?> F</div>
                <?php if ($totalCreances > 0): ?><div class="header-badge red"><i class="fas fa-clock"></i> Creances : <?= number_format($totalCreances, 0, ',', ' ') ?> F</div><?php endif; ?>
            </div>
        </header>

        <!-- KPI -->
        <section class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon emerald"><i class="fas fa-cash-register"></i></div>
                <div class="kpi-body">
                    <div class="kpi-value green"><?= number_format($totalTTC, 0, ',', ' ') ?> F</div>
                    <div class="kpi-label">CA Total</div><span class="kpi-spark up"><i class="fas fa-arrow-up"></i> <?= $nbVentes ?> ventes</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="fas fa-coins"></i></div>
                <div class="kpi-body">
                    <div class="kpi-value blue"><?= number_format($totalBenefice, 0, ',', ' ') ?> F</div>
                    <div class="kpi-label">Benefice Net</div><span class="kpi-spark up"><?= number_format($margeBenefice, 1, ',', ' ') ?>%</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon violet"><i class="fas fa-shopping-basket"></i></div>
                <div class="kpi-body">
                    <div class="kpi-value purple"><?= number_format($panierMoyen, 0, ',', ' ') ?> F</div>
                    <div class="kpi-label">Panier Moyen</div><span class="kpi-spark up"><?= $totalQte ?> unites</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="kpi-body">
                    <div class="kpi-value orange"><?= number_format($totalCreances, 0, ',', ' ') ?> F</div>
                    <div class="kpi-label">Creances</div><span class="kpi-spark down"><?= count($facturesImpayees) ?> fac.</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon sky"><i class="fas fa-wallet"></i></div>
                <div class="kpi-body">
                    <div class="kpi-value"><?= number_format($totalPaiements['total_montant'], 0, ',', ' ') ?> F</div>
                    <div class="kpi-label">Encaissements</div><span class="kpi-spark up"><?= $totalPaiements['nb_transactions'] ?> tr.</span>
                </div>
            </div>
        </section>

        <!-- FILTRES -->
        <div class="filters-bar">
            <div class="filter-group"><label><i class="fas fa-calendar-alt"></i> Periode</label><select id="filtrePeriode" onchange="appliquerFiltres()">
                    <option value="">Toutes</option>
                    <option value="jour">Aujourd'hui</option>
                    <option value="semaine">Semaine</option>
                    <option value="mois" selected>Mois</option>
                    <option value="trimestre">Trimestre</option>
                    <option value="annee">Annee</option>
                </select></div>
            <div class="filter-group"><label><i class="fas fa-user"></i> Client</label><select id="filtreClient" onchange="appliquerFiltres()">
                    <option value="">Tous</option><?php foreach ($clientsList as $c): ?><option value="<?= $c['code_contact'] ?>"><?= htmlspecialchars($c['nom_prenom_contact']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="filter-group"><label><i class="fas fa-tag"></i> Cat.</label><select id="filtreCategorie" onchange="appliquerFiltres()">
                    <option value="">Toutes</option><?php foreach ($categories as $cat): ?><option value="<?= $cat['code_categorie'] ?>"><?= htmlspecialchars($cat['titre_categorie']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="filter-group"><label><i class="fas fa-store"></i> Boutique</label><select id="filtreBoutique" onchange="appliquerFiltres()">
                    <option value="">Toutes</option><?php foreach ($boutiques as $bq): ?><option value="<?= $bq['code_boutique'] ?>"><?= htmlspecialchars($bq['nom_boutique']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="filter-group"><label><i class="fas fa-calendar-day"></i> Debut</label><input type="date" id="dateDebut" onchange="appliquerFiltres()"></div>
            <div class="filter-group"><label><i class="fas fa-calendar-day"></i> Fin</label><input type="date" id="dateFin" onchange="appliquerFiltres()"></div>
            <div class="filter-actions"><button class="btn-filter secondary" onclick="resetFiltres()"><i class="fas fa-undo"></i> Reset</button></div>
        </div>

        <!-- TABS -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab(this,'tabA')"><i class="fas fa-chart-line"></i> Bilans Ventes</button>
            <button class="tab-btn" onclick="switchTab(this,'tabB')"><i class="fas fa-trophy"></i> Performance</button>
            <button class="tab-btn" onclick="switchTab(this,'tabC')"><i class="fas fa-hand-holding-usd"></i> Creances <?php if (count($facturesImpayees) > 0): ?><span class="count-badge"><?= count($facturesImpayees) ?></span><?php endif; ?></button>
        </div>

        <!-- TAB A -->
        <div id="tabA" class="tab-content active">
            <div class="search-bar"><i class="fas fa-search search-icon"></i><input type="text" id="rechercheGlobale" placeholder="Rechercher..." oninput="appliquerFiltres()"></div>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-chart-line"></i> Detail Ventes</div>
                <div class="section-actions"><button class="btn-action" onclick="exporterTableCSV('tableVentes','ventes')"><i class="fas fa-file-csv"></i> CSV</button></div>
            </h3>
            <?php if (count($bilanVentes) > 0): ?>
                <div class="table-wrapper">
                    <table id="tableVentes">
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Produit</th>
                                <th>Cat.</th>
                                <th>Qte</th>
                                <th>HT</th>
                                <th>TVA</th>
                                <th>TTC</th>
                                <th>Benef.</th>
                                <th>Bout.</th>
                                <th>Etat</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyVentes">
                            <?php foreach ($bilanVentes as $v): ?>
                                <tr data-date="<?= $v['date_commande'] ?>" data-client="<?= $v['code_contact'] ?>" data-categorie="<?= $v['code_categorie'] ?>" data-boutique="<?= $v['code_boutique'] ?>" data-search="<?= strtolower($v['numero_commande'] . ' ' . ($v['client'] ?? '') . ' ' . ($v['titre_produit'] ?? '') . ' ' . ($v['titre_categorie'] ?? '')) ?>">
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($v['numero_commande']) ?></strong></td>
                                    <td><?= $v['date_commande'] ?> <?= substr($v['heure_commande'] ?? '', 0, 5) ?></td>
                                    <td><?= htmlspecialchars($v['client'] ?? 'Comptoir') ?></td>
                                    <td><?= htmlspecialchars($v['titre_produit'] ?? '—') ?></td>
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($v['titre_categorie'] ?? '—') ?></span></td>
                                    <td><?= $v['quantite_commande'] ?> <?= htmlspecialchars($v['unite_affichage'] ?? '') ?></td>
                                    <td><?= number_format($v['montant_ht_calc'], 0, ',', ' ') ?> F</td>
                                    <td><?= number_format($v['tva_calc'], 0, ',', ' ') ?> F</td>
                                    <td><strong><?= number_format($v['montant_commande'], 0, ',', ' ') ?> F</strong></td>
                                    <td style="color:<?= $v['benefice_calc'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>;font-weight:700"><?= number_format($v['benefice_calc'], 0, ',', ' ') ?> F</td>
                                    <td><?= htmlspecialchars($v['nom_boutique'] ?? '—') ?></td>
                                    <td><span class="badge badge-success"><?= htmlspecialchars($v['etat_commande']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="8" style="text-align:right">TOTAUX :</td>
                                <td id="totalTTCFiltre"><?= number_format($totalTTC, 0, ',', ' ') ?> F</td>
                                <td id="totalBeneficeFiltre" style="color:var(--success)"><?= number_format($totalBenefice, 0, ',', ' ') ?> F</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?><div class="empty-cell">Aucune vente.</div><?php endif; ?>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-chart-pie"></i> Analyses</div>
            </h3>
            <div class="charts-grid">
                <div class="chart-card">
                    <h4><i class="fas fa-chart-line"></i> Evolution CA</h4><canvas id="chartEvolutionCA" height="220"></canvas>
                </div>
                <div class="chart-card">
                    <h4><i class="fas fa-chart-pie"></i> Par Categorie</h4><canvas id="chartCategories" height="220"></canvas>
                </div>
            </div>
        </div>

        <!-- TAB B -->
        <div id="tabB" class="tab-content">
            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-trophy"></i> Top 10 Vendus</div>
                <div class="section-actions"><button class="btn-action" onclick="exporterTableCSV('tableTopVendus','top_vendus')"><i class="fas fa-file-csv"></i> CSV</button></div>
            </h3>
            <?php if (count($topProduitsVendus) > 0): ?>
                <div class="table-wrapper">
                    <table id="tableTopVendus">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Produit</th>
                                <th>Cat.</th>
                                <th>Qte</th>
                                <th>Ventes</th>
                                <th>CA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProduitsVendus as $idx => $p): $rank = $idx + 1;
                                $rc = $rank == 1 ? 'rank-1' : ($rank == 2 ? 'rank-2' : ($rank == 3 ? 'rank-3' : 'rank-other')); ?>
                                <tr>
                                    <td><span class="rank-badge <?= $rc ?>"><?= $rank ?></span></td>
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($p['titre_produit']) ?></strong></td>
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($p['titre_categorie'] ?? '—') ?></span></td>
                                    <td><strong><?= $p['qte_vendue'] ?></strong></td>
                                    <td><?= $p['nb_ventes'] ?></td>
                                    <td><strong><?= number_format($p['ca'], 0, ',', ' ') ?> F</strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?><div class="empty-cell">Aucune donnee.</div><?php endif; ?>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-gem"></i> Top 10 Rentables</div>
            </h3>
            <?php if (count($topProduitsRentables) > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Produit</th>
                                <th>Cat.</th>
                                <th>Qte</th>
                                <th>CA</th>
                                <th>Benefice</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProduitsRentables as $idx => $p): $rank = $idx + 1;
                                $rc = $rank == 1 ? 'rank-1' : ($rank == 2 ? 'rank-2' : ($rank == 3 ? 'rank-3' : 'rank-other')); ?>
                                <tr>
                                    <td><span class="rank-badge <?= $rc ?>"><?= $rank ?></span></td>
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($p['titre_produit']) ?></strong></td>
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($p['titre_categorie'] ?? '—') ?></span></td>
                                    <td><?= $p['qte_vendue'] ?></td>
                                    <td><?= number_format($p['ca'], 0, ',', ' ') ?> F</td>
                                    <td><strong style="color:var(--success)"><?= number_format($p['benefice'], 0, ',', ' ') ?> F</strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?><div class="empty-cell">Aucune donnee.</div><?php endif; ?>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-arrow-down"></i> Moins Vendus</div>
            </h3>
            <?php if (count($topProduitsMoinsVendus) > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Cat.</th>
                                <th>Qte</th>
                                <th>CA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProduitsMoinsVendus as $p): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($p['titre_produit']) ?></strong></td>
                                    <td><span class="badge badge-secondary"><?= htmlspecialchars($p['titre_categorie'] ?? '—') ?></span></td>
                                    <td style="color:var(--warning);font-weight:700"><?= $p['qte_vendue'] ?></td>
                                    <td><?= number_format($p['ca'], 0, ',', ' ') ?> F</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (count($produitsSansMouvement) > 0): ?>
                <h3 class="section-title">
                    <div class="section-title-left"><i class="fas fa-box-open" style="color:var(--danger)"></i> Sans Mouvement</div>
                </h3>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Cat.</th>
                                <th>Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($produitsSansMouvement as $p): ?>
                                <tr>
                                    <td><strong style="color:var(--danger)"><?= htmlspecialchars($p['titre_produit']) ?></strong></td>
                                    <td><span class="badge badge-secondary"><?= htmlspecialchars($p['titre_categorie'] ?? '—') ?></span></td>
                                    <td><span class="badge badge-danger"><?= $p['stock_actuel'] ?? $p['stock_produit'] ?? 0 ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-users"></i> Top 10 Clients</div>
                <div class="section-actions"><button class="btn-action" onclick="exporterTableCSV('tableTopClients','top_clients')"><i class="fas fa-file-csv"></i> CSV</button></div>
            </h3>
            <?php if (count($topClients) > 0): ?>
                <div class="table-wrapper">
                    <table id="tableTopClients">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Client</th>
                                <th>Tel.</th>
                                <th>Achats</th>
                                <th>CA</th>
                                <th>Dernier</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topClients as $idx => $c): $rank = $idx + 1;
                                $rc = $rank == 1 ? 'rank-1' : ($rank == 2 ? 'rank-2' : ($rank == 3 ? 'rank-3' : 'rank-other')); ?>
                                <tr>
                                    <td><span class="rank-badge <?= $rc ?>"><?= $rank ?></span></td>
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($c['nom_prenom_contact']) ?></strong></td>
                                    <td><?= htmlspecialchars($c['telephone_contact']) ?></td>
                                    <td><?= $c['nb_achats'] ?></td>
                                    <td><strong><?= number_format($c['ca_total'], 0, ',', ' ') ?> F</strong></td>
                                    <td><?= $c['dernier_achat'] ?? '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (count($nouveauxClients) > 0): ?>
                <h3 class="section-title">
                    <div class="section-title-left"><i class="fas fa-user-plus" style="color:var(--success)"></i> Nouveaux Clients</div>
                </h3>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Tel.</th>
                                <th>1er Achat</th>
                                <th>CA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($nouveauxClients as $nc): ?>
                                <tr>
                                    <td><strong style="color:var(--success)"><?= htmlspecialchars($nc['nom_prenom_contact']) ?></strong></td>
                                    <td><?= htmlspecialchars($nc['telephone_contact']) ?></td>
                                    <td><?= $nc['date_premier_achat'] ?></td>
                                    <td><?= number_format($nc['ca_initial'], 0, ',', ' ') ?> F</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (count($clientsInactifs) > 0): ?>
                <h3 class="section-title">
                    <div class="section-title-left"><i class="fas fa-user-clock" style="color:var(--warning)"></i> Inactifs (>90j)</div>
                </h3>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Dernier</th>
                                <th>Jours</th>
                                <th>CA total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientsInactifs as $ci): ?>
                                <tr>
                                    <td><strong style="color:var(--warning)"><?= htmlspecialchars($ci['nom_prenom_contact']) ?></strong></td>
                                    <td><?= $ci['dernier_achat'] ?></td>
                                    <td><span class="badge badge-amber"><?= $ci['jours_inactif'] ?>j</span></td>
                                    <td><?= number_format($ci['ca_total'], 0, ',', ' ') ?> F</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB C -->
        <div id="tabC" class="tab-content">
            <div class="creances-summary"><i class="fas fa-exclamation-circle"></i>
                <div>
                    <div class="total"><?= number_format($totalCreances, 0, ',', ' ') ?> FCFA</div>
                    <div class="text">Reste sur <?= count($facturesImpayees) ?> factures</div>
                </div>
            </div>

            <div class="aging-row">
                <div class="aging-card aging-30">
                    <h5>1-30 jours</h5>
                    <div class="number"><?= number_format($aging['aging_30'], 0, ',', ' ') ?> F</div>
                    <p>Recent</p>
                </div>
                <div class="aging-card aging-60">
                    <h5>31-60 jours</h5>
                    <div class="number"><?= number_format($aging['aging_60'], 0, ',', ' ') ?> F</div>
                    <p>A surveiller</p>
                </div>
                <div class="aging-card aging-90">
                    <h5>61-90 jours</h5>
                    <div class="number"><?= number_format($aging['aging_90'], 0, ',', ' ') ?> F</div>
                    <p>En retard</p>
                </div>
                <div class="aging-card aging-plus">
                    <h5>+90 jours</h5>
                    <div class="number"><?= number_format($aging['aging_plus'], 0, ',', ' ') ?> F</div>
                    <p>Critique</p>
                </div>
            </div>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-file-invoice-dollar"></i> Factures Impayees</div>
            </h3>
            <?php if (count($facturesImpayees) > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Libelle</th>
                                <th>Client</th>
                                <th>Date</th>
                                <th>TTC</th>
                                <th>Avance</th>
                                <th>Reste</th>
                                <th>Retard</th>
                                <th>Etat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facturesImpayees as $f): $jr = $f['jours_retard'] ?? 0;
                                $jrBadge = $jr <= 30 ? 'badge-amber' : ($jr <= 60 ? 'badge-warning' : ($jr <= 90 ? 'badge-danger' : 'badge-danger')); ?>
                                <tr>
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($f['numero_facture']) ?></strong></td>
                                    <td><?= htmlspecialchars($f['titre_facture']) ?></td>
                                    <td><?= htmlspecialchars($f['client'] ?? '—') ?></td>
                                    <td><?= $f['date_facture'] ?></td>
                                    <td><?= number_format($f['montant_ttc'], 0, ',', ' ') ?> F</td>
                                    <td><?= number_format($f['avance'], 0, ',', ' ') ?> F</td>
                                    <td><span class="badge badge-danger"><?= number_format($f['reste'], 0, ',', ' ') ?> F</span></td>
                                    <td><span class="badge <?= $jrBadge ?>"><?= $jr ?>j</span></td>
                                    <td><span class="badge badge-amber"><?= htmlspecialchars($f['etat_facture']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?><div class="empty-cell">Aucune creance.</div><?php endif; ?>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-credit-card"></i> Paiements par Mode</div>
            </h3>
            <div class="split-row">
                <?php if (count($paiementsParMode) > 0): ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Mode</th>
                                    <th>Nb</th>
                                    <th>Montant</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paiementsParMode as $pm): ?>
                                    <tr>
                                        <td><strong style="color:var(--primary)"><?= htmlspecialchars($pm['mode']) ?></strong></td>
                                        <td><?= $pm['nb_transactions'] ?></td>
                                        <td><strong><?= number_format($pm['montant_total'], 0, ',', ' ') ?> F</strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="chart-card">
                    <h4><i class="fas fa-chart-pie"></i> Repart. Modes</h4><canvas id="chartPaiementsMode" height="200"></canvas>
                </div>
            </div>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-history"></i> Historique Paiements</div>
                <div class="section-actions"><button class="btn-action" onclick="exporterTableCSV('tablePaiements','paiements')"><i class="fas fa-file-csv"></i> CSV</button></div>
            </h3>
            <?php if (count($historiquePaiements) > 0): ?>
                <div class="table-wrapper">
                    <table id="tablePaiements">
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Date</th>
                                <th>Montant</th>
                                <th>Type</th>
                                <th>Mode</th>
                                <th>Client</th>
                                <th>Facture</th>
                                <th>Etat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historiquePaiements as $hp): ?>
                                <tr>
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($hp['numero_transaction']) ?></strong></td>
                                    <td><?= $hp['date_transaction'] ?> <?= substr($hp['heure_transaction'] ?? '', 0, 5) ?></td>
                                    <td><strong><?= number_format($hp['montant_transaction'], 0, ',', ' ') ?> F</strong></td>
                                    <td><span class="badge badge-info"><?= htmlspecialchars($hp['type_transaction']) ?></span></td>
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($hp['mode_reglement'] ?? '—') ?></span></td>
                                    <td><?= htmlspecialchars($hp['client'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($hp['numero_facture'] ?? '—') ?></td>
                                    <td><span class="badge badge-success"><?= htmlspecialchars($hp['etat_transaction']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?><div class="empty-cell">Aucun paiement.</div><?php endif; ?>
        </div>

    </div>

    <script>
        function switchTab(btn, tabId) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }

        function showToast(type, title, msg) {
            const c = document.getElementById('toastContainer');
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-times-circle',
                warning: 'fa-exclamation-circle',
                info: 'fa-info-circle'
            };
            const t = document.createElement('div');
            t.className = `toast ${type}`;
            t.innerHTML = `<i class="fas ${icons[type]} toast-icon"></i><div class="toast-content"><div class="toast-title">${title}</div><div class="toast-message">${msg}</div></div><button class="toast-close" onclick="closeToast(this)"><i class="fas fa-times"></i></button>`;
            c.appendChild(t);
            setTimeout(() => closeToast(t.querySelector('.toast-close')), 5000);
        }

        function closeToast(btn) {
            const t = btn.closest('.toast');
            if (!t || t.classList.contains('hiding')) return;
            t.classList.add('hiding');
            setTimeout(() => t.remove(), 300);
        }

        function number_format(n, d, dp, ts) {
            n = parseFloat(n);
            if (isNaN(n)) return '0';
            const s = n.toFixed(d);
            const p = s.split('.');
            p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ts);
            return p.join(dp);
        }

        function appliquerFiltres() {
            const rows = document.querySelectorAll('#tbodyVentes tr');
            const periode = document.getElementById('filtrePeriode').value;
            const client = document.getElementById('filtreClient').value;
            const cat = document.getElementById('filtreCategorie').value;
            const bout = document.getElementById('filtreBoutique').value;
            const dd = document.getElementById('dateDebut').value;
            const df = document.getElementById('dateFin').value;
            const search = document.getElementById('rechercheGlobale').value.toLowerCase();
            const now = new Date();
            let vis = 0,
                ttcVis = 0,
                benVis = 0;
            rows.forEach(row => {
                let show = true;
                const rd = row.dataset.date;
                if (client && row.dataset.client !== client) show = false;
                if (cat && row.dataset.categorie !== cat) show = false;
                if (bout && row.dataset.boutique !== bout) show = false;
                if (dd && rd < dd) show = false;
                if (df && rd > df) show = false;
                if (periode) {
                    const d = new Date(rd);
                    if (periode === 'jour' && d.toDateString() !== now.toDateString()) show = false;
                    if (periode === 'semaine') {
                        const ws = new Date(now);
                        ws.setDate(now.getDate() - now.getDay());
                        if (d < ws) show = false;
                    }
                    if (periode === 'mois' && (d.getMonth() !== now.getMonth() || d.getFullYear() !== now.getFullYear())) show = false;
                    if (periode === 'trimestre' && QUARTER(rd) !== QUARTER(now.toISOString())) show = false;
                    if (periode === 'annee' && d.getFullYear() !== now.getFullYear()) show = false;
                }
                if (search && !row.dataset.search.includes(search)) show = false;
                row.style.display = show ? '' : 'none';
                if (show) {
                    vis++;
                    const ttd = row.querySelectorAll('td')[8];
                    const bnd = row.querySelectorAll('td')[9];
                    if (ttd) ttcVis += parseFloat(ttd.textContent.replace(/[^\d]/g, '')) || 0;
                    if (bnd) benVis += parseFloat(bnd.textContent.replace(/[^\d]/g, '')) || 0;
                }
            });
            document.getElementById('totalTTCFiltre').textContent = number_format(ttcVis, 0, ',', ' ') + ' F';
            document.getElementById('totalBeneficeFiltre').textContent = number_format(benVis, 0, ',', ' ') + ' F';
        }

        function resetFiltres() {
            ['filtrePeriode', 'filtreClient', 'filtreCategorie', 'filtreBoutique', 'dateDebut', 'dateFin', 'rechercheGlobale'].forEach(id => document.getElementById(id).value = '');
            appliquerFiltres();
            showToast('info', 'Filtres', 'Reinitialises.');
        }

        function exporterTableCSV(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) return;
            let csv = '';
            table.querySelectorAll('tr').forEach(row => {
                const cells = row.querySelectorAll('th,td');
                csv += Array.from(cells).map(c => '"' + c.textContent.trim().replace(/\n/g, ' ').replace(/"/g, '""') + '"').join(';') + '\n';
            });
            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename + '_' + new Date().toISOString().slice(0, 10) + '.csv';
            link.click();
            showToast('success', 'Export', link.download + ' genere.');
        }

        // CHARTS
        document.addEventListener('DOMContentLoaded', function() {
            const fontCfg = {
                family: 'Plus Jakarta Sans',
                size: 11
            };
            const gridCfg = {
                color: '#e2e8f0'
            };
            const legendCfg = {
                position: 'bottom',
                labels: {
                    padding: 10,
                    usePointStyle: true,
                    font: fontCfg
                }
            };

            const ctxCA = document.getElementById('chartEvolutionCA')?.getContext('2d');
            if (ctxCA && <?= count($evolutionCA) ?> > 0) {
                new Chart(ctxCA, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode(array_column($evolutionCA, 'mois')) ?>,
                        datasets: [{
                            label: 'CA',
                            data: <?= json_encode(array_map(fn($r) => floatval($r['ca']), $evolutionCA)) ?>,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37,99,235,0.08)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 3,
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: legendCfg
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    font: fontCfg
                                },
                                grid: gridCfg
                            },
                            x: {
                                ticks: {
                                    font: fontCfg
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
            const ctxCat = document.getElementById('chartCategories')?.getContext('2d');
            if (ctxCat && <?= count($ventesParCategorie) ?> > 0) {
                new Chart(ctxCat, {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode(array_column($ventesParCategorie, 'titre_categorie')) ?>,
                        datasets: [{
                            data: <?= json_encode(array_map(fn($r) => floatval($r['ca']), $ventesParCategorie)) ?>,
                            backgroundColor: ['#2563eb', '#0284c7', '#1e40af', '#3b82f6', '#60a5fa', '#d97706', '#059669', '#7c3aed', '#ea580c', '#dc2626'],
                            borderWidth: 0,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: legendCfg
                        },
                        cutout: '55%'
                    }
                });
            }
            const ctxPM = document.getElementById('chartPaiementsMode')?.getContext('2d');
            if (ctxPM && <?= count($paiementsParMode) ?> > 0) {
                new Chart(ctxPM, {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode(array_column($paiementsParMode, 'mode')) ?>,
                        datasets: [{
                            data: <?= json_encode(array_map(fn($r) => floatval($r['montant_total']), $paiementsParMode)) ?>,
                            backgroundColor: ['#2563eb', '#059669', '#d97706', '#7c3aed', '#dc2626', '#0284c7'],
                            borderWidth: 0,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: legendCfg
                        },
                        cutout: '55%'
                    }
                });
            }
        });
    </script>
</body>

</html>