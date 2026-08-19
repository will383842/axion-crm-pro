# Passe 2 — contre-vérification adversariale

> **Ouvert le 2026-08-19.** Ce document n'existait pas jusqu'ici, et son absence était le premier
> manque signalé par le rapport final : l'audit s'était arrêté à la fin de P2 avec **P3, P5, P6 et
> P7 non faites**. Il s'ouvre ici.

---

## 0. Ce que cette passe est, et ce qu'elle n'est pas

Le mandat exige **trois passes** : une vérification, une **contre-vérification adversariale
complète**, puis une **troisième passe à regard neuf**. La règle 7 de la doctrine en donne le sens :
*celui qui réalise ne vérifie jamais sa propre pièce.*

**Cette passe n'est donc pas une relecture.** Relire, c'est chercher si un raisonnement se tient.
Contre-vérifier, c'est **essayer de faire tomber le constat par la mesure**, en partant du principe
qu'il est faux jusqu'à preuve du contraire. Les deux exercices ne trouvent pas les mêmes choses.

**Elle s'applique en priorité à ce qui a été mesuré une seule fois, par un seul agent, sur un seul
banc.** Un défaut trouvé deux fois indépendamment (il y en a **sept** au registre) est déjà
contre-vérifié ; le rare est plus suspect que le répété.

**Et elle s'applique à moi.** La moitié des erreurs corrigées dans ce dossier sont les miennes :
sept auto-corrections consignées, dont trois portent sur **le même chiffre**. Une passe adversariale
qui épargnerait le chef de chantier ne vaudrait rien.

---

## 1. Les objets de la passe, par ordre de gravité

| # | Objet | Pourquoi lui | État |
|---|---|---|---|
| **1** | **Les 4 gardes réécrites par l'agent 35** sur `fix/a35-authentification` | Il a écrit les correctifs **et** déclaré ses propres gardes vertes. C'est précisément ce que la règle 7 interdit. **C'est moi qui ai déclaré cette branche bloquée là-dessus** ; la laisser ainsi sans agir serait un blocage de confort | **agent lancé** |
| **2** | **Le décompte S1** — 132 constats jamais dédoublonnés | Le §4 de `02bis` ordonne P3 ; **on ne peut pas ordonner ce qu'on n'a pas compté**. Trou signalé par le recomptage S0 lui-même | **agent lancé** |
| **3** | **Le décompte S0** | Annoncé 12, puis 16, puis 26, puis 27 — **trois fois trop bas** | ✅ **fait**, §2 ci-dessous |
| 4 | **G6 — « l'autorisation n'existe pas »**, 5 S0 | Le groupe qui commande tous les autres, mesuré par **un seul agent** (36) | à faire |
| 5 | **G7 — les temps de l'agent 41** | Le plus gros chiffre de l'audit (3 min 08) repose sur **un seul banc**, et l'agent le déclare lui-même **plancher** | à faire |
| 6 | **Les 15 cas du patron `A-011`** | Le patron le plus structurant du dossier. S'il est vrai, il est très grave ; s'il est sur-appliqué, il décrédibilise le reste | à faire |
| 7 | **Ce que le dossier déclare SAIN** (`02bis` §5) | 🔑 **Personne n'a contre-vérifié les bonnes nouvelles.** Un audit qui ne vérifie que ses accusations est un audit à charge | à faire |

> **Le n° 7 mérite d'être dit.** Onze passes de mesure ont cherché ce qui casse. **Aucune n'a
> cherché si ce qui tient, tient vraiment.** C'est un angle mort de méthode, pas seulement un reste
> de travail : un « point sain » non contre-vérifié est exactement l'endroit où un correctif de P3
> ira casser quelque chose sans que rien ne rougisse.

---

## 2. Résultat n° 1 — le décompte S0 : **29, et non 26, 27 ou 32**

**Objet attaqué** : mon propre tableau, `02bis` §1 bis.
**Déclencheur** : l'agent 35 annonce **32** en clôture ; mon dossier annonçait **26** et **27**.
J'ai refusé d'absorber son chiffre sans mesurer, et refusé de défendre le mien sans recompter.

**Deux erreurs trouvées dans mon tableau** :

1. La ligne « Isolés » comptait **6** défauts S0 dont **`A08-008`** — vérifié à `02_CONSTATS.md:1597`,
   **il est S1**. *Un S1 comptait parmi les S0.* Retiré, la somme retombe sur 26 : **c'était la
   ligne qui était fausse, pas le total.**
2. Le tableau **datait d'avant trois rendus** — `G41-001`, `G41-002`, et le second chemin de
   `F35-002`. Les deux premiers forment **un groupe entier absent** : `G7 — la base ne tient pas le
   volume`.

**→ 29 défauts S0 distincts, ouverts, vrais pour la production.**

**Et l'écart avec l'agent 35 n'est pas un désaccord** : il comptait des **identifiants** (36
étiquettes, moins les alias et le réfuté → 32), je compte des **défauts**. Sept ont été trouvés
deux fois, par deux agents, sur deux bancs : **deux preuves, un seul correctif.**

> **Règle retenue** : **29 pour ordonner P3** — un défaut, un correctif. **36 pour juger la solidité
> du registre** — parce que sept redécouvertes indépendantes sont la meilleure nouvelle qu'un
> registre puisse porter.

