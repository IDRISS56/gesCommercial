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
// ENREGISTREMENT D'UN RÈGLEMENT CLIENT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regler') {
    if (($_POST['csrf_token'] ?? '') !== $csrf_token) {
        $message = "Token de sécurité invalide.";
        $messageType = 'danger';
    } else {
        $numero_facture = trim($_POST['numero_facture'] ?? '');
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

            $stmt = $pdo->prepare("SELECT * FROM facture WHERE numero_facture = ? AND type_facture = 'Client' FOR UPDATE");
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

            $soldeAvant = floatval($caisse['solde']);
            $soldeApres = $soldeAvant + $montant;
            $numTrans = 'TR-' . date('YmdHis') . rand(100, 999);

            $stmtTr = $pdo->prepare("INSERT INTO transaction
                (numero_transaction, date_transaction, heure_transaction, montant_transaction,
                 frais_transaction, montant_total, type_transaction, objet_transaction,
                 caisse_id, facture_id, mode_reglement, numero_reglement, reference_reglement,
                 utilisateur_id, etat_transaction)
                VALUES (?, ?, CURTIME(), ?, 0, ?, 'Entree', 'Règlement facture client',
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
$stmt = $pdo->prepare("SELECT code_contact, nom_prenom_contact FROM contact WHERE type_contact = 'Client' AND etat_contact = 'Actif' ORDER BY nom_prenom_contact");
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ REQUÊTE CORRIGÉE : uniquement factures VALIDÉES + IMPAYÉES/PARTIELLES
$stmt = $pdo->prepare("SELECT f.numero_facture, f.date_facture, f.montant_ttc, f.avance, f.reste, f.etat_facture, f.statut_facture, c.nom_prenom_contact, c.code_contact
                       FROM facture f
                       LEFT JOIN contact c ON c.code_contact = f.contact_id
                       WHERE f.type_facture = 'Client'
                         AND f.categorie_facture <> 'Avoir'
                         AND f.statut_facture = 'Validee'
                         AND f.etat_facture IN ('Impayee','Partielle')
                       ORDER BY f.date_facture DESC");
$stmt->execute();
$factures_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
$factures_json = json_encode($factures_clients);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Règlement Facture Client</title>
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
        .hdr-badge { background: var(--sucl); border: 1px solid var(--sucb); color: var(--suc); padding: 8px 14px; border-radius: var(--Rs); font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .card-reglement { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 26px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .pbar { background: var(--bg); border: 1px solid var(--brd); border-radius: var(--Rs); padding: 12px 16px; margin-bottom: 16px; }
        .prow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .prow label { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .03em; min-width: 80px; }
        .form-label { font-size: 11px; font-weight: 600; color: var(--mt); text-transform: uppercase; letter-spacing: .03em; }
        .form-control, .form-select { padding: 9px 12px; border: 1.5px solid var(--brd); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--dk); font-family: 'Inter', sans-serif; }
        .form-control:focus, .form-select:focus { border-color: var(--b); background: #fff; box-shadow: 0 0 0 3px var(--bl); outline: none; }
        .champ-lecture { background: var(--bg) !important; font-weight: 700; color: var(--dk); }
        .montant-du { color: var(--suc) !important; }
        .reste-a-payer { color: var(--dng) !important; }
        .btn-valider { background: var(--suc); color: #fff; padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 700; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-valider:hover:not(:disabled) { background: #059669; }
        .btn-valider:disabled { opacity: .5; cursor: not-allowed; }
        .btn-annuler { background: transparent; color: var(--mt); border: 1.5px solid var(--brd); padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-annuler:hover { background: var(--bg); }
        .bootstrap-select .dropdown-toggle { background: var(--bg) !important; border: 1.5px solid var(--brd) !important; border-radius: 8px !important; font-size: 13px !important; }
        .bootstrap-select .dropdown-toggle:focus { border-color: var(--b) !important; box-shadow: 0 0 0 3px var(--bl) !important; }
        .help-text { font-size: 10px; color: var(--lt); margin-top: 4px; font-style: italic; }
        @media (max-width:700px) { body { padding: 14px; } .hdr { flex-direction: column; align-items: flex-start; } .prow { flex-direction: column; align-items: stretch; } }
    </style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div class="hdr-l">
            <h1><i class="bi bi-cash-coin text-success me-2"></i>Règlement — Facture Client</h1>
            <p>Encaissement des paiements clients (factures validées impayées ou partielles)</p>
        </div>
        <div class="hdr-badge"><i class="bi bi-wallet2"></i> Encaissement</div>
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
            <input type="hidden" name="numero_facture" id="numeroFacture">

            <div class="pbar">
                <div class="prow">
                    <label><i class="bi bi-calendar"></i> Date</label>
                    <input type="date" name="date_reglement" value="<?= date('Y-m-d') ?>" required style="flex:1; border:none;">
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Client</label>
                    <select class="form-select selectpicker" id="selectClient" data-live-search="true" data-live-search-placeholder="Rechercher un client..." required>
                        <option value="">-- Sélectionner un client --</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= e($c['code_contact']) ?>" data-nom="<?= e($c['nom_prenom_contact']) ?>"><?= e($c['nom_prenom_contact']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Facture</label>
                    <select class="form-select selectpicker" id="selectFacture" data-live-search="true" data-live-search-placeholder="Rechercher une facture..." required disabled>
                        <option value="">-- Sélectionner d'abord un client --</option>
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
                    <i class="bi bi-check-circle"></i> Valider le règlement
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

$(document).ready(function() {
    $('.selectpicker').selectpicker();

    const selectClient = document.getElementById('selectClient');
    const selectFacture = document.getElementById('selectFacture');
    const montantDuEl = document.getElementById('montantDu');
    const montantVerseEl = document.getElementById('montantVerse');
    const resteAPayerEl = document.getElementById('resteAPayer');
    const btnValider = document.getElementById('btnValider');
    let montantDuValue = 0;

    $('#selectClient').on('changed.bs.select', function() {
        const clientCode = this.value;
        selectFacture.innerHTML = '<option value="">-- Sélectionner une facture --</option>';
        montantDuValue = 0;
        updateCalculs();

        if (!clientCode) {
            $('#selectFacture').selectpicker('refresh');
            $('#selectFacture').prop('disabled', true);
            return;
        }

        const facturesClient = facturesData.filter(f => f.code_contact === clientCode);
        if (facturesClient.length === 0) {
            selectFacture.innerHTML = '<option value="">Aucune facture validée impayée</option>';
            $('#selectFacture').selectpicker('refresh');
            $('#selectFacture').prop('disabled', true);
            return;
        }

        facturesClient.forEach(f => {
            const opt = document.createElement('option');
            opt.value = f.numero_facture;
            opt.textContent = `${f.numero_facture} — ${fmt2(f.montant_ttc)} F (Reste: ${fmt2(f.reste)} F) — ${f.etat_facture}`;
            opt.dataset.reste = f.reste;
            opt.dataset.montant = f.montant_ttc;
            selectFacture.appendChild(opt);
        });

        $('#selectFacture').prop('disabled', false);
        $('#selectFacture').selectpicker('refresh');
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
        btnValider.disabled = !(selectFacture.value && verse > 0);
    }

    window.resetForm = function() {
        document.getElementById('formReglement').reset();
        selectFacture.innerHTML = '<option value="">-- Sélectionner d\'abord un client --</option>';
        $('#selectFacture').selectpicker('refresh');
        $('#selectFacture').prop('disabled', true);
        $('#selectClient').selectpicker('val', '');
        document.getElementById('numeroFacture').value = '';
        montantDuValue = 0;
        updateCalculs();
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