<?php
ob_start(); // Capture toute sortie parasite (BOM, espaces, etc.)

// Fonction utilitaire pour envoyer une réponse JSON propre
function sendJson($data)
{
    // Supprimer tous les buffers de sortie actifs
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ---- TRAITEMENT AJAX (AVANT TOUTE SORTIE) ----
if (isset($_GET['action']) && $_GET['action'] === 'get_produits' && isset($_GET['boutique_id'])) {
    session_start();
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        sendJson(['error' => 'Non authentifié']);
        exit;
    }
    // Inclusion de la base de données
    $dbFile = __DIR__ . '/../../databases/database.php';
    if (!file_exists($dbFile)) {
        http_response_code(500);
        sendJson(['error' => 'Fichier database.php introuvable']);
        exit;
    }
    require_once $dbFile;

    $stmt = $pdo->prepare("SELECT id FROM utilisateur WHERE id = ? AND etat = 'Actif'");
    $stmt->execute([$_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        session_destroy();
        http_response_code(401);
        sendJson(['error' => 'Utilisateur invalide']);
        exit;
    }

    $boutiqueId = $_GET['boutique_id'];
    $search = $_GET['search'] ?? '';

    $sql = "
        SELECT 
            sb.produit_id,
            p.titre_produit,
            sb.quantite,
            sb.stock_alerte,
            sb.maj_le,
            sb.lot_produit_id,
            lp.titre_lot,
            lp.unites_par_lot,
            sb.quantite_lot
        FROM stock_boutique sb
        LEFT JOIN produit p ON sb.produit_id = p.code_produit
        LEFT JOIN lot_produit lp ON sb.lot_produit_id = lp.code_lot_produit
        WHERE sb.boutique_id = ?
    ";
    $params = [$boutiqueId];
    if (!empty($search)) {
        $sql .= " AND (p.titre_produit LIKE ? OR sb.produit_id LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY p.titre_produit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendJson($produits);
    exit;
}

// ---- AFFICHAGE NORMAL ----
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../utilisateur/login');
    exit;
}

$dbFile = __DIR__ . '/../../databases/database.php';
if (!file_exists($dbFile)) {
    die("Erreur : fichier database.php introuvable");
}
require_once $dbFile;

$stmt = $pdo->prepare("SELECT id, nom_prenom, role FROM utilisateur WHERE id = ? AND etat = 'Actif'");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../utilisateur/login');
    exit;
}

// ---- FONCTIONS (UNE SEULE FOIS) ----
function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt($n)
{
    return number_format(floatval($n), 0, ',', ' ');
}

