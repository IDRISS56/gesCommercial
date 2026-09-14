<?php
// ==========================================
// 1. CONNEXION À LA BASE DE DONNÉES
// ==========================================
require 'databases/database.php';
require 'librairies/fpdf/fpdf.php';
require_once 'config/csrf.php';

// Boutiques que cet utilisateur a le droit de voir (achats limités à ces
// boutiques pour Vendeur/Caisse/Proprietaire ; toutes pour Administrateur/Superviseur).
$boutiquesAutorisees = getBoutiquesAutorisees($pdo, $_SESSION['role'] ?? null, $_SESSION['boutique_id'] ?? null);

require 'views/commande/suivi_achat_pdf.php';

require 'views/commande/suivi_achat_data.php';

require 'views/commande/suivi_achat_actions.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include "includes/pwa_head.php"; ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi des Achats Fournisseur</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
            --color-info: #0891b2;
            --color-info-soft: #cffafe;
            --color-purple: #8b5cf6;
            --color-purple-soft: #ede9fe;
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
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-tertiary: #64748b;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.08);
            --radius-sm: 10px;
            --radius-md: 14px;
            --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            min-height: 100vh;
            font-size: 14px;
            padding: 24px 20px;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-track { background: transparent; }
        .W { max-width: 1400px; margin: 0 auto; }

        /* ===== CARTES ACHATS (compactes - 4 par ligne) ===== */
        .facture-card {
            background: var(--bg-surface); border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 10px 12px; cursor: pointer;
            transition: all 0.15s ease; display: flex; flex-direction: column;
            justify-content: space-between; min-height: 120px; position: relative;
            animation: fadeUp .4s ease both;
        }
        .facture-card:hover { border-color: var(--color-primary); box-shadow: 0 4px 12px rgba(79, 70, 229, .12); transform: translateY(-2px); }
        .facture-card.validated { border-color: var(--color-success); background: #f0fdf4; }
        .facture-card.validated::after {
            content: '✓'; position: absolute; top: 6px; right: 6px; background: var(--color-success);
            color: #fff; width: 18px; height: 18px; border-radius: 50%; font-size: 10px;
            display: flex; align-items: center; justify-content: center; font-weight: 700;
        }
        .fc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
        .fc-number { font-size: 11px; font-weight: 700; color: var(--color-primary-dark); font-family: 'Outfit', sans-serif; }
        .facture-card.validated .fc-number { color: #059669; }
        .fc-amount { font-size: 14px; font-weight: 800; color: var(--color-primary); font-family: 'Outfit', sans-serif; line-height: 1; }
        .fc-amount small { font-size: 9px; color: var(--text-tertiary); margin-left: 2px; }
        .fc-middle { flex: 1; display: flex; flex-direction: column; gap: 3px; margin: 4px 0; }
        .fc-client { font-size: 11px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fc-client i { color: var(--color-primary); font-size: 11px; flex-shrink: 0; }
        .fc-date { font-size: 9px; color: var(--text-tertiary); display: flex; align-items: center; gap: 3px; }
        .fc-badges { display: flex; gap: 3px; flex-wrap: wrap; margin-top: 2px; }
        .badge-pill { display: inline-flex; align-items: center; gap: 2px; padding: 1px 6px; border-radius: 999px; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .fc-bottom { border-top: 1px dashed var(--border-color); padding-top: 6px; display: flex; justify-content: flex-end; align-items: center; gap: 4px; }

        .icon-btn {
            width: 26px; height: 26px; border-radius: 5px; border: 1.5px solid transparent;
            background: transparent; display: inline-flex; align-items: center; justify-content: center;
            transition: all .2s; font-size: 12px; cursor: pointer; padding: 0; position: relative;
        }
        .icon-btn:hover { transform: scale(1.1); }
        .icon-btn.view { color: var(--color-primary); border-color: rgba(79, 70, 229, 0.2); }
        .icon-btn.view:hover { color: var(--color-primary-dark); background: var(--color-primary-soft); border-color: var(--color-primary); }
        .icon-btn.validate { color: var(--color-success); border-color: rgba(16, 185, 129, 0.2); }
        .icon-btn.validate:hover { color: #059669; background: var(--color-success-soft); border-color: var(--color-success); }
        .icon-btn.validate.validated { color: var(--color-success); background: var(--color-success-soft); border-color: var(--color-success); cursor: default; opacity: 0.7; }
        .icon-btn.validate.validated:hover { transform: none; }
        .icon-btn.delete { color: var(--color-danger); border-color: rgba(239, 68, 68, 0.2); }
        .icon-btn.delete:hover { color: #b91c1c; background: var(--color-danger-soft); border-color: var(--color-danger); }
        .icon-btn::before {
            content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%;
            transform: translateX(-50%); background: var(--color-gray-800); color: #fff;
            padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 600;
            white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity .2s; z-index: 10;
        }
        .icon-btn:hover::before { opacity: 1; }

        .stat-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 14px 16px; transition: var(--transition-base); }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-label { font-size: 10px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); font-family: 'Outfit', sans-serif; line-height: 1; }

        .modal-chic .modal-content { border: none; border-radius: 20px; box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15); overflow: hidden; animation: modalSlideIn .4s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes modalSlideIn { from { opacity: 0; transform: translateY(30px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-chic .modal-header { background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%); color: #fff; border: none; padding: 22px 28px; position: relative; overflow: hidden; }
        .modal-chic .modal-header::before { content: ''; position: absolute; top: -50%; right: -20%; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%; }
        .modal-chic .modal-title { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
        .modal-chic .modal-title i { font-size: 22px; background: rgba(255,255,255,0.15); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px); }
        .modal-chic .btn-close { filter: invert(1); opacity: 0.7; position: relative; z-index: 1; transition: all .2s; }
        .modal-chic .btn-close:hover { opacity: 1; transform: rotate(90deg); }
        .modal-chic .modal-body { padding: 28px; max-height: 70vh; overflow-y: auto; background: #f8fafc; }
        .modal-chic .modal-footer { background: #fff; border-top: 1px solid var(--border-color); padding: 18px 28px; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }

        .detail-section-chic { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 20px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); transition: all .2s; }
        .detail-section-chic:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.06); border-color: #cbd5e1; }
        .detail-section-title-chic { font-size: 11px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.8px; padding-bottom: 10px; border-bottom: 2px solid #f1f5f9; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .detail-section-title-chic.info i { color: #3b82f6; }
        .detail-section-title-chic.money i { color: #10b981; }
        .detail-section-title-chic.box i { color: #f59e0b; }
        .badge-chic { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 2px 4px rgba(0,0,0,0.06); }
        .badge-chic.success { background: #d1fae5; color: #065f46; }
        .badge-chic.warning { background: #fef3c7; color: #92400e; }
        .badge-chic.danger { background: #fee2e2; color: #991b1b; }
        .badge-chic.primary { background: #dbeafe; color: #1e40af; }
        .badge-chic.secondary { background: #f1f5f9; color: #475569; }
        .badge-chic .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        .btn-chic { padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; letter-spacing: -0.01em; }
        .btn-chic::before { content: ''; position: absolute; top: 50%; left: 50%; width: 0; height: 0; background: rgba(255,255,255,0.3); border-radius: 50%; transform: translate(-50%, -50%); transition: width .4s, height .4s; }
        .btn-chic:hover::before { width: 300px; height: 300px; }
        .btn-chic i { font-size: 15px; position: relative; z-index: 1; }
        .btn-chic span { position: relative; z-index: 1; }
        .btn-chic-modifier { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #fff; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); }
        .btn-chic-modifier:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4); }
        .btn-chic-imprimer { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); color: #fff; box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3); }
        .btn-chic-imprimer:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(6, 182, 212, 0.4); }
        .btn-chic-partager { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .btn-chic-partager:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); }
        .btn-chic-fermer { background: linear-gradient(135deg, #64748b 0%, #475569 100%); color: #fff; box-shadow: 0 4px 12px rgba(100, 116, 139, 0.25); }
        .btn-chic-fermer:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(100, 116, 139, 0.35); }

        .modal-table { width: 100%; font-size: 13px; }
        .modal-table thead th { background: var(--color-gray-100); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 10px 12px; }
        .modal-table tbody td { padding: 10px 12px; border-bottom: 1px solid var(--border-color); }
        .modal-table tbody tr:hover { background: var(--color-primary-soft); }

        .edit-section-chic { display: none; background: #fff; border: 1px solid var(--border-color); border-radius: 16px; padding: 28px; margin-top: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); }
        .edit-header-chic { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
        .edit-header-chic h2 { font-size: 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .edit-header-chic h2 i { color: #3b82f6; font-size: 22px; }
        .btn-retour-chic { background: #f1f5f9; color: #475569; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border-color); transition: all .2s; }
        .btn-retour-chic:hover { background: var(--color-gray-200); color: #1e293b; }
        .edit-table-chic { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; border-radius: 10px; overflow: hidden; border: 1px solid var(--border-color); }
        .edit-table-chic thead th { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); color: var(--text-tertiary); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; padding: 12px 14px; border-bottom: 2px solid var(--border-color); }
        .edit-table-chic tbody td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; color: #1e293b; background: #fff; }
        .edit-table-chic tbody tr:hover td { background: #f8fafc; }
        .edit-table-chic tbody tr:last-child td { border-bottom: none; }
        .btn-delete-chic { width: 32px; height: 32px; border-radius: 8px; border: 1px solid #fecaca; background: #fff; color: var(--color-danger); display: inline-flex; align-items: center; justify-content: center; transition: all .2s; }
        .btn-delete-chic:hover { background: #fee2e2; border-color: var(--color-danger); transform: scale(1.1); }
        .btn-action-chic { padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; transition: all .25s; }
        .btn-action-chic.annuler { background: #f1f5f9; color: #475569; border: 1px solid var(--border-color); }
        .btn-action-chic.annuler:hover { background: var(--color-gray-200); }
        .btn-action-chic.enregistrer { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #fff; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        .btn-action-chic.enregistrer:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4); }

        .bootstrap-select .dropdown-toggle { background: #fff !important; border: 1.5px solid var(--border-color) !important; border-radius: 8px !important; min-width: 220px; }
        .bootstrap-select .dropdown-toggle:focus { border-color: var(--color-primary) !important; box-shadow: 0 0 0 3px var(--color-primary-soft) !important; }

        @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        .facture-card.deleting { animation: fadeOut .4s ease forwards; }
        @keyframes fadeOut { to { opacity: 0; transform: scale(0.9); } }
        @media (max-width: 700px) {
            .bootstrap-select, .bootstrap-select .dropdown-toggle { width: 100% !important; min-width: 0 !important; }
        }
    </style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-box-arrow-in-down text-primary me-2"></i>Suivi des Achats Fournisseur</h1>
            <p class="text-muted small mb-0">Suivez tous vos achats fournisseur et l'entrée en stock associée</p>
        </div>
        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
            <i class="bi bi-truck"></i> <?= $totalAchats ?> achat(s)
        </span>
    </div>

    <!-- Statistiques -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['primary', 'truck', 'Total achats', $totalAchats, ''],
            ['success', 'check-circle-fill', 'Réglés', $payees, ''],
            ['warning', 'hourglass-split', 'Partiels', $partielles, ''],
            ['danger', 'x-circle-fill', 'Impayés', $impayees, ''],
            ['purple', 'cash-coin', 'Total TTC', number_format($totalMontant, 0, ',', ' '), ' FCFA'],
            ['info', 'wallet2', 'Reste à devoir', number_format($totalReste, 0, ',', ' '), ' FCFA'],
        ];
        $colorMap = [
            'primary' => ['var(--color-primary-soft)', 'var(--color-primary)'],
            'success' => ['var(--color-success-soft)', 'var(--color-success)'],
            'warning' => ['var(--color-warning-soft)', 'var(--color-warning)'],
            'danger' => ['var(--color-danger-soft)', 'var(--color-danger)'],
            'purple' => ['var(--color-purple-soft)', 'var(--color-purple)'],
            'info' => ['var(--color-info-soft)', 'var(--color-info)'],
        ];
        foreach ($stats as $s):
            $bg = $colorMap[$s[0]][0];
            $fg = $colorMap[$s[0]][1];
        ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="background: <?= $bg ?>; color: <?= $fg ?>;">
                    <i class="bi bi-<?= $s[1] ?>"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-label"><?= $s[2] ?></div>
                    <div class="stat-value text-truncate"><?= $s[3] ?><?php if ($s[4]): ?><small class="text-muted ms-1" style="font-size:11px;"><?= $s[4] ?></small><?php endif; ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filtres -->
    <div class="bg-white border rounded-3 p-3 mb-4 shadow-sm">
        <form id="searchForm" onsubmit="return false;">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <label for="fournisseurFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-truck"></i> Fournisseur</label>
                <select id="fournisseurFilter" class="selectpicker" data-live-search="true" data-live-search-placeholder="Rechercher un fournisseur...">
                    <option value="">Tous les fournisseurs</option>
                    <?php foreach ($fournisseurs as $f): ?>
                        <option value="<?= htmlspecialchars($f['code_contact']) ?>"><?= htmlspecialchars($f['nom_prenom_contact']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="etatFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-cash-coin"></i> État</label>
                <select id="etatFilter" class="selectpicker">
                    <option value="">Tous les états</option>
                    <option value="Impayee">Impayé</option>
                    <option value="Partielle">Partiel</option>
                    <option value="Payee cash">Réglé cash</option>
                    <option value="Payee">Réglé</option>
                </select>
                <label for="statutFilter" class="text-uppercase small fw-bold text-muted mb-0"><i class="bi bi-check2-square"></i> Statut</label>
                <select id="statutFilter" class="selectpicker">
                    <option value="">Tous les statuts</option>
                    <option value="En attente">En attente (Bon)</option>
                    <option value="Validee">Validée (Facture)</option>
                    <option value="Annule">Annulée</option>
                </select>
                <button type="button" class="btn btn-primary fw-bold" id="filterBtn"><i class="bi bi-funnel"></i> Filtrer</button>
                <button type="button" class="btn btn-outline-secondary fw-semibold" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</button>
            </div>
        </form>
    </div>

    <!-- Liste des achats -->
    <div class="row g-3" id="facturesGrid">
        <?php
        // Restriction boutique : un achat n'apparaît que s'il contient au moins
        // une ligne (table commande) dans une boutique autorisée.
        // Chargement initial : seulement la première page (voir getAchatsListe plus haut).
        // Le reste se charge à la demande via l'action AJAX 'liste_achats'.
        $listeAchatsInitiale = getAchatsListe($pdo, $boutiquesAutorisees, [], 1);
        echo $listeAchatsInitiale['html'];
        ?>
    </div>
    <div id="facturesPagination"><?= $listeAchatsInitiale['pagination'] ?></div>
</div>

<!-- Modal détails chic -->
<div class="modal fade modal-chic" id="factureModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt-cutoff"></i><span id="modalTitleText">Détails de l'achat</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="factureDetails">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Chargement...</span></div>
                    <p class="mt-3 text-muted small">Chargement des détails...</p>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($_SESSION['role'] === 'Administrateur' || $_SESSION['role'] === 'Proprietaire' || $_SESSION['role'] === 'Superviseur' || $_SESSION['role'] === 'Caisse'): ?>
                <button class="btn-chic btn-chic-modifier" id="btnModifier"><i class="bi bi-pencil-square"></i><span>Modifier</span></button>
                <?php endif; ?>
                <button class="btn-chic btn-chic-imprimer" id="btnImprimer"><i class="bi bi-printer-fill"></i><span>Imprimer</span></button>
                <button class="btn-chic btn-chic-partager" id="btnPartager" style="position: relative;"><i class="bi bi-whatsapp"></i><span>Partager</span></button>
                <button class="btn-chic btn-chic-fermer" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i><span>Fermer</span></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal confirmation suppression -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Confirmer la suppression</h5>
                <p class="text-muted small mb-4">Êtes-vous sûr de vouloir supprimer l'achat <strong id="deleteFactureId" class="text-danger"></strong> ?<br>Le stock entré sera automatiquement retiré.<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger rounded-3" id="confirmDeleteBtn"><i class="bi bi-trash3 me-1"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal confirmation générique (remplace window.confirm()) -->
<div class="modal fade" id="genericConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-question-circle-fill text-primary" style="font-size: 3rem;"></i></div>
                <h5 class="mb-2 fw-bold">Confirmation</h5>
                <p class="text-muted small mb-4" id="genericConfirmMsg"></p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-primary rounded-3" id="genericConfirmOkBtn">Confirmer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Section édition chic -->
<div id="editSection" class="edit-section-chic">
    <div class="edit-header-chic">
        <h2><i class="bi bi-pencil-square"></i> Modification de l'achat</h2>
        <button class="btn-retour-chic" id="btnRetourListe"><i class="bi bi-arrow-left"></i> Retour</button>
    </div>
    <div id="editContent"></div>
</div>

<!-- Toast -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:2000;">
    <div id="toastMsg" class="toast align-items-center text-white border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-semibold" id="toastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/i18n/defaults-fr_FR.min.js"></script>
<script>
const csrfTokenJs = <?= json_encode(csrfToken()) ?>;
// Injecte automatiquement le jeton CSRF dans chaque requête POST envoyée via $.ajax,
// sans avoir à modifier chacun des appels existants un par un.
$.ajaxPrefilter(function(options) {
    if ((options.type || options.method || '').toUpperCase() === 'POST') {
        if (typeof options.data === 'string') {
            options.data += (options.data ? '&' : '') + 'csrf_token=' + encodeURIComponent(csrfTokenJs);
        } else {
            options.data = options.data || {};
            options.data.csrf_token = csrfTokenJs;
        }
    }
});
$(document).ready(function() {
    $('.selectpicker').selectpicker();
    const toastEl = document.getElementById('toastMsg');
    const toast = new bootstrap.Toast(toastEl, { delay: 2500 });
    const baseUrl = window.location.pathname;
    // Catégories disponibles pour l'ajout de nouvelles lignes lors de la
    // modification d'un achat (mêmes données que entree_stock.php). Les
    // produits, eux, sont chargés à la demande par catégorie via l'action AJAX
    // 'produits_par_categorie' — voir filtrerProduitsNouvelleLigne ci-dessous.
    const CATEGORIES_ACHAT = <?= json_encode($categoriesAchat, JSON_UNESCAPED_UNICODE) ?>;
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    let factureToDelete = null;

    // Remplace window.confirm() par la modal Bootstrap générique
    const genericConfirmModalEl = document.getElementById('genericConfirmModal');
    const genericConfirmModal = new bootstrap.Modal(genericConfirmModalEl);
    function genericConfirm(message, onOk) {
        $('#genericConfirmMsg').text(message);
        const okBtn = document.getElementById('genericConfirmOkBtn');
        const newOkBtn = okBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(newOkBtn, okBtn);
        newOkBtn.addEventListener('click', function() {
            genericConfirmModal.hide();
            onOk();
        });
        genericConfirmModal.show();
    }

    function showToast(msg, type = 'success') {
        const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-primary' };
        const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        $('#toastBody').html(`<i class="bi ${icons[type]} me-2"></i>${msg}`);
        toastEl.className = `toast align-items-center text-white border-0 ${colors[type]}`;
        toast.show();
    }

    // Valider achat
    $(document).on('click', '.valider-facture', function(e) {
        e.stopPropagation();
        const btn = $(this), id = btn.data('id'), card = btn.closest('.facture-card');
        genericConfirm('Confirmer que la marchandise de l\'achat ' + id + ' a été physiquement reçue ? Cette action va créditer le stock.', function() {
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i>');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'validate_facture', id: id },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    showToast('Achat ' + id + ' validé — stock crédité');
                    card.addClass('validated');
                    card.find('.fc-badges .badge-pill').last().removeClass('bg-secondary-subtle text-secondary').addClass('bg-primary-subtle text-primary').html('<i class="bi bi-check2" style="font-size:8px;"></i> VALIDEE');
                    card.find('.fc-number').css('color', '#059669');
                    btn.replaceWith('<button class="icon-btn validate validated" disabled data-tooltip="Validée" title="Validée"><i class="bi bi-check-circle-fill"></i></button>');
                } else {
                    showToast('Erreur : ' + (resp.error|| 'Inconnue'), 'error');
                    btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i>');
                }
            },
            error: function() {
                showToast('Erreur de communication', 'error');
                btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i>');
            }
        });
        });
    });

    // Supprimer achat
    // Chargement paginé de la liste des achats (remplace l'ancien filtrage
    // purement client-side, qui obligeait à charger tous les achats d'un coup).
    let currentPageAchats = 1;
    function chargerAchats(page) {
        currentPageAchats = page || 1;
        const data = {
            action: 'liste_achats',
            page: currentPageAchats,
            fournisseur: $('#fournisseurFilter').val() || '',
            etat: $('#etatFilter').val() || '',
            statut: $('#statutFilter').val() || '',
        };
        $.post(baseUrl, data, function(resp) {
            if (resp && resp.success) {
                $('#facturesGrid').html(resp.html);
                $('#facturesPagination').html(resp.pagination);
            }
        }, 'json');
    }

    $('#facturesPagination').on('click', '.page-link', function(e) {
        e.preventDefault();
        const page = $(this).data('page');
        if (page) chargerAchats(page);
    });

    $(document).on('click', '.supprimer-facture', function(e) {
        e.stopPropagation();
        factureToDelete = $(this).data('id');
        $('#deleteFactureId').text(factureToDelete);
        deleteModal.show();
    });

    $('#confirmDeleteBtn').on('click', function() {
        if (!factureToDelete) return;
        const btn = $(this);
        const id = factureToDelete;
        const cardItem = $('.facture-item[data-id="' + id + '"]');
        const card = cardItem.find('.facture-card');
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Suppression...');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'delete_facture', id: id },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    deleteModal.hide();
                    showToast('Achat ' + id + ' supprimé', 'success');
                    card.addClass('deleting');
                    setTimeout(function() {
                        chargerAchats(currentPageAchats);
                    }, 400);
                    factureToDelete = null;
                    btn.prop('disabled', false).html('<i class="bi bi-trash3 me-1"></i> Supprimer');
                } else {
                    showToast('Erreur : ' + (resp.error|| 'Inconnue'), 'error');
                    btn.prop('disabled', false).html('<i class="bi bi-trash3 me-1"></i> Supprimer');
                }
            },
            error: function() {
                showToast('Erreur de communication', 'error');
                btn.prop('disabled', false).html('<i class="bi bi-trash3 me-1"></i> Supprimer');
            }
        });
    });

    // Filtres
    $('#filterBtn').on('click', function() {
        chargerAchats(1);
    });

    $('#resetBtn').on('click', function() {
        $('#fournisseurFilter').selectpicker('val', '');
        $('#etatFilter').selectpicker('val', '');
        $('#statutFilter').selectpicker('val', '');
        chargerAchats(1);
    });

    // Voir détails
    $(document).on('click', '.voir-facture', function(e) {
        e.stopPropagation();
        const id = $(this).data('id');
        $('#modalTitleText').text('Achat ' + id);
        $('#factureDetails').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted small">Chargement...</p></div>');
        $('#btnModifier').prop('disabled', false).removeClass('disabled').css('opacity', '1').attr('title', 'Modifier');
        $('#factureModal').modal('show');
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'get_details', id: id },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#factureDetails').html('<div class="alert alert-danger">' + data.error + '</div>');
                    return;
                }
                const f = data.facture;
                const etatColor = f.etat_facture === 'Payee' || f.etat_facture === 'Payee cash' ? 'success' : (f.etat_facture === 'Partielle' ? 'warning' : 'danger');
                const statutColor = (f.statut_facture === 'Validee') ? 'primary' : 'secondary';
                let html = `<div class="detail-section-chic">
                    <div class="detail-section-title-chic info"><i class="bi bi-info-circle-fill"></i> INFORMATIONS GÉNÉRALES</div>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">N° ACHAT</div><div class="fw-bold" style="color:#1e293b;font-size:15px;">${f.numero_facture}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">DATE</div><div class="fw-semibold">${new Date(f.date_facture).toLocaleDateString('fr-FR')}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">FOURNISSEUR</div><div class="fw-semibold">${f.nom_prenom_contact|| 'N/C'}</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">TYPE DOCUMENT</div><div class="fw-semibold">${f.categorie_facture}</div></div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <span class="badge-chic ${etatColor}"><span class="dot"></span> ${f.etat_facture}</span>
                        <span class="badge-chic ${statutColor}"><span class="dot"></span> ${f.statut_facture}</span>
                    </div>
                </div>
                <div class="detail-section-chic">
                    <div class="detail-section-title-chic money"><i class="bi bi-cash-stack"></i> MONTANTS</div>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">MONTANT TOTAL</div><div class="fw-bold" style="color:#4f46e5;font-size:18px;font-family:'Outfit',sans-serif;">${Number(f.montant_ttc).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-6"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">DÉJÀ VERSÉ</div><div class="fw-bold text-success">${Number(f.avance).toLocaleString('fr-FR')} FCFA</div></div>
                        <div class="col-md-12"><div class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">RESTE DÛ AU FOURNISSEUR</div><div class="fw-bold text-danger">${Number(f.reste).toLocaleString('fr-FR')} FCFA</div></div>
                    </div>
                    <div class="mt-2 small text-muted"><i class="bi bi-info-circle"></i> Le règlement de ce fournisseur se gère depuis votre interface de règlement dédiée.</div>
                </div>`;
                if (data.commandes.length > 0) {
                    html += `<div class="detail-section-chic">
                        <div class="detail-section-title-chic box"><i class="bi bi-box-seam-fill"></i> LIGNES D'ACHAT (${data.commandes.length})</div>
                        <div class="table-responsive"><table class="modal-table"><thead><tr><th>Produit</th><th class="text-center">Qté</th><th class="text-end">Prix d'achat</th><th class="text-end">Montant</th></tr></thead><tbody>`;
                    data.commandes.forEach(c => {
                        const ppl = parseInt(c.produits_par_lot) || 1;
                        let qteTxt;
                        if (ppl > 1) {
                            const nbLots = Math.floor(c.quantite_commande / ppl);
                            const reste = c.quantite_commande % ppl;
                            const lib = c.libelle_lot || 'Lot';
                            qteTxt = reste > 0 ? `${nbLots} ${lib}(s) et ${reste} Pièce(s)` : `${nbLots} ${lib}(s)`;
                        } else {
                            qteTxt = `${c.quantite_commande} Pièce(s)`;
                        }
                        // "Prix d'achat" est le coût DU LOT tel qu'il a été saisi à
                        // l'achat (colonne dédiée prix_lot_ligne, jamais recalculé —
                        // donc jamais d'arrondi du type "395,83" au lieu de "9 500").
                        // Repli sur prix_achat (prix/unité) pour les lignes à l'unité
                        // ou les anciennes lignes sans coût de lot enregistré.
                        const aPrixLot = c.prix_lot_ligne !== null && c.prix_lot_ligne !== undefined && c.prix_lot_ligne !== '';
                        const prixAffiche = aPrixLot ? c.prix_lot_ligne : c.prix_achat;
                        html += `<tr><td class="fw-semibold">${c.titre_produit}</td><td class="text-center">${qteTxt}</td><td class="text-end">${Number(prixAffiche).toLocaleString('fr-FR')} FCFA</td><td class="text-end fw-bold">${Number(c.montant_commande).toLocaleString('fr-FR')} FCFA</td></tr>`;
                    });
                    html += '</tbody></table></div></div>';
                }
                $('#factureDetails').html(html).data('facture-id', f.numero_facture).data('contact-name', f.nom_prenom_contact || '');
                if (data.is_locked) {
                    $('#btnModifier').prop('disabled', true).addClass('disabled').css('opacity', '0.5').attr('title', 'Achat réglé intégralement, modification impossible');
                }
            },
            error: function() {
                $('#factureDetails').html('<div class="alert alert-danger">Erreur de chargement.</div>');
            }
        });
    });

    $('#btnModifier').click(function() {
        if ($(this).prop('disabled')) return;
        const id = $('#factureDetails').data('facture-id');
        if (id) {
            $('#factureModal').modal('hide');
            chargerEdition(id);
        }
    });

    function submitPdfPost(id, targetSelf) {
        const params = new URLSearchParams(window.location.search);
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = baseUrl;
        if (targetSelf) form.target = '_self';
        const addField = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = name; input.value = value;
            form.appendChild(input);
        };
        params.forEach((value, key) => addField(key, value));
        addField('action', 'pdf');
        addField('id', id);
        addField('csrf_token', csrfTokenJs);
        document.body.appendChild(form);
        form.submit();
        form.remove();
    }

    $('#btnImprimer').click(function() {
        const id = $('#factureDetails').data('facture-id');
        if (id) submitPdfPost(id, true);
    });

    // Menu de partage de repli (WhatsApp / Email / Telegram) : télécharge le PDF
    // sur l'appareil puis ouvre le canal choisi avec un message pré-rempli,
    // à utiliser quand le partage natif (Web Share API avec fichier) est
    // indisponible (HTTP, navigateur desktop, etc.).
    function fermerMenuPartage() {
        $('#menuPartageRepli').remove();
        $(document).off('click.menuPartage');
    }

    function ouvrirMenuPartage(btn, blob, id, nomContact, messageTexte) {
        fermerMenuPartage();
        const url = URL.createObjectURL(blob);
        const nomFichier = 'achat-' + id + '.pdf';
        const declencherTelechargement = () => {
            const a = document.createElement('a');
            a.href = url; a.download = nomFichier;
            document.body.appendChild(a); a.click(); a.remove();
        };
        const $menu = $(`
            <div id="menuPartageRepli" style="position:absolute; bottom:100%; left:0; margin-bottom:8px; background:#fff; border-radius:12px; box-shadow:0 10px 30px rgba(15,23,42,.2); padding:8px; z-index:2000; min-width:220px;">
                <div class="small text-muted px-2 pb-1" style="font-size:11px;">Le PDF sera téléchargé, à joindre au message</div>
                <button type="button" class="dropdown-item-partage" data-canal="whatsapp" style="display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;padding:8px;border-radius:8px;text-align:left;"><i class="bi bi-whatsapp" style="color:#25D366;"></i> WhatsApp</button>
                <button type="button" class="dropdown-item-partage" data-canal="email" style="display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;padding:8px;border-radius:8px;text-align:left;"><i class="bi bi-envelope-fill" style="color:#3b82f6;"></i> Email</button>
                <button type="button" class="dropdown-item-partage" data-canal="telegram" style="display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;padding:8px;border-radius:8px;text-align:left;"><i class="bi bi-telegram" style="color:#229ED9;"></i> Telegram</button>
                <button type="button" class="dropdown-item-partage" data-canal="telecharger" style="display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;padding:8px;border-radius:8px;text-align:left;"><i class="bi bi-download" style="color:#64748b;"></i> Télécharger seulement</button>
            </div>
        `);
        $menu.find('.dropdown-item-partage').on('mouseenter', function() { $(this).css('background', '#f1f5f9'); }).on('mouseleave', function() { $(this).css('background', 'none'); });
        $menu.on('click', '.dropdown-item-partage', function() {
            const canal = $(this).data('canal');
            declencherTelechargement();
            if (canal === 'whatsapp') {
                window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(messageTexte), '_blank');
            } else if (canal === 'email') {
                window.open('mailto:?subject=' + encodeURIComponent('Bon fournisseur N°' + id) + '&body=' + encodeURIComponent(messageTexte), '_blank');
            } else if (canal === 'telegram') {
                window.open('https://t.me/share/url?url=&text=' + encodeURIComponent(messageTexte), '_blank');
            }
            fermerMenuPartage();
        });
        btn.parent().css('position', 'relative').append($menu);
        setTimeout(() => {
            $(document).on('click.menuPartage', function(e) {
                if (!$(e.target).closest('#menuPartageRepli, #btnPartager').length) fermerMenuPartage();
            });
        }, 0);
    }

    $('#btnPartager').click(async function() {
        const id = $('#factureDetails').data('facture-id');
        if (!id) return;
        const nomContact = $('#factureDetails').data('contact-name') || '';
        const messageTexte = 'Bonjour' + (nomContact ? ' ' + nomContact : '') + ', voici le bon de commande N°' + id + '. Le fichier PDF est joint à ce message. Merci.';
        const btn = $(this);
        const originalHtml = btn.html();
        fermerMenuPartage();
        const params = new URLSearchParams(window.location.search);
        params.set('action', 'pdf');
        params.set('id', id);
        params.set('csrf_token', csrfTokenJs);

        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i><span>Préparation...</span>');
        let blob;
        try {
            const resp = await fetch(baseUrl, { method: 'POST', body: params, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            blob = await resp.blob();
            if (!resp.ok || (blob.type && blob.type.indexOf('pdf') === -1)) {
                let detail = '';
                try { detail = (await blob.text()); } catch (e2) {}
                const titreMatch = detail.match(/<title>(.*?)<\/title>/i);
                console.error('Échec de préparation du PDF (partage) — URL POSTée :', baseUrl, '— statut HTTP', resp.status, '— titre de la page reçue :', titreMatch ? titreMatch[1] : '(aucun)', '— début de la réponse :', detail.slice(0, 300));
                throw new Error('pdf_invalide');
            }
        } catch (e) {
            btn.prop('disabled', false).html(originalHtml);
            showToast('Impossible de préparer le PDF pour le partage (voir la console du navigateur, F12, pour le détail).', 'danger');
            return;
        }

        let raisonRepli = null;
        if (!window.isSecureContext) raisonRepli = 'HTTPS requis pour le partage natif';
        else if (!navigator.share) raisonRepli = 'navigateur non compatible avec le partage natif';
        else if (!navigator.canShare) raisonRepli = 'partage de fichiers non supporté';

        if (!raisonRepli) {
            const file = new File([blob], 'achat-' + id + '.pdf', { type: 'application/pdf' });
            if (navigator.canShare({ files: [file] })) {
                try {
                    await navigator.share({ files: [file], title: 'Achat N°' + id, text: messageTexte });
                    btn.prop('disabled', false).html(originalHtml);
                    return;
                } catch (e) {
                    btn.prop('disabled', false).html(originalHtml);
                    if (e && e.name === 'AbortError') return;
                    raisonRepli = 'le partage natif a échoué';
                }
            } else {
                raisonRepli = 'cet appareil ne peut pas partager de fichier PDF';
            }
        }

        btn.prop('disabled', false).html(originalHtml);
        console.warn('Partage natif indisponible (' + raisonRepli + '), affichage des options de repli');
        ouvrirMenuPartage(btn, blob, id, nomContact, messageTexte);
    });

    function chargerEdition(id) {
        $('#editSection').show();
        $('#editContent').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 text-muted small">Chargement...</p></div>');
        $('html, body').animate({ scrollTop: $('#editSection').offset().top - 20 }, 400);
        $.ajax({
            url: baseUrl, type: 'POST',
            data: { action: 'get_details', id: id },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#editContent').html('<div class="alert alert-danger">' + data.error + '</div>');
                    return;
                }
                if (data.is_locked) {
                    $('#editContent').html('<div class="alert alert-warning"><i class="bi bi-lock-fill me-2"></i>Cet achat est réglé intégralement. Il ne peut plus être modifié.</div>');
                    return;
                }
                afficherFormulaireEdition(data);
            },
            error: function() {
                $('#editContent').html('<div class="alert alert-danger">Erreur de chargement.</div>');
            }
        });
    }

    function afficherFormulaireEdition(data) {
        const f = data.facture;
        const avanceFixe = parseFloat(f.avance) || 0;
        let html = `<div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">MONTANT TOTAL</label>
                <input type="text" class="form-control form-control-lg" id="edit_montant_ttc" value="${Number(f.montant_ttc).toLocaleString('fr-FR')}" readonly style="background:#f8fafc;font-weight:700;color:#4f46e5;">
            </div>
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">DÉJÀ VERSÉ</label>
                <input type="text" class="form-control form-control-lg" value="${Number(f.avance).toLocaleString('fr-FR')}" readonly style="background:#f8fafc;font-weight:700;color:#10b981;">
            </div>
            <div class="col-md-4">
                <label class="text-uppercase small fw-bold text-muted" style="font-size:10px;letter-spacing:.5px;">RESTE DÛ</label>
                <input type="text" class="form-control form-control-lg" id="edit_reste" value="${Number(f.reste).toLocaleString('fr-FR')}" readonly style="background:#f8fafc;font-weight:700;color:#ef4444;">
            </div>
            <div class="col-12"><div class="small text-muted"><i class="bi bi-info-circle"></i> Le règlement (avance/reste) se gère depuis votre interface dédiée. Ici, seules les lignes achetées sont modifiables — le montant total et le reste dû se recalculent automatiquement.</div></div>
        </div>
        <h6 class="mb-3 fw-bold" style="color:#1e293b;display:flex;align-items:center;gap:8px;"><i class="bi bi-box-seam-fill text-warning"></i> Lignes d'achat</h6>
        <div class="table-responsive">
            <table class="edit-table-chic" id="editLignesTable">
                <thead><tr><th>Pièce</th><th class="text-center">Qté</th><th class="text-end">Prix d'achat</th><th class="text-end">Montant</th><th class="text-center">Action</th></tr></thead>
                <tbody>`;
        data.commandes.forEach(c => {
            // prix_lot_ligne (colonne dédiée) dit sans ambiguïté si cette ligne a été
            // achetée par lot : si oui, on affiche/édite directement ce coût de lot
            // (jamais de reconstruction par multiplication, jamais d'arrondi introduit).
            const aPrixLot = c.prix_lot_ligne !== null && c.prix_lot_ligne !== undefined && c.prix_lot_ligne !== '';
            const prixAffiche = aPrixLot ? c.prix_lot_ligne : c.prix_achat;
            html += `<tr class="ligne-commande" data-id="${c.numero_commande}" data-produit="${c.produit_id}" data-prix-est-lot="${aPrixLot ? '1' : '0'}" data-unites-par-lot="${parseInt(c.produits_par_lot) || 1}">
                <td class="fw-semibold">${c.titre_produit}</td>
                <td class="text-center"><input type="number" class="form-control form-control-sm qte" value="${c.quantite_commande}" min="0" style="width:80px;display:inline-block;text-align:center;"></td>
                <td class="text-end"><input type="number" class="form-control form-control-sm prix" value="${prixAffiche}" min="0" style="width:120px;display:inline-block;text-align:right;"></td>
                <td class="text-end montant-ligne fw-bold">${Number(c.montant_commande).toLocaleString('fr-FR')}</td>
                <td class="text-center"><button class="btn-delete-chic supprimer-ligne" title="Supprimer"><i class="bi bi-trash3"></i></button></td>
            </tr>`;
        });
        html += `</tbody></table></div>
        <div class="mt-4 p-3" style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;">
            <h6 class="mb-3 fw-bold" style="color:#1e293b;display:flex;align-items:center;gap:8px;"><i class="bi bi-plus-circle text-primary"></i> Ajouter un produit</h6>
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Catégorie</label>
                    <select id="newLigneCategorie" class="form-select selectpicker" data-live-search="true">
                        <option value="">-- Catégorie --</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Produit</label>
                    <select id="newLigneProduit" class="form-select selectpicker" data-live-search="true" disabled title="-- Choisir d'abord une catégorie --">
                        <option value="">-- Produit --</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" id="newLigneQteLabel">Quantité</label>
                    <input type="number" id="newLigneQte" class="form-control" min="1" step="1" placeholder="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label small" id="newLignePrixLabel">Prix d'achat</label>
                    <input type="number" id="newLignePrix" class="form-control" min="0" step="0.01" placeholder="0.00">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn-action-chic enregistrer w-100" id="btnAjouterLigne"><i class="bi bi-plus-lg"></i> Ajouter</button>
                </div>
            </div>
            <div class="small text-muted mt-2" id="newLigneStockInfo">Stock actuel : —</div>

            <!-- Produit déjà catalogué (menu "Configuration des lots") : on choisit
                 juste le mode d'achat, seul le coût du lot reste modifiable ici. -->
            <div class="mt-2" id="newLigneVenteCatalogue" style="display:none;font-size:12px;color:#64748b;">
                Acheter par :
                <select id="newLigneModeVente" style="display:inline-block;width:auto;">
                    <option value="unite">Unité</option>
                </select>
                <span id="newLigneModeVenteLotWrap" style="display:none;margin-left:8px;">
                    <span id="newLigneModeVentePrixLotZone" style="display:none;">
                        Coût du lot pour cet achat : <input type="number" id="newLigneModeVentePrixLot" min="0" step="0.01" style="width:110px;">
                        —
                    </span>
                    <strong id="newLigneModeVenteApercu"></strong>
                </span>
            </div>

            <!-- Produit pas encore catalogué : ancien système, configuration
                 libre (à retirer une fois tous les produits catalogués). -->
            <div class="mt-2" style="font-size:12px;color:#64748b;" id="newLigneVenteAdHoc">
                <label style="cursor:pointer;">
                    <input type="checkbox" id="newLigneLotConfigure"> Configurer un lot pour cette ligne
                </label>
                <span id="newLigneLotDetails" style="display:none;margin-left:10px;">
                    <input type="number" id="newLigneLotUnites" min="2" step="1" value="2" style="width:56px;" title="Unités par lot"> unité(s) par
                    <select id="newLigneLotLibelle" style="display:inline-block;width:auto;">
                        ${['Boîte', 'Palette', 'Carton', 'Bidon', 'Unité'].map(l => `<option value="${l}">${l}</option>`).join('')}
                    </select>
                    — <strong id="newLigneLotApercu"></strong>
                </span>
            </div>

            <div id="newLigneLotPrixInfo" class="mt-1" style="display:none;color:#0f766e;font-weight:600;"></div>
        </div>
        <div class="mt-4 d-flex gap-2 justify-content-end">
            <button class="btn-action-chic annuler" id="btnAnnulerEdit"><i class="bi bi-x-lg"></i> Annuler</button>
            <button class="btn-action-chic enregistrer" id="btnEnregistrerEdit"><i class="bi bi-check-lg"></i> Enregistrer</button>
        </div>`;
        $('#editContent').html(html).data('facture-id', f.numero_facture).data('boutique-id', data.boutique_id || '');

        // ------------------------------------------------------------
        // Recalcul en direct du montant total et du reste (pas de taxe/remise
        // ici : le montant d'un achat = somme brute des lignes).
        // ------------------------------------------------------------
        // Montant d'une ligne : si "Prix" contient un prix DE LOT (flag
        // data-prix-est-lot, posé pour toute ligne ayant un prix_lot_ligne en
        // base, ou pour une nouvelle ligne ajoutée via le catalogue) -> montant
        // = nb de cartons complets × prix du lot. Sinon (produit simple),
        // montant = qté × prix/unité, comme toujours. La quantité en base
        // reste toujours en unités.
        function montantLigne(row) {
            const qte = parseFloat(row.find('.qte').val()) || 0;
            const prix = parseFloat(row.find('.prix').val()) || 0;
            const ppl = Math.max(1, parseInt(row.data('unites-par-lot')) || 1);
            const prixEstLot = row.data('prix-est-lot') == 1 || row.data('prix-est-lot') === '1';
            if (prixEstLot && ppl > 1) return Math.floor(qte / ppl) * prix;
            return qte * prix;
        }

        function recalculerTotaux() {
            let totalLignes = 0;
            $('#editLignesTable tbody tr').each(function() {
                totalLignes += montantLigne($(this));
            });
            $('#edit_montant_ttc').val(totalLignes.toLocaleString('fr-FR'));
            $('#edit_reste').val(Math.max(0, Math.round((totalLignes - avanceFixe) * 100) / 100).toLocaleString('fr-FR'));
        }

        // ------------------------------------------------------------
        // Panneau d'ajout de produit : catégorie -> produit (filtré).
        // ------------------------------------------------------------
        const $newCat = $('#newLigneCategorie');
        const $newProd = $('#newLigneProduit');
        CATEGORIES_ACHAT.forEach(function(c) {
            $newCat.append($('<option>', { value: c.code_categorie, text: c.titre_categorie }));
        });

        function filtrerProduitsNouvelleLigne() {
            const cat = String($newCat.val() || '').trim();
            $newProd.empty();
            $newProd.append($('<option>', { value: '', text: '-- Choisir un produit --' }));
            if (cat === '') {
                $newProd.prop('disabled', true).attr('title', "-- Choisir d'abord une catégorie --");
                if ($newProd.hasClass('bs-select-hidden') || $newProd.data('selectpicker')) { $newProd.selectpicker('destroy'); }
                $newProd.selectpicker();
                $('#newLigneStockInfo').text('Stock actuel : —');
                return;
            }
            $newProd.prop('disabled', true).attr('title', 'Chargement...');
            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: { action: 'produits_par_categorie', categorie_id: cat },
                success: function(resp) {
                    if (resp.success) {
                        (resp.produits || []).forEach(function(p) {
                            const suffixe = (p.etat_produit === 'RUPTURE') ? ' (rupture)' : '';
                            $newProd.append($('<option>', {
                                value: p.code_produit,
                                text: p.titre_produit + suffixe,
                                'data-prix': p.prix_fournisseur || 0
                            }));
                        });
                    }
                    $newProd.prop('disabled', false).attr('title', '-- Choisir un produit --');
                    if ($newProd.hasClass('bs-select-hidden') || $newProd.data('selectpicker')) { $newProd.selectpicker('destroy'); }
                    $newProd.selectpicker();
                    // ⚠️ Un seul événement lié ici : lier 'changed.bs.select' ET 'change'
                    // ensemble exécutait ce handler deux fois par sélection (double appel
                    // AJAX de vérification de stock, silencieux mais inutile).
                    $newProd.off('changed.bs.select').on('changed.bs.select', function() {
                        // ⚠️ NE PAS appeler .selectpicker('refresh') ici : sur bootstrap-select
                        // 1.14 beta, refresh() après un destroy()+réinit dynamique duplique les
                        // éléments internes du bouton (ex. "ProduitProduitProduit"). Le
                        // destroy()+réinit fait juste au-dessus suffit à resynchroniser le
                        // picker ; on réécrit simplement le texte affiché à la main.
                        const texteChoisi = $newProd.find('option:selected').text();
                        $newProd.parent().find('.filter-option-inner-inner').text(texteChoisi);
                        updateNouvelleLigneStock();
                    });
                    $('#newLigneStockInfo').text('Stock actuel : —');
                }
            });
        }

        function reinitialiserLabelsQteEtPrix() {
            $('#newLigneQteLabel').text('Quantité');
            $('#newLigneQte').attr('placeholder', '0');
            $('#newLignePrixLabel').text('Prix d\'achat');
        }

        function appliquerModeVenteProduit(lots) {
            const $sel = $('#newLigneModeVente');
            $sel.find('option:not([value="unite"])').remove();
            if (lots.length > 0) {
                lots.forEach(function(l) {
                    $sel.append(
                        '<option value="' + escHtml(l.libelle) + '" data-unites="' + l.unites_par_lot +
                        '" data-cout-lot="' + (l.cout_lot !== null ? l.cout_lot : '') + '">' +
                        escHtml(l.libelle) + ' — ' + l.unites_par_lot + ' unité(s)</option>'
                    );
                });
                $sel.val('unite');
                $('#newLigneModeVenteLotWrap').hide();
                $('#newLigneVenteCatalogue').show();
                $('#newLigneVenteAdHoc').hide();
                $('#newLigneLotConfigure').prop('checked', false);
                $('#newLigneLotDetails').hide();
            } else {
                $('#newLigneVenteCatalogue').hide();
                $('#newLigneVenteAdHoc').show();
            }
            reinitialiserLabelsQteEtPrix();
            $('#newLigneLotPrixInfo').hide();
        }

        function updateNouvelleLigneStock() {
            const produit = $newProd.val();
            const boutique = $('#editContent').data('boutique-id');
            $newProd.data('prix-unitaire', 0);
            $newProd.data('lots', []);
            appliquerModeVenteProduit([]);
            if (!produit) { $('#newLigneStockInfo').text('Stock actuel : —'); return; }
            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: { action: 'check_stock_produit', produit_id: produit, boutique_id: boutique },
                success: function(resp) {
                    if (resp.success) {
                        $newProd.data('prix-unitaire', resp.prix);
                        $newProd.data('lots', resp.lots || []);
                        $newProd.data('saisie-carton', !!resp.saisie_par_carton);
                        $('#newLigneStockInfo').html('Stock actuel : <strong>' + resp.disponible + '</strong>' + (boutique ? '' : ' (boutique de réception inconnue)'));
                        if (!$('#newLignePrix').val() && resp.prix > 0) $('#newLignePrix').val(resp.prix);
                        appliquerModeVenteProduit(resp.lots || []);
                    } else {
                        $('#newLigneStockInfo').text('Stock actuel : —');
                    }
                }
            });
        }

        $newCat.selectpicker();
        // ⚠️ Un seul événement lié ici (pas 'changed.bs.select change' ensemble) :
        // bootstrap-select déclenche à la fois son propre événement et l'événement
        // natif 'change' à chaque sélection. Lier les deux au même handler
        // l'exécutait deux fois, ce qui dupliquait visuellement les produits dans
        // la liste (deux appels AJAX ajoutant chacun leur lot d'options).
        $newCat.on('changed.bs.select', filtrerProduitsNouvelleLigne);
        filtrerProduitsNouvelleLigne();

        // ------------------------------------------------------------
        // Configuration de lot pour la nouvelle ligne (même principe que
        // dans achat.php) : optionnelle — si non cochée, comportement
        // "produit simple" (pas de lot, produits_par_lot = 1).
        // ------------------------------------------------------------
        // ------------------------------------------------------------
        // Configuration de lot pour la nouvelle ligne (même principe que
        // dans achat.php/vente.php) : optionnelle — si non cochée, comportement
        // "produit simple" (pas de lot, produits_par_lot = 1).
        //
        // Si le lot choisi correspond à un lot du catalogue (menu "Configuration
        // des lots") ayant un coût de lot défini, on suggère automatiquement le
        // prix d'achat correspondant. Sinon, le prix reste celui saisi/proposé —
        // comportement strictement inchangé.
        // ------------------------------------------------------------

        // ----- Mode "produit catalogué" : structure du lot fixe. La quantité
        // saisie est TOUJOURS le nombre de pièces. Le prix saisi est le prix
        // unitaire tant qu'aucun coût n'est configuré pour ce lot ; s'il y en
        // a un, le prix saisi devient le COÛT DU LOT (modifiable). -----
        function majModeVenteCatalogue() {
            const $sel = $('#newLigneModeVente');
            const opt = $sel.find('option:selected');
            const libelle = $sel.val();
            if (libelle === 'unite') {
                reinitialiserLabelsQteEtPrix();
                $('#newLigneLotPrixInfo').hide();
                return;
            }
            const unites = parseInt(opt.data('unites')) || 1;
            const enCarton = !!$newProd.data('saisie-carton');
            const qteSaisie = parseInt($('#newLigneQte').val()) || 0;
            const qte = enCarton ? qteSaisie * unites : qteSaisie; // qte = toujours en pièces
            const nbLots = enCarton ? qteSaisie : Math.floor(qte / unites);
            const reste = enCarton ? 0 : qte % unites;
            const coutLotCatalogue = opt.data('cout-lot');
            const aCoutLot = (coutLotCatalogue !== '' && coutLotCatalogue !== undefined && coutLotCatalogue !== null);

            $('#newLigneQteLabel').text(enCarton ? 'Quantité (cartons)' : 'Quantité (pièces)');
            $('#newLigneQte').attr('placeholder', enCarton ? 'Nombre de cartons' : 'Nombre de pièces');
            $('#newLignePrixLabel').text(aCoutLot ? ('Coût du ' + libelle.toLowerCase()) : 'Prix unitaire');
            $('#newLigneModeVenteApercu').text(reste > 0 ? (nbLots + ' ' + libelle + '(s) et ' + reste + ' pièce(s)') : (nbLots + ' ' + libelle + '(s)'));
            $('#newLigneModeVentePrixLotZone').toggle(aCoutLot);

            if (!aCoutLot || qte <= 0) { $('#newLigneLotPrixInfo').hide(); return; }

            let coutLotSaisi = parseFloat($('#newLigneModeVentePrixLot').val());
            if (isNaN(coutLotSaisi)) {
                coutLotSaisi = parseFloat(coutLotCatalogue);
                $('#newLigneModeVentePrixLot').val(coutLotSaisi);
            }

            // Le prix affiché/modifiable dans "Coût du <lot>" EST directement
            // le coût par lot.
            const $prix = $('#newLignePrix');
            if ($prix.val() === '' || parseFloat($prix.val()) === $prix.data('derniere-suggestion')) {
                $prix.val(coutLotSaisi);
                $prix.data('derniere-suggestion', coutLotSaisi);
            }
            const prixUnitEffectif = Math.round(((parseFloat($prix.val()) || 0) / unites) * 100) / 100;
            const total = Math.floor(qte / unites) * (parseFloat($prix.val()) || 0);
            $('#newLigneLotPrixInfo').text(
                qte + ' pièce(s), soit ' + prixUnitEffectif.toLocaleString('fr-FR') + ' F/pièce (dérivé du coût du lot) = ' +
                total.toLocaleString('fr-FR') + ' F.'
            ).show();
        }
        $('#newLigneModeVente').on('change', function() {
            const opt = $(this).find('option:selected');
            if ($(this).val() === 'unite') {
                $('#newLigneModeVenteLotWrap').hide();
                reinitialiserLabelsQteEtPrix();
                $('#newLigneLotPrixInfo').hide();
            } else {
                const coutLotCatalogue = opt.data('cout-lot');
                const aCoutLot = (coutLotCatalogue !== '' && coutLotCatalogue !== undefined && coutLotCatalogue !== null);
                if (aCoutLot) {
                    $('#newLigneModeVentePrixLot').val(coutLotCatalogue);
                    $('#newLignePrix').val('').removeData('derniere-suggestion');
                } else {
                    $('#newLigneModeVentePrixLot').val('');
                }
                $('#newLigneModeVenteLotWrap').show();
                majModeVenteCatalogue();
            }
        });
        $('#newLigneModeVentePrixLot').on('input change', majModeVenteCatalogue);

        // ----- Mode "ad-hoc" (produit pas encore catalogué) : configuration
        // libre. La quantité saisie est TOUJOURS le nombre de pièces ; le prix
        // reste le prix unitaire. -----
        function majApercuLotNouvelleLigne() {
            const unites = Math.max(2, parseInt($('#newLigneLotUnites').val()) || 2);
            const qte = parseInt($('#newLigneQte').val()) || 0;
            const libelle = $('#newLigneLotLibelle').val();
            const nbLots = Math.floor(qte / unites);
            const reste = qte % unites;
            $('#newLigneQteLabel').text('Quantité (pièces)');
            $('#newLignePrixLabel').text('Prix d\'achat');
            $('#newLigneLotApercu').text(reste > 0 ? (nbLots + ' ' + libelle + '(s) et ' + reste + ' pièce(s)') : (nbLots + ' ' + libelle + '(s)'));
            $('#newLigneLotPrixInfo').hide();
        }
        $('#newLigneLotConfigure').on('change', function() {
            if (this.checked) { $('#newLigneLotDetails').show(); majApercuLotNouvelleLigne(); }
            else { $('#newLigneLotDetails').hide(); reinitialiserLabelsQteEtPrix(); $('#newLigneLotPrixInfo').hide(); }
        });
        $('#newLigneLotUnites, #newLigneLotLibelle').on('input change', majApercuLotNouvelleLigne);

        // La quantité peut alimenter l'un ou l'autre mode selon celui actif.
        $('#newLigneQte').on('input change', function() {
            if ($('#newLigneVenteCatalogue').is(':visible') && $('#newLigneModeVente').val() !== 'unite') {
                majModeVenteCatalogue();
            } else if ($('#newLigneLotConfigure').is(':checked')) {
                majApercuLotNouvelleLigne();
            }
        });

        $('#btnAjouterLigne').click(function() {
            const produit = $newProd.val();
            const produitTexte = $newProd.find('option:selected').text();
            const boutique = $('#editContent').data('boutique-id');
            const prixSaisi = parseFloat($('#newLignePrix').val()) || 0;

            // Le prix saisi est le coût du lot uniquement si un coût de lot est
            // configuré en catalogue pour ce type ; sinon (ad-hoc, ou lot
            // catalogue sans coût configuré), c'est le prix unitaire classique.
            let lotConfigure, unitesParLot, libelleLot, prixEstLot;
            const modeCatalogueActif = $('#newLigneVenteCatalogue').is(':visible');
            if (modeCatalogueActif && $('#newLigneModeVente').val() !== 'unite') {
                lotConfigure = true;
                const opt = $('#newLigneModeVente').find('option:selected');
                unitesParLot = parseInt(opt.data('unites')) || 2;
                libelleLot = $('#newLigneModeVente').val();
                const coutLotCatalogue = opt.data('cout-lot');
                prixEstLot = (coutLotCatalogue !== '' && coutLotCatalogue !== undefined && coutLotCatalogue !== null);
            } else if (!modeCatalogueActif && $('#newLigneLotConfigure').is(':checked')) {
                lotConfigure = true;
                unitesParLot = Math.max(2, parseInt($('#newLigneLotUnites').val()) || 2);
                libelleLot = $('#newLigneLotLibelle').val() || 'Unité';
                prixEstLot = false;
            } else {
                lotConfigure = false;
                unitesParLot = 1;
                libelleLot = 'Unité';
                prixEstLot = false;
            }

            // Saisie : nombre de CARTONS pour un produit "saisie_par_carton"
            // (fiche produit) tant qu'un mode lot catalogue est actif ; nombre
            // de PIÈCES dans tous les autres cas (comportement standard,
            // inchangé). qte reste TOUJOURS en pièces en interne.
            const enModeCarton = modeCatalogueActif && $('#newLigneModeVente').val() !== 'unite' && !!$newProd.data('saisie-carton');
            const qteSaisie = parseInt($('#newLigneQte').val()) || 0;
            const qte = enModeCarton ? qteSaisie * unitesParLot : qteSaisie;

            if (!produit) { showToast('Choisissez une catégorie puis un produit.', 'error'); return; }
            if (qte <= 0) { showToast('Saisissez une quantité valide (> 0).', 'error'); return; }

            function reinitialiserFormulaireNouvelleLigne() {
                $('#newLigneQte, #newLignePrix').val('');
                $('#newLigneLotConfigure').prop('checked', false);
                $('#newLigneLotDetails').hide();
                $('#newLigneLotUnites').val(2);
                $('#newLigneLotLibelle').val('Unité');
                $('#newLigneModeVente').val('unite');
                $('#newLigneModeVenteLotWrap').hide();
                $('#newLigneModeVentePrixLotZone').hide();
                $('#newLigneModeVentePrixLot').val('');
                $('#newLigneLotPrixInfo').hide();
                appliquerModeVenteProduit([]);
                filtrerProduitsNouvelleLigne();
            }

            // Le produit est-il déjà présent dans cet achat (ligne existante ou
            // déjà ajoutée) ? Si oui, on augmente simplement sa quantité au lieu
            // de dupliquer une ligne pour le même produit.
            const ligneExistante = $('#editLignesTable tbody tr').filter(function() {
                return String($(this).data('produit')) === String(produit) && !$(this).hasClass('ligne-a-supprimer');
            }).first();

            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: { action: 'check_stock_produit', produit_id: produit, boutique_id: boutique },
                success: function(resp) {
                    const titreProduit = (resp.success && resp.titre) ? resp.titre : produitTexte;

                    if (ligneExistante.length) {
                        const qteActuelle = parseFloat(ligneExistante.find('.qte').val()) || 0;
                        const nouvelleQte = qteActuelle + qte;
                        ligneExistante.find('.qte').val(nouvelleQte).trigger('change');
                        reinitialiserFormulaireNouvelleLigne();
                        showToast(titreProduit + ' est déjà dans cet achat : quantité augmentée à ' + nouvelleQte + ' pièce(s) sur la ligne existante.', 'success');
                        return;
                    }

                    const prixFinal = prixSaisi > 0 ? prixSaisi : (resp.prix || 0);
                    // Montant : nb de lots complets × coût DU LOT si un coût de lot
                    // est configuré, qté (pièces) × prix unitaire sinon.
                    const nbLotsInitial = Math.floor(qte / unitesParLot);
                    const montant = (prixEstLot && unitesParLot > 1) ? nbLotsInitial * prixFinal : qte * prixFinal;
                    let sousTitreLot = '';
                    if (lotConfigure) {
                        const reste = qte % unitesParLot;
                        const apercuLot = reste > 0 ? `${nbLotsInitial} ${libelleLot}(s) et ${reste} pièce(s)` : `${nbLotsInitial} ${libelleLot}(s)`;
                        sousTitreLot = `<br><span class="small text-muted">Lot : ${unitesParLot} unité(s) par ${libelleLot} — ${apercuLot}</span>`;
                    }
                    const row = `<tr class="ligne-commande ligne-nouvelle" data-id="" data-produit="${produit}" data-boutique="${boutique}"
                        data-lot-configure="${lotConfigure ? '1' : '0'}" data-prix-est-lot="${prixEstLot ? '1' : '0'}" data-unites-par-lot="${unitesParLot}" data-libelle-lot="${escHtml(libelleLot)}">
                        <td class="fw-semibold">${escHtml(titreProduit)} <span class="badge bg-primary-subtle text-primary" style="font-size:9px;">nouveau</span>${sousTitreLot}</td>
                        <td class="text-center"><input type="number" class="form-control form-control-sm qte" value="${qte}" min="0" style="width:80px;display:inline-block;text-align:center;"></td>
                        <td class="text-end"><input type="number" class="form-control form-control-sm prix" value="${prixFinal}" min="0" style="width:120px;display:inline-block;text-align:right;"></td>
                        <td class="text-end montant-ligne fw-bold">${montant.toLocaleString('fr-FR')}</td>
                        <td class="text-center"><button class="btn-delete-chic supprimer-ligne" title="Supprimer"><i class="bi bi-trash3"></i></button></td>
                    </tr>`;
                    $('#editLignesTable tbody').append(row);
                    // On ne réinitialise QUE le produit / quantité / prix / lot : la
                    // catégorie reste sélectionnée pour enchaîner rapidement l'ajout de
                    // plusieurs lignes. On repasse par filtrerProduitsNouvelleLigne()
                    // (reconstruction complète) plutôt que 'selectpicker(val, "")', qui
                    // ne réaffiche pas toujours correctement le placeholder sur cette
                    // version du plugin.
                    reinitialiserFormulaireNouvelleLigne();
                    recalculerTotaux();
                    showToast('Ligne ajoutée : ' + titreProduit + '. Vous pouvez continuer à ajouter d\'autres lignes.', 'success');
                },
                error: function() { showToast('Erreur lors de l\'ajout de la ligne.', 'error'); }
            });
        });

        $(document).on('change', '#editLignesTable .qte, #editLignesTable .prix', function() {
            const row = $(this).closest('tr');
            row.find('.montant-ligne').text(montantLigne(row).toLocaleString('fr-FR'));
            recalculerTotaux();
        });
        $(document).on('click', '#editLignesTable .supprimer-ligne', function() {
            const row = $(this).closest('tr');
            const estNouvelle = row.hasClass('ligne-nouvelle');
            genericConfirm('Supprimer cette ligne ? Le stock correspondant sera retiré.', function() {
                if (estNouvelle) {
                    // Ligne pas encore enregistrée en base : on peut la retirer
                    // complètement, elle ne sera jamais envoyée au serveur.
                    row.remove();
                } else {
                    // Ligne existante : on ne la retire PAS du DOM, sinon elle n'est
                    // plus envoyée au serveur et n'est donc jamais supprimée en base
                    // (le stock qu'elle avait crédité ne serait jamais retiré non plus).
                    // On la marque à quantité 0 (convention déjà gérée côté serveur :
                    // quantité 0 = suppression + retrait du stock), avec possibilité
                    // d'annuler avant l'enregistrement.
                    row.data('qte-avant-suppression', row.find('.qte').val());
                    row.data('prix-avant-suppression', row.find('.prix').val());
                    row.find('.qte').val(0);
                    row.addClass('ligne-a-supprimer');
                    row.css({ opacity: 0.5, textDecoration: 'line-through' });
                    row.find('input').prop('disabled', true);
                    row.find('.montant-ligne').text('0');
                    row.find('.supprimer-ligne')
                        .removeClass('supprimer-ligne').addClass('annuler-suppression-ligne')
                        .attr('title', 'Annuler la suppression')
                        .html('<i class="bi bi-arrow-counterclockwise"></i>');
                }
                recalculerTotaux();
            });
        });
        $(document).on('click', '#editLignesTable .annuler-suppression-ligne', function() {
            const row = $(this).closest('tr');
            row.removeClass('ligne-a-supprimer').css({ opacity: '', textDecoration: '' });
            row.find('input').prop('disabled', false);
            row.find('.qte').val(row.data('qte-avant-suppression') || 0);
            row.find('.prix').val(row.data('prix-avant-suppression') || 0);
            row.find('.montant-ligne').text(montantLigne(row).toLocaleString('fr-FR'));
            row.find('.annuler-suppression-ligne')
                .removeClass('annuler-suppression-ligne').addClass('supprimer-ligne')
                .attr('title', 'Supprimer')
                .html('<i class="bi bi-trash3"></i>');
            recalculerTotaux();
        });
        $('#btnAnnulerEdit').click(function() {
            $('#editSection').hide();
            $('#editContent').empty();
            location.reload();
        });
        // Le serveur (comme l'historique de toutes les lignes déjà enregistrées)
        // attend toujours un prix/unité. Pour une ligne où "Prix" contient un
        // prix DE LOT (flag data-prix-est-lot), on convertit ici seulement,
        // jamais à l'écran.
        function prixUnitePourEnvoi(row) {
            const prix = parseFloat(row.find('.prix').val()) || 0;
            const ppl = Math.max(1, parseInt(row.data('unites-par-lot')) || 1);
            const prixEstLot = row.data('prix-est-lot') == 1 || row.data('prix-est-lot') === '1';
            return (prixEstLot && ppl > 1) ? Math.round((prix / ppl) * 100) / 100 : prix;
        }
        // Le prix du lot TEL QUE SAISI (sans division), à conserver tel quel en
        // base pour un affichage futur sans reconstruction ni erreur d'arrondi.
        // null si la ligne n'est pas achetée par lot.
        function prixLotPourEnvoi(row) {
            const prixEstLot = row.data('prix-est-lot') == 1 || row.data('prix-est-lot') === '1';
            return prixEstLot ? (parseFloat(row.find('.prix').val()) || 0) : null;
        }

        $('#btnEnregistrerEdit').click(function() {
            const commandes = [];
            const nouvellesLignes = [];
            $('#editLignesTable tbody tr').each(function() {
                const row = $(this);
                const qte = parseFloat(row.find('.qte').val()) || 0;
                const id = row.data('id');
                if (row.hasClass('ligne-nouvelle') || !id) {
                    if (qte > 0) {
                        nouvellesLignes.push({
                            produit_id: row.data('produit'),
                            boutique_id: row.data('boutique'),
                            quantite: qte,
                            prix: prixUnitePourEnvoi(row),
                            prix_lot: prixLotPourEnvoi(row),
                            lot_configure: row.data('lot-configure') == 1 || row.data('lot-configure') === '1',
                            unites_par_lot: row.data('unites-par-lot') || 1,
                            libelle_lot: row.data('libelle-lot') || 'Unité'
                        });
                    }
                } else {
                    commandes.push({
                        id: id,
                        quantite: qte,
                        prix: prixUnitePourEnvoi(row),
                        prix_lot: prixLotPourEnvoi(row),
                        supprimer: qte === 0
                    });
                }
            });
            $.ajax({
                url: baseUrl, type: 'POST', dataType: 'json',
                data: {
                    action: 'update_achat',
                    facture_id: $('#editContent').data('facture-id'),
                    commandes: JSON.stringify(commandes),
                    nouvelles_lignes: JSON.stringify(nouvellesLignes)
                },
                success: function(resp) {
                    if (resp.success) {
                        showToast(resp.message || 'Achat mis à jour, stock ajusté');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast('Erreur : ' + (resp.error || 'Inconnue'), 'error');
                    }
                },
                error: function() {
                    showToast('Erreur de communication', 'error');
                }
            });
        });
    }

    $('#btnRetourListe').click(function() {
        $('#editSection').hide();
        $('#editContent').empty();
        location.reload();
    });
});
</script>
</body>
</html>