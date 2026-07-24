<?php
class utilisateur
{
    public function gestion()
    {
        include "views/utilisateur/index.php";
    }
    public function login()
    {
        include "views/utilisateur/login.php";
    }
    public function profil()
    {
        include "views/utilisateur/profil.php";
    }
    public function menu()
    {
        // checkAccessConditions();
        include "config/menu.php";
    }
}
