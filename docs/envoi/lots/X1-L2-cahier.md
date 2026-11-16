# CAHIER 1 — LOT X1-L2 « SelectionDestinataires » (lecture seule)

**Pour le développeur :** dépôt public `will383842/axion-crm-pro`, branche depuis `origin/main` (a79d75c2). Aucune migration, aucune écriture en base, aucune route. Dans le code, les tests, la PR et les commits : écrire « le fondateur » (jamais de nom) ; données fictives en `*.example.test`.

## Fichiers à créer (aucun fichier existant n'est modifié)
- `backend/app/Envoi/Campagnes/SelectionDestinataires.php`
- `backend/app/Envoi/Campagnes/Destinataire.php`
- `backend/app/Envoi/Campagnes/LotDestinataires.php`
- `backend/app/Envoi/Campagnes/SelectionRefusee.php`
- `backend/tests/Feature/Envoi/SelectionDestinatairesTest.php`
- `docs/envoi/lots/X1-L2.md` (sur le modèle de `docs/envoi/lots/X1-L1.md` : fait, sortie rouge, tableau des mutations, limites)

## Signatures
```php
namespace App\Envoi\Campagnes;

final class Destinataire {            // aucune adresse e-mail en sortie
  public function __construct(
      public readonly string $population,   // toujours 'entreprises' dans ce lot
      public readonly int $contactId,
      public readonly int $entrepriseId,
      public readonly ?int $personneId,     // toujours null dans ce lot
      public readonly string $empreinte,    // ListeSuppression::empreinte(adresse brute de contacts.email), 64 hex
  ) {}
}

final class LotDestinataires {
  /** @param list<Destinataire> $destinataires @param array<string,int> $compteurs */
  public function __construct(
      public readonly array $destinataires,
      public readonly array $compteurs,
      public readonly ?int $curseurSuivant,   // null = fin de la cible
  ) {}
}

final class SelectionRefusee extends \RuntimeException {
  public function __construct(public readonly string $motif, public readonly bool $reessayable, string $message, ?\Throwable $prev = null)
  // fabriques : populationNonOuverte(), cibleIntrouvable(), cibleNonSupportee(), lectureOppositionImpossible(\Throwable),
  //             lectureTropLongue(), campagneIntrouvable(), tailleInvalide()
}

final class SelectionDestinataires {
  public const POPULATIONS_OUVERTES = ['entreprises'];
  public const TAILLE_MAX = 500;
  public const CONTACTS_PAR_ENTREPRISE = 20;
  public const DELAI_SQL_MS = 3000;
  public const MOTIFS = ['opposition','rgpd_en_cours','presse','client_console','deja_en_sequence','doublon',
      'sans_contact','sans_adresse','sans_entreprise','fiche_supprimee','generique_cible','entreprise_deja_couverte'];
  public const PRIORITE = ['opposition','rgpd_en_cours','presse','client_console','deja_en_sequence','doublon']; // ordre strict

  /** @param (\Closure(string, array): array)|null $lecteurOpposition  injectable pour les tests ; défaut : (new StatutOpposition)->pourLignes(...) */
  public function __construct(private readonly ?\Closure $lecteurOpposition = null) {}

  public function lot(string $ws, string $campagneId, string $population, int $apres = 0, int $taille = 200): LotDestinataires;
}
```
`compteurs` contient toujours toutes les clés de `MOTIFS`, plus `membres`, `retenus` et `sans_contact_generique_disponible`. Ce dernier est un sous-compte d'information pour le fondateur, hors somme. **Invariant à tester partout :** `membres = retenus + somme(MOTIFS)`. Tout est en nombres : `L4` l'écrira dans `envoi_campagnes_materialisations.compteurs` (CHECK des nombres, 2 048 octets au plus : `2026_12_04_000020…:26-29`).

