<?php
class depense {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/depense/index.php";
    }
}
?>
