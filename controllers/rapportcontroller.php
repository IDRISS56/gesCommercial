<?php
class rapport
{


    public function chiffreAffaire()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/chiffre_affaires.php";
    }

    public function compteTresorerie()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/compte_tresorerie.php";
    }

    public function factureClient()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/facture_clients.php";
    }

    public function factureFournisseur()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/facture_fournisseur.php";
    }

    public function margeBeneficiaire()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/marge_beneficiaire.php";
    }

    public function mouvementStock()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/mouvement_stock.php";
    }

    public function performanceVendeur()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/performance_vendeur.php";
    }

    public function rentabiliteVente()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/rentabilite_ventes.php";
    }

    public function resumeAchat()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/resume_achats.php";
    }

    public function resumeVente()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/resume_ventes.php";
    }

    public function situationClient()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/situation_clients.php";
    }

    public function transactionClient()
    {
        requirePermission(['Administrateur', 'Superviseur','Proprietaire']);
        include "views/rapport/transaction_clients.php";
    }

}
