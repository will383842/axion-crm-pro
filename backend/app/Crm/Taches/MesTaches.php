<?php

namespace App\Crm\Taches;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Ligne array{id: int, title: string, due_at: string|null, done_at: string|null, payload: string, subject_type: string|null, subject_id: int|null}
 *
 * « MES TÂCHES » (lot G2-A, cahier G1-G2 § 8) : lecture et écriture des tâches
 * de l'espace business pour l'utilisateur courant.
 *
 * Une tâche est une ligne `activities` de nature `task` (la même que celles
 * écrites par `CompanyTachesController` et `PersonnesController`). Il n'y a pas
 * d'« assigné » : « mes tâches » = celles que j'ai créées + celles du système
 * (`user_id IS NULL`). On ne SUPPRIME JAMAIS une tâche : on la marque faite, ou
 * on la rouvre.
 *
 * Tout est exécuté dans un `WorkspaceContext::run` posé par l'appelant ; le
 * filtre `workspace_id` reste pourtant explicite dans chaque requête (le
 * propriétaire, superutilisateur en test, ignore la RLS). Le prédicat
 * `kind = 'task'` est recopié MOT POUR MOT de l'index `idx_activities_taches`.
 */
final class MesTaches
{
    public const FUSEAU = 'Europe/Paris';

    public const LIMITE = 500;

    /** Une tâche faite reste visible ce nombre de jours. */
    public const JOURS_FAITES_VISIBLES = 7;

    /** Heure (Paris) donnée à une échéance sans heure : au milieu du jour, hors de tout bord de date. */
    private const HEURE_DEFAUT = '12:00';

    /** @return list<array<string,mixed>> */
    public static function liste(string $workspaceId, string $userId, CarbonImmutable $maintenant): array
    {
        $seuil = $maintenant->subDays(self::JOURS_FAITES_VISIBLES);

        $lignes = DB::select(
            "SELECT a.id, a.title, a.due_at, a.done_at, a.payload, a.subject_type, a.subject_id
             FROM activities a
             WHERE a.workspace_id = :ws AND a.kind = 'task'
               AND (a.user_id = :moi OR a.user_id IS NULL)
               AND (a.done_at IS NULL OR a.done_at >= :seuil)
             ORDER BY a.due_at NULLS LAST, a.id
             LIMIT " . self::LIMITE,
            ['ws' => $workspaceId, 'moi' => $userId, 'seuil' => self::ts($seuil)],
        );

        return self::projeter($workspaceId, array_map(self::normaliser(...), array_values($lignes)), $maintenant);
    }

