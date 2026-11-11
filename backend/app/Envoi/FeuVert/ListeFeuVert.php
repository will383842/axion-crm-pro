<?php

namespace App\Envoi\FeuVert;

use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\Sante\DepotSante;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feu vert : liste CALCULÉE (L8-F04). Lecture seule : elle ne signe jamais, n'écrit jamais `envoi_drapeaux`,
 * ne change aucun réglage. Points automatiques = lus dans le système ; points manuels = jamais automatiques,
 * « fait » seulement si une preuve a été déposée (et, par défaut, « vérifiable seulement avec le vrai serveur »).
 *
 * Spec § 8 absente du dépôt : codes manuels = liste prudente (18 points + A11), à réconcilier avec la spec.
 */
class ListeFeuVert
{
    /** Les 6 fournisseurs témoins attendus (colonne `envoi_temoins.fournisseur`, fiche F03). */
    public const FOURNISSEURS_TEMOINS = ['gmail', 'outlook', 'yahoo', 'orange', 'free', 'proton'];

    public const PREMIER = 'm8_01_tls_mutuel';

    public const MANUELS = [
        'm8_01_tls_mutuel' => 'TLS mutuel entre l’application et le serveur d’envoi vérifié',
        'm8_02_dkim' => 'Signature DKIM vérifiée sur un message réel de recette',
        'm8_03_spf' => 'SPF aligné vérifié sur un message réel de recette',
        'm8_04_dmarc' => 'DMARC aligné et rapports reçus',
        'm8_05_ptr' => 'DNS inverse (PTR) cohérent avec le nom HELO',
        'm8_06_liste_unsubscribe' => 'En-têtes List-Unsubscribe et One-Click acceptés par un témoin',
        'm8_07_desinscription_bout_en_bout' => 'Désinscription de bout en bout avec le vrai serveur',
        'm8_08_retours_imap' => 'Rebonds et plaintes réellement relevés dans la boîte de retours',
        'm8_09_plafonds' => 'Plafonds journaliers constatés sur le vrai serveur',
        'm8_10_chauffe' => 'Courbe de chauffe validée sur le vrai serveur',
        'm8_11_tout_arreter' => 'Arrêt général éprouvé avec le vrai serveur',
        'm8_12_reprise_serveur' => 'Reprise après perte du serveur éprouvée (runbook)',
        'm8_13_supervision_alerte' => 'Alerte de supervision reçue par un humain',
        'm8_14_sauvegarde' => 'Sauvegarde et restauration du serveur d’envoi éprouvées',
        'm8_15_mentions_legales' => 'Mentions légales et identité de l’expéditeur validées',
        'm8_16_base_legale' => 'Base légale et registre des traitements validés',
        'm8_17_contenu_message' => 'Gabarit de message relu et approuvé',
        'm8_18_banc_temoins' => 'Banc d’essai sur adresses témoins réussi',
        'a11_inscriptions' => 'Inscriptions de réputation (A11) faites',
    ];

    public function __construct(private readonly DepotPreuves $preuves, private readonly DepotSante $sante) {}

