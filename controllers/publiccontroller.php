<?php
class publics
{

    public function dashboard()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/publics/dashboard.php";
    }


    public function vente()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/publics/vente_comptoir.php";
    }
}
