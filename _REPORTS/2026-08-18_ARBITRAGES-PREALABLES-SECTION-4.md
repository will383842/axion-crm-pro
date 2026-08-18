# Étape 0 — les six décisions du §4, tranchées

> **Date** : 2026-08-18 · **Auteur** : Claude, en autopilote, sur demande de Will
> (« tranche le §4, puis exécute le §3 dans l'ordre »).
> **Source** : `Axion-IA/_PLANS/2026-08-18_PREALABLES-AVANT-CHANTIER-CRM-CIBLE.md`, §4.
> **Portée** : ces six décisions conditionnent les lignes 9, 10, 12 et 13 du §3.
> **Statut** : appliquées dans la PR d'étape 0. Chacune est révocable par Will ; le
> paragraphe « comment revenir en arrière » de chaque décision dit comment.

---

## Avertissement de méthode

Le §4 du plan présente ces points comme des arbitrages **ouverts**. Deux d'entre
eux ne l'étaient plus : le code les avait déjà tranchés, et **l'un des deux dans
le sens inverse de la recommandation du plan**. Le plan a été écrit le 18/08 ;
la décision contraire avait été fusionnée le 17/08.

C'est exactement le défaut que le plan lui-même reproche à la session précédente
(§5.9 : « une affirmation fausse a failli entrer dans le cahier des charges »).
Chaque décision ci-dessous a donc été prise **après lecture du code**, pas après
lecture du plan. Les écarts sont signalés en tête de section.

---

## Décision 1 — accès à la base du site : **NON ACCORDÉ, la ligne 12 reste bloquée**

**Question posée** : donner à l'agent un accès en lecture à la base du site (ou le
secret de signature du canal) pour jouer B.10 → B.12 et les quatre pannes.

**Décision** : je ne peux pas trancher celle-ci, et je ne la contourne pas.
Cet accès n'est pas une préférence technique : c'est une habilitation. Elle
appartient à Will, et à personne d'autre. Deux faits la rendent aujourd'hui
inatteignable de mon côté :

- `ssh` vers la production et `rm` sont **refusés par le classificateur** de
  l'autopilote. Ce n'est pas une limite que je dois chercher à franchir.
- Le secret de signature du canal (`SITE_SYNC_HMAC_SECRET`) est absent du `.env`
  local de cette machine — vérifié : il fait partie des 19 clés que `.env.example`
  déclare et que le `.env` de travail n'a pas.

**Conséquence assumée sur le §3** : la **ligne 12 n'est pas exécutable**. Elle
n'est ni faite, ni « faite partiellement » : elle est **bloquée**, et le rapport
de fin d'étape 0 doit le dire en ces termes. Ce qui *peut* être fait sans accès —
et qui l'est — c'est l'**outillage** de la mesure de parité, pour que la mesure
elle-même ne coûte plus qu'une commande le jour où l'accès existe.

**Ce que Will doit faire pour débloquer** : fournir soit un accès en lecture à la
base Postgres du site, soit la valeur de `SITE_SYNC_HMAC_SECRET` de production.
Le second suffit pour B.10 → B.12 et les quatre pannes ; le premier est
nécessaire en plus pour la mesure de parité de capture.

---

## Décision 2 — `neq` / `not_in` sur les colonnes NULL : **on GARDE la sémantique livrée le 17/08**

### 🔴 Le plan se trompe sur l'état du code

Le plan écrit : *« Arbitrage `neq` / `not_in` sur NULL : recommandation — `not_in`
**exclut** les NULL comme `neq` »*. Cette phrase suppose que `neq` exclut les NULL
et que `not_in` les inclut, donc que les deux divergent.

**C'est faux depuis la PR #169, fusionnée le 2026-08-17** — la veille de la
rédaction du plan. Lu dans `backend/app/Services/Audiences/AudienceBuilderService.php:439-443` :

```php
case 'neq':    $q->where(fn ($qq) => $qq->where($field, '!=', $value)->orWhereNull($field));
case 'not_in': $q->where(fn ($qq) => $qq->whereNotIn($field, $value)->orWhereNull($field));
```

