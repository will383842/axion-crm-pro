<?php

namespace App\Jobs;

use App\Crm\FichesProtegees;
use App\Crm\Pharmacies\SelectionEnrichissement;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Jobs\Concerns\RunsInWorkspace;
use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use App\Services\Legal\MentionsLegalesScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Enrichissement RESTREINT d'une officine tenue par une PERSONNE PHYSIQUE
 * (forme juridique « 1xxx » ou `metadata.personne_physique`) : le site de l'officine et son téléphone,
 * rien d'autre. Le waterfall complet n'est pas utilisé ici : il écrit les
 * dirigeants et l'adresse de la personne.
 *
 * Réutilise `DomainFinderService` (site) et `MentionsLegalesScraperService::
 * harvestFromWebsite` (téléphone lu sur le site de l'établissement, sans
 * persistance par le service). Ne remplace jamais une valeur non vide.
 */
class EnrichirSiteOfficineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInWorkspace, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $companyId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [120, 900];
    }

    public function handle(DomainFinderService $domaines, MentionsLegalesScraperService $mentions): void
    {
        $espace = $this->espaceDuJob() ?? $this->espaceDepuisLaLigne('companies', $this->companyId);
        if ($espace === null) {
            Log::warning('EnrichirSiteOfficineJob: aucun espace de travail', ['company_id' => $this->companyId]);

            return;
        }
        $this->inWorkspace($espace, fn () => $this->enrichir($domaines, $mentions));
    }

    private function enrichir(DomainFinderService $domaines, MentionsLegalesScraperService $mentions): void
    {
        $company = Company::find($this->companyId);
        if (! $company || FichesProtegees::estProtegee((int) $company->id)) {
            return;
        }
        $sources = [];
        $aucunePage = false;
        try {
            if (empty($company->website)) {
                // Cherché avec l'ENSEIGNE (Brave plafond 0) ; sans enseigne, pas de recherche :
                // jamais le nom de la personne physique.
                $enseigne = trim((string) $company->enseigne);
                $trouve = null;
                if ($enseigne !== '') {
                    $copie = clone $company;
                    $copie->denomination = $enseigne;
                    $trouve = $domaines->findAvecMethode($copie, true);
                }
                if ($trouve !== null) {
                    // Écriture conditionnelle : jamais par-dessus un site posé entre-temps.
                    $poses = DB::table('companies')->where('id', $company->id)
                        ->where(fn ($q) => $q->whereNull('website')->orWhere('website', ''))
                        ->update(['website' => $trouve['url'], 'website_method' => $trouve['methode'], 'updated_at' => now()]);
                    if ($poses > 0) {
                        $company->website = $trouve['url'];
                        $sources[] = 'domain-finder';
                    }
                }
            }
            if (empty($company->phone) && ! empty($company->website)) {
                $releve = $mentions->harvestTelephones((string) $company->website);
                if ($releve === null) {
                    // Aucune page n'a répondu (panne réseau) : la fiche n'est PAS « passée », elle sera reprise.
                    $aucunePage = true;
                } else {
                    $numero = $this->choisirTelephone($releve);
                    if ($numero !== null) {
                        // Écriture conditionnelle : jamais par-dessus un numéro posé entre-temps.
                        $posees = DB::table('companies')->where('id', $company->id)
                            ->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))
                            ->update(['phone' => $numero, 'updated_at' => now()]);
                        if ($posees > 0) {
                            $sources[] = 'site-officine';
                        }
                    }
                }
            }
        } catch (ConnectionException|RequestException $e) {
            // Erreur réseau passagère : on relance (tries/backoff) au lieu de l'avaler.
            Log::warning('EnrichirSiteOfficineJob: erreur réseau, nouvel essai', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('EnrichirSiteOfficineJob failed', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        }
        if ($aucunePage) {
            // Le marqueur de passage est posé à la mise en file : on le retire pour que la fiche soit reprise.
            DB::update("UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) - ? WHERE id = ?", [SelectionEnrichissement::CLE_PASSAGE, $this->companyId]);
        }
        if ($sources !== []) {
            DB::update(
                "UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('enrichissement_pharmacie_sources', ?::jsonb) WHERE id = ?",
                [json_encode($sources), $this->companyId],
            );
        }
    }

    /**
     * Premier numéro non opposé, en préférant ceux qui ne viennent pas d'une page de
     * mentions légales ; null si aucun.
     *
     * @param  array{phones: list<string>, hors_mentions: list<string>}  $releve
     */
    private function choisirTelephone(array $releve): ?string
    {
        $ingest = app(ScrapedRecordIngestService::class);
        $candidats = array_values(array_unique([...$releve['hors_mentions'], ...$releve['phones']]));
        foreach ($candidats as $tel) {
            if (! $ingest->estOppose(null, $tel)) {
                return $tel;
            }
        }

        return null;
    }
}
