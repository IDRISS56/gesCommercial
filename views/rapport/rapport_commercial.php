<?php
// rapport_commercial.php
// Fusion de : chiffre_affaires.php, resume_ventes.php, marge_beneficiaire.php,
// rentabilite_ventes.php, performance_vendeur.php
// Filtres (période + boutique) partagés entre les 5 onglets.
require 'databases/database.php';
require 'fonctions_rapport.php';

// - Authentification -
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}
$stmtU = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmtU->execute([$_SESSION['user_id']]);
$user = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}
if (!in_array($user['role'], ['Administrateur', 'Superviseur', 'Proprietaire'], true)) {
    http_response_code(403);
    die("Accès non autorisé à ce rapport.");
}

if (!function_exists('e')) {
    function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt')) {
    function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }
}

// ==========================================================
// FILTRES PARTAGÉS : PÉRIODE + BOUTIQUE
// ==========================================================
$periodesValides = ['journalier', 'hebdomadaire', 'mensuel', 'trimestriel', 'semestriel', 'annuel'];
$periode = $_GET['periode'] ?? $_POST['periode'] ?? 'journalier';
if (!in_array($periode, $periodesValides, true)) $periode = 'journalier';

function valeurParDefaut($periode) {
    switch ($periode) {
        case 'hebdomadaire': return date('o') . '-W' . date('W');
        case 'mensuel':      return date('Y-m');
        case 'trimestriel':  return date('Y') . '-T' . (int)ceil(date('n') / 3);
        case 'semestriel':   return date('Y') . '-S' . (date('n') <= 6 ? 1 : 2);
        case 'annuel':       return date('Y');
        default:             return date('Y-m-d');
    }
}

$valeur = $_GET['valeur'] ?? $_POST['valeur'] ?? valeurParDefaut($periode);
$boutiqueId = trim($_GET['boutique_id'] ?? $_POST['boutique_id'] ?? '');

// Construction de la clause de date (sur c.date_commande) selon la période
$whereDate = "1=1";
if ($periode === 'journalier' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
    $whereDate = "DATE(c.date_commande) = '$valeur'";
} elseif ($periode === 'hebdomadaire' && preg_match('/^(\d{4})-W(\d{2})$/', $valeur, $m)) {
    $whereDate = "YEARWEEK(c.date_commande, 3) = '{$m[1]}{$m[2]}'";
} elseif ($periode === 'mensuel' && preg_match('/^\d{4}-\d{2}$/', $valeur)) {
    $whereDate = "DATE_FORMAT(c.date_commande, '%Y-%m') = '$valeur'";
} elseif ($periode === 'trimestriel' && preg_match('/^(\d{4})-T([1-4])$/', $valeur, $m)) {
    $whereDate = "YEAR(c.date_commande) = '{$m[1]}' AND QUARTER(c.date_commande) = '{$m[2]}'";
} elseif ($periode === 'semestriel' && preg_match('/^(\d{4})-S([1-2])$/', $valeur, $m)) {
    $moisDebut = ($m[2] == '1') ? 1 : 7;
    $moisFin = ($m[2] == '1') ? 6 : 12;
    $whereDate = "YEAR(c.date_commande) = '{$m[1]}' AND MONTH(c.date_commande) BETWEEN $moisDebut AND $moisFin";
} elseif ($periode === 'annuel' && preg_match('/^\d{4}$/', $valeur)) {
    $whereDate = "YEAR(c.date_commande) = '$valeur'";
} else {
    $valeur = valeurParDefaut($periode);
    switch ($periode) {
        case 'hebdomadaire':
            preg_match('/^(\d{4})-W(\d{2})$/', $valeur, $m);
            $whereDate = "YEARWEEK(c.date_commande, 3) = '{$m[1]}{$m[2]}'";
            break;
        case 'mensuel':
            $whereDate = "DATE_FORMAT(c.date_commande, '%Y-%m') = '$valeur'";
            break;
        case 'trimestriel':
            preg_match('/^(\d{4})-T([1-4])$/', $valeur, $m);
            $whereDate = "YEAR(c.date_commande) = '{$m[1]}' AND QUARTER(c.date_commande) = '{$m[2]}'";
            break;
        case 'semestriel':
            preg_match('/^(\d{4})-S([1-2])$/', $valeur, $m);
            $moisDebut = ($m[2] == '1') ? 1 : 7;
            $moisFin = ($m[2] == '1') ? 6 : 12;
            $whereDate = "YEAR(c.date_commande) = '{$m[1]}' AND MONTH(c.date_commande) BETWEEN $moisDebut AND $moisFin";
            break;
        case 'annuel':
            $whereDate = "YEAR(c.date_commande) = '$valeur'";
            break;
        default:
            $whereDate = "DATE(c.date_commande) = '$valeur'";
    }
}

$paramsBoutique = [];
$whereBoutique = "";
if ($boutiqueId !== '') {
    $whereBoutique = " AND c.boutique_id = :boutique_id";
    $paramsBoutique[':boutique_id'] = $boutiqueId;
}

$whereVente = "c.statut_id='012' AND c.etat_commande NOT IN ('En attente','Annulé')";
$whereFull = "$whereVente AND $whereDate$whereBoutique";
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

