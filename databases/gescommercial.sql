-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : sam. 25 juil. 2026 à 23:29
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `boutique`
--

INSERT INTO `boutique` (`code_boutique`, `nom_boutique`, `telephone_boutique`, `email_boutique`, `pays_boutique`, `ville_boutique`, `quartier_boutique`, `adresse_boutique`, `latitude`, `longitude`, `couleur`, `etat_boutique`) VALUES
('BQ001', 'Boutique Centrale', '0123456789', 'contact@boutique1.com', 'France', 'Paris', '1er', '10 rue de Rivoli', '48.8584', '2.2945', '#FF5733', 'Actif'),
('BQ002', 'Boutique Express', '0987654321', 'contact@boutique2.com', 'France', 'Lyon', 'Presqu\'île', '22 rue Mercière', '45.7640', '4.8357', '#33C3FF', 'Actif'),
('BQ003', 'Boutique du Plateau', '0707070707', 'plateau@gescom.ci', 'Côte d’Ivoire', 'Abidjan', 'Plateau', 'Immeuble Alpha, 2e étage', '5.3364', '-4.0267', '#FFA500', 'Actif'),
('BQ004', 'Boutique de la Gare', '0808080808', 'gare@gescom.ci', 'Côte d’Ivoire', 'Bouaké', 'Gare', 'Avenue de la Gare', '7.6900', '-5.0300', '#00BFFF', 'Actif');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `caisse`
--

INSERT INTO `caisse` (`code_caisse`, `titre_caisse`, `solde_virtuel`, `solde_physique`, `utilisateur_id`, `etat_caisse`) VALUES
('CAI001', 'Caisse Principale', '153965', '153965', 'USR001', 'Ouverte'),
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
  `stock_avant` int DEFAULT NULL,
  `stock_apres` int DEFAULT NULL,
  `commentaire` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_validation` datetime DEFAULT NULL,
  `utilisateur_validation_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_reception_reelle` date DEFAULT NULL,
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
  `reference_liee` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `montant_rembourse` decimal(10,2) NOT NULL,
  `motif_retour` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_retour` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`numero_commande`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `commande`
--

