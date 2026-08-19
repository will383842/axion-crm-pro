# AGENT 35 — JOURNAL DE RÉPARATION (autopilote)

> Mandat reçu de Will en cours de session : « je veux que tu corriges et fixes au fur et à
> mesure », en appliquant à la lettre la doctrine de
> `Axion-IA/_AUDIT/PROMPT-AUDIT-QUALIOPI-E2E-50-AGENTS-2026-08-18.md`.
> Conséquences retenues : aucune question ; tout constat confirmé est réparé, testé, ouvert
> en PR ; **chaque correctif porte sa garde, et la garde a été vue ROUGIR avant le correctif** ;
> ordre imposé SÉCURITÉ/BLOQUANT → MAJEUR → … ; le périmètre n'est jamais réduit en silence.

## Décisions prises sans demander (avec leur justification)

**D1 — Où je travaille.** Worktree dédié `crmpro-wt-a35-auth`, branche `fix/a35-authentification`,
créée depuis `origin/main` = `e8924b8`, exactement ma référence d'audit.
*Pourquoi pas la copie principale* : une cinquantaine d'agents y mesurent en ce moment ; modifier
`backend/app/**` sous eux fausserait leurs mesures en cours.
⛔ Le worktree `crmpro-wt-etape1a` n'est ni lu, ni écrit, ni approché — consigne du dirigeant.

**D2 — Aligner le CODE sur le SCHÉMA, pas l'inverse (A07-001).** Le service 2FA écrit
`two_factor_secret`, `two_factor_enabled`, `two_factor_recovery_codes` ; la base porte
`totp_secret`, `totp_enabled_at`, `totp_recovery_codes`. Deux voies possibles : une migration qui
crée les colonnes manquantes, ou corriger le code.
*Je tranche pour le code*, pour trois raisons mesurées : (a) le schéma `totp_*` est cohérent et
**déjà utilisé** par `AuthService` (`totp_enabled_at` décide de `requires_2fa`) et par le script
`definir-mot-de-passe-crm.sh` ; (b) ajouter `two_factor_*` créerait **deux** jeux de colonnes pour
la même chose, donc une divergence future garantie (piège 15 du dossier) ; (c) une migration sur
une table `users` de production pour réparer une faute de frappe est un risque inutile.
`two_factor_enabled` n'a pas de colonne et n'en a pas besoin : il se **dérive** de
`totp_enabled_at !== null`.

**D3 — Ce que je ne fais pas, et qui reste à Will.** Aucune écriture en production, aucune
variable d'environnement de production touchée, aucun déploiement. Ces trois classes sont
explicitement humaines dans la doctrine reçue. Elles sont listées en fin de journal sous
« RESTE WILL », avec le geste exact.

## Chronologie

