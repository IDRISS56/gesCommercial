<?php
class categorie {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/categorie/index.php";
    }
}
?>
