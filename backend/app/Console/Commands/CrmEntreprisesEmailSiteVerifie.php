<?php

namespace App\Console\Commands;

use App\Crm\FichesProtegees;
use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Propositions\Propositions;
use App\Crm\Scraping\EmailMxValidator;
use App\Crm\Sites\CurseurTraitement;
use App\Crm\Sites\EmailSiteVerifie;
use App\Crm\Sites\SiteFiable;
use App\Crm\Sites\VerificationSite;
use App\Services\Email\EmailConfidenceService;
use App\Support\EligibiliteCampagne;
use App\Support\WorkspaceContext;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use stdClass;
use Throwable;

/**
 * L'ADRESSE E-MAIL AFFICHÉE SUR LES SITES VÉRIFIÉS, PAR LOTS (décision du
 * propriétaire, 04/10/2026). Règles : `EmailSiteVerifie`.
 *
 * Portée : les fiches vivantes au site VÉRIFIÉ (SIREN prouvé,
 * `metadata.site_entreprise` `verifie` / `trouve-verifie`) ou de source
 * fiable (jamais deviné), SANS e-mail générique fiable (aucun, ou vérifié
 * `invalide` / `jetable`). Un site deviné non vérifié n'est JAMAIS lu.
 *
 * Pages lues : l'accueil (pour ses liens), la page de PREUVE (accueil ou
 * mentions légales) et au plus 2 pages « contact » du même site. Seules la
 * page de preuve et les pages contact sont fouillées.
 *
 * ── L'ÉCRITURE ───────────────────────────────────────────────────────────
 *
 * Une seule adresse par fiche : la GÉNÉRIQUE préférée, sur le domaine du
 * site, ni opposée (`EligibiliteCampagne::peutRecevoir`), ni sans courrier
 * (`EmailMxValidator`, la vérification DNS de la collecte, coupée par
 * `crm.scrape_funnel.validate_mx`). Une NOMINATIVE n'est jamais écrite.
 *
 *   - `email_generic` VIDE, champ non déclaré, fiche non protégée → écrite :
 *     `email_generic`, `field_origins.email_generic` = `site-verifie`,
 *     `signals.email_site_verifie` = {url de la page, date, type, preuve du
 *     site, version}, `best_email_confidence` relevée à la note de
 *     `EmailConfidenceService` (A : domaine du site) si elle était moins
 *     bonne ou absente ;
 *   - sinon (valeur présente, déclarée, fiche protégée) → PROPOSITION
 *     (`Propositions::proposerAutomatisme`, origine `site-verifie`) : la
 *     fiche reste INTACTE, le propriétaire décide.
 * Rien n'est jamais supprimé ni écrasé. L'adresse écrite n'est pas encore
 * « vérifiée » : `crm:emails:verifier` la juge ensuite, comme toute autre —
 * aucune règle d'envoi n'est contournée (`EligibiliteAdresse`).
 *
 * ── PRUDENCE : celle de `crm:entreprises:verifier-sites` ─────────────────
 *
 * Même lecteur (`LecturePageAccueil` : robots.txt, garde SSRF, corps ≤ 1,5 Mo,
 * redirections revérifiées), même concurrence (3), même délai par domaine
 * (1 500 ms), même délai d'expiration (5 s), même fenêtre (tous les jours,
 * 08:00-19:00, heure de Paris ; `--forcer`), même verrou consultatif : jamais
 * en même temps que la vérification des sites. Une page arrivée sur un AUTRE
 * domaine ne compte pas. Curseur persistant, mémoire constante (aucun corps
 * de page gardé au-delà de sa lecture), comptage avant = après.
 *
 * `--dry-run` : les sites sont lus, RIEN n'est écrit — ni adresse, ni
 * proposition, ni curseur ; le bilan dit ce qui l'aurait été.
 *
 * ⛔ NE PAS inscrire au calendrier (`routes/console.php`) : lancement à la
 * main, après un essai à blanc validé par Will.
 */
