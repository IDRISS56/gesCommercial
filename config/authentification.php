<?php
require 'database/database.php';

// ----------------------------------------------------
// Vérification des rôles
// ----------------------------------------------------
if (!function_exists('userHasPermission')) {
    function userHasPermission($rolesAutorises) {
        if (!isset($_SESSION['role'])) return false;
        if (!is_array($rolesAutorises)) $rolesAutorises = [$rolesAutorises];
        return in_array($_SESSION['role'], $rolesAutorises);
    }
}

// ----------------------------------------------------
// Vérification des conditions d'accès
// ----------------------------------------------------
if (!function_exists('checkAccessConditions')) {
    function checkAccessConditions() {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        $isInIframe = (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe') || isset($_GET['in_app']);

        if (!$isInIframe && !$isAjax) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
            $base_url = $protocol . $_SERVER['HTTP_HOST'] . (dirname($_SERVER['PHP_SELF']) === '/' ? '' : rtrim(dirname($_SERVER['PHP_SELF']), '/\\'));
            header("Location: " . $base_url . "/utilisateur/login");
            exit;
        }

        if (!isset($_SESSION['login']) || !isset($_SESSION['mdp']) || !isset($_SESSION['role'])) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
            $base_url = $protocol . $_SERVER['HTTP_HOST'] . (dirname($_SERVER['PHP_SELF']) === '/' ? '' : rtrim(dirname($_SERVER['PHP_SELF']), '/\\'));
            header("Location: " . $base_url . "/utilisateur/deconnexion");
            exit;
        }
    }
}

// ----------------------------------------------------
// Exiger un ou plusieurs rôles
// ----------------------------------------------------
if (!function_exists('requirePermission')) {
    function requirePermission($rolesAutorises) {
        checkAccessConditions();
        
        if (!userHasPermission($rolesAutorises)) {
            echo '<!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Accès Refusé</title>
                <style>
                    body {
                        margin: 0;
                        padding: 0;
                        font-family: "Segoe UI", system-ui, sans-serif;
                        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                        height: 100vh;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .access-denied {
                        text-align: center;
                        background: white;
                        padding: 50px 40px;
                        border-radius: 16px;
                        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                        max-width: 420px;
                        width: 90%;
                    }
                    .icon {
                        font-size: 4.5rem;
                        margin-bottom: 20px;
                    }
                    h1 {
                        color: #e74c3c;
                        margin: 0 0 15px 0;
                        font-size: 1.8rem;
                    }
                    p {
                        color: #555;
                        font-size: 1.05rem;
                        margin-bottom: 30px;
                        line-height: 1.5;
                    }
                    .btn {
                        display: inline-block;
                        background: #3498db;
                        color: white;
                        padding: 12px 28px;
                        border-radius: 8px;
                        text-decoration: none;
                        font-weight: 600;
                        transition: all 0.3s ease;
                    }
                    .btn:hover {
                        background: #2980b9;
                        transform: translateY(-2px);
                        box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
                    }
                </style>
            </head>
            <body>
                <div class="access-denied">
                    <div class="icon">🚫</div>
                    <h1>Accès refusé</h1>
                    <p>Vous n\'avez pas les permissions nécessaires pour accéder à cette page.</p>
                    <a href="javascript:history.back()" class="btn">← Retour à la page précédente</a>
                </div>
            </body>
            </html>';
            exit;
        }
    }
}

// ----------------------------------------------------
// Vérifier un rôle sans bloquer
// ----------------------------------------------------
if (!function_exists('hasPermission')) {
    function hasPermission($rolesAutorises) {
        checkAccessConditions();
        return userHasPermission($rolesAutorises);
    }
}
?>