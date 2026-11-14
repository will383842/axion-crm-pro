<?php

namespace App\Envoi\FeuVert;

use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\JourEnvoi;
use App\Envoi\Sante\DepotSante;
use App\Envoi\Temoins\ReleveTemoins;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feu vert : liste CALCULÉE (L8-F04). Lecture seule : elle ne signe jamais, n'écrit jamais `envoi_drapeaux`,
 * ne change aucun réglage. Points automatiques = lus dans le système ; points manuels = jamais automatiques,
 * « fait » seulement si une preuve a été déposée ET que le point n'attend pas le vrai serveur
 * (un point qui l'attend reste non fait et bloquant, sa preuve restant affichée).
 *
 * Catalogue versionné unique CatalogueManuels : `auto.*` (calculés) et `manuel.*` (spécification 26 § 8, 18 points).
 */
class ListeFeuVert
{
    public const PREMIER = CatalogueManuels::PREMIER;

    public function __construct(private readonly DepotPreuves $preuves, private readonly DepotSante $sante) {}

    /** @return list<PointFeuVert> */
    public function points(string $ws): array
    {
        $d = WorkspaceContext::run($ws, fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first());
        $auto = static fn (string $code, bool $fait, string $ok, string $ko): PointFeuVert => self::pointAuto($code, $fait, $fait ? $ok : $ko);

        $dns = $this->sante->controlesDns($ws);
        $listes = $this->sante->controlesListes($ws);
        $dnsOk = $dns !== [] && array_filter($dns, static fn (array $c): bool => ! $c['pret'] || $c['pause']) === [];
        $listesOk = $listes !== [] && array_filter($listes, static fn (array $c): bool => $c['statut'] !== 'non_liste' || $c['pause']) === [];

        $points = [
            $auto('auto.reel_actif_faux', config('envoi.reel_actif') === false, 'ENVOI_REEL_ACTIF est faux.', 'L’envoi réel est actif.'),
            $auto('auto.mode_temoins_vrai', $d !== null && (bool) $d->mode_temoins, 'Le mode témoins est actif.', 'Mode témoins inactif ou drapeaux absents.'),
            $auto('auto.signature_absente', $d !== null && $d->feu_vert_signature === null, 'Aucune signature posée.', $d === null ? 'Drapeaux absents.' : 'Une signature existe déjà.'),
            $this->temoins($ws),
            $this->troisJours($ws),
            $auto('auto.arret_entreprise', ! self::estAbsent(app(ArretParEntreprise::class), ArretParEntrepriseAbsent::class), 'Implémentation réelle liée.', 'Implémentation « absente » liée.'),
            $auto('auto.retrait_file', ! self::estAbsent(app(RetraitEnvoisEnFile::class), RetraitEnvoisEnFileAbsent::class), 'Implémentation réelle liée.', 'Implémentation « absente » liée.'),
            // Mêmes règles que la vraie garde (JetonDesinscription) : jamais une copie.
            $auto('auto.cle_desinscription', JetonDesinscription::cleConfigureeValide(), 'Clé posée, longueur suffisante.', 'Clé absente ou trop courte.'),
            $auto('auto.hotes_desinscription', JetonDesinscription::hotesConfiguresValides(), 'Hôte (app ou api) et deux adresses valides posés.', 'Hôte inconnu, ou adresse app/api absente ou invalide.'),
            $auto('auto.releve_imap', config('envoi.releve_actif', false) === true, 'Interrupteur de relève actif.', 'Interrupteur de relève éteint ou absent (fiche F12).'),
            $auto('auto.domaines_prets', $dnsOk, 'Tous les domaines contrôlés sont prêts.', 'Aucun contrôle, ou un domaine pas prêt / en pause.'),
            $auto('auto.listes_noires', $listesOk, 'Aucune ressource listée.', 'Aucun contrôle, ou une ressource listée / non fiable / en pause.'),
            $auto('auto.supervision_k15', config('envoi.sante.actif') === true, 'Supervision active.', 'Supervision éteinte.'),
        ];

        $preuves = $this->preuves->dernieres($ws);
        foreach (CatalogueManuels::codesManuels() as $code) {
            $p = $preuves[$code] ?? null;
            $depose = $p !== null && $p['fait'];
            $c = CatalogueManuels::POINTS[$code];
            // Aucun signal « K17 fait » n'existe encore dans le code : par prudence, un point qui exige le vrai serveur attend toujours.
            // Règle : `fait` = (`etat` == « fait »). Un point qui attend le vrai serveur n'est PAS fait (donc bloquant),
            // même avec une preuve déposée, qui reste affichée.
            $attend = $c['serveur_reel'];
            $etat = $attend ? PointFeuVert::ETAT_ATTEND_SERVEUR : ($depose ? PointFeuVert::ETAT_FAIT : PointFeuVert::ETAT_A_FAIRE);
            $points[] = new PointFeuVert(
                $code,
                $c['libelle'],
                false,
                $etat === PointFeuVert::ETAT_FAIT,
                self::detailManuel($attend, $depose),
                $c['source'],
                $c['groupe'],
                $etat,
                $depose ? $p['preuve'] : null,
                $depose ? $p['par'] : null,
                $depose ? $p['le'] : null,
            );
        }

        return $points;
    }

    /** « Vrai serveur » seulement si le point attend le serveur réel (dépend de `$attend`). */
    private static function detailManuel(bool $attend, bool $fait): string
    {
        if ($attend) {
            return $fait ? 'Preuve déposée, à revalider avec le vrai serveur.' : 'Vérifiable seulement avec le vrai serveur.';
        }

        return $fait ? 'Preuve déposée.' : 'Preuve humaine à déposer.';
    }

    /**
     * Verrous en LECTURE SEULE (contrat VerrousFeuVert) : aucune écriture, aucune valeur secrète.
     *
     * @return array{envoi_reel_actif: bool, mode_temoins: bool, signature_presente: bool, signature_date: ?string, fournisseurs_temoins_couverts: int, fournisseurs_temoins_requis: int}
     */
    public function verrous(string $ws): array
    {
        $d = WorkspaceContext::run($ws, fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first());
        $requis = DepotSocleDb::FOURNISSEURS_TEMOINS;
        $presents = Schema::hasColumn('envoi_temoins', 'fournisseur')
            ? WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->where('workspace_id', $ws)->distinct()->pluck('fournisseur')->all())
            : [];

        return [
            'envoi_reel_actif' => config('envoi.reel_actif') !== false,
            'mode_temoins' => $d !== null && (bool) $d->mode_temoins,
            'signature_presente' => $d !== null && $d->feu_vert_signature !== null,
            'signature_date' => $d !== null && $d->feu_vert_date !== null ? substr((string) $d->feu_vert_date, 0, 10) : null,
            'fournisseurs_temoins_couverts' => count(array_intersect($requis, array_map('strval', $presents))),
            'fournisseurs_temoins_requis' => count($requis),
        ];
    }

    private static function pointAuto(string $code, bool $fait, string $detail): PointFeuVert
    {
        $c = CatalogueManuels::POINTS[$code];

        return new PointFeuVert($code, $c['libelle'], true, $fait, $detail, $c['source'], $c['groupe'], $fait ? PointFeuVert::ETAT_FAIT : PointFeuVert::ETAT_A_FAIRE);
    }

    /** @param class-string $absent */
    private static function estAbsent(object $liaison, string $absent): bool
    {
        return is_a($liaison, $absent);
    }

    private function temoins(string $ws): PointFeuVert
    {
        $code = 'auto.temoins_fournisseurs';
        if (! Schema::hasColumn('envoi_temoins', 'fournisseur')) {
            return self::pointAuto($code, false, 'Colonne fournisseur absente (fiche F03 pas encore posée).');
        }
        $presents = WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->where('workspace_id', $ws)->distinct()->pluck('fournisseur')->all());
        $manquants = array_values(array_diff(DepotSocleDb::FOURNISSEURS_TEMOINS, array_map('strval', $presents)));

        return self::pointAuto($code, $manquants === [], $manquants === [] ? 'Tous les fournisseurs témoins sont couverts.' : count($manquants) . ' fournisseur(s) sans témoin : ' . implode(', ', $manquants) . '.');
    }

    /**
     * F15 : les 6 fournisseurs verts le même jour (réception inbox, authentification alignée, bouton vu, POST effectif)
     * pendant 3 jours CONSÉCUTIFS se terminant aujourd'hui ou hier. La dernière ligne par jour et fournisseur fait foi, SAUF qu'un constat `releve_auto` « indesirables » rougit la journée
     * pour de bon (aucune saisie postérieure ne la reverdit) et qu'une ligne `releve_auto` ne verdit jamais rien ;
     * un jour rouge, incomplet ou manquant remet le compteur à zéro. Lecture seule.
     */
    private function troisJours(string $ws): PointFeuVert
    {
        $code = 'auto.temoins_3_jours';
        if (! Schema::hasTable('envoi_temoins_journees')) {
            return self::pointAuto($code, false, 'Table des journées témoins absente (fiche F15 pas encore posée).');
        }
        $aujourdhui = JourEnvoi::de();
        $depuis = $aujourdhui->modify('-4 days')->format('Y-m-d');
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')
            ->where('workspace_id', $ws)->where('jour', '>=', $depuis)
            ->orderBy('id')->get(['jour', 'fournisseur', 'reception', 'auth_alignee', 'bouton_vu', 'post_effectif', 'auteur']));
        $derniere = [];
        $rouges = []; // jours où la relève automatique a constaté un message en indésirables : rouges quoi qu'il arrive ensuite
        foreach ($lignes as $l) {
            if ((string) $l->auteur === ReleveTemoins::AUTEUR) {
                // Un constat automatique peut FREINER, jamais libérer : il rougit la journée et ne la verdit jamais.
                if ($l->reception === 'indesirables') {
                    $rouges[(string) $l->jour] = true;
                }

                continue;
            }
            $derniere[(string) $l->jour][(string) $l->fournisseur] = $l; // ordre croissant d'id : la dernière écrase
        }
        $vert = static function (string $jour) use ($derniere, $rouges): bool {
            if (isset($rouges[$jour])) {
                return false;
            }
            foreach (DepotSocleDb::FOURNISSEURS_TEMOINS as $f) {
                $l = $derniere[$jour][$f] ?? null;
                if ($l === null || $l->reception !== 'inbox' || ! $l->auth_alignee || ! $l->bouton_vu || ! $l->post_effectif) {
                    return false;
                }
            }

            return true;
        };
        $jour = $aujourdhui;
        // Aujourd'hui sans aucune ligne : on compte depuis hier. Aujourd'hui avec des lignes : il doit être vert.
        if (! isset($derniere[$jour->format('Y-m-d')]) && ! isset($rouges[$jour->format('Y-m-d')])) {
            $jour = $jour->modify('-1 day');
        }
        $suite = 0;
        while ($suite < 3 && $vert($jour->format('Y-m-d'))) {
            $suite++;
            $jour = $jour->modify('-1 day');
        }

        return self::pointAuto($code, $suite >= 3, $suite >= 3 ? 'Les 6 témoins sont verts depuis 3 jours consécutifs.' : $suite . ' jour(s) vert(s) consécutif(s) sur 3 requis.');
    }
}
