<?php
class boutique {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire']);
        include "views/boutique/index.php";
    }
}
?>
