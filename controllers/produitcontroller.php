<?php
class produit
{
    public function gestion()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse']);
        include "views/produit/index.php";
    }

    public function stockEntree()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse']);
        include "views/produit/entree_stock.php";
    }

    public function stockSortie()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse']);
        include "views/produit/sortie_stock.php";
    }

    public function ajustement()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse']);
        include "views/produit/ajustement.php";
    }

    public function lots()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire']);
        include "views/produit/lots.php";
    }

    // Tranches de prix de vente par quantité (dégressif), activables produit par produit
    public function tranches()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire']);
        include "views/produit/tranches.php";
    }
}