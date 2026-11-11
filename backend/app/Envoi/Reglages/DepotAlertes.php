<?php

namespace App\Envoi\Reglages;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Alertes d'envoi (R14) : notifications du produit « envoi » de la personne + pauses d'envoi, et règles d'alerte.
 * Lecture des pauses SEULE : `envoi_pauses` n'est jamais écrit ici (régler une alerte ne lève jamais un frein).
 */
final class DepotAlertes
{
    public const REGROUPEMENT_MINUTES = 15;

    public const CANAUX = ['console', 'email'];

    /** Motifs d'alerte connus (TypeAlerte du contrat) et leur nom par défaut. */
    public const TYPES = [
        'taux_refus' => 'Taux de refus élevé', 'plainte' => 'Plainte reçue', 'liste_noire' => 'Domaine en liste noire',
        'boite_ralentie' => 'Boîte ralentie', 'test_matin' => 'Test du matin en échec', 'reponse_interessee' => 'Réponse intéressée',
        'quota_atteint' => 'Quota atteint', 'temoin_indesirable' => 'Message en indésirables chez un témoin',
        'reponses_en_baisse' => 'Réponses en baisse', 'reports_en_hausse' => 'Reports en hausse',
        'homme_mort' => 'Tableau de bord non validé', 'sante_silencieuse' => 'Santé silencieuse',
        'reponses_non_traitees' => 'Réponses non traitées', 'reputation_mauvaise' => 'Réputation dégradée',
    ];

    /** @return list<array<string, mixed>> forme `AlerteEnvoi`, plus récentes d'abord */
    public function lister(string $ws, string $userId): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $userId): array {
            $reglees = DB::table('envoi_alertes_reglees')->where('workspace_id', $ws)->pluck('alerte_id')->flip()->all();
            $alertes = [];
            foreach (DB::table('notifications')->where('workspace_id', $ws)->where('user_id', $userId)->where('produit', 'envoi')
                ->where('type', 'like', 'alerte.%')->orderByDesc('created_at')->limit(200)->get() as $n) {
                $alertes[] = self::depuisNotification($n, isset($reglees['n-' . $n->id]));
            }
            foreach (DB::table('envoi_pauses')->where('workspace_id', $ws)->orderByDesc('declenchee_le')->limit(200)->get() as $p) {
                $alertes[] = self::depuisPause($p, isset($reglees['p-' . $p->id]));
            }
            usort($alertes, fn (array $a, array $b): int => strcmp($b['survenue_le'], $a['survenue_le']));

            return $alertes;
        });
    }

    /** @return array<string, mixed>|null l'alerte réglée, ou null si elle n'existe pas pour cette personne dans cet espace */
    public function regler(string $ws, string $userId, string $id, string $auteur): ?array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $userId, $id, $auteur): ?array {
            $existe = collect($this->lister($ws, $userId))->contains(fn (array $a): bool => $a['id'] === $id);
            if (! $existe) {
                return null;
            }
            DB::statement(
                'INSERT INTO envoi_alertes_reglees (workspace_id, alerte_id, auteur) VALUES (?, ?, ?) ON CONFLICT (workspace_id, alerte_id) DO NOTHING',
                [$ws, $id, mb_substr($auteur, 0, 100)],
            );

            return collect($this->lister($ws, $userId))->first(fn (array $a): bool => $a['id'] === $id);
        });
    }

    /** @return list<array<string, mixed>> forme `RegleAlerte` : dernière version de chaque type, défauts sinon */
    public function regles(string $ws): array
    {
        $dernieres = WorkspaceContext::run($ws, fn () => DB::table('envoi_regles_alerte')->where('workspace_id', $ws)
            ->orderBy('id')->get()->keyBy('condition')->all());
        $regles = [];
        foreach (self::TYPES as $type => $nom) {
            $l = $dernieres[$type] ?? null;
            $regles[] = [
                'id' => $type, 'nom' => $l === null ? $nom : (string) $l->nom, 'condition' => $type,
                'seuil' => $l?->seuil === null ? null : (float) $l->seuil,
                'canaux' => $l === null ? self::CANAUX : array_values(array_filter(explode(',', (string) $l->canaux))),
                'actif' => $l === null ? true : (bool) $l->actif,
            ];
        }

        return $regles;
    }

    /** @param list<array{condition: string, nom: string, seuil: float|int|null, canaux: list<string>, actif: bool}> $regles */
    public function enregistrer(string $ws, array $regles, string $auteur): void
    {
        WorkspaceContext::run($ws, function () use ($ws, $regles, $auteur): void {
            foreach ($regles as $r) {
                DB::table('envoi_regles_alerte')->insert([
                    'workspace_id' => $ws, 'condition' => $r['condition'], 'nom' => $r['nom'], 'seuil' => $r['seuil'],
                    'canaux' => implode(',', $r['canaux']), 'actif' => $r['actif'], 'auteur' => mb_substr($auteur, 0, 100),
                ]);
            }
        });
    }

    /** @return array<string, mixed> */
    private static function depuisNotification(\stdClass $n, bool $reglee): array
    {
        $niveau = $reglee ? 'fait' : match ($n->gravite) {
            'critique' => 'a_faire', 'attention' => 'attention', default => 'nouveau',
        };

        return [
            'id' => 'n-' . $n->id, 'titre' => (string) $n->title, 'detail' => (string) ($n->body ?? ''),
            'ce_qui_se_passe' => (string) ($n->body ?? $n->title),
            'ce_que_le_systeme_a_fait' => 'Une alerte a été envoyée dans la cloche (et par e-mail selon vos règles).',
            'que_faire' => 'Ouvrez l’écran lié, vérifiez la situation, puis marquez l’alerte comme réglée.',
            'niveau' => $niveau, 'survenue_le' => self::iso($n->created_at), 'lien' => $n->action_url, 'close' => $reglee,
        ];
    }

    /** @return array<string, mixed> */
    private static function depuisPause(\stdClass $p, bool $reglee): array
    {
        $ouverte = $p->levee_le === null;
        $portee = $p->portee === 'global' ? 'tous les envois' : 'les envois vers ' . $p->fournisseur;

        return [
            'id' => 'p-' . $p->id, 'titre' => 'Envois en pause : ' . $p->motif,
            'detail' => ($ouverte ? 'Pause ouverte' : 'Pause levée') . ' (' . $portee . ').',
            'ce_qui_se_passe' => 'Un frein a mis en pause ' . $portee . ' (motif : ' . $p->motif . ').',
            'ce_que_le_systeme_a_fait' => $ouverte ? 'Les envois concernés sont arrêtés.' : 'La pause a été levée ; elle reste dans l’historique.',
            'que_faire' => 'Marquer l’alerte comme réglée ne lève JAMAIS la pause : la levée reste une décision humaine hors de cet écran.',
            'niveau' => $reglee ? 'fait' : ($ouverte ? 'a_faire' : 'nouveau'), 'survenue_le' => self::iso($p->declenchee_le),
            'lien' => '/envoi/sante', 'close' => $reglee,
        ];
    }

    private static function iso(mixed $v): string
    {
        return (new \DateTimeImmutable((string) $v))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
