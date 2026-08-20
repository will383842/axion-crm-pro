<?php

namespace App\Http\Controllers\Internal;

use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestRejection;
use App\Http\Controllers\Api\ApiController;
use App\Support\HmacSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Endpoint interne signé HMAC sha256, appelé par les workers Node après scraping.
 *
 * HISTOIRE (lot L3) : depuis sa création (« Sprint 6 — DeduplicationService +
 * waterfall.advance() + écriture scraper_runs »), ce contrôleur ne faisait que
 * LOGGER — le chaînon manquant constaté par l'audit scraping §A.1 : les workers
 * Node envoyaient, l'API répondait « ingested: true », et RIEN n'était ingéré.
 * Un job vert qui ne relaie rien est le pire des états (leçon IndexNow).
 *
 * Depuis L3, il est branché sur le FUNNEL D'INGESTION UNIQUE — derrière le
 * drapeau `CRM_SCRAPE_FUNNEL_ENABLED` :
 *   - OFF (défaut) : comportement HISTORIQUE inchangé (log-only, 200) — le lot
 *     est fusionnable sans activer quoi que ce soit ;
 *   - ON : validation du pivot `ScrapedRecord` (422 si invalide — le producteur
 *     saura ENFIN que son message est mauvais), puis ingestion complète.
 */
class ScraperResultController extends ApiController
{
    public function __construct(private readonly ScrapedRecordIngestService $ingest) {}

    public function store(Request $r): JsonResponse
    {
        $sig = $r->header('X-Worker-Signature');

        // Constat P5-HMAC-002 : ce secret etait le SEUL du depot lu par `env()`
        // brut, sans entree `config/`. Il passe par la configuration comme tous
        // les autres -- voir le commentaire de `config/services.php`.
        $secret = (string) config('services.worker_internal.hmac_secret', '');
        $body = $r->getContent();

        // -- SECRET ABSENT : ON REFUSE. On ne verifie pas « quand meme ». -----
        //
        // Ce controle etait FAIL-OPEN, et le secret est VIDE dans les trois
        // fichiers `.env` du depot (`.env`, `.env.example`, `backend/.env`).
        //
        // ⚠️ RECTIFICATION du 2026-08-20. La premiere redaction de ce
        // commentaire ecrivait « le secret est VIDE sur le serveur de
        // production ». Ce n'etait pas mesure : l'agent qui l'a ecrit n'a aucun
        // acces a la production, et l'a dit lui-meme. Ce qui est mesure, c'est
        // le depot. La valeur reelle sur le serveur est inconnue de ce lot.
        // Le defaut, lui, ne depend pas de cette valeur : un controle fail-open
        // est faux quelle que soit la configuration qu'il rencontre.
        //
        // `hash_hmac('sha256', $body, '')` produit un condensé
        // parfaitement valide - avec une cle que TOUT LE MONDE connait, puisque
        // c'est la chaine vide. N'importe qui pouvait donc forger
        // `X-Worker-Signature` et pousser des enregistrements dans la base de
        // production, le funnel d'ingestion etant ouvert.
        // Mesure le 2026-08-19 (audit 360, F37-001, S0).
        //
        // Un secret manquant est une faute de CONFIGURATION, pas une requete
        // malformee : on rend 503, comme le fait deja le webhook ZeptoMail. Le
        // 401 reste reserve a une signature reellement fausse.
        if ($secret === '') {
            Log::error('scraper-result : WORKER_INTERNAL_HMAC_SECRET est vide - canal refuse', [
                'ip' => $r->ip(),
            ]);

            return response()->json([
                'error' => 'signature_secret_absent',
                'message' => "Le canal interne n'est pas configuré côté serveur.",
            ], 503);
        }

        // On reprend la classe DURCIE deja en place sur SiteSync et Gdpr plutot
        // que de recopier le patron : elle refuse un secret vide, elle tolere le
        // prefixe `sha256=`, et elle compare a temps constant. La migration vers
        // cette classe avait ete faite pour SiteSync et jamais retroportee ici -
        // c'est precisement l'origine du defaut.
        // Le format signe reste le CORPS BRUT, sans horodatage : y ajouter la
        // fenetre temporelle casserait les workers Node en place. Le rejeu tardif
        // reste donc ouvert, et c'est note comme tel.
        //
        // ⚠️ Ce n'est plus seulement une note : c'est le constat P5-HMAC-003
        // (S2) au registre de l'audit 360, avec sa garde
        // (`tests/Feature/Internal/PatronHmacDeReferenceTest.php`). Une limite
        // ecrite en commentaire disparait avec la PR ; un constat, non.
        // `/internal/site-sync` est protege du rejeu, celui-ci ne l'est pas :
        // l'ecart entre les deux canaux est reel et il est suivi.
        if (! HmacSignature::verify($secret, $body, $sig)) {
            Log::warning('Internal scraper result rejected (bad HMAC)', ['ip' => $r->ip()]);

            return response()->json(['error' => 'bad_signature'], 401);
        }

        $payload = $r->json()->all();

        if (! filter_var(config('crm.scrape_funnel.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            // Comportement historique, à l'identique : log et 200.
            Log::info('ScraperResult ingested', [
                'run_id' => $payload['run_id'] ?? null,
                'source' => $payload['source'] ?? null,
                'status' => $payload['status'] ?? null,
            ]);

            return $this->ok(['ingested' => true]);
        }

        try {
            $record = ScrapedRecord::fromArray($payload);
            $outcome = $this->ingest->ingest($record);
        } catch (ScrapeIngestRejection $rejection) {
            Log::warning('scraper-result refusé', [
                'code' => $rejection->errorCode,
                'message' => $rejection->getMessage(),
            ]);

            return response()->json([
                'error' => $rejection->errorCode,
                'message' => $rejection->getMessage(),
                'details' => $rejection->details,
            ], $rejection->status);
        } catch (Throwable $e) {
            Log::error('scraper-result en erreur', ['exception' => $e->getMessage()]);

            return response()->json(['error' => 'ingest_failed'], 500);
        }

        return $this->ok(['ingested' => true, 'result' => $outcome->toArray()]);
    }
}
