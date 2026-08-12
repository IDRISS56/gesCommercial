<?php
// includes/pwa_head.php
// Fragment commun injecte juste apres <head> sur toutes les pages afin de
// rendre l'application installable en PWA (manifest, icones, service worker).
// Un seul fichier a modifier pour changer le comportement PWA de toute
// l'application.
//
// $_SERVER['PHP_SELF'] correspond toujours au script reellement execute
// (ex : /sutura-group/router.php), quelle que soit la profondeur de l'URL
// "propre" affichee dans le navigateur (ex : /sutura-group/produit/index).
// dirname() donne donc systematiquement le bon dossier racine de l'appli
// (ex : /sutura-group), que le site soit a la racine du domaine ou dans un
// sous-dossier. C'est la meme technique deja utilisee ailleurs dans
// l'application (voir config/menu/*.php pour le logo).
$pwaProtocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && $_SERVER['HTTPS'] !== '') ? 'https://' : 'http://';
$pwaBase = $pwaProtocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
?>
<link rel="manifest" href="<?= $pwaBase ?>/manifest.json">
<meta name="theme-color" content="#5ba140">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Kapanou">
<link rel="apple-touch-icon" href="<?= $pwaBase ?>/assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $pwaBase ?>/assets/icons/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?= $pwaBase ?>/assets/icons/favicon-16.png">
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('<?= $pwaBase ?>/service-worker.js').catch(function (err) {
        console.warn('Echec de l\'enregistrement du service worker :', err);
      });
    });
  }
</script>
