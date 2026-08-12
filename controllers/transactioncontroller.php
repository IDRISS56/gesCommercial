<?php
class transaction {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur','Caisse']);
        include "views/transaction/index.php";
    }
}
?>
