<?php

namespace App\Console\Commands;

use App\Services\Email\EmailConfidenceService;
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

    /**
     * Emails PARASITES à rejeter (mêmes fléaux que l'enrichissement entreprises, qui a
     * demandé 5 passes de nettoyage) : artefacts tracking/assets (Sentry/Wix, images,
     * hashs) et domaines de PARKING/revente (le site est garé → l'email n'est pas celui
     * du média). Cf. audit-cartographie-crm-pro : « 19 % d'emails parasites ».
     */
    public const PARASITE_PATTERNS = [
        '/[0-9a-f]{16,}@/i',                                   // boîte = hash (tracking)
        '/@[0-9a-f]{16,}/i',
        '/(wixpress|sentry)/i',                                // artefacts Wix/Sentry
        // Domaines de PARKING / revente de noms de domaine.
        '/@(solidnames|sedoparking|parkingcrew|bodis|above\.com|dan\.com|domainmarket|hugedomains|afternic|sedo\.com|godaddy|namecheap)\b/i',
        // Plateformes / builders / éditeurs de thèmes (email = plateforme, pas le média).
        '/(unitedthemes|readymag|myshopify|squarespace|webflow|weebly|jimdo|wixsite|websitebuilder|wordpress\.com|hostinger|wpengine|kinsta|shopify|webador|monsite)/i',
        // Placeholders / exemples de démo (jamais un vrai contact).
        '/^(your|votre|user|utilisateur|test|nom|prenom|email|exemple|example)@/i',
        '/@(email\.com|domain\.com|domaine\.|exemple\.|example\.|mail\.com|societe\.|votresite\.)/i',
        '/scaled_jpg|@2x/i',                                   // assets image
        '/\.(png|jpe?g|gif|svg|webp|avif)(@|$)/i',
    ];

    public function __construct(
        private readonly MentionsLegalesScraperService $scraper,
        private readonly ?MxEmailValidator $mx = null,
        private readonly ?EmailConfidenceService $confidence = null,
    ) {
        parent::__construct();
    }

    /** Un email « parasite » (tracking/asset/parking) ne doit jamais être stocké. */
    private function isParasite(string $email): bool
    {
        foreach (self::PARASITE_PATTERNS as $rx) {
            if (preg_match($rx, $email) === 1) {
                return true;
            }
        }

        return false;
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
                    if ($this->isParasite($email)) {
                        continue;
                    }
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
                    $update['email'] = $this->pickBestEmail($validEmails, (string) $m->website);
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

    /**
     * Choisit l'email principal du média :
     *  1. PRIORITÉ au même domaine que le site (confiance A) → le vrai email du média,
     *     pas celui de l'agence web / du registrar de parking ;
     *  2. dans ce pool, préfère une boîte rédaction/presse/contact ;
     *  3. sinon, premier email valide.
     */
    private function pickBestEmail(array $emails, ?string $website): string
    {
        $sameDomain = $this->confidence !== null && $website
            ? array_values(array_filter($emails, fn ($e) => $this->confidence->score((string) $e, $website) === 'A'))
            : [];
        $pool = ! empty($sameDomain) ? $sameDomain : array_values($emails);

        foreach (self::PREFERRED_LOCALPARTS as $pref) {
            foreach ($pool as $e) {
                $local = strtolower((string) strstr((string) $e, '@', true));
                if ($local !== '' && str_contains($local, $pref)) {
                    return $e;
                }
            }
        }

        return $pool[0];
    }
}
