<?php

namespace App\Console\Commands;

use App\Services\Email\MxEmailValidator;
use App\Services\Legal\MentionsLegalesScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * media:enrich — enrichissement direct des médias par scraping de leur propre site.
 *
 * Constat mesuré : l'héritage entreprise→média est déjà à fond (0 média lié sans email
 * alors que sa company en a un). Pour AUGMENTER la couverture email/tél des médias, il
 * faut donc aller RÉCOLTER sur leur site — exactement comme pour les entreprises.
 *
 * Pour chaque média avec un site non encore scrapé (ou --refresh) :
 *   - récolte emails + téléphones via MentionsLegalesScraperService::harvestFromWebsite
 *     (mêmes extracteurs que l'enrichissement entreprises, même filtrage anti-assets) ;
 *   - valide MX (doctrine « 0 email douteux » : jamais d'invalid/disposable) ;
 *   - pose media.email (si vide, en préférant une boîte rédaction/contact/presse) et
 *     media.phone (si vide) — n'écrase JAMAIS l'existant ;
 *   - conserve la liste COMPLÈTE dans socials.contact_channels (0 canal perdu) ;
 *   - marque socials.contacts_scraped_at (reprise incrémentale, sans migration).
 *
 * Shardé (id % shards) + curseur id + borné (--limit) → run parallèle supervisé (systemd).
 * Les JOURNALISTES/présentateurs sont extraits séparément par `journalists:scrape-ours`.
 */
class MediaEnrich extends Command
{
    protected $signature = 'media:enrich {--shards=1} {--shard=0} {--limit=0} {--batch=50} {--refresh} {--autonomous}';

    protected $description = 'Scrape le site des médias pour récolter emails + téléphones (0 email douteux).';

    /** Boîtes « rédaction » préférées pour l'email principal d'un média. */
    private const PREFERRED_LOCALPARTS = ['redaction', 'redac', 'presse', 'contact', 'info', 'journal'];

    public function __construct(
        private readonly MentionsLegalesScraperService $scraper,
        private readonly ?MxEmailValidator $mx = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $shards = max(1, (int) $this->option('shards'));
        $shard = max(0, (int) $this->option('shard'));
        $limit = (int) $this->option('limit');
        $batch = max(1, (int) $this->option('batch'));
        $refresh = (bool) $this->option('refresh');
        $autonomousOnly = (bool) $this->option('autonomous');

        $lastId = 0;
        $processed = 0;
        $enriched = 0;

        while (true) {
            $q = DB::table('media')
                ->whereNull('deleted_at')
                ->whereNotNull('website')
                ->where('id', '>', $lastId);

            if ($shards > 1) {
                $q->whereRaw('(id % ?) = ?', [$shards, $shard]);
            }
            if ($autonomousOnly) {
                $q->whereNull('company_id');
            }
            if (! $refresh) {
                // Pas d'opérateur jsonb `?` (confondu avec un placeholder PDO) : `->>` est sûr.
                $q->whereRaw("(socials->>'contacts_scraped_at') IS NULL");
            }

            $rows = $q->orderBy('id')->limit($batch)->get(['id', 'website', 'email', 'phone', 'socials']);
            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $m) {
                $lastId = (int) $m->id;
                $processed++;

                $harvest = $this->scraper->harvestFromWebsite((string) $m->website);

                $validEmails = [];
                foreach ($harvest['emails'] as $email) {
                    if ($this->mx !== null) {
                        $status = $this->mx->validate($email)['status'] ?? 'unknown';
                        if (in_array($status, ['invalid', 'disposable'], true)) {
                            continue;
                        }
                    }
                    $validEmails[] = $email;
                }
                $validEmails = array_values(array_unique($validEmails));
                $phones = array_values(array_unique($harvest['phones']));

                $socials = json_decode((string) ($m->socials ?? '{}'), true);
                if (! is_array($socials)) {
                    $socials = [];
                }
                $channels = $socials['contact_channels'] ?? [];
                $channels['emails'] = array_values(array_unique(array_merge($channels['emails'] ?? [], $validEmails)));
                $channels['phones'] = array_values(array_unique(array_merge($channels['phones'] ?? [], $phones)));
                $socials['contact_channels'] = $channels;
                $socials['contacts_scraped_at'] = now()->toIso8601String();

                $update = ['socials' => json_encode($socials), 'updated_at' => now()];

                if (! $m->email && ! empty($validEmails)) {
                    $update['email'] = $this->pickBestEmail($validEmails);
                }
                if (! $m->phone && ! empty($phones)) {
                    $update['phone'] = $phones[0];
                }
                if (isset($update['email']) || isset($update['phone'])) {
                    $update['enrich_status'] = 'enriched';
                    $update['enriched_at'] = now();
                    $enriched++;
                }

                DB::table('media')->where('id', $m->id)->update($update);
            }

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $this->info("Médias traités : {$processed}, enrichis (email/tél posé) : {$enriched}.");

        return self::SUCCESS;
    }

    /** Préfère une boîte rédaction/contact/presse, sinon le premier email valide. */
    private function pickBestEmail(array $emails): string
    {
        foreach (self::PREFERRED_LOCALPARTS as $pref) {
            foreach ($emails as $e) {
                $local = strtolower((string) strstr((string) $e, '@', true));
                if ($local !== '' && str_contains($local, $pref)) {
                    return $e;
                }
            }
        }

        return $emails[0];
    }
}
