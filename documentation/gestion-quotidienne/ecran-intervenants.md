# Écran des intervenants

## Objectif

Donnez aux agents de la garderie et de la cantine une liste des enfants attendus, consultable sur téléphone, où ils pointent les présents et les absents, notent les heures d'arrivée et de départ et retrouvent, en cas d'urgence, les numéros des parents.

## Avant de commencer

Connectez-vous avec un rôle autorisé à gérer les réglages. Créez une page WordPress dédiée contenant le shortcode `[periscolaire_sidscm]` : c'est elle que les intervenants ouvrent. Aucun compte WordPress n'est nécessaire pour eux.

## Donner l'accès aux intervenants

1. Ouvrez **Périscolaire › Réglages**, rubrique **Listes intervenantes SIDSCM**.
2. Dans **Page "Accès intervenants"**, laissez **Détection automatique** si une seule page porte le shortcode, ou choisissez la page. Le lien **Espace intervenants** de l'espace familles pointe vers elle.
3. Dans **Identités intervenantes**, créez une ligne par agent : son **Nom**, son **Code individuel**, une date d'**Expiration** facultative et la case **Actif**. Pour changer un code, saisissez-le dans **Nouveau code (facultatif)** ; laissé vide, le code existant est conservé. Décocher **Actif** retire l'accès de l'agent. Communiquez à chaque agent son code directement, jamais par un e-mail aux familles.
4. Le **Code d'accès intervenantes SIDSCM** (ancien code partagé) n'est accepté que tant qu'aucune identité n'est enregistrée. Dès qu'une identité existe, seul le code individuel ouvre l'écran. Sans aucun code, personne ne peut déverrouiller la page.

Sur la page, l'intervenant choisit son identité dans **Choisir votre identité**, saisit son **Code d'accès**, puis clique sur **Accéder à la liste**. Un code faux affiche « Code incorrect. Réessayez. ». La session reste ouverte 8 heures sur l'appareil ; le bouton **Verrouiller** la ferme avant, par exemple sur une tablette partagée.

## Lire la liste

1. L'écran **Listes des enfants attendus** s'ouvre en vue **Jour** sur la semaine en cours : un onglet par jour d'école (lundi, mardi, jeudi, vendredi), puis un onglet par service, **Garderie Matin**, **Cantine** et **Garderie Soir**, avec le nombre d'enfants attendus.
2. Chaque ligne donne le prénom et le nom de l'enfant et son régime (**Standard**, **Sans porc**, **Sans viande**). Un enfant en cantine sans repas porte la mention **Midi sans repas — à ne pas compter dans les couverts** : il apporte son plat.
3. En **Garderie Soir**, le bouton **Autorisés** affiche les personnes autorisées à récupérer l'enfant, avec leur téléphone. Cette liste n'est consultable que pour la semaine en cours.
4. La vue **Semaine** donne les enfants attendus par jour et par service pour la semaine choisie dans **Semaine à consulter** : la semaine en cours et les huit suivantes. Elle sert à anticiper les effectifs ; le pointage se fait en vue **Jour**.

Les listes viennent du planning déclaré par les familles, corrigé par la mairie : un enfant dont la prestation a été annulée n'y figure plus.

## Pointer les présences

1. Chaque enfant part **à pointer**. Touchez **Présent** ou **Absent** ; toucher de nouveau le bouton actif annule le pointage, l'enfant repasse « à pointer ».
2. Au-dessus de la liste, les compteurs indiquent les présents, les absents et les enfants restant à pointer. Quand plus personne n'est à pointer, l'onglet du service et le bas de la liste affichent **pointage terminé**.
3. **Tous présents** marque présents, en un geste, tous les enfants encore à pointer. Les enfants déjà marqués absents ne sont pas touchés.
4. En **Garderie Matin**, l'heure d'**Arrivée** se remplit à l'heure courante au passage en Présent ; en **Garderie Soir**, c'est l'heure de **Départ**. L'heure reste modifiable. Elle s'efface si l'enfant est marqué absent, et n'existe pas pour la cantine.
5. Si l'enregistrement échoue (connexion coupée), la ligne revient à son état précédent et un message le signale : « Enregistrement impossible : le pointage de … n'a pas été modifié. Vérifiez la connexion et réessayez. »

Le pointage ne sert qu'aux intervenants : il n'entre ni dans la facturation, ni dans la commande fournisseur, ni dans les exports. Il figure dans l'export des données d'une famille, cf. [Données personnelles (RGPD)](../rgpd.md).

## Joindre les parents en urgence

Sur chaque ligne de la vue **Jour**, pour les trois services, le bouton **Contact d'urgence** affiche à la demande le nom et le rôle de chaque parent (titulaire, second parent) et ses numéros, cliquables pour appeler. Si aucun numéro n'est renseigné, l'écran affiche « Aucun numéro renseigné : contactez la mairie ».

Les numéros ne sont pas chargés avec la liste : ils ne s'affichent qu'au clic, et chaque consultation est inscrite au **Journal d'audit** avec l'intervenant, l'enfant et la famille. Relisez ce journal périodiquement : une consultation sans urgence se voit.

## Résultat attendu

Les intervenants disposent chaque jour de la liste exacte des enfants attendus par service, pointent les présences sans papier, et joignent les parents sans passer par la mairie. La mairie retrouve les consultations de contacts dans le journal d'audit.

## Pour aller plus loin

- [Personnes autorisées](personnes-autorisees.md)
- [Annulations et absences](annulations-absences.md)
- [Régimes alimentaires](../configuration/regimes-alimentaires.md)
- [Données personnelles (RGPD)](../rgpd.md)