    /** @return list<PointFeuVert> */
    public function points(string $ws): array
    {
        $d = WorkspaceContext::run($ws, fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first());
        $auto = static fn (string $code, string $libelle, bool $fait, string $ok, string $ko, string $source): PointFeuVert => new PointFeuVert($code, $libelle, true, $fait, $fait ? $ok : $ko, $source);

        $cle = (string) config('envoi.cle_desinscription');
        $hotes = (array) config('envoi.desinscription_hotes');
        $hotesPoses = (string) config('envoi.desinscription_hote') !== '' && (string) ($hotes['app'] ?? '') !== '' && (string) ($hotes['api'] ?? '') !== '';
        $releve = config('envoi.releve_actif', config('envoi.releve.actif', false)) === true;
        $dns = $this->sante->controlesDns($ws);
        $listes = $this->sante->controlesListes($ws);
        $dnsOk = $dns !== [] && array_filter($dns, static fn (array $c): bool => ! $c['pret'] || $c['pause']) === [];
        $listesOk = $listes !== [] && array_filter($listes, static fn (array $c): bool => $c['statut'] !== 'non_liste' || $c['pause']) === [];

        $points = [
            $auto('reel_actif_faux', 'Envoi réel éteint', config('envoi.reel_actif') === false, 'ENVOI_REEL_ACTIF est faux.', 'L’envoi réel est actif.', 'config envoi.reel_actif'),
            $auto('mode_temoins_vrai', 'Mode témoins actif', $d !== null && (bool) $d->mode_temoins, 'Le mode témoins est actif.', 'Mode témoins inactif ou drapeaux absents.', 'table envoi_drapeaux.mode_temoins'),
            $auto('signature_absente', 'Aucune signature de feu vert', $d !== null && $d->feu_vert_signature === null, 'Aucune signature posée.', $d === null ? 'Drapeaux absents.' : 'Une signature existe déjà.', 'table envoi_drapeaux.feu_vert_signature'),
            $this->temoins($ws),
            $auto('arret_entreprise', 'Arrêt par entreprise branché', ! self::estAbsent(app(ArretParEntreprise::class), ArretParEntrepriseAbsent::class), 'Implémentation réelle liée.', 'Implémentation « absente » liée.', 'conteneur ArretParEntreprise'),
            $auto('retrait_file', 'Retrait des envois en file branché', ! self::estAbsent(app(RetraitEnvoisEnFile::class), RetraitEnvoisEnFileAbsent::class), 'Implémentation réelle liée.', 'Implémentation « absente » liée.', 'conteneur RetraitEnvoisEnFile'),
            $auto('cle_desinscription', 'Clé de désinscription posée (32 caractères au moins)', mb_strlen($cle) >= 32, 'Clé posée, longueur suffisante.', 'Clé absente ou trop courte.', 'config envoi.cle_desinscription (valeur jamais lue ici)'),
            $auto('hotes_desinscription', 'Hôtes de désinscription posés', $hotesPoses, 'Hôte et adresses app/api posés.', 'Hôte ou adresse app/api manquant.', 'config envoi.desinscription_hote(s) (valeurs jamais lues ici)'),
            $auto('releve_imap', 'Relève IMAP planifiée', $releve, 'Interrupteur de relève actif.', 'Interrupteur de relève éteint ou absent (fiche F12).', 'config envoi.releve_actif'),
            $auto('domaines_prets', 'Domaines prêts (K12)', $dnsOk, 'Tous les domaines contrôlés sont prêts.', 'Aucun contrôle, ou un domaine pas prêt / en pause.', 'table envoi_controles_dns'),
            $auto('listes_noires', 'Hors listes noires (K13)', $listesOk, 'Aucune ressource listée.', 'Aucun contrôle, ou une ressource listée / non fiable / en pause.', 'table envoi_controles_listes'),
            $auto('supervision_k15', 'Supervision K15 active', config('envoi.sante.actif') === true, 'Supervision active.', 'Supervision éteinte.', 'config envoi.sante.actif'),
        ];

        $preuves = $this->preuves->dernieres($ws);
        foreach (self::MANUELS as $code => $libelle) {
            $p = $preuves[$code] ?? null;
            $points[] = new PointFeuVert(
                $code,
                $libelle,
                false,
                $p !== null,
                $p !== null ? 'Preuve déposée.' : 'Vérifiable seulement avec le vrai serveur.',
                'table envoi_feu_vert_preuves',
                $p,
            );
        }

        return $points;
    }

    /** @param class-string $absent */
    private static function estAbsent(object $liaison, string $absent): bool
    {
        return is_a($liaison, $absent);
    }

    private function temoins(string $ws): PointFeuVert
    {
        $code = 'temoins_fournisseurs';
        $libelle = 'Les 6 fournisseurs témoins sont couverts';
        $source = 'table envoi_temoins.fournisseur (fiche F03)';
        if (! Schema::hasColumn('envoi_temoins', 'fournisseur')) {
            return new PointFeuVert($code, $libelle, true, false, 'Colonne fournisseur absente (fiche F03 pas encore posée).', $source);
        }
        $presents = WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->where('workspace_id', $ws)->distinct()->pluck('fournisseur')->all());
        $manquants = array_values(array_diff(self::FOURNISSEURS_TEMOINS, array_map('strval', $presents)));

        return new PointFeuVert($code, $libelle, true, $manquants === [], $manquants === [] ? 'Les 6 fournisseurs sont couverts.' : count($manquants) . ' fournisseur(s) sans témoin : ' . implode(', ', $manquants) . '.', $source);
    }
}
