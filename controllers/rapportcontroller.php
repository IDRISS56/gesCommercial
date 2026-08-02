<?php
class rapport
{


    public function rapportCommercial()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/rapport_commercial.php";
    }

    public function rapportFinancier()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/rapport_financier.php";
    }

    public function mouvementStock()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/mouvement_stock.php";
    }

}
