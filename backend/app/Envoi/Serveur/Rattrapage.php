<?php

namespace App\Envoi\Serveur;

use App\Envoi\Serveur\Contrat\TireurEvenements;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Tirage par curseur, borné : 72 h en arrière au plus, quelques pages par passage. */
final class Rattrapage
{
    public const HEURES_MAX = 72;

    public function __construct(private readonly TireurEvenements $tireur, private readonly RecepteurEvenements $recepteur) {}

    public function executer(string $workspaceId, string $source, int $pagesMax = 20): void
    {
        $depuis = new DateTimeImmutable('-' . self::HEURES_MAX . ' hours');
        for ($page = 0; $page < $pagesMax; $page++) {
            $dernier = (int) WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_curseurs_serveur')
                ->where('workspace_id', $workspaceId)->where('source', $source)->value('dernier_seq'));
            $lot = $this->tireur->tirer($workspaceId, $source, (string) $dernier, 100, $depuis);
            if ($lot->evenements === []) {
                return;
            }
            $this->recepteur->recevoir($workspaceId, $source, $lot->evenements);
            if ($lot->curseurSuivant === null) {
                return;
            }
        }
    }
}
