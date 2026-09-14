<?php
// Extrait de vente_comptoir.php (découpage du fichier — voir audit technique) :
// chargement des données nécessaires au gabarit HTML (taxes, catégories,
// boutiques/clients autorisés, infos caisse et utilisateur courant).

// - RÉCUPÉRATION DES DONNÉES POUR LA PAGE -
$taxes = $pdo->query("SELECT * FROM taxe WHERE etat_taxe = 'ACTIF' ORDER BY type_taxe, taux_taxe")->fetchAll();

$categories = $pdo->query("SELECT DISTINCT c.titre_categorie
                           FROM produit p
                           JOIN categorie c ON p.categorie_id = c.code_categorie
                           WHERE c.titre_categorie IS NOT NULL AND c.titre_categorie <> ''
                           ORDER BY c.titre_categorie ASC")->fetchAll(PDO::FETCH_COLUMN);
// Remarque : cette liste n'est PAS filtrée ici par boutique — tous les
// boutons de catégorie sont rendus, puis affichés/masqués dynamiquement côté
// JS selon la boutique sélectionnée (voir CATEGORIES_AUTORISEES_PAR_BOUTIQUE_COMPTOIR
// et filtrerCategoriesParBoutique() dans vente_comptoir.php). Ça permet de
// changer de boutique sans recharger la page tout en gardant le bon filtre.

// Boutiques actives (sélecteur en haut du panier) : la boutique connectée reste
// pré-sélectionnée par défaut ; le vendeur ne peut choisir que parmi les
// boutiques auxquelles il a accès ($boutiquesAutorisees), sauf
// Administrateur/Superviseur qui voient tout.
if (empty($boutiquesAutorisees)) {
    $boutiquesListe = [];
} else {
    $inPh = implode(',', array_fill(0, count($boutiquesAutorisees), '?'));
    $stmtB = $pdo->prepare("SELECT code_boutique, nom_boutique FROM boutique WHERE etat_boutique = 'Actif' AND code_boutique IN ($inPh) ORDER BY nom_boutique");
    $stmtB->execute($boutiquesAutorisees);
    $boutiquesListe = $stmtB->fetchAll(PDO::FETCH_ASSOC);
}

// Catégories autorisées par boutique (restriction optionnelle, voir
// config/authentification.php::getCategoriesAutoriseesBoutique). Ici, le
// filtre catégorie de la caisse fonctionne par LIBELLÉ (titre_categorie), pas
// par code — on convertit donc les codes autorisés en titres pour chaque
// boutique du sélecteur.
$categoriesAutoriseesParBoutiqueComptoir = [];
foreach ($boutiquesAutorisees as $bId) {
    $codesAutorises = getCategoriesAutoriseesBoutique($pdo, $user['role'] ?? null, $bId);
    if ($codesAutorises === null) {
        $categoriesAutoriseesParBoutiqueComptoir[$bId] = null;
    } else {
        if (empty($codesAutorises)) {
            $categoriesAutoriseesParBoutiqueComptoir[$bId] = [];
        } else {
            $inPhCat = implode(',', array_fill(0, count($codesAutorises), '?'));
            $stmtTitres = $pdo->prepare("SELECT titre_categorie FROM categorie WHERE code_categorie IN ($inPhCat)");
            $stmtTitres->execute($codesAutorises);
            $categoriesAutoriseesParBoutiqueComptoir[$bId] = $stmtTitres->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

// Clients actifs (sélecteur client) : "Client comptoir" reste la valeur par
// défaut si rien n'est choisi.
$clientsListe = $pdo->query("SELECT code_contact, nom_prenom_contact, telephone_contact FROM contact WHERE type_contact = 'Client' AND etat_contact = 'Actif' AND code_contact <> '" . CLIENT_COMPTOIR_CODE . "' ORDER BY nom_prenom_contact")->fetchAll(PDO::FETCH_ASSOC);

$caisse = null;
if (defined('CAISSE_ID')) {
    $stmtCaisseInfo = $pdo->prepare("SELECT * FROM caisse WHERE caisse_id = ?");
    $stmtCaisseInfo->execute([CAISSE_ID]);
    $caisse = $stmtCaisseInfo->fetch();
}

$stmtUserInfo = $pdo->prepare("SELECT * FROM utilisateur WHERE id = ?");
$stmtUserInfo->execute([USER_ID]);
$userInfo = $stmtUserInfo->fetch();