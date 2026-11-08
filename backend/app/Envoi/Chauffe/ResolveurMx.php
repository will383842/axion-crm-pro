<?php

namespace App\Envoi\Chauffe;

use Illuminate\Support\Facades\DB;

/**
 * Résolution MX du domaine du destinataire, avec cache en table (`envoi_fournisseur_domaines`, clé « espace|domaine »,
 * ajout/mise à jour seulement, aucune donnée de personne : un domaine et sa famille).
 *  - MX en `*.google.com` / `*.googlemail.com`         => gmail
 *  - MX en `*.outlook.com` / `*.protection.outlook.com` / `*.hotmail.com` => microsoft
 *  - tout le reste                                      => autre ;
 *  - tout ÉCHEC DNS                                     => INCONNU (jamais mis en cache) : la garde le bloque
 *    dès qu'une pause de fournisseur est active (fail-closed), au lieu de le laisser passer comme « autre ».
 * Les domaines publics connus (gmail.com, outlook.com…) ne coûtent aucun appel DNS.
 * La requête DNS est injectable (`$dns`) ; la valeur par défaut REFUSE de sortir en test.
 */
final class ResolveurMx implements ResolveurFournisseur
{
    public const TTL_JOURS = 7;

    private const PUBLICS = [
        'gmail.com' => Fournisseur::GMAIL, 'googlemail.com' => Fournisseur::GMAIL,
        'outlook.com' => Fournisseur::MICROSOFT, 'outlook.fr' => Fournisseur::MICROSOFT, 'hotmail.com' => Fournisseur::MICROSOFT,
        'hotmail.fr' => Fournisseur::MICROSOFT, 'live.com' => Fournisseur::MICROSOFT, 'live.fr' => Fournisseur::MICROSOFT, 'msn.com' => Fournisseur::MICROSOFT,
    ];

    /** @param  (\Closure(string): list<string>)|null  $dns  domaine => liste des hôtes MX ; lève en cas d'échec */
    public function __construct(private readonly ?\Closure $dns = null) {}

    public function fournisseurDe(string $adresse, ?string $workspaceId = null): string
    {
        $domaine = Fournisseur::domaine($adresse);
        if ($domaine === '') {
            return Fournisseur::AUTRE;
        }
        if (isset(self::PUBLICS[$domaine])) {
            return self::PUBLICS[$domaine];
        }
        // Cache cloisonné par espace : la clé est « espace|domaine » (un espace ne lit ni n'écrase la ligne d'un autre).
        $cle = ($workspaceId ?? 'sans-espace') . '|' . $domaine;
        $cache = DB::table('envoi_fournisseur_domaines')->where('domaine', $cle)
            ->where('resolu_le', '>', now()->subDays(self::TTL_JOURS))->value('fournisseur');
        if (is_string($cache)) {
            return $cache;
        }
        try {
            $hotes = $this->interroger($domaine);
        } catch (\LogicException $e) {
            throw $e; // « pas de réseau en test » : jamais avalé
        } catch (\Throwable) {
            return Fournisseur::INCONNU; // échec DNS : fail-closed côté garde, et surtout pas mis en cache
        }
        $f = self::famille($hotes);
        DB::statement(
            'INSERT INTO envoi_fournisseur_domaines (domaine, fournisseur, resolu_le) VALUES (?, ?, now())
             ON CONFLICT (domaine) DO UPDATE SET fournisseur = EXCLUDED.fournisseur, resolu_le = now()',
            [$cle, $f],
        );

        return $f;
    }

    /** @param  list<string>  $hotes */
    public static function famille(array $hotes): string
    {
        foreach ($hotes as $h) {
            $h = rtrim(mb_strtolower($h), '.');
            if (preg_match('/(^|\.)(google|googlemail)\.com$/', $h) === 1) {
                return Fournisseur::GMAIL;
            }
            if (preg_match('/(^|\.)(outlook|hotmail)\.com$/', $h) === 1) {
                return Fournisseur::MICROSOFT;
            }
        }

        return Fournisseur::AUTRE;
    }

    /** @return list<string> */
    private function interroger(string $domaine): array
    {
        if ($this->dns !== null) {
            return ($this->dns)($domaine);
        }
        if (app()->environment('testing')) {
            throw new \LogicException('Résolution MX interdite en test : injecter un faux (ResolveurEnMemoire).');
        }
        $lignes = dns_get_record($domaine, DNS_MX);
        if ($lignes === false) {
            throw new \RuntimeException('dns_get_record a échoué');
        }
        usort($lignes, fn (array $a, array $b): int => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

        return array_map(fn (array $l): string => (string) ($l['target'] ?? ''), $lignes);
    }
}
