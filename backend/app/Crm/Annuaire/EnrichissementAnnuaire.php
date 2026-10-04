<?php

namespace App\Crm\Annuaire;

use App\Crm\FichesProtegees;
use App\Crm\Propositions\Propositions;
use App\Crm\Sites\CurseurTraitement;
use App\Crm\Sites\SiteFiable;
use App\Crm\Sites\VerificationSite;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;

/**
 * L'ANNUAIRE OFFICIEL DE L'ADMINISTRATION SUR LES FICHES DU SECTEUR PUBLIC
 * (décision du propriétaire, 04/10/2026) — `crm:public:annuaire-officiel`.
 *
 * Les ≈ 93 000 unités du secteur public importées de l'INSEE (catégorie
 * juridique 7…) n'ont ni e-mail ni téléphone. L'annuaire de Service-public
 * (DILA, licence ouverte) les publie : e-mail, téléphone et site GÉNÉRIQUES
 * de l'organisme. AUCUNE DEVINETTE.
 *
 * ── RAPPROCHEMENT : UNIQUEMENT CERTAIN ───────────────────────────────────
 *
 * Seules les fiches vivantes de l'espace, de catégorie juridique 7…, non
 * marquées non diffusibles, sont visées. Dans l'ordre :
 *  1. SIRET de l'annuaire = `companies.siret` — si ce SIRET n'est porté que
 *     par UN organisme de l'annuaire ;
 *  2. organisme SANS SIRET, avec un SIREN = `companies.siren` — si ce SIREN
 *     n'est porté que par UN organisme de l'annuaire (un SIREN partagé par
 *     plusieurs services ne désigne aucun d'eux) ;
 *  3. MAIRIE (pivot `mairie`) dont le code INSEE de commune est unique dans
 *     la ligne ET n'est porté par aucune autre mairie de l'annuaire ↔ LA
 *     fiche de COMMUNE (catégorie 7210) de ce code dans le CRM, si elle est
 *     seule et que son code est sûr (`commune_code` et `insee` d'accord
 *     quand les deux sont connus).
 * JAMAIS par le nom. Tout le reste est compté (`non_rapproches`,
 * `ambigus`). Une fiche n'est servie qu'UNE fois par passage (`doublons`).
 *
 * ── ÉCRITURE : JAMAIS D'ÉCRASEMENT ───────────────────────────────────────
 *
 * Pour chacun des champs `email_generic`, `phone`, `website` :
 *  - vide, fiche non protégée, champ non déclaré → ÉCRIT, `field_origins`
 *    = `annuaire-service-public` ;
 *  - même valeur → rien (un site deviné CONFIRMÉ par l'annuaire reçoit le
 *    marqueur `metadata.site_entreprise` `verifie`, preuve
 *    `annuaire-service-public` : il sort de la quarantaine) ;
 *  - valeur posée par un passage PRÉCÉDENT de l'annuaire (`field_origins`)
 *    → mise à jour (l'annuaire bouge) ;
 *  - toute AUTRE valeur (saisie, déclarée, collectée, devinée, autre
 *    source), ou fiche protégée → PROPOSITION (`propositions_champs`,
 *    `Propositions::proposerSourceOfficielle`) ; la fiche ne bouge pas.
 * Le site écrit porte `website_method` = `annuaire-service-public` : ce
 * n'est pas un site deviné (`SiteFiable`, `QuarantaineSite`). L'adresse
 * écrite n'est marquée ni vérifiée ni valide : elle suit le circuit normal
 * (`crm:emails:verifier`, `EligibiliteAdresse`). La trace du passage est dans
 * `metadata.annuaire_service_public` = {id, le}. RIEN N'EST SUPPRIMÉ.
 *
 * ── MÉMOIRE ET REPRISE ───────────────────────────────────────────────────
 *
 * Le fichier est téléchargé en flux et lu ligne par ligne, DEUX fois : la
 * première compte les SIRET, SIREN et codes de mairie (clés entières :
 * mémoire bornée par la taille de l'annuaire, pas par le CRM), la seconde
 * rapproche par paquets de `TAILLE_PAQUET`. Le fichier est supprimé à la
 * fin, même sur une erreur. Le CURSEUR (ligne) est écrit dans la
 * transaction du paquet (`curseurs_traitements`, clé du MOIS) : un passage
 * coupé (`--limite`, 19:00, coupure) reprend, dans le mois, après la
 * dernière ligne validée ; un passage fini pose la clé `…:fin`. Un essai à
 * blanc lit TOUT depuis la ligne 1 et n'écrit RIEN.
 */
