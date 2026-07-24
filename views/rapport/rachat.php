<?php
// ============================================
// PAGE 3 : ACHATS, STOCKS, INVENTAIRE & FOURNISSEURS
// "La Marchandise" — Design Premium Bleu — Monnaie FCFA
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
    die("Erreur de connexion : " . $e->getMessage());
}

// ============================================
// SOUS-ONGLET A : ACHATS & FOURNISSEURS
// ============================================

$bilanAchats = $pdo->query("
    SELECT 
        c.numero_commande, c.date_commande, c.prix_achat, c.quantite_commande,
        c.montant_commande, c.etat_commande, c.unite_affichage, c.date_livraison_recue,
        c.statut_id, p.titre_produit, p.code_produit,
        ct.nom_prenom_contact AS fournisseur, ct.code_contact,
        b.nom_boutique, b.code_boutique,
        cat.titre_categorie, cat.code_categorie
    FROM commande c
    LEFT JOIN produit p ON c.produit_id = p.code_produit
    LEFT JOIN contact ct ON c.contact_id = ct.code_contact
    LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
    LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
    WHERE c.statut_id IN ('ST001', 'Achat', '011')
    ORDER BY c.date_commande DESC, c.heure_commande DESC
")->fetchAll();

$totalAchats = $pdo->query("
    SELECT COALESCE(SUM(CAST(montant_commande AS DECIMAL(12,2))), 0) AS total 
    FROM commande WHERE statut_id IN ('ST001', 'Achat', '011')
")->fetch()['total'];

$nbCommandesAchat = $pdo->query("
    SELECT COUNT(*) AS nb FROM commande WHERE statut_id IN ('ST001', 'Achat', '011')
")->fetch()['nb'];

$rapportFournisseurs = $pdo->query("
    SELECT 
        ct.code_contact, ct.nom_prenom_contact AS fournisseur,
        ct.telephone_contact, ct.email_contact, ct.adresse_contact,
        COUNT(c.numero_commande) AS nb_commandes,
        COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))), 0) AS montant_total,
        COALESCE(AVG(CAST(c.prix_achat AS DECIMAL(12,2))), 0) AS prix_moyen,
        MAX(c.date_commande) AS derniere_commande
    FROM contact ct
    LEFT JOIN commande c ON ct.code_contact = c.contact_id 
        AND c.statut_id IN ('ST001', 'Achat', '011')
    WHERE ct.type_contact = 'FOURNISSEUR'
    GROUP BY ct.code_contact
    ORDER BY montant_total DESC
")->fetchAll();

