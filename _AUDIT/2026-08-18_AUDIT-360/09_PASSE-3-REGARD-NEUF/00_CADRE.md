# P6 — TROISIÈME PASSE : REGARD NEUF ET COMPLÉTUDE

> Ouverte le **2026-08-20**. Elle n'avait **jamais été lancée** — la définition de fini du
> mandat (§12, point 14) l'exige, et le rapport final ne peut pas être rendu sans elle.

---

## 1. Ce que cette passe est, et ce qu'elle n'est pas

Le §8 du mandat la définit ainsi :

> **P6 — Troisième passe : regard neuf et complétude.**
> Agents **neufs**, sans accès aux rapports des passes 1 et 2 ni à `02_CONSTATS.md`. On leur
> donne : le CDC, le code, ce prompt. Ils refont l'audit **de zéro** sur le périmètre des §4
> et §5. Puis **comparaison des trois passes** : tout écart est **en soi un défaut de
> méthode** à expliquer ligne à ligne (« pourquoi la passe 1 ne l'a-t-elle pas vu ? »).

**Ce n'est donc pas une relecture, ni une contre-vérification.** La passe 2 (adversariale)
cherchait à **réfuter** la passe 1 : elle en connaissait les conclusions et les attaquait. La
passe 3 ne les connaît pas. Elle mesure **ce qu'un regard sans mémoire trouve**, et l'écart
entre les deux est la vraie mesure de complétude de l'audit.

## 2. La règle d'ignorance — et pourquoi elle ne pouvait pas être tenue par l'agent 35

Chaque agent de cette passe reçoit l'interdiction **explicite** de lire :

- `02_CONSTATS.md`, `02bis_P2-CONSOLIDATION.md`, `02ter_CONSOLIDATION-S1.md`
- `07_RAPPORT-FINAL.md`, `08_PASSE-2-ADVERSARIALE.md`
- `11_GRILLES/`, `04_PREUVES/`, `05_DECISIONS.md`, `10_NAVIGATION-CIBLE.md`
- ce dossier-ci, hors le présent cadre

Il reçoit : **le code**, **le CDC**, et **le mandat** (`_PROMPTS/`).

> 🔑 **C'est la raison pour laquelle cette passe se délègue et ne s'improvise pas.** L'agent
> qui a mené les réparations du 19 et du 20 août connaît les 508 constats. Il ne peut pas les
> oublier, et tout ce qu'il « retrouverait » serait suspect de l'avoir été de mémoire. *Un
> regard neuf ne se simule pas : il se recrute.*

## 3. Ce que chaque agent doit rendre

Un fichier `NN_<perimetre>.md` dans ce dossier, portant :

1. **Ce qu'il a mesuré**, avec la commande jouée et la sortie brute. *Un constat sans commande
   jouée n'existe pas* (règle 1 du mandat).
2. **Ses constats**, au format du §9 du mandat, avec une **sévérité argumentée**.
3. **Son témoin négatif** : la preuve que son contrôle *aurait* trouvé un défaut s'il y en
   avait un. *Un « rien trouvé » ne vaut rien sans lui* (règle 3).
4. **Ce qu'il n'a PAS pu mesurer**, et pourquoi. *Une couverture bornée qui se tait passe pour
   une couverture complète.*

**Interdit de réparer.** Cette passe constate. Un agent qui corrige contamine sa propre
mesure, et viole la règle 7 (« celui qui réalise ne vérifie jamais sa propre pièce »).

## 4. Interdits d'exécution, sans exception

- ⛔ **Aucune écriture en production**, aucune requête vers l'hôte Hetzner.
- ⛔ Le worktree `crmpro-wt-etape1a` et la copie principale `Axion-CRM-Pro/backend` : **une
  cinquantaine d'agents y mesurent**. Lecture seule, jamais d'écriture.
- ⛔ Aucun `git push`, aucune fusion, aucun `docker compose up` sur un hôte distant.

## 5. La comparaison des trois passes

Elle vient **après**, et c'est elle le livrable qui compte. Pour chaque constat trouvé par P6 :

| Cas | Ce qu'il signifie |
|---|---|
| **P6 le trouve, P1 aussi** | la passe 1 était bonne sur ce point |
| **P6 le trouve, P1 non** | 🔴 **défaut de méthode de P1** — à expliquer ligne à ligne |
| **P1 le tenait, P6 ne le retrouve pas** | soit il est **fermé** depuis, soit P1 l'avait **surestimé** — et il faut trancher par une mesure, pas par l'ancienneté |

**Le troisième cas est le plus délicat** : entre le 19 et le 20 août, trente-cinq constats ont
été fermés sur la branche `fix/a35-authentification`, **qui n'est pas fusionnée**. Un agent P6
qui lit `main` ne verra pas ces correctifs ; un agent qui lit la branche les verra. **Chaque
agent dit donc sur quelle référence il a mesuré**, et le note en tête de son rapport.

## 6. État de départ, à relire soi-même

⚠️ **Ne fais confiance à aucun identifiant de commit écrit dans un document de ce dossier.**
Relis `git log` et `gh pr list` toi-même, et note la référence réelle en tête de ton rapport.
C'est la règle 6 du mandat, et elle a déjà été payée deux fois.
