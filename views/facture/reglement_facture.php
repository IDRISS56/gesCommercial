<?php
// reglement_fournisseur.php – Gestion des factures fournisseurs (type = Fournisseur) – règlements à effectuer
// Design aligné sur vente.php

while (ob_get_level()) ob_end_clean();
ob_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

require 'databases/database.php';
require 'librairies/fpdf/fpdf.php';

$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

// ---- FONCTIONS ----
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}
function safeText($str) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str);
}

// ---- RÉCUPÉRATION DES TAXES ET REMISES ----
$taxes = $pdo->query("SELECT code_taxe, titre_taxe, taux_taxe FROM taxe WHERE type_taxe = 'TVA' AND etat_taxe = 'Actif' ORDER BY titre_taxe")->fetchAll(PDO::FETCH_ASSOC);
$remises = $pdo->query("SELECT code_taxe, titre_taxe, taux_taxe FROM taxe WHERE type_taxe = 'Remise' AND etat_taxe = 'Actif' ORDER BY titre_taxe")->fetchAll(PDO::FETCH_ASSOC);

// ---- LISTES POUR LES SELECTS (filtrés par type Fournisseur) ----
$contacts = $pdo->query("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Fournisseur' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);
$utilisateurs = $pdo->query("SELECT id, nom_prenom FROM utilisateur WHERE etat = 'Actif' ORDER BY nom_prenom")->fetchAll(PDO::FETCH_ASSOC);
$produits = $pdo->query("SELECT code_produit, titre_produit, prix_produit FROM produit WHERE etat_produit = 'Actif' ORDER BY titre_produit")->fetchAll(PDO::FETCH_ASSOC);
$lots = $pdo->query("SELECT code_lot_produit, produit_id, titre_lot, unites_par_lot FROM lot_produit WHERE etat_lot = 'Actif' ORDER BY titre_lot")->fetchAll(PDO::FETCH_ASSOC);

$types_facture = ['Fournisseur']; // fixé à Fournisseur
$categories_facture = ['Facture', 'Avoir'];
$etats_facture = ['Payée', 'Impayée', 'Partielle', 'En attente'];

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

// --- AJOUT OU MODIFICATION ---
if (($action === 'add' || $action === 'edit') && isset($_POST['titre_facture'])) {
    $numero = trim($_POST['numero_facture'] ?? '');
    $titre = trim($_POST['titre_facture'] ?? '');
    $type = 'Fournisseur'; // forcé
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
                    $pdo->rollBack();
                    $message = "Ce numéro de facture existe déjà.";
                    $messageType = 'warning';
                } else {
                    $sql = "INSERT INTO facture (numero_facture, titre_facture, type_facture, categorie_facture, date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, contact_id, utilisateur_id, etat_facture)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$numero, $titre, $type, $categorie, $date, $montant_ht, $taxe_taux, $remise_taux, $montant_ttc, $avance, $reste, $contact_id, $utilisateur_id, $etat]);

                    if (in_array($categorie, ['Avoir', 'Devis'])) {
                        foreach ($lignesPost as $ligne) {
                            $numCommande = 'LIG-' . $numero . '-' . date('His') . rand(100, 999);
                            $stmtLigne = $pdo->prepare("INSERT INTO commande 
                                (numero_commande, produit_id, contact_id, facture_id, date_commande, heure_commande,
                                 prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id,
                                 boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion)
                                VALUES (?, ?, ?, ?, ?, CURTIME(), 0, ?, ?, ?, ?, NULL, 'Valider', ?, ?, ?)");
                            $stmtLigne->execute([
                                $numCommande,
                                $ligne['produit_id'],
                                $contact_id,
                                $numero,
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
                    $message = "Facture fournisseur « $titre » ajoutée avec succès." . (in_array($categorie, ['Avoir', 'Devis']) ? ' (' . count($lignesPost) . ' ligne(s))' : '');
                    $messageType = 'success';
                }
            } elseif ($action === 'edit') {
                $oldNumero = $_POST['old_numero'] ?? $numero;
                $sql = "UPDATE facture SET numero_facture=?, titre_facture=?, type_facture=?, categorie_facture=?, date_facture=?, montant_ht=?, taxe=?, remise=?, montant_ttc=?, avance=?, reste=?, contact_id=?, utilisateur_id=?, etat_facture=?
                        WHERE numero_facture = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$numero, $titre, $type, $categorie, $date, $montant_ht, $taxe_taux, $remise_taux, $montant_ttc, $avance, $reste, $contact_id, $utilisateur_id, $etat, $oldNumero]);

                $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$oldNumero]);

                if (in_array($categorie, ['Avoir', 'Devis'])) {
                    foreach ($lignesPost as $ligne) {
                        $numCommande = 'LIG-' . $numero . '-' . date('His') . rand(100, 999);
                        $stmtLigne = $pdo->prepare("INSERT INTO commande 
                            (numero_commande, produit_id, contact_id, facture_id, date_commande, heure_commande,
                             prix_achat, prix_commande, quantite_commande, montant_commande, utilisateur_id,
                             boutique_id, etat_commande, lot_produit_id, unite_affichage, facteur_conversion)
                            VALUES (?, ?, ?, ?, ?, CURTIME(), 0, ?, ?, ?, ?, NULL, 'Valider', ?, ?, ?)");
                        $stmtLigne->execute([
                            $numCommande,
                            $ligne['produit_id'],
                            $contact_id,
                            $numero,
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
                $message = "Facture fournisseur « $titre » mise à jour." . (in_array($categorie, ['Avoir', 'Devis']) ? ' (' . count($lignesPost) . ' ligne(s))' : '');
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
            $pdo->prepare("DELETE FROM commande WHERE facture_id = ?")->execute([$numero]);
            $stmt = $pdo->prepare("SELECT titre_facture FROM facture WHERE numero_facture = ?");
            $stmt->execute([$numero]);
            $titre = $stmt->fetchColumn();
            $stmt = $pdo->prepare("DELETE FROM facture WHERE numero_facture = ?");
            $stmt->execute([$numero]);
            $message = "Facture fournisseur « $titre » supprimée.";
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
    $stmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? AND type_facture = 'Fournisseur'");
    $stmt->execute([$numero]);
    $editFacture = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editFacture && in_array($editFacture['categorie_facture'], ['Avoir', 'Devis'])) {
        $stmtLignes = $pdo->prepare("SELECT * FROM commande WHERE facture_id = ?");
        $stmtLignes->execute([$numero]);
        $editLignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $editLignes = [];
    }
}

// ---- FONCTION POUR LE TABLEAU (AJAX) ----
function getTableContent($pdo, $search, $categorie_filter, $contact_filter, $utilisateur_filter, $etat_filter, $page, $perPage = 20)
{
    $sql = "SELECT f.*, c.nom_prenom_contact, u.nom_prenom 
            FROM facture f
            LEFT JOIN contact c ON f.contact_id = c.code_contact
            LEFT JOIN utilisateur u ON f.utilisateur_id = u.id
            WHERE f.type_facture = 'Fournisseur'";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (f.numero_facture LIKE ? OR f.titre_facture LIKE ? OR c.nom_prenom_contact LIKE ? OR u.nom_prenom LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 4; $i++) $params[] = $like;
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
                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                Aucune facture fournisseur trouvée
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
                    <span class="status-badge <?= ($f['etat_facture'] === 'Payée') ? 'on' : 'off' ?>">
                        <span class="sdot"></span><?= e($f['etat_facture']) ?>
                    </span>
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="act-btn e editBtn" data-numero="<?= e($f['numero_facture']) ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                        <button class="act-btn d deleteBtn" data-numero="<?= e($f['numero_facture']) ?>" data-nom="<?= e($f['titre_facture']) ?>" title="Supprimer" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"><i class="bi bi-trash"></i></button>
                        <form method="post" style="display:inline-block;">
                            <input type="hidden" name="export_pdf" value="1">
                            <input type="hidden" name="numero" value="<?= e($f['numero_facture']) ?>">
                            <button type="submit" class="act-btn" title="PDF" style="color:#dc3545; border:none; background:transparent; padding:0; width:34px; height:34px;">
                                <i class="bi bi-file-pdf"></i>
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
                        <a class="page-link" href="#" data-page="<?= $page - 1 ?>"><i class="bi bi-chevron-left"></i></a>
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
                        <a class="page-link" href="#" data-page="<?= $page + 1 ?>"><i class="bi bi-chevron-right"></i></a>
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
    $categorie_filter = trim($_POST['categorie_filter'] ?? '');
    $contact_filter = trim($_POST['contact_filter'] ?? '');
    $utilisateur_filter = trim($_POST['utilisateur_filter'] ?? '');
    $etat_filter = trim($_POST['etat_filter'] ?? '');
    $page = (int)($_POST['page'] ?? 1);
    if ($page < 1) $page = 1;
    $result = getTableContent($pdo, $search, $categorie_filter, $contact_filter, $utilisateur_filter, $etat_filter, $page);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// ---- EXPORT PDF (inchangé) ----
if (isset($_POST['export_pdf']) && $_POST['export_pdf'] == '1' && !empty($_POST['numero'])) {
    error_reporting(0);
    while (ob_get_level()) ob_end_clean();

    $numero = $_POST['numero'];
    $stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact, c.adresse_contact, c.telephone_contact, c.email_contact,
        u.nom_prenom AS vendeur_nom
        FROM facture f
        LEFT JOIN contact c ON f.contact_id = c.code_contact
        LEFT JOIN utilisateur u ON f.utilisateur_id = u.id
        WHERE f.numero_facture = ?");
    $stmt->execute([$numero]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$facture) die('Facture introuvable');

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

    $stmt = $pdo->prepare("SELECT c.*, p.titre_produit,
        COALESCE(c.facteur_conversion, 1) AS facteur_conversion
        FROM commande c
        LEFT JOIN produit p ON c.produit_id = p.code_produit
        WHERE c.facture_id = ?");
    $stmt->execute([$numero]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);
    $blueDark = [0, 51, 102];
    $blueLight = [240, 245, 255];
    $grayBg = [245, 245, 245];
    $toLatin = function($chaine) { return safeText($chaine); };

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

    $pdf->SetFont('Arial', 'B', 24);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $titreDoc = match($facture['categorie_facture']) {
        'Avoir' => 'AVOIR FOURNISSEUR',
        'Devis' => 'DEVIS FOURNISSEUR',
        default => 'FACTURE FOURNISSEUR'
    };
    $pdf->Text(125, $yStart + 10, $toLatin($titreDoc));

    $pdf->SetFillColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Rect(115, $yStart + 13, 50, 10, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Text(122, $yStart + 20, $toLatin('N° ' . $facture['numero_facture']));

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

    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(10, 42, 287, 42);

    $yBlocks = 48;
    $drawAddressBlock = function($pdf, $x, $y, $w, $title, $name, $address, $phone, $email) use ($toLatin, $blueDark, $grayBg) {
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
    $wBlock = (277 - 10) / 2;
    $drawAddressBlock($pdf, 10, $yBlocks, $wBlock, 'ACHETEUR (Notre boutique)',
        $boutique['nom_boutique'],
        $boutique['ville_boutique'] . ', ' . $boutique['pays_boutique'],
        $boutique['telephone_boutique'],
        $boutique['email_boutique']);
    $drawAddressBlock($pdf, 10 + $wBlock + 10, $yBlocks, $wBlock, 'FOURNISSEUR',
        $facture['nom_prenom_contact'],
        $facture['adresse_contact'] ?? '',
        $facture['telephone_contact'] ?? '',
        $facture['email_contact'] ?? '');

    $yTable = 80;
    $pageBottom = 195;
    $colWidths = [25, 100, 28, 22, 27, 35, 40];
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
            $txtX = ($align == 'R') ? $x + $colWidths[$i] - 2 - $pdf->GetStringWidth($label) : (($align == 'C') ? $x + ($colWidths[$i] / 2) - ($pdf->GetStringWidth($label) / 2) : $x + 1);
            $pdf->Rect($x, $yCurrent, $colWidths[$i], $rowH, 'D');
            $pdf->Text($txtX, $yCurrent + 5, $label);
            $x += $colWidths[$i];
        }
        $yCurrent += $rowH;
    }

    if ($yCurrent + 65 > $pageBottom + 15) {
        $pdf->AddPage();
        $yCurrent = 15;
    }

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
    $pdf->Text(12, $ySig + 5, $toLatin('Le fournisseur'));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text(12, $ySig + 10, $toLatin('Nom et Signature'));

    $pdf->SetDrawColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Rect(60, $ySig + 5, 70, $hSig - 6, 'D');
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text(63, $ySig + 9, $toLatin($facture['nom_prenom_contact']));
    $pdf->SetFont('Arial', '', 6);
    $pdf->Text(63, $ySig + 13, $toLatin($facture['adresse_contact'] ?? ''));
    $pdf->Text(63, $ySig + 17, $toLatin('Tél. : ' . $facture['telephone_contact'] ?? ''));

    $xClient = 10 + $wSig + 21;
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Rect($xClient, $ySig, $wSig, $hSig, 'D');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($blueDark[0], $blueDark[1], $blueDark[2]);
    $pdf->Text($xClient + 2, $ySig + 5, $toLatin('Notre entreprise'));
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Text($xClient + 2, $ySig + 10, $toLatin('Nom et Signature'));

    while (ob_get_level()) ob_end_clean();
    $pdf->Output('I', 'Facture_Fournisseur_' . $facture['numero_facture'] . '.pdf');
    exit;
}

// ---- AFFICHAGE INITIAL ----
$search = trim($_POST['search'] ?? '');
$categorie_filter = trim($_POST['categorie_filter'] ?? '');
$contact_filter = trim($_POST['contact_filter'] ?? '');
$utilisateur_filter = trim($_POST['utilisateur_filter'] ?? '');
$etat_filter = trim($_POST['etat_filter'] ?? '');
$page = (int)($_POST['page'] ?? 1);
if ($page < 1) $page = 1;
$initialData = getTableContent($pdo, $search, $categorie_filter, $contact_filter, $utilisateur_filter, $etat_filter, $page);

$formAction = ($action === 'edit' && isset($editFacture)) ? 'edit' : 'add';
$oldNumero = ($action === 'edit' && isset($editFacture)) ? e($editFacture['numero_facture']) : '';
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Règlement fournisseurs</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Bootstrap SelectPicker (CSS) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --b: #2563eb;
            --bd: #1d4ed8;
            --bl: #eff6ff;
            --bb: #bfdbfe;
            --bg: #f1f5f9;
            --w: #fff;
            --dk: #0f172a;
            --mt: #64748b;
            --lt: #94a3b8;
            --brd: #e2e8f0;
            --dng: #ef4444;
            --dngl: #fef2f2;
            --dngb: #fecaca;
            --suc: #10b981;
            --sucl: #ecfdf5;
            --sucb: #a7f3d0;
            --wrn: #f59e0b;
            --wrnl: #fffbeb;
            --wrnb: #fde68a;
            --prp: #8b5cf6;
            --prpl: #f5f3ff;
            --prpb: #e9d5ff;
            --tl: #0891b2;
            --tll: #ecfeff;
            --tlb: #cffafe;
            --R: 16px;
            --Rs: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg);
            color: var(--dk);
            min-height: 100vh;
            line-height: 1.5;
            padding: 28px 20px;
        }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .W { max-width: 1400px; margin: 0 auto; }
        .hdr {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--dk); letter-spacing: -0.02em; }
        .hdr-l p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
        .hdr-r {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .hdr-badge {
            background: var(--bl);
            border: 1px solid var(--bb);
            color: var(--b);
            padding: 8px 14px;
            border-radius: var(--Rs);
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .pbar {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            padding: 16px 20px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .prow {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .prow label {
            font-size: 11px;
            font-weight: 600;
            color: var(--mt);
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .prow input, .prow select {
            padding: 7px 10px;
            border: 1.5px solid var(--brd);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: var(--dk);
            background: var(--bg);
            font-family: 'Inter', sans-serif;
            transition: all .2s;
        }
        .prow input:focus, .prow select:focus {
            border-color: var(--b);
            background: #fff;
            box-shadow: 0 0 0 3px var(--bl);
            outline: none;
        }
        .prow select {
            appearance: none;
            padding-right: 32px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }
        .btn-go {
            background: var(--b);
            color: #fff;
            padding: 7px 16px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 4px rgba(37,99,235,.2);
            transition: background .15s;
            border: none;
            cursor: pointer;
        }
        .btn-go:hover { background: var(--bd); }
        .btn-go-outline {
            background: transparent;
            color: var(--mt);
            border: 1.5px solid var(--brd);
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s;
            cursor: pointer;
        }
        .btn-go-outline:hover {
            background: var(--bg);
            border-color: var(--lt);
        }

        .data-table-wrap {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .table>:not(caption)>*>* { padding: 12px 18px; }
        .table thead th {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--lt);
            background: var(--bg);
            border-bottom: 1px solid var(--brd);
        }
        .table tbody tr {
            border-bottom: 1px solid var(--brd);
            transition: background .2s;
        }
        .table tbody tr:hover { background: var(--bl); }
        .table tbody td {
            vertical-align: middle;
            color: var(--dk);
            font-size: 0.85rem;
        }
        .td-bold { color: var(--dk) !important; font-weight: 700; }
        .td-semi { color: var(--dk) !important; font-weight: 500; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            border-radius: 999px;
            font-size: 0.73rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .status-badge .sdot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .status-badge.on { background: var(--sucl); color: #059669; }
        .status-badge.off { background: var(--dngl); color: #dc2626; }

        .act-btn {
            width: 34px;
            height: 34px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--lt);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all .2s;
        }
        .act-btn:hover { transform: scale(1.1); }
        .act-btn.e:hover { color: var(--wrn); background: var(--wrnl); border-color: rgba(245,158,11,.15); }
        .act-btn.d:hover { color: var(--dng); background: var(--dngl); border-color: rgba(239,68,68,.15); }

        .pagination .page-link {
            color: var(--b);
            border: 1px solid var(--brd);
            border-radius: 6px;
            margin: 0 2px;
            padding: 6px 14px;
            font-weight: 500;
        }
        .pagination .page-link:hover { background: var(--bl); border-color: var(--b); }
        .pagination .page-item.active .page-link { background: var(--b); border-color: var(--b); color: #fff; }
        .pagination .page-item.disabled .page-link { color: var(--lt); border-color: var(--brd); }

        .modal-content {
            border-radius: var(--R);
            border: none;
            box-shadow: 0 12px 40px rgba(15,23,42,.08);
        }
        .modal-header { border-bottom: 1px solid var(--brd); background: var(--bg); }
        .modal-footer { border-top: 1px solid var(--brd); background: var(--bg); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .data-table-wrap { animation: fadeUp .4s ease both; }

        @media (max-width:700px) {
            body { padding: 14px; }
            .hdr { flex-direction: column; align-items: flex-start; }
            .prow { flex-direction: column; align-items: stretch; }
            .prow .btn-go { width: 100%; justify-content: center; }
        }
        .bootstrap-select .dropdown-toggle .filter-option { color: var(--dk); }
        .bootstrap-select .dropdown-menu {
            border-radius: var(--Rs);
            border-color: var(--brd);
        }
        .bootstrap-select .dropdown-menu .bs-searchbox input {
            border-radius: 6px;
            border: 1px solid var(--brd);
            padding: 8px 12px;
        }
        .bootstrap-select .dropdown-menu .bs-searchbox input:focus {
            border-color: var(--b);
            box-shadow: 0 0 0 3px var(--bl);
        }
    </style>
</head>

<body>
<div class="W">
    <!-- En-tête -->
    <div class="hdr">
        <div class="hdr-l">
            <h1>Règlement fournisseurs</h1>
            <p>Factures à payer – suivi des dettes fournisseurs</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-receipt"></i> <?= $initialData['total'] ?? 0 ?> factures fournisseurs</div>
            <button class="btn-go" id="addBtn"><i class="bi bi-plus-circle"></i> Nouvelle facture fournisseur</button>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Barre de recherche / filtres -->
    <div class="pbar">
        <form id="searchForm" method="post" onsubmit="return false;">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="page" id="pageInput" value="<?= e($page) ?>">
            <div class="prow">
                <label for="searchInput"><i class="bi bi-search"></i> Recherche</label>
                <input type="text" name="search" id="searchInput" placeholder="Numéro, titre, fournisseur..." value="<?= e($search) ?>" style="flex:1; min-width:150px;">
                <label for="categorieFilter">Catégorie</label>
                <select name="categorie_filter" id="categorieFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher une catégorie...">
                    <option value="">Toutes</option>
                    <?php foreach ($categories_facture as $c): ?>
                        <option value="<?= e($c) ?>" <?= ($categorie_filter == $c) ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="contactFilter">Fournisseur</label>
                <select name="contact_filter" id="contactFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un fournisseur...">
                    <option value="">Tous</option>
                    <?php foreach ($contacts as $c): ?>
                        <option value="<?= e($c['code_contact']) ?>" <?= ($contact_filter == $c['code_contact']) ? 'selected' : '' ?>>
                            <?= e($c['nom_prenom_contact']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="etatFilter">État</label>
                <select name="etat_filter" id="etatFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un état...">
                    <option value="">Tous</option>
                    <?php foreach ($etats_facture as $e): ?>
                        <option value="<?= e($e) ?>" <?= ($etat_filter == $e) ? 'selected' : '' ?>><?= e($e) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-go" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn-go-outline" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i></button>
            </div>
        </form>
    </div>

    <!-- Tableau -->
    <div class="data-table-wrap" id="tableWrapper">
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-bottom bg-light">
            <h5 class="mb-0 fw-bold" style="font-family:'Outfit',sans-serif;">Liste des factures fournisseurs</h5>
            <span class="text-muted small" id="totalCount"><?= $initialData['total'] ?? 0 ?> facture(s) - Page <?= $initialData['page'] ?? 1 ?> / <?= max(1, $initialData['totalPages'] ?? 1) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Numéro</th>
                        <th>Titre</th>
                        <th>Type</th>
                        <th>Catégorie</th>
                        <th>Date</th>
                        <th>HT</th>
                        <th>TTC</th>
                        <th>Avance</th>
                        <th>Reste</th>
                        <th>Fournisseur</th>
                        <th>État</th>
                        <th class="text-end">Actions</th>
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

<!-- ========================================================= -->
<!-- MODAL FORMULAIRE (ajout/modification) -->
<!-- ========================================================= -->
<div class="modal fade" id="factureModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-file-earmark-text text-primary me-2"></i> Nouvelle facture fournisseur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form method="post" id="factureForm">
                <input type="hidden" name="action" id="formAction" value="<?= $formAction ?>">
                <input type="hidden" name="old_numero" id="oldNumero" value="<?= $oldNumero ?>">
                <input type="hidden" name="type_facture" value="Fournisseur">
                <div class="modal-body">
                    <!-- Identification -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-tag me-1"></i> Identification</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label for="numero_facture" class="form-label fw-semibold">Numéro facture <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control" id="numero_facture" name="numero_facture" required placeholder="FAC-FOUR-001" 
                                       value="<?= e($editFacture['numero_facture'] ?? '') ?>"
                                       <?= ($action === 'edit' && isset($editFacture)) ? 'readonly' : '' ?>>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="titre_facture" class="form-label fw-semibold">Titre <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-heading"></i></span>
                                <input type="text" class="form-control" id="titre_facture" name="titre_facture" placeholder="Facture d'achat" value="<?= e($editFacture['titre_facture'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="date_facture" class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                <input type="date" class="form-control" id="date_facture" name="date_facture" value="<?= isset($editFacture) ? $editFacture['date_facture'] : date('Y-m-d') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Catégorie (le type est fixe) -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-tags me-1"></i> Catégorie</h6>
                    <div class="row g-3 mb-4">
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
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-boxes me-1"></i> Lignes de produits</h6>
                        <div id="lignesContainerFacture">
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
                                        <button type="button" class="btn btn-danger btn-sm supprimer-ligne" onclick="supprimerLigneFacture(this)" style="display:none;"><i class="bi bi-trash"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary btn-ajouter" onclick="ajouterLigneFacture()" id="btnAjouterLigne"><i class="bi bi-plus"></i> Ajouter une ligne</button>
                    </div>

                    <!-- Montants -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-calculator me-1"></i> Montants</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label for="montant_ht" class="form-label fw-semibold">Montant HT</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-currency-dollar"></i></span>
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
                                <span class="input-group-text"><i class="bi bi-currency-dollar"></i></span>
                                <input type="number" step="0.01" class="form-control" id="montant_ttc" name="montant_ttc" placeholder="0.00" readonly value="<?= e($editFacture['montant_ttc'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Avance et reste -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-hand-holding-usd me-1"></i> Paiement</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="avance" class="form-label fw-semibold">Avance</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-arrow-down"></i></span>
                                <input type="number" step="0.01" class="form-control" id="avance" name="avance" placeholder="0.00" value="<?= e($editFacture['avance'] ?? '0') ?>" oninput="calculerTotauxFacture()">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="reste" class="form-label fw-semibold">Reste</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-arrow-up"></i></span>
                                <input type="number" step="0.01" class="form-control" id="reste" name="reste" placeholder="0.00" readonly value="<?= e($editFacture['reste'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Associations -->
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-people me-1"></i> Associations</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="contact_id" class="form-label fw-semibold">Fournisseur <span class="text-danger">*</span></label>
                            <select class="selectpicker form-control" id="contact_id" name="contact_id" data-live-search="true" data-live-search-placeholder="Rechercher un fournisseur..." required>
                                <option value="">=== Choisir un fournisseur ===</option>
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
                    <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-toggle-on me-1"></i> Statut</h6>
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x"></i> Annuler</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn"><i class="bi bi-save"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODALE CONFIRMATION SUPPRESSION -->
<!-- ========================================================= -->
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

    function calculerLigneFacture(element) {
        const ligne = element.closest('.ligne-produit');
        const quantite = parseFloat(ligne.querySelector('.quantite').value) || 0;
        const prix = parseFloat(ligne.querySelector('.prix-unitaire').value) || 0;
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
        let taxeTaux = 0, remiseTaux = 0;
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

    function toggleLignes() {
        const categorie = $('#categorie_facture').val();
        const isAvoirDevis = (categorie === 'Avoir' || categorie === 'Devis');
        if (isAvoirDevis) {
            $('#blocLignes').slideDown();
            $('#montant_ht').prop('readonly', true);
            $('#lignesContainerFacture .select-produit, #lignesContainerFacture .select-lot, #lignesContainerFacture .quantite, #lignesContainerFacture .prix-unitaire')
                .prop('disabled', false);
        } else {
            $('#blocLignes').slideUp();
            $('#montant_ht').prop('readonly', false);
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

        toggleLignes();
        $('#categorie_facture').on('change', toggleLignes);

        $(document).on('change', '.select-produit', function() {
            mettreAJourLots(this);
        });
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
                                <button type="button" class="btn btn-danger btn-sm supprimer-ligne" onclick="supprimerLigneFacture(this)" style="display:<?= ($idx == 0) ? 'none' : 'inline-block' ?>;"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    `;
                    container.appendChild(div);
                <?php endforeach; ?>
                $('.select-produit').each(function() {
                    mettreAJourLots(this);
                });
                toggleLignes();
                calculerTotauxFacture();
            });
        <?php endif; ?>

        const factureModal = new bootstrap.Modal(document.getElementById('factureModal'));

        $('#addBtn').on('click', function() {
            $('#formAction').val('add');
            $('#oldNumero').val('');
            $('#modalTitle').text('Nouvelle facture fournisseur');
            $('#factureForm')[0].reset();
            $('#numero_facture').prop('readonly', false);
            $('#numero_facture').val('');
            $('#titre_facture').val('');
            $('#montant_ht, #montant_ttc, #avance, #reste').val('0');
            $('#taxe, #remise').val('');
            $('#categorie_facture').val('');
            $('#etat_facture').prop('selectedIndex', 0);
            $('#contact_id, #utilisateur_id').selectpicker('val', '');
            var today = new Date().toISOString().split('T')[0];
            $('#date_facture').val(today);
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
                            <button type="button" class="btn btn-danger btn-sm supprimer-ligne" onclick="supprimerLigneFacture(this)" style="display:none;"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                </div>
            `;
            toggleLignes();
            factureModal.show();
        });

        $(document).on('click', '.editBtn', function() {
            const numero = $(this).data('numero');
            $('#actionField').val('edit');
            $('#editNumeroField').val(numero);
            $('#actionForm').submit();
        });

        function rechercher(page) {
            page = page || 1;
            var search = $('#searchInput').val();
            var categorie = $('#categorieFilter').val();
            var contact = $('#contactFilter').val();
            var etat = $('#etatFilter').val();
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    ajax: 1,
                    search: search,
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
            searchTimeout = setTimeout(function() { rechercher(1); }, 300);
        });
        $('#categorieFilter, #contactFilter, #etatFilter').on('changed.bs.select', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() { rechercher(1); }, 300);
        });
        $('#filterBtn').on('click', function() { rechercher(1); });
        $('#resetBtn').on('click', function() {
            $('#searchInput').val('');
            $('#categorieFilter, #contactFilter, #etatFilter').selectpicker('val', '');
            rechercher(1);
        });

        $(document).on('click', '.deleteBtn', function() {
            const numero = $(this).data('numero');
            const nom = $(this).data('nom');
            $('#deleteNomFacture').text(nom);
            $('#deleteFormId').val(numero);
        });
        $('#confirmDeleteBtn').on('click', function() {
            $('#deleteForm').submit();
        });

        setTimeout(function() { $('.alert').alert('close'); }, 5000);

        <?php if (isset($editFacture) && $action === 'edit' && !isset($_POST['titre_facture'])): ?>
            $(function() {
                calculerTotauxFacture();
                factureModal.show();
            });
        <?php endif; ?>
    });
</script>
</body>
</html>