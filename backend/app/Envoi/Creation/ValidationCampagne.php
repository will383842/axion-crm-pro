<?php

namespace App\Envoi\Creation;

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Socle\Alertes\CanalEmail;
use App\Support\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Code de validation, validation et annulation d'une campagne (R11). « Valider » passe la campagne en « programmee » :
 * AUCUNE séquence, AUCUNE inscription, aucun envoi. Le code est un courriel transactionnel, envoyé par le canal e-mail du
 * socle à l'adresse de connexion de l'auteur — jamais par le moteur d'envoi ni par une boîte d'envoi.
 * Chaque geste prend un verrou consultatif propre à la campagne ; le code est à usage unique (UPDATE conditionnel de R08).
 */
final class ValidationCampagne
{
    public const ANNULATION_MINUTES = 5;

    public const CODE_VALIDITE_MINUTES = 10;

    public const CODES_PAR_FENETRE = 3;

    public const FENETRE_MINUTES = 15;

    public function __construct(
        private readonly DepotCampagnes $depot,
        private readonly AssistantCreation $assistant,
        private readonly CanalEmail $canal,
    ) {}

    /** @return array{envoye_a: string, expire_le: string} */
    public function demanderCode(string $ws, string $userId, string $email, string $id, ?string $cle): array
    {
        $c = $this->auteur($ws, $userId, $id);
        $this->exigerControles($ws, $c);
        $recents = WorkspaceContext::run($ws, fn () => DB::table('envoi_campagne_codes')->where('campagne_id', $id)
            ->where('cree_le', '>', DB::raw("now() - interval '" . self::FENETRE_MINUTES . " minutes'"))->count());
        if ($recents >= self::CODES_PAR_FENETRE) {
            throw new ErreurCreation('code_trop_frequent', 'Trois codes ont déjà été envoyés ces 15 dernières minutes : patientez avant d\'en demander un autre.', 429);
        }
        $this->avecVerrou($ws, $id, function () use ($ws, $userId, $id, $cle): void {
            $c = $this->assistant->lire($ws, $id);
            if ($c->statut === 'brouillon') {
                $this->transitionner($ws, $id, 'a_valider', (int) $c->version, $userId, 'Code de validation demandé', $cle ?? "code:{$id}:{$c->version}");
            } elseif ($c->statut !== 'a_valider') {
                throw ErreurCreation::conflit('campagne_non_validable', 'Cette campagne est déjà validée ou terminée.');
            }
        });
        $code = sprintf('%06d', random_int(0, 999999));
        try {
            $ligne = $this->depot->emettreCode($ws, $id, $code, self::CODE_VALIDITE_MINUTES);
        } catch (CampagneRefusee $e) {
            throw new ErreurCreation($e->getCode() === 503 ? 'codes_indisponibles' : 'validation', $e->getMessage(), $e->getCode());
        }
        $envoye = $this->canal->envoyerA($email, 'Votre code de validation de campagne', "Votre code de validation : {$code}\nIl est valable " . self::CODE_VALIDITE_MINUTES . ' minutes et ne sert qu\'une fois. Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message.');
        if (! $envoye) {
            throw new ErreurCreation('courriel_indisponible', 'Le courriel du code n\'a pas pu partir : réessayez dans quelques minutes.', 503);
        }
        $expire = WorkspaceContext::run($ws, fn () => DB::table('envoi_campagne_codes')->where('id', $ligne)->value('expire_le'));

        return ['envoye_a' => $this->masquer($email), 'expire_le' => Carbon::parse($expire)->toIso8601String()];
    }

    /** @return array{campagne: array<string, mixed>, annulable_jusqua: string} */
    public function valider(string $ws, string $userId, string $id, string $code, ?string $cle): array
    {
        $this->auteur($ws, $userId, $id);
        // Le verdict est calculé DANS la transaction, rendu APRÈS le COMMIT : un refus ne doit pas défaire le compte des tentatives.
        $verdict = $this->avecVerrou($ws, $id, function () use ($ws, $userId, $id, $code, $cle): string {
            $c = $this->assistant->lire($ws, $id);
            if ($c->statut !== 'a_valider') {
                return 'statut';
            }
            if ($this->assistant->controles($ws, $c)['bloquants'] > 0) {
                return 'controles';
            }
            if (! $this->depot->consommerCode($ws, $id, $code)) {
                return 'code';
            }
            $this->transitionner($ws, $id, 'programmee', (int) $c->version, $userId, 'Validée par code e-mail', $cle ?? "valider:{$id}:{$c->version}");

            return 'ok';
        });
        match ($verdict) {
            'statut' => throw ErreurCreation::conflit('campagne_non_a_valider', 'Demandez d\'abord un code : cette campagne n\'attend pas de validation.'),
            'controles' => throw ErreurCreation::invalide('controles_bloquants', 'Des contrôles bloquants empêchent la validation.'),
            'code' => throw ErreurCreation::invalide('code_invalide', 'Code invalide, expiré, déjà utilisé ou trop d\'essais : demandez un nouveau code.'),
            default => null,
        };
        $c = $this->assistant->lire($ws, $id);

        return ['campagne' => $this->resume($c), 'annulable_jusqua' => (string) $this->assistant->finAnnulation($c)?->toIso8601String()];
    }

