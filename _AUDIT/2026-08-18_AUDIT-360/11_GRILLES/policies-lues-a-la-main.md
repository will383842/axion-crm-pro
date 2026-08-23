# LES 11 POLICIES, LUES UNE PAR UNE

> **Écrit à la main le 2026-08-23**, en lisant les onze fichiers. C'est le
> complément de `inventaire-code.md`, qui est généré : celui-ci porte ce qu'une
> machine ne mesure pas — ce que chaque policy **autoriserait**, et ce qui
> cloche.
>
> Constats visés : `B12-003` (S0), `B12-012`, `F36-001`, `F36-002`, `F36-009`,
> `Z50-003`. Le §12-1 du mandat exige « aucune ligne vide » ; il y en avait onze.

---

## 0. LE RÉSULTAT EN TROIS PHRASES

1. **Les onze policies sont enregistrées et aucune n'est jamais consultée.**
   `grep -c '$this->authorize('` sur les **45 contrôleurs** rend **0**.
2. **Neuf des onze sont des coquilles vides** — `class XPolicy extends
   BasePolicy {}`, cinq lignes. Toute la décision vit dans `BasePolicy`.
3. **`BasePolicy::sameWorkspace()` est fail-open** : quand le modèle n'a pas de
   `workspace_id`, elle répond `true`. Le jour où l'on branchera `authorize()`,
   c'est le premier piège qui se refermera.

---

## 1. CE QUE `BasePolicy` DÉCIDE VRAIMENT

C'est le seul fichier qui contient une décision. Les neuf coquilles en héritent
telle quelle, sans rien y ajouter.

| Méthode | Ce qu'elle exige | Remarque |
|---|---|---|
| `viewAny(User)` | un rôle parmi `owner`, `admin`, `operator`, `viewer` | c'est-à-dire **tout compte ayant un rôle**. Elle ne filtre rien en pratique. |
| `view(User, $model)` | `sameWorkspace()` **seulement** | 🔑 **aucun contrôle de rôle** : un `viewer` voit tout ce qui est dans son espace. C'est cohérent avec « lecture seule », mais il faut le savoir. |
| `create(User)` | `owner`, `admin` ou `operator` | ne peut pas vérifier l'espace : **il n'y a pas de modèle** à cet instant. C'est une limite de forme, pas un oubli. |
| `update(User, $model)` | `sameWorkspace()` **et** `owner`/`admin`/`operator` | |
| `delete(User, $model)` | `sameWorkspace()` **et** `owner`/`admin` | conforme à « operator = CRUD sans destruction ». |

### 🔴 `sameWorkspace()` répond `true` quand elle ne sait pas

```php
protected function sameWorkspace(User $user, $model): bool
{
    if (! isset($model->workspace_id)) {
        return true;          // <<<< fail-open
    }

    return hash_equals((string) $user->current_workspace_id, (string) $model->workspace_id);
}
```

C'est **exactement le motif `B12-012`**, et c'est le même que celui que
`ApiController::estDeMonEspace()` porte un étage plus haut — sauf que là, le
correctif du 2026-08-20 a tranché dans l'autre sens (`refuserHorsEspace()`
abort en 404). Les deux couches d'un même produit répondent donc l'inverse
l'une de l'autre à la même question.

⚠️ **Ce n'est pas un défaut actif aujourd'hui**, puisque rien n'appelle ces
policies. Il le deviendra **au premier `authorize()`** — et ce jour-là il
s'armera sur les **onze** d'un coup, silencieusement. Le constat `B12-012` le
dit déjà ; la grille ajoute que le piège est **dans la classe mère**, donc
qu'un seul correctif suffit à le fermer partout.

---

## 2. LES ONZE, UNE PAR UNE

| # | Policy | Modèle protégé | Ce qu'elle ajoute à `BasePolicy` | Verdict |
|---:|---|---|---|---|
| 1 | `BasePolicy` | — *(abstraite)* | **tout** : les 5 méthodes | 🟡 la seule qui décide ; `sameWorkspace()` fail-open |
| 2 | `AuditLogPolicy` | `AuditLog` | `viewAny` → **`owner` seul** | 🟢 **la seule surcharge du dépôt**, et elle est juste : un journal d'audit n'est pas une donnée d'équipe |
| 3 | `CompanyPolicy` | `Company` | **rien** | 🔴 coquille vide |
| 4 | `ContactPolicy` | `Contact` | **rien** | 🔴 coquille vide — et c'est celle qui protégerait 1 319 567 fiches de personnes |
| 5 | `LlmUseCasePolicy` | `LlmUseCase` | **rien** | 🔴 coquille vide |
| 6 | `ProxyProviderPolicy` | `ProxyProvider` | **rien** | 🔴 coquille vide |
| 7 | `RgpdRequestPolicy` | `RgpdRequest` | **rien** | 🔴 coquille vide — une demande RGPD mériterait le même traitement que le journal d'audit (n° 2) |
| 8 | `ScraperRunPolicy` | `ScraperRun` | **rien** | 🔴 coquille vide |
| 9 | `TagPolicy` | `Tag` | **rien** | 🔴 coquille vide |
| 10 | `UserPolicy` | `User` | **rien** | 🔴 coquille vide — donc `update()` autorise un `operator` à modifier n'importe quel compte de son espace, **y compris celui d'un `owner`** |
| 11 | `WorkspacePolicy` | `Workspace` | **rien** | 🔴 coquille vide — et `Workspace` n'a pas de `workspace_id`, donc `sameWorkspace()` y répond **toujours `true`** : la policy de l'espace est **entièrement fail-open** |

