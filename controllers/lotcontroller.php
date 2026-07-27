<?php
class lot
{
    public function gestion()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/lot/index.php";
    }
    public function stock()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/lot/stock.php";
    }
}