final class EnrichissementAnnuaire
{
    public const ORIGINE = Propositions::ORIGINE_ANNUAIRE;

    /** Préfixe des clés de `curseurs_traitements`. */
    public const TRAITEMENT = 'annuaire-service-public';

    public const FUSEAU = 'Europe/Paris';

    /** Le verrou du planificateur (`routes/console.php`). */
    public const VERROU_PLANIFICATEUR = 'crm-public-annuaire-officiel';

    public const TAILLE_PAQUET = 500;

    /** Ligne JSONL la plus longue acceptée (une ligne utile fait < 4 Ko). */
    public const LONGUEUR_MAX_LIGNE = 262144;

    /** La catégorie juridique INSEE des communes. */
    public const CATEGORIE_COMMUNE = '7210';

    /** Les champs écrits, et leur clé de bilan. */
    public const CHAMPS = ['email_generic' => 'emails', 'phone' => 'telephones', 'website' => 'sites'];

    /** Compteurs du bilan, dans l'ordre d'affichage. */
    public const COMPTEURS = [
        'lus', 'sans_coordonnees', 'rapproches_siret', 'rapproches_siren', 'rapproches_commune',
        'non_rapproches', 'ambigus', 'doublons', 'exclues_non_diffusibles', 'hors_secteur_public',
        'emails_ajoutes', 'telephones_ajoutes', 'sites_ajoutes',
        'emails_mis_a_jour', 'telephones_mis_a_jour', 'sites_mis_a_jour',
        'inchanges', 'sites_confirmes', 'conflits', 'propositions_deja_faites', 'lignes_malformees',
    ];

    /** @var array<string, int> */
    private array $bilan = [];

    private string $workspaceId = '';

    private bool $essai = false;

    private string $cle = '';

    /** @var array<int, int> SIRET → nombre d'organismes */
    private array $sirets = [];

    /** @var array<int, int> SIREN (organismes sans SIRET) → nombre */
    private array $sirens = [];

    /** @var array<string, int> code commune → nombre de mairies */
    private array $mairies = [];

    /** @var array<string, int> code commune → fiche de commune (-1 : ambigu) */
    private array $communes = [];

    /** @var array<int, true> fiches déjà servies dans ce passage */
    private array $servies = [];

    /** @var list<OrganismeAnnuaire> */
    private array $paquet = [];

    public function __construct(private readonly SourceAnnuaire $source) {}

    /**
     * @param  int  $limite  organismes lus au plus (0 = sans limite) ; le passage reprendra
     * @param  ?CarbonInterface  $arret  instant d'arrêt propre (fin de fenêtre) ; null = aucun
     * @param  ?callable(string): void  $journal
     * @return array{statut: string, reprise: bool, curseur: int, bilan: array<string, int>, avant: array<string, int>, apres: ?array<string, int>}
     */
    public function executer(
        string $workspaceId,
        string $url,
        bool $essai = false,
        int $limite = 0,
        ?CarbonInterface $arret = null,
        ?callable $journal = null,
    ): array {
        $this->workspaceId = $workspaceId;
        $this->essai = $essai;
        $this->bilan = array_fill_keys(self::COMPTEURS, 0);
        $this->sirets = $this->sirens = $this->mairies = $this->communes = $this->servies = [];
        $this->paquet = [];
        $this->cle = self::cleDuMois(now());
        $journal ??= static function (string $ligne): void {};

        return WorkspaceContext::run($workspaceId, function () use ($url, $limite, $arret, $journal): array {
            $avant = self::compter($this->workspaceId);
            $depart = $this->essai ? 0 : (CurseurTraitement::lire($this->workspaceId, $this->cle) ?? 0);
            if ($depart > 0) {
                $journal("Reprise du passage du mois après la ligne {$depart}.");
            }

            $chemin = tempnam(sys_get_temp_dir(), 'annuaire-sp-');
            if ($chemin === false) {
                throw new RuntimeException('Impossible de créer un fichier dans le dossier temporaire.');
            }
            try {
                $this->source->telecharger($url, $chemin);
                $this->indexer($chemin);
                $this->chargerCommunes();
                $journal(sprintf(
                    'Annuaire indexé : %d SIRET, %d SIREN seuls, %d codes de mairie ; %d codes de commune sûrs dans le CRM.',
                    count($this->sirets),
                    count($this->sirens),
                    count($this->mairies),
                    count(array_filter($this->communes, static fn (int $id): bool => $id > 0)),
                ));
                [$curseur, $termine] = $this->lire($chemin, $depart, max(0, $limite), $arret, $journal);
            } catch (\Throwable $e) {
                Log::error('crm:public:annuaire-officiel : passage interrompu', ['erreur' => $e->getMessage()]);

                throw $e;
            } finally {
                if (is_file($chemin)) {
                    @unlink($chemin);
                }
            }

            if ($termine && ! $this->essai) {
                CurseurTraitement::ecrire($this->workspaceId, $this->cle . ':fin', 1);
            }

            return [
                'statut' => $termine ? 'reussie' : 'en_cours',
                'reprise' => $depart > 0,
                'curseur' => $curseur,
                'bilan' => $this->bilan,
                'avant' => $avant,
                'apres' => $this->essai ? null : self::compter($this->workspaceId),
            ];
        });
    }