// ==========================================================
// FONCTION DE RENDU DU CHAMP VALEUR SELON LA PÉRIODE
// ==========================================================
function renderValeurInput($periode, $valeur) {
    switch ($periode) {
        case 'journalier':
            echo '<input type="date" name="valeur" class="form-select" value="' . e($valeur) . '">';
            break;
        case 'hebdomadaire':
            echo '<input type="week" name="valeur" class="form-select" value="' . e($valeur) . '">';
            break;
        case 'mensuel':
            echo '<input type="month" name="valeur" class="form-select" value="' . e($valeur) . '">';
            break;
        case 'trimestriel':
            echo '<select name="valeur" class="form-select">';
            $ac = (int)date('Y');
            for ($a = $ac - 2; $a <= $ac + 1; $a++) {
                for ($t = 1; $t <= 4; $t++) {
                    $v = "$a-T$t";
                    $sel = ($v === $valeur) ? ' selected' : '';
                    echo "<option value=\"$v\"$sel>T$t $a</option>";
                }
            }
            echo '</select>';
            break;
        case 'semestriel':
            echo '<select name="valeur" class="form-select">';
            $ac = (int)date('Y');
            for ($a = $ac - 2; $a <= $ac + 1; $a++) {
                for ($s = 1; $s <= 2; $s++) {
                    $v = "$a-S$s";
                    $sel = ($v === $valeur) ? ' selected' : '';
                    $lib = ($s == 1) ? 'Jan-Juin' : 'Juil-Déc';
                    echo "<option value=\"$v\"$sel>S$s $a ($lib)</option>";
                }
            }
            echo '</select>';
            break;
        case 'annuel':
            echo '<select name="valeur" class="form-select">';
            $ac = (int)date('Y');
            for ($a = $ac - 3; $a <= $ac + 1; $a++) {
                $sel = ((string)$a === $valeur) ? ' selected' : '';
                echo "<option value=\"$a\"$sel>$a</option>";
            }
            echo '</select>';
            break;
    }
}

// ==========================================================
// PAGINATION
// ==========================================================
function paginer($pdo, $sql, $countSql, $params, $page, $perPage, $rowRenderer, $colspan) {
    $page = max(1, (int)$page);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPages = (int)ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare($sql . " LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($rows)) {
        echo '<tr><td colspan="' . $colspan . '" class="empty-cell"><i class="bi bi-inbox d-block mb-2" style="font-size:3rem;opacity:.2;"></i><div class="text-muted small">Aucune donnée pour cette période</div></td></tr>';
    } else {
        foreach ($rows as $row) echo $rowRenderer($row);
    }
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top">
        <span class="text-muted small">Affichage de <?= ($offset + 1) ?> à <?= min($offset + $perPage, $total) ?> sur <?= $total ?></span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $page - 1 ?>"><i class="bi bi-chevron-left"></i></a></li>
                <?php
                $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
                if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>'; if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
                for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>"><a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a></li>
                <?php endfor;
                if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $totalPages . '">' . $totalPages . '</a></li>'; }
                ?>
                <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>"><a class="page-link" href="#" data-page="<?= $page + 1 ?>"><i class="bi bi-chevron-right"></i></a></li>
            </ul>
        </nav>
    </div>
    <?php endif;
    $paginationHtml = ob_get_clean();
    return compact('tableHtml', 'paginationHtml', 'total', 'page', 'totalPages');
}

// ==========================================================
// ONGLET 1 : CHIFFRE D'AFFAIRES
// ==========================================================
function chargerCA($pdo, $whereFull, $paramsBoutique, $page) {
    $sql = "SELECT DATE_FORMAT(c.date_commande, '%Y-%m-%d') AS date, COUNT(*) AS nb_ventes, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca
            FROM commande c WHERE $whereFull GROUP BY DATE_FORMAT(c.date_commande, '%Y-%m-%d') ORDER BY c.date_commande DESC";
    $countSql = "SELECT COUNT(DISTINCT DATE_FORMAT(c.date_commande, '%Y-%m-%d')) FROM commande c WHERE $whereFull";
    $renderer = function ($row) {
        return '<tr><td class="fw-semibold">' . e($row['date']) . '</td><td>' . (int)$row['nb_ventes'] . '</td><td class="fw-bold text-primary">' . fmt($row['ca']) . ' F</td></tr>';
    };
    return paginer($pdo, $sql, $countSql, $paramsBoutique, $page, 20, $renderer, 3);
}

