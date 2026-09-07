# Export des ordres de prélèvement SEPA

Dans **Périscolaire → Factures**, sélectionner le mois, renseigner la date de prélèvement convenue avec la banque puis cliquer sur **export fichier pain.008**. Le fichier est téléchargé pour être remis à la banque par la mairie. Le plugin ne transmet aucun ordre et ne change ni les factures ni leur statut d’envoi.

## Configuration

Dans **Réglages**, renseigner le nom du créancier, l’ICS français et l’IBAN du compte à créditer ; le BIC est conseillé, facultatif pour un compte EEE sauf exigence bancaire. L’IBAN du créancier est chiffré dans les options WordPress avec la même protection que les comptes familles.

Chaque famille doit disposer d’un titulaire, d’un IBAN valide, d’une RUM et d’une date d’acceptation du règlement de prélèvement. Par décision de la mairie, cette date alimente la date de signature du mandat (`DtOfSgntr`). Aucune date n’est déduite de la création du compte ou de la facture. Les noms sont translittérés dans le jeu latin accepté par SEPA ; les références sont conservées et refusées si elles ne respectent pas ce jeu.

Pour les opérations impliquant une banque SEPA hors EEE, l’adresse complète et le pays de résidence du titulaire ainsi que le BIC débiteur sont exigés. Le pays de résidence est configurable sur la fiche famille (FR par défaut pour les foyers français). Il n’est jamais déduit du pays de l’IBAN. La migration du schéma 4.4.0 ajoute ce champ.

## Format et contrôles

- Guide CFONB fourni : **Remises informatisées d’ordres de prélèvement SEPA (pain.008), v1.7, février 2023** ; message **pain.008.001.02**.
- Un message et un lot homogène, **SEPA CORE / RCUR**, monnaie EUR, frais SLEV. Le guide autorise RCUR dès la première opération récurrente (§2.5 et §2.9.1). `BatchBooking` est omis : le contrat bancaire détermine la comptabilisation.
- Une opération par facture positive de famille active en prélèvement, sur la base du total DECIMAL enregistré. Les factures nulles sont exclues ; une facture négative ou invalide bloque le fichier. Totaux calculés en centimes entiers.
- RUM, date d’acceptation, références de facture, caractères, longueurs, IBAN, BIC, ICS, dates et périmètre SEPA contrôlés avant production. Une erreur bloque toute la remise, sans export partiel silencieux.
- BIC absent pour une opération EEE : `FinInstnId/Othr/Id = NOTPROVIDED`, conformément au §2.7.3.
- Référence de message et de lot stable pour un même contenu et une même date. Retélécharger le même lot ne crée pas une nouvelle référence ; cela facilite la détection des doublons par la banque, sans remplacer son suivi des remises.
- Validation du XML contre le XSD embarqué **avant chaque téléchargement**, en plus des règles SEPA. Aucun XML bancaire n’est stocké sur le serveur. Accès protégé par capacité et nonce ; réponse `no-store` et téléchargement journalisé sans IBAN.

Le format est celui demandé par le guide de 2023. La banque doit accepter cette version et les conditions du contrat de remise restent applicables (délais, jours de traitement, prénotification). L’export couvre les prélèvements récurrents ordinaires ; les représentations après rejet et les amendements de mandat demandant des données historiques spécifiques doivent être traités dans l’outil bancaire. La validité structurelle ne prouve pas l’existence du compte, la participation de sa banque au scheme SDD CORE ni la validité juridique du mandat.

Périmètre des pays : [EPC v8.0](https://www.europeanpaymentscouncil.eu/document-library/other/epc-list-sepa-scheme-countries). Source et licence du XSD : `includes/schemas/README.md`.

## Vérification

- `php tests/unit/sepa-export.php` : conformité XSD et règles métier, totaux exacts, dates, mandats, références, BIC optionnels, opérations hors EEE, refus de lots invalides et identifiants de remise stables.
- `tests/pain008.spec.ts` : accès, nonce, bouton, téléchargement réel, absence de modification des factures et erreur de mandat. Une fixture isolée est supprimée et les réglages du créancier restaurés après le test.
- `npm run test:banking` : contrôle IBAN navigateur, désormais aussi sur les réglages du compte créancier.
