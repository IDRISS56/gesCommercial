<?php
// vente_comptoir.php – Caisse - Vente Comptoir
while (ob_get_level()) ob_end_clean();
ob_start();

// - DÉTECTION PRÉCOCE DES REQUÊTES AJAX -
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$isAjax = $isAjax || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

// - SESSION : protection si déjà démarrée -
// if (session_status() === PHP_SESSION_NONE) {
// session_start();
// }
// if ($isAjax && !isset($_SESSION['user_id'])) {
// header('Content-Type: application/json; charset=utf-8');
// echo json_encode(['success' => false, 'message' => 'Session expirée']);
// exit;
// }

// if (!isset($_SESSION['user_id'])) {
// if ($isAjax) {
// header('Content-Type: application/json; charset=utf-8');
// echo json_encode(['success' => false, 'message' => 'Session expirée']);
// exit;
// }
// header('Location: ../utilisateur/login');
// exit;
// }

$host = 'localhost';
$dbname = 'gescommercial';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

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
$role = $user['role'] ?? '';

// - BOUTIQUE ACTIVE -
// Le superviseur peut superviser plusieurs boutiques : par défaut on prend la
// dernière choisie (ou la première active), et un selectpicker dans l'en-tête
// lui permet de changer à tout moment. Les autres rôles restent sur leur
// boutique assignée.
$boutiques = [];
if ($role === 'Superviseur') {
    $boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique")->fetchAll(PDO::FETCH_ASSOC);
    $validBoutiqueIds = array_column($boutiques, 'code_boutique');

    if (!empty($_GET['choisir_boutique']) && in_array($_GET['choisir_boutique'], $validBoutiqueIds)) {
        $_SESSION['boutique_choisie_superviseur'] = $_GET['choisir_boutique'];
    }

    $boutiqueChoisie = $_SESSION['boutique_choisie_superviseur'] ?? null;
    if ($boutiqueChoisie && in_array($boutiqueChoisie, $validBoutiqueIds)) {
        $boutiqueActive = $boutiqueChoisie;
    } elseif (!empty($boutiques)) {
        $boutiqueActive = $boutiques[0]['code_boutique'];
    } else {
        $boutiqueActive = $user['boutique_id'] ?? null;
    }
    $_SESSION['boutique_choisie_superviseur'] = $boutiqueActive;

    define('USER_BOUTIQUE', $boutiqueActive);
} else {
    define('USER_BOUTIQUE', $user['boutique_id'] ?? null);
}
$caisseActive = null;
$needCaisseChoice = false;
$caissesDisponibles = [];

if ($role === 'Superviseur') {
    // Le superviseur voit toutes les caisses ouvertes de sa boutique (ou de tout le système à défaut) :
    // une caisse ne compte comme "ouverte" que si elle a réellement une journée en cours (jc.statut = 'OUVERTE'),
    // pas seulement d'après le statut statique de la caisse (qui ne reflète pas toujours la fermeture du jour).
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
    // Tous les autres rôles (caissier, etc.) : uniquement la caisse qui leur a été autorisée,
    // c'est-à-dire la caisse dont ils ont eux-mêmes ouvert la journée en cours.
    $stmt = $pdo->prepare("SELECT c.caisse_id, c.nom_caisse
                           FROM caisse c
                           INNER JOIN journees_caisse jc ON jc.caisse_id = c.caisse_id AND jc.statut = 'OUVERTE'
                           WHERE c.statut = 'Actif' AND jc.id_utilisateur_ouverture = ?
                           ORDER BY jc.date_ouverture DESC LIMIT 1");
    $stmt->execute([USER_ID]);
    $caisseActive = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$caisseActive) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => "Aucune caisse ne vous a été autorisée. Ouvrez d'abord votre caisse."]);
            exit;
        }
        echo '<script>alert("Aucune caisse ne vous a été autorisée. Veuillez ouvrir votre caisse avant de vendre.");document.location.replace("../caisse/journee");</script>';
        exit;
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

