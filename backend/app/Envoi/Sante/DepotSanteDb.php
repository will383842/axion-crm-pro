<?php

namespace App\Envoi\Sante;

use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class DepotSanteDb implements DepotSante
{
    public function noterReleve(string $ws, string $source, DateTimeImmutable $quand, bool $ok, array $mesures): void
    {
        WorkspaceContext::run($ws, fn () => DB::table('envoi_releves_sante')->insert([
            'workspace_id' => $ws, 'source' => $source, 'mesure_le' => $quand->format('c'), 'ok' => $ok,
            'mesures' => json_encode($mesures, JSON_THROW_ON_ERROR),
        ]));
    }

    public function dernierReleve(string $ws, string $source): ?array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_releves_sante')->where('workspace_id', $ws)->where('source', $source)->orderByDesc('id')->first());

        return $l === null ? null : ['mesure_le' => (string) $l->mesure_le, 'ok' => (bool) $l->ok, 'mesures' => (array) json_decode((string) $l->mesures, true)];
    }

    public function dernierBattementOk(string $ws, string $source): ?DateTimeImmutable
    {
        $d = WorkspaceContext::run($ws, fn () => DB::table('envoi_releves_sante')->where('workspace_id', $ws)->where('source', $source)->where('ok', true)->max('mesure_le'));

        return $d === null ? null : new DateTimeImmutable((string) $d);
    }

    public function premierReleve(string $ws, string $source): ?DateTimeImmutable
    {
        $d = WorkspaceContext::run($ws, fn () => DB::table('envoi_releves_sante')->where('workspace_id', $ws)->where('source', $source)->min('mesure_le'));

        return $d === null ? null : new DateTimeImmutable((string) $d);
    }

    public function constatsOuverts(string $ws, string $source): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::select(
            "WITH dernier AS (
                SELECT DISTINCT ON (code) id, code, etat, gravite, detail, palier, cree_le FROM envoi_constats_sante
                WHERE workspace_id = ? AND source = ? AND etat IN ('ouvert', 'retabli') ORDER BY code, id DESC
             )
             SELECT d.code, d.gravite, d.detail, d.palier, d.cree_le,
                    EXISTS (SELECT 1 FROM envoi_constats_sante a WHERE a.workspace_id = ? AND a.source = ? AND a.code = d.code
                            AND a.etat = 'alerte_emise' AND a.id > d.id) AS alerte_emise
             FROM dernier d WHERE d.etat = 'ouvert'",
            [$ws, $source, $ws, $source],
        ));
        $out = [];
        foreach ($lignes as $l) {
            $out[(string) $l->code] = ['gravite' => (string) $l->gravite, 'detail' => (string) $l->detail, 'depuis' => (string) $l->cree_le, 'palier' => (int) $l->palier, 'alerte_emise' => (bool) $l->alerte_emise];
        }
        ksort($out);

        return $out;
    }

    public function ouvrir(string $ws, string $source, string $code, string $gravite, string $detail, DateTimeImmutable $quand, int $palier = 1): void
    {
        $this->constat($ws, $source, $code, 'ouvert', $gravite, $detail, $quand, $palier);
    }

    public function marquerAlerteEmise(string $ws, string $source, string $code, string $gravite, DateTimeImmutable $quand): void
    {
        $this->constat($ws, $source, $code, 'alerte_emise', $gravite, '', $quand);
    }

    public function retablir(string $ws, string $source, string $code, DateTimeImmutable $quand): void
    {
        $this->constat($ws, $source, $code, 'retabli', 'attention', '', $quand);
    }

    /** @return list<array{domaine: string, pret: bool, pause: bool, controle_le: string}> */
    public function controlesDns(string $ws): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::select(
            'SELECT DISTINCT ON (domaine) domaine, pret, pause, controle_le FROM envoi_controles_dns WHERE workspace_id = ? ORDER BY domaine, controle_le DESC, id DESC',
            [$ws],
        ));

        return array_values(array_map(static fn ($r): array => ['domaine' => (string) $r->domaine, 'pret' => (bool) $r->pret, 'pause' => (bool) $r->pause, 'controle_le' => (string) $r->controle_le], $l));
    }

    /** @return list<array{type: string, ressource: string, statut: string, pause: bool}> */
    public function controlesListes(string $ws): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::select(
            'SELECT DISTINCT ON (type, ressource) type, ressource, statut, pause FROM envoi_controles_listes WHERE workspace_id = ? ORDER BY type, ressource, id DESC',
            [$ws],
        ));

        return array_values(array_map(static fn ($r): array => ['type' => (string) $r->type, 'ressource' => (string) $r->ressource, 'statut' => (string) $r->statut, 'pause' => (bool) $r->pause], $l));
    }

    /** @return list<array{0: string, 1: string}> */
    public function serveursActifs(): array
    {
        $l = WorkspaceContext::runWithoutScope('supervision de santé : parcourt les serveurs d’envoi de tous les espaces', fn () => DB::table('envoi_serveurs')->where('actif', true)->orderBy('workspace_id')->orderBy('source')->get(['workspace_id', 'source']));

        return array_values(array_map(static fn ($r): array => [(string) $r->workspace_id, (string) $r->source], $l->all()));
    }

    public function dernierReleveImap(string $ws, ?string $boite = null): ?DateTimeImmutable
    {
        $d = WorkspaceContext::run($ws, fn () => DB::table('envoi_curseurs_imap')->where('workspace_id', $ws)
            ->when($boite !== null, fn ($q) => $q->where('boite', $boite))->max('maj_le'));

        return $d === null ? null : new DateTimeImmutable((string) $d);
    }

    public function debutSerieReleves(string $ws, string $source, DateTimeImmutable $jusqua, int $trouSecondes): ?DateTimeImmutable
    {
        $l = WorkspaceContext::run($ws, fn () => DB::selectOne(
            'SELECT max(mesure_le) AS debut FROM (
                SELECT mesure_le, mesure_le - lag(mesure_le) OVER (ORDER BY mesure_le) AS ecart FROM envoi_releves_sante
                WHERE workspace_id = ? AND source = ? AND mesure_le > ?::timestamptz - interval \'3 days\' AND mesure_le <= ?::timestamptz
             ) x WHERE ecart IS NULL OR ecart > make_interval(secs => ?)',
            [$ws, $source, $jusqua->format('c'), $jusqua->format('c'), $trouSecondes],
        ));

        return $l?->debut === null ? null : new DateTimeImmutable((string) $l->debut);
    }

    public function envoisEntre(string $ws, ?DateTimeImmutable $apres, ?DateTimeImmutable $jusqua, ?string $boite = null): int
    {
        return (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_messages_envoyes as m')->where('m.workspace_id', $ws)
            ->when($boite !== null, fn ($q) => $q->join('envoi_injections_smtp as i', 'i.id_injection', '=', 'm.id_injection')
                ->where('i.workspace_id', $ws)->where('i.boite_id', $boite))
            ->when($apres !== null, fn ($q) => $q->where('m.cree_le', '>', $apres?->format('c')))
            ->when($jusqua !== null, fn ($q) => $q->where('m.cree_le', '<=', $jusqua?->format('c')))
            ->count());
    }

    public function boitesAvecEnvoisDepuis(string $ws, DateTimeImmutable $apres): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_messages_envoyes as m')
            ->join('envoi_injections_smtp as i', 'i.id_injection', '=', 'm.id_injection')
            ->where('m.workspace_id', $ws)->where('i.workspace_id', $ws)->where('m.cree_le', '>', $apres->format('c'))
            ->distinct()->orderBy('i.boite_id')->pluck('i.boite_id'));

        return array_values(array_map('strval', $l->all()));
    }

    private function constat(string $ws, string $source, string $code, string $etat, string $gravite, string $detail, DateTimeImmutable $quand, int $palier = 1): void
    {
        WorkspaceContext::run($ws, fn () => DB::table('envoi_constats_sante')->insert([
            'workspace_id' => $ws, 'source' => $source, 'code' => $code, 'etat' => $etat, 'gravite' => $gravite,
            'detail' => mb_substr($detail, 0, 500), 'palier' => $palier, 'cree_le' => $quand->format('c'),
        ]));
    }
}
