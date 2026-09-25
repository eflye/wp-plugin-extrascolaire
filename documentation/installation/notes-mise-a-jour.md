# Notes de mise à jour

## Objectif

Passer d'une version antérieure à 5.26.0 (5.23.x, 5.24.x ou 5.25.x) à la version 5.27.0 ou plus récente, en contrôlant avant la mise à jour ce qui pourrait l'arrêter, puis en réglant ce qui est nouveau. La procédure générale reste celle de [Déployer une nouvelle version](deploiement-zip.md) ; cette page en détaille l'application à ce saut de version.

## Ce qui change entre 5.23 et 5.27

- **Une seule migration de base :** le schéma passe de 4.14.0 à 4.15.0 (5.26.0), avec une seule table d'année scolaire nommée par sa rentrée et le statut de l'enfant porté par l'année. Elle est automatique, mais s'arrête si deux années d'une même rentrée portent chacune des inscriptions : l'étape 2 le vérifie avant.
- **Nouveaux réglages :** rubrique **Confidentialité** (5.24.0), modèle d'e-mail **Réinscription retirée par la famille** (5.26.0).
- **Clé de chiffrement des IBAN** (5.27.0) : commande `wp psc chiffrement` pour sortir la clé de la base.

## Avant de commencer

- Accès SSH avec WP-CLI (`wp --info` doit répondre) et un compte administrateur WordPress.
- Un créneau calme : hors réinscription et hors facturation.
- Un coffre de mots de passe pour la clé de chiffrement de l'étape 8.

## Étapes

1. **Relevez l'état actuel.**

    ```
    wp plugin list | grep periscolaire      # version installée
    wp option get psc_db_version            # 4.14.0 attendu
    wp option get psc_invoice_debug_delete  # erreur « Could not get » attendue (mode debug inactif)
    ```

