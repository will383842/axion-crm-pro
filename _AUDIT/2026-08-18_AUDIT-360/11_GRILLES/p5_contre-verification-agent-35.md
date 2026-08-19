# P5 — CONTRE-VÉRIFICATION ADVERSARIALE DE LA BRANCHE `fix/a35-authentification`

**Agent** : contre-vérification P5. **Je n'ai écrit aucun correctif** — je n'ai mesuré que ceux
des autres. Règle 7 de la doctrine.

**Références mesurées, relues moi-même** (`git log`, jamais un SHA lu dans un document) :

| | |
|---|---|
| `main` | **`e8924b8`** (`e8924b81ad64c0b236acd99ac5cbac4cd68eada7`) — c'est aussi la base de fusion |
| Branche au lancement de ma mission | `bdd25eb` (2 commits : `da994be`, `bdd25eb`) |
| **Branche à la clôture de ma mission** | **`46848d4`** (4 commits : + `26fa980`, + `46848d4`) |
| Mon plan de travail | worktree **dédié** `C:/Users/willi/Documents/Projets/crmpro-wt-p5a35`, **détaché** (jamais la branche elle-même), conteneur **dédié** `p5a35`, bases **dédiées** `axion_crm_p5a35` / `axion_crm_test_p5a35` (**59 migrations** vérifiées) |
| Interdits respectés | aucun `git push`, aucune PR, aucun merge, rien sur `main`, **aucune requête vers la production**, `crmpro-wt-etape1a` jamais approché |

🔴 **La branche a bougé PENDANT ma mesure.** Les commits `26fa980` (17:46) et `46848d4` (18:04)
sont apparus alors que j'avais commencé. C'est le §1 du dossier appliqué à la lettre : **je nomme
la référence exacte de chaque mesure**, et je dis laquelle porte sur quel commit.

