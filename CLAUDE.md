# CLAUDE.md — Ferme Tranquille

Jeu de ferme personnel, solo, jouable sur mobile et PC via navigateur.
Inspiré des mécaniques d'un jeu de gestion de ferme mobile (champs, élevages, chaînes de production, marché, extensions de terrain), **sans PvP, sans co-op, sans événements, sans achats**.
Un panneau admin permet de modifier librement ressources, niveau et vitesse du temps.

## Règles impératives

- Projet **strictement personnel, jamais publié** (dépôt privé). Décision du propriétaire (2026-10) : les images de bâtiments fournies dans `asset-a-integrer/` peuvent être intégrées (`public/assets/img/buildings`, voir VX). Pas de logos, noms de marque ni personnages du jeu d'origine dans l'interface. Le nom du jeu est « Ferme Tranquille ».
- Aucune image ni fichier chargé depuis bfmhfansite.com. Seules des **valeurs chiffrées** (coûts, niveaux, prix) servent de base d'équilibrage, saisies dans `data/game-data.json`.
- Toute valeur inconnue/estimée porte `"_estimated": true` dans le JSON.
  Les estimations en place au 2026-10-18 ont été **validées par le joueur** : on les garde telles quelles (le marqueur reste pour la traçabilité).
- Code en anglais (identifiants, commentaires techniques), **interface en français**.
- Travailler étape par étape (voir « Plan V1 »), un commit par étape, tests verts avant de passer à la suivante.

## Stack

