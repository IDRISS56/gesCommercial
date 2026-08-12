<?php

require 'databases/database.php';
// vente_comptoir.php – Caisse - Vente Comptoir
while (ob_get_level()) ob_end_clean();
ob_start();

// - DÉTECTION PRÉCOCE DES REQUÊTES AJAX -
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$isAjax = $isAjax || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

// Récupération utilisateur et boutique
$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Utilisateur inactif']);
        exit;
    }
    header('Location: ../utilisateur/login');
    exit;
}

define('USER_ID', $_SESSION['user_id']);
define('USER_BOUTIQUE', $user['boutique_id'] ?? null);
// Vente comptoir = vente cash sans compte client : toujours rattachée à ce
// client générique plutôt qu'à une sélection manuelle.
define('CLIENT_COMPTOIR_CODE', 'CLI-COMPTOIR');

// Caisse active - logique basée sur le rôle
$caisseActive = null;
$role = $user['role'] ?? '';
$needCaisseChoice = false;
$caissesDisponibles = [];

if ($role === 'Superviseur') {
    // Le superviseur voit toutes les caisses actives ET actuellement en session
    // ouverte (journees_caisse.statut = 'OUVERTE') de sa boutique, à défaut de tout le système.
    if (!empty(USER_BOUTIQUE)) {
        $stmt = $pdo->prepare("SELECT DISTINCT c.caisse_id, c.nom_caisse
                               FROM caisse c
                               INNER JOIN journees_caisse jc ON jc.caisse_id = c.caisse_id AND jc.statut = 'OUVERTE'
                               WHERE c.statut = 'Actif' AND c.boutique_id = ? ORDER BY c.nom_caisse");
        $stmt->execute([USER_BOUTIQUE]);
        $caissesDisponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (empty($caissesDisponibles)) {
        $caissesDisponibles = $pdo->query("SELECT DISTINCT c.caisse_id, c.nom_caisse
                               FROM caisse c
                               INNER JOIN journees_caisse jc ON jc.caisse_id = c.caisse_id AND jc.statut = 'OUVERTE'
                               WHERE c.statut = 'Actif' ORDER BY c.nom_caisse")->fetchAll(PDO::FETCH_ASSOC);
    }

    if (count($caissesDisponibles) === 1) {
        $caisseActive = $caissesDisponibles[0];
        $_SESSION['caisse_choisie_superviseur'] = $caisseActive['caisse_id'];
    } elseif (count($caissesDisponibles) > 1) {
        // Prise en compte d'un choix explicite envoyé par le superviseur
        if (!empty($_GET['choisir_caisse'])) {
            $_SESSION['caisse_choisie_superviseur'] = $_GET['choisir_caisse'];
        }
        $chosen = $_SESSION['caisse_choisie_superviseur'] ?? null;
        $validIds = array_column($caissesDisponibles, 'caisse_id');
        if ($chosen && in_array($chosen, $validIds)) {
            foreach ($caissesDisponibles as $c) { if ($c['caisse_id'] === $chosen) { $caisseActive = $c; break; } }
        } else {
            $needCaisseChoice = true;
        }
    }
} else {
    // Tous les autres rôles (vendeur, caissier, etc.) : on regarde s'ils sont liés
    // (via boutique_id) à la boutique dont une caisse est actuellement ouverte —
    // peu importe qui a personnellement ouvert cette journée.
    if (empty(USER_BOUTIQUE)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => "Votre compte n'est lié à aucune boutique. Contactez un administrateur."]);
            exit;
        }
        include __DIR__ . '/vente_bloquee.php';
        renderVenteBloquee("Votre compte n'est lié à aucune boutique. Contactez un administrateur pour faire vendre.");
        exit;
    }

    $stmt = $pdo->prepare("SELECT c.caisse_id, c.nom_caisse
                           FROM caisse c
                           INNER JOIN journees_caisse jc ON jc.caisse_id = c.caisse_id AND jc.statut = 'OUVERTE'
                           WHERE c.statut = 'Actif' AND c.boutique_id = ? ORDER BY c.nom_caisse");
    $stmt->execute([USER_BOUTIQUE]);
    $caissesDisponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aucune caisse ouverte : on ne bloque plus la page — la vente cash sera
    // simplement indisponible (bouton "Encaisser" désactivé), mais le bon de
    // commande (en attente) reste utilisable sans caisse.
    if (count($caissesDisponibles) === 1) {
        $caisseActive = $caissesDisponibles[0];
    } else {
        // Plusieurs caisses ouvertes dans la même boutique : l'utilisateur choisit.
        if (!empty($_GET['choisir_caisse'])) {
            $_SESSION['caisse_choisie_boutique'] = $_GET['choisir_caisse'];
        }
        $chosen = $_SESSION['caisse_choisie_boutique'] ?? null;
        $validIds = array_column($caissesDisponibles, 'caisse_id');
        if ($chosen && in_array($chosen, $validIds)) {
            foreach ($caissesDisponibles as $c) { if ($c['caisse_id'] === $chosen) { $caisseActive = $c; break; } }
        } else {
            $needCaisseChoice = true;
        }
    }
}

// Si le superviseur doit choisir parmi plusieurs caisses ouvertes
if ($needCaisseChoice) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'need_caisse_choice' => true, 'caisses' => $caissesDisponibles]);
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
<?php include "includes/pwa_head.php"; ?>

        <meta charset="UTF-8">
        <title>Choix de la caisse</title>
        <style>
            body { font-family: Arial, sans-serif; background:#f1f5f9; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
            .box { background:#fff; padding:32px; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,.08); width:360px; }
            .box h3 { margin-top:0; }
            .box a { display:block; padding:12px 16px; margin-bottom:10px; border:1px solid #cbd5e1; border-radius:8px; text-decoration:none; color:#0f172a; font-weight:600; }
            .box a:hover { background:#e2e8f0; }
        </style>
    </head>
    <body>
        <div class="box">
            <h3>Plusieurs caisses sont ouvertes</h3>
            <p>Choisissez la caisse sur laquelle enregistrer vos ventes :</p>
            <?php foreach ($caissesDisponibles as $c): ?>
                <a href="?choisir_caisse=<?= urlencode($c['caisse_id']) ?>"><?= htmlspecialchars($c['nom_caisse']) ?></a>
            <?php endforeach; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Aucune caisse ouverte : on ne bloque plus l'accès à la page — on doit
// pouvoir créer un bon de commande même sans caisse ouverte. Seul le bouton
// "Encaisser" (vente cash) exigera une caisse ouverte, vérifié au moment du clic.
if ($caisseActive) {
    define('CAISSE_ID', $caisseActive['caisse_id']);
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// - TRAITEMENT AJAX - TOUT EN POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        switch ($action) {

            // ===== CRÉER UN BON DE COMMANDE (EN ATTENTE) =====
            // Contrairement à la vente comptoir (cash, immédiate), le bon de commande :
            //  - n'exige AUCUN paiement (avance optionnelle, 0 par défaut) ;
            //  - NE TOUCHE PAS le stock (ni produit, ni lot) à la création : le stock
            //    n'est vérifié/réservé qu'à la validation du bon (views/commande/vente.php,
            //    action validate_facture — cf. condition reference_id IS NULL) ;
            //  - crée une vraie ligne `facture` (categorie_facture='Bon', statut 'En
            //    attente') afin d'apparaître dans le suivi existant (/commande/vente),
            //    exactement comme un bon transformé depuis un devis.
            case 'creer_bon_attente':
                $data = $_POST;
                $token = $data['csrf_token'] ?? '';
                if ($token !== $csrf_token) {
                    echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
                    exit;
                }

                $panier = json_decode($data['panier'] ?? '[]', true) ?: [];
                if (empty($panier)) throw new Exception('Le panier est vide.');

                // Client et boutique : ceux choisis dans les sélecteurs du panier,
                // sinon le client générique comptoir et la boutique connectée
                // (find-or-create pour le client comptoir, comme pour la vente cash).
                $client_id = trim($data['client_id'] ?? '') ?: CLIENT_COMPTOIR_CODE;
                $boutique_id = trim($data['boutique_id'] ?? '') ?: USER_BOUTIQUE;
                $stmtCheckClient = $pdo->prepare("SELECT code_contact FROM contact WHERE code_contact = ?");
                $stmtCheckClient->execute([$client_id]);
                if (!$stmtCheckClient->fetchColumn()) {
                    if ($client_id === CLIENT_COMPTOIR_CODE) {
                        $pdo->prepare("INSERT INTO contact(code_contact, nom_prenom_contact, telephone_contact, email_contact, type_contact, statut_contact, solde_contact, solde_maximum, etat_contact) VALUES (?, 'Client comptoir', '-', '-', 'Client', 'Particulier', 0, 0, 'Actif')")
                            ->execute([$client_id]);
                    } else {
                        throw new Exception('Client introuvable.');
                    }
                }

                $tax_rate = floatval($data['taux_tva'] ?? 0);
                $discount_rate = floatval($data['taux_remise'] ?? 0);
                $lotsData = json_decode($data['lots'] ?? '[]', true) ?: [];

                $montantHT = 0;
                foreach ($panier as $item) {
                    $montantHT += floatval($item['montant'] ?? ($item['prix'] * $item['qte']));
                }
                $taxe = round($montantHT * $tax_rate / 100, 2);
                $remise = round($montantHT * $discount_rate / 100, 2);
                $montantTTC = round($montantHT + $taxe - $remise, 2);

                // Acompte optionnel — jamais obligatoire pour créer le bon.
                $avance = max(0, min(floatval($data['avance'] ?? 0), $montantTTC));
                $reste = round($montantTTC - $avance, 2);
                if ($avance <= 0) {
                    $etatFacture = 'Impayee';
                } elseif ($reste > 0) {
                    $etatFacture = 'Partielle';
                } else {
                    $etatFacture = 'Payee';
                }

                $numBon = 'BON-' . date('Ymd') . '-' . str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);

                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO facture(numero_facture, titre_facture, type_facture, categorie_facture, date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, contact_id, utilisateur_id, etat_facture, statut_facture, reference_id)
                                   VALUES (?, ?, 'Client', 'Bon', CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'En attente', NULL)")
                        ->execute([$numBon, 'Bon de commande ' . $numBon, $montantHT, $taxe, $remise, $montantTTC, $avance, $reste, $client_id, USER_ID, $etatFacture]);

                    // Lignes de commande — AUCUNE vérification/mise à jour de stock ni de
                    // lot ici : c'est repoussé à la validation du bon (validate_facture
                    // dans commande/vente.php), qui reconnaît ce cas via reference_id NULL.
                    $numBase = date('dmYHis');
                    foreach ($panier as $i => $ligne) {
                        $numCmd = $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '-DOC';
                        $prix = floatval($ligne['prix'] ?? 0);
                        $qte = intval($ligne['qte'] ?? 1);
                        $montant = floatval($ligne['montant'] ?? ($prix * $qte));
                        $prix_achat = floatval($ligne['prix_achat'] ?? $prix);
                        $code_prod = $ligne['code'] ?? $ligne['product_id'];
                        // Pas de lot réel créé ici (aucune écriture de stock/lot n'est
                        // faite avant la validation du bon) : on retient seulement le
                        // nombre d'unités par lot choisi, pour l'affichage (X Boîte(s)...).
                        $lot_id = null;
                        $lotConfigure = filter_var($ligne['lot_configure'] ?? false, FILTER_VALIDATE_BOOLEAN);
                        $produits_par_lot = $lotConfigure ? max(2, intval($ligne['unites_par_lot'] ?? 2)) : 1;

                        $stmtCmd = $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                                                  VALUES (?, ?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, 'VALIDEE')");
                        $stmtCmd->execute([$numCmd, $code_prod, $lot_id, $client_id, $numBon,
                                           $prix_achat, $prix, $qte, $produits_par_lot, $montant, USER_ID, $boutique_id]);
                    }

                    $pdo->commit();
                    echo json_encode([
                        'success' => true,
                        'message' => 'Bon de commande ' . $numBon . ' créé (en attente). Le stock sera vérifié à la validation.',
                        'document' => $numBon,
                        'etat' => $etatFacture,
                        'lots' => $lotsData,
                        'totaux' => ['ht' => $montantHT, 'taxe' => $taxe, 'remise' => $remise, 'ttc' => $montantTTC, 'reste' => $reste, 'avance' => $avance]
                    ]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                exit;

            // ===== CHARGER LES CATÉGORIES =====
            case 'load_categories':
                $cats = $pdo->query("SELECT titre_categorie FROM categorie WHERE etat_categorie = 'ACTIF' ORDER BY titre_categorie ASC")->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode(['success' => true, 'data' => $cats, 'has_categorie' => count($cats) > 0]);
                exit;

            // ===== CHARGER TOUS LES CLIENTS =====
            case 'load_all_clients':
                $sql = "SELECT c.code_contact, c.nom_prenom_contact, c.telephone_contact,
                        COALESCE(c.type_contact, 'Client') as type_contact,
                        COALESCE(c.statut_contact, 'Particulier') as statut_contact
                        FROM contact c
                        WHERE c.type_contact = 'Client' AND c.etat_contact = 'Actif'
                        ORDER BY c.nom_prenom_contact ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $clients]);
                exit;

            // ===== CLIENTS POUR LE SELECTPICKER =====
            case 'get_clients':
                $q = trim($_POST['q'] ?? '');
                $sql = "SELECT c.code_contact, c.nom_prenom_contact, c.telephone_contact,
                        COALESCE(c.type_contact, 'Client') as type_contact,
                        COALESCE(c.statut_contact, 'Particulier') as statut_contact
                        FROM contact c
                        WHERE c.type_contact = 'Client' AND c.etat_contact = 'Actif'";
                $params = [];
                if ($q) {
                    $sql .= " AND (c.nom_prenom_contact LIKE ? OR c.telephone_contact LIKE ? OR c.code_contact LIKE ?)";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                }
                $sql .= " ORDER BY c.nom_prenom_contact ASC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['success' => true, 'clients' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                exit;

            // ===== RECHERCHER CLIENTS =====
            case 'search_customers':
                $q = trim($_POST['q'] ?? '');
                $sql = "SELECT c.code_contact, c.nom_prenom_contact, c.telephone_contact,
                        COALESCE(c.type_contact, 'Client') as type_contact,
                        COALESCE(c.statut_contact, 'Particulier') as statut_contact
                        FROM contact c
                        WHERE c.type_contact = 'Client' AND c.etat_contact = 'Actif'";
                $params = [];
                if ($q) {
                    $sql .= " AND (c.nom_prenom_contact LIKE ? OR c.telephone_contact LIKE ? OR c.code_contact LIKE ?)";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                }
                $sql .= " ORDER BY c.nom_prenom_contact ASC LIMIT 20";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $clients]);
                exit;

            // ===== CHARGER PRODUITS =====
            case 'get_products':
            case 'search_products':
                $q = trim($_POST['q'] ?? '');
                $cat = $_POST['categorie'] ?? 'Tous';
                // Boutique dont on affiche le stock : celle choisie dans le sélecteur,
                // sinon la boutique de l'utilisateur connecté par défaut.
                $boutiqueAffichage = trim($_POST['boutique_id'] ?? '') ?: USER_BOUTIQUE;
                // Le stock affiché doit être celui de la boutique choisie uniquement
                // (pas le stock global du produit) : si aucune ligne de stock n'existe
                // pour cette boutique, on affiche 0 — sauf si aucune boutique n'est
                // disponible, auquel cas on retombe sur le stock global.
                $stockExpr = !empty($boutiqueAffichage) ? "COALESCE(sb.quantite, 0)" : "CAST(p.stock_produit AS SIGNED)";
                $sql = "SELECT p.code_produit, p.titre_produit, p.stock_produit, p.prix_produit,
                        p.categorie_id, p.etat_produit,
                        $stockExpr as stock,
                        COALESCE(c.titre_categorie, 'Autre') as categorie
                        FROM produit p
                        LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
                        LEFT JOIN stock sb ON sb.produit_id = p.code_produit AND sb.boutique_id = ?
                        WHERE p.etat_produit != 'RUPTURE'";
                $params = [$boutiqueAffichage];
                if ($cat !== 'Tous') {
                    $sql .= " AND c.titre_categorie = ?";
                    $params[] = $cat;
                }
                if ($q) {
                    $sql .= " AND (p.titre_produit LIKE ? OR p.code_produit LIKE ?)";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                }
                $sql .= " ORDER BY p.titre_produit ASC LIMIT 80";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'products' => $products]);
                exit;

            // ===== CRÉER CLIENT =====
            case 'create_customer':
                $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
                $token = $data['csrf_token'] ?? '';
                if ($token !== $csrf_token) {
                    echo json_encode(['success' => false, 'message' => 'Token invalide']);
                    exit;
                }
                $nom = trim($data['nom'] ?? '');
                if (!$nom) {
                    echo json_encode(['success' => false, 'message' => 'Nom requis']);
                    exit;
                }
                $numClient = 'CT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO contact (code_contact, nom_prenom_contact, telephone_contact, email_contact, type_contact, statut_contact, adresse_contact, etat_contact) VALUES (?, ?, ?, ?, 'Client', ?, ?, 'Actif')");
                $stmt->execute([$numClient, $nom, $data['tel'] ?? '', $data['email'] ?? '', $data['statut'] ?? 'Particulier', $data['adresse'] ?? '']);
                echo json_encode(['success' => true, 'code' => $numClient, 'nom' => $nom]);
                exit;

            // ===== VALIDER VENTE =====
            case 'valider_vente':
                $data = $_POST;
                $token = $data['csrf_token'] ?? '';
                if ($token !== $csrf_token) {
                    echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
                    exit;
                }

                // L'encaissement (vente cash immédiate) exige une caisse ouverte —
                // contrairement au bon de commande, qui peut être créé sans caisse.
                if (!defined('CAISSE_ID')) {
                    throw new Exception("Aucune caisse n'est ouverte. Demandez au caissier d'ouvrir sa journée pour pouvoir encaisser, ou créez un bon de commande en attente.");
                }

                $panier = json_decode($data['panier'] ?? '[]', true) ?: [];
                if (empty($panier)) throw new Exception('Le panier est vide.');

                // Client et boutique : ceux choisis dans les sélecteurs du panier ;
                // à défaut, on retombe sur le client comptoir générique et la boutique
                // de l'utilisateur connecté (find-or-create pour le client comptoir).
                $boutique_id = trim($data['boutique_id'] ?? '') ?: USER_BOUTIQUE;
                $client_id = trim($data['client_id'] ?? '') ?: CLIENT_COMPTOIR_CODE;
                $stmtCheckClient = $pdo->prepare("SELECT code_contact FROM contact WHERE code_contact = ?");
                $stmtCheckClient->execute([$client_id]);
                if (!$stmtCheckClient->fetchColumn()) {
                    if ($client_id === CLIENT_COMPTOIR_CODE) {
                        $pdo->prepare("INSERT INTO contact(code_contact, nom_prenom_contact, telephone_contact, email_contact, type_contact, statut_contact, solde_contact, solde_maximum, etat_contact) VALUES (?, 'Client comptoir', '-', '-', 'Client', 'Particulier', 0, 0, 'Actif')")
                            ->execute([$client_id]);
                    } else {
                        throw new Exception('Client introuvable.');
                    }
                }

                // Vente comptoir = paiement cash uniquement, aucun crédit ni bon en attente.
                $mode_reglement = 'Espèce';
                $amount_paid = floatval($data['avance'] ?? 0);
                $tax_rate = floatval($data['taux_tva'] ?? 0);
                $discount_rate = floatval($data['taux_remise'] ?? 0);

                // Récupération des données de lots
                $lotsData = json_decode($data['lots'] ?? '[]', true) ?: [];

                $montantHT = 0;
                foreach ($panier as $item) {
                    $montantHT += floatval($item['montant'] ?? ($item['prix'] * $item['qte']));
                }
                $taxe = round($montantHT * $tax_rate / 100, 2);
                $remise = round($montantHT * $discount_rate / 100, 2);
                $montantTTC = round($montantHT + $taxe - $remise, 2);

                // ===== PAIEMENT CASH INTÉGRAL OBLIGATOIRE =====
                // La vente comptoir ne gère plus les bons / crédits : le montant reçu
                // doit couvrir le total (le devis + transformation en bon de commande
                // prend maintenant en charge les ventes à crédit).
                if ($amount_paid < $montantTTC) {
                    throw new Exception('Paiement insuffisant : la vente comptoir se règle intégralement en espèces (reçu ' . $amount_paid . ', dû ' . $montantTTC . ').');
                }
                // Seul le montant dû entre en caisse et sur la facture ; le surplus
                // éventuel repart en monnaie rendue au client (jamais en caisse).
                $avance = $montantTTC;
                $reste = 0;
                $etatFacture = 'Payee';
                $statutFacture = 'Validee';
                $categorieDocument = 'Ticket';
                $titreDocument = 'Ticket de caisse';

                // Numéro unique du ticket (aucune facture n'est créée pour la vente
                // comptoir : elle est réglée intégralement en cash, le ticket suffit
                // comme justificatif ; ce numéro sert uniquement de référence pour
                // les lignes de commande et la transaction de caisse).
                $numDocument = 'TICKET-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

                $pdo->beginTransaction();
                try {
                    // 0. CONTRÔLE DE STOCK (verrouillage + vérification AVANT toute écriture)
                    // On agrège les quantités demandées par produit (le panier peut contenir
                    // plusieurs lignes du même produit, ex. lots différents) puis on verrouille
                    // et vérifie le stock réellement disponible pour la boutique de vente.
                    $qteDemandeeParProduit = [];
                    foreach ($panier as $ligne) {
                        $code_prod = $ligne['code'] ?? $ligne['product_id'] ?? null;
                        if (!$code_prod) {
                            throw new Exception('Ligne de panier invalide : produit non identifié.');
                        }
                        $qte = intval($ligne['qte'] ?? 1);
                        if ($qte <= 0) {
                            throw new Exception('Quantité invalide pour un article du panier.');
                        }
                        $qteDemandeeParProduit[$code_prod] = ($qteDemandeeParProduit[$code_prod] ?? 0) + $qte;
                    }

                    foreach ($qteDemandeeParProduit as $code_prod => $qteDemandee) {
                        $stmtNomProd = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
                        $stmtNomProd->execute([$code_prod]);
                        $nomProd = $stmtNomProd->fetchColumn() ?: $code_prod;

                        if (!empty($boutique_id)) {
                            // Verrouille la ligne de stock de cette boutique jusqu'au commit/rollback
                            $stmtStockLock = $pdo->prepare(
                                "SELECT quantite FROM stock WHERE produit_id = ? AND boutique_id = ? FOR UPDATE"
                            );
                            $stmtStockLock->execute([$code_prod, $boutique_id]);
                            $stockDispo = $stmtStockLock->fetchColumn();
                            $stockDispo = ($stockDispo === false) ? 0 : (int) $stockDispo;
                        } else {
                            // Pas de boutique sélectionnée : on se rabat sur le stock global
                            $stmtStockLock = $pdo->prepare(
                                "SELECT stock_produit FROM produit WHERE code_produit = ? FOR UPDATE"
                            );
                            $stmtStockLock->execute([$code_prod]);
                            $stockDispo = (int) $stmtStockLock->fetchColumn();
                        }

                        if ($qteDemandee > $stockDispo) {
                            throw new Exception(
                                "Stock insuffisant pour « $nomProd » : disponible $stockDispo, demandé $qteDemandee."
                            );
                        }
                    }

                    // 1. AUCUNE FACTURE CRÉÉE — la vente comptoir est réglée intégralement
                    // en cash, le ticket (numéro $numDocument, non stocké en base facture)
                    // suffit comme justificatif. Seules les lignes de commande et la
                    // transaction de caisse ci-dessous sont enregistrées.

                    // 2. LIGNES DE COMMANDE — vente comptoir = remise immédiate au client,
                    // donc pas de bon de livraison (le client repart avec sa marchandise
                    // tout de suite ; le bon de livraison ne sert que pour les commandes
                    // à préparer/livrer plus tard, gérées via le circuit devis → bon).
                    $libellesLotValides = ['Boîte', 'Palette', 'Carton', 'Bidon', 'Unité'];
                    $numBase = date('dmYHis');
                    foreach ($panier as $i => $ligne) {
                        $numCmd = $numBase . str_pad($i, 2, '0', STR_PAD_LEFT);
                        $prix = floatval($ligne['prix'] ?? 0);
                        $qte = intval($ligne['qte'] ?? 1);
                        $montant = floatval($ligne['montant'] ?? ($prix * $qte));
                        $prix_achat = floatval($ligne['prix_achat'] ?? $prix);
                        $code_prod = $ligne['code'] ?? $ligne['product_id'];

                        // Configuration de lot (optionnelle, saisie manuellement dans le
                        // panier) : si non configurée, on reste sur le comportement
                        // "produit simple" (lot_id NULL, produits_par_lot = 1), et le
                        // ticket affichera "X Produit(s)" plutôt que de parler de lot.
                        $lotConfigure = filter_var($ligne['lot_configure'] ?? false, FILTER_VALIDATE_BOOLEAN);
                        $unitesParLot = max(2, intval($ligne['unites_par_lot'] ?? 2));
                        $libelleLot = in_array($ligne['libelle_lot'] ?? '', $libellesLotValides, true) ? $ligne['libelle_lot'] : 'Unité';

                        $lot_id = null;
                        $produits_par_lot = 1;
                        if ($lotConfigure) {
                            $lot_id = 'LOT-' . date('YmdHis') . rand(100, 999) . '-' . $i;
                            $produits_par_lot = $unitesParLot;
                            $pdo->prepare("INSERT INTO lot (code_lot, libelle, unites_par_lot, produit_id, quantite, etat_lot)
                                           VALUES (?, ?, ?, ?, ?, 'Actif')")
                                ->execute([$lot_id, $libelleLot, $unitesParLot, $code_prod, $qte]);
                        }

                        $stmtCmd = $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                                                  VALUES (?, ?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, 'VALIDEE')");
                        $stmtCmd->execute([$numCmd . '-DOC', $code_prod, $lot_id, $client_id, $numDocument,
                                           $prix_achat, $prix, $qte, $produits_par_lot, $montant, USER_ID, $boutique_id]);

                        // Mise à jour stock boutique
                        if (!empty($boutique_id)) {
                            $pdo->prepare("UPDATE stock SET quantite = GREATEST(0, quantite - ?) WHERE produit_id = ? AND boutique_id = ?")
                                ->execute([$qte, $code_prod, $boutique_id]);
                        }

                        // Mise à jour stock produit
                        $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) - ? AS CHAR) WHERE code_produit = ?")
                            ->execute([$qte, $code_prod]);

                        // Mise à jour état produit
                        $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                        WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                        WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                        ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                            ->execute([$code_prod]);

                        // Gestion du lot tout juste créé : il est immédiatement vendu, donc
                        // déplété au même montant que sa quantité de création (=> Inactif).
                        if ($lot_id) {
                            $pdo->prepare("UPDATE lot SET quantite = quantite - ? WHERE code_lot = ? AND quantite >= ?")
                                ->execute([$qte, $lot_id, $qte]);
                            $pdo->prepare("UPDATE lot SET etat_lot = 'Inactif' WHERE code_lot = ? AND quantite <= 0")
                                ->execute([$lot_id]);
                        }
                    }

                    // 4. TRANSACTION CAISSE — la vente comptoir est toujours payée cash et
                    // intégralement : on encaisse exactement le montant dû (montantTTC).
                    // La monnaie rendue au client ne transite jamais par la caisse.
                    if ($montantTTC > 0) {
                        $stmtSolde = $pdo->prepare("SELECT solde FROM caisse WHERE caisse_id = ? FOR UPDATE");
                        $stmtSolde->execute([CAISSE_ID]);
                        $soldeAvant = floatval($stmtSolde->fetchColumn());
                        $soldeApres = $soldeAvant + $montantTTC;
                        $numTrans = 'TR-' . date('YmdHis') . rand(100, 999);

                        $stmtTr = $pdo->prepare("INSERT INTO transaction
                            (numero_transaction, date_transaction, heure_transaction, montant_transaction, frais_transaction, montant_total, type_transaction, objet_transaction, caisse_id, facture_id, mode_reglement, utilisateur_id, etat_transaction)
                            VALUES (?, CURDATE(), CURTIME(), ?, 0, ?, 'Entree', 'Vente comptoir', ?, ?, ?, ?, 'Succes')");
                        $stmtTr->execute([$numTrans, $montantTTC, $montantTTC, CAISSE_ID, $numDocument, $mode_reglement, USER_ID]);

                        $pdo->prepare("UPDATE caisse SET solde = ? WHERE caisse_id = ?")
                            ->execute([$soldeApres, CAISSE_ID]);
                    }

                    // 5. SOLDE DU CONTACT : sans objet ici. La vente comptoir étant
                    // toujours payée intégralement cash sur le client générique, elle
                    // ne crée jamais de créance/avance sur un compte client.

                    $pdo->commit();
                    echo json_encode([
                        'success' => true,
                        'document' => $numDocument,
                        'type_document' => $categorieDocument,
                        'reste' => $reste,
                        'etat' => $etatFacture,
                        'statut' => $statutFacture,
                        'lots' => $lotsData,
                        'totaux' => [
                            'ht' => $montantHT,
                            'taxe' => $taxe,
                            'remise' => $remise,
                            'ttc' => $montantTTC,
                            'reste' => $reste,
                            'avance' => $avance
                        ]
                    ]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                exit;

            default:
                throw new Exception('Action inconnue');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'message' => $e->getMessage()]);
        exit;
    }
}

