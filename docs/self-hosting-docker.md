# Installer une instance de test à la maison (Linux + Docker Compose)

Ce guide déploie le plugin **Périscolaire — Inscriptions** sur un serveur
Linux à la maison, pour un test avec un petit nombre de bêta-testeurs. Le
reverse proxy / HTTPS est géré en dehors de ce projet, via **Nginx Proxy
Manager** (déjà en place sur le serveur) — ce compose se contente
d'exposer WordPress et Mailpit sur deux ports locaux, à pointer depuis
Nginx Proxy Manager.

Aucun e-mail n'est réellement envoyé : tous les e-mails du plugin (liens
de connexion, factures, menus...) sont capturés par **Mailpit**, une boîte
de réception de test consultable dans un navigateur. C'est volontaire —
inutile de configurer un vrai relais SMTP pour un test, et ça évite
d'envoyer quoi que ce soit par erreur à une vraie adresse.

**À expliquer aux bêta-testeurs** : leurs e-mails (dont le lien de
connexion sans mot de passe) n'arrivent pas dans leur boîte mail, mais sur
la page Mailpit de l'instance de test — donne-leur l'URL et les
identifiants une fois l'étape [Mailpit](#8-consulter-les-e-mails-de-test-mailpit)
faite.

---

## Vue d'ensemble

| Composant | Rôle | Port hôte |
|---|---|---|
| `db` (MySQL 8) | Base de données WordPress | — (interne) |
| `wordpress` | WordPress + le plugin (monté depuis ce dépôt) | `8085` → 80 |
| `mailpit` | Capture tous les e-mails sortants du plugin | `8086` → 8025 |

Nginx Proxy Manager (géré séparément) fait deux **Proxy Hosts** vers ce
serveur, chacun avec son propre certificat Let's Encrypt :
- domaine du site → `http://<ip-du-serveur>:8085`
- domaine Mailpit → `http://<ip-du-serveur>:8086`, avec un contrôle
  d'accès (Access List, utilisateur/mot de passe) côté Nginx Proxy
  Manager — tous les e-mails des bêta-testeurs, dont leurs liens de
  connexion, y transitent.

---

## 1. Prérequis

- Un serveur/PC Linux allumé en continu à la maison, avec Nginx Proxy
  Manager déjà opérationnel (DNS, ports 80/443, certificats — hors
  périmètre de ce guide).
- Docker + Docker Compose sur ce même serveur (le reste du guide part
  d'une Debian/Ubuntu — adapter les commandes `apt` pour une autre
  distribution).

### Docker déjà installé ?

```bash
docker --version && docker compose version
```

Si ces deux commandes répondent, passe à l'[étape 2](#2-récupérer-le-projet).
Sinon, installe Docker Engine (Debian/Ubuntu) :

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
```

Déconnecte-toi puis reconnecte-toi (ou `newgrp docker`) pour que
l'appartenance au groupe `docker` prenne effet, puis revérifie avec la
commande ci-dessus.

---

## 2. Récupérer le projet

```bash
git clone git@github.com:eflye/wp-plugin-extrascolaire.git
cd wp-plugin-extrascolaire
```

---

## 3. Configurer l'environnement

```bash
cp .env.prod.example .env
```

Éditer `.env` : `DB_PASSWORD` / `DB_ROOT_PASSWORD`, deux mots de passe
forts et différents de ceux utilisés en dev local.

---

## 4. Lancer les services

```bash
docker compose -f docker-compose.prod.yml up -d
```

Vérifie que tout démarre correctement :

```bash
docker compose -f docker-compose.prod.yml ps
```

---

## 5. Configurer Nginx Proxy Manager

Dans Nginx Proxy Manager, créer deux **Proxy Hosts** vers ce serveur :

| Domain | Forward Hostname/IP | Forward Port | Notes |
|---|---|---|---|
| domaine du site | IP du serveur | `8085` | SSL Let's Encrypt + « Force SSL » |
| domaine Mailpit | IP du serveur | `8086` | SSL Let's Encrypt + **Access List** (utilisateur/mot de passe) |

L'Access List sur le Proxy Host Mailpit est importante : tous les e-mails
des bêta-testeurs (dont leur lien de connexion) sont lisibles sur cette
page.

---

## 6. Installer WordPress

Ouvrir le domaine configuré pour le site : l'assistant d'installation
WordPress se lance (langue, titre du site, identifiant/mot de passe
admin, e-mail). Le terminer normalement.

---

## 7. Activer et configurer le plugin

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

## 8. Consulter les e-mails de test (Mailpit)

Le domaine configuré à l'étape 5 pour Mailpit, avec l'utilisateur/mot de
passe de l'Access List Nginx Proxy Manager. Chaque e-mail envoyé par le
plugin (lien de connexion, confirmation de planning, facture, menu de
cantine...) y apparaît, quel que soit le destinataire réel saisi dans
WordPress.

C'est cette URL + ces identifiants qu'il faut donner aux bêta-testeurs, en
leur expliquant qu'ils doivent aller y chercher leurs e-mails pendant le
test (en particulier leur lien de connexion).

---

## 9. Ajouter les bêta-testeurs

Deux façons de procéder, au choix :

- **Auto-inscription** : donner l'URL du site aux 2 testeurs, ils
  remplissent le formulaire « Première inscription ? » ; la demande
  apparaît dans **Périscolaire > Demandes**, à valider manuellement.
- **Création directe** : **Périscolaire > Familles** → ajouter directement
  une famille et ses enfants, sans passer par le formulaire public.

---

## 10. Sauvegardes (recommandé, même pour un test)

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

## 11. Mettre à jour

```bash
git pull
docker compose -f docker-compose.prod.yml pull   # images WordPress/MySQL/Mailpit
docker compose -f docker-compose.prod.yml up -d
```

Le code du plugin (monté depuis ce dépôt) est pris en compte
immédiatement après `git pull`, sans avoir besoin de relancer les
conteneurs.

---

## 12. Dépannage

**Le site n'est pas joignable via son domaine** — vérifier que le Proxy
Host Nginx Proxy Manager pointe bien vers `<ip-du-serveur>:8085`, et que
`docker compose -f docker-compose.prod.yml ps` montre `wordpress` en
statut sain (« healthy »/« Up »).

**Le calendrier scolaire officiel ne se télécharge pas** — le conteneur
n'a pas d'accès sortant vers Internet (pare-feu, proxy...) ; utiliser le
bouton d'upload manuel d'un fichier `.ics` sur **Périscolaire > Calendrier
scolaire** (voir README).

**Un e-mail attendu n'apparaît pas dans Mailpit** — vérifier que la
variable `MAILPIT_ENABLED` est bien à `"true"` pour le service
`wordpress` dans `docker-compose.prod.yml` (c'est le cas par défaut dans
ce guide), et que le Proxy Host Mailpit pointe bien vers
`<ip-du-serveur>:8086`.