    /** @return array<string, mixed> le brouillon */
    public function annuler(string $ws, string $userId, string $id, ?string $cle): array
    {
        $this->auteur($ws, $userId, $id);
        $this->avecVerrou($ws, $id, function () use ($ws, $userId, $id, $cle): void {
            $c = $this->assistant->lire($ws, $id);
            if ($c->statut === 'programmee') {
                if ($this->assistant->finAnnulation($c)?->isPast() !== false) {
                    throw ErreurCreation::conflit('delai_annulation_depasse', 'Le délai de 5 minutes pour annuler la validation est dépassé.');
                }
                $this->transitionner($ws, $id, 'a_valider', (int) $c->version, $userId, 'Validation annulée', ($cle ?? "annuler:{$id}:{$c->version}") . ':1');
                $c = $this->assistant->lire($ws, $id);
            }
            if ($c->statut !== 'a_valider') {
                throw ErreurCreation::conflit('annulation_impossible', 'Cette campagne n\'est pas en attente de validation.');
            }
            $this->transitionner($ws, $id, 'brouillon', (int) $c->version, $userId, 'Retour au brouillon', ($cle ?? "annuler:{$id}:{$c->version}") . ':2');
        });

        return $this->assistant->presenter($this->assistant->lire($ws, $id));
    }

    /** Seul l'auteur demande, valide ou annule : le code part à SON adresse. */
    private function auteur(string $ws, string $userId, string $id): \stdClass
    {
        $c = $this->assistant->lire($ws, $id);
        if ($c->cree_par !== $userId) {
            throw ErreurCreation::interdit('Seul l\'auteur de la campagne peut demander le code, valider ou annuler.');
        }

        return $c;
    }

    private function exigerControles(string $ws, \stdClass $c): void
    {
        if ($this->assistant->controles($ws, $c)['bloquants'] > 0) {
            throw ErreurCreation::invalide('controles_bloquants', 'Des contrôles bloquants empêchent la validation : corrigez-les avant de demander un code.');
        }
    }

    private function transitionner(string $ws, string $id, string $vers, int $version, string $auteur, string $motif, string $cle): void
    {
        try {
            $this->depot->transitionner($ws, $id, $vers, $version, $auteur, $motif, substr($cle, 0, 100));
        } catch (CampagneRefusee $e) {
            throw new ErreurCreation($e->getCode() === 409 ? 'conflit_version' : 'validation', $e->getMessage(), $e->getCode());
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $f
     * @return T
     */
    private function avecVerrou(string $ws, string $id, \Closure $f): mixed
    {
        return WorkspaceContext::run($ws, fn () => DB::transaction(function () use ($id, $f) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_campagne:' . $id]);

            return $f();
        }));
    }

    private function masquer(string $email): string
    {
        [$local, $domaine] = explode('@', $email, 2) + ['', ''];

        return mb_substr($local, 0, 1) . '***@' . $domaine;
    }

    /**
     * Forme `CampagneResume` du contrat.
     *
     * @return array<string, mixed>
     */
    private function resume(\stdClass $c): array
    {
        $saisie = json_decode($c->saisie, true) ?: [];
        $cible = json_decode($c->cible, true);

        return [
            'id' => $c->id, 'nom' => $c->nom, 'statut' => $c->statut,
            'cible' => $cible + ['total' => $this->assistant->compter($c->workspace_id, $cible['type'], $cible['reference'])],
            'envoyes' => 0, 'total' => 0, 'reponses' => 0, 'reponses_interessees' => 0,
            'ab_objet' => ! empty($saisie['contenu']['sequence'][0]['objet_b']), 'prochain_envoi_le' => null,
            'cree_le' => Carbon::parse($c->cree_le)->toIso8601String(),
            'boites' => $c->boites === '{}' ? [] : explode(',', trim($c->boites, '{}')), 'etapes' => (int) $c->nb_etapes,
        ];
    }
}
