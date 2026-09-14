<?php
/**
 * config/environment.php
 *
 * Bootstrap d'environnement — à inclure en tout premier, avant tout autre
 * fichier, sur CHAQUE requête (voir router.php).
 *
 * Par défaut, l'application est considérée en PRODUCTION : les erreurs PHP
 * ne sont jamais affichées à l'écran (ce qui exposerait requêtes SQL,
 * chemins serveur, etc. à un visiteur), mais elles sont toutes journalisées
 * dans un fichier de log dédié pour rester exploitables par un développeur.
 *
 * Pour travailler en local avec l'affichage des erreurs à l'écran, créer un
 * fichier `config/environment.local.php` (non versionné, à ajouter au
 * .gitignore) contenant simplement :
 *
 *     <?php
 *     define('APP_ENV', 'development');
 *
 * Ce fichier local, s'il existe, est chargé avant que APP_ENV soit fixé ici,
 * donc son define() est prioritaire (define() ne peut pas être redéfini).
 */

$localEnvFile = __DIR__ . '/environment.local.php';
if (file_exists($localEnvFile)) {
    require $localEnvFile;
}

if (!defined('APP_ENV')) {
    define('APP_ENV', 'production');
}

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    // @ : le dossier a pu être créé entre-temps par une requête concurrente
    @mkdir($logDir, 0775, true);
}

ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/php-error.log');

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

/**
 * Filet de sécurité : si une exception non interceptée remonte jusqu'ici
 * (bug applicatif, requête SQL en échec, etc.), on ne veut ni écran blanc
 * ni trace technique affichée à l'utilisateur — seulement un message
 * générique, pendant que le détail part dans les logs.
 */
set_exception_handler(function (Throwable $e) {
    error_log('[EXCEPTION NON INTERCEPTÉE] ' . $e->getMessage() . ' dans ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());

    if (APP_ENV === 'production') {
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><title>Erreur</title></head>"
           . "<body style='font-family:sans-serif;text-align:center;padding:60px 20px;color:#334155;'>"
           . "<h1 style='color:#e74c3c;'>Une erreur est survenue</h1>"
           . "<p>L'équipe technique a été notifiée. Merci de réessayer dans quelques instants.</p>"
           . "</body></html>";
    } else {
        // En développement, on garde l'affichage complet pour déboguer vite.
        echo '<pre>' . htmlspecialchars((string) $e) . '</pre>';
    }
});
