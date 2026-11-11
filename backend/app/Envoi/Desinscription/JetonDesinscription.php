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

    private const HOTES = ['app', 'api'];

    /** Longueur minimale de la clé, contrôlée à l'émission : une clé courte se devine. */
    public const CLE_LONGUEUR_MIN = 32;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function emettre(string $workspaceId, string $reference, ?DateTimeInterface $maintenant = null): string
    {
        $cle = self::cle();
        if ($cle === '') {
            throw new RuntimeException('Clé de désinscription absente (envoi.cle_desinscription) : jeton non émis.');
        }
        if (! self::cleAssezLongue($cle)) {
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

    /**
     * Lien complet à placer dans le message et dans l'en-tête List-Unsubscribe. L'hôte est FIXÉ par la
     * configuration (`envoi.desinscription_hote` = app|api, adresses dans `envoi.desinscription_hotes`) :
     * jamais déduit de la requête en cours (en-tête Host, proxy, tâche planifiée). Non configuré = lien refusé.
     */
    public static function url(string $workspaceId, string $reference): string
    {
        $base = self::baseHote();

        return $base . route('envoi.desinscription.page', ['jeton' => self::emettre($workspaceId, $reference)], false);
    }

    private static function baseHote(): string
    {
        $hote = (string) config('envoi.desinscription_hote', 'app');
        if (! in_array($hote, self::HOTES, true)) {
            throw new RuntimeException('Hôte des liens de désinscription inconnu (envoi.desinscription_hote : app ou api).');
        }
        $base = self::adresseValide($hote);
        if ($base === null) {
            throw new RuntimeException("Adresse de l'hôte « {$hote} » des liens de désinscription absente ou invalide (envoi.desinscription_hotes) : lien non émis.");
        }

        return $base;
    }

    /**
     * Adresse de l'hôte $hote (app|api) si elle est valide, sinon null : sans blancs de bord, https (http hors production),
     * avec un host, sans chemin, requête, fragment ni identifiant. Règle UNIQUE, partagée par `baseHote()` et le feu vert.
     */
    private static function adresseValide(string $hote): ?string
    {
        $base = rtrim(trim((string) (((array) config('envoi.desinscription_hotes', []))[$hote] ?? '')), '/');
        $p = parse_url($base);
        $schemaOk = is_array($p) && in_array($p['scheme'] ?? '', app()->environment('production') ? ['https'] : ['https', 'http'], true);
        if ($base === '' || ! $schemaOk || ($p['host'] ?? '') === '' || isset($p['path']) || isset($p['query']) || isset($p['fragment']) || isset($p['user'])) {
            return null;
        }

        return $base;
    }

    /** Vrai si l'hôte choisi vaut app|api ET si les DEUX adresses sont valides (lecture seule : aucun lien n'est émis). */
    public static function hotesConfiguresValides(): bool
    {
        if (! in_array((string) config('envoi.desinscription_hote', 'app'), self::HOTES, true)) {
            return false;
        }
        foreach (self::HOTES as $hote) {
            if (self::adresseValide($hote) === null) {
                return false;
            }
        }

        return true;
    }

    /** Règle de longueur de la clé (après retrait des blancs de bord). Source UNIQUE : émission, garde de démarrage, feu vert. */
    public static function cleAssezLongue(string $cle): bool
    {
        return strlen(trim($cle)) >= self::CLE_LONGUEUR_MIN;
    }

    /** La clé configurée respecte la règle (lecture seule, la valeur n'est jamais exposée). */
    public static function cleConfigureeValide(): bool
    {
        return self::cleAssezLongue(self::cle());
    }

    /**
     * Garde de démarrage : en production ET seulement si l'envoi réel est ouvert (`envoi.reel_actif`), une clé de
     * signature de moins de 32 caractères (espaces retirés) ou absente interdit le démarrage. Interrupteur éteint
     * (défaut) : jamais d'échec du démarrage général (web, Horizon, scheduler, migrate) ; l'émission d'un lien reste
     * refusée par `emettre()`. Jamais sous `testing` (ni en local) : les tests posent leurs propres clés.
     */
    public static function garderAuDemarrage(string $environnement): void
    {
        if ($environnement === 'production' && config('envoi.reel_actif') === true && ! self::cleConfigureeValide()) {
            throw new RuntimeException('ENVOI_CLE_DESINSCRIPTION absente ou trop courte (' . self::CLE_LONGUEUR_MIN . ' caractères au moins) : démarrage refusé en production.');
        }
    }

    private static function cle(): string
    {
        return trim((string) config('envoi.cle_desinscription', ''));
    }
}
