<?php

namespace App\Crm\Doublons;

use App\Crm\FichesProtegees;
use App\Services\Audit\AuditHashChain;
use App\Support\TotalListe;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * FUSIONNER DEUX FICHES, SANS RIEN PERDRE, ET POUVOIR L'ANNULER (chantier 5).
 *
 * RÈGLE DE WILL : aucun contact supprimé, aucune fiche supprimée en dur, tout
 * est réversible.
 *
 * Une fusion, dans UNE transaction courte (jamais plusieurs fusions dans la
 * même : le nombre de verrous reste celui d'une seule fusion) :
 *
 *  1. verrouille les deux fiches (ordre des identifiants : pas d'interblocage)
 *     et la paire de la file ;
 *  2. refuse — sans rien écrire — deux SIREN différents, une fiche à la
 *     corbeille, une paire déjà traitée, deux fédérations, un lien de réseau
 *     entre les deux, des homonymes aux coordonnées contradictoires, et, en
 *     mode automatique, une preuve qui n'est plus certaine sur les données du
 *     moment, ou qui ne désigne plus UNE seule fiche (`preuve_ambigue`) ;
 *  3. RATTACHE à la fiche gardée tout ce qui pointe vers la fiche absorbée —
 *     `REFERENCES` (clés étrangères) et `REFERENCES_SANS_CLE` (références
 *     polymorphes) ; la garde « GARDE DE COUVERTURE » de `DoublonsFusionTest`
 *     rougit si une table nouvelle pointe vers `companies` sans y être :
 *     personnes, étiquettes (protection comprise : la fiche gardée en HÉRITE),
 *     liens d'événements, ligne fédération et antennes, activités et
 *     démarches, historique métier (`business_events`), affaires, audiences,
 *     collectes, médias, journalistes, praticiens, personnes de la lettre ;
 *  4. recopie sur la fiche gardée les coordonnées qu'elle n'a pas (adresse
 *     générique, téléphone, site, LinkedIn, date d'information art. 14) ;
 *  5. met la fiche absorbée à la CORBEILLE (jamais `DELETE`) ;
 *  6. écrit le journal (`fusions_fiches`) : chaque identifiant déplacé, chaque
 *     valeur recopiée par son EMPREINTE SALÉE (`doublons_empreinte`) — et une
 *     entrée de la chaîne d'audit. `annuler()` rejoue ce journal à l'envers.
 *
 * ── Après la fusion, les ancres de la fiche absorbée mènent à la gardée ─────
 *
 * Un import qui cherche la fiche absorbée par son SIREN ou son identifiant de
 * source la trouve à la corbeille : `gardeDe()` suit alors le journal (fusion
 * non annulée, en chaîne s'il le faut) jusqu'à la fiche gardée. Choisi plutôt
 * qu'une table `company_identifiants` : aucune colonne ni reprise sur les
 * 4,3 M de fiches, une seule vérité (le journal), et l'annulation défait le
 * renvoi d'elle-même (`annulee_at`). Un lien d'événement posé par ce renvoi est
 * inscrit au journal (`noterRattachement`) : l'annulation le rend à la fiche
 * absorbée.
 *
 * ── Les personnes homonymes ─────────────────────────────────────────────────
 *
 * La clé d'une personne est (nom normalisé, fiche) : la même personne présente
 * sur les deux fiches ne peut pas être rattachée deux fois à la fiche gardée.
 * Celle de la fiche gardée reste la référence ; elle reçoit les coordonnées
 * qu'elle n'a pas (e-mail, téléphone, LinkedIn). Celle de la fiche absorbée
 * n'est PAS supprimée : elle reste sur la fiche absorbée (corbeille), et
 * l'annulation la rend visible à nouveau. Si les deux ont un e-mail ou un
 * téléphone DIFFÉRENTS, la fusion est refusée : à régler à la main.
 * La corbeille des personnes (`deleted_at`) compte : une personne supprimée
 * de la fiche absorbée ne transmet rien et ne bloque rien ; une personne
 * VIVANTE de la fiche absorbée dont l'homonyme de la fiche gardée est
 * supprimé refuse la fusion (elle disparaîtrait de la vue).
 *
 * Tout se fait dans le contexte de l'espace (`WorkspaceContext`, RLS) et
 * chaque requête filtre AUSSI `workspace_id`.
 */
final class FusionFiches
{
    public const MODE_AUTO = 'auto';

    public const MODE_MANUEL = 'manuel';

    /**
     * Toute colonne de la base qui désigne une fiche par une clé étrangère,
     * et que la fusion rattache. Lue par la garde de couverture.
     *
     * @var list<string>
     */
    public const REFERENCES = [
        'audience_members.company_id',
        'company_tag.company_id',
        'contacts.company_id',
        'deals.company_id',
        'event_organizers.company_id',
        'federations.company_id',
        'federations.parent_company_id',
        'health_practitioners.company_id',
        'journalists.company_id',
        'media.company_id',
        'personnes.company_id',
        'scraper_runs.company_id',
    ];

    /**
     * Les références SANS clé étrangère (polymorphes) : colonne => condition
     * qui dit « cette ligne désigne une fiche ». Rattachées et annulées comme
     * les autres ; la garde de couverture balaye le code pour qu'aucune
     * nouvelle (`*_type = 'company'`) n'y échappe.
     *
     * @var array<string, string>
     */
    public const REFERENCES_SANS_CLE = [
        'activities.subject_id' => "subject_type = 'company'",
        'business_events.resource_id' => "resource_type = 'company'",
    ];

    /** Tables rattachées ligne par ligne, par leur identifiant, sans conflit possible. */
    private const TABLES_SIMPLES = ['deals', 'scraper_runs', 'health_practitioners', 'media', 'journalists', 'personnes'];

    /** Coordonnées de la fiche recopiées sur la fiche gardée QUAND ELLE NE LES A PAS. */
    public const CHAMPS_FICHE = ['email_generic', 'phone', 'website', 'linkedin_url', 'first_info_at'];

    /** Coordonnées d'une personne recopiées sur son homonyme de la fiche gardée. */
    private const CHAMPS_PERSONNE = ['email', 'email_status', 'phone', 'linkedin_url'];

    /** Verrous tenus au plus, mesurés juste avant la validation de chaque fusion. */
    public int $verrousMax = 0;

    public int $verrousTxMax = 0;

    /** @var array<string, true>|null */
    private ?array $sourcesCollecte = null;

    public function __construct(private readonly AuditHashChain $audit) {}

    /**
     * La même condition que `FichesProtegees::conditionSql` : « cette fiche
     * n'est ni absorbée ni gardée par une fusion en cours ». Posée dans les
     * purges (fiches et personnes) : la fiche gardée porte les personnes
     * rattachées, les purger rendrait l'annulation impossible. Alias INTERNES
     * réservés (`fu_fa`, `fu_fg`).
     */
    public static function conditionSql(string $colonneId = 'companies.id'): string
    {
        return 'NOT EXISTS (SELECT 1 FROM fusions_fiches fu_fa'
            . " WHERE fu_fa.absorbee_id = {$colonneId} AND fu_fa.annulee_at IS NULL)"
            . ' AND NOT EXISTS (SELECT 1 FROM fusions_fiches fu_fg'
            . " WHERE fu_fg.garde_id = {$colonneId} AND fu_fg.annulee_at IS NULL)";
    }

    /**
     * La fiche vers laquelle une fiche absorbée a été fusionnée (en suivant
     * les fusions en chaîne), ou null si elle n'est absorbée par aucune fusion
     * en cours. Servie par `idx_fusions_fiches_absorbee`.
     *
     * @return array{garde: int, fusion: int}|null
     */
    public static function gardeDe(string $ws, int $companyId): ?array
    {
        $courant = $companyId;
        $fusion = null;
        for ($pas = 0; $pas < 16; $pas++) {
            $ligne = DB::selectOne(
                'SELECT id, garde_id FROM fusions_fiches WHERE absorbee_id = ? AND annulee_at IS NULL AND workspace_id = ? ORDER BY id DESC LIMIT 1',
                [$courant, $ws],
            );
            if (! $ligne instanceof stdClass) {
                break;
            }
            $courant = (int) $ligne->garde_id;
            // La PREMIÈRE fusion de la chaîne est celle qui a absorbé la fiche
            // cherchée : c'est elle que l'annulation doit défaire.
            $fusion ??= (int) $ligne->id;
        }

        return $fusion === null ? null : ['garde' => $courant, 'fusion' => $fusion];
    }

    /**
     * Un lien posé APRÈS la fusion sur la fiche gardée, en suivant l'ancre de
     * la fiche absorbée : inscrit au journal de la fusion, pour que
     * l'annulation le rende à la fiche absorbée.
     */
    public static function noterRattachement(string $ws, int $fusionId, string $table, int $cle): void
    {
        if (! in_array($table, ['event_organizers'], true)) {
            throw new InvalidArgumentException("Rattachement non journalisable : {$table}");
        }
        DB::update(
            "UPDATE fusions_fiches
                SET journal = jsonb_set(journal, ARRAY['deplacements', ?::text],
                                        COALESCE(journal->'deplacements'->(?::text), '[]'::jsonb) || to_jsonb(?::bigint))
              WHERE workspace_id = ? AND id = ? AND annulee_at IS NULL",
            [$table, $table, $cle, $ws, $fusionId],
        );
    }

    /**
     * @return int l'identifiant de la fusion (0 à blanc)
     *
     * @throws RefusFusion
     */
    public function fusionner(
        string $workspaceId,
        int $gardeId,
        int $absorbeeId,
        string $motif,
        string $mode,
        ?int $flagId = null,
        ?string $userId = null,
        string $operateur = '?',
        bool $aBlanc = false,
    ): int {
        if ($gardeId === $absorbeeId) {
            throw new RefusFusion('meme_fiche');
        }

        try {
            $id = DB::transaction(function () use ($workspaceId, $gardeId, $absorbeeId, $motif, $mode, $flagId, $userId, $operateur, $aBlanc): int {
                $id = $this->ecrire($workspaceId, $gardeId, $absorbeeId, $motif, $mode, $flagId, $userId, $operateur);
                $this->mesurerVerrous();
                if ($aBlanc) {
                    throw new FusionABlanc($id);
                }

                return $id;
            });
        } catch (FusionABlanc) {
            return 0;
        } catch (QueryException $e) {
            // Un déclencheur (boucle de réseau, espace incohérent) ou une clé a
            // refusé : la transaction est annulée en entier. Seul le code
            // d'état part : le message SQL peut citer des valeurs.
            throw new RefusFusion('erreur_base', 'SQLSTATE ' . $e->getCode());
        }

        TotalListe::oublier($workspaceId);

        return $id;
    }

    /**
     * Rejoue le journal d'une fusion à l'envers.
     *
     * @return array<string, int> ce qui a été remis en place, et ce qui ne l'a pas été (déplacé depuis)
     *
     * @throws RefusFusion
     */
    public function annuler(string $workspaceId, int $fusionId, string $operateur = '?', bool $aBlanc = false): array
    {
        try {
            $bilan = DB::transaction(function () use ($workspaceId, $fusionId, $operateur, $aBlanc): array {
                $bilan = $this->defaire($workspaceId, $fusionId, $operateur);
                $this->mesurerVerrous();
                if ($aBlanc) {
                    throw new FusionABlanc(0, $bilan);
                }

                return $bilan;
            });
        } catch (FusionABlanc $blanc) {
            return $blanc->bilan;
        } catch (QueryException $e) {
            throw new RefusFusion('erreur_base', 'SQLSTATE ' . $e->getCode());
        }

        TotalListe::oublier($workspaceId);

        return $bilan;
    }

    // ── La fusion ───────────────────────────────────────────────────────────

    private function ecrire(string $ws, int $gardeId, int $absorbeeId, string $motif, string $mode, ?int $flagId, ?string $userId, string $operateur): int
    {
        $fiches = $this->verrouillerFiches($ws, $gardeId, $absorbeeId);
        $garde = $fiches[$gardeId] ?? null;
        $absorbee = $fiches[$absorbeeId] ?? null;
        if ($garde === null || $absorbee === null) {
            throw new RefusFusion('fiche_introuvable');
        }
        if ($garde->deleted_at !== null || $absorbee->deleted_at !== null) {
            throw new RefusFusion('fiche_a_la_corbeille');
        }
        if (! Rapprochement::pairePossible(self::texte($garde->siren), self::texte($absorbee->siren))) {
            throw new RefusFusion('sirens_differents');
        }
        if ($flagId !== null) {
            $this->verrouillerPaire($ws, $flagId, $gardeId, $absorbeeId);
        }
        if ($mode === self::MODE_AUTO && ! Rapprochement::preuveCertaine(
            $motif,
            self::pourPreuve($garde),
            self::pourPreuve($absorbee),
            $this->vientDUneCollecte(self::texte($absorbee->discovery_source)),
        )) {
            throw new RefusFusion('preuve_insuffisante');
        }
        if ($mode === self::MODE_AUTO && $this->autreCandidatCertain($ws, $motif, $gardeId, $absorbee)) {
            throw new RefusFusion('preuve_ambigue');
        }
        $this->refuserLiensDeReseau($ws, $gardeId, $absorbeeId);
        $jumeaux = $this->jumeaux($ws, $gardeId, $absorbeeId);

        $etaitProtegee = $this->estProtegee($ws, $absorbeeId);

        // ── Rattacher ───────────────────────────────────────────────────────
        $deplacements = [];
        $idsJumeaux = array_map(static fn (array $j): int => $j['absorbee_contact'], $jumeaux);
        $deplacements['contacts'] = $this->ids(DB::select(
            'UPDATE contacts SET company_id = ? WHERE workspace_id = ? AND company_id = ?'
            . ($idsJumeaux === [] ? '' : ' AND NOT (id = ANY(?::bigint[]))')
            . ' RETURNING id',
            array_merge([$gardeId, $ws, $absorbeeId], $idsJumeaux === [] ? [] : [self::tableau($idsJumeaux)]),
        ), 'id');
        $jumeauxJournal = $this->completerJumeaux($ws, $jumeaux);

        $deplacements['company_tag'] = $this->ids(DB::select(
            'UPDATE company_tag ct_abs SET company_id = ?
             WHERE ct_abs.workspace_id = ? AND ct_abs.company_id = ?
               AND NOT EXISTS (SELECT 1 FROM company_tag ct_gar WHERE ct_gar.company_id = ? AND ct_gar.tag_id = ct_abs.tag_id)
             RETURNING ct_abs.tag_id',
            [$gardeId, $ws, $absorbeeId, $gardeId],
        ), 'tag_id');

        $deplacements['event_organizers'] = $this->ids(DB::select(
            'UPDATE event_organizers eo_abs SET company_id = ?
             WHERE eo_abs.workspace_id = ? AND eo_abs.company_id = ?
               AND NOT EXISTS (SELECT 1 FROM event_organizers eo_gar WHERE eo_gar.company_id = ? AND eo_gar.event_id = eo_abs.event_id)
             RETURNING eo_abs.event_id',
            [$gardeId, $ws, $absorbeeId, $gardeId],
        ), 'event_id');

        $deplacements['audience_members'] = $this->ids(DB::select(
            'UPDATE audience_members am_abs SET company_id = ?
             WHERE am_abs.workspace_id = ? AND am_abs.company_id = ?
               AND NOT EXISTS (SELECT 1 FROM audience_members am_gar WHERE am_gar.company_id = ?
                               AND am_gar.audience_id = am_abs.audience_id
                               AND am_gar.contact_id IS NOT DISTINCT FROM am_abs.contact_id)
             RETURNING am_abs.id',
            [$gardeId, $ws, $absorbeeId, $gardeId],
        ), 'id');

        foreach (self::TABLES_SIMPLES as $table) {
            $deplacements[$table] = $this->ids(DB::select(
                "UPDATE {$table} SET company_id = ? WHERE workspace_id = ? AND company_id = ? RETURNING id",
                [$gardeId, $ws, $absorbeeId],
            ), 'id');
        }

        // La ligne « fédération » (clé primaire = la fiche) suit la fiche
        // absorbée quand la fiche gardée n'en a pas (deux fédérations : refusé
        // plus haut). Puis ses antennes désignent la fiche gardée.
        $deplacements['federations'] = DB::update(
            'UPDATE federations SET company_id = ? WHERE workspace_id = ? AND company_id = ?',
            [$gardeId, $ws, $absorbeeId],
        ) > 0;
        $deplacements['federations_antennes'] = $this->ids(DB::select(
            'UPDATE federations SET parent_company_id = ? WHERE workspace_id = ? AND parent_company_id = ? RETURNING company_id',
            [$gardeId, $ws, $absorbeeId],
        ), 'company_id');

        $deplacements['activities'] = $this->ids(DB::select(
            "UPDATE activities SET subject_id = ? WHERE workspace_id = ? AND subject_type = 'company' AND subject_id = ? RETURNING id",
            [$gardeId, $ws, $absorbeeId],
        ), 'id');
        // `resource_id` est du texte (VARCHAR) : l'identifiant s'y écrit en clair.
        $deplacements['business_events'] = $this->ids(DB::select(
            "UPDATE business_events SET resource_id = ? WHERE workspace_id = ? AND resource_type = 'company' AND resource_id = ? RETURNING id",
            [(string) $gardeId, $ws, (string) $absorbeeId],
        ), 'id');

        // ── Recopier les coordonnées manquantes ─────────────────────────────
        // Le journal ne garde que la valeur d'avant (VIDE par construction :
        // NULL ou '') et l'EMPREINTE SALÉE de la valeur recopiée, relue sur la
        // colonne écrite — jamais la valeur.
        $valeurs = [];
        $avants = [];
        foreach (self::CHAMPS_FICHE as $col) {
            $avant = $garde->{$col};
            $apres = $absorbee->{$col};
            if (self::vide($avant) && ! self::vide($apres)) {
                $valeurs[$col] = $apres;
                $avants[$col] = $avant === null ? null : '';
            }
        }
        $champs = [];
        if ($valeurs !== []) {
            $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($valeurs)));
            DB::update(
                "UPDATE companies SET {$sets} WHERE workspace_id = ? AND id = ?",
                array_merge(array_values($valeurs), [$ws, $gardeId]),
            );
            foreach ($this->empreintesColonnes('companies', $ws, $gardeId, array_keys($valeurs)) as $col => $empreinte) {
                $champs[$col] = ['avant' => $avants[$col] ?? null, 'empreinte' => $empreinte];
            }
        }

        // ── La corbeille, jamais plus loin ──────────────────────────────────
        $corbeille = DB::selectOne(
            'UPDATE companies SET deleted_at = now() WHERE workspace_id = ? AND id = ? AND deleted_at IS NULL RETURNING deleted_at',
            [$ws, $absorbeeId],
        );
        if (! $corbeille instanceof stdClass) {
            throw new RefusFusion('fiche_a_la_corbeille');
        }

        if ($etaitProtegee && ! $this->estProtegee($ws, $gardeId)) {
            throw new RefusFusion('protection_perdue');
        }

        $fusionId = (int) DB::table('fusions_fiches')->insertGetId([
            'workspace_id' => $ws,
            'flag_id' => $flagId,
            'garde_id' => $gardeId,
            'absorbee_id' => $absorbeeId,
            'motif' => $motif,
            'mode' => $mode,
            'journal' => json_encode([
                'deplacements' => $deplacements,
                'champs' => $champs,
                'jumeaux' => $jumeauxJournal,
            ], JSON_THROW_ON_ERROR),
            'absorbee_supprimee_le' => $corbeille->deleted_at,
            'fait_par' => $userId,
            'operateur' => $operateur,
            'created_at' => now(),
        ]);

        if ($flagId !== null) {
            DB::update(
                "UPDATE duplicate_flags SET reviewed_at = now(), reviewed_by = ?, resolution = 'merge' WHERE workspace_id = ? AND id = ?",
                [$userId, $ws, $flagId],
            );
        }

        $this->auditer($ws, $userId, $operateur, 'FUSION_FICHES', [
            'fusion' => $fusionId, 'garde' => $gardeId, 'absorbee' => $absorbeeId, 'motif' => $motif, 'mode' => $mode,
            'deplacements' => $deplacements, 'champs' => array_keys($champs), 'jumeaux' => count($jumeauxJournal),
        ], "fusion {$fusionId} : fiche {$absorbeeId} dans {$gardeId}");

        return $fusionId;
    }

    /** @return array<int, stdClass> identifiant => fiche */
    private function verrouillerFiches(string $ws, int $a, int $b): array
    {
        $cols = implode(', ', array_merge(
            ['id', 'siren', 'country_code', 'foreign_id', 'denomination_normalized', 'postcode', 'website', 'discovery_source', 'deleted_at'],
            array_diff(self::CHAMPS_FICHE, ['website']),
        ));
        $lignes = DB::select(
            "SELECT {$cols} FROM companies WHERE workspace_id = ? AND id IN (?, ?) ORDER BY id FOR UPDATE",
            [$ws, min($a, $b), max($a, $b)],
        );
        $fiches = [];
        foreach ($lignes as $l) {
            if ($l instanceof stdClass) {
                $fiches[(int) $l->id] = $l;
            }
        }

        return $fiches;
    }

    private function verrouillerPaire(string $ws, int $flagId, int $gardeId, int $absorbeeId): void
    {
        $paire = DB::selectOne(
            "SELECT entity_a_id, entity_b_id, reviewed_at FROM duplicate_flags
             WHERE workspace_id = ? AND id = ? AND entity_type = 'company' FOR UPDATE",
            [$ws, $flagId],
        );
        if (! $paire instanceof stdClass) {
            throw new RefusFusion('paire_inconnue');
        }
        $membres = [(int) $paire->entity_a_id, (int) $paire->entity_b_id];
        sort($membres);
        $demandes = [$gardeId, $absorbeeId];
        sort($demandes);
        if ($membres !== $demandes) {
            throw new RefusFusion('paire_inconnue');
        }
        if ($paire->reviewed_at !== null) {
            throw new RefusFusion('deja_traitee');
        }
    }

    /**
     * Deux fédérations, ou une fiche tête de réseau de l'autre : jamais
     * fusionnées d'office.
     */
    private function refuserLiensDeReseau(string $ws, int $gardeId, int $absorbeeId): void
    {
        $lignes = DB::select(
            'SELECT company_id, parent_company_id FROM federations WHERE workspace_id = ? AND company_id IN (?, ?)',
            [$ws, $gardeId, $absorbeeId],
        );
        if (count($lignes) > 1) {
            throw new RefusFusion('deux_federations');
        }
        foreach ($lignes as $l) {
            if (! $l instanceof stdClass || $l->parent_company_id === null) {
                continue;
            }
            $parent = (int) $l->parent_company_id;
            if ($parent === $gardeId || $parent === $absorbeeId) {
                throw new RefusFusion('lien_de_reseau_entre_les_deux');
            }
        }
    }

    /**
     * Les personnes de la fiche absorbée qui ont un homonyme sur la fiche
     * gardée (même clé `normalized_hash` une fois rattachées) — lue par
     * l'index unique (`workspace_id`, `normalized_hash`). Refuse si leurs
     * coordonnées se contredisent.
     *
     * @return list<array{absorbee_contact: int, garde_contact: int, champs: array<string, mixed>}>
     */
    private function jumeaux(string $ws, int $gardeId, int $absorbeeId): array
    {
        $cols = ['ct_abs.deleted_at AS abs_supprime', 'ct_gar.deleted_at AS gar_supprime'];
        foreach (self::CHAMPS_PERSONNE as $c) {
            $cols[] = "ct_abs.{$c} AS abs_{$c}";
            $cols[] = "ct_gar.{$c} AS gar_{$c}";
        }
        $lignes = DB::select(
            'SELECT ct_abs.id AS absorbee_contact, ct_gar.id AS garde_contact, ' . implode(', ', $cols) . '
             FROM contacts ct_abs
             JOIN contacts ct_gar
               ON ct_gar.workspace_id = ct_abs.workspace_id
              AND ct_gar.normalized_hash = encode(digest(normalize_name(coalesce(ct_abs.first_name, \'\') || \'_\' || ct_abs.last_name) || \'_\' || CAST(? AS BIGINT)::TEXT, \'sha256\'), \'hex\')
             WHERE ct_abs.workspace_id = ? AND ct_abs.company_id = ?
             ORDER BY ct_abs.id',
            [$gardeId, $ws, $absorbeeId],
        );

        $jumeaux = [];
        foreach ($lignes as $l) {
            if (! $l instanceof stdClass) {
                continue;
            }
            // Supprimée de la fiche absorbée : elle y reste, ne transmet rien,
            // ne bloque rien.
            if ($l->abs_supprime !== null) {
                $jumeaux[] = ['absorbee_contact' => (int) $l->absorbee_contact, 'garde_contact' => (int) $l->garde_contact, 'champs' => []];

                continue;
            }
            // Vivante, mais son homonyme de la fiche gardée est supprimé : elle
            // disparaîtrait de la vue. À régler à la main.
            if ($l->gar_supprime !== null) {
                throw new RefusFusion('homonyme_supprime_sur_la_fiche_gardee');
            }
            $abs = self::texte($l->abs_email);
            $gar = self::texte($l->gar_email);
            if ($abs !== null && $gar !== null && mb_strtolower($abs) !== mb_strtolower($gar)) {
                throw new RefusFusion('personnes_homonymes_en_conflit');
            }
            $telAbs = preg_replace('/\D/', '', (string) $l->abs_phone);
            $telGar = preg_replace('/\D/', '', (string) $l->gar_phone);
            if ($telAbs !== '' && $telGar !== '' && $telAbs !== $telGar) {
                throw new RefusFusion('personnes_homonymes_en_conflit');
            }

            $champs = [];
            foreach (self::CHAMPS_PERSONNE as $c) {
                // Le statut suit l'e-mail : jamais un statut sans son adresse.
                if ($c === 'email_status') {
                    continue;
                }
                if (self::vide($l->{"gar_{$c}"}) && ! self::vide($l->{"abs_{$c}"})) {
                    $champs[$c] = $l->{"abs_{$c}"};
                    if ($c === 'email') {
                        $champs['email_status'] = $l->abs_email_status;
                    }
                }
            }
            $jumeaux[] = ['absorbee_contact' => (int) $l->absorbee_contact, 'garde_contact' => (int) $l->garde_contact, 'champs' => $champs];
        }

        return $jumeaux;
    }

    /**
     * Recopie sur l'homonyme de la fiche gardée les coordonnées qu'il n'a pas.
     *
     * @param  list<array{absorbee_contact: int, garde_contact: int, champs: array<string, mixed>}>  $jumeaux
     * @return list<array{absorbee_contact: int, garde_contact: int, champs: array<string, array{avant: ?string, empreinte: string}>}>
     */
    private function completerJumeaux(string $ws, array $jumeaux): array
    {
        $journal = [];
        foreach ($jumeaux as $j) {
            $champs = [];
            if ($j['champs'] !== []) {
                $avant = DB::selectOne(
                    'SELECT ' . implode(', ', array_keys($j['champs'])) . ' FROM contacts WHERE workspace_id = ? AND id = ?',
                    [$ws, $j['garde_contact']],
                );
                $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($j['champs'])));
                DB::update(
                    "UPDATE contacts SET {$sets} WHERE workspace_id = ? AND id = ?",
                    array_merge(array_values($j['champs']), [$ws, $j['garde_contact']]),
                );
                foreach ($this->empreintesColonnes('contacts', $ws, $j['garde_contact'], array_keys($j['champs'])) as $c => $empreinte) {
                    $ancienne = $avant instanceof stdClass ? $avant->{$c} : null;
                    $champs[$c] = ['avant' => $ancienne === null ? null : '', 'empreinte' => $empreinte];
                }
            }
            $journal[] = ['absorbee_contact' => $j['absorbee_contact'], 'garde_contact' => $j['garde_contact'], 'champs' => $champs];
        }

        return $journal;
    }

    // ── L'annulation ────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private function defaire(string $ws, int $fusionId, string $operateur): array
    {
        $fusion = DB::selectOne(
            'SELECT * FROM fusions_fiches WHERE workspace_id = ? AND id = ? FOR UPDATE',
            [$ws, $fusionId],
        );
        if (! $fusion instanceof stdClass) {
            throw new RefusFusion('fusion_introuvable');
        }
        if ($fusion->annulee_at !== null) {
            throw new RefusFusion('deja_annulee');
        }
        $gardeId = (int) $fusion->garde_id;
        $absorbeeId = (int) $fusion->absorbee_id;
        $fiches = $this->verrouillerFiches($ws, $gardeId, $absorbeeId);
        if (! isset($fiches[$gardeId])) {
            throw new RefusFusion('gardee_introuvable');
        }
        $absorbee = $fiches[$absorbeeId] ?? null;
        // Deux `timestamptz` lus par la même session : même écriture textuelle.
        if ($absorbee === null || $absorbee->deleted_at === null
            || (string) $absorbee->deleted_at !== (string) $fusion->absorbee_supprimee_le) {
            throw new RefusFusion('absorbee_modifiee');
        }

        $journal = json_decode((string) $fusion->journal, true, 512, JSON_THROW_ON_ERROR);
        $journal = is_array($journal) ? $journal : [];
        $deplacements = is_array($journal['deplacements'] ?? null) ? $journal['deplacements'] : [];
        $bilan = ['remis' => 0, 'non_retrouves' => 0, 'champs_remis' => 0, 'champs_modifies_depuis' => 0];
        $compter = static function (int $remis, int $attendus) use (&$bilan): void {
            $bilan['remis'] += $remis;
            $bilan['non_retrouves'] += max(0, $attendus - $remis);
        };

        // La fiche absorbée sort de la corbeille EN PREMIER : ses lignes y
        // reviennent ensuite.
        DB::update('UPDATE companies SET deleted_at = NULL WHERE workspace_id = ? AND id = ?', [$ws, $absorbeeId]);

        // Les antennes reviennent à la fiche absorbée (sortie de la corbeille :
        // le déclencheur anti-boucle refuse une tête à la corbeille), puis la
        // ligne fédération elle-même.
        $antennes = self::liste($deplacements['federations_antennes'] ?? []);
        if ($antennes !== []) {
            $compter(DB::update(
                'UPDATE federations SET parent_company_id = ? WHERE workspace_id = ? AND parent_company_id = ? AND company_id = ANY(?::bigint[])',
                [$absorbeeId, $ws, $gardeId, self::tableau($antennes)],
            ), count($antennes));
        }
        if (($deplacements['federations'] ?? false) === true) {
            $compter(DB::update(
                'UPDATE federations SET company_id = ? WHERE workspace_id = ? AND company_id = ?',
                [$absorbeeId, $ws, $gardeId],
            ), 1);
        }

        $parCle = [
            'contacts' => 'id', 'audience_members' => 'id', 'deals' => 'id', 'scraper_runs' => 'id',
            'health_practitioners' => 'id', 'media' => 'id', 'journalists' => 'id', 'personnes' => 'id',
            'company_tag' => 'tag_id', 'event_organizers' => 'event_id',
        ];
        foreach ($parCle as $table => $cle) {
            $ids = self::liste($deplacements[$table] ?? []);
            if ($ids === []) {
                continue;
            }
            $compter(DB::update(
                "UPDATE {$table} SET company_id = ? WHERE workspace_id = ? AND company_id = ? AND {$cle} = ANY(?::bigint[])",
                [$absorbeeId, $ws, $gardeId, self::tableau($ids)],
            ), count($ids));
        }
        $activites = self::liste($deplacements['activities'] ?? []);
        if ($activites !== []) {
            $compter(DB::update(
                "UPDATE activities SET subject_id = ? WHERE workspace_id = ? AND subject_type = 'company' AND subject_id = ? AND id = ANY(?::bigint[])",
                [$absorbeeId, $ws, $gardeId, self::tableau($activites)],
            ), count($activites));
        }
        $evenementsMetier = self::liste($deplacements['business_events'] ?? []);
        if ($evenementsMetier !== []) {
            $compter(DB::update(
                "UPDATE business_events SET resource_id = ? WHERE workspace_id = ? AND resource_type = 'company' AND resource_id = ? AND id = ANY(?::bigint[])",
                [(string) $absorbeeId, $ws, (string) $gardeId, self::tableau($evenementsMetier)],
            ), count($evenementsMetier));
        }

        // Une valeur recopiée n'est retirée que si son EMPREINTE n'a pas bougé
        // (personne ne l'a changée depuis). `COALESCE` : la condition ne dit
        // rien de « non nul », si bien qu'aucun index PARTIEL (`… IS NOT
        // NULL`) ne peut la servir — la ligne se lit par sa clé primaire.
        $remettre = function (string $table, string $col, int $id, mixed $v) use ($ws, &$bilan): void {
            $v = is_array($v) ? $v : [];
            $n = DB::update(
                "UPDATE {$table} SET {$col} = ? WHERE workspace_id = ? AND id = ?
                   AND public.doublons_empreinte(COALESCE(CAST({$col} AS TEXT), '')) = ?",
                [($v['avant'] ?? null) === '' ? '' : null, $ws, $id, is_string($v['empreinte'] ?? null) ? $v['empreinte'] : ''],
            );
            $bilan[$n > 0 ? 'champs_remis' : 'champs_modifies_depuis']++;
        };
        foreach ((array) ($journal['champs'] ?? []) as $col => $v) {
            if (in_array($col, self::CHAMPS_FICHE, true)) {
                $remettre('companies', (string) $col, $gardeId, $v);
            }
        }
        foreach ((array) ($journal['jumeaux'] ?? []) as $j) {
            if (! is_array($j)) {
                continue;
            }
            foreach ((array) ($j['champs'] ?? []) as $col => $v) {
                if (in_array($col, self::CHAMPS_PERSONNE, true)) {
                    $remettre('contacts', (string) $col, (int) ($j['garde_contact'] ?? 0), $v);
                }
            }
        }

        if ($fusion->flag_id !== null) {
            // La paire revient dans la file — et n'en repartira jamais seule :
            // la fusion automatique écarte toute paire dont une fusion a été
            // annulée.
            DB::update(
                'UPDATE duplicate_flags SET reviewed_at = NULL, reviewed_by = NULL, resolution = NULL WHERE workspace_id = ? AND id = ?',
                [$ws, (int) $fusion->flag_id],
            );
        }
        DB::update(
            'UPDATE fusions_fiches SET annulee_at = now(), annulee_par = ? WHERE workspace_id = ? AND id = ?',
            [$operateur, $ws, $fusionId],
        );

        $this->auditer($ws, null, $operateur, 'FUSION_FICHES_ANNULEE', ['fusion' => $fusionId, 'bilan' => $bilan], "annulation de la fusion {$fusionId}");

        return $bilan;
    }

    // ── Outils ──────────────────────────────────────────────────────────────

    private function estProtegee(string $ws, int $companyId): bool
    {
        // Corbeille comprise, volontairement : la question porte sur les
        // étiquettes de la fiche, pas sur sa visibilité.
        $r = DB::selectOne(
            'SELECT NOT ' . FichesProtegees::conditionSql('pr_c.id') . ' AS protegee
             FROM companies pr_c WHERE pr_c.workspace_id = ? AND pr_c.id = ?',
            [$ws, $companyId],
        );

        return $r instanceof stdClass && (bool) $r->protegee;
    }

    /** La source est-elle une source de collecte du registre, autre que l'INSEE ? */
    public function vientDUneCollecte(?string $source): bool
    {
        if ($source === null || $source === 'insee') {
            return false;
        }
        if ($this->sourcesCollecte === null) {
            $this->sourcesCollecte = [];
            foreach (DB::table('scraping_sources')->pluck('slug') as $slug) {
                $this->sourcesCollecte[(string) $slug] = true;
            }
        }

        return isset($this->sourcesCollecte[$source]);
    }

    /**
     * @return array{siren: ?string, country_code: ?string, foreign_id: ?string, nom: ?string, cp: ?string, domaine: ?string, source: ?string}
     */
    public static function pourPreuve(stdClass $fiche): array
    {
        return [
            'siren' => self::texte($fiche->siren ?? null),
            'country_code' => self::texte($fiche->country_code ?? null),
            'foreign_id' => self::texte($fiche->foreign_id ?? null),
            'nom' => self::texte($fiche->denomination_normalized ?? null),
            'cp' => Rapprochement::codePostal(self::texte($fiche->postcode ?? null)),
            'domaine' => Rapprochement::domaineSite(self::texte($fiche->website ?? null)),
            'source' => self::texte($fiche->discovery_source ?? null),
        ];
    }

    private function mesurerVerrous(): void
    {
        $v = DB::selectOne(
            "SELECT count(*) AS n, count(*) FILTER (WHERE locktype = 'transactionid') AS tx FROM pg_locks WHERE pid = pg_backend_pid()",
        );
        if ($v instanceof stdClass) {
            $this->verrousMax = max($this->verrousMax, (int) $v->n);
            $this->verrousTxMax = max($this->verrousTxMax, (int) $v->tx);
        }
    }

    /** @param  array<string, mixed>  $details */
    private function auditer(string $ws, ?string $userId, string $operateur, string $evenement, array $details, string $resume): void
    {
        $this->audit->record([
            'workspace_id' => $ws,
            'user_id' => $userId,
            'method' => $evenement,
            'path' => 'doublons — ' . $resume,
            'status' => 200,
            'ip' => null,
            'user_agent' => 'doublons ' . $operateur,
            'payload_hash' => hash('sha256', json_encode($details, JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * @param  array<int, mixed>  $lignes
     * @return list<int>
     */
    private function ids(array $lignes, string $colonne): array
    {
        $ids = [];
        foreach ($lignes as $l) {
            if ($l instanceof stdClass) {
                $ids[] = (int) $l->{$colonne};
            }
        }
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private static function liste(mixed $valeur): array
    {
        return is_array($valeur) ? array_values(array_map(static fn (mixed $v): int => (int) $v, $valeur)) : [];
    }

    /** @param  list<int>  $ids */
    private static function tableau(array $ids): string
    {
        return '{' . implode(',', $ids) . '}';
    }

    private static function texte(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /**
     * Les empreintes SALÉES des colonnes d'une ligne, calculées par la base
     * sur la valeur écrite (`CAST(col AS TEXT)`) : exactement ce que
     * l'annulation recalculera.
     *
     * @param  list<string>  $colonnes
     * @return array<string, string>
     */
    private function empreintesColonnes(string $table, string $ws, int $id, array $colonnes): array
    {
        $select = implode(', ', array_map(
            static fn (string $c): string => "public.doublons_empreinte(COALESCE(CAST({$c} AS TEXT), '')) AS {$c}",
            $colonnes,
        ));
        $ligne = DB::selectOne("SELECT {$select} FROM {$table} WHERE workspace_id = ? AND id = ?", [$ws, $id]);
        $empreintes = [];
        foreach ($colonnes as $c) {
            $empreintes[$c] = $ligne instanceof stdClass ? (string) $ligne->{$c} : '';
        }

        return $empreintes;
    }

    /**
     * E1 — la preuve « nom, code postal et site » ne vaut que si elle désigne
     * UNE seule fiche : une autre fiche à SIREN qui la satisferait aussi rend
     * la fusion ambiguë (lue par `idx_companies_denom_btree`).
     */
    private function autreCandidatCertain(string $ws, string $motif, int $gardeId, stdClass $absorbee): bool
    {
        if ($motif !== Rapprochement::NOM_CP_SITE) {
            return false;
        }
        $autres = DB::select(
            'SELECT ac.id, ac.siren, ac.country_code, ac.foreign_id, ac.denomination_normalized, ac.postcode, ac.website, ac.discovery_source
             FROM companies ac
             WHERE ac.workspace_id = ? AND ac.denomination_normalized = ? AND ac.id NOT IN (?, ?)
               AND ac.deleted_at IS NULL AND ac.siren IS NOT NULL
             ORDER BY ac.id
             LIMIT 51',
            [$ws, (string) $absorbee->denomination_normalized, $gardeId, (int) $absorbee->id],
        );
        $preuveAbsorbee = self::pourPreuve($absorbee);
        foreach ($autres as $autre) {
            if ($autre instanceof stdClass && Rapprochement::preuveCertaine($motif, self::pourPreuve($autre), $preuveAbsorbee, true)) {
                return true;
            }
        }

        return false;
    }

    private static function vide(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '');
    }
}
