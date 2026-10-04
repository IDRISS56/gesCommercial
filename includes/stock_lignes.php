<?php
// includes/stock_lignes.php – Saisie multi-lignes des mouvements de stock
// (entrée, sortie, ajustement) : lecture et vérifications communes côté serveur.
//
// Principe : l'utilisateur choisit la boutique et la catégorie, ajoute autant de lignes
// produit qu'il veut, puis valide UNE fois. Toutes les lignes sont traitées dans une seule
// transaction : si une ligne est refusée (stock insuffisant, produit hors catégorie…),
// rien n'est enregistré.
// Les lignes arrivent dans $_POST['lignes'] = [ ['produit_id'=>..,'quantite'=>..., ...], ... ].

const STOCK_LIGNES_MAX = 100;

/**
 * Lignes postées, nettoyées : on ignore celles sans produit et on trie par code produit.
 * Ce tri donne le même ordre de verrouillage à deux saisies simultanées (évite les deadlocks).
 */
function stockLireLignes($brut): array
{
    $lignes = [];
    if (!is_array($brut)) return $lignes;
    foreach ($brut as $l) {
        if (!is_array($l)) continue;
        $code = trim((string) ($l['produit_id'] ?? ''));
        if ($code === '') continue;
        $l['produit_id'] = $code;
        $lignes[] = $l;
    }
    usort($lignes, function ($a, $b) { return strcmp($a['produit_id'], $b['produit_id']); });
    return $lignes;
}

/**
 * Charge les produits des lignes et vérifie : pas de doublon, produit existant, produit de la
 * catégorie choisie. À appeler dans la transaction. Lève une Exception (texte brut, sans HTML).
 * @return array [code_produit => ligne produit (titre_produit, categorie_id, prix_fournisseur, prix_produit)]
 */
function stockChargerProduits(PDO $pdo, array $lignes, string $categorieId): array
{
    $codes = array_column($lignes, 'produit_id');
    if (count($codes) !== count(array_unique($codes))) {
        throw new Exception("Un même produit ne peut figurer qu'une seule fois dans la liste.");
    }
    $in = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $pdo->prepare("SELECT code_produit, titre_produit, categorie_id, prix_fournisseur, prix_produit
                           FROM produit WHERE code_produit IN ($in)");
    $stmt->execute($codes);
    $infos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $infos[$r['code_produit']] = $r;
    }
    foreach ($codes as $code) {
        if (!isset($infos[$code])) {
            throw new Exception("Produit introuvable : $code.");
        }
        if ((string) ($infos[$code]['categorie_id'] ?? '') !== $categorieId) {
            throw new Exception("Le produit « " . $infos[$code]['titre_produit'] . " » n'appartient pas à la catégorie choisie.");
        }
    }
    return $infos;
}

/** Données produits pour le JS : [{code, titre, categorie, inactif}, ...] (pour json_encode). */
function stockProduitsPourJs(array $produits): array
{
    return array_map(function ($p) {
        return [
            'code'      => (string) $p['code_produit'],
            'titre'     => (string) $p['titre_produit'],
            'categorie' => (string) ($p['categorie_id'] ?? ''),
            'inactif'   => (($p['etat_produit'] ?? '') === 'Inactif'),
        ];
    }, $produits);
}