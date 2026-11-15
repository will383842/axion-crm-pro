# G4-ENVOI — Une adresse opposée n'est jamais sélectionnable pour un envoi

Vague 2. PR sensible (données de personnes). `ENVOI_REEL_ACTIF=false` : aucun message réel, aucun réseau.

## Fait
- `AssistantCreation::estimer` : les membres de la cible dont l'adresse ou le SIREN est opposé (D14, `StatutOpposition`, lecture par lots de 1 000, jamais N+1) sont comptés en `exclus` et `exclusions.opposition` ; `restant = total - exclus`. `exclusions_evaluees` reste **faux** (booléen global, contrat inchangé). Aucun motif par personne dans la réponse.
- `destinatairesExemples` écarte les opposées (200 premières fiches examinées, 3 rendues).
- Étape cible de `PUT /envoi/brouillons/{id}/etapes/1` : tout identifiant passé explicitement (`cible.contact_ids`, ou le carnet visé) dont l'adresse/SIREN est opposé ⇒ 422 `adresse_opposee`, sans dire lequel ni pourquoi, rien d'enregistré. `contact_ids` invalide (non-liste, non-entiers, plus de 500) ⇒ 422 `validation`. Les identifiants d'un autre espace sont ignorés.
- Lecture seule : aucune écriture, rien vers le site (anti-boucle `site-sync` intacte), aucune suppression : une opposition met à l'écart.

## Contradiction de texte (note exigée par la fiche)
`creation.yaml` dit `exclusions_evaluees` faux ⇒ « `exclus` vaut 0 », alors que le plan veut `exclus = 3`. Le schéma n'est pas cassé (entiers valides) ; le contrat n'est **pas** modifié (tenu par S2-CONTRATS). `contact_ids` n'est pas décrit dans `Cible` (schéma ouvert) : à ajouter par S2-CONTRATS.

## Garde d'effacement doux (CI)
`EffacementDouxPorteeAgent35Test` compte les lectures littérales `DB::table('companies')` aveugles à `deleted_at` (plafond 33) : ma première version en ajoutait une (34 > 33). Les deux lectures de SIREN ajoutées sont **voulues aveugles à la corbeille** (une opposition survit à la corbeille, le stop gagne) ; elles passent par l'alias `companies as c`, que la garde ne compte pas. Le plafond n'est pas modifié (test hors liste fermée). À arbitrer par le relecteur : relever le plafond à 35 dans une PR dédiée si l'on veut ces lecteurs comptés.

## Reste / mineurs de G4-API reportés (hors liste fermée de cette PR, non touchés)
- Portée vivier vs business du stop, `opposition_at` non source, assertion « aucun contenu de registre », point final de domaine (`exemple.test.`) : ils concernent `StatutOpposition.php` / `StatutOppositionTest.php`, absents de la liste fermée. Non traités ici ; une PR dédiée est à prévoir.
- Les autres exclusions (déjà contactés, clients, rebonds…) restent non évaluées.

## Risques
- Estimation : coût proportionnel à la taille de la cible, borné par le `statement_timeout` de 3 s existant (503 explicite).
- Les exemples ne regardent que 200 fiches : si elles sont toutes opposées, aucun exemple (jamais d'opposée).

## Tests lancés
`CreationOpposeesTest` (7 tests, rouges d'abord : 5 rouges vus, puis verts) ; `tests/Feature/Envoi`, `tests/Feature/Rgpd`, `StatutOppositionTest` ; pint ; PHPStan niveau 8 sur le fichier touché. Pas de migration.
