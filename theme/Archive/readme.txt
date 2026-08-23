=== Montgeroult Familles ===
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
License: GPLv2 or later

Thème institutionnel pour le site de la commune de Montgeroult, conçu pour
habiller le portail familles du plugin "Périscolaire - Inscriptions"
(inscriptions cantine/garderie, factures, enfants, menu) avec la charte
graphique de la commune (bleu encre/abricot terracotta, Fraunces + Work Sans).

== Installation ==

1. Copier le dossier `montgeroult-familles` dans `wp-content/themes/`.
2. Activer le thème depuis Apparence > Thèmes.
3. Apparence > Menus : assigner un menu à l'emplacement "Menu principal"
   (optionnel — un lien "Accueil" s'affiche par défaut sinon).
4. Sur la page qui contient le shortcode [periscolaire_form], ouvrir
   l'éditeur de page > Attributs de page > Modèle, et choisir
   "Espace Familles" pour afficher le bandeau dédié au-dessus du
   formulaire (optionnel — le thème fonctionne aussi avec le modèle
   par défaut).
5. Aucune configuration côté plugin n'est nécessaire : le thème détecte
   automatiquement la feuille de style du plugin (assets/css/frontend.css,
   handle "psc-frontend") et charge par-dessus assets/css/psc-theme.css,
   qui redéfinit son apparence sans toucher au code du plugin.

== Notes ==

- Les polices (Fraunces, Work Sans, Cormorant Garamond) sont chargées
  depuis Google Fonts. Pour un site 100% auto-hébergé (RGPD), héberger
  les fichiers de police en local et remplacer l'appel dans functions.php.
- Le blason utilisé dans l'en-tête est un espace réservé (SVG stylisé,
  bouclier or à deux formes sombres) : remplacer par le blason officiel
  de la commune si un fichier vectoriel est disponible.
- Testé avec le plugin "Périscolaire - Inscriptions" v2.1.0
  (eflye/wp-plugin-extrascolaire).
