# Clé de chiffrement des IBAN

## Objectif

Placez la clé qui chiffre les coordonnées bancaires dans `wp-config.php`, hors de la base de données, pour qu'une copie de la base ne suffise pas à lire les IBAN. Les IBAN chiffrés sont ceux des familles, ceux des demandes d'inscription en cours et celui du créancier (**Réglages**).

## Où se trouve la clé aujourd'hui

Le plugin cherche d'abord la constante `PSC_ENCRYPTION_KEY` dans `wp-config.php`. Si elle n'est pas déclarée, il utilise une clé que WordPress tire de l'option `secret_key`, **enregistrée dans la base de données**. Dans ce cas, un dump de la base contient à la fois les IBAN chiffrés et de quoi les déchiffrer.

Pour savoir dans quel cas se trouve votre site, lancez sur le serveur :

```
wp psc chiffrement statut
```

La première ligne indique **Clé courante : constante PSC_ENCRYPTION_KEY (wp-config.php)** ou **Clé courante : option secret_key, EN BASE**. Les lignes suivantes comptent les valeurs chiffrées avec la clé courante, avec une ancienne clé, restées en clair, et illisibles.

## Avant de commencer

- Vous avez un accès WP-CLI au serveur et le droit de modifier `wp-config.php`.
- Vous avez fait la sauvegarde habituelle : voir [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md).
- Vous disposez d'un coffre de mots de passe où conserver la nouvelle clé, à l'écart des sauvegardes de la base.

## Étapes : sortir la clé de la base

1. Générez une clé :

    ```
    wp psc chiffrement generer-cle
    ```

    La commande affiche une ligne `define( 'PSC_ENCRYPTION_KEY', '…' );`. La clé n'est enregistrée nulle part : copiez-la tout de suite dans votre coffre de mots de passe.

2. Ajoutez cette ligne dans `wp-config.php`, avant la ligne « That's all, stop editing! ».

    Le site continue de fonctionner normalement : pendant la transition, il lit aussi les valeurs chiffrées avec l'ancienne clé tirée de la base, mais toute nouvelle valeur est chiffrée avec la nouvelle clé.

3. Vérifiez ce qui reste à rechiffrer, sans rien modifier :

    ```
    wp psc chiffrement rechiffrer --dry-run
    ```

4. Rechiffrez :

    ```
    wp psc chiffrement rechiffrer
    ```

    Chaque IBAN est relu, puis rechiffré avec la clé de `wp-config.php`. Un IBAN modifié par une famille pendant l'opération n'est pas écrasé. La commande peut être relancée : les valeurs déjà rechiffrées ne sont pas retouchées. L'opération est notée dans le **Journal d'audit** (« systeme.rechiffrement »).

5. Contrôlez le résultat :

    ```
    wp psc chiffrement statut
    ```

    Le message **Toutes les valeurs lisibles sont chiffrées avec la clé de wp-config.php** confirme la fin de l'opération. Ouvrez ensuite **Périscolaire › Familles** : pour une famille en prélèvement, l'IBAN masqué s'affiche sous **Prélèvement** (un IBAN illisible laisserait un champ vide). Vérifiez aussi l'IBAN du créancier dans **Réglages**.

6. Faites une nouvelle sauvegarde de la base. Les sauvegardes antérieures restent lisibles avec l'ancienne clé, que la base contient : protégez-les ou supprimez-les selon votre politique de conservation.

!!! warning "Valeurs illisibles"
    Si `statut` ou `rechiffrer` signalent des **valeurs illisibles**, aucune clé connue ne les déchiffre (base restaurée avec une autre clé, par exemple). Elles sont laissées intactes et désignées par leur table et leur numéro. La famille concernée devra ressaisir son IBAN.

## Changer de clé plus tard

Pour remplacer une clé déjà placée dans `wp-config.php` (départ d'un prestataire, fuite suspectée) :

1. Générez une nouvelle clé avec `wp psc chiffrement generer-cle`.
2. Dans `wp-config.php`, renommez la ligne existante en `PSC_ENCRYPTION_KEY_PREVIOUS`, puis ajoutez la nouvelle `PSC_ENCRYPTION_KEY`.
3. Lancez `wp psc chiffrement rechiffrer`, puis `wp psc chiffrement statut`.
4. Une fois toutes les valeurs à la nouvelle clé, retirez la ligne `PSC_ENCRYPTION_KEY_PREVIOUS`.

## Résultat attendu

`wp psc chiffrement statut` indique que la clé courante est la constante de `wp-config.php` et que toutes les valeurs lisibles sont chiffrées avec elle. La clé est sauvegardée dans un coffre, séparément des dumps de la base.

!!! danger
    Une fois la clé dans `wp-config.php`, perdre `wp-config.php` sans copie de la clé rend les IBAN illisibles. Conservez la clé dans votre coffre, et testez la restauration avec elle (voir [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)).

## Pour aller plus loin

- [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)
- [Fiche de recette de l'hébergement](fiche-recette-p1.md)
- [Données personnelles (RGPD)](../rgpd.md)
