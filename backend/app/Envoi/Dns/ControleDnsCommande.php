<?php

namespace App\Envoi\Dns;

use App\Envoi\Depot\DepotSocleDb;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        $lot = new ControleDnsLot(
            $controle,
            $config,
            fn () => DB::table('workspaces')->whereNull('deleted_at')->pluck('id'),
            fn (string $ws) => WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->pluck('nom')->all()),
            fn (string $m) => $this->warn($m),
            fn (string $m) => $this->line($m),
        );

        return $lot->executer() > 0 ? self::FAILURE : self::SUCCESS;
    }
}
