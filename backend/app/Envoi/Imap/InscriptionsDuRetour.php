<?php

namespace App\Envoi\Imap;

use App\Envoi\Sequence\RegistreInjectionsSequence;
use Illuminate\Support\Facades\DB;

/** Inscriptions rattachées à l'injection d'un retour (exact : injection -> messages -> inscriptions). Aucune adresse lue. */
final class InscriptionsDuRetour
{
    /**
     * C1-2 — lecture des DEUX formes d'identifiant pendant la transition (cahier C1, §1.5). Un identifiant rattache les
     * messages où : (1) `id_injection` le porte (nouvelle forme, ou VERP Kumo ancien) ; (2) `id_injection_serveur` le porte ;
     * (3) s'il s'agit d'un UUID du registre local dont la clé est `seq-<inscription>-<étape>` : le message de cette
     * inscription et de cette étape (ancienne forme : le serveur seul était écrit dans `envoi_messages`).
     * Branches 2 et 3 à retirer dans un lot ultérieur, pas ici. Filtre d'espace explicite (la RLS est contournée en propriétaire).
     *
     * @return list<string>
     */
    public function pour(string $workspaceId, string $id): array
    {
        $cle = null;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1) {
            $nomCle = DB::table('envoi_injections_locales')->where('workspace_id', $workspaceId)->where('id_injection', strtolower($id))->value('cle_idempotence');
            $cle = is_string($nomCle) ? RegistreInjectionsSequence::inscriptionDeCle($nomCle) : null;
        }

        $ids = DB::table('envoi_messages')->where('workspace_id', $workspaceId)
            ->where(function ($q) use ($id, $cle): void {
                $q->where('id_injection', $id)->orWhere('id_injection_serveur', $id);
                if ($cle !== null) {
                    $q->orWhere(fn ($r) => $r->where('inscription_id', $cle[0])->where('etape', $cle[1]));
                }
            })
            ->orderBy('inscription_id')->distinct()->pluck('inscription_id')->map(fn ($v): string => (string) $v)->all();

        return array_values($ids);
    }
}
