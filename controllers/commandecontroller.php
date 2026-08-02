<?php
class commande
{
    public function suiviAchat()
    {
        requirePermission(['Administrateur', 'Superviseur']);
        include "views/commande/suivi_achat.php";
    }
    public function achat()
    {
        requirePermission(['Administrateur', 'Superviseur']);
        include "views/commande/achat.php";
    }
    public function vente()
    {
        requirePermission(['Administrateur', 'Superviseur','Caisse', 'Vendeur']);
        include "views/commande/vente.php";
    }
    public function transfert()
    {
        requirePermission(['Administrateur', 'Superviseur']);
        include "views/commande/transfert.php";
    }
}
