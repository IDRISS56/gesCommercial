<?php
// profile.php – Profil utilisateur
require 'databases/database.php';

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

$boutiqueNom = '—';
if (!empty($user['boutique_id'])) {
    $stmtB = $pdo->prepare("SELECT nom_boutique FROM boutique WHERE code_boutique = ?");
    $stmtB->execute([$user['boutique_id']]);
    $boutiqueNom = $stmtB->fetchColumn() ?: $user['boutique_id'];
}

$message = '';
$messageType = '';
$csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

// Gestion de la déconnexion en POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deconnexion') {
    if (!empty($_POST['csrf_token']) && $_POST['csrf_token'] === $csrf_token) {
        session_destroy();
        header('Location: connexion.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_profile') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $message = 'Token de sécurité invalide.';
        $messageType = 'danger';
    } else {
        $nom = trim($_POST['nom_prenom'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $ville = trim($_POST['ville'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $current_mdp = $_POST['current_mdp'] ?? '';
        $new_mdp = $_POST['new_mdp'] ?? '';
        $confirm_mdp = $_POST['confirm_mdp'] ?? '';
        
        $errors = [];
        if (empty($nom)) $errors[] = 'Le nom est requis.';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';
        
        if (!empty($new_mdp)) {
            if (empty($current_mdp)) {
                $errors[] = 'Veuillez saisir votre mot de passe actuel.';
            } else {
                if (!$user['mdp']) {
                    $errors[] = 'Mot de passe actuel incorrect.';
                }
            }
            if ($new_mdp !== $confirm_mdp) {
                $errors[] = 'Les nouveaux mots de passe ne correspondent pas.';
            }
        }
        
        if (empty($errors)) {
            try {
                $sql = "UPDATE utilisateur SET nom_prenom = ?, telephone = ?, email = ?, ville = ?, adresse = ?";
                $params = [$nom, $telephone, $email, $ville, $adresse];
                
                if (!empty($new_mdp)) {
                    $hashed = $new_mdp;
                    $sql .= ", mdp = ?";
                    $params[] = $hashed;
                }
                
                $sql .= " WHERE id = ?";
                $params[] = $user_id;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                $user['nom_prenom'] = $nom;
                $user['telephone'] = $telephone;
                $user['email'] = $email;
                $user['ville'] = $ville;
                $user['adresse'] = $adresse;
                
                $message = 'Profil mis à jour avec succès.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Erreur : ' . $e->getMessage();
                $messageType = 'danger';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'warning';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mon profil</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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
.W { max-width: 1000px; margin: 0 auto; }

/* ===== PROFILE CARD ===== */
.profile-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 30px;
    box-shadow: var(--shadow-sm);
    animation: fadeUp .4s ease both;
    margin-bottom: 24px;
}
.profile-header {
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
    margin-bottom: 30px;
    padding-bottom: 24px;
    border-bottom: 2px solid var(--color-gray-100);
}
.avatar {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--color-primary-soft) 0%, #dbeafe 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    font-weight: 800;
    color: var(--color-primary);
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
}
.profile-header .info h2 {
    font-size: 24px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 4px 0;
}
.profile-header .info p {
    color: var(--text-tertiary);
    margin: 0;
    font-weight: 500;
    font-size: 13px;
}
.profile-header .actions {
    margin-left: auto;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* ===== INFO GRID ===== */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
}
.info-item {
    background: var(--color-gray-50);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 16px;
    transition: var(--transition-base);
}
.info-item:hover {
    border-color: var(--color-primary);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.info-item .label {
    font-size: 10px;
    color: var(--text-tertiary);
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.info-item .label i {
    font-size: 12px;
    color: var(--color-primary);
}
.info-item .value {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 15px;
    word-break: break-word;
}

/* ===== BADGES ===== */
.badge-chic {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.06);
}
.badge-chic.success {
    background: var(--color-success-soft);
    color: #065f46;
}
.badge-chic.danger {
    background: var(--color-danger-soft);
    color: #991b1b;
}
.badge-chic .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* ===== BUTTONS ===== */
.btn-chic {
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: none;
    cursor: pointer;
    transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    letter-spacing: -0.01em;
    text-decoration: none;
}
.btn-chic::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(255,255,255,0.3);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width .4s, height .4s;
}
.btn-chic:hover::before {
    width: 300px;
    height: 300px;
}
.btn-chic i {
    font-size: 15px;
    position: relative;
    z-index: 1;
}
.btn-chic span {
    position: relative;
    z-index: 1;
}
.btn-chic-primary {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
    color: #fff;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}
.btn-chic-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
}
.btn-chic-danger {
    background: linear-gradient(135deg, var(--color-danger) 0%, #dc2626 100%);
    color: #fff;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}
.btn-chic-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

/* ===== MODAL CHIC ===== */
.modal-chic .modal-content {
    border: none;
    border-radius: 20px;
    box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15);
    overflow: hidden;
    animation: modalSlideIn .4s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(30px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.modal-chic .modal-header {
    background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%);
    color: #fff;
    border: none;
    padding: 22px 28px;
    position: relative;
    overflow: hidden;
}
.modal-chic .modal-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    border-radius: 50%;
}
.modal-chic .modal-title {
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
}
.modal-chic .modal-title i {
    font-size: 22px;
    background: rgba(255,255,255,0.15);
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(10px);
}
.modal-chic .btn-close {
    filter: invert(1);
    opacity: 0.7;
    position: relative;
    z-index: 1;
    transition: all .2s;
}
.modal-chic .btn-close:hover {
    opacity: 1;
    transform: rotate(90deg);
}
.modal-chic .modal-body {
    padding: 28px;
    max-height: 70vh;
    overflow-y: auto;
    background: #f8fafc;
}
.modal-chic .modal-footer {
    background: #fff;
    border-top: 1px solid var(--border-color);
    padding: 18px 28px;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* ===== FORM ===== */
.form-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.form-control, .form-select {
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    padding: 10px 14px;
    font-size: 13px;
    transition: all .2s;
}
.form-control:focus, .form-select:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}

/* ===== ALERTS ===== */
.alert {
    border-radius: var(--radius-sm);
    border: none;
    padding: 16px 20px;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-success {
    background: var(--color-success-soft);
    color: #065f46;
}
.alert-danger {
    background: var(--color-danger-soft);
    color: #991b1b;
}
.alert-warning {
    background: var(--color-warning-soft);
    color: #92400e;
}

/* ===== ANIMATIONS ===== */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 700px) {
    .profile-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .profile-header .actions {
        margin-left: 0;
        width: 100%;
    }
    .profile-header .actions .btn-chic {
        flex: 1;
        justify-content: center;
    }
    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="W">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-person-circle text-primary me-2"></i>Mon Profil</h1>
            <p class="text-muted small mb-0">Gérez vos informations personnelles et votre sécurité</p>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : ($messageType === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?>"></i>
        <div><?= $message ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Carte de profil -->
    <div class="profile-card">
        <div class="profile-header">
            <div class="avatar">
                <?= strtoupper(substr($user['nom_prenom'] ?? 'U', 0, 1)) ?>
            </div>
            <div class="info flex-grow-1">
                <h2><?= e($user['nom_prenom'] ?? 'Utilisateur') ?></h2>
                <p><?= e($user['role'] ?? '—') ?> · <?= e($user['login']) ?></p>
            </div>
            <div class="actions">
                <button class="btn-chic btn-chic-primary" id="editProfileBtn">
                    <i class="bi bi-pencil-square"></i>
                    <span>Modifier</span>
                </button>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="deconnexion">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <button type="submit" class="btn-chic btn-chic-danger" formtarget="_top">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Déconnexion</span>
                    </button>
                </form>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <div class="label"><i class="bi bi-hash"></i> Matricule</div>
                <div class="value"><?= e($user['matricule'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="bi bi-telephone"></i> Téléphone</div>
                <div class="value"><?= e($user['telephone'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="bi bi-envelope"></i> Email</div>
                <div class="value"><?= e($user['email'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="bi bi-gender-ambiguous"></i> Sexe</div>
                <div class="value"><?= e($user['sexe'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="bi bi-geo-alt"></i> Ville</div>
                <div class="value"><?= e($user['ville'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="bi bi-house"></i> Adresse</div>
                <div class="value"><?= e($user['adresse'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="bi bi-shop"></i> Boutique</div>
                <div class="value"><?= e($boutiqueNom) ?></div>
            </div>
            <div class="info-item">
                <div class="label"><i class="bi bi-calendar-check"></i> Date d'inscription</div>
                <div class="value"><?= !empty($user['date_saisie']) ? date('d/m/Y', strtotime($user['date_saisie'])) : '—' ?></div>
            </div>
            <div class="info-item" style="grid-column: span 2;">
                <div class="label"><i class="bi bi-shield-check"></i> État du compte</div>
                <div class="value">
                    <span class="badge-chic <?= ($user['etat'] ?? 'Inactif') === 'Actif' ? 'success' : 'danger' ?>">
                        <span class="dot"></span>
                        <?= e($user['etat'] ?? 'Inactif') ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal d'édition -->
<div class="modal fade modal-chic" id="editProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square"></i>
                    <span>Modifier mon profil</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="editProfileForm">
                <input type="hidden" name="action" value="edit_profile">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body">
                    <!-- Informations personnelles -->
                    <div class="mb-4">
                        <h6 class="text-uppercase text-muted small fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-primary);display:flex;align-items:center;gap:8px;">
                            <i class="bi bi-person-fill"></i> Identité
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="edit_nom" class="form-label">Nom complet <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_nom" name="nom_prenom" required value="<?= e($user['nom_prenom']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_telephone" class="form-label">Téléphone</label>
                                <input type="text" class="form-control" id="edit_telephone" name="telephone" value="<?= e($user['telephone']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="edit_email" name="email" value="<?= e($user['email']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_ville" class="form-label">Ville</label>
                                <input type="text" class="form-control" id="edit_ville" name="ville" value="<?= e($user['ville']) ?>">
                            </div>
                            <div class="col-12">
                                <label for="edit_adresse" class="form-label">Adresse</label>
                                <textarea class="form-control" id="edit_adresse" name="adresse" rows="2"><?= e($user['adresse']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Sécurité -->
                    <div>
                        <h6 class="text-uppercase text-muted small fw-bold mb-3" style="font-size:11px;letter-spacing:0.8px;color:var(--color-danger);display:flex;align-items:center;gap:8px;">
                            <i class="bi bi-shield-lock-fill"></i> Sécurité
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="edit_current_mdp" class="form-label">Mot de passe actuel</label>
                                <input type="password" class="form-control" id="edit_current_mdp" name="current_mdp" placeholder="••••••">
                            </div>
                            <div class="col-md-4">
                                <label for="edit_new_mdp" class="form-label">Nouveau mot de passe</label>
                                <input type="password" class="form-control" id="edit_new_mdp" name="new_mdp" placeholder="••••••">
                                <div class="form-text" style="font-size:11px;color:var(--text-tertiary);margin-top:4px;">Laissez vide si inchangé.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_confirm_mdp" class="form-label">Confirmer</label>
                                <input type="password" class="form-control" id="edit_confirm_mdp" name="confirm_mdp" placeholder="••••••">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-chic" style="background:var(--color-gray-100);color:var(--text-secondary);" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i>
                        <span>Annuler</span>
                    </button>
                    <button type="submit" class="btn-chic btn-chic-primary">
                        <i class="bi bi-check-lg"></i>
                        <span>Enregistrer</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    // Ouvrir la modale d'édition
    $('#editProfileBtn').on('click', function() {
        new bootstrap.Modal(document.getElementById('editProfileModal')).show();
    });

    // Auto-fermeture des alertes
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);

    // Si des erreurs sont présentes, rouvrir la modale automatiquement
    <?php if (!empty($message) && $messageType !== 'success'): ?>
    $(function() {
        new bootstrap.Modal(document.getElementById('editProfileModal')).show();
    });
    <?php endif; ?>
});
</script>
</body>
</html>