    /**
     * Crée la tâche (ou relit celle du même geste dans la même minute).
     *
     * @return array{tache: array<string,mixed>|null, creee: bool}
     */
    public static function creer(string $workspaceId, string $userId, string $titre, ?string $contexte, ?string $echeance, ?string $heure, CarbonImmutable $maintenant): array
    {
        $paris = $maintenant->setTimezone(self::FUSEAU);
        $jour = $echeance ?? $paris->format('Y-m-d');
        $due = $echeance === null && $heure === null
            ? null
            : CarbonImmutable::parse($jour . ' ' . ($heure ?? self::HEURE_DEFAUT), self::FUSEAU)->utc();

        $payload = ['origine' => 'manuelle', 'genre' => 'tache', 'avec_heure' => $heure !== null];
        if ($contexte !== null && $contexte !== '') {
            $payload['contexte'] = $contexte;
        }

        // Un double clic ne crée qu'une ligne (empreinte du geste à la minute).
        $ref = 'console:moi:' . $userId . ':task:' . $maintenant->utc()->format('Y-m-d\TH:i') . ':' . substr(sha1($titre), 0, 12);

        $cree = DB::table('activities')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'type' => 'task',
            'kind' => 'task',
            'occurred_at' => self::ts($maintenant),
            'due_at' => $due === null ? null : self::ts($due),
            'external_ref' => $ref,
            'subject_type' => null,
            'subject_id' => null,
            'title' => $titre,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => self::ts($maintenant),
        ]);

        $ligne = DB::selectOne(
            "SELECT a.id, a.title, a.due_at, a.done_at, a.payload, a.subject_type, a.subject_id
             FROM activities a WHERE a.workspace_id = :ws AND a.kind = 'task' AND a.external_ref = :ref",
            ['ws' => $workspaceId, 'ref' => $ref],
        );

        return ['tache' => $ligne === null ? null : self::projeter($workspaceId, [self::normaliser($ligne)], $maintenant)[0], 'creee' => $cree > 0];
    }

    /**
     * Marque faite / rouvre. Null = introuvable pour moi (autre espace, tâche
     * d'un collègue, autre nature d'activité) : l'appelant répond 404.
     *
     * @return array<string,mixed>|null
     */
    public static function basculer(string $workspaceId, string $userId, int $id, bool $faite, CarbonImmutable $maintenant): ?array
    {
        $ligne = DB::selectOne(
            "SELECT a.id, a.title, a.due_at, a.done_at, a.payload, a.subject_type, a.subject_id
             FROM activities a
             WHERE a.workspace_id = :ws AND a.kind = 'task' AND a.id = :id
               AND (a.user_id = :moi OR a.user_id IS NULL)
             FOR UPDATE",
            ['ws' => $workspaceId, 'id' => $id, 'moi' => $userId],
        );
        if ($ligne === null) {
            return null;
        }
        $ligne = self::normaliser($ligne);

        if ($faite && $ligne['done_at'] === null) {
            DB::table('activities')->where('workspace_id', $workspaceId)->where('id', $id)->where('kind', 'task')
                ->update(['done_at' => self::ts($maintenant)]);
            $ligne['done_at'] = self::ts($maintenant);
        } elseif (! $faite && $ligne['done_at'] !== null) {
            DB::table('activities')->where('workspace_id', $workspaceId)->where('id', $id)->where('kind', 'task')
                ->update(['done_at' => null]);
            $ligne['done_at'] = null;
        }

        return self::projeter($workspaceId, [$ligne], $maintenant)[0];
    }

    /**
     * Ligne SQL brute -> tableau typé (les colonnes lues sont celles du SELECT).
     *
     * @return Ligne
     */
    private static function normaliser(object $r): array
    {
        $texte = static fn (string $k): ?string => isset($r->{$k}) && is_scalar($r->{$k}) ? (string) $r->{$k} : null;

        return [
            'id' => (int) $texte('id'),
            'title' => $texte('title') ?? '',
            'due_at' => $texte('due_at'),
            'done_at' => $texte('done_at'),
            'payload' => $texte('payload') ?? '{}',
            'subject_type' => $texte('subject_type'),
            'subject_id' => $texte('subject_id') === null ? null : (int) $texte('subject_id'),
        ];
    }

    /** Horodatage AVEC décalage : la session SQL est en Europe/Paris, un format nu serait lu comme heure de Paris. */
    private static function ts(CarbonImmutable $t): string
    {
        return $t->utc()->format('Y-m-d H:i:sP');
    }

    public static function aujourdhui(CarbonImmutable $maintenant): string
    {
        return $maintenant->setTimezone(self::FUSEAU)->format('Y-m-d');
    }

    /**
     * @param  list<Ligne>  $lignes
     * @return list<array<string,mixed>>
     */
    private static function projeter(string $workspaceId, array $lignes, CarbonImmutable $maintenant): array
    {
        $noms = self::nomsDesSujets($workspaceId, $lignes);

        return array_map(function (array $l) use ($noms, $maintenant): array {
            $payload = json_decode($l['payload'], true);
            $payload = is_array($payload) ? $payload : [];
            $due = $l['due_at'] === null ? null : CarbonImmutable::parse($l['due_at'])->setTimezone(self::FUSEAU);
            $contexte = $payload['contexte'] ?? null;
            if (! is_string($contexte) || $contexte === '') {
                $contexte = $l['subject_type'] === null ? null : ($noms[$l['subject_type'] . ':' . $l['subject_id']] ?? null);
            }
            $origine = $payload['origine'] ?? 'manuelle';
            $genre = $payload['genre'] ?? 'tache';

            return [
                'id' => (string) $l['id'],
                'titre' => $l['title'],
                'contexte' => $contexte ?? '—',
                'origine' => $origine === 'reponse' ? 'reponse' : 'manuelle',
                'genre' => in_array($genre, ['rendez_vous', 'relance'], true) ? $genre : 'tache',
                'echeance' => ($due ?? $maintenant->setTimezone(self::FUSEAU))->format('Y-m-d'),
                'heure' => $due !== null && ($payload['avec_heure'] ?? false) === true ? $due->format('H:i') : null,
                'faite' => $l['done_at'] !== null,
                'faite_le' => $l['done_at'] === null ? null : CarbonImmutable::parse($l['done_at'])->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        }, $lignes);
    }

    /**
     * Dénomination de l'entreprise de chaque sujet, en UNE requête par type
     * (jamais une par ligne). Chaque lecture porte `workspace_id` : une
     * entreprise ou un contact d'un autre espace n'est jamais joint.
     *
     * @param  list<Ligne>  $lignes
     * @return array<string,string>
     */
    private static function nomsDesSujets(string $workspaceId, array $lignes): array
    {
        $ids = ['company' => [], 'personne' => [], 'contact' => []];
        foreach ($lignes as $l) {
            if ($l['subject_id'] !== null && isset($ids[$l['subject_type']])) {
                $ids[$l['subject_type']][] = $l['subject_id'];
            }
        }

        $companyDe = [];
        foreach (['personne' => 'personnes', 'contact' => 'contacts'] as $type => $table) {
            if ($ids[$type] === []) {
                continue;
            }
            foreach (DB::table($table)->where('workspace_id', $workspaceId)->whereIn('id', array_unique($ids[$type]))->get(['id', 'company_id']) as $r) {
                if ($r->company_id !== null) {
                    $companyDe[$type . ':' . $r->id] = (int) $r->company_id;
                    $ids['company'][] = (int) $r->company_id;
                }
            }
        }

        $denominations = [];
        if ($ids['company'] !== []) {
            foreach (DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereIn('id', array_unique($ids['company']))->get(['id', 'denomination']) as $r) {
                $denominations[(int) $r->id] = (string) $r->denomination;
            }
        }

        $noms = [];
        foreach ($ids['company'] as $id) {
            if (isset($denominations[$id])) {
                $noms['company:' . $id] = $denominations[$id];
            }
        }
        foreach ($companyDe as $cle => $companyId) {
            if (isset($denominations[$companyId])) {
                $noms[$cle] = $denominations[$companyId];
            }
        }

        return $noms;
    }
}