### 🔴 Deux lignes de ce tableau méritent d'être lues deux fois

**`UserPolicy` (n° 10).** Héritée telle quelle, `update()` demande
`owner`/`admin`/`operator` **plus** le même espace. Rien ne compare les **rangs** :
un `operator` passerait la garde pour modifier le compte d'un `owner`. Le
produit s'en tire aujourd'hui parce que les routes portent
`permission:users.manage`, que `operator` n'a pas — **c'est le middleware qui
protège, pas la policy.**

**`WorkspacePolicy` (n° 11) — et ce n'est pas la seule.** En le vérifiant, j'ai
mesuré les **dix** modèles protégés plutôt que de raisonner sur un seul :

```
Company         workspace_id présent        Tag             workspace_id présent
Contact         workspace_id présent        RgpdRequest     workspace_id présent
ScraperRun      workspace_id présent        AuditLog        workspace_id présent
LlmUseCase      workspace_id présent        ProxyProvider   workspace_id présent
Workspace   >>> PAS de workspace_id  ->  sameWorkspace() TOUJOURS vrai
User        >>> PAS de workspace_id  ->  sameWorkspace() TOUJOURS vrai
```

*(mesuré par `Schema::getColumnListing()` sur le banc, pas déduit du code)*

**Deux modèles sur dix échappent structurellement au cloisonnement des
policies — et ce sont les deux plus sensibles.** `workspaces` porte `id`, pas
`workspace_id` ; `users` porte `current_workspace_id`. Dans les deux cas
`isset($model->workspace_id)` est **toujours faux**, donc `sameWorkspace()` rend
**toujours `true`**, et `view`/`update`/`delete` ne vérifient plus que le rôle.

Autrement dit : le jour où l'on branche `authorize()`, **n'importe quel `admin`
passerait la policy sur l'espace ET sur les comptes de n'importe quel client.**
Aujourd'hui, seuls les middlewares `permission:workspaces.manage` et
`permission:users.manage` tiennent la porte.

🔑 **Ce n'est pas une faute de `BasePolicy`, c'est une faute d'hypothèse.** Elle
suppose que la colonne d'appartenance s'appelle partout `workspace_id`. Deux
tables ne suivent pas cette convention — et la classe le lit comme « ce modèle
n'appartient à personne » au lieu de « je ne sais pas ». C'est le même
raisonnement que `F37-001` : un contrôle qui, faute de savoir, répond « oui ».

---

## 3. POURQUOI RIEN NE ROUGIT — `F36-009`, mesuré

| mesure | résultat |
|---|---|
| `$this->authorize(` dans `app/Http/Controllers/` | **0** sur 45 fichiers |
| `->can(` dans les contrôleurs | **1** — `AiActRegisterController:126`, et c'est une **permission Spatie**, pas une policy |
| `permission:` sur les routes de `routes/api.php` | **53** |
| policies **nommées** par un fichier de test | **1 sur 11** (`BasePolicy`) |

**L'autorisation du produit passe donc entièrement par les 53 middlewares
`permission:`.** Les policies sont un second dispositif, complet sur le papier,
enregistré, jamais branché — et sans test qui le remarquerait.

*C'est la définition même du faux témoin : un lecteur du dossier
`app/Policies/` conclut que le produit a une couche d'autorisation par objet.
Elle existe, elle est morte.*

---

## 4. CE QUE CETTE LECTURE NE FAIT PAS

- ❌ **Elle ne branche rien.** Poser `authorize()` dans 45 contrôleurs armerait
  d'un coup le fail-open de `sameWorkspace()` **et** les deux fail-open
  ci-dessus. L'ordre est : corriger `BasePolicy`, écrire les gardes, **puis**
  brancher — jamais l'inverse.
- ❌ **Elle ne comble pas `F36-009`.** Les policies n'ont toujours aucun test.
  La garde à écrire est nommée dans le constat : les réécrire en refus total
  doit faire rougir quelque chose.
- ❌ **Elle ne tranche pas** si les 9 coquilles doivent être remplies ou
  supprimées. Neuf fichiers qui n'ajoutent rien peuvent être un socle prêt à
  recevoir des règles, ou du décor. **C'est une décision, elle revient à Will.**
