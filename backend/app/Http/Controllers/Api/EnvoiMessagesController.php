<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Exceptions\ModeleRefuse;
use App\Envoi\Message\MessageRefuse;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Création, partie 1 (lot R10) : messages types et outils de contenu (contrat backend/openapi/envoi/creation.yaml).
 * Lecture : `users.manage` ; écriture : `workspaces.manage` (voir routes/envoi/creation.php).
 * AUCUNE route ne fait partir un message : `test` répond 409 `envoi_ferme_palier_0`, `suggestion` 501 (décision du
 * propriétaire : coût et données confiées à un tiers). Ce contrôleur ne résout jamais `ServeurEnvoi`.
 */
class EnvoiMessagesController extends ApiController
{
    public function __construct(private readonly ServiceMessagesTypes $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->dansEspace(function (string $ws) use ($request) {
            $q = $request->query('q');

            return response()->json(['data' => $this->service->lister($ws, is_string($q) ? $q : null, $request->boolean('archives')), 'blocs' => []]);
        });
    }

    public function show(string $id): JsonResponse
    {
        return $this->dansEspace(fn (string $ws) => response()->json($this->service->lire($ws, $id)));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->ecrire($request, 'creation', fn (string $ws, array $s, string $par) => response()->json($this->service->creer($ws, $s['nom'], $s['objet'], $s['corps'], $par), 201));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->ecrire($request, 'version', fn (string $ws, array $s, string $par) => response()->json($this->service->enregistrer($ws, $id, $s['objet'], $s['corps'], $par)), $id);
    }

    public function restaurer(Request $request, string $id, int $numero): JsonResponse
    {
        return $this->dansEspace(function (string $ws) use ($request, $id, $numero) {
            $this->journaliser('restauration', $request, $id);

            return response()->json($this->service->restaurer($ws, $id, $numero, $this->auteur($request)));
        });
    }

    public function valider(Request $request, string $id, int $numero): JsonResponse
    {
        return $this->dansEspace(function (string $ws) use ($request, $id, $numero) {
            $this->journaliser('validation', $request, $id);

            return response()->json($this->service->valider($ws, $id, $numero));
        });
    }

    public function archivage(Request $request, string $id): JsonResponse
    {
        $corps = $request->json()->all();
        if (! is_bool($corps['archive'] ?? null)) {
            return $this->erreur('validation', 'Indiquez « archive » : vrai ou faux.', 422);
        }
        if ($corps['archive'] === false) {
            return $this->erreur('transition_interdite', 'Un message archivé ne se désarchive pas : créez un nouveau message à partir de son contenu.', 409);
        }

        return $this->dansEspace(function (string $ws) use ($request, $id) {
            $this->journaliser('archivage', $request, $id);

            return response()->json($this->service->archiver($ws, $id));
        });
    }

    public function controler(Request $request): JsonResponse
    {
        $s = $this->saisie($request, false);

        return $s instanceof JsonResponse ? $s : response()->json($this->service->controler($s['objet'], $s['corps'], $s['bloc_information']));
    }

    public function apercu(Request $request): JsonResponse
    {
        $corps = $request->json()->all();
        if (! is_string($corps['objet'] ?? null) || ! is_string($corps['corps'] ?? null)) {
            return $this->erreur('validation', 'L’objet et le texte sont obligatoires.', 422);
        }

        // Rendu seul : ni écriture, ni signature, ni envoi. Le destinataire exemple n'est pas lu (valeurs de repli).
        return $this->dansEspace(fn () => response()->json($this->service->apercu($corps['objet'], $corps['corps'])));
    }

    public function test(): JsonResponse
    {
        return $this->erreur('envoi_ferme_palier_0', 'L’envoi de test est fermé : aucun message ne part tant que l’envoi réel n’est pas ouvert. Utilisez l’aperçu pour relire le message.', 409);
    }

    public function suggestion(): JsonResponse
    {
        return $this->erreur('non_disponible', 'La suggestion par intelligence artificielle n’est pas ouverte : elle a un coût et confierait des données à un tiers. Rédigez la séquence vous-même.', 501);
    }

    public function contexte(): JsonResponse
    {
        return response()->json(['maintenant' => now()->utc()->toIso8601String(), 'fuseau' => (string) config('app.timezone')]);
    }

