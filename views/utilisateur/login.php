<?php
// login.php – Page de connexion
require_once 'databases/database.php';

session_start();

// Si l'utilisateur est déjà connecté, rediriger vers le dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: utilisateur/menu');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['mdp'] ?? '');

    if (empty($login) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        // Vérifier les identifiants (le mot de passe est en clair dans la base, mais on peut utiliser password_verify si haché)
        // Ici, on suppose que le mot de passe est stocké en clair (à améliorer)
        $stmt = $pdo->prepare("SELECT id, nom_prenom, login, mdp, role, boutique_id, etat FROM utilisateur WHERE login = ? AND etat = 'Actif'");
        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['mdp'] === $password) {
            // Connexion réussie
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nom_prenom'] = $user['nom_prenom'];
            $_SESSION['login'] = $user['login'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['boutique_id'] = $user['boutique_id'];
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Identifiants incorrects ou compte inactif.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 40px 36px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }

        .login-card h1 {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
            letter-spacing: -0.02em;
        }

        .login-card p.sub {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 28px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 6px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            background: #f8fafc;
            transition: all 0.2s;
        }

        .form-group input:focus {
            border-color: #4f46e5;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.08);
            outline: none;
        }

        .btn-primary {
            width: 100%;
            background: #4f46e5;
            color: #fff;
            border: none;
            padding: 14px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
            cursor: pointer;
        }

        .btn-primary:hover {
            background: #3730a3;
        }

        .error {
            background: #fee2e2;
            color: #b91c1c;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
            border: 1px solid #fecaca;
        }

        .footer-links {
            margin-top: 20px;
            text-align: center;
            font-size: 13px;
            color: #64748b;
        }

        .footer-links a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 600;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        .input-icon {
            position: relative;
        }

        .input-icon i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 18px;
        }

        .input-icon input {
            padding-left: 42px;
        }
    </style>
</head>

<body>
    <div class="login-card">
        <h1>Bienvenue</h1>
        <p class="sub">Connectez-vous à votre espace de gestion</p>

        <?php if ($error): ?>
            <div class="error"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label for="login">Identifiant</label>
                <div class="input-icon">
                    <i class="bi bi-person"></i>
                    <input type="text" id="login" name="login" placeholder="Entrez votre login" value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" required autofocus>
                </div>
            </div>
            <div class="form-group">
                <label for="mdp">Mot de passe</label>
                <div class="input-icon">
                    <i class="bi bi-lock"></i>
                    <input type="password" id="mdp" name="mdp" placeholder="••••••••" required>
                </div>
            </div>
            <button type="submit" class="btn-primary"><i class="bi bi-box-arrow-in-right"></i> Se connecter</button>
        </form>
        <div class="footer-links">
            <a href="#">Mot de passe oublié ?</a> · <a href="#">Aide</a>
        </div>
    </div>
</body>

</html>