// - RÉCUPÉRATION DES DONNÉES POUR LA PAGE -
$taxes = $pdo->query("SELECT * FROM taxe WHERE etat_taxe = 'ACTIF' ORDER BY type_taxe, taux_taxe")->fetchAll();

$categories = $pdo->query("SELECT DISTINCT c.titre_categorie
                           FROM produit p
                           JOIN categorie c ON p.categorie_id = c.code_categorie
                           WHERE c.titre_categorie IS NOT NULL AND c.titre_categorie <> ''
                           ORDER BY c.titre_categorie ASC")->fetchAll(PDO::FETCH_COLUMN);

// Boutiques actives (sélecteur en haut du panier) : la boutique connectée reste
// pré-sélectionnée par défaut, mais le vendeur peut vendre/commander pour une
// autre boutique — le stock affiché suit alors la boutique choisie.
$boutiquesListe = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);

// Clients actifs (sélecteur client) : "Client comptoir" reste la valeur par
// défaut si rien n'est choisi.
$clientsListe = $pdo->query("SELECT code_contact, nom_prenom_contact, telephone_contact FROM contact WHERE type_contact = 'Client' AND etat_contact = 'Actif' AND code_contact <> '" . CLIENT_COMPTOIR_CODE . "' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);

$caisse = null;
if (defined('CAISSE_ID')) {
    $stmtCaisseInfo = $pdo->prepare("SELECT * FROM caisse WHERE caisse_id = ?");
    $stmtCaisseInfo->execute([CAISSE_ID]);
    $caisse = $stmtCaisseInfo->fetch();
}

$stmtUserInfo = $pdo->prepare("SELECT * FROM utilisateur WHERE id = ?");
$stmtUserInfo->execute([USER_ID]);
$userInfo = $stmtUserInfo->fetch();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caisse - Vente Comptoir</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
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
            --color-gray-50: #f8fafc;
            --color-gray-100: #f1f5f9;
            --color-gray-200: #e2e8f0;
            --color-gray-500: #64748b;
            --color-gray-800: #1e293b;
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --border-color: #e2e8f0;
            --radius-sm: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg-body); color: var(--color-gray-800); min-height: 100vh; }
        .pos-container { display: flex; height: 100vh; overflow: hidden; }
        .pos-left { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .pos-right { width: 420px; background: var(--bg-surface); border-left: 1px solid var(--border-color); display: flex; flex-direction: column; }
        .pos-header { background: var(--bg-surface); border-bottom: 1px solid var(--border-color); padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; }
        .pos-header h2 { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .search-box { padding: 12px 16px; border-bottom: 1px solid var(--border-color); }
        .search-box input { width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-color); border-radius: 8px; font-size: 14px; }
        .search-box input:focus { outline: none; border-color: var(--color-primary); }
        .bootstrap-select .dropdown-toggle { background: #fff !important; border: 1.5px solid var(--border-color) !important; border-radius: 8px !important; }
        .bootstrap-select .dropdown-toggle:focus { border-color: var(--color-primary) !important; box-shadow: 0 0 0 3px var(--color-primary-soft) !important; }
        .category-bar { display: flex; gap: 6px; padding: 10px 16px; border-bottom: 1px solid var(--border-color); overflow-x: auto; background: var(--bg-surface); }
        .cat-btn { padding: 6px 14px; border: 1px solid var(--border-color); background: var(--bg-surface); border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: all .2s; }
        .cat-btn.active { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
        .cat-btn:hover:not(.active) { background: var(--color-gray-100); }
        .products-scroll { flex: 1; overflow-y: auto; padding: 16px; }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
        .product-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; cursor: pointer; transition: all .2s; position: relative; }
        .product-card:hover { border-color: var(--color-primary); box-shadow: 0 4px 12px rgba(79,70,229,.12); transform: translateY(-2px); }
        .product-card.in-cart { border-color: var(--color-success); background: #f0fdf4; }
        .product-card .pc-title { font-size: 13px; font-weight: 600; margin-bottom: 4px; line-height: 1.3; }
        .product-card .pc-code { font-size: 10px; color: var(--color-gray-500); margin-bottom: 6px; }
        .product-card .pc-price { font-size: 14px; font-weight: 800; color: var(--color-primary); }
        .product-card .pc-stock { font-size: 10px; margin-top: 4px; }
        .stock-in { color: var(--color-success); }
        .stock-low { color: var(--color-warning); }
        .stock-out { color: var(--color-danger); }
        .cart-header { padding: 14px 16px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; }
        .cart-header h2 { font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .cart-badge { background: var(--color-primary); color: #fff; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        .client-select-zone { padding: 12px 16px; border-bottom: 1px solid var(--border-color); }
        .client-display { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: var(--color-primary-soft); border-radius: 8px; margin-bottom: 8px; }
        .client-display .cl-name { font-weight: 600; font-size: 13px; }
        .client-display .cl-code { font-size: 10px; color: var(--color-gray-500); }
        .btn-change { background: none; border: none; color: var(--color-primary); font-size: 11px; font-weight: 600; cursor: pointer; }
        .cart-items { flex: 1; overflow-y: auto; padding: 12px 16px; }
        .cart-line { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--color-gray-100); }
        .cart-line .cl-info { flex: 1; min-width: 0; }
        .cart-line .cl-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cart-line .cl-price { font-size: 11px; color: var(--color-gray-500); }
        .cart-line .cl-price input { width: 80px; padding: 2px 6px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 11px; text-align: right; }
        .cart-line .cl-price input:focus { outline: none; border-color: var(--color-primary); }
        .cart-line .cl-qty { display: flex; align-items: center; gap: 4px; }
        .cart-line .cl-qty button { width: 24px; height: 24px; border: 1px solid var(--border-color); background: var(--bg-surface); border-radius: 4px; cursor: pointer; font-size: 12px; }
        .cart-line .cl-qty button:hover { background: var(--color-gray-100); }
        .cart-line .cl-qty span { min-width: 24px; text-align: center; font-weight: 600; font-size: 13px; }
        .cart-line .cl-qty-input { width: 40px; height: 24px; text-align: center; font-weight: 600; font-size: 13px; border: 1px solid var(--border-color); border-radius: 4px; -moz-appearance: textfield; }
        .cart-line .cl-qty-input::-webkit-outer-spin-button, .cart-line .cl-qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .cart-line .cl-montant { font-size: 13px; font-weight: 700; min-width: 70px; text-align: right; }
        .cart-line .cl-remove { background: none; border: none; color: var(--color-danger); cursor: pointer; font-size: 14px; }
        .cart-empty { text-align: center; padding: 40px 20px; color: var(--color-gray-500); }
        .cart-empty i { font-size: 48px; opacity: .3; }
        .cart-footer { padding: 16px; border-top: 1px solid var(--border-color); background: var(--bg-surface); }
        .totals-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px; }
        .totals-row .t-label { color: var(--color-gray-500); }
        .totals-row .t-value { font-weight: 600; }
        .total-grand { display: flex; justify-content: space-between; padding: 10px 0; border-top: 2px solid var(--color-gray-200); margin-top: 8px; font-size: 16px; font-weight: 800; }
        .total-grand .t-value { color: var(--color-primary); }
        .actions-row { display: flex; gap: 8px; margin-top: 12px; }
        .btn-clear { flex: 1; padding: 10px; border: 1px solid var(--border-color); background: var(--bg-surface); border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px; }
        .btn-clear:hover { background: var(--color-gray-100); }
        .btn-attente { flex: 1; padding: 10px; border: none; background: var(--color-warning); color: #fff; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px; }
        .btn-attente:hover { background: #d97706; }
        .btn-attente:disabled { opacity: .5; cursor: not-allowed; }
        .btn-pay { flex: 2; padding: 10px; border: none; background: var(--color-primary); color: #fff; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px; }
        .btn-pay:hover { background: var(--color-primary-dark); }
        .btn-pay:disabled { opacity: .5; cursor: not-allowed; }
        /* Modal */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.show { display: flex; }
        .modal-box { background: var(--bg-surface); border-radius: 16px; width: 480px; max-width: 90%; max-height: 90vh; overflow-y: auto; }
        .modal-head { padding: 18px 24px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; }
        .modal-head h3 { font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--color-gray-500); }
        .modal-body { padding: 24px; }
        .modal-foot { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; gap: 10px; justify-content: flex-end; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--color-gray-500); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1.5px solid var(--border-color); border-radius: 8px; font-size: 14px; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--color-primary); }
        .pay-modes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
        .pay-mode { padding: 14px; border: 1.5px solid var(--border-color); background: var(--bg-surface); border-radius: 10px; cursor: pointer; text-align: center; transition: all .2s; }
        .pay-mode i { display: block; font-size: 22px; margin-bottom: 4px; }
        .pay-mode span { font-size: 12px; font-weight: 600; }
        .pay-mode.active { border-color: var(--color-primary); background: var(--color-primary-soft); color: var(--color-primary); }
        .change-box { background: var(--color-primary-soft); border: 1px solid #bfdbfe; padding: 16px; border-radius: 10px; text-align: center; margin-top: 16px; }
        .change-box .lbl { font-size: 13px; color: var(--color-primary-dark); font-weight: 600; margin-bottom: 4px; }
        .change-box .val { font-size: 28px; font-weight: 800; color: var(--color-primary); }
        .change-box.insufficient { background: #fee2e2; border-color: #fecaca; }
        .change-box.insufficient .lbl { color: #991b1b; }
        .change-box.insufficient .val { color: var(--color-danger); }
        .pay-amount-display { font-size: 20px; font-weight: 800; color: var(--color-primary); text-align: center; padding: 12px; background: var(--color-primary-soft); border-radius: 10px; margin-bottom: 16px; }
        /* Toast */
        .toast-msg { position: fixed; top: 20px; right: 20px; background: var(--color-success); color: #fff; padding: 12px 20px; border-radius: 10px; font-weight: 600; z-index: 2000; display: none; box-shadow: 0 4px 12px rgba(0,0,0,.15); }
        .toast-msg.error { background: var(--color-danger); }
        .toast-msg.show { display: block; animation: slideIn .3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        /* Bootstrap select custom */
        .bootstrap-select .dropdown-toggle { background: #fff !important; border: 1.5px solid var(--border-color) !important; border-radius: 8px !important; }
        .bootstrap-select .dropdown-toggle:focus { border-color: var(--color-primary) !important; box-shadow: 0 0 0 3px var(--color-primary-soft) !important; }
        /* Lots section */
        .lots-section { margin-top: 16px; padding: 12px; background: #f8fafc; border-radius: 8px; border: 1px solid var(--border-color); }
        .lots-section .lots-title { font-weight: 700; margin-bottom: 12px; color: #1e293b; display: flex; align-items: center; gap: 6px; }
        .lot-item { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; padding: 8px; background: white; border-radius: 6px; border: 1px solid var(--border-color); }
        .lot-item .lot-info { flex: 1; min-width: 0; }
        .lot-item .lot-name { font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lot-item .lot-qty { font-size: 11px; color: #64748b; }
        .lot-item .lot-input-group { display: flex; align-items: center; gap: 4px; }
        .lot-item .lot-input-group label { font-size: 11px; color: #64748b; white-space: nowrap; }
        .lot-item .lot-input-group input { width: 60px; padding: 4px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 12px; text-align: center; }
        .lot-item .lot-result { min-width: 70px; text-align: right; font-size: 12px; font-weight: 700; color: #0369a1; }
        .lots-total { margin-top: 12px; padding: 10px; background: #e0f2fe; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; }
        .lots-total .label { font-weight: 600; color: #0c4a6e; }
        .lots-total .value { font-size: 18px; font-weight: 800; color: #0369a1; }
        @media (max-width: 900px) {
            .pos-container { flex-direction: column; height: auto; }
            .pos-right { width: 100%; border-left: none; border-top: 1px solid var(--border-color); }
        }
    </style>
</head>
<body>
<div class="pos-container">
    <!-- GAUCHE : PRODUITS -->
    <div class="pos-left">
        <div class="pos-header">
            <h2><i class="bi bi-shop"></i> Vente Comptoir</h2>
            <div class="d-flex align-items-center gap-3">
                <div style="display:flex;align-items:center;gap:6px;">
                    <label for="boutiqueSelect" style="font-size:11px;font-weight:600;color:var(--color-gray-500);text-transform:uppercase;white-space:nowrap;">Boutique</label>
                    <select id="boutiqueSelect" class="selectpicker" data-live-search="true" data-width="220px" data-container="body">
                        <?php foreach ($boutiquesListe as $b): ?>
                            <option value="<?= htmlspecialchars($b['code_boutique']) ?>" <?= ($b['code_boutique'] === USER_BOUTIQUE) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['nom_boutique']) ?><?= ($b['code_boutique'] === USER_BOUTIQUE) ? ' (ma boutique)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="text-muted small"><i class="bi bi-person"></i> <?= htmlspecialchars($userInfo['nom_prenom'] ?? '') ?></span>
                <?php if ($caisse): ?>
                    <span class="badge bg-success-subtle text-success"><i class="bi bi-cash-stack"></i> Caisse ouverte</span>
                <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning" title="Vente cash indisponible — le bon de commande reste possible">
                        <i class="bi bi-exclamation-triangle"></i> Aucune caisse ouverte
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="Rechercher un produit (code, titre)...">
        </div>
        <div class="category-bar" id="categoryBar">
            <button class="cat-btn active" data-cat="Tous" onclick="filterCategory('Tous', this)">Tous</button>
            <?php foreach ($categories as $cat): ?>
                <button class="cat-btn" data-cat="<?= htmlspecialchars($cat) ?>" onclick="filterCategory('<?= htmlspecialchars($cat) ?>', this)"><?= htmlspecialchars($cat) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="products-scroll">
            <div class="product-grid" id="productGrid">
                <div class="empty-state" style="text-align:center;padding:40px;color:var(--color-gray-500);">
                    <i class="bi bi-arrow-repeat" style="font-size:48px;opacity:.3;"></i>
                    <h3>Chargement...</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- DROITE : PANIER -->
    <div class="pos-right">
        <div class="cart-header">
            <h2><i class="bi bi-receipt"></i> Ticket <span class="cart-badge" id="cartCount">0</span></h2>
        </div>
        <div class="client-select-zone">
            <label style="font-size:11px;font-weight:700;color:var(--color-gray-500);text-transform:uppercase;">Client</label>
            <div style="display:flex;gap:8px;align-items:center;">
                <select id="clientSelect" class="form-select form-select-sm" style="flex:1;min-width:0;">
                    <option value="">Client comptoir (par défaut)</option>
                    <?php foreach ($clientsListe as $c): ?>
                        <option value="<?= htmlspecialchars($c['code_contact']) ?>">
                            <?= htmlspecialchars($c['nom_prenom_contact']) ?><?= $c['telephone_contact'] ? ' — ' . htmlspecialchars($c['telephone_contact']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-outline-primary flex-shrink-0" style="height:31px;white-space:nowrap;" onclick="openModal('clientModal')" title="Nouveau client">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
        </div>
        <div class="cart-items" id="cartItems">
            <div class="cart-empty"><i class="bi bi-cart-x"></i><p>Panier vide</p></div>
        </div>
        <div class="cart-footer">
            <div class="totals-row">
                <span class="t-label">Sous-total HT</span>
                <select id="taxRate" style="width:auto;padding:2px 6px;border:1px solid var(--border-color);border-radius:4px;font-size:11px;">
                    <option value="0">0%</option>
                    <?php foreach ($taxes as $t): ?>
                        <option value="<?= floatval($t['taux_taxe']) ?>"><?= floatval($t['taux_taxe']) ?>%</option>
                    <?php endforeach; ?>
                </select>
                <span class="t-value" id="totalHT">0 FCFA</span>
            </div>
            <div class="totals-row">
                <span class="t-label">Remise</span>
                <select id="discountRate" style="width:auto;padding:2px 6px;border:1px solid var(--border-color);border-radius:4px;font-size:11px;">
                    <option value="0">0%</option>
                    <option value="5">5%</option>
                    <option value="10">10%</option>
                </select>
                <span class="t-value" id="totalRemise">0 FCFA</span>
            </div>
            <div class="total-grand">
                <span>TOTAL TTC</span>
                <span class="t-value" id="totalTTC">0 FCFA</span>
            </div>
            <div class="actions-row">
                <button class="btn-clear" id="btnClear"><i class="bi bi-trash3"></i> Vider</button>
                <button class="btn-pay" id="btnCheckout" disabled <?= !$caisse ? 'title="Aucune caisse ouverte — encaissement indisponible"' : '' ?>><i class="bi bi-credit-card-fill"></i> Encaisser</button>
            </div>
            <div class="actions-row" style="margin-top:8px;">
                <button class="btn-attente" id="btnBonCommande" disabled style="width:100%;justify-content:center;">
                    <i class="bi bi-hourglass-split"></i> Bon de commande (en attente)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Paiement -->
<div class="modal-overlay" id="payModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="bi bi-credit-card-2-front"></i> Encaissement</h3>
            <button class="modal-close" onclick="closeModal('payModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <div class="pay-amount-display">
                <div style="font-size:12px;color:var(--color-gray-500);font-weight:600;">MONTANT À PAYER</div>
                <div id="payAmount">0 FCFA</div>
            </div>
            <div class="form-group">
                <label>Mode de paiement</label>
                <div class="pay-modes">
                    <button class="pay-mode active" type="button" disabled>
                        <i class="bi bi-cash"></i><span>Espèces (comptant uniquement)</span>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label>Montant reçu</label>
                <input type="number" id="receivedAmount" style="font-size:20px;font-weight:700;text-align:center;" placeholder="0" oninput="calculateChange()">
            </div>
            <div class="change-box" id="changeBox">
                <div class="lbl" id="changeLbl">Monnaie à rendre</div>
                <div class="val" id="changeAmount">0 FCFA</div>
            </div>

            <div class="mt-3 p-3 rounded" style="background:var(--color-gray-50);font-size:12px;">
                <div class="d-flex justify-content-between mb-1">
                    <span>État de la facture :</span>
                    <strong id="etatPreview">-</strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Reste à payer :</span>
                    <strong id="restePreview" class="text-danger">0 FCFA</strong>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal('payModal')">Annuler</button>
            <button class="btn btn-primary" id="btnValidatePay" onclick="validatePayment()"><i class="bi bi-check-lg"></i> Valider</button>
        </div>
    </div>
</div>


<!-- Modal Nouveau Client -->
<div class="modal-overlay" id="clientModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="bi bi-person-plus"></i> Nouveau client</h3>
            <button class="modal-close" onclick="closeModal('clientModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <form id="clientForm">
                <div class="form-group">
                    <label>Nom et prénoms *</label>
                    <input type="text" id="clientNom" required>
                </div>
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="text" id="clientTel">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="clientEmail">
                </div>
                <div class="form-group">
                    <label>Adresse</label>
                    <input type="text" id="clientAdresse">
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select id="clientStatut">
                        <option value="Particulier">Particulier</option>
                        <option value="Société">Société</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal('clientModal')">Annuler</button>
            <button class="btn btn-primary" onclick="createClient()"><i class="bi bi-check-lg"></i> Créer</button>
        </div>
    </div>
</div>


<!-- Modal Ticket -->
<div class="modal-overlay" id="ticketModal">
    <div class="modal-box" style="width:300px;">
        <div class="modal-head">
            <h3><i class="bi bi-receipt"></i> Ticket</h3>
            <button class="modal-close" onclick="closeModal('ticketModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body" id="printZone"></div>
        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal('ticketModal');resetSale();">Nouvelle vente</button>
            <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimer</button>
        </div>
    </div>
</div>

<!-- Modal Confirmation générique (remplace les confirm()/alert() JS) -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box" style="width:360px;">
        <div class="modal-head">
            <h3><i class="bi bi-question-circle"></i> <span id="confirmModalTitle">Confirmation</span></h3>
            <button class="modal-close" onclick="closeModal('confirmModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <p id="confirmModalMsg" style="margin:0;font-size:14px;color:var(--text-secondary,#334155);"></p>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal('confirmModal')">Annuler</button>
            <button class="btn btn-primary" id="confirmModalOk">Confirmer</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast-msg" id="toastMsg"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
const BASE_URL = window.location.pathname;
const CSRF_TOKEN = '<?= $csrf_token ?>';
const CAISSE_OUVERTE = <?= $caisse ? 'true' : 'false' ?>;
let cart = [];
let currentProducts = [];
let currentCategory = 'Tous';
let searchTimer = null;

const gid = id => document.getElementById(id);
const fmt = n => new Intl.NumberFormat('fr-FR').format(Math.round(n || 0)) + ' FCFA';
const esc = s => (s || '').toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

function toast(msg, type = 'success') {
    const t = gid('toastMsg');
    t.textContent = msg;
    t.className = 'toast-msg show' + (type === 'error' ? ' error' : '');
    setTimeout(() => t.classList.remove('show'), 2500);
}

function openModal(id) { gid(id).classList.add('show'); }
function closeModal(id) { gid(id).classList.remove('show'); }

// Remplace window.confirm() par la modal maison
function confirmModal(message, onOk, title) {
    gid('confirmModalTitle').textContent = title || 'Confirmation';
    gid('confirmModalMsg').textContent = message;
    const okBtn = gid('confirmModalOk');
    const newOkBtn = okBtn.cloneNode(true); // évite l'empilement de handlers
    okBtn.parentNode.replaceChild(newOkBtn, okBtn);
    newOkBtn.addEventListener('click', function() {
        closeModal('confirmModal');
        onOk();
    });
    openModal('confirmModal');
}

// ===== TOUT EN POST, AUCUN GET =====
function api(action, data) {
    return fetch(BASE_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({ ...data, action })
    }).then(r => r.json()).catch(err => { throw new Error('Erreur connexion'); });
}

async function createClient() {
    const nom = gid('clientNom').value.trim();
    if (!nom) { toast('Nom requis', 'error'); return; }
    try {
        const res = await api('create_customer', {
            nom,
            tel: gid('clientTel').value,
            email: gid('clientEmail').value,
            adresse: gid('clientAdresse').value,
            statut: gid('clientStatut').value,
            csrf_token: CSRF_TOKEN
        });
        if (res.success) {
            closeModal('clientModal');
            gid('clientForm').reset();
            const select = gid('clientSelect');
            const opt = document.createElement('option');
            opt.value = res.code;
            opt.textContent = res.nom;
            select.appendChild(opt);
            select.value = res.code;
            toast('Client créé');
        } else {
            toast(res.message || 'Erreur', 'error');
        }
    } catch (e) { toast('Erreur connexion', 'error'); }
}

// Charger produits
async function loadProducts(q = '') {
    try {
        const res = await api('get_products', { q, categorie: currentCategory, boutique_id: gid('boutiqueSelect').value || '' });
        if (res.success) {
            currentProducts = res.products;
            renderProducts(currentProducts, q);
        }
    } catch (e) { toast('Erreur chargement', 'error'); }
}

function renderProducts(products, q = '') {
    if (products.length === 0) {
        gid('productGrid').innerHTML = '<div style="text-align:center;padding:40px;color:var(--color-gray-500);"><i class="bi bi-box-seam" style="font-size:48px;opacity:.3;"></i><h3>Aucun produit</h3></div>';
        return;
    }
    gid('productGrid').innerHTML = products.map((p, i) => {
        const stock = parseInt(p.stock) || 0;
        let stockClass = 'stock-out', stockText = 'Rupture';
        if (stock > 5) { stockClass = 'stock-in'; stockText = 'Stock: ' + stock; }
        else if (stock > 0) { stockClass = 'stock-low'; stockText = 'Stock: ' + stock + ' ⚠'; }
        const inCart = cart.some(item => item.code === p.code_produit);
        const cardClass = inCart ? 'product-card in-cart' : 'product-card';
        const titleHTML = q ? p.titre_produit.replace(new RegExp(q, 'gi'), m => `<mark>${m}</mark>`) : esc(p.titre_produit);
        return `<div class="${cardClass}" onclick="addProduct(${i})">
            <div class="pc-title">${titleHTML}</div>
            <div class="pc-code">${esc(p.code_produit)}</div>
            <div class="pc-price">${fmt(p.prix_produit || 0)}</div>
            <div class="pc-stock ${stockClass}">${stockText}</div>
        </div>`;
    }).join('');
}

function filterCategory(cat, btn) {
    currentCategory = cat;
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    loadProducts(gid('searchInput').value.trim());
}

gid('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadProducts(this.value.trim()), 300);
});

gid('boutiqueSelect').addEventListener('change', function() {
    loadProducts(gid('searchInput').value.trim());
});

async function addProduct(idx) {
    const p = currentProducts[idx];
    if (!p) return;
    const stock = parseInt(p.stock) || 0;
    if (stock <= 0) { toast('Rupture de stock', 'error'); return; }
    const existing = cart.find(item => item.code === p.code_produit);
    if (existing) {
        if (existing.qte + 1 > stock) { toast('Stock max atteint', 'error'); return; }
        existing.qte += 1;
        existing.montant = existing.qte * existing.prix;
    } else {
        const prix = parseFloat(p.prix_produit) || 0;
        cart.push({
            code: p.code_produit,
            nom: p.titre_produit,
            prix: prix,
            prix_achat: parseFloat(p.prix_produit) || 0,
            qte: 1,
            stock: stock,
            montant: prix,
            lotConfigure: false,
            unitesParLot: 2,
            libelleLot: 'Unité'
        });
    }
    renderCart();
    renderProducts(currentProducts, gid('searchInput').value.trim());
    toast('Produit ajouté');
}

async function getProductPrice(produitId, quantite) {
    try {
        const res = await api('get_product_price', { produit_id: produitId, quantite });
        if (res.success) return parseFloat(res.prix) || 0;
        return 0;
    } catch (e) { return 0; }
}

window.updateQty = function(code, delta) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    const newQty = item.qte + delta;
    if (newQty <= 0) { window.removeProduct(code); return; }
    if (newQty > item.stock) { toast('Stock max', 'error'); return; }
    item.qte = newQty;
    item.montant = item.qte * item.prix;
    renderCart();
};

window.setQty = function(code, value) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    let newQty = parseInt(value, 10);
    if (isNaN(newQty) || newQty <= 0) { renderCart(); return; }
    if (newQty > item.stock) { toast('Stock max atteint', 'error'); newQty = item.stock; }
    item.qte = newQty;
    item.montant = item.qte * item.prix;
    renderCart();
};

window.updatePrice = function(code, newPrice) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    item.prix = parseFloat(newPrice) || 0;
    item.montant = item.qte * item.prix;
    renderCart();
};

window.removeProduct = function(code) {
    cart = cart.filter(p => p.code !== code);
    renderCart();
    renderProducts(currentProducts, gid('searchInput').value.trim());
    toast('Retiré');
};

function renderCart() {
    if (cart.length === 0) {
        gid('cartItems').innerHTML = '<div class="cart-empty"><i class="bi bi-cart-x"></i><p>Panier vide</p></div>';
        gid('cartCount').textContent = '0';
        gid('btnCheckout').disabled = true;
        gid('btnBonCommande').disabled = true;
    } else {
        let html = '';
        cart.forEach(p => {
            const unites = Math.max(1, parseInt(p.unitesParLot) || 1);
            let apercu;
            if (p.lotConfigure && unites > 1) {
                const nbLots = Math.floor(p.qte / unites);
                const reste = p.qte % unites;
                apercu = reste > 0 ? `${nbLots} ${esc(p.libelleLot)}(s) et ${reste} Produit(s)` : `${nbLots} ${esc(p.libelleLot)}(s)`;
            } else {
                apercu = `${p.qte} Produit(s)`;
            }
            html += `<div class="cart-line">
                <div class="cl-info">
                    <div class="cl-name">${esc(p.nom)}</div>
                    <div class="cl-price">P.U: <input type="number" value="${p.prix}" onchange="updatePrice('${p.code}', this.value)" onclick="event.stopPropagation()"> FCFA</div>
                </div>
                <div class="cl-qty">
                    <button onclick="updateQty('${p.code}', -1)">-</button>
                    <input type="number" class="cl-qty-input" min="1" max="${p.stock}" step="1"
                           value="${p.qte}"
                           onclick="event.stopPropagation()"
                           onchange="setQty('${p.code}', this.value)">
                    <button onclick="updateQty('${p.code}', 1)">+</button>
                </div>
                <div class="cl-montant">${fmt(p.montant)}</div>
                <button class="cl-remove" onclick="removeProduct('${p.code}')"><i class="bi bi-x-circle"></i></button>
            </div>
            <div class="cl-lot-config" style="padding:4px 10px 8px;font-size:11px;color:var(--color-gray-500);">
                <label style="cursor:pointer;">
                    <input type="checkbox" ${p.lotConfigure ? 'checked' : ''} onchange="toggleLotConfig('${p.code}', this.checked)">
                    Configurer un lot
                </label>
                ${p.lotConfigure ? `
                <span style="margin-left:8px;">
                    <input type="number" min="2" step="1" value="${unites}" style="width:56px;" title="Unités par lot"
                           onclick="event.stopPropagation()" onchange="setLotUnites('${p.code}', this.value)"> unité(s) par
                    <select onclick="event.stopPropagation()" onchange="setLotLibelle('${p.code}', this.value)">
                        ${['Boîte','Palette','Carton','Bidon','Unité'].map(l => `<option value="${l}" ${p.libelleLot === l ? 'selected' : ''}>${l}</option>`).join('')}
                    </select>
                    — <strong>${apercu}</strong>
                </span>` : `<span style="margin-left:8px;">— ${apercu}</span>`}
            </div>`;
        });
        gid('cartItems').innerHTML = html;
        gid('cartCount').textContent = cart.reduce((s, p) => s + p.qte, 0);
        gid('btnCheckout').disabled = false;
        gid('btnBonCommande').disabled = false;
    }
    calculateTotals();
}

window.toggleLotConfig = function(code, checked) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    item.lotConfigure = checked;
    if (checked && (!item.unitesParLot || item.unitesParLot < 2)) item.unitesParLot = 2;
    renderCart();
};

window.setLotUnites = function(code, value) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    item.unitesParLot = Math.max(2, parseInt(value, 10) || 2);
    renderCart();
};

window.setLotLibelle = function(code, value) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    item.libelleLot = value;
    renderCart();
};

function calculateTotals() {
    const ht = cart.reduce((s, p) => s + p.montant, 0);
    const taxRate = parseFloat(gid('taxRate').value) || 0;
    const discRate = parseFloat(gid('discountRate').value) || 0;
    const tax = Math.round(ht * taxRate / 100);
    const disc = Math.round(ht * discRate / 100);
    const ttc = Math.round(ht + tax - disc);
    gid('totalHT').textContent = fmt(ht);
    gid('totalRemise').textContent = fmt(disc);
    gid('totalTTC').textContent = fmt(ttc);
}

function getTTC() {
    const ht = cart.reduce((s, p) => s + p.montant, 0);
    const taxRate = parseFloat(gid('taxRate').value) || 0;
    const discRate = parseFloat(gid('discountRate').value) || 0;
    return Math.round(ht + Math.round(ht * taxRate / 100) - Math.round(ht * discRate / 100));
}

function updateButtons() {
    gid('btnCheckout').disabled = cart.length === 0 || !CAISSE_OUVERTE;
    gid('btnBonCommande').disabled = cart.length === 0;
}

gid('btnClear').addEventListener('click', function() {
    if (cart.length === 0) return;
    confirmModal('Vider le panier ?', function() {
        cart = [];
        renderCart();
        renderProducts(currentProducts, gid('searchInput').value.trim());
        toast('Panier vidé');
    });
});

// Encaisser — exige une caisse ouverte (vérifié aussi côté serveur)
gid('btnCheckout').addEventListener('click', function() {
    if (cart.length === 0) { toast('Panier vide', 'error'); return; }
    if (!CAISSE_OUVERTE) { toast("Aucune caisse ouverte : utilisez « Bon de commande (en attente) »", 'error'); return; }

    calculateTotals();
    gid('payAmount').textContent = fmt(getTTC());
    gid('receivedAmount').value = '';
    gid('changeAmount').textContent = '0 FCFA';
    gid('changeBox').className = 'change-box';
    gid('changeLbl').textContent = 'Monnaie à rendre';
    gid('etatPreview').textContent = '-';
    gid('restePreview').textContent = '0 FCFA';

    openModal('payModal');
});

// Bon de commande (en attente) — utilisable même sans caisse ouverte : pas de
// modal, la boutique et le client sont pris directement dans les sélecteurs
// du panier (par défaut : boutique connectée / client comptoir), le stock
// n'est ni vérifié ni touché ici.
gid('btnBonCommande').addEventListener('click', function() {
    if (cart.length === 0) { toast('Panier vide', 'error'); return; }

    confirmModal('Créer un bon de commande en attente pour ' + fmt(getTTC()) + ' FCFA ?', function() {
        creerBonAttente();
    });
});

async function creerBonAttente() {
    const btn = gid('btnBonCommande');
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-spinner"></i> Création...';

    const lotsData = cart
        .filter(item => item.lotConfigure && (item.unitesParLot || 1) > 1)
        .map(item => ({
            code: item.code, nom: item.nom, qte: item.qte,
            produitsParLot: item.unitesParLot || 1,
            nombreLots: Math.floor(item.qte / (item.unitesParLot || 1))
        }));

    try {
        const data = {
            panier: JSON.stringify(cart.map(p => ({
                code: p.code, prix: p.prix, prix_achat: p.prix_achat, qte: p.qte, montant: p.montant,
                lot_configure: !!p.lotConfigure, unites_par_lot: p.unitesParLot || 1, libelle_lot: p.libelleLot || 'Unité'
            }))),
            client_id: gid('clientSelect').value || '',
            boutique_id: gid('boutiqueSelect').value || '',
            taux_tva: parseFloat(gid('taxRate').value) || 0,
            taux_remise: parseFloat(gid('discountRate').value) || 0,
            csrf_token: CSRF_TOKEN,
            lots: JSON.stringify(lotsData)
        };

        const res = await api('creer_bon_attente', data);
        btn.disabled = false;
        btn.innerHTML = originalText;

        if (res.success) {
            toast('Bon de commande créé : ' + res.document + ' (en attente)');
            resetSale();
        } else {
            toast(res.message || 'Erreur', 'error');
        }
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = originalText;
        toast(err.message || 'Erreur connexion', 'error');
    }
}

function calculateChange() {
    const received = parseFloat(gid('receivedAmount').value) || 0;
    const ttc = getTTC();
    const change = received - ttc;
    const reste = Math.max(0, ttc - received);

    if (received >= ttc) {
        gid('changeLbl').textContent = 'Monnaie à rendre';
        gid('changeAmount').textContent = fmt(change);
        gid('changeBox').className = 'change-box';
        gid('etatPreview').innerHTML = '<span class="badge bg-success">PAYEE</span>';
        gid('restePreview').textContent = '0 FCFA';
    } else if (received > 0) {
        gid('changeLbl').textContent = 'Montant restant';
        gid('changeAmount').textContent = fmt(reste);
        gid('changeBox').className = 'change-box insufficient';
        gid('etatPreview').innerHTML = '<span class="badge bg-warning text-dark">PARTIELLE</span>';
        gid('restePreview').textContent = fmt(reste);
    } else {
        gid('changeLbl').textContent = 'Aucun paiement';
        gid('changeAmount').textContent = '0 FCFA';
        gid('changeBox').className = 'change-box insufficient';
        gid('etatPreview').innerHTML = '<span class="badge bg-danger">IMPAYEE</span>';
        gid('restePreview').textContent = fmt(ttc);
    }
}

function validatePayment() {
    const received = parseFloat(gid('receivedAmount').value) || 0;
    const ttc = getTTC();
    if (received < ttc) {
        toast('Paiement insuffisant : la vente comptoir se règle intégralement en espèces', 'error');
        return;
    }
    validateSale(received);
}

async function validateSale(avance = 0) {
    const btn = gid('btnValidatePay');
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-spinner"></i> Validation...';

    try {
        // Préparer les données de lots — uniquement les lignes où un lot a
        // réellement été configuré, sinon le ticket affichait à tort une
        // section "lots" pour de simples produits vendus à l'unité.
        const lotsData = cart
            .filter(item => item.lotConfigure && (item.unitesParLot || 1) > 1)
            .map(item => ({
                code: item.code,
                nom: item.nom,
                qte: item.qte,
                produitsParLot: item.unitesParLot || 1,
                nombreLots: Math.floor(item.qte / (item.unitesParLot || 1))
            }));

        const data = {
            panier: JSON.stringify(cart.map(p => ({
                code: p.code,
                prix: p.prix,
                prix_achat: p.prix_achat,
                qte: p.qte,
                montant: p.montant,
                lot_configure: !!p.lotConfigure,
                unites_par_lot: p.unitesParLot || 1,
                libelle_lot: p.libelleLot || 'Unité'
            }))),
            avance: avance,
            client_id: gid('clientSelect').value || '',
            boutique_id: gid('boutiqueSelect').value || '',
            taux_tva: parseFloat(gid('taxRate').value) || 0,
            taux_remise: parseFloat(gid('discountRate').value) || 0,
            csrf_token: CSRF_TOKEN,
            lots: JSON.stringify(lotsData)
        };

        const res = await api('valider_vente', data);

        btn.disabled = false;
        btn.innerHTML = originalText;

        if (res.success) {
            closeModal('payModal');
            toast('Vente validée ! ' + res.type_document + ': ' + res.document + ' (' + res.etat + ')');
            generateTicket(res);
            openModal('ticketModal');
            resetSale();
        } else {
            toast(res.message || 'Erreur', 'error');
        }
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = originalText;
        toast(err.message || 'Erreur connexion', 'error');
    }
}

function generateTicket(res) {
    const now = new Date();
    const dateStr = now.toLocaleDateString('fr-FR');
    const timeStr = now.toLocaleTimeString('fr-FR');
    const t = res.totaux;

    let html = `<div style="font-family:monospace;font-size:12px;">
        <div style="text-align:center;font-weight:700;font-size:16px;margin-bottom:4px;">VENTE COMPTOIR</div>
        <div style="text-align:center;font-size:11px;color:#64748b;margin-bottom:12px;">${dateStr} ${timeStr}</div>
        <div style="font-size:11px;margin-bottom:8px;">Client: Client comptoir</div>
        <div style="font-size:11px;margin-bottom:12px;">${res.type_document}: ${res.document}</div>
        <div style="font-size:11px;margin-bottom:12px;">État: <strong>${res.etat}</strong></div>
        <hr style="border:1px dashed #ccc;">`;

    cart.forEach(p => {
        html += `<div style="display:flex;justify-content:space-between;margin-bottom:4px;">
            <span>${esc(p.nom)} x${p.qte}</span>
            <span>${fmt(p.montant)}</span>
        </div>`;
    });

    html += `<hr style="border:1px dashed #ccc;">
        <div style="display:flex;justify-content:space-between;"><span>Sous-total HT</span><span>${fmt(t.ht)}</span></div>
        <div style="display:flex;justify-content:space-between;"><span>Taxe</span><span>${fmt(t.taxe)}</span></div>
        <div style="display:flex;justify-content:space-between;"><span>Remise</span><span>${fmt(t.remise)}</span></div>
        <div style="display:flex;justify-content:space-between;font-weight:700;font-size:14px;margin-top:8px;"><span>TOTAL TTC</span><span>${fmt(t.ttc)}</span></div>
        <div style="display:flex;justify-content:space-between;margin-top:8px;"><span>Avance payée</span><span>${fmt(t.avance)}</span></div>
        ${t.reste > 0 ? `<div style="display:flex;justify-content:space-between;color:#ef4444;"><span>Reste</span><span>${fmt(t.reste)}</span></div>` : ''}`;

    // Section des lots — uniquement les produits pour lesquels un lot a été
    // configuré (produitsParLot > 1) ; jamais de mention de "lot" sinon.
    const lotsConfigures = (res.lots || []).filter(lot => (lot.produitsParLot || 1) > 1);
    if (lotsConfigures.length > 0) {
        html += `<hr style="border:1px dashed #ccc;margin-top:8px;">
            <div style="font-weight:700;margin:8px 0;">CONFIGURATION DES LOTS</div>`;
        lotsConfigures.forEach(lot => {
            const nbLots = Math.floor(lot.qte / lot.produitsParLot);
            const reste = lot.qte % lot.produitsParLot;
            const resultText = reste > 0 ? `${nbLots} lot(s) et ${reste} produit(s)` : `${nbLots} lot(s)`;
            html += `<div style="font-size:10px;margin-bottom:4px;">
                ${esc(lot.nom)}: ${lot.qte} pcs / ${lot.produitsParLot} par lot = <strong>${resultText}</strong>
            </div>`;
        });
        const totalLots = lotsConfigures.reduce((sum, l) => sum + Math.floor(l.qte / l.produitsParLot), 0);
        html += `<div style="font-weight:700;margin-top:8px;">TOTAL: ${totalLots} lot(s)</div>`;
    }

    html += `<hr style="border:1px dashed #ccc;margin-top:8px;">
        <div style="text-align:center;font-size:10px;color:#94a3b8;margin-top:8px;">Merci !</div>
    </div>`;

    gid('printZone').innerHTML = html;
}

function resetSale() {
    cart = [];
    renderCart();
    loadProducts();
    gid('taxRate').value = '0';
    gid('discountRate').value = '0';
    calculateTotals();
}

// Initialisation
jQuery(document).ready(function() {
    jQuery('.selectpicker').selectpicker();
    loadProducts();
});
</script>
</body>
</html>