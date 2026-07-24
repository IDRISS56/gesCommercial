-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : ven. 24 juil. 2026 à 12:27
-- Version du serveur : 8.4.7
-- Version de PHP : 8.3.28

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

-- --------------------------------------------------------

--
-- Structure de la table `album`
--

DROP TABLE IF EXISTS `album`;
CREATE TABLE IF NOT EXISTS `album` (
  `id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `titre` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `photo` longblob,
  `type_photo` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `matricule` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `album`
--

INSERT INTO `album` (`id`, `titre`, `photo`, `type_photo`, `matricule`, `etat`) VALUES
('ALB001', 'Photo produit Smartphone', NULL, 'image/jpeg', 'MAT001', 'Actif'),
('ALB002', 'Photo boutique extérieure', NULL, 'image/jpeg', 'MAT001', 'Actif');

-- --------------------------------------------------------

--
-- Structure de la table `boutique`
--

DROP TABLE IF EXISTS `boutique`;
CREATE TABLE IF NOT EXISTS `boutique` (
  `code_boutique` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom_boutique` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone_boutique` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_boutique` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pays_boutique` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ville_boutique` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `quartier_boutique` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `adresse_boutique` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `latitude` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `longitude` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `couleur` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_boutique` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`code_boutique`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `boutique`
--

INSERT INTO `boutique` (`code_boutique`, `nom_boutique`, `telephone_boutique`, `email_boutique`, `pays_boutique`, `ville_boutique`, `quartier_boutique`, `adresse_boutique`, `latitude`, `longitude`, `couleur`, `etat_boutique`) VALUES
('BQ001', 'Boutique Centrale', '0123456789', 'contact@boutique1.com', 'France', 'Paris', '1er', '10 rue de Rivoli', '48.8584', '2.2945', '#FF5733', 'Actif'),
('BQ002', 'Boutique Express', '0987654321', 'contact@boutique2.com', 'France', 'Lyon', 'Presqu\'île', '22 rue Mercière', '45.7640', '4.8357', '#33C3FF', 'Actif');

-- --------------------------------------------------------

--
-- Structure de la table `caisse`
--

DROP TABLE IF EXISTS `caisse`;
CREATE TABLE IF NOT EXISTS `caisse` (
  `code_caisse` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `titre_caisse` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `solde_virtuel` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `solde_physique` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_caisse` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`code_caisse`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `caisse`
--

INSERT INTO `caisse` (`code_caisse`, `titre_caisse`, `solde_virtuel`, `solde_physique`, `utilisateur_id`, `etat_caisse`) VALUES
('CAI001', 'Caisse Principale', '1515', '1515', 'USR001', 'Ouverte'),
('CAI002', 'Caisse Secondaire', '750.00', '750.00', 'USR002', 'Ouverte');

-- --------------------------------------------------------

--
-- Structure de la table `categorie`
--

DROP TABLE IF EXISTS `categorie`;
CREATE TABLE IF NOT EXISTS `categorie` (
  `code_categorie` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `titre_categorie` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `photo` longblob,
  `type` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_categorie` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`code_categorie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `categorie`
--

INSERT INTO `categorie` (`code_categorie`, `titre_categorie`, `photo`, `type`, `etat_categorie`) VALUES
('CAT001', 'Électronique', NULL, 'produit', 'Actif'),
('CAT002', 'Alimentaire', NULL, 'produit', 'Actif'),
('CAT003', 'Vêtements', NULL, 'produit', 'Actif');

-- --------------------------------------------------------

--
-- Structure de la table `commande`
--

DROP TABLE IF EXISTS `commande`;
CREATE TABLE IF NOT EXISTS `commande` (
  `numero_commande` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `facture_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Lien vers statut (006=entrée, 007=sortie)',
  `date_commande` date DEFAULT NULL,
  `heure_commande` time DEFAULT NULL,
  `prix_achat` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Prix d''achat unitaire (coût)',
  `prix_commande` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Prix de vente unitaire (ou prix d''achat)',
  `quantite_commande` int DEFAULT '0' COMMENT 'Quantité dans l''unité choisie',
  `montant_commande` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `boutique_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_commande` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lot_produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Référence vers lot_produit (ex: LOT001_CARTON)',
  `unite_affichage` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Libellé de l''unité choisie (ex: Carton, Pièce, Kg)',
  `facteur_conversion` int DEFAULT '1' COMMENT 'Nombre d''unités de base par lot (ex: 12 pour un carton)',
  `date_livraison_recue` date DEFAULT NULL COMMENT 'Date effective de réception (pour fournisseur)',
  `reference_liee` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `montant_rembourse` decimal(10,2) NOT NULL,
  `motif_retour` text COLLATE utf8mb4_general_ci NOT NULL,
  `type_retour` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`numero_commande`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `commande`
--

INSERT INTO `commande` (`numero_commande`, `produit_id`, `contact_id`, `facture_id`, `statut_id`, `date_commande`, `heure_commande`, `prix_achat`, `prix_commande`, `quantite_commande`, `montant_commande`, `utilisateur_id`, `boutique_id`, `etat_commande`, `lot_produit_id`, `unite_affichage`, `facteur_conversion`, `date_livraison_recue`, `reference_liee`, `montant_rembourse`, `motif_retour`, `type_retour`) VALUES
('CMD001', 'PRD001', 'CT001', NULL, 'ST001', '2026-07-20', '10:30:00', '450.00', '450.00', 12, '5400.00', 'USR001', 'BQ001', 'Validée', 'LOT001_CARTON', 'Carton', 6, '2026-07-21', '', 0.00, '', ''),
('CMD002', 'PRD002', 'CT003', NULL, 'ST001', '2026-07-21', '09:15:00', '0.80', '0.80', 120, '96.00', 'USR001', 'BQ001', 'Validée', 'LOT002_CARTON', 'Carton', 12, '2026-07-22', '', 0.00, '', ''),
('CMD003', 'PRD001', 'CT002', NULL, 'ST002', '2026-07-22', '14:20:00', NULL, '699.00', 2, '1398.00', 'USR002', 'BQ001', 'Validée', 'LOT001_PIECE', 'Pièce', 1, NULL, '', 0.00, '', ''),
('CMD004', 'PRD003', 'CT002', NULL, 'ST002', '2026-07-23', '11:45:00', NULL, '15.00', 5, '75.00', 'USR003', 'BQ002', 'Validée', 'LOT003_PIECE', 'Pièce', 1, NULL, '', 0.00, '', ''),
('BC-2026-1239-202810855', 'PRD002', 'CT001', NULL, 'Achat', '2026-07-23', '20:28:10', '0.8', '0', 60, '48', '1', 'BQ001', 'Valider', 'LOT002_CARTON', 'Carton de 12', 12, '0000-00-00', 'BC-2026-1239', 0.00, '', ''),
('2407202611332500', 'PRD003', 'CT002', 'FAC-20260724-95116', 'Vente', '2026-07-24', '11:33:25', '8', '15', 1, '15', '1', NULL, 'Valider', NULL, NULL, 1, NULL, '', 0.00, '', '');

-- --------------------------------------------------------

--
-- Structure de la table `contact`
--

DROP TABLE IF EXISTS `contact`;
CREATE TABLE IF NOT EXISTS `contact` (
  `code_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom_prenom_contact` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `telephone_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `statut_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `solde_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `solde_minimum` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `solde_maximum` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `adresse_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`code_contact`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `contact`
--

INSERT INTO `contact` (`code_contact`, `nom_prenom_contact`, `telephone_contact`, `email_contact`, `type_contact`, `statut_contact`, `solde_contact`, `solde_minimum`, `solde_maximum`, `adresse_contact`, `etat_contact`) VALUES
('CT001', 'SARL Alpha', '0123456700', 'alpha@mail.com', 'FOURNISSEUR', 'ACTIF', '0.00', '0.00', '5000.00', '5 rue des Fournisseurs, Paris', 'Actif'),
('CT002', 'M. Martin Client', '0611223344', 'martin@mail.com', 'CLIENT', 'ACTIF', '250.00', '0.00', '1000.00', '18 avenue des Clients, Lyon', 'Actif'),
('CT003', 'Société Bêta', '0788990011', 'beta@mail.com', 'FOURNISSEUR', 'ACTIF', '0.00', '0.00', '2000.00', '7 rue du Commerce, Abidjan', 'Actif');

-- --------------------------------------------------------

--
-- Structure de la table `depense`
--

DROP TABLE IF EXISTS `depense`;
CREATE TABLE IF NOT EXISTS `depense` (
  `code_depense` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `titre_depense` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `boutique_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `montant_depense` decimal(12,2) DEFAULT '0.00',
  `date_depense` datetime DEFAULT CURRENT_TIMESTAMP,
  `description_depense` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_depense` enum('VALIDE','ANNULE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'VALIDE',
  PRIMARY KEY (`code_depense`),
  KEY `idx_depense_boutique` (`boutique_id`),
  KEY `idx_depense_date` (`date_depense`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `depense`
--

INSERT INTO `depense` (`code_depense`, `titre_depense`, `boutique_id`, `utilisateur_id`, `montant_depense`, `date_depense`, `description_depense`, `etat_depense`) VALUES
('DEP001', 'Achat fournitures bureau', 'BQ001', 'USR001', 120.50, '2026-07-23 20:00:53', 'Achat de papeterie et cartouches', 'VALIDE'),
('DEP002', 'Entretien local', 'BQ002', 'USR003', 80.00, '2026-07-23 20:00:53', 'Nettoyage du magasin', 'VALIDE');

-- --------------------------------------------------------

--
-- Structure de la table `facture`
--

DROP TABLE IF EXISTS `facture`;
CREATE TABLE IF NOT EXISTS `facture` (
  `numero_facture` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `titre_facture` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_facture` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `categorie_facture` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_facture` date NOT NULL,
  `montant_ht` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `taxe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `remise` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `montant_ttc` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `avance` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `reste` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `contact_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `etat_facture` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`numero_facture`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `facture`
--

INSERT INTO `facture` (`numero_facture`, `titre_facture`, `type_facture`, `categorie_facture`, `date_facture`, `montant_ht`, `taxe`, `remise`, `montant_ttc`, `avance`, `reste`, `contact_id`, `utilisateur_id`, `etat_facture`) VALUES
('FAC001', 'Facture Client Martin', 'VENTE', 'CLIENT', '2026-07-22', '1165.00', '20', '0', '1398.00', '700.00', '698.00', 'CT002', 'USR002', 'En attente'),
('FAC002', 'Facture Client Martin (T-shirt)', 'VENTE', 'CLIENT', '2026-07-23', '62.50', '20', '0', '75.00', '75.00', '0.00', 'CT002', 'USR003', 'Payée'),
('FAC-20260724-95116', 'Vente comptoir', 'Client', 'Facture', '2026-07-24', '15', '0', '0', '15', '15', '0', 'CT002', '1', 'Payer cash');

-- --------------------------------------------------------

--
-- Structure de la table `lot_produit`
--

DROP TABLE IF EXISTS `lot_produit`;
CREATE TABLE IF NOT EXISTS `lot_produit` (
  `code_lot_produit` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `titre_lot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Ex: Unité, Boîte, Carton, Palette',
  `unites_par_lot` int NOT NULL DEFAULT '1' COMMENT 'Nombre d''unités de base dans ce lot',
  `etat_lot` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Actif' COMMENT 'Actif / Inactif',
  `quantite` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`code_lot_produit`),
  UNIQUE KEY `uk_lot_produit_titre` (`produit_id`,`titre_lot`),
  KEY `idx_lot_produit` (`produit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `lot_produit`
--

INSERT INTO `lot_produit` (`code_lot_produit`, `produit_id`, `titre_lot`, `unites_par_lot`, `etat_lot`, `quantite`) VALUES
('LOT001_CARTON', 'PRD001', 'Carton de 6', 6, 'Actif', 0),
('LOT001_PIECE', 'PRD001', 'Pièce', 1, 'Actif', 0),
('LOT002_CARTON', 'PRD002', 'Carton de 12', 12, 'Actif', 0),
('LOT002_PIECE', 'PRD002', 'Pièce', 1, 'Actif', 0),
('LOT003_DIZAINE', 'PRD003', 'Dizaine', 10, 'Actif', 0),
('LOT003_PIECE', 'PRD003', 'Pièce', 1, 'Actif', 0);

-- --------------------------------------------------------

--
-- Structure de la table `notification`
--

DROP TABLE IF EXISTS `notification`;
CREATE TABLE IF NOT EXISTS `notification` (
  `id` int NOT NULL AUTO_INCREMENT,
  `objet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `titre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `date` datetime DEFAULT NULL,
  `user` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `fichier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `notification`
--

INSERT INTO `notification` (`id`, `objet`, `titre`, `text`, `date`, `user`, `fichier`) VALUES
(1, 'Alerte stock', 'Stock critique Smartphone', 'Le stock de Smartphone Galaxy S22 est inférieur à 5 unités.', '2026-07-23 20:00:53', 'USR001', NULL),
(2, 'Facture', 'Facture FAC001 en attente', 'Le client Martin a un reste à payer de 698.00 €.', '2026-07-23 20:00:53', 'USR002', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `prix`
--

DROP TABLE IF EXISTS `prix`;
CREATE TABLE IF NOT EXISTS `prix` (
  `code_prix` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `boutique_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'NULL = prix global',
  `titre_prix` enum('DETAILS','DEMI-GROS','GROS') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'DETAILS',
  `quantite_min` int NOT NULL DEFAULT '1',
  `quantite_max` int DEFAULT NULL COMMENT 'NULL = pas de limite haute',
  `prix_unitaire` decimal(12,2) NOT NULL,
  `etat_prix` enum('Actif','Inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Actif',
  `lot_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`code_prix`),
  UNIQUE KEY `uk_prix_produit_boutique_type_min` (`produit_id`,`boutique_id`,`titre_prix`,`quantite_min`),
  KEY `idx_prix_produit` (`produit_id`),
  KEY `idx_prix_boutique` (`boutique_id`),
  KEY `idx_prix_palier` (`produit_id`,`quantite_min`),
  KEY `idx_prix_lot` (`lot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `prix`
--

INSERT INTO `prix` (`code_prix`, `produit_id`, `boutique_id`, `titre_prix`, `quantite_min`, `quantite_max`, `prix_unitaire`, `etat_prix`, `lot_id`) VALUES
('PRIX001', 'PRD001', 'BQ001', 'DETAILS', 1, 5, 699.00, 'Actif', NULL),
('PRIX002', 'PRD001', 'BQ001', 'DEMI-GROS', 6, 11, 650.00, 'Actif', NULL),
('PRIX003', 'PRD001', 'BQ001', 'GROS', 12, NULL, 600.00, 'Actif', NULL),
('PRIX004', 'PRD002', 'BQ001', 'DETAILS', 1, 11, 1.20, 'Actif', NULL),
('PRIX005', 'PRD002', 'BQ001', 'GROS', 12, NULL, 1.00, 'Actif', NULL),
('PRIX006', 'PRD003', 'BQ002', 'DETAILS', 1, 9, 15.00, 'Actif', NULL),
('PRIX007', 'PRD003', 'BQ002', 'DEMI-GROS', 10, 49, 13.00, 'Actif', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `produit`
--

DROP TABLE IF EXISTS `produit`;
CREATE TABLE IF NOT EXISTS `produit` (
  `code_produit` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `titre_produit` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `prix_fournisseur` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `prix_produit` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `benefice_produit` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `stock_alerte` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `stock_produit` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `categorie_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description_produit` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `photo` longblob,
  `type_photo` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_produit` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`code_produit`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `produit`
--

INSERT INTO `produit` (`code_produit`, `titre_produit`, `prix_fournisseur`, `prix_produit`, `benefice_produit`, `stock_alerte`, `stock_produit`, `categorie_id`, `description_produit`, `photo`, `type_photo`, `etat_produit`) VALUES
('PRD001', 'Smartphone Galaxy S22', '450.00', '699.00', '55.33', '5', '15', 'CAT001', 'Smartphone dernière génération', NULL, 'image/jpeg', 'Actif'),
('PRD002', 'Lait 1L', '0.80', '1.20', '50.00', '20', '160', 'CAT002', 'Lait entier UHT', NULL, 'image/jpeg', 'Actif'),
('PRD003', 'T-shirt blanc', '8.00', '15.00', '87.50', '10', '29', 'CAT003', 'T-shirt en coton 100%', NULL, 'image/jpeg', 'Actif');

-- --------------------------------------------------------

--
-- Structure de la table `statut`
--

DROP TABLE IF EXISTS `statut`;
CREATE TABLE IF NOT EXISTS `statut` (
  `code_statut` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `titre_statut` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_statut` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `symbole_statut` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `etat_statut` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`code_statut`),
  UNIQUE KEY `titre_statut` (`titre_statut`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `statut`
--

INSERT INTO `statut` (`code_statut`, `titre_statut`, `type_statut`, `symbole_statut`, `etat_statut`) VALUES
('ST001', 'ENTRÉE', 'COMMANDE', 'IN', 'Actif'),
('ST002', 'SORTIE', 'COMMANDE', 'OUT', 'Actif'),
('ST003', 'EN ATTENTE', 'FACTURE', 'PENDING', 'Actif'),
('ST004', 'PAYÉE', 'FACTURE', 'PAID', 'Actif'),
('008', 'Transfert sortie', 'sortie', '', 'Actif'),
('009', 'Transfert entree', 'entree', '', 'Actif'),
('010', 'Retour SAV', 'sortie', '', 'Actif'),
('011', 'Achat fournisseur', 'entree', '', 'Actif'),
('012', 'Vente client', 'sortie', '', 'Actif');

-- --------------------------------------------------------

--
-- Structure de la table `stock_boutique`
--

DROP TABLE IF EXISTS `stock_boutique`;
CREATE TABLE IF NOT EXISTS `stock_boutique` (
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `boutique_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `quantite` int NOT NULL DEFAULT '0' COMMENT 'Toujours en unité de base (ex: pièce, litre)',
  `stock_alerte` int DEFAULT '10',
  `maj_le` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lot_produit_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `quantite_lot` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`produit_id`,`boutique_id`),
  KEY `idx_sb_produit` (`produit_id`),
  KEY `idx_sb_boutique` (`boutique_id`),
  KEY `fk_stock_boutique_lot` (`lot_produit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock_boutique`
--

INSERT INTO `stock_boutique` (`produit_id`, `boutique_id`, `quantite`, `stock_alerte`, `maj_le`, `lot_produit_id`, `quantite_lot`) VALUES
('PRD001', 'BQ001', 15, 5, '2026-07-23 20:00:53', NULL, 0),
('PRD001', 'BQ002', 8, 5, '2026-07-23 20:00:53', NULL, 0),
('PRD002', 'BQ001', 160, 20, '2026-07-23 20:28:10', NULL, 0),
('PRD003', 'BQ002', 30, 10, '2026-07-23 20:00:53', NULL, 0);

-- --------------------------------------------------------

--
-- Structure de la table `taxe`
--

DROP TABLE IF EXISTS `taxe`;
CREATE TABLE IF NOT EXISTS `taxe` (
  `code_taxe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `titre_taxe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `taux_taxe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type_taxe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_taxe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`code_taxe`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `taxe`
--

INSERT INTO `taxe` (`code_taxe`, `titre_taxe`, `taux_taxe`, `type_taxe`, `etat_taxe`) VALUES
('TAX001', 'TVA 20%', '20', 'Vente', 'Actif'),
('TAX002', 'TVA 5.5%', '5.5', 'Vente', 'Actif');

-- --------------------------------------------------------

--
-- Structure de la table `transaction`
--

DROP TABLE IF EXISTS `transaction`;
CREATE TABLE IF NOT EXISTS `transaction` (
  `numero_transaction` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_transaction` date DEFAULT NULL,
  `heure_transaction` time DEFAULT NULL,
  `montant_transaction` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `frais_transaction` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `montant_total` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type_transaction` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `objet_transaction` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact_id` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `facture_id` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mode_reglement` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `numero_reglement` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reference_reglement` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `valider_par` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_transaction` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`numero_transaction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `transaction`
--

INSERT INTO `transaction` (`numero_transaction`, `date_transaction`, `heure_transaction`, `montant_transaction`, `frais_transaction`, `montant_total`, `type_transaction`, `objet_transaction`, `contact_id`, `facture_id`, `mode_reglement`, `numero_reglement`, `reference_reglement`, `valider_par`, `etat_transaction`) VALUES
('TR-20260724113325965', '2026-07-24', '11:33:25', '15', '0', '15', 'Encaissement', NULL, 'CT002', 'FAC-20260724-95116', 'Espece', NULL, NULL, '1', 'Succes'),
('TRA001', '2026-07-22', '15:30:00', '700.00', '0.00', '700.00', 'Paiement', 'Acompte facture FAC001', 'CT002', 'FAC001', 'Carte bancaire', 'CB123456', 'REF001', 'USR002', 'Validée'),
('TRA002', '2026-07-23', '12:00:00', '75.00', '0.00', '75.00', 'Paiement', 'Règlement facture FAC002', 'CT002', 'FAC002', 'Espèces', 'ESP001', 'REF002', 'USR003', 'Validée');

-- --------------------------------------------------------

--
-- Structure de la table `utilisateur`
--

DROP TABLE IF EXISTS `utilisateur`;
CREATE TABLE IF NOT EXISTS `utilisateur` (
  `id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `matricule` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nom_prenom` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_naissance` date DEFAULT NULL,
  `lieu_naissance` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sexe` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `login` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mdp` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `profession` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nationalite` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ville` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `adresse` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `boutique_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_saisie` date DEFAULT NULL,
  `photo` longblob,
  `type` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `matricule` (`matricule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateur`
--

INSERT INTO `utilisateur` (`id`, `matricule`, `nom_prenom`, `date_naissance`, `lieu_naissance`, `sexe`, `login`, `mdp`, `telephone`, `email`, `profession`, `nationalite`, `ville`, `adresse`, `boutique_id`, `role`, `date_saisie`, `photo`, `type`, `etat`) VALUES
('1', '1', 'SALUT', NULL, NULL, NULL, '12', '1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'actif'),
('USR001', 'MAT001', 'Jean Dupont', '1985-03-12', 'Paris', 'M', 'jdupont', '0192023a7bbd73250516f069df18b500', '0612345678', 'jdupont@mail.com', 'Gérant', 'Française', 'Paris', '12 rue de la Paix', NULL, 'ADMIN', '2026-07-23', NULL, 'photo', 'Actif'),
('USR002', 'MAT002', 'Marie Curie', '1990-07-22', 'Lyon', 'F', 'mcurie', '2252da4c605711aed52d74e98d5732a0', '0698765432', 'mcurie@mail.com', 'Caissière', 'Française', 'Lyon', '45 avenue des Fleurs', NULL, 'CAISSIER', '2026-07-23', NULL, 'photo', 'Actif'),
('USR003', 'MAT003', 'Ali Traoré', '1988-11-02', 'Abidjan', 'M', 'atraore', '34fdd771c0b05faaf5f16b3b0ea12702', '0755123456', 'atraore@mail.com', 'Vendeur', 'Ivoirienne', 'Abidjan', '15 rue du Commerce', NULL, 'VENDEUR', '2026-07-23', NULL, 'photo', 'Actif');

-- --------------------------------------------------------

--
-- Structure de la table `visiteur`
--

DROP TABLE IF EXISTS `visiteur`;
CREATE TABLE IF NOT EXISTS `visiteur` (
  `id` int NOT NULL AUTO_INCREMENT,
  `date_connexion` date DEFAULT NULL,
  `heure_connexion` time DEFAULT NULL,
  `date_deconnexion` date DEFAULT NULL,
  `heure_deconnexion` time DEFAULT NULL,
  `duree_date` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `duree_heure` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reference` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_connexion` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `visiteur`
--

INSERT INTO `visiteur` (`id`, `date_connexion`, `heure_connexion`, `date_deconnexion`, `heure_deconnexion`, `duree_date`, `duree_heure`, `reference`, `etat_connexion`) VALUES
(1, '2026-07-23', '08:30:00', '2026-07-23', '18:30:00', '0', '10:00:00', 'VIS001', 'Terminée'),
(2, '2026-07-23', '09:00:00', '2026-07-23', '17:30:00', '0', '08:30:00', 'VIS002', 'Terminée');

-- --------------------------------------------------------

--
-- Structure de la table `vue`
--

DROP TABLE IF EXISTS `vue`;
CREATE TABLE IF NOT EXISTS `vue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `notification` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lecture` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `affichage` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `vue`
--

INSERT INTO `vue` (`id`, `notification`, `user`, `lecture`, `affichage`) VALUES
(1, '1', 'USR001', 'Oui', 'Lu'),
(2, '2', 'USR002', 'Oui', 'Lu');

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `prix`
--
ALTER TABLE `prix`
  ADD CONSTRAINT `fk_prix_lot` FOREIGN KEY (`lot_id`) REFERENCES `lot_produit` (`code_lot_produit`);

--
-- Contraintes pour la table `stock_boutique`
--
ALTER TABLE `stock_boutique`
  ADD CONSTRAINT `fk_stock_boutique_lot` FOREIGN KEY (`lot_produit_id`) REFERENCES `lot_produit` (`code_lot_produit`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