    /** La clé du curseur du mois (Paris), ex. `annuaire-service-public:2026-10`. */
    public static function cleDuMois(CarbonInterface $instant): string
    {
        return self::TRAITEMENT . ':' . CarbonImmutable::instance($instant)->setTimezone('Europe/Paris')->format('Y-m');
    }

    /** Premier jour du mois où le passage planifié peut partir : APRÈS la mise à jour INSEE du 4. */
    public const JOUR_DU_MOIS = 6;

    /**
     * Jour et heure de la planification : à partir du 6 du mois
     * (`JOUR_DU_MOIS`, la mise à jour INSEE tourne le 4 et reprend le 5),
     * tous les jours de la semaine, entre 08:00 et 19:00 heure de Paris.
     */
    public static function estJourPlanifie(CarbonInterface $instant): bool
    {
        $t = CarbonImmutable::instance($instant)->setTimezone('Europe/Paris');

        return $t->day >= self::JOUR_DU_MOIS && $t->hour >= 8 && $t->hour < 19;
    }

    /** Le passage du mois est-il fini dans cet espace ? */
    public static function moisTermine(string $workspaceId, CarbonInterface $instant): bool
    {
        return WorkspaceContext::run($workspaceId, static fn (): bool => (CurseurTraitement::lire($workspaceId, self::cleDuMois($instant) . ':fin') ?? 0) > 0);
    }

    /**
     * Les fiches du secteur public de l'espace et leurs coordonnées — le
     * comptage avant / après.
     *
     * @return array{fiches: int, avec_email: int, avec_telephone: int, avec_site: int}
     */
    public static function compter(string $workspaceId): array
    {
        $r = DB::selectOne(
            "SELECT count(*) AS fiches,
                    count(*) FILTER (WHERE email_generic IS NOT NULL AND btrim(email_generic) <> '') AS avec_email,
                    count(*) FILTER (WHERE phone IS NOT NULL AND btrim(phone) <> '') AS avec_telephone,
                    count(*) FILTER (WHERE website IS NOT NULL AND btrim(website) <> '') AS avec_site
               FROM companies
              WHERE workspace_id = ? AND deleted_at IS NULL AND legal_form LIKE '7%'",
            [$workspaceId],
        );

        return [
            'fiches' => (int) ($r->fiches ?? 0),
            'avec_email' => (int) ($r->avec_email ?? 0),
            'avec_telephone' => (int) ($r->avec_telephone ?? 0),
            'avec_site' => (int) ($r->avec_site ?? 0),
        ];
    }

    // ── Première lecture : les index d'unicité ───────────────────────────

    private function indexer(string $chemin): void
    {
        $this->parcourirFichier($chemin, function (?OrganismeAnnuaire $o, bool $malformee): bool {
            if ($o === null) {
                return true;
            }
            if (($s = $o->cleSiret()) !== null) {
                $this->sirets[$s] = ($this->sirets[$s] ?? 0) + 1;
            } elseif (($s = $o->cleSiren()) !== null) {
                $this->sirens[$s] = ($this->sirens[$s] ?? 0) + 1;
            }
            if ($o->codeMairie !== null) {
                $this->mairies[$o->codeMairie] = ($this->mairies[$o->codeMairie] ?? 0) + 1;
            }

            return true;
        });
    }