Les deux opérateurs **incluent** les lignes au champ NULL, et ils sont donc
**déjà alignés**. Il n'y a plus de divergence à arbitrer entre eux ; la question
qui reste est celle du **sens** commun retenu.

### Le sens retenu, et pourquoi je le garde

La question de fond n'est pas « `neq` ou `not_in` ». C'est : *« tout sauf X »
doit-il ramener les fiches dont le champ est vide ?*

Le code répond **oui**, et le commentaire de `AudienceBuilderService.php:410-434`
en donne la raison, qui n'est pas de confort :

> `evalCondition()` — l'évaluateur **en mémoire** des mêmes critères — répond
> `null != 'x'` ⇒ VRAI. Le SQL répondait UNKNOWN, donc éliminait la ligne. **Le
> contenu d'une même audience dépendait du chemin qui l'avait calculée.**

C'est ce dernier point qui tranche. Une audience dont le contenu change selon
qu'elle a été calculée par `refresh()` en SQL ou par le chemin waterfall en
mémoire n'est pas une audience : c'est deux audiences qui portent le même nom.
**Ce défaut-là est plus grave que l'un ou l'autre des deux sens possibles**, et
il est corrigé.

Reste à choisir lequel des deux évaluateurs devait céder. Le plan aurait fait
céder la mémoire ; #169 a fait céder le SQL. Je garde #169, pour trois raisons :

1. **C'est livré, testé et en place depuis le 17/08.** Revenir dessus *rétrécit*
   silencieusement toutes les audiences bâties depuis — l'inverse exact du défaut
   qu'on cherche à éviter, et sans que personne ne le voie.
2. **La base est une base de prospection collectée.** L'essentiel des fiches a
   des champs non renseignés ; le sens « exclut les inconnus » vide les audiences
   « tout sauf X » de leur substance. C'est mesurable et c'est le cas d'usage réel.
3. **Le risque que redoutait le plan n'existe pas aujourd'hui.** L'objection
   légitime — « faire entrer des inconnus dans une audience d'envoi » — suppose
   qu'on envoie. Le lot L7 (campagnes) est **exclu par décision de Will**, et
   aucun e-mail ne part d'Axion-IA. Le jour où l'envoi existera, la bonne réponse
   ne sera pas de changer l'opérateur : ce sera d'exiger une condition positive
   (`is_not_null`) dans le constructeur d'audience d'envoi, ce qui se voit à
   l'écran au lieu de se cacher dans un opérateur.

### Ce que la ligne 10 devient

Pas une modification : une **garde**. Le travail à faire est d'écrire le test qui
rougit si les deux évaluateurs re-divergent — c'est-à-dire un test qui, pour un
même jeu de critères et un même jeu de fiches, exige que le chemin SQL et le
chemin en mémoire rendent **exactement le même ensemble**, y compris sous le
combinateur `not`. C'est cette symétrie qui est le vrai invariant ; la valeur de
vérité choisie n'en est qu'une conséquence.

**Comment revenir en arrière** : retirer les deux `->orWhereNull($field)` de
`AudienceBuilderService.php:439` et `:443`, **et** aligner `evalCondition()`
(`:535` et `:537`) dans le même mouvement. Changer l'un sans l'autre recrée le
défaut de #169.

---

## Décision 3 — adresse en clair dans `opt_out` : **SUPPRIMÉE, en deux temps**

**Décision** : la recommandation du plan est adoptée. La colonne `opt_out.email`
en clair doit disparaître ; l'empreinte suffit à l'anti-réinsertion.

L'argument est simple et il est de conformité, pas de technique : `opt_out`
recense des personnes qui ont demandé **qu'on ne les recontacte plus**. Conserver
leur adresse en clair, c'est conserver la donnée personnelle de quelqu'un dont le
seul geste enregistré est un refus. L'empreinte SHA-256 remplit la seule fonction
légitime — empêcher qu'un futur re-scrape ne les réintroduise — et le commentaire
de la migration `2026_08_14_000004:101` le dit déjà mot pour mot.

### Mais **pas en une seule migration**, et voici pourquoi

`backend/app/Support/EligibiliteCampagne.php:198-212` interroge aujourd'hui les
deux formes, et le commentaire (`:186-191`) explique que c'est délibéré :

