<?php

namespace App\Envoi\FeuVert;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Feu vert final (D12) : « accorde » ou « retire », consigné en AJOUT SEUL (`envoi_feu_vert_decisions`) avec la photo des points.
 * Le feu vert est nécessaire, pas suffisant : ce service n'écrit AUCUNE configuration, aucun drapeau, aucune signature.
 *
 * Toute décision est prise sous un verrou consultatif PAR ESPACE (clé distincte de celle des preuves) ; l'empreinte est recalculée
 * sous ce verrou avant l'ajout : de deux « accorde » simultanés sur la même empreinte, un seul passe, l'autre reçoit 412.
 * « retire » est toujours accepté (le stop gagne) : ni If-Match, ni 412, ni 428, ni blocage.
 */
class DecisionFeuVert
{
    public const MOTIF_MIN = 10;

    public const MOTIF_MAX = 500;

    public function __construct(private readonly ListeFeuVert $liste) {}

    /**
     * @return array{statut: int, erreur?: string, message?: string} 200 accepté ; 409 bloquant rouge ; 412 empreinte périmée ; 428 If-Match absent
     *
     * @throws InvalidArgumentException décision ou motif invalide (422)
     */
    public function decider(string $ws, string $decision, string $motif, string $auteur, ?string $siMatch): array
    {
        if (! in_array($decision, ['accorde', 'retire'], true)) {
            throw new InvalidArgumentException('La décision doit être « accorde » ou « retire ».');
        }
        $motif = self::motifValide($motif);
        $auteur = DepotPreuves::auteurAffichable($auteur);
        $attendue = self::normaliser($siMatch);
        // « accorde » sans en-tête : refus AVANT tout calcul, sans verrou.
        if ($decision === 'accorde' && $attendue === null) {
            return ['statut' => 428, 'erreur' => 'precondition_requise', 'message' => 'L’en-tête If-Match (empreinte de la liste lue à l’écran) est obligatoire pour accorder.'];
        }

        return WorkspaceContext::run($ws, fn (): array => DB::transaction(function () use ($ws, $decision, $motif, $auteur, $attendue): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_feu_vert_decision|' . $ws]);
            $points = $this->liste->points($ws);
            $empreinte = $this->liste->empreinte($ws, $points);
            if ($decision === 'accorde') {
                if (! hash_equals($empreinte, (string) $attendue)) {
                    return ['statut' => 412, 'erreur' => 'precondition_echouee', 'message' => 'La liste a changé depuis votre lecture : relisez-la avant d’accorder.'];
                }
                $rouges = array_filter($points, static fn (PointFeuVert $p): bool => $p->bloquant && $p->code !== ListeFeuVert::POINT_EXPLOITATION);
                if ($rouges !== []) {
                    return ['statut' => 409, 'erreur' => 'conflit', 'message' => count($rouges) . ' point(s) bloquant(s) restent rouges : le feu vert ne peut pas être accordé.'];
                }
            }
            // Photo des points : code, état, fait, bloquant seulement (aucun texte libre, donc aucune adresse).
            $photo = [];
            foreach ($points as $p) {
                $photo[$p->code] = ['etat' => $p->etat, 'fait' => $p->fait, 'bloquant' => $p->bloquant];
            }
            DB::table('envoi_feu_vert_decisions')->insert([
                'workspace_id' => $ws,
                'decision' => $decision,
                'auteur' => $auteur,
                'motif' => $motif,
                'empreinte_liste' => $empreinte,
                'points' => json_encode($photo, JSON_THROW_ON_ERROR),
            ]);

            return ['statut' => 200];
        }));
    }

    /** `If-Match` : accepte la forme entre guillemets (et faible `W/"…"`) ; vide ⇒ absent. */
    public static function normaliser(?string $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $v = trim($valeur);
        if (str_starts_with($v, 'W/')) {
            $v = substr($v, 2);
        }
        $v = trim($v, " \t\"");

        return $v === '' ? null : $v;
    }

    private static function motifValide(string $motif): string
    {
        if (! mb_check_encoding($motif, 'UTF-8') || preg_match('/\p{Cf}/u', $motif) === 1) {
            throw new InvalidArgumentException('Motif invalide (UTF-8 sans caractère de format).');
        }
        $m = DepotPreuves::nettoyer($motif);
        if (preg_match('/\p{Cc}/u', $m) === 1) {
            throw new InvalidArgumentException('Caractère de contrôle interdit dans le motif.');
        }
        if (mb_strlen($m) < self::MOTIF_MIN || mb_strlen($m) > self::MOTIF_MAX) {
            throw new InvalidArgumentException('Le motif doit faire de ' . self::MOTIF_MIN . ' à ' . self::MOTIF_MAX . ' caractères.');
        }
        if (DepotPreuves::ressembleAUneAdresse($m)) {
            throw new InvalidArgumentException('Le motif ne doit contenir aucune adresse e-mail.');
        }

        return $m;
    }
}