// ==========================================================
// ONGLET 2 : RÉSUMÉ DES VENTES
// ==========================================================
function chargerVentes($pdo, $whereFull, $paramsBoutique, $page) {
    $sql = "SELECT c.numero_commande, c.date_commande, c.heure_commande, c.quantite_commande, c.montant_commande, c.etat_commande,
            p.titre_produit, ct.nom_prenom_contact AS client, b.nom_boutique, cat.titre_categorie
            FROM commande c
            LEFT JOIN produit p ON c.produit_id = p.code_produit
            LEFT JOIN contact ct ON c.contact_id = ct.code_contact
            LEFT JOIN boutique b ON c.boutique_id = b.code_boutique
            LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
            WHERE $whereFull ORDER BY c.date_commande DESC, c.heure_commande DESC";
    $countSql = "SELECT COUNT(*) FROM commande c WHERE $whereFull";
    $renderer = function ($row) {
        $etat = $row['etat_commande'];
        $etatLower = strtolower($etat);
        if ($etatLower === 'validé' || $etatLower === 'valider' || $etatLower === 'validee') {
            $badgeClass = 'bg-success-subtle text-success';
        } elseif ($etatLower === 'en attente') {
            $badgeClass = 'bg-warning-subtle text-warning';
        } else {
            $badgeClass = 'bg-danger-subtle text-danger';
        }
        return '<tr>'
            . '<td class="fw-bold">' . e($row['numero_commande']) . '</td>'
            . '<td>' . date('d/m/Y H:i', strtotime($row['date_commande'] . ' ' . $row['heure_commande'])) . '</td>'
            . '<td class="fw-semibold">' . e($row['client'] ?? '—') . '</td>'
            . '<td>' . e($row['titre_produit'] ?? '—') . '</td>'
            . '<td>' . e($row['titre_categorie'] ?? '—') . '</td>'
            . '<td class="text-center">' . (int)$row['quantite_commande'] . '</td>'
            . '<td class="text-end fw-bold text-primary">' . fmt($row['montant_commande']) . ' F</td>'
            . '<td>' . e($row['nom_boutique'] ?? '—') . '</td>'
            . '<td><span class="badge-chic ' . $badgeClass . '"><span class="dot"></span> ' . e($etat) . '</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, $paramsBoutique, $page, 20, $renderer, 9);
}

// ==========================================================
// ONGLET 3 : MARGE BÉNÉFICIAIRE
// ==========================================================
function chargerMarge($pdo, $whereFull, $paramsBoutique, $page) {
    $sql = "SELECT p.titre_produit, cat.titre_categorie,
            AVG(CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2))) AS prix_achat_moyen,
            AVG(CAST(COALESCE(c.prix_commande,0) AS DECIMAL(12,2))) AS prix_vente_moyen,
            SUM(c.quantite_commande) AS qte_vendue,
            COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca,
            COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2)) - (CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2)) * c.quantite_commande)),0) AS marge_totale
            FROM commande c JOIN produit p ON c.produit_id = p.code_produit
            LEFT JOIN categorie cat ON p.categorie_id = cat.code_categorie
            WHERE $whereFull GROUP BY p.code_produit HAVING qte_vendue > 0 ORDER BY marge_totale DESC";
    $countSql = "SELECT COUNT(*) FROM (SELECT p.code_produit FROM commande c JOIN produit p ON c.produit_id=p.code_produit WHERE $whereFull GROUP BY p.code_produit HAVING SUM(c.quantite_commande) > 0) x";
    $renderer = function ($row) {
        $prixAchat = (float)$row['prix_achat_moyen'];
        $margeTotale = (float)$row['marge_totale'];
        $taux = ($prixAchat > 0 && $row['qte_vendue'] > 0) ? round(($margeTotale / ($prixAchat * $row['qte_vendue'])) * 100, 1) : 0;
        if ($taux > 20) {
            $badgeClass = 'bg-success-subtle text-success';
        } elseif ($taux > 10) {
            $badgeClass = 'bg-warning-subtle text-warning';
        } else {
            $badgeClass = 'bg-danger-subtle text-danger';
        }
        return '<tr>'
            . '<td class="fw-bold">' . e($row['titre_produit']) . '</td>'
            . '<td>' . e($row['titre_categorie'] ?? '—') . '</td>'
            . '<td class="text-center">' . (int)$row['qte_vendue'] . '</td>'
            . '<td class="text-end">' . fmt($prixAchat) . ' F</td>'
            . '<td class="text-end">' . fmt((float)$row['prix_vente_moyen']) . ' F</td>'
            . '<td class="text-end fw-bold text-success">' . fmt($margeTotale) . ' F</td>'
            . '<td class="text-center"><span class="badge-chic ' . $badgeClass . '"><span class="dot"></span> ' . $taux . '%</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, $paramsBoutique, $page, 20, $renderer, 7);
}

// ==========================================================
// ONGLET 4 : RENTABILITÉ DES VENTES
// ==========================================================
function chargerRentabilite($pdo, $whereFull, $paramsBoutique, $page) {
    $sql = "SELECT c.numero_commande, c.date_commande, p.titre_produit, c.quantite_commande,
            CAST(c.montant_commande AS DECIMAL(12,2)) AS montant,
            (CAST(c.montant_commande AS DECIMAL(12,2)) - (CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2)) * c.quantite_commande)) AS benefice,
            ((CAST(c.montant_commande AS DECIMAL(12,2)) - (CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2)) * c.quantite_commande)) / NULLIF(CAST(c.montant_commande AS DECIMAL(12,2)),0)) * 100 AS taux
            FROM commande c JOIN produit p ON c.produit_id = p.code_produit
            WHERE $whereFull ORDER BY benefice DESC";
    $countSql = "SELECT COUNT(*) FROM commande c JOIN produit p ON c.produit_id=p.code_produit WHERE $whereFull";
    $renderer = function ($row) {
        $benefice = (float)$row['benefice'];
        $taux = (float)($row['taux'] ?? 0);
        if ($taux > 20) {
            $badgeClass = 'bg-success-subtle text-success';
        } elseif ($taux > 10) {
            $badgeClass = 'bg-warning-subtle text-warning';
        } else {
            $badgeClass = 'bg-danger-subtle text-danger';
        }
        return '<tr>'
            . '<td class="fw-bold">' . e($row['numero_commande']) . '</td>'
            . '<td>' . date('d/m/Y', strtotime($row['date_commande'])) . '</td>'
            . '<td>' . e($row['titre_produit']) . '</td>'
            . '<td class="text-center">' . (int)$row['quantite_commande'] . '</td>'
            . '<td class="text-end fw-bold text-primary">' . fmt((float)$row['montant']) . ' F</td>'
            . '<td class="text-end fw-semibold ' . ($benefice >= 0 ? 'text-success' : 'text-danger') . '">' . fmt($benefice) . ' F</td>'
            . '<td class="text-center"><span class="badge-chic ' . $badgeClass . '"><span class="dot"></span> ' . number_format($taux, 1) . '%</span></td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, $paramsBoutique, $page, 20, $renderer, 7);
}