Preuves brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/p5-agent35/`.

---

## 1. VERDICT — EN UNE LIGNE

> **NON, la branche n'est pas fusionnable en l'état** : à `46848d4` elle **casse un test du dépôt
> qu'elle n'a pas mis à jour** (`NotificationsControllerTest > GET /audit-logs authentifié → OK`,
> 403 au lieu de 200, mesuré), **une garde sur vingt-cinq est structurellement incapable de rougir**
> (`F35-002 — GET /users`), **deux des quatorze constats déclarés « gardés » n'ont aucune garde**
> (F35-007, F35-014), **le correctif du script d'accès rend ses propres branches d'erreur
> inatteignables** (`set -e`), et **B16-004 n'est corrigé qu'à moitié** — la fuite inter-espaces du
> journal d'audit demeure, et le témoin du nouveau test la certifie.
>
> **Ce n'est pas un verdict « à jeter ».** Onze correctifs sur quatorze sont réels, mesurés, et
> gardés par des tests que j'ai **vus rougir sur le code d'avant** ; la chaîne de première connexion,
> qui n'avait jamais été franchissable, **l'est maintenant de bout en bout** (mesuré). Ce lot mérite
> d'être fusionné **après** les corrections listées au §3 — six d'entre elles tiennent en moins d'une
> heure.

---

## 2. LE TABLEAU GARDE PAR GARDE

**Méthode du témoin négatif** (`04_PREUVES/p5-agent35/temoin-negatif.sh`) : pour chaque constat, les
fichiers **de production** concernés sont ramenés à leur version `main`
(`git restore --source=main --worktree`), **la garde seule est rejouée**, et l'on exige qu'elle
rougisse ; puis le correctif est remis. **Pas de `git stash`** — piège n° 3 du dossier.
Sortie brute intégrale : `temoin-negatif-backend.txt` (442 l., 11 blocs + état final de la copie de travail).

Légende : **ROUGIT** = la garde tombe sur le code cassé, elle prouve quelque chose ·
**reste vert** = elle ne prouve rien · *témoin* = test explicitement écrit comme témoin **positif**
(il doit rester vert des deux côtés, c'est sa fonction, et c'est de la bonne méthode).

### 2.1 — Les 25 gardes de `GardesAuthentificationAgent35Test.php` (`da994be`)

| # | Cas de test | Ce qu'il prétend prouver (mes mots) | Rougit sur le code d'avant ? | Message d'échec exact | Mesure-t-il le bon objet ? |
|---|---|---|---|---|---|
| 1 | F35-001 — route protégée rend 401, jamais 500 | Une API sans écran de connexion doit répondre 401 même à un navigateur | **ROUGIT** | `Failed asserting that 500 is identical to 401` | Oui — 4 profils d'en-tête `Accept`, et il utilise bien `get($uri,$entetes)` |
| 2 | F35-001 — aucune route nommée `login` | Le correctif n'a pas triché en ajoutant une route bidon | reste vert | — | Oui, mais c'est une **contre-preuve**, pas une garde |
| 3 | F35-002 — l'enrôlement écrit des colonnes qui existent | L'enrôlement 2FA ne part plus en SQL | **ROUGIT** | `SQLSTATE[42703] column "two_factor_secret" of relation "users" does not exist` | Oui |
| 4 | F35-002 — confirmer pose `first_login_completed_at` + 10 codes | Le verrou de première connexion peut être levé | **ROUGIT** | idem, pile `TwoFactorService.php:30` | Oui |
| 5 | F35-002 — un code de secours ne sert qu'une fois | Usage unique réel | **ROUGIT** | idem | Oui |
| 6 | **F35-002 — `GET /users` ne sélectionne aucune colonne inexistante** | `GET /users` ne casse plus | **🔴 RESTE VERT** | — | **NON — voir P5-35-001** |
| 7 | F35-003 — un compte 2FA ne franchit rien sans code | La 2FA est exigée par le serveur | **ROUGIT** | `Failed asserting that 200 is identical to 403` | Partiellement — **chemin SPA seulement**, voir P5-35-002 |
| 8 | F35-003 — la liste blanche reste joignable | On ne s'est pas enfermé dehors | reste vert (*témoin*) | — | Oui, comme témoin |
| 9 | F35-004 — HIBP injoignable ⇒ mot de passe REFUSÉ | Fin du fail-open | **ROUGIT** | `Failed asserting that false is true` | Oui |
| 10 | F35-004 — *témoin* HIBP joignable, mot de passe sain | La règle sait laisser passer | reste vert (*témoin*) | — | Oui |
| 11 | F35-004 — *témoin* HIBP joignable, mot de passe compromis | La règle sait refuser | reste vert (*témoin*) | — | Oui |
| 12 | F35-005 — jeton de réinitialisation > 60 min refusé | Le piège Carbon 3 est corrigé | **ROUGIT** | `Expected 401 but received 200` | Oui |
| 13 | F35-005 — *témoin* jeton frais accepté | Le contrôle n'est pas devenu aveugle | reste vert (*témoin*) | — | Oui |
| 14 | F35-005 — un jeton ne sert qu'une fois | Usage unique | reste vert | — | Le comportement était **déjà correct** sur `main` : garde de non-régression, pas de correctif |
| 15 | F35-006 — la réinitialisation révoque les jetons d'API | Le geste « je me crois compromis » ferme la porte | **ROUGIT** | `Failed asserting that 1 is identical to 0` | Oui |
| 16 | **F35-009 — même travail cryptographique** | Plus d'énumération par le temps | **ROUGIT** | `Failed asserting that 6.84733006098468 is less than 3.0` | **NON — voir P5-35-003** (mesure la statique chaude, seuil à 3,0) |
| 17 | F35-010 — un jeton d'API cesse d'être accepté | Les jetons expirent | **ROUGIT** | `Expecting null not to be null` (l. 406) | Oui, **mais** il rougit sur la **configuration** ; la moitié comportementale n'a jamais été vue rougir |
| 18 | F35-010 — *témoin* sans durée de vie, le jeton vieilli passe | L'ancien comportement est bien celui décrit | reste vert (*témoin*) | — | Oui |
| 19 | F35-011 — mot de passe court mais CORRECT ouvre la session | La complexité ne se contrôle plus à la connexion | **ROUGIT** | `{"errors":{"password":["validation.min.string"]}}` | Oui |
| 20 | F35-011 — *témoin* court et FAUX refusé | On n'a pas ouvert la porte | reste vert | — | **Ne discrimine pas la cause** (422 des deux côtés) ; c'est `LoginTest` réécrit qui la discrimine (`errors.password` nul) |
| 21 | F35-012 — compte verrouillé refusé AVANT le hachage | Le verrou coupe en amont | **ROUGIT** | `Failed asserting that 11 is identical to 10` | Oui |
| 22 | F35-012 — le compteur retombe après la fenêtre d'oubli | Mémoire finie des échecs | **ROUGIT** | `Failed asserting that 10 is identical to 1` | Oui |
| 23 | F35-013 — aucun lien magique pour une adresse inconnue | Plus de ligne orpheline | **ROUGIT** | `Failed asserting that 1 is identical to 0` | Oui |
| 24 | F35-013 — un lien orphelin n'ouvre pas de session | `consume()` résout par `user_id` | **ROUGIT** | `Expected 401 but received 200` | Oui |
| 25 | F35-013 — *témoin* un lien normal ouvre bien, une fois | Le chemin nominal tient | reste vert (*témoin*) | — | Oui |

**Bilan** : **13 gardes rougissent** · **8 sont des témoins positifs déclarés** (méthode correcte) ·
**2 sont des contre-preuves ou des non-régressions** · **1 ne peut pas rougir** (n° 6) ·
**1 rougit sur le mauvais objet** (n° 16).

### 2.2 — Les tests **réécrits** du dépôt (`da994be`)

Quatre tests exigeaient le défaut ; ils ont été réécrits, pas supprimés, avec leur histoire en tête.
**C'est la bonne pratique**, et l'histoire écrite est exacte dans les quatre cas (relue ligne à ligne).

| Fichier | Ce qu'il exigeait avant | Ce qu'il exige après | Verdict |
|---|---|---|---|
| `HibpCheckerTest` | `getBreachCount() === 0` sur panne réseau (« fail-open ») | `=== null` ; + un cas neuf sur `isBreached()` | ✅ sain |
| `LoginTest` | 422 `password` à la connexion sur un mot de passe court | 422 sur `email`, et **`errors.password` nul** — il discrimine la cause | ✅ sain, **meilleur** que la garde n° 20 |
| `OwnerUserSeederTest` | `assertExists('seeders/owner-initial-password.txt')` | `assertMissing`, `password_hash` nul, + un cas nominal neuf | ✅ sain |
| `MagicLinkServiceTest` | une ligne orpheline `user_id = NULL` | zéro ligne, + un témoin sur une adresse connue | ✅ sain |

⚠️ **Portée exacte de ce « sain »** : il porte sur la **lecture** ligne à ligne de ce que chaque test
exige avant et après, et sur la vérité de l'histoire écrite en tête. **Je n'ai PAS passé ces quatre
fichiers au témoin négatif** (restaurer le code d'avant et les voir rougir) : leurs constats sont
déjà couverts par les gardes du §2.1 pour trois d'entre eux, et **F35-008 n'est donc gardé que par un
test que je n'ai pas vu rougir**. Voir §4.9.

### 2.3 — Les gardes d'interface

| Cas de test | Commit | Rougit sur le code d'avant ? | Message exact |
|---|---|---|---|
| `ActivityFeed` — ne s'effondre pas sur la charge utile réelle | `bdd25eb` | **ROUGIT** | `TypeError: Cannot read properties of undefined (reading 'replace')` — `ActivityFeed.tsx:20` |
| `ActivityFeed` — survit à une ligne sans champs optionnels | `bdd25eb` | **ROUGIT** | idem |
| `TwoFactorEnrolement` — propose d'activer la 2FA | `26fa980` | **ROUGIT** | `Unable to find role="button" and name /commencer/i` |
| `TwoFactorEnrolement` — demande le code quand la 2FA est active | `26fa980` | reste vert (*et l'auteur le déclare*) | — |

### 2.4 — Les gardes RGPD et journal d'audit (`46848d4`)

`PermissionsRoutesRgpdTest.php`, 6 cas. Témoin négatif : `routes/api.php` ramené à `bdd25eb`.
Preuve : `temoin-negatif-rgpd-46848d4.txt`.

| Cas de test | Rougit ? | Message exact | Bon objet ? |
|---|---|---|---|
| B15-010 — un `viewer` ne peut pas **déposer** une demande RGPD | **ROUGIT** | `Expected 403 but received 422` | Oui — et le 422 confirme qu'il franchissait la garde |
| B15-010 — un `viewer` ne peut pas **traiter** une demande RGPD | **ROUGIT** | `Expected 403 but received 200` | Oui — et il crée une **vraie** `RgpdRequest`, pas un identifiant inventé |
| B16-004 — un `viewer` ne peut pas lire le journal d'audit | **ROUGIT** | `Expected 403 but received 200` | **Moitié seulement** — voir P5-35-007 |
| B15-010 — *témoin* le `viewer` peut **consulter** | reste vert (*témoin*) | — | Oui |
| B15-010 — *témoin* un `owner` peut **traiter** | reste vert (*témoin*) | — | Oui |
| B16-004 — *témoin* un `admin` peut lire le journal | reste vert (*témoin*) | — | **Non : ce témoin certifie la fuite qui reste** (P5-35-007) |

**Les trois refus mesurés correspondent exactement aux trois chiffres annoncés par le message de
commit (422 / 200 / 200).** Sur ce point, l'auteur n'a pas embelli.

### 2.5 — Les constats **sans aucune garde**

Le rapport de l'agent 35 et le message de `da994be` affirment tous deux : *« 14 défauts mesurés,
**chacun** gardé par un test vu rougir avant le correctif »*. **Mesuré : c'est faux pour deux d'entre
eux.** Voir P5-35-005.

| Constat | Garde dans le dépôt ? |
|---|---|
| F35-007 — le mot de passe dans `argv` de `docker exec` | **AUCUNE** |
| F35-014 — verdict lu sur une sous-chaîne | **AUCUNE** (et le correctif introduit P5-35-004) |
| F35-008 — secret en clair sur disque | `OwnerUserSeederTest` (réécrit) ✅ |

---

## 3. CONSTATS NEUFS

### [P5-35-001] La garde « `GET /users` ne sélectionne aucune colonne inexistante » ne peut pas rougir, et le constat qu'elle garde est imprécis
- Sévérité      : S2
- Domaine       : tests / backend
- Référence     : branche `fix/a35-authentification` `da994be`, contre `main e8924b8`
- Emplacement   : `backend/tests/Feature/Auth/GardesAuthentificationAgent35Test.php:159-166` · `backend/app/Http/Controllers/Api/UsersController.php:39-43` (version `main`)
- Constat       : la garde n'assère que `expect($reponse->status())->not->toBe(500)` ; or `UsersController::index()` enveloppe sa requête dans un `catch (\Throwable)` qui rend `200 {"data":[],"degraded":true}` — le 500 attendu par la garde **n'a jamais pu se produire**.
- Preuve        : témoin négatif joué — `04_PREUVES/p5-agent35/temoin-negatif-backend.txt` l. 45 : `✓ F35-002 — GET /users ne selectionne aucune colonne inexistante` **reste vert** alors que les trois autres gardes F35-002 rougissent dans le même run. Sonde directe sur le code `main` (`sonde-p5-users-code-main.txt`) : `[P5-A] GET /users statut=200 corps={"data":[],"degraded":true}`.
- Témoin négatif: la **même** sonde, sur le code de la branche, rend `200` avec la **vraie** liste (`sonde-p5-branche.txt` : `{"data":[{"id":"…","email":"p5-users@p5.test",…,"two_factor_enabled":false,…}]}`). La sonde sait donc distinguer une liste peuplée d'une liste vide : c'est bien la garde qui ne regarde pas au bon endroit.
- Impact        : (a) la garde est décorative — supprimer le correctif de `UsersController` ne ferait rougir aucun test ; (b) le constat **F35-002 est inexact** là où il écrit « `GET /api/v1/users` échoue » et « est donc cassé » : l'écran de gestion des utilisateurs ne renvoyait pas une erreur, il renvoyait **une liste vide, silencieusement** — c'est le patron de **A-002** (200 avec un corps figé), et c'est plus dangereux qu'un 500 parce que personne ne le signale. Le drapeau `degraded: true` n'est lu par **aucun** écran (`git grep degraded -- frontend/src` : zéro occurrence).
- Reproduction  : `git restore --source=main --worktree -- backend/app/Http/Controllers/Api/UsersController.php` puis rejouer la garde.
- Correctif     : asserter la **charge utile**, pas l'absence de 500 : `expect($reponse->json('data'))->toHaveCount(1)` et `expect($reponse->json('degraded'))->toBeNull()`. Corriger la phrase du constat F35-002. Coût : 15 min.
- Statut        : ouvert

### [P5-35-002] `EnsureTwoFactorPassed` ne s'applique pas aux clients par jeton d'API : la 2FA reste contournable hors du SPA
- Sévérité      : S2
- Domaine       : sécurité / backend
- Référence     : branche `fix/a35-authentification` `da994be` → `46848d4`
- Emplacement   : `backend/app/Http/Middleware/EnsureTwoFactorPassed.php:46-52,68-73` · `backend/bootstrap/app.php:43`
- Constat       : le middleware est posé dans le groupe `api` **global**, donc **avant** le middleware de route `auth:sanctum`, et il lit `$request->user()` — le garde par défaut (`web`, piloté par la session). Une requête porteuse d'un `Authorization: Bearer`, **sans `Origin` de domaine stateful**, n'a ni session ni utilisateur à ce stade : le middleware la laisse passer.
- Preuve        : **joué** — `04_PREUVES/p5-agent35/sonde-p5-jeton.txt` :
  ```
  [P5-E] client machine (Bearer, sans Origin stateful, sans session), compte 2FA ACTIVE,
          JAMAIS passe par /auth/2fa/verify :
          GET /api/v1/contacts -> statut=200  erreur=NULL
  ```
- Témoin négatif: **double**. (a) La même sonde vérifie que le jeton est bien accepté : `GET /api/v1/auth/me` → **200**, donc `auth:sanctum` reconnaît le porteur — le 200 sur `/contacts` n'est pas un accident d'authentification. (b) La **même** requête avec un `Origin` de domaine stateful rend, elle, **403 `two_factor_required`** (`sonde-p5-branche.txt`, bloc `[P5-C]`) : le middleware fonctionne, mais seulement sur le chemin SPA.
- Impact        : **latent, pas exploitable aujourd'hui** — `git grep createToken -- backend/app backend/routes` ne trouve **aucune** occurrence : aucune route ne fabrique de jeton personnel, et la production en compte 0 (A-012). Le défaut est ailleurs : **F35-003 est déclaré « CORRIGÉ » alors qu'il ne l'est que pour la session**, et sa garde ne mesure que ce chemin-là — alors que F35-001, dans le même lot, démontre que ce produit tient à ses clients non-SPA. Le jour où une console de jetons d'API existera (F35-010 la réclame), la 2FA sera muette dessus.
- Reproduction  : `04_PREUVES/p5-agent35/sondes/SondeP5JetonTest.php`.
- Correctif     : soit assumer et **l'écrire dans le constat** (« la 2FA protège la session, pas le jeton porteur »), soit exiger un second facteur à l'émission du jeton. À défaut, ajouter la garde manquante : la même que F35-003, **sans** `Origin` stateful. Coût : 1 h.
- Statut        : ouvert

### [P5-35-003] L'énumération par le temps n'est pas supprimée : elle est inversée et doublée, et la garde ne peut pas la voir
- Sévérité      : S2
- Domaine       : sécurité / tests
- Référence     : branche `fix/a35-authentification` `da994be`
- Emplacement   : `backend/app/Services/Auth/AuthService.php:322,334-339` · garde `GardesAuthentificationAgent35Test.php:330-395`
- Constat       : le hachage factice est mémorisé dans une propriété **statique** (`private static array $hachagesFactices`). Une propriété statique PHP **ne survit jamais d'une requête à l'autre** (aucun serveur persistant ici : la production sert l'API par `php -S`, constat A-010). À chaque requête réelle, une tentative sur un compte **inconnu** paie donc **deux** bcrypt (`Hash::make` du factice **puis** `Hash::check`), là où un compte **connu** n'en paie **qu'un**. La garde, elle, joue 5 requêtes dans **un seul** processus : seule la première paie la fabrication, et c'est la **médiane des quatre suivantes** qui est retenue.
- Preuve        : **joué** — `04_PREUVES/p5-agent35/sonde-p5-branche.txt`, bloc `[P5-D]`, coût bcrypt 10 (celui de la garde) :
  ```
  [P5-D] medianes : compte connu 76.5 ms
                  | inconnu (statique CHAUDE, condition de la garde)  75.3 ms (rapport 1.02)
                  | inconnu (statique VIDEE, condition de php -S)    156.3 ms (rapport 2.04)
  ```
- Témoin négatif: la colonne « statique CHAUDE » **est** le témoin : la même sonde, le même banc, la même seconde, rend 1,02 — donc la sonde sait mesurer un écart nul. C'est bien la **remise à zéro de la statique**, et elle seule, qui fait apparaître le facteur 2. Et la garde d'origine sait rougir : sur `main` elle rend `6.84733006098468 is less than 3.0` — elle n'est pas aveugle, elle est **mal calibrée**.
- Impact        : l'oracle de temps subsiste, avec le signe inverse (une adresse **inconnue** est désormais **deux fois plus lente**). Un attaquant énumère toujours, il change juste de sens de lecture. Et **la garde ne le verra jamais** : son seuil est `< 3,0` et sa condition de mesure est celle d'un processus persistant que ce produit n'a pas.
- Reproduction  : `04_PREUVES/p5-agent35/sondes/SondeP5Agent35Test.php`, cas `P5-D`.
- Correctif     : figer le hachage factice en **constante de classe** au coût de production (le calculer une fois, hors ligne, et le poser en `const`), ou l'obtenir de `Cache`. Puis resserrer la garde à `< 1,5` **et** vider la statique entre les tentatives, pour qu'elle mesure la condition de production. Coût : 45 min.
- Statut        : ouvert

### [P5-35-004] Le correctif F35-014 rend inatteignables les branches d'erreur du script qui rend l'accès au CRM
- Sévérité      : S1
- Domaine       : sécurité / infrastructure
- Référence     : branche `fix/a35-authentification` `da994be`
- Emplacement   : `infra/scripts/definir-mot-de-passe-crm.sh:45` (`set -euo pipefail`) et `:131` (`SORTIE="$(… | docker exec -i … )"`)
- Constat       : le commit ajoute `-e` à `set -uo pipefail`, **et** le code PHP appelle désormais `exit(1)` sur `A35_INTROUVABLE` et sur `A35_ECHEC_MDP_VIDE`. Sous `set -e`, une affectation dont la substitution de commande échoue **termine le script sur-le-champ** : le `case "$VERDICT"` qui suit, et ses messages, ne sont jamais atteints.
- Preuve        : **joué** — `04_PREUVES/p5-agent35/sonde-shell-set-e.txt` :
  ```
  == [2] MESURE : nouveau reglage (set -euo pipefail), meme sortie ==
    code de retour du sous-shell : 1  (aucune ligne au-dessus = le script est mort a l affectation)
  == [3] MEME MESURE SOUS bash (le script se lance par 'bash definir-...') ==
    code de retour bash : 1
  ```
- Témoin négatif: **double**, dans la même sonde. `[1]` le **même** scénario sous l'**ancien** réglage (`set -uo pipefail`) atteint bien la branche : `branche INTROUVABLE atteinte -> message rendu a l operateur`. `[4]` sous le **nouveau** réglage, une sortie à 0 laisse l'affectation survivre : `affectation survecue, SORTIE=A35_OK`. La sonde distingue donc le réglage du code de retour : c'est bien la combinaison `set -e` + sortie non nulle qui tue le script.
- Impact        : c'est **le script par lequel Will doit rendre l'accès au CRM** (A-012, §6 du rapport de l'agent 35). S'il vise un compte inexistant — une faute de frappe sur l'adresse suffit — ou si le tube ne transmet rien, l'opérateur ne voit **aucun message** : ni « aucun compte », ni « aucun mot de passe reçu », ni le bloc `--- sortie brute ---` prévu pour le diagnostic. Juste un code 1 muet. Le constat F35-014 disait : *« un faux "c'est fait" envoie l'opérateur chercher la panne du mauvais côté »*. Le correctif remplace le faux succès par **le silence total**, ce qui n'est pas mieux, et personne ne l'a vu parce que **ce script n'a aucune garde** (P5-35-005).
- Reproduction  : `sh _AUDIT/2026-08-18_AUDIT-360/04_PREUVES/p5-agent35/sonde-shell-set-e.sh`.
- Correctif     : `SORTIE="$(… )" || true` (le verdict est déjà lu sur la dernière ligne, le code de retour est redondant), **ou** retirer `-e` et garder `-uo pipefail`. Coût : **2 minutes**. Ajouter une garde qui exerce les quatre verdicts (P5-35-005).
- Statut        : ouvert

### [P5-35-005] Deux constats sur quatorze sont déclarés « gardés par un test vu rougir » alors qu'aucune garde n'existe
- Sévérité      : S2
- Domaine       : tests
- Référence     : branche `fix/a35-authentification` `da994be`
- Emplacement   : `_AUDIT/2026-08-18_AUDIT-360/11_GRILLES/agent-35_authentification.md:32,379,466` · message de commit `da994be`
- Constat       : le rapport annonce « **25 tests neufs**, chacun **vu rougir avant** son correctif » et chaque constat porte « Garde vue rougir puis verte ». **F35-007** (le mot de passe dans `argv`) et **F35-014** (le verdict lu sur une sous-chaîne) portent tous deux cette mention, et **aucun test du dépôt ne lit `infra/scripts/`**.
- Preuve        : le fichier de gardes couvre 11 constats — F35-001, 002, 003, 004, 005, 006, 009, 010, 011, 012, 013 (recensement par en-têtes de section : `04_PREUVES/p5-agent35/temoin-negatif-backend.txt`, 11 blocs). F35-008 est couvert par `OwnerUserSeederTest` réécrit. Restent F35-007 et F35-014, sans aucun fichier.
- Témoin négatif: la **même** méthode de recensement trouve bien les 11 autres, et retrouve F35-008 dans un **autre** fichier que celui des gardes : elle sait donc voir une garde quand elle est ailleurs. C'est bien qu'il n'y en a pas pour ces deux-là.
- Impact        : la ligne de défense de ce lot est déclarée plus large qu'elle ne l'est. Conséquence **immédiate et mesurée** : le correctif de F35-014 introduit P5-35-004 (S1) et **rien ne l'a rattrapé** — c'est exactement ce qu'une garde aurait attrapé. La règle 2 de la doctrine (« une garde ne vaut que si on l'a vue rougir ») suppose d'abord qu'elle existe.
- Reproduction  : `git grep -l "definir-mot-de-passe" -- backend/tests frontend/tests` → aucun résultat.
- Correctif     : une garde de shell (`bats`, ou un simple `.sh` appelé par la CI) qui exerce les quatre verdicts du script avec un `docker` bouchonné ; et corriger les trois mentions du rapport. Coût : 2 h.
- Statut        : ouvert

### [P5-35-006] Le commit `46848d4` casse un test du dépôt qu'il n'a pas mis à jour : la branche n'est pas verte
- Sévérité      : S1
- Domaine       : tests / CI
- Référence     : branche `fix/a35-authentification` **`46848d4`**
- Emplacement   : `backend/tests/Feature/Controllers/NotificationsControllerTest.php:58-61` · `backend/routes/api.php:238`
- Constat       : `46848d4` pose `->middleware('permission:audit.view')` sur `GET /audit-logs`. Le test `GET /audit-logs authentifié → OK` crée un compte **sans aucun rôle** et attend 200 ; il reçoit désormais 403. Le commit a mis à jour `RgpdRequestsControllerTest` pour la même raison, mais **pas celui-ci**.
- Preuve        : **joué** — `04_PREUVES/p5-agent35/six-tests-sans-role.txt` :
  ```
   FAILED  Tests\Feature\Controllers\NotificationsControllerTest > GET /audi…
    Expected response status code [200] but received 403.
    at tests/Feature/Controllers/NotificationsControllerTest.php:60
    Tests:  1 failed, 73 passed (163 assertions)
  ```
- Témoin négatif: les **73 autres** cas des six fichiers passent dans le même run, et `PermissionsRoutesRgpdTest` est vert au complet : le banc n'est donc pas cassé. Et `RgpdRequestsControllerTest`, que le commit **a** mis à jour, passe — la méthode distingue bien un fichier rattrapé d'un fichier oublié.
- Impact        : le message de `46848d4` annonce « **119 tests verts sur les 16 suites touchées** ». La mesure est vraie **pour les suites touchées** — et c'est précisément le point : la suite qui casse n'en fait pas partie. Une branche rouge ne se fusionne pas, et le piège n° 5 du dossier rappelle que **la CI évalue le commit de fusion**, pas la branche.
- Reproduction  : `docker exec <conteneur> php vendor/bin/pest tests/Feature/Controllers/NotificationsControllerTest.php`
- Correctif     : donner le rôle `admin` au compte de `makeNotifUser()`, ou déplacer l'assertion vers `assertForbidden()` avec un témoin `admin` — le même geste que le commit a déjà fait ailleurs. Coût : 15 min. **Et vérifier la suite ENTIÈRE, pas les suites touchées** : c'est la leçon de ce constat.
- Statut        : ouvert

### [P5-35-007] B16-004 n'est corrigé qu'à moitié : le journal d'audit reste inter-espaces, et le témoin du nouveau test le certifie
- Sévérité      : S1
- Domaine       : sécurité / conformité
- Référence     : branche `fix/a35-authentification` **`46848d4`**
- Emplacement   : `backend/app/Http/Controllers/Api/AuditLogsController.php:29` · `backend/tests/Feature/Rgpd/PermissionsRoutesRgpdTest.php` (dernier cas)
- Constat       : B16-004 énonce deux choses — « `GET /audit-logs` rend le journal **de tous les espaces** » **et** « à **tout compte authentifié** ». Le correctif exige `permission:audit.view`, ce qui traite la seconde moitié. La requête est restée `AuditLog::query()->orderByDesc('id')->paginate(50)`, **sans aucun filtre d'espace**.
- Preuve        : **joué** — `04_PREUVES/p5-agent35/sonde-p5-rgpd-restant.txt` :
  ```
  [P5-F] admin de l espace A lit GET /audit-logs -> statut=200
         voit la ligne de SON espace ?      OUI   (temoin positif)
         voit la ligne de l espace VOISIN ? OUI   <<< la fuite inter-espaces demeure
  ```
- Témoin négatif: la sonde insère **deux** lignes, une par espace, et vérifie **d'abord** que l'admin voit la sienne (`OUI`) : sans ce témoin, un « il ne voit pas celle du voisin » n'aurait pu vouloir dire que « la route ne rend rien ». La mesure sait donc distinguer une lecture qui marche d'une lecture qui filtre.
- ⚠️ Réserve    : mesure faite **en atelier local**, où `CRM_DB_APP_ROLE_ENABLED=false` (constat **B11-010**). En production ce drapeau vaut `true` et la RLS **pourrait** filtrer. **Je ne conclus donc pas sur la production.** Ce que je conclus, et qui ne dépend pas de la RLS : le **code** ne filtre pas, et **la garde de ce commit tourne dans la même configuration que moi** — elle ne peut donc pas voir la fuite non plus, quelle que soit la production.
- Impact        : le dernier cas du nouveau fichier de garde, `B16-004 — TEMOIN : un ADMIN peut lire le journal d audit`, assère `->not->toBe(403)`. Il **certifie** donc exactement ce qui reste ouvert : un admin lit le journal, y compris celui des autres espaces. Un constat S0 est déclaré traité alors que sa moitié la plus lourde — pour un journal qui est un **élément de preuve** au sens RGPD — demeure.
- Reproduction  : `04_PREUVES/p5-agent35/sondes/SondeP5RgpdTest.php`, cas `P5-F`.
- Correctif     : `->where('workspace_id', app('workspace.id'))` dans `AuditLogsController::index()`, et transformer le témoin en garde : *l'admin de A ne voit pas la ligne de B*. Coût : 1 h. Rejouer ensuite **avec `CRM_DB_APP_ROLE_ENABLED=true`** pour mesurer ce que fait vraiment la production.
- Statut        : ouvert

### [P5-35-008] Le même bloc de routes laisse `/audit-logs/verify-chain` et `/ai-act/register` sans aucune permission
- Sévérité      : S2
- Domaine       : sécurité
- Référence     : branche `fix/a35-authentification` **`46848d4`**
- Emplacement   : `backend/routes/api.php:234,235,239`
- Constat       : `46848d4` protège trois lignes du bloc « RGPD + AI Act + audit » et en laisse trois autres inchangées, **contiguës**, sans permission : `GET /ai-act/register`, `POST /ai-act/register`, `GET /audit-logs/verify-chain`.
- Preuve        : **joué** — `04_PREUVES/p5-agent35/sonde-p5-rgpd-restant.txt` :
  ```
  [P5-G] compte VIEWER (lecture seule), apres 46848d4 :
         GET  /audit-logs              -> 403  (temoin : doit etre 403)
         GET  /audit-logs/verify-chain -> 200
         GET  /ai-act/register         -> 200
         POST /ai-act/register         -> 501
  ```
- Témoin négatif: la **première** ligne du même bloc est le témoin : le **même** compte `viewer`, dans la **même** requête HTTP-test, reçoit bien **403** sur `/audit-logs`. La garde de permission est donc branchée et fonctionne — c'est bien que ces trois routes-là ne la portent pas.
- Impact        : `/audit-logs/verify-chain` répond à un compte en lecture seule si la chaîne d'intégrité du journal est valide — une information sur l'état d'un dispositif de preuve. `GET /ai-act/register` est un **registre réglementaire** (déjà connu comme 200-à-corps-figé, A-002) lisible par tout compte. `POST /ai-act/register` rend 501 : **aucun risque d'écriture aujourd'hui**, mais la route sera ouverte à tous le jour où elle sera implémentée.
- Reproduction  : `04_PREUVES/p5-agent35/sondes/SondeP5RgpdTest.php`, cas `P5-G`.
- Correctif     : `->middleware('permission:audit.view')` sur `verify-chain` ; décider la permission du registre AI Act (`aiact.view` / `aiact.manage`) et la poser. Coût : 30 min.
- Statut        : ouvert

### [P5-35-009] Le motif « compte sans rôle, geste destructeur, test vert » subsiste sur cinq des six fichiers signalés
- Sévérité      : S2
- Domaine       : tests / sécurité
- Référence     : branche `fix/a35-authentification` **`46848d4`**
- Emplacement   : `backend/tests/Feature/CompaniesControllerTest.php:71` · `CampaignsTest.php` · `Crm/CrmOutboundTest.php:354` · `Controllers/WorkspaceControllerTest.php:44,63` · `Controllers/NotificationsControllerTest.php`
- Constat       : sur les six fichiers qui prennent une identité **sans jamais poser de rôle** et attendent un succès, `46848d4` n'en a corrigé qu'un (`Rgpd/RgpdRequestsControllerTest`, seul à contenir `assignRole`). Les cinq autres n'en contiennent **aucun** et **restent verts** après le correctif — donc les routes qu'ils exercent n'ont, elles, aucune garde de permission.
- Preuve        : **joué** — `04_PREUVES/p5-agent35/six-tests-sans-role.txt` : 73 verts / 1 rouge (le rouge étant P5-35-006). Recensement : `grep -c "assignRole\|setPermissionsTeamId"` rend **2** pour `RgpdRequestsControllerTest` et **0** pour les cinq autres. Le cas le plus net : `CompaniesControllerTest:71` — `$this->deleteJson("/api/v1/companies/{$c->id}")->assertNoContent();` avec un compte sans aucun rôle, **toujours vert**.
- Témoin négatif: le fichier que le commit **a** mis à jour, `RgpdRequestsControllerTest`, passe lui aussi — mais avec un compte `admin` explicite. Le recensement distingue donc bien un fichier rattrapé d'un fichier laissé en l'état : il en trouve un, et cinq qui ne le sont pas.
- Impact        : c'est **B12-003 mesuré à nouveau, un lot plus tard** (« un `viewer` a supprimé définitivement une entreprise »). Le raisonnement de `46848d4` — *« les permissions existaient, elles n'étaient jamais exigées »* — est juste et n'a été appliqué qu'aux routes RGPD. Chacun de ces cinq tests est un **témoin négatif tout prêt** : le jour où la garde sera posée, il rougira, et c'est ainsi qu'on saura qu'elle est branchée.
- Reproduction  : `docker exec <conteneur> php vendor/bin/pest tests/Feature/CompaniesControllerTest.php`
- Correctif     : hors périmètre de ce lot — **à ne pas empiler dans cette branche**. À traiter dans un lot « permissions » dédié, en se servant de ces cinq fichiers comme carte. Coût : estimé 1 j.
- Statut        : ouvert

### [P5-35-010] Le durcissement de HIBP fait dépendre le seul chemin de changement de mot de passe d'un service externe
- Sévérité      : S2
- Domaine       : sécurité / disponibilité
- Référence     : branche `fix/a35-authentification` `da994be`
- Emplacement   : `backend/app/Rules/NotPwnedPassword.php:41-48` · `backend/app/Services/Auth/HibpChecker.php:44-46` · `backend/routes/api.php:65`
- Constat       : `NotPwnedPassword` refuse désormais le mot de passe quand `HibpChecker` rend `null` (service injoignable). Cette règle n'est branchée qu'à **un seul** endroit du produit — `PasswordResetController::reset()` — qui est aussi le **seul** point de l'API où un mot de passe peut être choisi (`grep` exhaustif : `Password::min` et `password_hash =` n'apparaissent nulle part ailleurs dans `backend/app`).
- Preuve        : `git grep -n "NotPwnedPassword" -- backend/app` → la règle et `PasswordResetController.php:81`, rien d'autre. `HibpChecker.php:44-46` : `'timeout' => 5, 'connect_timeout' => 3`. La garde F35-004 mesure exactement ce comportement et le confirme : réseau coupé ⇒ `$validation->fails() === true`, message contenant « indisponible ».
- Témoin négatif: la même garde, avec un `MockHandler` qui répond, laisse passer un mot de passe sain (`F35-004 — TEMOIN : HIBP joignable et mot de passe sain`) : le refus vient bien de l'indisponibilité, pas d'une règle devenue trop stricte.
- Impact        : si `api.pwnedpasswords.com` est injoignable depuis le serveur — DNS filtré, pare-feu sortant, panne du service — **plus aucun mot de passe ne peut être changé**, sur un produit dont c'est déjà le point douloureux (A-012). Le fail-open était un vrai défaut ; le fail-closed **sans porte de sortie**, sur le chemin unique de reprise d'accès, en est un autre. Par ailleurs la **moitié (b)** du constat F35-004 — *« brancher la règle sur tous les points d'entrée d'un mot de passe »* — **n'est pas faite**, et le constat est pourtant marqué CORRIGÉ.
- Reproduction  : couper la résolution de `api.pwnedpasswords.com` dans le conteneur, puis `POST /api/v1/auth/password/reset` avec un jeton valide.
- Correctif     : garder le refus **par défaut**, et prévoir la porte : un drapeau d'exploitation (`HIBP_FAIL_MODE=closed|open-audited`) qui, en mode dégradé, accepte **et écrit une ligne au journal d'audit**. Ou n'appliquer le fail-closed qu'après N échecs, le cache 24 h couvrant les coupures brèves. Et corriger le statut du constat, qui n'est corrigé qu'à moitié. Coût : 1 h.
- Statut        : ouvert

### [P5-35-011] Après l'enrôlement, l'utilisateur est renvoyé saisir le code qu'il vient de saisir
- Sévérité      : S3
- Domaine       : UX / navigation
- Référence     : branche `fix/a35-authentification` **`26fa980`**
- Emplacement   : `backend/app/Http/Controllers/Api/Auth/TwoFactorController.php:41-46` (`confirm()`) · `frontend/src/features/auth/TwoFactorPage.tsx` (bouton « J'ai noté mes codes, continuer »)
- Constat       : `POST /auth/2fa/confirm` pose `totp_enabled_at` et `first_login_completed_at`, mais **ne pose pas `2fa_passed_at` en session**. `EnsureTwoFactorPassed` refuse donc la première route métier qui suit l'enrôlement.
- Preuve        : **joué** — `04_PREUVES/p5-agent35/sonde-p5-chaine-complete.txt`, étapes 4 à 7 :
  ```
  4. POST /auth/2fa/confirm        -> 200  codes de secours : 10
  5. GET  /contacts                -> 403  erreur='two_factor_required'
  6. POST /auth/2fa/verify         -> 200
  7. GET  /contacts                -> 200  erreur=NULL
  ```
- Témoin négatif: l'étape 7 est le témoin : la **même** requête, après le seul appel à `/auth/2fa/verify`, rend 200. Le 403 de l'étape 5 vient donc bien de l'absence de `2fa_passed_at`, et de rien d'autre.
- Impact        : **ce n'est pas un blocage** — l'intercepteur de `api.ts` renvoie vers `/2fa`, et la page, interrogeant `/auth/me`, y trouve `totp_enabled_at` non nul et demande le code : le parcours aboutit. Mais l'utilisateur qui vient de taper son code à six chiffres et de noter ses dix codes de secours se voit redemander le même code, sans explication. C'est une confusion de navigation ; la doctrine la place au minimum en S2, je la laisse en S3 **parce qu'elle ne fait perdre personne** : l'écran demande exactement ce qu'il faut faire.
- Reproduction  : `04_PREUVES/p5-agent35/sondes/SondeP5ChaineTest.php`.
- Correctif     : une ligne dans `confirm()` — `$request->session()->put('2fa_passed_at', now()->toIso8601String());`. Celui qui vient de prouver son facteur l'a prouvé. Coût : 10 min.
- Statut        : ouvert

### [P5-35-012] Sur un refus de permission, le fil d'activité annonce des données à venir
- Sévérité      : S3
- Domaine       : interface
- Référence     : branche `fix/a35-authentification` **`46848d4`**
- Emplacement   : `frontend/src/features/dashboard/components/ActivityFeed.tsx:93-99`
- Constat       : depuis `46848d4`, `GET /audit-logs` rend 403 à tout compte sans `audit.view` — c'est-à-dire à `viewer` et `operator`. Le composant traite `isError` **exactement comme** une liste vide et affiche « Activité bientôt disponible — les actions de ton équipe apparaîtront ici ».
- Preuve        : **joué** — `sonde-p5-chaine-complete.txt`, étape 8 : `GET /audit-logs (ActivityFeed) -> 403` pour un compte sans rôle. Code : `isError || items.length === 0 ? <EmptyState title="Activité bientôt disponible" …>`.
- Témoin négatif: le même composant sait afficher les lignes quand elles arrivent — c'est ce que prouve la garde `ActivityFeed` de `bdd25eb`, vue rougir puis verte. L'écran n'est donc pas cassé : il **interprète** un refus comme un vide.
- Impact        : un message qui promet des données là où l'accès est refusé. Sur l'écran d'accueil, pour deux rôles sur quatre. C'est le patron **A-002** (200 à corps figé) transposé à l'interface, et c'est le défaut mineur d'un correctif par ailleurs excellent.
- Correctif     : distinguer `isError` de la liste vide, et rendre « Vous n'avez pas accès au journal d'activité ». Coût : 20 min.
- Statut        : ouvert

---

## 4. CE QUE JE N'AI **PAS** PU VÉRIFIER — et pourquoi

Cette liste est un livrable. Trois agents de cet audit ont refusé de conclure et ce refus a été
validé comme du travail supérieur ; je fais de même là où je ne sais pas.

1. 🔴 **La branche a bougé pendant ma mesure, et elle peut avoir rebougé depuis.** Mon verdict porte
   sur **`46848d4`**, relu par `git log` à 18:25. Les mesures du §2.1 (les 25 gardes) et du §2.3
   (`ActivityFeed`) ont été faites sur **`bdd25eb`** — mais les fichiers concernés n'ont pas été
   touchés par `26fa980` ni `46848d4` (`git diff bdd25eb..46848d4 --name-only` ne rend que
   `TwoFactorPage.tsx`, `routes/api.php` et trois fichiers de test). **Re-mesurez `git log` avant de
   fusionner.**
2. 🔴 **La production n'a été ni touchée ni interrogée.** Aucune requête, aucun `ssh`, aucun secret.
   Tout ce que je dis de la production est **déduit du code**, jamais mesuré en ligne — y compris
   P5-35-004 (le script) et P5-35-010 (HIBP injoignable).
3. 🔴 **Le cloisonnement RLS.** Mon atelier a `CRM_DB_APP_ROLE_ENABLED=false`, la production `true`
   (**B11-010**). **P5-35-007 (fuite inter-espaces du journal d'audit) n'est donc PAS conclusif pour
   la production.** Ce qui l'est : le code ne filtre pas, et la garde du commit tourne dans la même
   configuration que moi.
4. **Un vrai navigateur n'a jamais été ouvert.** Le parcours « compte neuf → connexion → enrôlement →
   accueil » a été mesuré **au niveau HTTP** (sonde `P5-H`), et l'écran d'enrôlement par
   `@testing-library` avec une API bouchonnée. Le §4 de la doctrine — *« le geste réel avant
   l'instrumentation »* — **n'est donc pas satisfait pour ce lot**. Ce qui reste à faire : ouvrir
   `https://app.localhost`, se connecter avec un compte neuf, et enrôler pour de vrai. Je ne l'ai pas
   fait : l'atelier partagé sert l'API par `php -S` mono-processus (A-009/A-010) et une quinzaine
   d'agents y travaillaient ; une mesure d'interface y aurait mesuré la file d'attente.
