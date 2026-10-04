<?php
// views/depense/index.php – Dépenses de caisse (sans fournisseur, motif obligatoire)
// Chaque dépense = 1 ligne `depense` + 1 transaction de caisse de type 'Sortie'
// (la caisse, la journée de caisse et le rapport financier continuent donc de
// fonctionner sans changement). Voir databases/migration_depense.sql.
require 'databases/database.php';
require_once 'config/upload_validation.php';

if (!function_exists('e')) {
    function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt')) {
    function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }
}

$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id'] ?? '']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$boutiquesAutorisees = getBoutiquesAutorisees($pdo, $user['role'], $user['boutique_id']);
$peutAnnuler   = in_array($user['role'], ['Administrateur', 'Superviseur'], true);

// Catégories = valeurs de l'ENUM depense.categorie (aucune table de catégories).
$categoriesEnum = [];
$colCat = $pdo->query("SHOW COLUMNS FROM depense LIKE 'categorie'")->fetch(PDO::FETCH_ASSOC);
if ($colCat && preg_match_all("/'((?:[^']|'')*)'/", $colCat['Type'], $mm)) {
    $categoriesEnum = array_map(fn($x) => str_replace("''", "'", $x), $mm[1]);
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$action = $_POST['action'] ?? '';

// ============================================================
// AJAX : affichage de la pièce justificative (image)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'piece') {
    while (ob_get_level()) ob_end_clean();
    if (($_POST['csrf_token'] ?? '') !== $csrf_token) { http_response_code(403); exit; }
    $stmtP = $pdo->prepare("SELECT piece_justificative, type_piece, boutique_id FROM depense WHERE code_depense = ?");
    $stmtP->execute([trim($_POST['code'] ?? '')]);
    $p = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$p || $p['piece_justificative'] === null
        || (!empty($p['boutique_id']) && !in_array($p['boutique_id'], $boutiquesAutorisees, true))) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . ($p['type_piece'] ?: 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    echo $p['piece_justificative'];
    exit;
}

