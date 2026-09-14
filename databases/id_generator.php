<?php
/**
 * databases/id_generator.php
 *
 * Génération atomique d'identifiants séquencés du type PREFIXE-AAAAMMJJ-00001,
 * SANS ajout de table à la base (contrainte : limiter les changements de
 * schéma). Remplace le pattern "SELECT COUNT(*)/MAX(...) LIKE 'PREFIXE-%' puis
 * +1" utilisé jusqu'ici : ce pattern n'est pas atomique — deux requêtes
 * concurrentes peuvent lire le même compteur avant qu'aucune des deux n'ait
 * inséré sa ligne, et donc calculer le même identifiant.
 *
 * Principe : on s'appuie sur la contrainte PRIMARY KEY déjà existante sur
 * numero_commande. Si deux requêtes calculent le même numéro, la seconde
 * insertion échoue avec une erreur MySQL 1062 (Duplicate entry) — dans ce
 * cas, on recalcule le numéro suivant et on réessaie, jusqu'à quelques
 * tentatives. Pas de table supplémentaire, pas de migration à exécuter.
 */

if (!function_exists('genererIdSequence')) {
    /**
     * @param PDO    $pdo
     * @param string $prefixe    Ex: 'MV', 'SC', 'ENT'
     * @param int    $largeur    Nombre de chiffres du compteur (padding), défaut 5
     * @param string $dateFormat Format de date inclus dans l'identifiant (défaut Ymd = numérotation par jour)
     * @return string L'identifiant complet, ex: "MV-20260829-00042"
     */
    function genererIdSequence(PDO $pdo, string $prefixe, int $largeur = 5, string $dateFormat = 'Ymd'): string
    {
        $suffixeDate = date($dateFormat);
        $motif = $prefixe . '-' . $suffixeDate . '-%';

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM commande WHERE numero_commande LIKE ?");
        $stmt->execute([$motif]);
        $prochain = ((int) $stmt->fetchColumn()) + 1;

        return $prefixe . '-' . $suffixeDate . '-' . str_pad((string) $prochain, $largeur, '0', STR_PAD_LEFT);
    }

    /**
     * À utiliser à la place d'un simple genererIdSequence() + INSERT quand on
     * veut une garantie d'unicité même sous forte concurrence : exécute
     * $inserer(un numéro) en boucle, et si l'insertion échoue pour cause de
     * doublon sur numero_commande (erreur MySQL 1062), recalcule un nouveau
     * numéro et réessaie.
     *
     * @param PDO      $pdo
     * @param string   $prefixe
     * @param callable $inserer  function(string $numero): void — doit lever
     *                           une PDOException (laisser remonter celle du
     *                           driver) en cas d'échec d'insertion.
     * @param int      $largeur
     * @param string   $dateFormat
     * @param int      $maxTentatives
     * @return string Le numéro effectivement inséré.
     */
    function genererEtInsererIdSequence(PDO $pdo, string $prefixe, callable $inserer, int $largeur = 5, string $dateFormat = 'Ymd', int $maxTentatives = 5): string
    {
        for ($tentative = 0; $tentative < $maxTentatives; $tentative++) {
            $numero = genererIdSequence($pdo, $prefixe, $largeur, $dateFormat);
            try {
                $inserer($numero);
                return $numero;
            } catch (PDOException $e) {
                // 23000 = violation de contrainte d'intégrité (dont clé dupliquée).
                // errorInfo[1] === 1062 = code MySQL spécifique "Duplicate entry".
                $estDoublon = ($e->getCode() === '23000') && (($e->errorInfo[1] ?? null) === 1062);
                if ($estDoublon && $tentative < $maxTentatives - 1) {
                    continue; // un autre mouvement a pris ce numéro entre-temps : on réessaie
                }
                throw $e;
            }
        }
        throw new RuntimeException('Impossible de générer un numéro de commande unique après plusieurs tentatives.');
    }
}