5. **La suite backend complète.** Elle tournait encore à la clôture de ma mission — résultat partiel
   consigné dans `04_PREUVES/p5-agent35/suite-complete-46848d4.txt`. Ce que je tiens pour acquis est
   plus étroit et suffit au verdict : **1 échec mesuré et reproductible** (P5-35-006) sur les
   6 fichiers ciblés, et **25 + 6 + 4 gardes vertes** sur les fichiers du lot. **Je n'affirme pas
   qu'il n'y a qu'un seul rouge** — j'affirme qu'il y en a au moins un.
6. **`axion_crm_test` est codée en dur dans `tests/bootstrap.php`** (`const TEST_DATABASE_NAME`), et
   ce fichier **écrase `$_SERVER`, `$_ENV` et `putenv()`** — le `force="true"` de `phpunit.xml` n'y
   peut rien. C'est le mécanisme exact de **B11-005**, et il rend l'isolement **impossible sans
   modifier un fichier suivi**. Mon **premier** passage de témoin négatif (§2.1) a donc tourné sur la
   base **partagée**. Je l'ai **rejoué en entier** après avoir épinglé `axion_crm_test_p5a35` dans ma
   copie de travail : **25/25 vertes, résultat identique**. Les deux sorties sont archivées.
7. **`sanctum.expiration` en production.** `(int) env('SANCTUM_TOKEN_TTL_MINUTES', 43200)` : si la
   variable est posée **vide**, `(int) '' === 0`, et Sanctum retombe sur « aucune expiration » — le
   comportement d'avant, silencieusement. Je n'ai **pas** mesuré ce cas, et je ne sais pas si la
   variable est posée en production.
