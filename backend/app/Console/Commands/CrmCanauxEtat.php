<?php

namespace App\Console\Commands;

use App\Support\BattementPlanificateur;
use App\Support\CanalSigneSite;
use App\Support\CompteurRefusCanal;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lot N2 — ÉTAT DES CANAUX D'ÉCHANGE CRM ↔ SITE, en LECTURE SEULE.
 *
 * Lue toutes les heures par `.github/workflows/surveillance-canaux.yml`, depuis
 * GitHub, en SSH. Sortie : UNE ligne JSON, sans aucune donnée personnelle
 * (des nombres, des dates, des booléens) — elle finit dans des issues d'un
 * dépôt PUBLIC.
 *
 * Quatre questions :
 *   1. la file sortante `crm_outbound_events` (CRM → site) avance-t-elle ?
 *      → aucune ligne `pending`/`failed` plus vieille que `--seuil-age-h` ;
 *      → aucune ligne passée `gave_up` dans les `--fenetre-abandon-min`
 *        dernières minutes (état TERMINAL : une opposition qui n'atteindra
 *        jamais le site). 26 h par défaut : un passage horaire sauté par
 *        GitHub ne fait plus disparaître un abandon ; les numéros des lignes
 *        (`gave_up_recents_ids`) permettent au workflow de ne signaler chaque
 *        ligne qu'une fois ;
 *   2. le site parle-t-il encore ? → au moins une activité
 *      `external_ref LIKE 'site:event:%'` reçue dans les `--seuil-silence-h`
 *      dernières heures ;
 *   3. les signatures du site sont-elles refusées ? → plus de `--seuil-refus`
 *      refus (`bad_signature`, `stale_signature`, `replay_guard_unavailable`)
 *      sur les `--fenetre-refus-min` dernières minutes (120), OU plus de
 *      `--seuil-bad-signature` `bad_signature` (1 : alerte dès 2), lus dans
 *      {@see CompteurRefusCanal}. Un `bad_signature` (horodatage valide,
 *      signature fausse) ne vient presque jamais d'un tiers : c'est le
 *      symptôme d'un secret désaligné, que le backoff du site (6 essais en
 *      31 min, puis ~1/h) rendait invisible une fois sur deux. Les POST sans
 *      AUCUN en-tête du site (`sans_entete`, bruit d'Internet) sont mesurés
 *      mais n'alertent jamais ;
 *   4. le planificateur tourne-t-il ? → un battement {@see BattementPlanificateur}
 *      de moins de `--seuil-planificateur-min` minutes (15).
 *
 * CANAL FERMÉ EXPRÈS. `--fermes-expres=site-vers-crm,crm-vers-site` neutralise
 * `site_muet` (resp. `file_sortante_bloquee`) pour un canal volontairement
 * coupé — SEULEMENT si son drapeau (`CRM_INGEST_ENABLED`, resp.
 * `CRM_OUTBOUND_ENABLED`) est effectivement fermé : une déclaration oubliée
 * après réouverture ne masque rien. Le JSON le dit (`ferme_expres`).
 * `file_sortante_abandon` n'est JAMAIS neutralisé (divergence RGPD).
 *
 * ⚠️ SÉCURITÉ PAR ESPACE. `activities` porte une RLS forcée : sous le rôle
 * applicatif de production, une requête sans contexte d'espace rend ZÉRO ligne
 * — et un « canal muet » qui serait en réalité une requête aveugle. On
 * parcourt donc les espaces (table `workspaces`, hors RLS) et on compte sous
 * le contexte de chacun, comme `audiences:full-refresh`.
 *
 * Une mesure qui échoue ne vaut JAMAIS zéro : elle vaut `null` et lève
 * l'alerte `controle_impossible`. Sinon une base injoignable ressemblerait
 * exactement à une file vide.
 *
 * Code de sortie : 0 sans alerte, 1 avec au moins une alerte. Le JSON est
 * imprimé dans les deux cas — c'est lui qui fait foi, pas le code.
 */
class CrmCanauxEtat extends Command
{
    protected $signature = 'crm:canaux:etat
        {--seuil-age-h=2 : Âge maximum, en heures, d\'une ligne pending/failed de la file sortante}
        {--fenetre-abandon-min=1560 : Fenêtre, en minutes, dans laquelle un passage en gave_up déclenche l\'alerte (26 h)}
        {--seuil-silence-h=48 : Silence maximum, en heures, du canal site → CRM}
        {--seuil-refus=5 : Nombre de refus de signature tolérés sur la fenêtre (alerte au-delà)}
        {--seuil-bad-signature=1 : Nombre de bad_signature tolérés sur la fenêtre (alerte au-delà)}
        {--fenetre-refus-min=120 : Fenêtre de comptage des refus de signature, en minutes}
        {--seuil-planificateur-min=15 : Âge maximum, en minutes, du dernier battement du planificateur}
        {--fermes-expres= : Canaux fermés exprès, séparés par des virgules (site-vers-crm, crm-vers-site)}';

    /** Les canaux qu'on peut déclarer fermés exprès, et le drapeau qui les ferme. */
    public const CANAUX_FERMABLES = ['site-vers-crm', 'crm-vers-site'];

    protected $description = 'État des canaux CRM ↔ site (file sortante, réception, refus de signature) — lecture seule, sortie JSON';

    /** @var list<array{type: string, message: string}> */
    private array $alertes = [];

    public function handle(): int
    {
        // L'instance de la commande est réutilisée d'un appel à l'autre dans
        // un même processus (Artisan::call) : sans cette remise à zéro, les
        // alertes d'un passage précédent s'ajouteraient au suivant.
        $this->alertes = [];

        $seuilAgeH = max(1, (int) $this->option('seuil-age-h'));
        $fenetreAbandonMin = max(1, (int) $this->option('fenetre-abandon-min'));
        $seuilSilenceH = max(1, (int) $this->option('seuil-silence-h'));
        $seuilRefus = max(0, (int) $this->option('seuil-refus'));
        $seuilBadSignature = max(0, (int) $this->option('seuil-bad-signature'));
        $fenetreRefusMin = max(5, (int) $this->option('fenetre-refus-min'));
        $seuilPlanificateurMin = max(2, (int) $this->option('seuil-planificateur-min'));

        $ingestion = filter_var(config('crm.ingest.enabled', false), FILTER_VALIDATE_BOOLEAN);
        $emission = filter_var(config('crm.outbound_enabled', false), FILTER_VALIDATE_BOOLEAN);
        $fermesExpres = $this->fermesExpres();

        $etat = [
            'genere_a' => now()->toIso8601String(),
            'drapeaux' => [
                'ingestion_site' => $ingestion,
                'emission_vers_site' => $emission,
            ],
            'canaux_fermes_expres' => $fermesExpres,
            'file_sortante' => $this->fileSortante($seuilAgeH, $fenetreAbandonMin, $emission, in_array('crm-vers-site', $fermesExpres, true)),
            'reception_site' => $this->receptionSite($seuilSilenceH, $ingestion, in_array('site-vers-crm', $fermesExpres, true)),
            'refus_signature' => $this->refusSignature($seuilRefus, $seuilBadSignature, $fenetreRefusMin),
            'planificateur' => $this->planificateur($seuilPlanificateurMin),
        ];
        $etat['alertes'] = $this->alertes;

        $this->line((string) json_encode($etat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->aucuneAlerte() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Les canaux déclarés fermés exprès, connus seulement, triés, sans doublon.
     * Un nom inconnu (faute de frappe) est ignoré : l'alerte reste, donc rien
     * n'est masqué.
     *
     * @return list<string>
     */
    private function fermesExpres(): array
    {
        $brut = $this->option('fermes-expres');
        $noms = array_map('trim', explode(',', is_string($brut) ? strtolower($brut) : ''));
        $connus = array_values(array_unique(array_intersect($noms, self::CANAUX_FERMABLES)));
        sort($connus);

        return $connus;
    }

    /**
     * @return array<string, mixed>
     */
    private function fileSortante(int $seuilAgeH, int $fenetreAbandonMin, bool $emission, bool $declareFerme): array
    {
        // Neutralisation SEULEMENT si le drapeau est réellement fermé.
        $fermeExpres = $declareFerme && ! $emission;
        $mesure = [
            'seuil_age_h' => $seuilAgeH,
            'fenetre_abandon_min' => $fenetreAbandonMin,
            'ferme_expres' => $fermeExpres,
            'pending' => null,
            'failed' => null,
            'gave_up_total' => null,
            'en_retard' => null,
            'plus_ancienne_en_attente_a' => null,
            'gave_up_recents' => null,
            'gave_up_recents_ids' => null,
        ];

        try {
            // Table GLOBALE (infrastructure, sans workspace_id, sans RLS).
            $parStatut = DB::table('crm_outbound_events')
                ->select('status', DB::raw('COUNT(*) AS c'))
                ->whereIn('status', ['pending', 'failed', 'gave_up'])
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all();

            $limite = now()->subHours($seuilAgeH);
            $enRetard = (int) DB::table('crm_outbound_events')
                ->whereIn('status', ['pending', 'failed'])
                ->where('created_at', '<', $limite)
                ->count();
            $plusAncienne = DB::table('crm_outbound_events')
                ->whereIn('status', ['pending', 'failed'])
                ->min('created_at');
            // `updated_at` est posé au passage en `gave_up` (CrmFlushOutbound).
            $abandonsRecents = (int) DB::table('crm_outbound_events')
                ->where('status', 'gave_up')
                ->where('updated_at', '>=', now()->subMinutes($fenetreAbandonMin))
                ->count();
            // Les numéros de ligne (BIGSERIAL, rien de personnel) : le workflow
            // ne complète l'issue qu'avec les lignes qu'il n'a pas déjà dites.
            // Plafonnés : l'issue n'a pas à porter un inventaire.
            $idsAbandons = DB::table('crm_outbound_events')
                ->where('status', 'gave_up')
                ->where('updated_at', '>=', now()->subMinutes($fenetreAbandonMin))
                ->orderBy('id')
                ->limit(200)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        } catch (Throwable $e) {
            $this->alerter('controle_impossible', 'File sortante illisible (' . $e::class . ').');

            return $mesure;
        }

        $mesure['pending'] = (int) ($parStatut['pending'] ?? 0);
        $mesure['failed'] = (int) ($parStatut['failed'] ?? 0);
        $mesure['gave_up_total'] = (int) ($parStatut['gave_up'] ?? 0);
        $mesure['en_retard'] = $enRetard;
        $mesure['plus_ancienne_en_attente_a'] = self::dateIso($plusAncienne);
        $mesure['gave_up_recents'] = $abandonsRecents;
        $mesure['gave_up_recents_ids'] = array_values($idsAbandons);

        if ($enRetard > 0 && ! $fermeExpres) {
            $this->alerter(
                'file_sortante_bloquee',
                "{$enRetard} événement(s) CRM → site en attente depuis plus de {$seuilAgeH} h (pending/failed)."
                . ($emission ? '' : ' Émission FERMÉE (CRM_OUTBOUND_ENABLED=false) : les lignes restent en file sans jamais partir.')
                . ($declareFerme && $emission ? ' Canal déclaré fermé exprès (SURV_CANAUX_FERMES_EXPRES), mais CRM_OUTBOUND_ENABLED est OUVERT : la déclaration ne vaut plus.' : ''),
            );
        }
        if ($abandonsRecents > 0) {
            $this->alerter(
                'file_sortante_abandon',
                "{$abandonsRecents} événement(s) CRM → site abandonné(s) (gave_up) dans les {$fenetreAbandonMin} dernières minutes ; {$mesure['gave_up_total']} au total.",
            );
        }

        return $mesure;
    }

    /**
     * @return array<string, mixed>
     */
    private function receptionSite(int $seuilSilenceH, bool $ingestion, bool $declareFerme): array
    {
        $fermeExpres = $declareFerme && ! $ingestion;
        $mesure = [
            'seuil_silence_h' => $seuilSilenceH,
            'ferme_expres' => $fermeExpres,
            'recus_dans_la_fenetre' => null,
            'derniere_reception_a' => null,
        ];

        try {
            $espaces = DB::table('workspaces')->whereNull('deleted_at')->orderBy('id')->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            $depuis = now()->subHours($seuilSilenceH);
            $recus = 0;
            $derniere = null;

            foreach ($espaces as $espace) {
                [$n, $max] = WorkspaceContext::run($espace, static function () use ($espace, $depuis): array {
                    $base = static fn () => DB::table('activities')
                        ->where('workspace_id', $espace)
                        ->where('external_ref', 'LIKE', 'site:event:%');

                    return [
                        (int) $base()->where('created_at', '>=', $depuis)->count(),
                        $base()->max('created_at'),
                    ];
                });

                $recus += $n;
                $iso = self::dateIso($max);
                if ($iso !== null && ($derniere === null || $iso > $derniere)) {
                    $derniere = $iso;
                }
            }
        } catch (Throwable $e) {
            $this->alerter('controle_impossible', 'Réception du site illisible (' . $e::class . ').');

            return $mesure;
        }

        $mesure['recus_dans_la_fenetre'] = $recus;
        $mesure['derniere_reception_a'] = $derniere;

        if ($recus === 0 && ! $fermeExpres) {
            $this->alerter(
                'site_muet',
                "Aucun événement reçu du site depuis {$seuilSilenceH} h (dernière réception : " . ($derniere ?? 'jamais') . ').'
                . ($ingestion ? '' : ' Ingestion FERMÉE (CRM_INGEST_ENABLED=false) : le CRM répond 503 à tout envoi du site.')
                . ($declareFerme && $ingestion ? ' Canal déclaré fermé exprès (SURV_CANAUX_FERMES_EXPRES), mais CRM_INGEST_ENABLED est OUVERT : la déclaration ne vaut plus.' : ''),
            );
        }

        return $mesure;
    }

    /**
     * @return array<string, mixed>
     */
    private function refusSignature(int $seuilRefus, int $seuilBadSignature, int $fenetreRefusMin): array
    {
        $lu = CompteurRefusCanal::lire(CanalSigneSite::CANAUX, $fenetreRefusMin);
        $mesure = ['seuil' => $seuilRefus, 'seuil_bad_signature' => $seuilBadSignature] + $lu;
        $mauvaises = $lu['par_motif']['bad_signature'] ?? 0;

        if (! $lu['disponible']) {
            $this->alerter('refus_signature', 'Compteur des refus de signature illisible (magasin Redis indisponible ?).');
        } elseif ($mauvaises > $seuilBadSignature) {
            $this->alerter(
                'refus_signature',
                "{$mauvaises} signature(s) fausse(s) (bad_signature) sur les {$lu['fenetre_min']} dernières minutes (seuil : {$seuilBadSignature}) : secret désaligné ?",
            );
        } elseif ($lu['total'] > $seuilRefus) {
            $this->alerter(
                'refus_signature',
                "{$lu['total']} refus de signature sur les {$lu['fenetre_min']} dernières minutes (seuil : {$seuilRefus}).",
            );
        }

        return $mesure;
    }

    /**
     * @return array<string, mixed>
     */
    private function planificateur(int $seuilMin): array
    {
        $mesure = ['seuil_min' => $seuilMin, 'dernier_battement_a' => null];

        try {
            $dernier = BattementPlanificateur::dernier();
        } catch (Throwable $e) {
            $this->alerter('controle_impossible', 'Battement du planificateur illisible (' . $e::class . ').');

            return $mesure;
        }

        if ($dernier !== null) {
            $mesure['dernier_battement_a'] = Carbon::createFromTimestampUTC($dernier)->format('Y-m-d\TH:i:s\Z');
        }

        if ($dernier === null || now()->getTimestamp() - $dernier > $seuilMin * 60) {
            $this->alerter(
                'planificateur_arrete',
                "Aucun battement du planificateur depuis plus de {$seuilMin} min (dernier : " . ($mesure['dernier_battement_a'] ?? 'jamais, ou plus de 24 h') . ') : `schedule:work` tourne-t-il ?',
            );
        }

        return $mesure;
    }

    private function aucuneAlerte(): bool
    {
        return $this->alertes === [];
    }

    private function alerter(string $type, string $message): void
    {
        $this->alertes[] = ['type' => $type, 'message' => $message];
    }

    /** Une date SQL (chaîne, ou null) en ISO 8601 UTC, comparable comme chaîne. */
    private static function dateIso(mixed $valeur): ?string
    {
        if (! is_string($valeur) || $valeur === '') {
            return null;
        }

        try {
            return Carbon::parse($valeur)->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return null;
        }
    }
}
