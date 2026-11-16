<?php

namespace App\Envoi\FeuVert;

use App\Console\Commands\EnvoiTemoinsReleve;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\JourEnvoi;
use App\Envoi\Sante\DepotSante;
use App\Envoi\Temoins\DepotAccesTemoins;
use App\Envoi\Temoins\HotesTemoins;
use App\Envoi\Temoins\ReleveTemoins;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feu vert : liste CALCULÉE (L8-F04). Lecture seule : elle ne signe jamais, n'écrit jamais `envoi_drapeaux`,
 * ne change aucun réglage. Points automatiques = lus dans le système ; points manuels = jamais automatiques,
 * « fait » seulement si une preuve a été déposée ET que le point n'attend pas le vrai serveur
 * (un point qui l'attend reste non fait et bloquant, sa preuve restant affichée).
 *
 * Catalogue versionné unique CatalogueManuels : `auto.*` (calculés) et `manuel.*` (spécification 26 § 8, 18 points).
 */
class ListeFeuVert
{
    public const PREMIER = CatalogueManuels::PREMIER;

    /** Point qui décrit le drapeau d'exploitation : ne bloque jamais « accorde », absent de l'empreinte (D12). */
    public const POINT_EXPLOITATION = 'auto.reel_actif_faux';

    public function __construct(private readonly DepotPreuves $preuves, private readonly DepotSante $sante) {}

    /** @return list<PointFeuVert> */
    public function points(string $ws): array
    {
        $d = WorkspaceContext::run($ws, fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first());
        $auto = static fn (string $code, bool $fait, string $ok, string $ko): PointFeuVert => self::pointAuto($code, $fait, $fait ? $ok : $ko);

        $dns = $this->sante->controlesDns($ws);
        $listes = $this->sante->controlesListes($ws);
        $dnsOk = $dns !== [] && array_filter($dns, static fn (array $c): bool => ! $c['pret'] || $c['pause']) === [];
        $listesOk = $listes !== [] && array_filter($listes, static fn (array $c): bool => $c['statut'] !== 'non_liste' || $c['pause']) === [];

        $points = [
            $auto('auto.reel_actif_faux', config('envoi.reel_actif') === false, 'ENVOI_REEL_ACTIF est faux.', 'L’envoi réel est actif.'),
            $auto('auto.mode_temoins_vrai', $d !== null && (bool) $d->mode_temoins, 'Le mode témoins est actif.', 'Mode témoins inactif ou drapeaux absents.'),
            $auto('auto.signature_absente', $d !== null && $d->feu_vert_signature === null, 'Aucune signature posée.', $d === null ? 'Drapeaux absents.' : 'Une signature existe déjà.'),
            $this->temoins($ws),
            $this->troisJours($ws),
            // X5 : signaux STRUCTURELS en lecture seule (aucune exécution réelle ne peut servir de preuve avant l'ouverture).
            $this->freinTemoinsBranche($ws),
            $this->freinReponsesBaisse($ws),
            $this->separation($ws),
            $auto('auto.arret_entreprise', ! self::estAbsent(app(ArretParEntreprise::class), ArretParEntrepriseAbsent::class), 'Implémentation réelle liée.', 'Implémentation « absente » liée.'),
            $auto('auto.retrait_file', ! self::estAbsent(app(RetraitEnvoisEnFile::class), RetraitEnvoisEnFileAbsent::class), 'Implémentation réelle liée.', 'Implémentation « absente » liée.'),
            // Mêmes règles que la vraie garde (JetonDesinscription) : jamais une copie.
            $auto('auto.cle_desinscription', JetonDesinscription::cleConfigureeValide(), 'Clé posée, longueur suffisante.', 'Clé absente ou trop courte.'),
            $auto('auto.hotes_desinscription', JetonDesinscription::hotesConfiguresValides(), 'Hôte (app ou api) et deux adresses valides posés.', 'Hôte inconnu, ou adresse app/api absente ou invalide.'),
            $auto('auto.releve_imap', config('envoi.releve_actif', false) === true, 'Interrupteur de relève actif.', 'Interrupteur de relève éteint ou absent (fiche F12).'),
            $auto('auto.domaines_prets', $dnsOk, 'Tous les domaines contrôlés sont prêts.', 'Aucun contrôle, ou un domaine pas prêt / en pause.'),
            $auto('auto.listes_noires', $listesOk, 'Aucune ressource listée.', 'Aucun contrôle, ou une ressource listée / non fiable / en pause.'),
            $auto('auto.supervision_k15', config('envoi.sante.actif') === true, 'Supervision active.', 'Supervision éteinte.'),
            $this->essaiABlancRecent($ws),
            $this->buteeEnBase(),
            $this->adaptateursDomaines($ws),
        ];

        $preuves = $this->preuves->dernieres($ws);
        foreach (CatalogueManuels::codesManuels() as $code) {
            $p = $preuves[$code] ?? null;
            $depose = $p !== null && $p['fait'];
            $c = CatalogueManuels::POINTS[$code];
            // Aucun signal « K17 fait » n'existe encore dans le code : par prudence, un point qui exige le vrai serveur attend toujours.
            // Règle : `fait` = (`etat` == « fait »). Un point qui attend le vrai serveur n'est PAS fait (donc bloquant),
            // même avec une preuve déposée, qui reste affichée.
            $attend = $c['serveur_reel'];
            $etat = $attend ? PointFeuVert::ETAT_ATTEND_SERVEUR : ($depose ? PointFeuVert::ETAT_FAIT : PointFeuVert::ETAT_A_FAIRE);
            $points[] = new PointFeuVert(
                $code,
                $c['libelle'],
                false,
                $etat === PointFeuVert::ETAT_FAIT,
                self::detailManuel($attend, $depose),
                $c['source'],
                $c['groupe'],
                $etat,
                $depose ? $p['preuve'] : null,
                $depose ? $p['par'] : null,
                $depose ? $p['le'] : null,
            );
        }

        return $points;
    }

    /** « Vrai serveur » seulement si le point attend le serveur réel (dépend de `$attend`). */
    private static function detailManuel(bool $attend, bool $fait): string
    {
        if ($attend) {
            return $fait ? 'Preuve déposée, à revalider avec le vrai serveur.' : 'Vérifiable seulement avec le vrai serveur.';
        }

        return $fait ? 'Preuve déposée.' : 'Preuve humaine à déposer.';
    }

    /**
     * Verrous en LECTURE SEULE (contrat VerrousFeuVert) : aucune écriture, aucune valeur secrète.
     *
     * @return array{envoi_reel_actif: bool, mode_temoins: bool, signature_presente: bool, signature_date: ?string, fournisseurs_temoins_couverts: int, fournisseurs_temoins_requis: int}
     */
    public function verrous(string $ws): array
    {
        $d = WorkspaceContext::run($ws, fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first());
        $requis = DepotSocleDb::FOURNISSEURS_TEMOINS;
        $presents = Schema::hasColumn('envoi_temoins', 'fournisseur')
            ? WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->where('workspace_id', $ws)->distinct()->pluck('fournisseur')->all())
            : [];

        return [
            'envoi_reel_actif' => config('envoi.reel_actif') !== false,
            'mode_temoins' => $d !== null && (bool) $d->mode_temoins,
            'signature_presente' => $d !== null && $d->feu_vert_signature !== null,
            'signature_date' => $d !== null && $d->feu_vert_date !== null ? substr((string) $d->feu_vert_date, 0, 10) : null,
            'fournisseurs_temoins_couverts' => count(array_intersect($requis, array_map('strval', $presents))),
            'fournisseurs_temoins_requis' => count($requis),
        ];
    }

    /**
     * Dernière décision de feu vert de l'espace (la plus grande `id` fait foi), ou null.
     *
     * @return array{id: int, decision: string, motif: string, le: string}|null
     */
    public function derniereDecision(string $ws): ?array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::selectOne('SELECT id, decision, motif, cree_le FROM envoi_feu_vert_decisions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1', [$ws]));

        return $l === null ? null : ['id' => (int) $l->id, 'decision' => (string) $l->decision, 'motif' => (string) $l->motif, 'le' => CarbonImmutable::parse((string) $l->cree_le)->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    /**
     * Empreinte de l'état exact affiché (D12) : points (code, fait, état, date de la preuve) + identifiant de la dernière décision (ou « aucune »).
     * Sans `auto.reel_actif_faux` (drapeau d'exploitation que le feu vert ne touche jamais) et sans aucun horodatage de calcul.
     *
     * @param  list<PointFeuVert>  $points
     */
    public function empreinte(string $ws, array $points): string
    {
        $lignes = [];
        foreach ($points as $p) {
            if ($p->code !== self::POINT_EXPLOITATION) {
                $lignes[] = $p->code . '|' . ($p->fait ? '1' : '0') . '|' . $p->etat . '|' . ($p->preuveLe ?? '');
            }
        }
        sort($lignes);

        return hash('sha256', implode("\n", $lignes) . "\ndecision=" . ($this->derniereDecision($ws)['id'] ?? 'aucune'));
    }

    /**
     * X5 : le frein « réponses en baisse » est évalué pour de bon. Vrai SEULEMENT si (a) `envoi:evaluer-freins` est planifiée au plus
     * toutes les 5 minutes, sans condition qui l'éteigne, ET (b) l'espace a un battement `evaluation_freins` d'aujourd'hui ou d'hier
     * (Paris), journalisé par la commande après une évaluation sans exception. Lecture seule : le feu vert reste une décision humaine.
     */
    private function freinReponsesBaisse(string $ws): PointFeuVert
    {
        $code = 'auto.frein_reponses_baisse';
        if (! $this->planifieeSansExtinction('envoi:evaluer-freins', 300)) {
            return self::pointAuto($code, false, 'L’évaluation des freins n’est pas planifiée au moins toutes les 5 minutes, ou une condition l’éteint.');
        }
        $aujourdhui = JourEnvoi::de();
        $jours = [$aujourdhui->format('Y-m-d'), $aujourdhui->modify('-1 day')->format('Y-m-d')];
        $battement = WorkspaceContext::run($ws, fn (): bool => DB::table('envoi_journal_commandes')
            ->where('workspace_id', $ws)->where('commande', 'evaluation_freins')->whereIn('jour', $jours)->exists());
        if (! $battement) {
            return self::pointAuto($code, false, 'Évaluation planifiée, mais aucun battement « evaluation_freins » d’aujourd’hui ou d’hier pour cet espace.');
        }

        return self::pointAuto($code, true, 'Évaluation des freins planifiée (5 minutes) et battement récent journalisé.');
    }

    /**
     * X5 : le frein « témoins » est branché. Vrai SEULEMENT si (a) l'interrupteur `envoi.temoins_releve_actif` est vrai (lu, jamais écrit),
     * (b) la relève `envoi:temoins-releve` est planifiée, ET (c) les accès des 6 fournisseurs de {@see HotesTemoins} sont tous déposés et lisibles.
     * Signal structurel : aucune exécution réelle n'est possible avant l'ouverture.
     */
    private function freinTemoinsBranche(string $ws): PointFeuVert
    {
        $code = 'auto.frein_temoins_branche';
        $suite = ' — exécution réelle prouvable seulement après ouverture : voir manuel.arrivee_boite.';
        if (config('envoi.temoins_releve_actif') !== true) {
            return self::pointAuto($code, false, 'Interrupteur de relève des témoins éteint.' . $suite);
        }
        if (! $this->evenementsPlanifies(EnvoiTemoinsReleve::SIGNATURE_PLANIFIEE)) {
            return self::pointAuto($code, false, 'Relève des témoins non planifiée.' . $suite);
        }
        $r = app(DepotAccesTemoins::class)->listerAvecPannes($ws);
        $couverts = array_values(array_unique(array_map(static fn ($a): string => $a->fournisseur, $r['acces'])));
        $manquants = array_values(array_diff(array_keys(HotesTemoins::LISTE), $couverts));
        if ($manquants !== [] || $r['illisibles'] > 0) {
            return self::pointAuto($code, false, count($manquants) . ' fournisseur(s) sans accès lisible, ' . $r['illisibles'] . ' accès illisible(s).' . $suite);
        }

        return self::pointAuto($code, true, 'Relève liée, planifiée, accès des 6 fournisseurs déposés et lisibles.' . $suite);
    }

    /** @return list<Event> événements planifiés dont la commande est exactement `$commande` (planificateur chargé même hors console). */
    private function evenementsPlanifies(string $commande): array
    {
        app(ConsoleKernel::class)->bootstrap(); // idempotent : charge routes/console.php si la requête n'est pas passée par la console
        $trouves = [];
        foreach (app(Schedule::class)->events() as $e) {
            if (is_string($e->command) && preg_match('/(^|[\s\'"])' . preg_quote($commande, '/') . '([\s\'"]|$)/', $e->command) === 1) {
                $trouves[] = $e;
            }
        }

        return $trouves;
    }

    /**
     * Planifiée au moins un événement, tous sans condition qui l'éteint (`when`, `skip`, environnements ; `withoutOverlapping` seul toléré),
     * avec un écart maximal de `$ecartMaxSecondes` entre deux passages sur les 8 prochains jours.
     */
    private function planifieeSansExtinction(string $commande, int $ecartMaxSecondes): bool
    {
        foreach ($this->evenementsPlanifies($commande) as $e) {
            $filtres = self::proprieteDe($e, 'filters');
            $rejets = self::proprieteDe($e, 'rejects');
            $environnements = self::proprieteDe($e, 'environments');
            if (! is_array($filtres) || ! is_array($rejets) || ! is_array($environnements)) {
                continue; // structure inattendue : prudence, rien ne libère
            }
            if ($filtres !== [] || $environnements !== [] || count($rejets) > ($e->withoutOverlapping === true ? 1 : 0)) {
                continue;
            }
            if (self::ecartMax($e->expression, $ecartMaxSecondes)) {
                return true;
            }
        }

        return false;
    }

    private static function proprieteDe(Event $e, string $nom): mixed
    {
        try {
            return (new \ReflectionProperty(Event::class, $nom))->getValue($e);
        } catch (\ReflectionException) {
            return null;
        }
    }

    /** Aucun trou de plus de `$max` secondes entre deux passages sur 8 jours (au plus 2 400 passages examinés). */
    private static function ecartMax(string $expression, int $max): bool
    {
        if (! CronExpression::isValidExpression($expression)) {
            return false;
        }
        $cron = new CronExpression($expression);
        $depart = new \DateTimeImmutable('now');
        $limite = $depart->modify('+8 days');
        $precedent = $depart;
        for ($i = 0; $i < 2400; $i++) {
            $prochain = \DateTimeImmutable::createFromInterface($cron->getNextRunDate($precedent));
            if ($prochain > $limite) {
                return $prochain->getTimestamp() - $precedent->getTimestamp() <= $max;
            }
            if ($prochain->getTimestamp() - $precedent->getTimestamp() > $max) {
                return false;
            }
            $precedent = $prochain;
        }

        return true;
    }

    /** Essai à blanc (D10) réussi, terminé depuis moins de 7 jours : un essai par semaine, voulu. */
    private function essaiABlancRecent(string $ws): PointFeuVert
    {
        $code = 'auto.essai_a_blanc_recent';
        $fin = WorkspaceContext::run($ws, fn () => DB::table('envoi_essais_blanc')->where('workspace_id', $ws)->where('reussi', true)->max('fin'));
        $maintenant = CarbonImmutable::now();
        $ok = $fin !== null && CarbonImmutable::parse((string) $fin)->greaterThanOrEqualTo($maintenant->subDays(7)) && CarbonImmutable::parse((string) $fin)->lessThanOrEqualTo($maintenant);

        return self::pointAuto($code, $ok, $ok ? 'Un essai à blanc réussi date de moins de 7 jours.' : 'Aucun essai à blanc réussi depuis moins de 7 jours.');
    }

    /** La butée de plafond par espace existe en base (fonction de la migration S1-A) : lecture du catalogue, aucune écriture. */
    private function buteeEnBase(): PointFeuVert
    {
        $presente = DB::selectOne("SELECT to_regprocedure('public.envoi_compteur_espace_reserver(uuid,date,integer)') IS NOT NULL AS p");
        $ok = $presente !== null && (bool) $presente->p;

        return self::pointAuto('auto.plafond_butee_base', $ok, $ok ? 'La butée de plafond est présente en base.' : 'La butée de plafond est absente de la base.');
    }

    /** Chaque domaine dont l'adaptateur n'est pas `faux` (D11) a un dernier contrôle DNS prêt, hors pause, daté d'aujourd'hui. */
    private function adaptateursDomaines(string $ws): PointFeuVert
    {
        $code = 'auto.adaptateurs_domaines';
        $reels = WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->where('adaptateur', '<>', 'faux')->pluck('nom')->map(fn ($n): string => (string) $n)->all());
        if ($reels === []) {
            return self::pointAuto($code, true, 'Aucun domaine n’utilise un adaptateur réel.');
        }
        $aujourdhui = JourEnvoi::de()->format('Y-m-d');
        $vert = [];
        foreach ($this->sante->controlesDns($ws) as $c) {
            $jour = JourEnvoi::de(CarbonImmutable::parse($c['controle_le'])->toDateTimeImmutable())->format('Y-m-d');
            if ($c['pret'] && ! $c['pause'] && $jour === $aujourdhui) {
                $vert[$c['domaine']] = true;
            }
        }
        $sans = count(array_filter($reels, static fn (string $d): bool => ! isset($vert[$d])));

        return self::pointAuto($code, $sans === 0, $sans === 0 ? 'Chaque domaine réel a un contrôle DNS vert du jour.' : $sans . ' domaine(s) à adaptateur réel sans contrôle DNS vert du jour.');
    }

    private static function pointAuto(string $code, bool $fait, string $detail): PointFeuVert
    {
        $c = CatalogueManuels::POINTS[$code];

        return new PointFeuVert($code, $c['libelle'], true, $fait, $detail, $c['source'], $c['groupe'], $fait ? PointFeuVert::ETAT_FAIT : PointFeuVert::ETAT_A_FAIRE);
    }

    /** @param class-string $absent */
    private static function estAbsent(object $liaison, string $absent): bool
    {
        return is_a($liaison, $absent);
    }

    private function temoins(string $ws): PointFeuVert
    {
        $code = 'auto.temoins_fournisseurs';
        if (! Schema::hasColumn('envoi_temoins', 'fournisseur')) {
            return self::pointAuto($code, false, 'Colonne fournisseur absente (fiche F03 pas encore posée).');
        }
        $presents = WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->where('workspace_id', $ws)->distinct()->pluck('fournisseur')->all());
        $manquants = array_values(array_diff(DepotSocleDb::FOURNISSEURS_TEMOINS, array_map('strval', $presents)));

        return self::pointAuto($code, $manquants === [], $manquants === [] ? 'Tous les fournisseurs témoins sont couverts.' : count($manquants) . ' fournisseur(s) sans témoin : ' . implode(', ', $manquants) . '.');
    }

    /**
     * F15 : les 6 fournisseurs verts le même jour (réception inbox, authentification alignée, bouton vu, POST effectif)
     * pendant 3 jours CONSÉCUTIFS se terminant aujourd'hui ou hier. La dernière ligne par jour et fournisseur fait foi, SAUF qu'un constat `releve_auto` « indesirables » rougit la journée
     * pour de bon (aucune saisie postérieure ne la reverdit) et qu'une ligne `releve_auto` ne verdit jamais rien ;
     * un jour rouge, incomplet ou manquant remet le compteur à zéro. Lecture seule.
     */
    private function troisJours(string $ws): PointFeuVert
    {
        $code = 'auto.temoins_3_jours';
        if (! Schema::hasTable('envoi_temoins_journees')) {
            return self::pointAuto($code, false, 'Table des journées témoins absente (fiche F15 pas encore posée).');
        }
        $aujourdhui = JourEnvoi::de();
        $depuis = $aujourdhui->modify('-4 days')->format('Y-m-d');
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')
            ->where('workspace_id', $ws)->where('jour', '>=', $depuis)
            ->orderBy('id')->get(['jour', 'fournisseur', 'reception', 'auth_alignee', 'bouton_vu', 'post_effectif', 'auteur']));
        $derniere = [];
        $rouges = []; // jours où la relève automatique a constaté un message en indésirables : rouges quoi qu'il arrive ensuite
        foreach ($lignes as $l) {
            if ((string) $l->auteur === ReleveTemoins::AUTEUR) {
                // Un constat automatique peut FREINER, jamais libérer : il rougit la journée et ne la verdit jamais.
                if ($l->reception === 'indesirables') {
                    $rouges[(string) $l->jour] = true;
                }

                continue;
            }
            $derniere[(string) $l->jour][(string) $l->fournisseur] = $l; // ordre croissant d'id : la dernière écrase
        }
        $vert = static function (string $jour) use ($derniere, $rouges): bool {
            if (isset($rouges[$jour])) {
                return false;
            }
            foreach (DepotSocleDb::FOURNISSEURS_TEMOINS as $f) {
                $l = $derniere[$jour][$f] ?? null;
                if ($l === null || $l->reception !== 'inbox' || ! $l->auth_alignee || ! $l->bouton_vu || ! $l->post_effectif) {
                    return false;
                }
            }

            return true;
        };
        $jour = $aujourdhui;
        // Aujourd'hui sans aucune ligne : on compte depuis hier. Aujourd'hui avec des lignes : il doit être vert.
        if (! isset($derniere[$jour->format('Y-m-d')]) && ! isset($rouges[$jour->format('Y-m-d')])) {
            $jour = $jour->modify('-1 day');
        }
        $suite = 0;
        while ($suite < 3 && $vert($jour->format('Y-m-d'))) {
            $suite++;
            $jour = $jour->modify('-1 day');
        }

        return self::pointAuto($code, $suite >= 3, $suite >= 3 ? 'Les 6 témoins sont verts depuis 3 jours consécutifs.' : $suite . ' jour(s) vert(s) consécutif(s) sur 3 requis.');
    }

    /**
     * A12 : lettre et prospection ne partagent ni domaine ni adresse IP. Les domaines doivent tous porter un flux
     * (colonne `flux` non NULL) ; les adresses IP n'ont aucune source lisible aujourd'hui : le point reste FAUX
     * (un constat peut freiner, jamais libérer). Lecture seule.
     */
    private function separation(string $ws): PointFeuVert
    {
        $code = 'auto.separation_lettre_prospection';
        if (! Schema::hasColumn('envoi_domaines', 'flux')) {
            return self::pointAuto($code, false, 'Colonne flux absente (migration R07 pas posée).');
        }
        $sans = WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->whereNull('flux')->count());
        if ($sans > 0) {
            return self::pointAuto($code, false, $sans . ' domaine(s) sans flux étiqueté (prospection ou lettre).');
        }

        return self::pointAuto($code, false, 'Flux étiquetés, mais la séparation des adresses IP n’a pas encore de source lisible.');
    }
}
