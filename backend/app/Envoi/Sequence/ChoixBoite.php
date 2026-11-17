<?php

namespace App\Envoi\Sequence;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * X11-B — la boîte d'expédition d'une inscription. Posée à la première étape (rotation : la moins employée du jour), puis FIGÉE
 * (`envoi_inscriptions.boite_id`) : un fil ne change jamais d'expéditeur. Boîte figée plus active ⇒ ATTENTE (report sans compteur),
 * jamais une autre boîte. Même domaine que `ParametresExpedition::domaine` (rotation de domaines : hors lot). Échec fermé.
 */
final class ChoixBoite
{
    public function __construct(private readonly DepotChauffe $depot, private readonly SourceExpeditionDb $source) {}

    /**
     * @return array{id: string, adresse: string}
     *
     * @throws EnvoiRefuse code ATTENTE : aucune boîte utilisable
     */
    public function pour(string $ws, \stdClass $inscription, string $domaine, DateTimeImmutable $jour): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $inscription, $domaine, $jour): array {
            $figee = $inscription->boite_id ?? null;
            $actives = $this->source->boitesActivesDuDomaine($ws, $domaine);
            if ($figee !== null) {
                foreach ($actives as $b) {
                    if ($b['id'] === (string) $figee) {
                        return $b;
                    }
                }
                throw new EnvoiRefuse('Boîte du fil indisponible (pause ou retirée) : le fil garde son expéditeur, report.', EnvoiRefuse::ATTENTE);
            }

            $permises = $this->boitesDeLaCampagne($ws, (string) $inscription->sequence_id);
            if ($permises !== null) {
                $actives = array_values(array_filter($actives, fn (array $b): bool => in_array($b['id'], $permises, true)));
            }
            if ($actives === []) {
                throw new EnvoiRefuse('Aucune boîte active pour ce domaine : report.', EnvoiRefuse::ATTENTE);
            }
            $envoyes = [];
            foreach ($actives as $b) {
                $envoyes[$b['id']] = $this->depot->envoyesBoite($ws, $b['id'], $jour); // une requête par boîte, pas par comparaison
            }
            usort($actives, fn (array $a, array $b): int => [$envoyes[$a['id']], $a['cree_le'], $a['id']] <=> [$envoyes[$b['id']], $b['cree_le'], $b['id']]);
            $choix = $actives[0];

            // Deux ouvriers : un seul pose ; l'autre relit la boîte gagnante (et la valide à son tour).
            $posees = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $inscription->id)
                ->whereNull('boite_id')->update(['boite_id' => $choix['id']]);
            if ($posees === 1) {
                return $choix;
            }
            $relue = (string) DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $inscription->id)->value('boite_id');
            foreach ($actives as $b) {
                if ($b['id'] === $relue) {
                    return $b;
                }
            }
            throw new EnvoiRefuse('Boîte du fil indisponible : report.', EnvoiRefuse::ATTENTE);
        });
    }

    /** @return list<string>|null null = la campagne n'en liste aucune (ou séquence libre) : aucune restriction */
    private function boitesDeLaCampagne(string $ws, string $sequenceId): ?array
    {
        $liste = DB::table('envoi_sequences as s')->join('envoi_campagnes as c', fn ($j) => $j->on('c.id', '=', 's.campagne_id')->on('c.workspace_id', '=', 's.workspace_id'))
            ->where('s.workspace_id', $ws)->where('s.id', $sequenceId)->value('c.boites');
        if (! is_string($liste)) {
            return null;
        }
        $ids = array_values(array_filter(array_map('trim', explode(',', trim($liste, '{}')))));

        return $ids === [] ? null : $ids;
    }
}
