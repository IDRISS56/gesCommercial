<?php
class commande
{
    public function gestion()
    {
        include "views/commande/index.php";
    }
    public function achat()
    {
        include "views/commande/achat.php";
    }
    public function vente()
    {
        include "views/commande/vente.php";
    }
    public function transfert()
    {
        include "views/commande/transfert.php";
    }
}
