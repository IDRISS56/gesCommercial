<?php
// views/commande/devis.php – Gestion des devis clients
// Un devis ne touche JAMAIS au stock. Le stock n'est vérifié (et réservé)
// qu'au moment où on transforme le devis en Bon de commande.

require 'databases/database.php';

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$isAjax = $isAjax || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

// ==========================================
// SÉCURITÉ : utilisateur connecté & actif
// ==========================================
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session expirée.']);
        exit;
    }

    header('Location: utilisateur/login');
    exit;
}

$stmtUser = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmtUser->execute([$_SESSION['user_id']]);
$userInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$userInfo) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Utilisateur invalide.']);
        exit;
    }

    session_destroy();
    header('Location: utilisateur/login');
    exit;
}

define('USER_ID', $_SESSION['user_id']);
define('USER_BOUTIQUE', $userInfo['boutique_id'] ?? null);

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function fmt($n) { return number_format((float)$n, 0, ',', ' '); }

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ==========================================
// ACTIONS AJAX
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];
    $csrf = $_POST['csrf_token'] ?? '';

    if (empty($csrf) || $csrf !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
        exit;
    }

    // Vente à perte : le prix de vente d'une ligne ne doit jamais être
    // inférieur au prix d'achat du produit. Lève une Exception (message prêt
    // à afficher) si c'est le cas ; ne fait rien si le prix est correct ou
    // égal (marge nulle tolérée, seulement signalée côté client).
    if (!function_exists('verifierPrixVenteLigneDevis')) {
        function verifierPrixVenteLigneDevis(PDO $pdo, string $codeProduit, float $prixSaisi): void {
            $stmtPa = $pdo->prepare("SELECT prix_fournisseur, titre_produit FROM produit WHERE code_produit = ?");
            $stmtPa->execute([$codeProduit]);
            $refProd = $stmtPa->fetch(PDO::FETCH_ASSOC);
            $prixAchatRef = (float) ($refProd['prix_fournisseur'] ?? 0);
            if ($prixAchatRef > 0 && $prixSaisi < $prixAchatRef) {
                $nomProd = $refProd['titre_produit'] ?? $codeProduit;
                throw new Exception("Prix de vente (" . number_format($prixSaisi, 0, ',', ' ') . " F) inférieur au prix d'achat (" . number_format($prixAchatRef, 0, ',', ' ') . " F) pour « $nomProd » — devis refusé.");
            }
        }
    }

    // ---- PRODUITS D'UNE CATÉGORIE (chargement à la demande, remplace le
    // préchargement complet du catalogue produit dans la page — voir la
    // même correction sur commande/vente.php et commande/suivi_achat.php) ----
    if ($action === 'produits_par_categorie') {
        $categorieId = trim($_POST['categorie_id'] ?? '');
        if (empty($categorieId)) {
            echo json_encode(['success' => false, 'message' => 'Catégorie manquante', 'produits' => []]);
            exit;
        }
        $stmtProd = $pdo->prepare(
            "SELECT code_produit, titre_produit, prix_produit, prix_fournisseur
             FROM produit
             WHERE categorie_id = ?
             ORDER BY titre_produit ASC"
        );
        $stmtProd->execute([$categorieId]);
        echo json_encode(['success' => true, 'produits' => $stmtProd->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ---- CRÉATION D'UN DEVIS ----
    if ($action === 'creer_devis') {
        try {
            $client_id = trim($_POST['client_id'] ?? '');
            if (empty($client_id)) throw new Exception('Veuillez sélectionner un client.');

            $lignes = json_decode($_POST['lignes'] ?? '[]', true) ?: [];
            if (empty($lignes)) throw new Exception('Le devis est vide.');

            $tax_rate = floatval($_POST['taux_taxe'] ?? 0);
            $discount_rate = floatval($_POST['taux_remise'] ?? 0);

            $montantHT = 0;
            $lignesValides = [];

            foreach ($lignes as $l) {
                $code_prod = $l['code'] ?? '';
                $qte = max(1, intval($l['qte'] ?? 1));
                $prix = floatval($l['prix'] ?? 0);
                $produits_par_lot = max(1, intval($l['produits_par_lot'] ?? 1));

                if (!$code_prod || $prix < 0) continue;

                // Vente à perte : vérification autoritaire (le client peut être
                // contourné) — voir verifierPrixVenteLigneDevis() plus haut.
                verifierPrixVenteLigneDevis($pdo, $code_prod, $prix);

                $montantLigne = round($qte * $prix, 2);
                $montantHT += $montantLigne;

                $lignesValides[] = [
                    'code' => $code_prod,
                    'qte' => $qte,
                    'prix' => $prix,
                    'montant' => $montantLigne,
                    'produits_par_lot' => $produits_par_lot
                ];
            }

            if (empty($lignesValides)) throw new Exception('Aucune ligne valide dans le devis.');

            $taxe = round($montantHT * $tax_rate / 100, 2);
            $remise = round($montantHT * $discount_rate / 100, 2);
            $montantTTC = round($montantHT + $taxe - $remise, 2);

            $pdo->beginTransaction();

            $numDevis = 'DEV-' . date('Ymd') . '-' . str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO facture(numero_facture, titre_facture, type_facture, categorie_facture, date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, contact_id, utilisateur_id, etat_facture, statut_facture, reference_id)
                           VALUES (?, ?, 'Client', 'Devis', CURDATE(), ?, ?, ?, ?, 0, ?, ?, ?, 'Impayee', 'Validee', NULL)")
                ->execute([$numDevis, 'Devis ' . $numDevis, $montantHT, $taxe, $remise, $montantTTC, $montantTTC, $client_id, USER_ID]);

            $numBase = date('dmYHis');

            foreach ($lignesValides as $i => $l) {
                $numCmd = 'DL-' . $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT);

                $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                               VALUES (?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, 'VALIDEE')")
                    ->execute([$numCmd, $l['code'], $client_id, $numDevis, $l['prix'], $l['prix'], $l['qte'], $l['produits_par_lot'], $l['montant'], USER_ID, USER_BOUTIQUE]);
            }

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Devis ' . $numDevis . ' créé.', 'numero' => $numDevis]);
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        }

        exit;
    }

    // ---- MODIFICATION D'UN DEVIS EXISTANT ----
    if ($action === 'modifier_devis') {
        try {
            $numDevis = trim($_POST['numero'] ?? '');
            if (empty($numDevis)) throw new Exception('Devis introuvable.');

            $client_id = trim($_POST['client_id'] ?? '');
            if (empty($client_id)) throw new Exception('Veuillez sélectionner un client.');

            $lignes = json_decode($_POST['lignes'] ?? '[]', true) ?: [];
            if (empty($lignes)) throw new Exception('Le devis est vide.');

            $tax_rate = floatval($_POST['taux_taxe'] ?? 0);
            $discount_rate = floatval($_POST['taux_remise'] ?? 0);

            $montantHT = 0;
            $lignesValides = [];

            foreach ($lignes as $l) {
                $code_prod = $l['code'] ?? '';
                $qte = max(1, intval($l['qte'] ?? 1));
                $prix = floatval($l['prix'] ?? 0);
                $produits_par_lot = max(1, intval($l['produits_par_lot'] ?? 1));

                if (!$code_prod || $prix < 0) continue;

                // Vente à perte : vérification autoritaire (le client peut être
                // contourné) — voir verifierPrixVenteLigneDevis() plus haut.
                verifierPrixVenteLigneDevis($pdo, $code_prod, $prix);

                $montantLigne = round($qte * $prix, 2);
                $montantHT += $montantLigne;

                $lignesValides[] = [
                    'code' => $code_prod,
                    'qte' => $qte,
                    'prix' => $prix,
                    'montant' => $montantLigne,
                    'produits_par_lot' => $produits_par_lot
                ];
            }

            if (empty($lignesValides)) throw new Exception('Aucune ligne valide dans le devis.');

            $taxe = round($montantHT * $tax_rate / 100, 2);
            $remise = round($montantHT * $discount_rate / 100, 2);
            $montantTTC = round($montantHT + $taxe - $remise, 2);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT numero_facture FROM facture WHERE numero_facture = ? AND categorie_facture = 'Devis' FOR UPDATE");
            $stmt->execute([$numDevis]);
            if (!$stmt->fetchColumn()) throw new Exception('Devis introuvable.');

            $stmtCheck = $pdo->prepare("SELECT numero_facture FROM facture WHERE reference_id = ? LIMIT 1");
            $stmtCheck->execute([$numDevis]);
            if ($stmtCheck->fetchColumn()) throw new Exception('Ce devis a déjà été transformé et ne peut plus être modifié.');

            $pdo->prepare("UPDATE facture SET montant_ht = ?, taxe = ?, remise = ?, montant_ttc = ?, reste = ?, contact_id = ?
                           WHERE numero_facture = ? AND categorie_facture = 'Devis'")
                ->execute([$montantHT, $taxe, $remise, $montantTTC, $montantTTC, $client_id, $numDevis]);

            $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$numDevis]);

            $numBase = date('dmYHis');

            foreach ($lignesValides as $i => $l) {
                $numCmd = 'DL-' . $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT);

                $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                               VALUES (?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, 'VALIDEE')")
                    ->execute([$numCmd, $l['code'], $client_id, $numDevis, $l['prix'], $l['prix'], $l['qte'], $l['produits_par_lot'], $l['montant'], USER_ID, USER_BOUTIQUE]);
            }

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Devis ' . $numDevis . ' mis à jour.']);
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        }

        exit;
    }

    // ---- TRANSFORMATION DEVIS -> BON DE COMMANDE (stock vérifié ICI) ----
    if ($action === 'transformer_devis') {
        try {
            $numDevis = trim($_POST['numero'] ?? '');
            $boutique_id = trim($_POST['boutique_id'] ?? '') ?: USER_BOUTIQUE;

            if (empty($numDevis)) throw new Exception('Devis introuvable.');
            if (empty($boutique_id)) throw new Exception('Veuillez choisir une boutique pour vérifier le stock.');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? AND categorie_facture = 'Devis' FOR UPDATE");
            $stmt->execute([$numDevis]);
            $devis = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$devis) throw new Exception('Devis introuvable.');
            // (Un devis déjà transformé est supprimé immédiatement, donc il ne peut
            // plus jamais être retrouvé/retransformé : le SELECT ci-dessus suffit.)

            $stmt = $pdo->prepare("SELECT c.*, p.titre_produit FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit WHERE c.facture_id = ?");
            $stmt->execute([$numDevis]);
            $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($lignes)) throw new Exception('Ce devis ne contient aucune ligne.');

            // ---- VÉRIFICATION DU STOCK ----
            foreach ($lignes as $l) {
                $stmtStock = $pdo->prepare("SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE");
                $stmtStock->execute([$l['produit_id'], $boutique_id]);

                $dispo = $stmtStock->fetchColumn();
                $dispo = ($dispo === false) ? 0 : (int)$dispo;

                if ($dispo < (int)$l['quantite_commande']) {
                    throw new Exception('Stock insuffisant pour « ' . $l['titre_produit'] . ' » : disponible ' . $dispo . ', demandé ' . $l['quantite_commande'] . '.');
                }
            }

            // ---- CRÉATION DU BON DE COMMANDE ----
            // Note : le bon de livraison n'est PLUS créé ici. Il n'est généré qu'au
            // moment de la validation du bon de commande (views/commande/vente.php,
            // action validate_facture), qui est aussi le moment où la facture devient
            // encaissable.
            $numBon = 'BON-' . date('Ymd') . '-' . str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO facture(numero_facture, titre_facture, type_facture, categorie_facture, date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, contact_id, utilisateur_id, etat_facture, statut_facture, reference_id)
                           VALUES (?, ?, 'Client', 'Bon', CURDATE(), ?, ?, ?, ?, 0, ?, ?, ?, 'Impayee', 'En attente', ?)")
                ->execute([$numBon, 'Bon de commande ' . $numBon, $devis['montant_ht'], $devis['taxe'], $devis['remise'], $devis['montant_ttc'], $devis['montant_ttc'], $devis['contact_id'], USER_ID, $numDevis]);

            $numBase = date('dmYHis');

            foreach ($lignes as $i => $l) {
                $produitsParLot = max(1, intval($l['produits_par_lot'] ?? 1));
                $numCmd = $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT);

                // Ligne bon de commande
                $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                               VALUES (?, ?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, 'VALIDEE')")
                    ->execute([$numCmd . '-DOC', $l['produit_id'], $l['lot_id'] ?? null, $devis['contact_id'], $numBon, $l['prix_commande'], $l['prix_commande'], $l['quantite_commande'], $produitsParLot, $l['montant_commande'], USER_ID, $boutique_id]);

                // Réservation du stock
                $pdo->prepare("UPDATE stock SET quantite = GREATEST(0, quantite - ?) WHERE produit_id = ? AND boutique_id = ?")
                    ->execute([$l['quantite_commande'], $l['produit_id'], $boutique_id]);

                $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) - ? AS CHAR) WHERE code_produit = ?")
                    ->execute([$l['quantite_commande'], $l['produit_id']]);

                $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                    ->execute([$l['produit_id']]);
            }

            // ---- SUPPRESSION DÉFINITIVE DU DEVIS ----
            // Dès qu'un devis est transformé en bon de commande, il est supprimé
            // immédiatement (DELETE réel, aucune trace conservée). Le bon de commande
            // garde uniquement le numéro du devis d'origine dans reference_id.
            $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$numDevis]);
            $pdo->prepare("DELETE FROM facture WHERE numero_facture = ? AND categorie_facture = 'Devis'")->execute([$numDevis]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Devis transformé en bon de commande ' . $numBon . '. Le bon de livraison sera généré à la validation du bon de commande.',
                'numero' => $numBon
            ]);
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        }

        exit;
    }

    // ---- SUPPRESSION D'UN DEVIS ----
    if ($action === 'supprimer_devis') {
        try {
            $numDevis = trim($_POST['numero'] ?? '');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT numero_facture FROM facture WHERE reference_id = ? LIMIT 1");
            $stmt->execute([$numDevis]);
            if ($stmt->fetchColumn()) throw new Exception('Impossible de supprimer : ce devis a déjà été transformé.');

            $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$numDevis]);
            $pdo->prepare("DELETE FROM facture WHERE numero_facture = ? AND categorie_facture = 'Devis'")->execute([$numDevis]);

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Devis supprimé.']);
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        }

        exit;
    }

    // ---- DÉTAILS D'UN DEVIS ----
    if ($action === 'get_details') {
        $numDevis = trim($_POST['numero'] ?? '');

        $stmt = $pdo->prepare("SELECT c.*, p.titre_produit FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit WHERE c.facture_id = ?");
        $stmt->execute([$numDevis]);

        echo json_encode(['success' => true, 'lignes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ---- DÉTAILS D'UN DEVIS POUR ÉDITION (avec catégorie de chaque produit) ----
    if ($action === 'get_devis_edit') {
        try {
            $numDevis = trim($_POST['numero'] ?? '');
            if (empty($numDevis)) throw new Exception('Devis introuvable.');

            $stmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? AND categorie_facture = 'Devis'");
            $stmt->execute([$numDevis]);
            $devis = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$devis) throw new Exception('Devis introuvable.');

            $stmtCheck = $pdo->prepare("SELECT numero_facture FROM facture WHERE reference_id = ? LIMIT 1");
            $stmtCheck->execute([$numDevis]);
            if ($stmtCheck->fetchColumn()) throw new Exception('Ce devis a déjà été transformé et ne peut plus être modifié.');

            $stmtL = $pdo->prepare("SELECT c.*, p.titre_produit, p.categorie_id FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit WHERE c.facture_id = ?");
            $stmtL->execute([$numDevis]);
            $lignes = $stmtL->fetchAll(PDO::FETCH_ASSOC);

            $montantHT = (float)$devis['montant_ht'];
            $tauxTaxeCalc = $montantHT > 0 ? round(((float)$devis['taxe']) / $montantHT * 100, 2) : 0;
            $tauxRemiseCalc = $montantHT > 0 ? round(((float)$devis['remise']) / $montantHT * 100, 2) : 0;

            echo json_encode([
                'success' => true,
                'devis' => [
                    'contact_id' => $devis['contact_id'],
                    'taux_taxe' => $tauxTaxeCalc,
                    'taux_remise' => $tauxRemiseCalc,
                ],
                'lignes' => $lignes
            ]);
        } catch (Exception $ex) {
            echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        }

        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action inconnue.']);
    exit;
}

// ==========================================
// DONNÉES POUR LA PAGE
// ==========================================
$clients = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Client' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact ASC")->fetchAll(PDO::FETCH_ASSOC);
// Le catalogue produit n'est plus préchargé en entier ici (voir la même
// correction sur commande/vente.php) : chaque ligne du devis récupère ses
// produits à la demande, par catégorie, via l'action AJAX 'produits_par_categorie'.
$categories = $pdo->query("SELECT code_categorie, titre_categorie FROM categorie WHERE etat_categorie = 'ACTIF' ORDER BY titre_categorie ASC")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique ASC")->fetchAll(PDO::FETCH_ASSOC);
$taxes = $pdo->query("SELECT * FROM taxe WHERE etat_taxe = 'ACTIF' ORDER BY type_taxe, taux_taxe")->fetchAll(PDO::FETCH_ASSOC);

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$totalDevis = (int)$pdo->query("SELECT COUNT(*) FROM facture WHERE categorie_facture = 'Devis'")->fetchColumn();
$totalTransformes = (int)$pdo->query("SELECT COUNT(*) FROM facture f WHERE f.categorie_facture = 'Devis' AND EXISTS (SELECT 1 FROM facture f2 WHERE f2.reference_id = f.numero_facture)")->fetchColumn();
$totalEnAttente = max(0, $totalDevis - $totalTransformes);

$stmtList = $pdo->prepare("SELECT f.*, c.nom_prenom_contact,
    (SELECT numero_facture FROM facture f2 WHERE f2.reference_id = f.numero_facture LIMIT 1) AS bon_issu
    FROM facture f
    LEFT JOIN contact c ON f.contact_id = c.code_contact
    WHERE f.categorie_facture = 'Devis'
    ORDER BY f.date_facture DESC, f.numero_facture DESC
    LIMIT :limit OFFSET :offset");
$stmtList->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmtList->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtList->execute();

$devisListe = $stmtList->fetchAll(PDO::FETCH_ASSOC);
$totalPages = max(1, ceil($totalDevis / $perPage));
$baseUrl = '?c=commande&a=devis';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<title>Devis</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
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
    --color-gray-100: #f1f5f9;
    --color-gray-200: #e2e8f0;
    --color-gray-300: #cbd5e1;
    --bg-body: #f1f5f9;
    --bg-surface: #ffffff;
    --border-color: #e2e8f0;
    --text-primary: #0f172a;
    --text-secondary: #334155;
    --text-tertiary: #64748b;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
    --shadow-md: 0 4px 12px rgba(0,0,0,0.06);
    --radius-sm: 10px;
    --transition-base: 250ms cubic-bezier(0.4,0,0.2,1);
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

h1,h2,h3,h4,h5,h6 { font-family: 'Outfit', sans-serif; font-weight: 700; letter-spacing: -0.02em; }

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
::-webkit-scrollbar-track { background: transparent; }

.W { max-width: 1400px; margin: 0 auto; }

.stat-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base); }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

.data-table-wrap { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-sm); overflow: hidden; box-shadow: var(--shadow-sm); animation: fadeUp .4s ease both; }
.table { margin: 0; }
.table thead th { background: var(--color-gray-100); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; padding: 12px 14px; border-bottom: 2px solid var(--border-color); white-space: nowrap; }
.table tbody tr { border-bottom: 1px solid var(--border-color); transition: background .2s; }
.table tbody tr:hover { background: var(--color-primary-soft); }
.table tbody td { padding: 12px 14px; vertical-align: middle; color: var(--text-primary); font-size: 13px; }
.td-bold { color: var(--text-primary) !important; font-weight: 700; }

