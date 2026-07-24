<?php
// index.php – Gestion des factures (CRUD) avec lignes de produits pour Avoir/Devis
// Version avec export PDF design paysage (fusionné depuis votre nouveau fichier)

// Nettoyage initial du buffer (pour éviter les problèmes de headers)
while (ob_get_level()) ob_end_clean();
ob_start();

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require_once 'databases/database.php';
require_once 'librairies/fpdf/fpdf.php';

$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

// ---- CRÉATION DES STATUTS POUR AVOIR ET DEVIS ----
try {
    $pdo->exec("INSERT IGNORE INTO statut (code_statut, titre_statut, type_statut, symbole_statut, etat_statut)
                VALUES ('016', 'Avoir', 'avoir', '', 'Actif'),
                       ('017', 'Devis', 'devis', '', 'Actif')");
} catch (PDOException $e) {
    // ignore
}

// ---- FONCTIONS ----
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}
// Fonction pour encoder en ISO-8859-1 pour FPDF
function safeText($str) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str);
}

// ---- RÉCUPÉRATION DES TAXES ET REMISES ----
$taxes = $pdo->query("SELECT code_taxe, titre_taxe, taux_taxe FROM taxe WHERE type_taxe = 'TVA' AND etat_taxe = 'Actif' ORDER BY titre_taxe")->fetchAll(PDO::FETCH_ASSOC);
$remises = $pdo->query("SELECT code_taxe, titre_taxe, taux_taxe FROM taxe WHERE type_taxe = 'Remise' AND etat_taxe = 'Actif' ORDER BY titre_taxe")->fetchAll(PDO::FETCH_ASSOC);

// ---- LISTES POUR LES SELECTS ----
$contacts = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$utilisateurs = $pdo->query("SELECT id, nom_prenom FROM utilisateur WHERE etat = 'Actif' ORDER BY nom_prenom")->fetchAll(PDO::FETCH_ASSOC);
$produits = $pdo->query("SELECT code_produit, titre_produit, prix_produit FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);
$lots = $pdo->query("SELECT code_lot_produit, produit_id, titre_lot, unites_par_lot FROM lot_produit WHERE etat_lot = 'Actif' ORDER BY titre_lot")->fetchAll(PDO::FETCH_ASSOC);

$types_facture = ['Client', 'Fournisseur', 'Interne'];
$categories_facture = ['Facture', 'Avoir', 'Devis'];
$etats_facture = ['Payée', 'Impayée', 'Payer cash', 'Partielle', 'En attente'];

// ---- PRÉCHARGEMENT DES LOTS POUR LE JS ----
$lotsParProduit = [];
foreach ($lots as $l) {
    $lotsParProduit[$l['produit_id']][] = $l;
}

// ---- TRAITEMENT DES ACTIONS ----
$message = '';
$messageType = '';
$action = $_POST['action'] ?? '';
$editFacture = null;
$editLignes = [];
$viewFacture = null;

// --- AJOUT OU MODIFICATION (soumission du formulaire complet) ---
if (($action === 'add' || $action === 'edit') && isset($_POST['titre_facture'])) {
    $numero = trim($_POST['numero_facture'] ?? '');
    $titre = trim($_POST['titre_facture'] ?? '');
    $type = trim($_POST['type_facture'] ?? '');
    $categorie = trim($_POST['categorie_facture'] ?? '');
    $date = trim($_POST['date_facture'] ?? '');
    $montant_ht = trim(str_replace(',', '.', $_POST['montant_ht'] ?? '0'));
    $taxe_code = trim($_POST['taxe'] ?? '');
    $remise_code = trim($_POST['remise'] ?? '');
    $montant_ttc = trim(str_replace(',', '.', $_POST['montant_ttc'] ?? '0'));
    $avance = trim(str_replace(',', '.', $_POST['avance'] ?? '0'));
    $reste = trim(str_replace(',', '.', $_POST['reste'] ?? '0'));
    $contact_id = trim($_POST['contact_id'] ?? '');
    $utilisateur_id = trim($_POST['utilisateur_id'] ?? '');
    $etat = trim($_POST['etat_facture'] ?? '');

    // Récupération des taux
    $taxe_taux = 0;
    if (!empty($taxe_code)) {
        $stmt = $pdo->prepare("SELECT taux_taxe FROM taxe WHERE code_taxe = ?");
        $stmt->execute([$taxe_code]);
        $taxe_taux = (float) $stmt->fetchColumn();
    }
    $remise_taux = 0;
    if (!empty($remise_code)) {
        $stmt = $pdo->prepare("SELECT taux_taxe FROM taxe WHERE code_taxe = ?");
        $stmt->execute([$remise_code]);
        $remise_taux = (float) $stmt->fetchColumn();
    }

    // Lignes de produits pour Avoir/Devis
    $lignesPost = [];
    if (in_array($categorie, ['Avoir', 'Devis'])) {
        $produitsPost = $_POST['produit_id'] ?? [];
        $lotsPost = $_POST['lot_id'] ?? [];
        $quantites = $_POST['quantite'] ?? [];
        $prixUnitaires = $_POST['prix_unitaire'] ?? [];
        foreach ($produitsPost as $idx => $prodId) {
            if (empty($prodId)) continue;
            $qte = intval($quantites[$idx] ?? 0);
            $pu = floatval($prixUnitaires[$idx] ?? 0);
            if ($qte > 0 && $pu >= 0) {
                $lotId = $lotsPost[$idx] ?? null;
                $facteur = 1;
                $uniteAff = 'Unité';
                if (!empty($lotId)) {
                    foreach ($lots as $l) {
                        if ($l['code_lot_produit'] == $lotId && $l['produit_id'] == $prodId) {
                            $facteur = intval($l['unites_par_lot']);
                            $uniteAff = $l['titre_lot'];
                            break;
                        }
                    }
                }
                $quantiteBase = $qte * $facteur;
                $totalLigne = $quantiteBase * $pu;
                $lignesPost[] = [
                    'produit_id' => $prodId,
                    'lot_id' => $lotId,
                    'quantite_saisie' => $qte,
                    'facteur' => $facteur,
                    'quantite_base' => $quantiteBase,
                    'prix_unitaire' => $pu,
                    'total_ligne' => $totalLigne,
                    'unite_affichage' => $uniteAff
                ];
            }
        }
        // Recalcul du HT à partir des lignes
        if (!empty($lignesPost)) {
            $montant_ht = array_sum(array_column($lignesPost, 'total_ligne'));
        }
    }

    // Recalcul TTC et reste
    $montant_ht = (float)$montant_ht;
    $remise_montant = $montant_ht * ($remise_taux / 100);
    $montant_apres_remise = $montant_ht - $remise_montant;
    $montant_ttc_calc = $montant_apres_remise * (1 + $taxe_taux / 100);
    $montant_ttc = $montant_ttc_calc;
    $avance = (float)$avance;
    $reste_calc = $montant_ttc - $avance;
    if ($reste_calc < 0) $reste_calc = 0;
    $reste = $reste_calc;

    // Validation
    $errors = [];
    if (empty($numero)) $errors[] = 'Le numéro de facture est requis.';
    if (empty($titre)) $errors[] = 'Le titre est requis.';
    if (empty($type)) $errors[] = 'Le type est requis.';
    if (empty($categorie)) $errors[] = 'La catégorie est requise.';
    if (empty($date)) $errors[] = 'La date est requise.';
    if ($montant_ht < 0) $errors[] = 'Le montant HT doit être un nombre positif.';
    if ($montant_ttc < 0) $errors[] = 'Le montant TTC doit être un nombre positif.';
    if ($avance < 0) $errors[] = 'L\'avance doit être un nombre positif.';
    if ($reste < 0) $errors[] = 'Le reste doit être un nombre positif.';
    if (empty($contact_id)) $errors[] = 'Le contact est requis.';
    if (empty($utilisateur_id)) $errors[] = 'L\'utilisateur est requis.';
    if (in_array($categorie, ['Avoir', 'Devis']) && empty($lignesPost)) {
        $errors[] = 'Veuillez ajouter au moins une ligne de produit.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($action === 'add') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM facture WHERE numero_facture = ?");
                $stmt->execute([$numero]);
                if ($stmt->fetchColumn() > 0) {
                    $pdo->rollBack(); // Ajout rollback
                    $message = "Ce numéro de facture existe déjà.";
                    $messageType = 'warning';
                } else {
                    $sql = "INSERT INTO facture (numero_facture, titre_facture, type_facture, categorie_facture, date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, contact_id, utilisateur_id, etat_facture)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$numero, $titre, $type, $categorie, $date, $montant_ht, $taxe_taux, $remise_taux, $montant_ttc, $avance, $reste, $contact_id, $utilisateur_id, $etat]);

                    // Lignes pour Avoir/Devis
                    if (in_array($categorie, ['Avoir', 'Devis'])) {
                        $statutId = ($categorie == 'Avoir') ? '016' : '017';
                        foreach ($lignesPost as $ligne) {
                            $numCommande = 'LIG-' . $numero . '-' . date('His') . rand(100, 999);
                            $stmtLigne = $pdo->prepare("INSERT INTO commande 
                                (numero_commande, produit_id, contact_id, facture_id, statut_id, date_commande, heure_commande,
                                 prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id,
                                 boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion)
                                VALUES (?, ?, ?, ?, ?, ?, CURTIME(), 0, ?, ?, ?, ?, NULL, 'Valider', ?, ?, ?)");
                            $stmtLigne->execute([
                                $numCommande,
                                $ligne['produit_id'],
                                $contact_id,
                                $numero,
                                $statutId,
                                $date,
                                $ligne['prix_unitaire'],
                                $ligne['quantite_base'],
                                $ligne['total_ligne'],
                                $utilisateur_id,
                                $ligne['lot_id'],
                                $ligne['unite_affichage'],
                                $ligne['facteur']
                            ]);
                        }
                    }
                    $pdo->commit();
                    $message = "Facture « $titre » ajoutée avec succès." . (in_array($categorie, ['Avoir', 'Devis']) ? ' (' . count($lignesPost) . ' ligne(s))' : '');
                    $messageType = 'success';
                }
            } elseif ($action === 'edit') {
                $oldNumero = $_POST['old_numero'] ?? $numero;
                $sql = "UPDATE facture SET numero_facture=?, titre_facture=?, type_facture=?, categorie_facture=?, date_facture=?, montant_ht=?, taxe=?, remise=?, montant_ttc=?, avance=?, reste=?, contact_id=?, utilisateur_id=?, etat_facture=?
                        WHERE numero_facture = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$numero, $titre, $type, $categorie, $date, $montant_ht, $taxe_taux, $remise_taux, $montant_ttc, $avance, $reste, $contact_id, $utilisateur_id, $etat, $oldNumero]);

                // Supprimer et réinsérer les lignes pour Avoir/Devis
                if (in_array($categorie, ['Avoir', 'Devis'])) {
                    // Utiliser $oldNumero pour la suppression
                    $pdo->prepare("DELETE FROM commande WHERE facture_id = ? AND statut_id IN ('016','017')")->execute([$oldNumero]);
                    $statutId = ($categorie == 'Avoir') ? '016' : '017';
                    foreach ($lignesPost as $ligne) {
                        $numCommande = 'LIG-' . $numero . '-' . date('His') . rand(100, 999);
                        $stmtLigne = $pdo->prepare("INSERT INTO commande 
                            (numero_commande, produit_id, contact_id, facture_id, statut_id, date_commande, heure_commande,
                             prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id,
                             boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion)
                            VALUES (?, ?, ?, ?, ?, ?, CURTIME(), 0, ?, ?, ?, ?, NULL, 'Valider', ?, ?, ?)");
                        $stmtLigne->execute([
                            $numCommande,
                            $ligne['produit_id'],
                            $contact_id,
                            $numero,
                            $statutId,
                            $date,
                            $ligne['prix_unitaire'],
                            $ligne['quantite_base'],
                            $ligne['total_ligne'],
                            $utilisateur_id,
                            $ligne['lot_id'],
                            $ligne['unite_affichage'],
                            $ligne['facteur']
                        ]);
                    }
                }
                $pdo->commit();
                $message = "Facture « $titre » mise à jour." . (in_array($categorie, ['Avoir', 'Devis']) ? ' (' . count($lignesPost) . ' ligne(s))' : '');
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Erreur : " . $e->getMessage();
            $messageType = 'danger';
        }
    } else {
        $message = implode('<br>', $errors);
        $messageType = 'warning';
    }
}

