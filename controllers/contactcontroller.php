<?php
class contact {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/contact/index.php";
    }

    public function client() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/contact/clients.php";
    }

    public function fournisseur() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/contact/fournisseur.php";
    }
}
?>