// ==========================================================
// ONGLET 5 : PERFORMANCE DES VENDEURS
// ==========================================================
function chargerVendeurs($pdo, $whereFull, $paramsBoutique, $page) {
    $sql = "SELECT u.id, u.nom_prenom, u.role,
            COUNT(DISTINCT c.numero_commande) AS nb_ventes,
            COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca_total,
            COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2)) - (CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2)) * c.quantite_commande)),0) AS marge_totale,
            MAX(c.date_commande) AS derniere_vente
            FROM utilisateur u
            JOIN commande c ON u.id = c.utilisateur_id AND $whereFull
            WHERE u.etat = 'Actif'
            GROUP BY u.id ORDER BY ca_total DESC";
    $countSql = "SELECT COUNT(*) FROM (SELECT u.id FROM utilisateur u JOIN commande c ON u.id=c.utilisateur_id AND $whereFull WHERE u.etat='Actif' GROUP BY u.id) x";
    $renderer = function ($row) {
        return '<tr>'
            . '<td class="fw-bold">' . e($row['nom_prenom']) . '</td>'
            . '<td><span class="badge-chic bg-primary-subtle text-primary"><span class="dot"></span> ' . e($row['role']) . '</span></td>'
            . '<td class="text-center">' . (int)$row['nb_ventes'] . '</td>'
            . '<td class="text-end fw-bold text-primary">' . fmt((float)$row['ca_total']) . ' F</td>'
            . '<td class="text-end fw-semibold text-success">' . fmt((float)$row['marge_totale']) . ' F</td>'
            . '<td>' . ($row['derniere_vente'] ? date('d/m/Y', strtotime($row['derniere_vente'])) : '—') . '</td>'
            . '</tr>';
    };
    return paginer($pdo, $sql, $countSql, $paramsBoutique, $page, 20, $renderer, 6);
}

// ==========================================================
// DISPATCHER AJAX
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $tab = $_POST['tab'] ?? 'ca';
    $page = (int)($_POST['page'] ?? 1);
    switch ($tab) {
        case 'ventes':      $res = chargerVentes($pdo, $whereFull, $paramsBoutique, $page); break;
        case 'marge':       $res = chargerMarge($pdo, $whereFull, $paramsBoutique, $page); break;
        case 'rentabilite': $res = chargerRentabilite($pdo, $whereFull, $paramsBoutique, $page); break;
        case 'vendeurs':    $res = chargerVendeurs($pdo, $whereFull, $paramsBoutique, $page); break;
        default:            $res = chargerCA($pdo, $whereFull, $paramsBoutique, $page); break;
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['table' => $res['tableHtml'], 'pagination' => $res['paginationHtml'], 'total' => $res['total']]);
    exit;
}

// ==========================================================
// CHARGEMENT INITIAL
// ==========================================================
$resCA          = chargerCA($pdo, $whereFull, $paramsBoutique, 1);
$resVentes      = chargerVentes($pdo, $whereFull, $paramsBoutique, 1);
$resMarge       = chargerMarge($pdo, $whereFull, $paramsBoutique, 1);
$resRentabilite = chargerRentabilite($pdo, $whereFull, $paramsBoutique, 1);
$resVendeurs    = chargerVendeurs($pdo, $whereFull, $paramsBoutique, 1);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS total_ca, COUNT(*) AS total_nb FROM commande c WHERE $whereFull");
$stmt->execute($paramsBoutique);
$totaux = $stmt->fetch(PDO::FETCH_ASSOC);
$total_ca = (float)$totaux['total_ca'];
$total_nb = (int)$totaux['total_nb'];
$moyenne = $total_nb ? $total_ca / $total_nb : 0;

$stmt = $pdo->prepare("SELECT COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2)) - (CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2)) * c.quantite_commande)),0) AS marge FROM commande c WHERE $whereFull");
$stmt->execute($paramsBoutique);
$margeGlobale = (float)$stmt->fetchColumn();
$tauxGlobal = $total_ca > 0 ? round(($margeGlobale / $total_ca) * 100, 1) : 0;

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.utilisateur_id) FROM commande c WHERE $whereFull");
$stmt->execute($paramsBoutique);
$nbVendeursActifs = (int)$stmt->fetchColumn();

// Graphique 1 : évolution du CA (12 derniers mois)
$sqlEvol = "SELECT DATE_FORMAT(c.date_commande,'%Y-%m') AS mois, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca
            FROM commande c WHERE $whereVente AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . $whereBoutique . "
            GROUP BY mois ORDER BY mois ASC";
$stmt = $pdo->prepare($sqlEvol);
$stmt->execute($paramsBoutique);
$caMensuel = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Graphique 2 : ventes par catégorie
$sqlCat = "SELECT cat.titre_categorie, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2))),0) AS ca
           FROM commande c LEFT JOIN produit p ON c.produit_id=p.code_produit LEFT JOIN categorie cat ON p.categorie_id=cat.code_categorie
           WHERE $whereFull GROUP BY cat.code_categorie ORDER BY ca DESC";
