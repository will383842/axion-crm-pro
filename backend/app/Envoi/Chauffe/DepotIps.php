<?php

namespace App\Envoi\Chauffe;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * X6-A — inventaire des IP d'envoi (`envoi_ips`) et IP d'un domaine (`envoi_domaines.ip_id`).
 * Lecture et écriture sous le contexte de l'espace ET avec filtre `workspace_id` (RLS + filtre). Ajout seul : une IP
 * se retire (`retiree_le`), jamais ne se supprime. La liaison passe par `envoi_domaine_ip_poser` (aucun GRANT sur `ip_id`).
 */
final class DepotIps
{
    public const FLUX = ['prospection', 'lettre'];

    /** Forme seulement (IPv4 pointée, octets 0-255) : les IP privées sont permises, elles servent aux tests. */
    public static function formeValide(string $ip): bool
    {
        return preg_match('/^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])$/', $ip) === 1;
    }

    /** IP non retirée du domaine, ou null. */
    public function ipDuDomaine(string $ws, string $domaine): ?string
    {
        $ref = WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines as d')
            ->join('envoi_ips as i', fn ($j) => $j->on('i.workspace_id', '=', 'd.workspace_id')->on('i.id', '=', 'd.ip_id'))
            ->where('d.workspace_id', $ws)->where('d.nom', $domaine)->whereNull('i.retiree_le')
            ->value('i.ref'));

        return $ref === null ? null : (string) $ref;
    }

    /** @return array{prospection: list<string>, lettre: list<string>} IP non retirées, triées. */
    public function ipsParFlux(string $ws): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_ips')
            ->where('workspace_id', $ws)->whereNull('retiree_le')->orderBy('ref')->get(['ref', 'flux']));
        $out = ['prospection' => [], 'lettre' => []];
        foreach ($lignes as $l) {
            $out[(string) $l->flux][] = (string) $l->ref;
        }
        $trier = static function (array $refs): array {
            usort($refs, fn (string $x, string $y): int => ip2long($x) <=> ip2long($y));

            return $refs;
        };

        return ['prospection' => $trier($out['prospection']), 'lettre' => $trier($out['lettre'])];
    }

    /** Domaines de l'espace sans IP active (aucune, ou retirée). */
    public function domainesSansIp(string $ws): int
    {
        return (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines as d')
            ->leftJoin('envoi_ips as i', fn ($j) => $j->on('i.workspace_id', '=', 'd.workspace_id')->on('i.id', '=', 'd.ip_id')->whereNull('i.retiree_le'))
            ->where('d.workspace_id', $ws)->whereNull('i.id')->count());
    }

    public function ajouter(string $ws, string $ip, string $flux, string $auteur): void
    {
        if (! self::formeValide($ip)) {
            throw new InvalidArgumentException('forme d’IP invalide');
        }
        if (! in_array($flux, self::FLUX, true)) {
            throw new InvalidArgumentException('flux invalide (prospection ou lettre)');
        }
        WorkspaceContext::run($ws, fn () => DB::transaction(fn () => DB::table('envoi_ips')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $ws, 'ref' => $ip, 'flux' => $flux, 'auteur' => $auteur,
        ])));
    }

    /** @return bool faux si l'IP est inconnue ou déjà retirée */
    public function retirer(string $ws, string $ip): bool
    {
        return WorkspaceContext::run($ws, fn () => DB::table('envoi_ips')
            ->where('workspace_id', $ws)->where('ref', $ip)->whereNull('retiree_le')->update(['retiree_le' => now()])) > 0;
    }

    /** @return bool faux si la liaison existait déjà (rien changé) ; la base refuse le reste (flux, IP retirée, espace). */
    public function lier(string $ws, string $domaine, string $ip, string $auteur): bool
    {
        if (! self::formeValide($ip)) {
            throw new InvalidArgumentException('forme d’IP invalide');
        }

        return (bool) WorkspaceContext::run($ws, fn () => DB::transaction(fn () => DB::selectOne('SELECT envoi_domaine_ip_poser(?, ?, ?, ?) AS ok', [$ws, $domaine, $ip, $auteur])->ok));
    }
}