// ---- RECHERCHE DE BOUTIQUES ----
$search_boutique = $_GET['search_boutique'] ?? '';
$sqlBoutiques = "SELECT code_boutique, nom_boutique, adresse_boutique, telephone_boutique, email_boutique FROM boutique WHERE etat_boutique = 'Actif'";
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
        :root {
            --color-primary: #4f46e5;
            --color-primary-dark: #3730a3;
            --color-primary-soft: #eef2ff;
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
            --radius-sm: 10px;
            --radius-md: 14px;
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

        .card-boutique {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px;
            transition: all var(--transition-base);
            box-shadow: var(--shadow-sm);
        }

        .card-boutique:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }

        .btn-outline-primary {
            color: var(--color-primary);
            border-color: var(--color-primary);
        }

        .btn-outline-primary:hover {
            background: var(--color-primary);
            color: #fff;
        }

        .search-inline {
            display: flex;
            align-items: center;
            background: var(--bg-muted);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0 12px;
            height: 42px;
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

        .search-inline input {
            background: none;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: inherit;
            width: 100%;
            margin-left: 8px;
        }

        .badge-lot {
            background: var(--color-primary-soft);
            color: var(--color-primary);
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-alert {
            background: #fef3c7;
            color: #b45309;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .modal-content {
            border-radius: var(--radius-md);
            border: none;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-muted);
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
            background: var(--bg-muted);
        }

        .page-heading h2 {
            font-weight: 800;
        }

        .text-tertiary {
            color: var(--text-tertiary);
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-boutique {
            animation: fadeUp .4s ease both;
        }

        .table th {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-quaternary);
        }

        @media (max-width:768px) {
            body {
                padding: 10px;
            }

            .card-boutique {
                padding: 15px;
            }
        }
    </style>
</head>

<body>
    <div class="container-crud">
        <!-- En-tête -->
        <div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-3">
            <div class="page-heading">
                <h2 class="fw-800 mb-0">Stock par boutique</h2>
                <p class="text-tertiary mt-1">Sélectionnez une boutique pour voir ses produits en stock</p>
            </div>
        </div>

        <!-- Barre de recherche boutique -->
        <div class="bg-light p-3 rounded-3 mb-4 border">
            <form method="GET" id="searchForm" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label for="searchBoutique" class="form-label fw-semibold small">Rechercher une boutique</label>
                    <div class="search-inline" style="min-width:100%; height:42px;">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search_boutique" id="searchBoutique" placeholder="Nom, code, adresse..." value="<?= e($search_boutique) ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filtrer</button>
                </div>
                <div class="col-md-2">
                    <a href="?" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</a>
                </div>
            </form>
        </div>

        <!-- Liste des boutiques -->
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
            <?php endforeach;
            endif; ?>
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
                    <!-- Barre de recherche produits dans le modal -->
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
                                    <th>Lot</th>
                                    <th class="text-end">Qté lots</th>
                                    <th class="text-end">Facteur</th>
                                    <th>Dernière MAJ</th>
                                </tr>
                            </thead>
                            <tbody id="produitsTableBody">
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">Chargement...</td>
                                </tr>
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
            let produitsData = [];
            let boutiqueId = '';

            // Lors du clic sur "Voir"
            $('.voir-stock').on('click', function() {
                boutiqueId = $(this).data('boutique');
                const nomBoutique = $(this).data('nom');
                $('#modalBoutiqueNom').text(nomBoutique);
                $('#searchProduitModal').val('');
                $('#produitsTableBody').html('<tr><td colspan="7" class="text-center py-4 text-muted">Chargement...</td></tr>');
                const modal = new bootstrap.Modal(document.getElementById('produitsModal'));
                modal.show();
                chargerProduits(boutiqueId, '');
            });

            // ---- URL AJAX dynamique (basée sur la page courante) ----
            function chargerProduits(boutiqueId, search) {
                var url = window.location.href.split('?')[0] + '?action=get_produits&boutique_id=' + encodeURIComponent(boutiqueId) + (search ? '&search=' + encodeURIComponent(search) : '');
                console.log('URL appelée :', url);
                $.ajax({
                    url: url,
                    method: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        if (data.error) {
                            $('#produitsTableBody').html('<tr><td colspan="7" class="text-center py-4 text-danger">Erreur : ' + data.error + '</td></tr>');
                            return;
                        }
                        produitsData = data;
                        afficherProduits(data);
                    },
                    error: function(xhr, status, error) {
                        console.error('Erreur AJAX :', status, error);
                        console.log('Réponse brute :', xhr.responseText);
                        $('#produitsTableBody').html('<tr><td colspan="7" class="text-center py-4 text-danger">Erreur de chargement (vérifiez la console)</td></tr>');
                    }
                });
            }

            // Affichage des produits
            function afficherProduits(data) {
                let html = '';
                if (data.length === 0) {
                    html = '<tr><td colspan="7" class="text-center py-4 text-muted">Aucun produit trouvé</td></tr>';
                } else {
                    data.forEach(function(item) {
                        const alerte = (item.quantite <= item.stock_alerte) ? '<span class="badge-alert ms-1"><i class="bi bi-exclamation-triangle"></i> Alerte</span>' : '';
                        const lot = item.lot_produit_id ? '<span class="badge-lot">' + e(item.titre_lot || item.lot_produit_id) + '</span>' : '—';
                        const qtLot = item.lot_produit_id ? fmt(item.quantite_lot) : '—';
                        const facteur = item.lot_produit_id ? (item.unites_par_lot || '—') : '—';
                        const maj = item.maj_le ? new Date(item.maj_le).toLocaleString('fr-FR') : '—';
                        html += `<tr>
                    <td><strong>${e(item.titre_produit || item.produit_id)}</strong></td>
                    <td>${e(item.produit_id)}</td>
                    <td class="text-end"><strong class="${alerte ? 'text-danger' : ''}">${fmt(item.quantite)}</strong> ${alerte}</td>
                    <td>${lot}</td>
                    <td class="text-end">${qtLot}</td>
                    <td class="text-end">${facteur}</td>
                    <td>${maj}</td>
                </tr>`;
                    });
                }
                $('#produitsTableBody').html(html);
            }

            // Fonctions utilitaires JS
            function e(str) {
                if (!str) return '';
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function fmt(n) {
                return Number(n).toLocaleString('fr-FR');
            }

            // Recherche dans le modal
            let timer;
            $('#searchProduitModal').on('input', function() {
                clearTimeout(timer);
                const search = $(this).val();
                timer = setTimeout(function() {
                    if (boutiqueId) {
                        $('#produitsTableBody').html('<tr><td colspan="7" class="text-center py-4 text-muted">Recherche...</td></tr>');
                        chargerProduits(boutiqueId, search);
                    }
                }, 300);
            });
        });
    </script>
</body>

</html>