$stmt = $pdo->prepare($sqlCat);
$stmt->execute($paramsBoutique);
$catVentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Graphique 3 : marge par catégorie
$sqlMargeCat = "SELECT cat.titre_categorie, COALESCE(SUM(CAST(c.montant_commande AS DECIMAL(12,2)) - (CAST(COALESCE(c.prix_achat,0) AS DECIMAL(12,2)) * c.quantite_commande)),0) AS marge
                FROM commande c JOIN produit p ON c.produit_id=p.code_produit LEFT JOIN categorie cat ON p.categorie_id=cat.code_categorie
                WHERE $whereFull GROUP BY cat.code_categorie HAVING marge > 0 ORDER BY marge DESC";
$stmt = $pdo->prepare($sqlMargeCat);
$stmt->execute($paramsBoutique);
$margeCat = $stmt->fetchAll(PDO::FETCH_ASSOC);

$onglet = $_GET['onglet'] ?? 'ca';
if (!in_array($onglet, ['ca', 'ventes', 'marge', 'rentabilite', 'vendeurs'], true)) $onglet = 'ca';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapport Commercial</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
    --color-primary: #4f46e5;
    --color-primary-dark: #3730a3;
    --color-primary-soft: #eef2ff;
    --color-success: #10b981;
    --color-success-soft: #d1fae5;
    --color-warning: #f59e0b;
    --color-warning-soft: #fef3c7;
    --color-danger: #ef4444;
    --color-danger-soft: #fee2e2;
    --color-info: #0891b2;
    --color-info-soft: #cffafe;
    --color-purple: #8b5cf6;
    --color-purple-soft: #ede9fe;
    --color-gray-50: #f8fafc;
    --color-gray-100: #f1f5f9;
    --color-gray-200: #e2e8f0;
    --color-gray-300: #cbd5e1;
    --color-gray-400: #94a3b8;
    --color-gray-500: #64748b;
    --color-gray-600: #475569;
    --color-gray-700: #334155;
    --color-gray-800: #1e293b;
    --color-gray-900: #0f172a;
    --bg-body: #f1f5f9;
    --bg-surface: #ffffff;
    --border-color: #e2e8f0;
    --text-primary: #0f172a;
    --text-secondary: #334155;
    --text-tertiary: #64748b;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
    --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.08);
    --radius-sm: 10px;
    --radius-md: 14px;
    --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', sans-serif;
    background: var(--bg-body);
    color: var(--text-primary);
    min-height: 100vh;
    font-size: 14px;
    padding: 24px 20px;
}
h1, h2, h3, h4, h5, h6 {
    font-family: 'Outfit', sans-serif;
    font-weight: 700;
    letter-spacing: -0.02em;
}
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
::-webkit-scrollbar-track { background: transparent; }
.W { max-width: 1400px; margin: 0 auto; }

/* ===== STATS ===== */
.stat-card {
    background: var(--bg-surface); border: 1px solid var(--border-color);
    border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base);
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

/* ===== FILTRES ===== */
.filters-section {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 16px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
}
.filters-section label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-tertiary);
    letter-spacing: 0.5px;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.filters-section .form-select {
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    padding: 8px 12px;
    background: #fff;
    color: var(--text-primary);
}
.filters-section .form-select:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}
.btn-filter {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.25s;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}
.btn-filter:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
}
.btn-reset {
    background: var(--color-gray-100);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-reset:hover {
    background: var(--color-gray-200);
    color: var(--text-primary);
}

/* ===== ONGLETS ===== */
.nav-tabs {
    border-bottom: 2px solid var(--border-color);
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 4px;
}
.nav-tabs .nav-link {
    border: none;
    color: var(--text-tertiary);
    font-weight: 600;
    font-size: 13px;
    padding: 12px 18px;
    border-radius: 8px 8px 0 0;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.nav-tabs .nav-link:hover {
    color: var(--color-primary);
    background: var(--color-primary-soft);
}
.nav-tabs .nav-link.active {
    color: var(--color-primary);
    background: var(--color-primary-soft);
    border-bottom: 2px solid var(--color-primary);
    margin-bottom: -2px;
}

/* ===== CHART CARDS ===== */
.chart-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    transition: var(--transition-base);
}
.chart-card:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--color-gray-300);
}
.chart-card h4 {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-tertiary);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.chart-card h4 i { font-size: 16px; }

/* ===== REPORT CARDS ===== */
.report-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 20px 24px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    transition: var(--transition-base);
}
.report-card:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--color-gray-300);
}
.report-card h3 {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    border-bottom: 2px solid var(--color-gray-100);
    padding-bottom: 12px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.report-card h3 i {
    font-size: 18px;
    color: var(--color-primary);
}

/* ===== TABLES ===== */
.table-wrapper {
    overflow-x: auto;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    background: var(--bg-surface);
}
table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
}
thead {
    background: linear-gradient(135deg, var(--color-gray-50) 0%, var(--color-gray-100) 100%);
}
th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 700;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-tertiary);
    border-bottom: 2px solid var(--border-color);
}
td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--color-gray-100);
    color: var(--text-primary);
    vertical-align: middle;
}
tbody tr { transition: background 0.15s; }
tbody tr:hover { background: var(--color-primary-soft); }
tbody tr:last-child td { border-bottom: none; }
.empty-cell {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-tertiary);
}

