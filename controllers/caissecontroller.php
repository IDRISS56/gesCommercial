<?php
class caisse {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/caisse/index.php";
    }
}
?>
