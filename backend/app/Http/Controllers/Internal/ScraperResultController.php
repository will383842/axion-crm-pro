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
 *   - OFF (défaut) : le message est journalisé et RIEN n'est écrit — le lot est
 *     fusionnable sans activer quoi que ce soit. Depuis C18-001 (2026-08-20) la
 *     réponse le DIT : `200 {"ingested": false, "raison": "funnel_ferme"}`.
 *     Elle affirmait auparavant `ingested: true`, c'est-à-dire exactement le
 *     défaut décrit trois lignes plus haut, non pas subsistant dans le code mais
 *     REPRODUIT dans la réponse ;
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
            // 🔴 CONSTAT C18-001 (S1) — CETTE BRANCHE REPONDAIT « ingested: true »
            // ALORS QU'ELLE N'INGERE RIEN, ET C'EST LE DEFAUT LE PLUS COUTEUX
            // QU'UN CANAL MACHINE PUISSE PORTER.
            //
            // Mesure du 2026-08-20 sur `axion_crm_test_lot6` : drapeau
            // `crm.scrape_funnel.enabled` a false — sa VALEUR PAR DEFAUT, posee
            // dans `config/crm.php` (bloc `scrape_funnel`) — un message pivot
            // valide, signe, accepte, laissait `companies = 0`,
            // `scraper_runs = 0`, `activities = 0`, et le producteur recevait
            // `200 {"ingested": true}`.
            //
            // C'est la lecon IndexNow que ce depot cite lui-meme : un job vert
            // qui ne relaie rien est le pire des etats, parce qu'il consomme le
            // seul signal dont dispose l'exploitant. Ici le mensonge etait meme
            // DOCUMENTE — l'en-tete de ce fichier ET `config/crm.php` decrivaient
            // tous deux « log-only, 200 `ingested: true` » jusqu'a ce jour — sans
            // que personne n'ait releve que la REPONSE, elle, affirme le
            // contraire des faits. Les deux textes sont rectifies avec ce lot.
            //
            // ⚠️ CE QUI N'EST PAS CORRIGE ICI, ET C'EST VOULU : le funnel reste
            // FERME. Son etat par defaut est un choix de conception (le lot L3
            // est fusionnable sans rien activer). Le defaut n'est pas la porte
            // fermee, c'est l'accuse de reception qui ment. On corrige donc la
            // seule chose fausse : la reponse.
            //
            // ⚠️ LE STATUT HTTP NE BOUGE PAS, ET C'EST LA CONTRAINTE DURE.
            // Verifie dans `workers/src/bridge/result-sender.ts` (34 lignes) :
            // l'emetteur fait `await axios.post(...)` et JETTE la valeur de
            // retour — aucune lecture de `.data`, aucune occurrence de
            // « ingested » ; son appelant `workers/src/scrapers/base.ts` l. 178
            // fait `await envoyer({...})`, de type `Promise<void>`. Seul le
            // STATUT lui parvient : axios leve sur tout ce qui n'est pas 2xx, et
            // `base.ts` l. 185-201 traite cette levee comme un echec de job
            // (remise en file, puis abandon en `status: 'failed'`). Repondre 202
            // ou 4xx ici ferait donc rejouer puis PERDRE les collectes. Changer
            // le CORPS, en revanche, est sans effet sur le producteur en place.
            //
            // `raison` et non `reason` : le reste des reponses de ce controleur
            // porte `error` / `message` (anglais), mais `raison` est le mot que
            // l'audit emploie et il n'entre en collision avec aucune cle
            // existante. Ce qui compte est qu'il soit STABLE : c'est desormais
            // un contrat, garde par
            // `tests/Feature/Internal/ReponseVeridiqueIngestionTest.php` et par
            // `workers/tests/reponse-ingestion-veridique.test.ts`.
            //
            // `run_id` est renvoye tel quel : sans lui, un exploitant qui lit un
            // « ingested: false » dans les journaux du worker ne sait pas DE QUEL
            // run il parle.
            Log::info('ScraperResult recu SANS ingestion (funnel ferme)', [
                'run_id' => $payload['run_id'] ?? null,
                'source' => $payload['source'] ?? null,
                'status' => $payload['status'] ?? null,
                'ingested' => false,
                'raison' => 'funnel_ferme',
            ]);

            return $this->ok([
                'ingested' => false,
                'raison' => 'funnel_ferme',
                'message' => "Le funnel d'ingestion est desactive (CRM_SCRAPE_FUNNEL_ENABLED) : "
                    . 'le message a ete recu et journalise, aucune donnee n\'a ete ecrite.',
                'run_id' => $payload['run_id'] ?? null,
            ]);
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

        // ⚠️ CONSTAT VOISIN, MESURE ET NON REPARE — C18-001-bis.
        //
        // Ici `ingested` est ecrit en dur a `true`, quel que soit l'outcome. Or
        // `ScrapeIngestOutcome` (son propre docblock le dit) porte CINQ statuts,
        // dont trois n'ecrivent aucune donnee metier :
        //   - `noop_idempotent` : run deja ingere, « rien n'a bouge » — ZERO
        //     ecriture (`ScrapedRecordIngestService::apply()` l. 102-104) ;
        //   - `skipped_failed`  : echec cote collecteur, « aucune donnee a
        //     ecrire » — seule une ligne `scraper_runs` est consignee (l. 106-112) ;
        //   - `pending_match`   : « RIEN n'est cree », l'evenement part en file
        //     d'arbitrage (l. 119-124).
        // Trois cas sur cinq ou `ingested: true` est aussi faux qu'il l'etait
        // dans la branche fermee ci-dessus. C'est le MEME defaut, un etage plus
        // bas, et il ne se voit pas parce que le drapeau est ferme par defaut.
        //
        // POURQUOI CE LOT NE LE CORRIGE PAS (regle 10 du mandat) : deriver
        // `ingested` de `$outcome->status` change la SEMANTIQUE d'un champ de
        // reponse. Aucun consommateur ne le lit aujourd'hui — verifie : le
        // producteur Node jette le corps — mais un `false` sur
        // `noop_idempotent` se lit comme un ECHEC alors que c'est le
        // fonctionnement nominal d'un rejeu, et ce choix-la se tranche, il ne se
        // prend pas au detour d'un correctif de veracite.
        //
        // CE QUE JE FERAIS : ne plus emettre de booleen resume du tout dans
        // cette branche, et laisser `result.status` — deja present, deja
        // documente, deja a cinq valeurs — etre l'unique verdict ; `ingested`
        // etant conserve a `true` uniquement le temps d'une depreciation
        // annoncee. A defaut :
        //   'ingested' => in_array($outcome->status, [ScrapeIngestOutcome::CREATED,
        //                                             ScrapeIngestOutcome::UPDATED], true),
        // avec la meme cle `raison` que ci-dessus portant le statut d'outcome.
        return $this->ok(['ingested' => true, 'result' => $outcome->toArray()]);
    }
}
