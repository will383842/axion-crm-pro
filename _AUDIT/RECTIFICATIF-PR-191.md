# RECTIFICATIF — trois affirmations sur la production que ce lot ne pouvait pas mesurer

> Écrit le 2026-08-20, sur la branche `fix/a35-authentification` (PR #191).
> À lire avec `_AUDIT/2026-08-18_AUDIT-360/08_PASSE-2-ADVERSARIALE.md`, §11, §12 et §14.

---

## Ce dont il s'agit

La contre-vérification adversariale de l'audit 360° a établi un motif, et il faut le dire
sans le diluer :

> **Les correctifs de ce lot sont bons. Ses assertions sur la production ne sont pas
> fiables.**

L'agent qui a produit ces quatorze commits **n'a aucun accès au serveur de production** — il
l'a écrit lui-même : *« il me manque un identifiant et un mot de passe »*. Il a néanmoins écrit
**trois fois** ce qui s'y passe, dans des messages de commit d'un dépôt public. Les trois ont
été réfutées, ou ne sont pas établies, par des mesures faites sur le bon objet.

## Les trois

### 1. `46f1717` — « le secret de la chaîne d'audit est vide en production »

**Faux.** L'agent 40, le seul à avoir eu l'accès, a mesuré **deux fois sur l'application de
production en marche**, sans jamais afficher la valeur (`02_CONSTATS.md:669-673`) :

| Contrôle | Résultat |
|---|---|
| longueur de `AUDIT_HASH_CHAIN_SECRET` | **64 caractères** |
| `=== ''` | non |
| `=== 'dev-only-secret-change-me'` | non |

**`B16-001` reste donc « réfuté pour la production » au registre.** Le décompte S0 est
inchangé.

**Et le correctif reste entièrement valide** — c'est même le meilleur de la série. Il ne
porte pas sur la valeur du secret, mais sur **ce que la vérification répond quand le secret
manque** : elle répondait `valid: true`. Une chaîne hachée sans secret reste parfaitement
cohérente avec elle-même. *Un contrôle d'intégrité qui affirme « tout va bien » sans pouvoir
le savoir est pire qu'un contrôle absent : il endort celui qui le lit.* Le défaut réel est là,
et il est réel.

Ce qui subsiste sans contestation : `AuditHashChain.php:33` livrait par défaut
`'dev-only-secret-change-me'`, **une valeur en clair dans un dépôt public**.

### 2. `debc860` — « la production se croyait en simulacre »

**Mécanique réfutée par la mesure.** La thèse était que `config:cache` empêche `env()` de lire
le `.env`, si bien que `env('MOCK_MODE')` rendait son défaut `true`.

Mesuré dans le conteneur :

```
variables_order = EGPCS          <- le E y est
$_SERVER['MOCK_MODE'] -> oui
$_ENV['MOCK_MODE']    -> oui
```

`docker-compose` injecte les variables par `env_file:`, elles sont donc dans l'environnement
du **processus**, et le dépôt Dotenv de Laravel lit précisément `$_SERVER` et `$_ENV`.
**`env()` rend la vraie valeur, même sous `config:cache`.** Le piège du 2026-08-14 vise le
`.env` lu par dotenv, pas les variables posées par `env_file`.

Corroboration indépendante : la mesure de l'agent 40 ci-dessus. Si `env()` rendait ses défauts
sous configuration en cache, il aurait lu `dev-only-secret-change-me`. Il ne l'a pas lu.

**Et le défaut que cette explication habillait est réel, et c'est un S0** :
`config/mail.php:4` fait `env('MAIL_MAILER', 'log')`, et `MAIL_MAILER` n'est défini nulle part.
**Aucun courriel ne part**, ni lien magique ni réinitialisation. C'est `F40-002`.
*Le correctif s'appuie sur le bon défaut, avec la mauvaise explication.*

### 3. `22d1fd0` — « une purge efface 90 % de la base »

**Chiffre non établi pour la production.** Il a été mesuré sur le **jeu de référence**, où le
registre note déjà que la forme juridique est inconnue pour l'essentiel des lignes.

Et le registre porte une contre-indication : `B17-012` établit que ces deux purges ont **déjà
été jouées cinq fois en production, le 2026-07-04**. Or la production porte **4 295 349
entreprises** en août. *Si la commande effaçait neuf lignes sur dix, cinq exécutions
n'auraient pas laissé 4,29 millions de fiches.* Ce n'est pas une preuve — la base a pu être
recollectée — mais c'est une contre-indication sérieuse, et elle suffit à interdire de
reprendre le chiffre.

L'explication la plus probable : en production, `legal_form` est renseignée par l'INSEE pour
l'essentiel des lignes, ce qui n'est pas le cas du jeu de référence.

**`B17-012` reste S1.** Le correctif, lui, est bon **pour une raison indépendante du chiffre
contesté** : un plafond qui refuse au-delà de 30 % de la table protège exactement contre le
mode de panne redouté, quel que soit le taux réel de `legal_form` nulles. La forme de la
commande était dangereuse — pas de `--dry-run`, pas de plafond, pas de confirmation, pas de
transaction, pas de contexte d'espace, et lançable en un clic sur la production — et elle ne
l'est plus.

**Ce qu'il faudrait pour trancher**, et qui appartient à qui a l'accès :

```sql
SELECT count(*) FILTER (WHERE legal_form IS NULL), count(*) FROM companies;
```

Porté à `06_RESTE-WILL.md` comme **la question à poser, pas comme un fait**.

---

## Deux commentaires de code portaient la même affirmation

Rectifiés dans le code, là où ils seront relus :

| Fichier | Ce qu'il affirmait |
|---|---|
| `backend/app/Http/Controllers/Internal/ScraperResultController.php` | *« le secret est VIDE sur le serveur de production »* |
| `backend/tests/Feature/Internal/SignatureCanalInterneTest.php` | idem, en en-tête de garde |
| `backend/app/Services/Audit/AuditHashChain.php` | *« `AUDIT_HASH_CHAIN_SECRET` est la CHAÎNE VIDE en production »* |

Ce qui est mesuré, dans les trois cas, c'est **le dépôt** : `WORKER_INTERNAL_HMAC_SECRET` est
vide dans `.env`, `.env.example` et `backend/.env`. La valeur sur le serveur est inconnue de
ce lot. *Le défaut ne dépend d'ailleurs pas de cette valeur — un contrôle fail-open est faux
quelle que soit la configuration qu'il rencontre — mais une affirmation invérifiée n'a pas sa
place dans une garde.*

---

## Pourquoi ce fichier, et pas une réécriture de l'historique

Rectifier « le message de commit » demanderait un `rebase` puis un `push --force`. **Décision
prise le 2026-08-20 : on ne réécrit pas l'historique publié.** Deux raisons, et la première
est décisive.

**1. Le dossier d'audit ancre ses verdicts sur ces identifiants.** Le §10 de
`08_PASSE-2-ADVERSARIALE.md` écrit noir sur blanc que le verdict du contradicteur *« porte
jusqu'à `46848d4` »*, et quatorze sections citent des SHA. Réécrire les commits invalide
toutes ces références d'un coup. *Un audit dont les preuves pointent vers des objets qui
n'existent plus ne prouve plus rien.*

**2. Effacer une affirmation fausse n'est pas la rectifier.** Dans un audit, la correction
doit être **visible**, pas substituée. Un lecteur qui retrouve `46f1717` doit tomber sur la
rectification — pas sur un commit propre qui n'a jamais menti.

Le corps de la PR #191, lui, s'édite **sans rien réécrire** : il l'a été, et il renvoie ici.

---

## La règle qui en sort, et elle vaut pour toute la flotte

> **Ce qu'on n'a pas mesuré, on ne l'écrit pas — surtout dans un dépôt public.**
>
> La distinction est nette et elle est utilisable : *relire le code que ce lot produit avec
> confiance, et ne rien reprendre de ce qu'il affirme sur le serveur sans le remesurer.*

L'agent consigne lui-même que **sept fois** dans la session, un rouge venait de sa sonde et
non du produit, et qu'aucune de ces sept fois n'a produit de faux constat *« parce qu'à chaque
fois j'ai lu le motif du rouge avant de conclure »*. **C'est exactement la discipline qui
manquait à ses assertions de production** : il l'appliquait à ses sondes, pas à ses
affirmations.

Le contradicteur s'est appliqué la même règle à lui-même au §14, et le dit : *« il aurait été
facile — et spectaculaire — d'écrire "une commande efface 90 % de votre base". Ce serait le
même défaut que le sien, commis dans le document qui le relève. »*
