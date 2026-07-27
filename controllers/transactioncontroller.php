<?php
class transaction {
    public function gestion() {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/transaction/index.php";
    }
}
?>