## Déroulé de `lot()`
1. **Population.** Si `population` n'est pas dans `POPULATIONS_OUVERTES` : `SelectionRefusee::populationNonOuverte()` (motif `population_non_ouverte`, non réessayable), AVANT toute requête. Le test le prouve avec `DB::listen` : zéro requête. Si `taille` n'est pas entre 1 et 500, ou si `apres < 0` : `tailleInvalide`.
2. Tout se passe dans `WorkspaceContext::run($ws, fn () => DB::transaction(...))`. En transaction racine : `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY`, puis `SET LOCAL statement_timeout = '3000'`. C'est le modèle de `AssistantCreation.php:224-235`. Le code `57014` donne `lectureTropLongue` (réessayable).
3. **Campagne.** `SELECT cible, saisie FROM envoi_campagnes WHERE workspace_id = ? AND id = ?`. Si elle est absente : `campagneIntrouvable`.
 - La cible fait foi par la COLONNE `cible`, `{type, reference, libelle}` (CHECK `000080…:28-35`).
 - Si `saisie.contenu.cible.contact_ids` existe et n'est pas vide (`AssistantCreation.php:334`) : `cibleNonSupportee`. La colonne n'en garde pas trace, et la sélection ne doit pas deviner.
 - Le statut de la campagne n'est pas filtré (lecture seule, aussi utile pour une estimation).
4. **Membres de la cible.** Pagination par clé, `cle > :apres ORDER BY cle LIMIT :taille`. Une référence numérique suit la règle `ctype_digit && strlen < 18` (`AssistantCreation.php:257`). Une référence non valide ou un objet absent donnent `cibleIntrouvable` ; un type inconnu (« personnes » compris) donne aussi `cibleIntrouvable`.
 - **liste.** Contrôle d'existence : `SELECT 1 FROM listes_manuelles WHERE workspace_id=:ws AND id=:ref AND deleted_at IS NULL`. Puis :
   ```sql
   SELECT m.id AS cle, m.contact_id, m.company_id,
     (m.company_id IS NOT NULL AND EXISTS (SELECT 1 FROM listes_manuelles_membres m2 JOIN contacts c2 ON c2.id = m2.contact_id
        WHERE m2.liste_id = m.liste_id AND m2.workspace_id = m.workspace_id AND m2.retire_le IS NULL
          AND c2.workspace_id = m.workspace_id AND c2.company_id = m.company_id)) AS couverte
   FROM listes_manuelles_membres m
   WHERE m.workspace_id = :ws AND m.liste_id = :ref AND m.retire_le IS NULL AND m.id > :apres
   ORDER BY m.id LIMIT :taille
   ```
   Le schéma impose exactement un des deux, `company_id` ou `contact_id` : `2026_10_01_000040…:79-91`.
 - **audience.** Contrôle d'existence : `email_audiences WHERE workspace_id=:ws AND id=:ref AND deleted_at IS NULL AND is_active`. Puis `audience_members m WHERE m.workspace_id=:ws AND m.audience_id=:ref AND m.id > :apres`, avec un drapeau `couverte` construit de la même façon (un autre membre de la même audience a un `contact_id` pour ce `company_id`). `contact_id` peut être NULL : schéma `2026_05_18_000008…:62-70`.
 - **etiquette.** `tag = SELECT id FROM tags WHERE workspace_id=:ws AND slug=:ref`, puis :
   `SELECT ct.company_id AS cle FROM company_tag ct JOIN companies o ON o.id = ct.company_id AND o.workspace_id = :ws WHERE ct.tag_id = :tag AND ct.company_id > :apres ORDER BY ct.company_id LIMIT :taille`.
   Le filtre `o.workspace_id` est OBLIGATOIRE : `company_tag` n'a pas de colonne d'espace (`2026_05_16_000003…:141-145`), et le code actuel l'oublie (`AssistantCreation.php:321-323`).
 - **carnet.** `SELECT id AS cle FROM companies WHERE workspace_id=:ws AND id=:ref AND id > :apres` : un seul membre.
 - Un membre qui porte `contact_id` est un **contact ciblé**. Un membre qui ne porte que `company_id` (ou étiquette, ou carnet) est une **entreprise**. Une entreprise `couverte` est comptée `entreprise_deja_couverte` et sautée.