.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.status-badge .sdot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: pulse 2s infinite; }
.status-badge.devis { background: var(--color-warning-soft); color: #92400e; }
.status-badge.transforme { background: var(--color-success-soft); color: #065f46; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }

.act-btn { width: 32px; height: 32px; border-radius: 6px; border: 1.5px solid transparent; background: transparent; display: inline-flex; align-items: center; justify-content: center; transition: all .2s; font-size: 14px; cursor: pointer; padding: 0; }
.act-btn:hover { transform: scale(1.1); }
.act-btn.v { color: var(--color-info); border-color: rgba(8,145,178,0.2); }
.act-btn.v:hover { color: #0e7490; background: var(--color-info-soft); border-color: var(--color-info); }
.act-btn.t { color: var(--color-success); border-color: rgba(16,185,129,0.2); }
.act-btn.t:hover { color: #047857; background: var(--color-success-soft); border-color: var(--color-success); }
.act-btn.d { color: var(--color-danger); border-color: rgba(239,68,68,0.2); }
.act-btn.d:hover { color: #b91c1c; background: var(--color-danger-soft); border-color: var(--color-danger); }

.btn-chic { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s cubic-bezier(0.4,0,0.2,1); position: relative; overflow: hidden; letter-spacing: -0.01em; text-decoration: none; }
.btn-chic::before { content: ''; position: absolute; top: 50%; left: 50%; width: 0; height: 0; background: rgba(255,255,255,0.3); border-radius: 50%; transform: translate(-50%,-50%); transition: width .4s, height .4s; }
.btn-chic:hover::before { width: 300px; height: 300px; }
.btn-chic i { font-size: 15px; position: relative; z-index: 1; }
.btn-chic span { position: relative; z-index: 1; }
.btn-chic-primary { background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); color: #fff; box-shadow: 0 4px 12px rgba(79,70,229,0.3); }
.btn-chic-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79,70,229,0.4); color: #fff; }
.btn-chic-secondary { background: var(--color-gray-100); color: var(--text-secondary); border: 1px solid var(--border-color); }
.btn-chic-secondary:hover { background: var(--color-gray-200); color: var(--text-primary); }
.btn-chic-success { background: linear-gradient(135deg, var(--color-success) 0%, #059669 100%); color: #fff; box-shadow: 0 4px 12px rgba(16,185,129,0.3); }
.btn-chic-success:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(16,185,129,0.4); color: #fff; }

.btn-go-outline { background: transparent; color: var(--text-tertiary); border: 1.5px solid var(--border-color); padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; transition: all .2s; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
.btn-go-outline:hover { background: var(--color-gray-100); border-color: var(--color-gray-300); color: var(--text-primary); }

.form-label { font-size: 10px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.form-control, .form-select { border-radius: 10px; border: 1.5px solid var(--border-color); padding: 10px 14px; font-size: 13px; transition: all .2s; }
.form-control:focus, .form-select:focus { border-color: var(--color-primary); box-shadow: 0 0 0 3px var(--color-primary-soft); }

.section-title { font-size: 11px; letter-spacing: 0.8px; text-transform: uppercase; font-weight: 700; display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.section-title.primary { color: var(--color-primary); }
.section-title.info { color: var(--color-info); }

/* Modales chic (design index) */
.modal-chic .modal-content { border: none !important; border-radius: 20px !important; box-shadow: 0 25px 60px rgba(15,23,42,0.15) !important; overflow: hidden !important; animation: modalSlideIn .4s cubic-bezier(0.16,1,0.3,1); display: flex !important; flex-direction: column !important; max-height: 90vh !important; }
@keyframes modalSlideIn { from { opacity: 0; transform: translateY(30px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
.modal-chic .modal-header { background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%); color: #fff; border: none; padding: 22px 28px; position: relative; overflow: hidden; flex-shrink: 0 !important; }
.modal-chic .modal-header::before { content: ''; position: absolute; top: -50%; right: -20%; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%; }
.modal-chic .modal-title { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
.modal-chic .modal-title i { font-size: 22px; background: rgba(255,255,255,0.15); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.modal-chic .btn-close { filter: invert(1); opacity: 0.7; position: relative; z-index: 1; }
.modal-chic .btn-close:hover { opacity: 1; transform: rotate(90deg); }
.modal-chic .modal-body { padding: 28px !important; overflow-y: auto !important; background: #f8fafc !important; flex: 1 1 auto !important; min-height: 0 !important; }
.modal-chic .modal-footer { background: #ffffff !important; border-top: 2px solid var(--border-color) !important; padding: 18px 28px !important; display: flex !important; gap: 10px !important; justify-content: flex-end !important; flex-wrap: wrap !important; flex-shrink: 0 !important; }

/* Lignes du devis */
.ligne-header { display: grid; grid-template-columns: 120px minmax(160px,1.4fr) 60px 72px 88px 92px 32px; gap: 6px; padding: 0 10px 6px; }
.ligne-header span { font-size: 10px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
.ligne-devis { display: grid; grid-template-columns: 120px minmax(160px,1.4fr) 60px 72px 88px 92px 32px; gap: 6px; align-items: center; background: #fff; border: 1px solid var(--border-color); border-radius: 12px; padding: 8px; margin-bottom: 8px; max-width: 100%; }
.ligne-devis.line-error { border-color: var(--color-danger) !important; background: #fff5f5; }
.ligne-devis-hint { grid-column: 1 / -1; font-size: 11px; color: var(--text-tertiary); margin-top: 6px; }
.ligne-prix-alerte { grid-column: 1 / -1; font-size: 11px; margin-top: 2px; }

.totals-card { width: 320px; max-width: 100%; background: #fff; border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; }

.pagination .page-link { border-radius: 8px; color: var(--color-primary); }
.pagination .page-item.active .page-link { background: var(--color-primary); border-color: var(--color-primary); }

/* Bootstrap Select (largeur = largeur du champ) */
.bootstrap-select { width: 100% !important; }
.bootstrap-select > .dropdown-toggle { width: 100%; background: #fff; border: 1.5px solid var(--border-color); border-radius: 10px; padding: 10px 14px; font-size: 13px; }
.bootstrap-select > .dropdown-toggle:focus,
.bootstrap-select.show > .dropdown-toggle { border-color: var(--color-primary); box-shadow: 0 0 0 3px var(--color-primary-soft); }
.ligne-devis .bootstrap-select > .dropdown-toggle { padding: 7px 8px; font-size: 12px; }
.bootstrap-select .dropdown-menu { border-radius: 12px; }

@keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

@media (max-width: 900px) {
    .ligne-header { display: none; }
    .ligne-devis { grid-template-columns: 1fr; }
    .ligne-devis .ligne-montant { text-align: left !important; font-weight: 700; }
}
</style>
</head>
<body>
<div class="W">

    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-file-earmark-text text-primary me-2"></i>Devis</h1>
            <p class="text-muted small mb-0">Créez des devis clients puis transformez-les en bons de commande.</p>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                <i class="bi bi-person"></i> <?= e($userInfo['nom_prenom'] ?? '') ?>
            </span>
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                <i class="bi bi-file-earmark-text"></i> <?= $totalDevis ?> devis
            </span>
            <button type="button" class="btn-chic btn-chic-primary" onclick="ouvrirNouveauDevis()">
                <i class="bi bi-plus-circle"></i>
                <span>Nouveau devis</span>
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['primary', 'file-earmark-text', 'Total devis', $totalDevis],
            ['warning', 'hourglass-split', 'En attente', $totalEnAttente],
            ['success', 'arrow-right-circle', 'Transformés', $totalTransformes],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
        ];
        foreach ($stats as $s): $bg = $colorMap[$s[0]][0]; $fg = $colorMap[$s[0]][1]; ?>
        <div class="col-12 col-md-4 col-xl-4">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background: <?= $bg ?>; color: <?= $fg ?>;">
                    <i class="bi bi-<?= $s[1] ?>"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-label"><?= $s[2] ?></div>
                    <div class="stat-value text-truncate"><?= $s[3] ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold">Liste des devis</h5>
            <span class="text-muted small"><?= $totalDevis ?> devis - Page <?= $page ?> / <?= max(1, $totalPages) ?></span>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>N° Devis</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th class="text-end">Montant TTC</th>
                        <th class="text-center">Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="tbodyDevis">
                    <?php if (empty($devisListe)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                Aucun devis pour l'instant.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($devisListe as $d): ?>
                        <tr>
                            <td class="td-bold"><?= e($d['numero_facture']) ?></td>
                            <td><?= e($d['nom_prenom_contact'] ?? '—') ?></td>
                            <td><?= date('d/m/Y', strtotime($d['date_facture'])) ?></td>
                            <td class="text-end fw-bold"><?= fmt($d['montant_ttc']) ?> F</td>
                            <td class="text-center">
                                <?php if ($d['bon_issu']): ?>
                                    <span class="status-badge transforme"><span class="sdot"></span>Transformé</span>
                                    <div class="small text-muted mt-1">→ <?= e($d['bon_issu']) ?></div>
                                <?php else: ?>
                                    <span class="status-badge devis"><span class="sdot"></span>Devis</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="act-btn v" onclick="voirDetails('<?= e($d['numero_facture']) ?>')" title="Voir">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?php if (!$d['bon_issu']): ?>
                                    <button type="button" class="act-btn" onclick="ouvrirModificationDevis('<?= e($d['numero_facture']) ?>')" title="Modifier">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="act-btn t" onclick="ouvrirTransformation('<?= e($d['numero_facture']) ?>')" title="Transformer en bon de commande">
                                        <i class="bi bi-arrow-right-circle"></i>
                                    </button>
                                    <button type="button" class="act-btn d" onclick="supprimerDevis('<?= e($d['numero_facture']) ?>')" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
            <span class="text-muted small">
                Affichage de <?= (($page - 1) * $perPage + 1) ?> à <?= min($page * $perPage, $totalDevis) ?> sur <?= $totalDevis ?>
            </span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page <= 1): ?>
                        <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-left"></i></span></li>
                    <?php else: ?>
                        <li class="page-item"><a class="page-link" href="<?= e($baseUrl . '&page=' . ($page - 1)) ?>"><i class="bi bi-chevron-left"></i></a></li>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);

                    if ($start > 1) {
                        echo '<li class="page-item"><a class="page-link" href="' . e($baseUrl . '&page=1') . '">1</a></li>';
                        if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }

                    for ($i = $start; $i <= $end; $i++) {
                        $active = ($i == $page) ? 'active' : '';
                        echo '<li class="page-item ' . $active . '"><a class="page-link" href="' . e($baseUrl . '&page=' . $i) . '">' . $i . '</a></li>';
                    }

                    if ($end < $totalPages) {
                        if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="' . e($baseUrl . '&page=' . $totalPages) . '">' . $totalPages . '</a></li>';
                    }
                    ?>

                    <?php if ($page >= $totalPages): ?>
                        <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-right"></i></span></li>
                    <?php else: ?>
                        <li class="page-item"><a class="page-link" href="<?= e($baseUrl . '&page=' . ($page + 1)) ?>"><i class="bi bi-chevron-right"></i></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL NOUVEAU DEVIS -->
<div class="modal fade modal-chic" id="devisModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-plus"></i><span id="devisModalTitle">Nouveau devis</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body">
                <h6 class="section-title primary"><i class="bi bi-person-fill"></i> Client et conditions</h6>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label" for="clientSelect">Client</label>
                        <select
                            id="clientSelect"
                            class="form-select selectpicker"
                            data-live-search="true"
                            data-width="100%"
                            data-size="8"
                            data-live-search-placeholder="Rechercher un client"
                            title="-- Sélectionner un client --"
                        >
                            <option value="">-- Sélectionner un client --</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= e($c['code_contact']) ?>"><?= e($c['nom_prenom_contact']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="tauxTaxe">Taxe (%)</label>
                        <select id="tauxTaxe" class="form-select">
                            <option value="0">Aucune</option>
                            <?php foreach ($taxes as $t): ?>
                                <?php if (($t['type_taxe'] ?? '') === 'TAXE'): ?>
                                    <option value="<?= floatval($t['taux_taxe']) ?>"><?= floatval($t['taux_taxe']) ?>%</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="tauxRemise">Remise (%)</label>
                        <select id="tauxRemise" class="form-select">
                            <option value="0">Aucune</option>
                            <?php foreach ($taxes as $t): ?>
                                <?php if (($t['type_taxe'] ?? '') === 'REMISE'): ?>
                                    <option value="<?= floatval($t['taux_taxe']) ?>"><?= floatval($t['taux_taxe']) ?>%</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h6 class="section-title info"><i class="bi bi-cart-fill"></i> Lignes du devis</h6>

                <div class="ligne-header">
                    <span>Catégorie</span>
                    <span>Produit</span>
                    <span>Qté</span>
                    <span>Par lot</span>
                    <span>P.U.</span>
                    <span class="text-end">Montant</span>
                    <span></span>
                </div>

                <div id="lignesContainer"></div>

                <button type="button" class="btn-go-outline mt-2" onclick="ajouterLigne()">
                    <i class="bi bi-plus"></i> Ajouter une ligne
                </button>

                <hr class="my-4">

                <div class="d-flex justify-content-end">
                    <div class="totals-card">
                        <div class="d-flex justify-content-between mb-1"><span>Montant HT</span><span id="totHT">0 F</span></div>
                        <div class="d-flex justify-content-between mb-1"><span>Taxe</span><span id="totTaxe">0 F</span></div>
                        <div class="d-flex justify-content-between mb-1"><span>Remise</span><span id="totRemise">0 F</span></div>
                        <div class="d-flex justify-content-between fw-bold fs-5 mt-2"><span>Total TTC</span><span id="totTTC">0 F</span></div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-chic btn-chic-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i><span>Annuler</span>
                </button>
                <button type="button" class="btn-chic btn-chic-primary" onclick="enregistrerDevis()">
                    <i class="bi bi-save"></i><span id="btnEnregistrerDevisTexte">Enregistrer le devis</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL TRANSFORMATION -->
<div class="modal fade modal-chic" id="transformModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-right-circle"></i><span>Transformer en bon de commande</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body">
                <p class="text-muted small">Le stock disponible sera vérifié et réservé dans la boutique choisie.</p>

                <input type="hidden" id="transformNumero">

                <label class="form-label" for="transformBoutique">Boutique</label>
                <select
                    id="transformBoutique"
                    class="form-select selectpicker"
                    data-live-search="true"
                    data-width="100%"
                    data-size="8"
                    data-live-search-placeholder="Rechercher une boutique"
                >
                    <?php foreach ($boutiques as $b): ?>
                        <option value="<?= e($b['code_boutique']) ?>" <?= ($b['code_boutique'] === USER_BOUTIQUE) ? 'selected' : '' ?>>
                            <?= e($b['nom_boutique']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-chic btn-chic-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i><span>Annuler</span>
                </button>
                <button type="button" class="btn-chic btn-chic-success" onclick="confirmerTransformation()">
                    <i class="bi bi-check2"></i><span>Transformer</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DÉTAILS -->
<div class="modal fade modal-chic" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye"></i><span>Détails du devis</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body" id="detailsBody"></div>

            <div class="modal-footer">
                <button type="button" class="btn-chic btn-chic-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i><span>Fermer</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL CONFIRMATION SUPPRESSION (comme index.php) -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Confirmer la suppression</h5>
                <p class="text-muted small mb-4">
                    Êtes-vous sûr de vouloir supprimer le devis
                    <strong id="deleteNumeroDevis" class="text-danger"></strong> ?<br>
                    Cette action est irréversible.
                </p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger rounded-3" id="confirmDeleteBtn">
                        <i class="bi bi-trash3 me-1"></i> Supprimer
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TOAST (comme index.php) -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:2000;">
    <div id="toastMsg" class="toast align-items-center text-white border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-semibold" id="toastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

<script>
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
const CATEGORIES = <?= json_encode($categories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]' ?>;
// Le catalogue produit n'est plus préchargé en entier (voir la même
// correction sur commande/vente.php) : chaque ligne récupère ses produits à
// la demande, par catégorie, via 'produits_par_categorie'. Petit cache par
// catégorie pour éviter de re-télécharger la même liste à chaque nouvelle
// ligne d'une même catégorie dans un même devis.
const _produitsParCategorieCache = {};
function chargerProduitsCategorie(catId, callback) {
    if (Object.prototype.hasOwnProperty.call(_produitsParCategorieCache, catId)) {
        callback(_produitsParCategorieCache[catId]);
        return;
    }
    $.post(window.location.href, { action: 'produits_par_categorie', categorie_id: catId, csrf_token: CSRF_TOKEN }, function(resp) {
        const produits = (resp && resp.success) ? (resp.produits || []) : [];
        _produitsParCategorieCache[catId] = produits;
        callback(produits);
    }, 'json');
}

let devisEnEdition = null; // null = création, sinon numéro du devis en cours de modification

let devisModal = null;
let transformModal = null;
let detailsModal = null;
let deleteModal = null;
let toast = null;
let toastEl = null;
let devisNumeroToDelete = null;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
    });
}

function fmtN(n) {
    return Math.round(Number(n || 0)).toLocaleString('fr-FR') + ' F';
}

// Toast identique à index.php
function showToast(msg, type = 'success') {
    const colors = { success: 'bg-success', error: 'bg-danger', warning: 'bg-warning', info: 'bg-primary' };
    const icons = {
        success: 'bi-check-circle-fill',
        error: 'bi-exclamation-triangle-fill',
        warning: 'bi-exclamation-circle-fill',
        info: 'bi-info-circle-fill'
    };

    $('#toastBody').html(`<i class="bi ${icons[type] || icons.info} me-2"></i>${msg}`);
    toastEl.className = `toast align-items-center text-white border-0 ${colors[type] || colors.info}`;
    toast.show();
}

// Nettoie le champ de recherche des selectpickers
function nettoyerRechercheSelectpicker() {
    $('.bs-searchbox input, .bootstrap-select .search-input').val('');
}

// Force le libellé du bouton à correspondre à l'option sélectionnée (évite le texte dupliqué)
function corrigerLibelleSelectpicker($select) {
    if (!$.fn.selectpicker) return;

    const $wrapper = $select.closest('.bootstrap-select');
    if (!$wrapper.length) return;

    const txt = $select.find('option:selected').first().text().trim();
    $wrapper.find('.filter-option-inner-inner').text(txt);
}

function ouvrirNouveauDevis() {
    devisEnEdition = null;
    $('#devisModalTitle').text('Nouveau devis');
    $('#btnEnregistrerDevisTexte').text('Enregistrer le devis');

    $('#lignesContainer .ligne-produit, #lignesContainer .ligne-categorie').each(function () {
        if ($.fn.selectpicker) {
            try { $(this).selectpicker('destroy'); } catch (e) {}
        }
    });

    $('#lignesContainer').empty();

    if ($.fn.selectpicker && $('#clientSelect').hasClass('selectpicker')) {
        $('#clientSelect').selectpicker('val', '');
    } else {
        $('#clientSelect').val('');
    }

    $('#tauxTaxe').val('0');
    $('#tauxRemise').val('0');

    nettoyerRechercheSelectpicker();

    ajouterLigne();
    calculerTotaux();

    devisModal.show();
}

function buildCategorieOptions(selectedCat) {
    let options = '<option value="">-- Catégorie --</option>';
    CATEGORIES.forEach(c => {
        const sel = (selectedCat !== undefined && selectedCat !== null && String(c.code_categorie) === String(selectedCat)) ? ' selected' : '';
        options += `<option value="${escapeHtml(c.code_categorie)}"${sel}>${escapeHtml(c.titre_categorie)}</option>`;
    });
    return options;
}

// Reconstruit le <select> produit d'une ligne à partir de la catégorie choisie
// (même principe que le filtrage catégorie -> produit de entree_stock.php)
function filtrerProduitsLigne($line, catId, selectedProduitCode) {
    const $prodSelect = $line.find('.ligne-produit');
    catId = catId || '';

    // Source de vérité pour la validation : indépendante du widget bootstrap-select.
    $line.attr('data-produit-code', selectedProduitCode || '');

    function rendreOptions(produits) {
        let options = '<option value="">-- Produit --</option>';
        produits.forEach(p => {
            const sel = (selectedProduitCode && String(p.code_produit) === String(selectedProduitCode)) ? ' selected' : '';
            options += `
                <option
                    value="${escapeHtml(p.code_produit)}"
                    data-prix="${escapeHtml(p.prix_produit)}"
                    data-prix-achat="${escapeHtml(p.prix_fournisseur || 0)}"
                    data-subtext="${escapeHtml(fmtN(p.prix_produit))}"${sel}
                >
                    ${escapeHtml(p.titre_produit)}
                </option>
            `;
        });

        if ($.fn.selectpicker) {
            try { $prodSelect.selectpicker('destroy'); } catch (e) {}
        }

        $prodSelect.html(options);
        $prodSelect.prop('disabled', catId === '');
        $prodSelect.attr('title', catId === '' ? "-- Choisir d'abord une catégorie --" : '-- Produit --');

        if ($.fn.selectpicker) {
            $prodSelect.selectpicker({
                liveSearch: true,
                width: '100%',
                size: 8,
                liveSearchPlaceholder: 'Rechercher un produit'
            });

            $prodSelect.off('changed.bs.select').on('changed.bs.select', function () {
                produitChoisi(this);
                corrigerLibelleSelectpicker($(this));
            });
        } else {
            $prodSelect.off('change').on('change', function () {
                produitChoisi(this);
            });
        }
    }

    if (catId === '') {
        rendreOptions([]);
    } else {
        // Placeholder immédiat pendant le chargement, pour un retour visuel rapide.
        $prodSelect.prop('disabled', true).attr('title', 'Chargement...');
        chargerProduitsCategorie(catId, rendreOptions);
    }
}

function ajouterLigne(prefill) {
    prefill = prefill || null;

    const container = document.getElementById('lignesContainer');

    const div = document.createElement('div');
    div.className = 'ligne-devis';

    div.innerHTML = `
        <select class="form-select form-select-sm ligne-categorie selectpicker" title="-- Catégorie --">${buildCategorieOptions(prefill ? prefill.categorie_id : null)}</select>

        <select class="form-select form-select-sm ligne-produit selectpicker" title="-- Choisir d'abord une catégorie --" disabled>
            <option value="">-- Produit --</option>
        </select>

        <input type="number" class="form-control form-control-sm ligne-qte" min="1" value="${prefill ? prefill.qte : 1}"
               title="Quantité totale" onchange="calculerTotaux()" oninput="calculerTotaux()">

        <input type="number" class="form-control form-control-sm ligne-par-lot" min="1" value="${prefill ? prefill.produits_par_lot : 1}"
               title="Produits par lot" onchange="calculerTotaux()" oninput="calculerTotaux()">

        <input type="number" class="form-control form-control-sm ligne-prix" min="0" step="1" value="${prefill ? prefill.prix : 0}"
               onchange="calculerTotaux()" oninput="calculerTotaux()">

        <span class="ligne-montant text-end fw-semibold">0 F</span>

        <button type="button" class="act-btn d" onclick="supprimerLigne(this)" title="Supprimer la ligne">
            <i class="bi bi-x"></i>
        </button>

        <div class="ligne-devis-hint"></div>
        <div class="ligne-prix-alerte"></div>
    `;

    container.appendChild(div);

    const $line = $(div);
    const $catSelect = $line.find('.ligne-categorie');

    if ($.fn.selectpicker) {
        $catSelect.selectpicker({
            liveSearch: true,
            width: '100%',
            size: 8,
            liveSearchPlaceholder: 'Rechercher une catégorie'
        });

        $catSelect.on('changed.bs.select', function () {
            filtrerProduitsLigne($line, $(this).val(), null);
            corrigerLibelleSelectpicker($(this));
            calculerTotaux();
        });
    } else {
        $catSelect.on('change', function () {
            filtrerProduitsLigne($line, $(this).val(), null);
        });
    }

    // Construit tout de suite la liste produit (vide/désactivée si pas de préremplissage,
    // filtrée + présélectionnée si on édite un devis existant)
    filtrerProduitsLigne($line, prefill ? prefill.categorie_id : null, prefill ? prefill.code : null);

    calculerTotaux();
    calculerHintLigne(div);
}

function supprimerLigne(btn) {
    const $line = $(btn).closest('.ligne-devis');

    if ($.fn.selectpicker) {
        try { $line.find('.ligne-produit').selectpicker('destroy'); } catch (e) {}
        try { $line.find('.ligne-categorie').selectpicker('destroy'); } catch (e) {}
    }

    $line.remove();
    calculerTotaux();
}

function calculerHintLigne(div) {
    const qte = parseInt(div.querySelector('.ligne-qte').value) || 0;
    const parLot = Math.max(1, parseInt(div.querySelector('.ligne-par-lot').value) || 1);
    const hint = div.querySelector('.ligne-devis-hint');

    if (parLot > 1) {
        const nbLots = Math.floor(qte / parLot);
        const reste = qte % parLot;

        hint.textContent = reste > 0
            ? `Apparaîtra comme : ${nbLots} lot(s) et ${reste} produit(s)`
            : `Apparaîtra comme : ${nbLots} lot(s)`;
    } else {
        hint.textContent = `Apparaîtra comme : ${qte} produit(s) (pas de lot)`;
    }
}

function produitChoisi(sel) {
    const $select = $(sel);
    const prix = $select.find(':selected').data('prix') || 0;
    const code = $select.val() || '';

    const $ligne = $select.closest('.ligne-devis');
    $ligne.attr('data-produit-code', code);
    $ligne.removeClass('line-error');
    $ligne.find('.ligne-prix').val(prix);

    calculerTotaux();
}

function calculerTotaux() {
    let montantHT = 0;
    let prixInvalideDetecte = false;

    document.querySelectorAll('.ligne-devis').forEach(div => {
        const qte = parseFloat(div.querySelector('.ligne-qte').value) || 0;
        const prix = parseFloat(div.querySelector('.ligne-prix').value) || 0;
        const montant = qte * prix;

        div.querySelector('.ligne-montant').textContent = fmtN(montant);
        calculerHintLigne(div);

        // Vente à perte : le prix d'une ligne ne doit jamais être inférieur au
        // prix d'achat du produit choisi (marge nulle tolérée, signalée).
        // Lu directement sur l'option sélectionnée (fiable aussi bien après une
        // sélection manuelle qu'au préremplissage d'un devis existant).
        const $div = $(div);
        const prixAchatLigne = parseFloat($div.find('.ligne-produit option:selected').data('prix-achat')) || 0;
        const $alerte = $div.find('.ligne-prix-alerte');
        if (prixAchatLigne > 0 && prix > 0) {
            if (prix < prixAchatLigne) {
                $alerte.html('<span class="text-danger fw-semibold"><i class="bi bi-x-octagon-fill"></i> Inférieur au prix d\'achat (' + fmtN(prixAchatLigne) + ' F) — ligne invalide.</span>');
                prixInvalideDetecte = true;
            } else if (prix === prixAchatLigne) {
                $alerte.html('<span class="text-warning fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> Prix égal au prix d\'achat : marge nulle.</span>');
            } else {
                $alerte.html('');
            }
        } else {
            $alerte.html('');
        }

        montantHT += montant;
    });

    const tauxTaxe = parseFloat(document.getElementById('tauxTaxe').value) || 0;
    const tauxRemise = parseFloat(document.getElementById('tauxRemise').value) || 0;

    const taxe = montantHT * tauxTaxe / 100;
    const remise = montantHT * tauxRemise / 100;
    const ttc = montantHT + taxe - remise;

    document.getElementById('totHT').textContent = fmtN(montantHT);
    document.getElementById('totTaxe').textContent = fmtN(taxe);
    document.getElementById('totRemise').textContent = fmtN(remise);
    document.getElementById('totTTC').textContent = fmtN(ttc);

    return !prixInvalideDetecte;
}

function enregistrerDevis() {
    const client_id = $('#clientSelect').val();

    if (!client_id) {
        showToast('Veuillez sélectionner un client avant d’enregistrer le devis.', 'warning');
        return;
    }

    if (!calculerTotaux()) {
        showToast('Au moins une ligne a un prix de vente inférieur au prix d\'achat du produit : corrigez-la avant d\'enregistrer.', 'error');
        return;
    }

    const lignes = [];
    let incomplete = false;
    let hasLines = false;

    $('#lignesContainer .ligne-devis').each(function () {
        hasLines = true;

        const $line = $(this);
        $line.removeClass('line-error');

        const $select = $line.find('.ligne-produit');

        // Source de vérité en priorité : l'attribut posé par produitChoisi()/
        // filtrerProduitsLigne(), qui ne dépend pas de la synchronisation du
        // widget bootstrap-select. Le <select> lui-même sert de repli.
        let code = $line.attr('data-produit-code') || '';

        if (!code) {
            code = $select.length ? $select[0].value : '';

            if (!code && $.fn.selectpicker && $select.hasClass('selectpicker')) {
                try { code = $select.selectpicker('val') || ''; } catch (e) {}
            }
        }

        code = (code || '').toString().trim();

        const qte = parseInt($line.find('.ligne-qte').val(), 10) || 0;
        const prix = parseFloat($line.find('.ligne-prix').val()) || 0;

        // "Par lot" n'est JAMAIS bloquant : s'il n'a pas été configuré (vide,
        // 0, invalide), on retombe simplement sur 1 (pas de lot).
        const parLotRaw = parseInt($line.find('.ligne-par-lot').val(), 10);
        const produits_par_lot = (!isNaN(parLotRaw) && parLotRaw > 0) ? parLotRaw : 1;

        if (code && qte > 0) {
            lignes.push({ code, qte, prix, produits_par_lot });
        } else {
            incomplete = true;
            $line.addClass('line-error');
        }
    });

    // Cas 1 : aucune ligne du tout dans le devis.
    if (!hasLines) {
        showToast('Ajoutez au moins une ligne avec un produit sélectionné et une quantité supérieure à 0.', 'warning');
        return;
    }

    // Cas 2 : au moins une ligne existe mais elle est incomplète (produit
    // manquant ou quantité <= 0). On vérifie ce cas AVANT de re-tester
    // lignes.length, sinon le message du cas 1 s'affichait par erreur même
    // quand une ligne était présente.
    if (incomplete || lignes.length === 0) {
        showToast('Veuillez compléter toutes les lignes : sélectionnez un produit et saisissez une quantité supérieure à 0.', 'warning');
        return;
    }

    const action = devisEnEdition ? 'modifier_devis' : 'creer_devis';
    const postData = {
        action: action,
        ajax: 1,
        csrf_token: CSRF_TOKEN,
        client_id: client_id,
        lignes: JSON.stringify(lignes),
        taux_taxe: document.getElementById('tauxTaxe').value,
        taux_remise: document.getElementById('tauxRemise').value
    };
    if (devisEnEdition) postData.numero = devisEnEdition;

    $.post(window.location.href, postData, function (res) {
        if (res.success) {
            devisModal.hide();
            showToast(res.message, 'success');

            setTimeout(function () {
                location.reload();
            }, 1200);
        } else {
            showToast(res.message, 'error');
        }
    }, 'json').fail(function () {
        showToast('Erreur lors de l’enregistrement du devis.', 'error');
    });
}

function ouvrirModificationDevis(numero) {
    $.post(window.location.href, {
        action: 'get_devis_edit',
        ajax: 1,
        csrf_token: CSRF_TOKEN,
        numero: numero
    }, function (res) {
        if (!res.success) {
            showToast(res.message || 'Erreur lors du chargement du devis.', 'error');
            return;
        }

        devisEnEdition = numero;

        $('#lignesContainer .ligne-produit, #lignesContainer .ligne-categorie').each(function () {
            if ($.fn.selectpicker) {
                try { $(this).selectpicker('destroy'); } catch (e) {}
            }
        });
        $('#lignesContainer').empty();

        if ($.fn.selectpicker && $('#clientSelect').hasClass('selectpicker')) {
            $('#clientSelect').selectpicker('val', res.devis.contact_id || '');
        } else {
            $('#clientSelect').val(res.devis.contact_id || '');
        }

        $('#tauxTaxe').val(res.devis.taux_taxe || '0');
        $('#tauxRemise').val(res.devis.taux_remise || '0');

        nettoyerRechercheSelectpicker();

        (res.lignes || []).forEach(l => {
            ajouterLigne({
                code: l.produit_id,
                qte: parseInt(l.quantite_commande) || 1,
                prix: Number(l.prix_commande) || 0,
                produits_par_lot: Math.max(1, parseInt(l.produits_par_lot) || 1),
                categorie_id: l.categorie_id || ''
            });
        });

        if (!res.lignes || res.lignes.length === 0) {
            ajouterLigne();
        }

        calculerTotaux();

        $('#devisModalTitle').text('Modifier le devis ' + numero);
        $('#btnEnregistrerDevisTexte').text('Enregistrer les modifications');

        $('#devisModal').one('shown.bs.modal', function () {
            corrigerLibelleSelectpicker($('#clientSelect'));
        });

        devisModal.show();
    }, 'json').fail(function () {
        showToast('Erreur lors du chargement du devis.', 'error');
    });
}

function voirDetails(numero) {
    $.post(window.location.href, {
        action: 'get_details',
        ajax: 1,
        csrf_token: CSRF_TOKEN,
        numero: numero
    }, function (res) {
        if (!res.success) {
            showToast('Erreur de chargement des détails.', 'error');
            return;
        }

        let html = `
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Produit</th>
                            <th class="text-center">Qté</th>
                            <th>Lots / Unités</th>
                            <th class="text-end">P.U.</th>
                            <th class="text-end">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        let total = 0;

        res.lignes.forEach(l => {
            const qte = parseInt(l.quantite_commande) || 0;
            const parLot = Math.max(1, parseInt(l.produits_par_lot) || 1);
            const montant = Number(l.montant_commande || 0);

            total += montant;

            let repartition;

            if (parLot > 1) {
                const nbLots = Math.floor(qte / parLot);
                const reste = qte % parLot;
                repartition = reste > 0 ? `${nbLots} lot(s) et ${reste} produit(s)` : `${nbLots} lot(s)`;
            } else {
                repartition = `${qte} produit(s)`;
            }

            html += `
                <tr>
                    <td>${escapeHtml(l.titre_produit || 'N/A')}</td>
                    <td class="text-center">${qte}</td>
                    <td class="small text-muted">${escapeHtml(repartition)}</td>
                    <td class="text-end">${Number(l.prix_commande || 0).toLocaleString('fr-FR')} F</td>
                    <td class="text-end fw-bold">${montant.toLocaleString('fr-FR')} F</td>
                </tr>
            `;
        });

        html += `
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold">${total.toLocaleString('fr-FR')} F</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        `;

        $('#detailsBody').html(html);
        detailsModal.show();
    }, 'json').fail(function () {
        showToast('Erreur lors du chargement des détails du devis.', 'error');
    });
}

function ouvrirTransformation(numero) {
    $('#transformNumero').val(numero);

    if ($.fn.selectpicker) {
        // Détruit proprement l'ancienne instance (évite le libellé dupliqué)
        try {
            $('#transformBoutique').selectpicker('destroy');
        } catch (e) {}

        // Réinitialisation propre du selectpicker
        $('#transformBoutique').selectpicker({
            liveSearch: true,
            width: '100%',
            size: 8,
            liveSearchPlaceholder: 'Rechercher une boutique'
        });
    }

    nettoyerRechercheSelectpicker();

    transformModal.show();
}

function confirmerTransformation() {
    const numero = $('#transformNumero').val();
    const boutique_id = $('#transformBoutique').val();

    if (!boutique_id) {
        showToast('Veuillez sélectionner une boutique pour vérifier le stock.', 'warning');
        return;
    }

    $.post(window.location.href, {
        action: 'transformer_devis',
        ajax: 1,
        csrf_token: CSRF_TOKEN,
        numero: numero,
        boutique_id: boutique_id
    }, function (res) {
        if (res.success) {
            transformModal.hide();

            showToast(res.message, 'success');

            setTimeout(function () {
                location.reload();
            }, 2000);
        } else {
            showToast(res.message, 'error');
        }
    }, 'json').fail(function () {
        showToast('Erreur lors de la transformation du devis.', 'error');
    });
}

function supprimerDevis(numero) {
    devisNumeroToDelete = numero;
    $('#deleteNumeroDevis').text(numero);
    deleteModal.show();
}

function confirmerSuppressionDevis() {
    if (!devisNumeroToDelete) return;

    const $btn = $('#confirmDeleteBtn');
    $btn.prop('disabled', true);

    $.post(window.location.href, {
        action: 'supprimer_devis',
        ajax: 1,
        csrf_token: CSRF_TOKEN,
        numero: devisNumeroToDelete
    }, function (res) {
        deleteModal.hide();
        $btn.prop('disabled', false);

        if (res.success) {
            showToast(res.message, 'success');

            setTimeout(function () {
                location.reload();
            }, 1200);
        } else {
            showToast(res.message, 'error');
        }
    }, 'json').fail(function () {
        deleteModal.hide();
        $btn.prop('disabled', false);
        showToast('Erreur lors de la suppression du devis.', 'error');
    });
}

$(function () {
    if ($.fn.selectpicker) {
        $('.selectpicker').selectpicker();
    }

    devisModal = new bootstrap.Modal(document.getElementById('devisModal'));
    transformModal = new bootstrap.Modal(document.getElementById('transformModal'));
    detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));
    deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));

    toastEl = document.getElementById('toastMsg');
    toast = new bootstrap.Toast(toastEl, { delay: 5000 });

    $('#confirmDeleteBtn').on('click', confirmerSuppressionDevis);

    $('#tauxTaxe, #tauxRemise').on('change', calculerTotaux);

    // Libellé toujours correct après sélection / ouverture
    $('#transformBoutique').on('changed.bs.select', function () {
        corrigerLibelleSelectpicker($(this));
    });

    $('#clientSelect').on('changed.bs.select', function () {
        corrigerLibelleSelectpicker($(this));
    });

    $('#transformModal').on('shown.bs.modal', function () {
        corrigerLibelleSelectpicker($('#transformBoutique'));
    });

    $('#devisModal').on('shown.bs.modal', function () {
        corrigerLibelleSelectpicker($('#clientSelect'));
    });

    // Nettoie la recherche à chaque fermeture d'un selectpicker
    $(document).on('hidden.bs.select', function () {
        nettoyerRechercheSelectpicker();
    });
});
</script>
</body>
</html>