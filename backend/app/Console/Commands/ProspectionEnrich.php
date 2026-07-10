<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Waterfall\WaterfallOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Enrichit N entreprises de façon SYNCHRONE (test) via le waterfall :
 * dirigeants + CA (annuaire-entreprises), site web (DomainFinder), email/tél
 * (mentions légales), GPS (BAN). Affiche la config MOCK réelle + un résumé.
 */
class ProspectionEnrich extends Command
{
    protected $signature = 'prospection:enrich {--count=20} {--department=} {--workspace=} '
        . '{--refresh-incomplete : reprend aussi les fiches déjà enrichies mais incomplètes (pas de lat/lon, aucun email, ou Google Places en attente)} '
        . '{--with-website : ne sélectionne que les entreprises ayant déjà un site VIVANT (ROI email max)} '
        . '{--shard= : index de partition (0..shards-1) pour exécution distribuée} '
        . '{--shards= : nombre total de partitions (id % shards == shard)}';

    protected $description = 'Enrichit N entreprises (dirigeants, site, email, tél, GPS) — test synchrone.';

    public function handle(WaterfallOrchestrator $orch): int
    {
        $this->info('Config MOCK réelle (false = source réelle) :');
        foreach ([
            'MOCK_MODE', 'MOCK_INSEE', 'MOCK_ANNUAIRE_ENTREPRISES', 'MOCK_BODACC',
            'MOCK_BAN', 'MOCK_SCRAPERS', 'MOCK_SMTP', 'MOCK_LLM', 'MOCK_PROXIES',
        ] as $f) {
            $v = env($f);
            $this->line("  {$f} = " . ($v === null ? '(défaut)' : var_export($v, true)));
        }
        $this->line('');

        $count = max(1, (int) $this->option('count'));

        // Sharding optionnel (run parallèle supervisé, cf. prospection:find-websites) :
        // partitionne la sélection par id % shards == shard, sans chevauchement entre
        // instances → N runs simultanés sur des machines distinctes, débit ≈ ×N.
        $shards = $this->option('shards') !== null ? max(1, (int) $this->option('shards')) : null;
        $shard = $this->option('shard') !== null ? (int) $this->option('shard') : null;
        if ($shards !== null && ($shard === null || $shard < 0 || $shard >= $shards)) {
            $this->error("--shard doit être dans [0, {$shards}-1] quand --shards est fourni.");

            return self::FAILURE;
        }

        $q = Company::query();
        if ($this->option('refresh-incomplete')) {
            // Élargit la sélection : jamais enrichie OU enrichie mais incomplète.
            // Reste reprenable (enrich() repose enriched_at ; les fiches encore
            // incomplètes seront re-sélectionnées aux prochains passages).
            //
            // Garde-fou anti-churn : les conditions « incomplète » (pas de lat/lon,
            // aucun email, Google Places en attente) ne re-sélectionnent une fiche
            // que si elle n'a pas été touchée depuis > 7 jours. Sans cette borne,
            // une fiche non réparable (ex. lat IS NULL définitif) serait re-choisie
            // en boucle à chaque run sans jamais progresser.
            $q->where(function ($w) {
                $w->whereNull('enriched_at')
                    ->orWhere(function ($stale) {
                        $stale->whereRaw("enriched_at < now() - interval '7 days'")
                            ->where(function ($e) {
                                $e->whereNull('lat')
                                    ->orWhere(function ($mail) {
                                        $mail->whereNull('email_generic')
                                            ->whereNotExists(function ($sub) {
                                                $sub->selectRaw('1')
                                                    ->from('contacts')
                                                    ->whereColumn('contacts.company_id', 'companies.id')
                                                    ->whereNotNull('contacts.email');
                                            });
                                    })
                                    ->orWhereRaw("jsonb_exists(signals, 'google_places_pending')");
                            });
                    });
            });
        } else {
            $q->whereNull('enriched_at');
        }
        // Progression déterministe + évite un ordre non déterministe qui
        // re-brasserait le même sous-ensemble d'un run à l'autre.
        $q->orderBy('id');
        if ($dept = $this->option('department')) {
            $q->where('department_code', $dept);
        }
        if ($ws = $this->option('workspace')) {
            $q->where('workspace_id', $ws);
        }
        // --with-website : restreint aux entreprises ayant déjà un site VIVANT
        // (website non vide + statut != 'dead'). Priorise les ~801k fiches avec site
        // (ROI email maximal). Sans le flag → sélection inchangée.
        if ($this->option('with-website')) {
            $q->whereNotNull('website')
                ->where('website', '<>', '')
                ->where('website_status', '<>', 'dead');
        }
        // Sharding : ne conserve que les id de cette partition (appliqué au MÊME $q,
        // donc valable aussi pour la sélection --refresh-incomplete et le fallback).
        if ($shards !== null) {
            $q->whereRaw('id % ? = ?', [$shards, $shard]);
        }
        // Priorité aux entreprises avec des salariés (plus susceptibles d'avoir un site).
        // `clone` obligatoire : le builder est mutable, donc sans lui les contraintes
        // `effectif_range` resteraient posées sur $q et le fallback ci-dessous rejouerait
        // la même requête restreinte — il ne s'élargirait jamais aux NN/00/01.
        $companies = (clone $q)->whereNotNull('effectif_range')
            ->whereNotIn('effectif_range', ['NN', '00', '01'])
            ->limit($count)->get();
        if ($companies->isEmpty()) {
            $companies = $q->limit($count)->get();
        }

        $this->info("Enrichissement de {$companies->count()} entreprises…");
        $site = 0; $tel = 0; $mail = 0; $dir = 0;

        foreach ($companies as $c) {
            try {
                $orch->enrich($c);
            } catch (\Throwable $e) {
                $this->warn("  {$c->siren} ERREUR: " . mb_substr($e->getMessage(), 0, 120));
                continue;
            }
            $c->refresh();
            $nbDir = DB::table('contacts')->where('company_id', $c->id)->count();
            $email = $c->email_generic
                ?: DB::table('contacts')->where('company_id', $c->id)->whereNotNull('email')->value('email');
            if ($c->website) { $site++; }
            if ($c->phone) { $tel++; }
            if ($email) { $mail++; }
            if ($nbDir > 0) { $dir++; }

            $this->line(sprintf(
                '  • %-28s | site:%-3s tel:%-3s mail:%-24s dirigeants:%d',
                mb_substr((string) ($c->denomination ?? $c->siren), 0, 28),
                $c->website ? 'OUI' : '—',
                $c->phone ? 'OUI' : '—',
                $email ? mb_substr((string) $email, 0, 24) : '—',
                $nbDir,
            ));
        }

        $n = max(1, $companies->count());
        $this->info(sprintf(
            'RÉSUMÉ : site %d/%d · tél %d/%d · email %d/%d · dirigeants %d/%d',
            $site, $n, $tel, $n, $mail, $n, $dir, $n,
        ));
        return self::SUCCESS;
    }
}