    /**
     * Les fiches de COMMUNE (7210) du CRM, par code commune sûr. Deux fiches
     * pour un même code, ou une fiche dont `commune_code` et `insee`
     * diffèrent : ambigu (-1), jamais rapproché.
     */
    private function chargerCommunes(): void
    {
        $lignes = DB::table('companies')
            ->where('workspace_id', $this->workspaceId)
            ->where('legal_form', self::CATEGORIE_COMMUNE)
            ->whereNull('deleted_at')
            ->select(['id', 'commune_code', 'insee'])
            ->lazyById(5000, 'id');
        foreach ($lignes as $f) {
            $a = strtoupper(trim((string) ($f->commune_code ?? '')));
            $b = strtoupper(trim((string) ($f->insee ?? '')));
            if ($a !== '' && $b !== '' && $a !== $b) {
                foreach ([$a, $b] as $code) {
                    $this->communes[$code] = -1;
                }

                continue;
            }
            $code = $a !== '' ? $a : $b;
            if (preg_match('/^(\d{2}|2A|2B)[0-9]{3}$/', $code) !== 1) {
                continue;
            }
            $this->communes[$code] = isset($this->communes[$code]) ? -1 : (int) $f->id;
        }
    }

    // ── Seconde lecture : rapprochement et écriture ──────────────────────

    /** @return array{0: int, 1: bool} [curseur, fini] */
    private function lire(string $chemin, int $depart, int $limite, ?CarbonInterface $arret, callable $journal): array
    {
        $ligne = 0;
        $traitees = 0;
        $interrompu = false;
        $this->parcourirFichier($chemin, function (?OrganismeAnnuaire $o, bool $malformee) use (&$ligne, &$traitees, &$interrompu, $depart, $limite, $arret, $journal): bool {
            $ligne++;
            if ($ligne <= $depart) {
                return true;
            }
            if (($limite > 0 && $traitees >= $limite)
                || ($arret !== null && $traitees % self::TAILLE_PAQUET === 0 && now()->greaterThanOrEqualTo($arret))) {
                $interrompu = true;
                $ligne--;

                return false;
            }
            $traitees++;
            $this->bilan['lus']++;
            if ($o === null) {
                if ($malformee) {
                    $this->bilan['lignes_malformees']++;
                }

                return true;
            }
            if (! $o->aDesCoordonnees()) {
                $this->bilan['sans_coordonnees']++;

                return true;
            }
            $this->paquet[] = $o;
            if (count($this->paquet) >= self::TAILLE_PAQUET) {
                $this->vider($ligne);
                if ($this->bilan['lus'] % 10000 < self::TAILLE_PAQUET) {
                    $journal(sprintf('  … ligne %d — %d rapprochés', $ligne, $this->rapproches()));
                }
            }

            return true;
        });
        $this->vider(max($ligne, $depart));

        return [max($ligne, $depart), ! $interrompu];
    }

