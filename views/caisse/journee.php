<?php
ob_start();
function sendJson($data){
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json');
echo json_encode($data); exit;
}
require 'databases/database.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../utilisateur/login'); exit; }
$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { session_destroy(); header('Location: ../utilisateur/login'); exit; }

// $estCaissier autorise l'ouverture/fermeture d'une journée pour les rôles
// Caisse, Administrateur, Superviseur et Proprietaire (le nom de la variable
// est un raccourci historique, ce n'est pas réservé au seul rôle "Caisse").
$estCaissier = ($user['role'] === 'Caisse' || $user['role'] === 'Administrateur' || $user['role'] === 'Superviseur' || $user['role'] === 'Proprietaire');

// Boutique(s) auxquelles cet utilisateur a accès : toutes les boutiques
// actives pour Administrateur/Superviseur, uniquement la sienne (+ accès
// supplémentaire éventuel) pour les autres rôles — même mécanisme que le
// reste de l'application (config/authentification.php).
$boutiquesAutoriseesJournee = getBoutiquesAutorisees($pdo, $user['role'], $user['boutique_id']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$message = '';
$messageType = '';

// ============================================================
// FONCTIONS UTILITAIRES
// ============================================================
function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDec($n) { return number_format((float)$n, 0, ',', ' '); }

function generateJourneeId($pdo) {
    return 'JC-' . date('YmdHis') . rand(100, 999);
}

function recalculerJournee($pdo, $j) {
    // ⚠️ Corrigé : compte les transactions sur TOUTE la période d'ouverture
    // (de la date d'ouverture jusqu'à aujourd'hui inclus), pas seulement la
    // date d'ouverture. Avant ce correctif, une journée restée ouverte
    // plusieurs jours (oubli de fermeture, écart qui a découragé la clôture)
    // "perdait" silencieusement toutes les transactions des jours suivants
    // dans son calcul théorique — elles étaient bien enregistrées en base,
    // mais jamais comptées ici, ce qui donnait l'impression que rien ne
    // s'enregistrait et faisait apparaître un écart énorme à la fermeture.
    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN type_transaction='Entree' THEN montant_total ELSE 0 END), 0) as total_entrees,
        COALESCE(SUM(CASE WHEN type_transaction='Sortie' THEN montant_total ELSE 0 END), 0) as total_sorties,
        COUNT(*) as nombre_transactions
        FROM transaction
        WHERE caisse_id = ? AND etat_transaction = 'Succes' AND date_transaction BETWEEN ? AND CURDATE()");
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

