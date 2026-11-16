# CAHIER 2 — LOT X1-L3 « ConstructionSequence + DepotCampagnes::lierSequence »

**Pour le développeur :** branche depuis `origin/main` (a79d75c2, qui contient déjà X1-L1). Aucune migration. Aucune inscription, aucune ligne de matérialisation, aucun envoi. Mêmes règles sur le dépôt public (« le fondateur », `*.example.test`).

## Fichiers
- **Créer :**
- `backend/app/Envoi/Campagnes/PlanSequence.php` (pur, sans base) ;
- `backend/app/Envoi/Campagnes/ConstructionSequence.php` ;
- `backend/app/Envoi/Campagnes/ConstructionRefusee.php` ;
- `backend/app/Envoi/Campagnes/ResultatConstruction.php` ;
- `backend/tests/Feature/Envoi/ConstructionSequenceTest.php` ;
- `backend/tests/Support/construction-sequence-concurrente.php` ;
- `docs/envoi/lots/X1-L3.md`.
- **Modifier :**
- `backend/app/Envoi/Campagnes/DepotCampagnes.php` : en-tête (lignes 12-14), `transitionner()` (lignes 143-145), nouvelle méthode `lierSequence`. `TRANSITIONS` (lignes 19-27) reste INCHANGÉE ; la raison est au constat 3.
- `backend/tests/Feature/Envoi/CampagnesModeleDbTest.php` : lignes 276-278 seulement.

## Ce que la saisie fournit
`saisie.contenu.sequence` est une liste de `{etape: i+1, message_id: uuid, delai_jours: int ≥ 0, objet_b?}` (`AssistantCreation.php:170-184`, `nb_etapes = count` à la ligne 100). On y trouve aussi `saisie.contenu.jours` (sous-ensemble de lun…dim), `saisie.contenu.heures {de:'HH:MM', a:'HH:MM'}`, `depart_immediat` et `debut_le` (lignes 24 et 187-196). `objet_b` est lu à `ValidationCampagne.php:187`.

L'objet et le corps viennent de **la version validée** du message type : `envoi_modeles.statut='valide'` et `version_validee`, dans `envoi_modele_versions(objet, corps)` (`2026_12_02_000082…:22-51`). Attention : une nouvelle version remet le message en `brouillon` et `version_validee` à NULL (`DepotModeles.php:107-108`).

## `PlanSequence` (pur)
```php
final class PlanSequence {
  /** @param list<array{rang:int, message_id:string, delai:int}> $etapes */
  private function __construct(public readonly int $fenetreDebut, public readonly int $fenetreFin, public readonly array $etapes) {}
  /** @throws ConstructionRefusee */
  public static function depuisCampagne(\stdClass $campagne): self;
}
```
Contrôles, dans cet ordre (le premier qui échoue donne le motif) :
1. `contenu.jours` absent ou null : lun–ven. Présent : l'ensemble trié doit être EXACTEMENT {lun, mar, mer, jeu, ven} ; sinon `jours_non_applicables` (E8).
2. Un `objet_b` non vide dans N'IMPORTE QUELLE étape : `objet_b_non_applicable` (E8).
3. `sequence` absente, pas une liste, 0 ou plus de 3 éléments, `etape` ≠ i+1, `message_id` non UUID, `delai_jours` non entier ou hors 0..60 : `etapes_invalides`. Les bornes de la table : `rang` 1..3 et `delai_jours_ouvres` 0..60 (`2026_11_10_000030…:34-35`).
4. `count(sequence) !== nb_etapes` : `sequence_incoherente`.
5. `heures` absentes : 9 et 17 (valeurs par défaut de la table, `2026_11_10_000030…:20-21`). Présentes : `de` et `a` en « HH:00 » seulement, 0 ≤ de < a ≤ 24 ; sinon `etapes_invalides`. Aucun arrondi.
6. `boites` et `plafond_jour` sont ignorés : c'est le lancement (L5) qui les refuse.

**À TRANCHER par l'architecte (sans bloquer) :** `delai_jours` de l'assistant est copié tel quel dans `delai_jours_ouvres`. Le séquenceur compte en jours ouvrés (`FenetreHoraire.php:64-67`, `JoursOuvres`) ; le libellé de l'écran sera à corriger en L8.