5. **Lecture des contacts du lot.** Deux requêtes, jamais une par ligne.
 - Contacts ciblés :
   ```sql
   SELECT c.id, c.company_id, c.email::text AS email, (c.deleted_at IS NOT NULL) AS c_supprime,
          o.id AS o_id, o.siren, (o.deleted_at IS NOT NULL) AS o_supprimee,
          <GardePresse::estContactPresseSql('c')> AS contact_presse, NOT <GardePresse::conditionSql('o.id')> AS fiche_presse
   FROM contacts c LEFT JOIN companies o ON o.id = c.company_id AND o.workspace_id = c.workspace_id
   WHERE c.workspace_id = :ws AND c.id = ANY(:ids)
   ```
   Un identifiant absent (autre espace) est compté `fiche_supprimee`. `o_id` NULL donne `sans_entreprise`. Une adresse vide ou NULL donne `sans_adresse`. `c_supprime` ou `o_supprimee` donnent `fiche_supprimee`. Une adresse de contact ciblé de type `generique` donne `generique_cible` (**hypothèse à valider par l'architecte :** fermé tant que le fondateur n'a pas répondu à la question E7).
 - Entreprises (règle E7) :
   ```sql
   SELECT o.id AS entreprise_id, o.siren, (o.deleted_at IS NOT NULL) AS o_supprimee,
          NOT <GardePresse::conditionSql('o.id')> AS fiche_presse,
          (o.email_generic IS NOT NULL AND btrim(o.email_generic) <> '') AS a_generique_fiche,
          k.id AS contact_id, k.email, k.contact_presse
   FROM companies o
   LEFT JOIN LATERAL (
      SELECT c.id, c.email::text AS email, <GardePresse::estContactPresseSql('c')> AS contact_presse
      FROM contacts c
      WHERE c.workspace_id = :ws AND c.company_id = o.id AND c.deleted_at IS NULL
        AND c.email IS NOT NULL AND btrim(c.email::text) <> ''
      ORDER BY c.id LIMIT 20) k ON true
   WHERE o.workspace_id = :ws AND o.id = ANY(:entreprises)
   ORDER BY o.id, k.id
   ```
   Cette requête s'appuie sur `idx_contacts_company` (`2026_05_16_000003…:98`).
