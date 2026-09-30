<?php

namespace App\Crm\Doublons;

use App\Crm\Campagnes\GardePresse;
use App\Crm\FichesProtegees;
use App\Services\Audit\AuditHashChain;
use App\Support\TotalListe;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 *     colonne recopiée ; l'EMPREINTE SALÉE de chaque valeur recopiée est posée
 *     par la base dans `fusions_empreintes`, illisible par le rôle applicatif
 *     — et une entrée de la chaîne d'audit. `annuler()` rejoue ce journal à
 *     l'envers.
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
        // 2026-09-30 : une organisation cochée dans une liste manuelle.
        'listes_manuelles_membres.company_id',
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

    /** Bornes de `ancresAbsorbees` : niveaux de chaîne, fiches absorbées. */
    public const ANCRES_NIVEAUX_MAX = 16;

    public const ANCRES_FICHES_MAX = 200;

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
     * `fusions` : TOUTES les fusions de la chaîne (A→B puis B→C : les deux),
     * dans l'ordre. Un lien posé sur la fiche d'arrivée est inscrit au journal
     * de chacune : annulées dans l'ordre inverse, elles le ramènent pas à pas
     * jusqu'à la fiche d'origine.
     *
     * @return array{garde: int, fusions: list<int>}|null
     */
    public static function gardeDe(string $ws, int $companyId): ?array
    {
        $courant = $companyId;
        $fusions = [];
        for ($pas = 0; $pas < 16; $pas++) {
            $ligne = DB::selectOne(
                'SELECT id, garde_id FROM fusions_fiches WHERE absorbee_id = ? AND annulee_at IS NULL AND workspace_id = ? ORDER BY id DESC LIMIT 1',
                [$courant, $ws],
            );
            if (! $ligne instanceof stdClass) {
                break;
            }
            $courant = (int) $ligne->garde_id;
            $fusions[] = (int) $ligne->id;
        }

        return $fusions === [] ? null : ['garde' => $courant, 'fusions' => $fusions];
    }

    /**
     * La personne a-t-elle été RETIRÉE (supprimée, effacée art. 17) sous l'UNE
     * de ces ancres ? Après un renvoi de fusion, l'import doit interroger le
     * registre `contacts_retires` avec l'ancre du fichier ET celle de la fiche
     * gardée : la personne effacée sur la gardée y est inscrite sous l'ancre
     * de la gardée (veto RGPD de la relecture #260).
     *
     * @param  list<array{siren: ?string, pays: ?string, foreign_id: ?string}>  $ancres
     */
    public static function personneRetiree(string $ws, array $ancres, ?string $prenom, ?string $nom): bool
    {
        if ($nom === null) {
            return false;
        }
        foreach ($ancres as $a) {
            if ($a['siren'] !== null) {
                $ligne = DB::selectOne('SELECT contacts_retires_contient(?::uuid, ?, ?, ?) AS e', [$ws, $a['siren'], $prenom, $nom]);
            } elseif ($a['foreign_id'] !== null && $a['pays'] !== null) {
                $ligne = DB::selectOne('SELECT contacts_retires_contient_ancre(?::uuid, ?, ?, ?, ?) AS e', [$ws, $a['pays'], $a['foreign_id'], $prenom, $nom]);
            } else {
                continue;
            }
            if ((bool) ($ligne->e ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les ancres des fiches ABSORBÉES dans cette fiche (fusions non annulées,
     * en chaîne : A→B puis B→C donne A et B pour C). Une personne retirée de
     * A avant la fusion est inscrite au registre sous l'ancre de A : un import
     * par l'ancre de la fiche gardée doit la voir aussi (réserve C, #260).
     * Servie par `idx_fusions_fiches_garde` ; bornée à 16 niveaux et 200 fiches
     * absorbées. Borne ATTEINTE (il restait des fiches à voir) : `$tronquee`
     * passe à vrai et un avertissement part au journal — identifiants
     * seulement, aucune donnée personnelle —, car les ancres au-delà ne sont
     * pas interrogées : une personne retirée d'une fiche hors borne pourrait
     * revenir. L'appelant le compte dans son bilan.
     *
     * @param-out bool $tronquee
     *
     * @return list<array{siren: ?string, pays: ?string, foreign_id: ?string}>
     */
    public static function ancresAbsorbees(string $ws, int $companyId, ?bool &$tronquee = null): array
    {
        $tronquee = false;
        $vues = [$companyId => true];
        $aVoir = [$companyId];
        $ancres = [];
        for ($pas = 0; $aVoir !== []; $pas++) {
            $lignes = DB::select(
                'SELECT absorbee_id FROM fusions_fiches WHERE garde_id = ANY(?::bigint[]) AND annulee_at IS NULL AND workspace_id = ?',
                ['{' . implode(',', $aVoir) . '}', $ws],
            );
            $aVoir = [];
            foreach ($lignes as $l) {
                $id = (int) $l->absorbee_id;
                if (isset($vues[$id])) {
                    continue;
                }
                // Une fiche NON VUE au-delà d'une borne : la borne a coupé
                // quelque chose (pas seulement « atteinte »).
                if ($pas >= self::ANCRES_NIVEAUX_MAX || count($ancres) >= self::ANCRES_FICHES_MAX) {
                    $tronquee = true;
                    $aVoir = [];
                    break;
                }
                $vues[$id] = true;
                $aVoir[] = $id;
                $ancres[] = self::ancreDe($ws, $id);
            }
        }
        if ($tronquee) {
            Log::warning('crm.doublons.ancres_absorbees_tronquees', [
                'workspace_id' => $ws,
                'company_id' => $companyId,
                'fiches_vues' => count($ancres),
                'niveaux_max' => self::ANCRES_NIVEAUX_MAX,
                'fiches_max' => self::ANCRES_FICHES_MAX,
            ]);
        }

        return $ancres;
    }

    /**
     * L'ancre (SIREN, sinon pays + identifiant) d'une fiche, pour le registre.
     *
     * @return array{siren: ?string, pays: ?string, foreign_id: ?string}
     */
    public static function ancreDe(string $ws, int $companyId): array
    {
        $f = DB::table('companies')->where('workspace_id', $ws)->where('id', $companyId)
            ->first(['siren', 'country_code', 'foreign_id', 'deleted_at']);

        return [
            'siren' => $f === null ? null : self::texte($f->siren),
            'pays' => $f === null ? null : self::texte($f->country_code),
            'foreign_id' => $f === null ? null : self::texte($f->foreign_id),
        ];
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
        $n = DB::update(
            "UPDATE fusions_fiches
                SET journal = jsonb_set(journal, ARRAY['deplacements', ?::text],
                                        COALESCE(journal->'deplacements'->(?::text), '[]'::jsonb) || to_jsonb(?::bigint))
              WHERE workspace_id = ? AND id = ? AND annulee_at IS NULL",
            [$table, $table, $cle, $ws, $fusionId],
        );
        // La fusion a été annulée entre le renvoi et l'écriture : le lien ne
        // serait plus défait par personne. La ligne d'import est refusée (et
        // son point de sauvegarde annulé), jamais écrite à moitié.
        if ($n !== 1) {
            throw new InvalidArgumentException('renvoi_de_fusion_perdu');
        }
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
        // Harmonisation de la presse (relecture de #264) : une fiche de
        // presse n'est JAMAIS fusionnée sans un humain.
        if ($mode === self::MODE_AUTO && ($this->estFichePresse($ws, $gardeId) || $this->estFichePresse($ws, $absorbeeId))) {
            throw new RefusFusion('presse_verification_humaine');
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

        // Listes manuelles (2026-09-30) : l'organisation cochée dans une liste
        // le reste sous la fiche gardée. Si la fiche gardée y a déjà une
        // ligne, la ligne de l'absorbée reste où elle est (à la corbeille avec
        // elle) ; et si cette ligne de la fiche gardée était RETIRÉE alors que
        // l'absorbée est ACTIVE, elle est d'abord réactivée — sinon
        // l'appartenance cochée à la main serait perdue en silence (relecture
        // A09 de #266). Les PERSONNES cochées suivent `contacts.company_id`,
        // déjà rattaché plus haut ; les homonymes (qui restent sur la fiche
        // absorbée) reportent leurs appartenances sur leur jumeau, plus bas.
        $deplacements['listes_manuelles_reactivees'] = $this->reactiverAppartenances(
            $ws,
            'company_id',
            [['absorbee' => $absorbeeId, 'garde' => $gardeId]],
        );
        $deplacements['listes_manuelles_membres'] = $this->ids(DB::select(
            'UPDATE listes_manuelles_membres lmm_abs SET company_id = ?
             WHERE lmm_abs.workspace_id = ? AND lmm_abs.company_id = ?
               AND NOT EXISTS (SELECT 1 FROM listes_manuelles_membres lmm_gar WHERE lmm_gar.company_id = ?
                               AND lmm_gar.liste_id = lmm_abs.liste_id)
             RETURNING lmm_abs.id',
            [$gardeId, $ws, $absorbeeId, $gardeId],
        ), 'id');

        // Les homonymes VIVANTS de la fiche absorbée restent sur elle (à la
        // corbeille) : leurs appartenances passent à leur jumeau de la fiche
        // gardée — réactivées si le jumeau avait été retiré, déplacées s'il
        // n'avait aucune ligne dans la liste.
        $pairesPersonnes = [];
        foreach ($jumeaux as $j) {
            $pairesPersonnes[] = ['absorbee' => $j['absorbee_contact'], 'garde' => $j['garde_contact']];
        }
        $deplacements['listes_manuelles_reactivees'] = array_merge(
            $deplacements['listes_manuelles_reactivees'],
            $this->reactiverAppartenances($ws, 'contact_id', $pairesPersonnes),
        );
        $deplacements['listes_manuelles_personnes'] = $this->reporterPersonnes($ws, $pairesPersonnes);

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
            // Les empreintes sont posées par la base après l'insertion du journal
            // (`doublons_journaliser`, dans `fusions_empreintes`) : le rôle
            // applicatif n'en calcule ni n'en lit aucune.
            foreach (array_keys($valeurs) as $col) {
                $champs[$col] = ['avant' => $avants[$col] ?? null];
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

        DB::select('SELECT public.doublons_journaliser(?::uuid, ?)', [$ws, $fusionId]);

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
        $cols = ['ct_abs.deleted_at AS abs_supprime', 'ct_gar.deleted_at AS gar_supprime',
            GardePresse::estContactPresseSql('ct_abs') . ' AS abs_presse',
            GardePresse::estContactPresseSql('ct_gar') . ' AS gar_presse'];
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
            // Un JOURNALISTE (contact presse) de la fiche absorbée, homonyme
            // d'une personne hors presse de la fiche gardée : ses coordonnées
            // y seraient recopiées sans la marque presse, et partiraient par un
            // autre segment. Refusé : un humain règle l'homonyme d'abord
            // (relecture sécurité de #264, B1).
            if ((bool) $l->abs_presse && ! (bool) $l->gar_presse) {
                throw new RefusFusion('journaliste_homonyme_sur_la_fiche_gardee');
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
     * @return list<array{absorbee_contact: int, garde_contact: int, champs: array<string, array{avant: ?string}>}>
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
                foreach (array_keys($j['champs']) as $c) {
                    $ancienne = $avant instanceof stdClass ? $avant->{$c} : null;
                    $champs[$c] = ['avant' => $ancienne === null ? null : ''];
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
            'contacts' => 'id', 'audience_members' => 'id', 'listes_manuelles_membres' => 'id', 'deals' => 'id', 'scraper_runs' => 'id',
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
        // Les appartenances des homonymes reviennent à la personne absorbée,
        // si elles n'ont pas bougé depuis.
        foreach ((array) ($deplacements['listes_manuelles_personnes'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $compter(DB::update(
                'UPDATE listes_manuelles_membres SET contact_id = ? WHERE workspace_id = ? AND id = ? AND contact_id = ?',
                [(int) ($p['de'] ?? 0), $ws, (int) ($p['id'] ?? 0), (int) ($p['vers'] ?? 0)],
            ), 1);
        }
        // Une appartenance réactivée par la fusion redevient retirée — sauf si
        // quelqu'un l'a retirée de nouveau depuis (elle l'est déjà).
        foreach ((array) ($deplacements['listes_manuelles_reactivees'] ?? []) as $r) {
            if (! is_array($r) || ($r['retire_le'] ?? null) === null) {
                continue;
            }
            $compter(DB::update(
                'UPDATE listes_manuelles_membres SET retire_le = CAST(? AS timestamptz), retire_par = CAST(? AS uuid)
                 WHERE workspace_id = ? AND id = ? AND retire_le IS NULL',
                [(string) $r['retire_le'], $r['retire_par'] ?? null, $ws, (int) ($r['id'] ?? 0)],
            ), 1);
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

        // Une valeur recopiée n'est retirée que si elle n'a pas bougé depuis :
        // la BASE compare son empreinte à celle de `fusions_empreintes`
        // (`doublons_valeur_inchangee`, oui/non, chemin en liste fermée). Une
        // empreinte retirée par un effacement (art. 17) n'y est plus : rien à
        // remettre, la valeur effacée reste effacée.
        $remettre = function (string $table, string $col, int $id, mixed $v, string $chemin) use ($ws, $fusionId, &$bilan): void {
            $v = is_array($v) ? $v : [];
            $r = DB::selectOne(
                'SELECT public.doublons_valeur_inchangee(?::uuid, ?, ?) AS ok',
                [$ws, $fusionId, $chemin],
            );
            $n = 0;
            if ($r instanceof stdClass && (bool) $r->ok) {
                $n = DB::update(
                    "UPDATE {$table} SET {$col} = ? WHERE workspace_id = ? AND id = ?",
                    [($v['avant'] ?? null) === '' ? '' : null, $ws, $id],
                );
            }
            $bilan[$n > 0 ? 'champs_remis' : 'champs_modifies_depuis']++;
        };
        foreach ((array) ($journal['champs'] ?? []) as $col => $v) {
            if (in_array($col, self::CHAMPS_FICHE, true)) {
                $remettre('companies', (string) $col, $gardeId, $v, 'champs.' . $col);
            }
        }
        foreach (array_values((array) ($journal['jumeaux'] ?? [])) as $rang => $j) {
            if (! is_array($j)) {
                continue;
            }
            foreach ((array) ($j['champs'] ?? []) as $col => $v) {
                if (in_array($col, self::CHAMPS_PERSONNE, true)) {
                    $remettre('contacts', (string) $col, (int) ($j['garde_contact'] ?? 0), $v, 'jumeaux.' . $rang . '.' . $col);
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

    /**
     * Réactive, sur la fiche (ou la personne) gardée, les lignes RETIRÉES des
     * listes où la fiche absorbée est ACTIVE. Rend, pour l'annulation, la
     * ligne réactivée et son retrait d'avant (qui, quand).
     *
     * @param  'company_id'|'contact_id'  $colonne
     * @param  list<array{absorbee: int, garde: int}>  $paires
     * @return list<array{id: int, retire_le: string, retire_par: ?string}>
     */
    private function reactiverAppartenances(string $ws, string $colonne, array $paires): array
    {
        $vivante = $colonne === 'contact_id'
            ? ' AND EXISTS (SELECT 1 FROM contacts ra_ct WHERE ra_ct.id = lmm_abs.contact_id AND ra_ct.deleted_at IS NULL)'
            : '';
        $journal = [];
        foreach ($paires as $p) {
            $lignes = DB::select(
                "SELECT lmm_gar.id, CAST(lmm_gar.retire_le AS TEXT) AS retire_le, CAST(lmm_gar.retire_par AS TEXT) AS retire_par
                 FROM listes_manuelles_membres lmm_gar
                 WHERE lmm_gar.workspace_id = ? AND lmm_gar.{$colonne} = ? AND lmm_gar.retire_le IS NOT NULL
                   AND EXISTS (SELECT 1 FROM listes_manuelles_membres lmm_abs
                               WHERE lmm_abs.workspace_id = lmm_gar.workspace_id AND lmm_abs.{$colonne} = ?
                                 AND lmm_abs.liste_id = lmm_gar.liste_id AND lmm_abs.retire_le IS NULL{$vivante})
                 ORDER BY lmm_gar.id
                 FOR UPDATE OF lmm_gar",
                [$ws, $p['garde'], $p['absorbee']],
            );
            foreach ($lignes as $l) {
                if (! $l instanceof stdClass) {
                    continue;
                }
                DB::update(
                    'UPDATE listes_manuelles_membres SET retire_le = NULL, retire_par = NULL WHERE workspace_id = ? AND id = ?',
                    [$ws, (int) $l->id],
                );
                $journal[] = [
                    'id' => (int) $l->id,
                    'retire_le' => (string) $l->retire_le,
                    'retire_par' => $l->retire_par === null ? null : (string) $l->retire_par,
                ];
            }
        }

        return $journal;
    }

    /**
     * Déplace sur l'homonyme de la fiche gardée les appartenances d'une
     * personne VIVANTE de la fiche absorbée, dans les listes où l'homonyme
     * n'a aucune ligne.
     *
     * @param  list<array{absorbee: int, garde: int}>  $paires
     * @return list<array{id: int, de: int, vers: int}>
     */
    private function reporterPersonnes(string $ws, array $paires): array
    {
        $journal = [];
        foreach ($paires as $p) {
            $lignes = DB::select(
                'UPDATE listes_manuelles_membres lmm_abs SET contact_id = ?
                 WHERE lmm_abs.workspace_id = ? AND lmm_abs.contact_id = ?
                   AND EXISTS (SELECT 1 FROM contacts rp_ct WHERE rp_ct.id = lmm_abs.contact_id AND rp_ct.deleted_at IS NULL)
                   AND NOT EXISTS (SELECT 1 FROM listes_manuelles_membres lmm_gar WHERE lmm_gar.contact_id = ?
                                   AND lmm_gar.liste_id = lmm_abs.liste_id)
                 RETURNING lmm_abs.id',
                [$p['garde'], $ws, $p['absorbee'], $p['garde']],
            );
            foreach ($this->ids($lignes, 'id') as $id) {
                $journal[] = ['id' => $id, 'de' => $p['absorbee'], 'vers' => $p['garde']];
            }
        }

        return $journal;
    }

    /**
     * La fiche porte-t-elle le tag de provenance de la presse ? (corbeille comprise)
     *
     * ⚠️ Ce n'est PAS `QualificationPresse::estFichePresse`, et c'est voulu
     * (relecture A09 de #268) : celle-ci ignore la corbeille (une fiche
     * absorbée y est) et répond « presse » dès qu'une ligne `media` est
     * rattachée — elle bloquerait la fusion automatique de ~30 000 fiches Sirene
     * (productions audiovisuelles, extractions NAF) que rien ne protège. Ici, la
     * question est la PROTECTION posée par l'harmonisation : le tag, seul.
     */
    private function estFichePresse(string $ws, int $companyId): bool
    {
        return DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->where('company_tag.workspace_id', $ws)->where('company_tag.company_id', $companyId)
            ->where('tags.slug', FichesProtegees::TAG_PRESSE)->exists();
    }

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
        // Plus de 50 homonymes : l'unicité ne se vérifie pas — ambigu, jamais
        // « pas d'autre candidat ».
        if (count($autres) > 50) {
            return true;
        }
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