## `ConstructionRefusee` et `ResultatConstruction`
```php
final class ConstructionRefusee extends \RuntimeException {
  public const BLOQUANTS = ['jours_non_applicables','objet_b_non_applicable','etapes_invalides','sequence_incoherente']; // = motifs autorisés en matérialisation (000020:30-31)
  public function __construct(public readonly string $motif, public readonly bool $reessayable, string $message, ?\Throwable $prev = null) {}
  public function motifMaterialisation(): ?string { return in_array($this->motif, self::BLOQUANTS, true) ? $this->motif : null; }
}
final class ResultatConstruction { public function __construct(public readonly string $sequenceId, public readonly int $versionCampagne, public readonly bool $dejaFaite) {} }
```
Motifs non bloquants : `introuvable`, `statut_non_programmee`, `validation_introuvable` (non réessayables) ; `fenetre_annulation_ouverte`, `concurrence` (réessayables) ; `erreur_base` (non réessayable, garde l'exception d'origine).

## `DepotCampagnes` (modifications exactes)
- **En-tête :** « une seule méthode écrit `sequence_id` : `lierSequence`, appelée par `ConstructionSequence` sous `sousVerrou` ».
- **`transitionner()`, ligne 143 :** remplacer la condition par
`if (! self::autorisee($c->statut, $vers) || ($c->sequence_id !== null && $c->statut === 'programmee' && $vers === 'a_valider'))` → `CampagneRefusee::transition(...)`.
`TRANSITIONS` ne change pas : le test des 49 couples (`CampagnesModeleDbTest.php:114-130`) reste vrai.
- **Nouvelle méthode :**
```php
/** Pose le lien UNE fois. À appeler DANS une transaction ouverte par sousVerrou($ws, $campagneId, …). @return int nouvelle version */
public function lierSequence(string $ws, string $campagneId, int $version, string $sequenceId): int
{
  if (DB::transactionLevel() === 0) { throw new \LogicException('lierSequence exige la transaction de sousVerrou'); }
  $n = DB::table('envoi_campagnes')->where('workspace_id', $ws)->where('id', $campagneId)->where('version', $version)
      ->where('statut', 'programmee')->whereNull('sequence_id')
      ->update(['sequence_id' => $sequenceId, 'version' => $version + 1, 'maj_le' => now()]);
  if ($n !== 1) { throw CampagneRefusee::versionPerimee(); }
  return $version + 1;
}
```
La garde de la base (`000030…:70-95`) vérifie en plus que la séquence est celle de la campagne, du même espace, et que son nombre d'étapes égale `nb_etapes`. Sinon : `P0001`.

## `ConstructionSequence`
```php
final class ConstructionSequence {
  public const ESSAIS_MAX = 3;
  public const MARGE_ANNULATION_MINUTES = 1;
  public function __construct(private readonly DepotCampagnes $depot, private readonly ServiceMessagesTypes $messages) {}
  public function construire(string $ws, string $campagneId): ResultatConstruction;
  /** @template T @param \Closure(): T $f @return T  — public pour le test de relance */
  public static function relancer(\Closure $f, int $essais = self::ESSAIS_MAX): mixed;
}
```
**`relancer` :**
- `QueryException` de code `40P01` ou `40001`, avec `DB::transactionLevel() === 0`, et essais restants : attente aléatoire de 20 à 120 ms, puis nouvel essai complet.
- Après le dernier essai, et pour `55P03` / `57014` / `23505` : `ConstructionRefusee('concurrence', true)`.
- `P0001` : `sequence_incoherente`.
- Autre code : `erreur_base`.
- **Jamais de `QueryException` brute vers L4.** Dans une transaction englobante (les tests sous `RefreshDatabase`), pas de relance : conversion directe.

**`construire`** = `relancer(fn () => $this->depot->sousVerrou($ws, $campagneId, fn () => $this->sousVerrou($ws, $campagneId)))`. **Ordre des verrous :**
1. Verrou consultatif de l'espace `envoi_campagnes_espace:<ws>`, puis celui de la campagne `envoi_campagne:<id>`, tous deux posés par `DepotCampagnes::sousVerrou` (`DepotCampagnes.php:65-75`). C'est le même verrou que `ValidationCampagne::annuler` : la course annulation contre liaison est donc sérialisée.
2. `SET LOCAL lock_timeout = '5s'` ; `SET LOCAL statement_timeout = '10000'`.
3. `SELECT * FROM envoi_campagnes WHERE workspace_id=? AND id=? FOR UPDATE`. Absente : `introuvable`.
4. Si `sequence_id` n'est pas NULL : on vérifie `SELECT campagne_id FROM envoi_sequences WHERE workspace_id=? AND id=?` ; égal à la campagne : `ResultatConstruction(dejaFaite: true)` sans aucune écriture ; sinon `sequence_incoherente`.
5. Statut ≠ `programmee` : `statut_non_programmee`.
6. Fenêtre d'annulation :
 `SELECT max(cree_le) AS v, now() >= max(cree_le) + make_interval(mins => :m) AS close FROM envoi_campagnes_journal WHERE workspace_id=? AND campagne_id=? AND transition='a_valider>programmee'`
 avec `:m` = `ValidationCampagne::ANNULATION_MINUTES + MARGE_ANNULATION_MINUTES`. Si `v` est NULL : `validation_introuvable`. Si la fenêtre n'est pas close : `fenetre_annulation_ouverte` (réessayable).