**D4 — Une seule vague, pas quatorze.** La doctrine impose l'ordre
SÉCURITÉ → MAJEUR → … J'ai posé **les 24 gardes d'abord**, vu **16 rouges**, puis appliqué
les correctifs **en une vague**, plutôt qu'un aller-retour par constat.
*Pourquoi* : sur ce poste, un aller-retour de banc coûte **~5 minutes** (montage 9p saturé,
`opcache` désactivé dans l'image) ; quatorze allers-retours, c'est plus d'une heure de
compilation PHP pour aucune information neuve. La règle « la garde a été vue rougir avant le
correctif » **est respectée intégralement** : chaque rouge est archivé, horodaté, et
**attribué nominativement** à son correctif dans `gardes-ROUGE-avant-correctif.txt`.

**D5 — Un test qui gardait un défaut a été réécrit, pas supprimé.**
`tests/Unit/Auth/HibpCheckerTest.php` portait deux tests nommés « fail-open », qui
**exigeaient** `0` en cas de panne réseau — c'est-à-dire qui garantissaient le défaut
F35-004. Ils sont réécrits pour exiger `null`, avec l'explication en tête de test. Un test
vert n'est une bonne nouvelle que si ce qu'il exige est ce qu'on veut.

**D6 — Ce que je NE corrige pas, et pourquoi.** L'ancien fichier
`storage/app/private/seeders/owner-initial-password.txt` (mot de passe du propriétaire en
clair) **n'est pas supprimé par le code**. Le seeder le SIGNALE désormais, en toutes lettres,
à chaque exécution. Détruire le seul exemplaire d'un secret sans que personne l'ait demandé
est précisément l'erreur que ce chantier a déjà payée une fois (« 650 € »). → RESTE WILL.

## Chronologie mesurée

| Heure (UTC) | Fait |
|---|---|
| 13:21 | Worktree `crmpro-wt-a35-auth` créé depuis `origin/main` = `e8924b8` |
| 13:2x | 24 gardes écrites dans `tests/Feature/Auth/GardesAuthentificationAgent35Test.php` |
| ~13:5x | 1er lancement : **24 échecs pour un motif de banc** (`bootstrap/cache` absent du worktree neuf) — écarté, ne prouve rien |
| ~14:0x | 2e lancement : **16 rouges**, 27 assertions, 287 s. 15 rouges pour le BON motif, 1 artefact de banc (CSRF périmé après régénération de session) |
| ~14:1x | Correctifs appliqués : 13 fichiers modifiés, 3 créés (middleware, migration, gardes) |
| ~14:2x | Lancement de vérification |

### Le rouge, garde par garde (extrait ; détail dans `gardes-ROUGE-avant-correctif.txt`)

| Garde | Rouge observé | Motif |
|---|---|---|
| F35-002 ×3 | `SQLSTATE[42703] column "two_factor_secret" does not exist` | BON |
| F35-003 | 403 attendu, **200** reçu (session réelle, cookie rejoué) | BON |
| F35-004 | la règle n'a pas échoué (`false is true`) | BON |
| F35-005 | jeton de 61 min : 401 attendu, **200** reçu | BON |
| F35-006 | **1** jeton d'API restant après réinitialisation | BON |
| F35-001 | **500** là où 401 est attendu | BON |
| F35-009 | rapport des médianes = **4,07×** (seuil 3,0) | BON |
| F35-010 | `expires_at` du jeton = `null` | BON |
| F35-011 | bon mot de passe de 5 caractères : **422** | BON |
| F35-012 | compteur à **11** sur un compte déjà verrouillé | BON |
| F35-012 | `column "last_failed_login_at" does not exist` | BON (la colonne fait partie du correctif) |
| F35-013 ×2 | **1** ligne pour une adresse inconnue ; lien orphelin **200** | BON |
| F35-013 | 419 CSRF | **artefact de banc** — corrigé dans la sonde, pas dans le produit |

**Gain de mesure** : F35-009 cesse d'être un constat de lecture. L'écart de temps entre une
adresse connue et une adresse inconnue vaut **4,07×**. C'était la case « non mesuré » de mon
§5 ; elle est comblée.

### Le vert, et les trois fois où c'est ma sonde qui avait tort

Le passage au vert n'a pas été direct, et les trois échecs intermédiaires méritent d'être
écrits, parce que chacun est un piège de mesure — pas un défaut du produit :

1. **`bootstrap/cache` absent** du worktree neuf (git ne suit pas les dossiers vides) :
   24 échecs pour un motif de banc. Écarté, aucune valeur de preuve.
2. **F35-010 mesurait le mauvais objet.** Ma garde vérifiait `expires_at` sur le jeton
   créé. Or Sanctum ne remplit cette colonne que si on lui passe une date à
   `createToken()` ; `sanctum.expiration` agit ailleurs — le garde compare `created_at`
   à `now()->subMinutes(...)` **au moment de l'authentification**. Une garde
   irréprochable sur un objet qui n'est pas celui qui casse : c'est le piège n°19 du
   dossier, retrouvé sur ma propre sonde. Réécrite en garde de **comportement** (un jeton
   vieilli est refusé), avec son témoin (sans durée de vie configurée, le même jeton
   vieilli passe encore).
3. **F35-009 mesurait le limiteur de débit, pas le hachage.** En simplifiant la sonde,
   j'avais retiré l'IP neuve par requête. La connexion étant plafonnée à 5/min/IP —
   **deux fois**, sur la route et dans le service — les requêtes 6 à 10 repartaient en
   429 sans jamais atteindre `Hash::check`. Le rapport mesuré (18,4×) ne disait rien du
   défaut. La garde porte désormais un **témoin d'instrumentation** : elle vérifie que
   les 10 requêtes ont bien rendu 422 **avant** de conclure sur les temps.
   Elle a aussi révélé un vrai piège du framework : changer
   `config('hashing.bcrypt.rounds')` ne change rien tant qu'on n'a pas oublié l'instance
   `hash` — `HashManager` fige le coût à la construction.
4. **Les 24 « warnings »** venaient d'un `.env` absent du worktree, lu par phpdotenv.
   Le `phpunit.xml` du produit portant `failOnWarning="true"`, c'eût été rouge en CI pour
   une raison qui n'existe pas dans le dépôt. Corrigé en fournissant le `.env` au banc.

**Ce que ça dit** : sur quatre échecs après correctif, **quatre** étaient des défauts de
mesure. C'est le rendement normal d'un banc neuf, et c'est pour ça qu'on ne conclut
jamais sur un rouge sans lire son motif.

### Tests existants réécrits, et pourquoi ce n'est pas de la complaisance

Trois tests du dépôt **exigeaient le défaut**. Les laisser verts aurait voulu dire garder
le défaut ; les supprimer aurait effacé la trace. Ils sont donc **réécrits, avec leur
histoire en tête de test** :

| Fichier | Ce qu'il exigeait | Ce qu'il exige désormais |
|---|---|---|
| `tests/Unit/Auth/HibpCheckerTest.php` | `getBreachCount() === 0` en cas de panne réseau — soit « ce mot de passe n'est dans aucune fuite » | `null` : on ne sait pas, et on le dit |
| `tests/Feature/Auth/LoginTest.php` | un mot de passe court refusé **par la validation**, à la connexion | refusé par l'**authentification** s'il est faux ; accepté s'il est bon |
| `tests/Feature/Seeders/OwnerUserSeederTest.php` | que le seeder **écrive** le mot de passe en clair sur disque | qu'il n'écrive **aucun** secret |

**Un QUATRIÈME test gardait un défaut**, découvert en jouant la suite complète :
`tests/Unit/Auth/MagicLinkServiceTest.php` — « issue for unknown email does not throw
(anti-enumeration) » — **exigeait** qu'une ligne `magic_links` soit écrite pour une
adresse sans compte, `user_id` à `NULL`. C'était précisément le mécanisme de F35-013.
Réécrit : la ligne n'est plus écrite, et un **témoin** vérifie qu'une adresse connue en
écrit bien une, rattachée au bon compte. L'anti-énumération est préservée — elle tient à
l'identité de la **réponse**, pas à l'écriture d'une ligne inutile, et aucun hachage
n'est calculé sur ce chemin, donc aucun écart de temps non plus.

C'est le quatrième. Aucun n'était visible depuis le seul fichier corrigé : ils ne se
révèlent qu'en jouant la suite entière. Un correctif qui ne passe pas la suite complète
n'est pas un correctif.

## Résultat

| | Avant | Après |
|---|---|---|
| Suite auth complète (`failOnWarning`+`failOnRisky` = CI) | 44 tests | **69 passés, 0 échec, 0 avertissement** |
| Constats ouverts | 14 | **0** — tous corrigés, gardés, commités |
| Fichiers | — | 21 (16 modifiés, 3 créés, dont 1 middleware et 1 migration) |
| Commit | — | `da994be` sur `fix/a35-authentification` |

## RESTE WILL

1. **Pousser la branche** — `git push -u origin fix/a35-authentification` puis
   `gh pr create --fill --base main`. Le push a été **refusé par la couche de permission
   de l'outillage** ; je n'ai pas cherché à contourner. Le travail est committé localement.
2. **Supprimer** `storage/app/private/seeders/owner-initial-password.txt` sur le serveur,
   après avoir changé le mot de passe du compte. Le seeder ne l'écrit plus et le signale.
3. **Déployer** (la branche porte une migration additive) puis re-vérifier en ligne :
   `curl -H 'Accept: text/html' .../api/v1/auth/me` doit rendre **401**, plus 500.

Aucune variable d'environnement de production touchée, aucune donnée de production
modifiée, aucun e-mail envoyé. La rotation des secrets reste refusée par Will et n'est pas
reproposée.
