<?php
class commande
{
    public function suiviAchat()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse', 'Vendeur']);
        include "views/commande/suivi_achat.php";
    }
    public function achat()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse']);
        include "views/commande/achat.php";
    }
    public function vente()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse', 'Vendeur']);
        include "views/commande/vente.php";
    }
    public function transfert()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse']);
        include "views/commande/transfert.php";
    }
    public function devis()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Vendeur']);
        include "views/commande/devis.php";
    }
}