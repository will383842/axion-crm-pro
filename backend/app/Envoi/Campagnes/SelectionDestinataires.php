<?php

namespace App\Envoi\Campagnes;

use App\Crm\Campagnes\GardeConsoleClient;
use App\Crm\Campagnes\GardePresse;
use App\Crm\Emails\QualificationEmail;
use App\Crm\Opposition\FormesAdresse;
use App\Crm\Opposition\StatutOpposition;
use App\Crm\Origines\RequetesOrigines;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * X1-L2 — « qui reçoit cette campagne ? » : lit la cible d'une campagne, par lots à curseur, et rend les destinataires retenus
 * avec le compte de chaque exclusion. LECTURE SEULE : aucune écriture, aucune migration, aucune route.
 *
 *  - Transaction en lecture seule (REPEATABLE READ, READ ONLY si on est racine) et `statement_timeout` de 3 s ; le code 57014 devient
 *    `lectureTropLongue`. Modèle : `AssistantCreation::mesurer`.
 *  - FERMÉ EN CAS D'ERREUR : toute exception d'une lecture d'exclusion annule le lot (`lectureOppositionImpossible`) ; une adresse
 *    absente de la réponse du lecteur d'opposition compte comme opposée.
 *  - Entreprise (règle E7) : on retient le plus petit `contact_id` NOMINATIF sans motif d'exclusion. Un générique n'est JAMAIS choisi.
 *  - Ordre strict des motifs : PRIORITE. Le dédoublonnage par empreinte ne vaut que DANS le lot.
 *  - Aucune adresse n'est rendue : seulement des identifiants et l'empreinte.
 */
final class SelectionDestinataires
{
    public const POPULATIONS_OUVERTES = ['entreprises'];

    public const TAILLE_MAX = 500;

    public const CONTACTS_PAR_ENTREPRISE = 20;

    public const DELAI_SQL_MS = 3000;

    public const MOTIFS = ['opposition', 'rgpd_en_cours', 'presse', 'client_console', 'deja_en_sequence', 'doublon',
        'sans_contact', 'sans_adresse', 'sans_entreprise', 'fiche_supprimee', 'generique_cible', 'entreprise_deja_couverte', 'hors_reseau'];

    public const PRIORITE = ['opposition', 'rgpd_en_cours', 'presse', 'client_console', 'deja_en_sequence', 'doublon']; // ordre strict

    /** @param (\Closure(string, array<int|string, array{email: ?string, siren: ?string}>): array<int|string, array{opposee: bool, motifs: list<string>}>)|null $lecteurOpposition injectable pour les tests */
    public function __construct(private readonly ?\Closure $lecteurOpposition = null) {}