    /**
     * Lit le fichier ligne par ligne (jamais plus de `LONGUEUR_MAX_LIGNE`
     * octets en mémoire) ; `$rappel` reçoit l'organisme (ou null) et rend
     * false pour s'arrêter. Les lignes vides sont sautées sans être
     * comptées.
     *
     * @param  callable(?OrganismeAnnuaire, bool): bool  $rappel
     */
    private function parcourirFichier(string $chemin, callable $rappel): void
    {
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            throw new RuntimeException('Fichier de l\'annuaire illisible.');
        }
        try {
            while (($brute = fgets($flux, self::LONGUEUR_MAX_LIGNE + 1)) !== false) {
                $tropLongue = false;
                if (! str_ends_with($brute, "\n") && ! feof($flux)) {
                    do {
                        $reste = fgets($flux, self::LONGUEUR_MAX_LIGNE + 1);
                    } while ($reste !== false && ! str_ends_with($reste, "\n"));
                    $tropLongue = true;
                }
                $brute = trim($brute);
                if ($brute === '' && ! $tropLongue) {
                    continue;
                }
                $organisme = $tropLongue ? null : OrganismeAnnuaire::depuisLigne($brute);
                if (! $rappel($organisme, $organisme === null)) {
                    return;
                }
            }
        } finally {
            fclose($flux);
        }
    }

    private function rapproches(): int
    {
        return $this->bilan['rapproches_siret'] + $this->bilan['rapproches_siren'] + $this->bilan['rapproches_commune'];
    }

    /** Rapproche et écrit le paquet, et avance le curseur, dans UNE transaction. */
    private function vider(int $ligne): void
    {
        $paquet = $this->paquet;
        $this->paquet = [];
        $traiter = function () use ($paquet, $ligne): void {
            if ($paquet !== []) {
                $this->traiterPaquet($paquet);
            }
            if (! $this->essai) {
                CurseurTraitement::ecrire($this->workspaceId, $this->cle, $ligne);
            }
        };
        if ($this->essai) {
            $traiter();
        } else {
            DB::transaction($traiter);
        }
    }

    /** @param  list<OrganismeAnnuaire>  $paquet */
    private function traiterPaquet(array $paquet): void
    {
        // 1. Les fiches candidates, par l'index unique (workspace_id, siren).
        $sirens = [];
        foreach ($paquet as $o) {
            if ($o->siren !== null) {
                $sirens[$o->siren] = true;
            }
        }
        $parSiret = [];
        $parSiren = [];
        if ($sirens !== []) {
            $trouvees = DB::table('companies')
                ->where('workspace_id', $this->workspaceId)
                ->whereIn('siren', array_keys($sirens))
                ->whereNull('deleted_at')
                ->get(['id', 'siren', 'siret']);
            foreach ($trouvees as $f) {
                $parSiren[(string) $f->siren] = (int) $f->id;
                if ($f->siret !== null && trim((string) $f->siret) !== '') {
                    $parSiret[trim((string) $f->siret)] = (int) $f->id;
                }
            }
        }

        // 2. Chaque organisme → au plus une fiche, par une règle certaine.
        $rapprochements = [];
        foreach ($paquet as $o) {
            [$id, $mode] = $this->rapprocher($o, $parSiret, $parSiren);
            if ($id === null) {
                $this->bilan[$mode]++;

                continue;
            }
            if (isset($this->servies[$id])) {
                $this->bilan['doublons']++;

                continue;
            }
            $this->servies[$id] = true;
            $rapprochements[$id] = [$o, $mode];
        }
        if ($rapprochements === []) {
            return;
        }

        // 3. Les fiches, verrouillées pour la durée du paquet.
        $requete = DB::table('companies')
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('id', array_keys($rapprochements))
            ->whereNull('deleted_at');
        if (! $this->essai) {
            $requete->lockForUpdate();
        }
        $fiches = $requete->get(['id', 'legal_form', 'insee_non_diffusible_le', 'email_generic', 'phone', 'website', 'website_method', 'metadata', 'field_origins'])->keyBy('id');
        $protegees = $this->protegees(array_keys($rapprochements));

        foreach ($rapprochements as $id => [$o, $mode]) {
            $fiche = $fiches->get($id);
            if (! $fiche instanceof stdClass) {
                $this->bilan['non_rapproches']++;

                continue;
            }
            if (! str_starts_with((string) $fiche->legal_form, '7')) {
                $this->bilan['hors_secteur_public']++;

                continue;
            }
            if ($fiche->insee_non_diffusible_le !== null) {
                $this->bilan['exclues_non_diffusibles']++;

                continue;
            }
            $this->bilan[$mode]++;
            $this->enrichir($fiche, $o, isset($protegees[$id]));
        }
    }

    /**
     * @param  array<string, int>  $parSiret
     * @param  array<string, int>  $parSiren
     * @return array{0: ?int, 1: string} [fiche, compteur]
     */
    private function rapprocher(OrganismeAnnuaire $o, array $parSiret, array $parSiren): array
    {
        $ambigu = false;
        if ($o->siret !== null) {
            if (($this->sirets[(int) $o->siret] ?? 0) > 1) {
                $ambigu = true;
            } elseif (isset($parSiret[$o->siret])) {
                return [$parSiret[$o->siret], 'rapproches_siret'];
            }
        } elseif ($o->siren !== null) {
            if (($this->sirens[(int) $o->siren] ?? 0) > 1) {
                $ambigu = true;
            } elseif (isset($parSiren[$o->siren])) {
                return [$parSiren[$o->siren], 'rapproches_siren'];
            }
        }

        // Mairie : par le code INSEE de commune, si tout est univoque.
        if ($o->codeMairie !== null && ! $ambigu) {
            if (($this->mairies[$o->codeMairie] ?? 0) > 1) {
                return [null, 'ambigus'];
            }
            $id = $this->communes[$o->codeMairie] ?? null;
            if ($id === -1) {
                return [null, 'ambigus'];
            }
            if ($id !== null) {
                return [$id, 'rapproches_commune'];
            }
        } elseif ($o->estMairie && $o->codeMairie === null && $o->siret === null && $o->siren === null) {
            return [null, 'ambigus']; // mairie à plusieurs codes de commune
        }

        return [null, $ambigu ? 'ambigus' : 'non_rapproches'];
    }

    private function enrichir(stdClass $fiche, OrganismeAnnuaire $o, bool $protegee): void
    {
        $origines = json_decode(is_string($fiche->field_origins ?? null) ? $fiche->field_origins : '{}', true);
        $origines = is_array($origines) ? $origines : [];
        $metadata = json_decode(is_string($fiche->metadata ?? null) ? $fiche->metadata : '{}', true);
        $metadata = is_array($metadata) ? $metadata : [];
        $valeurs = ['email_generic' => $o->email, 'phone' => $o->telephone, 'website' => $o->site];

        $maj = [];
        $siteConfirme = false;
        foreach (self::CHAMPS as $champ => $nom) {
            $nouvelle = $valeurs[$champ];
            if ($nouvelle === null) {
                continue;
            }
            $actuelle = trim((string) ($fiche->{$champ} ?? ''));
            $origine = is_string($origines[$champ] ?? null) ? $origines[$champ] : null;
            $bloque = $protegee || $origine === 'declared';

            if ($actuelle !== '' && self::memeValeur($champ, $actuelle, $nouvelle)) {
                $this->bilan['inchanges']++;
                if ($champ === 'website' && SiteFiable::estNonVerifie($fiche->website_method, $metadata)) {
                    $siteConfirme = true;
                }

                continue;
            }
            if ($actuelle === '' && ! $bloque) {
                $maj[$champ] = $nouvelle;
                $this->bilan[$nom . '_ajoutes']++;

                continue;
            }
            if ($actuelle !== '' && ! $bloque && $origine === self::ORIGINE) {
                $maj[$champ] = $nouvelle;
                $this->bilan[$nom . '_mis_a_jour']++;

                continue;
            }

            // Conflit (ou champ protégé) : une proposition, jamais d'écrasement.
            if ($this->essai) {
                $this->bilan['conflits']++;

                continue;
            }
            $resultat = Propositions::proposerSourceOfficielle(
                $this->workspaceId,
                Propositions::ENTREPRISE,
                (int) $fiche->id,
                $champ,
                $actuelle === '' ? null : $actuelle,
                $nouvelle,
                self::ORIGINE,
                $o->identifiant,
            );
            $this->bilan[$resultat === Propositions::PROPOSEE ? 'conflits' : 'propositions_deja_faites']++;
        }

        if ($maj === [] && ! $siteConfirme) {
            return;
        }
        if ($siteConfirme) {
            $this->bilan['sites_confirmes']++;
        }
        if ($this->essai) {
            return;
        }

        $aujourdhui = now()->toDateString();
        foreach (array_keys($maj) as $champ) {
            $origines[$champ] = self::ORIGINE;
        }
        $ecriture = $maj;
        if (isset($maj['website'])) {
            $ecriture['website_method'] = self::ORIGINE;
        }
        if ($maj !== []) {
            $ecriture['field_origins'] = json_encode($origines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $metadata['annuaire_service_public'] = ['id' => $o->identifiant, 'le' => $aujourdhui];
        if ($siteConfirme) {
            $metadata[SiteFiable::CLE] = [
                'statut' => 'verifie',
                'url' => (string) $fiche->website,
                'preuve' => self::ORIGINE,
                'le' => $aujourdhui,
                'v' => VerificationSite::VERSION,
            ];
        }
        $ecriture['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $ecriture['updated_at'] = now();

        DB::table('companies')->where('workspace_id', $this->workspaceId)->where('id', (int) $fiche->id)->update($ecriture);
    }

    /** Deux écritures d'une même valeur (casse d'une adresse, forme d'un numéro, `/` final d'un site). */
    public static function memeValeur(string $champ, string $a, string $b): bool
    {
        return match ($champ) {
            'phone' => (ListeSuppression::variantesTelephone($a)[0] ?? $a) === (ListeSuppression::variantesTelephone($b)[0] ?? $b),
            'email_generic' => mb_strtolower(trim($a)) === mb_strtolower(trim($b)),
            'website' => self::siteComparable($a) === self::siteComparable($b),
            default => trim($a) === trim($b),
        };
    }

    private static function siteComparable(string $site): string
    {
        $s = mb_strtolower(trim($site));
        $s = (string) preg_replace('#^https?://(www\.)?#', '', $s);

        return rtrim($s, '/');
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function protegees(array $ids): array
    {
        $protegees = DB::table('company_tag')
            ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->whereIn('company_tag.company_id', $ids)
            ->whereIn('tags.slug', FichesProtegees::TAGS)
            ->pluck('company_tag.company_id');

        return array_fill_keys(array_map('intval', $protegees->all()), true);
    }
}
