<?php
/**
 * logout.php – Déconnexion de l'utilisateur
 * Détruit la session et redirige vers la page de connexion.
 */

// Démarrer la session si elle ne l'est pas déjà
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vider toutes les variables de session
$_SESSION = [];

// Si un cookie de session est utilisé, le supprimer
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Détruire la session
session_destroy();

// Rediriger vers la page de connexion
header('Location: login');
exit;