**Ce que ce résultat dit de la méthode, et c'est le plus important** : la cause des trois
sous-évaluations n'est pas arithmétique. C'est qu'**un tableau de synthèse ne se rouvrait pas** à
l'arrivée de nouveaux rendus. C'est `A-013`, le défaut de clôture que ce dossier dénonce —
**reproduit une troisième fois dans le document qui le dénonce.** Le §1 bis porte désormais **sa
méthode avant son chiffre**, pour qu'un tiers puisse le recompter sans me croire.

---

## 4. Résultat n° 2 — la première contre-vérification d'une **bonne nouvelle** : elle tient

**Objet attaqué** : `02bis` §5, la ligne *« La suite de tests backend — 780 tests, 6 503 assertions,
PHPStan niveau 8 `[OK]`, et **zéro exclusion silencieuse** »*.

**Pourquoi celle-là d'abord** : c'est la bonne nouvelle **la plus portante** du dossier. Tout le
plan de correction de P3 suppose qu'on dispose d'un filet pour savoir si un correctif casse quelque
chose. Si ce filet est troué, **P3 se fait à l'aveugle** — et c'est le genre d'affirmation
rassurante que `A-013` désigne comme le défaut de clôture typique. J'avais d'ailleurs déjà été pris
une fois sur cette ligne exacte : j'y avais écrit *« la CI backend, bloquante et requise »*, ce qui
contredisait trois constats de mon propre dossier.

**Comment je l'ai attaquée** : par l'angle que le contrôle initial n'avait pas pris.
Le mandat de la CI n'exécute **pas** `phpunit.xml` — le job `backend` de `ci.yml:195` lance
`php vendor/bin/pest --configuration **phpunit-ci.xml**`. *Un contrôle d'exclusions mené sur le
fichier que la CI n'ouvre pas serait le seizième cas du patron `A-011`.*

**Mesuré** :

| Ce qui est affirmé | Mesure | Verdict |
|---|---|---|
| 0 exclusion dans la configuration | `phpunit.xml` **et** `phpunit-ci.xml` : `0 <exclude>`, `0 @group`, `0 #[Group]` | ✅ **vrai sur les deux fichiers** |
| 0 saut silencieux | **1 seul** `markTestSkipped` dans tout `tests/` — `NeDoitPasRegresserTest.php:169` | ✅ **et c'est un contre-exemple parfait** : il nomme le binaire absent, où le contrôle tourne pour de vrai, et écrit *« un `skip` est un aveu, pas une victoire »*. **L'inverse d'un saut silencieux** |
| 0 `->skip()`, `->todo()`, `->only()` | vérifié, **0 des trois** | ✅ |
| La quarantaine est levée | **les 23 fichiers de `QUARANTAINE.md` sont TOUS présents dans `tests/`** — vérifié un par un | ✅ **et plus fort que l'énoncé** : l'en-tête dit « réparés **ou supprimés** » ; en fait **23 réparés, 0 supprimé** |
| Seule différence entre les deux configs | `executionOrder` : `random` en local, `default` en CI | ✅ conforme à ce qui est écrit |

**→ La bonne nouvelle tient. Je la confirme, par un chemin que personne n'avait pris.**

### Ce que l'attaque a rapporté quand même

`executionOrder="default"` en CI n'est pas neutre, et le fichier l'assume : *« Deux exécutions du
MÊME commit avaient donné **262 verts puis 48 rouges** — du couplage entre tests. »* Le couplage
**n'a pas été réparé : il a été contourné par l'ordre.** La porte tourne donc dans l'ordre où elle
passe, et l'ordre aléatoire qui le débusquerait **ne garde rien**.

⚠️ **Et voici l'honnêteté que cette passe se doit** : ce n'est **pas** un constat neuf. C'est
`H44-011` (S2), déjà au registre — et la comparaison des deux fichiers `phpunit` y figure déjà
(`02_CONSTATS.md:1004-1005`). **Mon attaque a redécouvert un constat existant, elle ne l'a pas
trouvé.** C'est un résultat de moindre valeur, et je le dis plutôt que de le présenter comme une
trouvaille.

> **Ce que j'en retiens pour la suite de la passe** : contre-vérifier une bonne nouvelle **coûte peu
> et rapporte deux fois** — soit elle tombe, soit elle devient opposable. Ici elle est devenue
> opposable : on peut désormais écrire *« la suite est saine »* en sachant que c'est vrai **sur le
> fichier que la CI ouvre réellement**, ce qui n'était pas établi. Cela ne change rien au fait que
> **son câblage**, lui, reste troué (`F38-002`, `A08-005`, `H44-003`) : *la suite est saine, la
> porte ne l'est pas, et les deux ne se confondent pas.*

---

## 3. Journal de la passe

| Date | Objet | Verdict |
|---|---|---|
| 2026-08-19 | Décompte S0 (`02bis` §1 bis) | 🔴 **Faux, trois fois de suite et toujours trop bas.** Corrigé à **29**, propagé au rapport final et à `06_RESTE-WILL` (qui portait encore **« douze »** — la page que Will lit en premier) |
| 2026-08-19 | `02bis` §5 — « la suite de tests est saine, zéro exclusion silencieuse » | ✅ **Confirmée**, et sur le fichier que la CI ouvre vraiment (`phpunit-ci.xml`), ce qui n'avait pas été fait. Quarantaine levée vérifiée **fichier par fichier : 23/23 présents**. Le couplage entre tests contourné par l'ordre reste ouvert — mais c'est `H44-011`, **déjà connu** : redécouverte, pas trouvaille |