- PHP 8.4+, `declare(strict_types=1);`, PSR-12, **sans framework**.
- MySQL 8 / MariaDB via PDO (requêtes préparées uniquement ; production : MariaDB 11.8).
- Front : HTML + CSS + JavaScript vanilla (ES modules), sans build.
- Composer uniquement pour PHPUnit (dev).
- Serveur : Debian 13 + Caddy + PHP-FPM (`bfmh.dagniere.com`), déploiement `git pull` (voir `DEPLOY.md`).
- Mise en ligne : voir `DEPLOY.md` (guide pour l'agent qui a accès au serveur : prérequis, base, config, vhost, HTTPS, accès privé, sauvegardes).

## Structure

```
/public                 ← racine web servie
  index.html            ← jeu
  admin.html            ← panneau admin
  api/index.php         ← routeur API unique
  assets/css/app.css
  assets/js/            ← main.js, api.js, store.js, actions.js, render.js, dom.js, format.js, ui/*.js
  .htaccess             ← réécriture /api/* → api/index.php
/src
  Db.php                ← connexion PDO singleton
  Auth.php              ← session, login, CSRF
  GameData.php          ← chargement + cache de data/game-data.json
  StateRepository.php   ← lecture/écriture état + verrou optimiste
  Engine/               ← logique de jeu pure (aucun accès BDD)
    Engine.php          ← applique une action à un état
    Actions/*.php       ← une classe par type d'action
    Clock.php           ← temps serveur × multiplicateur admin
/data/game-data.json   ← valeurs de jeu (éditable à la main, validé au chargement)
/sql/schema.sql
/config/config.example.php   ← config.php réel ignoré par Git
/scripts/create-user.php     ← CLI : création du compte joueur
/tests                       ← PHPUnit, cible Engine en priorité
```

## Base de données

```sql
users(id PK, login UNIQUE, password_hash, created_at)
player_state(user_id PK FK, state_json JSON, version INT, updated_at)
action_log(id PK, user_id, action_type, payload_json, created_at)  -- debug, purge > 30 jours
bot_world(id PK = 1, meta_json JSON, version INT, updated_at)      -- monde des bots : jour, semaine, vitesse
bots(id PK, tier, data_json JSON)                                   -- un bot par ligne (tier = palier de la semaine)
```

L'état complet de la ferme est un document JSON (`state_json`). `version` est incrémentée à chaque écriture ; une écriture avec une version périmée renvoie HTTP 409 et le front recharge l'état.

## Principe du temps réel

- **Le serveur fait autorité** : chaque action est validée et appliquée côté serveur avec l'heure serveur.
- Toute production stocke `started_at` et `duration` ; elle est prête si `now >= started_at + duration / speed`.
- Rien ne tourne en tâche de fond : l'état est « résolu » à la lecture. Fonctionne donc hors-ligne et entre appareils.
- Le front reçoit `server_now` à chaque réponse pour calculer son décalage d'horloge et afficher les comptes à rebours.
- `speed` (multiplicateur de temps, défaut 1) est réglable dans l'admin.

## Modèle d'état (state_json)

```json
{
  "level": 1, "xp": 0,
  "cash": 2000, "gold": 50,
  "materials": { "boards": 0, "nails": 0, "saws": 0, "mallets": 0, "buckets": 0, "bricks": 0, "tiles": 0, "glass": 0 },
  "barn": { "items": { "corn": 10 } },
  "expansions": 6,
  "buildings": [
    { "id": "b1", "type": "barn", "x": 0, "y": 0, "level": 1 },
    { "id": "b2", "type": "field", "x": 3, "y": 0, "level": 1,
      "job": { "product": "corn", "started_at": 1760000000, "duration": 300 } }
  ],
  "next_id": 3,
  "contracts": [
    { "id": "c1", "items": { "corn": 12 }, "reward": { "cash": 90, "xp": 9, "materials": { "nails": 2 } } },
    { "id": "c7", "items": { "wildflower": 8 }, "reward": { "cash": 10, "xp": 1 },
      "wait": { "started_at": 1760000000, "duration": 600 } }
  ],
  "market_seed": "27ea97fcb04b5d0b", "next_contract": 8,
  "settings": { "speed": 1 }
}
```

- La grange est un bâtiment de la grille ; sa capacité vient de son niveau (`game-data.json`), elle n'est pas stockée.
- Contrats tirés de façon déterministe : contrat n = graine `sha256(market_seed:n)` (recharger ne relance pas le tirage). `wait` = contrat de remplacement après « jeter », utilisable quand `now >= started_at + duration / speed`.
- `xp` = progression dans le niveau courant. `next_id` ne recule jamais (ids jamais réutilisés).
- Échelle : **1 case = 3×3 unités du jeu d'origine** (6×6 → 2×2 : moulin, habitations, poulailler, chèvrerie, clapier, fabrique, atelier, grange, ferme principale ; 3×6 → 1×2 : silo, composteur, ruche ; 9×6 → 3×2 : champs, prairie, vergers, porcherie, étable, canarderie, bergerie, confitures, laiterie ; décorations 1×1 en attendant). Départ : grange (0,0), ferme principale (0,2), champs (2,0) et (2,2).
- La grille est découpée en blocs d'extension (4×4 cases), numérotés en carré croissant (voir `Engine/Land.php`) ; posséder `n` extensions = posséder les blocs 1..n. La taille de grille affichée en découle (plus petit carré contenant le prochain bloc achetable). Les blocs non achetés sont verrouillés.

## API

Toutes les réponses : `{ ok, state?, server_now, error? }`. POST protégés par jeton CSRF (header `X-CSRF-Token`).

| Méthode | Route | Rôle |
|---|---|---|
| GET | /api/session | statut connecté + jeton CSRF (à appeler avant le login) |
| POST | /api/login | login + mot de passe, ouvre session (cookie 30 jours) |
| POST | /api/logout | ferme la session |
| GET | /api/data | contenu de game-data.json |
| GET | /api/state | `{ state, version, view }` (créé au premier accès) |
| POST | /api/action | `{ type, payload, version }` → `{ state, version, view }` |
| POST | /api/admin | `{ op, payload, version }` → `{ state, version, view }` (même verrou 409/422 qu'`/api/action`, journalisé `admin:<op>`) |
| GET | /api/bots | classement : tous les bots (id, nom, titre, niveau, niveau de marché, palier) + `me` + `world` |
| GET | /api/bot?id= | profil d'un bot (bâtiments et niveaux, cash, terrain) |

- `view` = valeurs dérivées jamais stockées : `barn {capacity, full}`, `grid {side, block_size, owned_blocks, next_block}`, `xp_needed`, `jobs {id: {ready_at, ready}}`, `contracts {id: {available_at, available}}`, `production {id: {inputs, outputs, duration, xp}}`.
- Codes d'erreur : 400 requête mal formée, 401 non connecté, 403 CSRF, 409 `stale_state` (renvoie `state` + `version` à jour), 422 règle de jeu (`error` = code stable, `message` en français, `details`).
- Rotation : `build`/`move` acceptent `rotated` (bool) et `rotate { id }` pivote sur place ; un bâtiment pivoté porte `rotated: true`, son emprise `size` est inversée (3×2 → 2×3) et son image est affichée en miroir (un quart de tour en vue isométrique). Bouton « ↻ Pivoter » (ou touche R) pendant le placement, et dans le panneau du bâtiment.
- Bâtiments `zone: "outside"` (grange extérieure, bibliothèque, animal de concours, café-boutique, atelier de couture ; un atelier déjà posé sur une ancienne ferme quitte la grille) : hors grille, sans x/y, ni déplaçables ni démolissables.

Types d'action V1 : `build`, `move`, `demolish`, `plant`, `harvest`, `start_production`, `collect`, `sell_barn`, `fulfill_contract`, `discard_contract`, `buy_expansion`, `upgrade`.

Productions en cours (champ, bâtiment de production) : `cancel_job { id }` annule, tout ce qui a été engagé est perdu ; `speed_up { id }` rend prêt tout de suite contre de l'or : `speed_up.gold_per_minute` (10) par minute restante entamée, 10 au minimum. Animaux de concours : `cancel_show_animal { species }` (production perdue, emplacement libéré).

« Tout » : `plant_all` (mêmes options que `plant`) plante le champ choisi puis tous les autres champs libres ; `start_production_all` (mêmes options que `start_production`) lance le bâtiment choisi puis tous les bâtiments libres du même type, variantes comprises (lots plafonnés par bâtiment). Chaque bâtiment refusé (ressources épuisées) est annulé et passé ; le front signale ceux qui restent libres. Pas de « Tout » pour les silos. Construire : onglets Normaux, Premium (premium et fertile), Plein air, Décoration, Extérieur. Nombre de bâtiments : champs, bâtiments d'animaux, vergers et prairies (variantes comprises) **illimités** (`max_count: null`) ; les autres gardent leur limite (moulin 2, moulin premium 3, fabrique artisanale 2, silo 2, composteur 2, ruche 2, laiterie 1, confitures 1, grange 1…). Marché : « Jeter » sans confirmation.

Opérations admin des bots (monde commun, la ferme ne change pas) : `set_bot_speed { speed }` (0,1 à 100), `advance_bots { days }` (1 à 60), `reset_bots`.

Opérations admin : `set_resources` (cash, or, matériaux, objets de grange — peut dépasser la capacité), `set_level` (+ xp optionnel), `set_speed` (0,1 à 1 000), `finish_all_jobs` (productions et contrats en attente), `unlock_all` (niveau max + tout le terrain), `reset_farm`, `set_tournament_curve`, `finish_tournament_week`. Interface : onglet Admin du jeu et `public/admin.html` (même composant `ui/admin.js`).

## Règles de jeu V1

- **Champs** : construits sur la grille, on y plante une culture (cash, durée et XP fixes par culture). La récolte dépend du **niveau du champ** (`crops.*.yields[niveau-1]`, 18 niveaux) et donne du **nectar de fleur** dès le champ niveau 8 (`flower_nectar`). Récolte figée à la plantation (`job.outputs`). Humus : +25 % sur la culture.
- **Grange extérieure** : 100 niveaux (+50 de capacité par niveau, 5 000 au niveau 100 ; coût en or × 1,17 par niveau depuis le 27, 100 milliards pour le 100).
- **Grange** : fumier, nourritures, engrais, humus et feuilles ne sont **pas limités** (`items.*.barn_limit: false`). Les autres : capacité limitée **par produit** (ex. 200 fleurs ET 200 maïs à la fois ; la grange extérieure s'ajoute), améliorable, vente directe au prix « grange » (curseur de quantité). Écran en deux onglets : **Produits** (tuiles groupées champs → animaux → vergers → autres, triées par niveau de déblocage ; cultures débloquées et produits des bâtiments possédés affichés même à 0) et **Ressources** (objets spéciaux, fumier, feuilles, nectar et tout ce qui est sans limite de grange) ; l'objet choisi s'affiche en haut avec sa vente. Récolte / collecte avec grange pleine : une fenêtre propose de vendre au prix grange, un curseur par produit qui déborde, du minimum (ce qui ne rentre pas) à tout le stock (`harvest` / `collect` / `collect_show_animal` avec `sell { produit: n }`, pris sur la récolte puis la grange, jamais moins que le surplus ; `sell_surplus: true` = le minimum).
- **Marché** : 6 contrats visibles, chacun demande 1 à 3 produits **vendables au marché** (`items.*.marketable` : champs, animaux, vergers, prairie, ruche ; jamais nourritures, fumier, engrais, humus, feuilles, nectars ni produits d'usine) que le joueur peut fournir (cultures débloquées + produits des bâtiments possédés), récompense cash + XP + parfois matériaux (planches, clous, scies). Un contrat jeté est remplacé après un délai. Les prix suivent la colonne « marché » des données.
- **Bâtiments de production** : chaque niveau liste ses `recipes` (clé = produit principal) `{ inputs, cost, outputs, duration, xp }` pour **un lot**. Mode `run` (animaux, vergers) : un lot par production. Mode `batch` : 1 à N lots, N = `max_batches` du niveau (laiterie, confitures) ou `max_output / sortie d'un lot` (moulin, silo, composteur, ruche), tout est multiplié (ingrédients, cash, produits, durée, XP). `start_production { id, recipe?, quantity? }` prend les ingrédients et paie ; `collect` met tous les produits en grange ou rien (job figé au lancement, XP comprise). Refus `barn_too_small` si un produit dépasse la capacité.
- Animaux : 10 nourritures **de leur espèce** + cash par production (poules 4 💵 / 6 min 30, cochons 24 / 1 h 10, vaches 185 / 7 h, chèvres 43 / 2 h, canards 68 / 3 h, lapins 32 / 45 min, moutons 32 / 1 h). Moulin : 7 nourritures, chacune faite de la culture débloquée au même niveau que l'animal (maïs, chou, blé, pissenlit, mâche, carotte, orge) ; quantités, cash, durées et XP du jeu pour les 12 niveaux (ex. volaille : 25 maïs + 2 💵, 3 min → 10). Silo : 210 fumiers + 209 💵 → 10 engrais (56 min au niv. 1, 6 min au niv. 10). Vergers : engrais + cash par production (pommeraie 10 + 496 💵, cerisaie 30 + 1 419, amanderaie 20 + 700, pêcheraie 5 + 474). Composteur : 20 feuilles + 60 💵 → 1 humus (4 h au niv. 1). Les « + N » des écrans du jeu sont des bonus du joueur, non appliqués.
- **Extensions** : `buy_expansion { option }` achète l'extension suivante avec `costs[option]` (alternatives : or, ou cash + matériaux dès la n° 11) + niveau minimum ; débloque le bloc suivant, la grille affichée grandit d'elle-même. Dernière extension : n° 55.
- **Niveaux** : courbe d'XP dans les données (`round(50 × n^1,6)`), **sans niveau maximum** (`player.max_level: null`), débloque cultures/bâtiments ; « tout débloquer » (admin) va au niveau du dernier déblocage.
- **Or** (`gold_rewards`) : 100 or au premier passage de chaque jour, 1 000 or par niveau gagné ; au tournoi, or de place en bronze 1★ 1 000 / 500 (2ᵉ–3ᵉ) / 250 (milieu) / 100 (relégués), × (palier + 1) (bronze 2★ : 2 000 / 1 000 / 500 / 200…). Les bots gagnent l'or de la même façon.
- Hors V1 (V2) : ouvriers/maisons, bonheur/décorations, améliorations de niveau multiples, verger, laiterie, boulangerie.

## Données connues (à transformer en data/game-data.json)

Source : valeurs chiffrées relevées sur le fan site, ajustables. Durées de pousse inconnues → estimations marquées.

Cultures (niveau de déblocage, coût par production, vente grange, vente marché) :

| id | nom FR | niveau | coût | grange | marché |
|---|---|---|---|---|---|
| wildflower | Fleurs sauvages | 1 | 0 | 1 | 1.25 |
| corn | Maïs | 1 | 47 | 5 | 7.5 |
| cabbage | Chou | 8 | 95 | 4 | 6.25 |
| wheat | Blé | 14 | 973 | 25 | 37.5 |
| dandelion | Pissenlit | 18 | 165 | 6 | 10 |
| lettuce | Laitue | 31 | 237 | 7 | 11.25 |
| potato | Pomme de terre | 36 | 580 | 24 | 7.5 |
| carrot | Carotte | 55 | 120 | 4 | 6.25 |
| barley | Orge | 100 | 180 | 6 | — |

Durées estimées (`_estimated`) : fleurs 1 min, maïs 5 min, chou 15 min, blé 1 h, pissenlit 30 min, laitue 45 min, pomme de terre 2 h, carotte 1 h 30, orge 3 h. Rendement par champ : 10 unités (estimé).

Maison (niveau, niveau joueur requis, ouvriers, bonheur, coût) — pour V2 :
1/2/10/-35/2 000 · 2/14/15/-60/10 000 · 3/20/20/-70/30 000 · 4/28/25/-90/60 000 · 5/32/30/-110/80 000 · 6/39/35/-120/120 000 · 7/49/40/-130/180 000 · 8/60/45/-140/250 000 · 9/75/50/-150/375 000 + 50 maillets · 10/89/55/-160/563 000 + 325 maillets · 11/105/60/-170/844 000 + 1 200 maillets + 1 350 seaux · 12/?/65/-180/1 265 000 + 5 800 maillets + 6 800 seaux

Extensions (n°, niveau requis, or, cash) : 7/4/50/400 · 8/5/120/1 200 · 9/6/400/4 500 · 10/8/1 200/15 200. Au-delà : estimer cash ×3 par extension, niveau requis croissant, matériaux (planches/clous/scies) à partir de la 11 — tout marqué `_estimated`.

Bâtiments V1 : champ, grange, moulin à grain (maïs → aliment poules ; le blé, débloqué au niveau 14, n'est pas requis pour que la chaîne marche dès le niveau 1), poulailler (aliment poules → œufs + fumier). Niveaux, coûts, capacités et quantités produites relevés sur le fan site ; recettes, durées et tailles estimées. Le joueur complétera les données réelles plus tard.

Courbe XP estimée : `xp_needed(n) = round(50 * n^1.6)`.

## Front

- Une page, CSS grid de cases emoji dans un conteneur scrollable (doigt et souris), zoom simple (boutons +/−).
- Barre du bas : Construire · Grange · Terrain · Admin. Marché, animaux de concours, bibliothèque et café s'ouvrent depuis leur emplacement sur la carte (bande hors de la ferme) ; un fanion au-dessus du marché compte les contrats livrables.
- Barre du haut : niveau + barre XP, cash, or, matériaux.
- Appui sur une case : panneau contextuel (planter, récolter, produire, améliorer, déplacer, détruire).
- Comptes à rebours mis à jour chaque seconde côté client, synchronisation `GET /api/state` toutes les 30 s et au retour sur l'onglet.
- Thème clair/sombre via `prefers-color-scheme`. Responsive dès 360 px.
- Confirmations dans les fenêtres du jeu (`ui/modal.js` : `openModal`, `confirmModal`, bouton rouge pour ce qui détruit ou fait perdre), jamais `confirm` / `prompt` du navigateur (les tests e2e échouent si une boîte du navigateur s'ouvre).

## Tests

- `composer test` : PHPUnit (moteur, données, API, sessions) sur SQLite en mémoire, sans MySQL.
- `composer test:e2e` : tests navigateur (Playwright + pytest, `tests/e2e/`) sur le vrai jeu via `php -S` et la base de `config/config.php`. Ils utilisent un compte dédié `e2e-playwright`, recréé à chaque test puis supprimé ; `tests/e2e/helper.php` refuse tout login ne commençant pas par `e2e-`. Installation : `python3 -m venv .venv && .venv/bin/pip install -r tests/e2e/requirements.txt && .venv/bin/playwright install chromium`. `FT_E2E_HEADED=1` pour voir le navigateur ; captures des échecs dans `tests/e2e/artifacts/`.

## Sécurité

- `password_hash` / `password_verify`, session régénérée au login, cookie `HttpOnly`, `Secure`, `SameSite=Lax`.
- CSRF sur tous les POST. Requêtes préparées partout. `config.php` hors Git.
- Un seul compte, créé via `php scripts/create-user.php <login>`.

## Plan V1 (un commit par étape)

1. Squelette : structure, `composer.json` (PHPUnit), `.gitignore`, `config.example.php`, `schema.sql`, `Db.php`.
2. Auth : `Auth.php`, `create-user.php`, routes login/logout, CSRF.
3. Données : `data/game-data.json` à partir de la section ci-dessus, `GameData.php`.
4. Moteur : `Engine`, `Clock`, actions champs + grange + vente, avec tests PHPUnit (dont temps accéléré et hors-ligne).
5. API : `/api/state`, `/api/action`, verrou optimiste 409.
6. Front : login, grille, construire/planter/récolter, comptes à rebours.
7. Marché : génération et livraison de contrats.
8. Production : moulin à grain + poulailler.
9. Extensions de terrain.
10. Admin : page + `/api/admin`.

À la fin de chaque étape : résumé de ce qui est fait et commande pour tester en local (`php -S localhost:8000 -t public`).

## Plan V2 (un commit par étape)

Données réelles (niveaux, coûts, quantités produites) reprises du fan site ; recettes, consommations, durées et prix estimés (`_estimated`). L'aliment du moulin (`chicken_feed`, « Aliment pour animaux ») nourrit tous les animaux.

1. ✅ Animaux : porcherie, étable, chèvrerie, cabane à canards, clapier, bergerie (aliment → produit + fumier). Nouveaux produits : cochon, lait, fromage, plumes, laine de mouton, laine de lapin, laine blanche.
2. ✅ Vergers : pommeraie (10 engrais, 3 h), cerisaie (30, 12 h), amanderaie (20, 8 h), pêcheraie (5, 1 h) → fruit + feuilles (+ nectar dès le niveau 7). Silo : fumier des animaux → engrais. Consommation fixe par production via `inputs_per_run`.
3. ✅ Usines à recettes multiples : laiterie (crème, beurre, fromage, yaourt à la cerise), cuisine de confitures (pêches, cerises), ruche (miel de forêt, miel de fleur). Humus : +25 % sur une récolte de champ (1 humus au moment de planter).
4. (reportée) Boulangerie : en V3/V4 avec les fermes exotiques.
   ✅ Prairie (lavande, aloe vera, vanille, cacao, jasmin, olive, agrume ; une semence par production) et fabrique artisanale (gel d'aloe vera, extrait de vanille, essences, poudre de cacao, huiles d'olive et d'agrume, teintures rouge/verte/violette) : valeurs du jeu.
5. ✅ Atelier de couture : 20 articles (coussins, chaussons lapins, bonnet, chaussettes, pull × clair/rouge/vert/violet), 10 par fabrication **instantanée** (mode `instant`). Vendus au marché : contrats de 10 à 20 pièces avec bonus de matériaux (`contract_bonus`) : clair/rouge → maillets, clous ou scies ; vert/violet → seaux (1 à 10 selon la commande).
6. ✅ Ouvriers et bonheur. Chaque niveau de bâtiment a `workers` (< 0 : employés, > 0 : logés) et `happiness`. Ferme principale (22 → 32 ouvriers ; sur la grille, permanente) et clôture (110 → 320 de bonheur ; hors grille) existent sur toute ferme (`starter`, id = type ; une ancienne ferme principale hors grille est replacée sur la première place libre). Habitation simple (18 niveaux ; 13 à 18 créés pour Ferme Tranquille, +5 ouvriers par niveau), décorations (parterre, arbre, pavillon, botte de foin, puits, corde à linge, fontaine). Construire / améliorer exige assez d'ouvriers libres ; une habitation dont les ouvriers travaillent ne se détruit pas. Le bonheur (somme des bâtiments, **jamais en dessous de 0** : les habitations annulent au plus le bonus des décorations) multiplie le **cash des productions** (plantations, animaux, vergers, usines) selon `happiness.cost_curve` (0 → ×1 ; 50 → ×0,5 ; 100 → ×0,39 ; 200 → ×0,23 ; 300 → ×0,16 ; −20 → ×1,54 ; −100 → ×3,30 ; −500 → ×4,92 ; linéaire entre les points). Les coûts de construction ne changent pas.
7. ✅ Bibliothèque : données `library.items` (42 produits, coûts parchemins/encre/livres et bonus du fan site, niveaux manquants estimés). `upgrade_library { item }` ; un produit ne dépasse pas le niveau du bâtiment bibliothèque (estimé). Le bonus = unités en plus à chaque récolte / lot de production, figé au lancement. État `library = { item: niveau }`. S'ouvre depuis son emplacement sur la carte.
8. ✅ Améliorations du marché : données `market_upgrades.tracks` (contrats +1 emplacement par niveau, argent, XP, planches, clous, scies, briques, tuiles, verre, parchemins, encre, livres ; fan site, « market certificate » payé en clés ; XP de marché : voir VX ; pistes aventure non reprises). `upgrade_market { track }` ; le bonus s'ajoute à **chaque contrat livré**. État `market_upgrades = { track: niveau }`. Parchemins, encre et livres arrivent aussi en bonus aléatoire des contrats.
9. ✅ Café-boutique (voir ci-dessous) : action `serve_cafe { recipe }` instantanée, données dans `cafe` (16 recettes inventées avec nos produits, débloquées par le niveau du café ; 7 groupes de clients ; paliers du jeu). État `cafe = { day, points, rewarded, served }`, remis à zéro chaque jour (sauf `served`). S'ouvre depuis son emplacement sur la carte.
   ✅ Variantes (`variant_of`, `variant`) : **premium** (or ; produit ×1,6, fumier ×1,6, feuilles +2 ; prairie ×1,5, moulin 16/lot, ruche 80) pour champs, vergers, 7 animaux (clapier et bergerie premium créés pour Ferme Tranquille : produits ×1,6, coût en or = cabane à canards premium × 1,4 / × 2, image générée par `scripts/make-premium-sprite.php`), moulin, prairie, ruche, habitation (14 → 133 ouvriers) ; **fertile** (or) pour champs et vergers ; **plein air** (clés `keys`) pour vergers et 5 animaux (produit ×1,2). Tout ce que le jeu d'origine paie en empreintes ou tickets d'événement se paie ici en **clés** (même quantité ; ex. cerisaie plein air niv. 7 → 8 : 6 100 clés). Coûts, ouvriers et sorties du fan site, recettes de base. Le prix boutique « niveau N » = somme des niveaux 1..N. Champs : `crops.*.yields` = champ normal ; fertile ×1,5 et premium ×1,6, arrondi au supérieur (`yield_factor`, valeurs du jeu : maïs 10/15/16 → 27/41/44 du niv. 1 au 18).
10. ✅ Animaux de concours (voir ci-dessous) : bâtiment présent dès le départ (niveau 1, hors grille). Données `show_animals` (7 espèces, 84 animaux aux noms inventés, coffres, quantités). État `show = { seed, opened, token_day, last_draw, animals, active }`. Actions `open_chest {count: 1|11}`, `buy_tokens {packs}`, `start_show_animal {animal}`, `collect_show_animal {species}`. Cartes pour monter de niveau : 5, 20, 50, 100 (estimé). S'ouvre depuis son emplacement sur la carte.

## Plan V3 — Marché réel, objets spéciaux, tournoi solo (un commit par étape)

Sources : captures du joueur (3 contrats niveau de marché 147, écran du tournoi) et ses explications (2026-10-18). Valeurs déduites marquées `_estimated`.

1. ✅ **Marché réel**
   - XP de marché de base par contrat ≈ `0,055 × argent^0,87` (3 contrats : 1 326 💵 → 29 ; 17 935 → 380 ; 34 563 → 498).
   - Niveau de marché **sans plafond** : XP cumulée pour atteindre le niveau n + 1 ≈ `1 800 × n²` (niveau 147 ≈ 38,8 M).
   - Améliorations en deux parties : normale (matériaux, fan site) **+ « or »** (payée en or, coût doublé à chaque niveau) : Argent or 10 niveaux +5 (100, 200, 400… or) ; Expérience or 10 niveaux +3 (500, 1 000…) ; Expérience de marché or 30 niveaux +5 (300, 600…). XP et XP de marché : bonus **fixe** par contrat. Argent : bonus en **%** du contrat (≈ 0,25 % par point, ex. 95 → ~24 %).
   - Contrats qui grandissent avec le niveau du joueur (de ~10 pièces par produit au niveau 1 à 100+ au niveau 150) ; **chaque contrat** donne un matériau ≈ argent ÷ 100.
2. ✅ **Objets spéciaux** (gratuits : aucun cash à payer en les utilisant ; non vendables, sans limite de grange ; gagnés au tournoi)
   - Cultures de base : pas de semence (illimitées). **Semences premium** par culture : +10 % de récolte.
   - **Roses** : récolte instantanée, +10 XP. **Tulipes** : récolte instantanée, XP de marché selon le niveau du champ (niv. 1 → 450, niv. 10 → 1 620, niv. 18 → 2 340, interpolé). **Fleurs d'or** : 2 h, 200 or.
   - **Nourriture instantanée** (tous les animaux), **engrais instantané** (vergers), **humus instantané** (champs) : un objet remplace la nourriture / l'engrais / l'humus d'une production, qui est **prête tout de suite**.
   - **Nourriture, engrais et humus premium** : +50 % de production, durée normale.
   - Actions : `plant { id, crop, humus?, boost?: premium|instant, seed?: premium }` (crop = `rose`, `tulip`, `golden_flower` pour les semences spéciales) ; `start_production { …, boost?: premium|instant }`. Données : `boosts`, `special_crops`, objets `category: special`, `sellable: false`. Pour l'instant, on les obtient par l'admin (objets de grange) ; le tournoi les donnera.
3. ✅ **Tournoi solo** (onglet 🏅)
   - Une semaine (lundi 0 h → dimanche 24 h, heure serveur). **Points = XP de marché gagnée dans la semaine** (contrats, tulipes).
   - 18 paliers : bronze, argent, or, émeraude, saphir, diamant × 1★ à 3★. Départ en bronze 1★.
   - Ligue de **20** : le joueur + **19 fermes fictives** (noms inventés). Leurs scores de la semaine suivent une **courbe réglable dans l'admin**, 4 lignes : groupe (fixe) → objectif de points (1 à 6) → pourcentage au-dessus (5, 10, 15… %). Par défaut : **1ᵉʳ** → objectif 6 + 15 % ; **podium** (2ᵉ–3ᵉ) → objectif 5 + 15 % ; **milieu de tableau** (4ᵉ–15ᵉ) → objectif 3 + 40 % ; **relégués** (16ᵉ–20ᵉ) → objectif 2 + 50 %. Chaque ferme d'un groupe tire son pourcentage entre (réglage − 5) et le réglage, pour que les scores diffèrent ; tirage déterministe par semaine. Les fermes progressent au fil de la semaine (score proportionnel au temps écoulé).
   - Fin de semaine : les **3 premiers montent**, les **5 derniers descendent** ; récompenses de place versées automatiquement, résumé affiché.
   - Récompenses de place (palier p = 0 pour bronze 1★ … 17 pour diamant 3★) :
     - 1ᵉʳ : clés 10 + 10·p ; maillets dès or 1★ (10, +10 par palier) ; seaux dès saphir 1★ (10, +10 par palier → 60 en diamant 3★).
     - 2ᵉ et 3ᵉ : pareil avec 5 et +5 par palier.
     - Argent pour tous : base = ce qu'un joueur de niveau `1 + 10·p` gagne en vendant à la grange toute sa production de 8 h ; 1ᵉʳ 100 %, 2ᵉ–3ᵉ 80 %, 4ᵉ–15ᵉ 60 %, 16ᵉ–20ᵉ 40 %.
     - Roses pour le podium (estimé).
   - Code : `Engine/Tournament.php` (réglé à chaque lecture comme le café). État `tournament = { week, round, started_at, tier, start_xp, seed, last }` ; points = `market_xp − start_xp`. Les fermes fictives montent de 0 (début du tournoi) à leur score final (fin de semaine). Admin : `set_tournament_curve { curve: { groupe: { objective, percent } } }` (dans `settings.tournament_curve`) et `finish_tournament_week` (termine le tournoi tout de suite, un nouveau court jusqu'à la fin de la semaine). Argent de place par palier : 1 500 → 460 000 💵 (calcul du générateur).
4. ✅ **Objectifs de points du tournoi** : 6 paliers par ligue (6ᵉ = 2 000 points en bronze 1★, 1,5 M en diamant 1★ ; objectifs 1 à 5 = 5, 12, 25, 45, 70 % du 6ᵉ), récompense versée dès que l'objectif est atteint (après chaque action, et à la lecture). Options par objectif, qui changent chaque semaine : 1 = nourriture / engrais / humus instantané ; 2 = tulipes / fleurs d'or / semences premium de la meilleure culture ; 3 = nourriture premium ; 4 = humus premium ; 5 = engrais premium ; 6 = clés. Quantités = base + par palier (diamant 3★ : 5, 10, 150, 70, 150, 60 comme la capture). État : `tournament.claimed`.
5. ✅ **Bots** (`Engine/BotWorld.php`, données `bots`) : 1 000 fermes simulées aux noms de personnages historiques (numérotés en cas de doublon) avec un titre de profil : 20 % « 90 », 30 % « 70 », 40 % « 60 », 10 % « 50 » (part d'une journée de 24 h jouée, ± 10 % par jour). Elles partent du niveau 1 et avancent d'un jour à la fois (timer systemd `ferme-bots` à 0 h 05 / 3 h 05 via `scripts/advance-bots.php`, sinon au premier appel du jour avec 7 jours de rattrapage par requête) : production de toute la ferme avec les durées réelles et les chaînes (une recette n'est gardée que si la ferme y gagne, XP comptée à `xp_value`), demande du marché limitée (`contracts_per_day` contrats, 1 à 3 produits parmi ceux du bot ; le surplus part à la grange), coûts réduits par la clôture comme le joueur, puis achats (terrain, habitations, bâtiments débloqués, améliorations ; jamais d'or). Elles jouent leurs propres ligues chaque lundi (3 montent, 5 descendent) ; la ligue du joueur = 19 bots de son palier tirés avec la graine de tournoi de sa ferme (aléatoire, chaque ferme a sa ligue ; complétée par les paliers voisins), points = vraie XP de marché de la semaine (`TournamentOpponents` / `BotLeagues`). Sans tables `bots`, le tournoi garde ses fermes inventées (courbe admin). Écran : onglets « Ma ligue » / « Classement » (bots et autres joueurs 👤 ; tri niveau, marché, palier ; filtre par titre, bots seuls ; top 100 puis ma place si j'en sors / autour de moi) ; un tap sur un bot ouvre son profil. Vitesse des bots réglable dans l'admin, indépendante de celle du joueur (×2 par défaut, `bots.default_speed`). Les bots gagnent de l'or comme le joueur et le dépensent : version **premium** d'un bâtiment voulu dès qu'ils peuvent la payer (elle compte comme le bâtiment normal), améliorations en or, extensions en or quand le cash manque.
6. Ensuite **V4** : boulangerie et fermes exotiques. Pièces de tournoi et décorations de tournoi : abandonnées.

### Café-boutique (V2.9)

- Recettes (≈ 9 par page, plusieurs pages) : consomment des produits de la grange, rapportent du cash et des **points de satisfaction**. Ex. de référence : 40 œufs + 20 vanille + 25 bonbons → 7 000 💵 et 100 points.
- Chaque recette a un niveau d'étoiles (0 à 7) qui monte **en la réalisant** : 3 réalisations pour la 1ʳᵉ étoile, puis 15 de plus pour la 2ᵉ (18 au total)… 700 de plus pour la 7ᵉ (étoiles 3 à 6 estimées). Récompenses en hausse : cash +1 % par étoile (7 000, 7 070, 7 140…), points +10 % composés (100, 110, 121, 134, 148).
- Un **groupe de clients** du jour (Enfants, Athlètes…) tourne ; certaines recettes rapportent **×2** ce jour-là.
- **Remise à zéro chaque jour** (heure serveur) : jauge de satisfaction et rotation des bonus.
- **Jauge de satisfaction** à paliers : chaque palier donne une récompense (cash, clés, maillets…). Réf. : 190 → 90 000 💵, 760 → 9 clés, 1 520 → 180 000 💵, 2 280 → 450 000 💵, 3 420 → 22 clés, 4 560 → 30 maillets.
- Les **clés** (nouveau matériau) servent à améliorer certaines variantes de bâtiments (ex. cerisier plein air).

### Animaux de concours (V2.10)

- **Jetons** : +20 par jour à la connexion (1ʳᵉ lecture de l'état du jour), ou 10 jetons pour 1 000 or.
- **Coffres** : 1 coffre = 10 jetons, ou 11 coffres = 100 jetons ; une ouverture de 11 donne **11 fois le même tirage**. Tirage : plan (`blueprints`) 0,4 % ; chaque animal gris 3 %, vert 1,3 %, bleu 0,4 %, violet 0,1 % (7 espèces × 3 animaux par rareté ≈ 100 %).
- Bâtiment : son niveau fixe le nombre d'animaux actifs en même temps (`levels[].animals` du fan site : 1, 2, 2, 2, 3, 3, 3, 4), **un seul animal actif par espèce** ; amélioration avec cash + plans.
- Chaque animal a une **rareté** (gris, vert, bleu, violet) et un **niveau 1 à 5** ; la 1ʳᵉ carte le débloque, les suivantes l'améliorent (ex. 13/50 cartes pour le niveau suivant).
- Activer un animal est **gratuit**. Chaque animal a **une seule durée** parmi les 3 de son espèce (`animals.*.duration` ; dans chaque couleur, le 1ᵉʳ court, le 2ᵉ moyen, le 3ᵉ long : Grisette 6 min 30, Picorette 20 min, Cendrine 3 h 30) ; la quantité dépend de la rareté et du niveau. Écran : un onglet par espèce, tuiles par rareté, fiche de l'animal choisi avec « Lancer ». Réf. poules (6 min 30 | 20 min | 3 h 30) :
  - niveau 1 : gris 10|30|323 · vert 11|33|356 · bleu 13|38|404 · violet 18|54|582
  - niveau 5 : gris 30|92|969 · vert 33|102|1 066 · bleu 38|115|1 212 · violet 54|166|1 745 (niveaux 2–4 interpolés)
  - autres espèces (réf. durée longue) : cochon 2 h 30, vache 7 h, chèvre 6 h 30 ; quantités estimées.
- Noms des animaux : inventés pour Ferme Tranquille (jamais ceux du jeu d'origine).

## VX — Habillage graphique (après la V2) ✅

- **Images par niveau** : `php scripts/import-sprites.php images/trier --levels` — le nombre après le nom est le niveau à partir duquel l'image s'applique (`moulin_4.jpg` → `windmill-L4.webp`, du niveau 4 au prochain seuil) ; les rendus JPG sur fond uni sont détourés (`scripts/cutout.php`). Un type qui a des images par niveau n'utilise plus ses paliers. En place : grange (1, 5, 10, 15, 20, 25), corps de ferme (1 à 6), moulin et moulin premium (1, 4, 8, 12), habitation et habitation premium (1, 5, 10, 15, 18), champ et champ premium (1, 5, 10, 15, 18), poulailler et porcherie, normaux, premium et plein air (1, 5, 10, 12). Sans dossier, le script ne fait que remesurer les images et réécrire `manifest.json` (coins gauche / droit cherchés dans la bande du sol, pour ignorer les ailes de moulin).
- **Images de bâtiments** : `php scripts/import-sprites.php asset-a-integrer/all` reconnaît les noms du joueur (`poule_premium_2.png` → `premium_chicken_coop-2.webp`, `marche_1.png` → marché, `terrain_a_acheter.png` → terrain non acheté…), convertit en WebP dans `public/assets/img/buildings/<type>-<palier>.webp` (transparence conservée) et écrit `manifest.json` (taille + coins gauche / droit / bas du losange au sol, mesurés par `scripts/sprite-metrics.php`). Le jeu pose chaque image sur son emprise (même isométrique 2:1), devine son orientation et la met en miroir si besoin (rotation) ; palier manquant → palier inférieur ; pas d'image → dessin SVG. Champs et prairies gardent leurs cultures dessinées par-dessus. Zone cliquable = losange relevé jusqu'en haut de l'image.

- Thème : planches de bois, panneaux crème, cartes bleu clair à cadre brun, gros boutons bombés, titres blancs cernés, étoile de niveau, médaillons ronds dans la barre du bas ; police Fredoka (OFL) servie en local (`public/assets/fonts`) ; variante sombre.
- Carte **isométrique** (`assets/js/iso.js`) : cases en losange (unité `--u` = demi-largeur de case), bord de terre, blocs verrouillés / prochain bloc, clic → case par calcul inverse, aperçu de l'emprise en plaçant (au doigt : 1ᵉʳ tap = aperçu, « Valider » ou 2ᵉ tap = construire), zoom par boutons, pincement ou Ctrl + molette.
- Bâtiments dessinés en SVG par le code (`assets/js/art.js` : murs en planches / pierre / enduit, toits en tuiles / chaume / tôle / ardoise, cylindres, arbres, clôtures), un dessin par type, des détails par variante et **3 paliers visuels selon le niveau** (`tierOf`) : le bâtiment grandit et change de matériaux (ex. poulailler : cabane sur pilotis et grillage → cabane avec abreuvoir → poulailler à étage et clôture blanche ; champs : terre sèche → sillons → bordure de pierres et irrigation dès le niveau 8). Vignette en tête du panneau d'un bâtiment et dans la liste Construire.
- Panneau d'un bâtiment : titre sur deux lignes (nom, puis « Niveau n / max » en petit) ; deux blocs dépliants, « Coût des niveaux suivants » et « Production par niveau » (un onglet par ressource : cultures d'un champ, recettes d'un bâtiment ; capacité de la grange, ouvriers d'une habitation).
- Fanions au-dessus des bâtiments : bleu pâle « zZ » avec le produit quand un champ / bâtiment de production est **inactif**, jaune avec le produit quand c'est **prêt**, rien pendant la production (le temps restant est dans le panneau). Animaux de concours : fanion « zZ 🏆 » s'il reste un emplacement libre, jaune avec le produit quand un animal a fini. Le panneau se ferme après planter, récolter, lancer / récupérer une production ou lancer un animal de concours (sauf fabrication instantanée). Dès que **2 productions ou plus** sont prêtes, un fanion « tout récolter » au-dessus de la grange lance l'action `collect_all` (récolte tout ce qui rentre dans la grange).
- **Bande hors de la ferme** (le long du bord haut-droit, avec un chemin) : marché, animaux de concours, bibliothèque, grange extérieure, café-boutique, atelier de couture, chacun sur un emplacement 4×3 (vide avec panneau tant qu'il n'est pas construit ; un tap ouvre son onglet ou Construire). Le **marché évolue avec le niveau de marché** ; l'animal de concours ne change pas. La clôture de la ferme suit le niveau de la clôture (bois → piquets blancs → piliers en pierre).
- Niveau de marché : chaque contrat livré donne de l'XP de marché (`market.market_xp.per_contract` + amélioration « Expérience de marché » du fan site, 110 niveaux) ; seuils des niveaux estimés. État `market_xp` (total).

S'inspirer de l'ambiance (panneaux bois, cartes à cadre coloré par rareté, étoiles, gros boutons arrondis) avec des visuels **créés pour le projet** (CSS, SVG maison, emojis) : aucune image, icône ni nom du jeu d'origine. Le dossier `images/` (captures de référence) est ignoré par Git.
