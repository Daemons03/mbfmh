# CLAUDE.md — Ferme Tranquille

Jeu de ferme personnel, solo, jouable sur mobile et PC via navigateur.
Inspiré des mécaniques d'un jeu de gestion de ferme mobile (champs, élevages, chaînes de production, marché, extensions de terrain), **sans PvP, sans co-op, sans événements, sans achats**.
Un panneau admin permet de modifier librement ressources, niveau et vitesse du temps.

## Règles impératives

- **Aucun asset du jeu d'origine** : pas d'images, logos, noms de marque ni personnages. Visuels = emojis + CSS. Le nom du jeu est « Ferme Tranquille ».
- Aucune image ni fichier chargé depuis bfmhfansite.com. Seules des **valeurs chiffrées** (coûts, niveaux, prix) servent de base d'équilibrage, saisies dans `data/game-data.json`.
- Toute valeur inconnue/estimée porte `"_estimated": true` dans le JSON.
- Code en anglais (identifiants, commentaires techniques), **interface en français**.
- Travailler étape par étape (voir « Plan V1 »), un commit par étape, tests verts avant de passer à la suivante.

## Stack

- PHP 8.4+, `declare(strict_types=1);`, PSR-12, **sans framework**.
- MySQL 8 via PDO (requêtes préparées uniquement).
- Front : HTML + CSS + JavaScript vanilla (ES modules), sans build.
- Composer uniquement pour PHPUnit (dev).
- Serveur cible : Ubuntu + Apache, déploiement Git + bash (script existant côté utilisateur, ne pas en créer sauf demande).

## Structure

```
/public                 ← DocumentRoot Apache
  index.html            ← jeu
  admin.html            ← panneau admin
  api/index.php         ← routeur API unique
  assets/css/app.css
  assets/js/            ← main.js, api.js, render.js, ui/*.js
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
  "materials": { "boards": 0, "nails": 0, "saws": 0, "mallets": 0, "buckets": 0 },
  "barn": { "capacity": 200, "items": { "corn": 0 } },
  "expansions": 6,
  "grid": { "cols": 12, "rows": 12 },
  "buildings": [
    { "id": "b1", "type": "field", "x": 2, "y": 3, "level": 1,
      "job": { "product": "corn", "started_at": 1760000000, "duration": 300 } }
  ],
  "contracts": [],
  "settings": { "speed": 1 }
}
```

La grille est découpée en blocs d'extension (4×4 cases). Les blocs non achetés sont verrouillés.

## API

Toutes les réponses : `{ ok, state?, server_now, error? }`. POST protégés par jeton CSRF (header `X-CSRF-Token`).

| Méthode | Route | Rôle |
|---|---|---|
| GET | /api/session | statut connecté + jeton CSRF (à appeler avant le login) |
| POST | /api/login | login + mot de passe, ouvre session (cookie 30 jours) |
| POST | /api/logout | ferme la session |
| GET | /api/data | contenu de game-data.json |
| GET | /api/state | état résolu |
| POST | /api/action | `{ type, payload, version }` → nouvel état |
| POST | /api/admin | `{ op, payload }` → nouvel état |

Types d'action V1 : `build`, `move`, `demolish`, `plant`, `harvest`, `start_production`, `collect`, `sell_barn`, `fulfill_contract`, `discard_contract`, `buy_expansion`, `upgrade`.

Opérations admin : `set_resources` (cash, or, matériaux, objets de grange), `set_level`, `set_speed`, `finish_all_jobs`, `unlock_all`, `reset_farm`.

## Règles de jeu V1

- **Champs** : construits sur la grille, on y plante une culture (coût par production), récolte → grange + XP.
- **Grange** : capacité limitée (améliorable), vente directe au prix « grange ».
- **Marché** : 6 contrats visibles, chacun demande 1 à 3 produits débloqués, récompense cash + XP + parfois matériaux (planches, clous, scies). Un contrat jeté est remplacé après un délai. Les prix suivent la colonne « marché » des données.
- **Bâtiments de production** : consomment des ingrédients, produisent après une durée (ex. moulin à grain → aliment pour poules ; poulailler → œufs).
- **Extensions** : coût cash ou or + niveau minimum, débloquent un bloc de grille.
- **Niveaux** : courbe d'XP dans les données, débloque cultures/bâtiments.
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
- Barre du bas : Construire · Grange · Marché · Terrain · Admin.
- Barre du haut : niveau + barre XP, cash, or, matériaux.
- Appui sur une case : panneau contextuel (planter, récolter, produire, améliorer, déplacer, détruire).
- Comptes à rebours mis à jour chaque seconde côté client, synchronisation `GET /api/state` toutes les 30 s et au retour sur l'onglet.
- Thème clair/sombre via `prefers-color-scheme`. Responsive dès 360 px.

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
