<style>
/* Saisie multi-lignes des mouvements de stock (voir includes/stock_lignes.php) */
.sl-panel { background: var(--color-gray-50, #f8fafc); border: 1px dashed var(--color-gray-300, #cbd5e1); border-radius: 12px; padding: 14px; margin-top: 16px; }
.sl-table th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; white-space: nowrap; }
.sl-table td { vertical-align: middle; }
.sl-table input.form-control, .sl-table select.form-select { min-width: 90px; }
.sl-table tr.sl-err td { background: #fee2e2; }
.sl-vide { text-align: center; color: #94a3b8; padding: 22px 10px; border: 1px dashed #e2e8f0; border-radius: 12px; margin-top: 14px; font-size: 13px; }
#slInfo { display: flex; gap: 24px; flex-wrap: wrap; margin-top: 12px; font-size: 13px; }
#slInfo .label { font-weight: 600; color: #64748b; margin-right: 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
.sl-alert { display: none; border-radius: 10px; padding: 8px 12px; font-size: 13px; font-weight: 500; margin-bottom: 10px; }
.sl-alert.sl-danger { background: #fee2e2; color: #991b1b; }
.sl-alert.sl-success { background: #d1fae5; color: #065f46; }
.sl-alert.sl-info { background: #cffafe; color: #155e75; }
</style>
<script>
// Saisie multi-lignes des mouvements de stock (entrée / sortie / ajustement).
// StockLignes.init({ mode, produits, catsParBoutique, motifs, qteDefaut, sel:{...} }) – voir chaque page.
// L'utilisateur choisit boutique + catégorie, ajoute autant de produits qu'il veut dans la liste,
// puis valide UNE fois (les lignes sont postées dans lignes[i][...]).
window.StockLignes = (function ($) {
    'use strict';

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function attr(s) { return esc(s).replace(/"/g, '&quot;'); }
    function nb(n) { return (Math.round(parseFloat(n) || 0)).toLocaleString('fr-FR'); }

    function init(cfg) {
        var S = cfg.sel, mode = cfg.mode;
        var $b = $(S.boutique), $c = $(S.categorie), $p = $(S.produit), $tbody = $(S.tbody);
        var idx = 0, dernierCode = '', alertTimer = null;
        var prevB = String($b.val() || ''), prevC = String($c.val() || '');

        // Toutes les catégories (la liste est filtrée selon la boutique choisie)
        var toutesCats = [];
        $c.find('option').each(function () {
            if ($(this).val() !== '') toutesCats.push({ value: $(this).val(), text: $(this).text().trim() });
        });

        // ---------- utilitaires ----------
        function rebuild($sel) {
            if ($.fn.selectpicker) {
                if ($sel.data('selectpicker') || $sel.hasClass('bs-select-hidden')) $sel.selectpicker('destroy');
                $sel.selectpicker();
            }
        }
        function notify(msg, type) {
            var $a = $('#slAlert');
            if (!$a.length) {
                $a = $('<div id="slAlert" class="sl-alert"></div>');
                $(S.panel).prepend($a);
            }
            $a.removeClass('sl-danger sl-success sl-info')
              .addClass(type === 'success' ? 'sl-success' : (type === 'info' ? 'sl-info' : 'sl-danger'))
              .text(msg).show();
            clearTimeout(alertTimer);
            alertTimer = setTimeout(function () { $a.hide(); }, 5000);
        }
        function nbLignes() { return $tbody.find('tr.sl-ligne').length; }
        function confirmerVidage(nom) {
            return nbLignes() === 0 || window.confirm('Changer de ' + nom + ' vide la liste des produits déjà ajoutés. Continuer ?');
        }
        function viderLignes() { $tbody.empty(); recalc(); }

        // ---------- catégories (selon la boutique) puis produits (selon la catégorie) ----------
        function filtrerCategories() {
            var bid = String($b.val() || '').trim();
            var auto = bid ? (cfg.catsParBoutique || {})[bid] : null; // null = pas de restriction
            var ancienne = String($c.val() || '');
            $c.empty().append($('<option>', { value: '', text: '-- Choisir une catégorie --' }));
            toutesCats.forEach(function (o) {
                if (!auto || auto.indexOf(o.value) !== -1) $c.append($('<option>', { value: o.value, text: o.text }));
            });
            if (ancienne && (!auto || auto.indexOf(ancienne) !== -1)) $c.val(ancienne);
            rebuild($c);
            prevC = String($c.val() || '');
            filtrerProduits();
        }
        function filtrerProduits() {
            var cat = String($c.val() || '').trim();
            $p.empty().append($('<option>', { value: '', text: '-- Choisir un produit --' }));
            if (!cat) {
                $p.prop('disabled', true);
            } else {
                cfg.produits.forEach(function (pr) {
                    if (String(pr.categorie) === cat) {
                        $p.append($('<option>', { value: pr.code, text: pr.titre + (pr.inactif ? ' (inactif)' : '') }));
                    }
                });
                $p.prop('disabled', false);
            }
            rebuild($p);
            dernierCode = '';
            majInfo(null);
        }

        // ---------- infos de stock / prix du produit en cours de saisie ----------
        function fetchInfo(code, done) {
            var bid = String($b.val() || '');
            if (!code || !bid) { done(null); return; }
            $.ajax({
                url: cfg.ajaxUrl || window.location.href, method: 'POST', dataType: 'json',
                data: { ajax: 1, produit_id: code, boutique_id: bid },
                success: function (d) { done(d || null); },
                error: function () { done(null); }
            });
        }
        function majInfo(d) {
            var $i = $(S.info);
            $i.find('[data-info="stock"]').text(d && d.success ? d.quantite : (d ? 0 : '—'));
            $i.find('[data-info="prixFour"]').text(d && d.prix ? nb(d.prix) : '—');
            $i.find('[data-info="prixVente"]').text(d && d.prix_vente ? nb(d.prix_vente) : '—');
        }
        $p.on('changed.bs.select change', function () {
            var code = String($p.val() || '');
            if (code === dernierCode) return; // 'changed.bs.select' + 'change' : un seul traitement
            dernierCode = code;
            if (!code) { majInfo(null); return; }
            fetchInfo(code, function (d) {
                if (String($p.val() || '') !== code) return; // le choix a changé entre-temps
                majInfo(d);
                if (mode === 'entree' && d) { // préremplit avec les prix actuels (modifiables)
                    if (d.prix > 0) $(S.prixAchat).val(d.prix);
                    if (d.prix_vente > 0) $(S.prixVente).val(d.prix_vente);
                }
            });
        });

        // ---------- changement de boutique / catégorie ----------
        $b.on('changed.bs.select change', function () {
            var v = String($b.val() || '');
            if (v === prevB) return;
            if (!confirmerVidage('boutique')) { $b.val(prevB); rebuild($b); return; }
            viderLignes();
            prevB = v;
            filtrerCategories();
        });
        $c.on('changed.bs.select change', function () {
            var v = String($c.val() || '');
            if (v === prevC) return;
            if (!confirmerVidage('catégorie')) { $c.val(prevC); rebuild($c); return; }
            viderLignes();
            prevC = v;
            filtrerProduits();
        });

        // ---------- lignes ----------
        function motifSelect(i, choisi) {
            var h = '<select class="form-select form-select-sm sl-motif" name="lignes[' + i + '][statut_id]">';
            (cfg.motifs || []).forEach(function (m) {
                h += '<option value="' + attr(m.code) + '" data-type="' + attr(m.type) + '"' + (String(m.code) === String(choisi) ? ' selected' : '') + '>' +
                     esc(m.titre) + ' (' + (m.type === 'entree' ? '↑ Entrée' : '↓ Sortie') + ')</option>';
            });
            return h + '</select>';
        }
        function ligneHtml(i, code, titre, stock, qte, extra) {
            var h = '<tr class="sl-ligne" data-produit="' + attr(code) + '" data-titre="' + attr(titre) + '" data-stock="' + stock + '">' +
                '<td><div class="fw-semibold">' + esc(titre) + '</div><div class="small text-muted">' + esc(code) + '</div>' +
                '<input type="hidden" name="lignes[' + i + '][produit_id]" value="' + attr(code) + '"></td>' +
                '<td class="text-center">' + stock + '</td>';
            if (mode === 'ajustement') h += '<td>' + motifSelect(i, extra.motif) + '</td>';
            h += '<td><input type="number" class="form-control form-control-sm sl-qte" name="lignes[' + i + '][quantite]" min="1" step="1" required value="' + qte + '"></td>';
            if (mode === 'entree') {
                h += '<td><input type="number" class="form-control form-control-sm sl-pa" name="lignes[' + i + '][prix_achat]" min="0" step="0.01" value="' + attr(extra.prixAchat) + '"></td>' +
                     '<td><input type="number" class="form-control form-control-sm sl-pv" name="lignes[' + i + '][prix_vente]" min="0" step="0.01" value="' + attr(extra.prixVente) + '"></td>';
            }
            if (mode === 'sortie') {
                h += '<td><input type="number" class="form-control form-control-sm sl-pv" name="lignes[' + i + '][prix_vente]" min="0" step="0.01" placeholder="prix du produit" value="' + attr(extra.prixVente) + '"></td>';
            }
            return h + '<td class="text-center sl-apres fw-bold"></td>' +
                '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger sl-suppr" title="Retirer cette ligne"><i class="bi bi-trash"></i></button></td></tr>';
        }

        function ajouterLigne() {
            var bid = String($b.val() || ''), cat = String($c.val() || ''), code = String($p.val() || '');
            if (!bid) return notify('Choisissez d\'abord une boutique.');
            if (!cat) return notify('Choisissez une catégorie.');
            if (!code) return notify('Choisissez un produit.');
            var qte = parseInt($(S.qte).val(), 10) || 0;
            if (qte <= 0) return notify('Saisissez une quantité supérieure à 0.');
            var motif = '';
            if (mode === 'ajustement') {
                motif = String($(S.motif).val() || '');
                if (!motif) return notify('Choisissez le motif de l\'ajustement.');
            }
            fetchInfo(code, function (d) {
                if (d === null) return notify('Impossible de lire le stock : vérifiez la connexion et réessayez.');
                if (mode === 'sortie' && !d.success) return notify('Ce produit n\'est pas présent dans cette boutique.');
                var stock = d.success ? (parseInt(d.quantite, 10) || 0) : 0;
                var titre = $p.find('option:selected').text().replace(/\s*\(inactif\)$/, '');

                var $exist = $tbody.find('tr.sl-ligne').filter(function () { return $(this).attr('data-produit') === code; });
                if ($exist.length) {
                    if (mode === 'ajustement' && String($exist.find('.sl-motif').val()) !== motif) {
                        return notify('« ' + titre + ' » est déjà dans la liste avec un autre motif : modifiez sa ligne.');
                    }
                    var $q = $exist.find('.sl-qte');
                    $q.val((parseInt($q.val(), 10) || 0) + qte);
                    notify('« ' + titre + ' » était déjà dans la liste : quantité cumulée.', 'info');
                } else {
                    $tbody.append(ligneHtml(idx++, code, titre, stock, qte, {
                        motif: motif,
                        prixAchat: $(S.prixAchat).val() || '',
                        prixVente: mode === 'entree' ? ($(S.prixVente).val() || '') : ($(S.prixSortie).val() || '')
                    }));
                }
                // prêt pour le produit suivant
                $(S.qte).val(cfg.qteDefaut || '');
                if (S.prixAchat) $(S.prixAchat).val('');
                if (S.prixVente) $(S.prixVente).val('');
                if (S.prixSortie) $(S.prixSortie).val('');
                filtrerProduits();
                recalc();
            });
        }

        // ---------- calculs, contrôles et récapitulatif ----------
        function erreurLigne($r) {
            var titre = $r.attr('data-titre'), stock = parseInt($r.attr('data-stock'), 10) || 0;
            var q = parseInt($r.find('.sl-qte').val(), 10) || 0;
            if (q <= 0) return 'Quantité invalide pour « ' + titre + ' » (doit être supérieure à 0).';
            if (mode === 'sortie' && q > stock) return 'Stock insuffisant pour « ' + titre + ' » : disponible ' + stock + ', demandé ' + q + '.';
            if (mode === 'ajustement') {
                var type = $r.find('.sl-motif option:selected').attr('data-type');
                if (type !== 'entree' && q > stock) return 'Stock insuffisant pour « ' + titre + ' » : stock ' + stock + ', sortie demandée ' + q + '.';
            }
            return '';
        }
        function recalc() {
            var n = 0, totalQte = 0, valeur = 0;
            $tbody.find('tr.sl-ligne').each(function () {
                var $r = $(this), stock = parseInt($r.attr('data-stock'), 10) || 0, q = parseInt($r.find('.sl-qte').val(), 10) || 0, apres;
                n++; totalQte += q;
                if (mode === 'entree') {
                    apres = stock + q;
                    valeur += q * (parseFloat($r.find('.sl-pa').val()) || 0);
                } else if (mode === 'sortie') {
                    apres = stock - q;
                } else {
                    apres = $r.find('.sl-motif option:selected').attr('data-type') === 'entree' ? stock + q : stock - q;
                }
                $r.find('.sl-apres').text(apres);
                $r.toggleClass('sl-err', erreurLigne($r) !== '');
            });
            $(S.vide).toggle(n === 0);
            $(S.tableWrap).toggle(n > 0);
            var recap = n + ' produit' + (n > 1 ? 's' : '') + ' — ' + totalQte + ' unité' + (totalQte > 1 ? 's' : '');
            if (mode === 'entree' && n > 0 && valeur > 0) recap += ' — valeur d\'achat ' + nb(valeur) + ' F';
            $(S.recap).text(n ? recap : '');
            $(S.submit).prop('disabled', n === 0);
        }

        $tbody.on('input change', '.sl-qte, .sl-pa, .sl-pv, .sl-motif', recalc);
        $tbody.on('click', '.sl-suppr', function () { $(this).closest('tr').remove(); recalc(); });
        $(S.btnAjouter).on('click', ajouterLigne);
        $(S.panel).on('keydown', 'input', function (e) { // Entrée = ajouter la ligne (pas valider le formulaire)
            if (e.key === 'Enter') { e.preventDefault(); ajouterLigne(); }
        });
        $(S.form).on('submit', function (e) {
            var $rows = $tbody.find('tr.sl-ligne');
            if (!$rows.length) { e.preventDefault(); notify('Ajoutez au moins un produit à la liste avant de valider.'); return; }
            var msg = '';
            $rows.each(function () { var m = erreurLigne($(this)); if (m) { msg = m; return false; } });
            if (msg) { e.preventDefault(); notify(msg); return; }
            $(S.submit).prop('disabled', true); // évite le double envoi
        });

        filtrerCategories(); // applique la restriction dès l'ouverture (boutique déjà présélectionnée)
        recalc();
        return { ajouterLigne: ajouterLigne, recalc: recalc };
    }

    return { init: init };
})(jQuery);
</script>