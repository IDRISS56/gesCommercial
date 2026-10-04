<?php
// includes/prix_tranche.php – Tranches de prix de vente par quantité.
// Voir databases/migration_tranches_prix.sql.
//
// Règles :
//  - une tranche = « à partir de quantite_min unités (de base), prix unitaire = prix_unitaire » ;
//  - les tranches d'un produit ne comptent QUE si produit.tranche_active = 1 ;
//  - sinon (ou si la migration n'est pas passée) seul produit.prix_produit est utilisé ;
//  - PRIX DÉTAIL : une tranche au libellé « Detail » (toujours « dès 1 unité ») est le prix de base
//    du produit quand il est défini ; s'il n'est PAS défini, c'est produit.prix_produit qui sert de
//    prix détail. Les tranches Demi-gros / Gros s'appliquent ensuite en dessous de ce prix de base.

/**
 * Libellés possibles d'une tranche (ex. Demi-gros, Gros) : lus dans la définition de l'ENUM
 * prix_tranche.libelle_tranche, donc modifiables par un simple ALTER TABLE.
 * Retourne ['Detail','Demi-gros','Gros'] si la colonne n'est pas encore installée.
 */
const LIBELLE_DETAIL = 'Detail';

function libellesTranche(PDO $pdo): array
{
    try {
        $type = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prix_tranche' AND COLUMN_NAME = 'libelle_tranche'")
                    ->fetchColumn();
        if ($type && preg_match_all("/'((?:[^']|'')*)'/u", $type, $m) && !empty($m[1])) {
            $vals = array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
            // « Detail » (prix détail) toujours en premier dans les listes de choix
            if (in_array(LIBELLE_DETAIL, $vals, true)) {
                $vals = array_merge([LIBELLE_DETAIL], array_values(array_diff($vals, [LIBELLE_DETAIL])));
            }
            return $vals;
        }
    } catch (Exception $e) { /* repli ci-dessous */ }
    return ['Detail','Demi-gros', 'Gros'];
}

/**
 * Tranches ACTIVES des produits donnés : [code_produit => [['quantite_min'=>int,'prix_unitaire'=>float,'libelle_tranche'=>string], ...]]
 * (triées par quantité croissante). Un produit sans tranche active est absent du résultat.
 * Ne lève jamais d'exception : si la table n'existe pas encore, retourne [].
 */
function chargerTranchesActives(PDO $pdo, array $codesProduits): array
{
    $codesProduits = array_values(array_unique(array_filter($codesProduits, fn($c) => $c !== null && $c !== '')));
    if (empty($codesProduits)) return [];
    try {
        $in = implode(',', array_fill(0, count($codesProduits), '?'));
        $stmt = $pdo->prepare("SELECT t.produit_id, t.quantite_min, t.prix_unitaire, t.libelle_tranche
                               FROM prix_tranche t
                               JOIN produit p ON p.code_produit = t.produit_id AND p.tranche_active = 1
                               WHERE t.produit_id IN ($in)
                               ORDER BY t.produit_id, t.quantite_min ASC");
        $stmt->execute($codesProduits);
        $res = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $res[$r['produit_id']][] = ['quantite_min' => (int) $r['quantite_min'], 'prix_unitaire' => (float) $r['prix_unitaire'], 'libelle_tranche' => (string) $r['libelle_tranche']];
        }
        return $res;
    } catch (Exception $e) {
        return [];
    }
}

/** Ajoute la clé 'tranches' (tableau, éventuellement vide) à chaque ligne produit. */
function joindreTranches(PDO $pdo, array $produits, string $cle = 'code_produit'): array
{
    $t = chargerTranchesActives($pdo, array_column($produits, $cle));
    foreach ($produits as &$p) {
        $p['tranches'] = $t[$p[$cle]] ?? [];
    }
    unset($p);
    return $produits;
}

/**
 * Prix détail défini parmi les tranches (libellé « Detail », prix > 0), ou null s'il n'y en a pas.
 * $tranches peut venir de la base (chaînes) ou de chargerTranchesActives().
 */
function prixDetailDefini(array $tranches): ?float
{
    foreach ($tranches as $t) {
        if (($t['libelle_tranche'] ?? '') === LIBELLE_DETAIL && (float) ($t['prix_unitaire'] ?? 0) > 0) {
            return (float) $t['prix_unitaire'];
        }
    }
    return null;
}

/** Prix de base effectif : le prix détail s'il est défini, sinon produit.prix_produit ($prixBase). */
function prixBaseEffectif(float $prixBase, array $tranches): float
{
    $detail = prixDetailDefini($tranches);
    return $detail !== null ? $detail : $prixBase;
}

/**
 * Prix unitaire pour une quantité (en unités de base) : tranche Demi-gros/Gros la plus haute atteinte,
 * sinon le prix détail s'il est défini, sinon le prix de base du produit.
 */
function prixPourQuantite(float $prixBase, array $tranches, float $quantite): float
{
    $base = prixBaseEffectif($prixBase, $tranches);
    $prix = $base;
    foreach ($tranches as $t) {
        if (($t['libelle_tranche'] ?? '') === LIBELLE_DETAIL) continue; // déjà pris en compte dans $base
        // min() : une tranche ne peut jamais AUGMENTER le prix par rapport au prix de base effectif.
        if ($quantite >= $t['quantite_min']) $prix = min($base, $t['prix_unitaire']);
    }
    return $prix;
}

