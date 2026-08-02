<?php
class facture
{
    // Suivi et enregistrement des règlements des factures CLIENTS
    public function reglementClient()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Vendeur', 'Caisse']);
        include "views/facture/reglement_client.php";
    }

    // Suivi et enregistrement des règlements des factures FOURNISSEURS
    public function reglementFournisseur()
    {
        requirePermission(['Administrateur', 'Superviseur',  'Caisse']);
        include "views/facture/reglement_fournisseur.php";
    }
     // Suivi et enregistrement des règlements des factures FOURNISSEURS
    public function bonLivraison()
    {
        requirePermission(['Administrateur', 'Superviseur',  'Caisse', 'Vendeur']);
        include "views/facture/bon_livraison.php";
    }
}