/* ===== PAGINATION ===== */
.pagination { gap: 4px; }
.pagination .page-link {
    color: var(--color-primary);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s;
}
.pagination .page-link:hover {
    background: var(--color-primary-soft);
    border-color: var(--color-primary);
    color: var(--color-primary-dark);
}
.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
    border-color: var(--color-primary);
    color: #fff;
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
}
.pagination .page-item.disabled .page-link {
    color: var(--text-tertiary);
    background: var(--color-gray-50);
}

/* ===== BADGES ===== */
.badge-chic {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.06);
}
.badge-chic .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* ===== SELECTPICKER ===== */
.bootstrap-select .dropdown-toggle {
    background: #fff !important;
    border: 1.5px solid var(--border-color) !important;
    border-radius: 8px !important;
    font-size: 13px;
    padding: 8px 12px;
}
.bootstrap-select .dropdown-toggle:focus {
    border-color: var(--color-primary) !important;
    box-shadow: 0 0 0 3px var(--color-primary-soft) !important;
}
.bootstrap-select .dropdown-menu {
    border-radius: 8px;
    border-color: var(--border-color);
    box-shadow: var(--shadow-lg);
}

/* ===== ANIMATIONS ===== */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}
.chart-card, .report-card, .stat-card {
    animation: fadeUp 0.4s ease both;
}

