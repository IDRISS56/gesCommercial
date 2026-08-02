<?php
class publics
{

    public function dashboard()
    {
        requirePermission(['Administrateur', 'Superviseur']);
        include "views/publics/dashboard.php";
    }


    public function vente()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Vendeur', 'Caisse']);
        include "views/publics/vente_comptoir.php";
    }
}