// ============================================================
// AJAX : annulation d'une dépense (motif d'annulation obligatoire)
// Remet le montant dans la caisse et marque la transaction 'Annulee'.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'annuler') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (($_POST['csrf_token'] ?? '') !== $csrf_token) {
        echo json_encode(['success' => false, 'message' => 'Token de sécurité invalide.']); exit;
    }
    if (!$peutAnnuler) {
        echo json_encode(['success' => false, 'message' => "Seul un administrateur ou un superviseur peut annuler une dépense."]); exit;
    }
    $code  = trim($_POST['code'] ?? '');
    $motifAnnulation = trim($_POST['motif'] ?? '');
    if ($code === '') { echo json_encode(['success' => false, 'message' => 'Dépense manquante.']); exit; }
    if ($motifAnnulation === '') {
        echo json_encode(['success' => false, 'message' => "Le motif d'annulation est obligatoire."]); exit;
    }
    try {
        $pdo->beginTransaction();
        $stmtD = $pdo->prepare("SELECT d.code_depense, d.boutique_id, d.montant, t.numero_transaction, t.etat_transaction, t.caisse_id, t.montant_transaction
                                FROM depense d
                                JOIN transaction t ON t.numero_transaction = d.numero_transaction
                                WHERE d.code_depense = ? FOR UPDATE");
        $stmtD->execute([$code]);
        $d = $stmtD->fetch(PDO::FETCH_ASSOC);
        if (!$d) throw new Exception("Dépense introuvable.");
        if (!empty($d['boutique_id']) && !in_array($d['boutique_id'], $boutiquesAutorisees, true)) {
            throw new Exception("Cette dépense appartient à une autre boutique.");
        }
        if ($d['etat_transaction'] !== 'Succes') {
            throw new Exception("Seule une dépense enregistrée avec succès peut être annulée (état actuel : " . $d['etat_transaction'] . ").");
        }
        $montant = floatval($d['montant_transaction']);

        $stmtC = $pdo->prepare("SELECT solde FROM caisse WHERE caisse_id = ? FOR UPDATE");
        $stmtC->execute([$d['caisse_id']]);
        $soldeCaisse = $stmtC->fetchColumn();
        if ($soldeCaisse !== false) {
            $pdo->prepare("UPDATE caisse SET solde = ? WHERE caisse_id = ?")
                ->execute([floatval($soldeCaisse) + $montant, $d['caisse_id']]);
        }

        $pdo->prepare("UPDATE transaction SET etat_transaction = 'Annulee', annule_par = ?, date_annulation = NOW(), motif_annulation = ? WHERE numero_transaction = ?")
            ->execute([$user['nom_prenom'], $motifAnnulation, $d['numero_transaction']]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Dépense annulée : ' . fmt($montant) . ' F remis en caisse.']);
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
    }
    exit;
}

$message = '';
$messageType = '';

// ============================================================
// ENREGISTREMENT D'UNE DÉPENSE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'enregistrer') {
    if (($_POST['csrf_token'] ?? '') !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'danger';
    } else {
        $montant      = floatval(str_replace(',', '.', $_POST['montant'] ?? 0));
        $categorie    = trim($_POST['categorie'] ?? '');
        $motif        = trim($_POST['motif'] ?? '');
        $mode_post    = trim($_POST['mode_reglement'] ?? 'Espece');
        $reference    = trim($_POST['reference_reglement'] ?? '');
        $date_depense = trim($_POST['date_depense'] ?? date('Y-m-d'));

        $modeMap = ['Espece' => 'Espèce', 'Mobile Money' => 'Mobile money', 'Cheque' => 'Chèque', 'Virement' => 'Virement'];
        $mode_reglement = $modeMap[$mode_post] ?? 'Espèce';

        try {
            if ($montant <= 0) throw new Exception("Le montant doit être supérieur à 0.");
            if ($motif === '') throw new Exception("Le motif de la dépense est obligatoire.");
            if (mb_strlen($motif) > 255) throw new Exception("Le motif est trop long (255 caractères maximum).");
            $dt = DateTime::createFromFormat('Y-m-d', $date_depense);
            if (!$dt || $dt->format('Y-m-d') !== $date_depense) throw new Exception("Date invalide.");

            if (!in_array($categorie, $categoriesEnum, true)) throw new Exception("Veuillez choisir une catégorie de dépense valide.");
            $libelleCat = $categorie;

            // Boutique : celle de l'utilisateur, sinon (admin/superviseur) celle choisie.
            $boutique_id_cible = $user['boutique_id'];
            if (empty($boutique_id_cible)) {
                $boutique_id_cible = trim($_POST['boutique_id'] ?? '');
                if (empty($boutique_id_cible)) throw new Exception("Veuillez sélectionner la boutique concernée par cette dépense.");
            }
            if (!in_array($boutique_id_cible, $boutiquesAutorisees, true)) {
                throw new Exception("Vous n'avez pas accès à cette boutique.");
            }

            // Pièce justificative (facultative) : validée AVANT d'ouvrir la transaction SQL.
            $piece = null; $typePiece = null;
            if (isset($_FILES['piece']) && ($_FILES['piece']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $v = validerImageUploadee($_FILES['piece']);
                if (!$v['ok']) throw new Exception("Pièce justificative : " . $v['erreur']);
                $piece = $v['contenu'];
                $typePiece = $v['type_mime'];
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM caisse WHERE statut = 'Actif' AND (boutique_id = ? OR boutique_id IS NULL) ORDER BY boutique_id IS NULL LIMIT 1 FOR UPDATE");
            $stmt->execute([$boutique_id_cible]);
            $caisse = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$caisse) throw new Exception("Aucune caisse active pour cette boutique.");

            $stmtJC = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'OUVERTE'");
            $stmtJC->execute([$caisse['caisse_id']]);
            if ($stmtJC->fetchColumn() == 0) throw new Exception("Aucune journée de caisse n'est ouverte : impossible d'enregistrer cette dépense.");
            if ($montant > floatval($caisse['solde'])) throw new Exception("Solde de caisse insuffisant (" . fmt($caisse['solde']) . " F disponibles).");

            $soldeApres = floatval($caisse['solde']) - $montant;
            $numTrans   = 'TR-' . date('YmdHis') . rand(100, 999);
            $codeDepense = 'DEP' . date('YmdHis') . rand(100, 999);
            // objet_transaction (varchar 100) : lisible dans « Transactions » et les rapports.
            $objet = mb_substr('Dépense — ' . $libelleCat . ' : ' . $motif, 0, 100);

            $pdo->prepare("INSERT INTO transaction
                    (numero_transaction, date_transaction, heure_transaction, montant_transaction,
                     frais_transaction, montant_total, type_transaction, objet_transaction,
                     caisse_id, facture_id, contact_id, mode_reglement, numero_reglement, reference_reglement,
                     utilisateur_id, etat_transaction)
                    VALUES (?, ?, CURTIME(), ?, 0, ?, 'Sortie', ?, ?, NULL, NULL, ?, '', ?, ?, 'Succes')")
                ->execute([$numTrans, $date_depense, $montant, $montant, $objet,
                           $caisse['caisse_id'], $mode_reglement, $reference, $user['id']]);

            $stmtMaj = $pdo->prepare("UPDATE caisse SET solde = ? WHERE caisse_id = ? AND statut = 'Actif'");
            $stmtMaj->execute([$soldeApres, $caisse['caisse_id']]);
            if ($stmtMaj->rowCount() === 0) throw new Exception("La mise à jour du solde de la caisse n'a affecté aucune ligne (caisse devenue inactive entre-temps ?).");

            $stmtIns = $pdo->prepare("INSERT INTO depense
                    (code_depense, numero_transaction, categorie, motif, montant, date_depense, boutique_id, utilisateur_id, piece_justificative, type_piece)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->bindValue(1, $codeDepense);
            $stmtIns->bindValue(2, $numTrans);
            $stmtIns->bindValue(3, $categorie);
            $stmtIns->bindValue(4, $motif);
            $stmtIns->bindValue(5, $montant);
            $stmtIns->bindValue(6, $date_depense);
            $stmtIns->bindValue(7, $boutique_id_cible);
            $stmtIns->bindValue(8, $user['id']);
            $stmtIns->bindValue(9, $piece, $piece === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $stmtIns->bindValue(10, $typePiece);
            $stmtIns->execute();

            $pdo->commit();
            $message = "Dépense de " . fmt($montant) . " F enregistrée (" . $libelleCat . ").";
            $messageType = 'success';
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "Erreur : " . $ex->getMessage();
            $messageType = 'danger';
        }
    }
}

// ============================================================
// DONNÉES D'AFFICHAGE
// ============================================================
$boutiquesChoix = [];
if (empty($user['boutique_id']) && !empty($boutiquesAutorisees)) {
    $in = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $stmtB = $pdo->prepare("SELECT code_boutique, nom_boutique FROM boutique WHERE code_boutique IN ($in) ORDER BY nom_boutique");
    $stmtB->execute($boutiquesAutorisees);
    $boutiquesChoix = $stmtB->fetchAll(PDO::FETCH_ASSOC);
}

// Filtres de la liste (POST uniquement ; par défaut : le mois en cours)
$filtrer = ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'filtrer');
$fDebut = $filtrer ? ($_POST['date_debut'] ?? '') : date('Y-m-01');
$fFin   = $filtrer ? ($_POST['date_fin'] ?? '') : date('Y-m-d');
$fCat   = $filtrer ? trim($_POST['categorie'] ?? '') : '';
foreach (['fDebut', 'fFin'] as $v) {
    $dtv = DateTime::createFromFormat('Y-m-d', $$v);
    if (!$dtv || $dtv->format('Y-m-d') !== $$v) $$v = ($v === 'fDebut') ? date('Y-m-01') : date('Y-m-d');
}
if ($fCat !== '' && !in_array($fCat, $categoriesEnum, true)) $fCat = '';

$depenses = [];
$totalValide = 0;
if (!empty($boutiquesAutorisees)) {
    $in = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $sql = "SELECT d.code_depense, d.motif, d.montant, d.date_depense, d.categorie,
                   (d.piece_justificative IS NOT NULL) AS a_piece,
                   t.heure_transaction, t.mode_reglement, t.etat_transaction,
                   t.motif_annulation, t.annule_par, u.nom_prenom AS utilisateur_nom, b.nom_boutique
            FROM depense d
            JOIN transaction t ON t.numero_transaction = d.numero_transaction
            LEFT JOIN utilisateur u ON u.id = d.utilisateur_id
            LEFT JOIN boutique b ON b.code_boutique = d.boutique_id
            WHERE d.boutique_id IN ($in) AND d.date_depense BETWEEN ? AND ?";
    $params = array_merge($boutiquesAutorisees, [$fDebut, $fFin]);
    if ($fCat !== '') { $sql .= " AND d.categorie = ?"; $params[] = $fCat; }
    $sql .= " ORDER BY d.date_depense DESC, t.heure_transaction DESC LIMIT 300";
    $stmtL = $pdo->prepare($sql);
    $stmtL->execute($params);
    $depenses = $stmtL->fetchAll(PDO::FETCH_ASSOC);
    foreach ($depenses as $d) {
        if ($d['etat_transaction'] === 'Succes') $totalValide += floatval($d['montant']);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dépenses</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --b:#2563eb; --bd:#1d4ed8; --bl:#eff6ff; --bb:#bfdbfe; --bg:#f1f5f9; --w:#fff; --dk:#0f172a; --mt:#64748b; --lt:#94a3b8; --brd:#e2e8f0; --dng:#ef4444; --dngl:#fef2f2; --suc:#10b981; --sucl:#ecfdf5; --R:16px; --Rs:10px; }
        * { box-sizing: border-box; }
        body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--dk); min-height:100vh; padding:28px 20px; margin:0; }
        .W { max-width:1200px; margin:0 auto; }
        .hdr { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
        .hdr h1 { font-size:26px; font-weight:800; font-family:'Outfit',sans-serif; letter-spacing:-.02em; margin:0; }
        .hdr p { font-size:13px; color:var(--mt); margin:2px 0 0; font-weight:500; }
        .hdr-badge { background:var(--dngl); border:1px solid #fecaca; color:var(--dng); padding:8px 14px; border-radius:var(--Rs); font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px; }
        .card-d { background:var(--w); border:1px solid var(--brd); border-radius:var(--R); padding:24px; box-shadow:0 1px 3px rgba(0,0,0,.04); margin-bottom:20px; }
        .form-label { font-size:11px; font-weight:600; color:var(--mt); text-transform:uppercase; letter-spacing:.03em; }
        .form-control, .form-select { padding:9px 12px; border:1.5px solid var(--brd); border-radius:8px; font-size:13px; background:var(--bg); }
        .form-control:focus, .form-select:focus { border-color:var(--b); background:#fff; box-shadow:0 0 0 3px var(--bl); }
        .req { color:var(--dng); }
        .help-text { font-size:10px; color:var(--lt); margin-top:4px; font-style:italic; }
        .btn-valider { background:var(--dng); color:#fff; padding:10px 20px; border-radius:8px; font-size:13px; font-weight:700; border:none; display:inline-flex; align-items:center; gap:6px; }
        .btn-valider:hover:not(:disabled) { background:#dc2626; }
        .btn-valider:disabled { opacity:.5; cursor:not-allowed; }
        .stat { background:var(--bl); border:1px solid var(--bb); border-radius:var(--Rs); padding:10px 16px; font-size:13px; font-weight:700; color:var(--bd); }
        table.dt { width:100%; font-size:12.5px; }
        table.dt th { font-size:10.5px; text-transform:uppercase; letter-spacing:.04em; color:var(--mt); border-bottom:2px solid var(--brd); padding:8px; white-space:nowrap; }
        table.dt td { padding:8px; border-bottom:1px solid var(--brd); vertical-align:top; }
        tr.annulee td { opacity:.55; }
        tr.annulee .montant { text-decoration:line-through; }
        .badge-etat { font-size:10px; font-weight:700; padding:3px 8px; border-radius:20px; }
        .badge-ok { background:var(--sucl); color:var(--suc); } .badge-ko { background:var(--dngl); color:var(--dng); }
        @media (max-width:700px) { body { padding:14px; } }
    </style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div>
            <h1><i class="bi bi-cash-stack text-danger me-2"></i>Dépenses</h1>
            <p>Sorties de caisse — le motif est obligatoire</p>
        </div>
        <div class="hdr-badge"><i class="bi bi-wallet2"></i> Décaissement</div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show">
        <?= e($message) ?>
        <button class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ===================== FORMULAIRE ===================== -->
    <div class="card-d">
        <form method="post" enctype="multipart/form-data" id="formDepense">
            <input type="hidden" name="action" value="enregistrer">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Date <span class="req">*</span></label>
                    <input type="date" class="form-control" name="date_depense" value="<?= date('Y-m-d') ?>" required>
                </div>
                <?php if (!empty($boutiquesChoix)): ?>
                <div class="col-md-3">
                    <label class="form-label">Boutique <span class="req">*</span></label>
                    <select class="form-select" name="boutique_id" required>
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($boutiquesChoix as $b): ?>
                            <option value="<?= e($b['code_boutique']) ?>"><?= e($b['nom_boutique']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label">Catégorie <span class="req">*</span></label>
                    <select class="form-select" name="categorie" id="categorieId" required>
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($categoriesEnum as $c): ?>
                            <option value="<?= e($c) ?>"><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Montant (F) <span class="req">*</span></label>
                    <input type="number" step="0.01" min="0" class="form-control" name="montant" id="montant" value="" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Motif de la dépense <span class="req">*</span></label>
                    <input type="text" class="form-control" name="motif" id="motif" maxlength="255" placeholder="Ex : Loyer de septembre, achat de carburant génératrice..." required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Mode de règlement</label>
                    <select class="form-select" name="mode_reglement">
                        <option value="Espece">Espèce</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Cheque">Chèque</option>
                        <option value="Virement">Virement</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Référence <span style="font-weight:400;text-transform:none;">(facultatif)</span></label>
                    <input type="text" class="form-control" name="reference_reglement" maxlength="200" placeholder="N° chèque, réf. mobile money...">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Pièce justificative <span style="font-weight:400;text-transform:none;">(facultatif)</span></label>
                    <input type="file" class="form-control" name="piece" id="piece" accept="image/jpeg,image/png,image/gif,image/webp">
                    <div class="help-text">Photo du reçu ou de la facture (JPEG, PNG, GIF ou WEBP — 4 Mo maximum).</div>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn-valider" id="btnValider" disabled><i class="bi bi-check-circle"></i> Enregistrer la dépense</button>
            </div>
        </form>
    </div>

    <!-- ===================== LISTE ===================== -->
    <div class="card-d">
        <form method="post" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="action" value="filtrer">
            <div class="col-auto"><label class="form-label">Du</label><input type="date" class="form-control" name="date_debut" value="<?= e($fDebut) ?>"></div>
            <div class="col-auto"><label class="form-label">Au</label><input type="date" class="form-control" name="date_fin" value="<?= e($fFin) ?>"></div>
            <div class="col-auto">
                <label class="form-label">Catégorie</label>
                <select class="form-select" name="categorie">
                    <option value="">Toutes</option>
                    <?php foreach ($categoriesEnum as $c): ?>
                        <option value="<?= e($c) ?>" <?= $fCat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Filtrer</button></div>
            <div class="col ms-auto text-end"><span class="stat">Total (hors annulées) : <?= fmt($totalValide) ?> F</span></div>
        </form>

        <div class="table-responsive">
        <table class="dt">
            <thead><tr><th>Date</th><th>Catégorie</th><th>Motif</th><th class="text-end">Montant</th><th>Boutique</th><th>Mode</th><th>Saisi par</th><th>État</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($depenses)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Aucune dépense sur cette période.</td></tr>
            <?php else: foreach ($depenses as $d):
                $annulee = ($d['etat_transaction'] === 'Annulee'); ?>
                <tr class="<?= $annulee ? 'annulee' : '' ?>">
                    <td><?= e(date('d/m/Y', strtotime($d['date_depense']))) ?><small class="d-block text-muted"><?= e(substr($d['heure_transaction'] ?? '', 0, 5)) ?></small></td>
                    <td><?= e($d['categorie'] ?? '—') ?></td>
                    <td style="max-width:320px;"><?= e($d['motif']) ?>
                        <?php if ($annulee): ?><small class="d-block text-danger">Annulée par <?= e($d['annule_par']) ?> — <?= e($d['motif_annulation']) ?></small><?php endif; ?>
                    </td>
                    <td class="text-end fw-bold montant"><?= fmt($d['montant']) ?> F</td>
                    <td><?= e($d['nom_boutique'] ?? '—') ?></td>
                    <td><?= e($d['mode_reglement']) ?></td>
                    <td><?= e($d['utilisateur_nom'] ?? '—') ?></td>
                    <td><span class="badge-etat <?= $annulee ? 'badge-ko' : 'badge-ok' ?>"><?= $annulee ? 'Annulée' : 'Validée' ?></span></td>
                    <td class="text-nowrap">
                        <?php if ($d['a_piece']): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="voirPiece('<?= e($d['code_depense']) ?>')" title="Voir le justificatif"><i class="bi bi-image"></i></button>
                        <?php endif; ?>
                        <?php if ($peutAnnuler && !$annulee): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="ouvrirAnnulation('<?= e($d['code_depense']) ?>', '<?= e(fmt($d['montant'])) ?>')" title="Annuler"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <?php if (count($depenses) >= 300): ?><div class="help-text mt-2">Seules les 300 dépenses les plus récentes sont affichées : affinez la période.</div><?php endif; ?>
    </div>

</div>

<!-- Modal annulation -->
<div class="modal fade" id="modalAnnulation" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h6 class="modal-title">Annuler la dépense de <span id="annMontant"></span> F</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label">Motif d'annulation <span class="req">*</span></label>
            <textarea class="form-control" id="annMotif" rows="2" placeholder="Ex : montant erroné, doublon..."></textarea>
            <div class="help-text">Le montant sera remis dans la caisse.</div>
            <div class="text-danger small mt-2 d-none" id="annErreur"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Fermer</button>
            <button class="btn btn-danger btn-sm" id="btnConfirmerAnnulation">Confirmer l'annulation</button>
        </div>
    </div></div>
</div>

<!-- Modal justificatif -->
<div class="modal fade" id="modalPiece" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h6 class="modal-title">Pièce justificative</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center"><img id="imgPiece" alt="Pièce justificative" style="max-width:100%;"></div>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($csrf_token) ?>;
const PAGE_URL = window.location.href;

// Bouton actif seulement si catégorie + motif + montant sont renseignés
const btn = document.getElementById('btnValider');
function verifier() {
    const ok = document.getElementById('categorieId').value
        && document.getElementById('motif').value.trim() !== ''
        && parseFloat(document.getElementById('montant').value) > 0;
    btn.disabled = !ok;
}
['categorieId', 'motif', 'montant'].forEach(id => document.getElementById(id).addEventListener('input', verifier));
document.getElementById('categorieId').addEventListener('change', verifier);

// Taille de la pièce (le serveur revérifie de toute façon)
document.getElementById('piece').addEventListener('change', function () {
    if (this.files[0] && this.files[0].size > 4 * 1024 * 1024) {
        alert('Le fichier dépasse 4 Mo.');
        this.value = '';
    }
});

async function postAjax(params) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    Object.entries(params).forEach(([k, v]) => fd.append(k, v));
    return fetch(PAGE_URL, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
}

async function voirPiece(code) {
    const r = await postAjax({ action: 'piece', code });
    if (!r.ok) { alert('Justificatif introuvable.'); return; }
    const blob = await r.blob();
    document.getElementById('imgPiece').src = URL.createObjectURL(blob);
    new bootstrap.Modal(document.getElementById('modalPiece')).show();
}

let codeAnnulation = null;
function ouvrirAnnulation(code, montant) {
    codeAnnulation = code;
    document.getElementById('annMontant').textContent = montant;
    document.getElementById('annMotif').value = '';
    document.getElementById('annErreur').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('modalAnnulation')).show();
}
document.getElementById('btnConfirmerAnnulation').addEventListener('click', async function () {
    const motif = document.getElementById('annMotif').value.trim();
    const err = document.getElementById('annErreur');
    if (!motif) { err.textContent = "Le motif d'annulation est obligatoire."; err.classList.remove('d-none'); return; }
    this.disabled = true;
    try {
        const r = await postAjax({ action: 'annuler', code: codeAnnulation, motif });
        const j = await r.json();
        if (j.success) { window.location.reload(); }
        else { err.textContent = j.message; err.classList.remove('d-none'); }
    } catch (e) {
        err.textContent = 'Erreur de communication avec le serveur.'; err.classList.remove('d-none');
    }
    this.disabled = false;
});

setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => setTimeout(() => new bootstrap.Alert(a).close(), 5000));
}, 100);
</script>
</body>
</html>