$dettesFournisseurs = $pdo->query("
    SELECT 
        f.numero_facture, f.titre_facture, f.date_facture, f.montant_ttc,
        f.avance, f.reste, f.etat_facture,
        ct.nom_prenom_contact AS fournisseur
    FROM facture f
    LEFT JOIN contact ct ON f.contact_id = ct.code_contact
    WHERE ct.type_contact = 'FOURNISSEUR'
      AND CAST(f.reste AS DECIMAL(12,2)) > 0
    ORDER BY CAST(f.reste AS DECIMAL(12,2)) DESC
")->fetchAll();

$totalDettes = $pdo->query("
    SELECT COALESCE(SUM(CAST(f.reste AS DECIMAL(12,2))), 0) AS total
    FROM facture f
    LEFT JOIN contact ct ON f.contact_id = ct.code_contact
    WHERE ct.type_contact = 'FOURNISSEUR'
      AND CAST(f.reste AS DECIMAL(12,2)) > 0
")->fetch()['total'];

// ============================================
// SOUS-ONGLET B : BILAN DU STOCK
// ============================================

$stockActuel = $pdo->query("
    SELECT 
        sb.produit_id, p.titre_produit, p.prix_fournisseur, p.stock_alerte,
        sb.boutique_id, b.nom_boutique, sb.quantite, sb.lot_produit_id,
        sb.quantite_lot, sb.maj_le,
        (sb.quantite * CAST(COALESCE(p.prix_fournisseur, '0') AS DECIMAL(12,2))) AS valeur_stock,
        cat.titre_categorie
    FROM stock_boutique sb
    LEFT JOIN produit p ON sb.produit_id = p.code_produit
    LEFT JOIN boutique b ON sb.boutique_id = b.code_boutique
    LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
    ORDER BY sb.quantite ASC
")->fetchAll();

$valeurStock = $pdo->query("
    SELECT COALESCE(SUM(sb.quantite * CAST(COALESCE(p.prix_fournisseur, '0') AS DECIMAL(12,2))), 0) AS total
    FROM stock_boutique sb
    LEFT JOIN produit p ON sb.produit_id = p.code_produit
")->fetch()['total'];

$mouvementsEntrees = $pdo->query("
    SELECT COALESCE(SUM(quantite_commande), 0) AS total 
    FROM commande WHERE statut_id IN ('ST001', 'Achat', '011')
")->fetch()['total'];

$mouvementsSorties = $pdo->query("
    SELECT COALESCE(SUM(quantite_commande), 0) AS total 
    FROM commande WHERE statut_id IN ('ST002', 'Vente', '012')
")->fetch()['total'];

$produitsRupture = $pdo->query("
    SELECT p.titre_produit, b.nom_boutique, sb.quantite, p.code_produit
    FROM stock_boutique sb
    LEFT JOIN produit p ON sb.produit_id = p.code_produit
    LEFT JOIN boutique b ON sb.boutique_id = b.code_boutique
    WHERE sb.quantite <= 0
")->fetchAll();

$produitsAlerte = $pdo->query("
    SELECT 
        p.titre_produit, b.nom_boutique, sb.quantite, p.stock_alerte,
        (CAST(p.stock_alerte AS UNSIGNED) - sb.quantite) AS manque
    FROM stock_boutique sb
    LEFT JOIN produit p ON sb.produit_id = p.code_produit
    LEFT JOIN boutique b ON sb.boutique_id = b.code_boutique
    WHERE sb.quantite <= CAST(p.stock_alerte AS UNSIGNED)
      AND sb.quantite > 0
    ORDER BY manque DESC
")->fetchAll();

$stockNormal = $pdo->query("
    SELECT COUNT(*) AS nb FROM stock_boutique sb
    LEFT JOIN produit p ON sb.produit_id = p.code_produit
    WHERE sb.quantite > CAST(p.stock_alerte AS UNSIGNED)
")->fetch()['nb'];

$stockFaible = count($produitsAlerte);
$stockRupture = count($produitsRupture);

$achatsVentesMois = $pdo->query("
    SELECT 
        DATE_FORMAT(c.date_commande, '%Y-%m') AS mois,
        SUM(CASE WHEN c.statut_id IN ('ST001', 'Achat', '011') THEN CAST(c.montant_commande AS DECIMAL(12,2)) ELSE 0 END) AS achats,
        SUM(CASE WHEN c.statut_id IN ('ST002', 'Vente', '012') THEN CAST(c.montant_commande AS DECIMAL(12,2)) ELSE 0 END) AS ventes
    FROM commande c
    WHERE c.date_commande IS NOT NULL
    GROUP BY DATE_FORMAT(c.date_commande, '%Y-%m')
    ORDER BY mois ASC
")->fetchAll();

// ============================================
// SOUS-ONGLET C : INVENTAIRE PHYSIQUE
// ============================================

$stockTheorique = $pdo->query("
    SELECT 
        sb.produit_id, p.titre_produit, sb.boutique_id, b.nom_boutique,
        sb.quantite AS stock_systeme, p.prix_fournisseur,
        (sb.quantite * CAST(COALESCE(p.prix_fournisseur, '0') AS DECIMAL(12,2))) AS valeur_systeme
    FROM stock_boutique sb
    LEFT JOIN produit p ON sb.produit_id = p.code_produit
    LEFT JOIN boutique b ON sb.boutique_id = b.code_boutique
    ORDER BY p.titre_produit, b.nom_boutique
")->fetchAll();

$transferts = $pdo->query("
    SELECT 
        c.numero_commande, c.date_commande, c.quantite_commande,
        c.etat_commande, c.statut_id, p.titre_produit,
        b.nom_boutique AS boutique_concernee
    FROM commande c
    LEFT JOIN produit p ON c.produit_id = p.code_produit
    LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
    WHERE c.statut_id IN ('008', '009')
    ORDER BY c.date_commande DESC
")->fetchAll();

$nbProduits = $pdo->query("SELECT COUNT(*) AS nb FROM produit WHERE etat_produit='Actif'")->fetch()['nb'];
$nbFournisseurs = $pdo->query("SELECT COUNT(*) AS nb FROM contact WHERE type_contact='FOURNISSEUR'")->fetch()['nb'];
$nbBoutiques = $pdo->query("SELECT COUNT(*) AS nb FROM boutique WHERE etat_boutique='Actif'")->fetch()['nb'];
$nbCategories = $pdo->query("SELECT COUNT(*) AS nb FROM categorie WHERE etat_categorie='Actif'")->fetch()['nb'];

$totalValSys = 0;
foreach ($stockTheorique as $st) {
    $totalValSys += floatval($st['valeur_systeme']);
}

$categories = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie WHERE etat_categorie='Actif'")->fetchAll();
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique='Actif'")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La Marchandise — Achats, Stocks, Inventaire & Fournisseurs</title>
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
            --accent-light: #fbbf24;
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
            --dark-mid: #1e293b;
            --dark-soft: #334155;
            --body: #f1f5f9;
            --card: #ffffff;
            --card-alt: #f8fafc;
            --text: #0f172a;
            --text-mid: #475569;
            --text-soft: #94a3b8;
            --border: #e2e8f0;
            --border-soft: #cbd5e1;
            --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.04), 0 1px 2px rgba(15, 23, 42, 0.06);
            --shadow: 0 4px 12px rgba(15, 23, 42, 0.08), 0 2px 4px rgba(15, 23, 42, 0.04);
            --shadow-lg: 0 12px 32px rgba(15, 23, 42, 0.12), 0 4px 8px rgba(15, 23, 42, 0.06);
            --shadow-xl: 0 20px 48px rgba(15, 23, 42, 0.16), 0 8px 16px rgba(15, 23, 42, 0.08);
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
            background:
                radial-gradient(ellipse 80% 50% at 15% 0%, var(--primary-glow) 0%, transparent 60%),
                radial-gradient(ellipse 50% 35% at 85% 90%, var(--accent-glow) 0%, transparent 50%),
                radial-gradient(ellipse 40% 30% at 50% 50%, var(--primary-glow-soft) 0%, transparent 40%),
                linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%);
            z-index: -1;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%231e40af' fill-opacity='0.025'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2v-4h4v-2h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2v-4h4v-2H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            z-index: -1;
            pointer-events: none;
            opacity: 0.6;
        }

        .page-wrapper {
            max-width: 1360px;
            margin: 0 auto;
            padding: 20px 28px 60px;
        }

        /* ── EN-TÊTE PAGE ── */
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
            color: white;
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
            font-weight: 400;
            margin-top: 1px;
        }

        .page-header-right {
            display: flex;
            gap: 8px;
        }

        .header-btn {
            padding: 8px 14px;
            border: 1px solid var(--border);
            background: var(--card);
            border-radius: var(--radius-xs);
            font-family: inherit;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-mid);
            cursor: pointer;
            transition: all 0.25s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .header-btn:hover {
            background: var(--primary-mid);
            color: white;
            border-color: var(--primary-mid);
            box-shadow: var(--shadow), 0 0 14px var(--primary-glow);
        }

        /* ── STATS ROW — COMPACT ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--card);
            border-radius: 10px;
            padding: 12px 14px 10px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 3px;
            height: 100%;
            background: linear-gradient(180deg, var(--primary-mid), var(--accent));
            transform: scaleY(0);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: var(--primary-mid);
        }

        .stat-card:hover::before {
            transform: scaleY(1);
        }

        .stat-icon-wrap {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            color: white;
            flex-shrink: 0;
        }

        .stat-icon-wrap.blue {
            background: linear-gradient(135deg, var(--primary-mid), var(--primary));
        }

        .stat-icon-wrap.blue-dark {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .stat-icon-wrap.sky {
            background: linear-gradient(135deg, #0284c7, #0369a1);
        }

        .stat-icon-wrap.amber {
            background: linear-gradient(135deg, var(--accent), #b45309);
        }

        .stat-icon-wrap.rose {
            background: linear-gradient(135deg, #e11d48, #be123c);
        }

        .stat-icon-wrap.violet {
            background: linear-gradient(135deg, var(--purple), #6d28d9);
        }

        .stat-body {
            flex: 1;
            min-width: 0;
        }

        .stat-value {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.5px;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--text-soft);
            font-weight: 500;
            margin-top: 1px;
            line-height: 1.2;
        }

        .stat-spark {
            font-size: 0.62rem;
            padding: 2px 6px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            margin-top: 3px;
        }

        .stat-spark.up {
            background: var(--success-light);
            color: var(--success);
        }

        .stat-spark.down {
            background: var(--danger-light);
            color: var(--danger);
        }

        /* ── TABS ── */
        .tabs-container {
            margin-bottom: 0;
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
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tab-btn i {
            font-size: 0.8rem;
        }

        .tab-btn:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .tab-btn.active {
            background: var(--primary-mid);
            color: white;
            box-shadow: 0 3px 10px var(--primary-glow);
        }

        .tab-btn .count-badge {
            background: var(--danger);
            color: white;
            font-size: 0.62rem;
            padding: 1px 5px;
            border-radius: 8px;
            font-weight: 700;
            line-height: 1.3;
        }

        .tab-btn.active .count-badge {
            background: rgba(255, 255, 255, 0.25);
        }

        /* ── TAB CONTENT ── */
        .tab-content {
            display: none;
            padding: 24px 28px 28px;
            background: var(--card);
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            border-top: none;
            margin-bottom: 28px;
            animation: tabFadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes tabFadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ── SECTION TITLE ── */
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
            color: white;
            border-color: var(--primary-mid);
            box-shadow: 0 2px 8px var(--primary-glow);
        }

        .btn-action.accent:hover {
            background: var(--accent);
            border-color: var(--accent);
            box-shadow: 0 2px 8px var(--accent-glow);
        }

        /* ── FILTERS ── */
        .filters-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
            padding: 14px 16px;
            background: var(--card-alt);
            border-radius: 10px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 140px;
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
            background: white;
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
            color: white;
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
            background: white;
            color: var(--text-mid);
            border: 1px solid var(--border);
        }

        .btn-filter.secondary:hover {
            background: var(--card-alt);
            color: var(--dark);
            box-shadow: var(--shadow-sm);
        }

        .filters-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
            padding: 10px 14px;
            background: var(--primary-light);
            border-radius: var(--radius-sm);
            align-items: center;
            border: 1px solid rgba(37, 99, 235, 0.15);
        }

        .filters-summary.hidden {
            display: none;
        }

        .filter-tag {
            background: white;
            color: var(--primary);
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 0.72rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            border: 1px solid var(--primary-mid);
        }

        .filter-tag .remove {
            cursor: pointer;
            color: var(--danger);
            font-weight: 800;
            font-size: 0.8rem;
            transition: transform 0.15s;
        }

        .filter-tag .remove:hover {
            transform: scale(1.15);
        }

        .results-counter {
            margin-left: auto;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--dark);
        }

        /* ── SEARCH ── */
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
            background: white;
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

        /* ── TABLES ── */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid var(--border);
            margin-bottom: 20px;
            position: relative;
            background: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        thead {
            background: linear-gradient(135deg, var(--primary-deeper), var(--primary-dark));
            color: white;
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
            cursor: default;
            user-select: none;
        }

        th.sortable {
            cursor: pointer;
        }

        th.sortable:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        th .sort-icon {
            margin-left: 3px;
            opacity: 0.4;
            font-size: 0.6rem;
            transition: opacity 0.2s;
        }

        th.sort-asc .sort-icon,
        th.sort-desc .sort-icon {
            opacity: 1;
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

        tbody tr.hidden-row {
            display: none;
        }

        tfoot td {
            background: var(--card-alt);
            font-weight: 700;
            color: var(--dark);
            border-top: 2px solid var(--primary-mid);
            border-bottom: none;
        }

        /* ── BADGES ── */
        .badge {
            padding: 3px 9px;
            border-radius: 16px;
            font-size: 0.68rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            letter-spacing: 0.2px;
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

        /* ── CHARTS ── */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
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

        /* ── ALERT CARDS ── */
        .alert-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .alert-card {
            padding: 14px 14px 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            border-left: 3px solid;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .alert-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: currentColor;
            opacity: 0.03;
            pointer-events: none;
        }

        .alert-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .alert-card.critical {
            border-color: var(--danger);
            background: var(--danger-light);
        }

        .alert-card.warning {
            border-color: var(--warning);
            background: var(--warning-light);
        }

        .alert-card.info {
            border-color: var(--info);
            background: var(--info-light);
        }

        .alert-card.success {
            border-color: var(--success);
            background: var(--success-light);
        }

        .alert-card h5 {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-soft);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .alert-card .number {
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: -0.8px;
            line-height: 1;
        }

        .alert-card.critical .number {
            color: var(--danger);
        }

        .alert-card.warning .number {
            color: var(--warning);
        }

        .alert-card.info .number {
            color: var(--info);
        }

        .alert-card.success .number {
            color: var(--success);
        }

        .alert-card .alert-sub {
            font-size: 0.68rem;
            color: var(--text-soft);
            margin-top: 3px;
        }

        /* ── INVENTORY INPUT ── */
        .inventory-input {
            width: 80px;
            padding: 6px 8px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-xs);
            text-align: center;
            font-family: inherit;
            font-weight: 700;
            font-size: 0.82rem;
            transition: all 0.2s;
        }

        .inventory-input:focus {
            outline: none;
            border-color: var(--primary-mid);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .inventory-input.modified {
            background: #fef3c7;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .ecart-positive {
            color: var(--success);
            font-weight: 700;
        }

        .ecart-negative {
            color: var(--danger);
            font-weight: 700;
        }

        .ecart-zero {
            color: var(--text-soft);
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-soft);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            opacity: 0.25;
            color: var(--primary-mid);
        }

        .empty-state p {
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* ── TOAST ── */
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
            max-width: 400px;
            animation: toastSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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

        .toast.warning .toast-icon {
            color: var(--warning);
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
            color: var(--dark);
        }

        .toast-message {
            font-size: 0.75rem;
            color: var(--text-soft);
            margin-top: 1px;
        }

        .toast-close {
            background: none;
            border: none;
            color: var(--text-soft);
            cursor: pointer;
            font-size: 1rem;
            padding: 0;
            transition: color 0.2s;
        }

        .toast-close:hover {
            color: var(--dark);
        }

        @keyframes toastSlideIn {
            from {
                transform: translateX(120%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes toastSlideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(120%);
                opacity: 0;
            }
        }

        .toast.hiding {
            animation: toastSlideOut 0.3s ease forwards;
        }

        /* ── PROGRESS ── */
        .progress-container {
            margin: 12px 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.78rem;
            font-weight: 600;
        }

        .progress-bar {
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-mid), var(--accent));
            border-radius: 3px;
            transition: width 0.6s ease;
        }

        /* ── DETTES SUMMARY BAR ── */
        .dettes-summary {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: linear-gradient(135deg, var(--danger-light), #fff1f2);
            border-radius: var(--radius-sm);
            border: 1px solid rgba(220, 38, 38, 0.15);
            margin-bottom: 16px;
        }

        .dettes-summary i {
            font-size: 1.1rem;
            color: var(--danger);
        }

        .dettes-summary .dettes-total {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--danger);
        }

        .dettes-summary .dettes-text {
            font-size: 0.78rem;
            color: var(--text-mid);
        }

        /* ── ANIMATIONS ── */
        @keyframes pulseGlow {

            0%,
            100% {
                box-shadow: var(--shadow-sm);
            }

            50% {
                box-shadow: 0 0 16px 3px var(--primary-glow);
            }
        }

        .stat-card.pulse {
            animation: pulseGlow 2.5s ease infinite;
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 1100px) {
            .stats-row {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-wrapper {
                padding: 12px 10px 40px;
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .tab-content {
                padding: 16px 12px 20px;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .page-header-right {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .stats-row {
                grid-template-columns: 1fr 1fr;
            }

            .stat-card {
                padding: 10px 10px 8px;
            }
        }

        @media print {

            .tabs,
            .filters-bar,
            .section-actions,
            .btn-action,
            .page-header-right,
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
                background: white;
            }

            body::before,
            body::after {
                display: none;
            }

            .page-wrapper {
                padding: 0;
                max-width: none;
            }

            .stat-card,
            .alert-card {
                box-shadow: none;
                border: 1px solid #ccc;
            }
        }
    </style>
</head>

<body>

    <div class="toast-container" id="toastContainer"></div>

    <div class="page-wrapper">

        <header class="page-header">
            <div class="page-header-left">
                <div class="page-header-icon"><i class="fas fa-cubes"></i></div>
                <div class="page-header-text">
                    <h1>Bilan des stock</h1>
                    <p>Achats, Stocks, Inventaire & Fournisseurs</p>
                </div>
            </div>
            <div class="page-header-right">
                <button class="header-btn" onclick="window.print()"><i class="fas fa-print"></i> Imprimer</button>
                <button class="header-btn accent" onclick="rafraichirDonnees()"><i class="fas fa-sync-alt"></i> Rafraichir</button>
            </div>
        </header>

        <!-- ═══ STATS COMPACTES ═══ -->
        <section class="stats-row">
            <div class="stat-card pulse" onclick="switchTab(document.querySelectorAll('.tab-btn')[0], 'tabA')">
                <div class="stat-icon-wrap blue"><i class="fas fa-shopping-cart"></i></div>
                <div class="stat-body">
                    <div class="stat-value"><?= number_format($totalAchats, 0, ',', ' ') ?> F</div>
                    <div class="stat-label">Total Achats</div>
                    <span class="stat-spark up"><i class="fas fa-arrow-up"></i> <?= $nbCommandesAchat ?> cmd</span>
                </div>
            </div>

            <div class="stat-card" onclick="switchTab(document.querySelectorAll('.tab-btn')[1], 'tabB')">
                <div class="stat-icon-wrap blue-dark"><i class="fas fa-warehouse"></i></div>
                <div class="stat-body">
                    <div class="stat-value"><?= number_format($valeurStock, 0, ',', ' ') ?> F</div>
                    <div class="stat-label">Valeur Stock</div>
                    <span class="stat-spark up"><i class="fas fa-cubes"></i> <?= $nbProduits ?> prod.</span>
                </div>
            </div>

            <div class="stat-card" onclick="switchTab(document.querySelectorAll('.tab-btn')[0], 'tabA')">
                <div class="stat-icon-wrap sky"><i class="fas fa-truck"></i></div>
                <div class="stat-body">
                    <div class="stat-value"><?= $nbFournisseurs ?></div>
                    <div class="stat-label">Fournisseurs</div>
                    <span class="stat-spark up"><i class="fas fa-store"></i> <?= $nbBoutiques ?> bout.</span>
                </div>
            </div>

            <div class="stat-card" onclick="switchTab(document.querySelectorAll('.tab-btn')[1], 'tabB')">
                <div class="stat-icon-wrap rose"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-body">
                    <div class="stat-value"><?= $stockFaible + $stockRupture ?></div>
                    <div class="stat-label">Alertes Stock</div>
                    <span class="stat-spark down"><i class="fas fa-arrow-down"></i> reappro.</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon-wrap violet"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="stat-body">
                    <div class="stat-value"><?= number_format($totalDettes, 0, ',', ' ') ?> F</div>
                    <div class="stat-label">Dettes Fourn.</div>
                    <span class="stat-spark down"><i class="fas fa-clock"></i> <?= count($dettesFournisseurs) ?> fac.</span>
                </div>
            </div>
        </section>

        <!-- ═══ TABS ═══ -->
        <nav class="tabs-container">
            <div class="tabs" role="tablist">
                <button class="tab-btn active" role="tab" onclick="switchTab(this, 'tabA')">
                    <i class="fas fa-handshake"></i> Achats & Fournisseurs
                </button>
                <button class="tab-btn" role="tab" onclick="switchTab(this, 'tabB')">
                    <i class="fas fa-boxes-stacked"></i> Bilan du Stock
                    <?php if ($stockFaible + $stockRupture > 0): ?>
                        <span class="count-badge"><?= $stockFaible + $stockRupture ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" role="tab" onclick="switchTab(this, 'tabC')">
                    <i class="fas fa-clipboard-check"></i> Inventaire Physique
                </button>
            </div>
        </nav>

        <!-- ═══ TAB A ═══ -->
        <div id="tabA" class="tab-content active" role="tabpanel">

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-sliders-h"></i> Filtres</div>
                <div class="section-actions">
                    <button class="btn-action" onclick="toggleFilters()">
                        <i class="fas fa-chevron-up" id="toggleFiltersIcon"></i>
                        <span id="toggleFiltersText">Reduire</span>
                    </button>
                </div>
            </h3>

            <div class="filters-bar" id="filtersBar">
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> Periode</label>
                    <select id="filtrePeriode" onchange="appliquerFiltres()">
                        <option value="">Toutes</option>
                        <option value="jour">Aujourd'hui</option>
                        <option value="semaine">Cette semaine</option>
                        <option value="mois" selected>Ce mois</option>
                        <option value="annee">Cette annee</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-truck"></i> Fournisseur</label>
                    <select id="filtreFournisseur" onchange="appliquerFiltres()">
                        <option value="">— Tous —</option>
                        <?php foreach ($rapportFournisseurs as $f): ?>
                            <option value="<?= $f['code_contact'] ?>"><?= htmlspecialchars($f['fournisseur']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-tag"></i> Categorie</label>
                    <select id="filtreCategorie" onchange="appliquerFiltres()">
                        <option value="">— Toutes —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['code_categorie'] ?>"><?= htmlspecialchars($cat['titre_categorie']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-store"></i> Boutique</label>
                    <select id="filtreBoutique" onchange="appliquerFiltres()">
                        <option value="">— Toutes —</option>
                        <?php foreach ($boutiques as $bq): ?>
                            <option value="<?= $bq['code_boutique'] ?>"><?= htmlspecialchars($bq['nom_boutique']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-day"></i> Debut</label>
                    <input type="date" id="dateDebut" onchange="appliquerFiltres()">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-day"></i> Fin</label>
                    <input type="date" id="dateFin" onchange="appliquerFiltres()">
                </div>
                <div class="filter-actions">
                    <button class="btn-filter secondary" onclick="resetFiltres()"><i class="fas fa-undo"></i> Reset</button>
                </div>
            </div>

            <div class="filters-summary hidden" id="filtersSummary">
                <i class="fas fa-filter" style="color:var(--primary-mid)"></i>
                <span style="font-weight:700;color:var(--dark)">Filtres :</span>
                <div id="filterTags"></div>
                <div class="results-counter" id="resultsCounter"></div>
            </div>

            <div class="search-bar">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="rechercheGlobale" placeholder="Rechercher..." oninput="appliquerFiltres()">
            </div>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-chart-line"></i> Bilan des Achats</div>
                <div class="section-actions">
                    <button class="btn-action" onclick="exporterTableCSV('tableAchats','achats')"><i class="fas fa-file-csv"></i> CSV</button>
                </div>
            </h3>

            <div class="table-wrapper">
                <table id="tableAchats">
                    <thead>
                        <tr>
                            <th class="sortable" onclick="trierTable('tableAchats',0)">N° <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" onclick="trierTable('tableAchats',1)">Date <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" onclick="trierTable('tableAchats',2)">Fournisseur <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" onclick="trierTable('tableAchats',3)">Produit <i class="fas fa-sort sort-icon"></i></th>
                            <th>Cat.</th>
                            <th class="sortable" onclick="trierTable('tableAchats',5)">Qte <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" onclick="trierTable('tableAchats',6)">Prix <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" onclick="trierTable('tableAchats',7)">Montant <i class="fas fa-sort sort-icon"></i></th>
                            <th>Boutique</th>
                            <th>Livr.</th>
                            <th>Etat</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyAchats">
                        <?php foreach ($bilanAchats as $a): ?>
                            <tr data-date="<?= $a['date_commande'] ?>"
                                data-fournisseur="<?= $a['code_contact'] ?>"
                                data-categorie="<?= $a['code_categorie'] ?>"
                                data-boutique="<?= $a['code_boutique'] ?>"
                                data-search="<?= strtolower($a['numero_commande'] . ' ' . ($a['fournisseur'] ?? '') . ' ' . ($a['titre_produit'] ?? '') . ' ' . ($a['titre_categorie'] ?? '') . ' ' . ($a['nom_boutique'] ?? '')) ?>">
                                <td><strong style="color:var(--primary)"><?= htmlspecialchars($a['numero_commande']) ?></strong></td>
                                <td><?= $a['date_commande'] ?></td>
                                <td><?= htmlspecialchars($a['fournisseur'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($a['titre_produit'] ?? '—') ?></td>
                                <td><span class="badge badge-blue"><?= htmlspecialchars($a['titre_categorie'] ?? '—') ?></span></td>
                                <td><?= $a['quantite_commande'] ?> <?= htmlspecialchars($a['unite_affichage'] ?? '') ?></td>
                                <td><?= number_format($a['prix_achat'], 0, ',', ' ') ?> F</td>
                                <td><strong><?= number_format($a['montant_commande'], 0, ',', ' ') ?> F</strong></td>
                                <td><?= htmlspecialchars($a['nom_boutique'] ?? '—') ?></td>
                                <td><?= $a['date_livraison_recue'] ?: '—' ?></td>
                                <td><span class="badge badge-success"><?= htmlspecialchars($a['etat_commande']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7" style="text-align:right">TOTAL :</td>
                            <td id="totalAchatsFiltre"><?= number_format($totalAchats, 0, ',', ' ') ?> FCFA</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-truck"></i> Fournisseurs</div>
                <div class="section-actions">
                    <button class="btn-action" onclick="exporterTableCSV('tableFournisseurs','fournisseurs')"><i class="fas fa-file-csv"></i> CSV</button>
                </div>
            </h3>

            <?php if (count($rapportFournisseurs) > 0): ?>
                <div class="table-wrapper">
                    <table id="tableFournisseurs">
                        <thead>
                            <tr>
                                <th class="sortable" onclick="trierTable('tableFournisseurs',0)">Fournisseur <i class="fas fa-sort sort-icon"></i></th>
                                <th>Tel.</th>
                                <th>Email</th>
                                <th>Adresse</th>
                                <th class="sortable" onclick="trierTable('tableFournisseurs',4)">Cmd <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" onclick="trierTable('tableFournisseurs',5)">Total <i class="fas fa-sort sort-icon"></i></th>
                                <th>Prix moy.</th>
                                <th>Dern. cmd</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rapportFournisseurs as $f): ?>
                                <tr>
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($f['fournisseur']) ?></strong></td>
                                    <td><?= htmlspecialchars($f['telephone_contact']) ?></td>
                                    <td><?= htmlspecialchars($f['email_contact']) ?></td>
                                    <td><?= htmlspecialchars($f['adresse_contact'] ?? '—') ?></td>
                                    <td><span class="badge badge-purple"><?= $f['nb_commandes'] ?></span></td>
                                    <td><strong><?= number_format($f['montant_total'], 0, ',', ' ') ?> F</strong></td>
                                    <td><?= number_format($f['prix_moyen'], 0, ',', ' ') ?> F</td>
                                    <td><?= $f['derniere_commande'] ?? '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-truck"></i>
                    <p>Aucun fournisseur.</p>
                </div>
            <?php endif; ?>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-file-invoice-dollar"></i> Dettes Fournisseurs</div>
            </h3>

            <?php if (count($dettesFournisseurs) > 0): ?>
                <div class="dettes-summary">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <div class="dettes-total"><?= number_format($totalDettes, 0, ',', ' ') ?> FCFA</div>
                        <div class="dettes-text">Reste sur <?= count($dettesFournisseurs) ?> factures</div>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Libelle</th>
                                <th>Fournisseur</th>
                                <th>Date</th>
                                <th>TTC</th>
                                <th>Avance</th>
                                <th>Reste</th>
                                <th>Etat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dettesFournisseurs as $d): ?>
                                <tr>
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($d['numero_facture']) ?></strong></td>
                                    <td><?= htmlspecialchars($d['titre_facture']) ?></td>
                                    <td><?= htmlspecialchars($d['fournisseur'] ?? '—') ?></td>
                                    <td><?= $d['date_facture'] ?></td>
                                    <td><?= number_format($d['montant_ttc'], 0, ',', ' ') ?> F</td>
                                    <td><?= number_format($d['avance'], 0, ',', ' ') ?> F</td>
                                    <td><span class="badge badge-danger"><?= number_format($d['reste'], 0, ',', ' ') ?> F</span></td>
                                    <td><span class="badge badge-amber"><?= htmlspecialchars($d['etat_facture']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" style="text-align:right">TOTAL DETTES :</td>
                                <td><strong style="color:var(--danger)"><?= number_format($totalDettes, 0, ',', ' ') ?> FCFA</strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert-card success" style="margin-bottom:16px">
                    <h5><i class="fas fa-check-circle" style="color:var(--success)"></i> Aucune dette</h5>
                    <div class="number" style="font-size:1rem;color:var(--success)">0 FCFA</div>
                    <div class="alert-sub">Tout est regle.</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══ TAB B ═══ -->
        <div id="tabB" class="tab-content" role="tabpanel">

            <div class="alert-grid">
                <div class="alert-card critical" onclick="filtrerStockParEtat('rupture')">
                    <h5><i class="fas fa-times-circle" style="color:var(--danger)"></i> Rupture</h5>
                    <div class="number"><?= $stockRupture ?></div>
                    <div class="alert-sub">en rupture</div>
                </div>
                <div class="alert-card warning" onclick="filtrerStockParEtat('faible')">
                    <h5><i class="fas fa-exclamation-triangle" style="color:var(--warning)"></i> Faible</h5>
                    <div class="number"><?= $stockFaible ?></div>
                    <div class="alert-sub">sous seuil</div>
                </div>
                <div class="alert-card info">
                    <h5><i class="fas fa-arrow-down" style="color:var(--info)"></i> Entrees</h5>
                    <div class="number"><?= $mouvementsEntrees ?></div>
                    <div class="alert-sub">unites recues</div>
                </div>
                <div class="alert-card success">
                    <h5><i class="fas fa-arrow-up" style="color:var(--success)"></i> Sorties</h5>
                    <div class="number"><?= $mouvementsSorties ?></div>
                    <div class="alert-sub">unites vendues</div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card">
                    <h4><i class="fas fa-chart-pie"></i> Etat des Stocks</h4>
                    <canvas id="chartEtatStock" height="220"></canvas>
                </div>
                <div class="chart-card">
                    <h4><i class="fas fa-chart-line"></i> Achats vs Ventes</h4>
                    <canvas id="chartAchatsVentes" height="220"></canvas>
                </div>
            </div>

            <div class="search-bar">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="rechercheStock" placeholder="Rechercher un produit..." oninput="filtrerStock()">
            </div>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-warehouse"></i> Stock par Boutique</div>
                <div class="section-actions">
                    <button class="btn-action" onclick="exporterTableCSV('tableStock','stock')"><i class="fas fa-file-csv"></i> CSV</button>
                </div>
            </h3>

            <?php if (count($stockActuel) > 0): ?>
                <div class="table-wrapper">
                    <table id="tableStock">
                        <thead>
                            <tr>
                                <th class="sortable" onclick="trierTable('tableStock',0)">Produit <i class="fas fa-sort sort-icon"></i></th>
                                <th>Cat.</th>
                                <th>Boutique</th>
                                <th class="sortable" onclick="trierTable('tableStock',3)">Qte <i class="fas fa-sort sort-icon"></i></th>
                                <th>Prix F.</th>
                                <th>Valeur</th>
                                <th>Seuil</th>
                                <th>Etat</th>
                                <th>MAJ</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyStock">
                            <?php foreach ($stockActuel as $s): ?>
                                <?php
                                $qte = intval($s['quantite']);
                                $seuil = intval($s['stock_alerte'] ?? 0);
                                $etatClass = $qte <= 0 ? 'badge-danger' : ($qte <= $seuil ? 'badge-warning' : 'badge-success');
                                $etatText = $qte <= 0 ? 'Rupture' : ($qte <= $seuil ? 'Faible' : 'Normal');
                                ?>
                                <tr data-quantite="<?= $qte ?>" data-seuil="<?= $seuil ?>"
                                    data-search="<?= strtolower($s['titre_produit'] . ' ' . $s['nom_boutique'] . ' ' . ($s['titre_categorie'] ?? '')) ?>">
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($s['titre_produit']) ?></strong></td>
                                    <td><span class="badge badge-blue"><?= htmlspecialchars($s['titre_categorie'] ?? '—') ?></span></td>
                                    <td><?= htmlspecialchars($s['nom_boutique']) ?></td>
                                    <td><strong><?= $qte ?></strong></td>
                                    <td><?= number_format($s['prix_fournisseur'], 0, ',', ' ') ?> F</td>
                                    <td><strong><?= number_format($s['valeur_stock'], 0, ',', ' ') ?> F</strong></td>
                                    <td><?= $seuil ?></td>
                                    <td><span class="badge <?= $etatClass ?>"><?= $etatText ?></span></td>
                                    <td><?= $s['maj_le'] ?? '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" style="text-align:right">TOTAL :</td>
                                <td><strong style="color:var(--primary)"><?= number_format($valeurStock, 0, ',', ' ') ?> FCFA</strong></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-warehouse"></i>
                    <p>Aucun stock.</p>
                </div>
            <?php endif; ?>

            <?php if (count($produitsRupture) > 0): ?>
                <h3 class="section-title">
                    <div class="section-title-left"><i class="fas fa-times-circle" style="color:var(--danger)"></i> Rupture</div>
                </h3>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Boutique</th>
                                <th>Qte</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($produitsRupture as $r): ?>
                                <tr>
                                    <td><strong style="color:var(--danger)"><?= htmlspecialchars($r['titre_produit']) ?></strong></td>
                                    <td><?= htmlspecialchars($r['nom_boutique']) ?></td>
                                    <td><span class="badge badge-danger"><?= $r['quantite'] ?></span></td>
                                    <td><button class="btn-action" onclick="showToast('info','Commande','Commander <?= htmlspecialchars($r['titre_produit']) ?>')"><i class="fas fa-cart-plus"></i> Cmd</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (count($produitsAlerte) > 0): ?>
                <h3 class="section-title">
                    <div class="section-title-left"><i class="fas fa-exclamation-triangle" style="color:var(--warning)"></i> Sous Seuil</div>
                </h3>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Boutique</th>
                                <th>Qte</th>
                                <th>Seuil</th>
                                <th>Manque</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($produitsAlerte as $pa): ?>
                                <tr>
                                    <td><strong style="color:var(--warning)"><?= htmlspecialchars($pa['titre_produit']) ?></strong></td>
                                    <td><?= htmlspecialchars($pa['nom_boutique']) ?></td>
                                    <td><span class="badge badge-amber"><?= $pa['quantite'] ?></span></td>
                                    <td><?= $pa['stock_alerte'] ?></td>
                                    <td><span class="badge badge-danger"><?= $pa['manque'] ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══ TAB C ═══ -->
        <div id="tabC" class="tab-content" role="tabpanel">

            <div class="dettes-summary" style="background:linear-gradient(135deg,var(--info-light),#f0f9ff);border-color:rgba(2,132,199,0.15)">
                <i class="fas fa-clipboard-list" style="color:var(--info)"></i>
                <div>
                    <div style="font-size:1.05rem;font-weight:800;color:var(--info)"><?= number_format($totalValSys, 0, ',', ' ') ?> FCFA</div>
                    <div class="dettes-text">Valeur theorique systeme</div>
                </div>
            </div>

            <div class="search-bar">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="rechercheInventaire" placeholder="Rechercher..." oninput="filtrerInventaire()">
            </div>

            <h3 class="section-title">
                <div class="section-title-left"><i class="fas fa-clipboard-check"></i> Inventaire Physique</div>
                <div class="section-actions">
                    <button class="btn-action accent" onclick="validerInventaire()"><i class="fas fa-check-double"></i> Valider</button>
                    <button class="btn-action" onclick="exporterTableCSV('tableInventaire','inventaire')"><i class="fas fa-file-csv"></i> CSV</button>
                </div>
            </h3>

            <?php if (count($stockTheorique) > 0): ?>
                <div class="table-wrapper">
                    <table id="tableInventaire">
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Boutique</th>
                                <th>Systeme</th>
                                <th>Physique</th>
                                <th>Ecart</th>
                                <th>Val. Ecart</th>
                                <th>Obs.</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyInventaire">
                            <?php foreach ($stockTheorique as $st): ?>
                                <?php $qteSys = intval($st['stock_systeme']); ?>
                                <tr data-search="<?= strtolower($st['titre_produit'] . ' ' . $st['nom_boutique']) ?>">
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($st['titre_produit']) ?></strong></td>
                                    <td><?= htmlspecialchars($st['nom_boutique']) ?></td>
                                    <td><strong><?= $qteSys ?></strong></td>
                                    <td><input class="inventory-input" type="number" min="0" value="<?= $qteSys ?>" data-system="<?= $qteSys ?>" data-price="<?= $st['prix_fournisseur'] ?>" onchange="calculerEcart(this)"></td>
                                    <td class="ecart-cell ecart-zero">0</td>
                                    <td class="ecart-value-cell">0 F</td>
                                    <td><span class="badge badge-success">OK</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-clipboard-check"></i>
                    <p>Aucun produit.</p>
                </div>
            <?php endif; ?>

            <?php if (count($transferts) > 0): ?>
                <h3 class="section-title">
                    <div class="section-title-left"><i class="fas fa-exchange-alt"></i> Transferts</div>
                </h3>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Date</th>
                                <th>Produit</th>
                                <th>Qte</th>
                                <th>Boutique</th>
                                <th>Etat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transferts as $t): ?>
                                <tr>
                                    <td><strong style="color:var(--primary)"><?= htmlspecialchars($t['numero_commande']) ?></strong></td>
                                    <td><?= $t['date_commande'] ?></td>
                                    <td><?= htmlspecialchars($t['titre_produit'] ?? '—') ?></td>
                                    <td><?= $t['quantite_commande'] ?></td>
                                    <td><?= htmlspecialchars($t['boutique_concernee'] ?? '—') ?></td>
                                    <td><span class="badge badge-info"><?= htmlspecialchars($t['etat_commande']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        function switchTab(btn, tabId) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }

        function showToast(type, title, message) {
            const container = document.getElementById('toastContainer');
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-times-circle',
                warning: 'fa-exclamation-circle',
                info: 'fa-info-circle'
            };
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<i class="fas ${icons[type]} toast-icon"></i><div class="toast-content"><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div><button class="toast-close" onclick="closeToast(this)"><i class="fas fa-times"></i></button>`;
            container.appendChild(toast);
            setTimeout(() => closeToast(toast.querySelector('.toast-close')), 5000);
        }

        function closeToast(btn) {
            const toast = btn.closest('.toast');
            if (!toast || toast.classList.contains('hiding')) return;
            toast.classList.add('hiding');
            setTimeout(() => toast.remove(), 300);
        }

        function toggleFilters() {
            const bar = document.getElementById('filtersBar');
            const icon = document.getElementById('toggleFiltersIcon');
            const text = document.getElementById('toggleFiltersText');
            const groups = bar.querySelectorAll('.filter-group');
            const isCollapsed = bar.dataset.collapsed === '1';
            if (isCollapsed) {
                groups.forEach(g => g.style.display = '');
                bar.querySelector('.filter-actions').style.display = '';
                icon.className = 'fas fa-chevron-up';
                text.textContent = 'Reduire';
                bar.dataset.collapsed = '0';
            } else {
                groups.forEach((g, i) => {
                    if (i > 1) g.style.display = 'none';
                });
                bar.querySelector('.filter-actions').style.display = 'none';
                icon.className = 'fas fa-chevron-down';
                text.textContent = 'Plus';
                bar.dataset.collapsed = '1';
            }
        }

        function appliquerFiltres() {
            const rows = document.querySelectorAll('#tbodyAchats tr');
            const periode = document.getElementById('filtrePeriode').value;
            const fournisseur = document.getElementById('filtreFournisseur').value;
            const categorie = document.getElementById('filtreCategorie').value;
            const boutique = document.getElementById('filtreBoutique').value;
            const dateDebut = document.getElementById('dateDebut').value;
            const dateFin = document.getElementById('dateFin').value;
            const recherche = document.getElementById('rechercheGlobale').value.toLowerCase();
            const now = new Date();
            let visibleCount = 0,
                totalVisible = 0;

            rows.forEach(row => {
                let show = true;
                const rowDate = row.dataset.date;
                if (fournisseur && row.dataset.fournisseur !== fournisseur) show = false;
                if (categorie && row.dataset.categorie !== categorie) show = false;
                if (boutique && row.dataset.boutique !== boutique) show = false;
                if (dateDebut && rowDate < dateDebut) show = false;
                if (dateFin && rowDate > dateFin) show = false;
                if (periode) {
                    const d = new Date(rowDate);
                    if (periode === 'jour' && d.toDateString() !== now.toDateString()) show = false;
                    if (periode === 'semaine') {
                        const ws = new Date(now);
                        ws.setDate(now.getDate() - now.getDay());
                        if (d < ws) show = false;
                    }
                    if (periode === 'mois' && (d.getMonth() !== now.getMonth() || d.getFullYear() !== now.getFullYear())) show = false;
                    if (periode === 'annee' && d.getFullYear() !== now.getFullYear()) show = false;
                }
                if (recherche && !row.dataset.search.includes(recherche)) show = false;
                row.classList.toggle('hidden-row', !show);
                if (show) {
                    visibleCount++;
                    const mc = row.querySelectorAll('td')[7];
                    if (mc) totalVisible += parseFloat(mc.textContent.replace(/[^\d]/g, '')) || 0;
                }
            });
            document.getElementById('totalAchatsFiltre').textContent = number_format(totalVisible, 0, ',', ' ') + ' FCFA';
            const summary = document.getElementById('filtersSummary');
            const tagsDiv = document.getElementById('filterTags');
            const counter = document.getElementById('resultsCounter');
            const activeFilters = [periode, fournisseur, categorie, boutique, dateDebut, dateFin, recherche].filter(Boolean);
            if (activeFilters.length > 0) {
                summary.classList.remove('hidden');
                tagsDiv.innerHTML = '';
                if (periode) tagsDiv.innerHTML += `<span class="filter-tag">Periode <span class="remove" onclick="document.getElementById('filtrePeriode').value='';appliquerFiltres()">x</span></span>`;
                if (fournisseur) tagsDiv.innerHTML += `<span class="filter-tag">Fourn. <span class="remove" onclick="document.getElementById('filtreFournisseur').value='';appliquerFiltres()">x</span></span>`;
                if (categorie) tagsDiv.innerHTML += `<span class="filter-tag">Cat. <span class="remove" onclick="document.getElementById('filtreCategorie').value='';appliquerFiltres()">x</span></span>`;
                if (boutique) tagsDiv.innerHTML += `<span class="filter-tag">Bout. <span class="remove" onclick="document.getElementById('filtreBoutique').value='';appliquerFiltres()">x</span></span>`;
                if (recherche) tagsDiv.innerHTML += `<span class="filter-tag">"${recherche}" <span class="remove" onclick="document.getElementById('rechercheGlobale').value='';appliquerFiltres()">x</span></span>`;
                counter.textContent = visibleCount + ' resultats';
            } else {
                summary.classList.add('hidden');
            }
        }

        function resetFiltres() {
            ['filtrePeriode', 'filtreFournisseur', 'filtreCategorie', 'filtreBoutique', 'dateDebut', 'dateFin', 'rechercheGlobale'].forEach(id => document.getElementById(id).value = '');
            appliquerFiltres();
            showToast('info', 'Filtres', 'Reinitialises.');
        }

        let sortStates = {};

        function trierTable(tableId, colIdx) {
            const table = document.getElementById(tableId);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr:not(.hidden-row)'));
            const th = table.querySelectorAll('th')[colIdx];
            const key = tableId + '_' + colIdx;
            sortStates[key] = !sortStates[key];
            const asc = sortStates[key];
            th.classList.toggle('sort-asc', asc);
            th.classList.toggle('sort-desc', !asc);
            rows.sort((a, b) => {
                const aVal = a.querySelectorAll('td')[colIdx]?.textContent.trim() || '';
                const bVal = b.querySelectorAll('td')[colIdx]?.textContent.trim() || '';
                const aNum = parseFloat(aVal.replace(/[^\d.-]/g, ''));
                const bNum = parseFloat(bVal.replace(/[^\d.-]/g, ''));
                if (!isNaN(aNum) && !isNaN(bNum)) return asc ? aNum - bNum : bNum - aNum;
                return asc ? aVal.localeCompare(bVal, 'fr') : bVal.localeCompare(aVal, 'fr');
            });
            rows.forEach(r => tbody.appendChild(r));
        }

        function exporterTableCSV(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) return;
            let csv = '';
            table.querySelectorAll('tr').forEach(row => {
                if (row.classList.contains('hidden-row')) return;
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

        function filtrerStock() {
            const search = document.getElementById('rechercheStock').value.toLowerCase();
            document.querySelectorAll('#tbodyStock tr').forEach(row => {
                row.classList.toggle('hidden-row', search && !row.dataset.search.includes(search));
            });
        }

        function filtrerStockParEtat(etat) {
            document.querySelectorAll('#tbodyStock tr').forEach(row => {
                const qte = parseInt(row.dataset.quantite);
                const seuil = parseInt(row.dataset.seuil);
                let show = true;
                if (etat === 'rupture' && qte > 0) show = false;
                if (etat === 'faible' && (qte <= 0 || qte > seuil)) show = false;
                row.classList.toggle('hidden-row', !show);
            });
            showToast('info', 'Filtre', `Produits en ${etat==='rupture'?'rupture':'stock faible'}.`);
        }

        function calculerEcart(input) {
            const sys = parseInt(input.dataset.system);
            const phys = parseInt(input.value) || 0;
            const price = parseFloat(input.dataset.price) || 0;
            const ecart = phys - sys;
            const ecartVal = ecart * price;
            const row = input.closest('tr');
            const ecartCell = row.querySelector('.ecart-cell');
            const ecartValCell = row.querySelector('.ecart-value-cell');
            const obsCell = row.querySelectorAll('td')[6];
            ecartCell.textContent = ecart;
            ecartCell.className = 'ecart-cell ' + (ecart > 0 ? 'ecart-positive' : ecart < 0 ? 'ecart-negative' : 'ecart-zero');
            ecartValCell.textContent = number_format(Math.abs(ecartVal), 0, ',', ' ') + ' F';
            ecartValCell.style.color = ecart < 0 ? 'var(--danger)' : ecart > 0 ? 'var(--success)' : 'var(--text-soft)';
            ecartValCell.style.fontWeight = '700';
            input.classList.toggle('modified', ecart !== 0);
            obsCell.innerHTML = ecart === 0 ? '<span class="badge badge-success">OK</span>' : ecart > 0 ? '<span class="badge badge-info">+' + ecart + '</span>' : '<span class="badge badge-danger">-' + Math.abs(ecart) + '</span>';
        }

        function filtrerInventaire() {
            const search = document.getElementById('rechercheInventaire').value.toLowerCase();
            document.querySelectorAll('#tbodyInventaire tr').forEach(row => {
                row.classList.toggle('hidden-row', search && !row.dataset.search.includes(search));
            });
        }

        function validerInventaire() {
            const inputs = document.querySelectorAll('.inventory-input.modified');
            if (!inputs.length) {
                showToast('info', 'Inventaire', 'Aucun ecart.');
                return;
            }
            let m = 0,
                e = 0;
            inputs.forEach(i => {
                const ec = parseInt(i.value) - parseInt(i.dataset.system);
                if (ec < 0) m++;
                else e++;
            });
            showToast('warning', 'Inventaire', `${inputs.length} ecarts : ${m} manquants, ${e} excedents.`);
        }

        function number_format(number, decimals, dec_point, thousands_sep) {
            number = parseFloat(number);
            if (isNaN(number)) return '0';
            const str = number.toFixed(decimals);
            const parts = str.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousands_sep);
            return parts.join(dec_point);
        }

        function rafraichirDonnees() {
            showToast('info', 'Rafraichir', 'Rechargement...');
            setTimeout(() => location.reload(), 800);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const ctxEtat = document.getElementById('chartEtatStock')?.getContext('2d');
            if (ctxEtat) {
                new Chart(ctxEtat, {
                    type: 'doughnut',
                    data: {
                        labels: ['Normal', 'Faible', 'Rupture'],
                        datasets: [{
                            data: [<?= $stockNormal ?>, <?= $stockFaible ?>, <?= $stockRupture ?>],
                            backgroundColor: ['#2563eb', '#ea580c', '#dc2626'],
                            borderWidth: 0,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 12,
                                    usePointStyle: true,
                                    font: {
                                        family: 'Plus Jakarta Sans',
                                        size: 12
                                    }
                                }
                            }
                        },
                        cutout: '55%'
                    }
                });
            }
            const ctxAV = document.getElementById('chartAchatsVentes')?.getContext('2d');
            if (ctxAV && <?= count($achatsVentesMois) ?> > 0) {
                new Chart(ctxAV, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode(array_column($achatsVentesMois, 'mois')) ?>,
                        datasets: [{
                                label: 'Achats',
                                data: <?= json_encode(array_column($achatsVentesMois, 'achats')) ?>,
                                backgroundColor: 'rgba(30,64,175,0.7)',
                                borderColor: '#1e40af',
                                borderWidth: 1,
                                borderRadius: 4
                            },
                            {
                                label: 'Ventes',
                                data: <?= json_encode(array_column($achatsVentesMois, 'ventes')) ?>,
                                backgroundColor: 'rgba(217,119,6,0.7)',
                                borderColor: '#d97706',
                                borderWidth: 1,
                                borderRadius: 4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 12,
                                    usePointStyle: true,
                                    font: {
                                        family: 'Plus Jakarta Sans',
                                        size: 12
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    font: {
                                        family: 'Plus Jakarta Sans'
                                    }
                                },
                                grid: {
                                    color: '#e2e8f0'
                                }
                            },
                            x: {
                                ticks: {
                                    font: {
                                        family: 'Plus Jakarta Sans'
                                    }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>

</body>

</html>