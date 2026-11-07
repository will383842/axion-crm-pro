<?php

namespace App\Envoi\Sequence;

use App\Support\ListeSuppression;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Inscription (version FIGÉE du message) et arrêts. N'efface jamais rien : une inscription arrêtée reste en base. */
final class Inscriptions
{
    public function __construct(private readonly LecteurFiche $fiches) {}

    /** @return string identifiant de l'inscription (existante si déjà inscrite) */
    public function inscrire(string $ws, string $sequenceId, int $contactId, DateTimeImmutable $maintenant): string
    {
        $seq = DB::table('envoi_sequences')->where('workspace_id', $ws)->where('id', $sequenceId)->first();
        if ($seq === null) {
            throw new InvalidArgumentException('Séquence inconnue dans cet espace.');
        }
        $etapes = DB::table('envoi_sequence_etapes')->where('workspace_id', $ws)->where('sequence_id', $sequenceId)
            ->orderBy('rang')->get(['rang', 'delai_jours_ouvres', 'sujet', 'corps']);
        $n = $etapes->count();
        if ($n < 1 || $n > 3) {
            throw new InvalidArgumentException('Une séquence compte de 1 à 3 étapes.');
        }
        $fiche = $this->fiches->lire($ws, $contactId) ?? throw new InvalidArgumentException('Fiche introuvable ou sans adresse.');

        $figee = [
            'fenetre' => ['debut' => (int) $seq->fenetre_debut, 'fin' => (int) $seq->fenetre_fin],
            'etapes' => $etapes->map(fn ($e): array => [
                'rang' => (int) $e->rang, 'delai_jours_ouvres' => (int) $e->delai_jours_ouvres,
                'sujet' => (string) $e->sujet, 'corps' => (string) $e->corps,
            ])->all(),
        ];
        $fenetre = new FenetreHoraire((int) $seq->fenetre_debut, (int) $seq->fenetre_fin);
        $premier = $figee['etapes'][0]['delai_jours_ouvres'];

        DB::table('envoi_inscriptions')->insertOrIgnore([
            'workspace_id' => $ws, 'sequence_id' => $sequenceId, 'contact_id' => $contactId,
            'entreprise_id' => $fiche->entrepriseId,
            'empreinte_adresse' => ListeSuppression::empreinte($fiche->email),
            'prochaine_echeance' => $fenetre->echeance($maintenant, $premier)->format('c'),
            'version_figee' => json_encode($figee, JSON_THROW_ON_ERROR),
        ]);

        return (string) DB::table('envoi_inscriptions')->where('workspace_id', $ws)
            ->where('sequence_id', $sequenceId)->where('contact_id', $contactId)->value('id');
    }

    /** Arrêt de l'inscription (+ de toute l'adresse pour stop/opposition/rebond dur, + de toute l'entreprise pour réponse/opposition). Retourne le nombre d'inscriptions arrêtées. */
    public function evenement(string $ws, string $inscriptionId, string $evenement, DateTimeImmutable $maintenant): int
    {
        if (! in_array($evenement, Evenement::TOUS, true)) {
            throw new InvalidArgumentException("Événement inconnu : {$evenement}.");
        }

        return DB::transaction(function () use ($ws, $inscriptionId, $evenement, $maintenant): int {
            $ins = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $inscriptionId)->lockForUpdate()->first();
            if ($ins === null) {
                return 0;
            }
            $n = $this->arreter($ws, [$inscriptionId], $evenement, $maintenant);
            if (in_array($evenement, Evenement::VALENT_POUR_L_ADRESSE, true)) {
                // Ajout seulement : les autres inscriptions de la MÊME adresse passent « arretee » (rien n'est effacé).
                $memeAdresse = array_values(DB::table('envoi_inscriptions')->where('workspace_id', $ws)
                    ->where('empreinte_adresse', $ins->empreinte_adresse)->where('statut', 'active')
                    ->where('id', '<>', $inscriptionId)->lockForUpdate()->pluck('id')->map(fn ($v): string => (string) $v)->all());
                $n += $this->arreter($ws, $memeAdresse, Evenement::MOTIF_ADRESSE, $maintenant);
            }
            if (in_array($evenement, Evenement::PROPAGENT_A_L_ENTREPRISE, true)) {
                $autres = array_values(DB::table('envoi_inscriptions')->where('workspace_id', $ws)
                    ->where('entreprise_id', $ins->entreprise_id)->where('statut', 'active')
                    ->where('id', '<>', $inscriptionId)->lockForUpdate()->pluck('id')->map(fn ($v): string => (string) $v)->all());
                $n += $this->arreter($ws, $autres, Evenement::MOTIF_ENTREPRISE, $maintenant);
            }

            return $n;
        });
    }

    /** @param  list<string>  $ids */
    private function arreter(string $ws, array $ids, string $motif, DateTimeImmutable $maintenant): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('envoi_inscriptions')->where('workspace_id', $ws)->whereIn('id', $ids)->where('statut', 'active')
            ->update(['statut' => 'arretee', 'motif_arret' => $motif, 'arretee_le' => $maintenant->format('c')]);
    }
}