// ============================================================
// TRAITEMENT OUVERTURE JOURNÉE
// ============================================================
if (isset($_POST['btn_ouvrir'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!$estCaissier) {
        $message = "Seul le caissier peut ouvrir une journée."; $messageType = 'danger';
    } elseif ($token !== $csrf_token) {
        $message = "Token invalide."; $messageType = 'danger';
    } else {
        $caisse_id = $_POST['caisse_id'] ?? '';
        $solde_ouverture = floatval(str_replace(',', '.', $_POST['solde_ouverture'] ?? 0));

        if (empty($caisse_id)) {
            $message = "Veuillez sélectionner une caisse."; $messageType = 'warning';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'OUVERTE'");
                $stmt->execute([$caisse_id]);
                if ($stmt->fetchColumn() > 0) {
                    $message = "Une journée est déjà ouverte sur cette caisse."; $messageType = 'warning';
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM caisse WHERE caisse_id = ?");
                    $stmt->execute([$caisse_id]);
                    $c = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$c) throw new Exception("Caisse introuvable.");
                    if (!empty($c['boutique_id']) && !in_array($c['boutique_id'], $boutiquesAutoriseesJournee, true)) {
                        throw new Exception("Vous n'avez pas accès à cette caisse (autre boutique).");
                    }

                    $pdo->beginTransaction();
                    $id = generateJourneeId($pdo);
                    $pdo->prepare("INSERT INTO journees_caisse (id, caisse_id, date_journee, date_ouverture, solde_ouverture, total_entrees, total_sorties, nombre_transactions, boutique_id, id_utilisateur_ouverture, statut)
                                   VALUES (?, ?, CURDATE(), NOW(), ?, 0, 0, 0, ?, ?, 'OUVERTE')")
                        ->execute([$id, $caisse_id, $solde_ouverture, $c['boutique_id'], $user['id']]);
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
    if (!$estCaissier) {
        $message = "Seul le caissier peut fermer une journée."; $messageType = 'danger';
    } elseif ($token !== $csrf_token) {
        $message = "Token invalide."; $messageType = 'danger';
    } else {
        $journee_id = $_POST['journee_id'] ?? '';
        $solde_physique = floatval(str_replace(',', '.', $_POST['solde_physique'] ?? 0));
        $observations = trim($_POST['observations'] ?? '');

        try {
            $stmt = $pdo->prepare("SELECT jc.*, c.nom_caisse, c.boutique_id FROM journees_caisse jc JOIN caisse c ON c.caisse_id = jc.caisse_id WHERE jc.id = ?");
            $stmt->execute([$journee_id]);
            $j = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$j) throw new Exception("Journée introuvable.");
            if (!empty($j['boutique_id']) && !in_array($j['boutique_id'], $boutiquesAutoriseesJournee, true)) {
                throw new Exception("Vous n'avez pas accès à cette journée (autre boutique).");
            }
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
$placeholdersBq = !empty($boutiquesAutoriseesJournee) ? implode(',', array_fill(0, count($boutiquesAutoriseesJournee), '?')) : "''";

$stmt = $pdo->prepare("SELECT c.*, b.nom_boutique FROM caisse c LEFT JOIN boutique b ON b.code_boutique = c.boutique_id
                        WHERE c.statut != 'Inactif' AND (c.boutique_id IN ($placeholdersBq) OR c.boutique_id IS NULL)
                        ORDER BY c.nom_caisse");
$stmt->execute($boutiquesAutoriseesJournee);
$caissesDisponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT jc.*, c.nom_caisse, c.caisse_id as code_caisse, b.nom_boutique, u.nom_prenom AS ouvert_par
                     FROM journees_caisse jc
                     JOIN caisse c ON c.caisse_id = jc.caisse_id
                     LEFT JOIN boutique b ON b.code_boutique = c.boutique_id
                     LEFT JOIN utilisateur u ON u.id = jc.id_utilisateur_ouverture
                     WHERE jc.statut = 'OUVERTE' AND (c.boutique_id IN ($placeholdersBq) OR c.boutique_id IS NULL)
                     ORDER BY jc.date_ouverture DESC");
$stmt->execute($boutiquesAutoriseesJournee);
$journeesOuvertes = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($journeesOuvertes as &$j) { $j = array_merge($j, recalculerJournee($pdo, $j)); }
unset($j);

// Historique des journées fermées également scopé par boutique, par
// cohérence avec le reste de l'application (les autres écrans filtrent déjà
// systématiquement tous leurs listings de cette façon).
$stmt = $pdo->prepare("SELECT jc.*, c.nom_caisse, uo.nom_prenom AS ouvert_par, uf.nom_prenom AS ferme_par
                     FROM journees_caisse jc
                     JOIN caisse c ON c.caisse_id = jc.caisse_id
                     LEFT JOIN utilisateur uo ON uo.id = jc.id_utilisateur_ouverture
                     LEFT JOIN utilisateur uf ON uf.id = jc.id_utilisateur_fermeture
                     WHERE jc.statut = 'FERMEE' AND (c.boutique_id IN ($placeholdersBq) OR c.boutique_id IS NULL)
                     ORDER BY jc.date_fermeture DESC LIMIT 30");
$stmt->execute($boutiquesAutoriseesJournee);
$historique = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$nbOuvertes = count($journeesOuvertes);
$stmtNbFermees = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse jc JOIN caisse c ON c.caisse_id = jc.caisse_id
                                 WHERE jc.statut = 'FERMEE' AND (c.boutique_id IN ($placeholdersBq) OR c.boutique_id IS NULL)");
$stmtNbFermees->execute($boutiquesAutoriseesJournee);
$nbFermees = $stmtNbFermees->fetchColumn();
$totalEntreesJour = 0;
$totalSortiesJour = 0;
$totalEcart = 0;
foreach ($journeesOuvertes as $j) {
    $totalEntreesJour += $j['total_entrees'];
    $totalSortiesJour += $j['total_sorties'];
}
foreach ($historique as $h) {
    $totalEcart += abs($h['ecart_solde'] ?? 0);
}
$soldeOuvertureTotal = 0;
foreach ($journeesOuvertes as $j) {
    $soldeOuvertureTotal += $j['solde_ouverture'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Journées de caisse</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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

/* ===== SECTION TITRE ===== */
.section-title {
font-size: 14px;
font-weight: 700;
color: var(--text-primary);
text-transform: uppercase;
letter-spacing: 0.8px;
margin-bottom: 16px;
display: flex;
align-items: center;
gap: 10px;
}
.section-title i {
width: 32px;
height: 32px;
border-radius: 8px;
display: inline-flex;
align-items: center;
justify-content: center;
font-size: 16px;
}
.section-title.open i { background: var(--color-success-soft); color: var(--color-success); }
.section-title.history i { background: var(--color-info-soft); color: var(--color-info); }

/* ===== CARTES JOURNÉES OUVERTES ===== */
.journee-card {
background: var(--bg-surface);
border: 1px solid var(--border-color);
border-radius: var(--radius-sm);
padding: 16px;
cursor: pointer;
transition: all 0.15s ease;
position: relative;
animation: fadeUp .4s ease both;
overflow: hidden;
}
.journee-card::before {
content: '';
position: absolute;
top: 0;
left: 0;
right: 0;
height: 3px;
background: linear-gradient(90deg, var(--color-success), #06b6d4);
}
.journee-card:hover {
border-color: var(--color-success);
box-shadow: 0 4px 16px rgba(16, 185, 129, .15);
transform: translateY(-2px);
}
.jc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.jc-caisse {
font-size: 13px;
font-weight: 700;
color: var(--text-primary);
display: flex;
align-items: center;
gap: 6px;
}
.jc-caisse i { color: var(--color-success); font-size: 14px; }
.jc-boutique {
font-size: 10px;
color: var(--text-tertiary);
display: flex;
align-items: center;
gap: 3px;
margin-top: 2px;
}
.jc-status {
display: inline-flex;
align-items: center;
gap: 4px;
padding: 3px 8px;
border-radius: 999px;
font-size: 9px;
font-weight: 700;
text-transform: uppercase;
letter-spacing: 0.3px;
background: var(--color-success-soft);
color: var(--color-success);
}
.jc-status .dot {
width: 6px;
height: 6px;
border-radius: 50%;
background: var(--color-success);
animation: pulse 2s infinite;
}
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

.jc-grid {
display: grid;
grid-template-columns: repeat(2, 1fr);
gap: 8px;
margin: 12px 0;
padding: 10px;
background: var(--color-gray-50);
border-radius: 8px;
}
.jc-stat {
display: flex;
flex-direction: column;
gap: 2px;
}
.jc-stat-label {
font-size: 9px;
font-weight: 700;
color: var(--text-tertiary);
text-transform: uppercase;
letter-spacing: 0.4px;
}
.jc-stat-value {
font-size: 13px;
font-weight: 800;
font-family: 'Outfit', sans-serif;
color: var(--text-primary);
line-height: 1;
}
.jc-stat-value.entrees { color: var(--color-success); }
.jc-stat-value.sorties { color: var(--color-danger); }
.jc-stat-value.theorique { color: var(--color-primary); }

.jc-bottom {
display: flex;
justify-content: space-between;
align-items: center;
padding-top: 10px;
border-top: 1px dashed var(--border-color);
}
.jc-ouvert-par {
font-size: 10px;
color: var(--text-tertiary);
display: flex;
align-items: center;
gap: 4px;
}
.jc-ouvert-par i { font-size: 11px; }
.jc-actions { display: flex; gap: 6px; }

/* Boutons icônes */
.icon-btn {
width: 30px; height: 30px; border-radius: 7px; border: 1.5px solid transparent;
background: transparent; display: inline-flex; align-items: center; justify-content: center;
transition: all .2s; font-size: 13px; cursor: pointer; padding: 0; position: relative;
}
.icon-btn:hover { transform: scale(1.1); }
.icon-btn.close { color: var(--color-warning); border-color: rgba(245, 158, 11, 0.2); }
.icon-btn.close:hover { color: #b45309; background: var(--color-warning-soft); border-color: var(--color-warning); }
.icon-btn.view { color: var(--color-primary); border-color: rgba(79, 70, 229, 0.2); }
.icon-btn.view:hover { color: var(--color-primary-dark); background: var(--color-primary-soft); border-color: var(--color-primary); }
.icon-btn::before {
content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%;
transform: translateX(-50%); background: var(--color-gray-800); color: #fff;
padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 600;
white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity .2s; z-index: 10;
}
.icon-btn:hover::before { opacity: 1; }

/* ===== STATS ===== */
.stat-card {
background: var(--bg-surface); border: 1px solid var(--border-color);
border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base);
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

/* ===== EMPTY STATE ===== */
.empty-state {
background: var(--bg-surface);
border: 2px dashed var(--border-color);
border-radius: var(--radius-md);
padding: 50px 20px;
text-align: center;
color: var(--text-tertiary);
}

/* ===== TABLEAU HISTORIQUE ===== */
.hist-table-wrap {
background: var(--bg-surface);
border: 1px solid var(--border-color);
border-radius: var(--radius-sm);
overflow: hidden;
box-shadow: var(--shadow-sm);
}
.hist-table {
width: 100%;
font-size: 13px;
margin: 0;
}
.hist-table thead th {
background: var(--color-gray-50);
color: var(--text-tertiary);
font-size: 10px;
font-weight: 700;
text-transform: uppercase;
letter-spacing: 0.6px;
padding: 12px 14px;
border-bottom: 2px solid var(--border-color);
}
.hist-table tbody td {
padding: 12px 14px;
border-bottom: 1px solid var(--color-gray-100);
color: var(--text-secondary);
}
.hist-table tbody tr:hover { background: var(--color-primary-soft); }
.hist-table tbody tr:last-child td { border-bottom: none; }

.badge-ecart {
display: inline-flex;
align-items: center;
gap: 4px;
padding: 3px 10px;
border-radius: 999px;
font-size: 11px;
font-weight: 700;
font-family: 'Outfit', sans-serif;
}
.badge-ecart.pos { background: var(--color-success-soft); color: var(--color-success); }
.badge-ecart.neg { background: var(--color-danger-soft); color: var(--color-danger); }
.badge-ecart.zero { background: var(--color-gray-100); color: var(--text-tertiary); }

/* ===== MODAL CHIC ===== */
.modal-chic .modal-content {
border: none; border-radius: 20px;
box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15); overflow: hidden;
animation: modalSlideIn .4s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalSlideIn {
from { opacity: 0; transform: translateY(30px) scale(0.96); }
to { opacity: 1; transform: translateY(0) scale(1); }
}
.modal-chic .modal-header {
background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%);
color: #fff; border: none; padding: 22px 28px; position: relative; overflow: hidden;
}
.modal-chic .modal-header::before {
content: ''; position: absolute; top: -50%; right: -20%; width: 200px; height: 200px;
background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%;
}
.modal-chic .modal-title { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
.modal-chic .modal-title i { font-size: 22px; background: rgba(255,255,255,0.15); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px); }
.modal-chic .btn-close { filter: invert(1); opacity: 0.7; position: relative; z-index: 1; transition: all .2s; }
.modal-chic .btn-close:hover { opacity: 1; transform: rotate(90deg); }
.modal-chic .modal-body { padding: 28px; background: #f8fafc; }
.modal-chic .modal-footer { background: #fff; border-top: 1px solid var(--border-color); padding: 18px 28px; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }

/* Sections chic */
.detail-section-chic { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 20px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); transition: all .2s; }
.detail-section-chic:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.06); border-color: #cbd5e1; }
.detail-section-title-chic { font-size: 11px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.8px; padding-bottom: 10px; border-bottom: 2px solid #f1f5f9; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.detail-section-title-chic.info i { color: #3b82f6; }
.detail-section-title-chic.money i { color: #10b981; }
.detail-section-title-chic.warn i { color: #f59e0b; }

.form-label-chic { font-size: 10px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.form-control-chic {
background: #fff;
border: 1.5px solid var(--border-color);
border-radius: 8px;
padding: 10px 14px;
font-size: 13px;
transition: all .2s;
width: 100%;
}
.form-control-chic:focus {
border-color: var(--color-primary);
box-shadow: 0 0 0 3px var(--color-primary-soft);
outline: none;
}

/* Boutons chic */
.btn-chic { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s; position: relative; overflow: hidden; }
.btn-chic i { font-size: 15px; position: relative; z-index: 1; }
.btn-chic span { position: relative; z-index: 1; }
.btn-chic-primary { background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%); color: #fff; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
.btn-chic-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4); }
.btn-chic-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
.btn-chic-success:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); }
.btn-chic-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #fff; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); }
.btn-chic-warning:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4); }
.btn-chic-secondary { background: linear-gradient(135deg, #64748b 0%, #475569 100%); color: #fff; box-shadow: 0 4px 12px rgba(100, 116, 139, 0.25); }
.btn-chic-secondary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(100, 116, 139, 0.35); }

/* ===== SELECTPICKER ===== */
.bootstrap-select .dropdown-toggle {
background: #fff !important;
border: 1.5px solid var(--border-color) !important;
border-radius: 8px !important;
min-width: 220px;
}
.bootstrap-select .dropdown-toggle:focus {
border-color: var(--color-primary) !important;
box-shadow: 0 0 0 3px var(--color-primary-soft) !important;
}

/* ===== ANIMATIONS ===== */
@keyframes fadeUp {
from { opacity: 0; transform: translateY(12px); }
to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 700px) {
.bootstrap-select, .bootstrap-select .dropdown-toggle {
width: 100% !important;
min-width: 0 !important;
}
.hist-table-wrap { overflow-x: auto; }
}
</style>
</head>
<body>
<div class="W">
<!-- En-tête -->
<div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
<div>
<h1 class="h3 fw-bold mb-1"><i class="bi bi-calendar-check text-primary me-2"></i>Journées de caisse</h1>
<p class="text-muted small mb-0"><?= $estCaissier ? 'Ouverture et fermeture des caisses — Suivi en temps réel' : 'Suivi en temps réel — lecture seule (réservé au caissier)' ?></p>
</div>
<?php if ($estCaissier): ?>
<button class="btn-chic btn-chic-primary" id="openBtn"><i class="bi bi-unlock-fill"></i><span>Ouvrir une journée</span></button>
<?php endif; ?>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
<?= $message ?>
<button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Statistiques -->
<div class="row g-3 mb-4">
<?php
$stats = [
['success', 'unlock-fill', 'Journées ouvertes', $nbOuvertes, ''],
['info', 'lock-fill', 'Journées fermées', $nbFermees, ''],
['primary', 'cash-stack', 'Fonds de départ', fmtDec($soldeOuvertureTotal), ' F'],
['purple', 'arrow-down-circle-fill', 'Total entrées', fmtDec($totalEntreesJour), ' F'],
['warning', 'arrow-up-circle-fill', 'Total sorties', fmtDec($totalSortiesJour), ' F'],
['danger', 'exclamation-triangle-fill', 'Écarts cumulés', fmtDec($totalEcart), ' F'],
];
$colorMap = [
'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
'success' => ['var(--color-success-soft)', 'var(--color-success)'],
'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
'danger' => ['var(--color-danger-soft)', 'var(--color-danger)'],
'info' => ['var(--color-info-soft)', 'var(--color-info)'],
'purple' => ['var(--color-purple-soft)', 'var(--color-purple)'],
];
foreach ($stats as $s):
$bg = $colorMap[$s[0]][0];
$fg = $colorMap[$s[0]][1];
?>
<div class="col-6 col-md-4 col-xl-2">
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

<!-- Journées en cours -->
<div class="section-title open">
<i class="bi bi-broadcast"></i>
<span>Journées en cours</span>
<span class="badge rounded-pill bg-success-subtle text-success ms-2"><?= $nbOuvertes ?></span>
</div>

<?php if (empty($journeesOuvertes)): ?>
<div class="empty-state mb-4">
<i class="bi bi-calendar-x d-block mb-2" style="font-size:56px;opacity:.2;"></i>
<h5 class="text-dark">Aucune journée ouverte</h5>
<p class="small mb-0">Cliquez sur « Ouvrir une journée » pour commencer.</p>
</div>
<?php else: ?>
<div class="row g-3 mb-5">
<?php foreach ($journeesOuvertes as $j): ?>
<div class="col-12 col-md-6 col-lg-4 col-xl-3">
<div class="journee-card">
<div class="jc-top">
<div>
<div class="jc-caisse"><i class="bi bi-cash-register"></i> <?= e($j['nom_caisse']) ?></div>
<div class="jc-boutique"><i class="bi bi-shop"></i> <?= e($j['nom_boutique'] ?? 'Aucune boutique') ?></div>
</div>
<div class="jc-status"><span class="dot"></span> Ouverte</div>
</div>
<?php
$joursOuverte = (strtotime(date('Y-m-d')) - strtotime($j['date_journee'])) / 86400;
if ($joursOuverte >= 1):
?>
<div class="alert alert-warning py-1 px-2 mb-2" style="font-size:11px;border-radius:8px;">
<i class="bi bi-exclamation-triangle-fill"></i>
Ouverte depuis <?= (int)$joursOuverte ?> jour(s) (le <?= date('d/m/Y', strtotime($j['date_journee'])) ?>) — à fermer dès que possible.
</div>
<?php endif; ?>

<div class="jc-grid">
<div class="jc-stat">
<div class="jc-stat-label">Solde ouv.</div>
<div class="jc-stat-value"><?= fmtDec($j['solde_ouverture']) ?> F</div>
</div>
<div class="jc-stat">
<div class="jc-stat-label">Théorique</div>
<div class="jc-stat-value theorique"><?= fmtDec($j['solde_theorique']) ?> F</div>
</div>
<div class="jc-stat">
<div class="jc-stat-label">Entrées</div>
<div class="jc-stat-value entrees">+<?= fmtDec($j['total_entrees']) ?> F</div>
</div>
<div class="jc-stat">
<div class="jc-stat-label">Sorties</div>
<div class="jc-stat-value sorties">-<?= fmtDec($j['total_sorties']) ?> F</div>
</div>
</div>

<div class="jc-bottom">
<div class="jc-ouvert-par">
<i class="bi bi-person-circle"></i>
<span><?= e($j['ouvert_par'] ?? '—') ?> • <?= date('H:i', strtotime($j['date_ouverture'])) ?></span>
</div>
<div class="jc-actions">
<?php if ($estCaissier): ?>
<button class="icon-btn close fermer-journee"
data-id="<?= e($j['id']) ?>"
data-caisse="<?= e($j['nom_caisse']) ?>"
data-theorique="<?= $j['solde_theorique'] ?>"
data-tooltip="Fermer" title="Fermer">
<i class="bi bi-lock-fill"></i>
</button>
<?php endif; ?>
</div>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Historique -->
<div class="section-title history">
<i class="bi bi-clock-history"></i>
<span>Historique (30 dernières)</span>
</div>

<?php if (empty($historique)): ?>
<div class="empty-state mb-4">
<i class="bi bi-inbox d-block mb-2" style="font-size:56px;opacity:.2;"></i>
<h5 class="text-dark">Aucune journée clôturée</h5>
<p class="small mb-0">L'historique apparaîtra ici après les fermetures.</p>
</div>
<?php else: ?>
<div class="hist-table-wrap mb-4">
<div class="table-responsive">
<table class="hist-table">
<thead>
<tr>
<th>Caisse</th>
<th>Date</th>
<th class="text-end">Ouverture</th>
<th class="text-end">Fermeture</th>
<th class="text-end">Théorique</th>
<th class="text-end">Physique</th>
<th class="text-end">Écart</th>
<th>Ouvert par</th>
<th>Fermé par</th>
</tr>
</thead>
<tbody>
<?php foreach ($historique as $h):
$ecartClass = ($h['ecart_solde'] ?? 0) > 0 ? 'pos' : (($h['ecart_solde'] ?? 0) < 0 ? 'neg' : 'zero');
$ecartIcon = $ecartClass === 'pos' ? 'arrow-up' : ($ecartClass === 'neg' ? 'arrow-down' : 'dash');
?>
<tr>
<td class="fw-bold"><?= e($h['nom_caisse']) ?></td>
<td><?= date('d/m/Y', strtotime($h['date_journee'])) ?></td>
<td class="text-end"><?= date('H:i', strtotime($h['date_ouverture'])) ?></td>
<td class="text-end"><?= $h['date_fermeture'] ? date('H:i', strtotime($h['date_fermeture'])) : '—' ?></td>
<td class="text-end fw-semibold"><?= fmtDec($h['solde_theorique']) ?> F</td>
<td class="text-end fw-semibold"><?= fmtDec($h['solde_physique']) ?> F</td>
<td class="text-end">
<span class="badge-ecart <?= $ecartClass ?>">
<i class="bi bi-<?= $ecartIcon ?>"></i>
<?= fmtDec(abs($h['ecart_solde'] ?? 0)) ?> F
</span>
</td>
<td class="text-muted small"><?= e($h['ouvert_par'] ?? '—') ?></td>
<td class="text-muted small"><?= e($h['ferme_par'] ?? '—') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>
</div>

<!-- Modal Ouverture -->
<div class="modal fade modal-chic" id="openModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<form method="post">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
<div class="modal-header">
<h5 class="modal-title"><i class="bi bi-unlock-fill"></i><span>Ouvrir une journée</span></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="detail-section-chic">
<div class="detail-section-title-chic info"><i class="bi bi-info-circle-fill"></i> PARAMÈTRES D'OUVERTURE</div>
<div class="row g-3">
<div class="col-12">
<label class="form-label-chic">Caisse *</label>
<select class="form-select form-control-chic selectpicker" name="caisse_id" id="caisse_id" data-live-search="true" required>
<option value="">— Sélectionner une caisse —</option>
<?php foreach ($caissesDisponibles as $c): ?>
<option value="<?= e($c['caisse_id']) ?>" data-solde="<?= $c['solde'] ?>">
<?= e($c['nom_caisse']) ?> <?php if (!empty($c['nom_boutique'])): ?>(<?= e($c['nom_boutique']) ?>)<?php endif; ?> — Solde: <?= fmtDec($c['solde']) ?> F
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-12">
<label class="form-label-chic">Fonds de départ (F)</label>
<input type="number" step="0.01" class="form-control form-control-chic" name="solde_ouverture" id="solde_ouverture" value="0">
<small class="text-muted d-block mt-1" style="font-size:10px;">Montant en caisse au début de la journée</small>
</div>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn-chic btn-chic-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i><span>Annuler</span></button>
<button type="submit" name="btn_ouvrir" class="btn-chic btn-chic-primary"><i class="bi bi-unlock-fill"></i><span>Ouvrir la journée</span></button>
</div>
</form>
</div>
</div>
</div>

<!-- Modal Fermeture -->
<div class="modal fade modal-chic" id="closeModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<form method="post">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
<input type="hidden" name="journee_id" id="closeJourneeId">
<div class="modal-header">
<h5 class="modal-title"><i class="bi bi-lock-fill"></i><span>Fermer la journée</span></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="detail-section-chic">
<div class="detail-section-title-chic info"><i class="bi bi-cash-register"></i> CAISSE</div>
<div class="row g-3">
<div class="col-12">
<div class="form-label-chic">CAISSE</div>
<div class="fw-bold" style="font-size:15px;" id="closeCaisseNom">—</div>
</div>
</div>
</div>

<div class="detail-section-chic">
<div class="detail-section-title-chic money"><i class="bi bi-calculator"></i> RÉCAPITULATIF</div>
<div class="row g-3">
<div class="col-md-6">
<div class="form-label-chic">SOLDE THÉORIQUE CALCULÉ</div>
<div class="fw-bold" style="font-size:18px;color:var(--color-primary);font-family:'Outfit',sans-serif;" id="closeTheorique">— F</div>
</div>
<div class="col-md-6">
<div class="form-label-chic">ÉCART PRÉVU</div>
<div class="fw-bold" style="font-size:18px;font-family:'Outfit',sans-serif;" id="closeEcart">— F</div>
</div>
</div>
</div>

<div class="detail-section-chic">
<div class="detail-section-title-chic warn"><i class="bi bi-pencil-square"></i> CLÔTURE MANUELLE</div>
<div class="row g-3">
<div class="col-12">
<label class="form-label-chic">Solde physique compté (F) *</label>
<input type="number" step="0.01" class="form-control form-control-chic" name="solde_physique" id="solde_physique" required>
</div>
<div class="col-12">
<label class="form-label-chic">Observations</label>
<textarea class="form-control form-control-chic" name="observations" rows="3" placeholder="Notes éventuelles..."></textarea>
</div>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn-chic btn-chic-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i><span>Annuler</span></button>
<button type="submit" name="btn_fermer" class="btn-chic btn-chic-warning"><i class="bi bi-lock-fill"></i><span>Confirmer la fermeture</span></button>
</div>
</form>
</div>
</div>
</div>

<!-- Toast -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:2000;">
<div id="toastMsg" class="toast align-items-center text-white border-0" role="alert">
<div class="d-flex">
<div class="toast-body fw-semibold" id="toastBody"></div>
<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
</div>
</div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
$(function(){
$('.selectpicker').selectpicker();

const openModal = new bootstrap.Modal(document.getElementById('openModal'));
const closeModal = new bootstrap.Modal(document.getElementById('closeModal'));

// Auto-fill du fonds de départ avec le solde actuel de la caisse
$('#caisse_id').on('changed.bs.select', function(){
const opt = $(this).find('option:selected');
const solde = opt.data('solde');
if (solde !== undefined) {
$('#solde_ouverture').val(solde);
}
});

$('#openBtn').on('click', function(){
$('#caisse_id').selectpicker('val', '');
$('#solde_ouverture').val('0');
openModal.show();
});

// Fermeture d'une journée
$(document).on('click', '.fermer-journee', function(){
const id = $(this).data('id');
const caisse = $(this).data('caisse');
const theo = parseFloat($(this).data('theorique')) || 0;

$('#closeJourneeId').val(id);
$('#closeCaisseNom').text(caisse);
$('#closeTheorique').text(theo.toLocaleString('fr-FR') + ' F');
$('#solde_physique').val(theo);
$('#closeEcart').text('0 F').css('color', 'var(--text-tertiary)');

closeModal.show();
});

// Calcul de l'écart en temps réel
$('#solde_physique').on('input', function(){
const phys = parseFloat($(this).val()) || 0;
const theo = parseFloat($('#closeTheorique').text().replace(/\s/g, '').replace('F','').replace(/,/g,'')) || 0;
const ecart = phys - theo;
const $ecart = $('#closeEcart');
$ecart.text(ecart.toLocaleString('fr-FR') + ' F');
if (ecart > 0) $ecart.css('color', 'var(--color-success)');
else if (ecart < 0) $ecart.css('color', 'var(--color-danger)');
else $ecart.css('color', 'var(--text-tertiary)');
});

setTimeout(()=>$('.alert').alert('close'), 5000);
});
</script>
</body>
</html>