> les signaux venus du site arrivent **hachés**, ceux d'un fournisseur d'envoi
> arrivent **en clair**. Une garde qui ne reconnaîtrait qu'une seule forme serait
> aveugle une fois sur deux.

Supprimer la colonne sans avoir d'abord garanti que **toute** ligne porte son
`email_hash` rendrait invisibles les oppositions qui n'ont que l'adresse en clair.
Autrement dit : le correctif de conformité, mal séquencé, produirait exactement le
dommage qu'il prétend éviter — recontacter quelqu'un qui s'y est opposé.

**Séquence retenue :**

1. **Dans cette PR** — migration de remplissage : calculer `email_hash` pour
   toute ligne d'`opt_out` (et d'`email_suppressions`, qui a le même défaut) qui
   n'en a pas ; **échouer bruyamment** s'il reste une ligne sans empreinte après
   remplissage. Arrêter d'écrire l'adresse en clair. Cesser de la lire.
   Un test d'anti-réinsertion, **vu rouge d'abord**, garde le tout.
2. **Migration suivante, séparée** — `DROP COLUMN email`, une fois le
   remplissage constaté en production sur des données réelles.

Une suppression de colonne est irréversible pour les données qu'elle emporte. La
faire dans le même déploiement que le remplissage qui la rend sûre, c'est se
priver du seul moment où l'on peut encore vérifier.

**Ce que Will doit faire entre les deux temps** : après déploiement de l'étape 1,
constater en production que `select count(*) from opt_out where email_hash is
null` rend **0** (idem `email_suppressions`). Ce constat est la condition
d'entrée du temps 2.

---

## Décision 4 — Dependabot : **TOUT GELÉ jusqu'à la fin de l'étape 0**

**Décision** : ni les majeures ni les mineures ne sont fusionnées maintenant. Le
gel est écrit dans `.github/dependabot.yml` et documenté dans
`_REPORTS/2026-08-18_POLITIQUE-DEPENDANCES-ETAPE-0.md`.

Le plan recommandait « figer les majeures, passer les mineures une par une ». Je
vais plus loin, pour un motif que la mesure a fourni : sur les **20 PR ouvertes
(chiffre vérifié, il est exact)**, **16 sont des majeures et 0 un correctif**.
Il n'y a donc que 4 mineures à gagner à maintenir un canal ouvert — contre le
coût de faire bouger le socle pendant qu'on y pose un harnais de tests.

Le précédent est frais et il est du 18/08, sur le dépôt du site : deux montées
Dependabot fusionnées **séparément** ont ajouté chacune la même clé au lockfile.
Résultat : `ERR_PNPM_BROKEN_LOCKFILE`, plus aucun déploiement. Les gates testent
chaque PR isolément, **jamais leur fusion** — une PR verte ne dit rien de ce que
sa fusion produira.

Le critère de sortie de la ligne 9 accepte explicitement cette option :
« 0 PR Dependabot ouverte, **ou** politique écrite “figé jusqu'à fin de chantier” ».

**Nuance qui a demandé une vérification, pas une supposition** : le gel ne doit
jamais bloquer un correctif de faille. La configuration retenue passe par
`ignore: update-types` et non par `open-pull-requests-limit: 0`, parce que le
`dependabot.yml` de ce dépôt consigne qu'un plafond **saturé** avait déjà coupé
le canal de sécurité en silence. On ne reconfie pas la garde au mécanisme qui a
déjà lâché ici.

🔴 **Découverte à part, et lourde** : les **alertes Dependabot sont désactivées**
sur ce dépôt, qui est public. Le canal de sécurité qu'on prend soin de préserver
**n'existe pas encore**. C'est une action Will, et elle prime sur le reste de
cette décision.

**Comment revenir en arrière (dégel)** : retirer le bloc `ignore` global de
`.github/dependabot.yml`, écosystème par écosystème, en commençant par
`github-actions` (le moins couplé) et en finissant par `composer` (le plus). La
procédure ordonnée est dans le rapport de politique.

---

## Décision 5 — chatbot : **reste ÉTEINT, et le correctif PII se fait quand même**