class CrmEntreprisesEmailSiteVerifie extends Command
{
    protected $signature = 'crm:entreprises:email-site-verifie
                            {--dry-run : Essai à blanc : les sites sont lus, rien n\'est écrit (ni adresse, ni proposition, ni curseur)}
                            {--limite= : Ne traiter que N fiches au plus}
                            {--jusqua= : Arrêt propre à HH:MM, heure de Paris (19:00 au plus sans --forcer)}
                            {--forcer : Passer outre la fenêtre de lancement (essai)}
                            {--depuis-debut : Repartir du début (le curseur revient à 0)}
                            {--paquet=40 : Fiches par paquet et par transaction (1 à 200)}
                            {--concurrence=3 : Requêtes HTTP simultanées au plus (1 à 6)}
                            {--delai-domaine-ms=1500 : Attente minimale entre deux requêtes d\'un même domaine (1000 à 60000)}
                            {--timeout=5 : Délai d\'attente d\'une requête, en secondes (1 à 10)}';

    protected $description = 'Relève l\'adresse e-mail générique affichée sur les sites vérifiés (SIREN prouvé), par lots, avec reprise au curseur. N\'écrase rien.';

    /** Le verrou de `crm:entreprises:verifier-sites` : un seul traitement lourd de sites à la fois. */
    private const VERROU = 'crm:entreprises:verifier-sites:';

    private const DELAI_MIN_MS = 1000;

    /** Libellés du rapport, dans l'ordre d'affichage. */
    private const LIBELLES = [
        'fiches_lues' => 'fiches lues',
        'sites_lus' => 'sites lus',
        'pages_lues' => 'pages lues (preuve et contact)',
        'generiques' => 'génériques trouvées',
        'nominatives' => 'nominatives trouvées (jamais écrites)',
        'rejet_autre-domaine' => 'rejetées : autre domaine',
        'rejet_noreply' => 'rejetées : noreply',
        'rejet_exemple' => 'rejetées : exemple',
        'rejet_image' => 'rejetées : image',
        'rejet_technique' => 'rejetées : adresse technique',
        'rejet_jetable' => 'rejetées : jetable',
        'rejet_syntaxe' => 'rejetées : syntaxe',
        'rejet_mx' => 'rejetées : domaine sans courrier (MX)',
        'rejet_opposition' => 'rejetées : opposition',
        'ecrites' => 'adresses écrites',
        'conflits' => 'conflits → propositions',
        'deja_proposees' => 'conflits déjà proposés',
        'identiques' => 'identiques à la valeur en place',
        'sans_generique' => 'fiches sans générique retenue',
        'redirections' => 'pages refusées (redirection hors domaine)',
        'robots' => 'pages ignorées (robots.txt)',
        'injoignables' => 'pages injoignables',
        'marqueur_autre_site' => 'ignorées (marqueur d\'un autre site)',
        'plateformes' => 'ignorées (site sur une plateforme partagée)',
        'ecartes' => 'écartées (fiche modifiée pendant la lecture)',
        'erreurs' => 'erreurs',
        'paquets' => 'paquets validés',
    ];

    /** @var array<string, int> */
    private array $bilan = [];

    private string $workspaceId = '';

    private EmailMxValidator $mx;

    private EmailConfidenceService $confiance;

    private Propositions $propositions;

    public function handle(EmailMxValidator $mx, EmailConfidenceService $confiance, Propositions $propositions): int
    {
        $this->mx = $mx;
        $this->confiance = $confiance;
        $this->propositions = $propositions;
        $dryRun = (bool) $this->option('dry-run');
        $forcer = (bool) $this->option('forcer');
        $limite = $this->entier('limite', 1, PHP_INT_MAX);
        $paquet = $this->entier('paquet', 1, 200);
        $concurrence = $this->entier('concurrence', 1, 6);
        $delai = $this->entier('delai-domaine-ms', self::DELAI_MIN_MS, 60000);
        $timeout = $this->entier('timeout', 1, 10);
        $jusqua = $this->option('jusqua');
        if ($limite === false || $paquet === false || $paquet === null || $concurrence === false || $concurrence === null
            || $delai === false || $delai === null || $timeout === false || $timeout === null
            || ($jusqua !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jusqua) !== 1)) {
            $this->error('Options invalides (--limite ≥ 1, --paquet 1 à 200, --concurrence 1 à 6, --delai-domaine-ms 1000 à 60000, --timeout 1 à 10, --jusqua HH:MM).');

            return self::FAILURE;
        }

        $maintenant = Carbon::now(VerificationSite::FUSEAU);
        if (! $forcer && ($raison = VerificationSite::horsFenetre($maintenant)) !== null) {
            $this->error("Refus de démarrer : hors fenêtre ({$raison}). --forcer pour un essai.");

            return self::FAILURE;
        }
        $fin = $this->heureDArret($maintenant, $jusqua, $forcer);

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error('Espace business introuvable.');

            return self::FAILURE;
        }
        $this->workspaceId = (string) $workspaceId;

        return WorkspaceContext::run($this->workspaceId, function () use ($dryRun, $limite, $paquet, $concurrence, $delai, $timeout, $fin): int {
            $verrou = self::VERROU . $this->workspaceId;
            if (! (bool) DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS ok', [$verrou])->ok) {
                $this->error('Un autre traitement des sites est en cours (vérification ou relevé) : refus.');

                return self::FAILURE;
            }
            try {
                $dormir = static function (int $ms): void {
                    if ($ms > 0) {
                        Sleep::usleep($ms * 1000);
                    }
                };
                $lecteur = new LecturePageAccueil($concurrence, $timeout, $delai, $dormir, CrmEntreprisesVerifierSites::USER_AGENT);

                return $this->parcourir($dryRun, $limite, $paquet, $lecteur, $fin);
            } finally {
                DB::select('SELECT pg_advisory_unlock(hashtext(?))', [$verrou]);
            }
        });
    }

    private function parcourir(bool $dryRun, ?int $limite, int $paquet, LecturePageAccueil $lecteur, ?CarbonInterface $fin): int
    {
        $cle = EmailSiteVerifie::TRAITEMENT;
        $this->bilan = array_fill_keys(array_keys(self::LIBELLES), 0);

        if ((bool) $this->option('depuis-debut') && ! $dryRun) {
            CurseurTraitement::remettreAZero($this->workspaceId, $cle);
        }
        $depart = (bool) $this->option('depuis-debut') ? 0 : (CurseurTraitement::lire($this->workspaceId, $cle) ?? 0);
        $plafond = (int) (DB::selectOne('SELECT max(id) AS m FROM companies WHERE workspace_id = ?', [$this->workspaceId])->m ?? 0);
        $avant = $this->compterFiches($depart, $plafond);
        $curseur = $depart;
        $arretHeure = false;
        $termine = false;
        $interruption = null;
        try {
            while (true) {
                if ($fin !== null && Carbon::now(VerificationSite::FUSEAU)->greaterThanOrEqualTo($fin)) {
                    $arretHeure = true;
                    break;
                }
                $combien = $limite === null ? $paquet : min($paquet, $limite - $this->bilan['fiches_lues']);
                if ($combien <= 0) {
                    break;
                }
                [$sql, $liaisons] = EmailSiteVerifie::selectionSql($this->workspaceId, $curseur, $combien);
                $fiches = array_values(DB::select($sql, $liaisons));
                if ($fiches === []) {
                    $termine = true;
                    break;
                }
                $this->traiterPaquet($fiches, $lecteur, $cle, $dryRun);
                $curseur = (int) $fiches[count($fiches) - 1]->id;
            }
        } catch (Throwable $e) {
            $interruption = $e;
        }
        $apres = $this->compterFiches($depart, $plafond);

        Log::info('crm.entreprises.email_site_verifie', $this->bilan + [
            'dry_run' => $dryRun, 'curseur' => $curseur, 'lignes_avant' => $avant['toutes'], 'lignes_apres' => $apres['toutes'],
            'vivantes_avant' => $avant['vivantes'], 'vivantes_apres' => $apres['vivantes'],
        ]);
        $this->info($dryRun
            ? '[À BLANC] les sites ont été lus, rien n\'a été écrit (ni adresse, ni proposition, ni curseur) : les compteurs disent ce qui l\'aurait été.'
            : 'Relevé appliqué.');
        $lignes = [];
        foreach (self::LIBELLES as $k => $libelle) {
            $lignes[] = [$libelle, $this->bilan[$k]];
        }
        $lignes[] = ['lignes avant (plage parcourue, corbeille comprise)', $avant['toutes']];
        $lignes[] = ['lignes après (plage parcourue, corbeille comprise)', $apres['toutes']];
        $lignes[] = ['fiches vivantes avant', $avant['vivantes']];
        $lignes[] = ['fiches vivantes après', $apres['vivantes']];
        $this->table(['compteur', 'nombre'], $lignes);
        $this->line("Curseur : fiche {$depart} → fiche {$curseur}.");
        if ($arretHeure && $fin !== null) {
            $this->warn('Arrêt à ' . $fin->format('H:i') . " (heure de Paris) : {$this->bilan['paquets']} paquet(s) validé(s), reprise au curseur au prochain lancement.");
        } elseif ($termine) {
            $this->line('Parcours terminé (--depuis-debut pour le reprendre).');
        }

        if ($avant !== $apres) {
            $this->error("Comptage des fiches DIFFÉRENT : {$avant['toutes']} avant, {$apres['toutes']} après ({$avant['vivantes']} → {$apres['vivantes']} vivantes). Ce traitement ne crée, ne supprime ni ne met à la corbeille rien : à examiner.");

            return self::FAILURE;
        }
        $this->line("Comptage des fiches : identique ({$avant['toutes']} avant, {$apres['toutes']} après ; {$apres['vivantes']} vivantes).");

        if ($interruption !== null) {
            $this->error('INTERROMPU : ' . $interruption::class . ". Les paquets validés restent ; reprise au curseur (fiche {$curseur}).");
            Log::error('crm:entreprises:email-site-verifie interrompu', ['exception' => $interruption::class, 'curseur' => $curseur]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param  list<stdClass>  $fiches */
    private function traiterPaquet(array $fiches, LecturePageAccueil $lecteur, string $cle, bool $dryRun): void
    {
        $delta = [];

        // 1. L'accueil de chaque site (réseau HORS transaction) : ses liens.
        $aLire = [];
        foreach ($fiches as $f) {
            $this->compter($delta, 'fiches_lues');
            $f->cible = LecturePageAccueil::cible((string) $f->website);
            $f->preuve = EmailSiteVerifie::PREUVE_SOURCE_FIABLE;
            $f->pages = [];
            $f->adresses = [];
            $f->rejetees = [];
            $f->ignoree = false;
            $marqueur = is_string($f->marqueur) ? json_decode($f->marqueur, true) : null;
            if (is_array($marqueur) && in_array($marqueur['statut'] ?? null, SiteFiable::STATUTS_VERIFIES, true)) {
                // Le marqueur doit parler de CE site : un site changé depuis
                // n'a pas été prouvé.
                if ($f->cible === null || ! VerificationSite::memeSite((string) ($marqueur['url'] ?? ''), $f->cible)) {
                    $this->compter($delta, 'marqueur_autre_site');
                    $f->ignoree = true;

                    continue;
                }
                $f->preuve = is_string($marqueur['preuve'] ?? null) ? $marqueur['preuve'] : VerificationSite::PREUVE_MENTIONS;
            }
            if ($f->cible === null) {
                $this->compter($delta, 'injoignables');
                $f->ignoree = true;

                continue;
            }
            // Un réseau social, un constructeur de sites, un annuaire : pas
            // le domaine de l'entreprise, aucune page n'est demandée.
            if (EmailSiteVerifie::estPlateforme($f->cible)) {
                $this->compter($delta, 'plateformes');
                $f->ignoree = true;

                continue;
            }
            $aLire[] = $f->cible;
        }
        $accueils = $this->lire($aLire, $lecteur);

        // 2. La page de preuve et les pages contact, par rangs : chaque appel
        // lit au plus une page par fiche (mémoire bornée comme N6).
        $rangs = [[], [], []];
        foreach ($fiches as $f) {
            if ($f->ignoree) {
                continue;
            }
            $lu = $accueils[$f->cible] ?? null;
            if (! $this->pageRecevable($lu, $f->cible, $delta, false)) {
                $f->ignoree = true;

                continue;
            }
            $this->compter($delta, 'sites_lus');
            $finale = (string) ($lu['finale'] ?? $f->cible);
            $base = VerificationSite::memeSite($finale, $f->cible) ? $finale : $f->cible;
            $corps = (string) ($lu['corps'] ?? '');
            $mentions = VerificationSite::lienMentions($corps, $base);
            $preuve = EmailSiteVerifie::pagePreuve($f->preuve, $mentions, $base);
            if ($preuve === null) {
                // L'accueil EST la page de preuve.
                $this->recolter($f, $corps, $f->cible, $delta);
            } else {
                $rangs[0][] = [$f, $preuve];
            }
            foreach (EmailSiteVerifie::liensContact($corps, $base, $preuve) as $i => $contact) {
                $rangs[$i + 1][] = [$f, $contact];
            }
        }
        unset($accueils);
        foreach ($rangs as $rang) {
            $lus = $this->lire(array_map(static fn (array $p): string => $p[1], $rang), $lecteur);
            foreach ($rang as [$f, $url]) {
                $lu = $lus[$url] ?? null;
                if ($this->pageRecevable($lu, $f->cible, $delta, true)) {
                    $this->recolter($f, (string) ($lu['corps'] ?? ''), $url, $delta);
                }
            }
            unset($lus);
        }

        // 3. Choisir l'adresse de chaque fiche (opposition, MX : DNS) HORS
        // transaction : aucun verrou n'est tenu pendant une attente réseau.
        foreach ($fiches as $f) {
            $f->choisie = null;
            if ($f->ignoree) {
                continue;
            }
            try {
                $f->choisie = $this->choisir($f, $delta);
            } catch (Throwable $e) {
                Log::warning('crm:entreprises:email-site-verifie choix en erreur', ['fiche' => (int) $f->id, 'exception' => $e::class]);
                $this->compter($delta, 'erreurs');
                $f->ignoree = true;
            }
        }

        // 4. Écrire, dans UNE transaction par paquet, avec le curseur : rien
        // d'autre que des lectures-écritures en base. À blanc : aucune
        // écriture ni transaction.
        if (! $dryRun) {
            DB::beginTransaction();
        }
        try {
            foreach ($fiches as $f) {
                if ($f->ignoree || $f->choisie === null) {
                    continue;
                }
                try {
                    $this->decider($f, $f->choisie, $delta, $dryRun);
                } catch (Throwable $e) {
                    Log::warning('crm:entreprises:email-site-verifie écriture en erreur', ['fiche' => (int) $f->id, 'exception' => $e::class]);
                    $this->compter($delta, 'erreurs');
                }
            }
            if (! $dryRun) {
                CurseurTraitement::ecrire($this->workspaceId, $cle, (int) $fiches[count($fiches) - 1]->id);
                DB::commit();
            }
        } catch (Throwable $e) {
            if (! $dryRun) {
                DB::rollBack();
            }

            throw $e;
        }
        foreach ($delta as $k => $n) {
            $this->bilan[$k] = ($this->bilan[$k] ?? 0) + $n;
        }
        $this->bilan['paquets']++;
    }

    /**
     * La page lue est-elle recevable ? Lue (2xx), et ARRIVÉE sur le domaine
     * du site : une redirection vers un autre domaine n'est jamais prise.
     *
     * @param  array<string, mixed>|null  $lu
     * @param  array<string, int>  $delta
     */
    private function pageRecevable(?array $lu, string $cible, array &$delta, bool $compterPage): bool
    {
        $statut = $lu['statut'] ?? null;
        if ($statut === LecturePageAccueil::STATUT_ROBOTS) {
            $this->compter($delta, 'robots');

            return false;
        }
        if ($statut !== LecturePageAccueil::STATUT_LU) {
            $this->compter($delta, 'injoignables');

            return false;
        }
        if (VerificationSite::motifArrivee($cible, (string) ($lu['finale'] ?? '')) !== null) {
            $this->compter($delta, 'redirections');

            return false;
        }
        if ($compterPage) {
            $this->compter($delta, 'pages_lues');
        }

        return true;
    }

    /**
     * Les adresses d'une page, jugées : seules les gardées restent en mémoire
     * (type et page de la première occurrence), le corps est oublié.
     *
     * @param  array<string, int>  $delta
     */
    private function recolter(stdClass $f, string $corps, string $url, array &$delta): void
    {
        if ($url === $f->cible) {
            $this->compter($delta, 'pages_lues');
        }
        foreach (EmailSiteVerifie::adresses($corps) as $email) {
            if (isset($f->adresses[$email]) || isset($f->rejetees[$email])) {
                continue;
            }
            [$type, $motif] = EmailSiteVerifie::juger($email, $f->cible);
            if ($type === null) {
                $f->rejetees[$email] = true;
                $this->compter($delta, 'rejet_' . $motif);

                continue;
            }
            $f->adresses[$email] = ['type' => $type, 'url' => $url];
            $this->compter($delta, $type === EmailSiteVerifie::GENERIQUE ? 'generiques' : 'nominatives');
        }
    }

    /**
     * La générique retenue pour la fiche — par ordre de préférence, la
     * première ni opposée ni sans courrier — ou null. Appelée HORS
     * transaction : la vérification MX interroge le DNS.
     *
     * @param  array<string, int>  $delta
     */
    private function choisir(stdClass $f, array &$delta): ?string
    {
        $generiques = [];
        foreach ($f->adresses as $email => $a) {
            if ($a['type'] === EmailSiteVerifie::GENERIQUE) {
                $generiques[] = (string) $email;
            }
        }
        // Par ordre de préférence, la première ni opposée ni sans courrier.
        $choisie = null;
        while ($generiques !== [] && $choisie === null) {
            $candidate = (string) EmailSiteVerifie::preferee($generiques);
            $generiques = array_values(array_diff($generiques, [$candidate]));
            if (! EligibiliteCampagne::peutRecevoir($candidate)) {
                $this->compter($delta, 'rejet_opposition');
            } elseif (! $this->mx->isDeliverable($candidate)) {
                $this->compter($delta, 'rejet_mx');
            } else {
                $choisie = $candidate;
            }
        }
        if ($choisie === null) {
            $this->compter($delta, 'sans_generique');
        }

        return $choisie;
    }

    /**
     * Écrit l'adresse retenue (champ vide) ou ouvre une proposition (conflit).
     * Appelée dans la transaction du paquet : aucun appel réseau.
     *
     * @param  array<string, int>  $delta
     */
    private function decider(stdClass $f, string $choisie, array &$delta, bool $dryRun): void
    {
        $page = (string) $f->adresses[$choisie]['url'];
        $actuelle = trim((string) ($f->email_generic ?? ''));

        if ($actuelle !== '' && mb_strtolower($actuelle) === $choisie) {
            $this->compter($delta, 'identiques');

            return;
        }
        // Remplir directement : champ vide, non déclaré, fiche non protégée.
        if ($actuelle === '' && $f->origine_email !== 'declared' && ! FichesProtegees::estProtegee((int) $f->id)) {
            $n = $dryRun ? $this->compterEligibles($f) : DB::transaction(fn (): int => $this->ecrire($f, $choisie, $page));
            $this->compter($delta, $n > 0 ? 'ecrites' : 'ecartes');

            return;
        }

        // Sinon : une PROPOSITION, la fiche ne bouge pas.
        if ($dryRun) {
            $this->compter($delta, Propositions::dejaEnAttente($this->workspaceId, Propositions::ENTREPRISE, (int) $f->id, 'email_generic', $choisie) ? 'deja_proposees' : 'conflits');

            return;
        }
        $resultat = $this->propositions->proposerAutomatisme(
            $this->workspaceId,
            Propositions::ENTREPRISE,
            (int) $f->id,
            'email_generic',
            $choisie,
            Propositions::ORIGINE_SITE_VERIFIE,
            $page,
        );
        $this->compter($delta, match ($resultat) {
            Propositions::PROPOSEE => 'conflits',
            Propositions::DEJA_PROPOSEE => 'deja_proposees',
            Propositions::IDENTIQUE => 'identiques',
            default => 'erreurs',
        });
    }

    /**
     * Écrit l'adresse, son origine, sa trace et la confiance — seulement si la
     * fiche est toujours vivante, au même site, toujours fiable, et son champ
     * toujours vide et non déclaré : jamais d'écrasement.
     */
    private function ecrire(stdClass $f, string $email, string $page): int
    {
        $trace = [
            'url' => $page,
            'le' => Carbon::now(VerificationSite::FUSEAU)->toDateString(),
            'type' => EmailSiteVerifie::GENERIQUE,
            'preuve_site' => $f->preuve,
            'v' => EmailSiteVerifie::VERSION,
        ];
        $note = $this->confiance->score($email, (string) $f->website);

        return DB::update(
            "UPDATE companies
                SET email_generic = ?,
                    field_origins = COALESCE(field_origins, '{}'::jsonb) || jsonb_build_object('email_generic', ?::text),
                    signals = COALESCE(signals, '{}'::jsonb) || jsonb_build_object(?::text, ?::jsonb),
                    best_email_confidence = CASE
                        WHEN ?::char IS NOT NULL AND (best_email_confidence IS NULL OR best_email_confidence > ?::char) THEN ?::char
                        ELSE best_email_confidence END,
                    updated_at = now()
              WHERE " . self::gardeEcritureSql(),
            [
                $email, EmailSiteVerifie::ORIGINE, EmailSiteVerifie::CLE_SIGNAL, json_encode($trace, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $note, $note, $note,
                ...$this->gardeEcritureLiaisons($f),
            ],
        );
    }

    /** À blanc : la fiche serait-elle écrite ? Même WHERE que `ecrire`, rien d'écrit. */
    private function compterEligibles(stdClass $f): int
    {
        return (int) DB::selectOne('SELECT count(*) AS n FROM companies WHERE ' . self::gardeEcritureSql(), $this->gardeEcritureLiaisons($f))->n;
    }

    private static function gardeEcritureSql(): string
    {
        return "id = ? AND workspace_id = ? AND deleted_at IS NULL AND website = ?
            AND (email_generic IS NULL OR btrim(email_generic) = '')
            AND COALESCE(field_origins ->> 'email_generic', '') <> 'declared'
            AND " . SiteFiable::fiableSql('companies');
    }

    /** @return list<int|string> */
    private function gardeEcritureLiaisons(stdClass $f): array
    {
        return [(int) $f->id, $this->workspaceId, (string) $f->website];
    }

    /**
     * Lit les adresses données (dédoublonnées) et rend, pour chacune, son
     * statut, son adresse d'arrivée et son corps. Une lecture groupée qui
     * lève est refaite adresse par adresse.
     *
     * @param  list<string>  $cibles
     * @return array<string, array<string, mixed>>
     */
    private function lire(array $cibles, LecturePageAccueil $lecteur): array
    {
        $cibles = array_values(array_unique($cibles));
        if ($cibles === []) {
            return [];
        }
        try {
            return $lecteur->lire($cibles, true);
        } catch (Throwable $e) {
            Log::warning('crm:entreprises:email-site-verifie lecture groupée en erreur, relecture une à une', ['exception' => $e::class]);
        }
        $lus = [];
        foreach ($cibles as $cible) {
            try {
                $lus += $lecteur->lire([$cible], true);
            } catch (Throwable $e) {
                Log::warning('crm:entreprises:email-site-verifie adresse en erreur', ['exception' => $e::class]);
            }
        }

        return $lus;
    }

    /**
     * L'heure d'arrêt du jour (Paris) — règle de `crm:entreprises:verifier-sites`.
     */
    private function heureDArret(CarbonInterface $maintenant, mixed $jusqua, bool $forcer): ?CarbonInterface
    {
        $finFenetre = $maintenant->copy()->setTimeFromTimeString(VerificationSite::HEURE_FIN);
        if (! is_string($jusqua)) {
            return $forcer ? null : $finFenetre;
        }
        $demandee = $maintenant->copy()->setTimeFromTimeString($jusqua);
        if ($forcer && $demandee->lessThanOrEqualTo($maintenant)) {
            $demandee = $demandee->addDay();
        }

        return ! $forcer && $demandee->greaterThan($finFenetre) ? $finFenetre : $demandee;
    }

    /**
     * Les fiches de la plage parcourue (`$depart` < id ≤ `$plafond`) : toutes,
     * corbeille comprise, et les vivantes.
     *
     * @return array{toutes: int, vivantes: int}
     */
    private function compterFiches(int $depart, int $plafond): array
    {
        $n = DB::table('companies')->where('workspace_id', $this->workspaceId)
            ->where('id', '>', $depart)->where('id', '<=', $plafond)
            ->selectRaw('count(*) AS toutes, count(*) FILTER (WHERE deleted_at IS NULL) AS vivantes')
            ->first();

        return ['toutes' => (int) ($n->toutes ?? 0), 'vivantes' => (int) ($n->vivantes ?? 0)];
    }

    /** @param  array<string, int>  $delta */
    private function compter(array &$delta, string $cle): void
    {
        $delta[$cle] = ($delta[$cle] ?? 0) + 1;
    }

    /** Entier dans [$min, $max] ; null si absent ; false si invalide. */
    private function entier(string $nom, int $min, int $max): int|false|null
    {
        $valeur = $this->option($nom);
        if ($valeur === null || $valeur === '') {
            return null;
        }
        if (! is_string($valeur) || preg_match('/^\d{1,18}$/', $valeur) !== 1) {
            return false;
        }
        $n = (int) $valeur;

        return $n >= $min && $n <= $max ? $n : false;
    }
}
