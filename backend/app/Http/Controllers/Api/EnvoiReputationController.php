<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\Reputation\ConstatReputation;
use App\Envoi\Reputation\DepotReputation;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réputation des fournisseurs (contrat backend/openapi/envoi/reputation.yaml), saisie manuelle des constats lus dans
 * les consoles. Un constat peut FREINER (pause vers un fournisseur), jamais libérer : aucune route ne lève une pause.
 * Aucune route n'envoie de message ni n'écrit `envoi_drapeaux`.
 */
class EnvoiReputationController extends ApiController
{
    public function index(DepotReputation $depot): JsonResponse
    {
        $ws = (string) WorkspaceContext::current();
        if ($ws === '') {
            return $this->sansEspace();
        }

        return response()->json(['data' => array_map([self::class, 'exposer'], $depot->derniers($ws))]);
    }

    public function store(Request $request, DepotReputation $depot, GardeChauffe $garde, DepotSocle $socle): JsonResponse
    {
        $ws = (string) WorkspaceContext::current();
        if ($ws === '') {
            return $this->sansEspace();
        }
        $corps = $request->json()->all();
        $constat = $this->lire($corps, DepotPreuves::auteurAffichable($request->user()?->name));
        if (is_string($constat)) {
            return $this->erreur('validation', $constat, 422);
        }
        $cle = $request->header('Idempotency-Key');
        if ($cle !== null && (! is_string($cle) || preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $cle) !== 1)) {
            return $this->erreur('validation', 'La clé de rejeu (Idempotency-Key) doit faire de 8 à 100 caractères simples.', 422);
        }
        $empreinte = hash('sha256', json_encode([
            $constat->source, $constat->ressourceType, $constat->ressource, $constat->jour, $constat->reputation,
            $constat->tauxPlaintes, $constat->erreursAuth, $constat->pieges, $constat->preuve,
        ], JSON_THROW_ON_ERROR));
        $fournisseur = ConstatReputation::fournisseurDe($constat->source);

        $r = $depot->enregistrer(
            $ws, $constat, $cle ?? 'auto-' . $empreinte, $empreinte,
            fn (): bool => $constat->reputation === ConstatReputation::MAUVAISE
                && $garde->reputationMauvaise($ws, $fournisseur, new DateTimeImmutable, $constat->auteur),
        );
        if ($r === null) {
            return $this->erreur('conflit', 'Cette clé de rejeu a déjà servi pour un autre contenu.', 409);
        }
        if (! $r['rejeu']) {
            $socle->journaliser($ws, 'reputation_constat', $constat->auteur, [
                'source' => $constat->source, 'ressource_type' => $constat->ressourceType, 'jour' => $constat->jour,
                'reputation' => $constat->reputation, 'pause_ouverte' => (bool) $r['ligne']['pause_ouverte'],
            ]);
        }

        return response()->json(self::exposer($r['ligne']), $r['rejeu'] ? 200 : 201);
    }

    /** @param  array<string, mixed>  $c */
    private function lire(array $c, string $auteur): ConstatReputation|string
    {
        $source = $c['source'] ?? null;
        $type = $c['ressource_type'] ?? null;
        $ressource = $c['ressource'] ?? null;
        $jour = $c['jour'] ?? null;
        $reputation = $c['reputation'] ?? null;
        $preuve = $c['preuve'] ?? null;
        $taux = $c['taux_plaintes'] ?? null;
        $auth = $c['erreurs_auth'] ?? null;
        $pieges = $c['pieges'] ?? null;
        if (! in_array($source, ConstatReputation::SOURCES, true)) {
            return 'Source inconnue (google_postmaster, microsoft_snds, microsoft_jmrp ou orange).';
        }
        if (! in_array($reputation, ConstatReputation::VALEURS, true)) {
            return 'Réputation inconnue (bonne, moyenne, mauvaise ou inconnue).';
        }
        if (! is_string($ressource) || ! in_array($type, ConstatReputation::RESSOURCES, true) || ! self::ressourceValide($type, $ressource)) {
            return 'La ressource doit être une adresse IP ou un nom de domaine (jamais une adresse e-mail), en accord avec son type.';
        }
        if (! is_string($jour) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour) !== 1 || ! checkdate((int) substr($jour, 5, 2), (int) substr($jour, 8, 2), (int) substr($jour, 0, 4))
            || $jour > (new DateTimeImmutable('tomorrow'))->format('Y-m-d')) {
            return 'Le jour doit être une date valide (AAAA-MM-JJ), pas dans le futur.';
        }
        if ($taux !== null && (! (is_int($taux) || is_float($taux)) || $taux < 0 || $taux > 1)) {
            return 'Le taux de plaintes doit être un nombre entre 0 et 1.';
        }
        foreach ([$auth, $pieges] as $n) {
            if ($n !== null && (! is_int($n) || $n < 0)) {
                return 'Les erreurs d’authentification et les pièges sont des entiers positifs ou nuls.';
            }
        }
        if (! is_string($preuve)) {
            return 'La preuve (texte lu dans la console) est obligatoire.';
        }
        try {
            $preuve = DepotPreuves::nettoyer($preuve);
        } catch (\InvalidArgumentException) {
            return 'Preuve invalide (UTF-8 attendu).';
        }
        if (preg_match('/[\p{Cf}\p{Cc}]/u', $preuve) === 1 || mb_strlen($preuve) < 10 || mb_strlen($preuve) > 500) {
            return 'La preuve doit faire de 10 à 500 caractères, sans caractère de contrôle.';
        }
        if (DepotPreuves::ressembleAUneAdresse($preuve)) {
            return 'La preuve ne doit contenir aucune adresse e-mail.';
        }

        return new ConstatReputation($source, $type, mb_strtolower($ressource), $jour, $reputation, $taux === null ? null : (float) $taux, $auth, $pieges, $preuve, $auteur);
    }

    private static function ressourceValide(string $type, string $r): bool
    {
        if ($type === 'ip') {
            return filter_var($r, FILTER_VALIDATE_IP) !== false;
        }

        return strlen($r) <= 253 && preg_match('/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $r) === 1;
    }

    private function sansEspace(): JsonResponse
    {
        return $this->erreur('interdit', 'Aucun espace de travail n’est sélectionné pour votre compte.', 403);
    }

    private function erreur(string $code, string $message, int $statut): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $statut);
    }

    /**
     * @param  array<string, mixed>  $l
     * @return array<string, mixed> forme `ConstatReputation` du contrat
     */
    private static function exposer(array $l): array
    {
        return [
            'id' => (int) $l['id'],
            'source' => $l['source'],
            'ressource_type' => $l['ressource_type'],
            'ressource' => $l['ressource'],
            'jour' => substr((string) $l['jour'], 0, 10),
            'reputation' => $l['reputation'],
            'taux_plaintes' => $l['taux_plaintes'] === null ? null : (float) $l['taux_plaintes'],
            'erreurs_auth' => $l['erreurs_auth'] === null ? null : (int) $l['erreurs_auth'],
            'pieges' => $l['pieges'] === null ? null : (int) $l['pieges'],
            'saisie' => $l['saisie'],
            'preuve' => $l['preuve'],
            'auteur' => $l['auteur'],
            'cree_le' => (new DateTimeImmutable((string) $l['cree_le']))->format('Y-m-d\TH:i:sP'),
            'pause_ouverte' => (bool) $l['pause_ouverte'],
        ];
    }
}
