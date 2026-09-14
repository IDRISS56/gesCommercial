<?php
/**
 * config/menu.php
 *
 * Avant : 5 fichiers quasi identiques de ~2500 lignes chacun
 * (config/menu/administrateur.php, superviseur.php, proprietaire.php,
 * caisse.php, vendeur.php) — la totalité de la page (CSS, structure,
 * JS) était dupliquée dans chacun, seule la liste des éléments de menu
 * changeait réellement d'un rôle à l'autre. Toute modification du
 * gabarit (style, comportement JS...) devait être répétée 5 fois, avec
 * un risque de désynchronisation.
 *
 * Maintenant : un seul gabarit (config/menu/_shell.php) partagé par
 * tous les rôles, et un petit fichier de données par rôle
 * (config/menu/data/<role>.php) qui ne contient plus que ce qui
 * différait réellement : la liste des sections de menu et l'URL du
 * tableau de bord.
 */

$rolesVersFichierDonnees = [
    'Administrateur' => 'administrateur',
    'Superviseur'    => 'superviseur',
    'Proprietaire'   => 'proprietaire',
    'Caisse'         => 'caisse',
    'Vendeur'        => 'vendeur',
];

$role = $_SESSION['role'] ?? '';

if (empty($role) || !isset($rolesVersFichierDonnees[$role])) {
    // Rôle absent ou inconnu : comportement identique à l'ancien menu.php
    // (on coupe la session et on renvoie vers la connexion).
    if (!empty($role)) {
        session_destroy();
    }
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $base_url = $protocol . $_SERVER['HTTP_HOST'] . (dirname($_SERVER['PHP_SELF']) === '/' ? '' : rtrim(dirname($_SERVER['PHP_SELF']), '/\\'));
    ?>
    <script type='text/javascript'>document.location.replace('<?= $base_url ?>/utilisateur/deconnexion');</script>
    <?php
    exit();
}

// Fournit $dashboardUrl et $menuConfigJs, consommés par _shell.php.
require __DIR__ . '/menu/data/' . $rolesVersFichierDonnees[$role] . '.php';
require __DIR__ . '/menu/_shell.php';
