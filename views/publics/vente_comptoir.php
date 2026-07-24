<?php
// vc.php – Caisse - Vente Comptoir (refonte complète avec ticket PDF)

// Nettoyage du buffer
while (ob_get_level()) ob_end_clean();
ob_start();

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require_once 'databases/database.php';
require_once 'librairies/fpdf/fpdf.php';

// Vérification de l'utilisateur
$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

define('USER_ID', $_SESSION['user_id']);
define('USER_BOUTIQUE', $user['boutique_id'] ?? null);

// Récupération de la caisse active
$stmt = $pdo->query("SELECT code_caisse FROM caisse WHERE etat_caisse = 'Actif' LIMIT 1");
$caisse = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$caisse) {
    $stmt = $pdo->query("SELECT code_caisse FROM caisse LIMIT 1");
    $caisse = $stmt->fetch(PDO::FETCH_ASSOC);
}
define('CAISSE_ID', $caisse['code_caisse'] ?? '1');

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---- FONCTIONS UTILITAIRES ----
function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}
function safeText($str)
{
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str);
}
function columnExists($pdo, $table, $col)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
        return $stmt && $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Vérification des colonnes
$produit_has_categorie = columnExists($pdo, 'produit', 'categorie_produit');
$contact_has_email = columnExists($pdo, 'contact', 'email_contact');
$contact_has_type = columnExists($pdo, 'contact', 'type_contact');
$contact_has_statut = columnExists($pdo, 'contact', 'statut_contact');

// Ajout colonne quantite dans lot_produit si absente
try {
    $pdo->exec("ALTER TABLE lot_produit ADD COLUMN quantite INT NOT NULL DEFAULT 0");
} catch (PDOException $e) {
}

// ---- TRAITEMENT DES ACTIONS AJAX ----
$input = json_decode(file_get_contents('php://input'), true);
if ($input) {
    $_REQUEST = array_merge($_REQUEST, $input);
    $isAjax = true;
    $action = $input['action'] ?? '';
} else {
    $isAjax = isset($_REQUEST['ajax']) && $_REQUEST['ajax'] == '1';
    $action = $_REQUEST['action'] ?? '';
}

