<?php
// profile.php – Profil utilisateur (design vente)
require 'databases/database.php';


$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);


function e($str)
{
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
                if (!password_verify($current_mdp, $user['mdp'])) {
                    $errors[] = 'Mot de passe actuel incorrect.';
                }
            }
            if ($new_mdp !== $confirm_mdp) {
                $errors[] = 'Les nouveaux mots de passe ne correspondent pas.';
            }
            if (strlen($new_mdp) < 6) {
                $errors[] = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
            }
        }

        if (empty($errors)) {
            try {
                $sql = "UPDATE utilisateur SET nom_prenom = ?, telephone = ?, email = ?, ville = ?, adresse = ?";
                $params = [$nom, $telephone, $email, $ville, $adresse];

                if (!empty($new_mdp)) {
                    $hashed = password_hash($new_mdp, PASSWORD_DEFAULT);
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* ===== STYLE DASHBOARD (repris de vente.php) ===== */
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

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg);
            color: var(--dk);
            min-height: 100vh;
            line-height: 1.5;
            padding: 28px 20px;
        }

        .W {
            margin: 0 auto;
        }

        .hdr {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
        }

        .hdr a {
            text-decoration: none;
            color: var(--mt);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color .2s;
        }

        .hdr a:hover {
            color: var(--b);
        }

        .hdr-l h1 {
            font-size: 26px;
            font-weight: 800;
            color: var(--dk);
            letter-spacing: -0.02em;
        }

        .hdr-l p {
            font-size: 13px;
            color: var(--mt);
            margin-top: 2px;
            font-weight: 500;
        }

        .profile-card {
            background: var(--w);
            border: 1px solid var(--brd);
            border-radius: var(--R);
            box-shadow: 0 1px 3px rgba(0, 0, 0, .04);
            padding: 30px;
            animation: fadeUp .4s ease both;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--brd);
        }

        .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--bl);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            color: var(--b);
            flex-shrink: 0;
        }

        .profile-header .info h2 {
            font-size: 22px;
            font-weight: 800;
            color: var(--dk);
            margin: 0;
        }

        .profile-header .info p {
            color: var(--mt);
            margin: 4px 0 0;
            font-weight: 500;
            font-size: 14px;
        }

        .profile-header .actions {
            margin-left: auto;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-go {
            background: var(--b);
            color: #fff;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            cursor: pointer;
            transition: background .15s;
            text-decoration: none;
        }

        .btn-go:hover {
            background: var(--bd);
            color: #fff;
        }

        .btn-go-outline {
            background: transparent;
            color: var(--mt);
            border: 1.5px solid var(--brd);
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-go-outline:hover {
            background: var(--bg);
            border-color: var(--lt);
            color: var(--dk);
        }

        .btn-danger {
            background: var(--dng);
            color: #fff;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            cursor: pointer;
            transition: background .15s;
            text-decoration: none;
        }

        .btn-danger:hover {
            background: #dc2626;
            color: #fff;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .info-item {
            background: var(--bg);
            padding: 14px 18px;
            border-radius: var(--Rs);
            border: 1px solid var(--brd);
        }

        .info-item .label {
            font-size: 11px;
            color: var(--lt);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: .04em;
        }

        .info-item .value {
            font-weight: 600;
            color: var(--dk);
            margin-top: 4px;
            font-size: 15px;
        }

        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .badge-status.on {
            background: var(--sucl);
            color: #059669;
        }

        .badge-status.off {
            background: var(--dngl);
            color: #dc2626;
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

        @media (max-width:700px) {
            body {
                padding: 14px;
            }

            .profile-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .profile-header .actions {
                margin-left: 0;
                width: 100%;
            }

            .profile-header .actions .btn-go,
            .profile-header .actions .btn-go-outline,
            .profile-header .actions .btn-danger {
                flex: 1;
                justify-content: center;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        .modal-content {
            border-radius: var(--R);
            border: none;
        }

        .modal-header {
            background: var(--bg);
            border-bottom: 1px solid var(--brd);
        }

        .modal-footer {
            border-top: 1px solid var(--brd);
            background: var(--bg);
        }

        .form-control:focus {
            border-color: var(--b);
            box-shadow: 0 0 0 3px var(--bl);
        }

        .alert {
            border-radius: var(--Rs);
        }
    </style>
</head>

<body>
    <div class="W">
        <!-- En-tête -->
        <div class="hdr">
            <div class="hdr-l">
                <h1><i class="bi bi-person-circle text-primary"></i> Mon profil</h1>
                <p>Gérez vos informations personnelles et votre sécurité</p>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Carte profil -->
        <div class="profile-card">
            <div class="profile-header">
                <div class="avatar"><?= strtoupper(substr($user['nom_prenom'] ?? 'U', 0, 2)) ?></div>
                <div class="info">
                    <h2><?= e($user['nom_prenom'] ?? 'Inconnu') ?></h2>
                    <p><i class="bi bi-person-badge"></i> <?= e($user['role'] ?? '—') ?> · <?= e($user['login']) ?></p>
                </div>
                <div class="actions">
                    <button class="btn-go" id="editProfileBtn"><i class="bi bi-pencil"></i> Modifier</button>
                    <a hidden href="<?php if(substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].dirname($_SERVER["PHP_SELF"])),-1) =="/"){ echo (substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].dirname($_SERVER["PHP_SELF"])), 0,-1)); }else{ echo ((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].dirname($_SERVER["PHP_SELF"]));} ?>/utilisateur/deconnexion" class="btn-danger"><i class="bi bi-box-arrow-right"></i> Déconnexion</a>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <div class="label">Matricule</div>
                    <div class="value"><?= e($user['matricule'] ?? '—') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Téléphone</div>
                    <div class="value"><?= e($user['telephone'] ?? '—') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Email</div>
                    <div class="value"><?= e($user['email'] ?? '—') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Sexe</div>
                    <div class="value"><?= e($user['sexe'] ?? '—') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Ville</div>
                    <div class="value"><?= e($user['ville'] ?? '—') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Adresse</div>
                    <div class="value"><?= e($user['adresse'] ?? '—') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Boutique</div>
                    <div class="value"><?= e($boutiqueNom) ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Date d'inscription</div>
                    <div class="value"><?= !empty($user['date_saisie']) ? date('d/m/Y', strtotime($user['date_saisie'])) : '—' ?></div>
                </div>
                <div class="info-item" style="grid-column: span 2;">
                    <div class="label">État du compte</div>
                    <div class="value">
                        <span class="badge-status <?= ($user['etat'] ?? 'Inactif') === 'Actif' ? 'on' : 'off' ?>">
                            <?= e($user['etat'] ?? 'Inactif') ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== MODALE D'ÉDITION ===== -->
    <div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i> Modifier mon profil</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="editProfileForm">
                    <input type="hidden" name="action" value="edit_profile">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="modal-body">
                        <!-- Informations personnelles -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-person me-1"></i> Identité</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="edit_nom" class="form-label fw-semibold">Nom complet <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_nom" name="nom_prenom" required value="<?= e($user['nom_prenom']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_telephone" class="form-label fw-semibold">Téléphone</label>
                                <input type="text" class="form-control" id="edit_telephone" name="telephone" value="<?= e($user['telephone']) ?>">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="edit_email" class="form-label fw-semibold">Email</label>
                                <input type="email" class="form-control" id="edit_email" name="email" value="<?= e($user['email']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_ville" class="form-label fw-semibold">Ville</label>
                                <input type="text" class="form-control" id="edit_ville" name="ville" value="<?= e($user['ville']) ?>">
                            </div>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-12">
                                <label for="edit_adresse" class="form-label fw-semibold">Adresse</label>
                                <input type="text" class="form-control" id="edit_adresse" name="adresse" value="<?= e($user['adresse']) ?>">
                            </div>
                        </div>

                        <!-- Changement de mot de passe -->
                        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-shield-lock me-1"></i> Sécurité</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="edit_current_mdp" class="form-label fw-semibold">Mot de passe actuel</label>
                                <input type="password" class="form-control" id="edit_current_mdp" name="current_mdp" placeholder="••••••">
                            </div>
                            <div class="col-md-4">
                                <label for="edit_new_mdp" class="form-label fw-semibold">Nouveau mot de passe</label>
                                <input type="password" class="form-control" id="edit_new_mdp" name="new_mdp" placeholder="••••••">
                                <div class="form-text">Laissez vide si inchangé.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_confirm_mdp" class="form-label fw-semibold">Confirmer</label>
                                <input type="password" class="form-control" id="edit_confirm_mdp" name="confirm_mdp" placeholder="••••••">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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