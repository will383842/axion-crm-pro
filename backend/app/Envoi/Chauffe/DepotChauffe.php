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

        return array_values($jours->map(fn ($j): DateTimeImmutable => new DateTimeImmutable((string) $j, new \DateTimeZone(JourEnvoi::FUSEAU)))->all());
    }

    /**
     * Messages envoyés par jour (Paris), STRICTEMENT avant $jour, avec au moins un envoi : la matière de
     * {@see CourbeChauffe::joursEffectifs()}.
     *
     * @return array<string, int>
     */
    public function volumesParJour(string $ws, string $boite, DateTimeImmutable $jour): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')
            ->where('workspace_id', $ws)->where('boite', $boite)->where('jour', '<', $jour->format('Y-m-d'))
            ->groupBy('jour')->havingRaw('SUM(envoyes) > 0')->orderBy('jour')
            ->selectRaw('jour, SUM(envoyes) AS n')->get());
        $v = [];
        foreach ($l as $r) {
            $v[(string) $r->jour] = (int) $r->n;
        }

        return $v;
    }

    /**
     * Identifiant de la boîte d'envoi (`envoi_boites`) de cette adresse expéditeur dans cet espace, ou null
     * (expéditeur inconnu : `a+1@dom`, « Nom <a@dom> »… ne sont PAS des boîtes). Comparaison exacte, casse ignorée.
     */
    public function boiteId(string $ws, string $expediteur): ?string
    {
        $id = WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')
            ->where('workspace_id', $ws)->whereRaw('lower(adresse) = ?', [GardeChauffe::boite($expediteur)])
            ->orderBy('cree_le')->orderBy('id')->value('id'));

        return is_string($id) ? $id : null;
    }

    /** Messages envoyés ce jour-là par TOUTES les boîtes de l'espace qui partagent ce domaine d'envoi. */
    public function envoyesDomaine(string $ws, string $domaine, DateTimeImmutable $jour): int
    {
        return (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours as c')
            ->join('envoi_boites as b', fn ($j) => $j->on('b.workspace_id', '=', 'c.workspace_id')->whereRaw('b.id::TEXT = c.boite'))
            ->where('c.workspace_id', $ws)->where('c.jour', $jour->format('Y-m-d'))
            ->whereRaw("split_part(lower(b.adresse), '@', 2) = ?", [$domaine])->sum('c.envoyes'));
    }

    public function envoyesBoite(string $ws, string $boite, DateTimeImmutable $jour): int
    {
        return (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')
            ->where('workspace_id', $ws)->where('boite', $boite)
            ->where('jour', $jour->format('Y-m-d'))->sum('envoyes'));
    }

    public function incrementer(string $ws, string $boite, DateTimeImmutable $jour, string $fournisseur, string $colonne, int $n = 1): void
    {
        if ($fournisseur === Fournisseur::INCONNU) {
            $fournisseur = Fournisseur::AUTRE; // résolution impossible : compté sous « autre » (le blocage, lui, est fait par la garde)
        }
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

        return self::totauxDe($l);
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

        return self::totauxDe($l);
    }

    /**
     * Plaintes des 7 jours glissants, sans celles qu'un arrêt de 48 h a déjà « consommées » : les jours APRÈS
     * $apres, plus les plaintes arrivées le jour même de l'arrêt APRÈS son ouverture (le journal de l'arrêt
     * garde le nombre de plaintes du jour à l'instant de l'ouverture ; sans cette trace, tout le jour est consommé).
     */
    public function plaintes7Jours(string $ws, DateTimeImmutable $jour, ?DateTimeImmutable $apres = null): int
    {
        $debut = $this->debutFenetre($jour)->format('Y-m-d');
        $fin = $jour->format('Y-m-d');
        $somme = fn (?string $apresJour, ?string $egal = null): int => (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')
            ->where('workspace_id', $ws)->whereBetween('jour', [$debut, $fin])
            ->when($apresJour !== null, fn ($q) => $q->where('jour', '>', $apresJour))
            ->when($egal !== null, fn ($q) => $q->where('jour', $egal))
            ->sum('plaintes'));
        if ($apres === null) {
            return $somme(null);
        }
        $jourArret = $apres->format('Y-m-d');
        $consommees = $this->plaintesDuJourALOuvertureDeLArret($ws, $jourArret);
        $memeJour = $consommees === null ? 0 : max(0, $somme(null, $jourArret) - $consommees);

        return $somme($jourArret) + $memeJour;
    }

    /** Plaintes du jour de l'arrêt, telles qu'elles étaient à son ouverture (trace du journal), ou null si inconnu. */
    private function plaintesDuJourALOuvertureDeLArret(string $ws, string $jourArret): ?int
    {
        $d = WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')
            ->where('workspace_id', $ws)->where('commande', 'pause_auto')->where('jour', $jourArret)
            ->whereRaw("details::jsonb->>'motif' = ?", [DecisionFrein::PLAINTES_ARRET])
            ->whereRaw("jsonb_exists(details::jsonb, 'plaintes_jour')")
            ->orderByDesc('id')->value('details'));
        if (! is_string($d)) {
            return null;
        }
        $n = json_decode($d, true)['plaintes_jour'] ?? null;

        return is_int($n) ? $n : null;
    }

    /**
     * Dernière pause de ce frein LEVÉE À LA MAIN (hors expiration) : {par, le}, ou null. Sert au message
     * « ce frein se rouvre alors qu'il a été levé à la main ».
     *
     * @return array{par: string, le: string}|null
     */
    public function derniereLeveeManuelle(string $ws, string $motif, ?string $fournisseur): ?array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')
            ->where('workspace_id', $ws)->where('motif', $motif)->whereNotNull('levee_le')
            ->where(fn ($q) => $q->whereNull('levee_par')->orWhere('levee_par', '<>', 'expiration'))
            ->when($fournisseur === null, fn ($q) => $q->whereNull('fournisseur'), fn ($q) => $q->where('fournisseur', $fournisseur))
            ->orderByDesc('levee_le')->first(['levee_par', 'levee_le']));

        return $l === null ? null : ['par' => (string) ($l->levee_par ?? 'inconnu'), 'le' => (string) $l->levee_le];
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

        return array_values($lignes->map(fn ($l): array => ['envoyes' => (int) $l->e, 'reponses' => (int) $l->a])->all());
    }

    /**
     * Pauses actives à $maintenant : non levées ET non expirées.
     *
     * @return list<\stdClass> lignes {id, portee, fournisseur, motif}
     */
    public function pausesActives(string $ws, DateTimeImmutable $maintenant): array
    {
        return array_values(WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')
            ->where('workspace_id', $ws)->whereNull('levee_le')
            ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', $maintenant->format('Y-m-d H:i:sP')))
            ->orderBy('id')->get(['id', 'portee', 'fournisseur', 'motif'])->all()));
    }

    /**
     * @param  ?int  $dureeHeures  durée de la pause ; null = jusqu'à levée humaine
     * @return bool vrai si une pause a été ouverte (pas de doublon actif identique)
     */
    public function ouvrirPause(string $ws, string $motif, ?string $fournisseur, DateTimeImmutable $maintenant, ?int $dureeHeures = null): bool
    {
        return WorkspaceContext::run($ws, fn (): bool => DB::transaction(function () use ($ws, $motif, $fournisseur, $maintenant, $dureeHeures): bool {
            $instant = $maintenant->format('Y-m-d H:i:sP');
            DB::select("SELECT set_config('app.maintenant', ?, true)", [$instant]); // horloge de référence des triggers de L7
            // Une pause expirée du même motif occupe encore l'index unique : on la date « levée » (la ligne reste).
            DB::table('envoi_pauses')->where('workspace_id', $ws)->whereNull('levee_le')
                ->where('motif', $motif)->whereNotNull('expire_le')->where('expire_le', '<=', $instant)
                ->when($fournisseur === null, fn ($q) => $q->whereNull('fournisseur'), fn ($q) => $q->where('fournisseur', $fournisseur))
                ->update(['levee_le' => DB::raw('expire_le'), 'levee_par' => 'expiration']); // levée = l'instant exact d'expiration
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
        $instant = $maintenant->format('Y-m-d H:i:sP');
        // Le trigger borne le battement à l'horloge de référence (jamais dans le futur) et le rend monotone.
        DB::transaction(function () use ($instant): void {
            DB::select("SELECT set_config('app.maintenant', ?, true)", [$instant]);
            DB::statement(
                'INSERT INTO envoi_sante_battement (id, dernier_le) VALUES (1, ?)
                 ON CONFLICT (id) DO UPDATE SET dernier_le = EXCLUDED.dernier_le',
                [$instant],
            );
        });
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

        return max(0, (int) ($c->r ?? 0) - (int) ($c->t ?? 0));
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

    /** @return array{envoyes: int, rebonds_durs: int, plaintes: int, reponses: int} */
    private static function totauxDe(?object $l): array
    {
        return [
            'envoyes' => (int) ($l->e ?? 0),
            'rebonds_durs' => (int) ($l->r ?? 0),
            'plaintes' => (int) ($l->p ?? 0),
            'reponses' => (int) ($l->a ?? 0),
        ];
    }
}
