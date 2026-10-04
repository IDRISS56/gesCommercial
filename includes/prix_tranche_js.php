<script>
// Tranches de prix par quantité (voir includes/prix_tranche.php).
// PrixTranche.prix(tranches, qte, prixBase) : prix unitaire à proposer pour `qte` unités de base.
// `tranches` = [{quantite_min, prix_unitaire, libelle_tranche}, ...] triées croissant ; vide/absent => prixBase.
// PRIX DÉTAIL : la tranche au libellé « Detail » (dès 1 unité) est le prix de base quand elle est définie ;
// sinon `prixBase` (= produit.prix_produit) sert de prix détail. Demi-gros / Gros s'appliquent en dessous.
window.PrixTranche = (function () {
    var DETAIL = 'Detail';
    function estDetail(t) { return !!t && t.libelle_tranche === DETAIL; }
    function affiche(lib) { return lib === DETAIL ? 'Détail' : (lib || ''); }

    // Prix de base effectif : prix « Detail » s'il est défini (> 0), sinon prixBase (prix_produit)
    function base(tranches, prixBase) {
        var b = parseFloat(prixBase) || 0;
        (tranches || []).forEach(function (t) {
            if (estDetail(t) && parseFloat(t.prix_unitaire) > 0) b = parseFloat(t.prix_unitaire);
        });
        return b;
    }

    return {
        base: base,
        prix: function (tranches, qte, prixBase) {
            var b = base(tranches, prixBase), prix = b, q = parseFloat(qte) || 0;
            (tranches || []).forEach(function (t) {
                if (estDetail(t)) return; // déjà pris en compte dans b
                if (q >= parseFloat(t.quantite_min)) prix = Math.min(b, parseFloat(t.prix_unitaire));
            });
            return prix;
        },
        // Petit texte d'aide affiché à côté du prix : « Détail 5 500 · Demi-gros dès 10 → 5 000 · Gros dès 100 → 4 500 »
        resume: function (tranches) {
            if (!tranches || !tranches.length) return '';
            return tranches.map(function (t) {
                var px = Math.round(parseFloat(t.prix_unitaire)).toLocaleString('fr-FR');
                if (estDetail(t)) return 'Détail ' + px;
                return (t.libelle_tranche ? t.libelle_tranche + ' ' : '') + 'dès ' + t.quantite_min + ' → ' + px;
            }).join(' · ');
        },
        // Libellé de la tranche atteinte pour `qte` unités ('' si aucune : prix de base = prix_produit)
        libelle: function (tranches, qte) {
            var lib = '', q = parseFloat(qte) || 0;
            (tranches || []).forEach(function (t) { if (q >= parseFloat(t.quantite_min)) lib = affiche(t.libelle_tranche); });
            return lib;
        }
    };
})();
</script>