if ($isAjax && $action) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        switch ($action) {
            case 'load_products':
            case 'search_products':
                $q = trim($_REQUEST['q'] ?? '');
                $categorie = trim($_REQUEST['categorie'] ?? '');
                $isSearch = ($action === 'search_products');

                $catSelect = $produit_has_categorie ? ", COALESCE(p.categorie_produit, 'Autre') as categorie" : ", 'Autre' as categorie";
                $params = [];
                $sql = "SELECT p.code_produit, p.titre_produit, 
                               COALESCE(p.stock_produit, 0) as stock,
                               CAST(p.prix_produit AS DECIMAL(10,2)) as prix,
                               CAST(p.prix_fournisseur AS DECIMAL(10,2)) as prix_fournisseur
                               $catSelect
                        FROM produit p
                        WHERE p.etat_produit = 'Actif'";
                if ($isSearch && $q !== '') {
                    $sql .= " AND (p.titre_produit LIKE ? OR p.code_produit LIKE ?)";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                }
                if ($categorie && $categorie !== 'Tous' && $produit_has_categorie) {
                    $sql .= " AND p.categorie_produit = ?";
                    $params[] = $categorie;
                }
                $sql .= " ORDER BY p.titre_produit ASC LIMIT 80";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($produits as &$prod) {
                    $stmtLots = $pdo->prepare("SELECT code_lot_produit, titre_lot, unites_par_lot, quantite 
                                               FROM lot_produit 
                                               WHERE produit_id = ? AND etat_lot = 'Actif' AND quantite > 0");
                    $stmtLots->execute([$prod['code_produit']]);
                    $prod['lots'] = $stmtLots->fetchAll(PDO::FETCH_ASSOC);
                }
                echo json_encode(['success' => true, 'data' => $produits]);
                exit;

            case 'load_categories':
                if ($produit_has_categorie) {
                    $cats = $pdo->query("SELECT DISTINCT COALESCE(categorie_produit,'Autre') as cat 
                                         FROM produit WHERE etat_produit='Actif' 
                                         ORDER BY cat ASC")->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $cats = [];
                }
                echo json_encode(['success' => true, 'data' => $cats, 'has_categorie' => $produit_has_categorie]);
                exit;

            case 'search_customers':
                $q = trim($_REQUEST['q'] ?? '');
                if ($q === '') {
                    echo json_encode(['success' => true, 'data' => []]);
                    exit;
                }
                $emailSelect = $contact_has_email ? ", c.email_contact" : ", '' as email_contact";
                $typeSelect = $contact_has_type ? ", COALESCE(c.type_contact,'Client') as type_contact" : ", 'Client' as type_contact";
                $statutSelect = $contact_has_statut ? ", COALESCE(c.statut_contact,'Particulier') as statut_contact" : ", 'Particulier' as statut_contact";
                $emailSearch = $contact_has_email ? " OR c.email_contact LIKE ?" : "";
                $emailParams = $contact_has_email ? ['%' . $q . '%'] : [];

                $sql = "SELECT c.code_contact, c.nom_prenom_contact, 
                               c.telephone_contact
                               $emailSelect $typeSelect $statutSelect
                        FROM contact c 
                        WHERE c.etat_contact = 'Actif' 
                          AND (c.nom_prenom_contact LIKE ? 
                               OR c.code_contact LIKE ? 
                               OR c.telephone_contact LIKE ?
                               $emailSearch)
                        ORDER BY 
                          CASE WHEN c.code_contact = ? THEN 0
                               WHEN c.nom_prenom_contact LIKE ? THEN 1
                               ELSE 2 END,
                          c.nom_prenom_contact ASC LIMIT 15";

                $params = array_merge(['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'], $emailParams, [$q, "$q%"]);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                exit;

            case 'load_recent_customers':
                $emailSelect = $contact_has_email ? ", c.email_contact" : ", '' as email_contact";
                $typeSelect = $contact_has_type ? ", COALESCE(c.type_contact,'Client') as type_contact" : ", 'Client' as type_contact";
                $statutSelect = $contact_has_statut ? ", COALESCE(c.statut_contact,'Particulier') as statut_contact" : ", 'Particulier' as statut_contact";
                $typeWhere = $contact_has_type ? " AND c.type_contact = 'Client'" : "";
                try {
                    $sql = "SELECT c.code_contact, c.nom_prenom_contact, 
                                   c.telephone_contact
                                   $emailSelect $typeSelect $statutSelect,
                                   COUNT(f.numero_facture) as nb_factures
                            FROM contact c
                            LEFT JOIN facture f ON f.contact_id = c.code_contact
                            WHERE c.etat_contact = 'Actif' $typeWhere
                            GROUP BY c.code_contact
                            ORDER BY nb_factures DESC, c.nom_prenom_contact ASC LIMIT 8";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute();
                    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                } catch (Exception $e) {
                    $sql2 = "SELECT c.code_contact, c.nom_prenom_contact, 
                                    c.telephone_contact
                                    $emailSelect $typeSelect $statutSelect, 0 as nb_factures
                             FROM contact c
                             WHERE c.etat_contact = 'Actif' $typeWhere
                             ORDER BY c.nom_prenom_contact ASC LIMIT 8";
                    $stmt2 = $pdo->prepare($sql2);
                    $stmt2->execute();
                    echo json_encode(['success' => true, 'data' => $stmt2->fetchAll()]);
                }
                exit;

            case 'get_product_price':
                $produit_id = trim($_REQUEST['produit_id'] ?? '');
                $quantite = max(1, intval($_REQUEST['quantite'] ?? 1));
                if (!$produit_id) throw new Exception('Produit requis');
                $boutique_id = USER_BOUTIQUE;
                $sql = "SELECT prix_unitaire
                        FROM prix
                        WHERE produit_id = ?
                          AND etat_prix = 'Actif'
                          AND quantite_min <= ?
                          AND (quantite_max >= ? OR quantite_max IS NULL)
                        ORDER BY CASE WHEN boutique_id = ? THEN 0 ELSE 1 END,
                                 quantite_min DESC
                        LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$produit_id, $quantite, $quantite, $boutique_id]);
                $prix = $stmt->fetchColumn();
                if ($prix === false) {
                    $stmt = $pdo->prepare("SELECT CAST(prix_produit AS DECIMAL(12,2)) FROM produit WHERE code_produit = ?");
                    $stmt->execute([$produit_id]);
                    $prix = $stmt->fetchColumn();
                    if ($prix === false) $prix = 0;
                }
                echo json_encode(['success' => true, 'prix' => floatval($prix)]);
                exit;

            case 'create_customer':
                $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
                $token = $data['csrf_token'] ?? '';
                if ($token !== $csrf_token) {
                    echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
                    exit;
                }
                $nom = trim($data['nom'] ?? '');
                if (!$nom) {
                    echo json_encode(['success' => false, 'message' => 'Le nom est obligatoire.']);
                    exit;
                }
                $code = 'CLT-' . date('YmdHis') . rand(100, 999);
                $emailVal = $contact_has_email ? ($data['email'] ?? '') : '';
                $emailCol = $contact_has_email ? ", email_contact" : "";
                $emailParam = $contact_has_email ? [$emailVal] : [];
                $typeVal = $contact_has_type ? 'Client' : '';
                $statutVal = $contact_has_statut ? 'Particulier' : '';
                $typeCol = $contact_has_type ? ", type_contact" : "";
                $statutCol = $contact_has_statut ? ", statut_contact" : "";

                $sql = "INSERT INTO contact (code_contact, nom_prenom_contact, telephone_contact $emailCol $typeCol $statutCol, adresse_contact, etat_contact) 
                        VALUES (?, ?, ? $emailCol $typeCol $statutCol, ?, 'Actif')";
                $params = array_merge(
                    [$code, $nom, $data['tel'] ?? ''],
                    $emailParam,
                    $contact_has_type ? [$typeVal] : [],
                    $contact_has_statut ? [$statutVal] : [],
                    [$data['adresse'] ?? '']
                );
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['success' => true, 'customer' => [
                    'code_contact' => $code,
                    'nom_prenom_contact' => $nom,
                    'telephone_contact' => $data['tel'] ?? '',
                    'email_contact' => $emailVal,
                    'type_contact' => 'Client',
                    'statut_contact' => 'Particulier'
                ]]);
                exit;

            case 'valider_vente':
                $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
                $token = $data['csrf_token'] ?? '';
                if ($token !== $csrf_token) {
                    echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
                    exit;
                }
                $panier = $data['panier'] ?? [];
                if (empty($panier)) throw new Exception('Le panier est vide.');
                $client_id = $data['client_id'] ?? null;
                if (empty($client_id)) throw new Exception('Veuillez sélectionner un client.');

                $payment_mode = $data['mode_reglement'] ?? 'Espece';
                $amount_paid = floatval($data['avance'] ?? 0);
                $tax_rate = floatval($data['taux_tva'] ?? 0);
                $discount_rate = floatval($data['taux_remise'] ?? 0);

                $montantHT = 0;
                foreach ($panier as $item) {
                    $montantHT += floatval($item['montant'] ?? ($item['prix'] * $item['qte']));
                }
                $taxe = round($montantHT * $tax_rate / 100, 2);
                $remise = round($montantHT * $discount_rate / 100, 2);
                $montantTTC = round($montantHT + $taxe - $remise, 2);
                $avance = min($amount_paid, $montantTTC);
                $reste = round($montantTTC - $avance, 2);
                if ($reste < 0) $reste = 0;
                $etatFacture = ($reste <= 0) ? 'Payer cash' : 'Credit';

                $numFacture = 'FAC-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("INSERT INTO facture (numero_facture, titre_facture, type_facture, categorie_facture, date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, contact_id, utilisateur_id, etat_facture) 
                                           VALUES (?, 'Vente comptoir', 'Client', 'Facture', CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$numFacture, $montantHT, $taxe, $remise, $montantTTC, $avance, $reste, $client_id, USER_ID, $etatFacture]);

                    $numBase = date('dmYHis');
                    foreach ($panier as $i => $ligne) {
                        $numCmd = $numBase . str_pad($i, 2, '0', STR_PAD_LEFT);
                        $prix = floatval($ligne['prix'] ?? 0);
                        $qte = intval($ligne['qte'] ?? 1);
                        $montant = floatval($ligne['montant'] ?? ($prix * $qte));
                        $prix_achat = floatval($ligne['prix_achat'] ?? $prix);
                        $code_prod = $ligne['code'] ?? $ligne['product_id'];
                        $lot_id = $ligne['lot_id'] ?? null;

                        $stmtCmd = $pdo->prepare("INSERT INTO commande (numero_commande, produit_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id, boutique_id, etat_commande) 
                                                  VALUES (?, ?, ?, ?, 'Vente', CURDATE(), CURTIME(), ?, ?, ?, ?, ?, NULL, 'Valider')");
                        $stmtCmd->execute([$numCmd, $code_prod, $client_id, $numFacture, $prix_achat, $prix, $qte, $montant, USER_ID]);

                        // Mise à jour du stock global
                        $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) - ? AS CHAR) WHERE code_produit = ?")
                            ->execute([$qte, $code_prod]);

                        // Gestion des lots
                        if ($lot_id) {
                            $pdo->prepare("UPDATE lot_produit SET quantite = quantite - ? WHERE code_lot_produit = ? AND quantite >= ?")
                                ->execute([$qte, $lot_id, $qte]);
                            $pdo->prepare("UPDATE lot_produit SET etat_lot = 'Inactif' WHERE code_lot_produit = ? AND quantite <= 0")
                                ->execute([$lot_id]);
                        } else {
                            $lots = $pdo->prepare("SELECT code_lot_produit, quantite FROM lot_produit WHERE produit_id = ? AND etat_lot = 'Actif' AND quantite > 0 ORDER BY code_lot_produit");
                            $lots->execute([$code_prod]);
                            $qteRestante = $qte;
                            while ($lot = $lots->fetch(PDO::FETCH_ASSOC)) {
                                if ($qteRestante <= 0) break;
                                $qteLot = min($qteRestante, $lot['quantite']);
                                $pdo->prepare("UPDATE lot_produit SET quantite = quantite - ? WHERE code_lot_produit = ?")
                                    ->execute([$qteLot, $lot['code_lot_produit']]);
                                if ($lot['quantite'] - $qteLot <= 0) {
                                    $pdo->prepare("UPDATE lot_produit SET etat_lot = 'Inactif' WHERE code_lot_produit = ?")
                                        ->execute([$lot['code_lot_produit']]);
                                }
                                $qteRestante -= $qteLot;
                            }
                        }
                    }

                    if ($avance > 0) {
                        $numTrans = 'TR-' . date('YmdHis') . rand(100, 999);
                        $stmtTr = $pdo->prepare("INSERT INTO transaction (numero_transaction, date_transaction, heure_transaction, montant_transaction, frais_transaction, montant_total, type_transaction, contact_id, facture_id, mode_reglement, valider_par, etat_transaction) 
                                                 VALUES (?, CURDATE(), CURTIME(), ?, 0, ?, 'Encaissement', ?, ?, ?, ?, 'Succes')");
                        $stmtTr->execute([$numTrans, $avance, $avance, $client_id, $numFacture, $payment_mode, USER_ID]);

                        $stmtCaisse = $pdo->prepare("UPDATE caisse SET solde_physique = CAST(CAST(COALESCE(solde_physique,0) AS DECIMAL(12,2)) + ? AS CHAR), solde_virtuel = CAST(CAST(COALESCE(solde_virtuel,0) AS DECIMAL(12,2)) + ? AS CHAR) WHERE code_caisse = ?");
                        $stmtCaisse->execute([$avance, $avance, CAISSE_ID]);
                    }

                    $pdo->commit();
                    echo json_encode(['success' => true, 'facture' => $numFacture, 'reste' => $reste, 'totaux' => ['ht' => $montantHT, 'taxe' => $taxe, 'remise' => $remise, 'ttc' => $montantTTC, 'reste' => $reste]]);
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

// ---- EXPORT PDF : TICKET ou FACTURE COMPLÈTE ----
if (isset($_POST['export_pdf']) && $_POST['export_pdf'] == '1' && !empty($_POST['numero'])) {
    error_reporting(0);
    while (ob_get_level()) ob_end_clean();

    $numero = $_POST['numero'];
    $format = $_POST['format'] ?? 'facture'; // 'ticket' ou 'facture'

    // Récupération des données de la facture
    $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact, c.adresse_contact, c.telephone_contact, c.email_contact,
        u.nom_prenom AS vendeur_nom
        FROM facture f
        LEFT JOIN contact c ON f.contact_id = c.code_contact
        LEFT JOIN utilisateur u ON f.utilisateur_id = u.id
        WHERE f.numero_facture = ?");
    $stmt->execute([$numero]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$facture) die('Facture introuvable');

    // Lignes de commande
    $stmt = $pdo->prepare("SELECT c.*, p.titre_produit
        FROM commande c
        LEFT JOIN produit p ON c.produit_id = p.code_produit
        WHERE c.facture_id = ?");
    $stmt->execute([$numero]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- SI FORMAT TICKET (design de la photo) ---
    if ($format === 'ticket') {
        $pdf = new FPDF('P', 'mm', array(80, 200)); // format ticket 80mm large
        $pdf->AddPage();
        $pdf->SetFont('Courier', '', 10); // police monospace
        $pdf->SetMargins(5, 5, 5);
        $pdf->SetAutoPageBreak(true, 5);

        // En-tête
        $pdf->SetFont('Courier', 'B', 14);
        $pdf->Cell(70, 8, 'CAISSE COMPTOIR', 0, 1, 'C');
        $pdf->SetFont('Courier', '', 10);
        $date = date('d/m/Y H:i:s');
        $pdf->Cell(70, 5, $date, 0, 1, 'C');
        $pdf->Ln(2);

        // Client et facture
        $client = $facture['nom_prenom_contact'] ?? 'Client inconnu';
        $code = $facture['contact_id'] ?? '';
        $pdf->Cell(70, 5, 'Client: ' . $client . ' (' . $code . ')', 0, 1, 'L');
        $pdf->Cell(70, 5, 'Facture: ' . $facture['numero_facture'], 0, 1, 'L');
        $pdf->Ln(2);

        // Séparateur
        $pdf->SetDrawColor(0);
        $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
        $pdf->Ln(2);

        // En-têtes tableau
        $pdf->SetFont('Courier', 'B', 9);
        $pdf->Cell(30, 5, 'Produit', 0, 0, 'L');
        $pdf->Cell(12, 5, 'Qte', 0, 0, 'C');
        $pdf->Cell(15, 5, 'Prix', 0, 0, 'R');
        $pdf->Cell(13, 5, 'Montant', 0, 1, 'R');
        $pdf->SetFont('Courier', '', 9);

        // Lignes produits
        foreach ($lignes as $l) {
            $nom = substr($l['titre_produit'] ?? $l['produit_id'], 0, 20);
            $qte = (int)$l['quantite_commande'];
            $pu = (float)$l['prix_commande'];
            $total = (float)$l['montant_commande'];
            $pdf->Cell(30, 5, $nom, 0, 0, 'L');
            $pdf->Cell(12, 5, $qte, 0, 0, 'C');
            $pdf->Cell(15, 5, number_format($pu, 0, ',', ' ') . ' FCFA', 0, 0, 'R');
            $pdf->Cell(13, 5, number_format($total, 0, ',', ' ') . ' FCFA', 0, 1, 'R');
        }

        // Séparateur
        $pdf->Ln(2);
        $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
        $pdf->Ln(2);

        // Totaux
        $ht = (float)$facture['montant_ht'];
        $ttc = (float)$facture['montant_ttc'];
        $avance = (float)$facture['avance'];
        $pdf->SetFont('Courier', 'B', 10);
        $pdf->Cell(50, 6, 'HT', 0, 0, 'L');
        $pdf->Cell(20, 6, number_format($ht, 0, ',', ' ') . ' FCFA', 0, 1, 'R');
        $pdf->Cell(50, 6, 'TTC', 0, 0, 'L');
        $pdf->Cell(20, 6, number_format($ttc, 0, ',', ' ') . ' FCFA', 0, 1, 'R');
        $pdf->Cell(50, 6, 'Avance', 0, 0, 'L');
        $pdf->Cell(20, 6, number_format($avance, 0, ',', ' ') . ' FCFA', 0, 1, 'R');

        // Séparateur final
        $pdf->Ln(2);
        $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
        $pdf->Ln(3);

        // Message de remerciement
        $pdf->SetFont('Courier', 'B', 12);
        $pdf->Cell(70, 8, 'Merci !', 0, 1, 'C');

        // Sortie
        while (ob_get_level()) ob_end_clean();
        $pdf->Output('I', 'Ticket_' . $facture['numero_facture'] . '.pdf');
        exit;
    }

    // --- SINON : FACTURE COMPLÈTE (paysage) ---
    // (Repris de l'original, avec le design de l'exemple)
    $boutique = $pdo->query("SELECT * FROM boutique WHERE etat_boutique = 'Actif' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$boutique) {
        $boutique = [
            'nom_boutique' => 'ABC DISTRIBUTION SARL',
            'adresse_boutique' => '01 BP 1234 Bouaké 01',
            'ville_boutique' => 'Bouaké',
            'pays_boutique' => 'Côte d\'Ivoire',
            'telephone_boutique' => '+225 07 08 09 10 11',
            'email_boutique' => 'contact@abcdistribution.ci'
        ];
    }

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);

    $blueDark = [0, 51, 102];
    $blueLight = [240, 245, 255];
    $grayBg = [245, 245, 245];

    $toLatin = function ($chaine) {
        return safeText($chaine);
    };

    // En-tête
    $yStart = 10;
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(10, $yStart + 6, $toLatin(strtoupper($boutique['nom_boutique'])));

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Text(10, $yStart + 11, $toLatin("Commerce Général - Distribution de Produits"));
    $pdf->Text(10, $yStart + 15, $toLatin($boutique['adresse_boutique'] . ', ' . $boutique['ville_boutique'] . ', ' . $boutique['pays_boutique']));
    $pdf->Text(10, $yStart + 19, $toLatin("Tél. : " . $boutique['telephone_boutique']));
    $pdf->Text(10, $yStart + 23, $toLatin("Email : " . $boutique['email_boutique']));
    $pdf->Text(10, $yStart + 27, $toLatin("N° CC : CI-BOUA-2020-B-12345   N° Contribuable : 1949444F"));

    // Titre
    $pdf->SetFont('Arial', 'B', 24);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(125, $yStart + 10, $toLatin('FACTURE'));

    // Numéro
    $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Rect(115, $yStart + 13, 50, 10, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Text(122, $yStart + 20, $toLatin('N° ' . $facture['numero_facture']));

    // Infos droite
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    $xRight = 200;
    $yInfo = $yStart;
    $pdf->Text($xRight, $yInfo + 5, $toLatin('Date de facture'));
    $pdf->Text($xRight + 40, $yInfo + 5, ': ' . date('d/m/Y', strtotime($facture['date_facture'])));
    $echeance = date('d/m/Y', strtotime($facture['date_facture'] . ' + 30 days'));
    $pdf->Text($xRight, $yInfo + 10, $toLatin("Date d'échéance"));
    $pdf->Text($xRight + 40, $yInfo + 10, ': ' . $echeance);
    $pdf->Text($xRight, $yInfo + 15, $toLatin("Mode de paiement"));
    $pdf->Text($xRight + 40, $yInfo + 15, ': ' . ($facture['mode_reglement'] ?? 'Virement bancaire'));

    // Séparateur
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(10, 42, 287, 42);

    // Blocs VENDEUR / CLIENT
    $yBlocks = 48;
    $wBlock = (277 - 10) / 2;
    $drawAddressBlock = function ($pdf, $x, $y, $w, $title, $name, $address, $phone, $email) use ($toLatin, $blueDark, $grayBg) {
        $h = 30;
        $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->Rect($x, $y, 40, 6, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Text($x + 3, $y + 4.5, $toLatin(strtoupper($title)));
        $pdf->SetFillColor($grayBg[0], $grayBg[1], $grayBg[2]);
        $pdf->Rect($x, $y + 6, $w, $h - 6, 'F');
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Rect($x, $y + 6, $w, $h - 6, 'D');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Text($x + 3, $y + 13, $toLatin($name));
        $pdf->SetFont('Arial', '', 8);
        $pdf->Text($x + 3, $y + 18, $toLatin($address));
        $pdf->Text($x + 3, $y + 23, $toLatin('Tél. : ' . $phone));
        $pdf->Text($x + 3, $y + 28, $toLatin('Email : ' . $email));
    };

    $drawAddressBlock(
        $pdf,
        10,
        $yBlocks,
        $wBlock,
        'VENDEUR',
        $boutique['nom_boutique'],
        $boutique['ville_boutique'] . ', ' . $boutique['pays_boutique'],
        $boutique['telephone_boutique'],
        $boutique['email_boutique']
    );

    $drawAddressBlock(
        $pdf,
        10 + $wBlock + 10,
        $yBlocks,
        $wBlock,
        'CLIENT',
        $facture['nom_prenom_contact'],
        $facture['adresse_contact'] ?? '',
        $facture['telephone_contact'] ?? '',
        $facture['email_contact'] ?? ''
    );

    // Tableau des lignes
    $yTable = 80;
    $pageBottom = 195;
    $colWidths = [25, 100, 28, 22, 27, 35, 40];
    $headers = ['RÉFÉRENCE', 'DÉSIGNATION', 'NB UNITÉ/CARTON', 'CARTON', 'QTÉ (UNITÉ)', 'P.U. (FCFA)', 'MONTANT (FCFA)'];
    $headerH = 7;
    $rowH = 7;

    $drawTableHeader = function () use ($pdf, $colWidths, $headers, $headerH, $toLatin, $blueDark, &$yTable) {
        $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 7);
        $x = 10;
        foreach ($headers as $i => $h) {
            $label = $toLatin($h);
            $pdf->Rect($x, $yTable, $colWidths[$i], $headerH, 'F');
            $pdf->Text($x + ($colWidths[$i] / 2) - ($pdf->GetStringWidth($label) / 2), $yTable + 5.5, $label);
            $x += $colWidths[$i];
        }
    };
    $drawTableHeader();

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 8);
    $yCurrent = $yTable + $headerH;
    $totalHT = 0;

    foreach ($lignes as $ligne) {
        if ($yCurrent + $rowH > $pageBottom) {
            $pdf->AddPage();
            $yTable = 15;
            $yCurrent = $yTable + $headerH;
            $drawTableHeader();
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 8);
        }

        $ref = $ligne['produit_id'];
        $des = substr($ligne['titre_produit'] ?? $ligne['produit_id'], 0, 45);
        $unites_par_carton = (int)($ligne['facteur_conversion'] ?? 1);
        $qte_commande = (int)$ligne['quantite_commande'];
        $nb_cartons = ($unites_par_carton > 0) ? ceil($qte_commande / $unites_par_carton) : 0;
        $pu = (float)$ligne['prix_commande'];
        $total_ligne = (float)$ligne['montant_commande'];
        $totalHT += $total_ligne;

        $data = [
            $ref,
            $des,
            $unites_par_carton,
            $nb_cartons,
            $qte_commande,
            number_format($pu, 0, ',', ' '),
            number_format($total_ligne, 0, ',', ' ')
        ];

        $x = 10;
        foreach ($data as $i => $val) {
            $align = ($i >= 2 && $i != 3) ? 'C' : (($i >= 5) ? 'R' : 'L');
            $label = $toLatin((string)$val);
            $txtX = ($align == 'R')
                ? $x + $colWidths[$i] - 2 - $pdf->GetStringWidth($label)
                : (($align == 'C') ? $x + ($colWidths[$i] / 2) - ($pdf->GetStringWidth($label) / 2) : $x + 1);

            $pdf->Rect($x, $yCurrent, $colWidths[$i], $rowH, 'D');
            $pdf->Text($txtX, $yCurrent + 5, $label);
            $x += $colWidths[$i];
        }
        $yCurrent += $rowH;
    }

    // Totaux
    $yTotals = $yCurrent + 6;
    $wObs = 170;
    $hObs = 28;
    $pdf->SetDrawColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect(10, $yTotals, $wObs, $hObs, 'D');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(12, $yTotals + 6, $toLatin('Observations :'));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(12, $yTotals + 9);
    $pdf->MultiCell($wObs - 4, 5, $toLatin($facture['titre_facture'] ?? "Merci de votre confiance.\nVeuillez effectuer le paiement avant la date d'échéance."), 0, 'L');

    // Bloc totaux
    $xTot = 10 + $wObs + 10;
    $wTot = 287 - $xTot;
    $hTot = 7;
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);

    $taxe = (float)($facture['taxe'] ?? 0);
    $remise = (float)($facture['remise'] ?? 0);
    $montantTtcSaisi = (float)($facture['montant_ttc'] ?? 0);
    $totalTTC = $montantTtcSaisi > 0 ? $montantTtcSaisi : ($totalHT * (1 + $taxe / 100) - $remise);

    $pdf->Rect($xTot, $yTotals, $wTot, $hTot, 'D');
    $pdf->Text($xTot + 2, $yTotals + 5, $toLatin('TOTAL HORS TAXES (HT)'));
    $pdf->SetXY($xTot, $yTotals + 5);
    $pdf->Cell($wTot - 2, 0, number_format($totalHT, 0, ',', ' '), 0, 0, 'R');

    $pdf->Rect($xTot, $yTotals + $hTot, $wTot, $hTot, 'D');
    $pdf->Text($xTot + 2, $yTotals + $hTot + 5, $toLatin('TVA (' . $taxe . '%)'));
    $pdf->SetXY($xTot, $yTotals + $hTot + 5);
    $pdf->Cell($wTot - 2, 0, number_format($totalHT * $taxe / 100, 0, ',', ' '), 0, 0, 'R');

    $pdf->Rect($xTot, $yTotals + ($hTot * 2), $wTot, $hTot, 'D');
    $pdf->Text($xTot + 2, $yTotals + ($hTot * 2) + 5, $toLatin('REMISE'));
    $pdf->SetXY($xTot, $yTotals + ($hTot * 2) + 5);
    $pdf->Cell($wTot - 2, 0, number_format($remise, 0, ',', ' '), 0, 0, 'R');

    $pdf->SetFillColor($blueLight[0], $blueLight[1], $blueLight[2]);
    $pdf->Rect($xTot, $yTotals + ($hTot * 3), $wTot, $hTot + 2, 'FD');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text($xTot + 2, $yTotals + ($hTot * 3) + 5.5, $toLatin('NET À PAYER (TTC)'));
    $pdf->SetXY($xTot, $yTotals + ($hTot * 3) + 5.5);
    $pdf->Cell($wTot - 2, 0, number_format($totalTTC, 0, ',', ' '), 0, 0, 'R');

    // Signatures
    $hSig = 26;
    $ySig = $yTotals + $hObs + 8;
    if ($ySig + $hSig > $pageBottom + 15) {
        $pdf->AddPage();
        $ySig = 20;
    }
    $wSig = 133;

    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Rect(10, $ySig, $wSig, $hSig, 'D');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(12, $ySig + 5, $toLatin('Le vendeur'));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text(12, $ySig + 10, $toLatin('Nom et Signature'));
    $pdf->SetDrawColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Rect(60, $ySig + 5, 70, $hSig - 6, 'D');
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(63, $ySig + 9, $toLatin($boutique['nom_boutique']));
    $pdf->SetFont('Arial', '', 6);
    $pdf->Text(63, $ySig + 13, $toLatin($boutique['adresse_boutique']));
    $pdf->Text(63, $ySig + 17, $toLatin('Tél. : ' . $boutique['telephone_boutique']));

    $xClient = 10 + $wSig + 21;
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Rect($xClient, $ySig, $wSig, $hSig, 'D');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text($xClient + 2, $ySig + 5, $toLatin('Le client'));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text($xClient + 2, $ySig + 10, $toLatin('Nom et Signature'));

    while (ob_get_level()) ob_end_clean();
    $pdf->Output('I', 'Facture_' . $facture['numero_facture'] . '.pdf');
    exit;
}

