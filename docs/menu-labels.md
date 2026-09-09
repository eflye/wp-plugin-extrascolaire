# Labels qualité des menus

La saisie et le stockage restent inchangés : un plat par ligne dans les champs de chaque jour.

- `Carottes râpées*` : logo AB.
- `Poulet fermier rôti**` : logo Label Rouge.
- Sans suffixe : aucun logo.
- `***` est actuellement traité comme Label Rouge ; le cumul des labels n’est pas activé.

Les étoiles au milieu des libellés sont retirées à l’affichage. Les lignes vides ou composées uniquement d’étoiles ne produisent pas de plat. L’aperçu local et le compteur permettent de vérifier la saisie avant enregistrement. Les logos et la légende apparaissent dans les menus invités, le portail, le tableau de bord et l’e-mail. L’origine de la viande reste après la légende.

`Psc_Menus::parse_menu_lines()` centralise le parsing PHP. Les expressions et leur ordre sont définis une fois par `label_rules()` et transmis à l’aperçu JavaScript, sans appel serveur à la frappe. Les deux exécutent les mêmes fixtures. Les logos fournis sont copiés sans transformation dans `assets/img/` et utilisés avec leur ratio original.

Il n’existe pas d’export PDF dédié aux menus dans ce dépôt : l’impression du rendu familles conserve les logos et les libellés nettoyés. Les e-mails utilisent des images hébergées en URL absolue avec texte alternatif.

Vérifications :

- `php tests/unit/menu-labels.php`
- WP-CLI `eval-file tests/integration/menu-label-rendering.php` : rendus PHP et e-mail intercepté, aucun envoi réel.
- `playwright test tests/menu-labels.spec.ts --project=test` : aperçu, compteur, images, mobile et impression.
