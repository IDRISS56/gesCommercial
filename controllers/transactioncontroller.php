<?php
class transaction {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse']);
        include "views/transaction/index.php";
    }
}
?>
