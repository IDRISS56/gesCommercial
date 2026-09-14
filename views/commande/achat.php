<?php
// achat_fournisseur.php – Enregistrement des achats / factures fournisseur
// Même design (POS) et mêmes conventions que vente_comptoir.php, mais en sens
// inverse : ENTRÉE de stock au lieu de sortie. Le règlement du fournisseur est
// géré par une interface dédiée existante — cette page ne fait qu'enregistrer
// l'achat et faire entrer le stock.

require 'databases/database.php';
while (ob_get_level()) ob_end_clean();
ob_start();

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$isAjax = $isAjax || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

// Récupération utilisateur et boutique
$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id'] ?? '']);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$userInfo) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Utilisateur inactif ou session expirée']);
        exit;
    }
    header('Location: ../utilisateur/login');
    exit;
}

define('USER_ID', $_SESSION['user_id']);
define('USER_BOUTIQUE', $userInfo['boutique_id'] ?? null);
// Boutiques que cet utilisateur a le droit d'utiliser pour un achat.
$boutiquesAutorisees = getBoutiquesAutorisees($pdo, $userInfo['role'], USER_BOUTIQUE);

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ==========================================
// TRAITEMENT AJAX
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($action) {

            // ===== FOURNISSEURS POUR LE SELECTPICKER =====
            case 'get_fournisseurs':
                $q = trim($_POST['q'] ?? '');
                $sql = "SELECT code_contact, nom_prenom_contact, telephone_contact, adresse_contact, statut_contact
                        FROM contact
                        WHERE type_contact = 'Fournisseur' AND etat_contact = 'Actif'";
                $params = [];
                if ($q) {
                    $sql .= " AND (nom_prenom_contact LIKE ? OR telephone_contact LIKE ? OR code_contact LIKE ?)";
                    $params = ["%$q%", "%$q%", "%$q%"];
                }
                $sql .= " ORDER BY nom_prenom_contact ASC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['success' => true, 'fournisseurs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                exit;

            // ===== CRÉER UN FOURNISSEUR =====
            case 'create_fournisseur':
                $token = $_POST['csrf_token'] ?? '';
                if ($token !== $csrf_token) {
                    echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
                    exit;
                }
                $nom = trim($_POST['nom'] ?? '');
                if (!$nom) {
                    echo json_encode(['success' => false, 'message' => 'Nom requis']);
                    exit;
                }
                $numFourn = 'CT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO contact (code_contact, nom_prenom_contact, telephone_contact, email_contact, type_contact, statut_contact, adresse_contact, etat_contact)
                                       VALUES (?, ?, ?, ?, 'Fournisseur', ?, ?, 'Actif')");
                $stmt->execute([
                    $numFourn, $nom, $_POST['tel'] ?? '', $_POST['email'] ?? '',
                    $_POST['statut'] ?? 'Société', $_POST['adresse'] ?? ''
                ]);
                echo json_encode(['success' => true, 'code' => $numFourn, 'nom' => $nom]);
                exit;

            // ===== PRODUITS (tous, y compris en rupture — c'est ce qu'on réapprovisionne) =====
            case 'get_products':
                $q = trim($_POST['q'] ?? '');
                $cat = $_POST['categorie'] ?? 'Tous';
                $sql = "SELECT p.code_produit, p.titre_produit, p.prix_fournisseur, p.prix_produit,
                               p.etat_produit, p.saisie_par_carton, COALESCE(c.titre_categorie, 'Autre') as categorie,
                               COALESCE(sb.quantite, CAST(p.stock_produit AS SIGNED)) as stock
                        FROM produit p
                        LEFT JOIN categorie c ON p.categorie_id = c.code_categorie
                        LEFT JOIN stock sb ON sb.produit_id = p.code_produit AND sb.boutique_id = ?
                        WHERE 1=1";
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
                $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Lots catalogue (menu "Configuration des lots") des produits renvoyés :
                // structure fixe (libelle + unites_par_lot), seul cout_lot est modifiable
                // au moment de l'achat.
                if ($produits) {
                    $codes = array_column($produits, 'code_produit');
                    $in = implode(',', array_fill(0, count($codes), '?'));
                    $stmtLots = $pdo->prepare("SELECT produit_id, libelle, unites_par_lot, cout_lot FROM lot WHERE etat_lot = 'Actif' AND produit_id IN ($in)");
                    $stmtLots->execute($codes);
                    $lotsParProduit = [];
                    foreach ($stmtLots->fetchAll(PDO::FETCH_ASSOC) as $l) {
                        $lotsParProduit[$l['produit_id']][] = [
                            'libelle' => $l['libelle'],
                            'unites_par_lot' => (int) $l['unites_par_lot'],
                            'cout_lot' => $l['cout_lot'] !== null ? (float) $l['cout_lot'] : null,
                        ];
                    }
                    foreach ($produits as &$p) {
                        $p['lots'] = $lotsParProduit[$p['code_produit']] ?? [];
                    }
                    unset($p);
                }

                echo json_encode(['success' => true, 'products' => $produits]);
                exit;

            // ===== VALIDER L'ACHAT =====
            case 'valider_achat':
                $token = $_POST['csrf_token'] ?? '';
                if ($token !== $csrf_token) {
                    echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']);
                    exit;
                }

                $panier = json_decode($_POST['panier'] ?? '[]', true) ?: [];
                if (empty($panier)) throw new Exception('Le panier est vide.');

                $fournisseur_id = $_POST['fournisseur_id'] ?? null;
                if (empty($fournisseur_id)) throw new Exception('Veuillez sélectionner un fournisseur.');

                $boutique_id = trim($_POST['boutique_id'] ?? '') ?: USER_BOUTIQUE;
                if (!in_array($boutique_id, $boutiquesAutorisees, true)) $boutique_id = USER_BOUTIQUE;
                if (empty($boutique_id)) throw new Exception('Veuillez sélectionner une boutique de réception.');

                $is_attente = filter_var($_POST['en_attente'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $maj_prix_fournisseur = filter_var($_POST['maj_prix_fournisseur'] ?? false, FILTER_VALIDATE_BOOLEAN);

                $montantHT = 0;
                foreach ($panier as $item) {
                    $montantHT += floatval($item['montant'] ?? (floatval($item['prix_achat'] ?? 0) * intval($item['qte'] ?? 1)));
                }
                $montantTTC = round($montantHT, 2); // pas de TVA sur les achats fournisseur par défaut

                if ($is_attente) {
                    $etatFacture = 'Impayee';
                    $statutFacture = 'En attente';
                    $categorieDocument = 'Bon';
                } else {
                    $statutFacture = 'Validee';
                    $categorieDocument = 'Facture';
                }

                $titreDocument = 'Facture fournisseur';
                $numDocument = 'FAC-FOUR-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

                $pdo->beginTransaction();
                try {
                    if ($is_attente) {
                        // Un bon en attente n'engage aucune dette tant qu'il n'est pas
                        // validé : on ne touche donc pas encore à l'avance du fournisseur ici.
                        $avance = 0;
                        $reste = $montantTTC;
                    } else {
                        // Si le fournisseur est en avance (solde_contact < 0), cette avance
                        // couvre directement la nouvelle facture : totalement -> Payée,
                        // partiellement -> Partielle (le reste s'ajoute au solde/dette), et
                        // sans avance -> Impayée comme avant.
                        $stmtSoldeF = $pdo->prepare("SELECT solde_contact FROM contact WHERE code_contact = ? FOR UPDATE");
                        $stmtSoldeF->execute([$fournisseur_id]);
                        $soldeAvantF = floatval($stmtSoldeF->fetchColumn());
                        $avanceDispoF = max(0, -$soldeAvantF);

                        $avance = min($avanceDispoF, $montantTTC);
                        $reste = round($montantTTC - $avance, 2);
                        if ($reste <= 0) {
                            $etatFacture = 'Payee';
                        } elseif ($avance > 0) {
                            $etatFacture = 'Partielle';
                        } else {
                            $etatFacture = 'Impayee';
                        }
                    }

                    // 1. FACTURE FOURNISSEUR
                    $stmtDoc = $pdo->prepare("INSERT INTO facture(numero_facture, titre_facture, type_facture, categorie_facture, date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, contact_id, utilisateur_id, etat_facture, statut_facture)
                                              VALUES (?, ?, 'Fournisseur', ?, CURDATE(), ?, 0, 0, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtDoc->execute([$numDocument, $titreDocument . ' ' . $numDocument, $categorieDocument,
                                        $montantHT, $montantTTC, $avance, $reste, $fournisseur_id, USER_ID,
                                        $etatFacture, $statutFacture]);

                    // 2. LIGNES DE COMMANDE (statut_id 011 = Achat/ENTREE) + entrée en stock
                    $libellesLotValides = ['Boîte', 'Palette', 'Carton', 'Bidon', 'Unité'];
                    $numBase = date('dmYHis');
                    foreach ($panier as $i => $ligne) {
                        $code_prod = $ligne['code'] ?? '';
                        $qte = max(1, intval($ligne['qte'] ?? 1));
                        $prix_achat = floatval($ligne['prix_achat'] ?? 0);
                        $montant = floatval($ligne['montant'] ?? ($prix_achat * $qte));
                        $prixLotLigne = (isset($ligne['prix_lot']) && $ligne['prix_lot'] !== null && $ligne['prix_lot'] !== '') ? round((float)$ligne['prix_lot'], 2) : null;
                        if (!$code_prod) continue;

                        // Configuration de lot (optionnelle) : si l'utilisateur n'a pas
                        // configuré de lot pour cette ligne, on reste sur le comportement
                        // "produit simple" (lot_id NULL, produits_par_lot = 1), et le bon/
                        // la facture afficheront "X Produit" plutôt que de parler de lot.
                        $lotConfigure = filter_var($ligne['lot_configure'] ?? false, FILTER_VALIDATE_BOOLEAN);
                        $unitesParLot = max(2, intval($ligne['unites_par_lot'] ?? 2));
                        $libelleLot = in_array($ligne['libelle_lot'] ?? '', $libellesLotValides, true) ? $ligne['libelle_lot'] : 'Unité';

                        $lot_id = null;
                        $produits_par_lot = 1;
                        if ($lotConfigure) {
                            // Un lot est déjà configuré pour ce produit (même libellé) ?
                            // On le réutilise — le lot ne doit être créé qu'une seule fois,
                            // pas à chaque nouvel achat du même produit.
                            $stmtLotExist = $pdo->prepare("SELECT code_lot, unites_par_lot FROM lot WHERE produit_id = ? AND libelle = ? AND etat_lot = 'Actif' LIMIT 1");
                            $stmtLotExist->execute([$code_prod, $libelleLot]);
                            $lotExistant = $stmtLotExist->fetch(PDO::FETCH_ASSOC);

                            if ($lotExistant) {
                                $lot_id = $lotExistant['code_lot'];
                                $produits_par_lot = (int) $lotExistant['unites_par_lot'];
                            } else {
                                $lot_id = 'LOT-' . date('YmdHis') . rand(100, 999) . '-' . $i;
                                $produits_par_lot = $unitesParLot;
                                $pdo->prepare("INSERT INTO lot (code_lot, libelle, unites_par_lot, produit_id, quantite, etat_lot)
                                              VALUES (?, ?, ?, ?, ?, 'Actif')")
                                    ->execute([$lot_id, $libelleLot, $unitesParLot, $code_prod, $qte]);
                            }
                        }

                        $numCmd = 'BC-' . $numBase . str_pad($i, 2, '0', STR_PAD_LEFT);
                        $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, lot_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, prix_lot_ligne, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                                      VALUES (?, ?, ?, ?, ?, '011', CURDATE(), CURTIME(), ?, 0, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$numCmd, $code_prod, $lot_id, $fournisseur_id, $numDocument,
                                       $prix_achat, $prixLotLigne, $qte, $produits_par_lot, $montant, USER_ID, $boutique_id,
                                       $is_attente ? 'EN ATTENTE' : 'VALIDEE']);

                        // Entrée en stock : UNIQUEMENT si l'achat est validé directement
                        // (marchandise reçue). Si l'achat est "en attente" (Bon fournisseur,
                        // pas encore confirmé), le stock ne doit pas bouger tant que la
                        // réception n'a pas été confirmée dans le suivi des achats.
                        if (!$is_attente) {
                            // Entrée en stock (boutique choisie par l'utilisateur)
                            $pdo->prepare("INSERT INTO stock (produit_id, boutique_id, quantite, stock_alerte)
                                          VALUES (?, ?, ?, 10)
                                          ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)")
                                ->execute([$code_prod, $boutique_id, $qte]);

                            // Entrée en stock (compteur global produit)
                            $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) + ? AS CHAR) WHERE code_produit = ?")
                                ->execute([$qte, $code_prod]);

                            // Mise à jour état produit (peut sortir de RUPTURE/ALERTE)
                            $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                            WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                            WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                            ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                                ->execute([$code_prod]);
                        }

                        // Option : mettre à jour le prix d'achat de référence du produit
                        if ($maj_prix_fournisseur) {
                            $pdo->prepare("UPDATE produit SET prix_fournisseur = ? WHERE code_produit = ?")
                                ->execute([$prix_achat, $code_prod]);
                        }
                    }

                    // 3. SOLDE DU CONTACT (compte fournisseur)
                    // Un achat "Bon" en attente n'engage aucune dette tant qu'il n'est pas validé.
                    // Une facture Validée (avance toujours = 0 ici) crédite intégralement le
                    // fournisseur : on lui doit maintenant reste = montantTTC.
                    // Convention : solde_contact > 0 => on doit ce montant au fournisseur
                    //              solde_contact < 0 => le fournisseur nous doit (trop-perçu)
                    if ($statutFacture === 'Validee' && $montantTTC != 0) {
                        $pdo->prepare("UPDATE contact SET solde_contact = solde_contact + ? WHERE code_contact = ?")
                            ->execute([$montantTTC, $fournisseur_id]);
                    }

                    $pdo->commit();
                    echo json_encode([
                        'success' => true,
                        'document' => $numDocument,
                        'type_document' => $categorieDocument,
                        'etat' => $etatFacture,
                        'statut' => $statutFacture,
                        'totaux' => ['ht' => $montantHT, 'ttc' => $montantTTC, 'reste' => $reste]
                    ]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
                exit;

            default:
                echo json_encode(['success' => false, 'message' => 'Action inconnue']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// - RÉCUPÉRATION DES DONNÉES POUR LA PAGE -
$categories = $pdo->query("SELECT DISTINCT c.titre_categorie
                           FROM produit p
                           JOIN categorie c ON p.categorie_id = c.code_categorie
                           WHERE c.titre_categorie IS NOT NULL AND c.titre_categorie <> ''
                           ORDER BY c.titre_categorie ASC")->fetchAll(PDO::FETCH_COLUMN);

// Boutiques actives : l'utilisateur choisit celle qui reçoit la marchandise
// (pré-sélectionnée sur sa propre boutique quand il en a une).
if (empty($boutiquesAutorisees)) {
    $boutiques = [];
} else {
    $inPhB = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $stmtBou = $pdo->prepare("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' AND code_boutique IN ($inPhB) ORDER BY nom_boutique ASC");
    $stmtBou->execute($boutiquesAutorisees);
    $boutiques = $stmtBou->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achat / Facture Fournisseur</title>
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
        .checkbox-row { display: flex; align-items: center; gap: 6px; font-size: 12px; margin-top: 10px; color: var(--color-gray-500); }
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
        /* Toast */
        .toast-msg { position: fixed; top: 20px; right: 20px; background: var(--color-success); color: #fff; padding: 12px 20px; border-radius: 10px; font-weight: 600; z-index: 2000; display: none; box-shadow: 0 4px 12px rgba(0,0,0,.15); }
        .toast-msg.error { background: var(--color-danger); }
        .toast-msg.show { display: block; animation: slideIn .3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        /* Bootstrap select custom */
        .bootstrap-select .dropdown-toggle { background: #fff !important; border: 1.5px solid var(--border-color) !important; border-radius: 8px !important; }
        .bootstrap-select .dropdown-toggle:focus { border-color: var(--color-primary) !important; box-shadow: 0 0 0 3px var(--color-primary-soft) !important; }
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
            <h2><i class="bi bi-box-arrow-in-down"></i> Achat Fournisseur</h2>
            <div class="d-flex align-items-center gap-3">
                <div style="display:flex;align-items:center;gap:6px;">
                    <label for="boutiqueSelect" style="font-size:11px;font-weight:600;color:var(--color-gray-500);text-transform:uppercase;white-space:nowrap;">Boutique de réception</label>
                    <select id="boutiqueSelect" class="selectpicker" data-live-search="true" data-width="220px" data-container="body">
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($boutiques as $b): ?>
                            <option value="<?= htmlspecialchars($b['code_boutique']) ?>" <?= ($b['code_boutique'] === USER_BOUTIQUE) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['nom_boutique']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="text-muted small"><i class="bi bi-person"></i> <?= htmlspecialchars($userInfo['nom_prenom'] ?? '') ?></span>
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

    <!-- DROITE : LIGNES D'ACHAT -->
    <div class="pos-right">
        <div class="cart-header">
            <h2><i class="bi bi-receipt"></i> Achat <span class="cart-badge" id="cartCount">0</span></h2>
        </div>
        <div class="client-select-zone">
            <div id="fournisseurDisplay" style="display:none;">
                <div class="client-display">
                    <div>
                        <div class="cl-name" id="fournisseurName"></div>
                        <div class="cl-code" id="fournisseurCode"></div>
                    </div>
                    <button class="btn-change" onclick="resetFournisseur()">Changer</button>
                </div>
            </div>
            <div id="fournisseurSelectWrapper">
                <label class="form-label" style="font-size:11px;font-weight:600;color:var(--color-gray-500);text-transform:uppercase;">Fournisseur</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <div style="flex:1;min-width:0;">
                        <select id="fournisseurSelect" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un fournisseur..." data-width="100%">
                            <option value="">-- Sélectionner un fournisseur --</option>
                        </select>
                    </div>
                    <button class="btn btn-sm btn-outline-primary flex-shrink-0" style="height:34px;white-space:nowrap;" onclick="openModal('fournisseurModal')" title="Nouveau fournisseur">
                        <i class="bi bi-plus-lg"></i> Nouveau
                    </button>
                </div>
            </div>
        </div>
        <div class="cart-items" id="cartItems">
            <div class="cart-empty"><i class="bi bi-cart-x"></i><p>Aucun produit ajouté</p></div>
        </div>
        <div class="cart-footer">
            <div class="total-grand">
                <span>TOTAL ACHAT</span>
                <span class="t-value" id="totalTTC">0 FCFA</span>
            </div>
            <div class="checkbox-row">
                <input type="checkbox" id="majPrixFournisseur">
                <label for="majPrixFournisseur">Mettre à jour le prix d'achat de référence des produits</label>
            </div>
            <div class="actions-row">
                <button class="btn-clear" id="btnClear"><i class="bi bi-trash3"></i> Vider</button>
                <button class="btn-attente" id="btnAttente" disabled><i class="bi bi-clock"></i> En attente</button>
                <button class="btn-pay" id="btnValider" disabled><i class="bi bi-check-circle"></i> Valider l'achat</button>
            </div>
            <p style="font-size:11px; color:var(--color-gray-500); margin-top:8px;">Le règlement du fournisseur se fait ensuite depuis votre interface de règlement fournisseur.</p>
        </div>
    </div>
</div>

<!-- Modal Nouveau Fournisseur -->
<div class="modal-overlay" id="fournisseurModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="bi bi-person-plus"></i> Nouveau fournisseur</h3>
            <button class="modal-close" onclick="closeModal('fournisseurModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <form id="fournisseurForm">
                <div class="form-group">
                    <label>Nom / Raison sociale *</label>
                    <input type="text" id="fournisseurNom" required>
                </div>
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="text" id="fournisseurTel">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="fournisseurEmail">
                </div>
                <div class="form-group">
                    <label>Adresse</label>
                    <input type="text" id="fournisseurAdresse">
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select id="fournisseurStatut">
                        <option value="Société">Société</option>
                        <option value="Particulier">Particulier</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal('fournisseurModal')">Annuler</button>
            <button class="btn btn-primary" onclick="createFournisseur()"><i class="bi bi-check-lg"></i> Créer</button>
        </div>
    </div>
</div>

<!-- Modal Confirmation générique (remplace les confirm() JS) -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box" style="width:360px;">
        <div class="modal-head">
            <h3><i class="bi bi-question-circle"></i> <span id="confirmModalTitle">Confirmation</span></h3>
            <button class="modal-close" onclick="closeModal('confirmModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <p id="confirmModalMsg" style="margin:0;font-size:14px;"></p>
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
let cart = [];
let selectedFournisseur = null;
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
    const newOkBtn = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOkBtn, okBtn);
    newOkBtn.addEventListener('click', function() {
        closeModal('confirmModal');
        onOk();
    });
    openModal('confirmModal');
}

// ===== TOUT EN POST =====
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

// ===== FOURNISSEURS (selectpicker) =====
async function loadFournisseurs() {
    try {
        const res = await api('get_fournisseurs', { q: '' });
        if (res.success) {
            const select = gid('fournisseurSelect');
            select.innerHTML = '<option value="">-- Sélectionner un fournisseur --</option>';
            res.fournisseurs.forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.code_contact;
                opt.textContent = f.nom_prenom_contact + (f.telephone_contact ? ' (' + f.telephone_contact + ')' : '');
                opt.dataset.name = f.nom_prenom_contact;
                opt.dataset.code = f.code_contact;
                select.appendChild(opt);
            });
            // destroy + reinit plutôt que 'refresh' : évite le menu dupliqué
            // (bug connu de bootstrap-select quand les options ont été
            // entièrement remplacées via innerHTML avant le refresh).
            jQuery('#fournisseurSelect').selectpicker('destroy');
            jQuery('#fournisseurSelect').selectpicker();
        }
    } catch (e) { console.error(e); }
}

jQuery(document).on('changed.bs.select', '#fournisseurSelect', function(e, clickedIndex) {
    const opt = this.options[clickedIndex];
    if (opt && opt.value) {
        selectedFournisseur = { code: opt.value, name: opt.dataset.name };
        gid('fournisseurDisplay').style.display = 'block';
        gid('fournisseurSelectWrapper').style.display = 'none';
        gid('fournisseurName').textContent = selectedFournisseur.name;
        gid('fournisseurCode').textContent = selectedFournisseur.code;
        updateButtons();
    }
});

function resetFournisseur() {
    selectedFournisseur = null;
    gid('fournisseurDisplay').style.display = 'none';
    gid('fournisseurSelectWrapper').style.display = 'block';
    gid('fournisseurSelect').selectedIndex = 0;
    jQuery('#fournisseurSelect').selectpicker('destroy');
    jQuery('#fournisseurSelect').selectpicker();
    updateButtons();
}

async function createFournisseur() {
    const nom = gid('fournisseurNom').value.trim();
    if (!nom) { toast('Nom requis', 'error'); return; }
    try {
        const res = await api('create_fournisseur', {
            nom,
            tel: gid('fournisseurTel').value,
            email: gid('fournisseurEmail').value,
            adresse: gid('fournisseurAdresse').value,
            statut: gid('fournisseurStatut').value,
            csrf_token: CSRF_TOKEN
        });
        if (res.success) {
            closeModal('fournisseurModal');
            gid('fournisseurForm').reset();
            await loadFournisseurs();
            setTimeout(() => {
                const select = gid('fournisseurSelect');
                for (let i = 0; i < select.options.length; i++) {
                    if (select.options[i].value === res.code) {
                        select.selectedIndex = i;
                        jQuery('#fournisseurSelect').selectpicker('refresh');
                        selectedFournisseur = { code: res.code, name: res.nom };
                        gid('fournisseurDisplay').style.display = 'block';
                        gid('fournisseurSelectWrapper').style.display = 'none';
                        gid('fournisseurName').textContent = res.nom;
                        gid('fournisseurCode').textContent = res.code;
                        updateButtons();
                        break;
                    }
                }
            }, 300);
            toast('Fournisseur créé');
        } else {
            toast(res.message || 'Erreur', 'error');
        }
    } catch (e) { toast('Erreur connexion', 'error'); }
}

// ===== PRODUITS =====
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

// Achat : pas de blocage sur la rupture — c'est précisément ce qu'on réapprovisionne,
// et pas de plafond de quantité lié au stock existant.
function addProduct(idx) {
    const p = currentProducts[idx];
    if (!p) return;
    const existing = cart.find(item => item.code === p.code_produit);
    if (existing) {
        existing.qte += 1;
        recalculerMontantAchat(existing);
    } else {
        const prix = parseFloat(p.prix_fournisseur) || 0;
        cart.push({
            code: p.code_produit,
            nom: p.titre_produit,
            prix_achat: prix,
            prixUnitaire: prix,
            prixManuel: false,
            qte: 1,
            montant: prix,
            lots: p.lots || [],
            modeVente: 'unite',
            lotConfigure: false,
            unitesParLot: 1,
            libelleLot: 'Unité',
            // Produit spécial (voir fiche produit) : la quantité saisie/affichée
            // en mode lot est le nombre de CARTONS, pas de pièces. item.qte
            // reste toujours en pièces en interne (backend inchangé).
            saisieParCarton: !!p.saisie_par_carton
        });
    }
    renderCart();
    renderProducts(currentProducts, gid('searchInput').value.trim());
    toast('Produit ajouté');
}

// ============================================================
// NOUVEAU MODÈLE (lots) :
// - La quantité saisie/affichée est TOUJOURS le nombre de pièces, que la
//   ligne soit achetée à l'unité ou "par lot" (Carton, Boîte...).
// - Le prix saisi est le PRIX UNITAIRE du produit tant qu'aucun coût n'est
//   configuré pour le lot choisi. Dès qu'un coût de lot est configuré en
//   catalogue pour ce type de lot, le prix saisi devient le COÛT DU LOT
//   (modifiable : ça devient alors le nouveau coût du lot utilisé pour le
//   calcul), et le montant = (pièces / unités du lot) x coût du lot.
// ============================================================

function lotChoisiAchat(item) {
    if (!(item.lots || []).length || item.modeVente === 'unite') return null;
    return item.lots.find(l => l.libelle === item.modeVente) || null;
}

function coutLotConfigure(item) {
    const lotCat = lotChoisiAchat(item);
    return !!(lotCat && lotCat.cout_lot !== null && lotCat.cout_lot !== '' && lotCat.cout_lot !== undefined);
}

function unitesActuellesAchat(item) {
    const lotCat = lotChoisiAchat(item);
    if (lotCat) return lotCat.unites_par_lot || 1;
    if (!(item.lots || []).length && item.lotConfigure) return Math.max(2, parseInt(item.unitesParLot) || 2);
    return 1;
}

function recalculerMontantAchat(item) {
    if (coutLotConfigure(item)) {
        const unites = unitesActuellesAchat(item);
        item.montant = Math.round((item.qte / unites) * item.prix_achat * 100) / 100;
    } else {
        item.montant = item.qte * item.prix_achat;
    }
}

// Le "pas" de saisie : en pièces normalement, ou en unités-de-carton pour un
// produit "saisieParCarton" tant qu'un lot est sélectionné. item.qte reste
// TOUJOURS en pièces (backend/stock inchangés).
function pasSaisieAchat(item) {
    if (item.saisieParCarton && lotChoisiAchat(item)) return unitesActuellesAchat(item);
    return 1;
}

window.updateQty = function(code, delta) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    const pas = pasSaisieAchat(item);
    const newQty = item.qte + delta * pas;
    if (newQty <= 0) { window.removeProduct(code); return; }
    item.qte = newQty;
    recalculerMontantAchat(item);
    renderCart();
};

window.setQty = function(code, value) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    const saisie = parseInt(value, 10);
    if (isNaN(saisie) || saisie <= 0) { renderCart(); return; }
    item.qte = saisie * pasSaisieAchat(item); // conversion cartons -> pièces (pas=1 en mode normal)
    recalculerMontantAchat(item);
    renderCart();
};

window.updatePrice = function(code, newPrice) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    item.prix_achat = Math.max(0, parseFloat(newPrice) || 0);
    item.prixManuel = true;
    if (coutLotConfigure(item)) {
        const lotCat = lotChoisiAchat(item);
        if (lotCat) lotCat.cout_lot = item.prix_achat;
    } else {
        item.prixUnitaire = item.prix_achat;
    }
    recalculerMontantAchat(item);
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
        gid('cartItems').innerHTML = '<div class="cart-empty"><i class="bi bi-cart-x"></i><p>Aucun produit ajouté</p></div>';
        gid('cartCount').textContent = '0';
    } else {
        let html = '';
        cart.forEach(p => {
            const aCatalogue = (p.lots || []).length > 0;
            let apercu, lotBlock, labelPrix = 'P.A';

            if (aCatalogue) {
                // Produit catalogué : structure du lot fixe. La quantité saisie
                // est TOUJOURS le nombre de pièces. Le prix affiché est le coût
                // du lot si un coût est configuré pour ce type, sinon le prix
                // unitaire.
                const lotChoisi = p.lots.find(l => l.libelle === p.modeVente);
                if (lotChoisi) {
                    const unites = lotChoisi.unites_par_lot || 1;
                    const nbLots = Math.floor(p.qte / unites);
                    const reste = p.qte % unites;
                    apercu = reste > 0 ? `${nbLots} ${esc(p.modeVente)}(s) et ${reste} Produit(s)` : `${nbLots} ${esc(p.modeVente)}(s)`;
                    labelPrix = coutLotConfigure(p) ? ('Coût/' + esc(p.modeVente).toLowerCase()) : 'Prix unitaire';
                } else {
                    apercu = `${p.qte} Produit(s)`;
                }
                lotBlock = `
                <span>Acheter par :
                    <select onclick="event.stopPropagation()" onchange="setModeVente('${p.code}', this.value)">
                        <option value="unite" ${p.modeVente === 'unite' ? 'selected' : ''}>Unité</option>
                        ${p.lots.map(l => `<option value="${esc(l.libelle)}" ${p.modeVente === l.libelle ? 'selected' : ''}>${esc(l.libelle)} — ${l.unites_par_lot} unité(s)</option>`).join('')}
                    </select>
                    ${lotChoisi ? `<span style="margin-left:8px;"><strong>${apercu}</strong></span>` : ''}
                </span>`;
            } else {
                // Produit pas encore catalogué : configuration libre. La
                // quantité saisie est TOUJOURS le nombre de pièces ; le prix
                // reste le prix unitaire (pas de coût de lot ad-hoc).
                const unites = Math.max(1, parseInt(p.unitesParLot) || 1);
                if (p.lotConfigure && unites > 1) {
                    const nbLots = Math.floor(p.qte / unites);
                    const reste = p.qte % unites;
                    apercu = reste > 0 ? `${nbLots} ${esc(p.libelleLot)}(s) et ${reste} Produit(s)` : `${nbLots} ${esc(p.libelleLot)}(s)`;
                } else {
                    apercu = `${p.qte} Produit(s)`;
                }
                lotBlock = `
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
                </span>` : `<span style="margin-left:8px;">— ${apercu}</span>`}`;
            }

            html += `<div class="cart-line">
                <div class="cl-info">
                    <div class="cl-name">${esc(p.nom)}</div>
                    <div class="cl-price">${labelPrix}: <input type="number" value="${p.prix_achat}" onchange="updatePrice('${p.code}', this.value)" onclick="event.stopPropagation()"> FCFA</div>
                </div>
                <div class="cl-qty">
                    <button onclick="updateQty('${p.code}', -1)">-</button>
                    <input type="number" class="cl-qty-input" min="1" step="1"
                           title="${p.saisieParCarton && aCatalogue && p.modeVente !== 'unite' ? 'Nombre de cartons' : 'Nombre de pièces'}"
                           value="${(p.saisieParCarton && aCatalogue && p.modeVente !== 'unite' && p.lots.find(l => l.libelle === p.modeVente)) ? Math.floor(p.qte / (p.lots.find(l => l.libelle === p.modeVente).unites_par_lot || 1)) : p.qte}"
                           onclick="event.stopPropagation()"
                           onchange="setQty('${p.code}', this.value)">
                    <button onclick="updateQty('${p.code}', 1)">+</button>
                </div>
                <div class="cl-montant">${fmt(p.montant)}</div>
                <button class="cl-remove" onclick="removeProduct('${p.code}')"><i class="bi bi-x-circle"></i></button>
            </div>
            <div class="cl-lot-config" style="padding:4px 10px 8px;font-size:11px;color:var(--color-gray-500);">
                ${lotBlock}
            </div>`;
        });
        gid('cartItems').innerHTML = html;
        gid('cartCount').textContent = cart.reduce((s, p) => s + p.qte, 0);
    }
    gid('totalTTC').textContent = fmt(cartTotal());
    updateButtons();
}

// ----- Changement de mode d'achat (catalogue) : la quantité (en pièces) n'a
// pas besoin d'être réinitialisée. Le prix devient le coût du lot configuré
// en catalogue s'il existe, sinon le prix unitaire du produit. -----
window.setModeVente = function(code, value) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    item.modeVente = value;
    item.prixManuel = false;
    if (value === 'unite') {
        item.prix_achat = item.prixUnitaire;
    } else {
        const lotCat = (item.lots || []).find(l => l.libelle === value);
        item.prix_achat = (lotCat && lotCat.cout_lot !== null && lotCat.cout_lot !== '' && lotCat.cout_lot !== undefined)
            ? parseFloat(lotCat.cout_lot)
            : item.prixUnitaire;
    }
    recalculerMontantAchat(item);
    renderCart();
};

// ----- Mode "ad-hoc" (produit pas encore catalogué) : la case ne fait que
// changer le libellé d'affichage, la quantité reste en pièces et le prix
// reste le prix unitaire. -----
window.toggleLotConfig = function(code, checked) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    item.lotConfigure = checked;
    if (checked && (!item.unitesParLot || item.unitesParLot < 2)) item.unitesParLot = 2;
    recalculerMontantAchat(item);
    renderCart();
};

window.setLotUnites = function(code, value) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    item.unitesParLot = Math.max(2, parseInt(value, 10) || 2);
    recalculerMontantAchat(item);
    renderCart();
};

window.setLotLibelle = function(code, value) {
    const item = cart.find(p => p.code === code);
    if (!item) return;
    item.libelleLot = value;
    renderCart();
};

function cartTotal() {
    return cart.reduce((s, p) => s + p.montant, 0);
}

function updateButtons() {
    const boutiqueOk = !!gid('boutiqueSelect').value;
    const ok = cart.length > 0 && selectedFournisseur && boutiqueOk;
    gid('btnAttente').disabled = !ok;
    gid('btnValider').disabled = !ok;
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

// ===== ENREGISTRER EN ATTENTE (Bon fournisseur) =====
gid('btnAttente').addEventListener('click', function() {
    confirmModal('Enregistrer cet achat en attente (Bon fournisseur) ?', function() {
        validerAchat(true);
    });
});

// ===== VALIDER L'ACHAT (Facture) — recharge aussitôt le solde du fournisseur,
// exactement comme la validation d'une facture client le fait pour le solde
// client (voir action 'valider_achat' côté serveur ci-dessus) =====
gid('btnValider').addEventListener('click', function() {
    confirmModal(`Valider cet achat de ${fmt(cartTotal())} auprès de ${selectedFournisseur.name} ?`, function() {
        validerAchat(false);
    });
});

async function validerAchat(enAttente) {
    try {
        const res = await api('valider_achat', {
            panier: JSON.stringify(cart.map(c => {
                const aCatalogue = (c.lots || []).length > 0;
                let lotConfigure, unitesParLot, libelleLot, prixUnite, prixLot;
                if (aCatalogue && c.modeVente !== 'unite') {
                    const lotCat = c.lots.find(l => l.libelle === c.modeVente);
                    const lotPrixConfigure = coutLotConfigure(c);
                    lotConfigure = true;
                    unitesParLot = (lotCat && lotCat.unites_par_lot) || 1;
                    libelleLot = c.modeVente;
                    // c.qte est déjà en pièces ; c.prix_achat représente le coût du
                    // lot si un coût est configuré pour ce type, sinon le prix unitaire.
                    prixUnite = lotPrixConfigure ? Math.round((c.prix_achat / unitesParLot) * 100) / 100 : c.prix_achat;
                    prixLot = lotPrixConfigure ? c.prix_achat : null;
                } else if (!aCatalogue && c.lotConfigure) {
                    lotConfigure = true;
                    unitesParLot = c.unitesParLot || 1;
                    libelleLot = c.libelleLot || 'Unité';
                    prixUnite = c.prix_achat;
                    prixLot = null;
                } else {
                    lotConfigure = false;
                    unitesParLot = 1;
                    libelleLot = 'Unité';
                    prixUnite = c.prix_achat;
                    prixLot = null;
                }
                return { code: c.code, prix_achat: prixUnite, prix_lot: prixLot, qte: c.qte, montant: c.montant,
                         lot_configure: lotConfigure, unites_par_lot: unitesParLot, libelle_lot: libelleLot };
            })),
            fournisseur_id: selectedFournisseur.code,
            boutique_id: gid('boutiqueSelect').value,
            en_attente: enAttente,
            maj_prix_fournisseur: gid('majPrixFournisseur').checked,
            csrf_token: CSRF_TOKEN
        });
        if (res.success) {
            toast('Achat enregistré : ' + res.document);
            cart = [];
            renderCart();
            loadProducts(gid('searchInput').value.trim());
        } else {
            toast(res.message || 'Erreur', 'error');
        }
    } catch (e) { toast('Erreur connexion', 'error'); }
}

// Initialisation
jQuery(document).ready(function() {
    // IMPORTANT : initialiser le plugin une seule fois ici, avant tout appel
    // .selectpicker('refresh') — sinon bootstrap-select s'auto-initialise ET
    // rafraîchit dans la foulée, ce qui construit le menu en double.
    jQuery('.selectpicker').selectpicker();
    jQuery('#boutiqueSelect').on('changed.bs.select', updateButtons);
    loadFournisseurs();
    loadProducts();
});
</script>
</body>
</html>