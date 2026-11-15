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
`EffacementDouxPorteeAgent35Test` compte les lectures littérales `DB::table('companies')` aveugles à `deleted_at`. Les deux lectures ajoutées (estimation du carnet, vérification du carnet à l'étape cible) sont **voulues aveugles à la corbeille** (une opposition survit à la corbeille, le stop gagne). Un alias (`companies as c`) contournait la garde : retiré. Décision du coordinateur : lectures littérales sans alias, plafond `companies` relevé de 33 à 35 et compteur global de 108 à 110 (test modifié, commentaire « G4-ENVOI : une opposition survit à la corbeille… »). Aucune autre assertion changée.

## Reste (non traité dans cette PR)
- M3 : deviner l'existence d'une opposition dans son propre espace (par `contact_ids` / carnet : 422 `adresse_opposee` vs `validation`) reste possible pour un membre de l'espace.
- M4 : `cibles()` affiche des `joignables` qui incluent encore les opposées (`joignables = total`) ; seul `estimer` les retire.
- M5 : `StatutOpposition` ne normalise pas le point final de domaine, le punycode (IDN), l'alias `+` (`nom+x@…`) ni l'espace insécable : une adresse opposée peut passer sous une de ces formes. PR dédiée.
- Portée vivier vs business du stop, `opposition_at` non source, assertion « aucun contenu de registre » : concernent `StatutOpposition.php` / `StatutOppositionTest.php`, hors liste fermée.
- Les autres exclusions (déjà contactés, clients, rebonds…) restent non évaluées.
- Étiquette : `company_tag` ne porte que des entreprises (pas de contact nominatif) ; seuls `email_generic` et le SIREN sont examinés.
- **Trou antérieur à signaler (non corrigé ici)** : la porte `EnvoiAutorise::verifier` ne relit pas les `rgpd_requests` de type `opposition` en cours (art. 21). Le filtre de création les lit (via `StatutOpposition`), pas le départ.

## Adresses des entreprises et porte d'envoi
- Une entreprise est jointe à son adresse par `companies.email_generic` (comme `AudienceBuilderService` et `ResolveurDestinataires`) ; pour une liste, `COALESCE(contacts.email, companies.email_generic)`. Les cibles audience, étiquette et carnet lisent donc l'adresse opposée ; pour une audience, l'adresse nominative du contact (`audience_members.contact_id`) est aussi examinée.
- Porte d'envoi (`EnvoiAutorise::verifier`, lecture seule, non modifiée) : au départ, elle refiltre **par adresse** (registre manuel, portée adresse ou domaine) **et par SIREN** (`RegistreOppositionManuel::refuse($ws, $email, $siren)`, portée organisation), quelle que soit la cible, carnet compris : le départ ne dépend pas du filtre de création. Le STOP par adresse (`envoi_oppositions`) n'est pas relu par cette fonction : il est traité en amont (inscriptions/séquenceur), non revérifié dans ce lot.

## Risques
- Estimation : une **échéance globale de 3 s** (`hrtime`, total + opposées, contrôlée entre les lots de 1 000) et un **plafond de 200 000 lignes examinées** ; au-delà, même 503 explicite `estimation_trop_longue` que le SQLSTATE 57014. `SET LOCAL statement_timeout` (3 s) vaut par requête seulement, il n'est que le filet. Le découpage se fait sur la clé réelle de la table parcourue (`listes_manuelles_membres.id`, `audience_members.id`, `company_tag.company_id`), pas sur `companies.id`.
- Le total et les opposées sont lus dans une seule transaction (REPEATABLE READ, lecture seule quand elle est à la racine) : `exclus` ne peut pas dépasser `total` (de plus borné par `min`).
- Les exemples ne regardent que 200 fiches : si elles sont toutes opposées, aucun exemple (jamais d'opposée).

## Tests lancés
`CreationOpposeesTest` (15 tests, dont 5 vus rouges sur l'ancien code : échéance, plafond, liste tout opposée, contact nominatif, carnet inconnu ; 3 rouges vus d'abord : audience d'entreprises, carnet opposé, contact_ids étranger, puis verts ; sortie rouge collée dans la PR) ; `tests/Feature/Envoi`, `tests/Feature/Rgpd`, `StatutOppositionTest` ; pint ; PHPStan niveau 8 sur le fichier touché. Pas de migration.
