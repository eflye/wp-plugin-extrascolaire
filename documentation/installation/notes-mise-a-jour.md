# Notes de mise à jour

## Objectif

Passer d'une version antérieure à la version courante (5.32.1, schéma de base 4.18.0), en une seule fois : inutile d'installer les versions intermédiaires. Depuis 5.28.0, tout se fait **depuis le backoffice**, dans **Périscolaire › Maintenance**, sans ligne de commande : c'est la méthode adaptée à un WordPress en conteneur. Les commandes WP-CLI restent possibles ; elles sont indiquées en encadré.

## Ce qui change entre 5.23 et 5.28

- **Une seule migration de base :** le schéma passe de 4.14.0 à 4.15.0 (5.26.0), avec une seule table d'année scolaire nommée par sa rentrée et le statut de l'enfant porté par l'année. Elle est automatique. Si deux années d'une même rentrée portent chacune des inscriptions, elle s'arrête **sans rien perdre** et la page **Maintenance** affiche les années en cause.
- **Nouveaux réglages :** rubrique **Confidentialité** (5.24.0), modèle d'e-mail **Réinscription retirée par la famille** (5.26.0).
- **Clé de chiffrement des IBAN** (5.27.0) : à sortir de la base. Pour un conteneur, la clé se déclare en variable d'environnement (5.28.0).

## Ce qui change entre 5.28 et 5.32

- **Polices** (5.28.1) : servies par le site lui-même, plus aucun appel à Google Fonts.
- **Facturation d'une journée** (5.29.0) : une journée complète est facturée au prix du forfait, sans jamais dépasser la somme de ses prestations ; plus de cumul forfait + garderies. Les factures déjà envoyées ne changent pas. **Vérifiez le tarif du forfait sans repas** : avec la valeur par défaut, il coûte plus que ses prestations et ne s'applique jamais (voir [Services et tarifs](../configuration/services-tarifs.md)).
- **Annulation de la cantine d'une classe** (5.30.0) : les enfants au forfait sont concernés comme les autres. Les avis de fermeture listent les prestations de chaque enfant (5.30.0, 5.31.0).
- **Montants calculés en centimes** (5.32.1) : pas de migration, les factures existantes ne changent pas.

