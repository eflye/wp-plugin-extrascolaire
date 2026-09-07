# Tests bancaires

Exécution : `php tests/unit/run.php` et `npm run test:banking` (Chromium Playwright).

`iban-cases.json` contient des données fictives : 89 exemples officiels SWIFT release 102 (`iban-swift-examples.json`), les 13 exemples fournis par l’utilisateur, les 34 cas VocaLink V900, puis des régressions majoritairement françaises. Les variantes françaises incluent chaque lettre à chacune des 11 positions du compte, des erreurs de saisie, les caractères interdits et les fausses clés RIB avec une clé IBAN recalculée indépendamment. Les contrôles PHP et navigateur consomment exactement le même jeu.

Le navigateur retire automatiquement CR/LF des champs `type=text` : ces cas sont vérifiés côté PHP pour les requêtes POST directes. Le test navigateur vérifie aussi le blocage de la soumission, la correction et la désactivation/réactivation du champ dans les quatre formulaires : inscription, profil famille, administration des familles et paramètres du compte créancier.

Divergence documentée : `GB68CITI18500483515538` est présenté comme invalide sur https://www.iban.com/testibans, mais passe la table officielle VocaLink V900 (sort code 185004, poids 2/7/6/5/4/3/2/1, total 121 divisible par 11) et le modulo 97. Son résultat attendu reflète ces règles vérifiables, sans prouver l’existence du compte. Aucun numéro n’est bloqué arbitrairement.

Sources et licences : `includes/data/README.md` ; cas VocaLink issus de https://github.com/cs278/bank-modulus/blob/master/tests/Spec/VocaLinkV900.fixtures.txt (© VocaLink Limited).
