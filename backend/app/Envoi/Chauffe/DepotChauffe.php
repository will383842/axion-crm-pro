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

    /** Un retour ne dit pas de quelle boîte il vient : il s'écrit sous cette boîte fictive (les freins comptent par espace et fournisseur). */
    public const BOITE_RETOURS = '(retours)';

    /**
     * Jours (Paris) où la boîte a réellement envoyé, STRICTEMENT avant $jour : la matière des jours EFFECTIFS.
     *
     * @return list<DateTimeImmutable>
     */
    public function joursAvecEnvoi(string $ws, string $boite, DateTimeImmutable $jour): array
    {
        $jours = WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')
            ->where('workspace_id', $ws)->where('boite', $boite)->where('jour', '<', $jour->format('Y-m-d'))
            ->groupBy('jour')->havingRaw('SUM(envoyes) > 0')->orderBy('jour')->pluck('jour'));

        return $jours->map(fn ($j): DateTimeImmutable => new DateTimeImmutable((string) $j, new \DateTimeZone(JourEnvoi::FUSEAU)))->all();
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
     * Totaux des 7 jours glissants se terminant à $jour (inclus).
     *
     * @return array{envoyes: int, rebonds_durs: int, plaintes: int, reponses: int}
     */
    public function totaux7Jours(string $ws, DateTimeImmutable $jour): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')->where('workspace_id', $ws)
            ->whereBetween('jour', [$this->debutFenetre($jour)->format('Y-m-d'), $jour->format('Y-m-d')])
            ->selectRaw('COALESCE(SUM(envoyes),0) e, COALESCE(SUM(rebonds_durs),0) r, COALESCE(SUM(plaintes),0) p, COALESCE(SUM(reponses),0) a')->first());

        return ['envoyes' => (int) $l->e, 'rebonds_durs' => (int) $l->r, 'plaintes' => (int) $l->p, 'reponses' => (int) $l->a];
    }

    /** Plaintes des 7 jours glissants, en ne comptant que les jours APRÈS $apres (plaintes déjà « consommées » par un arrêt). */
    public function plaintes7Jours(string $ws, DateTimeImmutable $jour, ?DateTimeImmutable $apres = null): int
    {
        return (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')->where('workspace_id', $ws)
            ->whereBetween('jour', [$this->debutFenetre($jour)->format('Y-m-d'), $jour->format('Y-m-d')])
            ->when($apres !== null, fn ($q) => $q->where('jour', '>', $apres->format('Y-m-d')))
            ->sum('plaintes'));
    }

    /** Dernier jour (7 jours glissants) où une plainte est arrivée, ou null. */
    public function dernierJourPlainte(string $ws, DateTimeImmutable $jour): ?DateTimeImmutable
    {
        $j = WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')->where('workspace_id', $ws)->where('plaintes', '>', 0)
            ->whereBetween('jour', [$this->debutFenetre($jour)->format('Y-m-d'), $jour->format('Y-m-d')])->max('jour'));

        return $j === null ? null : new DateTimeImmutable((string) $j, new \DateTimeZone(JourEnvoi::FUSEAU));
    }

    private function debutFenetre(DateTimeImmutable $jour): DateTimeImmutable
    {
        return $jour->modify('-' . (Seuils::FENETRE_JOURS - 1) . ' days');
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

    /**
     * Pauses actives à $maintenant : non levées ET non expirées.
     *
     * @return list<object{id: int, portee: string, fournisseur: ?string, motif: string}>
     */
    public function pausesActives(string $ws, DateTimeImmutable $maintenant): array
    {
        return WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')
            ->where('workspace_id', $ws)->whereNull('levee_le')
            ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', $maintenant->format('Y-m-d H:i:sP')))
            ->orderBy('id')->get(['id', 'portee', 'fournisseur', 'motif'])->all());
    }

    /**
     * @param  ?int  $dureeHeures  durée de la pause ; null = jusqu'à levée humaine
     * @return bool vrai si une pause a été ouverte (pas de doublon actif identique)
     */
    public function ouvrirPause(string $ws, string $motif, ?string $fournisseur, DateTimeImmutable $maintenant, ?int $dureeHeures = null): bool
    {
        return WorkspaceContext::run($ws, fn (): bool => DB::transaction(function () use ($ws, $motif, $fournisseur, $maintenant, $dureeHeures): bool {
            $instant = $maintenant->format('Y-m-d H:i:sP');
            // Une pause expirée du même motif occupe encore l'index unique : on la date « levée » (la ligne reste).
            DB::table('envoi_pauses')->where('workspace_id', $ws)->whereNull('levee_le')
                ->where('motif', $motif)->whereNotNull('expire_le')->where('expire_le', '<=', $instant)
                ->when($fournisseur === null, fn ($q) => $q->whereNull('fournisseur'), fn ($q) => $q->where('fournisseur', $fournisseur))
                ->update(['levee_le' => $instant, 'levee_par' => 'expiration']);
            $insere = DB::affectingStatement(
                "INSERT INTO envoi_pauses (workspace_id, portee, fournisseur, motif, declenchee_le, expire_le)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (workspace_id, motif, COALESCE(fournisseur, '')) WHERE levee_le IS NULL DO NOTHING",
                [
                    $ws, $fournisseur === null ? 'global' : 'fournisseur', $fournisseur, $motif, $instant,
                    $dureeHeures === null ? null : $maintenant->modify("+{$dureeHeures} hours")->format('Y-m-d H:i:sP'),
                ],
            );

            return $insere > 0;
        }));
    }

    /** Jour (Paris) de la dernière ouverture d'une pause de ce motif, ou null. */
    public function derniereOuverture(string $ws, string $motif): ?DateTimeImmutable
    {
        $d = WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')->where('workspace_id', $ws)->where('motif', $motif)->max('declenchee_le'));

        return $d === null ? null : JourEnvoi::de(new DateTimeImmutable((string) $d));
    }

    /** Lever = dater la levée (la ligne reste). Réservé à la commande humaine, qui l'enveloppe avec son journal. */
    public function leverPause(string $ws, int $id, string $par): bool
    {
        return WorkspaceContext::run($ws, fn (): bool => DB::table('envoi_pauses')
            ->where('workspace_id', $ws)->where('id', $id)->whereNull('levee_le')
            ->update(['levee_le' => now(), 'levee_par' => $par]) > 0);
    }

    /** Cette commande a-t-elle déjà été journalisée ce jour-là (dédoublonnage des alertes) ? */
    public function dejaJournaliseLe(string $ws, string $commande, DateTimeImmutable $jour): bool
    {
        return WorkspaceContext::run($ws, fn (): bool => DB::table('envoi_journal_commandes')
            ->where('workspace_id', $ws)->where('commande', $commande)->where('jour', $jour->format('Y-m-d'))->exists());
    }

    /** Homme mort INF-025 : dernier appel à sante() (global, sans donnée de personne). */
    public function noterBattement(DateTimeImmutable $maintenant): void
    {
        DB::statement(
            'INSERT INTO envoi_sante_battement (id, dernier_le) VALUES (1, ?)
             ON CONFLICT (id) DO UPDATE SET dernier_le = EXCLUDED.dernier_le',
            [$maintenant->format('Y-m-d H:i:sP')],
        );
    }

    public function dernierBattement(): ?DateTimeImmutable
    {
        $d = DB::table('envoi_sante_battement')->where('id', 1)->value('dernier_le');

        return $d === null ? null : new DateTimeImmutable((string) $d);
    }

    /**
     * Applique UN événement de retour, une seule fois (clé = identifiant de l'événement, dans le journal) :
     * vrai si le compteur a été incrémenté, faux si l'événement était déjà compté.
     */
    public function compterRetour(string $ws, string $idEvenement, string $colonne, DateTimeImmutable $jour, string $fournisseur): bool
    {
        return WorkspaceContext::run($ws, fn (): bool => DB::transaction(function () use ($ws, $idEvenement, $colonne, $jour, $fournisseur): bool {
            $nouveau = DB::affectingStatement(
                "INSERT INTO envoi_journal_commandes (workspace_id, commande, auteur, details, jour, cle_idempotence)
                 VALUES (?, 'retour_applique', 'retours', ?, ?, ?)
                 ON CONFLICT (workspace_id, cle_idempotence) WHERE commande = 'retour_applique' AND cle_idempotence IS NOT NULL DO NOTHING",
                [$ws, json_encode(['colonne' => $colonne], JSON_THROW_ON_ERROR), $jour->format('Y-m-d'), $idEvenement],
            );
            if ($nouveau > 0) {
                $this->incrementer($ws, self::BOITE_RETOURS, $jour, $fournisseur, $colonne);
            }

            return $nouveau > 0;
        }));
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
