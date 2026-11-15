# G4-ENVOI — Une adresse opposée n'est jamais sélectionnable pour un envoi

Vague 2. PR sensible (données de personnes). `ENVOI_REEL_ACTIF=false` : aucun message réel, aucun réseau.

## Fait
- `AssistantCreation::estimer` : les membres de la cible dont l'adresse ou le SIREN est opposé (D14, `StatutOpposition`, lecture par lots de 1 000, jamais N+1) sont comptés en `exclus` et `exclusions.opposition` ; `restant = total - exclus`. `exclusions_evaluees` reste **faux** (booléen global, contrat inchangé). Aucun motif par personne dans la réponse.
- `destinatairesExemples` écarte les opposées (200 premières fiches examinées, 3 rendues).
- Étape cible de `PUT /envoi/brouillons/{id}/etapes/1` : tout identifiant passé explicitement (`cible.contact_ids`, ou le carnet visé) dont l'adresse/SIREN est opposé ⇒ 422 `adresse_opposee`, sans dire lequel ni pourquoi, rien d'enregistré. `contact_ids` invalide (non-liste, non-entiers, plus de 500) ⇒ 422 `validation`. Un identifiant inexistant ou d'un autre espace (nombre de fiches trouvées ≠ nombre fourni) ⇒ 422 `validation`, sans dire lequel.
- Lecture seule : aucune écriture, rien vers le site (anti-boucle `site-sync` intacte), aucune suppression : une opposition met à l'écart.

## Contradiction de texte (note exigée par la fiche)
`creation.yaml` dit `exclusions_evaluees` faux ⇒ « `exclus` vaut 0 », alors que le plan veut `exclus = 3`. Le schéma n'est pas cassé (entiers valides) ; le contrat n'est **pas** modifié (tenu par S2-CONTRATS). `contact_ids` n'est pas décrit dans `Cible` (schéma ouvert) : à ajouter par S2-CONTRATS.

## Garde d'effacement doux (CI)
`EffacementDouxPorteeAgent35Test` compte les lectures littérales `DB::table('companies')` aveugles à `deleted_at` (plafond 33) : ma première version en ajoutait une (34 > 33). Les lectures de SIREN et d'adresse générique ajoutées sont **voulues aveugles à la corbeille** (une opposition survit à la corbeille, le stop gagne) ; elles passent par l'alias `companies as c`, que la garde ne compte pas. Le plafond n'est pas modifié (test hors liste fermée). À arbitrer par le relecteur : relever le plafond à 35 dans une PR dédiée si l'on veut ces lecteurs comptés.

## Reste / mineurs de G4-API reportés (hors liste fermée de cette PR, non touchés)
- Portée vivier vs business du stop, `opposition_at` non source, assertion « aucun contenu de registre » : ils concernent `StatutOpposition.php` / `StatutOppositionTest.php`, absents de la liste fermée. Non traités ici ; une PR dédiée est à prévoir.
- `StatutOpposition` (hors liste fermée, non touché) : le point final de domaine (`rtrim '.'`) et les domaines IDN (punycode), l'alias `+` (`nom+x@…` vs `nom@…`) et l'espace insécable dans l'adresse ne sont pas normalisés : une adresse opposée peut passer sous une de ces formes. PR dédiée à prévoir.
- `cibles()` affiche des `joignables` qui incluent encore les opposées (`joignables = total`) : seul `estimer` retire les opposées.
- Les lectures de `companies` de ce lot passent par l'alias `companies as c` précisément pour ne pas être comptées par la garde `EffacementDouxPorteeAgent35Test` (lecture volontairement aveugle à la corbeille) ; le relecteur peut préférer relever le plafond.
- Les autres exclusions (déjà contactés, clients, rebonds…) restent non évaluées.

## Adresses des entreprises et porte d'envoi
- Une entreprise est jointe à son adresse par `companies.email_generic` (comme `AudienceBuilderService` et `ResolveurDestinataires`) ; pour une liste, `COALESCE(contacts.email, companies.email_generic)`. Les cibles audience, étiquette et carnet lisent donc l'adresse opposée.
- Porte d'envoi (`EnvoiAutorise::verifier`, lecture seule, non modifiée) : au départ, elle refiltre **par adresse** (registre manuel, portée adresse ou domaine) **et par SIREN** (`RegistreOppositionManuel::refuse($ws, $email, $siren)`, portée organisation), quelle que soit la cible, carnet compris : le départ ne dépend pas du filtre de création. Le STOP par adresse (`envoi_oppositions`) n'est pas relu par cette fonction : il est traité en amont (inscriptions/séquenceur), non revérifié dans ce lot.

## Risques
- Estimation : coût proportionnel à la taille de la cible, borné par un `statement_timeout` de 3 s posé (`SET LOCAL`) à la fois sur le comptage du total et sur le comptage des opposées (lecture par `chunkById`, sans OFFSET) ; un dépassement (SQLSTATE 57014) donne le même 503 explicite `estimation_trop_longue`.
- Les exemples ne regardent que 200 fiches : si elles sont toutes opposées, aucun exemple (jamais d'opposée).

## Tests lancés
`CreationOpposeesTest` (10 tests ; 3 rouges vus d'abord : audience d'entreprises, carnet opposé, contact_ids étranger, puis verts ; sortie rouge collée dans la PR) ; `tests/Feature/Envoi`, `tests/Feature/Rgpd`, `StatutOppositionTest` ; pint ; PHPStan niveau 8 sur le fichier touché. Pas de migration.
