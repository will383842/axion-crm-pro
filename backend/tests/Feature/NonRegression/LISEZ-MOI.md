# Le filet de non-régression (lot R0)

**But.** Dire « ce qui marche aujourd'hui marche encore demain ». Si un lot casse l'existant, la CI devient rouge à son premier job (`filet-non-regression`) et rien ne part en production.

## Où est quoi

| Élément | Emplacement |
|---|---|
| Gardes neuves (24 risques NR-01 à NR-24 + horodatages de Paris) | ce dossier, groupe Pest `non-regression` |
| Gardes existantes « citées et verrouillées » | rattachées au groupe dans `backend/tests/Pest.php` (leur fichier n'est pas touché) |
| Photos de référence (« instantanés ») | `instantanes/*.json` |
| Liste figée | `LISTE.json` (base figée) + `LISTE.d/<date>-<lot>.json` (un fragment par lot), union vérifiée par `ListeFigeeTest.php` |
| Colonnes JSON/JSONB (RGPD) | `../Rgpd/colonnes-json/<date>-<lot>.json` (un fragment par lot), union vérifiée par `PortabiliteCompleteTest.php` (B10-004) |
| Gardes du frontend (routes, routeTree, menu) | `frontend/tests/non-regression/*.test.ts` |
| Job de CI | `.github/workflows/ci.yml`, job `filet-non-regression` (premier ; le job `backend-verdict` l'attend et échoue si le filet n'est pas vert — le contexte exigé par la protection de `main`) |
| Verdict et artefacts | `backend-verdict` télécharge `pest-part-*` : il relit les artefacts de TOUTE la run (y compris d'une relance partielle), pas seulement ceux de la tentative en cours |

## Lancer le filet en local

```bash
cd backend
php vendor/bin/pest --group=non-regression --no-coverage      # ≈ 1 minute
cd ../frontend && pnpm exec vitest run tests/non-regression
```

Il faut la base de test habituelle (PostGIS, pgvector, pg_partman), exactement comme `php vendor/bin/pest`.

## Les règles

1. **Un test du filet ne se modifie que pour une raison écrite** (décision de Will ou changement voulu par un lot), dans la même PR, avec la ligne **« Filet modifié : pourquoi »** dans la description.
2. **Jamais de quarantaine, jamais de test sauté**, jamais `continue-on-error`.
3. **Données factices uniquement** : deux espaces « Espace A » et « Espace B », adresses en `@example.test`, aucune personne réelle, aucun secret (les valeurs de test sont inventées à l'exécution).
4. **Aucun test ne touche un serveur**, n'envoie un e-mail ou n'appelle un service extérieur (simulateurs `MOCK_*`).
5. **Le filet constate, il ne corrige pas.** Un défaut trouvé est écrit dans la photo ou dans le test avec la mention « à corriger par le lot X » ; le lot X mettra le test à jour en connaissance de cause.

## Les instantanés

Trois formes de comparaison (classe `Instantane`) :

| Méthode | Sens | Exemples |
|---|---|---|
| `verifier` | égalité stricte : tout écart fait rougir | tâches planifiées (NR-07), drapeaux (NR-16), liste de destinataires (NR-02) |
| `verifierInclut` | la réalité doit CONTENIR la photo : retirer fait rougir, ajouter est permis | routes (NR-20), tables de l'export RGPD (NR-06) |
| `verifierContenuDans` | la réalité doit rester CONTENUE dans la photo : ajouter fait rougir, retirer est permis | workflows qui touchent un serveur (NR-18), migrations sans retour réel (NR-17) |

**Régénérer une photo est un geste explicite**, jamais un effet de bord, et refusé en CI :

```bash
NR_REGENERER_INSTANTANES=1 php vendor/bin/pest --group=non-regression
git diff backend/tests/Feature/NonRegression/instantanes/   # le changement apparaît en clair dans la PR
```

## Ajouter un test au filet

1. Écrire le test dans ce dossier (il reçoit le groupe automatiquement), avec un titre qui commence par `NR-xx`.
2. Le déclarer dans un NOUVEAU fragment `LISTE.d/<date>-<lot>.json` (objet dont la SEULE clé est `gardes`, une liste ; chaque garde porte un `id` non vide et uniquement les clés `id`, `nouveaux`, `existants`, `frontend` — les trois dernières peuvent être omises ; toute autre clé, un JSON illisible ou un `id` vide fait échouer le chargement) — ne pas toucher `LISTE.json` ni le fragment d'un autre lot ; `ListeFigeeTest` rougit sinon, et refuse un même identifiant de garde ou un même `fichier::titre` présent deux fois.
3. Un test qui constate doit avoir son **témoin** : la preuve que l'instrument sait voir (une garde qui ne trouve rien passerait pour verte).

## Nouvel appel vers une nouvelle route (NR-20)

Le frontend ne peut appeler qu'une route qui existe : si votre lot ajoute un appel vers une route nouvelle, il faut **régénérer `instantanes/nr20-routes.json`** (photo des routes serveur, commande de régénération ci-dessus) et le dire dans la PR avec « Filet modifié : pourquoi ».

## Ce qui est refusé automatiquement (pas de quarantaine)

- `ListeFigeeTest` retire les commentaires de `Pest.php` avant de chercher les rattachements : un rattachement mis en commentaire fait rougir.
- `ListeFigeeTest` refuse `->skip(`, `->todo(`, `markTestSkipped` et `markTestIncomplete` dans les fichiers de ce dossier.
- Le job de CI lance le groupe avec `--fail-on-skipped` : tout test sauté du groupe (y compris une garde existante rattachée) fait échouer le job.

## Écarts constatés au moment de R0 (à traiter par d'autres lots)

Ils sont aussi écrits en tête des fichiers concernés.

| Constat | Fichier | Lot |
|---|---|---|
| Valeur vide de `CRM_SEGMENTS_OUVERTS` : les 3 segments connus sont OUVERTS (le plan écrivait « fermés ») | `Nr05SegmentsFermesTest.php` | S6 |
| L'opposition (`opt_out`, `email_suppressions`) est globale, pas par espace | `Nr01OppositionDeuxEspacesTest.php` | P2 |
| `MOCK_MAIL`, `MOCK_IGN`, `MOCK_RPPS` sont lus hors de la garde de production | `Nr15SimulateursTest.php` | S2 |
| La sonde « non diffusibles » est aveugle sous le rôle de production (RLS) | `Nr23Nr08AudiencesEtRlsTest.php` | S7 |
| Seules 2 tâches planifiées sont rejouées sous le rôle de production ; les autres sont listées « non jouée » | `instantanes/nr08-taches-sous-rls.json` | S7 |
| NR-22 (connexion de bout en bout) : seules les gardes Pest sont rattachées ; l'e2e `auth` est hors du périmètre fermé de ce lot | `LISTE.json` | lot e2e |

## Ajouter une colonne JSON/JSONB (B10-004)

Une migration qui ajoute une colonne `json`/`jsonb` n'édite plus de nombre en dur : elle ajoute un NOUVEAU fragment `backend/tests/Feature/Rgpd/colonnes-json/<date>-<lot>.json`, au format `{"table.colonne": "couverte" | "hors-portee: motif"}`. Le test exige l'**égalité stricte** entre l'union des fragments et les colonnes réellement présentes en base (dans les deux sens), et entre les « hors-portee » déclarées et celles que le test calcule. Un même nom dans deux fragments est refusé. Deux lots n'écrivent donc plus jamais dans le même fichier.

## Journal de la liste figée (ajouts seulement)

| PR | Ajout dans `LISTE.json` | Pourquoi |
|---|---|---|
| #433 (envoi P0 L0) | NR-17 « la liste des retours inertes voulus est fermée : fichiers existants, nouveaux, jamais réels » | Test ajouté dans `Nr17MigrationsTest.php` avec la liste fermée `NR17_RETOUR_INERTE_VOULU` (000011, 000013). Aucun test retiré ni affaibli : toute autre migration nouvelle (dont les 000020-22 de L3, au `down()` non destructif) garde l’exigence d’un retour réel + aller-retour-aller. |
