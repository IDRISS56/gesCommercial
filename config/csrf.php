<?php
/**
 * config/csrf.php
 *
 * Helper CSRF centralisé. La plupart des vues du projet réimplémentent déjà
 * ce pattern individuellement (génération + vérification directes) ; ce
 * fichier sert de version commune pour les pages qui n'avaient encore
 * aucune protection, et pourra à terme remplacer les copies dispersées.
 */

if (!function_exists('csrfToken')) {
    function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrfChampCache')) {
    // Pratique pour les vues : imprime directement le <input type="hidden"> du token.
    function csrfChampCache(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('verifierCsrfToken')) {
    /**
     * À appeler en tout début de traitement d'un POST. Coupe la requête
     * (403) si le token est absent ou ne correspond pas.
     */
    function verifierCsrfToken(): void
    {
        $recu = $_POST['csrf_token'] ?? '';
        $attendu = $_SESSION['csrf_token'] ?? '';
        if (empty($recu) || empty($attendu) || !hash_equals($attendu, $recu)) {
            http_response_code(403);
            die('Requête invalide (jeton de sécurité manquant ou expiré). Merci de recharger la page et réessayer.');
        }
    }
}