// ---- RÉCUPÉRATION DES DONNÉES POUR LA PAGE ----
$taxes = $pdo->query("SELECT * FROM taxe WHERE etat_taxe = 'Actif' ORDER BY type_taxe, taux_taxe")->fetchAll();
$caisse = $pdo->query("SELECT * FROM caisse WHERE code_caisse = '" . CAISSE_ID . "'")->fetch();
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* Styles identiques à la version précédente (inchangés) */
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
            --color-gray-300: #cbd5e1;
            --color-gray-400: #94a3b8;
            --color-gray-500: #64748b;
            --color-gray-600: #475569;
            --color-gray-700: #334155;
            --color-gray-800: #1e293b;
            --color-gray-900: #0f172a;
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --bg-muted: #f8fafc;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-tertiary: #64748b;
            --text-quaternary: #94a3b8;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.08);
            --radius-sm: 10px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            height: 100vh;
            overflow: hidden;
            font-size: 14px;
            display: flex;
            flex-direction: column;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        button {
            cursor: pointer;
            font-family: inherit;
            border: none;
        }

        input,
        select {
            font-family: inherit;
            outline: none;
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        .pos-layout {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        .pos-left {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 16px 20px;
            overflow: hidden;
        }

        .pos-right {
            width: 480px;
            background: var(--bg-surface);
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            min-height: 0;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: var(--bg-surface);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0 16px;
            height: 52px;
            transition: all var(--transition-base);
            position: relative;
        }

        .search-box:focus-within {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 4px var(--color-primary-soft);
        }

        .search-box.is-loading {
            border-color: var(--color-warning);
            opacity: .8;
        }

        .search-box i.bi-search {
            font-size: 20px;
            color: var(--color-primary);
            margin-right: 12px;
        }

        .search-box input {
            flex: 1;
            border: none;
            background: transparent;
            font-size: 15px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .search-box input::placeholder {
            color: var(--text-tertiary);
        }

        .search-box .clear-btn {
            color: #94a3b8;
            font-size: 16px;
            display: none;
            background: none;
            padding: 4px;
        }

        .search-box .clear-btn:hover {
            color: var(--color-danger);
        }

        .search-box .result-count {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-tertiary);
            background: var(--bg-muted);
            padding: 4px 10px;
            border-radius: 6px;
            white-space: nowrap;
            margin-left: 8px;
        }

        .search-box .result-count.has-results {
            color: var(--color-primary);
            background: var(--color-primary-soft);
        }

        .search-box .spinner-inline {
            width: 18px;
            height: 18px;
            border: 2.5px solid var(--border-color);
            border-top-color: var(--color-primary);
            border-radius: 50%;
            animation: spin .6s linear infinite;
            margin-right: 10px;
            flex-shrink: 0;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .kbd-hint {
            font-size: 10px;
            color: var(--text-tertiary);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .kbd {
            background: #f1f5f9;
            border: 1px solid var(--border-color);
            padding: 1px 5px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 10px;
            font-weight: 600;
        }

        .category-bar {
            display: flex;
            gap: 6px;
            margin-bottom: 14px;
            overflow-x: auto;
            padding-bottom: 4px;
        }

        .category-bar.hidden {
            display: none;
        }

        .cat-btn {
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            background: var(--bg-surface);
            color: var(--text-tertiary);
            border: 1.5px solid var(--border-color);
            transition: all .15s;
            white-space: nowrap;
        }

        .cat-btn:hover {
            border-color: #bfdbfe;
            color: var(--color-primary);
            background: var(--color-primary-soft);
        }

        .cat-btn.active {
            background: var(--color-primary);
            color: #fff;
            border-color: var(--color-primary);
        }

        .products-scroll {
            flex: 1;
            overflow-y: auto;
            padding-right: 4px;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .empty-state {
            grid-column: 1/-1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-tertiary);
            text-align: center;
            padding-top: 40px;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: .15;
            color: var(--color-primary);
        }

        .empty-state h3 {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .empty-state p {
            font-size: 13px;
        }

        .product-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 14px;
            cursor: pointer;
            transition: all .15s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 145px;
            position: relative;
        }

        .product-card:hover {
            border-color: var(--color-primary);
            box-shadow: 0 4px 12px rgba(79, 70, 229, .12);
            transform: translateY(-2px);
        }

        .product-card.in-cart {
            border-color: var(--color-success);
            background: #f0fdf4;
        }

        .product-card.in-cart::after {
            content: '✓';
            position: absolute;
            top: 8px;
            right: 8px;
            background: var(--color-success);
            color: #fff;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .pc-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .pc-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.4;
            flex: 1;
            padding-right: 6px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .pc-cat {
            font-size: 10px;
            font-weight: 600;
            color: var(--color-primary);
            background: var(--color-primary-soft);
            padding: 2px 8px;
            border-radius: 4px;
            margin-bottom: 6px;
            display: inline-block;
        }

        .pc-stock {
            font-size: 10px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .stock-in {
            background: #dcfce7;
            color: #15803d;
        }

        .stock-low {
            background: #fef9c3;
            color: #a16207;
        }

        .stock-out {
            background: #fee2e2;
            color: #b91c1c;
        }

        .pc-bottom {
            border-top: 1px dashed var(--border-color);
            padding-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .pc-price {
            font-size: 17px;
            font-weight: 700;
            color: var(--color-primary);
        }

        .pc-code {
            font-size: 10px;
            color: var(--text-tertiary);
            font-family: monospace;
        }

        .cart-header {
            padding: 10px 16px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-surface);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-header h2 {
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-primary);
        }

        .cart-badge {
            background: var(--color-primary);
            color: #fff;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
        }

        .client-zone {
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-color);
            background: var(--color-primary-soft);
        }

        .client-search-wrapper {
            display: flex;
            gap: 6px;
            position: relative;
        }

        .client-search-wrapper input {
            flex: 1;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 12px;
            background: #fff;
            transition: all .2s;
            height: 34px;
        }

        .client-search-wrapper input:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, .1);
        }

        .client-search-wrapper .btn-add {
            background: var(--color-primary);
            color: #fff;
            width: 34px;
            height: 34px;
            border-radius: 6px;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .2s;
        }

        .client-search-wrapper .btn-add:hover {
            background: var(--color-primary-dark);
        }

        .client-selected {
            display: none;
            align-items: center;
            justify-content: space-between;
            background: #fff;
            border: 1px solid var(--color-primary);
            border-radius: 6px;
            padding: 6px 10px;
        }

        .client-selected .info h5 {
            font-size: 13px;
            font-weight: 700;
            color: var(--color-primary-dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .client-selected .info p {
            font-size: 10px;
            color: var(--text-tertiary);
            margin: 0;
        }

        .client-selected .btn-change {
            font-size: 10px;
            color: var(--text-tertiary);
            text-decoration: underline;
            background: none;
        }

        .customer-dropdown {
            position: absolute;
            top: 38px;
            left: 0;
            right: 40px;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-top: 2px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .12);
            z-index: 100;
            display: none;
            max-height: 240px;
            overflow-y: auto;
        }

        .customer-dropdown .dd-header {
            padding: 6px 12px;
            background: var(--color-primary-soft);
            border-radius: 8px 8px 0 0;
            font-size: 10px;
            font-weight: 600;
            color: var(--color-primary-dark);
            display: flex;
            justify-content: space-between;
        }

        .customer-item {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background .1s;
        }

        .customer-item:hover {
            background: var(--color-primary-soft);
        }

        .customer-item .ci-avatar {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            background: var(--color-primary-soft);
            color: var(--color-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .customer-item .ci-body strong {
            display: block;
            font-size: 12px;
            color: var(--text-primary);
            font-weight: 600;
        }

        .customer-item .ci-body small {
            font-size: 10px;
            color: var(--text-tertiary);
            display: block;
            margin-top: 1px;
        }

        .customer-item .ci-tags {
            display: flex;
            gap: 3px;
            margin-top: 2px;
        }

        .customer-item .ci-tag {
            font-size: 8px;
            font-weight: 600;
            padding: 1px 5px;
            border-radius: 3px;
        }

        .ci-tag.tag-client {
            background: #dcfce7;
            color: #15803d;
        }

        .ci-tag.tag-particulier {
            background: #fef9c3;
            color: #a16207;
        }

        .customer-item .ci-arrow {
            color: #cbd5e1;
            font-size: 12px;
        }

        .customer-item:hover .ci-arrow {
            color: var(--color-primary);
        }

        .dd-empty {
            padding: 16px;
            text-align: center;
            color: var(--text-tertiary);
            font-size: 12px;
        }

        .dd-empty i {
            font-size: 28px;
            opacity: .15;
            display: block;
            margin-bottom: 6px;
        }

        .recent-clients {
            margin-top: 4px;
        }

        .recent-clients.hidden {
            display: none;
        }

        .recent-clients .rc-title {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-tertiary);
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .recent-clients .rc-grid {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .rc-chip {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            background: #fff;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: all .15s;
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .rc-chip:hover {
            border-color: var(--color-primary);
            color: var(--color-primary);
            background: var(--color-primary-soft);
        }

        .rc-chip i {
            font-size: 9px;
            color: var(--color-primary);
        }

        .cart-items {
            flex: 1;
            overflow-y: auto;
            padding: 6px 10px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            align-content: start;
        }

        .cart-empty {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-tertiary);
        }

        .cart-empty i {
            font-size: 40px;
            opacity: .15;
            margin-bottom: 8px;
            color: var(--color-primary);
        }

        .cart-empty p {
            font-size: 13px;
        }

        .cart-line {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .02);
            transition: all .2s;
            height: fit-content;
        }

        .cart-line:hover {
            border-color: var(--color-primary);
            box-shadow: 0 2px 8px rgba(79, 70, 229, .1);
        }

        .cl-header {
            display: flex;
            justify-content: space-between;
            gap: 4px;
            align-items: center;
        }

        .cl-name {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .cl-lot {
            font-size: 9px;
            color: var(--text-tertiary);
            background: var(--bg-muted);
            padding: 0 6px;
            border-radius: 3px;
            white-space: nowrap;
        }

        .cl-remove {
            color: #cbd5e1;
            background: none;
            font-size: 12px;
            transition: color .2s;
            flex-shrink: 0;
        }

        .cl-remove:hover {
            color: var(--color-danger);
        }

        .cl-footer {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .qty-selector {
            display: flex;
            align-items: center;
            background: var(--bg-muted);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            width: fit-content;
        }

        .qty-selector button {
            width: 24px;
            height: 24px;
            background: none;
            font-size: 14px;
            font-weight: 600;
            color: var(--color-primary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qty-selector button:hover {
            background: var(--color-primary-soft);
        }

        .qty-selector span {
            min-width: 24px;
            text-align: center;
            font-weight: 600;
            font-size: 12px;
            color: var(--text-primary);
        }

        .cl-total {
            font-size: 14px;
            font-weight: 700;
            color: var(--color-primary-dark);
        }

        .cl-unit {
            font-size: 10px;
            color: var(--text-tertiary);
            text-align: right;
        }

        .cart-footer {
            padding: 8px 12px;
            border-top: 1px solid var(--border-color);
            background: #fff;
        }

        .totals-split-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }

        .split-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .split-label {
            font-size: 10px;
            color: var(--text-tertiary);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .split-label select {
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 1px 4px;
            font-size: 10px;
            background: #fff;
            color: var(--text-primary);
            width: auto;
        }

        .split-value {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .total-grand {
            display: flex;
            justify-content: space-between;
            padding-top: 6px;
            margin-top: 4px;
            border-top: 2px solid var(--border-color);
            margin-bottom: 8px;
        }

        .total-grand span:first-child {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
            align-self: center;
        }

        .total-grand span:last-child {
            font-size: 22px;
            font-weight: 800;
            color: var(--color-primary);
        }

        .actions-row {
            display: flex;
            gap: 8px;
        }

        .btn-pay {
            flex: 2;
            background: var(--color-primary);
            color: #fff;
            padding: 10px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: background .2s;
            box-shadow: 0 4px 6px rgba(79, 70, 229, .2);
        }

        .btn-pay:hover:not(:disabled) {
            background: var(--color-primary-dark);
        }

        .btn-pay:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            box-shadow: none;
        }

        .btn-clear {
            flex: 1;
            background: #fff;
            color: var(--color-danger);
            border: 1.5px solid #fecaca;
            padding: 8px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 600;
            transition: all .2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .btn-clear:hover {
            background: #fee2e2;
            border-color: var(--color-danger);
        }

        /* Modales */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-box {
            background: #fff;
            border-radius: 16px;
            width: 420px;
            max-width: 90%;
            box-shadow: 0 20px 25px rgba(0, 0, 0, .1);
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-head h3 {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-primary);
        }

        .modal-close {
            background: #f1f5f9;
            font-size: 18px;
            color: var(--text-tertiary);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .2s;
        }

        .modal-close:hover {
            background: #fee2e2;
            color: var(--color-danger);
        }

        .modal-body {
            padding: 20px;
        }

        .modal-foot {
            padding: 16px 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text-tertiary);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            background: #f8fafc;
            transition: all .2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--color-primary);
            background: #fff;
            box-shadow: 0 0 0 3px var(--color-primary-soft);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: var(--text-primary);
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        .btn-primary {
            background: var(--color-primary);
            color: #fff;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            transition: background .2s;
        }

        .btn-primary:hover {
            background: var(--color-primary-dark);
        }

        .pay-modes {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
        }

        .pay-mode {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-tertiary);
            background: #f8fafc;
            transition: all .2s;
        }

        .pay-mode.active {
            border-color: var(--color-primary);
            color: var(--color-primary);
            background: var(--color-primary-soft);
        }

        .pay-mode i {
            display: block;
            font-size: 20px;
            margin-bottom: 4px;
        }

        .change-box {
            background: var(--color-primary-soft);
            border: 1px solid #bfdbfe;
            padding: 16px;
            border-radius: 10px;
            text-align: center;
            margin-top: 16px;
        }

        .change-box .lbl {
            font-size: 13px;
            color: var(--color-primary-dark);
            font-weight: 600;
            margin-bottom: 4px;
        }

        .change-box .val {
            font-size: 28px;
            font-weight: 800;
            color: var(--color-primary);
        }

        .lot-select-modal .modal-box {
            width: 480px;
        }

        .lot-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all .15s;
        }

        .lot-option:hover {
            border-color: var(--color-primary);
            background: var(--color-primary-soft);
        }

        .lot-option.selected {
            border-color: var(--color-primary);
            background: var(--color-primary-soft);
        }

        .lot-option .lot-info {
            flex: 1;
        }

        .lot-option .lot-info strong {
            display: block;
            font-size: 14px;
            color: var(--text-primary);
        }

        .lot-option .lot-info small {
            font-size: 11px;
            color: var(--text-tertiary);
        }

        .lot-option .lot-stock {
            font-weight: 700;
            color: var(--color-primary);
        }

        .toast-notif {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--text-primary);
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            z-index: 2000;
            display: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .15);
            align-items: center;
            gap: 8px;
        }

        .toast-notif.error {
            background: var(--color-danger);
        }

        .toast-notif.success {
            background: var(--color-primary);
        }

        @media print {
            body * {
                visibility: hidden;
            }

            #printZone,
            #printZone * {
                visibility: visible;
            }

            #printZone {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 20px;
            }
        }

        @media (max-width:768px) {
            .pos-right {
                width: 100%;
                border-left: none;
            }

            .pos-left {
                display: none;
            }

            .pos-layout {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <input type="hidden" id="csrfToken" value="<?= $csrf_token ?>">

    <div class="pos-layout">
        <!-- GAUCHE -->
        <div class="pos-left">
            <div class="search-zone">
                <div class="search-box" id="searchBox">
                    <i class="bi bi-search"></i>
                    <input type="text" id="searchInput" placeholder="Rechercher un produit (nom, code)..." autofocus>
                    <span class="result-count" id="resultCount" style="display:none;"></span>
                    <button class="clear-btn" id="clearBtn"><i class="bi bi-x-circle-fill"></i></button>
                </div>
                <div class="kbd-hint"><span class="kbd">Entrée</span> ajouter le 1er &nbsp; <span class="kbd">Esc</span> effacer</div>
            </div>
            <div class="category-bar hidden" id="categoryBar">
                <button class="cat-btn active" data-cat="Tous" onclick="filterCategory('Tous',this)">Tous</button>
            </div>
            <div class="products-scroll">
                <div class="product-grid" id="productGrid">
                    <div class="empty-state"><i class="bi bi-arrow-repeat"></i>
                        <h3>Chargement...</h3>
                        <p>Veuillez patienter.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- DROITE : PANIER -->
        <div class="pos-right">
            <div class="cart-header">
                <h2><i class="bi bi-receipt"></i> Ticket <span class="cart-badge" id="cartCount">0</span></h2>
                <!-- Bouton PDF Facture complète (paysage) -->
                <form method="post" target="_blank" style="margin:0;">
                    <input type="hidden" name="export_pdf" value="1">
                    <input type="hidden" name="numero" id="pdfFactureNum" value="">
                    <input type="hidden" name="format" value="facture">
                    <button type="submit" class="btn btn-sm btn-outline-danger" id="pdfExportBtn" disabled title="Exporter la facture complète (PDF)">
                        <i class="bi bi-file-pdf"></i>
                    </button>
                </form>
            </div>
            <div class="client-zone">
                <div class="client-search-wrapper" id="clientSearchWrapper">
                    <input type="text" id="clientSearch" placeholder="Chercher un client...">
                    <button class="btn-add" data-bs-toggle="modal" data-bs-target="#clientModal"><i class="bi bi-plus-lg"></i></button>
                    <div class="customer-dropdown" id="clientDropdown"></div>
                </div>
                <div class="client-selected" id="clientSelectedBox">
                    <div class="info">
                        <h5><i class="bi bi-person-check"></i> <span id="selectedClientName"></span></h5>
                        <p id="selectedClientInfo"></p>
                    </div>
                    <button class="btn-change" onclick="resetClient()">Changer</button>
                </div>
                <div class="recent-clients" id="recentClientsZone">
                    <div class="rc-title"><i class="bi bi-clock-history"></i> Clients fréquents</div>
                    <div class="rc-grid" id="recentClientsGrid"></div>
                </div>
            </div>
            <div class="cart-items" id="cartItems">
                <div class="cart-empty"><i class="bi bi-cart-x"></i>
                    <p>Panier vide</p>
                </div>
            </div>
            <div class="cart-footer">
                <div class="totals-split-row">
                    <div class="split-item">
                        <div class="split-label">Sous-total HT <select id="taxRate">
                                <option value="0">0%</option>
                                <?php foreach ($taxes as $t): if ($t['type_taxe'] == 'TVA'): ?>
                                        <option value="<?= floatval($t['taux_taxe']) ?>">TVA <?= floatval($t['taux_taxe']) ?>%</option>
                                <?php endif;
                                endforeach; ?>
                            </select></div>
                        <div class="split-value" id="totalHT">0 FCFA</div>
                    </div>
                    <div class="split-item">
                        <div class="split-label">Remise <select id="discountRate">
                                <option value="0">0%</option>
                                <?php foreach ($taxes as $t): if ($t['type_taxe'] == 'Remise'): ?>
                                        <option value="<?= floatval($t['taux_taxe']) ?>"><?= floatval($t['taux_taxe']) ?>%</option>
                                <?php endif;
                                endforeach; ?>
                            </select></div>
                        <div class="split-value" id="totalRemise">0 FCFA</div>
                    </div>
                </div>
                <div class="total-grand"><span>TOTAL TTC</span><span id="totalTTC">0 FCFA</span></div>
                <div class="actions-row">
                    <button class="btn-clear" id="btnClear"><i class="bi bi-trash3"></i> Vider</button>
                    <button class="btn-pay" id="btnCheckout" disabled><i class="bi bi-credit-card-fill"></i> Encaisser</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODALES -->
    <div class="modal-overlay" id="payModal">
        <div class="modal-box">
            <div class="modal-head">
                <h3><i class="bi bi-credit-card-2-front"></i> Encaissement</h3>
                <button class="modal-close" onclick="closeModal('payModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <div class="pay-modes">
                    <button class="pay-mode active" data-mode="Espece"><i class="bi bi-cash"></i>Espèces</button>
                    <button class="pay-mode" data-mode="Mobile"><i class="bi bi-phone"></i>Mobile</button>
                    <button class="pay-mode" data-mode="Cheque"><i class="bi bi-receipt"></i>Chèque</button>
                </div>
                <div style="font-size:16px;font-weight:700;margin-bottom:16px;display:flex;justify-content:space-between;"><span>Montant à payer</span><span id="payAmount" style="color:var(--color-primary);">0 FCFA</span></div>
                <div class="form-group"><label>Montant reçu</label><input type="number" id="receivedAmount" style="font-size:18px;font-weight:700;" placeholder="0"></div>
                <div class="change-box" id="changeBox">
                    <div class="lbl" id="changeLbl">Monnaie à rendre</div>
                    <div class="val" id="changeAmount">0 FCFA</div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn-secondary" onclick="closeModal('payModal')">Annuler</button>
                <button class="btn-primary" id="btnValidatePay"><i class="bi bi-check-lg"></i> Valider</button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="clientModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-head">
                    <h3><i class="bi bi-person-plus"></i> Nouveau Client</h3>
                    <button type="button" class="modal-close" data-bs-dismiss="modal"><i class="bi bi-x"></i></button>
                </div>
                <div class="modal-body">
                    <form id="clientForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <div class="form-group"><label>Nom complet *</label><input type="text" id="newClientName" required></div>
                        <div class="form-group"><label>Téléphone</label><input type="text" id="newClientPhone"></div>
                        <div class="form-group" style="margin-bottom:0;"><label>Adresse</label><input type="text" id="newClientAddress"></div>
                    </form>
                </div>
                <div class="modal-foot">
                    <button class="btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button class="btn-primary" id="btnSaveClient">Créer</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay lot-select-modal" id="lotModal">
        <div class="modal-box">
            <div class="modal-head">
                <h3><i class="bi bi-boxes"></i> Choisir un lot</h3>
                <button class="modal-close" onclick="closeModal('lotModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <div id="lotSelectionList"></div>
                <div class="form-group" style="margin-top:12px;"><label>Quantité (en unités de base)</label><input type="number" id="lotQtyInput" value="1" min="1"></div>
            </div>
            <div class="modal-foot">
                <button class="btn-secondary" onclick="closeModal('lotModal')">Annuler</button>
                <button class="btn-primary" id="btnConfirmLot"><i class="bi bi-check"></i> Ajouter au panier</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="ticketModal">
        <div class="modal-box" style="width:480px;">
            <div class="modal-head">
                <h3><i class="bi bi-receipt"></i> Ticket</h3>
                <button class="modal-close" onclick="closeModal('ticketModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body" id="printZone"></div>
            <div class="modal-foot">
                <button class="btn-secondary" onclick="closeModal('ticketModal');resetSale();">Nouvelle vente</button>
                <button class="btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimer</button>
                <!-- Bouton PDF du ticket (format ticket) -->
                <form method="post" target="_blank" style="margin:0;">
                    <input type="hidden" name="export_pdf" value="1">
                    <input type="hidden" name="numero" id="pdfFactureNumTicket" value="">
                    <input type="hidden" name="format" value="ticket">
                    <button type="submit" class="btn btn-primary" id="pdfExportTicketBtn"><i class="bi bi-file-pdf"></i> PDF</button>
                </form>
            </div>
        </div>
    </div>

    <div class="toast-notif" id="toastMsg"></div>

    <script>
        // ===== CONFIG =====
        function getBaseUrl() {
            const path = window.location.pathname;
            const base = path.split('?')[0];
            return window.location.origin + base;
        }
        const BASE_URL = getBaseUrl();
        const CSRF_TOKEN = document.getElementById('csrfToken').value;

        function api(action, data = {}) {
            const payload = {
                action,
                ...data
            };
            if (['create_customer', 'valider_vente'].includes(action)) {
                payload.csrf_token = CSRF_TOKEN;
            }
            return fetch(BASE_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            }).then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });
        }

        // ===== VARIABLES =====
        let cart = [];
        let selectedClient = null;
        let payMode = 'Espece';
        let currentProducts = [];
        let allProductsCache = [];
        let searchTimer = null;
        let clientTimer = null;
        let currentCategory = 'Tous';
        let recentClients = [];
        let hasCategorie = false;
        let pendingProduct = null;
        let selectedLotId = null;
        let lastFactureNum = null;

        const $ = id => document.getElementById(id);
        const fmt = n => new Intl.NumberFormat('fr-FR').format(Math.round(n || 0)) + ' FCFA';
        const esc = s => (s || '').toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

        function toast(msg, type = 'success') {
            const t = $('toastMsg');
            const icon = type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill';
            t.innerHTML = `<i class="bi ${icon}"></i> ${msg}`;
            t.className = 'toast-notif ' + type;
            t.style.display = 'flex';
            clearTimeout(t._timer);
            t._timer = setTimeout(() => t.style.display = 'none', 2500);
        }

        function openModal(id) {
            $(id).classList.add('show');
        }

        function closeModal(id) {
            $(id).classList.remove('show');
        }

        // ===== INIT =====
        document.addEventListener('DOMContentLoaded', function() {
            loadCategories();
            loadInitialProducts();
            loadRecentClients();
            $('searchInput').focus();
        });

        // ===== CATÉGORIES =====
        function loadCategories() {
            api('load_categories').then(res => {
                if (res.success && res.has_categorie && res.data && res.data.length > 0) {
                    hasCategorie = true;
                    const bar = $('categoryBar');
                    bar.classList.remove('hidden');
                    res.data.forEach(cat => {
                        const btn = document.createElement('button');
                        btn.className = 'cat-btn';
                        btn.dataset.cat = cat;
                        btn.textContent = cat;
                        btn.onclick = function() {
                            filterCategory(cat, this);
                        };
                        bar.appendChild(btn);
                    });
                } else {
                    $('categoryBar').classList.add('hidden');
                }
            }).catch(() => $('categoryBar').classList.add('hidden'));
        }

        function filterCategory(cat, btnEl) {
            currentCategory = cat;
            document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');
            const q = $('searchInput').value.trim();
            if (q.length >= 2) searchProducts(q);
            else loadInitialProducts();
        }

        // ===== PRODUITS =====
        function loadInitialProducts() {
            showSearchSpinner(true);
            api('load_products', {
                    categorie: currentCategory
                })
                .then(res => {
                    showSearchSpinner(false);
                    if (res.success) {
                        currentProducts = res.data || [];
                        allProductsCache = currentProducts;
                        renderProducts(currentProducts, '');
                        $('resultCount').textContent = currentProducts.length + ' produits';
                        $('resultCount').className = 'result-count has-results';
                        $('resultCount').style.display = 'inline-block';
                    } else {
                        toast(res.message || 'Erreur chargement', 'error');
                        $('productGrid').innerHTML = `<div class="empty-state"><i class="bi bi-emoji-frown"></i><h3>Erreur</h3><p>${esc(res.message||'')}</p></div>`;
                    }
                })
                .catch(() => {
                    showSearchSpinner(false);
                    toast('Erreur connexion', 'error');
                    $('productGrid').innerHTML = `<div class="empty-state"><i class="bi bi-wifi-off"></i><h3>Erreur de connexion</h3><p>Vérifiez le serveur.</p></div>`;
                });
        }

        function showSearchSpinner(show) {
            const box = $('searchBox');
            if (show) {
                box.classList.add('is-loading');
                if (!box.querySelector('.spinner-inline')) {
                    const sp = document.createElement('div');
                    sp.className = 'spinner-inline';
                    box.insertBefore(sp, $('clearBtn'));
                }
            } else {
                box.classList.remove('is-loading');
                const sp = box.querySelector('.spinner-inline');
                if (sp) sp.remove();
            }
        }

        function renderProducts(products, q) {
            if (products.length === 0) {
                $('productGrid').innerHTML = q ?
                    `<div class="empty-state"><i class="bi bi-emoji-frown"></i><h3>Aucun produit pour "${esc(q)}"</h3><p>Essayez autre chose.</p></div>` :
                    `<div class="empty-state"><i class="bi bi-box-seam"></i><h3>Aucun produit</h3><p>Changez de catégorie.</p></div>`;
                return;
            }
            $('productGrid').innerHTML = products.map((p, i) => {
                const stock = parseInt(p.stock) || 0;
                let stockClass = 'stock-out',
                    stockText = 'Rupture';
                if (stock > 5) {
                    stockClass = 'stock-in';
                    stockText = 'Stock: ' + stock;
                } else if (stock > 0) {
                    stockClass = 'stock-low';
                    stockText = 'Stock: ' + stock + ' ⚠';
                }
                const inCart = cart.some(item => item.code === p.code_produit);
                const cardClass = inCart ? 'product-card in-cart' : 'product-card';
                const catLabel = (p.categorie && p.categorie !== 'Autre') ? `<span class="pc-cat">${esc(p.categorie)}</span>` : '';
                const lotBadge = (p.lots && p.lots.length > 0) ? `<span class="badge bg-info" style="font-size:9px;margin-left:4px;">${p.lots.length} cond.</span>` : '';
                const titleHTML = q ? highlightText(p.titre_produit, q) : esc(p.titre_produit);
                const codeHTML = q ? highlightText(p.code_produit, q) : esc(p.code_produit);
                return `
                    <div class="${cardClass}" onclick="openLotSelection(${i})">
                        <div class="pc-top">
                            <div style="flex:1;">${catLabel}${lotBadge}<div class="pc-title">${titleHTML}</div></div>
                            <span class="pc-stock ${stockClass}">${stockText}</span>
                        </div>
                        <div class="pc-bottom">
                            <div class="pc-price">${fmt(p.prix)}</div>
                            <div class="pc-code">${codeHTML}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function highlightText(text, q) {
            if (!q) return esc(text);
            const reg = new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi');
            return esc(text).replace(reg, '<mark style="background:#bfdbfe;color:#1e40af;padding:0 2px;border-radius:2px;">$1</mark>');
        }

        // ===== RECHERCHE PRODUITS =====
        $('searchInput').addEventListener('input', function() {
            const q = this.value.trim();
            $('clearBtn').style.display = q ? 'block' : 'none';
            clearTimeout(searchTimer);
            if (q.length < 2) {
                $('resultCount').style.display = 'none';
                currentProducts = allProductsCache;
                renderProducts(currentProducts, '');
                if (allProductsCache.length > 0) {
                    $('resultCount').textContent = allProductsCache.length + ' produits';
                    $('resultCount').className = 'result-count has-results';
                    $('resultCount').style.display = 'inline-block';
                }
                return;
            }
            showSearchSpinner(true);
            searchTimer = setTimeout(() => searchProducts(q), 250);
        });

        function searchProducts(q) {
            api('search_products', {
                    q,
                    categorie: currentCategory
                })
                .then(res => {
                    showSearchSpinner(false);
                    if (res.success) {
                        currentProducts = res.data || [];
                        renderProducts(currentProducts, q);
                        const count = currentProducts.length;
                        $('resultCount').textContent = count + ' résultat(s)';
                        $('resultCount').className = count > 0 ? 'result-count has-results' : 'result-count';
                        $('resultCount').style.display = 'inline-block';
                    } else {
                        toast(res.message || 'Erreur recherche', 'error');
                        $('productGrid').innerHTML = `<div class="empty-state"><i class="bi bi-emoji-frown"></i><h3>Erreur</h3><p>${esc(res.message||'')}</p></div>`;
                    }
                })
                .catch(() => {
                    showSearchSpinner(false);
                    toast('Erreur connexion', 'error');
                });
        }

        $('clearBtn').addEventListener('click', function() {
            $('searchInput').value = '';
            $('clearBtn').style.display = 'none';
            $('searchInput').dispatchEvent(new Event('input'));
            $('searchInput').focus();
        });

        $('searchInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && currentProducts.length > 0) {
                e.preventDefault();
                openLotSelection(0);
            }
            if (e.key === 'Escape') {
                this.value = '';
                this.dispatchEvent(new Event('input'));
            }
        });

        // ===== CLIENTS =====
        function loadRecentClients() {
            api('load_recent_customers').then(res => {
                if (res.success && res.data && res.data.length > 0) {
                    recentClients = res.data;
                    renderRecentClients();
                } else {
                    $('recentClientsZone').classList.add('hidden');
                }
            }).catch(() => $('recentClientsZone').classList.add('hidden'));
        }

        function renderRecentClients() {
            if (recentClients.length === 0) {
                $('recentClientsZone').classList.add('hidden');
                return;
            }
            $('recentClientsZone').classList.remove('hidden');
            $('recentClientsGrid').innerHTML = recentClients.map(c => `
                <div class="rc-chip" onclick="quickSelectClient('${esc(c.code_contact)}','${esc(c.nom_prenom_contact)}','${esc(c.telephone_contact||'')}')">
                    <i class="bi bi-person"></i> ${esc(c.nom_prenom_contact)}
                </div>
            `).join('');
        }

        function quickSelectClient(code, name, phone) {
            selectClient(code, name, phone);
            $('recentClientsZone').classList.add('hidden');
        }

        $('clientSearch').addEventListener('input', function() {
            const q = this.value.trim();
            clearTimeout(clientTimer);
            if (q.length < 2) {
                $('clientDropdown').style.display = 'none';
                if (!selectedClient && recentClients.length > 0) $('recentClientsZone').classList.remove('hidden');
                return;
            }
            $('recentClientsZone').classList.add('hidden');
            clientTimer = setTimeout(() => {
                api('search_customers', {
                        q
                    })
                    .then(res => {
                        if (res.success) renderClientDropdown(res.data || [], q);
                        else toast(res.message || 'Erreur client', 'error');
                    })
                    .catch(() => toast('Erreur connexion', 'error'));
            }, 250);
        });

        $('clientSearch').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const firstItem = $('clientDropdown').querySelector('.customer-item[data-code]');
                if (firstItem) firstItem.click();
                e.preventDefault();
            }
            if (e.key === 'Escape') {
                this.value = '';
                $('clientDropdown').style.display = 'none';
            }
        });

        function renderClientDropdown(data, q) {
            const dd = $('clientDropdown');
            if (data.length === 0) {
                dd.innerHTML = `<div class="dd-header"><span>Aucun résultat</span></div><div class="dd-empty"><i class="bi bi-emoji-frown"></i>Aucun client pour "${esc(q)}"<br><button style="margin-top:8px;background:var(--color-primary);color:#fff;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#clientModal"><i class="bi bi-person-plus"></i> Créer</button></div>`;
                dd.style.display = 'block';
                return;
            }
            dd.innerHTML = `
                <div class="dd-header"><span>${data.length} client(s)</span><span style="color:var(--text-tertiary);">Entrée = sélectionner</span></div>
                ${data.map(c => {
                    const initials = (c.nom_prenom_contact||'').split(' ').map(w=>(w[0]||'')).join('').substring(0,2).toUpperCase();
                    const typeLabel = c.statut_contact || c.type_contact || 'Client';
                    const tagClass = (c.type_contact === 'Entreprise') ? 'tag-client' : 'tag-particulier';
                    return `
                        <div class="customer-item" data-code="${esc(c.code_contact)}" onclick="selectClient('${esc(c.code_contact)}','${esc(c.nom_prenom_contact)}','${esc(c.telephone_contact||'')}')">
                            <div class="ci-avatar">${initials}</div>
                            <div class="ci-body">
                                <strong>${highlightText(c.nom_prenom_contact, q)}</strong>
                                <small>${highlightText(c.code_contact, q)}${c.telephone_contact ? ' — ' + highlightText(c.telephone_contact, q) : ''}</small>
                                <div class="ci-tags"><span class="ci-tag ${tagClass}">${esc(typeLabel)}</span></div>
                            </div>
                            <i class="bi bi-chevron-right ci-arrow"></i>
                        </div>
                    `;
                }).join('')}
            `;
            dd.style.display = 'block';
        }

        document.addEventListener('click', function(e) {
            if (!$('clientSearchWrapper').contains(e.target)) $('clientDropdown').style.display = 'none';
        });

        function selectClient(code, name, phone) {
            selectedClient = {
                code,
                name,
                phone
            };
            $('selectedClientName').textContent = name;
            $('selectedClientInfo').textContent = code + (phone ? ' — ' + phone : '');
            $('clientSearchWrapper').style.display = 'none';
            $('clientSelectedBox').style.display = 'flex';
            $('clientDropdown').style.display = 'none';
            $('recentClientsZone').classList.add('hidden');
            $('clientSearch').value = '';
        }

        function resetClient() {
            selectedClient = null;
            $('clientSearchWrapper').style.display = 'flex';
            $('clientSelectedBox').style.display = 'none';
            $('clientSearch').focus();
            if (recentClients.length > 0) $('recentClientsZone').classList.remove('hidden');
        }

        // ===== CRÉER CLIENT =====
        $('btnSaveClient').addEventListener('click', function() {
            const nom = $('newClientName').value.trim();
            if (!nom) {
                toast('Nom requis', 'error');
                return;
            }
            const payload = {
                action: 'create_customer',
                nom,
                tel: $('newClientPhone').value,
                adresse: $('newClientAddress').value,
                csrf_token: CSRF_TOKEN
            };
            fetch(BASE_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const c = res.customer;
                        selectClient(c.code_contact, c.nom_prenom_contact, c.telephone_contact || '');
                        bootstrap.Modal.getInstance($('clientModal')).hide();
                        $('clientForm').reset();
                        recentClients.unshift(c);
                        toast('Client créé');
                    } else {
                        toast(res.message || 'Erreur', 'error');
                    }
                })
                .catch(() => toast('Erreur connexion', 'error'));
        });

        // ===== SÉLECTION DE LOT =====
        function openLotSelection(idx) {
            const p = currentProducts[idx];
            if (!p) return;
            if (parseInt(p.stock) <= 0) {
                toast('Rupture de stock', 'error');
                return;
            }
            const lots = p.lots || [];
            if (lots.length === 0) {
                addProductToCart(p, null);
                return;
            }
            if (lots.length === 1) {
                addProductToCart(p, lots[0].code_lot_produit);
                return;
            }
            pendingProduct = p;
            selectedLotId = null;
            const list = $('lotSelectionList');
            list.innerHTML = lots.map(l => `
                <div class="lot-option" data-lot="${esc(l.code_lot_produit)}" onclick="selectLotOption('${esc(l.code_lot_produit)}')">
                    <div class="lot-info"><strong>${esc(l.titre_lot)}</strong><small>${l.unites_par_lot} unité(s) par lot — disponible : ${l.quantite}</small></div>
                    <div class="lot-stock">${l.quantite} restant(s)</div>
                </div>
            `).join('');
            if (lots.length > 0) {
                const first = lots[0].code_lot_produit;
                selectLotOption(first);
                document.querySelectorAll('.lot-option').forEach(el => el.classList.remove('selected'));
                document.querySelector(`.lot-option[data-lot="${first}"]`)?.classList.add('selected');
            }
            $('lotQtyInput').value = 1;
            openModal('lotModal');
        }

        function selectLotOption(lotId) {
            selectedLotId = lotId;
            document.querySelectorAll('.lot-option').forEach(el => el.classList.remove('selected'));
            document.querySelector(`.lot-option[data-lot="${lotId}"]`)?.classList.add('selected');
        }

        $('btnConfirmLot').addEventListener('click', function() {
            if (!pendingProduct) {
                toast('Aucun produit en attente', 'error');
                return;
            }
            if (!selectedLotId) {
                toast('Veuillez sélectionner un lot', 'error');
                return;
            }
            const qty = parseInt($('lotQtyInput').value) || 1;
            if (qty < 1) {
                toast('Quantité invalide', 'error');
                return;
            }
            const lot = pendingProduct.lots.find(l => l.code_lot_produit === selectedLotId);
            if (lot && parseInt(lot.quantite) < qty) {
                toast('Stock insuffisant pour ce lot', 'error');
                return;
            }
            addProductToCart(pendingProduct, selectedLotId, qty);
            closeModal('lotModal');
        });

        // ===== AJOUT AU PANIER =====
        async function addProductToCart(product, lotId, qty = 1) {
            const stock = parseInt(product.stock) || 0;
            if (stock <= 0) {
                toast('Rupture de stock', 'error');
                return;
            }

            const existing = cart.find(item => item.code === product.code_produit && item.lot_id === lotId);
            let item;
            if (existing) {
                if (existing.qte + qty > stock) {
                    toast('Stock max atteint', 'error');
                    return;
                }
                existing.qte += qty;
                item = existing;
            } else {
                const totalQte = cart.filter(i => i.code === product.code_produit).reduce((s, i) => s + i.qte, 0) + qty;
                const prix = await getProductPrice(product.code_produit, totalQte);
                item = {
                    code: product.code_produit,
                    nom: product.titre_produit,
                    prix,
                    prix_achat: parseFloat(product.prix_fournisseur) || 0,
                    qte: qty,
                    stock: stock,
                    montant: 0,
                    categorie: product.categorie || '',
                    lot_id: lotId
                };
                cart.push(item);
            }
            item.montant = item.qte * item.prix;
            renderCart();
            refreshProductCards();
            toast('Produit ajouté');
        }

        async function getProductPrice(produitId, quantite) {
            try {
                const res = await fetch(BASE_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'get_product_price',
                        produit_id: produitId,
                        quantite
                    })
                });
                const data = await res.json();
                if (data.success) return parseFloat(data.prix) || 0;
                else return 0;
            } catch (e) {
                return 0;
            }
        }

        // ===== FONCTIONS PANIER GLOBALES =====
        window.updateQty = function(code, lotId, delta) {
            const item = cart.find(p => p.code === code && (p.lot_id || '') === (lotId || ''));
            if (!item) return;
            const newQty = item.qte + delta;
            if (newQty <= 0) {
                window.removeProduct(code, lotId);
                return;
            }
            if (newQty > item.stock) {
                toast('Stock max', 'error');
                return;
            }
            item.qte = newQty;
            const totalQte = cart.filter(i => i.code === code).reduce((s, i) => s + i.qte, 0);
            getProductPrice(code, totalQte).then(prix => {
                item.prix = prix;
                item.montant = item.qte * item.prix;
                renderCart();
            });
        };

        window.removeProduct = function(code, lotId) {
            cart = cart.filter(p => !(p.code === code && (p.lot_id || '') === (lotId || '')));
            renderCart();
            refreshProductCards();
            toast('Retiré');
        };

        // ===== RENDU PANIER =====
        function renderCart() {
            if (cart.length === 0) {
                $('cartItems').innerHTML = `<div class="cart-empty"><i class="bi bi-cart-x"></i><p>Panier vide</p></div>`;
                $('cartCount').textContent = '0';
                $('btnCheckout').disabled = true;
                $('pdfExportBtn').disabled = true;
            } else {
                let html = '';
                cart.forEach(p => {
                    html += `
                        <div class="cart-line" data-code="${esc(p.code)}" data-lotid="${esc(p.lot_id||'')}">
                            <div class="cl-header">
                                <div class="cl-name">${esc(p.nom)}</div>
                                ${p.lot_id ? `<span class="cl-lot">${esc(p.lot_id)}</span>` : ''}
                                <button class="cl-remove" onclick="window.removeProduct('${p.code}','${p.lot_id||''}')"><i class="bi bi-trash"></i></button>
                            </div>
                            <div class="cl-footer">
                                <div class="qty-selector">
                                    <button onclick="window.updateQty('${p.code}','${p.lot_id||''}',-1)">-</button>
                                    <span>${p.qte}</span>
                                    <button onclick="window.updateQty('${p.code}','${p.lot_id||''}',1)">+</button>
                                </div>
                                <div style="text-align:right;">
                                    <div class="cl-total">${fmt(p.montant)}</div>
                                    <div class="cl-unit">${fmt(p.prix)} / unité</div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                $('cartItems').innerHTML = html;
                $('cartCount').textContent = cart.reduce((s, p) => s + p.qte, 0);
                $('btnCheckout').disabled = false;
                $('pdfExportBtn').disabled = !lastFactureNum;
            }
            calculateTotals();
        }

        $('btnClear').addEventListener('click', function() {
            if (cart.length === 0) return;
            cart = [];
            renderCart();
            refreshProductCards();
            toast('Panier vidé');
        });

        function refreshProductCards() {
            const q = $('searchInput').value.trim();
            renderProducts(currentProducts, q.length >= 2 ? q : '');
        }

        // ===== TOTAUX =====
        function calculateTotals() {
            const ht = cart.reduce((s, p) => s + p.montant, 0);
            const taxRate = parseFloat($('taxRate').value) || 0;
            const discRate = parseFloat($('discountRate').value) || 0;
            const tax = Math.round(ht * taxRate / 100);
            const disc = Math.round(ht * discRate / 100);
            const ttc = Math.round(ht + tax - disc);
            $('totalHT').textContent = fmt(ht);
            $('totalRemise').textContent = fmt(disc);
            $('totalTTC').textContent = fmt(ttc);
        }
        $('taxRate').addEventListener('change', calculateTotals);
        $('discountRate').addEventListener('change', calculateTotals);

        function getTTC() {
            const ht = cart.reduce((s, p) => s + p.montant, 0);
            const taxRate = parseFloat($('taxRate').value) || 0;
            const discRate = parseFloat($('discountRate').value) || 0;
            return Math.round(ht + Math.round(ht * taxRate / 100) - Math.round(ht * discRate / 100));
        }

        // ===== PAIEMENT =====
        $('btnCheckout').addEventListener('click', function() {
            if (!selectedClient) {
                toast('Sélectionnez un client', 'error');
                return;
            }
            if (cart.length === 0) {
                toast('Panier vide', 'error');
                return;
            }
            calculateTotals();
            $('payAmount').textContent = fmt(getTTC());
            $('receivedAmount').value = '';
            $('changeAmount').textContent = '0 FCFA';
            $('changeBox').style.background = 'var(--color-primary-soft)';
            $('changeLbl').textContent = 'Monnaie à rendre';
            openModal('payModal');
        });

        document.querySelectorAll('.pay-mode').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.pay-mode').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                payMode = this.dataset.mode;
            });
        });

        $('receivedAmount').addEventListener('input', function() {
            const received = parseFloat(this.value) || 0;
            const ttc = getTTC();
            const change = received - ttc;
            if (change >= 0) {
                $('changeLbl').textContent = 'Monnaie à rendre';
                $('changeAmount').textContent = fmt(change);
                $('changeBox').style.background = 'var(--color-primary-soft)';
            } else {
                $('changeLbl').textContent = 'Montant restant';
                $('changeAmount').textContent = fmt(Math.abs(change));
                $('changeBox').style.background = '#fee2e2';
            }
        });

        $('btnValidatePay').addEventListener('click', function() {
            const received = parseFloat($('receivedAmount').value) || 0;
            const ttc = getTTC();
            if (received < ttc && payMode === 'Espece') {
                toast('Montant insuffisant', 'error');
                return;
            }
            const advance = Math.min(received, ttc);
            const data = {
                panier: cart.map(p => ({
                    code: p.code,
                    prix: p.prix,
                    prix_achat: p.prix_achat,
                    qte: p.qte,
                    montant: p.montant,
                    lot_id: p.lot_id || null
                })),
                client_id: selectedClient.code,
                mode_reglement: payMode,
                avance: advance,
                taux_tva: parseFloat($('taxRate').value) || 0,
                taux_remise: parseFloat($('discountRate').value) || 0,
                csrf_token: CSRF_TOKEN
            };
            this.disabled = true;
            this.innerHTML = '<i class="bi bi-spinner"></i> Validation...';
            api('valider_vente', data)
                .then(res => {
                    this.disabled = false;
                    this.innerHTML = '<i class="bi bi-check-lg"></i> Valider';
                    if (res.success) {
                        closeModal('payModal');
                        lastFactureNum = res.facture;
                        // Activer les boutons PDF
                        $('pdfExportBtn').disabled = false;
                        $('pdfFactureNum').value = lastFactureNum;
                        $('pdfFactureNumTicket').value = lastFactureNum;
                        generateTicket(res);
                        openModal('ticketModal');
                        toast('Vente validée !');
                    } else {
                        toast(res.message || res.error || 'Erreur', 'error');
                    }
                })
                .catch(err => {
                    this.disabled = false;
                    this.innerHTML = '<i class="bi bi-check-lg"></i> Valider';
                    toast('Erreur connexion', 'error');
                });
        });

        // ===== TICKET =====
        function generateTicket(res) {
            const now = new Date();
            const dateStr = now.toLocaleDateString('fr-FR');
            const timeStr = now.toLocaleTimeString('fr-FR');
            const linesHTML = cart.map(p => `
                <tr><td style="text-align:left;padding:4px 0;font-size:12px;">${esc(p.nom)}${p.lot_id ? ' <span style="color:#64748b;font-size:10px;">('+esc(p.lot_id)+')</span>' : ''}</td><td style="text-align:center;padding:4px 0;font-size:12px;">${p.qte}</td><td style="text-align:right;padding:4px 0;font-size:12px;">${fmt(p.prix)}</td><td style="text-align:right;padding:4px 0;font-size:12px;font-weight:600;">${fmt(p.montant)}</td></tr>
            `).join('');
            const t = res.totaux;
            $('printZone').innerHTML = `
                <div style="font-family:monospace;max-width:300px;margin:0 auto;">
                    <div style="text-align:center;font-weight:700;font-size:16px;margin-bottom:4px;">CAISSE COMPTOIR</div>
                    <div style="text-align:center;font-size:11px;color:#64748b;margin-bottom:12px;">${dateStr} ${timeStr}</div>
                    <div style="font-size:11px;margin-bottom:8px;">Client: ${esc(selectedClient.name)} (${selectedClient.code})</div>
                    <div style="font-size:11px;margin-bottom:12px;">Facture: ${res.facture}</div>
                    <hr style="border:1px dashed #ccc;">
                    <table style="width:100%;border-collapse:collapse;"><thead><tr style="font-size:11px;font-weight:600;color:#64748b;"><th style="text-align:left;">Produit</th><th style="text-align:center;">Qté</th><th style="text-align:right;">Prix</th><th style="text-align:right;">Montant</th></tr></thead><tbody>${linesHTML}</tbody></table>
                    <hr style="border:1px dashed #ccc;">
                    <div style="font-size:12px;margin-top:8px;">
                        <div style="display:flex;justify-content:space-between;"><span>HT</span><span>${fmt(t.ht)}</span></div>
                        ${t.taxe>0?`<div style="display:flex;justify-content:space-between;"><span>TVA</span><span>${fmt(t.taxe)}</span></div>`:''}
                        ${t.remise>0?`<div style="display:flex;justify-content:space-between;"><span>Remise</span><span>${fmt(t.remise)}</span></div>`:''}
                        <div style="display:flex;justify-content:space-between;font-weight:700;font-size:14px;margin-top:4px;"><span>TTC</span><span>${fmt(t.ttc)}</span></div>
                        <div style="display:flex;justify-content:space-between;color:#2563eb;"><span>Avance</span><span>${fmt(parseFloat($('receivedAmount').value)||t.ttc)}</span></div>
                        ${t.reste>0?`<div style="display:flex;justify-content:space-between;color:#ef4444;"><span>Reste</span><span>${fmt(t.reste)}</span></div>`:''}
                    </div>
                    <hr style="border:1px dashed #ccc;margin-top:8px;">
                    <div style="text-align:center;font-size:10px;color:#94a3b8;margin-top:8px;">Merci !</div>
                </div>
            `;
        }

        function resetSale() {
            cart = [];
            selectedClient = null;
            lastFactureNum = null;
            renderCart();
            resetClient();
            loadInitialProducts();
            $('taxRate').value = '0';
            $('discountRate').value = '0';
            calculateTotals();
            $('pdfExportBtn').disabled = true;
            $('pdfFactureNum').value = '';
            $('pdfFactureNumTicket').value = '';
        }

        // Ouvrir modale client avec pré-remplissage
        document.querySelector('[data-bs-target="#clientModal"]').addEventListener('click', function() {
            const q = $('clientSearch').value.trim();
            if (q) $('newClientName').value = q;
        });

        // Fermer modale lot en cliquant sur l'overlay
        document.querySelector('#lotModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal('lotModal');
        });
    </script>
</body>

</html>