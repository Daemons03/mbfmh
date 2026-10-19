# Déploiement de Ferme Tranquille — guide pour l'agent qui a accès au serveur

Ce document s'adresse à un assistant (Claude) qui a un accès shell au serveur du propriétaire. Il décrit l'installation en place et comment la refaire ou la mettre à jour. Lis-le en entier avant de commencer. Pour le fonctionnement du jeu lui-même, voir `CLAUDE.md` (inutile pour déployer).

## Installation en place

| Élément | Valeur |
|---|---|
| Serveur | VPS OVH `debian@162.19.39.231` (IPv6 `2001:41d0:304:400::2f32`), Debian 13 (trixie), `sudo` sans mot de passe |
| Domaine | `https://bfmh.dagniere.com` (A + AAAA) |
| Dossier | `/var/www/ferme-tranquille` (propriétaire `debian`, `var/` à `www-data`) |
| Web | **Caddy** (déjà présent, HTTPS Let's Encrypt automatique) → **PHP 8.4-FPM** (`/run/php/php8.4-fpm.sock`) |
| Base | **MariaDB 11.8** (paquet Debian ; MySQL 8 n'existe pas dans les dépôts Debian 13), base `ferme_tranquille`, utilisateur `ferme@localhost` |
| Code | `git clone github-ferme:Daemons03/mbfmh.git` avec une deploy key en lecture seule (`~/.ssh/ferme_deploy`, alias `github-ferme` dans `~/.ssh/config`) |
| Accès privé | Minimum choisi par le propriétaire : `X-Robots-Tag: noindex, nofollow` + `public/robots.txt` |

**Le serveur héberge aussi un autre site, à ne pas casser** : `foyer.dagniere.com` (inventaire foyer : pile Supabase en Docker dans `/home/debian/inventaire-foyer`, PWA dans `/var/www/foyer-pwa`, APK dans `/var/www/foyer-dl`), servi par le même Caddyfile. Après toute modification de Caddy, vérifie que `https://foyer.dagniere.com/` répond toujours 200.

**Mémoire limitée** (2 Go, dont la plus grande partie prise par Supabase) : MariaDB et PHP-FPM sont réglés au plus bas (voir étape 2). Ne relève pas ces réglages sans vérifier `free -m`.

## Règles à respecter

- **Projet strictement privé.** Le jeu contient des images de bâtiments issues d'un jeu commercial, utilisées pour un usage personnel. Il ne doit pas être public : ne rien publier ni indexer (voir « Accès privé »).
- **Ne crée pas et ne lis pas de mot de passe à la place du propriétaire.** Le compte du joueur est créé par le propriétaire lui-même, de façon interactive (étape 6). Le mot de passe MariaDB généré va uniquement dans `config/config.php`, jamais dans Git, dans un log ou dans la conversation.
- **Demande avant toute action difficile à annuler** : suppression de fichiers ou de base, changement de configuration Caddy, MariaDB ou Docker partagée, ouverture de ports, arrêt de la pile Supabase.

## Ce qu'est l'application

- PHP 8.4 sans framework, base MySQL/MariaDB via PDO (DSN `mysql:`, schéma et requêtes standards : MariaDB convient sans modification). Le front est en HTML, CSS et JavaScript, sans étape de build.
- Le dossier servi est **`public/`** (et seulement lui). Tout le reste (`src/`, `data/`, `config/`, `scripts/`, `var/`, `tests/`) doit rester hors du web.
- Une seule page de jeu (`public/index.html`), une page d'admin (`public/admin.html`) et une API (`public/api/index.php`, toutes les routes `/api/*`). `public/.htaccess` fait cette réécriture pour Apache ; **Caddy l'ignore**, la réécriture est donc dans le Caddyfile.
- Les **sessions** durent 30 jours, dans `var/sessions/`. Le cookie est `Secure` : **HTTPS est obligatoire**, sinon la connexion ne tient pas.
- Les données de jeu (`data/game-data.json`) sont mises en cache dans `var/cache/` ; le cache se renouvelle tout seul quand le fichier change.
- Le fuseau horaire est réglé dans la config (`Europe/Paris`) : il fixe la remise à zéro quotidienne du café et la semaine du tournoi.
- Le journal `action_log` se purge tout seul (> 30 jours). **Aucune tâche cron n'est nécessaire** au fonctionnement du jeu.

## Réinstallation, étape par étape

### 1. Paquets

```bash
sudo apt-get update
sudo apt-get install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-opcache php8.4-mbstring composer mariadb-server
```

`gd` n'est utile que pour importer des images (`scripts/import-sprites.php`), ce qui se fait sur le poste du propriétaire, pas sur le serveur.

### 2. Réglages mémoire

`/etc/mysql/mariadb.conf.d/90-ferme-lowmem.cnf` :

```ini
[mysqld]
bind-address = 127.0.0.1
innodb_buffer_pool_size = 64M
innodb_log_buffer_size = 4M
performance_schema = OFF
max_connections = 20
key_buffer_size = 8M
tmp_table_size = 16M
max_heap_table_size = 16M
```

Dans `/etc/php/8.4/fpm/pool.d/www.conf` : `pm = ondemand`, `pm.max_children = 4`, `pm.process_idle_timeout = 60s`.

```bash
sudo systemctl restart mariadb php8.4-fpm
```

### 3. Code et dépendances

```bash
sudo install -d -o debian -g debian /var/www/ferme-tranquille
cd /var/www/ferme-tranquille
git clone github-ferme:Daemons03/mbfmh.git .
composer install --no-dev --optimize-autoloader
mkdir -p var/sessions var/cache
sudo chown -R www-data:www-data var && sudo chmod 700 var/sessions
```

Le dépôt **ne contient pas** : `vendor/`, `config/config.php`, `var/`, `images/`, `asset-a-integrer/` (ignorés par Git, c'est normal). `composer install` sert seulement à créer `vendor/autoload.php` (aucune dépendance de production).

La deploy key se recrée avec `ssh-keygen -t ed25519 -f ~/.ssh/ferme_deploy -N ""`, puis le propriétaire ajoute la clé publique dans GitHub → Daemons03/mbfmh → Settings → Deploy keys (sans accès en écriture).

### 4. Base de données

Génère un mot de passe aléatoire et ne l'affiche pas dans la conversation (par ex. dans une variable shell, puis directement dans `config.php`) :

```sql
-- sudo mariadb
CREATE DATABASE ferme_tranquille CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ferme'@'localhost' IDENTIFIED BY '<mot-de-passe-aléatoire>';
GRANT SELECT, INSERT, UPDATE, DELETE ON ferme_tranquille.* TO 'ferme'@'localhost';
FLUSH PRIVILEGES;
```

```bash
sudo mariadb ferme_tranquille < sql/schema.sql
```

### 5. Configuration

```bash
cp config/config.example.php config/config.php
sudo chown debian:www-data config/config.php
chmod 640 config/config.php
```

Dans `config/config.php` : `db.password` (le mot de passe de l'étape 4), `session.secure` = `true`, `app.timezone` = `Europe/Paris`, `app.debug` = `false`. Les autres valeurs de l'exemple conviennent.

### 6. Compte du joueur (par le propriétaire)

Le script demande le mot de passe de façon masquée. **Laisse le propriétaire le lancer lui-même** (par exemple avec `!` dans Claude Code) :

```bash
ssh -t debian@162.19.39.231 'cd /var/www/ferme-tranquille && php scripts/create-user.php <login>'
```

Relancer la même commande sur un compte existant propose de changer son mot de passe.

### 7. Caddy

Bloc ajouté à la fin de `/etc/caddy/Caddyfile` (sauvegarde datée `Caddyfile.bak.AAAAMMJJ_HHMMSS` avant toute modification) :

```caddy
bfmh.dagniere.com {
	root * /var/www/ferme-tranquille/public

	header {
		X-Robots-Tag "noindex, nofollow"
		X-Content-Type-Options nosniff
		Referrer-Policy same-origin
		-Server
	}

	# One-off fix for a browser stuck on an old version: empties its cache for the site, then back to the game
	handle /vider-cache {
		header Clear-Site-Data "\"cache\""
		header Cache-Control "no-store"
		header Content-Type "text/html; charset=utf-8"
		respond "<!doctype html><meta charset=utf-8><meta name=viewport content=\"width=device-width\"><meta http-equiv=refresh content=\"2;url=/\"><title>Ferme Tranquille</title><p style=\"font:18px sans-serif;text-align:center;margin-top:30vh\">Cache vidé, retour au jeu… <a href=\"/\">Ouvrir le jeu</a></p>" 200
	}

	# Code and pages: the browser checks for a new version on every load (304 if unchanged)
	@revalidate path / *.html *.js *.css *.json
	header @revalidate Cache-Control "no-cache"

	# Replaces public/.htaccess (Caddy ignores it)
	@hidden path */.*
	respond @hidden 404
	rewrite /api /api/index.php
	rewrite /api/* /api/index.php

	php_fastcgi unix//run/php/php8.4-fpm.sock
	file_server
	encode gzip
}
```

```bash
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo systemctl reload caddy
```

Caddy obtient et renouvelle le certificat tout seul. L'utilisateur `caddy` est dans le groupe `www-data`, ce qui lui donne accès au socket PHP-FPM.

### 8. Accès privé

L'API est protégée par la connexion du jeu, mais **les fichiers statiques de `public/` sont lisibles par n'importe qui** qui connaît l'adresse (pages, JavaScript, images `public/assets/img/buildings/`). Le propriétaire a choisi le minimum : pas de lien public, `X-Robots-Tag noindex` et `public/robots.txt` (`Disallow: /`).

S'il veut fermer davantage plus tard :

- **Authentification HTTP Basic** : `caddy hash-password` (mot de passe saisi par le propriétaire), puis dans le bloc du site :
  ```caddy
  basic_auth {
  	<login> <hash>
  }
  ```
- **Restriction par adresse IP** : `@outside not remote_ip <ip>…` + `respond @outside 403`.

### 9. Vérifications

```bash
curl -s https://bfmh.dagniere.com/api/session            # {"ok":true,...,"authenticated":false,"csrf_token":"..."}
curl -s -6 https://bfmh.dagniere.com/api/session         # idem en IPv6
curl -sI https://bfmh.dagniere.com/data/game-data.json   # 404 (hors DocumentRoot)
curl -sI https://bfmh.dagniere.com/config/config.php     # 404
curl -sI https://bfmh.dagniere.com/.htaccess             # 404
curl -sI https://foyer.dagniere.com/                     # 200 (l'autre site n'est pas cassé)
```

Ensuite, le propriétaire ouvre `https://bfmh.dagniere.com/`, se connecte, puis vérifie : la carte s'affiche avec ses images ; une action (planter) fonctionne ; après rechargement de la page, il est toujours connecté.

## Reprendre la ferme locale (optionnel)

Si le propriétaire veut retrouver sur le serveur la ferme de son poste local, c'est **lui** qui exporte depuis sa machine :

```bash
# sur le poste local
mysqldump -u root -p --no-create-info ferme_tranquille users player_state > ferme-export.sql
```

Puis, sur le serveur, **avant** l'étape 6 (sinon le compte existe déjà) et avec son accord :

```bash
sudo mariadb ferme_tranquille < ferme-export.sql
```

Le mot de passe de jeu reste celui du poste local. Ne transfère pas `action_log`, ni les comptes de test `e2e-*`.

## Mettre à jour plus tard

```bash
ssh debian@162.19.39.231 'cd /var/www/ferme-tranquille && git pull && composer install --no-dev --optimize-autoloader'
```

- Rien d'autre à faire : `data/game-data.json` est relu automatiquement (cache par date de modification), opcache revalide les fichiers PHP modifiés.
- Si `sql/schema.sql` change un jour, applique seulement les nouveautés, jamais de `DROP`.
- **Nouvelles images** : le propriétaire les importe sur son poste (`php scripts/import-sprites.php asset-a-integrer/all`) et les commite dans `public/assets/img/buildings/`. Sur le serveur, la mise à jour du code suffit.

## Sauvegardes (recommandé)

Toute la ferme tient dans la base. Une sauvegarde quotidienne suffit, par exemple dans la crontab de root (root se connecte à MariaDB par socket, sans mot de passe) :

```cron
15 4 * * * mariadb-dump --single-transaction ferme_tranquille users player_state | gzip > /var/backups/ferme-$(date +\%F).sql.gz && find /var/backups -name 'ferme-*.sql.gz' -mtime +30 -delete
```

Mets ça en place seulement avec l'accord du propriétaire.

## Dépannage

| Symptôme | Cause probable |
|---|---|
| Déconnecté à chaque rechargement | Site servi en HTTP (cookie `Secure`) ou `var/sessions` non inscriptible par `www-data` |
| `/api/...` en 404 | Lignes `rewrite /api…` absentes du bloc Caddy |
| 502 sur les pages PHP | PHP-FPM arrêté (`systemctl status php8.4-fpm`) ou `caddy` hors du groupe `www-data` |
| Réponse `{"error":"server_error"}` | Voir `journalctl -u php8.4-fpm` et `/var/log/php8.4-fpm.log` (lignes `[ferme-tranquille]`) ; souvent identifiants MariaDB ou `vendor/` manquant. `app.debug = true` montre le message, **à remettre à false ensuite** |
| Erreur 409 « stale_state » ponctuelle | Normal : deux onglets ou appareils ; le jeu recharge l'état tout seul |
| Mise à jour invisible dans le navigateur | Lignes `@revalidate` / `Cache-Control "no-cache"` absentes du bloc Caddy : le navigateur garde l'ancien JavaScript en cache (rechargement forcé, ou ouvrir `https://bfmh.dagniere.com/vider-cache` qui vide le cache du site via `Clear-Site-Data`) |
| Images absentes (dessins à la place) | `public/assets/img/buildings/manifest.json` absent : le code n'est pas à jour |
| Café ou tournoi décalé d'une heure | `app.timezone` absent ou incorrect dans `config/config.php` |
| MariaDB tué (OOM) | Mémoire saturée par Supabase : `free -m`, `journalctl -k | grep -i oom` |
