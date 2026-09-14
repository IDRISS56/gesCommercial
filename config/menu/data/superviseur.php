<?php
/**
 * config/menu/data/superviseur.php
 * Données de menu spécifiques au rôle Superviseur.
 * Extrait tel quel (aucune modification) de l'ancien config/menu/superviseur.php.
 */

$dashboardUrl = 'dashboard';

$menuConfigJs = <<<'JS'
[

             {
                 key: 'accueil',
                 title: 'ACCUEIL',
                 icon: 'bi-speedometer2',
                 emoji: '📊',
                 items: [{
                         icon: 'bi-speedometer2',
                         label: 'Tableau de bord',
                         url: '/publics/dashboard'
                     },
                     {
                         icon: 'bi-person-badge',
                         label: 'Mon profil',
                         url: '/utilisateur/profil'
                     }
                 ]
             },

             {
                 key: 'acteur',
                 title: 'ACTEURS',
                 icon: 'bi-people-fill',
                 emoji: '👥',
                 items: [{
                         icon: 'bi-table',
                         label: 'Utilisateurs',
                         url: '/utilisateur/gestion'
                     },
                    {
                         icon: 'bi-speedometer2',
                         label: 'Clients',
                         url: '/contact/client'
                     },
                     {
                         icon: 'bi-person-badge',
                         label: 'Fournisseurs',
                         url: '/contact/fournisseur'
                     }
                 ]
             },
             {
                 key: 'article',
                 title: 'ARTICLES & BOUTIQUES',
                 icon: 'bi-box-seam',
                 emoji: '📦',
                 items: [{
                         icon: 'bi-collection',
                         label: 'Catégories',
                         url: '/categorie/gestion'
                     },

                     {
                         icon: 'bi-bar-chart',
                         label: 'Produits',
                         url: '/produit/gestion'
                     },
                     {
                         icon: 'bi-boxes',
                         label: 'Configuration des lots',
                         url: '/produit/lots'
                     },

                     {
                         icon: 'bi-geo',
                         label: 'Boutiques',
                         url: '/boutique/gestion'
                     },
                     
                     {
                         icon: 'bi-envelope',
                         label: 'Taxes',
                         url: '/taxe/gestion'
                     }
                 ]
             },
             {
                 key: 'Stock',
                 title: 'STOCK',
                 icon: 'bi-box',
                 emoji: '📚',
                 items: [
                    {
                         icon: 'bi-list-ul',
                         label: 'Entree de stock',
                         url: '/produit/stockEntree'
                     },
                    {
                         icon: 'bi-list-ul',
                         label: 'Sortie de stock',
                         url: '/produit/stockSortie'
                     },
                    {
                         icon: 'bi-list-ul',
                         label: 'Transfert du stock',
                         url: '/commande/transfert'
                     },
                     {
                         icon: 'bi-list-ul',
                         label: 'Ajustement du stock',
                         url: '/produit/ajustement'
                     }

                 ]
             },
             {
                 key: 'Vente',
                 title: 'VENTES',
                 icon: 'bi-cart',
                 emoji: '🛒',
                 items: [{
                         icon: 'bi-list-ul',
                         label: 'Vente en Détail',
                         url: '/publics/vente'
                     },


                     {
                         icon: 'bi-list-ul',
                         label: 'Bon de Commande',
                         url: '/commande/vente'
                     },

                    //  {
                    //      icon: 'bi-cash-coin',
                    //      label: 'Avoirs Clients',
                    //      url: '/facture/avoirClient'
                    //  },

                     {
                         icon: 'bi-cash-coin',
                         label: 'Reglement Factures Clients',
                         url: '/facture/reglementClient'
                     },

                     {
                         icon: 'bi-list-ul',
                         label: 'Bon de livraison',
                         url: '/facture/bonLivraison'
                     },
                 ]
             },

             {
                 key: 'achat',
                 title: 'ACHATS',
                 icon: 'bi-box-arrow-in-down',
                 emoji: '🛍️',
                 items: [{
                         icon: 'bi-list-ul',
                         label: 'Enregistrer Facture fournisseur',
                         url: '/commande/achat'
                     },

                     {
                         icon: 'bi-list-ul',
                         label: 'Bon de Commande fournisseur',
                         url: '/commande/suiviAchat'
                     },

                     {
                         icon: 'bi-truck',
                         label: 'Reglement Factures Fournisseurs',
                         url: '/facture/reglementFournisseur'
                     },

                 ]
             },
              
             {
                 key: 'tresorerie',
                 title: 'TRESORERIE',
                 icon: 'bi-graph-up',
                 emoji: '💰',
                 items: [{
                         icon: 'bi-pin-map',
                         label: 'Caisse',
                         url: '/caisse/gestion'
                     },
                     {
                         icon: 'bi-door-open',
                         label: 'Ouverture / Fermeture caisse',
                         url: '/caisse/journee'
                     },
                     
                     {
                         icon: 'bi-eye',
                         label: 'Transactions',
                         url: '/transaction/gestion'
                     }
                 ]
             },

             {
    key: 'Rapport',
    title: 'RAPPORTS',
    icon: 'bi-clock-history',
    emoji: '📈',
    items: [
        
         {
            icon: 'bi-arrow-left-right',
            label: 'Historique des Produits',
            url: '/rapport/historiqueProduit'
         },

          {
            icon: 'bi-arrow-left-right',
            label: 'Mouvement de Stock',
            url: '/rapport/mouvementStock'
         },

        { 
            icon: 'bi-wallet2',
            label: 'Rapport Commercial',
            url: '/rapport/rapportCommercial'
        },

        { 
            icon: 'bi-bar-chart-line',
            label: 'Rapport Financier',
            url: '/rapport/rapportFinancier'
        }

    ]
}
         ]
JS;
