<?php
class taxe {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/taxe/index.php";
    }
}
?>
