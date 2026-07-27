<?php
session_start();
require 'config/authentification.php';
class utilisateur
{
    public function gestion()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/utilisateur/index.php";
    }
    public function login()
    {
        include "views/utilisateur/login.php";
    }
    public function profil()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/utilisateur/profil.php";
    }
    public function deconnexion()
    {
        include "views/utilisateur/logout.php";
    }
    public function menu()
    {
        
        if (!isset($_SESSION['login']) and !isset($_SESSION['mdp'])) {

            ?>
            <script type='text/javascript'>document.location.replace('<?php if(substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].dirname($_SERVER["PHP_SELF"])),-1) =="/"){ echo (substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].dirname($_SERVER["PHP_SELF"])), 0,-1)); }else{ echo ((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].dirname($_SERVER["PHP_SELF"]));} ?>/utilisateur/deconnexion');</script>
            <?php
        }
        include "config/menu.php";
    }
}
