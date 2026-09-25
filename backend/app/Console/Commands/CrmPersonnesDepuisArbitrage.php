<?php

namespace App\Console\Commands;

use App\Crm\Taxonomy;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RATTRAPAGE de la file d'arbitrage vers les PERSONNES (lot L4-C).
 *
 * Avant ce lot, une inscription à la lettre sans SIREN finissait dans
 * `activities` avec un bloc `payload.pending_match` qui porte l'adresse EN
 * CLAIR, et la file d'arbitrage proposait de « rattacher » l'événement à une
 * entreprise. Cette commande rattache chacune de ces activités à la PERSONNE
 * de même `person_key`, passe `subject_type` à `personne` et RETIRE le bloc
 * `pending_match` — l'adresse disparaît du JSON.
 *
 * ── Ce qu'elle ne fait PAS ─────────────────────────────────────────────────
 *
 *   - elle ne CRÉE aucune personne depuis le JSON : c'est le rattrapage du
 *     SITE (événements rejoués, `event_id` déterministes) qui fait foi. Une
 *     activité sans personne correspondante est comptée et laissée en place ;
 *   - elle ne touche pas aux autres types (rendez-vous, formulaires) : ils
 *     restent dans l'arbitrage tant que Will n'a pas décidé autrement. La liste
 *     `--kinds` est bornée à `Taxonomy::PERSONNES_EVENT_TYPES` ;
 *   - elle n'imprime JAMAIS une adresse, ni en sortie ni au journal : des
 *     identifiants et des comptes seulement (dépôt public, journaux collectés).
 *
 * ── À blanc par défaut ─────────────────────────────────────────────────────
 *
 * Sans `--appliquer`, rien n'est écrit (`--a-blanc` le dit explicitement).
 * AVANT la vraie exécution : exporter les lignes visées dans la sauvegarde
 * chiffrée du serveur (hors dépôt), vérifier le compte exporté, puis seulement
 * appliquer. Le retrait du bloc `pending_match` est définitif.
 */
class CrmPersonnesDepuisArbitrage extends Command
{
    public const SIGNATURE = 'crm:personnes-depuis-arbitrage';

    protected $signature = self::SIGNATURE
        . ' {--kinds=newsletter_optin : Types d\'activité visés, séparés par des virgules (lettre et guide seulement)}'
        . ' {--a-blanc : Compte sans rien écrire (comportement par défaut)}'
        . ' {--appliquer : Écrit réellement — après sauvegarde des lignes visées}';

    protected $description = 'Rattache aux personnes les activités lettre/guide restées dans la file d arbitrage (à blanc par défaut)';

    public function handle(): int
    {
        if ((bool) $this->option('a-blanc') && (bool) $this->option('appliquer')) {
            $this->error('--a-blanc et --appliquer sont contradictoires : choisir l\'un des deux.');

            return self::FAILURE;
        }

        $appliquer = (bool) $this->option('appliquer');

        $kinds = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('kinds')))));
        $horsPerimetre = array_values(array_diff($kinds, Taxonomy::PERSONNES_EVENT_TYPES));
        if ($kinds === [] || $horsPerimetre !== []) {
            $this->error('Types hors périmètre (lettre et guide seulement : ' . implode(', ', Taxonomy::PERSONNES_EVENT_TYPES) . ') : ' . implode(', ', $horsPerimetre));

            return self::FAILURE;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error("Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $workspaceId = (string) $workspaceId;

        $bilan = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $kinds, $appliquer): array {
            $lignes = DB::table('activities')
                ->where('workspace_id', $workspaceId)
                ->whereNull('subject_id')
                ->whereIn('kind', $kinds)
                ->whereRaw("payload -> 'pending_match' IS NOT NULL")
                ->whereRaw("payload -> 'arbitrage_dismissed' IS NULL")
                ->orderBy('id')
                ->get(['id', 'person_key', 'payload']);

            $bilan = ['visees' => $lignes->count(), 'rattachables' => 0, 'sans_personne' => 0, 'sans_cle' => 0, 'rattachees' => 0];

            foreach ($lignes as $ligne) {
                $cle = is_string($ligne->person_key) ? $ligne->person_key : null;
                if ($cle === null) {
                    $bilan['sans_cle']++;

                    continue;
                }

                $personneId = DB::table('personnes')
                    ->where('workspace_id', $workspaceId)
                    ->where('person_key', $cle)
                    ->value('id');

                if ($personneId === null) {
                    $bilan['sans_personne']++;

                    continue;
                }

                $bilan['rattachables']++;

                if (! $appliquer) {
                    continue;
                }

                $payload = json_decode(is_string($ligne->payload) ? $ligne->payload : '{}', true);
                $payload = is_array($payload) ? $payload : [];
                unset($payload['pending_match']);
                $payload['rattrapage_personnes'] = [
                    'at' => now()->toIso8601String(),
                    'commande' => self::SIGNATURE,
                ];

                $mises = DB::table('activities')
                    ->where('id', $ligne->id)
                    ->whereNull('subject_id')
                    ->update([
                        'subject_type' => 'personne',
                        'subject_id' => (int) $personneId,
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    ]);

                $bilan['rattachees'] += $mises;
            }

            return $bilan;
        });

        $this->line(($appliquer ? 'APPLIQUÉ' : 'À BLANC — rien n\'a été écrit') . ' · types : ' . implode(', ', $kinds));
        $this->line('Activités visées (sans sujet, en attente de rapprochement) : ' . $bilan['visees']);
        $this->line('  dont rattachables à une personne existante : ' . $bilan['rattachables']);
        $this->line('  dont sans personne correspondante (rattrapage du site à jouer d\'abord) : ' . $bilan['sans_personne']);
        $this->line('  dont sans clé de personne : ' . $bilan['sans_cle']);
        if ($appliquer) {
            $this->line('Activités rattachées, bloc pending_match retiré : ' . $bilan['rattachees']);
        }

        return self::SUCCESS;
    }
}