INSERT INTO `commande` (`numero_commande`, `produit_id`, `contact_id`, `facture_id`, `statut_id`, `stock_avant`, `stock_apres`, `commentaire`, `date_validation`, `utilisateur_validation_id`, `date_reception_reelle`, `date_commande`, `heure_commande`, `prix_achat`, `prix_commande`, `quantite_commande`, `montant_commande`, `utilisateur_id`, `boutique_id`, `etat_commande`, `lot_produit_id`, `unite_affichage`, `facteur_conversion`, `date_livraison_recue`, `reference_liee`, `montant_rembourse`, `motif_retour`, `type_retour`) VALUES
('2407202611332500', 'PRD003', 'CT002', 'FAC-20260724-95116', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '11:33:25', '8', '15', 1, '15', '1', NULL, 'Valider', NULL, NULL, 1, NULL, '', 0.00, '', ''),
('2407202622281800', 'PRD007', 'CT002', 'FAC-20260724-50430', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '22:28:18', '6000', '8500', 1, '8500', '1', NULL, 'Valider', NULL, NULL, 1, NULL, '', 0.00, '', ''),
('2407202622281801', 'PRD002', 'CT002', 'FAC-20260724-50430', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '22:28:18', '0.8', '450', 1, '450', '1', NULL, 'Valider', NULL, NULL, 1, NULL, '', 0.00, '', ''),
('2407202622484300', 'PRD005', 'CT002', 'FAC-20260724-58244', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '22:48:43', '25000', '45000', 1, '45000', '1', NULL, 'Valider', NULL, NULL, 1, NULL, '', 0.00, '', ''),
('2407202622484301', 'PRD007', 'CT002', 'FAC-20260724-58244', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '22:48:43', '6000', '8500', 1, '8500', '1', NULL, 'Valider', NULL, NULL, 1, NULL, '', 0.00, '', ''),
('2507202615212700', 'PRD005', 'CT002', 'FAC-20260725-91435', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-25', '15:21:27', '25000', '45000', 2, '90000', '1', NULL, 'Valider', NULL, NULL, 1, NULL, '', 0.00, '', ''),
('BC-2026-1239-202810855', 'PRD002', 'CT001', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-23', '20:28:10', '0.8', '0', 60, '48', '1', 'BQ001', 'Valider', 'LOT002_CARTON', 'Carton de 12', 12, '0000-00-00', 'BC-2026-1239', 0.00, '', ''),
('BC-2026-4183-154144530', 'PRD006', 'CT007', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-25', '15:41:44', '2500', '0', 20, '50000', '1', 'BQ003', 'En attente', 'LOT006_CARTON', 'Carton (20 sacs)', 20, '2026-07-31', 'BC-2026-4183', 0.00, '', ''),
('BC-2026-5103-154127751', 'PRD006', 'CT007', NULL, '011', NULL, NULL, NULL, '2026-07-25 15:41:59', '1', NULL, '2026-07-25', '15:41:27', '2500', '0', 20, '50000', '1', 'BQ003', 'Annulé', 'LOT006_CARTON', 'Carton (20 sacs)', 20, '2026-07-31', 'BC-2026-5103', 0.00, '', ''),
('BC-2026-5322-215944993', 'PRD008', 'CT002', 'FAC-20260725-2870', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-25', '21:59:44', '0', '5000', 1, '5000', '1', 'BQ004', 'En attente', 'LOT008_UNITE', 'Unité (pièce)', 1, NULL, 'BC-2026-5322', 0.00, '', ''),
('BC-2026-5520-164422348', 'PRD003', 'CT003', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '16:44:22', '8', '0', 40, '320', '1', 'BQ002', 'Valider', 'LOT003_DIZAINE', 'Dizaine', 10, '2026-08-28', 'BC-2026-5520', 0.00, '', ''),
('BC-2026-5520-164422798', 'PRD001', 'CT003', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '16:44:22', '450', '0', 12, '5400', '1', 'BQ002', 'Valider', 'LOT001_CARTON', 'Carton de 6', 6, '2026-08-28', 'BC-2026-5520', 0.00, '', ''),
('BC-2026-5520-164422867', 'PRD003', 'CT003', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '16:44:22', '8', '0', 13, '104', '1', 'BQ002', 'Valider', 'LOT003_PIECE', 'Pièce', 1, '2026-08-28', 'BC-2026-5520', 0.00, '', ''),
('BC-2026-5925-152010596', 'PRD001', 'CT005', 'FAC-20260725-4901', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-25', '15:20:10', '0', '699', 1, '699', '1', 'BQ001', 'En attente', '', 'Unité', 1, NULL, 'BC-2026-5925', 0.00, '', ''),
('BC-2026-7001-080000', 'PRD004', 'CT006', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '08:00:00', '800000', '0', 5, '4000000', 'USR001', 'BQ001', 'Valider', 'LOT004_UNITE', 'Unité (pc)', 1, '2026-07-25', 'BC-2026-7001', 0.00, '', ''),
('BC-2026-7002-090000', 'PRD005', 'CT006', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '09:00:00', '25000', '0', 20, '500000', 'USR001', 'BQ002', 'Valider', 'LOT005_UNITE', 'Unité (pièce)', 1, '2026-07-25', 'BC-2026-7002', 0.00, '', ''),
('BC-2026-7003-100000', 'PRD006', 'CT007', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '10:00:00', '2500', '0', 100, '250000', 'USR001', 'BQ003', 'Valider', 'LOT006_SAC', 'Sac (5 kg)', 1, '2026-07-24', 'BC-2026-7003', 0.00, '', ''),
('BC-2026-7004-110000', 'PRD007', 'CT006', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '11:00:00', '6000', '0', 30, '180000', 'USR001', 'BQ001', 'Valider', 'LOT007_BOUTEILLE', 'Bouteille (1L)', 1, '2026-07-25', 'BC-2026-7004', 0.00, '', ''),
('BC-2026-7005-120000', 'PRD008', 'CT007', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '12:00:00', '3000', '0', 50, '150000', 'USR001', 'BQ003', 'Valider', 'LOT008_UNITE', 'Unité (pièce)', 1, '2026-07-24', 'BC-2026-7005', 0.00, '', ''),
('BC-2026-8001-140000', 'PRD004', 'CT004', 'FAC-20260724-0001', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '14:00:00', '0', '1050000', 1, '1050000', 'USR002', 'BQ001', 'Valider', 'LOT004_UNITE', 'Unité (pc)', 1, NULL, 'BC-2026-8001', 0.00, '', ''),
('BC-2026-8002-143000', 'PRD005', 'CT005', 'FAC-20260724-0002', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '14:30:00', '0', '45000', 5, '225000', 'USR003', 'BQ002', 'Valider', 'LOT005_UNITE', 'Unité (pièce)', 1, NULL, 'BC-2026-8002', 0.00, '', ''),
('BC-2026-8003-150000', 'PRD006', 'CT005', 'FAC-20260724-0003', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '15:00:00', '0', '3000', 10, '30000', 'USR003', 'BQ003', 'Valider', 'LOT006_SAC', 'Sac (5 kg)', 1, NULL, 'BC-2026-8003', 0.00, '', ''),
('BC-2026-8004-153000', 'PRD007', 'CT004', 'FAC-20260724-0004', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '15:30:00', '0', '8500', 3, '25500', 'USR002', 'BQ001', 'Valider', 'LOT007_BOUTEILLE', 'Bouteille (1L)', 1, NULL, 'BC-2026-8004', 0.00, '', ''),
('BC-2026-8005-160000', 'PRD008', 'CT004', 'FAC-20260724-0005', '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '16:00:00', '0', '5000', 8, '40000', 'USR001', 'BQ003', 'Valider', 'LOT008_UNITE', 'Unité (pièce)', 1, NULL, 'BC-2026-8005', 0.00, '', ''),
('BC-2026-8074-151435674', 'PRD004', 'CT001', NULL, '011', 10, 15, NULL, '2026-07-25 15:23:02', '1', '2026-07-25', '2026-07-25', '15:14:35', '800000', '0', 5, '4000000', '1', 'BQ001', 'Reçu', 'LOT004_CARTON', 'Carton (5 pc)', 5, '2026-07-30', 'BC-2026-8074', 0.00, '', ''),
('BC-2026-8074-151435706', 'PRD001', 'CT001', NULL, '011', 15, 27, NULL, '2026-07-25 15:23:02', '1', '2026-07-25', '2026-07-25', '15:14:35', '450', '0', 12, '5400', '1', 'BQ001', 'Reçu', 'LOT001_CARTON', 'Carton de 6', 6, '2026-07-30', 'BC-2026-8074', 0.00, '', ''),
('CMD001', 'PRD001', 'CT001', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-20', '10:30:00', '450.00', '450.00', 12, '5400.00', 'USR001', 'BQ001', 'Validée', 'LOT001_CARTON', 'Carton', 6, '2026-07-21', '', 0.00, '', ''),
('CMD002', 'PRD002', 'CT003', NULL, '011', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-21', '09:15:00', '0.80', '0.80', 120, '96.00', 'USR001', 'BQ001', 'Validée', 'LOT002_CARTON', 'Carton', 12, '2026-07-22', '', 0.00, '', ''),
('CMD003', 'PRD001', 'CT002', NULL, '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-22', '14:20:00', NULL, '699.00', 2, '1398.00', 'USR002', 'BQ001', 'Validée', 'LOT001_PIECE', 'Pièce', 1, NULL, '', 0.00, '', ''),
('CMD004', 'PRD003', 'CT002', NULL, '012', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-23', '11:45:00', NULL, '15.00', 5, '75.00', 'USR003', 'BQ002', 'Validée', 'LOT003_PIECE', 'Pièce', 1, NULL, '', 0.00, '', ''),
('RET-20260724-170000', 'PRD005', 'CT005', 'FAC-20260724-0002', '010', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '17:00:00', '0', '0', 1, '0', 'USR003', 'BQ002', 'Valider', 'LOT005_UNITE', 'Unité (pièce)', 1, NULL, 'RET-20260724', 45000.00, 'Défectueux à l\'ouverture', 'Défectueux'),
('RET-20260724162916342', 'PRD002', 'CT002', '', '010', NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-24', '16:29:16', '0', '0', 12, '0', '1', 'BQ001', 'Valider', 'LOT002_CARTON', 'Carton de 12', 12, NULL, 'RET-20260724162916342', 0.00, 'Mauvais etat', 'Echange');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `contact`
--

INSERT INTO `contact` (`code_contact`, `nom_prenom_contact`, `telephone_contact`, `email_contact`, `type_contact`, `statut_contact`, `solde_contact`, `solde_minimum`, `solde_maximum`, `adresse_contact`, `etat_contact`) VALUES
('CT001', 'SARL Alpha', '0123456700', 'alpha@mail.com', 'FOURNISSEUR', 'ACTIF', '0.00', '0.00', '5000.00', '5 rue des Fournisseurs, Paris', 'Actif'),
('CT002', 'M. Martin Client', '0611223344', 'martin@mail.com', 'CLIENT', 'ACTIF', '250.00', '0.00', '1000.00', '18 avenue des Clients, Lyon', 'Actif'),
('CT003', 'Société Bêta', '0788990011', 'beta@mail.com', 'FOURNISSEUR', 'ACTIF', '0.00', '0.00', '2000.00', '7 rue du Commerce, Abidjan', 'Actif'),
('CT004', 'Mme KOUADIO Awa', '0122334455', 'awa.kouadio@mail.com', 'Client', 'Particulier', NULL, NULL, NULL, 'Abidjan Cocody', 'Actif'),
('CT005', 'Entreprise SNDI', '0505050505', 'contact@sndi.ci', 'Client', 'Societe', NULL, NULL, NULL, 'Abidjan Zone 4', 'Actif'),
('CT006', 'Fournisseur SOLIBRA', '0606060606', 'contact@solibra.ci', 'Fournisseur', 'Societe', NULL, NULL, NULL, 'Abidjan Yopougon', 'Actif'),
('CT007', 'SARL BAMBOU', '0707070707', 'contact@bambou.ci', 'Fournisseur', 'Societe', NULL, NULL, NULL, 'Bouaké Air France', 'Actif');

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
('DEP002', 'Entretien local', 'BQ002', 'USR003', 80.00, '2026-07-23 20:00:53', 'Nettoyage du magasin', 'VALIDE'),
('DEP003', 'Transport colis', 'BQ001', 'USR001', 5000.00, '2026-07-24 08:30:00', 'Frais de livraison client', 'VALIDE'),
('DEP004', 'Réparation imprimante', 'BQ002', 'USR003', 25000.00, '2026-07-24 09:00:00', 'Changement de tête d\'impression', 'VALIDE');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `facture`
--

INSERT INTO `facture` (`numero_facture`, `titre_facture`, `type_facture`, `categorie_facture`, `date_facture`, `montant_ht`, `taxe`, `remise`, `montant_ttc`, `avance`, `reste`, `contact_id`, `utilisateur_id`, `etat_facture`) VALUES
('FAC-20260724-0001', 'Facture client KOUADIO Awa (Laptop)', 'Client', 'Facture', '2026-07-24', '1050000', '0', '0', '1050000', '1050000', '0', 'CT004', 'USR002', 'Payer cash'),
('FAC-20260724-0002', 'Facture client SNDI (Casques)', 'Client', 'Facture', '2026-07-24', '225000', '0', '0', '225000', '225000', '0', 'CT005', 'USR003', 'Payer cash'),
('FAC-20260724-0003', 'Facture client SNDI (Riz)', 'Client', 'Facture', '2026-07-24', '30000', '0', '0', '30000', '15000', '15000', 'CT005', 'USR003', 'Partielle'),
('FAC-20260724-0004', 'Facture client KOUADIO Awa (Huile)', 'Client', 'Facture', '2026-07-24', '25500', '0', '0', '25500', '0', '25500', 'CT004', 'USR002', 'En attente'),
('FAC-20260724-0005', 'Facture client KOUADIO Awa (T-shirts)', 'Client', 'Facture', '2026-07-24', '40000', '0', '0', '40000', '40000', '0', 'CT004', 'USR001', 'Payer cash'),
('FAC-20260724-50430', 'Vente comptoir', 'Client', 'Facture', '2026-07-24', '8950', '0', '0', '8950', '8950', '0', 'CT002', '1', 'Payer cash'),
('FAC-20260724-58244', 'Vente comptoir', 'Client', 'Facture', '2026-07-24', '53500', '0', '0', '53500', '53500', '0', 'CT002', '1', 'Payer cash'),
('FAC-20260724-95116', 'Vente comptoir', 'Client', 'Facture', '2026-07-24', '15', '0', '0', '15', '15', '0', 'CT002', '1', 'Payer cash'),
('FAC-20260725-2870', 'Facture client FAC-20260725-2870', 'Client', 'Facture', '2026-07-25', '5000', '18', '0', '5900', '0', '5900', 'CT002', '1', 'En attente'),
('FAC-20260725-4901', 'Facture client FAC-20260725-4901', 'Client', 'Facture', '2026-07-25', '699', '18', '0', '824.82', '0', '824.82', 'CT005', '1', 'En attente'),
('FAC-20260725-91435', 'Vente comptoir', 'Client', 'Facture', '2026-07-25', '90000', '0', '0', '90000', '90000', '0', 'CT002', '1', 'Payer cash'),
('FAC001', 'Facture Client Martin', 'Client', 'Facture', '2026-07-22', '1165', '0', '0', '1165', '700', '465', 'CT002', 'USR002', 'Partielle'),
('FAC002', 'Facture Client Martin (T-shirt)', 'VENTE', 'CLIENT', '2026-07-23', '62.50', '20', '0', '75.00', '75.00', '0.00', 'CT002', 'USR003', 'Payée');

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
('LOT003_PIECE', 'PRD003', 'Pièce', 1, 'Actif', 0),
('LOT004_CARTON', 'PRD004', 'Carton (5 pc)', 5, 'Actif', 0),
('LOT004_UNITE', 'PRD004', 'Unité (pc)', 1, 'Actif', 0),
('LOT005_CARTON', 'PRD005', 'Carton (10 pièces)', 10, 'Actif', 0),
('LOT005_UNITE', 'PRD005', 'Unité (pièce)', 1, 'Actif', 0),
('LOT006_CARTON', 'PRD006', 'Carton (20 sacs)', 20, 'Actif', 0),
('LOT006_SAC', 'PRD006', 'Sac (5 kg)', 1, 'Actif', 0),
('LOT007_BOUTEILLE', 'PRD007', 'Bouteille (1L)', 1, 'Actif', 0),
('LOT007_CARTON', 'PRD007', 'Carton (12 bouteilles)', 12, 'Actif', 0),
('LOT008_DIZAINE', 'PRD008', 'Dizaine (10 pièces)', 10, 'Actif', 0),
('LOT008_UNITE', 'PRD008', 'Unité (pièce)', 1, 'Actif', 0);

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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `notification`
--

INSERT INTO `notification` (`id`, `objet`, `titre`, `text`, `date`, `user`, `fichier`) VALUES
(1, 'Alerte stock', 'Stock critique Smartphone', 'Le stock de Smartphone Galaxy S22 est inférieur à 5 unités.', '2026-07-23 20:00:53', 'USR001', NULL),
(2, 'Facture', 'Facture FAC001 en attente', 'Le client Martin a un reste à payer de 698.00 €.', '2026-07-23 20:00:53', 'USR002', NULL),
(3, 'Alerte stock', 'Stock bas Laptop', 'Le stock de PRD004 (Laptop) est inférieur à 3 unités.', '2026-07-24 10:00:00', 'USR001', NULL),
(4, 'Facture impayée', 'Rappel paiement', 'La facture FAC-20260724-0004 présente un solde de 25500 FCFA.', '2026-07-24 12:00:00', 'USR002', NULL);

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
('PRIX004', 'PRD002', 'BQ001', 'DETAILS', 1, 11, 450.00, 'Actif', NULL),
('PRIX005', 'PRD002', 'BQ001', 'GROS', 12, NULL, 400.00, 'Actif', NULL),
('PRIX006', 'PRD003', 'BQ002', 'DETAILS', 1, 9, 15.00, 'Actif', NULL),
('PRIX007', 'PRD003', 'BQ002', 'DEMI-GROS', 10, 49, 13.00, 'Actif', NULL),
('PRIX008', 'PRD001', NULL, 'DETAILS', 1, 11, 550.00, 'Actif', 'LOT001_CARTON'),
('PRIX009', 'PRD004', 'BQ001', 'DETAILS', 1, 2, 1050000.00, 'Actif', 'LOT004_UNITE'),
('PRIX010', 'PRD004', 'BQ001', 'DEMI-GROS', 3, 5, 1000000.00, 'Actif', 'LOT004_UNITE'),
('PRIX011', 'PRD004', 'BQ001', 'GROS', 6, NULL, 950000.00, 'Actif', 'LOT004_CARTON'),
('PRIX012', 'PRD005', 'BQ002', 'DETAILS', 1, 5, 45000.00, 'Actif', 'LOT005_UNITE'),
('PRIX013', 'PRD005', 'BQ002', 'DEMI-GROS', 6, 20, 40000.00, 'Actif', 'LOT005_UNITE'),
('PRIX014', 'PRD005', 'BQ002', 'GROS', 21, NULL, 35000.00, 'Actif', 'LOT005_CARTON'),
('PRIX015', 'PRD006', 'BQ003', 'DETAILS', 1, 5, 3000.00, 'Actif', 'LOT006_SAC'),
('PRIX016', 'PRD006', 'BQ003', 'DEMI-GROS', 6, 20, 2800.00, 'Actif', 'LOT006_SAC'),
('PRIX017', 'PRD006', 'BQ003', 'GROS', 21, NULL, 2500.00, 'Actif', 'LOT006_CARTON'),
('PRIX018', 'PRD007', 'BQ001', 'DETAILS', 1, 5, 8500.00, 'Actif', 'LOT007_BOUTEILLE'),
('PRIX019', 'PRD007', 'BQ001', 'DEMI-GROS', 6, 15, 8000.00, 'Actif', 'LOT007_BOUTEILLE'),
('PRIX020', 'PRD007', 'BQ001', 'GROS', 16, NULL, 7500.00, 'Actif', 'LOT007_CARTON'),
('PRIX021', 'PRD008', 'BQ004', 'DETAILS', 1, 3, 5000.00, 'Actif', 'LOT008_UNITE'),
('PRIX022', 'PRD008', 'BQ004', 'DEMI-GROS', 4, 10, 4500.00, 'Actif', 'LOT008_UNITE'),
('PRIX023', 'PRD008', 'BQ004', 'GROS', 11, NULL, 4000.00, 'Actif', 'LOT008_DIZAINE');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `produit`
--

INSERT INTO `produit` (`code_produit`, `titre_produit`, `prix_fournisseur`, `prix_produit`, `benefice_produit`, `stock_alerte`, `stock_produit`, `categorie_id`, `description_produit`, `photo`, `type_photo`, `etat_produit`) VALUES
('PRD001', 'Smartphone Galaxy S22', '450.00', '699.00', '55.33', '5', '39', 'CAT001', 'Smartphone dernière génération', NULL, 'image/jpeg', 'Actif'),
('PRD002', 'Lait 1L', '0.80', '1.20', '50.00', '20', '171', 'CAT002', 'Lait entier UHT', NULL, 'image/jpeg', 'Actif'),
('PRD003', 'T-shirt blanc', '8.00', '15.00', '87.50', '10', '82', 'CAT003', 'T-shirt en coton 100%', NULL, 'image/jpeg', 'Actif'),
('PRD004', 'Laptop Dell XPS 13', '800000', '1050000', '250000', '3', '15', 'CAT001', 'Ordinateur portable haute performance', NULL, 'image/jpeg', 'Actif'),
('PRD005', 'Casque Audio JBL', '25000', '45000', '20000', '5', '27', 'CAT001', 'Casque Bluetooth sans fil', NULL, 'image/jpeg', 'Actif'),
('PRD006', 'Riz étuvé 5kg', '2500', '3000', '500', '20', '200', 'CAT002', 'Riz de qualité', NULL, 'image/jpeg', 'Actif'),
('PRD007', 'Huile d\'olive 1L', '6000', '8500', '2500', '10', '48', 'CAT002', 'Huile d\'olive vierge extra', NULL, 'image/jpeg', 'Actif'),
('PRD008', 'T-shirt manches longues', '3000', '5000', '2000', '10', '60', 'CAT003', 'T-shirt en coton bio', NULL, 'image/jpeg', 'Actif');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `statut`
--

INSERT INTO `statut` (`code_statut`, `titre_statut`, `type_statut`, `symbole_statut`, `etat_statut`) VALUES
('001', 'Mauvais etat', 'sortie', '', 'Actif'),
('002', 'Vol/Perte', 'sortie', '', 'Actif'),
('003', 'Cadeau', 'sortie', '', 'Actif'),
('004', 'Surplus', 'sortie', '', 'Actif'),
('006', 'Stock d\'entree', 'entree', '', 'Actif'),
('007', 'Stock de sortie', 'sortie', '', 'Actif'),
('008', 'Transfert - Sortie', 'sortie', '', 'Actif'),
('009', 'Transfert - Entrée', 'entree', '', 'Actif'),
('010', 'Retour SAV', 'entree', '', 'Actif'),
('011', 'Achat', 'entree', '', 'Actif'),
('012', 'Vente', 'sortie', '', 'Actif');

-- --------------------------------------------------------

--
-- Structure de la table `stock_boutique`
--

DROP TABLE IF EXISTS `stock_boutique`;
CREATE TABLE IF NOT EXISTS `stock_boutique` (
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `boutique_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `quantite` int NOT NULL DEFAULT '0' COMMENT 'Toujours en unité de base (ex: pièce, litre)',
  `quantite_reservee` int NOT NULL DEFAULT '0',
  `stock_alerte` int DEFAULT '10',
  `maj_le` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lot_produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `quantite_lot` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`produit_id`,`boutique_id`),
  KEY `idx_sb_produit` (`produit_id`),
  KEY `idx_sb_boutique` (`boutique_id`),
  KEY `fk_stock_boutique_lot` (`lot_produit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stock_boutique`
--

INSERT INTO `stock_boutique` (`produit_id`, `boutique_id`, `quantite`, `quantite_reservee`, `stock_alerte`, `maj_le`, `lot_produit_id`, `quantite_lot`) VALUES
('PRD001', 'BQ001', 27, 1, 5, '2026-07-25 15:23:02', 'LOT001_CARTON', 2),
('PRD001', 'BQ002', 20, 0, 5, '2026-07-24 16:44:22', 'LOT001_CARTON', 2),
('PRD002', 'BQ001', 172, 0, 20, '2026-07-24 16:29:16', NULL, 0),
('PRD003', 'BQ002', 83, 0, 10, '2026-07-24 16:44:22', 'LOT003_PIECE', 17),
('PRD004', 'BQ001', 15, 0, 3, '2026-07-25 15:23:02', 'LOT004_CARTON', 1),
('PRD005', 'BQ002', 30, 0, 5, '2026-07-24 17:16:48', NULL, 0),
('PRD006', 'BQ003', 200, 0, 20, '2026-07-24 17:16:48', NULL, 0),
('PRD007', 'BQ001', 50, 0, 10, '2026-07-24 17:16:48', NULL, 0),
('PRD008', 'BQ003', 40, 0, 10, '2026-07-24 17:16:48', NULL, 0),
('PRD008', 'BQ004', 60, 1, 10, '2026-07-25 21:59:44', NULL, 0);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `taxe`
--

INSERT INTO `taxe` (`code_taxe`, `titre_taxe`, `taux_taxe`, `type_taxe`, `etat_taxe`) VALUES
('TAX001', 'TVA 20%', '20', 'TVA', 'Actif'),
('TAX002', 'REMISE 5.5%', '5.5', 'Remise', 'Actif');

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
('TR-20260724-001', '2026-07-24', '14:05:00', '1050000', '0', '1050000', 'Encaissement', 'Paiement comptant', 'CT004', 'FAC-20260724-0001', 'Espece', NULL, NULL, 'USR002', 'Succes'),
('TR-20260724-002', '2026-07-24', '14:35:00', '225000', '0', '225000', 'Encaissement', 'Paiement comptant', 'CT005', 'FAC-20260724-0002', 'Espece', NULL, NULL, 'USR003', 'Succes'),
('TR-20260724-003', '2026-07-24', '15:05:00', '15000', '0', '15000', 'Encaissement', 'Acompte', 'CT005', 'FAC-20260724-0003', 'Virement', 'VIR20260724', 'ORDRE001', 'USR003', 'Succes'),
('TR-20260724-004', '2026-07-24', '16:05:00', '40000', '0', '40000', 'Encaissement', 'Paiement comptant', 'CT004', 'FAC-20260724-0005', 'Espece', NULL, NULL, 'USR001', 'Succes'),
('TR-20260724113325965', '2026-07-24', '11:33:25', '15', '0', '15', 'Encaissement', NULL, 'CT002', 'FAC-20260724-95116', 'Espece', NULL, NULL, '1', 'Succes'),
('TR-20260724222818316', '2026-07-24', '22:28:18', '8950', '0', '8950', 'Encaissement', NULL, 'CT002', 'FAC-20260724-50430', 'Espece', NULL, NULL, '1', 'Succes'),
('TR-20260724224843502', '2026-07-24', '22:48:43', '53500', '0', '53500', 'Encaissement', NULL, 'CT002', 'FAC-20260724-58244', 'Espece', NULL, NULL, '1', 'Succes'),
('TR-20260725152127607', '2026-07-25', '15:21:27', '90000', '0', '90000', 'Encaissement', NULL, 'CT002', 'FAC-20260725-91435', 'Espece', NULL, NULL, '1', 'Succes'),
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
('USR002', 'MAT002', 'Marie Curie', '1990-07-22', 'Lyon', 'F', 'mcurie', '1', '0698765432', 'mcurie@mail.com', 'Caissière', 'Française', 'Lyon', '45 avenue des Fleurs', NULL, 'CAISSIER', '2026-07-23', NULL, 'photo', 'Actif'),
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `visiteur`
--

INSERT INTO `visiteur` (`id`, `date_connexion`, `heure_connexion`, `date_deconnexion`, `heure_deconnexion`, `duree_date`, `duree_heure`, `reference`, `etat_connexion`) VALUES
(1, '2026-07-23', '08:30:00', '2026-07-23', '18:30:00', '0', '10:00:00', 'VIS001', 'Terminée'),
(2, '2026-07-23', '09:00:00', '2026-07-23', '17:30:00', '0', '08:30:00', 'VIS002', 'Terminée'),
(3, '2026-07-24', '08:00:00', '2026-07-24', '18:00:00', '0', '10:00:00', 'VIS003', 'Terminée'),
(4, '2026-07-24', '09:00:00', '2026-07-24', '17:30:00', '0', '08:30:00', 'VIS004', 'Terminée');

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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `vue`
--

INSERT INTO `vue` (`id`, `notification`, `user`, `lecture`, `affichage`) VALUES
(1, '1', 'USR001', 'Oui', 'Lu'),
(2, '2', 'USR002', 'Oui', 'Lu'),
(3, '3', 'USR001', 'Oui', 'Lu'),
(4, '4', 'USR002', 'Non', 'Non lu');

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