@media (max-width: 768px) {
    .filters-section .d-flex {
        flex-direction: column;
        align-items: stretch;
    }
    .bootstrap-select, .bootstrap-select .dropdown-toggle {
        width: 100% !important;
    }
}
</style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-graph-up-arrow text-primary me-2"></i>Rapport Commercial</h1>
            <p class="text-muted small mb-0">Ventes, marge, rentabilité et performance des vendeurs</p>
        </div>
        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
            <i class="bi bi-cash-stack"></i> CA: <?= fmt($total_ca) ?> F
        </span>
    </div>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['primary', 'cash-stack', 'CA Total', fmt($total_ca) . ' F', ''],
            ['info', 'cart-check', 'Nb Ventes', $total_nb, ''],
            ['purple', 'basket', 'Panier Moyen', fmt($moyenne) . ' F', ''],
            ['success', 'piggy-bank', 'Marge', fmt($margeGlobale) . ' F', '(' . $tauxGlobal . '%)'],
            ['warning', 'people', 'Vendeurs actifs', $nbVendeursActifs, ''],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
            'danger'  => ['var(--color-danger-soft)', 'var(--color-danger)'],
            'purple'  => ['var(--color-purple-soft)', 'var(--color-purple)'],
            'info'    => ['var(--color-info-soft)', 'var(--color-info)'],
        ];
        foreach ($stats as $s):
            $bg = $colorMap[$s[0]][0];
            $fg = $colorMap[$s[0]][1];
        ?>
        <div class="col-6 col-md-4 col-xl">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background: <?= $bg ?>; color: <?= $fg ?>;">
                    <i class="bi bi-<?= $s[1] ?>"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-label"><?= $s[2] ?></div>
                    <div class="stat-value text-truncate"><?= $s[3] ?><?php if ($s[4]): ?><small class="text-muted ms-1" style="font-size:11px;"><?= $s[4] ?></small><?php endif; ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filtres -->
    <div class="filters-section">
        <form method="GET" id="filterFormMain">
            <input type="hidden" name="onglet" id="ongletInput" value="<?= e($onglet) ?>">
            <div class="d-flex flex-wrap align-items-end gap-3">
                <div class="flex-grow-1" style="min-width: 180px;">
                    <label for="periodeSelect"><i class="bi bi-calendar3"></i> Période</label>
                    <select name="periode" id="periodeSelect" class="form-select selectpicker" data-live-search="true">
                        <option value="journalier" <?= $periode=='journalier'?'selected':'' ?>>Journalier</option>
                        <option value="hebdomadaire" <?= $periode=='hebdomadaire'?'selected':'' ?>>Hebdomadaire</option>
                        <option value="mensuel" <?= $periode=='mensuel'?'selected':'' ?>>Mensuel</option>
                        <option value="trimestriel" <?= $periode=='trimestriel'?'selected':'' ?>>Trimestriel</option>
                        <option value="semestriel" <?= $periode=='semestriel'?'selected':'' ?>>Semestriel</option>
                        <option value="annuel" <?= $periode=='annuel'?'selected':'' ?>>Annuel</option>
                    </select>
                </div>
                <div class="flex-grow-1" style="min-width: 180px;">
                    <label><i class="bi bi-calendar-range"></i> Valeur</label>
                    <div id="valeurContainer">
                        <?php renderValeurInput($periode, $valeur); ?>
                    </div>
                </div>
                <div class="flex-grow-1" style="min-width: 180px;">
                    <label for="boutiqueSelect"><i class="bi bi-shop"></i> Boutique</label>
                    <select name="boutique_id" id="boutiqueSelect" class="form-select selectpicker" data-live-search="true">
                        <option value="">Toutes les boutiques</option>
                        <?php foreach ($boutiques as $b): ?>
                            <option value="<?= e($b['code_boutique']) ?>" <?= $boutiqueId==$b['code_boutique']?'selected':'' ?>><?= e($b['nom_boutique']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn-filter"><i class="bi bi-funnel"></i> Filtrer</button>
                    <a href="?onglet=<?= e($onglet) ?>" class="btn-reset"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Onglets -->
    <ul class="nav nav-tabs" id="rapportTabs" role="tablist">
        <li class="nav-item"><button class="nav-link <?= $onglet=='ca'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-ca" type="button" data-tab="ca"><i class="bi bi-cash-stack"></i> Chiffre d'affaires</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='ventes'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-ventes" type="button" data-tab="ventes"><i class="bi bi-cart-check"></i> Résumé des ventes</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='marge'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-marge" type="button" data-tab="marge"><i class="bi bi-piggy-bank"></i> Marge bénéficiaire</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='rentabilite'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-rentabilite" type="button" data-tab="rentabilite"><i class="bi bi-graph-up-arrow"></i> Rentabilité des ventes</button></li>
        <li class="nav-item"><button class="nav-link <?= $onglet=='vendeurs'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#pane-vendeurs" type="button" data-tab="vendeurs"><i class="bi bi-people"></i> Performance vendeurs</button></li>
    </ul>

    <div class="tab-content">
        <!-- ONGLET 1 : CA -->
        <div class="tab-pane fade <?= $onglet=='ca'?'show active':'' ?>" id="pane-ca">
            <div class="chart-card">
                <h4><i class="bi bi-graph-up-arrow"></i> Évolution du CA (12 derniers mois)</h4>
                <canvas id="chartCA" height="90"></canvas>
            </div>
            <div class="report-card">
                <h3><i class="bi bi-list-ul"></i> Détail du CA par jour</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Date</th><th>Nb Ventes</th><th>CA</th></tr></thead>
                        <tbody id="tbody-ca"><?= $resCA['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-ca"><?= $resCA['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 2 : VENTES -->
        <div class="tab-pane fade <?= $onglet=='ventes'?'show active':'' ?>" id="pane-ventes">
            <div class="chart-card">
                <h4><i class="bi bi-pie-chart"></i> Ventes par catégorie</h4>
                <canvas id="chartCat" height="90"></canvas>
            </div>
            <div class="report-card">
                <h3><i class="bi bi-list-ul"></i> Détail des ventes</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>N° Commande</th><th>Date</th><th>Client</th><th>Produit</th><th>Catégorie</th><th>Qté</th><th>Montant</th><th>Boutique</th><th>État</th></tr></thead>
                        <tbody id="tbody-ventes"><?= $resVentes['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-ventes"><?= $resVentes['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 3 : MARGE -->
        <div class="tab-pane fade <?= $onglet=='marge'?'show active':'' ?>" id="pane-marge">
            <div class="chart-card">
                <h4><i class="bi bi-bar-chart"></i> Marge par catégorie</h4>
                <canvas id="chartMarge" height="90"></canvas>
            </div>
            <div class="report-card">
                <h3><i class="bi bi-list-ul"></i> Marge par produit</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Produit</th><th>Catégorie</th><th>Qté vendue</th><th>Prix achat moy.</th><th>Prix vente moy.</th><th>Marge totale</th><th>Taux</th></tr></thead>
                        <tbody id="tbody-marge"><?= $resMarge['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-marge"><?= $resMarge['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 4 : RENTABILITÉ -->
        <div class="tab-pane fade <?= $onglet=='rentabilite'?'show active':'' ?>" id="pane-rentabilite">
            <div class="report-card">
                <h3><i class="bi bi-list-ul"></i> Rentabilité par vente</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>N° Commande</th><th>Date</th><th>Produit</th><th>Qté</th><th>Montant</th><th>Bénéfice</th><th>Taux</th></tr></thead>
                        <tbody id="tbody-rentabilite"><?= $resRentabilite['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-rentabilite"><?= $resRentabilite['paginationHtml'] ?></div>
            </div>
        </div>

        <!-- ONGLET 5 : VENDEURS -->
        <div class="tab-pane fade <?= $onglet=='vendeurs'?'show active':'' ?>" id="pane-vendeurs">
            <div class="report-card">
                <h3><i class="bi bi-list-ul"></i> Performance par vendeur</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Nom</th><th>Rôle</th><th>Nb ventes</th><th>CA total</th><th>Marge</th><th>Dernière vente</th></tr></thead>
                        <tbody id="tbody-vendeurs"><?= $resVendeurs['tableHtml'] ?></tbody>
                    </table>
                </div>
                <div id="pagination-vendeurs"><?= $resVendeurs['paginationHtml'] ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire caché pour la pagination AJAX -->
<form id="filterForm" style="display:none;">
    <input type="hidden" name="periode" value="<?= e($periode) ?>">
    <input type="hidden" name="valeur" value="<?= e($valeur) ?>">
    <input type="hidden" name="boutique_id" value="<?= e($boutiqueId) ?>">
</form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(document).ready(function () {
    $('.selectpicker').selectpicker('destroy');
    $('.selectpicker').selectpicker();

    // Synchroniser le formulaire caché avec les filtres visibles avant chaque requête AJAX
    function syncFilterForm() {
        $('#filterForm input[name="periode"]').val($('#periodeSelect').val());
        var $valeurInput = $('#valeurContainer').find('[name="valeur"]');
        $('#filterForm input[name="valeur"]').val($valeurInput.val());
        $('#filterForm input[name="boutique_id"]').val($('#boutiqueSelect').val());
    }

    // Quand la période change → on remplace le champ "Valeur" dynamiquement
    $('#periodeSelect').on('changed.bs.select', function () {
        var periode = $(this).val();
        var $container = $('#valeurContainer');
        var html = '';

        switch (periode) {
            case 'journalier':
                html = '<input type="date" name="valeur" class="form-select" value="' + new Date().toISOString().split('T')[0] + '">';
                break;
            case 'hebdomadaire':
                var now = new Date();
                var tempDate = new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()));
                var dayNum = tempDate.getUTCDay() || 7;
                tempDate.setUTCDate(tempDate.getUTCDate() + 4 - dayNum);
                var yearStart = new Date(Date.UTC(tempDate.getUTCFullYear(), 0, 1));
                var weekNum = Math.ceil((((tempDate - yearStart) / 86400000) + 1) / 7);
                var weekStr = tempDate.getUTCFullYear() + '-W' + (weekNum < 10 ? '0' : '') + weekNum;
                html = '<input type="week" name="valeur" class="form-select" value="' + weekStr + '">';
                break;
            case 'mensuel':
                var m = new Date();
                var monthStr = m.getFullYear() + '-' + ((m.getMonth() + 1) < 10 ? '0' : '') + (m.getMonth() + 1);
                html = '<input type="month" name="valeur" class="form-select" value="' + monthStr + '">';
                break;
            case 'trimestriel':
                var ac = new Date().getFullYear();
                var ct = Math.ceil((new Date().getMonth() + 1) / 3);
                html = '<select name="valeur" class="form-select">';
                for (var a = ac - 2; a <= ac + 1; a++) {
                    for (var t = 1; t <= 4; t++) {
                        var sel = (a == ac && t == ct) ? ' selected' : '';
                        html += '<option value="' + a + '-T' + t + '"' + sel + '>T' + t + ' ' + a + '</option>';
                    }
                }
                html += '</select>';
                break;
            case 'semestriel':
                var ac2 = new Date().getFullYear();
                var cs = (new Date().getMonth() < 6) ? 1 : 2;
                html = '<select name="valeur" class="form-select">';
                for (var a = ac2 - 2; a <= ac2 + 1; a++) {
                    for (var s = 1; s <= 2; s++) {
                        var lib = (s == 1) ? 'Jan-Juin' : 'Juil-Déc';
                        var sel = (a == ac2 && s == cs) ? ' selected' : '';
                        html += '<option value="' + a + '-S' + s + '"' + sel + '>S' + s + ' ' + a + ' (' + lib + ')</option>';
                    }
                }
                html += '</select>';
                break;
            case 'annuel':
                var ac3 = new Date().getFullYear();
                html = '<select name="valeur" class="form-select">';
                for (var a = ac3 - 3; a <= ac3 + 1; a++) {
                    var sel = (a == ac3) ? ' selected' : '';
                    html += '<option value="' + a + '"' + sel + '>' + a + '</option>';
                }
                html += '</select>';
                break;
        }

        $container.html(html);
        syncFilterForm();
    });

    // Synchroniser quand la valeur change aussi
    $('#valeurContainer').on('change', '[name="valeur"]', syncFilterForm);
    $('#boutiqueSelect').on('changed.bs.select', syncFilterForm);

    // Mémoriser l'onglet actif dans l'URL
    $('#rapportTabs button').on('shown.bs.tab', function (e) {
        var tab = $(e.target).data('tab');
        $('#ongletInput').val(tab);
        var url = new URL(window.location.href);
        url.searchParams.set('onglet', tab);
        history.replaceState(null, '', url);
    });

    // --- Graphiques ---
    var ctxCA = document.getElementById('chartCA')?.getContext('2d');
    if (ctxCA) {
        new Chart(ctxCA, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($caMensuel, 'mois')) ?>,
                datasets: [{
                    label: 'CA (FCFA)',
                    data: <?= json_encode(array_map(fn($r) => floatval($r['ca']), $caMensuel)) ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.08)',
                    tension: 0.3,
                    fill: true,
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointBackgroundColor: '#4f46e5'
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } }
            }
        });
    }
    var ctxCat = document.getElementById('chartCat')?.getContext('2d');
    if (ctxCat) {
        new Chart(ctxCat, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($r) => $r['titre_categorie'] ?? 'Sans catégorie', $catVentes)) ?>,
                datasets: [{
                    label: 'CA (FCFA)',
                    data: <?= json_encode(array_map(fn($r) => floatval($r['ca']), $catVentes)) ?>,
                    backgroundColor: 'rgba(79, 70, 229, 0.85)',
                    borderRadius: 6
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } }
            }
        });
    }
    var ctxMarge = document.getElementById('chartMarge')?.getContext('2d');
    if (ctxMarge) {
        new Chart(ctxMarge, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($r) => $r['titre_categorie'] ?? 'Sans catégorie', $margeCat)) ?>,
                datasets: [{
                    label: 'Marge (FCFA)',
                    data: <?= json_encode(array_map(fn($r) => floatval($r['marge']), $margeCat)) ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.85)',
                    borderRadius: 6
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } }
            }
        });
    }

    // --- Pagination AJAX par onglet ---
    function chargerPage(tab, page) {
        syncFilterForm();
        var data = $('#filterForm').serialize() + '&ajax=1&tab=' + tab + '&page=' + page;
        $.post(window.location.pathname, data, function (res) {
            $('#tbody-' + tab).html(res.table);
            $('#pagination-' + tab).html(res.pagination);
        }, 'json');
    }
    $('.tab-content').on('click', '.pagination .page-link', function (e) {
        e.preventDefault();
        var page = $(this).data('page');
        if (!page || $(this).closest('li').hasClass('disabled')) return;
        var tab = $(this).closest('.tab-pane').attr('id').replace('pane-', '');
        chargerPage(tab, page);
    });
});
</script>
</body>
</html>