8. **La fenêtre TOTP et les codes de secours en conditions réelles** (horloge décalée, code rejoué à
   la seconde près) : non joués. La sonde `P5-H` n'exerce que le chemin nominal.
9. **Le témoin négatif de `OwnerUserSeederTest` (donc de F35-008) n'a pas été joué.** J'ai lu le test
    réécrit et vérifié que ce qu'il exige est bien l'inverse de ce qu'il exigeait ; je ne l'ai pas vu
    **rougir** sur l'ancien `OwnerUserSeeder`. Le geste manque : `git restore --source=main
    --worktree -- backend/database/seeders/OwnerUserSeeder.php` puis rejouer le fichier. Coût : 5 min.
    **F35-008 est donc, à ce stade, le seul des douze constats « gardés » dont la garde n'a pas été
    contre-vérifiée par moi.**
10. **Aucune mesure de performance ou de concurrence.** Toutes mes mesures sont **à un seul
    utilisateur** (§5 bis.0 du dossier) : les durées relevées en `P5-D` (76 ms / 156 ms) sont des
    durées de banc de test, pas des durées de production, et elles ne servent qu'à comparer deux
    conditions **entre elles**, pas à qualifier la latence du produit.
11. **`26fa980` et `46848d4` sortent du périmètre de l'agent 35** (constats des agents 22, 15, 16,
   plus l'agent 24 pour `bdd25eb`). **Ce n'est pas à moi d'arbitrer** si un agent doit corriger le
   constat d'un autre ; je signale que la règle 7 (« celui qui réalise ne vérifie jamais sa propre
   pièce ») s'applique désormais à **quatre** périmètres dans une seule branche, et que le lot est
   devenu difficile à réviser d'un bloc.

---

## 5. CE QUI EST **SAIN** — nommément, et prouvé

Un rapport uniquement à charge ne vaut rien. Voici ce que j'ai cherché à casser et qui a tenu.

1. **Onze des quatorze correctifs sont réels et gardés.** Treize gardes vues rougir sur le code
   d'avant, avec un message d'échec précis pour chacune (§2.1). Ce n'est pas une déclaration : c'est
   `temoin-negatif-backend.txt`, 442 lignes de sortie brute.
2. 🟢 **La chaîne de première connexion est franchissable — mesurée de bout en bout.** C'est le
   risque n° 1 qui m'était désigné, et **il est levé à `26fa980`** (il était réel à `bdd25eb`) :
   ```
   1. POST /auth/login              -> 200  requires_2fa=false
   2. GET  /auth/me                 -> 200  porte bien `user.totp_enabled_at`
   3. POST /auth/2fa/setup          -> 200  secret fourni, qr_url fourni
   4. POST /auth/2fa/confirm        -> 200  10 codes de secours
   5. GET  /contacts                -> 403  two_factor_required   (l'interface renvoie vers /2fa)
   6. POST /auth/2fa/verify         -> 200
   7. GET  /contacts                -> 200
   ```
   **Un humain peut franchir cet enchaînement.** Le CRM n'avait jamais permis ce geste.
3. **`EnsureTwoFactorPassed` est réellement branché**, pas seulement écrit — contrairement à
   l'`ErrorBoundary` que ce dossier avait déjà attrapé. `bootstrap/app.php:43` l'ajoute au groupe
   `api`, et je l'ai vu **refuser** une requête (403 `two_factor_required`) puis **l'accepter** après
   `/auth/2fa/verify`. Sa liste blanche est cohérente avec celle d'`EnforceFirstLoginSetup`.
   *Réserve* : le chemin par jeton d'API lui échappe (P5-35-002).
4. **La migration est réversible, et son `down()` fait ce qu'il annonce.** Mesuré sur une base neuve
   dédiée (`migration-reversibilite.txt`) : `up()` → `totp_recovery_codes` **text** +
   `last_failed_login_at` **timestamptz** ; `migrate:rollback --step=1` → `totp_recovery_codes`
   **ARRAY/_text**, `last_failed_login_at` disparue ; `migrate` rejoué → état final identique au
   premier. Le `down()` **dit lui-même** qu'il ne reconstitue pas les données, ce qui est honnête
   et sans conséquence : la colonne n'a jamais rien reçu.
5. **Les quatre tests réécrits le sont correctement** (§2.2), avec leur histoire en tête de test.
   `LoginTest` est même **plus rigoureux** que la garde équivalente du fichier neuf : il vérifie que
   `errors.password` est nul, donc que le refus vient bien de l'authentification et non de la
   validation.
6. **La garde `ActivityFeed` est exemplaire.** Elle rejoue la charge utile **réelle** de
   `GET /audit-logs` (douze champs, ceux de la table), pas une forme inventée, et son second cas
   couvre le pire des cas — une ligne réduite à `id` et `created_at`. Vue rougir avec l'erreur exacte
   du défaut (`Cannot read properties of undefined (reading 'replace')`, `ActivityFeed.tsx:20`).
7. **Les trois gardes RGPD de `46848d4` rougissent, et leurs trois témoins tiennent.** Les statuts
   mesurés (422 / 200 / 200) sont **exactement** ceux annoncés par le message de commit : sur ce
   point, l'auteur n'a rien embelli. Le second cas crée une **vraie** `RgpdRequest` plutôt qu'un
   identifiant inventé, et commente pourquoi — c'est la marque de quelqu'un qui a joué sa garde
   plutôt que de l'imaginer.
8. **La qualité de la documentation dans le code est au-dessus de la moyenne du dépôt.** Chaque
   correctif porte, en commentaire, le défaut qu'il répare, la mesure qui l'a établi et sa date.
   `MagicLinkService`, `AuthService` et `HibpChecker` sont maintenant lisibles par quelqu'un qui
   arrive dans six mois. Ce n'est pas cosmétique : c'est ce qui empêche la prochaine réécriture de
   réintroduire le défaut.
9. **Le rapport de l'agent 35 déclare ses propres pièges de mesure**, y compris trois qui l'avaient
   trompé, et il a corrigé une de ses gardes qui mesurait le mauvais objet (`F35-010`, note en tête
   du cas). Un auteur qui écrit *« ma sonde avait tort »* rend le travail de contre-vérification
   possible ; c'est la raison pour laquelle ce rapport-ci a pu être court là où il n'y avait rien à
   redire.

---

## 6. CE QU'IL FAUT FAIRE AVANT DE FUSIONNER — par ordre de coût croissant

| # | Geste | Constat | Coût |
|---|---|---|---|
| 1 | `SORTIE="$( … )" || true` dans `definir-mot-de-passe-crm.sh` | P5-35-004 (S1) | **2 min** |
| 2 | `2fa_passed_at` posé par `confirm()` | P5-35-011 (S3) | 10 min |
| 3 | Rôle `admin` dans `NotificationsControllerTest` | P5-35-006 (S1) | 15 min |
| 4 | La garde `GET /users` assère la charge utile | P5-35-001 (S2) | 15 min |
| 5 | `ActivityFeed` distingue le refus du vide | P5-35-012 (S3) | 20 min |
| 6 | `permission:audit.view` sur `verify-chain` | P5-35-008 (S2) | 30 min |
| 7 | Filtre d'espace sur `AuditLogsController::index()` + la garde qui va avec | P5-35-007 (S1) | 1 h |
| 8 | Hachage factice en constante + garde recalibrée | P5-35-003 (S2) | 45 min |
| 9 | Porte de sortie sur le fail-closed HIBP | P5-35-010 (S2) | 1 h |
| 10 | Corriger les trois mentions « garde vue rougir » de F35-007 et F35-014 | P5-35-005 (S2) | 15 min |
| — | *Hors périmètre de cette branche* : le lot « permissions » des cinq fichiers sans rôle | P5-35-009 (S2) | ~1 j |

**Le §1 à 6 tient en une heure trente.** Après quoi cette branche mérite d'être fusionnée : elle
répare une chaîne de verrous qui empêchait, depuis le 2026-05-17, quiconque d'utiliser ce produit.

---

## 7. TRAÇABILITÉ DES PREUVES

Toutes dans `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/p5-agent35/` :

| Fichier | Ce qu'il contient |
|---|---|
| `temoin-negatif.sh` | le script de témoin négatif, rejouable tel quel |
| `temoin-negatif-backend.txt` | les 11 blocs, garde par garde, avec chaque message d'échec |
| `temoin-negatif-rgpd-46848d4.txt` | le témoin négatif des 6 gardes RGPD |
| `temoin-negatif-frontend-activityfeed.txt` | le rouge de la garde `ActivityFeed` |
| `temoin-negatif-frontend-2fa-26fa980.txt` | le rouge de la garde d'enrôlement 2FA |
| `sonde-p5-branche.txt` | `P5-A` (`/users`), `P5-B` (compte neuf), `P5-C` (jeton + Origin), `P5-D` (énumération) |
| `sonde-p5-users-code-main.txt` | `GET /users` sur le code `main` : `200 {"data":[],"degraded":true}` |
| `sonde-p5-jeton.txt` | `P5-E` — la 2FA contournée par un jeton d'API |
| `sonde-p5-rgpd-restant.txt` | `P5-F` (fuite inter-espaces), `P5-G` (routes voisines) |
| `sonde-p5-chaine-complete.txt` | `P5-H` — la chaîne de première connexion, 8 étapes |
| `sonde-shell-set-e.sh` / `.txt` | la mesure du `set -e`, avec ses deux témoins positifs |
| `migration-reversibilite.txt` | `up` → `rollback` → `up`, schéma relevé aux trois moments |
| `six-tests-sans-role.txt` | les six fichiers signalés, 73 verts / 1 rouge |
| `suite-complete-46848d4.txt` | la suite complète (partielle à la clôture, cf. §4.5) |
| `sondes/*.php` | les sources de mes quatre sondes, rejouables |
