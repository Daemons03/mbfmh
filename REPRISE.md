# Reprise du projet — à lire par le prochain assistant

Ce document sert à reprendre le travail sans rien perdre, quand le propriétaire revient avec un autre compte Claude Code (la mémoire des sessions précédentes n'est alors pas disponible). Il complète :

- `CLAUDE.md` : règles du projet et règles du jeu (lu automatiquement) ;
- `DEPLOY.md` : le serveur, l'installation et le dépannage.

Dernière mise à jour : 2026-11-10.

## Le propriétaire et sa façon de travailler

- Il **joue sur le site en ligne** (`https://bfmh.dagniere.com`, sur mobile et PC) et demande des améliorations au fil du jeu, en français. Réponds en français.
- Après chaque demande, il attend la boucle complète : coder, **tester** (PHPUnit et e2e), commiter, pousser, **déployer sur le serveur**, vérifier, puis résumer en quelques lignes ce qui change pour lui.
- **Avant de changer l'équilibrage** (récompenses, gains, tailles de ligue…), montre-lui un tableau des valeurs et attends son accord.
- Quand il dit « prends juste des notes, on fera tout d'un coup », ne code rien : note, reformule, pose les questions qui manquent.
- **Demande avant toute action difficile à annuler**, sur le serveur comme sur les données des fermes. Avant de modifier la ferme d'un compte, sauvegarde son `state_json` (exemple : `~/backups/ferme-bots-20261108/` sur son poste).
- Ne crée et ne lis jamais de mot de passe à sa place (voir `DEPLOY.md`).

## Accès

- Serveur : `ssh debian@162.19.39.231` (clé SSH du poste du propriétaire, `sudo` sans mot de passe). Si l'accès manque avec le nouveau compte, demande-lui.
- Code : dépôt GitHub privé `Daemons03/mbfmh`, branche `main`. Le serveur tire le code avec une deploy key en lecture seule.
- Le serveur héberge aussi **l'inventaire foyer** (Supabase en Docker, `foyer.dagniere.com`). N'y touche jamais sans accord : pas d'arrêt de conteneur, pas de modification de sa config ni du Caddyfile partagé.

## Déployer

```bash
git push
ssh debian@162.19.39.231 'cd /var/www/ferme-tranquille && git pull -q && git log --oneline -1'
curl -s -o /dev/null -w "%{http_code}\n" https://bfmh.dagniere.com/api/session   # 200 attendu
```

Pas de `composer install` sauf si les dépendances changent, pas de `sudo -u www-data git pull` (refusé : *dubious ownership*). Si le propriétaire voit encore l'ancienne version, dis-lui d'ouvrir `https://bfmh.dagniere.com/vider-cache`.

## Tester en local

Sur le poste du propriétaire :

- **PHP** : `php` vaut 8.5, sans les extensions utiles. Utilise `php8.4`. Pour les scripts qui appellent `php` (e2e, import d'images avec GD), crée un lien `php → /usr/bin/php8.4` dans un dossier temporaire et mets ce dossier en tête du `PATH`.
- **PHPUnit** : `php8.4 vendor/bin/phpunit tests/Engine tests/GameDataTest.php`. `tests/Api` et les tests de base échouent en local, « could not find driver », car `pdo_sqlite` n'est pas installé pour php8.4. Ce n'est pas une régression.
- **e2e** (Playwright, `.venv` déjà installé) : **jamais sur la base du serveur**. Lance un MariaDB/MySQL jetable dans un dossier temporaire `$T` :

```bash
mysqld --no-defaults --initialize-insecure --datadir=$T/data
mysqld --no-defaults --datadir=$T/data --port=13306 --mysqlx=OFF --socket=$T/sock --pid-file=$T/pid &
mysql --socket=$T/sock -uroot -e "CREATE DATABASE ferme_e2e; CREATE USER 'ferme'@'127.0.0.1' IDENTIFIED BY 'e2e-local'; CREATE USER 'ferme'@'localhost' IDENTIFIED BY 'e2e-local'; GRANT ALL ON ferme_e2e.* TO 'ferme'@'127.0.0.1'; GRANT ALL ON ferme_e2e.* TO 'ferme'@'localhost';"
mysql --socket=$T/sock -uroot ferme_e2e < sql/schema.sql
sed -e "s/3306/13306/; s/'ferme_tranquille'/'ferme_e2e'/; s/'change-me'/'e2e-local'/; s/'secure' => true/'secure' => false/" config/config.example.php > config/config.php
PATH=<dossier du lien php>:$PATH .venv/bin/python -m pytest tests/e2e -q      # ~3 min 30, 75 tests
rm config/config.php; mysqladmin --socket=$T/sock -uroot shutdown; rm -rf $T   # toujours nettoyer
```

- Pars d'une **base neuve** pour la suite complète. Un script lancé à la main sur la base de test (reclassement, clôture) change les paliers des bots et fait échouer les tests du tournoi : ils attendent les 1 000 bots en bronze 1★.
- `test_admin::test_finish_jobs_and_speed` échoue parfois dans la suite complète seulement. Relance-le seul avant de conclure.
- Le shell est **zsh** : une variable n'y est pas découpée en mots. Pour les boucles sur des fichiers, passe par Python.

## Sur le serveur

| Quoi | Comment |
|---|---|
| Bots calculés (1 000, monde commun) | timer `ferme-bots` (0 h 05 et 3 h 05), `scripts/advance-bots.php` |
| Vrais bots Herzo, Daemons, Styfller | timer `ferme-autoplay` (toutes les 15 min) ; `sudo -u www-data php scripts/autoplay.php list` ; journal : `sudo journalctl -u ferme-autoplay.service -n 20 -o cat` |
| Base | `sudo mariadb ferme_tranquille` (root par socket) ; tables `users`, `player_state`, `action_log`, `bot_world`, `bots` |
| Scripts ponctuels | toujours avec `sudo -u www-data php scripts/…` depuis `/var/www/ferme-tranquille` |

Scripts utiles (détails en tête de chaque fichier) :

- `autoplay.php add|remove|list|run` : vrais bots ;
- `tournament-reseat.php [palier] [--apply]` : reclassement unique, déjà fait ; sans `--apply`, il ne fait qu'un aperçu ;
- `tournament-close-now.php` : clôt la semaine du tournoi tout de suite pour tout le monde ;
- `calibrate-tournament-cash.php` : mesure l'argent des lots selon le niveau, en local, sans base ;
- `import-sprites.php` : images de bâtiments, sur le poste local avec php8.4 et GD ;
- `create-user.php` : réservé au propriétaire, mot de passe interactif.

Modifier la ferme d'un compte (exemple : la vitesse d'un vrai bot) : passe par `StateRepository` (`load` puis `save` avec la version, réessaie en cas de `StaleStateException`), jamais par un `UPDATE` SQL direct. Lance-le entre deux tours de bots (évite :00, :15, :30, :45) :

```bash
sudo -u www-data php -r 'require "vendor/autoload.php";
use FermeTranquille\{Db, GameData, StateRepository}; use FermeTranquille\Engine\Engine;
$r = new StateRepository(Db::pdo(), new Engine(GameData::load()));
foreach ([2, 3, 4] as $id) { ["state" => $s, "version" => $v] = $r->load($id); $s["settings"]["speed"] = 1; $r->save($id, $s, $v); }'
```

## Comptes

| id | Login | Rôle |
|---|---|---|
| 1 | Maxime | le propriétaire (diamant 3★, seul dans son palier) |
| 2 | Herzo | vrai bot, activité 90 % |
| 3 | Daemons | vrai bot, activité 100 % |
| 4 | Styfller | vrai bot, activité 95 % |

Le 2026-11-08, la ferme de Herzo a été copiée sur Daemons et Styfller, avec de nouvelles graines de marché, de coffres et de tournoi. Les trois jouent à la **vitesse ×10**, l'ancien réglage de la ferme de Herzo. Les e2e utilisent des comptes `e2e-…`, créés puis supprimés par les tests.

## État et choses à faire (au 2026-11-10)

- **Tournoi à ligue unique** (CLAUDE.md, Plan V3 étape 7), en ligne et reclassé. Une clôture anticipée a eu lieu le 2026-11-10 à 18 h 15 UTC, à la demande du propriétaire. Une nouvelle saison court jusqu'à la clôture normale du lundi.
- **À faire** : quand Herzo, Daemons et Styfller arrivent en **diamant 3★**, le palier du propriétaire (attendu vers le 2026-11-23 s'ils montent chaque semaine), **remets-les à ×1** avec le snippet ci-dessus. Préviens-le avant.
- Argent des lots : `tournament.rank_rewards.cash_by_level`, ce qu'un vrai bot à ×1 encaisse en une journée. C'est mesuré sur une ferme qui grandit vraiment à ×1 : une ferme grandie à ×10 encaisse deux fois moins une fois remise à ×1, et fausse la mesure.
- Premier « Loser of the week » : le bot Omar Khayyam IV (id 951).
- Ensuite, d'après le plan : V4 (boulangerie, fermes exotiques), rien de demandé pour l'instant.

## Pièges connus

- **Panneaux qui ne se redessinent pas** : `refreshSheet` ne fait rien tant qu'un `input`, `select` ou `textarea` du panneau a le focus (`isEditing`), pour ne pas effacer une saisie. Après un choix dans une liste, appelle `blur()` (c'est fait dans l'onglet « Ligues »).
- **Pas de boîte de dialogue du navigateur** (`confirm`, `prompt`) : utilise `ui/modal.js`, sinon les e2e échouent.
- **Fin de semaine du tournoi** : elle est calculée par le monde des bots (`BotWorld::closeWeek`) avant qu'une ferme se résolve. Tout code qui fait jouer une ferme hors de l'API doit d'abord appeler `BotRepository::ensure()` et utiliser `BotLeagues`, comme `scripts/autoplay.php`.
- **Données** : toute valeur estimée garde `"_estimated": true`. `data/game-data.json` est validé au chargement (`GameDataValidator`) ; lance `php8.4 vendor/bin/phpunit tests/GameDataTest.php` après chaque modification.
- **Images** : `images/` (captures) et `asset-a-integrer/` ne sont pas versionnés ; seules les copies converties dans `public/assets/img/buildings/` le sont.
- **Sauvegardes** sur le poste du propriétaire : serveur complet du 2026-10-18 dans `~/backups/serveur-ovh-162.19.39.231-20261018/`, anciennes fermes de Daemons et Styfller dans `~/backups/ferme-bots-20261108/`.
