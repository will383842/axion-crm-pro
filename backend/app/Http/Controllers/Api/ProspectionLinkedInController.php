<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\AuditHashChainLogger;
use App\ProspectionLinkedIn\Arret;
use App\ProspectionLinkedIn\ControlesProspect;
use App\ProspectionLinkedIn\DepotProspects;
use App\ProspectionLinkedIn\EtatsProspect;
use App\ProspectionLinkedIn\FileDuJour;
use App\ProspectionLinkedIn\JournalProspection;
use App\ProspectionLinkedIn\OpposerProspect;
use App\ProspectionLinkedIn\Plafonds;
use App\ProspectionLinkedIn\TextePret;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * PONT-LINKEDIN LI-C — API de la file « prêt à cliquer » (voie S : le fondateur clique lui-même ; aucun appel vers LinkedIn).
 * Drapeau `prospection_linkedin.active` éteint ⇒ 404 avant tout accès à la base du module ; réservé au propriétaire (même
 * permission que l'opposition manuelle, posée sur les routes). Échec fermé : une panne du contrôle d'opposition ne propose rien. Aucune suppression.
 * L'audit est écrit ici, sans profil ni adresse (le journal HTTP générique est désactivé).
 */
class ProspectionLinkedInController extends ApiController
{
    private const GABARIT_INVITATION = 'Bonjour, je souhaiterais vous ajouter à mon réseau professionnel.';

    /** Drapeau éteint ⇒ 404 avant tout accès à la base du module ; le journal HTTP générique est désactivé (audit propre). */
    private function garde(Request $request): void
    {
        abort_unless((bool) config('prospection_linkedin.active', false), 404);
        $request->attributes->set(AuditHashChainLogger::ATTRIBUT_JOURNAL_PROPRE, true);
    }

    public function file(Request $request): JsonResponse
    {
        $this->garde($request);
        $ws = $this->ws();
        $uid = $this->uid($request);
        $this->auditer($request, 'file', 200);
        $vide = static fn (string $raison): JsonResponse => response()->json(['prospects' => [], 'raison' => $raison, 'arret' => $raison === 'arret', 'restants' => ['jour' => 0, 'semaine' => 0]]);

        try {
            $r = (new FileDuJour)->construire($ws, $uid);
            if (in_array($r['raison'], ['arret', 'controle_illisible'], true)) {
                return $vide((string) $r['raison']);
            }
            $lignes = WorkspaceContext::run($ws, static fn (): array => array_values(DB::table('pl_prospects')
                ->where('workspace_id', $ws)->where('expediteur_user_id', $uid)->where('etat', 'en_file')
                ->orderByDesc('score')->orderBy('cree_le')->orderBy('id')->limit(50)->get()->all()));
            $controles = new ControlesProspect;
            $opposes = array_flip($controles->opposes($ws, $lignes)); // lève si illisible : rien n'est proposé
            $arret = (new Arret)->actif($ws, $uid);
            if ($arret) {
                return $vide('arret');
            }
            $restants = (new Plafonds)->restants($ws, $uid);
            $essai = ! (bool) config('envoi.reel_actif', false);
            $sortie = [];
            foreach ($lignes as $p) {
                if (isset($opposes[(string) $p->id])) {
                    $controles->ecarter($ws, (string) $p->id);

                    continue;
                }
                $sortie[] = [
                    'id' => (string) $p->id,
                    'profil' => 'https://www.linkedin.com/in/' . $p->linkedin_slug . '/',
                    'entreprise' => $p->entreprise,
                    'score' => (int) $p->score,
                    'texte' => $this->texte($essai),
                ];
            }

            return response()->json(['prospects' => $sortie, 'raison' => $sortie === [] ? $r['raison'] : null, 'arret' => false, 'restants' => $restants]);
        } catch (Throwable) {
            return $vide('controle_illisible');
        }
    }

    public function ajouter(Request $request): JsonResponse
    {
        $this->garde($request);
        $v = $request->validate([
            'profil' => ['required', 'string', 'max:300'],
            'source' => ['required', 'string', 'in:' . implode(',', DepotProspects::SOURCES)],
            'source_ref' => ['nullable', 'string', 'max:120', 'not_regex:/@/'],
            'source_le' => ['nullable', 'date_format:Y-m-d'],
            'entreprise' => ['nullable', 'string', 'max:200'],
            'siren' => ['nullable', 'string', 'regex:/^[0-9]{9}$/'],
        ]);
        try {
            $r = (new DepotProspects)->ajouter($this->ws(), $this->uid($request), $v['profil'], $v['source'], $v['source_ref'] ?? null, $v['source_le'] ?? now()->toDateString(), $v['entreprise'] ?? null, $v['siren'] ?? null);
        } catch (Throwable) {
            $this->auditer($request, 'ajouter', 503);

            return response()->json(['refus' => 'indisponible'], 503);
        }
        $statut = $r['refus'] === 'oppose' ? 409 : ($r['refus'] !== null ? 422 : ($r['cree'] ? 201 : 200));
        $this->auditer($request, 'ajouter', $statut);

        return response()->json(['refus' => $r['refus'], 'cree' => $r['cree'], 'prospect_id' => $r['prospect_id']], $statut);
    }

    public function geste(Request $request, string $id): JsonResponse
    {
        $this->garde($request);
        $ws = $this->ws();
        $uid = $this->uid($request);
        $cle = $request->header('Idempotency-Key');
        $v = $request->validate(['geste' => ['required', 'string', 'in:' . implode(',', array_keys(EtatsProspect::GESTES))]]);
        if (! is_string($cle) || mb_strlen($cle) < 16 || mb_strlen($cle) > 128) {
            return response()->json(['message' => 'Idempotency-Key : 16 à 128 caractères.', 'errors' => ['Idempotency-Key' => ['obligatoire']]], 422);
        }
        $p = Str::isUuid($id) ? WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->where('workspace_id', $ws)->where('expediteur_user_id', $uid)->where('id', $id)->first()) : null; // un prospect d'un autre expéditeur est introuvable, comme dans la file
        if ($p === null) {
            return response()->json(['message' => 'Prospect introuvable.'], 404);
        }
        $geste = $v['geste'];

        try {
            if ($geste === 'opposition') {
                $r = (new OpposerProspect)->opposer($ws, $id, $cle, $uid);
            } else {
                if (in_array($geste, ['invitation_faite', 'message_fait'], true)) {
                    // Relecture de l'opposition ET de l'arrêt juste avant d'écrire ; toute panne ⇒ rien n'est écrit (échec fermé).
                    $refus = $this->relire($ws, $p);
                    if ($refus !== null) {
                        $this->auditer($request, 'geste', 409);

                        return response()->json(['refus' => $refus], 409);
                    }
                }
                $r = (new DepotProspects)->appliquerGeste($ws, $id, $geste, $cle, $uid);
            }
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Clé d’idempotence déjà utilisée.'], 422);
        } catch (Throwable) {
            $this->auditer($request, 'geste', 503);

            return response()->json(['refus' => 'indisponible'], 503);
        }
        $this->auditer($request, 'geste', 200);

        return response()->json($r);
    }

    public function arret(Request $request): JsonResponse
    {
        return $this->poserArret($request, 'arret');
    }

    public function reprise(Request $request): JsonResponse
    {
        return $this->poserArret($request, 'reprise');
    }

    public function plafonds(Request $request): JsonResponse
    {
        $this->garde($request);
        $v = $request->validate([
            'invitations_jour' => ['required', 'integer', 'between:0,25'],
            'invitations_semaine' => ['required', 'integer', 'between:0,100'],
            'messages_jour' => ['required', 'integer', 'between:0,40'],
            'notes_mois' => ['required', 'integer', 'between:0,5'],
            'jours_ouvres_seulement' => ['required', 'boolean'],
            'confirme' => ['sometimes', 'boolean'],
        ]);
        $defauts = (array) config('prospection_linkedin.plafonds', []);
        $audessus = false;
        foreach (['invitations_jour', 'invitations_semaine', 'messages_jour', 'notes_mois'] as $k) {
            $audessus = $audessus || $v[$k] > (int) ($defauts[$k] ?? 0);
        }
        $audessus = $audessus || (! $v['jours_ouvres_seulement'] && (bool) ($defauts['jours_ouvres_seulement'] ?? true));
        if ($audessus && ($v['confirme'] ?? false) !== true) {
            $this->auditer($request, 'plafonds', 422);

            return response()->json(['refus' => 'confirmation_requise', 'message' => 'Au-dessus des plafonds prudents : confirme=true requis.'], 422);
        }
        $ws = $this->ws();
        $uid = $this->uid($request);
        try {
            WorkspaceContext::run($ws, static fn () => DB::table('pl_plafonds')->insert([
                'workspace_id' => $ws, 'expediteur_user_id' => $uid, 'invitations_jour' => $v['invitations_jour'], 'invitations_semaine' => $v['invitations_semaine'],
                'messages_jour' => $v['messages_jour'], 'notes_mois' => $v['notes_mois'], 'jours_ouvres_seulement' => $v['jours_ouvres_seulement'], 'auteur' => $uid,
            ]));
            (new JournalProspection)->ecrire($ws, null, 'plafonds', 'humain', $uid, 'plafonds-' . Str::uuid());
        } catch (Throwable) {
            $this->auditer($request, 'plafonds', 503);

            return response()->json(['refus' => 'indisponible'], 503);
        }
        $this->auditer($request, 'plafonds', 200);

        return response()->json(['plafonds' => array_diff_key($v, ['confirme' => 1])]);
    }

    private function poserArret(Request $request, string $geste): JsonResponse
    {
        $this->garde($request);
        $v = $request->validate(['motif' => ['required', 'string', 'min:10', 'max:300', 'not_regex:/@/']]);
        $ws = $this->ws();
        $uid = $this->uid($request);
        try {
            DB::transaction(static function () use ($ws, $uid, $geste, $v): void {
                WorkspaceContext::run($ws, static fn () => DB::table('pl_arret')->insert([
                    'workspace_id' => $ws, 'expediteur_user_id' => $uid, 'geste' => $geste, 'motif' => $v['motif'], 'auteur' => $uid, 'declencheur' => 'humain',
                ]));
                (new JournalProspection)->ecrire($ws, null, $geste, 'humain', $uid, $geste . '-' . Str::uuid(), ['motif' => $v['motif']]);
            });
        } catch (Throwable) {
            $this->auditer($request, $geste, 503);

            return response()->json(['refus' => 'indisponible'], 503);
        }
        $this->auditer($request, $geste, 200);

        return response()->json(['arret' => $geste === 'arret']);
    }

    /** @return ?string `oppose` | `arret` | null (rien à signaler) ; lève si la lecture est impossible */
    private function relire(string $ws, \stdClass $p): ?string
    {
        $opposes = (new ControlesProspect)->opposes($ws, [$p]);
        if ($opposes !== []) {
            (new ControlesProspect)->ecarter($ws, (string) $p->id);
            $this->arretAutomatique($ws, $p);

            return 'oppose';
        }

        return (new Arret)->actif($ws, (string) $p->expediteur_user_id) ? 'arret' : null;
    }

    /** Un opposé présent dans la file : alerte et arrêt automatique de l'expéditeur (déclencheur `oppose_dans_file`). */
    private function arretAutomatique(string $ws, \stdClass $p): void
    {
        $motif = 'Personne opposée présente dans la file : arrêt automatique.';
        DB::transaction(static function () use ($ws, $p, $motif): void {
            WorkspaceContext::run($ws, static fn () => DB::table('pl_arret')->insert([
                'workspace_id' => $ws, 'expediteur_user_id' => $p->expediteur_user_id, 'geste' => 'arret', 'motif' => $motif, 'auteur' => 'systeme', 'declencheur' => 'oppose_dans_file',
            ]));
            (new JournalProspection)->ecrire($ws, (string) $p->id, 'alerte', 'systeme', 'file_du_jour', 'alerte-oppose-' . Str::uuid(), ['motif' => 'oppose_dans_file']);
        });
    }

    private function texte(bool $essai): ?string
    {
        try {
            return TextePret::composer(self::GABARIT_INVITATION, [], $essai, 'invitation');
        } catch (Throwable) {
            return null; // texte non conforme : jamais de texte dégradé
        }
    }

    private function ws(): string
    {
        $ws = WorkspaceContext::current();
        if ($ws === null || $ws === '') {
            throw new RuntimeException('Espace introuvable.');
        }

        return (string) $ws;
    }

    private function uid(Request $request): string
    {
        $uid = $request->user()?->id;

        return is_string($uid) ? $uid : 'inconnu';
    }

    private function auditer(Request $request, string $action, int $statut): void
    {
        try {
            $uid = $request->user()?->id;
            app(AuditHashChain::class)->record([
                'workspace_id' => $this->ws(),
                'user_id' => is_string($uid) ? $uid : null,
                'method' => $request->method(),
                'path' => 'api/v1/prospection-linkedin | action=' . $action,
                'status' => $statut,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'payload_hash' => hash('sha256', '[]'),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
