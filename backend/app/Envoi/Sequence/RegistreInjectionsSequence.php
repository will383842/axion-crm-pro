<?php

namespace App\Envoi\Sequence;

use App\Envoi\Imap\Verp;
use Illuminate\Support\Facades\DB;

/**
 * C1 — identifiant d'injection STABLE, choisi par le CRM avant le réseau (cahier C1, §1.4).
 *
 * Même clé `seq-<inscription>-<étape>` => même UUID, à vie (clé primaire (workspace_id, cle_idempotence) de
 * `envoi_injections_locales`, table en ajout seul : aucun UPDATE ni DELETE ici). À appeler DANS la transaction du temps A,
 * sous le contexte d'espace. Aucune exception n'est avalée : une panne SQL remonte au séquenceur (échec compté, rejeu).
 */
final class RegistreInjectionsSequence
{
    /** Adaptateurs pour lesquels le CRM choisit l'identifiant. smtp garde le sien (décision E4). */
    public const ADAPTATEURS = ['faux', 'kumo'];

    /** @var list<array{0: string, 1: array<string, mixed>}> alertes à émettre APRÈS le COMMIT (jamais dans la transaction) */
    public array $alertes = [];

    /**
     * Réserve (ou relit) l'identifiant de la clé. Rend null si le domaine est inconnu de l'espace ou sur smtp.
     * `cheminRetour` n'est posé que si `envoi.cle_empreinte` est non vide et le domaine en ASCII (sinon le VERP ne se relirait jamais).
     *
     * @return array{id: string, adaptateur: string, cheminRetour: ?string}|null
     */
    public function reserver(string $ws, string $cle, string $domaine): ?array
    {
        $adaptateur = DB::table('envoi_domaines')->where('workspace_id', $ws)->where('nom', $domaine)->value('adaptateur');
        if (! is_string($adaptateur) || ! in_array($adaptateur, self::ADAPTATEURS, true)) {
            return null;
        }
        DB::table('envoi_injections_locales')->insertOrIgnore(['workspace_id' => $ws, 'cle_idempotence' => $cle, 'adaptateur' => $adaptateur]);
        $ligne = DB::table('envoi_injections_locales')->where('workspace_id', $ws)->where('cle_idempotence', $cle)->first(['id_injection', 'adaptateur']);
        if ($ligne === null) {
            throw new \RuntimeException('Registre des injections : ligne introuvable après réservation.');
        }
        $id = (string) $ligne->id_injection;
        if ($ligne->adaptateur !== $adaptateur) {
            // Domaine basculé entre deux tentatives : l'identifiant reste le même (stabilité d'abord), alerte sans adresse.
            $this->alertes[] = ['envoi.registre_adaptateur_change : le domaine a changé d\'adaptateur depuis la réservation.', ['workspace_id' => $ws, 'cle' => $cle]];
        }
        $chemin = null;
        if ((string) config('envoi.cle_empreinte', '') !== '' && preg_match('/^[a-z0-9.-]+$/', $domaine) === 1) {
            $chemin = Verp::adressePour($id, $domaine);
        }

        return ['id' => $id, 'adaptateur' => (string) $ligne->adaptateur, 'cheminRetour' => $chemin];
    }

    /**
     * Clé `seq-<uuid>-<1..3>` => [inscription_id, étape] ; sinon null (lecture de l'ancienne forme, lot C1-2).
     *
     * @return array{0: string, 1: int}|null
     */
    public static function inscriptionDeCle(string $cle): ?array
    {
        if (preg_match('/^seq-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})-([1-3])$/', $cle, $m) !== 1) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }
}
