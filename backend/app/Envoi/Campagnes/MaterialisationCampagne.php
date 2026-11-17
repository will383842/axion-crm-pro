<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Depot\DepotSocle;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sequence\FenetreHoraire;
use App\Envoi\Sequence\JoursOuvres;
use App\Envoi\Verdicts\EcrivainVerdicts;
use App\Envoi\Verdicts\PreparationVerdicts;
use App\Envoi\Verdicts\VerdictAdresse;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Lot X1-L4-B : matérialise une campagne « programmee » : construit sa séquence (L3) si besoin, lit sa cible par lots à curseur (L2) et
 * crée les inscriptions, étalées sur les jours ouvrés (L4-A). AUCUN envoi, aucun passage à « en_cours », aucune ligne `envoi_messages` : la séquence
 * naît en pause et les inscriptions sont inertes. Les réglages que le transport n'applique pas encore (boîtes, plafond saisi) sont refusés SANS rien écrire.
 * Rejouable : le curseur de la ligne de matérialisation est la clé de reprise ; chaque lot s'écrit dans UNE transaction, sous les verrous de
 * l'espace, de la campagne puis de l'étalement (toujours dans cet ordre : pas d'interblocage).
 */
final class MaterialisationCampagne
{
    public const TAILLE_LOT = 200;

    public const LOTS_PAR_APPEL = 5;

    public const ESSAIS_MAX = 3;

    /** Réservé aux tests : niveau de transaction englobante toléré (celui de RefreshDatabase). Ignoré hors APP_ENV=testing. */
    public static int $niveauTolere = 0;

    public function __construct(
        private readonly DepotCampagnes $depot,
        private readonly SelectionDestinataires $selection,
        private readonly ConstructionSequence $construction,
        private readonly DepotSocle $socle,
        private readonly int $tailleLot = self::TAILLE_LOT,
        private readonly int $lotsParAppel = self::LOTS_PAR_APPEL,
        private readonly ?PreparationVerdicts $verdicts = null,
    ) {}

    /** Hors de toute transaction (la sélection ouvre sa propre transaction REPEATABLE READ, READ ONLY). */
    public function materialiser(string $ws, string $campagneId, DateTimeImmutable $maintenant): ResultatMaterialisation
    {
        if (DB::transactionLevel() > (app()->environment('testing') ? self::$niveauTolere : 0)) {
            throw new \LogicException('materialiser ne s\'appelle pas dans une transaction englobante.');
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $campagneId) !== 1) {
            throw new MaterialisationRefusee('campagne_introuvable', false, 'Campagne introuvable dans cet espace.');
        }
        try {
            return $this->derouler($ws, strtolower($campagneId), $maintenant);
        } catch (QueryException $e) {
            throw self::convertir($e);
        }
    }

    private function derouler(string $ws, string $id, DateTimeImmutable $maintenant): ResultatMaterialisation
    {
        $c = $this->depot->lire($ws, $id) ?? throw new MaterialisationRefusee('campagne_introuvable', false, 'Campagne introuvable dans cet espace.');
        if (! in_array($c->statut, ['programmee', 'en_cours'], true)) {
            throw new MaterialisationRefusee('statut_non_materialisable', false, "La campagne n'est pas matérialisable (statut {$c->statut}).");
        }
        $ligne = $this->ligne($ws, $id);
        if ($ligne !== null && $ligne->etat !== 'en_cours') {
            return $this->resultat($ligne, 0, $ligne->motif);
        }
        $contenu = json_decode((string) $c->saisie, true)['contenu'] ?? [];
        if (trim((string) $c->boites, '{}') !== '' || (is_array($contenu) && ($contenu['plafond_jour'] ?? null) !== null)) {
            throw new MaterialisationRefusee('reglage_non_applique', false, 'Boîtes ou plafond saisis : le transport ne les applique pas encore, la campagne reste programmée.');
        }

        $sequenceId = $c->sequence_id;
        if ($sequenceId === null) {
            try {
                $sequenceId = $this->construction->construire($ws, $id)->sequenceId;
            } catch (ConstructionRefusee $e) {
                if ($e->motifMaterialisation() !== null) {
                    return $this->bloquer($ws, $id, null, $e->motifMaterialisation());
                }
                throw $e->reessayable ? new MaterialisationRefusee('construction_a_reessayer', true, $e->getMessage(), $e) : $e;
            }
        }
        $sequenceId = (string) $sequenceId;
        if ($ligne === null) {
            $this->bloquerOuCreer($ws, $id, $sequenceId, null);
        }

        $debutLe = null;
        if (is_array($contenu) && is_string($contenu['debut_le'] ?? null)) {
            try {
                $debutLe = new DateTimeImmutable($contenu['debut_le']);
            } catch (\Exception) {
                $debutLe = null;
            }
        }
        $depart = $debutLe !== null && $debutLe > $maintenant ? $debutLe : $maintenant;
        [$fenetre, $delai] = $this->plan($ws, $sequenceId);
        $auPlusTot = JoursOuvres::ajouter($depart, $delai);

        $inscrits = 0;
        $ecrit = false;
        $motif = null;
        for ($tour = 0; $tour < $this->lotsParAppel; $tour++) {
            $ligne = $this->ligne($ws, $id) ?? throw new MaterialisationRefusee('concurrence', true, 'La matérialisation a disparu.');
            if ($ligne->etat !== 'en_cours') {
                break;
            }
            $curseurLu = (int) $ligne->curseur;
            $p = min((int) config('envoi.plafond_jour'), $this->socle->plafondEspace($ws), PasserelleLocale::PLAFOND_MAX);
            if ($p <= 0) {
                throw new MaterialisationRefusee('plafond_nul', true, 'Le plafond par jour est nul : rien n\'est planifié.');
            }
            $capacite = EtalementEcheances::capacite($fenetre, $maintenant, $auPlusTot, $p, $this->dejaParJour($ws, $maintenant, $auPlusTot));
            if ($capacite === 0) {
                if (! $ecrit) {
                    throw new MaterialisationRefusee('horizon_plein', true, 'Aucune place sur l\'horizon des jours ouvrés : réessayez plus tard.');
                }
                $motif = 'horizon_plein';
                break;
            }
            try {
                $lot = $this->selection->lot($ws, $id, 'entreprises', $curseurLu, min($this->tailleLot, $capacite));
            } catch (SelectionRefusee $e) {
                if ($e->motif === 'cible_introuvable') {
                    return $this->bloquer($ws, $id, $sequenceId, 'cible_introuvable');
                }
                throw in_array($e->motif, ['cible_non_supportee', 'population_non_ouverte', 'filtre_origine_inactif'], true)
                    ? new MaterialisationRefusee($e->motif, false, $e->getMessage(), $e)
                    : new MaterialisationRefusee('lecture_impossible', true, $e->getMessage(), $e);
            }
            $lot = $this->trierParVerdict($ws, $lot, $maintenant);
            $inscrits += self::relancer(fn (): int => $this->ecrire($ws, $id, $sequenceId, $curseurLu, $lot, $fenetre, $maintenant, $auPlusTot, $p));
            $ecrit = true;
        }

        return $this->resultat($this->ligne($ws, $id), $inscrits, $motif);
    }

    /**
     * Vérifie (et écrit) les verdicts du lot AVANT l'écriture : hors de toute transaction et de tout verrou, car le DNS ne doit jamais en tenir un.
     * `invalide` et `risque` sont écartés et comptés ; `valide` récent et `null` (indéterminé : la porte reste seule juge, le séquenceur reporte) sont inscrits.
     * `retenus` est diminué d'autant : `membres - retenus` reste le total des exclus de préparation.
     */
    private function trierParVerdict(string $ws, LotDestinataires $lot, DateTimeImmutable $maintenant): LotDestinataires
    {
        if ($lot->destinataires === []) {
            return $lot;
        }
        if (! EcrivainVerdicts::schemaPret()) {
            throw new MaterialisationRefusee('verdicts_indisponibles', true, 'Verdicts d\'adresse indisponibles : rien n\'est inscrit, réessayez.');
        }
        try {
            $ids = array_map(static fn (Destinataire $d): int => $d->contactId, $lot->destinataires);
            $trouves = WorkspaceContext::run($ws, fn (): array => ($this->verdicts ?? app(PreparationVerdicts::class))->pourContacts($ws, $ids, $maintenant));
        } catch (\Throwable $e) {
            throw new MaterialisationRefusee('verdicts_indisponibles', true, 'Vérification des adresses impossible : rien n\'est inscrit, réessayez.', $e);
        }
        $gardes = [];
        $invalides = 0;
        $risquees = 0;
        foreach ($lot->destinataires as $d) {
            $v = $trouves[$d->contactId] ?? null;
            if ($v?->verdict === VerdictAdresse::INVALIDE) {
                $invalides++;
            } elseif ($v?->verdict === VerdictAdresse::RISQUE) {
                $risquees++;
            } else {
                $gardes[] = $d;
            }
        }
        if ($invalides === 0 && $risquees === 0) {
            return $lot;
        }
        $compteurs = $lot->compteurs;
        $compteurs['retenus'] = (int) ($compteurs['retenus'] ?? 0) - $invalides - $risquees;
        $compteurs['adresse_invalide'] = $invalides;
        $compteurs['adresse_risquee'] = $risquees;

        return new LotDestinataires($gardes, $compteurs, $lot->curseurSuivant);
    }

    /** Corps d'écriture d'UN lot : une transaction, sous les verrous de l'espace, de la campagne puis de l'étalement. Rend le nombre d'inscriptions créées. */
    private function ecrire(string $ws, string $id, string $sequenceId, int $curseurLu, LotDestinataires $lot, FenetreHoraire $fenetre, DateTimeImmutable $maintenant, DateTimeImmutable $auPlusTot, int $p): int
    {
        return $this->depot->sousVerrou($ws, $id, function () use ($ws, $id, $sequenceId, $curseurLu, $lot, $fenetre, $maintenant, $auPlusTot, $p): int {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '15000'");
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_etalement:' . $ws]);
            $c = DB::selectOne('SELECT statut, sequence_id FROM envoi_campagnes WHERE workspace_id = ? AND id = ? FOR SHARE', [$ws, $id]);
            if ($c === null || ! in_array($c->statut, ['programmee', 'en_cours'], true)) {
                throw new MaterialisationRefusee('statut_non_materialisable', false, 'La campagne n\'est plus matérialisable.');
            }
            if ((string) $c->sequence_id !== $sequenceId) {
                throw new MaterialisationRefusee('concurrence', true, 'La séquence de la campagne a changé.');
            }
            $m = DB::selectOne('SELECT etat, curseur, compteurs FROM envoi_campagnes_materialisations WHERE workspace_id = ? AND campagne_id = ? FOR UPDATE', [$ws, $id]);
            if ($m === null || $m->etat !== 'en_cours' || (int) $m->curseur !== $curseurLu) {
                throw new MaterialisationRefusee('concurrence', true, 'Un autre appel a avancé la matérialisation.');
            }

            $cles = [];
            foreach ($lot->destinataires as $d) {
                $cles[$id . '|' . $d->contactId] = $d;
            }
            $echeances = EtalementEcheances::repartir($fenetre, $maintenant, $auPlusTot, $p, $this->dejaParJour($ws, $maintenant, $auPlusTot), array_keys($cles), $id);
            if (count($echeances) !== count($cles)) {
                throw new MaterialisationRefusee('concurrence', true, 'La place a été prise sur l\'horizon : réessayez.');
            }
            $inseres = 0;
            if ($cles !== []) {
                $lignes = [];
                foreach ($cles as $cle => $d) {
                    $lignes[] = ['contact_id' => $d->contactId, 'entreprise_id' => $d->entrepriseId, 'empreinte' => $d->empreinte, 'echeance' => $echeances[$cle]->format('c')];
                }
                $inseres = count(DB::select(
                    'INSERT INTO envoi_inscriptions (workspace_id, sequence_id, population, contact_id, entreprise_id, empreinte_adresse, prochaine_echeance, version_figee)
                     SELECT ?, ?, \'entreprises\', x.contact_id, x.entreprise_id, x.empreinte, x.echeance, ?::jsonb
                       FROM jsonb_to_recordset(?::jsonb) AS x(contact_id bigint, entreprise_id bigint, empreinte char(64), echeance timestamptz)
                     ON CONFLICT DO NOTHING RETURNING contact_id',
                    [$ws, $sequenceId, $this->versionFigee($ws, $sequenceId), json_encode($lignes, JSON_THROW_ON_ERROR)],
                ));
            }

            $compteurs = json_decode((string) $m->compteurs, true) ?: [];
            foreach ($lot->compteurs + ['inscrits' => $inseres, 'ecartes_insertion' => count($cles) - $inseres] as $k => $v) {
                $compteurs[$k] = (int) ($compteurs[$k] ?? 0) + (int) $v;
            }
            $fin = $lot->curseurSuivant === null;
            $n = DB::update(
                'UPDATE envoi_campagnes_materialisations SET sequence_id = COALESCE(sequence_id, ?), curseur = ?, compteurs = ?::jsonb, maj_le = now()'
                . ($fin ? ", etat = 'terminee', terminee_le = now()" : '')
                . " WHERE workspace_id = ? AND campagne_id = ? AND curseur = ? AND etat = 'en_cours'",
                [$sequenceId, $lot->curseurSuivant ?? $curseurLu, json_encode($compteurs, JSON_THROW_ON_ERROR), $ws, $id, $curseurLu],
            );
            if ($n !== 1) {
                throw new MaterialisationRefusee('concurrence', true, 'Un autre appel a avancé la matérialisation.');
            }

            return $inseres;
        });
    }

    /** Version figée au format EXACT de Inscriptions::inscrire (même séquence, mêmes étapes). */
    private function versionFigee(string $ws, string $sequenceId): string
    {
        $seq = DB::selectOne('SELECT fenetre_debut, fenetre_fin FROM envoi_sequences WHERE workspace_id = ? AND id = ?', [$ws, $sequenceId]);
        $etapes = DB::select('SELECT rang, delai_jours_ouvres, sujet, corps FROM envoi_sequence_etapes WHERE workspace_id = ? AND sequence_id = ? ORDER BY rang', [$ws, $sequenceId]);

        return json_encode([
            'fenetre' => ['debut' => (int) $seq->fenetre_debut, 'fin' => (int) $seq->fenetre_fin],
            'etapes' => array_map(static fn ($e): array => [
                'rang' => (int) $e->rang, 'delai_jours_ouvres' => (int) $e->delai_jours_ouvres,
                'sujet' => (string) $e->sujet, 'corps' => (string) $e->corps,
            ], $etapes),
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{FenetreHoraire, int} fenêtre de la séquence et délai (jours ouvrés) de la première étape */
    private function plan(string $ws, string $sequenceId): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $sequenceId): array {
            $s = DB::selectOne('SELECT fenetre_debut, fenetre_fin FROM envoi_sequences WHERE workspace_id = ? AND id = ?', [$ws, $sequenceId])
                ?? throw new MaterialisationRefusee('concurrence', true, 'Séquence introuvable.');
            $e = DB::selectOne('SELECT delai_jours_ouvres FROM envoi_sequence_etapes WHERE workspace_id = ? AND sequence_id = ? ORDER BY rang LIMIT 1', [$ws, $sequenceId]);

            return [new FenetreHoraire((int) $s->fenetre_debut, (int) $s->fenetre_fin), (int) ($e->delai_jours_ouvres ?? 0)];
        });
    }

    /**
     * Inscriptions actives déjà prévues par jour (Paris), toutes séquences, toutes étapes (prudent).
     *
     * @return array<string, int>
     */
    private function dejaParJour(string $ws, DateTimeImmutable $maintenant, DateTimeImmutable $auPlusTot): array
    {
        $debut = max($maintenant, $auPlusTot)->setTimezone(new DateTimeZone(JoursOuvres::FUSEAU))->setTime(0, 0);
        $par = [];
        $lignes = WorkspaceContext::run($ws, fn (): array => DB::select(
            "SELECT to_char(prochaine_echeance AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD') AS jour, count(*) AS n
               FROM envoi_inscriptions WHERE workspace_id = ? AND statut = 'active' AND prochaine_echeance >= ? AND prochaine_echeance < ? GROUP BY 1",
            [$ws, $debut->format('c'), $debut->modify('+60 days')->format('c')],
        ));
        foreach ($lignes as $l) {
            $par[(string) $l->jour] = (int) $l->n;
        }

        return $par;
    }

    private function ligne(string $ws, string $id): ?\stdClass
    {
        return WorkspaceContext::run($ws, fn () => DB::selectOne('SELECT etat, sequence_id, curseur, compteurs, motif FROM envoi_campagnes_materialisations WHERE workspace_id = ? AND campagne_id = ?', [$ws, $id]));
    }

    private function resultat(?\stdClass $ligne, int $inscrits, ?string $motif): ResultatMaterialisation
    {
        $ligne ?? throw new MaterialisationRefusee('concurrence', true, 'La matérialisation a disparu.');

        return new ResultatMaterialisation((string) $ligne->etat, $inscrits, (int) $ligne->curseur, json_decode((string) $ligne->compteurs, true) ?: [], $motif ?? ($ligne->motif === null ? null : (string) $ligne->motif));
    }

    /** Naissance de la ligne (idempotente) ; avec $motif, elle passe aussitôt à « bloquee » (état terminal). */
    private function bloquerOuCreer(string $ws, string $id, ?string $sequenceId, ?string $motif): void
    {
        self::relancer(fn () => $this->depot->sousVerrou($ws, $id, function () use ($ws, $id, $sequenceId, $motif): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::insert('INSERT INTO envoi_campagnes_materialisations (campagne_id, workspace_id, sequence_id) VALUES (?, ?, ?) ON CONFLICT (campagne_id) DO NOTHING', [$id, $ws, $sequenceId]);
            if ($motif !== null) {
                DB::update("UPDATE envoi_campagnes_materialisations SET etat = 'bloquee', motif = ?, maj_le = now() WHERE workspace_id = ? AND campagne_id = ? AND etat = 'en_cours'", [$motif, $ws, $id]);
            }
        }));
    }

    private function bloquer(string $ws, string $id, ?string $sequenceId, string $motif): ResultatMaterialisation
    {
        $this->bloquerOuCreer($ws, $id, $sequenceId, $motif);

        return $this->resultat($this->ligne($ws, $id), 0, null);
    }

    /**
     * Rejoue $f sur interblocage (40P01) ou échec de sérialisation (40001), HORS transaction englobante seulement.
     * Toute erreur de base devient une MaterialisationRefusee : jamais de QueryException brute.
     *
     * @template T
     *
     * @param  \Closure(): T  $f
     * @return T
     */
    public static function relancer(\Closure $f, int $essais = self::ESSAIS_MAX): mixed
    {
        $restants = $essais;
        while (true) {
            try {
                return $f();
            } catch (QueryException $e) {
                $restants--;
                if (in_array((string) $e->getCode(), ['40P01', '40001'], true) && DB::transactionLevel() === 0 && $restants > 0) {
                    usleep(random_int(20_000, 120_000));

                    continue;
                }
                throw self::convertir($e);
            }
        }
    }

    private static function convertir(QueryException $e): MaterialisationRefusee
    {
        return in_array((string) $e->getCode(), ['40P01', '40001', '55P03', '57014', '23505'], true)
            ? new MaterialisationRefusee('concurrence', true, 'Matérialisation en concurrence avec une autre écriture : réessayez.', $e)
            : new MaterialisationRefusee('erreur_base', false, 'Erreur de base de données pendant la matérialisation.', $e);
    }
}
