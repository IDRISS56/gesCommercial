/* =====================================================================
   GUIDE D'UTILISATION — COMPORTEMENT (guide.js)
   Fait fonctionner : le filtre par rôle, la recherche, l'agrandissement
   des captures, le menu mobile, le thème clair/sombre, le bouton « haut ».
   Aucune bibliothèque externe n'est nécessaire.
   ===================================================================== */
(function () {
  // Petit raccourci : liste les éléments qui correspondent à un sélecteur
  function all(selector, parent) {
    return Array.prototype.slice.call((parent || document).querySelectorAll(selector));
  }

  var links    = all('aside a[data-roles]');       // liens du menu
  var pages    = all('.doc[data-roles]');          // pages du guide
  var groups   = all('.navgrp');                   // groupes du menu
  var roleBox  = document.getElementById('role');  // liste « Je suis… »
  var searchBox = document.getElementById('search');
  var emptyMsg = document.getElementById('empty');

  // ---- 1. Filtre par rôle + recherche ----
  function appliquerFiltres() {
    var role = roleBox.value;
    var mot  = searchBox.value.trim().toLowerCase();
    var visibles = 0;

    links.forEach(function (a) {
      var okRole = !role || a.dataset.roles.split(' ').indexOf(role) > -1;
      var okMot  = !mot  || a.dataset.t.indexOf(mot) > -1;
      a.classList.toggle('hidden', !(okRole && okMot));
    });

    pages.forEach(function (p) {
      var okRole = !role || p.dataset.roles.split(' ').indexOf(role) > -1;
      var okMot  = !mot  || (p.textContent || '').toLowerCase().indexOf(mot) > -1;
      var ok = okRole && okMot;
      p.classList.toggle('hidden', !ok);
      if (ok) visibles++;
    });

    // cache les groupes du menu qui n'ont plus aucun lien visible
    groups.forEach(function (g) {
      g.classList.toggle('hidden', all('a:not(.hidden)', g).length === 0);
    });

    emptyMsg.style.display = visibles ? 'none' : 'block';
    try { localStorage.setItem('guideRole', role); } catch (e) {}   // mémorise le choix
  }

  try {
    var rolePrecedent = localStorage.getItem('guideRole');
    if (rolePrecedent) roleBox.value = rolePrecedent;
  } catch (e) {}
  roleBox.addEventListener('change', appliquerFiltres);
  searchBox.addEventListener('input', appliquerFiltres);
  appliquerFiltres();

  // ---- 2. Agrandissement des captures (clic) ----
  var fenetre = document.getElementById('lb');
  var grandeImage = fenetre.querySelector('img');
  document.addEventListener('click', function (e) {
    var cible = e.target;
    if (cible.matches && cible.matches('img[data-zoom]')) {
      grandeImage.src = cible.src;
      fenetre.classList.add('on');
      return;
    }
    if (fenetre.classList.contains('on')) { fenetre.classList.remove('on'); return; }
    if (cible.closest && cible.closest('aside a')) {                // ferme le menu mobile
      document.querySelector('aside').classList.remove('open');
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') fenetre.classList.remove('on');
  });

  // ---- 3. Menu mobile (bouton ☰) ----
  document.getElementById('burger').addEventListener('click', function () {
    document.querySelector('aside').classList.toggle('open');
  });

  // ---- 4. Thème clair / sombre ----
  document.getElementById('theme').addEventListener('click', function () {
    var racine = document.documentElement;
    var actuel = racine.getAttribute('data-theme') ||
                 (matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light');
    racine.setAttribute('data-theme', actuel === 'dark' ? 'light' : 'dark');
  });

  // ---- 5. Bouton « haut de page » + lien actif dans le menu ----
  var boutonHaut = document.getElementById('top');
  boutonHaut.addEventListener('click', function () { scrollTo({ top: 0, behavior: 'smooth' }); });

  function auDefilement() {
    boutonHaut.style.display = scrollY > 600 ? 'block' : 'none';
    var courante = null;
    pages.forEach(function (p) {
      if (p.getBoundingClientRect().top < 120 && !p.classList.contains('hidden')) courante = p.id;
    });
    all('aside a.active').forEach(function (a) { a.classList.remove('active'); });
    if (courante) {
      var lien = document.querySelector('aside a[href="#' + courante + '"]');
      if (lien) lien.classList.add('active');
    }
  }
  addEventListener('scroll', auDefilement, { passive: true });
  auDefilement();
})();
