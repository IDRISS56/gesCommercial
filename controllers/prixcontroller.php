<?php
class prix
{
    
    public function gestion()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/prix/index.php";
    }
}
