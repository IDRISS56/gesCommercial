<?php
class facture {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/facture/facture_client.php";
    }

    public function reglement() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/facture/reglement_facture.php";
    }
}
?>