    public function lot(string $ws, string $campagneId, string $population, int $apres = 0, int $taille = 200): LotDestinataires
    {
        if (! in_array($population, self::POPULATIONS_OUVERTES, true)) {
            throw SelectionRefusee::populationNonOuverte(); // AVANT toute requête
        }
        if ($taille < 1 || $taille > self::TAILLE_MAX || $apres < 0) {
            throw SelectionRefusee::tailleInvalide();
        }
        $racine = DB::transactionLevel() === 0;
        try {
            return WorkspaceContext::run($ws, fn (): LotDestinataires => DB::transaction(function () use ($ws, $campagneId, $apres, $taille, $racine): LotDestinataires {
                if ($racine) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                }
                DB::statement("SET LOCAL statement_timeout = '" . self::DELAI_SQL_MS . "'");

                return $this->calculer($ws, $campagneId, $apres, $taille);
            }));
        } catch (QueryException $e) {
            throw $e->getCode() === '57014' ? SelectionRefusee::lectureTropLongue() : SelectionRefusee::lectureOppositionImpossible($e);
        }
    }

    private function calculer(string $ws, string $campagneId, int $apres, int $taille): LotDestinataires
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $campagneId) !== 1) {
            throw SelectionRefusee::campagneIntrouvable();
        }
        // `to_jsonb(k) -> 'filtre_origine'` (NULLIF : un NULL SQL devient le JSON `null`) : colonne absente (code déployé avant la migration) => null => pas de filtre, ce qui est exact
        $campagne = DB::selectOne("SELECT cible, saisie, NULLIF(to_jsonb(k) -> 'filtre_origine', 'null'::jsonb) AS filtre_origine, NULLIF(to_jsonb(k) -> 'filtre_origine_contenu', 'null'::jsonb) AS filtre_origine_contenu FROM envoi_campagnes k WHERE k.workspace_id = ? AND k.id = ?", [$ws, $campagneId]);
        if ($campagne === null) {
            throw SelectionRefusee::campagneIntrouvable();
        }
        $cible = json_decode((string) $campagne->cible, true);
        $saisie = json_decode((string) $campagne->saisie, true);
        $ids = is_array($saisie) && is_array($saisie['contenu'] ?? null) && is_array($saisie['contenu']['cible'] ?? null) ? ($saisie['contenu']['cible']['contact_ids'] ?? null) : null;
        if (is_array($ids) && $ids !== []) {
            throw SelectionRefusee::cibleNonSupportee(); // la colonne `cible` n'en garde pas trace : on ne devine pas
        }

        // Filtre « réseau d'origine » : il ne peut que RESTREINDRE. Forme invalide ou porte fermée = refus (jamais ignoré : ce serait élargir la cible).
        try {
            $filtre = FiltreOrigine::depuisColonne($campagne->filtre_origine);
            $contenu = FiltreOrigineContenu::depuisColonne($campagne->filtre_origine_contenu); // PONT-LINKEDIN PO-B : mots / guides (même porte)
        } catch (\InvalidArgumentException) {
            throw SelectionRefusee::filtreOrigineInactif();
        }
        if (($filtre !== null || $contenu !== null) && ! FiltreOrigine::actif($ws)) {
            throw SelectionRefusee::filtreOrigineInactif(); // AVANT toute lecture des membres
        }

        $membres = $this->membres($ws, is_array($cible) ? $cible : [], $apres, $taille);
        $curseur = count($membres) === $taille ? (int) $membres[$taille - 1]->cle : null;

        $compteurs = array_fill_keys(self::MOTIFS, 0) + ['membres' => count($membres), 'retenus' => 0, 'sans_contact_generique_disponible' => 0];
        $destinataires = $this->choisir($ws, $membres, $compteurs, $filtre, $contenu);
        $compteurs['retenus'] = count($destinataires);

        return new LotDestinataires($destinataires, $compteurs, $curseur);
    }

    /**
     * Membres de la cible : `cle > :apres ORDER BY cle LIMIT :taille`.
     *
     * @param  array<mixed>  $cible
     * @return list<\stdClass>
     */
    private function membres(string $ws, array $cible, int $apres, int $taille): array
    {
        $type = (string) ($cible['type'] ?? '');
        $ref = (string) ($cible['reference'] ?? '');
        $numerique = ctype_digit($ref) && strlen($ref) < 18;
        $introuvable = SelectionRefusee::cibleIntrouvable();
        if ($type !== 'etiquette' && ! $numerique) {
            throw $introuvable; // type inconnu (« personnes » compris) ou référence invalide : même refus
        }

        switch ($type) {
            case 'liste':
                if (DB::selectOne('SELECT 1 AS x FROM listes_manuelles WHERE workspace_id = ? AND id = ? AND deleted_at IS NULL', [$ws, (int) $ref]) === null) {
                    throw $introuvable;
                }
                $lignes = DB::select(
                    'SELECT m.id AS cle, m.contact_id, m.company_id,
                        (m.company_id IS NOT NULL AND EXISTS (SELECT 1 FROM listes_manuelles_membres m2 JOIN contacts c2 ON c2.id = m2.contact_id
                            WHERE m2.liste_id = m.liste_id AND m2.workspace_id = m.workspace_id AND m2.retire_le IS NULL
                              AND c2.workspace_id = m.workspace_id AND c2.company_id = m.company_id)) AS couverte
                       FROM listes_manuelles_membres m
                      WHERE m.workspace_id = ? AND m.liste_id = ? AND m.retire_le IS NULL AND m.id > ?
                      ORDER BY m.id LIMIT ?',
                    [$ws, (int) $ref, $apres, $taille],
                );
                break;
            case 'audience':
                if (DB::selectOne('SELECT 1 AS x FROM email_audiences WHERE workspace_id = ? AND id = ? AND deleted_at IS NULL AND is_active', [$ws, (int) $ref]) === null) {
                    throw $introuvable;
                }
                $lignes = DB::select(
                    'SELECT m.id AS cle, m.contact_id, m.company_id,
                        (m.contact_id IS NULL AND EXISTS (SELECT 1 FROM audience_members m2
                            WHERE m2.audience_id = m.audience_id AND m2.workspace_id = m.workspace_id AND m2.company_id = m.company_id AND m2.contact_id IS NOT NULL)) AS couverte
                       FROM audience_members m
                      WHERE m.workspace_id = ? AND m.audience_id = ? AND m.id > ?
                      ORDER BY m.id LIMIT ?',
                    [$ws, (int) $ref, $apres, $taille],
                );
                break;
            case 'etiquette':
                $tag = DB::selectOne('SELECT id FROM tags WHERE workspace_id = ? AND slug = ?', [$ws, $ref]);
                if ($tag === null) {
                    throw $introuvable;
                }
                // `company_tag` n'a pas de colonne d'espace : le filtre `o.workspace_id` est OBLIGATOIRE
                $lignes = DB::select(
                    'SELECT ct.company_id AS cle, NULL::bigint AS contact_id, ct.company_id, false AS couverte
                       FROM company_tag ct JOIN companies o ON o.id = ct.company_id AND o.workspace_id = ?
                      WHERE ct.tag_id = ? AND ct.company_id > ?
                      ORDER BY ct.company_id LIMIT ?',
                    [$ws, (int) $tag->id, $apres, $taille],
                );
                break;
            case 'carnet':
                if (DB::selectOne('SELECT 1 AS x FROM companies WHERE workspace_id = ? AND id = ?', [$ws, (int) $ref]) === null) {
                    throw $introuvable;
                }
                $lignes = DB::select(
                    'SELECT id AS cle, NULL::bigint AS contact_id, id AS company_id, false AS couverte FROM companies WHERE workspace_id = ? AND id = ? AND id > ? ORDER BY id LIMIT ?',
                    [$ws, (int) $ref, $apres, $taille],
                );
                break;
            default:
                throw $introuvable;
        }

        return array_values($lignes);
    }

    /**
     * @param  list<\stdClass>  $membres
     * @param  array<string, int>  $compteurs  modifié en place
     * @return list<Destinataire>
     */
    private function choisir(string $ws, array $membres, array &$compteurs, ?FiltreOrigine $filtre = null, ?FiltreOrigineContenu $contenu = null): array
    {
        $idsCibles = [];
        $idsEntreprises = [];
        foreach ($membres as $m) {
            if ($m->contact_id !== null) {
                $idsCibles[] = (int) $m->contact_id;
            } elseif (! self::vrai($m->couverte) && $m->company_id !== null) {
                $idsEntreprises[] = (int) $m->company_id;
            }
        }
        $cibles = $this->lireCibles($ws, $idsCibles);
        $entreprises = $this->lireEntreprises($ws, $idsEntreprises);

        // Unités à juger : une par membre. `candidats` = contacts à qui l'on pourrait écrire (un seul pour un contact ciblé).
        $unites = [];
        foreach ($membres as $m) {
            if ($m->contact_id !== null) {
                $unites[] = $this->uniteCiblee($m, $cibles[(int) $m->contact_id] ?? null, $compteurs);
            } elseif (self::vrai($m->couverte)) {
                $compteurs['entreprise_deja_couverte']++;
                $unites[] = null;
            } else {
                $unites[] = $this->uniteEntreprise($entreprises[(int) $m->company_id] ?? null, $compteurs);
            }
        }

        // Filtre d'origine (seulement s'il y en a un) : UNE lecture ; une unité hors réseau est comptée puis sautée, sans lecture d'exclusion.
        $opposes = [];
        if ($filtre !== null || $contenu !== null) {
            $opposes = $this->appliquerFiltre($ws, $unites, $compteurs, $filtre, $contenu);
        }

        // Exclusions : UNE lecture chacune pour tout le lot ; une exception annule le lot (fermé en cas d'erreur).
        $candidats = [];
        $sirens = [];
        foreach ($unites as $u) {
            foreach ($u['candidats'] ?? [] as $c) {
                $candidats[$c['contact_id']] = $c;
            }
            if (isset($u['entreprise_id'], $u['siren'])) {
                $sirens[$u['entreprise_id']] = $u['siren'];
            }
        }
        $ex = $this->lireExclusions($ws, $candidats, $sirens, $idsEntreprises);

        $retenus = [];
        $vus = [];
        foreach ($unites as $u) {
            if ($u === null || isset($u['motif_direct'])) {
                continue;
            }
            $choisi = null;
            $motifs = [];
            foreach ($u['candidats'] as $c) {
                $m = $this->motifPrioritaire($c, $u, $ex, isset($opposes[$c['contact_id']]));
                if ($m === null) {
                    $choisi = $choisi ?? $c; // candidats triés par contact_id : le premier sans motif est le plus petit
                } else {
                    $motifs[] = $m;
                }
            }
            if (($u['siren_oppose'] ?? false) || ($ex['entreprise_opposee'][$u['entreprise_id'] ?? 0] ?? false)) {
                $this->compter($compteurs, 'opposition');

                continue;
            }
            if ($u['fiche_presse'] ?? false) {
                $this->compter($compteurs, 'presse');

                continue;
            }
            if ($choisi === null) {
                if ($u['candidats'] === []) {
                    $this->compter($compteurs, 'sans_contact');
                    if ($u['generique_disponible']) {
                        $compteurs['sans_contact_generique_disponible']++;
                    }
                } else {
                    $this->compter($compteurs, self::plusPrioritaire($motifs));
                }

                continue;
            }
            $empreinte = ListeSuppression::empreinte($choisi['email']);
            if (isset($vus[$empreinte])) {
                $this->compter($compteurs, 'doublon');

                continue;
            }
            $vus[$empreinte] = true;
            $retenus[] = new Destinataire('entreprises', $choisi['contact_id'], $choisi['entreprise_id'], null, $empreinte);
        }

        return $retenus;
    }

    /**
     * Applique le filtre : marque `hors_reseau` (compteur) toute unité non tranchée qui n'est pas « du réseau » (R1/R2), en la remplaçant
     * par `null` dans `$unites` (elle n'ira pas aux lectures d'exclusion). Rend les contacts rattachés à une personne opposée (O2).
     * Toute exception de lecture => `lectureOrigineImpossible` (aucun destinataire) ; jamais « du réseau » par défaut.
     *
     * @param  list<array<string, mixed>|null>  $unites  modifié en place
     * @param  array<string, int>  $compteurs  modifié en place
     * @return array<int, true>
     */
    private function appliquerFiltre(string $ws, array &$unites, array &$compteurs, ?FiltreOrigine $filtre, ?FiltreOrigineContenu $contenu = null): array
    {
        $entreprises = [];
        $contacts = [];
        foreach ($unites as $u) {
            if ($u === null || isset($u['motif_direct'])) {
                continue;
            }
            $entreprises[] = (int) $u['entreprise_id'];
            if ($u['ciblee']) {
                $contacts[] = (int) $u['candidats'][0]['contact_id'];
            }
        }
        if ($entreprises === []) {
            return [];
        }
        try {
            $ents = array_values(array_unique($entreprises));
            $cts = array_values(array_unique($contacts));
            // Réseau seul : chemin L2b-C inchangé. Avec mot / guide : UNE lecture « même entrée » (PO-A) ; hors origine demandée = `hors_reseau`.
            $reseau = $contenu === null
                ? RequetesOrigines::reseauxDesUnites($ws, $ents, $cts, $filtre->reseaux ?? [])
                : RequetesOrigines::unitesParOrigine($ws, $ents, $cts, $filtre?->reseaux, $contenu->mots, $contenu->guides);
        } catch (QueryException $e) {
            throw $e->getCode() === '57014' ? SelectionRefusee::lectureTropLongue() : SelectionRefusee::lectureOrigineImpossible($e);
        } catch (\Throwable $e) {
            throw SelectionRefusee::lectureOrigineImpossible($e);
        }
        foreach ($unites as $i => $u) {
            if ($u === null || isset($u['motif_direct'])) {
                continue;
            }
            $dansReseau = $u['ciblee']
                ? isset($reseau['contacts'][(int) $u['candidats'][0]['contact_id']]) || isset($reseau['entreprises'][(int) $u['entreprise_id']])
                : isset($reseau['entreprises'][(int) $u['entreprise_id']]);
            if (! $dansReseau) {
                $compteurs['hors_reseau']++;
                $unites[$i] = null;
            }
        }

        return $reseau['contacts_opposes'];
    }

    /**
     * Contact ciblé : motifs de fiche (hors PRIORITE) tranchés tout de suite ; sinon une unité à un candidat.
     *
     * @param  array<string, int>  $compteurs
     * @return array<string, mixed>
     */
    private function uniteCiblee(\stdClass $m, ?\stdClass $c, array &$compteurs): array
    {
        $direct = static fn (string $motif): array => ['motif_direct' => $motif];
        $motif = match (true) {
            $c === null, self::vrai($c->c_supprime), self::vrai($c->o_supprimee) => 'fiche_supprimee',
            $c->o_id === null => 'sans_entreprise',
            trim((string) $c->email) === '' => 'sans_adresse',
            QualificationEmail::type((string) $c->email) === 'generique' => 'generique_cible',
            default => null,
        };
        if ($motif !== null) {
            $compteurs[$motif]++;

            return $direct($motif);
        }

        return ['entreprise_id' => (int) $c->o_id, 'siren' => $c->siren === null ? null : (string) $c->siren, 'ciblee' => true, 'siren_oppose' => false,
            'candidats' => [['contact_id' => (int) $c->id, 'entreprise_id' => (int) $c->o_id, 'email' => (string) $c->email, 'siren' => $c->siren === null ? null : (string) $c->siren,
                'presse' => self::vrai($c->contact_presse) || self::vrai($c->fiche_presse)]]];
    }

    /**
     * Entreprise (E7) : candidats = contacts NOMINATIFS de la fiche (jamais un générique).
     *
     * @param  array<string, mixed>|null  $e
     * @param  array<string, int>  $compteurs
     * @return array<string, mixed>
     */
    private function uniteEntreprise(?array $e, array &$compteurs): array
    {
        if ($e === null || $e['supprimee']) {
            $compteurs['fiche_supprimee']++;

            return ['motif_direct' => 'fiche_supprimee'];
        }
        $candidats = [];
        $generique = $e['a_generique_fiche'];
        foreach ($e['contacts'] as $k) {
            if (QualificationEmail::type($k['email']) !== 'nominatif') {
                $generique = true;

                continue;
            }
            $candidats[] = ['contact_id' => $k['contact_id'], 'entreprise_id' => $e['id'], 'email' => $k['email'], 'siren' => $e['siren'], 'presse' => $k['contact_presse'] || $e['fiche_presse']];
        }

        return ['entreprise_id' => $e['id'], 'siren' => $e['siren'], 'ciblee' => false, 'fiche_presse' => $e['fiche_presse'], 'candidats' => $candidats, 'generique_disponible' => $generique];
    }

    /**
     * Premier motif de PRIORITE (sauf « doublon », traité à part) qui s'applique à ce candidat.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $u
     * @param  array<string, mixed>  $ex
     * @param  bool  $personneOpposee  contact rattaché à une personne opposée (lecture des origines, chemin filtré seulement)
     */
    private function motifPrioritaire(array $c, array $u, array $ex, bool $personneOpposee = false): ?string
    {
        $id = $c['contact_id'];
        $formes = FormesAdresse::variantes($c['email']);
        $drapeaux = [
            'opposition' => $personneOpposee || ($ex['opposee']['c' . $id] ?? true), // absent de la réponse : opposée
            'rgpd_en_cours' => array_intersect($formes, array_keys($ex['rgpd'])) !== [],
            'presse' => $c['presse'],
            'client_console' => isset($ex['console'][QualificationEmail::normaliser($c['email'])]),
            'deja_en_sequence' => isset($ex['seq_contacts'][$id]) || isset($ex['seq_empreintes'][ListeSuppression::empreinte($c['email'])]) || (! $u['ciblee'] && isset($ex['seq_entreprises'][$c['entreprise_id']])),
        ];
        foreach (self::PRIORITE as $motif) {
            if ($motif !== 'doublon' && $drapeaux[$motif]) {
                return $motif;
            }
        }

        return null;
    }

    /** @param list<string> $motifs non vide */
    private static function plusPrioritaire(array $motifs): string
    {
        foreach (self::PRIORITE as $motif) {
            if (in_array($motif, $motifs, true)) {
                return $motif;
            }
        }

        return $motifs[0];
    }

    /** @param array<string, int> $compteurs */
    private function compter(array &$compteurs, string $motif): void
    {
        $compteurs[$motif]++;
    }

    /**
     * Contacts ciblés : UNE requête pour le lot.
     *
     * @param  list<int>  $ids
     * @return array<int, \stdClass>
     */
    private function lireCibles(string $ws, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $lignes = DB::select(
            'SELECT c.id, c.company_id, c.email::text AS email, (c.deleted_at IS NOT NULL) AS c_supprime,
                    o.id AS o_id, o.siren, (o.deleted_at IS NOT NULL) AS o_supprimee,
                    ' . GardePresse::estContactPresseSql('c') . ' AS contact_presse, NOT ' . GardePresse::conditionSql('o.id') . ' AS fiche_presse
               FROM contacts c LEFT JOIN companies o ON o.id = c.company_id AND o.workspace_id = c.workspace_id
              WHERE c.workspace_id = ? AND c.id = ANY(?::bigint[])',
            [$ws, self::tableau($ids)],
        );
        $par = [];
        foreach ($lignes as $l) {
            $par[(int) $l->id] = $l;
        }

        return $par;
    }

    /**
     * Entreprises du lot (règle E7) : UNE requête, au plus CONTACTS_PAR_ENTREPRISE contacts par fiche (index idx_contacts_company).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function lireEntreprises(string $ws, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $lignes = DB::select(
            'SELECT o.id AS entreprise_id, o.siren, (o.deleted_at IS NOT NULL) AS o_supprimee,
                    NOT ' . GardePresse::conditionSql('o.id') . ' AS fiche_presse,
                    (o.email_generic IS NOT NULL AND btrim(o.email_generic) <> \'\') AS a_generique_fiche,
                    k.id AS contact_id, k.email, k.contact_presse
               FROM companies o
               LEFT JOIN LATERAL (
                  SELECT c.id, c.email::text AS email, ' . GardePresse::estContactPresseSql('c') . ' AS contact_presse
                    FROM contacts c
                   WHERE c.workspace_id = ? AND c.company_id = o.id AND c.deleted_at IS NULL
                     AND c.email IS NOT NULL AND btrim(c.email::text) <> \'\'
                   ORDER BY c.id LIMIT ' . self::CONTACTS_PAR_ENTREPRISE . ') k ON true
              WHERE o.workspace_id = ? AND o.id = ANY(?::bigint[])
              ORDER BY o.id, k.id',
            [$ws, $ws, self::tableau(array_values(array_unique($ids)))],
        );
        $par = [];
        foreach ($lignes as $l) {
            $id = (int) $l->entreprise_id;
            $par[$id] ??= ['id' => $id, 'siren' => $l->siren === null ? null : (string) $l->siren, 'supprimee' => self::vrai($l->o_supprimee), 'fiche_presse' => self::vrai($l->fiche_presse),
                'a_generique_fiche' => self::vrai($l->a_generique_fiche), 'contacts' => []];
            if ($l->contact_id !== null) {
                $par[$id]['contacts'][] = ['contact_id' => (int) $l->contact_id, 'email' => (string) $l->email, 'contact_presse' => self::vrai($l->contact_presse)];
            }
        }

        return $par;
    }

    /**
     * Les cinq exclusions, chacune lue UNE fois. Toute exception => `SelectionRefusee` (aucun destinataire).
     *
     * @param  array<int, array<string, mixed>>  $candidats  contact_id => candidat
     * @param  array<int, string>  $sirens  entreprise_id => SIREN
     * @param  list<int>  $idsEntreprises
     * @return array<string, mixed>
     */
    private function lireExclusions(string $ws, array $candidats, array $sirens, array $idsEntreprises): array
    {
        try {
            $lignes = [];
            foreach ($candidats as $id => $c) {
                $lignes['c' . $id] = ['email' => $c['email'], 'siren' => $c['siren']];
            }
            foreach ($sirens as $id => $siren) {
                $lignes['o' . $id] = ['email' => null, 'siren' => $siren];
            }
            $reponse = $lignes === [] ? [] : ($this->lecteurOpposition ?? fn (string $w, array $l): array => (new StatutOpposition)->pourLignes($w, $l))($ws, $lignes);
            $opposee = [];
            $entrepriseOpposee = [];
            foreach (array_keys($lignes) as $cle) {
                $opposee[$cle] = (bool) ($reponse[$cle]['opposee'] ?? true); // clé absente : opposée
            }
            foreach (array_keys($sirens) as $id) {
                $entrepriseOpposee[$id] = $opposee['o' . $id];
            }

            $formes = [];
            $normalisees = [];
            $empreintes = [];
            foreach ($candidats as $c) {
                array_push($formes, ...FormesAdresse::variantes($c['email']));
                $normalisees[] = QualificationEmail::normaliser($c['email']);
                $empreintes[] = ListeSuppression::empreinte($c['email']);
            }
            $contacts = array_keys($candidats);

            $rgpd = [];
            if ($formes !== []) {
                foreach (DB::select(
                    "SELECT DISTINCT subject_email::text AS adresse FROM rgpd_requests WHERE workspace_id = ? AND status IN ('pending','processing') AND subject_email = ANY(?::citext[])",
                    [$ws, self::tableau(array_values(array_unique($formes)))],
                ) as $r) {
                    $rgpd[mb_strtolower((string) $r->adresse)] = true; // subject_email est CITEXT : la casse est déjà ignorée par la base
                }
            }
            $console = $normalisees === [] ? [] : GardeConsoleClient::exclues($ws, $normalisees);

            $seqContacts = $this->inscrits('contact_id', $ws, $contacts, 'bigint');
            $seqEmpreintes = $this->inscrits('empreinte_adresse', $ws, $empreintes, 'text');
            $seqEntreprises = $this->inscrits('entreprise_id', $ws, array_values(array_unique($idsEntreprises)), 'bigint');
        } catch (QueryException $e) {
            throw $e->getCode() === '57014' ? SelectionRefusee::lectureTropLongue() : SelectionRefusee::lectureOppositionImpossible($e);
        } catch (\Throwable $e) {
            throw SelectionRefusee::lectureOppositionImpossible($e);
        }

        return ['opposee' => $opposee, 'entreprise_opposee' => $entrepriseOpposee, 'rgpd' => $rgpd, 'console' => $console,
            'seq_contacts' => $seqContacts, 'seq_empreintes' => $seqEmpreintes, 'seq_entreprises' => $seqEntreprises];
    }

    /**
     * Inscriptions ACTIVES, une requête indexée par colonne (jamais un `OR`).
     *
     * @param  list<int|string>  $valeurs
     * @return array<int|string, true>
     */
    private function inscrits(string $colonne, string $ws, array $valeurs, string $type): array
    {
        if ($valeurs === []) {
            return [];
        }
        $sortie = [];
        foreach (DB::select("SELECT {$colonne} AS v FROM envoi_inscriptions WHERE workspace_id = ? AND statut = 'active' AND {$colonne} = ANY(?::{$type}[])", [$ws, self::tableau($valeurs)]) as $r) {
            $sortie[is_string($r->v) ? trim($r->v) : (int) $r->v] = true;
        }

        return $sortie;
    }

    /** @param list<int|string> $valeurs */
    private static function tableau(array $valeurs): string
    {
        return '{' . implode(',', array_map(static fn (int|string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"', $valeurs)) . '}';
    }

    private static function vrai(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }
}
