<?php
class depense
{
    // Enregistrement et suivi des dépenses de caisse (sans fournisseur,
    // motif obligatoire). Voir databases/migration_depense.sql.
    public function gestion()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse']);
        include "views/depense/index.php";
    }
}
