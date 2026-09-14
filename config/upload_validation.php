<?php
/**
 * config/upload_validation.php
 *
 * Validation SERVEUR des images uploadées (photos produit/catégorie, logo
 * boutique). Ne jamais faire confiance à $_FILES[...]['type'] : c'est
 * l'en-tête envoyé par le navigateur, donc falsifiable à volonté. On
 * vérifie ici le contenu réel du fichier.
 */

if (!function_exists('validerImageUploadee')) {
    /**
     * @param array $fichier Une entrée de $_FILES (ex. $_FILES['photo_produit']).
     * @param int   $tailleMaxOctets Taille maximale acceptée (par défaut 4 Mo).
     * @return array{ok: bool, erreur: ?string, contenu: ?string, type_mime: ?string}
     *         'contenu' est le contenu binaire du fichier prêt à être stocké,
     *         'type_mime' est le type MIME RÉEL détecté (jamais celui envoyé par le navigateur).
     */
    function validerImageUploadee(array $fichier, int $tailleMaxOctets = 4 * 1024 * 1024): array
    {
        $echec = fn(string $msg) => ['ok' => false, 'erreur' => $msg, 'contenu' => null, 'type_mime' => null];

        if (!isset($fichier['error']) || $fichier['error'] !== UPLOAD_ERR_OK) {
            return $echec("Échec de l'envoi du fichier.");
        }

        // Vérifie que le fichier a bien été uploadé via HTTP POST (protection
        // contre une manipulation directe de tmp_name).
        if (!is_uploaded_file($fichier['tmp_name'])) {
            return $echec('Fichier invalide.');
        }

        if ($fichier['size'] > $tailleMaxOctets) {
            $maxMo = round($tailleMaxOctets / 1024 / 1024, 1);
            return $echec("Le fichier dépasse la taille maximale autorisée ({$maxMo} Mo).");
        }

        // getimagesize() lit les octets réels du fichier : un fichier renommé
        // en .jpg mais qui n'est pas une vraie image sera rejeté ici, quel
        // que soit le Content-Type envoyé par le navigateur.
        $infos = @getimagesize($fichier['tmp_name']);
        if ($infos === false) {
            return $echec("Le fichier n'est pas une image valide.");
        }

        $typesAutorises = [
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG  => 'image/png',
            IMAGETYPE_GIF  => 'image/gif',
            IMAGETYPE_WEBP => 'image/webp',
        ];

        if (!isset($typesAutorises[$infos[2]])) {
            return $echec('Format d\'image non autorisé (formats acceptés : JPEG, PNG, GIF, WEBP).');
        }

        $contenu = file_get_contents($fichier['tmp_name']);
        if ($contenu === false) {
            return $echec('Impossible de lire le fichier envoyé.');
        }

        return [
            'ok' => true,
            'erreur' => null,
            'contenu' => $contenu,
            'type_mime' => $typesAutorises[$infos[2]], // type réel, jamais celui du navigateur
        ];
    }
}
