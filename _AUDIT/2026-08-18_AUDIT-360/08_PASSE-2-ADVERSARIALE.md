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

## 3. Journal de la passe

| Date | Objet | Verdict |
|---|---|---|
| 2026-08-19 | Décompte S0 (`02bis` §1 bis) | 🔴 **Faux, trois fois de suite et toujours trop bas.** Corrigé à **29**, propagé au rapport final et à `06_RESTE-WILL` (qui portait encore **« douze »** — la page que Will lit en premier) |
