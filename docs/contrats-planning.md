# Contrats d'une journée : ce que chaque canal lit du planning

Une journée d'un enfant se décrit par **sa carte de déclarations effectives** `{code => bool}`, calculée par `Psc_Planning::declared_map()` (ou `psc_is_declared()` pour un seul triplet). La carte est déjà résolue :

- le rythme, les exceptions, les jours fermés et les prestations fermées sont arbitrés ;
- un forfait déclaré et réalisable rend vrais les créneaux qu'il couvre (GM, CANT, GS) ;
- un enfant « cantine sans repas » a sa cantine convertie en MSR.

Aucun canal ne relit `psc_pattern` ou `psc_exception`, et aucun ne réinterprète la carte à sa façon : chacun passe par **une** des fonctions ci-dessous (`includes/helpers/planning.php`, fonctions pures).

| Contrat | Fonction | Canaux |
| --- | --- | --- |
| Prestation déclarée | `declared_map()` / `psc_is_declared()` | Écrans de saisie du portail (état affiché), pointage SIDSCM (enfant attendu à un service), annulations |
| Présence | `psc_day_slots($day)` : GM, CANT ou MSR, GS, dans l'ordre de la journée ; jamais FORF | Avis de fermeture d'un jour ou d'une période |
| Repas fourni | `psc_day_meal($day, $allergie)` : cantine, hors allergie alimentaire | Commande au fournisseur |
| Prestation facturée | `psc_billing_services($day, $sans_repas, $regle)` : règle 2 (P1-15) | Factures, estimations du portail (`sibling_summary`, `year_summary`), export CSV des inscriptions, effectifs du calendrier mairie |

Le navigateur ne calcule aucun montant : les totaux affichés par le portail viennent de la réponse du serveur (`planning_state`).

## Table de décision

Source unique : `tests/unit/contrats-journee.php`. Elle est rejouée par `tests/unit/run.php` avec deux jeux de tarifs, et par `bin/verify-channel-contracts.php` en conditions réelles. Une ligne ajoutée ici s'ajoute là-bas, et inversement.

| Journée (carte résolue) | Présence | Repas fourni | Facturée |
| --- | --- | --- | --- |
| Forfait déclaré, journée complète | GM, CANT, GS | oui | FORF si son tarif ≤ GM + CANT + GS, sinon les trois |
| Trois créneaux cochés séparément | GM, CANT, GS | oui | comme ci-dessus |
| Forfait, cantine retirée (famille ou annulation de classe) | GM, GS | non | GM + GS |
| Forfait, garderie du soir fermée par la mairie | GM, CANT | oui | GM + CANT |
| Cantine seule | CANT | oui | CANT |
| Cantine seule, allergie alimentaire | CANT | **non** (la famille fournit le repas) | CANT |
| Midi sans repas seul | MSR | non | MSR |
| Journée complète, midi sans repas | GM, MSR, GS | non | FSR si son tarif ≤ GM + MSR + GS, sinon les trois |
| Enfant « cantine sans repas » au forfait | GM, MSR, GS | non | comme ci-dessus |
| Garderies seules | GM, GS | non | GM + GS |
| Rien | — | non | — |

Deux compléments :

- **Goûter** : il suit la garderie du soir (`Psc_Supplier_Orders::gouter_services()`, filtre `psc_gouter_services`), sauf allergie alimentaire.
- **Factures envoyées** : elles sont recalculées avec la règle inscrite dans leur instantané (`calcul`), jamais avec une règle adoptée depuis.

## Ajouter un tarif ou un profil

1. Ajoutez la ligne ou le cas dans `tests/unit/contrats-journee.php` et dans le tableau ci-dessus.
2. Adaptez la fonction du contrat concerné, **et elle seule**.
3. Lancez `tests/unit/run.php` et `bin/verify-channel-contracts.php` : les canaux qui passent par le contrat suivent sans modification.

Un canal qui aurait besoin d'une lecture nouvelle reçoit une fonction de contrat de plus, pas un calcul local.
