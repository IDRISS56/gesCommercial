-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : mer. 30 sep. 2026 à 13:48
-- Version du serveur : 8.4.7
-- Version de PHP : 8.4.15

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `gescommercial`
--

DELIMITER $$
--
-- Procédures
--
DROP PROCEDURE IF EXISTS `vider_toutes_tables`$$
CREATE DEFINER=`u738064605_sutura`@`127.0.0.1` PROCEDURE `vider_toutes_tables` ()   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE tableName VARCHAR(255);
    
    -- Curseur sur toutes les tables de la base courante (exclut les vues)
    DECLARE cur CURSOR FOR 
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
          AND table_type = 'BASE TABLE';
          
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO tableName;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Construction et exécution dynamique de TRUNCATE TABLE
        SET @sql = CONCAT('TRUNCATE TABLE `', tableName, '`');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP;

    CLOSE cur;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `acces_boutique_supplementaire`
--

DROP TABLE IF EXISTS `acces_boutique_supplementaire`;
CREATE TABLE IF NOT EXISTS `acces_boutique_supplementaire` (
  `id` int NOT NULL AUTO_INCREMENT,
  `boutique_origine` varchar(100) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'code_boutique de l affectation de l utilisateur',
  `boutique_cible` varchar(100) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'code_boutique auquel il a aussi accès',
  `etat` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Actif',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_origine_cible` (`boutique_origine`,`boutique_cible`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Structure de la table `bon_livraison`
--

DROP TABLE IF EXISTS `bon_livraison`;
CREATE TABLE IF NOT EXISTS `bon_livraison` (
  `code_bon` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `date_livraison` date NOT NULL,
  `facture_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `adresse_livraison` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transporteur` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('En préparation','Expédié','Livré','Annulé') COLLATE utf8mb4_general_ci DEFAULT 'En préparation',
  `commentaire` text COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`code_bon`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `boutique`
--

DROP TABLE IF EXISTS `boutique`;
CREATE TABLE IF NOT EXISTS `boutique` (
  `code_boutique` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `nom_boutique` varchar(300) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone_boutique` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_boutique` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pays_boutique` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ville_boutique` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `quartier_boutique` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `adresse_boutique` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `latitude` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `longitude` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `logo` longblob,
  `type_logo` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_boutique` enum('Actif','Inactif') COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`code_boutique`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Structure de la table `boutique_categorie_autorisee`
--

DROP TABLE IF EXISTS `boutique_categorie_autorisee`;
CREATE TABLE IF NOT EXISTS `boutique_categorie_autorisee` (
  `boutique_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `categorie_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`boutique_id`,`categorie_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Structure de la table `caisse`
--

DROP TABLE IF EXISTS `caisse`;
CREATE TABLE IF NOT EXISTS `caisse` (
  `caisse_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `nom_caisse` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `solde` decimal(15,2) DEFAULT '0.00',
  `boutique_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('Actif','Inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Actif',
  PRIMARY KEY (`caisse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `categorie`
--

DROP TABLE IF EXISTS `categorie`;
CREATE TABLE IF NOT EXISTS `categorie` (
  `code_categorie` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `titre_categorie` varchar(300) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `photo` longblob,
  `type` varchar(300) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_categorie` enum('ACTIF','INACTIF') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'ACTIF',
  PRIMARY KEY (`code_categorie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Structure de la table `commande`
--

DROP TABLE IF EXISTS `commande`;
CREATE TABLE IF NOT EXISTS `commande` (
  `numero_commande` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `produit_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lot_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Référence vers lot.code_lot utilisé pour cette ligne, si applicable',
  `produits_par_lot` int NOT NULL DEFAULT '1' COMMENT 'Nombre de produits par lot au moment de la vente (copié depuis lot.unites_par_lot)',
  `contact_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `facture_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Lien vers statut (006=entrée, 007=sortie)',
  `date_commande` date DEFAULT NULL,
  `heure_commande` time DEFAULT NULL,
  `prix_achat` decimal(10,2) DEFAULT NULL COMMENT 'Prix d''achat unitaire (coût)',
  `prix_commande` decimal(10,2) DEFAULT NULL COMMENT 'Prix de vente unitaire (ou prix d''achat)',
  `prix_lot_ligne` decimal(10,2) DEFAULT NULL COMMENT 'Prix du lot (carton/palette...) tel que saisi, si cette ligne a été vendue/achetée par lot. NULL = vente/achat à l''unité.',
  `quantite_commande` int DEFAULT '0' COMMENT 'Quantité dans l''unité choisie',
  `montant_commande` decimal(10,2) DEFAULT NULL,
  `utilisateur_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `boutique_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_commande` enum('EN ATTENTE','VALIDEE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'EN ATTENTE',
  PRIMARY KEY (`numero_commande`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `contact`
--

DROP TABLE IF EXISTS `contact`;
CREATE TABLE IF NOT EXISTS `contact` (
  `code_contact` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `nom_prenom_contact` varchar(300) COLLATE utf8mb4_general_ci NOT NULL,
  `telephone_contact` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `email_contact` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `type_contact` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `statut_contact` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `solde_contact` decimal(10,2) DEFAULT '0.00',
  `solde_maximum` decimal(10,2) DEFAULT '0.00',
  `adresse_contact` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_contact` enum('Actif','Inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Actif',
  PRIMARY KEY (`code_contact`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Structure de la table `depense`
--

DROP TABLE IF EXISTS `depense`;
CREATE TABLE IF NOT EXISTS `depense` (
  `code_depense` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `numero_transaction` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `categorie` enum('Loyer','Électricité / Eau / Internet','Transport / Carburant','Salaires / Primes','Fournitures / Entretien','Réparation / Maintenance','Communication / Téléphone','Taxes / Impôts','Autres') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Autres',
  `motif` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `date_depense` date NOT NULL,
  `boutique_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utilisateur_id` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `piece_justificative` longblob,
  `type_piece` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`code_depense`),
  UNIQUE KEY `uq_depense_transaction` (`numero_transaction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `facture`
--

DROP TABLE IF EXISTS `facture`;
CREATE TABLE IF NOT EXISTS `facture` (
  `numero_facture` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `titre_facture` varchar(300) COLLATE utf8mb4_general_ci NOT NULL,
  `type_facture` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `categorie_facture` enum('Facture','Bon','Devis','AvoirClient','AvoirFournisseur') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Bon',
  `date_facture` date NOT NULL,
  `montant_ht` decimal(10,2) NOT NULL,
  `taxe` decimal(10,2) NOT NULL,
  `remise` decimal(10,2) NOT NULL,
  `montant_ttc` decimal(10,2) NOT NULL,
  `avance` decimal(10,2) NOT NULL,
  `reste` decimal(10,2) NOT NULL,
  `contact_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `utilisateur_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `etat_facture` enum('Impayee','Partielle','Payee cash','Payee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Impayee',
  `statut_facture` enum('En attente','Validee','Annule') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'En attente',
  `reference_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Devis d''origine si ce Bon vient d''un devis transformé, ou Facture d''origine si c''est un Avoir',
  PRIMARY KEY (`numero_facture`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Structure de la table `journees_caisse`
--

DROP TABLE IF EXISTS `journees_caisse`;
CREATE TABLE IF NOT EXISTS `journees_caisse` (
  `id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `caisse_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `date_journee` date NOT NULL,
  `date_ouverture` datetime NOT NULL,
  `date_fermeture` datetime DEFAULT NULL,
  `solde_ouverture` decimal(15,2) NOT NULL,
  `solde_theorique` decimal(15,2) DEFAULT NULL COMMENT 'Calculé : ouverture + entrées - sorties',
  `solde_physique` decimal(15,2) DEFAULT NULL COMMENT 'Compté physiquement par le guichetier',
  `total_entrees` decimal(15,2) DEFAULT '0.00',
  `total_sorties` decimal(15,2) DEFAULT '0.00',
  `ecart_solde` decimal(15,2) DEFAULT '0.00',
  `nombre_transactions` int NOT NULL DEFAULT '0',
  `boutique_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `observations` text COLLATE utf8mb4_general_ci,
  `statut` enum('OUVERTE','FERMEE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Structure de la table `lot`
--

DROP TABLE IF EXISTS `lot`;
CREATE TABLE IF NOT EXISTS `lot` (
  `code_lot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle` enum('Boîte','Palette','Carton','Bidon','Unité') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Ex: Unité, Boîte, Carton, Palette',
  `unites_par_lot` int NOT NULL DEFAULT '1' COMMENT 'Nombre de produits contenus dans une unité de ce lot (ex: 24 pour un carton)',
  `prix_lot` decimal(10,2) DEFAULT NULL COMMENT 'Prix de vente du lot entier (ex: 10000 pour un carton de 24). NULL = pas de prix spécial, on garde prix_unitaire x unites_par_lot.',
  `cout_lot` decimal(10,2) DEFAULT NULL COMMENT 'Coût d''achat du lot entier auprès du fournisseur (ex: 8000 pour un carton de 24). NULL = pas de coût spécial, on garde prix_fournisseur x unites_par_lot.',
  `produit_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `quantite` int NOT NULL DEFAULT '0',
  `etat_lot` enum('Actif','Inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Actif' COMMENT 'Actif / Inactif',
  PRIMARY KEY (`code_lot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `prix_tranche`
--

DROP TABLE IF EXISTS `prix_tranche`;
CREATE TABLE IF NOT EXISTS `prix_tranche` (
  `id` int NOT NULL AUTO_INCREMENT,
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `quantite_min` int NOT NULL COMMENT 'À partir de cette quantité (en unités de base), le prix_unitaire s''applique',
  `prix_unitaire` decimal(10,2) NOT NULL,
  `libelle_tranche` enum('Detail','Demi-gros','Gros') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Demi-gros' COMMENT 'Nom commercial de la tranche',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prix_tranche_produit_qte` (`produit_id`,`quantite_min`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Structure de la table `produit`
--

DROP TABLE IF EXISTS `produit`;
CREATE TABLE IF NOT EXISTS `produit` (
  `code_produit` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `titre_produit` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `prix_fournisseur` decimal(10,2) DEFAULT '0.00',
  `prix_produit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `benefice_produit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stock_alerte` int NOT NULL DEFAULT '0',
  `stock_produit` int NOT NULL DEFAULT '0',
  `categorie_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description_produit` longtext COLLATE utf8mb4_general_ci,
  `photo` longblob,
  `type_photo` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_produit` enum('Actif','Inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Actif',
  `saisie_par_carton` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Si 1 : en vente/achat par lot, la quantité saisie représente le nombre de cartons (pas de pièces). Si 0 (défaut) : comportement standard, toujours en pièces.',
  `tranche_active` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = les tranches de prix (table prix_tranche) sont appliquées ; 0 = seul prix_produit compte',
  PRIMARY KEY (`code_produit`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `statut`
--

DROP TABLE IF EXISTS `statut`;
CREATE TABLE IF NOT EXISTS `statut` (
  `code_statut` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `titre_statut` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `type_statut` enum('ENTREE','SORTIE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `symbole_statut` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `etat_statut` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`code_statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Structure de la table `stock`
--

DROP TABLE IF EXISTS `stock`;
CREATE TABLE IF NOT EXISTS `stock` (
  `produit_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `boutique_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `quantite` int NOT NULL DEFAULT '0' COMMENT 'Toujours en unité de base (ex: pièce, litre)',
  `stock_alerte` int DEFAULT '10',
  PRIMARY KEY (`produit_id`,`boutique_id`),
  KEY `idx_sb_produit` (`produit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `taxe`
--

DROP TABLE IF EXISTS `taxe`;
CREATE TABLE IF NOT EXISTS `taxe` (
  `code_taxe` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `titre_taxe` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `taux_taxe` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type_taxe` enum('TAXE','REMISE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_taxe` enum('ACTIF','INACTF') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'ACTIF',
  PRIMARY KEY (`code_taxe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `transaction`
--

DROP TABLE IF EXISTS `transaction`;
CREATE TABLE IF NOT EXISTS `transaction` (
  `numero_transaction` varchar(250) COLLATE utf8mb4_general_ci NOT NULL,
  `date_transaction` date DEFAULT NULL,
  `heure_transaction` time DEFAULT NULL,
  `montant_transaction` decimal(10,2) DEFAULT NULL,
  `frais_transaction` decimal(10,2) DEFAULT NULL,
  `montant_total` decimal(10,2) DEFAULT NULL,
  `type_transaction` enum('Entree','Sortie') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `objet_transaction` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `caisse_id` varchar(250) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `facture_id` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mode_reglement` enum('Espèce','Virement','Carte','Mobile money','Chèque','Autres') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `numero_reglement` varchar(250) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reference_reglement` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utilisateur_id` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Utilisateur qui a créé la transaction',
  `etat_transaction` enum('Succes','Echec','En attente','Annulee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact_id` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Client/fournisseur concerné : permet de retrouver le solde à ajuster même quand la transaction n''est pas liée à une facture (avance, dépense)',
  `montant_applique_facture` decimal(10,2) DEFAULT NULL COMMENT 'Part du montant réellement appliquée à la facture liée (facture_id) — le reste (surplus) a pu être réparti automatiquement sur d''autres factures du même contact',
  `annule_par` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Identifiant utilisateur ayant annulé cette transaction',
  `date_annulation` datetime DEFAULT NULL,
  `motif_annulation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`numero_transaction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `transaction_facture`
--

DROP TABLE IF EXISTS `transaction_facture`;
CREATE TABLE IF NOT EXISTS `transaction_facture` (
  `id` int NOT NULL AUTO_INCREMENT,
  `numero_transaction` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `numero_facture` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `montant_applique` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Part du montant de la transaction appliquée à cette facture précise',
  `reste_avant` decimal(10,2) DEFAULT NULL COMMENT 'Reste de la facture juste avant ce règlement (instantané, pour affichage fiable même si d''autres paiements arrivent plus tard)',
  `reste_apres` decimal(10,2) DEFAULT NULL COMMENT 'Reste de la facture juste après ce règlement',
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `utilisateur`
--

DROP TABLE IF EXISTS `utilisateur`;
CREATE TABLE IF NOT EXISTS `utilisateur` (
  `id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `matricule` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nom_prenom` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_naissance` date DEFAULT NULL,
  `lieu_naissance` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sexe` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `login` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mdp` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone` varchar(250) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `profession` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nationalite` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ville` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `adresse` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `boutique_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_saisie` date DEFAULT NULL,
  `photo` longblob,
  `type` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat` varchar(250) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `matricule` (`matricule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