if (!$caisseActive) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => "Aucune caisse ouverte."]);
        exit;
    }
    echo '<script>alert("Aucune caisse ouverte.");document.location.replace("../caisse/journee");</script>';
    exit;
}
define('CAISSE_ID', $caisseActive['caisse_id']);

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
                $sql = "SELECT p.code_produit, p.titre_produit, p.stock_produit, p.prix_fournisseur,
                        p.categorie_id, p.etat_produit,
                        COALESCE(sb.quantite, CAST(p.stock_produit AS SIGNED)) as stock,
                        COALESCE(c.titre_categorie, 'Autre') as categorie
                        FROM produit p
                        LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
                        LEFT JOIN stock sb ON sb.produit_id = p.code_produit AND sb.boutique_id = ?
                        WHERE p.etat_produit != 'RUPTURE'";
                $params = [USER_BOUTIQUE];
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

                // Chargement des lots pour chaque produit (table `lot`)
                foreach ($products as &$p) {
                    $stmtLots = $pdo->prepare("SELECT code_lot, libelle, quantite, unites_par_lot
                                               FROM lot
                                               WHERE produit_id = ? AND etat_lot = 'Actif' AND quantite > 0");
                    $stmtLots->execute([$p['code_produit']]);
                    $lots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

                    // Transformation pour correspondre à l'attente du JS
                    $p['lots'] = array_map(function($lot) {
                        return [
                            'code_lot_produit' => $lot['code_lot'],
                            'titre_lot' => $lot['libelle'],
                            'quantite' => $lot['quantite'],
                            'unites_par_lot' => max(1, intval($lot['unites_par_lot'] ?? 1))
                        ];
                    }, $lots);
                }
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
                $stmt = $pdo->prepare("INSERT INTO contact (code_contact, nom_prenom_contact, telephone_contact, email_contact, type_contact, statut_contact, etat_contact) VALUES (?, ?, ?, ?, 'Client', 'Particulier', 'Actif')");
                $stmt->execute([$numClient, $nom, $data['tel'] ?? '', $data['email'] ?? '']);
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

                $panier = json_decode($data['panier'] ?? '[]', true) ?: [];
                if (empty($panier)) throw new Exception('Le panier est vide.');

                $client_id = $data['client_id'] ?? null;
                if (empty($client_id)) throw new Exception('Veuillez sélectionner un client.');

                $payment_mode = $data['mode_reglement'] ?? 'Espece';
                // Mapper les modes de paiement
                $modeMap = [
                    'Espece' => 'Espèce',
                    'Mobile' => 'Mobile money',
                    'Cheque' => 'Chèque',
                    'Carte' => 'Carte',
                    'Virement' => 'Virement',
                    'Autres' => 'Autres'
                ];
                $mode_reglement = $modeMap[$payment_mode] ?? 'Espèce';

                $amount_paid = floatval($data['avance'] ?? 0);
                $tax_rate = floatval($data['taux_tva'] ?? 0);
                $discount_rate = floatval($data['taux_remise'] ?? 0);
                $is_attente = filter_var($data['en_attente'] ?? false, FILTER_VALIDATE_BOOLEAN);

                // Récupération des données de lots
                $lotsData = json_decode($data['lots'] ?? '[]', true) ?: [];

                $montantHT = 0;
                foreach ($panier as $item) {
                    $montantHT += floatval($item['montant'] ?? ($item['prix'] * $item['qte']));
                }
                $taxe = round($montantHT * $tax_rate / 100, 2);
                $remise = round($montantHT * $discount_rate / 100, 2);
                $montantTTC = round($montantHT + $taxe - $remise, 2);

                // ===== LOGIQUE DE STATUT ET TYPE DE DOCUMENT =====
                if ($is_attente) {
                    $avance = 0;
                    $reste = $montantTTC;
                    $etatFacture = 'Impayee';
                    $statutFacture = 'En attente';
                    $categorieDocument = 'Bon';
                } else {
                    $avance = min($amount_paid, $montantTTC);
                    $reste = round($montantTTC - $avance, 2);
                    if ($reste < 0) $reste = 0;
                    
                    if ($avance > 0) {
                        // Paiement (partiel ou intégral) → Facture validée
                        $categorieDocument = 'Facture';
                        $statutFacture = 'Validee';
                        $etatFacture = ($reste > 0) ? 'Partielle' : 'Payee';
                    } else {
                        // Aucun paiement → Bon en attente
                        $categorieDocument = 'Bon';
                        $etatFacture = 'Impayee';
                        $statutFacture = 'En attente';
                    }
                }

                $titreDocument = ($categorieDocument === 'Facture') ? 'Facture client' : 'Bon';

                // Numéros uniques pour chaque document
                $numDocument = ($categorieDocument === 'Facture')
                    ? 'FAC-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT)
                    : 'BON-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
                $numBL = 'BL-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

                $pdo->beginTransaction();
                try {
                    // 1. DOCUMENT PRINCIPAL (Facture ou Bon)
                    $stmtDoc = $pdo->prepare("INSERT INTO facture(numero_facture, titre_facture, type_facture, categorie_facture, date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, contact_id, utilisateur_id, etat_facture, statut_facture)
                                           VALUES (?, ?, 'Client', ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtDoc->execute([$numDocument, $titreDocument, $categorieDocument,
                                    $montantHT, $taxe, $remise, $montantTTC, $avance, $reste,
                                    $client_id, USER_ID, $etatFacture, $statutFacture]);

                    // 2. BON DE LIVRAISON (table dédiée `bon_livraison`, toujours en attente)
                    $stmtBL = $pdo->prepare("INSERT INTO bon_livraison(code_bon, date_livraison, facture_id, adresse_livraison, transporteur, statut, commentaire)
                                             VALUES (?, CURDATE(), ?, NULL, NULL, 'En attente', NULL)");
                    $stmtBL->execute([$numBL, $numDocument]);

                    // 3. LIGNES DE COMMANDE pour le document principal ET le bon de livraison
                    $numBase = date('dmYHis');
                    foreach ($panier as $i => $ligne) {
                        $numCmd = $numBase . str_pad($i, 2, '0', STR_PAD_LEFT);
                        $prix = floatval($ligne['prix'] ?? 0);
                        $qte = intval($ligne['qte'] ?? 1);
                        $montant = floatval($ligne['montant'] ?? ($prix * $qte));
                        $prix_achat = floatval($ligne['prix_achat'] ?? $prix);
                        $code_prod = $ligne['code'] ?? $ligne['product_id'];
                        $lot_id = $ligne['lot_id'] ?? null;
                        $produits_par_lot = max(0, intval($ligne['produits_par_lot'] ?? 0));

                        // Ligne pour le document principal
                        $stmtCmd = $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                                                  VALUES (?, ?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, 'VALIDEE')");
                        $stmtCmd->execute([$numCmd . '-DOC', $code_prod, $lot_id, $client_id, $numDocument,
                                           $prix_achat, $prix, $qte, $produits_par_lot, $montant, USER_ID, USER_BOUTIQUE]);

                        // Ligne pour le bon de livraison (toujours en attente)
                        $stmtCmdBL = $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                                                  VALUES (?, ?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, 'EN ATTENTE')");
                        $stmtCmdBL->execute([$numCmd . '-BL', $code_prod, $lot_id, $client_id, $numBL,
                                           $prix_achat, $prix, $qte, $produits_par_lot, $montant, USER_ID, USER_BOUTIQUE]);

                        // Mise à jour stock boutique
                        if (!empty(USER_BOUTIQUE)) {
                            $pdo->prepare("UPDATE stock SET quantite = GREATEST(0, quantite - ?) WHERE produit_id = ? AND boutique_id = ?")
                                ->execute([$qte, $code_prod, USER_BOUTIQUE]);
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

                        // Gestion des lots (table `lot`)
                        if ($lot_id) {
                            $pdo->prepare("UPDATE lot SET quantite = quantite - ? WHERE code_lot = ? AND quantite >= ?")
                                ->execute([$qte, $lot_id, $qte]);
                            $pdo->prepare("UPDATE lot SET etat_lot = 'Inactif' WHERE code_lot = ? AND quantite <= 0")
                                ->execute([$lot_id]);
                        }
                    }

                    // 4. TRANSACTION CAISSE (si paiement effectué)
                    if (!$is_attente && $avance > 0) {
                        $stmtSolde = $pdo->prepare("SELECT solde, statut FROM caisse WHERE caisse_id = ? FOR UPDATE");
                        $stmtSolde->execute([CAISSE_ID]);
                        $caisseRow = $stmtSolde->fetch(PDO::FETCH_ASSOC);
                        if ($caisseRow === false) {
                            throw new Exception("Caisse introuvable (caisse_id='" . CAISSE_ID . "') : le paiement n'a pas pu être lié à une caisse.");
                        }
                        if ($caisseRow['statut'] !== 'Actif') {
                            throw new Exception("Cette caisse est inactive : impossible d'enregistrer un paiement dessus. Réactivez la caisse avant de continuer.");
                        }
                        // Le statut de la caisse ne suffit pas : on vérifie qu'une journée est
                        // réellement en cours (la fermeture de journée ne modifie pas ce statut).
                        $stmtJC = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'OUVERTE'");
                        $stmtJC->execute([CAISSE_ID]);
                        if ($stmtJC->fetchColumn() == 0) {
                            throw new Exception("Aucune journée de caisse n'est ouverte pour cette caisse : impossible d'enregistrer un paiement. Ouvrez la caisse avant de continuer.");
                        }
                        $soldeAvant = floatval($caisseRow['solde']);
                        $soldeApres = $soldeAvant + $avance;
                        $numTrans = 'TR-' . date('YmdHis') . rand(100, 999);

                        $stmtTr = $pdo->prepare("INSERT INTO transaction
                            (numero_transaction, date_transaction, heure_transaction, montant_transaction, frais_transaction, montant_total, type_transaction, objet_transaction, caisse_id, facture_id, mode_reglement, utilisateur_id, etat_transaction)
                            VALUES (?, CURDATE(), CURTIME(), ?, 0, ?, 'Entree', 'Vente comptoir', ?, ?, ?, ?, 'Succes')");
                        $stmtTr->execute([$numTrans, $avance, $avance, CAISSE_ID, $numDocument, $mode_reglement, USER_ID]);

                        $stmtMaj = $pdo->prepare("UPDATE caisse SET solde = ? WHERE caisse_id = ? AND statut = 'Actif'");
                        $stmtMaj->execute([$soldeApres, CAISSE_ID]);
                        if ($stmtMaj->rowCount() === 0) {
                            throw new Exception("La mise à jour du solde de la caisse (caisse_id='" . CAISSE_ID . "') n'a affecté aucune ligne (caisse fermée entre-temps ?).");
                        }
                    }

                    $pdo->commit();
                    echo json_encode([
                        'success' => true,
                        'document' => $numDocument,
                        'type_document' => $categorieDocument,
                        'bon_livraison' => $numBL,
                        'reste' => $reste,
                        'etat' => $etatFacture,
                        'statut' => $statutFacture,
                        'en_attente' => $is_attente,
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

$stmtCaisseInfo = $pdo->prepare("SELECT * FROM caisse WHERE caisse_id = ?");
$stmtCaisseInfo->execute([CAISSE_ID]);
$caisse = $stmtCaisseInfo->fetch();

$userInfo = $pdo->query("SELECT * FROM utilisateur WHERE id = '" . USER_ID . "'")->fetch();
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
                <?php if ($role === 'Superviseur' && count($boutiques) > 1): ?>
                    <select id="boutiqueSelect" class="form-select form-select-sm" style="width:auto;display:inline-block;" onchange="window.location.href='?choisir_boutique='+encodeURIComponent(this.value)">
                        <?php foreach ($boutiques as $b): ?>
                            <option value="<?= htmlspecialchars($b['code_boutique']) ?>" <?= $b['code_boutique'] === USER_BOUTIQUE ? 'selected' : '' ?>><?= htmlspecialchars($b['nom_boutique']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <span class="text-muted small"><i class="bi bi-person"></i> <?= htmlspecialchars($userInfo['nom_prenom'] ?? '') ?></span>
                <span class="badge bg-success-subtle text-success"><i class="bi bi-cash-stack"></i> Caisse ouverte</span>
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
            <div id="clientDisplay" style="display:none;">
                <div class="client-display">
                    <div>
                        <div class="cl-name" id="clientName"></div>
                        <div class="cl-code" id="clientCode"></div>
                    </div>
                    <button class="btn-change" onclick="resetClient()">Changer</button>
                </div>
            </div>
            <div id="clientSelectWrapper">
                <label class="form-label" style="font-size:11px;font-weight:600;color:var(--color-gray-500);text-transform:uppercase;">Client</label>
                <select id="clientSelect" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un client...">
                    <option value="">-- Sélectionner un client --</option>
                </select>
                <button class="btn btn-sm btn-outline-primary mt-2 w-100" onclick="openModal('clientModal')">
                    <i class="bi bi-plus"></i> Nouveau client
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
                <button class="btn-attente" id="btnAttente" disabled><i class="bi bi-clock"></i> En attente</button>
                <button class="btn-pay" id="btnCheckout" disabled><i class="bi bi-credit-card-fill"></i> Encaisser</button>
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
                    <button class="pay-mode active" data-mode="Espece" onclick="selectPayMode(this)">
                        <i class="bi bi-cash"></i><span>Espèces</span>
                    </button>
                    <button class="pay-mode" data-mode="Mobile" onclick="selectPayMode(this)">
                        <i class="bi bi-phone"></i><span>Mobile</span>
                    </button>
                    <button class="pay-mode" data-mode="Cheque" onclick="selectPayMode(this)">
                        <i class="bi bi-receipt"></i><span>Chèque</span>
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

            <!-- SECTION LOTS -->
            <div class="lots-section">
                <div class="lots-title">
                    <i class="bi bi-box-seam"></i> Configuration des lots (Bon de livraison)
                </div>
                <div id="lotsContainer"></div>
                <div class="lots-total">
                    <span class="label">Nombre total de lots :</span>
                    <span class="value" id="totalLots">0</span>
                </div>
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

<!-- Modal En attente (avec configuration des lots) -->
<div class="modal-overlay" id="attenteModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="bi bi-clock"></i> Mise en attente</h3>
            <button class="modal-close" onclick="closeModal('attenteModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <div class="mt-3 p-3 rounded" style="background:var(--color-gray-50);font-size:12px;">
                Vérifiez le nombre de produits par lot avant de mettre la commande en attente : c'est cette valeur qui déterminera le nombre de cartons à préparer pour la livraison.
            </div>

            <!-- SECTION LOTS -->
            <div class="lots-section">
                <div class="lots-title">
                    <i class="bi bi-box-seam"></i> Configuration des lots (Bon de livraison)
                </div>
                <div id="lotsContainerAttente"></div>
                <div class="lots-total">
                    <span class="label">Nombre total de lots :</span>
                    <span class="value" id="totalLotsAttente">0</span>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal('attenteModal')">Annuler</button>
            <button class="btn btn-primary" id="btnConfirmerAttente" onclick="confirmerAttente()"><i class="bi bi-check-lg"></i> Confirmer la mise en attente</button>
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
                    <label>Nom complet *</label>
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
    <div class="modal-box" style="width:400px;">
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

<!-- Toast -->
<div class="toast-msg" id="toastMsg"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
const BASE_URL = window.location.pathname;
const CSRF_TOKEN = '<?= $csrf_token ?>';
let cart = [];
let selectedClient = null;
let payMode = 'Espece';
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

// Charger clients pour selectpicker
async function loadClients() {
    try {
        const res = await api('get_clients', { q: '' });
        if (res.success) {
            const select = gid('clientSelect');
            select.innerHTML = '<option value="">-- Sélectionner un client --</option>';
            res.clients.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.code_contact;
                opt.textContent = c.nom_prenom_contact + (c.telephone_contact ? ' (' + c.telephone_contact + ')' : '');
                opt.dataset.name = c.nom_prenom_contact;
                opt.dataset.code = c.code_contact;
                select.appendChild(opt);
            });
            jQuery('.selectpicker').selectpicker('refresh');
        }
    } catch (e) { console.error(e); }
}

// Recherche clients dans selectpicker
jQuery('#clientSelect').on('changed.bs.select', function(e, clickedIndex) {
    const opt = this.options[clickedIndex];
    if (opt && opt.value) {
        selectedClient = { code: opt.value, name: opt.dataset.name };
        gid('clientDisplay').style.display = 'block';
        gid('clientSelectWrapper').style.display = 'none';
        gid('clientName').textContent = selectedClient.name;
        gid('clientCode').textContent = selectedClient.code;
        updateButtons();
    }
});

function resetClient() {
    selectedClient = null;
    gid('clientDisplay').style.display = 'none';
    gid('clientSelectWrapper').style.display = 'block';
    gid('clientSelect').selectedIndex = 0;
    jQuery('.selectpicker').selectpicker('refresh');
    updateButtons();
}

// Charger produits
async function loadProducts(q = '') {
    try {
        const res = await api('get_products', { q, categorie: currentCategory });
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
            <div class="pc-price">${fmt(p.prix_fournisseur || 0)}</div>
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
        const prix = parseFloat(p.prix_fournisseur) || 0;
        const premierLot = (p.lots && p.lots.length > 0) ? p.lots[0] : null;
        cart.push({
            code: p.code_produit,
            nom: p.titre_produit,
            prix: prix,
            prix_achat: parseFloat(p.prix_fournisseur) || 0,
            qte: 1,
            stock: stock,
            montant: prix,
            lot_id: premierLot ? premierLot.code_lot_produit : null,
            produitsParLot: premierLot ? (parseInt(premierLot.unites_par_lot) || 0) : 0
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
        gid('btnAttente').disabled = true;
    } else {
        let html = '';
        cart.forEach(p => {
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
            </div>`;
        });
        gid('cartItems').innerHTML = html;
        gid('cartCount').textContent = cart.reduce((s, p) => s + p.qte, 0);
        gid('btnCheckout').disabled = !selectedClient;
        gid('btnAttente').disabled = !selectedClient;
    }
    calculateTotals();
}

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
    const hasItems = cart.length > 0;
    gid('btnCheckout').disabled = !(hasItems && selectedClient);
    gid('btnAttente').disabled = !(hasItems && selectedClient);
}

gid('btnClear').addEventListener('click', function() {
    if (cart.length === 0) return;
    if (confirm('Vider le panier ?')) {
        cart = [];
        renderCart();
        renderProducts(currentProducts, gid('searchInput').value.trim());
        toast('Panier vidé');
    }
});

// ===== GESTION DES LOTS =====
// containerId/totalId permettent de réutiliser cet écran depuis le modal
// de paiement (payModal) OU le modal de mise en attente (attenteModal).
let lotsRenderTarget = { containerId: 'lotsContainer', totalId: 'totalLots' };

function renderLotsConfig(containerId, totalId) {
    if (containerId) lotsRenderTarget = { containerId, totalId };
    const container = gid(lotsRenderTarget.containerId);
    const totalEl = gid(lotsRenderTarget.totalId);
    if (cart.length === 0) {
        container.innerHTML = '<div style="color: #64748b; font-style: italic; font-size: 12px;">Aucun produit dans le panier</div>';
        totalEl.textContent = '0';
        return;
    }

    let html = '';
    let totalLots = 0;

    cart.forEach((item, idx) => {
        const produitsParLot = item.produitsParLot ?? 0;
        const nombreLots = produitsParLot > 0 ? Math.floor(item.qte / produitsParLot) : 0;
        const reste = produitsParLot > 0 ? (item.qte % produitsParLot) : item.qte;
        totalLots += nombreLots;

        let resultText;
        if (produitsParLot === 0) {
            resultText = 'Non configuré';
        } else {
            resultText = `${nombreLots} lot(s)`;
            if (reste > 0) resultText += ` et ${reste} produit(s)`;
        }

        html += `
            <div class="lot-item">
                <div class="lot-info">
                    <div class="lot-name">${esc(item.nom)}</div>
                    <div class="lot-qty">Quantité: ${item.qte}</div>
                </div>
                <div class="lot-input-group">
                    <label>Produits/lot:</label>
                    <input type="number"
                           min="0"
                           max="${item.qte}"
                           value="${produitsParLot}"
                           onchange="updateProduitsParLot(${idx}, this.value)"
                           onclick="event.stopPropagation()">
                </div>
                <div class="lot-result">${resultText}</div>
            </div>
        `;
    });

    container.innerHTML = html;
    totalEl.textContent = totalLots;
}

function updateProduitsParLot(idx, value) {
    const produitsParLot = Math.max(0, parseInt(value) || 0);
    cart[idx].produitsParLot = produitsParLot;
    renderLotsConfig();
}

// Encaisser
gid('btnCheckout').addEventListener('click', function() {
    if (!selectedClient) { toast('Sélectionnez un client', 'error'); return; }
    if (cart.length === 0) { toast('Panier vide', 'error'); return; }

    // produitsParLot reste à 0 (non configuré) tant que l'utilisateur ne le renseigne pas
    gid('payAmount').textContent = fmt(getTTC());
    gid('receivedAmount').value = '';
    gid('changeAmount').textContent = '0 FCFA';
    gid('changeBox').className = 'change-box';
    gid('changeLbl').textContent = 'Monnaie à rendre';
    gid('etatPreview').textContent = '-';
    gid('restePreview').textContent = '0 FCFA';

    // Afficher la configuration des lots
    renderLotsConfig('lotsContainer', 'totalLots');

    openModal('payModal');
});

// En attente
gid('btnAttente').addEventListener('click', function() {
    if (!selectedClient) { toast('Sélectionnez un client', 'error'); return; }
    if (cart.length === 0) { toast('Panier vide', 'error'); return; }

    // produitsParLot reste à 0 (non configuré) tant que l'utilisateur ne le renseigne pas
    renderLotsConfig('lotsContainerAttente', 'totalLotsAttente');
    openModal('attenteModal');
});

function confirmerAttente() {
    closeModal('attenteModal');
    validateSale(true, 0);
}

function selectPayMode(btn) {
    document.querySelectorAll('.pay-mode').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    payMode = btn.dataset.mode;
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
    validateSale(false, received);
}

async function validateSale(enAttente = false, avance = 0) {
    const btn = enAttente ? gid('btnAttente') : gid('btnValidatePay');
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-spinner"></i> Validation...';

    try {
        // Préparer les données de lots
        const lotsData = cart.map(item => {
            const ppl = item.produitsParLot ?? 0;
            return {
                code: item.code,
                nom: item.nom,
                qte: item.qte,
                produitsParLot: ppl,
                nombreLots: ppl > 0 ? Math.floor(item.qte / ppl) : 0
            };
        });

        const data = {
            panier: JSON.stringify(cart.map(p => ({
                code: p.code,
                prix: p.prix,
                prix_achat: p.prix_achat,
                qte: p.qte,
                montant: p.montant,
                lot_id: p.lot_id || null,
                produits_par_lot: p.produitsParLot ?? 0
            }))),
            client_id: selectedClient.code,
            mode_reglement: payMode,
            avance: enAttente ? 0 : avance,
            taux_tva: parseFloat(gid('taxRate').value) || 0,
            taux_remise: parseFloat(gid('discountRate').value) || 0,
            en_attente: enAttente,
            csrf_token: CSRF_TOKEN,
            lots: JSON.stringify(lotsData)
        };

        const res = await api('valider_vente', data);

        btn.disabled = false;
        btn.innerHTML = originalText;

        if (res.success) {
            closeModal('payModal');
            if (enAttente) {
                toast('Commande mise en attente : ' + res.document);
            } else {
                toast('Vente validée ! ' + res.type_document + ': ' + res.document + ' (' + res.etat + ')');
                generateTicket(res);
                openModal('ticketModal');
            }
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
        <div style="font-size:11px;margin-bottom:8px;">Client: ${esc(selectedClient.name)} (${selectedClient.code})</div>
        <div style="font-size:11px;margin-bottom:12px;">${res.type_document}: ${res.document}</div>
        <div style="font-size:11px;margin-bottom:12px;">Bon livraison: ${res.bon_livraison}</div>
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

    // Section des lots
    if (res.lots && res.lots.length > 0) {
        html += `<hr style="border:1px dashed #ccc;margin-top:8px;">
            <div style="font-weight:700;margin:8px 0;">CONFIGURATION DES LOTS</div>`;
        res.lots.forEach(lot => {
            const ppl = lot.produitsParLot || 0;
            const nbLots = ppl > 0 ? Math.floor(lot.qte / ppl) : 0;
            const reste = ppl > 0 ? (lot.qte % ppl) : lot.qte;
            const resultText = ppl === 0 ? 'Non configuré' : (reste > 0 ? `${nbLots} lot(s) et ${reste} produit(s)` : `${nbLots} lot(s)`);
            html += `<div style="font-size:10px;margin-bottom:4px;">
                ${esc(lot.nom)}: ${lot.qte} pcs / ${ppl} par lot = <strong>${resultText}</strong>
            </div>`;
        });
        const totalLots = res.lots.reduce((sum, l) => sum + ((l.produitsParLot || 0) > 0 ? Math.floor(l.qte / l.produitsParLot) : 0), 0);
        html += `<div style="font-weight:700;margin-top:8px;">TOTAL: ${totalLots} lot(s)</div>`;
    }

    html += `<hr style="border:1px dashed #ccc;margin-top:8px;">
        <div style="text-align:center;font-size:10px;color:#94a3b8;margin-top:8px;">Merci !</div>
    </div>`;

    gid('printZone').innerHTML = html;
}

function resetSale() {
    cart = [];
    selectedClient = null;
    renderCart();
    resetClient();
    loadProducts();
    gid('taxRate').value = '0';
    gid('discountRate').value = '0';
    calculateTotals();
}

async function createClient() {
    const nom = gid('clientNom').value.trim();
    if (!nom) { toast('Nom requis', 'error'); return; }
    try {
        const res = await api('create_customer', {
            nom,
            tel: gid('clientTel').value,
            email: gid('clientEmail').value,
            csrf_token: CSRF_TOKEN
        });
        if (res.success) {
            closeModal('clientModal');
            gid('clientForm').reset();
            await loadClients();
            setTimeout(() => {
                const select = gid('clientSelect');
                for (let i = 0; i < select.options.length; i++) {
                    if (select.options[i].value === res.code) {
                        select.selectedIndex = i;
                        jQuery('.selectpicker').selectpicker('refresh');
                        selectedClient = { code: res.code, name: res.nom };
                        gid('clientDisplay').style.display = 'block';
                        gid('clientSelectWrapper').style.display = 'none';
                        gid('clientName').textContent = res.nom;
                        gid('clientCode').textContent = res.code;
                        updateButtons();
                        break;
                    }
                }
            }, 300);
            toast('Client créé');
        } else {
            toast(res.message || 'Erreur', 'error');
        }
    } catch (e) { toast('Erreur connexion', 'error'); }
}

// Initialisation
jQuery(document).ready(function() {
    loadClients();
    loadProducts();
});
</script>
</body>
</html>