<?php
// ============================================================
// 1. SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 2. DÉTERMINER SI C'EST UNE REQUÊTE AJAX
// ============================================================
$isAjax = isset($_GET['action']) && $_GET['action'] === 'get_produits' && isset($_GET['boutique_id']);

// ============================================================
// 3. AUTHENTIFICATION
// ============================================================
if (!$isAjax) {
    // Page normale
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../utilisateur/login');
        exit;
    }
} else {
    // Requête AJAX : vérifier l'authentification
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Non authentifié']);
        exit;
    }
}

// ============================================================
// 4. INCLUSION DE LA BASE DE DONNÉES (UNE SEULE FOIS)
// ============================================================
$rootPath = realpath(__DIR__ . '/../..');
$dbFile = $rootPath . '/databases/database.php';
if (!file_exists($dbFile)) {
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Fichier database.php introuvable']);
        exit;
    } else {
        die("Erreur : fichier database.php introuvable dans $dbFile");
    }
}

// Inclusion avec nettoyage des buffers
ob_start();
require_once $dbFile;
ob_end_clean();

// ============================================================
// 5. FONCTIONS UTILITAIRES
// ============================================================
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}

// ============================================================
// 6. TRAITEMENT AJAX (RENVOI DES PRODUITS)
// ============================================================
if ($isAjax) {
    // Nettoyage de tous les buffers existants
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Désactiver l'affichage des erreurs pour garantir du JSON pur
    ini_set('display_errors', '0');
    error_reporting(0);

    header('Content-Type: application/json; charset=utf-8');

    try {
        $boutiqueId = trim($_GET['boutique_id']);
        $search = trim($_GET['search'] ?? '');

        $sql = "SELECT sb.produit_id, p.titre_produit, sb.quantite, sb.quantite_reservee, sb.stock_alerte, sb.maj_le,
                sb.lot_produit_id, lp.titre_lot, lp.unites_par_lot, sb.quantite_lot
                FROM stock_boutique sb
                LEFT JOIN produit p ON sb.produit_id = p.code_produit
                LEFT JOIN lot_produit lp ON sb.lot_produit_id = lp.code_lot_produit
                WHERE sb.boutique_id = ?";

        $params = [$boutiqueId];
        if ($search !== '') {
            $sql .= " AND (p.titre_produit LIKE ? OR sb.produit_id LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= " ORDER BY p.titre_produit";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Nettoyage final avant envoi
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo json_encode($produits, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erreur serveur: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit; // Arrêt total
}

// ============================================================
// 7. VÉRIFICATION DE L'UTILISATEUR POUR LA PAGE NORMALE
// ============================================================
$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

// ============================================================
// 8. RÉCUPÉRATION DES BOUTIQUES POUR L'AFFICHAGE
// ============================================================
$search_boutique = $_GET['search_boutique'] ?? '';
$sqlBoutiques = "SELECT code_boutique, nom_boutique, adresse_boutique, telephone_boutique, email_boutique, ville_boutique, pays_boutique 
                 FROM boutique 
                 WHERE etat_boutique = 'Actif'";

if (!empty($search_boutique)) {
    $sqlBoutiques .= " AND (nom_boutique LIKE :search OR code_boutique LIKE :search OR adresse_boutique LIKE :search)";
    $paramsBoutiques = ['search' => '%' . $search_boutique . '%'];
    $stmtBoutiques = $pdo->prepare($sqlBoutiques);
    $stmtBoutiques->execute($paramsBoutiques);
} else {
    $stmtBoutiques = $pdo->query($sqlBoutiques);
}
$boutiques = $stmtBoutiques->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stock par boutique</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
/* [Styles inchangés] */
:root { --b: #2563eb; --bd: #1d4ed8; --bl: #eff6ff; --bb: #bfdbfe; --bg: #f1f5f9; --w: #fff; --dk: #0f172a; --mt: #64748b; --lt: #94a3b8; --brd: #e2e8f0; --dng: #ef4444; --suc: #10b981; --wrn: #f59e0b; --R: 16px; --Rs: 10px; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', -apple-system, sans-serif; background: var(--bg); color: var(--dk); min-height: 100vh; line-height: 1.5; padding: 28px 20px; }
::-webkit-scrollbar { width: 5px; } ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.W { max-width: 1400px; margin: 0 auto; }
.hdr { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.hdr-l h1 { font-size: 26px; font-weight: 800; color: var(--dk); letter-spacing: -0.02em; }
.hdr-l p { font-size: 13px; color: var(--mt); margin-top: 2px; font-weight: 500; }
.hdr-badge { background: var(--bl); border: 1px solid var(--bb); color: var(--b); padding: 8px 14px; border-radius: var(--Rs); font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
.pbar { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 16px 20px; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.prow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.prow label { font-size: 11px; font-weight: 600; color: var(--mt); letter-spacing: .03em; text-transform: uppercase; }
.prow input, .prow select { padding: 7px 10px; border: 1.5px solid var(--brd); border-radius: 8px; font-size: 13px; font-weight: 500; color: var(--dk); background: var(--bg); font-family: 'Inter', sans-serif; transition: all .2s; }
.prow input:focus, .prow select:focus { border-color: var(--b); background: #fff; box-shadow: 0 0 0 3px var(--bl); outline: none; }
.btn-go { background: var(--b); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 5px; box-shadow: 0 2px 4px rgba(37,99,235,.2); transition: background .15s; border: none; cursor: pointer; }
.btn-go:hover { background: var(--bd); }
.btn-go-outline { background: transparent; color: var(--mt); border: 1.5px solid var(--brd); padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; transition: all .2s; cursor: pointer; text-decoration: none; }
.btn-go-outline:hover { background: var(--bg); border-color: var(--lt); }
.card-boutique { background: var(--w); border: 1px solid var(--brd); border-radius: var(--R); padding: 20px; transition: all .25s; box-shadow: 0 1px 3px rgba(0,0,0,.04); height: 100%; }
.card-boutique:hover { transform: translateY(-4px); box-shadow: 0 8px 25px rgba(0,0,0,.08); }
.card-boutique .btn-outline-primary { color: var(--b); border-color: var(--b); }
.card-boutique .btn-outline-primary:hover { background: var(--b); color: #fff; }
.modal-content { border-radius: var(--R); border: none; box-shadow: 0 12px 40px rgba(15,23,42,.12); }
.modal-header { border-bottom: 1px solid var(--brd); background: var(--bg); }
.modal-footer { border-top: 1px solid var(--brd); background: var(--bg); }
.table th { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--lt); background: var(--bg); border-bottom: 1px solid var(--brd); white-space: nowrap; }
.table td { vertical-align: middle; font-size: 0.82rem; }
.badge-lot { background: var(--bl); color: var(--b); padding: 2px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 600; }
.badge-alert { background: #fef3c7; color: #b45309; padding: 2px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 600; }
@keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.card-boutique { animation: fadeUp .4s ease both; }
.search-inline { display: flex; align-items: center; background: var(--bg); border: 1.5px solid var(--brd); border-radius: var(--Rs); padding: 0 12px; height: 42px; transition: all .2s; min-width: 200px; }
.search-inline:focus-within { border-color: var(--b); background: var(--w); box-shadow: 0 0 0 4px var(--bl); }
.search-inline i { color: var(--lt); font-size: 0.8rem; }
.search-inline input { background: none; border: none; outline: none; color: var(--dk); font-size: 0.85rem; font-family: inherit; width: 100%; margin-left: 8px; }
.search-inline input::placeholder { color: var(--lt); }
.text-muted { color: var(--mt); }
@media (max-width:700px) { body { padding: 14px; } .hdr { flex-direction: column; align-items: flex-start; } .prow { flex-direction: column; align-items: stretch; } .prow .btn-go { width: 100%; justify-content: center; } .table th, .table td { font-size: 0.65rem; padding: 4px 6px; } }
</style>
</head>
<body>
<div class="W">
    <div class="hdr">
        <div class="hdr-l">
            <h1>Stock par boutique</h1>
            <p>Sélectionnez une boutique pour voir ses produits en stock</p>
        </div>
        <div class="hdr-r">
            <div class="hdr-badge"><i class="bi bi-box-seam"></i> <?= count($boutiques) ?> boutique(s)</div>
        </div>
    </div>

    <div class="pbar">
        <form method="GET" id="searchForm" class="prow">
            <label for="searchBoutique"><i class="bi bi-search"></i> Recherche</label>
            <input type="text" name="search_boutique" id="searchBoutique" placeholder="Nom, code, adresse..." value="<?= e($search_boutique) ?>" style="flex:1; min-width:150px;">
            <button type="submit" class="btn-go"><i class="bi bi-funnel"></i> Filtrer</button>
            <a href="?" class="btn-go-outline"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</a>
        </form>
    </div>

    <div class="row g-4">
    <?php if (empty($boutiques)): ?>
        <div class="col-12">
            <div class="alert alert-info text-center py-5">
                <i class="bi bi-inbox d-block mb-2" style="font-size:2rem;"></i>
                Aucune boutique trouvée
            </div>
        </div>
    <?php else: foreach ($boutiques as $boutique): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card-boutique h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="fw-bold mb-1"><?= e($boutique['nom_boutique']) ?></h5>
                        <p class="text-muted small mb-1"><i class="bi bi-tag"></i> <?= e($boutique['code_boutique']) ?></p>
                        <?php if (!empty($boutique['adresse_boutique'])): ?>
                            <p class="text-muted small mb-1"><i class="bi bi-geo-alt"></i> <?= e($boutique['adresse_boutique']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($boutique['telephone_boutique'])): ?>
                            <p class="text-muted small mb-1"><i class="bi bi-telephone"></i> <?= e($boutique['telephone_boutique']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($boutique['email_boutique'])): ?>
                            <p class="text-muted small"><i class="bi bi-envelope"></i> <?= e($boutique['email_boutique']) ?></p>
                        <?php endif; ?>
                    </div>
                    <button class="btn btn-outline-primary btn-sm voir-stock" data-boutique="<?= e($boutique['code_boutique']) ?>" data-nom="<?= e($boutique['nom_boutique']) ?>">
                        <i class="bi bi-eye"></i> Voir
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; endif; ?>
    </div>
</div>

<!-- Modal pour les produits -->
<div class="modal fade" id="produitsModal" tabindex="-1" aria-labelledby="produitsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="produitsModalLabel"><i class="bi bi-box-seam text-primary me-2"></i> Stock de <span id="modalBoutiqueNom"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="search-inline" style="min-width:100%; height:42px;">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchProduitModal" class="form-control border-0" placeholder="Rechercher un produit..." style="background:transparent; box-shadow:none;">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="produitsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Produit</th>
                                <th>Réf.</th>
                                <th class="text-end">Qté (base)</th>
                                <th class="text-end">Réservée</th>
                                <th class="text-end">Disponible</th>
                                <th>Lot</th>
                                <th class="text-end">Qté lots</th>
                                <th class="text-end">Alerte</th>
                                <th>Dernière MAJ</th>
                            </tr>
                        </thead>
                        <tbody id="produitsTableBody">
                            <tr><td colspan="9" class="text-center py-4 text-muted">Chargement...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    let boutiqueId = '';

    // Instance unique du modal
    const modalEl = document.getElementById('produitsModal');
    const bsModal = new bootstrap.Modal(modalEl, { backdrop: true, keyboard: true });

    modalEl.addEventListener('hidden.bs.modal', function () {
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    });

    $('.voir-stock').on('click', function() {
        boutiqueId = $(this).data('boutique');
        const nomBoutique = $(this).data('nom');

        $('#modalBoutiqueNom').text(nomBoutique);
        $('#searchProduitModal').val('');
        $('#produitsTableBody').html('<tr><td colspan="9" class="text-center py-4 text-muted">Chargement...</td></tr>');

        bsModal.show();
        chargerProduits(boutiqueId, '');
    });

    function chargerProduits(bId, search) {
        // Utilisation de l'URL de la page pour l'appel AJAX
        var url = window.location.pathname + '?action=get_produits&boutique_id=' + encodeURIComponent(bId) + (search ? '&search=' + encodeURIComponent(search) : '');

        $('#produitsTableBody').html('<tr><td colspan="9" class="text-center py-4 text-muted">Chargement...</td></tr>');

        $.ajax({
            url: url,
            method: 'GET',
            dataType: 'text',
            timeout: 15000,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function(rawResponse) {
                try {
                    var data = JSON.parse(rawResponse);
                    if (data && data.error) {
                        $('#produitsTableBody').html('<tr><td colspan="9" class="text-center py-4 text-danger">Erreur : ' + e(data.error) + '</td></tr>');
                        return;
                    }
                    afficherProduits(data);
                } catch (parseErr) {
                    console.error('❌ Réponse non-JSON reçue :', rawResponse);
                    $('#produitsTableBody').html('<tr><td colspan="9" class="text-center py-4 text-danger">Le serveur a renvoyé du HTML. Ouvrez la console (F12) pour voir la réponse brute.</td></tr>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Erreur AJAX :', status, error);
                console.log('URL appelée :', url);
                console.log('Réponse brute :', xhr.responseText);

                var msg = 'Erreur de chargement (code ' + xhr.status + '). ';
                if (xhr.responseText) {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.error) msg += resp.error;
                    } catch(e) {
                        msg += 'La réponse n\'est pas un JSON valide. Voir console pour le contenu brut.';
                    }
                } else {
                    msg += 'Aucune réponse du serveur.';
                }
                $('#produitsTableBody').html('<tr><td colspan="9" class="text-center py-4 text-danger">' + e(msg) + '</td></tr>');
            }
        });
    }

    function afficherProduits(data) {
        let html = '';
        if (!data || data.length === 0) {
            html = '<tr><td colspan="9" class="text-center py-4 text-muted">Aucun produit trouvé</td></tr>';
        } else {
            data.forEach(function(item) {
                const disponible = (item.quantite || 0) - (item.quantite_reservee || 0);
                const alerte = ((item.quantite || 0) <= (item.stock_alerte || 0)) ? '<span class="badge-alert ms-1"><i class="bi bi-exclamation-triangle"></i> Alerte</span>' : '';
                const lot = item.lot_produit_id ? '<span class="badge-lot">' + e(item.titre_lot || item.lot_produit_id) + '</span>' : '—';
                const qtLot = item.lot_produit_id ? fmt(item.quantite_lot) : '—';
                const maj = item.maj_le ? new Date(item.maj_le).toLocaleString('fr-FR') : '—';

                html += `<tr>
                    <td><strong>${e(item.titre_produit || item.produit_id)}</strong></td>
                    <td>${e(item.produit_id)}</td>
                    <td class="text-end">${fmt(item.quantite)}</td>
                    <td class="text-end">${fmt(item.quantite_reservee || 0)}</td>
                    <td class="text-end"><strong class="${disponible < 0 ? 'text-danger' : ''}">${fmt(disponible)}</strong></td>
                    <td>${lot}</td>
                    <td class="text-end">${qtLot}</td>
                    <td class="text-end">${alerte}</td>
                    <td>${maj}</td>
                </tr>`;
            });
        }
        $('#produitsTableBody').html(html);
    }

    function e(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fmt(n) {
        return Number(n).toLocaleString('fr-FR');
    }

    // Recherche dans le modal avec debounce
    let timer;
    $('#searchProduitModal').on('input', function() {
        clearTimeout(timer);
        const search = $(this).val();
        timer = setTimeout(function() {
            if (boutiqueId) {
                chargerProduits(boutiqueId, search);
            }
        }, 300);
    });
});
</script>
</body>
</html>