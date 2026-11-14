<?php

namespace App\Http\Controllers\Api;

use App\Envoi\JourEnvoi;
use App\Envoi\Resultats\DepotResultats;
use App\Support\AuditLogger;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Résultats d'envoi (lot R04), en LECTURE SEULE : aucun envoi, aucune écriture hors le journal de recherche.
 * Jours = jours civils d'Europe/Paris. Pas de suivi d'ouverture ni de clic.
 */
class EnvoiResultatsController extends ApiController
{
    public function index(Request $request, DepotResultats $depot): JsonResponse
    {
        $v = $request->validate(['par' => ['required', 'in:campagne,boite,domaine,liste,message'], 'jours' => ['nullable', 'integer', 'in:7,30,90']]);
        $jours = (int) ($v['jours'] ?? 30);
        $ws = (string) WorkspaceContext::current();
        [$du, $au] = self::periode($jours);
        $duPrec = $du->sub(new DateInterval("P{$jours}D"));
        $auPrec = $du->sub(new DateInterval('P1D'));
        $f = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d');

        // Campagne, liste et message dépendent de R08 (lien campagne → séquence) : data vide tant qu'il manque, sans erreur.
        $data = in_array($v['par'], DepotResultats::PARS_CHAUFFE, true) ? $depot->parChauffe($ws, $v['par'], $f($du), $f($au)) : [];
        $totaux = $depot->totaux($ws, $f($du), $f($au));

        return response()->json([
            'data' => $data,
            'totaux' => $totaux,
            'precedent' => $depot->totaux($ws, $f($duPrec), $f($auPrec)),
            // Lien CRM non défini : rendez-vous et gagnés restent null (on ne les invente pas).
            'entonnoir' => ['envoyes' => $totaux['envoyes'], 'reponses' => $totaux['reponses'], 'rendez_vous' => null, 'gagnes' => null],
            'periode' => ['du' => $f($du), 'au' => $f($au)],
        ]);
    }

    public function courbe(Request $request, DepotResultats $depot): JsonResponse
    {
        $request->validate(['campagne_id' => ['nullable', 'uuid']]);
        if ($request->filled('campagne_id')) {
            return response()->json(['data' => []]); // R08 : pas de lien campagne → envois avant ce lot
        }
        [$du, $au] = self::periode(30);
        $parJour = $depot->parJour((string) WorkspaceContext::current(), $du->format('Y-m-d'), $au->format('Y-m-d'));
        $points = [];
        for ($j = $du; $j <= $au; $j = $j->add(new DateInterval('P1D'))) {
            $k = $j->format('Y-m-d');
            $points[] = ['jour' => $k] + ($parJour[$k] ?? ['remis' => 0, 'rebonds' => 0, 'reponses' => 0]);
        }

        return response()->json(['data' => $points]);
    }

    public function chercher(Request $request): JsonResponse
    {
        $adresse = (string) $request->validate(['adresse' => ['required', 'string', 'max:254', 'email:filter']])['adresse'];
        $ws = (string) WorkspaceContext::current();
        // MÊME empreinte que les événements de retour (ListeSuppression) pour la recherche seulement : jamais journalisée.
        $empreinte = ListeSuppression::empreinte($adresse);
        $cle = (string) config('envoi.cle_empreinte');
        AuditLogger::log('envoi.recherche', [
            'workspace_id' => $ws,
            'resource_type' => 'envoi_recherche',
            // HMAC à clé : relie deux recherches de la même adresse sans permettre de la retrouver.
            'empreinte' => $cle !== '' ? hash_hmac('sha256', $empreinte, $cle) : null,
        ]);
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_evenements')
            ->where('workspace_id', $ws)->where('empreinte_destinataire', $empreinte)
            ->orderByDesc('recu_le')->limit(50)->get(['recu_le', 'classe', 'boite']));

        // Inconnue ou d'un autre espace (RLS) : `data` vide, strictement la même réponse.
        return response()->json(['data' => $lignes->map(static fn ($l): array => [
            'recu_le' => (new DateTimeImmutable((string) $l->recu_le))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'classe' => (string) $l->classe,
            'boite' => (string) $l->boite,
        ])->all()]);
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} premier et dernier jour (Paris) de la période */
    private static function periode(int $jours): array
    {
        $au = JourEnvoi::de();

        return [$au->sub(new DateInterval('P' . ($jours - 1) . 'D')), $au];
    }
}