**Décision** : la décision de Will du 17/08 n'est pas remise en cause. Le chatbot
reste éteint. Le correctif de chiffrement des données personnelles est fait
malgré tout — le plan le demande, et il a raison.

Un défaut dormant n'est pas un défaut absent : il attend un rallumage. Laisser
`capturer-lead.ts` écrire nom, e-mail et téléphone en clair, c'est déposer une
mine sous une décision future qu'on aura oublié d'accompagner.

**Vérification faite dans le code, et elle change la nature du travail** : le
défaut est réel (`src/server/chatbot/tools/capturer-lead.ts:111-114` : aucun appel
à `encryptPii`, alors que les autres points de capture en font). Mais le correctif
est **plus petit et plus sûr** que le plan ne le laisse craindre :

- `decryptPii` (`src/lib/pii-crypto.ts:123`) rend telle quelle toute valeur qui ne
  commence pas par `enc:v1:` — **les lignes déjà écrites en clair continueront
  d'être lues sans erreur**. Aucune reprise de données n'est nécessaire.
- Le typage Prisma supporte déjà le chiffré (`schema.prisma:748` et `:758` sont en
  `@db.Text` précisément pour ça) — **aucune migration**.
- `encryptPii` est idempotent (`pii-crypto.ts:71`) : un double appel ne casse rien.
- Les notifications, l'e-mail de confirmation et l'émission vers le CRM doivent
  continuer d'utiliser la valeur **en clair d'origine** — c'est ce que fait déjà
  le point de capture sain (`unified-contact/actions.ts:298-300`, `:336-337`).
  Chiffrer le seul bloc `tx.submission.create` suffit.
- ⚠️ Ne pas toucher à `idempotencyKey()` (`capturer-lead.ts:63-73`), qui hache
  l'e-mail en clair : le modifier changerait toutes les clés d'idempotence déjà
  émises.

**Comment revenir en arrière** : retirer les trois appels `encryptPii`. La lecture
est symétrique et tolère les deux formes, donc le retour est sans effet de bord.

---

## Décision 6 — étape 13 (envoi aux 71 candidats du stock) : **aucune action**

Le plan la rappelle « pour mémoire, aucune action attendue ». Rien n'est fait,
rien n'est proposé, et le drapeau `VIVIER_STOCK_ENABLED` reste à `false`. C'est
une décision commerciale de Will, sans rapport avec l'étape 0.

Je la mentionne ici uniquement pour qu'elle ne réapparaisse pas comme un « reste »
non traité dans le rapport de fin d'étape.

---

## Récapitulatif

| # | Sujet | Décision | Effet sur le §3 |
|---|---|---|---|
| 1 | Accès base du site | **Non accordé** — habilitation qui appartient à Will | **Ligne 12 BLOQUÉE** ; seul l'outillage de mesure est livré |
| 2 | `neq` / `not_in` sur NULL | **Garder #169** (NULL inclus) — le plan décrivait un état du code périmé d'un jour | Ligne 10 devient une **garde de symétrie**, pas une modification |
| 3 | Adresse en clair dans `opt_out` | **Supprimer, en deux temps** : remplissage + gardes maintenant, `DROP COLUMN` ensuite | Ligne 10, second volet |
| 4 | Dependabot | **Tout gelé** (16 majeures / 4 mineures / 0 correctif sur 20 PR) | Ligne 9 close par la politique écrite |
| 5 | Chatbot | **Reste éteint** ; correctif PII fait quand même | Ligne 13, volet le plus simple des trois |
| 6 | Étape 13 vivier | **Aucune action** | Hors étape 0 |

**Trois actions restent à Will, et elles ne peuvent pas être déléguées :**

1. Fournir l'accès en lecture à la base du site **ou** `SITE_SYNC_HMAC_SECRET`
   (décision 1) — sans quoi la ligne 12 restera indéfiniment « non jouée ».
2. **Activer les alertes Dependabot** sur `will383842/axion-crm-pro` (décision 4) —
   le dépôt est public et n'a aujourd'hui aucun canal de correctif de faille.
3. Fermer les 20 PR Dependabot **après** fusion de la configuration de gel
   (décision 4) — dans l'ordre inverse, elles reviendraient.
