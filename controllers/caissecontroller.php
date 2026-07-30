<?php
class caisse
{
    // Gestion des caisses (table `caisses`) - réservé aux administrateurs
    public function gestion()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire']);
        include "views/caisse/index.php";
    }

    // Ouverture / fermeture de la caisse du jour (table `journees_caisse`)
    // Accessible à tous les rôles habilités à encaisser
    public function journee()
    {
        requirePermission(['Administrateur', 'Superviseur', 'Proprietaire', 'Caisse', 'Assistant']);
        include "views/caisse/journee.php";
    }
}