- **Journal d'audit, schéma 4.16.0 :** deux colonnes ajoutées au journal, calculées automatiquement à la mise à jour, sans toucher aux lignes existantes. Une ligne dont la durée de conservation est dépassée voit désormais son contenu effacé même si des lignes plus anciennes sont conservées plus longtemps.
- **Tâches planifiées :** un avis rouge sur le tableau de bord signale un WP-Cron à l'arrêt. S'il apparaît, suivez [Tâches planifiées](taches-planifiees.md#alerte-de-retard).
- **Tarifs et statut « cantine sans repas » datés, schéma 4.17.0 :** la grille de tarifs en place devient le premier tarif de chaque prestation, à compter de la première rentrée enregistrée, et chaque enfant signalé « cantine sans repas » l'est depuis cette même date. Les montants des factures existantes ne changent pas. Ensuite, un changement de prix ou de statut se fait **à partir d'une date** : voir [Services et tarifs](../configuration/services-tarifs.md) et [Régimes alimentaires](../configuration/regimes-alimentaires.md).
- **Versions des règlements approuvés, schéma 4.18.0 :** chaque acceptation enregistre désormais la version du règlement affichée (texte et PDF). Les acceptations antérieures apparaissent « antérieures au suivi des versions ». Le texte du règlement de prélèvement est désormais le même à l'inscription et dans **Mon profil**, et la réinscription affiche le règlement intérieur.

## Avant de commencer

- Un compte administrateur WordPress (capacité de configuration du périscolaire).
- Un accès à l'hôte des conteneurs pour la sauvegarde et, à l'étape 5, pour déclarer une variable d'environnement.
- Un créneau calme : hors réinscription et hors facturation.
- Un coffre de mots de passe pour la clé de chiffrement.

## Étapes

1. **Sauvegardez**, depuis l'hôte des conteneurs :

    - la base de données, par exemple `docker exec <conteneur-mysql> sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction <base>' > avant-mise-a-jour.sql` (`podman` à la place de `docker` selon votre installation) ;
    - le dossier privé des documents : son chemin est affiché à l'étape 1 de **Périscolaire › Maintenance** ; dans un conteneur, il se trouve en général dans le volume de WordPress ;
    - le fichier `wp-config.php` et le `docker-compose.yml`.

    Vérifiez que le dump s'ouvre.

2. **Téléchargez et vérifiez l'archive.** Sur la page de la Release, téléchargez `periscolaire-registration-X.Y.Z.zip` et `SHA256SUMS`, puis sur votre poste : `sha256sum -c SHA256SUMS --ignore-missing` (sous macOS : `shasum -a 256 -c SHA256SUMS`). Le résultat doit être `OK`.

3. **Installez** depuis **Extensions › Ajouter une extension › Téléverser une extension**, puis confirmez le remplacement de la version installée. Ne supprimez pas l'extension avant.

4. **Ouvrez Périscolaire › Maintenance.** La mise à jour de la base se fait au premier affichage du backoffice. La page présente cinq étapes, chacune avec son état (**Fait**, **À faire** ou **Bloquant**) :

    - **1. Sauvegarde** : cochez la case une fois l'étape 1 ci-dessus réalisée, puis cliquez sur **Confirmer la sauvegarde**.
    - **2. Base de données** : l'état doit être **Fait**, avec le schéma **4.18.0** (version 5.32). Les migrations s'enchaînent seules, dans l'ordre, quelle que soit la version de départ. S'il est **Bloquant** avec un tableau d'**années scolaires en double**, ouvrez **Année scolaire**, supprimez l'année en trop, c'est-à-dire celle qui n'aurait pas dû exister (ses inscriptions sont supprimées avec elle), puis cliquez sur **Relancer la mise à jour de la base**. En cas de doute sur l'année à garder, arrêtez-vous et faites-vous accompagner : les étapes réussies sont conservées, rien n'est perdu.

5. **Sortez la clé de chiffrement de la base** (étape **3. Clé de chiffrement des IBAN** de la page) :

    1. Cliquez sur **Générer une clé**. Copiez-la immédiatement dans votre coffre : elle n'est affichée qu'une fois et n'est enregistrée nulle part.
    2. Ajoutez-la au service WordPress de votre `docker-compose.yml`, puis recréez le conteneur (`docker compose up -d`) :

        ```yaml
        environment:
          PSC_ENCRYPTION_KEY: "la-clé-générée"
        ```

        Sans conteneur, ajoutez plutôt la ligne `define( 'PSC_ENCRYPTION_KEY', '…' );` affichée dans `wp-config.php`.

    3. Revenez sur **Maintenance** : l'étape indique que la clé est la variable d'environnement. Cliquez sur **Rechiffrer avec la nouvelle clé**. L'état passe à **Fait** quand plus aucun IBAN n'utilise l'ancienne clé.

6. **Renseignez les nouveautés** (étape **4. Nouveaux réglages**) : chaque point à faire porte un lien **Ouvrir**. Le responsable du traitement se renseigne dans **Réglages › Confidentialité**. Après relecture des modèles d'e-mails, cliquez sur **J'ai relu les modèles d'e-mails**.

7. **Recettez** (étape **5. Recette**) : cochez chaque point après l'avoir constaté sur une famille de test, puis **Enregistrer la recette**. L'auteur et la date de chaque point sont conservés.

8. **Terminez.** Quand les cinq étapes sont **Fait**, le rappel disparaît du tableau de bord. Faites une nouvelle sauvegarde, puis protégez ou supprimez les dumps antérieurs : ils contiennent l'ancienne clé.

!!! note "Avec WP-CLI"
    Si WP-CLI est disponible, un contrôle des années peut être lancé **avant** la mise à jour, en lecture seule, avec `wp eval-file controle-4.15.php` et ce contenu :

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

    Les étapes de la clé existent aussi en ligne de commande : `wp psc chiffrement statut`, `generer-cle`, `rechiffrer`. Voir [Clé de chiffrement des IBAN](cle-chiffrement.md).

!!! warning "Retour arrière"
    Le schéma de la base ayant changé, réinstaller l'ancienne archive ne suffit pas. Restaurez le dump de l'étape 1 et le dossier privé, retirez la variable `PSC_ENCRYPTION_KEY` si vous l'aviez ajoutée après ce dump, puis réinstallez l'ancienne archive. Les saisies faites depuis la sauvegarde sont perdues.

## Résultat attendu

Les cinq étapes de **Périscolaire › Maintenance** sont **Fait** : base au schéma 4.18.0, clé des IBAN hors de la base, nouveaux réglages renseignés, recette signée. Aucun avis « tâches planifiées » ne s'affiche sur le tableau de bord. Dans **Réglages › Tarifs**, chaque prestation a un tarif du jour ; dans **Enfants**, les enfants « cantine sans repas » le sont toujours.

## Pour aller plus loin

- [Déployer une nouvelle version](deploiement-zip.md)
- [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)
- [Clé de chiffrement des IBAN](cle-chiffrement.md)
- [Dépannage et FAQ](depannage-faq.md)
