<?php
class publics
{

    public function dashboard()
    {
        requirePermission(['Administrateur', 'Superviseur']);
        include "views/publics/dashboard.php";
    }

    public function proprietaireDashboard()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire']);
        include "views/publics/dashboard_proprietaire.php";
    }

     public function caisseDashboard()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Caisse']);
        include "views/publics/dashboard_caisse.php";
    }

    public function vendeurDashboard()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Vendeur']);
        include "views/publics/dashboard_vendeur.php";
    }


    public function vente()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Vendeur', 'Caisse']);
        include "views/publics/vente_comptoir.php";
    }
}