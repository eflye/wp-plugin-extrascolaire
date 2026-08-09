# Installer une instance de test à la maison (Linux + Docker Compose)

Ce guide déploie le plugin **Périscolaire — Inscriptions** sur un serveur
Linux à la maison, exposé sur Internet via un sous-domaine provisoire
(exemple utilisé ici : `sidiscm-test.flye.fr`), pour un test avec un petit
nombre de bêta-testeurs.

Aucun e-mail n'est réellement envoyé : tous les e-mails du plugin (liens
de connexion, factures, menus...) sont capturés par **Mailpit**, une boîte
de réception de test consultable dans un navigateur. C'est volontaire —
inutile de configurer un vrai relais SMTP pour un test, et ça évite
d'envoyer quoi que ce soit par erreur à une vraie adresse.

**À expliquer aux bêta-testeurs** : leurs e-mails (dont le lien de
connexion sans mot de passe) n'arrivent pas dans leur boîte mail, mais sur
la page Mailpit de l'instance de test — donne-leur l'URL et les
identifiants une fois l'étape [Mailpit](#7-consulter-les-e-mails-de-test-mailpit)
faite.

---

## Vue d'ensemble

| Composant | Rôle |
|---|---|
| `db` (MySQL 8) | Base de données WordPress |
| `wordpress` | WordPress + le plugin (monté depuis ce dépôt) |
| `mailpit` | Capture tous les e-mails sortants du plugin |
| `caddy` | Reverse proxy : HTTPS automatique (Let's Encrypt) pour le site et pour Mailpit |

Deux sous-domaines sont nécessaires :
- `sidiscm-test.flye.fr` → le site (formulaire + backoffice)
- `mail.sidiscm-test.flye.fr` → Mailpit (protégé par mot de passe)

---

## 1. Prérequis

- Un serveur/PC Linux allumé en continu à la maison, avec un accès réseau
  local classique (le reste du guide part d'une Debian/Ubuntu — adapter
  les commandes `apt` pour une autre distribution).
- Un nom de domaine que tu contrôles (ici `flye.fr`) et l'accès à sa zone
  DNS.
- Accès à l'interface d'administration de ta box/routeur (redirection de
  ports).

### Docker déjà installé ?

```bash
docker --version && docker compose version
```

Si ces deux commandes répondent, passe à l'[étape 2](#2-dns). Sinon,
installe Docker Engine (Debian/Ubuntu) :

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
```

Déconnecte-toi puis reconnecte-toi (ou `newgrp docker`) pour que
l'appartenance au groupe `docker` prenne effet, puis revérifie avec la
commande ci-dessus.

---

## 2. DNS

Dans la zone DNS de `flye.fr`, ajoute deux enregistrements **A** (ou
**AAAA** en IPv6) pointant vers l'IP publique de ta maison :

| Nom | Type | Valeur |
|---|---|---|
| `sidiscm-test` | A | `<IP publique de ta maison>` |
| `mail.sidiscm-test` | A | `<IP publique de ta maison>` |

Pour connaître ton IP publique actuelle : `curl -4 ifconfig.me`.

> **IP dynamique.** Si ton FAI ne fournit pas d'IP fixe, elle peut changer
> de temps en temps. Pour un test avec 2 personnes sur une courte durée,
> il suffit généralement de remettre à jour ces deux enregistrements à la
> main si la connexion est coupée/relancée. Pour quelque chose de plus
> durable, utilise un service de DNS dynamique (ex. DuckDNS) ou vérifie si
> ton routeur peut mettre à jour la zone `flye.fr` automatiquement.

---

## 3. Redirection de ports sur la box

Dans l'interface d'administration de la box/routeur, redirige vers l'IP
locale du serveur Linux (ex. `192.168.1.50`) :

- Port **80** (TCP) → port 80 du serveur — nécessaire pour la validation
  automatique du certificat Let's Encrypt.
- Port **443** (TCP) → port 443 du serveur — le trafic HTTPS réel.

Si le serveur a un pare-feu local (`ufw`), autorise ces deux ports :

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
```

---

## 4. Récupérer le projet

```bash
git clone git@github.com:eflye/wp-plugin-extrascolaire.git
cd wp-plugin-extrascolaire
```

---

## 5. Configurer l'environnement

```bash
cp .env.prod.example .env
```

Éditer `.env` :

- `DOMAIN` / `MAIL_DOMAIN` : les deux sous-domaines créés à l'étape 2.
- `ACME_EMAIL` : ton adresse e-mail (notifications Let's Encrypt).
- `DB_PASSWORD` / `DB_ROOT_PASSWORD` : deux mots de passe forts et
  différents de ceux utilisés en dev local.
- `MAILPIT_BASIC_AUTH_USER` : le nom d'utilisateur pour accéder à Mailpit
  (le mot de passe se configure à l'étape suivante, directement dans le
  `Caddyfile`).

## 6. Protéger l'accès à Mailpit

Génère un hash du mot de passe que tu donneras aux bêta-testeurs pour
consulter Mailpit :

```bash
docker run --rm caddy:2-alpine caddy hash-password --plaintext 'CHOISIR-UN-MOT-DE-PASSE'
```

Édite `Caddyfile` et remplace `REMPLACER_PAR_LE_HASH_BCRYPT` par le hash
obtenu (le coller tel quel, avec ses `$`).

---

## 7. Lancer les services

```bash
docker compose -f docker-compose.prod.yml up -d
```

Vérifie que le certificat HTTPS s'obtient correctement :

```bash
docker compose -f docker-compose.prod.yml logs -f caddy
```

Cherche une ligne mentionnant `certificate obtained successfully` pour
chacun des deux domaines, puis `Ctrl+C` pour sortir des logs (les
conteneurs continuent de tourner).

---

## 8. Installer WordPress

Ouvrir `https://sidiscm-test.flye.fr` dans un navigateur : l'assistant
d'installation WordPress se lance (langue, titre du site, identifiant/mot
de passe admin, e-mail). Le terminer normalement.

---

## 9. Activer et configurer le plugin

1. **Extensions** → activer **Périscolaire — Inscriptions**.
2. **Périscolaire > Calendrier scolaire** → « Charger le calendrier
   officiel » (si le conteneur a bien un accès sortant vers Internet —
   sinon, utiliser le bouton d'upload manuel d'un fichier `.ics` sur la
   même page).
3. **Périscolaire > Trimestres** → créer un trimestre et l'activer.
4. **Périscolaire > Réglages** → renseigner les informations de
   facturation (dont l'identifiant créancier SEPA si pertinent pour le
   test).
5. Créer une page WordPress avec le shortcode `[periscolaire_form]`, et la
   définir en page d'accueil ou la lier depuis le menu du site.

(Détail de chaque section : voir le [README](../README.md).)

---

## 10. Consulter les e-mails de test (Mailpit)

`https://mail.sidiscm-test.flye.fr` — demande l'utilisateur/mot de passe
configurés à l'étape 6. Chaque e-mail envoyé par le plugin (lien de
connexion, confirmation de planning, facture, menu de cantine...) y
apparaît, quel que soit le destinataire réel saisi dans WordPress.