6. **Exclusions**, chacune lue en UNE requête pour tout le lot. Toute exception de l'une d'elles annule le lot entier : `lectureOppositionImpossible`, aucun destinataire rendu (règle « fermé en cas d'erreur »).
 - **Opposition :** `$lecteurOpposition($ws, [cle => ['email' => brute, 'siren' => siren]])` via `StatutOpposition::pourLignes` (`StatutOpposition.php:33`). Une clé absente de la réponse compte comme opposée.
 - **E6 :** `SELECT DISTINCT lower(subject_email::text) FROM rgpd_requests WHERE workspace_id = :ws AND status IN ('pending','processing') AND subject_email = ANY(:formes)`. `:formes` = `FormesAdresse::variantes()` de chaque adresse. Tous types confondus. Schéma : `2026_05_16_000006…:72-87`, `subject_email` en CITEXT.
 - **Presse :** colonnes `contact_presse` / `fiche_presse` ci-dessus.
 - **Clients de la console :** `GardeConsoleClient::exclues($ws, adresses normalisées par QualificationEmail::normaliser)`.
 - **Déjà en séquence :** trois requêtes indexées plutôt qu'un `OR`.
   - `SELECT contact_id FROM envoi_inscriptions WHERE workspace_id=:ws AND statut='active' AND contact_id = ANY(:contacts)` ;
   - `… AND empreinte_adresse = ANY(:empreintes)` ;
   - pour les entreprises (E7) seulement : `… AND entreprise_id = ANY(:entreprises)`. Une entreprise qui a déjà une inscription active est comptée entière `deja_en_sequence` : aucun autre de ses contacts n'est pris (hypothèse prudente, à valider).
7. **Choix.**
 - Contact ciblé : premier motif dans l'ordre de `PRIORITE`, sinon il est retenu.
 - Entreprise :
   - fiche supprimée : `fiche_supprimee` ;
   - fiche de presse : `presse`, sauf si son SIREN est opposé (alors `opposition`) ;
   - sinon, on garde les contacts non-presse dont `QualificationEmail::type(email) === 'nominatif'` ;
   - aucun nominatif : `sans_contact`, et `+1` à `sans_contact_generique_disponible` s'il existe un contact générique ou si `a_generique_fiche` est vrai. Un générique n'est JAMAIS choisi ;
   - sinon, on retient le **plus petit `contact_id`** sans motif ;
   - si tous les nominatifs sont exclus : le motif le plus prioritaire rencontré parmi eux.
 - Puis dédoublonnage par `empreinte` dans le lot : la seconde occurrence est comptée `doublon`. L'unicité d'un lot à l'autre est assurée par le « déjà en séquence », une fois que L4 a inscrit.
8. `curseurSuivant` = dernière clé lue si le lot est plein, sinon `null`.

## À ne pas toucher (L2)
- `backend/app/Crm/Opposition/**`, dont `StatutOpposition.php` : c'est la session P0 qui les modifie.
- `DepotCampagnes.php` (L3), `AssistantCreation.php` (L8), `LecteurFicheDb.php`, `Inscriptions.php`, `Sequenceur.php`, `ResolveurDestinataires.php`.
- `config/envoi.php`, `ENVOI_REEL_ACTIF` et tous les drapeaux.
- `backend/app/Providers/EnvoiServiceProvider.php` : L2 ne le modifie PAS (résolution automatique par le conteneur).
- Les routes, les instantanés `tests/Feature/Envoi/Routes/instantane-routes-envoi/*`, `phpstan-baseline` : s'il faut y toucher, ESCALADE.

## Tests (écrits AVANT, vus rouges, sortie rouge recopiée dans X1-L2.md)
`backend/tests/Feature/Envoi/SelectionDestinatairesTest.php`, `uses(TestCase::class, RefreshDatabase::class)`, préfixe de fonctions `sdx`. Chaque test tourne dans les modes `['app','proprio']`, selon le patron de `SchemaLienCampagneSequenceTest.php:77-95` : `WorkspaceContext::set` puis `SET LOCAL ROLE "<pgsql_app.username>"` / `RESET ROLE`. On crée les campagnes avec `app(DepotCampagnes::class)->creer(...)` et les fiches comme dans `CreationOpposeesTest.php`. L'invariant `membres = retenus + somme` est vérifié à chaque test.
1. `personnes` et `xyz` : refus `population_non_ouverte` avec 0 requête (`DB::listen`).
2. Liste mixte (contact + entreprise) : forme typée exacte (`population`, `personneId` null) ; `empreinte === ListeSuppression::empreinte(adresse brute)` pour `"  Jeanne.MARTIN@Exemple.example.test "`.
3. E7 :
 - générique au plus petit id + nominatif : on prend le nominatif ;
 - deux nominatifs : le plus petit id ;
 - générique seul, ou `email_generic` de la fiche seul : `sans_contact` + `sans_contact_generique_disponible` ;
 - aucun contact : `sans_contact` ;
 - plus petit nominatif opposé : on prend le suivant.
4. Priorité à l'opposition : un contact à la fois opposé (`envoi_oppositions`) et de presse est compté `opposition` ; un SIREN au registre exclut l'entreprise en `opposition`.
5. Lecteur d'opposition qui lève une exception : `SelectionRefusee` `lecture_opposition_impossible`, aucun destinataire.
6. E6 : `pending` / `processing` excluent, pour les 5 types ; `done`, `rejected` et `expired` de type `access` n'excluent pas ; une demande d'un autre espace n'exclut pas.
7. Doublon : deux contacts portant la même adresse à la casse près : un seul retenu, `doublon` = 1.
8. Déjà en séquence : inscription `active` sur le contact ou sur l'empreinte : exclu ; `arretee` ou `terminee` : non exclu ; entreprise avec une inscription active d'un autre contact : `deja_en_sequence`.
9. Contact de presse, fiche de presse, client de la console (`discovery_source='console'`, étiquette `src:console-client`) : exclus.
10. Les quatre types de cible :
  - liste : un membre `retire_le` ignoré, une liste à la corbeille donne `cible_introuvable` ;
  - audience : inactive ou supprimée donne introuvable ; une ligne `contact_id` NULL suit E7 ; `entreprise_deja_couverte` ;
  - etiquette : une fiche d'un autre espace portant la même étiquette n'est jamais lue ; un slug d'un autre espace donne introuvable ;
  - carnet d'un autre espace : introuvable.
11. Curseur : 5 membres, `taille` 2 : 3 lots, curseurs croissants ; l'union des lots = un lot de taille 500 ; mêmes compteurs une fois additionnés. `taille` 0 et 501 sont refusées.
12. Cloisonnement : une campagne d'un autre espace donne `campagne_introuvable`.
13. Lecture seule : `DB::listen` ne voit aucun `INSERT`, `UPDATE` ni `DELETE` ; `SET LOCAL statement_timeout` est émis avant le premier `SELECT`.
14. `saisie.contenu.cible.contact_ids` non vide : `cible_non_supportee`.

**Mutations à prouver** (casser, voir rouge, restaurer ; tableau dans X1-L2.md) :
| Mutation | Test qui rougit |
|---|---|
| ordre de PRIORITE (opposition après presse) | 4 |
| `max` au lieu de `min` | 3 |
| génériques acceptés | 3 |
| `processing` retiré de E6 | 6 |
| filtre d'espace de E6 retiré | 6 |
| `o.workspace_id` retiré de l'étiquette | 10 |
| repli « non opposée » sur erreur de lecture | 5 |
| dédoublonnage retiré | 7 |
| `READ ONLY` ou `statement_timeout` retiré | 13 |

## Erreurs attendues
`SelectionRefusee`, motifs dans une liste fermée : `population_non_ouverte`, `campagne_introuvable`, `cible_introuvable`, `cible_non_supportee`, `taille_invalide` (non réessayables) ; `lecture_opposition_impossible`, `lecture_trop_longue` (réessayables). Jamais de `QueryException` brute : `57014` est convertie, le reste remonte enveloppé dans `lecture_opposition_impossible`.

---



# Annexe : constats sur l'existant
## Constats sur l'existant qui changent la conception (à lire avant les cahiers)
1. **Le dépôt ne peut pas écrire `sequence_id`, et un test le vérifie.** L'en-tête de `DepotCampagnes.php:12-14` le dit. `CampagnesModeleDbTest.php:277-278` lit le code source et refuse `'sequence_id' =>`. L3 doit donc modifier ce test : il n'y a pas d'autre moyen propre.
2. **Toute modification de la campagne doit monter la version de 1.** La garde l'impose (`2026_12_04_000030…:64-66`). Les champs se lient ainsi : `lierSequence` écrit `sequence_id`, `version+1` et `maj_le`, toujours avec la clause `WHERE version = ?`. Les droits le permettent : `000080…:104` et `000030…:163`.
3. **La matrice des transitions ne doit pas être modifiée.** La base garde `programmee→a_valider` dans `envoi_campagnes_transition_permise` (`000080…:56-62`). Le test des 49 couples (`CampagnesModeleDbTest.php:114-130`) compare cette fonction à `DepotCampagnes::TRANSITIONS` en tenant déjà compte de l'exception. `ValidationCampagne::annuler` (`ValidationCampagne.php:104-123`) a encore besoin de `programmee→a_valider` pendant 5 minutes sur une campagne non reliée.
 - On aligne donc **dynamiquement** dans `transitionner()` : refus quand `sequence_id !== null`. La constante reste telle quelle.
4. **Il faut une garde que l'arbitrage n'a pas prévue (amendement à valider).** Sans elle, une construction lancée moins de 5 minutes après la validation rendrait l'annulation impossible : la base refuserait avec une `P0001` brute, car `ValidationCampagne.php:143-153` n'attrape que `CampagneRefusee`. Je propose que L3 refuse de construire tant que la fenêtre d'annulation n'est pas close, plus une minute de marge, lue dans `envoi_campagnes_journal` à l'heure de la base.
5. **Il n'existe pas de « contact générique » dans le résolveur actuel.** `ResolveurDestinataires.php:453-468` range toute ligne `contacts` en nominative. Le générique, c'est `companies.email_generic`, sans `contact_id`, donc impossible à inscrire : `envoi_inscriptions.contact_id` est NOT NULL (`2026_11_10_000031…:22`). L2 classe chaque adresse de contact avec `QualificationEmail::type()` (`QualificationEmail.php:114-121`).
6. **Les deux gardes demandées existent déjà.** Presse : `GardePresse::estContactPresseSql` / `conditionSql` (`GardePresse.php:79-83, 115-119`), déjà utilisées dans `LecteurFicheDb.php:45-46, 67-71`. Clients de la console : `GardeConsoleClient::exclues` (`GardeConsoleClient.php:37`), qui doit tourner dans le contexte d'espace.
7. **L'empreinte d'adresse.** Elle vaut `ListeSuppression::empreinte()` = `hash('sha256', mb_strtolower(trim($email)))` (`ListeSuppression.php:47-49`). `Inscriptions.php:43` l'applique à l'adresse BRUTE du contact. L2 doit faire exactement pareil, jamais via la fonction SQL `empreinte_adresse_sql`.
8. **Il manque un index pour les inscriptions actives par empreinte.** Il n'y a pas d'index `envoi_inscriptions (workspace_id, empreinte_adresse) WHERE statut='active'` (seulement l'index par contact, `000040…:28`, et par entreprise, `2026_11_10_000031…:42`). L2 n'a pas le droit d'ajouter de migration ; c'est sans conséquence au volume actuel. **ESCALADE (mineure, données) → eq-architecte-systeme :** il faudra un index partiel, et même UNIQUE (« une seule inscription active par adresse »), avant que L4 écrive en volume. Cette unicité n'existe nulle part en base aujourd'hui.
9. **Dépendance au lot P0.** Tant que P0 n'est pas fusionné, `StatutOpposition` ne lit pas `personnes.opposition_at`. L2 l'utilise tel quel, mais L4 ne doit pas être activé avant P0.

---


## Fichiers communs L2 / L3
**Aucun.** L2 ne crée que `SelectionDestinataires`, `Destinataire`, `LotDestinataires`, `SelectionRefusee`, son test et `X1-L2.md`. L3 crée `PlanSequence`, `ConstructionSequence`, `ConstructionRefusee`, `ResultatConstruction`, son test et son script, `X1-L3.md`, et modifie `DepotCampagnes.php` et `CampagnesModeleDbTest.php`. Les deux lots peuvent partir en parallèle. Si l'un a besoin de toucher `phpstan-baseline` ou une fiche d'avancement : L2 passe en premier, L3 rebase.



# Arbitrages du pilotage (16/11/2026)
- Base : origin/main 11bce50b (le lot P0 « opposition des personnes » et le correctif des droits de l'action de masse sont fusionnés). Les numéros de ligne cités ont été relevés sur a79d75c2 : vérifier chaque ligne avant de s'y fier.
- Hypothèses fermées du cahier : toutes RETENUES telles quelles (contact ciblé générique refusé, entreprise déjà en séquence exclue entière, audience inactive introuvable, contact_ids dans la saisie refusé, délai en jours ouvrés, heures avec minutes refusées, garde de la fenêtre d'annulation).
- L'index unique « une seule inscription active par adresse » fera l'objet d'un lot de migration séparé, avant L4 : ne pas l'ajouter ici.
- Ce fichier de cahier reste dans la PR (dossier docs/envoi/lots/).
