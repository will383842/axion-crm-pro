<?php

namespace App\Envoi\Chauffe;

use App\Envoi\JourEnvoi;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Lecture/écriture Postgres (RLS par espace) des compteurs, pauses et seuils. Aucune suppression de ligne. */
final class DepotChauffe
{
    public const COLONNES = ['envoyes', 'rebonds_durs', 'plaintes', 'reponses'];

    public function premierJourBoite(string $ws, string $boite): ?DateTimeImmutable
    {
        $j = WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')
            ->where('workspace_id', $ws)->where('boite', $boite)->min('jour'));

        return $j === null ? null : new DateTimeImmutable((string) $j, new \DateTimeZone(JourEnvoi::FUSEAU));
    }

    public function envoyesBoite(string $ws, string $boite, DateTimeImmutable $jour): int
    {
        return (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')
            ->where('workspace_id', $ws)->where('boite', $boite)
            ->where('jour', $jour->format('Y-m-d'))->sum('envoyes'));
    }

    public function incrementer(string $ws, string $boite, DateTimeImmutable $jour, string $fournisseur, string $colonne, int $n = 1): void
    {
        if (! in_array($colonne, self::COLONNES, true) || ! in_array($fournisseur, Fournisseur::TOUS, true)) {
            throw new \InvalidArgumentException('colonne ou fournisseur inconnu');
        }
        WorkspaceContext::run($ws, fn () => DB::statement(
            "INSERT INTO envoi_chauffe_jours (workspace_id, boite, jour, fournisseur, {$colonne}) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (workspace_id, boite, jour, fournisseur)
             DO UPDATE SET {$colonne} = envoi_chauffe_jours.{$colonne} + EXCLUDED.{$colonne}, mis_a_jour_le = now()",
            [$ws, $boite, $jour->format('Y-m-d'), $fournisseur, $n],
        ));
    }

    /** @return array{envoyes: int, rebonds_durs: int, plaintes: int, reponses: int} tous fournisseurs si $fournisseur est null */
    public function totauxDuJour(string $ws, DateTimeImmutable $jour, ?string $fournisseur = null): array
    {
        $l = WorkspaceContext::run($ws, function () use ($ws, $jour, $fournisseur) {
            $q = DB::table('envoi_chauffe_jours')->where('workspace_id', $ws)->where('jour', $jour->format('Y-m-d'));
            if ($fournisseur !== null) {
                $q->where('fournisseur', $fournisseur);
            }

            return $q->selectRaw('COALESCE(SUM(envoyes),0) e, COALESCE(SUM(rebonds_durs),0) r, COALESCE(SUM(plaintes),0) p, COALESCE(SUM(reponses),0) a')->first();
        });

        return ['envoyes' => (int) $l->e, 'rebonds_durs' => (int) $l->r, 'plaintes' => (int) $l->p, 'reponses' => (int) $l->a];
    }

    /**
     * Les $n fournées (jours avec envois) d'un fournisseur AVANT $jour, les plus récentes d'abord.
     *
     * @return list<array{envoyes: int, reponses: int}>
     */
    public function fourneesPrecedentes(string $ws, string $fournisseur, DateTimeImmutable $jour, int $n): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')
            ->where('workspace_id', $ws)->where('fournisseur', $fournisseur)->where('jour', '<', $jour->format('Y-m-d'))
            ->groupBy('jour')->havingRaw('SUM(envoyes) > 0')->orderByDesc('jour')->limit($n)
            ->selectRaw('SUM(envoyes) e, SUM(reponses) a')->get());

        return $lignes->map(fn ($l): array => ['envoyes' => (int) $l->e, 'reponses' => (int) $l->a])->all();
    }

    /** @return list<object{id: int, portee: string, fournisseur: ?string, motif: string}> */
    public function pausesActives(string $ws): array
    {
        return WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')
            ->where('workspace_id', $ws)->whereNull('levee_le')->orderBy('id')->get(['id', 'portee', 'fournisseur', 'motif'])->all());
    }

    /** @return bool vrai si une pause a été ouverte (pas de doublon actif identique) */
    public function ouvrirPause(string $ws, string $motif, ?string $fournisseur): bool
    {
        return WorkspaceContext::run($ws, fn (): bool => DB::transaction(function () use ($ws, $motif, $fournisseur): bool {
            $existe = DB::table('envoi_pauses')->where('workspace_id', $ws)->whereNull('levee_le')
                ->where('motif', $motif)
                ->when($fournisseur === null, fn ($q) => $q->whereNull('fournisseur'), fn ($q) => $q->where('fournisseur', $fournisseur))
                ->exists();
            if ($existe) {
                return false;
            }
            DB::table('envoi_pauses')->insert([
                'workspace_id' => $ws,
                'portee' => $fournisseur === null ? 'global' : 'fournisseur',
                'fournisseur' => $fournisseur,
                'motif' => $motif,
            ]);

            return true;
        }));
    }

    /** Lever = dater la levée (la ligne reste). Réservé à la commande humaine. */
    public function leverPause(string $ws, int $id, string $par): bool
    {
        return WorkspaceContext::run($ws, fn (): bool => DB::table('envoi_pauses')
            ->where('workspace_id', $ws)->where('id', $id)->whereNull('levee_le')
            ->update(['levee_le' => now(), 'levee_par' => $par]) > 0);
    }

    /** Réponses « en attente » = reçues − traitées, d'après le journal (contrat du lot Retours : commandes `reponse_recue` / `reponse_traitee`). */
    public function reponsesEnAttente(string $ws): int
    {
        $c = WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')
            ->where('workspace_id', $ws)->whereIn('commande', ['reponse_recue', 'reponse_traitee'])
            ->selectRaw("COUNT(*) FILTER (WHERE commande = 'reponse_recue') r, COUNT(*) FILTER (WHERE commande = 'reponse_traitee') t")->first());

        return max(0, (int) $c->r - (int) $c->t);
    }

    public function derniereValidation(string $ws): ?DateTimeImmutable
    {
        $v = WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')
            ->where('workspace_id', $ws)->where('commande', 'tableau_valide')->max('cree_le'));

        return $v === null ? null : new DateTimeImmutable((string) $v);
    }

    public function seuil(string $ws, string $nom): ?float
    {
        $v = WorkspaceContext::run($ws, fn () => DB::table('envoi_seuils')->where('workspace_id', $ws)->where('nom', $nom)->value('valeur'));

        return $v === null ? null : (float) $v;
    }

    public function fixerSeuil(string $ws, string $nom, float $valeur, string $auteur): void
    {
        if (! in_array($nom, Seuils::NOMS, true)) {
            throw new \InvalidArgumentException('seuil inconnu');
        }
        WorkspaceContext::run($ws, fn () => DB::statement(
            'INSERT INTO envoi_seuils (workspace_id, nom, valeur, auteur) VALUES (?, ?, ?, ?)
             ON CONFLICT (workspace_id, nom) DO UPDATE SET valeur = EXCLUDED.valeur, auteur = EXCLUDED.auteur, mis_a_jour_le = now()',
            [$ws, $nom, $valeur, $auteur],
        ));
    }
}