C'est cette URL + ces identifiants qu'il faut donner aux bêta-testeurs, en
leur expliquant qu'ils doivent aller y chercher leurs e-mails pendant le
test (en particulier leur lien de connexion).

---

## 11. Ajouter les bêta-testeurs

Deux façons de procéder, au choix :

- **Auto-inscription** : donner l'URL du site aux 2 testeurs, ils
  remplissent le formulaire « Première inscription ? » ; la demande
  apparaît dans **Périscolaire > Demandes**, à valider manuellement.
- **Création directe** : **Périscolaire > Familles** → ajouter directement
  une famille et ses enfants, sans passer par le formulaire public.

---

## 12. Sauvegardes (recommandé, même pour un test)

Sauvegarde simple de la base et des fichiers (factures PDF, menus...) :

```bash
docker compose -f docker-compose.prod.yml exec db \
  sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" wordpress' \
  > backup-$(date +%F).sql

docker run --rm --volumes-from "$(docker compose -f docker-compose.prod.yml ps -q wordpress)" \
  -v "$(pwd)":/backup alpine \
  tar czf /backup/wp-data-$(date +%F).tar.gz /var/www/html/wp-content/uploads
```

Pour automatiser, ajouter ces deux commandes dans un script et le
planifier via `cron` (ex. une fois par jour).

---

## 13. Mettre à jour

```bash
git pull
docker compose -f docker-compose.prod.yml pull   # images WordPress/MySQL/Mailpit/Caddy
docker compose -f docker-compose.prod.yml up -d
```

Le code du plugin (monté depuis ce dépôt) est pris en compte
immédiatement après `git pull`, sans avoir besoin de relancer les
conteneurs.

---

## 14. Dépannage

**Le certificat HTTPS ne s'obtient pas** — vérifier que les ports 80 et
443 sont bien redirigés vers le serveur (étape 3) et que les
enregistrements DNS pointent vers la bonne IP (étape 2) :
`docker compose -f docker-compose.prod.yml logs caddy`.

**Le calendrier scolaire officiel ne se télécharge pas** — le conteneur
n'a pas d'accès sortant vers Internet (pare-feu, proxy...) ; utiliser le
bouton d'upload manuel d'un fichier `.ics` sur **Périscolaire > Calendrier
scolaire** (voir README).

**Un e-mail attendu n'apparaît pas dans Mailpit** — vérifier que la
variable `MAILPIT_ENABLED` est bien à `"true"` pour le service
`wordpress` dans `docker-compose.prod.yml` (c'est le cas par défaut dans
ce guide).
