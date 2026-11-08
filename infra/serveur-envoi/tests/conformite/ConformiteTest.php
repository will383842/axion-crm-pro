<?php

declare(strict_types=1);

namespace Conformite;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kit de conformité de la passerelle (contrat 26 § 3.5). INDÉPENDANT du code de la passerelle : il signe et parle HTTP
 * tout seul, donc il valide une implémentation quelconque. Paramètres (environnement) :
 *   PASSERELLE_URL        adresse de base (sans « / » final) — absente : tout le kit est ignoré
 *   PASSERELLE_WORKSPACE  UUID de l'espace de test
 *   PASSERELLE_CLE        clé HMAC de TEST de cet espace (jamais une clé de production)
 *   PASSERELLE_CLE_ID     identifiant de clé (défaut « 1 »)
 *   PASSERELLE_CURSEUR_PURGE  (facultatif) un curseur situé SOUS la borne de purge ; absent : le cas 410 est ignoré
 * La passerelle de test pilote un faux KumoMTA : aucun envoi réel.
 */
final class ConformiteTest extends TestCase
{
    private string $url;
    private string $ws;
    private string $cle;
    private string $cleId;

    protected function setUp(): void
    {
        $url = getenv('PASSERELLE_URL') ?: '';
        if ($url === '') {
            $this->markTestSkipped('PASSERELLE_URL absent : kit de conformité non exécuté.');
        }
        $this->url = rtrim($url, '/');
        $this->ws = (string) getenv('PASSERELLE_WORKSPACE');
        $this->cle = (string) getenv('PASSERELLE_CLE');
        $this->cleId = getenv('PASSERELLE_CLE_ID') ?: '1';
        $this->assertNotSame('', $this->ws, 'PASSERELLE_WORKSPACE requis');
        $this->assertGreaterThanOrEqual(32, strlen($this->cle), 'PASSERELLE_CLE (32 octets au moins) requise');
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** @return array{0:string,1:array<string,string>} chemin complet + en-têtes signés (charge : METHODE\nchemin\nts\nnonce\nws\ncorps) */
    private function signer(string $methode, string $chemin, string $requete, string $corps, ?int $ts = null, ?string $nonce = null, ?string $cle = null): array
    {
        $ts ??= time();
        $nonce ??= self::uuid();
        $charge = $methode."\n".$chemin."\n".$ts."\n".$nonce."\n".$this->ws."\n".($methode === 'GET' ? $requete : $corps);
        $sig = hash_hmac('sha256', $charge, $cle ?? $this->cle);

        return [$chemin.($requete !== '' ? '?'.$requete : ''), [
            'X-Envoi-Workspace' => $this->ws, 'X-Envoi-Timestamp' => (string) $ts, 'X-Envoi-Nonce' => $nonce,
            'X-Envoi-Signature' => 'k='.$this->cleId.',sha256='.$sig,
        ]];
    }

    /**
     * @param array<string,string> $entetes
     * @return array{0:int,1:string,2:array<string,string>}
     */
    private function appeler(string $methode, string $cheminComplet, array $entetes, string $corps = ''): array
    {
        $lignes = array_map(static fn (string $k, string $v): string => $k.': '.$v, array_keys($entetes), $entetes);
        $ctx = stream_context_create(['http' => ['method' => $methode, 'header' => implode("\r\n", $lignes), 'content' => $corps, 'ignore_errors' => true, 'timeout' => 10]]);
        $reponse = @file_get_contents($this->url.$cheminComplet, false, $ctx);
        $this->assertNotFalse($reponse, 'passerelle injoignable sur '.$this->url);
        $statut = 0;
        $retour = [];
        foreach ($http_response_header ?? [] as $l) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $l, $m) === 1) {
                $statut = (int) $m[1];
            } elseif (str_contains($l, ':')) {
                [$k, $v] = explode(':', $l, 2);
                $retour[strtolower(trim($k))] = trim($v);
            }
        }

        return [$statut, $reponse, $retour];
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function lire(string $requete, ?int $ts = null, ?string $nonce = null, ?string $cle = null): array
    {
        [$chemin, $h] = $this->signer('GET', '/v1/evenements', $requete, '', $ts, $nonce, $cle);

        return $this->appeler('GET', $chemin, $h);
    }

    public function test_signature_fausse_401(): void
    {
        [$statut] = $this->lire('curseur=&limite=1', null, null, str_repeat('x', 40));
        $this->assertSame(401, $statut);
    }

    /** @return array<string,array{0:int}> */
    public static function horodatagesHorsFenetre(): array
    {
        return ['6 min dans le passé' => [-360], '6 min dans le futur' => [360]];
    }

    #[DataProvider('horodatagesHorsFenetre')]
    public function test_horodatage_hors_fenetre_401(int $decalage): void
    {
        [$statut] = $this->lire('curseur=&limite=1', time() + $decalage);
        $this->assertSame(401, $statut, 'signature valide mais horodatage à ±6 min');
    }

    public function test_nonce_rejoue_409(): void
    {
        $ts = time();
        $nonce = self::uuid();
        $this->assertSame(200, $this->lire('curseur=&limite=1', $ts, $nonce)[0]);
        $this->assertSame(409, $this->lire('curseur=&limite=1', $ts, $nonce)[0], 'même nonce = rejeu = 409 (et non 401)');
    }

    public function test_commande_rejouee_donne_la_meme_reponse(): void
    {
        $corps = json_encode(['commande_id' => self::uuid(), 'commande' => 'pause'], JSON_THROW_ON_ERROR);
        $envoyer = function () use ($corps): array {
            [$chemin, $h] = $this->signer('POST', '/v1/commandes/pause', '', $corps);

            return $this->appeler('POST', $chemin, $h + ['Content-Type' => 'application/json'], $corps);
        };
        [$s1, $c1] = $envoyer();
        [$s2, $c2] = $envoyer(); // nouveau nonce, MÊME commande_id
        $this->assertSame($s1, $s2);
        $this->assertSame($c1, $c2, 'rejouée = même réponse');
        $this->assertContains($s1, [200, 502], 'réponse de commande attendue');
    }

    public function test_curseur_trop_grand_409(): void
    {
        [$statut, $corps] = $this->lire('curseur=999999999999&limite=1');
        $this->assertSame(409, $statut);
        $this->assertSame('curseur_desynchronise', json_decode($corps, true)['erreur'] ?? null);
    }

    public function test_410_sous_la_borne_de_purge(): void
    {
        $curseur = getenv('PASSERELLE_CURSEUR_PURGE');
        if ($curseur === false || $curseur === '') {
            $this->markTestSkipped('PASSERELLE_CURSEUR_PURGE absent : cas 410 ignoré (il faut une passerelle déjà purgée).');
        }
        [$statut, $corps] = $this->lire('curseur='.$curseur.'&limite=1');
        $this->assertSame(410, $statut);
        $this->assertArrayHasKey('curseur_minimal', json_decode($corps, true));
    }

    public function test_reponse_signee_avec_le_nonce_de_la_requete(): void
    {
        $nonce = self::uuid();
        [$statut, $corps, $h] = $this->lire('curseur=&limite=1', null, $nonce);
        $this->assertSame(200, $statut);
        $attendue = hash_hmac('sha256', "REPONSE\n".$nonce."\n".$this->ws."\n200\n".$corps, $this->cle);
        $this->assertSame('k='.$this->cleId.',sha256='.$attendue, $h['x-envoi-signature'] ?? '');
    }
}