    /** Champs de fusion de la séquence : `manquants` vaut 0 (la cible n'est pas parcourue à ce palier). */
    public function champs(Request $request): JsonResponse
    {
        $corps = $request->json()->all();
        $etapes = $corps['sequence'] ?? null;
        $repli = $corps['repli'] ?? [];
        if (! is_array($corps['cible'] ?? null) || ! is_array($etapes) || $etapes === [] || count($etapes) > 4 || ! is_array($repli)) {
            return $this->erreur('validation', 'La cible et une séquence de 1 à 4 messages sont obligatoires.', 422);
        }

        return $this->dansEspace(function (string $ws) use ($etapes, $repli) {
            $textes = [];
            $replis = [];
            foreach ($etapes as $e) {
                $id = is_array($e) ? ($e['message_id'] ?? null) : null;
                if (! is_string($id)) {
                    return $this->erreur('validation', 'Chaque étape désigne un message type.', 422);
                }
                $m = $this->service->lire($ws, $id);
                $v = ServiceMessagesTypes::derniere($m);
                array_push($textes, $v['objet'], $v['corps']);
            }
            preg_match_all('/\{\{(.*?)\}\}/s', implode("\n", $textes), $trouves);
            foreach ($trouves[1] as $i) {
                [$nom, $r] = array_pad(explode('|', $i, 2), 2, '');
                $replis[trim($nom)] ??= trim($r) === '' ? null : trim($r);
            }
            $champs = array_map(static function (string $nom) use ($replis, $repli): array {
                $r = isset($repli[$nom]) && is_string($repli[$nom]) && trim($repli[$nom]) !== '' ? trim($repli[$nom]) : ($replis[$nom] ?? null);

                return ['nom' => $nom, 'obligatoire' => false, 'manquants' => 0, 'repli' => $r, 'bloquant' => false];
            }, ServiceMessagesTypes::champs(...$textes));

            return response()->json(['champs' => $champs, 'bloquants' => 0]);
        });
    }

    /** @param Closure(string, array{nom:string,objet:string,corps:string,bloc_information:bool}, string): JsonResponse $f */
    private function ecrire(Request $request, string $geste, Closure $f, ?string $id = null): JsonResponse
    {
        $s = $this->saisie($request, true);
        if ($s instanceof JsonResponse) {
            return $s;
        }

        return $this->dansEspace(function (string $ws) use ($request, $geste, $f, $s, $id) {
            $this->journaliser($geste, $request, $id);

            return $f($ws, $s, $this->auteur($request));
        });
    }

    /** @return array{nom:string,objet:string,corps:string,bloc_information:bool}|JsonResponse */
    private function saisie(Request $request, bool $ecriture): array|JsonResponse
    {
        $d = $request->json()->all();
        $v = Validator::make($d, ['nom' => ['required', 'string', 'max:200'], 'objet' => ['required', 'string', 'max:300'], 'corps' => ['required', 'string', 'max:20000'],
            'objet_b' => ['nullable'], 'bloc_information' => ['sometimes', 'boolean']]);
        if ($v->fails()) {
            return $this->erreur('validation', 'Le nom, l’objet et le texte sont obligatoires (objet 300, texte 20 000 caractères au plus).', 422);
        }
        if (($d['objet_b'] ?? null) !== null) {
            return $this->erreur('validation', 'L’objet B (test A/B) n’est pas encore disponible : un seul objet par message.', 422);
        }
        $bloc = $d['bloc_information'] ?? true;
        if ($ecriture && $bloc !== true) {
            return $this->erreur('validation', 'Le bloc d’information et le lien de désinscription sont obligatoires dans chaque message.', 422);
        }

        return ['nom' => $d['nom'], 'objet' => $d['objet'], 'corps' => $d['corps'], 'bloc_information' => (bool) $bloc];
    }

    /** @param Closure(string): JsonResponse $f */
    private function dansEspace(Closure $f): JsonResponse
    {
        $ws = (string) WorkspaceContext::current();
        if ($ws === '') {
            return $this->erreur('interdit', 'Aucun espace de travail n’est sélectionné pour votre compte.', 403);
        }
        try {
            return $f($ws);
        } catch (ModeleRefuse $e) {
            return $this->erreur(match ($e->getCode()) {
                404 => 'non_trouve', 409 => 'conflit', default => 'validation'
            }, $e->getMessage(), (int) $e->getCode());
        } catch (MessageRefuse $e) {
            return response()->json(['error' => 'contenu_refuse', 'message' => 'Le contenu est refusé : corrigez les points ci-dessous.', 'details' => ['contenu' => $e->motifs]], 422);
        }
    }

    private function auteur(Request $request): string
    {
        return Str::limit(trim((string) $request->user()?->name) ?: 'compte', 100, '');
    }

    /** Geste tracé sans contenu (ni objet, ni texte) : qui, quoi, quel message. */
    private function journaliser(string $geste, Request $request, ?string $id): void
    {
        Log::info('envoi.messages_types', ['geste' => $geste, 'compte' => $request->user()?->getKey(), 'modele' => $id]);
    }

    private function erreur(string $code, string $message, int $statut): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $statut);
    }
}