7. `PlanSequence::depuisCampagne($c)` : les refus E8 et autres n'écrivent RIEN.
8. Messages types, par `message_id` en ordre croissant (ordre de verrou fixe) :
 - `SELECT statut, version_validee FROM envoi_modeles WHERE workspace_id=? AND id=? FOR SHARE` : il faut `statut='valide'` et `version_validee` non NULL ;
 - puis `SELECT objet, corps FROM envoi_modele_versions WHERE workspace_id=? AND modele_id=? AND numero=?` ;
 - puis `$this->messages->motifs($objet, $corps) === []` (`ServiceMessagesTypes.php:39-44`).
 - Tout échec : `etapes_invalides`.
9. Reprise : `SELECT id, fenetre_debut, fenetre_fin FROM envoi_sequences WHERE workspace_id=? AND campagne_id=?` (clé de reprise = l'index unique partiel `envoi_sequences_campagne_unique`, `000010…:34`). Si elle existe : on compare les fenêtres et les étapes `(rang, delai_jours_ouvres, sujet, corps)` au plan. Identiques : on réutilise. Différentes : `sequence_incoherente`. Jamais d'UPDATE ni de DELETE.
10. Sinon :
  - `INSERT INTO envoi_sequences (id, workspace_id, nom, fenetre_debut, fenetre_fin, campagne_id)`, avec `nom` = nom de la campagne. Les déclencheurs vérifient la campagne (`FOR SHARE`) et posent le geste `pause` / `campagne:naissance` (`000010…:36-52, 68-80`) ;
  - puis `INSERT INTO envoi_sequence_etapes (workspace_id, sequence_id, rang, delai_jours_ouvres, sujet, corps)` pour les rangs 1..n (le déclencheur `FOR NO KEY UPDATE` sur la séquence : `000010…:82-106`).
11. `$this->depot->lierSequence($ws, $id, (int) $c->version, $sequenceId)` : la garde lit la séquence `FOR SHARE` et compte les étapes.
12. Rendre `ResultatConstruction($sequenceId, $nouvelleVersion, false)`.

**Hypothèse :** niveau d'isolation READ COMMITTED (`X1-L1.md`, « Hypothèses », F2). Ne jamais poser REPEATABLE READ ici.

**Ce qu'il ne faut PAS faire en L3 :**
- aucune `envoi_inscriptions` (c'est L4) ;
- aucune ligne `envoi_campagnes_materialisations` (L4) ;
- aucun passage à `en_cours` (L5) ;
- aucun geste `reprise`.

## Modification de `CampagnesModeleDbTest.php:276-278`
On remplace la vérification « jamais `'sequence_id' =>` » par : exactement UNE occurrence de `'sequence_id' =>` dans `DepotCampagnes.php`, située entre `function lierSequence` et la `function` suivante, et toujours zéro occurrence de `['sequence_id']`. Le reste du test (42501 / P0001) ne change pas.

## Tests (écrits AVANT, vus rouges)
`backend/tests/Feature/Envoi/ConstructionSequenceTest.php`, préfixe `cns`. Modes `['app','proprio']` comme dans `SchemaLienCampagneSequenceTest.php:77-95`.

Aide `cnsProgrammee($ws, $saisie, $nb, $ilYa = '10 minutes')` :
- crée les messages par `ServiceMessagesTypes::creer` puis `valider` ;
- crée la campagne par `DepotCampagnes::creer`, puis la fait passer `a_valider` par `transitionner` ;
- la met `programmee` par UPDATE en propriétaire (`version+1`) ;
- insère la ligne de journal `a_valider>programmee` avec `cree_le = now() - interval $ilYa`. Le journal accepte une date fournie ; il refuse seulement une ligne qui ne correspond pas à l'état (`2026_12_02_000081…:45-57`).

1. **Cas nominal** :
 - séquence créée avec `campagne_id`, le nom et les fenêtres attendus ;
 - étapes = texte de la version validée ; `delai_jours_ouvres` = `delai_jours` ;
 - `sequence_id` posé, version +1 ;
 - exactement un geste `pause` / `campagne:naissance` ;
 - 0 `envoi_inscriptions`, 0 matérialisation.
2. **Idempotence :** un second appel rend `dejaFaite` vrai, le même identifiant, et les comptes (séquences, étapes, gestes, version) ne bougent pas.
3. **E8 :**
 - jours `[lun..ven, sam]` ou `[lun, mar]` : `jours_non_applicables` ; `motifMaterialisation()` vaut ce motif ;
 - `objet_b` à l'étape 2 : `objet_b_non_applicable` ;
 - les deux à la fois : `jours` l'emporte ;
 - dans chaque cas, AUCUNE écriture (0 séquence, version inchangée).
4. **`etapes_invalides` :** message en brouillon (nouvelle version après validation), message archivé, message d'un autre espace, `delai_jours` à 61, `heures` « 08:30 », contenu refusé par `motifs` (version insérée en propriétaire). `nb_etapes` ≠ `count` : `sequence_incoherente`.
5. **Statut** brouillon, `a_valider` ou `en_cours` : `statut_non_programmee`, rien n'est écrit.
6. **Fenêtre d'annulation :** journal à `now()` : `fenetre_annulation_ouverte` (réessayable) ; à -10 minutes : succès ; journal absent : `validation_introuvable`.
7. **Reprise :**
 - séquence préexistante avec ce `campagne_id` et des étapes identiques au plan (créée par `LienCampagneSequence::creerSequence`, puis étapes réécrites en propriétaire) : elle est reliée, sans seconde séquence ;
 - étapes différentes : `sequence_incoherente`, rien ne change.
8. **Transitions :** une fois la campagne reliée, `DepotCampagnes::transitionner(programmee→a_valider)` lève `CampagneRefusee` `transition_refusee` (pas de `QueryException`) ; `ValidationCampagne::annuler` répond `delai_annulation_depasse`. Une campagne programmée NON reliée revient toujours à `a_valider`.
9. **`lierSequence` :** hors transaction : `LogicException` ; mauvaise version : `version_perimee` ; second appel : `version_perimee` ; sous le rôle applicatif, ça passe.
10. **Relance :** une fermeture qui lève deux fois une `QueryException` `40P01` (`PDOException` anonyme avec `protected $code = '40P01'`), puis qui réussit : le résultat est rendu ; trois échecs : `ConstructionRefusee('concurrence', reessayable: true)` ; `P0001` : `sequence_incoherente`.
11. **Concurrence en processus réels.**
  - Script `tests/Support/construction-sequence-concurrente.php`, modèle `lien-campagne-concurrent.php` : en première instruction `require __DIR__ . '/garde_environnement_ouvrier.php'`, puis `DB::setDefaultConnection('pgsql_app')` et `set_config('app.current_workspace_id', …)`. Sorties : 0 = construite, 2 = déjà faite, 3 = `ConstructionRefusee`, 4 = autre.
  - Données insérées et validées par `pgsql_owner`, comme `SchemaLienCampagneSequenceTest.php:348-400`. Nettoyage en `try/finally`, avec `SET session_replication_role` LUI-MÊME entouré d'un `try/finally` (suivi de #595).
  - Trois `construire` simultanés : exactement un 0 et deux 2, une seule séquence, un seul geste de naissance.
  - Un `construire` contre un `GestesCampagne::changerDate` simultané : jamais de demi-état (séquence existante ⇒ `sequence_id` égal) ; le perdant reçoit `concurrence` ou `version_perimee`.

**Mutations à prouver :**
| Mutation | Test qui rougit |
|---|---|
| contrôle `jours` retiré | 3 |
| `objet_b` retiré | 3 |
| message non validé accepté | 4 |
| fenêtre d'annulation retirée | 6, 8 |
| retour anticipé `dejaFaite` retiré | 2 (P0001 → `sequence_incoherente`) |
| condition dynamique de `transitionner` retirée | 8 (`QueryException` P0001) |
| relance retirée | 10 |
| comparaison de reprise retirée | 7 |
| `FOR UPDATE` et verrou consultatif retirés | 11 (le doc dit honnêtement si l'index unique suffit à le garder vert) |

## À ne pas toucher (L3)
- Les migrations (aucune).
- `Sequenceur.php`, `Inscriptions.php`, `DepotSequences.php`, `GestesCampagne.php`, `ValidationCampagne.php`, `AssistantCreation.php` (L8), `LecteurFicheDb.php`, `backend/app/Crm/Opposition/**`.
- `config/envoi.php`, `ENVOI_REEL_ACTIF`, les drapeaux, les routes et leurs instantanés.
- **`EnvoiServiceProvider.php` :** des deux lots, L3 est le SEUL autorisé à y toucher, mais il ne devrait pas en avoir besoin (classes `final` résolues automatiquement ; `ServiceMessagesTypes` dépend de `DepotModeles`, aucune liaison n'existe dans `EnvoiServiceProvider.php:82-167`). L2 n'y touche pas.

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
