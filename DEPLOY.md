# Déploiement de Ferme Tranquille — guide pour l'agent qui a accès au serveur

Ce document s'adresse à un assistant (Claude) qui a un accès shell au serveur du propriétaire. Il décrit ce qu'il faut installer et configurer pour mettre le jeu en ligne. Lis-le en entier avant de commencer. Pour le fonctionnement du jeu lui-même, voir `CLAUDE.md` (inutile pour déployer).

## Règles à respecter

- **Projet strictement privé.** Le jeu contient des images de bâtiments issues d'un jeu commercial, utilisées pour un usage personnel. Il ne doit pas être public : voir « Accès privé » ci-dessous, et ne rien publier ni indexer.
- **Ne crée pas et ne lis pas de mot de passe à la place du propriétaire.** Le compte du joueur est créé par le propriétaire lui-même, de façon interactive (voir l'étape 6). Les mots de passe MySQL générés vont uniquement dans `config/config.php`, jamais dans Git, dans un log ou dans la conversation.
- **Le propriétaire a déjà un script de déploiement Git + bash.** Demande-lui comment le code arrive sur le serveur avant d'en inventer un. N'en crée un que s'il le demande.
- **Demande avant toute action difficile à annuler** : suppression de fichiers ou de base, changement de configuration Apache ou MySQL partagée, ouverture de ports.

## Ce qu'est l'application

- PHP 8.4 sans framework, et MySQL 8 via PDO. Le front est en HTML, CSS et JavaScript, sans étape de build.
- Le dossier servi par Apache est **`public/`** (et seulement lui). Tout le reste (`src/`, `data/`, `config/`, `scripts/`, `var/`, `tests/`) doit rester hors du web.
- Une seule page de jeu (`public/index.html`), une page d'admin (`public/admin.html`) et une API (`public/api/index.php`, toutes les routes `/api/*` grâce à `public/.htaccess`).
- Les **sessions** durent 30 jours, dans `var/sessions/`. Le cookie est `Secure` : **HTTPS est obligatoire**, sinon la connexion ne tient pas.
- Les données de jeu (`data/game-data.json`) sont mises en cache dans `var/cache/` ; le cache se renouvelle tout seul quand le fichier change.
- Le fuseau horaire est réglé dans la config (`Europe/Paris`) : il fixe la remise à zéro quotidienne du café et la semaine du tournoi (du lundi 0 h au dimanche 24 h).
- Le journal `action_log` se purge tout seul (> 30 jours). **Aucune tâche cron n'est nécessaire** au fonctionnement du jeu : tout est calculé à la lecture.

## Prérequis sur le serveur

| Élément | Version / détail |
|---|---|
| OS | Ubuntu (cible prévue) |
| Apache | 2.4 avec `mod_rewrite`, `mod_headers`, `AllowOverride All` sur le dossier `public/` |
| PHP | **8.4** (CLI + module Apache ou PHP-FPM), extensions `pdo_mysql`, `json` (incluse), `opcache` recommandée ; `gd` seulement pour importer des images (`scripts/import-sprites.php`, optionnel sur le serveur) |
| MySQL | 8.x |
| Composer | 2.x (sert à générer l'autoload) |
| HTTPS | certificat valide (Let's Encrypt / certbot par exemple) |

Vérifications rapides :

```bash
php -v                      # 8.4.x
php -m | grep -Ei 'pdo_mysql|opcache|gd'
apache2 -v
mysql --version
composer --version
```

## Installation, étape par étape

Remplace les valeurs entre chevrons : `<dossier>` (par ex. `/var/www/ferme-tranquille`), `<domaine>`, `<utilisateur-web>` (souvent `www-data`).

### 1. Code

Récupère le dépôt dans `<dossier>`, avec le script ou la méthode du propriétaire. Le dépôt **ne contient pas** : `vendor/`, `config/config.php`, `var/`, `images/`, `asset-a-integrer/` (tous ignorés par Git, c'est normal).

### 2. Dépendances PHP (autoload)

```bash
cd <dossier>
composer install --no-dev --optimize-autoloader
```

Il n'y a aucune dépendance de production : cette commande sert seulement à créer `vendor/autoload.php`. PHPUnit et les tests navigateur ne sont pas nécessaires en production.

### 3. Dossiers d'exécution

```bash
mkdir -p var/sessions var/cache
chown -R <utilisateur-web>:<utilisateur-web> var
chmod 700 var/sessions
```

### 4. Base de données

En tant qu'administrateur MySQL. Génère un mot de passe aléatoire pour l'utilisateur applicatif, et ne l'affiche pas dans la conversation :

```sql
CREATE DATABASE ferme_tranquille CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ferme'@'localhost' IDENTIFIED BY '<mot-de-passe-aléatoire>';
GRANT SELECT, INSERT, UPDATE, DELETE ON ferme_tranquille.* TO 'ferme'@'localhost';
FLUSH PRIVILEGES;
```

Puis crée les tables :

```bash
mysql -u <admin-mysql> -p ferme_tranquille < sql/schema.sql
```

### 5. Configuration

```bash
cp config/config.example.php config/config.php
chown root:<utilisateur-web> config/config.php
chmod 640 config/config.php
```

Dans `config/config.php` :

- `db.host`, `db.port`, `db.name`, `db.user` et `db.password` (le mot de passe aléatoire de l'étape 4).
- `session.secure` = `true` (HTTPS), `session.save_path` inchangé (`var/sessions`).
- `app.timezone` = `Europe/Paris`, `app.debug` = `false`.

### 6. Compte du joueur (par le propriétaire)

Le script demande le mot de passe de façon masquée. **Laisse le propriétaire le lancer lui-même** dans un terminal (par exemple avec `! <commande>` dans Claude Code) :

```bash
cd <dossier> && php scripts/create-user.php <login>
```

Relancer la même commande sur un compte existant propose de changer son mot de passe.

### 7. Apache (vhost)

Exemple de base, à adapter à la configuration existante du serveur (et à faire valider par le propriétaire s'il y a d'autres sites) :

```apache
<VirtualHost *:443>
    ServerName <domaine>
    DocumentRoot <dossier>/public

    <Directory <dossier>/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Jamais indexé par les moteurs de recherche
    Header always set X-Robots-Tag "noindex, nofollow"

    SSLEngine on
    # SSLCertificateFile / SSLCertificateKeyFile : fournis par certbot
</VirtualHost>

<VirtualHost *:80>
    ServerName <domaine>
    Redirect permanent / https://<domaine>/
</VirtualHost>
```

```bash
a2enmod rewrite headers ssl
apache2ctl configtest && systemctl reload apache2
```

### 8. Accès privé (important)

L'API est protégée par la connexion du jeu, mais **les fichiers statiques de `public/` sont lisibles par n'importe qui** qui connaît l'adresse : pages, JavaScript et surtout les images `public/assets/img/buildings/`. Le site doit donc être fermé au public. **Demande au propriétaire** l'option qu'il préfère :

1. **Authentification HTTP Basic sur tout le site** (simple, le navigateur la retient) :
   ```bash
   htpasswd -c /etc/apache2/.htpasswd-ferme <login>   # mot de passe saisi par le propriétaire
   ```
   ```apache
   <Directory <dossier>/public>
       AuthType Basic
       AuthName "Ferme Tranquille"
       AuthUserFile /etc/apache2/.htpasswd-ferme
       Require valid-user
   </Directory>
   ```
2. **Restriction par adresse IP** ou accès via VPN, si le propriétaire joue toujours depuis les mêmes réseaux.
3. **Au minimum**, si le propriétaire refuse les deux : pas de lien public, `X-Robots-Tag noindex` (déjà dans le vhost) et un `public/robots.txt` avec `User-agent: *` / `Disallow: /`.

### 9. Vérifications

```bash
curl -s https://<domaine>/api/session        # {"ok":true,...,"authenticated":false,"csrf_token":"..."}
curl -sI https://<domaine>/data/game-data.json   # doit être 404 (hors DocumentRoot)
curl -sI https://<domaine>/config/config.php     # doit être 404
```

Ensuite, le propriétaire ouvre `https://<domaine>/` dans son navigateur, se connecte, puis vérifie :
- la carte s'affiche avec ses images ;
- une action (planter) fonctionne ;
- après rechargement de la page, il est toujours connecté.

## Reprendre la ferme locale (optionnel)

Si le propriétaire veut retrouver sur le serveur la ferme de son poste local, c'est **lui** qui exporte depuis sa machine (l'agent du serveur n'y a pas accès) :

```bash
# sur le poste local
mysqldump -u root -p --no-create-info ferme_tranquille users player_state > ferme-export.sql
```

Puis, sur le serveur, **avant** l'étape 6 (sinon le compte existe déjà) et avec son accord :

```bash
mysql -u <admin-mysql> -p ferme_tranquille < ferme-export.sql
```

Le mot de passe de jeu reste celui du poste local. Ne transfère pas `action_log`, ni les comptes de test `e2e-*` s'il y en a.

## Mettre à jour plus tard

```bash
cd <dossier>
# récupérer le code (méthode du propriétaire, ex. git pull)
composer install --no-dev --optimize-autoloader
```

- Rien d'autre à faire : `data/game-data.json` est relu automatiquement (cache par date de modification).
- Si `sql/schema.sql` change un jour, applique seulement les nouveautés, jamais de `DROP`.
- **Nouvelles images** : le propriétaire les importe sur son poste (`php scripts/import-sprites.php asset-a-integrer/all`) et les commite dans `public/assets/img/buildings/`. Sur le serveur, la mise à jour du code suffit.

## Sauvegardes (recommandé)

Toute la ferme tient dans la base. Une sauvegarde quotidienne suffit, par exemple dans la crontab de root :

```cron
15 4 * * * mysqldump --single-transaction ferme_tranquille users player_state | gzip > /var/backups/ferme-$(date +\%F).sql.gz && find /var/backups -name 'ferme-*.sql.gz' -mtime +30 -delete
```

Utilise un fichier d'options MySQL (`~/.my.cnf`, `chmod 600`) plutôt qu'un mot de passe dans la crontab. Mets ça en place seulement avec l'accord du propriétaire.

## Dépannage

| Symptôme | Cause probable |
|---|---|
| Déconnecté à chaque rechargement | Site servi en HTTP (cookie `Secure`) ou `var/sessions` non inscriptible par `<utilisateur-web>` |
| `/api/...` en 404 | `mod_rewrite` désactivé ou `AllowOverride` différent de `All` sur `public/` |
| Réponse `{"error":"server_error"}` | Voir le log d'erreurs Apache/PHP (lignes `[ferme-tranquille]`) ; souvent identifiants MySQL ou `vendor/` manquant. `app.debug = true` montre le message, **à remettre à false ensuite** |
| Erreur 409 « stale_state » ponctuelle | Normal : deux onglets ou appareils ; le jeu recharge l'état tout seul |
| Images absentes (dessins à la place) | `public/assets/img/buildings/manifest.json` absent : le code n'est pas à jour |
| Café ou tournoi décalé d'une heure | `app.timezone` absent ou incorrect dans `config/config.php` |
