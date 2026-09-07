# Données de validation bancaire

- `iban-patterns.json` : 89 formats ISO 13616, registre SWIFT release 102 (juin 2026), https://www.swift.com/swift-resource/9606/download?language=en. Transcription des structures nationales, sans annuaire bancaire.
- `uk-modulus.json` : tables VocaLink V900, applicables depuis le 15 août 2026 (© VocaLink Limited), récupérées dans https://github.com/cs278/bank-modulus/tree/master/res/specifications/VocaLink-V900. Les algorithmes PHP/JS sont adaptés du pilote de cette bibliothèque MIT (licence jointe). Spécification : https://www.vocalink.com/media/sdjd3qea/validating-account-numbers-uk-modulus-checking-v900.pdf.

Ces données sont embarquées : aucune coordonnée bancaire n’est transmise à un service tiers. Les deux validateurs utilisent les mêmes tables. Pour une mise à jour, remplacer les tables depuis les sources officielles et exécuter les tests PHP et navigateur. Un sort code absent des plages VocaLink n’est pas une preuve d’invalidité : conformément à la spécification, le contrôle national n’est alors pas applicable. Le contrôle ne prouve ni l’existence ni l’activité du compte, ni l’identité du titulaire.