// --- SUPPRESSION ---
if (isset($_POST['btn_supprimer']) && $_POST['btn_supprimer'] == '1') {
    $numero = $_POST['sai_supprimer_id'] ?? '';
    if (!empty($numero)) {
        try {
            $pdo->prepare("DELETE FROM commande WHERE facture_id = ? AND statut_id IN ('016','017')")->execute([$numero]);
            $stmt = $pdo->prepare("SELECT titre_facture FROM facture WHERE numero_facture = ?");
            $stmt->execute([$numero]);
            $titre = $stmt->fetchColumn();
            $stmt = $pdo->prepare("DELETE FROM facture WHERE numero_facture = ?");
            $stmt->execute([$numero]);
            $message = "Facture « $titre » supprimée.";
            $messageType = 'danger';
        } catch (PDOException $e) {
            $message = "Erreur : " . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// --- RÉCUPÉRATION DE LA FACTURE POUR ÉDITION ---
if ($action === 'edit' && isset($_POST['edit_numero']) && !isset($_POST['titre_facture'])) {
    $numero = $_POST['edit_numero'];
    $stmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ?");
    $stmt->execute([$numero]);
    $editFacture = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editFacture && in_array($editFacture['categorie_facture'], ['Avoir', 'Devis'])) {
        $stmtLignes = $pdo->prepare("SELECT * FROM commande WHERE facture_id = ? AND statut_id IN ('016','017')");
        $stmtLignes->execute([$numero]);
        $editLignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $editLignes = [];
    }
}

// --- VUE DÉTAIL ---
if ($action === 'view' && isset($_POST['view_numero'])) {
    $numero = $_POST['view_numero'];
    $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact, u.nom_prenom 
                           FROM facture f
                           LEFT JOIN contact c ON f.contact_id = c.code_contact
                           LEFT JOIN utilisateur u ON f.utilisateur_id = u.id
                           WHERE f.numero_facture = ?");
    $stmt->execute([$numero]);
    $viewFacture = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ---- FONCTION POUR LE TABLEAU (AJAX) ----
function getTableContent($pdo, $search, $type_filter, $categorie_filter, $contact_filter, $utilisateur_filter, $etat_filter, $page, $perPage = 20)
{
    $sql = "SELECT f.*, c.nom_prenom_contact, u.nom_prenom 
            FROM facture f
            LEFT JOIN contact c ON f.contact_id = c.code_contact
            LEFT JOIN utilisateur u ON f.utilisateur_id = u.id
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (f.numero_facture LIKE ? OR f.titre_facture LIKE ? OR c.nom_prenom_contact LIKE ? OR u.nom_prenom LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 4; $i++) $params[] = $like;
    }
    if (!empty($type_filter)) {
        $sql .= " AND f.type_facture = ?";
        $params[] = $type_filter;
    }
    if (!empty($categorie_filter)) {
        $sql .= " AND f.categorie_facture = ?";
        $params[] = $categorie_filter;
    }
    if (!empty($contact_filter)) {
        $sql .= " AND f.contact_id = ?";
        $params[] = $contact_filter;
    }
    if (!empty($utilisateur_filter)) {
        $sql .= " AND f.utilisateur_id = ?";
        $params[] = $utilisateur_filter;
    }
    if (!empty($etat_filter)) {
        $sql .= " AND f.etat_facture = ?";
        $params[] = $etat_filter;
    }

    $countSql = str_replace("SELECT f.*, c.nom_prenom_contact, u.nom_prenom", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $sql .= " ORDER BY f.date_facture DESC, f.numero_facture LIMIT " . (($page - 1) * $perPage) . ", $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($factures)): ?>
        <tr>
            <td colspan="12" class="text-center py-5 text-muted">
                <i class="fas fa-inbox fa-2x d-block mb-2 opacity-50"></i>
                Aucune facture trouvée
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($factures as $f): ?>
            <tr>
                <td class="td-bold"><?= e($f['numero_facture']) ?></td>
                <td class="td-semi"><?= e($f['titre_facture']) ?></td>
                <td><?= e($f['type_facture']) ?></td>
                <td><?= e($f['categorie_facture']) ?></td>
                <td><?= date('d/m/Y', strtotime($f['date_facture'])) ?></td>
                <td><?= number_format((float)$f['montant_ht'], 2) ?></td>
                <td><?= number_format((float)$f['montant_ttc'], 2) ?></td>
                <td><?= number_format((float)$f['avance'], 2) ?></td>
                <td><?= number_format((float)$f['reste'], 2) ?></td>
                <td><?= e($f['nom_prenom_contact'] ?? '—') ?></td>
                <td>
                    <span class="status-badge <?= ($f['etat_facture'] === 'Payer cash' || $f['etat_facture'] === 'Payée') ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= e($f['etat_facture']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn v viewBtn" data-numero="<?= e($f['numero_facture']) ?>" title="Voir"><i class="fas fa-eye"></i></button>
                        <button class="act-btn e editBtn" data-numero="<?= e($f['numero_facture']) ?>" title="Modifier"><i class="fas fa-pen"></i></button>
                        <button class="act-btn d deleteBtn" data-numero="<?= e($f['numero_facture']) ?>" data-nom="<?= e($f['titre_facture']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="fas fa-trash"></i></button>
                        <!-- <form method="post" target="_blank" style="display:inline-block;"> -->
                        <form method="post" style="display:inline-block;">
                            <input type="hidden" name="export_pdf" value="1">
                            <input type="hidden" name="numero" value="<?= e($f['numero_facture']) ?>">
                            <button type="submit" class="act-btn" title="PDF" style="color:#dc3545; border:none; background:transparent; padding:0; width:34px; height:34px;">
                                <i class="fas fa-file-pdf"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
            <span class="text-muted small">Affichage de <?= (($page - 1) * $perPage + 1) ?> à <?= min($page * $perPage, $total) ?> sur <?= $total ?></span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    if ($start > 1) {
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                        if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                            <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor;
                    if ($end < $totalPages) {
                        if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="' . $totalPages . '">' . $totalPages . '</a></li>';
                    }
                    ?>
                    <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
<?php endif;
    $paginationHtml = ob_get_clean();

    return [
        'table' => $tableHtml,
        'pagination' => $paginationHtml,
        'total' => $total,
        'page' => $page,
        'totalPages' => $totalPages
    ];
}

// ---- AJAX ----
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $search = trim($_POST['search'] ?? '');
    $type_filter = trim($_POST['type_filter'] ?? '');
    $categorie_filter = trim($_POST['categorie_filter'] ?? '');
    $contact_filter = trim($_POST['contact_filter'] ?? '');
    $utilisateur_filter = trim($_POST['utilisateur_filter'] ?? '');
    $etat_filter = trim($_POST['etat_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $type_filter, $categorie_filter, $contact_filter, $utilisateur_filter, $etat_filter, $page);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// ---- EXPORT PDF (DESIGN PAYSAGE FUSIONNÉ) ----
if (isset($_POST['export_pdf']) && $_POST['export_pdf'] == '1' && !empty($_POST['numero'])) {
    error_reporting(0);
    while (ob_get_level()) ob_end_clean();

    $numero = $_POST['numero'];

    // Récupération de la facture
    $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact, c.adresse_contact, c.telephone_contact, c.email_contact,
        u.nom_prenom AS vendeur_nom
        FROM facture f
        LEFT JOIN contact c ON f.contact_id = c.code_contact
        LEFT JOIN utilisateur u ON f.utilisateur_id = u.id
        WHERE f.numero_facture = ?");
    $stmt->execute([$numero]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$facture) die('Facture introuvable');

    // Informations boutique (depuis la base ou défaut)
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

    // Récupération des lignes de commande (pour Avoir/Devis ou Facture selon le cas)
    // On récupère toutes les lignes liées à cette facture (qu'elles aient un statut ou non)
    // On utilise les champs facteur_conversion et unite_affichage de notre table commande
    $stmt = $pdo->prepare("SELECT c.*, p.titre_produit,
        COALESCE(c.facteur_conversion, 1) AS facteur_conversion
        FROM commande c
        LEFT JOIN produit p ON c.produit_id = p.code_produit
        WHERE c.facture_id = ?");
    $stmt->execute([$numero]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Création du PDF en mode PAYSAGE
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);

    // Couleurs
    $blueDark = [0, 51, 102];
    $blueLight = [240, 245, 255];
    $grayBg = [245, 245, 245];

    // Fonction d'encodage
    $toLatin = function($chaine) {
        return safeText($chaine);
    };

    // --- EN-TÊTE (Format paysage : 297mm de large) ---
    $yStart = 10;

    // Logo / Nom société (gauche)
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

    // Titre Facture (centre)
    $pdf->SetFont('Arial', 'B', 24);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    // Titre dynamique selon catégorie
    $titreDoc = match($facture['categorie_facture']) {
        'Avoir' => 'AVOIR',
        'Devis' => 'DEVIS',
        default => 'FACTURE'
    };
    $pdf->Text(125, $yStart + 10, $toLatin($titreDoc));

    // Numéro de facture encadré
    $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Rect(115, $yStart + 13, 50, 10, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Text(122, $yStart + 20, $toLatin('N° ' . $facture['numero_facture']));

    // Informations Droite (Date, Échéance, etc.)
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
    $pdf->Text($xRight + 40, $yInfo + 15, ': Virement bancaire');

    $pdf->Text($xRight, $yInfo + 20, $toLatin("Bon de commande"));
    $pdf->Text($xRight + 40, $yInfo + 20, ': BC-2026-0001');

    $pdf->Text($xRight, $yInfo + 25, $toLatin("Bon de livraison"));
    $pdf->Text($xRight + 40, $yInfo + 25, ': BL-2026-0015');

    // Ligne séparatrice
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(10, 42, 287, 42);

    // --- BLOCS VENDEUR ET CLIENT (côte à côte) ---
    $yBlocks = 48;

    $drawAddressBlock = function($pdf, $x, $y, $w, $title, $name, $address, $phone, $email) use ($toLatin, $blueDark, $grayBg) {
        $h = 30;

        // Titre du bloc
        $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
        $pdf->Rect($x, $y, 40, 6, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Text($x + 3, $y + 4.5, $toLatin(strtoupper($title)));

        // Contenu du bloc
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

    // Largeur utile totale = 277mm (de x=10 à x=287), répartie en 2 blocs égaux avec 10mm d'écart
    $wBlock = (277 - 10) / 2; // 133.5mm

    $drawAddressBlock($pdf, 10, $yBlocks, $wBlock, 'VENDEUR',
        $boutique['nom_boutique'],
        $boutique['ville_boutique'] . ', ' . $boutique['pays_boutique'],
        $boutique['telephone_boutique'],
        $boutique['email_boutique']);

    $drawAddressBlock($pdf, 10 + $wBlock + 10, $yBlocks, $wBlock, 'CLIENT',
        $facture['nom_prenom_contact'],
        $facture['adresse_contact'] ?? '',
        $facture['telephone_contact'] ?? '',
        $facture['email_contact'] ?? '');

    // --- TABLEAU DES LIGNES (occupe toute la largeur utile du paysage : 10 -> 287 mm) ---
    $yTable = 80;
    $pageBottom = 195; // limite basse avant saut de page (marge de sécurité sur les 210mm de hauteur)
    $colWidths = [25, 100, 28, 22, 27, 35, 40]; // 7 colonnes, somme = 277mm (largeur utile en paysage)
    $headers = ['RÉFÉRENCE', 'DÉSIGNATION', 'NB UNITÉ/CARTON', 'CARTON', 'QTÉ (UNITÉ)', 'P.U. (FCFA)', 'MONTANT (FCFA)'];
    $headerH = 7;
    $rowH = 7;

    $drawTableHeader = function() use ($pdf, $colWidths, $headers, $headerH, $toLatin, $blueDark, &$yTable) {
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

    // Données tableau
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 8);
    $yCurrent = $yTable + $headerH;
    $totalHT = 0;

    foreach ($lignes as $ligne) {
        // Saut de page si la ligne suivante dépasserait le bas utile de la page
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

    // Si la zone des totaux ne tient plus sur la page courante, on passe à une nouvelle page
    if ($yCurrent + 65 > $pageBottom + 15) {
        $pdf->AddPage();
        $yCurrent = 15;
    }

    // --- TOTAUX ---
    $yTotals = $yCurrent + 6;

    // Bloc Observations (Gauche) — occupe l'espace libéré par la suppression du montant en lettres
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

    // Bloc Totaux (Droite)
    $xTot = 10 + $wObs + 10; // 190
    $wTot = 287 - $xTot;     // occupe le reste de la largeur utile
    $hTot = 7;
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);

    $taxe = (float)($facture['taxe'] ?? 0);
    $remise = (float)($facture['remise'] ?? 0);
    $montantTtcSaisi = (float)($facture['montant_ttc'] ?? 0);
    $totalTTC = $montantTtcSaisi > 0 ? $montantTtcSaisi : ($totalHT * (1 + $taxe / 100) - $remise);

    // HT
    $pdf->Rect($xTot, $yTotals, $wTot, $hTot, 'D');
    $pdf->Text($xTot + 2, $yTotals + 5, $toLatin('TOTAL HORS TAXES (HT)'));
    $pdf->SetXY($xTot, $yTotals + 5);
    $pdf->Cell($wTot - 2, 0, number_format($totalHT, 0, ',', ' '), 0, 0, 'R');

    // TVA
    $pdf->Rect($xTot, $yTotals + $hTot, $wTot, $hTot, 'D');
    $pdf->Text($xTot + 2, $yTotals + $hTot + 5, $toLatin('TVA (' . $taxe . '%)'));
    $pdf->SetXY($xTot, $yTotals + $hTot + 5);
    $pdf->Cell($wTot - 2, 0, number_format($totalHT * $taxe / 100, 0, ',', ' '), 0, 0, 'R');

    // Remise
    $pdf->Rect($xTot, $yTotals + ($hTot * 2), $wTot, $hTot, 'D');
    $pdf->Text($xTot + 2, $yTotals + ($hTot * 2) + 5, $toLatin('REMISE'));
    $pdf->SetXY($xTot, $yTotals + ($hTot * 2) + 5);
    $pdf->Cell($wTot - 2, 0, number_format($remise, 0, ',', ' '), 0, 0, 'R');

    // TTC (Mis en valeur)
    $pdf->SetFillColor($blueLight[0], $blueLight[1], $blueLight[2]);
    $pdf->Rect($xTot, $yTotals + ($hTot * 3), $wTot, $hTot + 2, 'FD');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text($xTot + 2, $yTotals + ($hTot * 3) + 5.5, $toLatin('NET À PAYER (TTC)'));
    $pdf->SetXY($xTot, $yTotals + ($hTot * 3) + 5.5);
    $pdf->Cell($wTot - 2, 0, number_format($totalTTC, 0, ',', ' '), 0, 0, 'R');

    // --- SIGNATURES ---
    $hSig = 26;
    $ySig = $yTotals + $hObs + 8;
    if ($ySig + $hSig > $pageBottom + 15) {
        $pdf->AddPage();
        $ySig = 20;
    }
    $wSig = 133;

    // Vendeur
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Rect(10, $ySig, $wSig, $hSig, 'D');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(12, $ySig + 5, $toLatin('Le vendeur'));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text(12, $ySig + 10, $toLatin('Nom et Signature'));

    // Cachet Vendeur
    $pdf->SetDrawColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Rect(60, $ySig + 5, 70, $hSig - 6, 'D');
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(63, $ySig + 9, $toLatin($boutique['nom_boutique']));
    $pdf->SetFont('Arial', '', 6);
    $pdf->Text(63, $ySig + 13, $toLatin($boutique['adresse_boutique']));
    $pdf->Text(63, $ySig + 17, $toLatin('Tél. : ' . $boutique['telephone_boutique']));

    // Client
    $xClient = 10 + $wSig + 21; // 164
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Rect($xClient, $ySig, $wSig, $hSig, 'D');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text($xClient + 2, $ySig + 5, $toLatin('Le client'));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text($xClient + 2, $ySig + 10, $toLatin('Nom et Signature'));

    // --- SORTIE ---
    while (ob_get_level()) ob_end_clean();
    $pdf->Output('I', 'Facture_' . $facture['numero_facture'] . '.pdf');
    exit;
}

// ---- AFFICHAGE INITIAL ----
$search = trim($_POST['search'] ?? '');
$type_filter = trim($_POST['type_filter'] ?? '');
$categorie_filter = trim($_POST['categorie_filter'] ?? '');
$contact_filter = trim($_POST['contact_filter'] ?? '');
$utilisateur_filter = trim($_POST['utilisateur_filter'] ?? '');
$etat_filter = trim($_POST['etat_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $type_filter, $categorie_filter, $contact_filter, $utilisateur_filter, $etat_filter, $page);

// Déterminer l'action du formulaire pour les champs cachés
$formAction = ($action === 'edit' && isset($editFacture)) ? 'edit' : 'add';
$oldNumero = ($action === 'edit' && isset($editFacture)) ? e($editFacture['numero_facture']) : '';
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des factures</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Styles identiques à la version précédente */
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

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            padding: 30px 20px;
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
        .container-crud {
            max-width: 1400px;
            margin: 0 auto;
        }
        .data-table-wrap {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table {
            min-width: 900px;
            margin-bottom: 0;
        }
        .table> :not(caption)>*>* {
            padding: 8px 12px;
        }
        .table thead th {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-quaternary);
            background: var(--bg-muted);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        .table tbody td {
            vertical-align: middle;
            color: var(--text-secondary);
            font-size: 0.8rem;
            white-space: nowrap;
        }
        .td-bold {
            font-weight: 700;
            color: var(--text-primary) !important;
        }
        .td-semi {
            font-weight: 500;
            color: var(--text-primary) !important;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 12px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
        }
        .status-badge .sdot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }
        .status-badge.on {
            background: var(--color-success-soft);
            color: #059669;
        }
        .status-badge.off {
            background: var(--color-danger-soft);
            color: #dc2626;
        }
        .act-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-quaternary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-base);
            font-size: 0.8rem;
        }
        .act-btn:hover {
            transform: scale(1.1);
        }
        .act-btn.v:hover {
            color: var(--color-primary);
            background: var(--color-primary-soft);
            border-color: rgba(79, 70, 229, 0.15);
        }
        .act-btn.e:hover {
            color: var(--color-warning);
            background: var(--color-warning-soft);
            border-color: rgba(245, 158, 11, 0.15);
        }
        .act-btn.d:hover {
            color: var(--color-danger);
            background: var(--color-danger-soft);
            border-color: rgba(239, 68, 68, 0.15);
        }
        .search-inline {
            display: flex;
            align-items: center;
            background: var(--bg-muted);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0 12px;
            height: 42px;
            min-width: 160px;
            transition: all var(--transition-base);
        }
        .search-inline:focus-within {
            border-color: var(--color-primary);
            background: var(--bg-surface);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.08);
        }
        .search-inline i {
            color: var(--text-quaternary);
            font-size: 0.8rem;
        }
        .search-inline input,
        .search-inline select {
            background: none;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: inherit;
            width: 100%;
            margin-left: 8px;
        }
        .search-inline select {
            padding-right: 20px;
            cursor: pointer;
        }
        .search-inline input::placeholder {
            color: var(--text-quaternary);
        }
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            .table thead th,
            .table tbody td {
                font-size: 0.65rem;
                padding: 4px 6px;
            }
            .table .act-btn {
                width: 26px;
                height: 26px;
                font-size: 0.65rem;
            }
            .status-badge {
                font-size: 0.6rem;
                padding: 0 8px;
            }
            .page-heading h2 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>

<body>
    <div class="container-crud">
        <!-- En-tête -->
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
            <div class="page-heading">
                <h2 class="fw-800 mb-0">Gestion des factures</h2>
                <p class="text-tertiary mt-1">Suivez vos factures clients et fournisseurs</p>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" id="addBtn"><i class="fas fa-plus"></i> Nouvelle facture</button>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= e($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Barre de recherche -->
        <div class="bg-light p-3 rounded-3 mb-3 border">
            <form id="searchForm" method="post" onsubmit="return false;">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="page" id="pageInput" value="<?= e($page) ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="searchInput" class="form-label fw-semibold small">Recherche</label>
                        <div class="search-inline" style="min-width:100%; height:42px;">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" id="searchInput" placeholder="Numéro, titre, contact..." value="<?= e($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label for="typeFilter" class="form-label fw-semibold small">Type</label>
                        <select name="type_filter" id="typeFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un type...">
                            <option value="">Tous</option>
                            <?php foreach ($types_facture as $t): ?>
                                <option value="<?= e($t) ?>" <?= ($type_filter == $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="categorieFilter" class="form-label fw-semibold small">Catégorie</label>
                        <select name="categorie_filter" id="categorieFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher une catégorie...">
                            <option value="">Toutes</option>
                            <?php foreach ($categories_facture as $c): ?>
                                <option value="<?= e($c) ?>" <?= ($categorie_filter == $c) ? 'selected' : '' ?>><?= e($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="contactFilter" class="form-label fw-semibold small">Contact</label>
                        <select name="contact_filter" id="contactFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un contact...">
                            <option value="">Tous</option>
                            <?php foreach ($contacts as $c): ?>
                                <option value="<?= e($c['code_contact']) ?>" <?= ($contact_filter == $c['code_contact']) ? 'selected' : '' ?>>
                                    <?= e($c['nom_prenom_contact']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="etatFilter" class="form-label fw-semibold small">État</label>
                        <select name="etat_filter" id="etatFilter" class="selectpicker form-control" data-live-search="true" data-live-search-placeholder="Rechercher un état...">
                            <option value="">Tous</option>
                            <?php foreach ($etats_facture as $e): ?>
                                <option value="<?= e($e) ?>" <?= ($etat_filter == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-primary w-100" id="filterBtn"><i class="fas fa-filter"></i></button>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-outline-secondary w-100" id="resetBtn"><i class="fas fa-undo"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="data-table-wrap" id="tableWrapper">
            <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
                <h5 class="mb-0 fw-bold">Liste des factures</h5>
                <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?? 0 ?> facture(s) - Page <?= $initialData['page'] ?? 1 ?> / <?= max(1, $initialData['totalPages'] ?? 1) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width:100px;">Numéro</th>
                            <th style="min-width:150px;">Titre</th>
                            <th style="min-width:80px;">Type</th>
                            <th style="min-width:100px;">Catégorie</th>
                            <th style="min-width:90px;">Date</th>
                            <th style="min-width:70px;">HT</th>
                            <th style="min-width:70px;">TTC</th>
                            <th style="min-width:70px;">Avance</th>
                            <th style="min-width:70px;">Reste</th>
                            <th style="min-width:120px;">Contact</th>
                            <th style="min-width:100px;">État</th>
                            <th class="text-end" style="min-width:120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?= $initialData['table'] ?>
                    </tbody>
                </table>
            </div>
            <div id="paginationContainer">
                <?= $initialData['pagination'] ?>
            </div>
        </div>
    </div>

    <!-- Modal Formulaire -->
    <div class="modal fade" id="factureModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle"><i class="fas fa-file-invoice text-primary me-2"></i> Nouvelle facture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post" id="factureForm">
                    <input type="hidden" name="action" id="formAction" value="<?= $formAction ?>">
                    <input type="hidden" name="old_numero" id="oldNumero" value="<?= $oldNumero ?>">
                    <div class="modal-body">
                        <!-- Identification -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-tag me-1"></i> Identification</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="numero_facture" class="form-label fw-semibold">Numéro facture <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                                    <input type="text" class="form-control" id="numero_facture" name="numero_facture" required placeholder="FAC-001" 
                                           value="<?= e($editFacture['numero_facture'] ?? '') ?>"
                                           <?= ($action === 'edit' && isset($editFacture)) ? 'readonly' : '' ?>>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="titre_facture" class="form-label fw-semibold">Titre <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-heading"></i></span>
                                    <input type="text" class="form-control" id="titre_facture" name="titre_facture" placeholder="Facture de vente" value="<?= e($editFacture['titre_facture'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="date_facture" class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                    <input type="date" class="form-control" id="date_facture" name="date_facture" value="<?= isset($editFacture) ? $editFacture['date_facture'] : date('Y-m-d') ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Type et catégorie -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-tags me-1"></i> Type et catégorie</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="type_facture" class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="type_facture" name="type_facture" required>
                                    <option value="">=== Faites votre choix ===</option>
                                    <?php foreach ($types_facture as $t): ?>
                                        <option value="<?= e($t) ?>" <?= (isset($editFacture) && $editFacture['type_facture'] == $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="categorie_facture" class="form-label fw-semibold">Catégorie <span class="text-danger">*</span></label>
                                <select class="form-select" id="categorie_facture" name="categorie_facture" required>
                                    <option value="">=== Faites votre choix ===</option>
                                    <?php foreach ($categories_facture as $c): ?>
                                        <option value="<?= e($c) ?>" <?= (isset($editFacture) && $editFacture['categorie_facture'] == $c) ? 'selected' : '' ?>><?= e($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Bloc Lignes de produits (visible uniquement pour Avoir/Devis) -->
                        <div id="blocLignes" style="display: none;">
                            <hr>
                            <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-boxes me-1"></i> Lignes de produits</h6>
                            <div id="lignesContainerFacture">
                                <div class="ligne-produit" data-index="0">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold">Produit</label>
                                            <select name="produit_id[]" class="form-select select-produit">
                                                <!-- required retiré, géré dynamiquement -->
                                                <option value="">-- Choisir --</option>
                                                <?php foreach ($produits as $p): ?>
                                                    <option value="<?= e($p['code_produit']) ?>" data-prix="<?= $p['prix_produit'] ?>"><?= e($p['titre_produit']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold">Lot/unité</label>
                                            <select name="lot_id[]" class="form-select select-lot">
                                                <option value="">Unité</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold">Unité affichée</label>
                                            <input type="text" name="unite_affichage[]" class="form-control unite-affichage" readonly placeholder="Auto">
                                        </div>
                                        <div class="col-md-1">
                                            <label class="form-label fw-semibold">Qté</label>
                                            <input type="number" name="quantite[]" class="form-control quantite" min="1" value="1" oninput="calculerLigneFacture(this)">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold">Prix unitaire</label>
                                            <input type="number" step="0.01" name="prix_unitaire[]" class="form-control prix-unitaire" min="0" oninput="calculerLigneFacture(this)">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold">Total ligne</label>
                                            <input type="text" name="total_ligne[]" class="form-control total-ligne" readonly value="0">
                                        </div>
                                        <div class="col-md-auto d-flex align-items-end">
                                            <button type="button" class="btn btn-danger btn-sm supprimer-ligne" onclick="supprimerLigneFacture(this)" style="display:none;"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-ajouter" onclick="ajouterLigneFacture()" id="btnAjouterLigne"><i class="fas fa-plus"></i> Ajouter une ligne</button>
                        </div>

                        <!-- Montants -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-calculator me-1"></i> Montants</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <label for="montant_ht" class="form-label fw-semibold">Montant HT</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-dollar-sign"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="montant_ht" name="montant_ht" placeholder="0.00" value="<?= e($editFacture['montant_ht'] ?? '0') ?>" oninput="calculerTotauxFacture()">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="taxe" class="form-label fw-semibold">TVA</label>
                                <select class="form-select" id="taxe" name="taxe" onchange="calculerTotauxFacture()">
                                    <option value="">Aucune</option>
                                    <?php foreach ($taxes as $t): ?>
                                        <option value="<?= e($t['code_taxe']) ?>" data-taux="<?= $t['taux_taxe'] ?>" <?= (isset($editFacture) && (float)$editFacture['taxe'] == (float)$t['taux_taxe']) ? 'selected' : '' ?>>
                                            <?= e($t['titre_taxe']) ?> (<?= $t['taux_taxe'] ?>%)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="remise" class="form-label fw-semibold">Remise</label>
                                <select class="form-select" id="remise" name="remise" onchange="calculerTotauxFacture()">
                                    <option value="">Aucune</option>
                                    <?php foreach ($remises as $r): ?>
                                        <option value="<?= e($r['code_taxe']) ?>" data-taux="<?= $r['taux_taxe'] ?>" <?= (isset($editFacture) && (float)$editFacture['remise'] == (float)$r['taux_taxe']) ? 'selected' : '' ?>>
                                            <?= e($r['titre_taxe']) ?> (<?= $r['taux_taxe'] ?>%)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="montant_ttc" class="form-label fw-semibold">Montant TTC</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-dollar-sign"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="montant_ttc" name="montant_ttc" placeholder="0.00" readonly value="<?= e($editFacture['montant_ttc'] ?? '0') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Avance et reste -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-hand-holding-usd me-1"></i> Paiement</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="avance" class="form-label fw-semibold">Avance</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-arrow-down"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="avance" name="avance" placeholder="0.00" value="<?= e($editFacture['avance'] ?? '0') ?>" oninput="calculerTotauxFacture()">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="reste" class="form-label fw-semibold">Reste</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-arrow-up"></i></span>
                                    <input type="number" step="0.01" class="form-control" id="reste" name="reste" placeholder="0.00" readonly value="<?= e($editFacture['reste'] ?? '0') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Associations -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-users me-1"></i> Associations</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="contact_id" class="form-label fw-semibold">Contact <span class="text-danger">*</span></label>
                                <select class="selectpicker form-control" id="contact_id" name="contact_id" data-live-search="true" data-live-search-placeholder="Rechercher un contact..." required>
                                    <option value="">=== Choisir un contact ===</option>
                                    <?php foreach ($contacts as $c): ?>
                                        <option value="<?= e($c['code_contact']) ?>" <?= (isset($editFacture) && $editFacture['contact_id'] == $c['code_contact']) ? 'selected' : '' ?>>
                                            <?= e($c['nom_prenom_contact']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="utilisateur_id" class="form-label fw-semibold">Utilisateur <span class="text-danger">*</span></label>
                                <select class="selectpicker form-control" id="utilisateur_id" name="utilisateur_id" data-live-search="true" data-live-search-placeholder="Rechercher un utilisateur..." required>
                                    <option value="">=== Choisir un utilisateur ===</option>
                                    <?php foreach ($utilisateurs as $u): ?>
                                        <option value="<?= e($u['id']) ?>" <?= (isset($editFacture) && $editFacture['utilisateur_id'] == $u['id']) ? 'selected' : '' ?>>
                                            <?= e($u['nom_prenom']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- État -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="fas fa-toggle-on me-1"></i> Statut</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="etat_facture" class="form-label fw-semibold">État</label>
                                <select class="form-select" id="etat_facture" name="etat_facture">
                                    <?php foreach ($etats_facture as $e): ?>
                                        <option value="<?= e($e) ?>" <?= (isset($editFacture) && $editFacture['etat_facture'] == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Annuler</button>
                        <button type="submit" class="btn btn-primary" id="saveBtn"><i class="fas fa-save"></i> Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Vue -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:600px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="viewModalLabel"><i class="fas fa-eye text-primary me-2"></i> Détails de la facture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3" id="viewGrid"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Suppression -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 16px;">
                <div class="modal-body text-center p-4">
                    <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                    <h5 class="modal-title mb-2" style="font-weight: 600; color: var(--dark);">Confirmer la suppression</h5>
                    <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer la facture <strong id="deleteNomFacture"></strong> ?<br>Cette action est irréversible.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-outline-secondary" style="border-radius: 10px;" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" class="btn btn-danger" id="confirmDeleteBtn" style="border-radius: 10px; min-width: 120px;"><i class="bi bi-trash3 me-1"></i> Supprimer</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulaires cachés -->
    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="btn_supprimer" value="1">
        <input type="hidden" name="sai_supprimer_id" id="deleteFormId" value="">
    </form>
    <form method="post" id="actionForm">
        <input type="hidden" name="action" id="actionField">
        <input type="hidden" name="edit_numero" id="editNumeroField">
        <input type="hidden" name="view_numero" id="viewNumeroField">
    </form>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>

    <script>
        // ---- Définition des fonctions globales ----
        const lotsParProduit = <?= json_encode($lotsParProduit) ?>;

        function mettreAJourLots(selectProduit) {
            const ligne = selectProduit.closest('.ligne-produit');
            const lotSelect = ligne.querySelector('.select-lot');
            const uniteAff = ligne.querySelector('.unite-affichage');
            const prixInput = ligne.querySelector('.prix-unitaire');
            const produitId = selectProduit.value;
            const option = selectProduit.options[selectProduit.selectedIndex];
            if (option && option.dataset.prix) {
                prixInput.value = option.dataset.prix;
            } else {
                prixInput.value = '';
            }
            const lots = lotsParProduit[produitId] || [];
            let options = '<option value="">Unité</option>';
            lots.forEach(lot => {
                options +=
                    `<option value="${lot.code_lot_produit}" data-unite="${lot.titre_lot}" data-facteur="${lot.unites_par_lot}">${lot.titre_lot}</option>`;
            });
            lotSelect.innerHTML = options;
            if (lotSelect.value) {
                const selected = lotSelect.options[lotSelect.selectedIndex];
                uniteAff.value = selected.text;
            } else {
                uniteAff.value = 'Unité';
            }
            calculerLigneFacture(prixInput);
        }

        // Nouvelle fonction calculerLigneFacture avec facteur
        function calculerLigneFacture(element) {
            const ligne = element.closest('.ligne-produit');
            const quantite = parseFloat(ligne.querySelector('.quantite').value) || 0;
            const prix = parseFloat(ligne.querySelector('.prix-unitaire').value) || 0;
            // Récupérer le facteur du lot
            const lotSelect = ligne.querySelector('.select-lot');
            let facteur = 1;
            if (lotSelect.selectedIndex > 0) {
                const option = lotSelect.options[lotSelect.selectedIndex];
                facteur = parseFloat(option.dataset.facteur) || 1;
            }
            const total = quantite * facteur * prix;
            ligne.querySelector('.total-ligne').value = total.toFixed(2);
            calculerTotauxFacture();
        }

        function calculerTotauxFacture() {
            let ht = 0;
            const categorie = document.getElementById('categorie_facture').value;
            const isAvoirDevis = (categorie === 'Avoir' || categorie === 'Devis');
            if (isAvoirDevis) {
                document.querySelectorAll('#lignesContainerFacture .ligne-produit').forEach(function(ligne) {
                    const totalLigne = parseFloat(ligne.querySelector('.total-ligne').value) || 0;
                    ht += totalLigne;
                });
                document.getElementById('montant_ht').value = ht.toFixed(2);
                document.getElementById('montant_ht').readOnly = true;
            } else {
                ht = parseFloat(document.getElementById('montant_ht').value) || 0;
                document.getElementById('montant_ht').readOnly = false;
            }

            const taxeSelect = document.getElementById('taxe');
            const remiseSelect = document.getElementById('remise');
            let taxeTaux = 0,
                remiseTaux = 0;
            if (taxeSelect.selectedIndex > 0) {
                const opt = taxeSelect.options[taxeSelect.selectedIndex];
                taxeTaux = parseFloat(opt.dataset.taux) || 0;
            }
            if (remiseSelect.selectedIndex > 0) {
                const opt = remiseSelect.options[remiseSelect.selectedIndex];
                remiseTaux = parseFloat(opt.dataset.taux) || 0;
            }

            const remiseMontant = ht * (remiseTaux / 100);
            const apresRemise = ht - remiseMontant;
            const ttc = apresRemise * (1 + taxeTaux / 100);
            document.getElementById('montant_ttc').value = ttc.toFixed(2);

            const avance = parseFloat(document.getElementById('avance').value) || 0;
            let reste = ttc - avance;
            if (reste < 0) reste = 0;
            document.getElementById('reste').value = reste.toFixed(2);
        }

        // Fonction toggleLignes avec gestion disabled
        function toggleLignes() {
            const categorie = $('#categorie_facture').val();
            const isAvoirDevis = (categorie === 'Avoir' || categorie === 'Devis');
            if (isAvoirDevis) {
                $('#blocLignes').slideDown();
                $('#montant_ht').prop('readonly', true);
                // Activer les champs du bloc lignes
                $('#lignesContainerFacture .select-produit, #lignesContainerFacture .select-lot, #lignesContainerFacture .quantite, #lignesContainerFacture .prix-unitaire')
                    .prop('disabled', false);
            } else {
                $('#blocLignes').slideUp();
                $('#montant_ht').prop('readonly', false);
                // Désactiver les champs du bloc lignes
                $('#lignesContainerFacture .select-produit, #lignesContainerFacture .select-lot, #lignesContainerFacture .quantite, #lignesContainerFacture .prix-unitaire')
                    .prop('disabled', true);
            }
            calculerTotauxFacture();
        }

        function ajouterLigneFacture() {
            const container = document.getElementById('lignesContainerFacture');
            const original = container.querySelector('.ligne-produit');
            const clone = original.cloneNode(true);
            clone.dataset.index = container.querySelectorAll('.ligne-produit').length;
            // Réinitialiser les valeurs
            const selectProd = clone.querySelector('.select-produit');
            selectProd.value = '';
            const selectLot = clone.querySelector('.select-lot');
            selectLot.innerHTML = '<option value="">Unité</option>';
            clone.querySelector('.unite-affichage').value = '';
            clone.querySelector('.quantite').value = 1;
            clone.querySelector('.prix-unitaire').value = '';
            clone.querySelector('.total-ligne').value = '0';
            clone.querySelector('.supprimer-ligne').style.display = 'inline-block';
            container.appendChild(clone);
            // Appliquer l'état disabled en fonction de la catégorie
            const isAvoirDevis = ($('#categorie_facture').val() === 'Avoir' || $('#categorie_facture').val() === 'Devis');
            clone.querySelectorAll('.select-produit, .select-lot, .quantite, .prix-unitaire').forEach(el => {
                el.disabled = !isAvoirDevis;
            });
            calculerTotauxFacture();
        }

        function supprimerLigneFacture(btn) {
            const ligne = btn.closest('.ligne-produit');
            if (document.querySelectorAll('#lignesContainerFacture .ligne-produit').length > 1) {
                ligne.remove();
                calculerTotauxFacture();
            } else {
                alert('Il faut au moins une ligne.');
            }
        }

        $(document).ready(function() {
            $('.selectpicker').selectpicker('destroy');
            $('.selectpicker').selectpicker();

            // ---- Gestion de l'affichage du bloc des lignes selon la catégorie ----
            toggleLignes();
            $('#categorie_facture').on('change', toggleLignes);

            // ---- Événements ----
            $(document).on('change', '.select-produit', function() {
                mettreAJourLots(this);
            });
            // Événement changement de lot pour recalcul
            $(document).on('change', '.select-lot', function() {
                const ligne = $(this).closest('.ligne-produit');
                const uniteAff = ligne.find('.unite-affichage');
                if (this.selectedIndex > 0) {
                    uniteAff.val(this.options[this.selectedIndex].text);
                } else {
                    uniteAff.val('Unité');
                }
                const prixInput = ligne.find('.prix-unitaire')[0];
                if (prixInput) calculerLigneFacture(prixInput);
            });
            $(document).on('input', '.quantite, .prix-unitaire', function() {
                calculerLigneFacture(this);
            });
            $(document).on('change', '#taxe, #remise', function() {
                calculerTotauxFacture();
            });
            $(document).on('input', '#montant_ht', function() {
                const categorie = $('#categorie_facture').val();
                if (categorie !== 'Avoir' && categorie !== 'Devis') {
                    calculerTotauxFacture();
                }
            });

            // ---- Validation avant soumission ----
            $('#factureForm').on('submit', function(e) {
                const categorie = $('#categorie_facture').val();
                if (categorie === 'Avoir' || categorie === 'Devis') {
                    let hasValidLine = false;
                    $('#lignesContainerFacture .ligne-produit').each(function() {
                        const prod = $(this).find('.select-produit').val();
                        const qte = parseFloat($(this).find('.quantite').val()) || 0;
                        if (prod && qte > 0) hasValidLine = true;
                    });
                    if (!hasValidLine) {
                        alert('Veuillez ajouter au moins une ligne de produit valide (produit sélectionné et quantité > 0).');
                        e.preventDefault();
                        return false;
                    }
                }
                return true;
            });

            // ---- Initialisation pour l'édition (lignes de produits) ----
            <?php if (isset($editFacture) && in_array($editFacture['categorie_facture'], ['Avoir', 'Devis']) && !empty($editLignes)): ?>
                $(function() {
                    const container = document.getElementById('lignesContainerFacture');
                    container.innerHTML = '';
                    <?php foreach ($editLignes as $idx => $ligne): ?>
                        const idx = <?= $idx ?>;
                        const div = document.createElement('div');
                        div.className = 'ligne-produit';
                        div.dataset.index = idx;
                        div.innerHTML = `
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Produit</label>
                                    <select name="produit_id[]" class="form-select select-produit">
                                        <option value="">-- Choisir --</option>
                                        <?php foreach ($produits as $p): ?>
                                            <option value="<?= e($p['code_produit']) ?>" data-prix="<?= $p['prix_produit'] ?>" <?= ($p['code_produit'] == $ligne['produit_id']) ? 'selected' : '' ?>><?= e($p['titre_produit']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-semibold">Lot/unité</label>
                                    <select name="lot_id[]" class="form-select select-lot">
                                        <option value="">Unité</option>
                                        <?php foreach ($lots as $lot): ?>
                                            <option value="<?= e($lot['code_lot_produit']) ?>" <?= ($lot['code_lot_produit'] == $ligne['lot_produit_id']) ? 'selected' : '' ?>><?= e($lot['titre_lot']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-semibold">Unité affichée</label>
                                    <input type="text" name="unite_affichage[]" class="form-control unite-affichage" readonly value="<?= e($ligne['unite_affichage']) ?>">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label fw-semibold">Qté</label>
                                    <input type="number" name="quantite[]" class="form-control quantite" min="1" value="<?= $ligne['quantite_commande'] / max(1, intval($ligne['facteur_conversion'] ?: 1)) ?>" oninput="calculerLigneFacture(this)">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-semibold">Prix unitaire</label>
                                    <input type="number" step="0.01" name="prix_unitaire[]" class="form-control prix-unitaire" min="0" value="<?= $ligne['prix_commande'] ?>" oninput="calculerLigneFacture(this)">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-semibold">Total ligne</label>
                                    <input type="text" name="total_ligne[]" class="form-control total-ligne" readonly value="<?= $ligne['montant_commande'] ?>">
                                </div>
                                <div class="col-md-auto d-flex align-items-end">
                                    <button type="button" class="btn btn-danger btn-sm supprimer-ligne" onclick="supprimerLigneFacture(this)" style="display:<?= ($idx == 0) ? 'none' : 'inline-block' ?>;"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                        `;
                        container.appendChild(div);
                    <?php endforeach; ?>
                    // Réinitialiser les lots pour chaque ligne
                    $('.select-produit').each(function() {
                        mettreAJourLots(this);
                    });
                    // Appliquer l'état disabled selon la catégorie
                    toggleLignes();
                    calculerTotauxFacture();
                });
            <?php endif; ?>

            // ---- MODAL ----
            const factureModal = new bootstrap.Modal(document.getElementById('factureModal'));
            const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));

            // Nouvelle facture
            $('#addBtn').on('click', function() {
                $('#formAction').val('add');
                $('#oldNumero').val('');
                $('#modalTitle').text('Nouvelle facture');
                $('#factureForm')[0].reset();
                $('#numero_facture').prop('readonly', false);
                $('#numero_facture').val('');
                $('#titre_facture').val('');
                $('#montant_ht, #montant_ttc, #avance, #reste').val('0');
                $('#taxe, #remise').val('');
                $('#type_facture').val('');
                $('#categorie_facture').val('');
                $('#etat_facture').prop('selectedIndex', 0);
                $('#contact_id, #utilisateur_id').selectpicker('val', '');
                var today = new Date().toISOString().split('T')[0];
                $('#date_facture').val(today);
                // Réinitialiser les lignes
                const container = document.getElementById('lignesContainerFacture');
                container.innerHTML = `
                    <div class="ligne-produit" data-index="0">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Produit</label>
                                <select name="produit_id[]" class="form-select select-produit">
                                    <option value="">-- Choisir --</option>
                                    <?php foreach ($produits as $p): ?>
                                        <option value="<?= e($p['code_produit']) ?>" data-prix="<?= $p['prix_produit'] ?>"><?= e($p['titre_produit']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Lot/unité</label>
                                <select name="lot_id[]" class="form-select select-lot">
                                    <option value="">Unité</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Unité affichée</label>
                                <input type="text" name="unite_affichage[]" class="form-control unite-affichage" readonly placeholder="Auto">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label fw-semibold">Qté</label>
                                <input type="number" name="quantite[]" class="form-control quantite" min="1" value="1" oninput="calculerLigneFacture(this)">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Prix unitaire</label>
                                <input type="number" step="0.01" name="prix_unitaire[]" class="form-control prix-unitaire" min="0" oninput="calculerLigneFacture(this)">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Total ligne</label>
                                <input type="text" name="total_ligne[]" class="form-control total-ligne" readonly value="0">
                            </div>
                            <div class="col-md-auto d-flex align-items-end">
                                <button type="button" class="btn btn-danger btn-sm supprimer-ligne" onclick="supprimerLigneFacture(this)" style="display:none;"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                    </div>
                `;
                toggleLignes();
                factureModal.show();
            });

            // Modifier (via le bouton editBtn) : on soumet le formulaire caché pour recharger avec les données
            $(document).on('click', '.editBtn', function() {
                const numero = $(this).data('numero');
                $('#actionField').val('edit');
                $('#editNumeroField').val(numero);
                $('#actionForm').submit();
            });

            // Voir détail
            $(document).on('click', '.viewBtn', function() {
                const numero = $(this).data('numero');
                $('#actionField').val('view');
                $('#viewNumeroField').val(numero);
                $('#actionForm').submit();
            });

            // ---- Recherche AJAX ----
            function rechercher(page) {
                page = page || 1;
                var search = $('#searchInput').val();
                var type = $('#typeFilter').val();
                var categorie = $('#categorieFilter').val();
                var contact = $('#contactFilter').val();
                var etat = $('#etatFilter').val();
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        ajax: 1,
                        search: search,
                        type_filter: type,
                        categorie_filter: categorie,
                        contact_filter: contact,
                        etat_filter: etat,
                        page: page
                    },
                    dataType: 'json',
                    success: function(data) {
                        $('#tableBody').html(data.table);
                        $('#paginationContainer').html(data.pagination);
                        $('#totalCount').text(data.total + ' facture(s) - Page ' + data.page + ' / ' +
                            Math.max(1, data.totalPages));
                        $('.page-link').off('click').on('click', function(e) {
                            e.preventDefault();
                            var p = $(this).data('page');
                            if (p) rechercher(p);
                        });
                    },
                    error: function() {
                        alert('Erreur lors de la recherche.');
                    }
                });
            }

            var searchTimeout = null;
            $('#searchInput').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });
            $('#typeFilter, #categorieFilter, #contactFilter, #etatFilter').on('changed.bs.select', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    rechercher(1);
                }, 300);
            });
            $('#filterBtn').on('click', function() {
                rechercher(1);
            });
            $('#resetBtn').on('click', function() {
                $('#searchInput').val('');
                $('#typeFilter').selectpicker('val', '');
                $('#categorieFilter').selectpicker('val', '');
                $('#contactFilter').selectpicker('val', '');
                $('#etatFilter').selectpicker('val', '');
                rechercher(1);
            });

            // ---- Suppression ----
            $(document).on('click', '.deleteBtn', function() {
                const numero = $(this).data('numero');
                const nom = $(this).data('nom');
                $('#deleteNomFacture').text(nom);
                $('#deleteFormId').val(numero);
            });
            $('#confirmDeleteBtn').on('click', function() {
                $('#deleteForm').submit();
            });

            // Auto‑fermeture des alertes
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);

            // ---- Affichage du modal d'édition si on a des données ----
            <?php if (isset($editFacture) && $action === 'edit' && !isset($_POST['titre_facture'])): ?>
                $(function() {
                    // Les valeurs sont déjà dans les inputs via PHP, on force le calcul
                    calculerTotauxFacture();
                    factureModal.show();
                });
            <?php endif; ?>

            <?php if (isset($viewFacture) && $action === 'view'): ?>
                $(function() {
                    $('#viewModalLabel').text('Détails de la facture - <?= e($viewFacture['titre_facture'] ?? $viewFacture['numero_facture']) ?>');
                    const fields = [
                        ['Numéro', '<?= e($viewFacture['numero_facture']) ?>'],
                        ['Titre', '<?= e($viewFacture['titre_facture']) ?>'],
                        ['Type', '<?= e($viewFacture['type_facture']) ?>'],
                        ['Catégorie', '<?= e($viewFacture['categorie_facture']) ?>'],
                        ['Date', '<?= isset($viewFacture['date_facture']) ? date('d/m/Y', strtotime($viewFacture['date_facture'])) : '—' ?>'],
                        ['Montant HT', '<?= number_format((float)$viewFacture['montant_ht'], 2) ?>'],
                        ['TVA', '<?= number_format((float)$viewFacture['taxe'], 2) ?>%'],
                        ['Remise', '<?= number_format((float)$viewFacture['remise'], 2) ?>%'],
                        ['Montant TTC', '<?= number_format((float)$viewFacture['montant_ttc'], 2) ?>'],
                        ['Avance', '<?= number_format((float)$viewFacture['avance'], 2) ?>'],
                        ['Reste', '<?= number_format((float)$viewFacture['reste'], 2) ?>'],
                        ['Contact', '<?= e($viewFacture['nom_prenom_contact'] ?? '—') ?>'],
                        ['Utilisateur', '<?= e($viewFacture['nom_prenom'] ?? '—') ?>'],
                        ['État', '<?= e($viewFacture['etat_facture']) ?>']
                    ];
                    let html = '';
                    fields.forEach(([l, v]) => {
                        let val = v || '—';
                        html +=
                            `<div class="col-sm-6"><div class="bg-light p-3 rounded-3 border"><div class="text-muted small text-uppercase fw-bold">${l}</div><div class="fw-semibold">${val}</div></div></div>`;
                    });
                    $('#viewGrid').html(html);
                    viewModal.show();
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>