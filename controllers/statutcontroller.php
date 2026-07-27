<?php
class statut {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/statut/index.php";
    }
}
?>
