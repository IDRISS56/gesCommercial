<?php
require __DIR__ . '/../../databases/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

$stmt = $pdo->prepare("SELECT id, nom_prenom, role, boutique_id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function fmt($n) { return number_format(floatval($n), 0, ',', ' '); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$message = '';
$messageType = '';

// ============================================================
// ENREGISTREMENT D'UN RÈGLEMENT OU DÉCAISSEMENT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regler') {
    if (($_POST['csrf_token'] ?? '') !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'danger';
    } else {
        $numero_facture = trim($_POST['numero_facture'] ?? '');
        $fournisseur_id = trim($_POST['fournisseur_id'] ?? '');
        $montant = floatval(str_replace(',', '.', $_POST['montant'] ?? 0));
        $mode_reglement = trim($_POST['mode_reglement'] ?? 'Espece');
        $numero_reglement = trim($_POST['numero_reglement'] ?? '');
        $reference_reglement = trim($_POST['reference_reglement'] ?? '');
        $date_reglement = trim($_POST['date_reglement'] ?? date('Y-m-d'));

        $modeMap = [
            'Espece' => 'Espèce',
            'Mobile Money' => 'Mobile money',
            'Cheque' => 'Chèque',
            'Virement' => 'Virement',
            'Carte' => 'Carte',
            'Autres' => 'Autres'
        ];
        $mode_reglement_mapped = $modeMap[$mode_reglement] ?? 'Espèce';

        try {
            if ($montant <= 0) throw new Exception("Le montant doit être supérieur à 0.");

            $pdo->beginTransaction();

            // ============================================================
            // CAS 1 : DÉCAISSEMENT DIRECT (DÉPENSE) SANS FACTURE
            // ============================================================
            if (empty($numero_facture)) {
                if (empty($fournisseur_id)) {
                    throw new Exception("Veuillez sélectionner un fournisseur ou une facture.");
                }

                $stmt = $pdo->prepare("SELECT * FROM caisse WHERE statut = 'Actif' AND (boutique_id = ? OR boutique_id IS NULL) ORDER BY boutique_id IS NULL LIMIT 1 FOR UPDATE");
                $stmt->execute([$user['boutique_id']]);
                $caisse = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$caisse) throw new Exception("Aucune caisse active.");

                $stmtJC = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'OUVERTE'");
                $stmtJC->execute([$caisse['caisse_id']]);
                if ($stmtJC->fetchColumn() == 0) throw new Exception("Aucune journée de caisse n'est ouverte : impossible d'enregistrer ce décaissement.");
                if ($montant > floatval($caisse['solde'])) throw new Exception("Solde de caisse insuffisant (" . fmt($caisse['solde']) . " F disponibles).");

                $soldeAvant = floatval($caisse['solde']);
                $soldeApres = $soldeAvant - $montant;
                $numTrans = 'TR-' . date('YmdHis') . rand(100, 999);

                $stmtTr = $pdo->prepare("INSERT INTO transaction
                    (numero_transaction, date_transaction, heure_transaction, montant_transaction,
                     frais_transaction, montant_total, type_transaction, objet_transaction,
                     caisse_id, facture_id, mode_reglement, numero_reglement, reference_reglement,
                     utilisateur_id, etat_transaction)
                    VALUES (?, ?, CURTIME(), ?, 0, ?, 'Sortie', 'Décaissement fournisseur (dépense)',
                            ?, NULL, ?, ?, ?, ?, 'Succes')");
                $stmtTr->execute([
                    $numTrans, $date_reglement, $montant, $montant,
                    $caisse['caisse_id'], $mode_reglement_mapped,
                    $numero_reglement, $reference_reglement, $user['id']
                ]);

                $stmtMaj = $pdo->prepare("UPDATE caisse SET solde = ? WHERE caisse_id = ? AND statut = 'Actif'");
                $stmtMaj->execute([$soldeApres, $caisse['caisse_id']]);
                if ($stmtMaj->rowCount() === 0) throw new Exception("La mise à jour du solde de la caisse n'a affecté aucune ligne (caisse devenue inactive entre-temps ?).");

                $pdo->commit();
                $message = "Décaissement de " . fmt($montant) . " F enregistré comme dépense.";
                $messageType = 'success';
            }
            // ============================================================
            // CAS 2 : RÈGLEMENT D'UNE FACTURE FOURNISSEUR
            // ============================================================
            else {
                $stmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? AND type_facture = 'Fournisseur' FOR UPDATE");
                $stmt->execute([$numero_facture]);
                $facture = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$facture) throw new Exception("Facture introuvable.");
                if ($facture['statut_facture'] !== 'Validee') throw new Exception("Seule une facture validée peut recevoir un règlement.");
                if ($montant > floatval($facture['reste'])) throw new Exception("Le montant dépasse le reste à payer (" . fmt($facture['reste']) . " F).");

                $stmt = $pdo->prepare("SELECT * FROM caisse WHERE statut = 'Actif' AND (boutique_id = ? OR boutique_id IS NULL) ORDER BY boutique_id IS NULL LIMIT 1 FOR UPDATE");
                $stmt->execute([$user['boutique_id']]);
                $caisse = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$caisse) throw new Exception("Aucune caisse active.");

                $stmtJC = $pdo->prepare("SELECT COUNT(*) FROM journees_caisse WHERE caisse_id = ? AND statut = 'OUVERTE'");
                $stmtJC->execute([$caisse['caisse_id']]);
                if ($stmtJC->fetchColumn() == 0) throw new Exception("Aucune journée de caisse n'est ouverte : impossible d'enregistrer ce règlement.");
                if ($montant > floatval($caisse['solde'])) throw new Exception("Solde de caisse insuffisant (" . fmt($caisse['solde']) . " F disponibles).");

                $soldeAvant = floatval($caisse['solde']);
                $soldeApres = $soldeAvant - $montant;
                $numTrans = 'TR-' . date('YmdHis') . rand(100, 999);

                $stmtTr = $pdo->prepare("INSERT INTO transaction
                    (numero_transaction, date_transaction, heure_transaction, montant_transaction,
                     frais_transaction, montant_total, type_transaction, objet_transaction,
                     caisse_id, facture_id, mode_reglement, numero_reglement, reference_reglement,
                     utilisateur_id, etat_transaction)
                    VALUES (?, ?, CURTIME(), ?, 0, ?, 'Sortie', 'Règlement facture fournisseur',
                            ?, ?, ?, ?, ?, ?, 'Succes')");
                $stmtTr->execute([
                    $numTrans, $date_reglement, $montant, $montant,
                    $caisse['caisse_id'], $numero_facture, $mode_reglement_mapped,
                    $numero_reglement, $reference_reglement, $user['id']
                ]);

                $stmtMaj = $pdo->prepare("UPDATE caisse SET solde = ? WHERE caisse_id = ? AND statut = 'Actif'");
                $stmtMaj->execute([$soldeApres, $caisse['caisse_id']]);
                if ($stmtMaj->rowCount() === 0) throw new Exception("La mise à jour du solde de la caisse n'a affecté aucune ligne (caisse devenue inactive entre-temps ?).");

                $nouvelleAvance = floatval($facture['avance']) + $montant;
                $nouveauReste = round(floatval($facture['montant_ttc']) - $nouvelleAvance, 2);
                if ($nouveauReste < 0) $nouveauReste = 0;
                $nouvelEtat = ($nouveauReste <= 0) ? 'Payee' : (($nouvelleAvance > 0) ? 'Partielle' : 'Impayee');

                $pdo->prepare("UPDATE facture SET avance = ?, reste = ?, etat_facture = ? WHERE numero_facture = ?")
                    ->execute([$nouvelleAvance, $nouveauReste, $nouvelEtat, $numero_facture]);

                $pdo->commit();
                $message = "Règlement de " . fmt($montant) . " F enregistré avec succès.";
                $messageType = 'success';
            }
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "Erreur : " . $ex->getMessage();
            $messageType = 'danger';
        }
    }
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES
// ============================================================
$stmt = $pdo->prepare("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Fournisseur' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact");
$stmt->execute();
$fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ REQUÊTE CORRIGÉE : uniquement factures VALIDÉES + IMPAYÉES/PARTIELLES
$stmt = $pdo->prepare("SELECT f.numero_facture, f.date_facture, f.montant_ttc, f.avance, f.reste, f.etat_facture, f.statut_facture, f.contact_id
                       FROM facture f
                       WHERE f.type_facture = 'Fournisseur'
                         AND f.categorie_facture <> 'Avoir'
                         AND f.statut_facture = 'Validee'
                         AND f.etat_facture IN ('Impayee','Partielle')
                       ORDER BY f.date_facture DESC");
$stmt->execute();
$factures_fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$factures_json = json_encode($factures_fournisseurs);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Règlement / Décaissement Fournisseur</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --b: #2563eb; --bd: #1d4ed8; --bl: #eff6ff; --bb: #bfdbfe;
            --bg: #f1f5f9; --w: #fff; --dk: #0f172a; --mt: #64748b;
            --lt: #94a3b8; --brd: #e2e8f0; --dng: #ef4444; --dngl: #fef2f2;
            --suc: #10b981; --sucl: #ecfdf5; --sucb: #a7f3d0;
            --wrn: #f59e0b; --wrnl: #fffbeb; --wrnb: #fde68a;
            --R: 16px; --Rs: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--dk); min-height: 100vh; padding: 28px 20px; }
        .W { max-width: 1100px; margin: 0 auto; }
        .hdr { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--dk); letter-spacing: -0.02em; font-family: 'Outfit', sans-serif; }
        .hdr-l p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
        .hdr-badge { background: var(--bl); border: 1px solid var(--bb); color: var(--b); padding: 8px 14px; border-radius: var(--Rs); font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .card-reglement { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 26px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .pbar { background: var(--bg); border: 1px solid var(--brd); border-radius: var(--Rs); padding: 12px 16px; margin-bottom: 16px; }
        .prow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .prow label { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .03em; min-width: 80px; }
        .form-label { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .03em; }
        .form-control, .form-select { padding: 9px 12px; border: 1.5px solid var(--brd); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--dk); font-family: 'Inter', sans-serif; }
        .form-control:focus, .form-select:focus { border-color: var(--b); background: #fff; box-shadow: 0 0 0 3px var(--bl); outline: none; }
        .champ-lecture { background: var(--bg) !important; font-weight: 700; color: var(--dk); }
        .montant-du { color: var(--b) !important; }
        .reste-a-payer { color: var(--dng) !important; }
        .btn-valider { background: var(--b); color: #fff; padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 700; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-valider:hover:not(:disabled) { background: var(--bd); }
        .btn-valider:disabled { opacity: .5; cursor: not-allowed; }
        .btn-annuler { background: transparent; color: var(--mt); border: 1.5px solid var(--brd); padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-annuler:hover { background: var(--bg); }
        .bootstrap-select .dropdown-toggle { background: var(--bg) !important; border: 1.5px solid var(--brd) !important; border-radius: 8px !important; font-size: 13px !important; }
        .bootstrap-select .dropdown-toggle:focus { border-color: var(--b) !important; box-shadow: 0 0 0 3px var(--bl) !important; }
        .type-selector { display: flex; gap: 10px; margin-bottom: 20px; }
        .type-btn { flex: 1; padding: 14px; border: 2px solid var(--brd); background: var(--w); border-radius: 10px; cursor: pointer; text-align: center; transition: all .2s; }
        .type-btn i { font-size: 24px; display: block; margin-bottom: 6px; }
        .type-btn span { font-size: 12px; font-weight: 700; }
        .type-btn.active { border-color: var(--b); background: var(--bl); color: var(--b); }
        .type-btn.active-facture { border-color: var(--suc); background: var(--sucl); color: var(--suc); }
        .type-btn.active-depense { border-color: var(--dng); background: var(--dngl); color: var(--dng); }
        .info-box { background: var(--wrnl); border: 1px solid var(--wrnb); color: #92400e; padding: 10px 14px; border-radius: 8px; font-size: 12px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .help-text { font-size: 10px; color: var(--lt); margin-top: 4px; font-style: italic; }
        @media (max-width:700px) { body { padding: 14px; } .hdr { flex-direction: column; align-items: flex-start; } .prow { flex-direction: column; align-items: stretch; } }
    </style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div class="hdr-l">
            <h1><i class="bi bi-truck text-danger me-2"></i>Règlement / Décaissement — Fournisseur</h1>
            <p>Décaissement des paiements fournisseurs ou dépenses directes</p>
        </div>
        <div class="hdr-badge"><i class="bi bi-wallet2"></i> Décaissement</div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show">
        <?= e($message) ?>
        <button class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card-reglement">
        <form method="post" id="formReglement">
            <input type="hidden" name="action" value="regler">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="numero_facture" id="numeroFacture" value="">
            <input type="hidden" name="fournisseur_id" id="fournisseurId" value="">

            <div class="type-selector">
                <div class="type-btn active-facture" id="typeFactureBtn" onclick="setType('facture')">
                    <i class="bi bi-receipt"></i>
                    <span>Règlement de facture</span>
                </div>
                <div class="type-btn" id="typeDepenseBtn" onclick="setType('depense')">
                    <i class="bi bi-cash-stack"></i>
                    <span>Dépense directe (sans facture)</span>
                </div>
            </div>

            <div class="info-box" id="infoBox">
                <i class="bi bi-info-circle-fill"></i>
                <span id="infoText">Sélectionnez un fournisseur puis une facture à régler.</span>
            </div>

            <div class="pbar">
                <div class="prow">
                    <label><i class="bi bi-calendar"></i> Date</label>
                    <input type="date" name="date_reglement" value="<?= date('Y-m-d') ?>" required style="flex:1; border:none;">
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Fournisseur</label>
                    <select class="form-select selectpicker" id="selectFournisseur" data-live-search="true" data-live-search-placeholder="Rechercher un fournisseur..." required>
                        <option value="">-- Sélectionner un fournisseur --</option>
                        <?php foreach ($fournisseurs as $f): ?>
                            <option value="<?= e($f['code_contact']) ?>" data-nom="<?= e($f['nom_prenom_contact']) ?>"><?= e($f['nom_prenom_contact']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6" id="factureCol">
                    <label class="form-label">Facture</label>
                    <select class="form-select selectpicker" id="selectFacture" data-live-search="true" data-live-search-placeholder="Rechercher une facture..." disabled>
                        <option value="">-- Sélectionner d'abord un fournisseur --</option>
                    </select>
                </div>
            </div>

            <hr style="border-color: var(--brd); margin: 20px 0;">

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Montant dû</label>
                    <input type="text" class="form-control champ-lecture montant-du" id="montantDu" value="0 F" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Montant versé</label>
                    <input type="number" step="0.01" class="form-control champ-lecture" id="montantVerse" name="montant" value="0" min="0" required>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Reste à payer</label>
                    <input type="text" class="form-control champ-lecture reste-a-payer" id="resteAPayer" value="0 F" readonly>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">N° de règlement</label>
                    <input type="text" class="form-control" name="numero_reglement" id="numeroReglement" placeholder="Ex: 0012345, MM-2026-XYZ...">
                    <div class="help-text">Numéro du document de paiement (n° de chèque, référence transaction mobile money, n° de virement...)</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Référence</label>
                    <input type="text" class="form-control" name="reference_reglement" id="referenceReglement" placeholder="Ex: Réf. chèque BDCI, N° bordereau virement...">
                    <div class="help-text">Référence du document (banque émettrice du chèque, référence interne, libellé...)</div>
                </div>
            </div>

            <div class="pbar mt-4">
                <div class="prow">
                    <label><i class="bi bi-wallet"></i> Mode de règlement</label>
                    <select class="form-select selectpicker" name="mode_reglement" required>
                        <option value="Espece">ESPÈCE</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Cheque">Chèque</option>
                        <option value="Virement">Virement</option>
                    </select>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <button type="button" class="btn-annuler" onclick="resetForm()"><i class="bi bi-x-circle"></i> Annuler</button>
                <button type="submit" class="btn-valider" id="btnValider" disabled>
                    <i class="bi bi-check-circle"></i> Valider
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
const facturesData = <?= $factures_json ?>;
let currentType = 'facture';

$(document).ready(function() {
    $('.selectpicker').selectpicker();

    const selectFournisseur = document.getElementById('selectFournisseur');
    const selectFacture = document.getElementById('selectFacture');
    const montantDuEl = document.getElementById('montantDu');
    const montantVerseEl = document.getElementById('montantVerse');
    const resteAPayerEl = document.getElementById('resteAPayer');
    const btnValider = document.getElementById('btnValider');
    let montantDuValue = 0;

    $('#selectFournisseur').on('changed.bs.select', function() {
        const fourCode = this.value;
        document.getElementById('fournisseurId').value = fourCode;
        selectFacture.innerHTML = '<option value="">-- Sélectionner une facture --</option>';
        montantDuValue = 0;
        updateCalculs();

        if (!fourCode) {
            $('#selectFacture').selectpicker('refresh');
            $('#selectFacture').prop('disabled', true);
            return;
        }

        if (currentType === 'facture') {
            const facturesFour = facturesData.filter(f => f.contact_id === fourCode);
            if (facturesFour.length === 0) {
                selectFacture.innerHTML = '<option value="">Aucune facture validée impayée</option>';
                $('#selectFacture').selectpicker('refresh');
                $('#selectFacture').prop('disabled', true);
                return;
            }
            facturesFour.forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.numero_facture;
                opt.textContent = `${f.numero_facture} — ${fmt2(f.montant_ttc)} F (Reste: ${fmt2(f.reste)} F) — ${f.etat_facture}`;
                opt.dataset.reste = f.reste;
                opt.dataset.montant = f.montant_ttc;
                selectFacture.appendChild(opt);
            });
            $('#selectFacture').prop('disabled', false);
            $('#selectFacture').selectpicker('refresh');
        }
    });

    $('#selectFacture').on('changed.bs.select', function() {
        const reste = parseFloat(this.selectedOptions[0]?.dataset.reste || 0);
        montantDuValue = reste;
        document.getElementById('numeroFacture').value = this.value;
        montantDuEl.value = fmt2(montantDuValue) + ' F';
        montantVerseEl.value = montantDuValue;
        updateCalculs();
    });

    $('#montantVerse').on('input', updateCalculs);

    function updateCalculs() {
        const verse = parseFloat(montantVerseEl.value) || 0;
        let reste = montantDuValue - verse;
        if (reste < 0) reste = 0;
        resteAPayerEl.value = fmt2(reste) + ' F';

        if (currentType === 'facture') {
            btnValider.disabled = !(selectFacture.value && verse > 0);
        } else {
            btnValider.disabled = !(selectFournisseur.value && verse > 0);
        }
    }

    window.setType = function(type) {
        currentType = type;
        const btnFacture = document.getElementById('typeFactureBtn');
        const btnDepense = document.getElementById('typeDepenseBtn');
        const factureCol = document.getElementById('factureCol');
        const infoText = document.getElementById('infoText');

        btnFacture.className = 'type-btn' + (type === 'facture' ? ' active-facture' : '');
        btnDepense.className = 'type-btn' + (type === 'depense' ? ' active-depense' : '');

        if (type === 'facture') {
            factureCol.style.display = '';
            infoText.textContent = 'Seules les factures VALIDÉES avec état IMPAYÉE ou PARTIELLE sont affichées.';
            document.getElementById('numeroFacture').value = '';
            montantDuValue = 0;
            montantDuEl.value = '0 F';
        } else {
            factureCol.style.display = 'none';
            selectFacture.innerHTML = '<option value="">-- Mode dépense directe --</option>';
            $('#selectFacture').selectpicker('refresh');
            $('#selectFacture').prop('disabled', true);
            infoText.textContent = 'Dépense directe : sélectionnez un fournisseur et saisissez le montant. Aucune facture ne sera impactée.';
            document.getElementById('numeroFacture').value = '';
            montantDuValue = 0;
            montantDuEl.value = 'Dépense directe';
        }
        updateCalculs();
    };

    window.resetForm = function() {
        document.getElementById('formReglement').reset();
        selectFacture.innerHTML = '<option value="">-- Sélectionner d\'abord un fournisseur --</option>';
        $('#selectFacture').selectpicker('refresh');
        $('#selectFacture').prop('disabled', true);
        $('#selectFournisseur').selectpicker('val', '');
        document.getElementById('numeroFacture').value = '';
        document.getElementById('fournisseurId').value = '';
        montantDuValue = 0;
        updateCalculs();
        setType('facture');
    };
});

function fmt2(n) {
    return Number(n).toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        const bs = new bootstrap.Alert(a);
        setTimeout(() => bs.close(), 5000);
    });
}, 100);
</script>
</body>
</html>