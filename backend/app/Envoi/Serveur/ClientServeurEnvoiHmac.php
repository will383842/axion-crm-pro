<?php

namespace App\Envoi\Serveur;

use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Contrat\EvenementSuivi;
use App\Envoi\Serveur\Contrat\LotSuivi;
use App\Envoi\Serveur\Contrat\TireurEvenements;
use App\Support\HmacSignature;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client signé du serveur d'envoi. Mêmes en-têtes que la réception
 * (`X-Envoi-Timestamp` / `-Nonce` / `-Signature` sur « horodatage.nonce.corps »),
 * le « corps » d'un GET étant « GET <chemin?requête> ». Secret vide = refus, aucun appel.
 */
final class ClientServeurEnvoiHmac implements CommandeurServeur, TireurEvenements
{
    public function __construct(private readonly JournalCommandes $journal) {}

    public function tirer(string $workspaceId, string $source, ?string $curseur, int $limite, DateTimeImmutable $depuis): LotSuivi
    {
        $chemin = '/evenements?' . http_build_query(['curseur' => $curseur ?? '', 'limite' => $limite, 'depuis' => $depuis->format('c')]);
        $json = $this->appeler($workspaceId, $source, 'GET', $chemin, 'GET ' . $chemin);
        $evenements = [];
        foreach ((array) ($json['evenements'] ?? []) as $e) {
            $evenements[] = new EvenementSuivi((string) $e['event_id'], (int) $e['seq'], (string) $e['type'], (string) $e['id_injection']);
        }

        return new LotSuivi($evenements, isset($json['curseur_suivant']) ? (string) $json['curseur_suivant'] : null);
    }

    public function commander(string $workspaceId, string $source, string $commandeId, string $commande): bool
    {
        if (! $this->journal->enregistrer($workspaceId, $commandeId, $commande)) {
            return false;
        }
        $corps = json_encode(['commande_id' => $commandeId, 'commande' => $commande], JSON_THROW_ON_ERROR);
        $this->appeler($workspaceId, $source, 'POST', '/commandes', $corps, $corps);

        return true;
    }

    /** @return array<string, mixed> */
    private function appeler(string $workspaceId, string $source, string $methode, string $chemin, string $signe, ?string $corps = null): array
    {
        $secret = (string) config('envoi.serveur.secret', '');
        if ($secret === '') {
            throw new RuntimeException('serveur d’envoi : secret HMAC absent, appel refusé');
        }
        $url = WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_serveurs')
            ->where('workspace_id', $workspaceId)->where('source', $source)->where('actif', true)->value('url_base'));
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('serveur d’envoi inconnu : ' . $source);
        }
        $ts = (string) time();
        $nonce = (string) Str::uuid();
        $requete = Http::timeout(10)->withHeaders([
            'X-Envoi-Timestamp' => $ts,
            'X-Envoi-Nonce' => $nonce,
            'X-Envoi-Signature' => HmacSignature::sign($secret, HmacSignature::signedPayload($ts, $nonce . '.' . $signe)),
        ]);
        $reponse = $methode === 'GET'
            ? $requete->get(rtrim($url, '/') . $chemin)
            : $requete->withBody((string) $corps, 'application/json')->post(rtrim($url, '/') . $chemin);

        return $reponse->throw()->json() ?? [];
    }
}
