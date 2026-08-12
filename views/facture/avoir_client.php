<?php
// views/facture/avoir_client.php – Émission d'avoirs clients
//
// Un vrai avoir client peut désormais prendre deux formes :
//   1) Retour de marchandise : on saisit les articles physiquement rendus par
//      le client (quantité, prix). Le stock de la boutique choisie est
//      RÉINTÉGRÉ automatiquement, et la quantité retournée ne peut jamais
//      dépasser la quantité réellement vendue sur la facture d'origine
//      (moins ce qui a déjà été retourné).
//   2) Avoir financier libre (geste commercial, erreur de facturation...) :
//      un montant saisi à la main, motif obligatoire, sans aucun impact sur
//      le stock.
// Dans les deux cas, l'avoir réduit ce que le client doit (ou augmente son
// avance) exactement comme un règlement : contact.solde_contact -= montant.
require 'databases/database.php';
while (ob_get_level()) ob_end_clean();
ob_start();

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$isAjax = $isAjax || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

// ==========================================
// SÉCURITÉ : utilisateur connecté & actif
// ==========================================
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Session expirée.']); exit; }
    header('Location: utilisateur/login');
    exit;
}
$stmtUser = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmtUser->execute([$_SESSION['user_id']]);
$userInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$userInfo) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Utilisateur invalide.']); exit; }
    session_destroy();
    header('Location: utilisateur/login');
    exit;
}
define('USER_ID', $_SESSION['user_id']);
define('USER_BOUTIQUE', $userInfo['boutique_id'] ?? null);

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function fmt($n) { return number_format((float)$n, 0, ',', ' '); }

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

    if ($action === 'creer_avoir') {
        try {
            $client_id = trim($_POST['client_id'] ?? '');
            $motif = trim($_POST['motif'] ?? '');
            $facture_ref = trim($_POST['facture_ref'] ?? '') ?: null;
            $boutique_id = trim($_POST['boutique_id'] ?? '') ?: null;
            $lignes = json_decode($_POST['lignes'] ?? '[]', true) ?: [];
            $montantLibre = round(floatval($_POST['montant'] ?? 0), 2);

            if (empty($client_id)) throw new Exception('Veuillez sélectionner un client.');

            // Nettoyage / validation des lignes de retour de marchandise
            $lignesValides = [];
            foreach ($lignes as $l) {
                $code = trim($l['code'] ?? '');
                $qte = max(0, intval($l['qte'] ?? 0));
                $prix = floatval($l['prix'] ?? 0);
                if ($code && $qte > 0 && $prix >= 0) {
                    $lignesValides[] = ['code' => $code, 'qte' => $qte, 'prix' => $prix, 'montant' => round($qte * $prix, 2)];
                }
            }

            $avecRetourStock = !empty($lignesValides);

            if ($avecRetourStock) {
                if (empty($boutique_id)) throw new Exception('Veuillez indiquer la boutique qui réceptionne le retour de marchandise.');
                $montant = round(array_sum(array_column($lignesValides, 'montant')), 2);
            } else {
                $montant = $montantLibre;
                if ($montant <= 0) throw new Exception('Indiquez un montant, ou ajoutez au moins un article retourné.');
                if ($motif === '') throw new Exception('Le motif est obligatoire pour un avoir sans article retourné.');
            }

            $pdo->beginTransaction();

            // Si l'avoir est rattaché à une facture précise, on ne peut pas dépasser son reste
            if ($facture_ref) {
                $stmt = $pdo->prepare("SELECT reste FROM facture WHERE numero_facture = ? AND contact_id = ? AND type_facture = 'Client' FOR UPDATE");
                $stmt->execute([$facture_ref, $client_id]);
                $reste = $stmt->fetchColumn();
                if ($reste === false) throw new Exception('Facture introuvable pour ce client.');
                if ($montant > floatval($reste)) throw new Exception('L\'avoir dépasse le reste dû sur cette facture (' . fmt($reste) . ' F).');
            }

            // Contrôle des quantités : impossible de retourner plus que ce qui a
            // été vendu sur la facture (moins ce qui a déjà été retourné dessus).
            if ($avecRetourStock && $facture_ref) {
                foreach ($lignesValides as $l) {
                    $stmtV = $pdo->prepare("SELECT COALESCE(SUM(quantite_commande),0) FROM commande WHERE facture_id = ? AND produit_id = ?");
                    $stmtV->execute([$facture_ref, $l['code']]);
                    $qteVendue = (int)$stmtV->fetchColumn();

                    $stmtR = $pdo->prepare("SELECT COALESCE(SUM(cm.quantite_commande),0) FROM commande cm
                        JOIN facture fa ON cm.facture_id = fa.numero_facture
                        WHERE fa.reference_id = ? AND fa.categorie_facture = 'AvoirClient' AND cm.produit_id = ?");
                    $stmtR->execute([$facture_ref, $l['code']]);
                    $qteDejaRetournee = (int)$stmtR->fetchColumn();

                    if ($qteDejaRetournee + $l['qte'] > $qteVendue) {
                        $stmtNom = $pdo->prepare("SELECT titre_produit FROM produit WHERE code_produit = ?");
                        $stmtNom->execute([$l['code']]);
                        $nom = $stmtNom->fetchColumn() ?: $l['code'];
                        throw new Exception("Retour refusé pour « $nom » : vendu $qteVendue, déjà retourné $qteDejaRetournee, demandé {$l['qte']}.");
                    }
                }
            }

            $numAvoir = 'AVC-' . date('Ymd') . '-' . str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $titre = 'Avoir client' . ($motif !== '' ? ' — ' . $motif : '') . ($avecRetourStock ? ' (retour marchandise)' : '');
            $pdo->prepare("INSERT INTO facture(numero_facture, titre_facture, type_facture, categorie_facture, date_facture, montant_ht, taxe, remise, montant_ttc, avance, reste, contact_id, utilisateur_id, etat_facture, statut_facture, reference_id)
                          VALUES (?, ?, 'Client', 'AvoirClient', CURDATE(), ?, 0, 0, ?, ?, 0, ?, ?, 'Payee', 'Validee', ?)")
                ->execute([$numAvoir, $titre, $montant, $montant, $montant, $client_id, USER_ID, $facture_ref]);

            // Lignes de l'avoir + réintégration physique du stock retourné
            if ($avecRetourStock) {
                $numBase = date('dmYHis');
                foreach ($lignesValides as $i => $l) {
                    $numLigne = 'AVL-' . $numBase . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO commande(numero_commande, produit_id, contact_id, facture_id, statut_id, date_commande, heure_commande, prix_achat, prix_commande, quantite_commande, produits_par_lot, montant_commande, utilisateur_id, boutique_id, etat_commande)
                                  VALUES (?, ?, ?, ?, '012', CURDATE(), CURTIME(), ?, ?, ?, 1, ?, ?, ?, 'VALIDEE')")
                        ->execute([$numLigne, $l['code'], $client_id, $numAvoir, $l['prix'], $l['prix'], $l['qte'], $l['montant'], USER_ID, $boutique_id]);

                    // Réintégration du stock (retour physique côté client)
                    $pdo->prepare("UPDATE stock SET quantite = quantite + ? WHERE produit_id = ? AND boutique_id = ?")
                        ->execute([$l['qte'], $l['code'], $boutique_id]);
                    $pdo->prepare("UPDATE produit SET stock_produit = CAST(CAST(COALESCE(stock_produit,0) AS SIGNED) + ? AS CHAR) WHERE code_produit = ?")
                        ->execute([$l['qte'], $l['code']]);
                    $pdo->prepare("UPDATE produit SET etat_produit = CASE
                                    WHEN CAST(stock_produit AS SIGNED) <= 0 THEN 'RUPTURE'
                                    WHEN CAST(stock_produit AS SIGNED) <= COALESCE(stock_alerte,0) THEN 'ALERTE'
                                    ELSE 'DISPONIBLE' END WHERE code_produit = ?")
                        ->execute([$l['code']]);
                }
            }

            // Si rattaché à une facture, on réduit aussi son reste (comme un règlement)
            if ($facture_ref) {
                $stmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? FOR UPDATE");
                $stmt->execute([$facture_ref]);
                $f = $stmt->fetch(PDO::FETCH_ASSOC);
                $nouvelleAvance = min(floatval($f['avance']) + $montant, floatval($f['montant_ttc']));
                $nouveauReste = max(0, round(floatval($f['montant_ttc']) - $nouvelleAvance, 2));
                $nouvelEtat = ($nouveauReste <= 0) ? 'Payee' : (($nouvelleAvance > 0) ? 'Partielle' : 'Impayee');
                $pdo->prepare("UPDATE facture SET avance = ?, reste = ?, etat_facture = ? WHERE numero_facture = ?")
                    ->execute([$nouvelleAvance, $nouveauReste, $nouvelEtat, $facture_ref]);
            }

            // Impact sur le solde global du client : même formule qu'un règlement
            $pdo->prepare("SELECT solde_contact FROM contact WHERE code_contact = ? FOR UPDATE")->execute([$client_id]);
            $pdo->prepare("UPDATE contact SET solde_contact = solde_contact - ? WHERE code_contact = ?")
                ->execute([$montant, $client_id]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Avoir ' . $numAvoir . ' émis pour ' . fmt($montant) . ' F' . ($avecRetourStock ? ' — stock réintégré.' : '.')]);
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_factures_client') {
        $client_id = trim($_POST['client_id'] ?? '');
        $stmt = $pdo->prepare("SELECT numero_facture, reste, montant_ttc FROM facture WHERE contact_id = ? AND type_facture = 'Client' AND categorie_facture = 'Facture' AND reste >= 0 ORDER BY date_facture DESC");
        $stmt->execute([$client_id]);
        echo json_encode(['success' => true, 'factures' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ---- ARTICLES VENDUS SUR UNE FACTURE (pour préremplir le retour) ----
    if ($action === 'get_lignes_facture') {
        $facture_ref = trim($_POST['facture_ref'] ?? '');
        $stmt = $pdo->prepare("SELECT c.produit_id, p.titre_produit, c.prix_commande, SUM(c.quantite_commande) as qte_vendue
                FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit
                WHERE c.facture_id = ?
                GROUP BY c.produit_id, p.titre_produit, c.prix_commande");
        $stmt->execute([$facture_ref]);
        $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lignes as &$l) {
            $stmtR = $pdo->prepare("SELECT COALESCE(SUM(cm.quantite_commande),0) FROM commande cm
                JOIN facture fa ON cm.facture_id = fa.numero_facture
                WHERE fa.reference_id = ? AND fa.categorie_facture = 'AvoirClient' AND cm.produit_id = ?");
            $stmtR->execute([$facture_ref, $l['produit_id']]);
            $l['qte_deja_retournee'] = (int)$stmtR->fetchColumn();
            $l['qte_max_retour'] = max(0, (int)$l['qte_vendue'] - $l['qte_deja_retournee']);
        }
        unset($l);
        echo json_encode(['success' => true, 'lignes' => $lignes]);
        exit;
    }

    // ---- DÉTAILS D'UN AVOIR (lignes retournées, si présentes) ----
    if ($action === 'get_details') {
        $numero = trim($_POST['numero'] ?? '');
        $stmt = $pdo->prepare("SELECT c.*, p.titre_produit FROM commande c LEFT JOIN produit p ON c.produit_id = p.code_produit WHERE c.facture_id = ?");
        $stmt->execute([$numero]);
        echo json_encode(['success' => true, 'lignes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action inconnue.']);
    exit;
}

$clients = $pdo->query("SELECT code_contact, nom_prenom_contact, solde_contact FROM contact WHERE type_contact = 'Client' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact ASC")->fetchAll(PDO::FETCH_ASSOC);
$produits = $pdo->query("SELECT code_produit, titre_produit, prix_produit FROM produit ORDER BY titre_produit ASC")->fetchAll(PDO::FETCH_ASSOC);
$boutiques = $pdo->query("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' ORDER BY nom_boutique ASC")->fetchAll(PDO::FETCH_ASSOC);

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$totalAvoirs = (int)$pdo->query("SELECT COUNT(*) FROM facture WHERE categorie_facture = 'AvoirClient'")->fetchColumn();
$stmt = $pdo->prepare("SELECT f.*, c.nom_prenom_contact,
        EXISTS(SELECT 1 FROM commande cm WHERE cm.facture_id = f.numero_facture) AS a_lignes
    FROM facture f JOIN contact c ON f.contact_id = c.code_contact
    WHERE f.categorie_facture = 'AvoirClient' ORDER BY f.date_facture DESC, f.numero_facture DESC LIMIT $perPage OFFSET $offset");
$stmt->execute();
$avoirsListe = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPages = max(1, ceil($totalAvoirs / $perPage));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

<meta charset="UTF-8">
<title>Avoirs clients</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
body { background: #f8fafc; font-family: 'Segoe UI', system-ui, sans-serif; }
.page-header { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.page-header h2 { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px; margin: 0; }
.card-avoir { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
.container-page { max-width: 1200px; margin: 0 auto; padding: 0 20px 40px; }
.ligne-retour { display:grid; grid-template-columns: 2fr 110px 110px 120px 34px; gap:8px; align-items:center; margin-bottom:8px; }
.ligne-retour .hint { font-size: 10px; color: #6b7280; }
.badge-type { font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 999px; }
.badge-stock { background: #d1fae5; color: #065f46; }
.badge-libre { background: #e0e7ff; color: #3730a3; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1050; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal-box { background:#fff; border-radius:12px; width:700px; max-width:95vw; max-height:92vh; overflow-y:auto; }
.modal-box-head { padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; }
.modal-box-body { padding:20px; }
</style>
</head>
<body>

<div class="page-header">
    <h2><i class="bi bi-receipt-cutoff"></i> Avoirs clients</h2>
    <span class="text-muted small"><i class="bi bi-person"></i> <?= e($userInfo['nom_prenom'] ?? '') ?></span>
</div>

<div class="container-page">
    <div id="alertZone"></div>

    <div class="card-avoir p-3 mb-4">
        <h5 class="mb-3"><i class="bi bi-plus-circle"></i> Émettre un avoir</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Client</label>
                <select id="clientSelect" class="form-select" onchange="chargerFactures()">
                    <option value="">-- Sélectionner un client --</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= e($c['code_contact']) ?>" data-solde="<?= floatval($c['solde_contact']) ?>"><?= e($c['nom_prenom_contact']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="soldeInfo" class="small text-muted mt-1"></div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Facture concernée (optionnel)</label>
                <select id="factureSelect" class="form-select" onchange="chargerLignesFacture()">
                    <option value="">-- Avoir libre (non rattaché) --</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Boutique de réception du retour</label>
                <select id="boutiqueSelect" class="form-select">
                    <option value="">-- Requis si articles retournés --</option>
                    <?php foreach ($boutiques as $b): ?>
                    <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label fw-semibold mb-0"><i class="bi bi-box-arrow-in-left"></i> Articles retournés (réintègrent le stock)</label>
                    <button class="btn btn-sm btn-outline-primary" onclick="ajouterLigneRetour()"><i class="bi bi-plus"></i> Ajouter un article</button>
                </div>
                <div id="lignesContainer"></div>
                <div class="text-end fw-semibold mt-1" id="totalLignes">Total articles : 0 F</div>
            </div>

            <div class="col-12">
                <hr>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="modeLibre" onchange="toggleModeLibre()">
                    <label class="form-check-label" for="modeLibre">Avoir financier libre (geste commercial / erreur de facturation, sans article retourné)</label>
                </div>
                <div id="zoneLibre" style="display:none;">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Montant de l'avoir (F)</label>
                            <input type="number" id="montantAvoir" class="form-control" min="1" step="1" placeholder="0">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Motif (obligatoire)</label>
                            <input type="text" id="motifAvoir" class="form-control" placeholder="Ex : geste commercial, erreur de facturation...">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="text-end mt-3">
            <button class="btn btn-primary" onclick="emettreAvoir()"><i class="bi bi-save"></i> Émettre l'avoir</button>
        </div>
    </div>

    <div class="card-avoir p-3">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>N° Avoir</th><th>Client</th><th>Date</th><th>Type</th><th>Motif</th><th class="text-end">Montant</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($avoirsListe)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Aucun avoir émis pour l'instant.</td></tr>
                <?php endif; ?>
                <?php foreach ($avoirsListe as $a): ?>
                <tr>
                    <td class="fw-semibold"><?= e($a['numero_facture']) ?></td>
                    <td><?= e($a['nom_prenom_contact']) ?></td>
                    <td><?= date('d/m/Y', strtotime($a['date_facture'])) ?></td>
                    <td><?php if ($a['a_lignes']): ?><span class="badge-type badge-stock">Retour marchandise</span><?php else: ?><span class="badge-type badge-libre">Financier libre</span><?php endif; ?></td>
                    <td><?= e($a['titre_facture']) ?><?= $a['reference_id'] ? ' <span class="badge bg-light text-dark">' . e($a['reference_id']) . '</span>' : '' ?></td>
                    <td class="text-end fw-bold text-success"><?= fmt($a['montant_ttc']) ?> F</td>
                    <td class="text-end"><button class="btn btn-sm btn-outline-secondary" onclick="voirDetails('<?= e($a['numero_facture']) ?>')"><i class="bi bi-eye"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="d-flex justify-content-center gap-2 mt-3">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?c=facture&a=avoirClient&page=<?= $p ?>" class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL DÉTAILS -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box">
        <div class="modal-box-head">
            <h5 class="mb-0"><i class="bi bi-eye"></i> Détails de l'avoir</h5>
            <button class="btn-close" onclick="fermerModal('detailsModal')"></button>
        </div>
        <div class="modal-box-body" id="detailsBody"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.0/dist/jquery.min.js"></script>
<script>
const CSRF_TOKEN = '<?= $csrf_token ?>';
const PRODUITS = <?= json_encode($produits) ?>;

function ouvrirModal(id) { document.getElementById(id).classList.add('show'); }
function fermerModal(id) { document.getElementById(id).classList.remove('show'); }

function fmtN(n) { return Math.round(n).toLocaleString('fr-FR') + ' F'; }

document.getElementById('clientSelect').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    const solde = parseFloat(opt.dataset.solde || 0);
    const info = document.getElementById('soldeInfo');
    if (!this.value) { info.textContent = ''; return; }
    info.textContent = solde > 0 ? `Doit actuellement ${fmtN(solde)}` : (solde < 0 ? `En avance de ${fmtN(-solde)}` : `À jour`);
});

function chargerFactures() {
    const client_id = document.getElementById('clientSelect').value;
    const sel = document.getElementById('factureSelect');
    sel.innerHTML = '<option value="">-- Avoir libre (non rattaché) --</option>';
    document.getElementById('lignesContainer').innerHTML = '';
    calculerTotalLignes();
    if (!client_id) return;
    $.post(window.location.href, { action: 'get_factures_client', ajax: 1, csrf_token: CSRF_TOKEN, client_id }, function(res) {
        if (res.success) {
            res.factures.forEach(f => {
                sel.innerHTML += `<option value="${f.numero_facture}" data-reste="${f.reste}">${f.numero_facture} — reste ${Number(f.reste).toLocaleString('fr-FR')} F</option>`;
            });
        }
    }, 'json');
}

// Charge automatiquement les articles vendus sur la facture choisie, avec la
// quantité déjà retournée pour empêcher un retour fantôme.
function chargerLignesFacture() {
    const facture_ref = document.getElementById('factureSelect').value;
    document.getElementById('lignesContainer').innerHTML = '';
    if (!facture_ref) { calculerTotalLignes(); return; }
    $.post(window.location.href, { action: 'get_lignes_facture', ajax: 1, csrf_token: CSRF_TOKEN, facture_ref }, function(res) {
        if (res.success) {
            res.lignes.forEach(l => {
                if (l.qte_max_retour > 0) {
                    ajouterLigneRetour(l.produit_id, l.titre_produit, l.qte_max_retour, l.prix_commande, 0);
                }
            });
        }
    }, 'json');
}

function ajouterLigneRetour(code, nom, qteMax, prix, qteDefaut) {
    const container = document.getElementById('lignesContainer');
    const div = document.createElement('div');
    div.className = 'ligne-retour';

    let options = '<option value="">-- Produit --</option>';
    PRODUITS.forEach(p => {
        const sel = code && p.code_produit === code ? 'selected' : '';
        options += `<option value="${p.code_produit}" data-prix="${p.prix_produit}" ${sel}>${p.titre_produit.replace(/</g,'&lt;')}</option>`;
    });

    const qteVal = (qteDefaut !== undefined) ? qteDefaut : 1;
    const prixVal = (prix !== undefined && prix !== null) ? prix : 0;
    const maxAttr = qteMax ? qteMax : '';

    div.innerHTML = `
        <select class="form-select form-select-sm ligne-produit" onchange="produitLigneChoisi(this)">${options}</select>
        <input type="number" class="form-control form-control-sm ligne-qte" min="0" ${maxAttr ? 'max="' + maxAttr + '"' : ''} value="${qteVal}" onchange="calculerTotalLignes()">
        <input type="number" class="form-control form-control-sm ligne-prix" min="0" step="1" value="${prixVal}" onchange="calculerTotalLignes()">
        <span class="ligne-montant text-end fw-semibold">0 F</span>
        <button class="btn btn-sm btn-outline-danger" onclick="this.parentElement.remove(); calculerTotalLignes();"><i class="bi bi-x"></i></button>
    `;
    if (maxAttr) {
        const hint = document.createElement('div');
        hint.className = 'hint';
        hint.style.gridColumn = '1 / -1';
        hint.textContent = `Quantité vendue et non encore retournée sur cette facture : ${qteMax}`;
        div.appendChild(hint);
    }
    container.appendChild(div);
    calculerTotalLignes();
}

function produitLigneChoisi(sel) {
    const opt = sel.options[sel.selectedIndex];
    const prix = opt.dataset.prix || 0;
    sel.closest('.ligne-retour').querySelector('.ligne-prix').value = prix;
    calculerTotalLignes();
}

function calculerTotalLignes() {
    let total = 0;
    document.querySelectorAll('.ligne-retour').forEach(div => {
        const qte = parseFloat(div.querySelector('.ligne-qte').value) || 0;
        const prix = parseFloat(div.querySelector('.ligne-prix').value) || 0;
        const montant = qte * prix;
        div.querySelector('.ligne-montant').textContent = fmtN(montant);
        total += montant;
    });
    document.getElementById('totalLignes').textContent = 'Total articles : ' + fmtN(total);
    return total;
}

function toggleModeLibre() {
    document.getElementById('zoneLibre').style.display = document.getElementById('modeLibre').checked ? 'block' : 'none';
}

function afficherAlerte(message, type) {
    document.getElementById('alertZone').innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">${message}<button class="btn-close" data-bs-dismiss="alert"></button></div>`;
}

function emettreAvoir() {
    const client_id = document.getElementById('clientSelect').value;
    const facture_ref = document.getElementById('factureSelect').value;
    const boutique_id = document.getElementById('boutiqueSelect').value;
    if (!client_id) { afficherAlerte('Veuillez sélectionner un client.', 'danger'); return; }

    const lignes = [];
    document.querySelectorAll('.ligne-retour').forEach(div => {
        const code = div.querySelector('.ligne-produit').value;
        const qte = parseInt(div.querySelector('.ligne-qte').value) || 0;
        const prix = parseFloat(div.querySelector('.ligne-prix').value) || 0;
        if (code && qte > 0) lignes.push({ code, qte, prix });
    });

    const modeLibre = document.getElementById('modeLibre').checked;
    const montant = modeLibre ? (parseFloat(document.getElementById('montantAvoir').value) || 0) : 0;
    const motif = modeLibre ? document.getElementById('motifAvoir').value : '';

    if (lignes.length === 0 && !modeLibre) {
        afficherAlerte('Ajoutez au moins un article retourné, ou cochez "Avoir financier libre".', 'danger');
        return;
    }
    if (lignes.length > 0 && !boutique_id) {
        afficherAlerte('Choisissez la boutique qui réceptionne le retour.', 'danger');
        return;
    }

    $.post(window.location.href, {
        action: 'creer_avoir', ajax: 1, csrf_token: CSRF_TOKEN,
        client_id, facture_ref, boutique_id,
        lignes: JSON.stringify(lignes), montant, motif
    }, function(res) {
        if (res.success) {
            afficherAlerte(res.message, 'success');
            setTimeout(() => location.reload(), 900);
        } else {
            afficherAlerte(res.message, 'danger');
        }
    }, 'json');
}

function voirDetails(numero) {
    $.post(window.location.href, { action: 'get_details', ajax: 1, csrf_token: CSRF_TOKEN, numero }, function(res) {
        if (!res.success) { afficherAlerte('Erreur de chargement.', 'danger'); return; }
        if (res.lignes.length === 0) {
            document.getElementById('detailsBody').innerHTML = '<p class="text-muted mb-0">Avoir financier libre — aucun article retourné, aucun impact sur le stock.</p>';
        } else {
            let html = '<table class="table table-sm"><thead><tr><th>Produit</th><th class="text-center">Qté retournée</th><th class="text-end">P.U.</th><th class="text-end">Montant</th></tr></thead><tbody>';
            res.lignes.forEach(l => {
                html += `<tr><td>${l.titre_produit || 'N/A'}</td><td class="text-center">${l.quantite_commande}</td><td class="text-end">${Number(l.prix_commande).toLocaleString('fr-FR')} F</td><td class="text-end fw-bold">${Number(l.montant_commande).toLocaleString('fr-FR')} F</td></tr>`;
            });
            html += '</tbody></table><p class="text-muted small mb-0"><i class="bi bi-check-circle"></i> Ces quantités ont été réintégrées au stock de la boutique de réception.</p>';
            document.getElementById('detailsBody').innerHTML = html;
        }
        ouvrirModal('detailsModal');
    }, 'json');
}
</script>
</body>
</html>