2. **Contrôlez les années scolaires (lecture seule).** Créez sur le serveur, hors du dossier du plugin, un fichier `controle-4.15.php` avec ce contenu :

    ```php
    <?php
    // Contrôle avant la mise à jour vers 5.26.0 ou plus : années scolaires qui
    // tomberaient sur la même clé (ex. 2026-2027). Lecture seule.
    global $wpdb;
    $t  = $wpdb->prefix . 'psc_school_years';
    $cy = $wpdb->prefix . 'psc_child_school_years';
    $has_label = (bool) $wpdb->get_var("SHOW COLUMNS FROM $t LIKE 'label'");
    $rows = $wpdb->get_results(
        'SELECT y.id, ' . ($has_label ? 'y.label' : "'' AS label") . ", y.date_debut, y.statut,
                (SELECT COUNT(*) FROM $cy c WHERE c.school_year_id = y.id) AS inscriptions
         FROM $t y ORDER BY y.date_debut"
    );
    $groups = array();
    foreach ($rows as $r) {
        if (preg_match('/^\d{4}-\d{4}$/', (string) $r->label)) {
            $key = $r->label;
        } else {
            $y = (int) substr($r->date_debut, 0, 4);
            if ((int) substr($r->date_debut, 5, 2) < 8) $y--;
            $key = $y . '-' . ($y + 1);
        }
        $groups[$key][] = $r;
        printf("#%-4d %-22s début %s -> année %s  %-11s %d inscription(s)\n", $r->id, $r->label, $r->date_debut, $key, $r->statut, $r->inscriptions);
    }
    $blocking = 0;
    foreach ($groups as $key => $list) {
        $with = array_filter($list, function ($r) { return (int) $r->inscriptions > 0; });
        if (count($with) > 1) {
            $blocking++;
            echo "BLOQUANT : plusieurs années $key portent des inscriptions (ids " . implode(', ', wp_list_pluck($with, 'id')) . ").\n";
        } elseif (count($list) > 1) {
            echo "Info : années $key en double sans conflit, la mise à jour gardera " . (count($with) ? 'celle qui a des inscriptions' : 'l’active, sinon la plus récente') . ".\n";
        }
    }
    echo $blocking ? "=> À corriger avant la mise à jour.\n" : "=> Aucun blocage : la mise à jour 4.15.0 peut passer.\n";
    ```

    Lancez-le :

    ```
    wp eval-file controle-4.15.php
    ```

    - **Aucun blocage** : poursuivez.
    - **BLOQUANT** : arrêtez-vous. Deux années de la même rentrée ont des inscriptions ; il faut décider laquelle garder, car supprimer une année supprime aussi ses inscriptions (classes, justificatifs d'assurance). Faites-vous accompagner par la personne qui maintient le site.

3. **Sauvegardez.**

    ```
    wp db export avant-mise-a-jour.sql           # ou l'outil de sauvegarde de l'hébergeur
    wp eval 'echo psc_private_dir(), "\n";'      # chemin du dossier privé à sauvegarder
    cp wp-config.php wp-config.avant-mise-a-jour.php
    ```

    Sauvegardez aussi le dossier privé affiché par la deuxième commande, et vérifiez que le dump s'ouvre.

4. **Téléchargez et vérifiez l'archive.** Sur la page de la Release, téléchargez `periscolaire-registration-X.Y.Z.zip` et `SHA256SUMS`, puis :

    ```
    sha256sum -c SHA256SUMS --ignore-missing     # « periscolaire-registration-X.Y.Z.zip: OK » attendu
    ```

5. **Installez.**

    ```
    wp plugin install periscolaire-registration-X.Y.Z.zip --force
    ```

    Ou **Extensions › Ajouter une extension › Téléverser une extension**, puis confirmez le remplacement. Ne supprimez pas l'extension avant.

6. **Laissez la migration se faire, puis vérifiez.** Ouvrez une page du backoffice, puis :

    ```
    wp option get psc_db_version            # 4.15.0 attendu
    wp option get psc_migration_failed      # erreur « Could not get » attendue : aucun échec
    ```

    Si l'alerte rouge **la mise à jour de la base de données est incomplète** s'affiche, elle nomme l'étape et la cause ; les étapes réussies sont conservées. Voir [Dépannage et FAQ](depannage-faq.md).

7. **Renseignez les nouveautés.**

    - **Périscolaire › Réglages › Confidentialité** : au moins le responsable du traitement (sinon les familles lisent « Collectivité (à adapter) »). Voir [Données personnelles (RGPD)](../rgpd.md).
    - **Périscolaire › Année scolaire** : les années portent désormais leur clé (2026-2027) ; vérifiez la liste et l'année active. Voir [Année scolaire](../configuration/annee-scolaire.md).
    - **Périscolaire › Modèles d'e-mails** : relisez **Réinscription retirée par la famille**.

8. **Sortez la clé de chiffrement de la base.** Suivez [Clé de chiffrement des IBAN](cle-chiffrement.md) : `wp psc chiffrement statut`, `generer-cle`, ajout de la ligne dans `wp-config.php`, `rechiffrer`, puis `statut` de nouveau.

9. **Recettez.** Tableau de bord sans alerte nouvelle ; lien de connexion reçu et portail famille ouvert ; justificatif et facture existants téléchargeables ; case de planning cochée puis décochée sur un enfant de test ; IBAN masqué affiché dans **Familles** pour une famille en prélèvement ; **Journal d'audit** avec « Schéma de la base mis à jour (4.14.0 → 4.15.0) » et le rechiffrement.

10. **Terminez.** Faites une nouvelle sauvegarde. Protégez ou supprimez les dumps antérieurs, qui contiennent l'ancienne clé. Supprimez du serveur `controle-4.15.php` et `wp-config.avant-mise-a-jour.php` une fois la clé rangée dans le coffre.

!!! warning "Retour arrière"
    Le schéma de la base ayant changé, réinstaller l'ancienne archive ne suffit pas. Restaurez le dump de l'étape 3, le dossier privé et `wp-config.avant-mise-a-jour.php`, puis réinstallez l'ancienne archive avec `--force`. Les saisies faites depuis la sauvegarde sont perdues.

## Résultat attendu

La nouvelle version est installée, la base est au schéma 4.15.0 sans alerte, les réglages nouveaux sont renseignés et la clé des IBAN est dans `wp-config.php`.

## Pour aller plus loin

- [Déployer une nouvelle version](deploiement-zip.md)
- [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)
- [Clé de chiffrement des IBAN](cle-chiffrement.md)
- [Dépannage et FAQ](depannage-faq.md)
