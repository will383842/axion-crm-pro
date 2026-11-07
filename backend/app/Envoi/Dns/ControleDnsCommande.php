<?php

namespace App\Envoi\Dns;

use App\Envoi\Depot\DepotSocleDb;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Contrôle DNS quotidien de tous les domaines d'envoi. Lit le DNS, écrit des ajouts, n'envoie rien. */
final class ControleDnsCommande extends Command
{
    public const SIGNATURE_PLANIFIEE = 'envoi:controle-dns';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Envoi : contrôle quotidien de cohérence DNS des domaines d\'envoi';

    public function handle(): int
    {
        $dns = app()->bound(ResolveurDns::class) ? app(ResolveurDns::class) : new ResolveurDnsSysteme;
        $controle = new ControleQuotidien($dns, new DepotControlesDnsDb, new DepotSocleDb, fn () => new DateTimeImmutable('now'));
        /** @var array<string, array<string, string>> $config */
        $config = (array) config('envoi.dns.domaines', []);

        foreach (DB::table('workspaces')->pluck('id') as $ws) {
            $ws = (string) $ws;
            $noms = WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->pluck('nom')->all());
            foreach ($noms as $nom) {
                $p = $config[(string) $nom] ?? null;
                if ($p === null) {
                    $this->warn('domaine sans configuration DNS : ignoré');

                    continue;
                }
                try {
                    $c = new ConfigurationDomaine((string) $nom, (string) ($p['selecteur'] ?? ''), (string) ($p['helo'] ?? ''), (string) ($p['ip'] ?? ''));
                } catch (InvalidArgumentException) {
                    $this->warn('configuration DNS invalide : ignorée');

                    continue;
                }
                $r = $controle->controler($ws, $c);
                $this->line(sprintf('%s : %s%s', $c->domaine, $r->pret() ? 'prêt' : 'pas prêt', $r->pause() ? ' (pause)' : ''));
            }
        }

        return self::SUCCESS;
    }
}
