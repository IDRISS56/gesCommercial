<?php
// Fichier: config/authentification.php

function checkAccessConditions() {
    // Démarrer la session si elle n'est pas déjà active
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 1. Vérifier si c'est une requête AJAX
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    // 2. Détecter si la page est chargée dans un iframe
    $isInIframe = (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe')
                  || isset($_GET['in_app']);

    // 3. Redirection SEULEMENT si ce n'est PAS un iframe ET PAS une requête AJAX
    if (!$isInIframe && !$isAjax) {
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
                  . $_SERVER['HTTP_HOST']
                  . (dirname($_SERVER['PHP_SELF']) === '/' ? '' : rtrim(dirname($_SERVER['PHP_SELF']), '/\\'));
                  
        header("Location: " . $base_url . "/utilisateur/connexion");
        exit;
    }

    // 4. Vérifier si l'utilisateur est connecté
    if (!isset($_SESSION['email']) || !isset($_SESSION['mdp'])) {
        session_destroy();
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
                  . $_SERVER['HTTP_HOST']
                  . (dirname($_SERVER['PHP_SELF']) === '/' ? '' : rtrim(dirname($_SERVER['PHP_SELF']), '/\\'));
        
        header("Location: " . $base_url . "/utilisateur/connexion");
        exit;
    }

    return true;
}
?>