/**
 * Valide une grille de tranches saisie. $lignes = [['quantite_min'=>..,'prix_unitaire'=>..,'libelle_tranche'=>..], ...].
 * Retourne ['ok'=>bool, 'erreur'=>string, 'lignes'=>tranches nettoyées et triées].
 * Règles :
 *  - « Detail » (prix détail) : facultatif, une seule ligne, toujours dès 1 unité (la quantité saisie est
 *    ignorée) ; une ligne Detail sans prix est ignorée = prix détail non défini, on prend produit.prix_produit.
 *    Son prix peut être supérieur ou inférieur à prix_produit ;
 *  - autres tranches : quantités entières >= 2 et toutes différentes ; prix > 0 ; prix strictement
 *    inférieurs au prix précédent, en partant du prix détail s'il est défini, sinon de prix_produit ;
 *  - jamais sous le prix fournisseur (pas de vente à perte) ;
 *  - libellé obligatoire et pris dans $libellesAutorises (liste de l'ENUM, cf. libellesTranche()).
 */
function validerTranches(array $lignes, float $prixBase, float $prixFournisseur, array $libellesAutorises = ['Detail','Demi-gros', 'Gros']): array
{
    $propres = [];
    $nbDetail = 0;
    foreach ($lignes as $i => $l) {
        $q = $l['quantite_min'] ?? '';
        $p = $l['prix_unitaire'] ?? '';
        $lib = trim((string) ($l['libelle_tranche'] ?? ''));
        $estDetail = ($lib === LIBELLE_DETAIL);
        // ligne vide ignorée (le libellé seul ne compte pas) ; ligne Detail sans prix = pas de prix détail
        if ($p === '' && ($q === '' || $estDetail)) continue;
        $n = $i + 1;
        if ($estDetail) $q = 1; // le prix détail vaut toujours dès 1 unité
        if (!is_numeric($q) || (float) $q != (int) $q || (int) $q < ($estDetail ? 1 : 2)) {
            return ['ok' => false, 'erreur' => "Tranche $n : la quantité doit être un entier d'au moins 2 (le prix détail vaut toujours dès 1 unité).", 'lignes' => []];
        }
        if (!is_numeric($p) || (float) $p <= 0) {
            return ['ok' => false, 'erreur' => "Tranche $n : le prix doit être supérieur à 0.", 'lignes' => []];
        }
        if (!in_array($lib, $libellesAutorises, true)) {
            return ['ok' => false, 'erreur' => "Tranche $n : choisissez un libellé (" . implode(' / ', $libellesAutorises) . ").", 'lignes' => []];
        }
        if ($estDetail && ++$nbDetail > 1) {
            return ['ok' => false, 'erreur' => "Un seul prix détail est possible par produit (tranche $n en trop).", 'lignes' => []];
        }
        $propres[] = ['quantite_min' => (int) $q, 'prix_unitaire' => round((float) $p, 2), 'libelle_tranche' => $lib];
    }
    usort($propres, fn($a, $b) => $a['quantite_min'] <=> $b['quantite_min']);

    // Point de départ de la chaîne de prix : prix détail s'il est défini, sinon prix de vente normal
    $prixDetail = prixDetailDefini($propres);
    $precedentQ = null;
    $precedentP = $prixDetail !== null ? $prixDetail : ($prixBase > 0 ? $prixBase : null);
    $nomPrecedent = $prixDetail !== null ? 'prix détail' : 'prix de vente normal';
    foreach ($propres as $t) {
        if ($t['libelle_tranche'] === LIBELLE_DETAIL) {
            if ($prixFournisseur > 0 && $t['prix_unitaire'] < $prixFournisseur) {
                return ['ok' => false, 'erreur' => "Le prix détail ({$t['prix_unitaire']}) est inférieur au prix d'achat ({$prixFournisseur}) : vente à perte refusée.", 'lignes' => []];
            }
            $precedentQ = $t['quantite_min'];
            continue;
        }
        if ($precedentQ !== null && $t['quantite_min'] === $precedentQ) {
            return ['ok' => false, 'erreur' => "La quantité {$t['quantite_min']} est utilisée deux fois.", 'lignes' => []];
        }
        if ($precedentP !== null && $t['prix_unitaire'] >= $precedentP) {
            return ['ok' => false, 'erreur' => "À partir de {$t['quantite_min']} unités, le prix ({$t['prix_unitaire']}) doit être inférieur au {$nomPrecedent} ({$precedentP}).", 'lignes' => []];
        }
        if ($prixFournisseur > 0 && $t['prix_unitaire'] < $prixFournisseur) {
            return ['ok' => false, 'erreur' => "À partir de {$t['quantite_min']} unités, le prix ({$t['prix_unitaire']}) est inférieur au prix d'achat ({$prixFournisseur}) : vente à perte refusée.", 'lignes' => []];
        }
        $precedentQ = $t['quantite_min'];
        $precedentP = $t['prix_unitaire'];
        $nomPrecedent = 'prix précédent';
    }
    return ['ok' => true, 'erreur' => '', 'lignes' => $propres];
}