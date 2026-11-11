<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Reglages\AssouplissementRefuse;
use App\Envoi\Reglages\DepotAlertes;
use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Reglages\ReglesPrudence;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * R14b : réglages d'envoi (contrat openapi/envoi/conformite.yaml). On ne peut que DURCIR (sens porté par ReglesPrudence).
 * Aucune route ici ne fait partir un message, ne résout `ServeurEnvoi`, ni n'écrit `envoi_drapeaux`.
 */
class EnvoiReglagesController extends ApiController
{
    private const JOURS = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];

    private const CHAMPS_REGLES = ['pause_si_refus_pct', 'pause_si_plaintes_pour_mille', 'test_du_matin_bloquant', 'frein_reponses_par_jour',
        'pression_max_par_organisation_30j', 'heure_de', 'heure_a', 'jours_ouvres_seulement'];

    public function __construct(private readonly DepotReglages $depot) {}

    public function regles(): JsonResponse
    {
        return response()->json($this->vueRegles());
    }

    public function enregistrerRegles(Request $r): JsonResponse
    {
        $d = $r->json()->all();
        $manque = array_diff(self::CHAMPS_REGLES, array_keys($d));
        $faute = $manque !== [] ? 'Il manque : ' . implode(', ', $manque) . '.' : ReglesPrudence::invalide($d);
        if ($faute !== null) {
            return $this->erreur('validation', $faute);
        }

        return $this->durcir(array_intersect_key($d, array_flip(self::CHAMPS_REGLES)), $r, fn () => $this->vueRegles());
    }

    public function reglages(): JsonResponse
    {
        return response()->json($this->vueReglages());
    }

    public function enregistrerReglages(Request $r): JsonResponse
    {
        $d = $r->json()->all();
        $h = $d['heures'] ?? null;
        if (! isset($d['plafond_jour'], $d['jours'], $d['fuseau'], $d['pause_si_refus_pct']) || ! is_array($h) || ! isset($h['de'], $h['a'])) {
            return $this->erreur('validation', 'Réglages incomplets (plafond, jours, heures, fuseau, seuil de refus).');
        }
        if ($d['fuseau'] !== 'Europe/Paris') {
            return $this->erreur('validation', 'Le fuseau d’envoi est Europe/Paris et ne se change pas ici.');
        }
        $demande = ['plafond_jour' => $d['plafond_jour'], 'jours' => $d['jours'], 'heure_de' => $h['de'], 'heure_a' => $h['a'], 'pause_si_refus_pct' => $d['pause_si_refus_pct']];
        if (($faute = ReglesPrudence::invalide($demande)) !== null) {
            return $this->erreur('validation', $faute);
        }

        return $this->durcir($demande, $r, fn () => $this->vueReglages());
    }

    public function apercu(DepotAlertes $alertes): JsonResponse
    {
        $ws = $this->ws();
        $regles = $alertes->regles($ws);
        $canaux = array_values(array_unique(array_merge(...array_map(fn (array $x): array => $x['actif'] ? $x['canaux'] : [], $regles))));
        $oppositions = (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions')->where('workspace_id', $ws)->count());
        // Aucune source automatique ne confirme ces boucles : elles restent « à faire » (jamais un faux « fait »).
        $boucles = array_map(
            fn (string $n): array => ['nom' => $n, 'etat' => 'a_faire', 'detail' => 'À confirmer par le responsable du projet : aucune source automatique ne le vérifie.'],
            ['Boucle de retour Gmail', 'Boucle de retour Microsoft', 'Boucle de retour Yahoo'],
        );

        return response()->json([
            'regles_actives' => count(array_filter($regles, fn (array $x): bool => $x['actif'])), 'canaux' => $canaux,
            'oppositions' => $oppositions, 'absence' => $this->depot->absence($ws), 'boucles_de_retour' => $boucles,
        ]);
    }

    public function absence(Request $r): JsonResponse
    {
        $d = $r->json()->all();
        $date = fn (mixed $v): bool => $v === null || (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false);
        if (! is_bool($d['actif'] ?? null) || ! $date($d['du'] ?? null) || ! $date($d['au'] ?? null)) {
            return $this->erreur('validation', 'Indiquez si l’absence est active et, au besoin, deux dates AAAA-MM-JJ.');
        }
        if (isset($d['du'], $d['au']) && $d['du'] > $d['au']) {
            return $this->erreur('validation', 'La date de début doit précéder la date de fin.');
        }

        return response()->json($this->depot->reglerAbsence($this->ws(), $d['actif'], $d['du'] ?? null, $d['au'] ?? null, $this->auteur($r)));
    }

    public function identite(): JsonResponse
    {
        return response()->json($this->depot->identite($this->ws()));
    }

    public function enregistrerIdentite(Request $r): JsonResponse
    {
        $d = $r->json()->all();
        foreach (['raison_sociale' => 200, 'forme' => 50, 'adresse_postale' => 500, 'signature' => 2000, 'bloc_information' => 4000] as $c => $max) {
            if (! is_string($d[$c] ?? null) || mb_strlen($d[$c]) > $max) {
                return $this->erreur('validation', "« {$c} » doit être un texte d’au plus {$max} caractères.");
            }
        }
        if (($d['lien_desinscription'] ?? null) !== true) {
            return $this->erreur('validation', 'Le lien de désinscription est obligatoire dans chaque message.');
        }
        $rdv = $d['lien_rendez_vous'] ?? null;
        if ($rdv !== null && (! is_string($rdv) || mb_strlen($rdv) > 500 || preg_match('#^https://#', $rdv) !== 1)) {
            return $this->erreur('validation', 'Le lien de rendez-vous doit être une adresse https.');
        }
        $this->depot->enregistrerIdentite($this->ws(), $d + ['lien_rendez_vous' => null], $this->auteur($r));

        return response()->json($this->depot->identite($this->ws()));
    }

    /** Au palier 0 la connexion est « interne » : rien n'est appelé à l'extérieur, ni à la lecture ni au test. */
    public function connexionCrm(): JsonResponse
    {
        return response()->json([
            'adaptateur' => 'interne', 'etat' => 'gris', 'derniere_synchro_le' => null, 'contacts_vus' => 0,
            'ce_qui_passe' => ['Envoi lit les contacts du CRM de la même application, sans connexion extérieure.'],
            'ce_qui_ne_passe_pas' => ['Aucun message n’est envoyé par ce test.', 'Aucun contact n’est copié, modifié ni supprimé.'],
        ]);
    }

    /** 409 et non 501 : la suite R00 refuse tout 5xx sur un GET /envoi/*, et l'écran doit pouvoir expliquer. */
    public function carnet(): JsonResponse
    {
        return response()->json(['error' => 'carnet_indisponible', 'message' => 'Le carnet de destinataires (Envoi sans CRM) n’est pas disponible : décision à prendre par le responsable du projet.'], 409);
    }

    /** @param array<string, mixed> $demande */
    private function durcir(array $demande, Request $r, callable $vue): JsonResponse
    {
        try {
            $this->depot->durcir($this->ws(), $demande, $this->auteur($r));
        } catch (AssouplissementRefuse $e) {
            return response()->json(['error' => 'assouplissement_refuse', 'message' => $e->getMessage(), 'details' => ['champs' => $e->champs]], 422);
        }

        return response()->json($vue());
    }

    /** @return array<string, mixed> */
    private function vueRegles(): array
    {
        return array_intersect_key($this->depot->effectives($this->ws()), array_flip(self::CHAMPS_REGLES));
    }

    /** @return array<string, mixed> */
    private function vueReglages(): array
    {
        $v = $this->depot->effectives($this->ws());

        return [
            'plafond_jour' => $v['plafond_jour'], 'jours' => array_values(array_intersect(self::JOURS, $v['jours'])),
            'heures' => ['de' => $v['heure_de'], 'a' => $v['heure_a']], 'fuseau' => 'Europe/Paris', 'pause_si_refus_pct' => $v['pause_si_refus_pct'],
        ];
    }

    private function auteur(Request $r): string
    {
        return (string) $r->user()?->name;
    }

    private function erreur(string $code, string $message): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], 422);
    }

    private function ws(): string
    {
        return (string) WorkspaceContext::current();
    }
}
