<?php

namespace App\Envoi\Desinscription;

use App\Support\HmacSignature;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Jeton de désinscription signé (HMAC-SHA256 via `HmacSignature`).
 *
 * Forme : `v1.<espace>.<référence>.<échéance>.<signature>`. La référence est un
 * UUID aléatoire lié à une inscription : AUCUNE donnée de personne (ni adresse,
 * ni empreinte) ne voyage dans le lien. La signature couvre l'espace, la
 * référence et l'échéance. La clé vient de la configuration
 * (`envoi.cle_desinscription`, variable d'environnement) — jamais du dépôt ;
 * clé vide = émission impossible et toute vérification échoue (porte fermée).
 */
final class JetonDesinscription
{
    private const VERSION = 'v1';

    private const LONGUEUR_MAX = 300;

    /** Longueur minimale de la clé, contrôlée à l'émission : une clé courte se devine. */
    public const CLE_LONGUEUR_MIN = 32;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function emettre(string $workspaceId, string $reference, ?DateTimeInterface $maintenant = null): string
    {
        $cle = self::cle();
        if ($cle === '') {
            throw new RuntimeException('Clé de désinscription absente (envoi.cle_desinscription) : jeton non émis.');
        }
        if (strlen($cle) < self::CLE_LONGUEUR_MIN) {
            throw new RuntimeException('Clé de désinscription trop courte (' . self::CLE_LONGUEUR_MIN . ' caractères au moins) : jeton non émis.');
        }
        $depart = $maintenant ?? Carbon::now();
        $jours = max(1, (int) config('envoi.desinscription_validite_jours', 730));
        $echeance = $depart->getTimestamp() + $jours * 86400;
        $charge = implode('.', [self::VERSION, $workspaceId, $reference, (string) $echeance]);

        return $charge . '.' . HmacSignature::sign($cle, $charge);
    }

    /**
     * @param  bool  $accepterEchu  vrai pour la page (GET) et l'opposition (POST) : l'échéance n'est alors pas contrôlée, la signature reste exigée
     * @return array{workspace_id: string, reference: string}|null null pour TOUTE
     *                                                             cause de refus (forme, signature, échéance) : l'appelant ne peut pas les distinguer.
     */
    public static function verifier(string $jeton, bool $accepterEchu = false): ?array
    {
        $cle = self::cle();
        if ($cle === '' || strlen($jeton) > self::LONGUEUR_MAX) {
            return null;
        }
        $parties = explode('.', $jeton);
        if (count($parties) !== 5 || $parties[0] !== self::VERSION) {
            return null;
        }
        [, $espace, $reference, $echeance, $signature] = $parties;
        if (preg_match(self::UUID, $espace) !== 1 || preg_match(self::UUID, $reference) !== 1 || preg_match('/^\d{1,12}$/', $echeance) !== 1) {
            return null;
        }
        if (! HmacSignature::verify($cle, implode('.', [self::VERSION, $espace, $reference, $echeance]), $signature)) {
            return null;
        }
        // Une opposition ne coûte rien : la page (GET, sans écriture) et le POST acceptent un jeton signé mais échu ($accepterEchu).
        if (! $accepterEchu && (int) $echeance < Carbon::now()->getTimestamp()) {
            return null;
        }

        return ['workspace_id' => $espace, 'reference' => $reference];
    }

    /** Lien complet à placer dans le message et dans l'en-tête List-Unsubscribe. */
    public static function url(string $workspaceId, string $reference): string
    {
        return route('envoi.desinscription.page', ['jeton' => self::emettre($workspaceId, $reference)]);
    }

    private static function cle(): string
    {
        return (string) config('envoi.cle_desinscription', '');
    }
}
