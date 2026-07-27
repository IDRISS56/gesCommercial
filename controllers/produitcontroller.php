<?php
class produit
{
    public function gestion()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/produit/index.php";
    }

    public function stockEntree()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/produit/entree_stock.php";
    }

    public function stockSortie()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/produit/sortie_stock.php";
    }

    public function ajustement()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/produit/ajustement.php";
    }

    public function stockDisponible()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/produit/stock_disponible.php";
    }

     public function stockRupture()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/produit/stock_rupture.php";
    }

     public function stockAlerte()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/produit/stock_alerte.php";
    }


}
