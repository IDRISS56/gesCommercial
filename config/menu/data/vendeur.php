<?php
/**
 * config/menu/data/vendeur.php
 * Données de menu spécifiques au rôle Vendeur.
 * Extrait tel quel (aucune modification) de l'ancien config/menu/vendeur.php.
 */

$dashboardUrl = 'vendeurDashboard';

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
                         url: '/publics/vendeurDashboard'
                     },
                     {
                         icon: 'bi-person-badge',
                         label: 'Mon profil',
                         url: '/utilisateur/profil'
                     },
                    {
                        icon: 'bi-book',
                        label: 'Guide d\'utilisation',
                        url: '/assets/guide/guide.html?role=vendeur'  
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
                         label: 'Bon de commande',
                         url: '/commande/vente'
                     },

                    //  {
                    //      icon: 'bi-cash-coin',
                    //      label: 'Reglement Factures Clients',
                    //      url: '/facture/reglementClient'
                    //  },

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
                         label: 'Bon de commande fournisseur',
                         url: '/commande/suiviAchat'
                     },

                    //  {
                    //      icon: 'bi-truck',
                    //      label: 'Reglement Factures Fournisseurs',
                    //      url: '/facture/reglementFournisseur'
                    //  },

                 ]
             },
              
            //  {
            //      key: 'tresorerie',
            //      title: 'TRESORERIE',
            //      icon: 'bi-graph-up',
            //      emoji: '💰',
            //      items: [{
            //              icon: 'bi-pin-map',
            //              label: 'Caisse',
            //              url: '/caisse/gestion'
            //          },
            //          {
            //              icon: 'bi-door-open',
            //              label: 'Ouverture / Fermeture caisse',
            //              url: '/caisse/journee'
            //          },
                     
            //          {
            //              icon: 'bi-eye',
            //              label: 'Transactions',
            //              url: '/transaction/gestion'
            //          }
            //      ]
            //  },

         ]
JS;
