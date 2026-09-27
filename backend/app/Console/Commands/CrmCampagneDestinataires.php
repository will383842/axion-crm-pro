<?php

namespace App\Console\Commands;

use App\Crm\Campagnes\Segments;
use App\Crm\Personnes\NatureEmail;
use App\Support\EligibiliteCampagne;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * La LISTE DES DESTINATAIRES d'une campagne, prête à donner à l'outil d'envoi.
 *
 * Le CRM ne fait QUE préparer : il n'envoie rien, ne contacte aucun service,
 * n'écrit rien en base. Il produit un fichier JSONL (hors dépôt) dont chaque
 * ligne est UNE adresse autorisée :
 *
 *  - adresse valide : ni `email_status` invalid/disposable ;
 *  - aucune opposition ni suppression (`EligibiliteCampagne::peutRecevoir`,
 *    portée business — la porte imposée par la garde B15-009) ;
 *  - jamais une adresse grand public (gmail…) : on n'écrit à une personne sur
 *    son adresse privée qu'à la main, au sujet de son rôle — jamais dans une
 *    campagne (décision D3, plan d'envoi §10) ;
 *  - UNE ligne par adresse : une boîte partagée par plusieurs organisateurs
 *    (même CCI sous plusieurs noms) ne reçoit qu'un message, qui les cite ;
 *  - l'événement à venir le plus proche de l'organisateur, pour personnaliser.
 *
 * Chaque ligne porte un `crm_ref` stable que l'outil renverra dans ses retours
 * (`crm:campagne:retours`).
 */
class CrmCampagneDestinataires extends Command
{
    protected $signature = 'crm:campagne:destinataires
                            {segment : Segment visé (liste fermée, cf. App\Crm\Campagnes\Segments)}
                            {sortie : Fichier JSONL à écrire (hors dépôt)}
                            {--non-informes : Seulement les adresses dont la fiche n\'a jamais reçu de premier message (first_info_at vide)}';

    protected $description = 'Prépare la liste des destinataires autorisés d\'une campagne (n\'envoie rien).';

    public function handle(): int
    {
        $segment = (string) $this->argument('segment');
        if (! in_array($segment, Segments::OUVERTS, true)) {
            $this->error("Segment fermé ou inconnu : « {$segment} ». Ouverts : " . implode(', ', Segments::OUVERTS) . '.');

            return self::FAILURE;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error("Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $workspaceId = (string) $workspaceId;

        $chemin = (string) $this->argument('sortie');
        $flux = @fopen($chemin, 'wb');
        if ($flux === false) {
            $this->error("Écriture impossible : {$chemin}");

            return self::FAILURE;
        }

        $bilan = [
            'organisateurs' => 0, 'adresses_vues' => 0, 'destinataires' => 0,
            'ecartees_opposition' => 0, 'ecartees_invalides' => 0, 'ecartees_perso' => 0,
            'ecartees_deja_informees' => 0, 'doublons_fusionnes' => 0, 'sans_evenement_a_venir' => 0,
        ];

        try {
            WorkspaceContext::run($workspaceId, function () use ($workspaceId, $flux, &$bilan): void {
                /** @var array<string, array<string, mixed>> $parAdresse */
                $parAdresse = [];

                foreach ($this->organisateurs($workspaceId) as $org) {
                    $bilan['organisateurs']++;
                    $evenement = $this->prochainEvenement($workspaceId, (int) $org->id);

                    foreach ($this->adresses($workspaceId, $org) as $a) {
                        $bilan['adresses_vues']++;
                        $email = mb_strtolower(trim($a['email']));

                        if (in_array($a['status'], ['invalid', 'disposable'], true)) {
                            $bilan['ecartees_invalides']++;

                            continue;
                        }
                        if ($a['perso'] || NatureEmail::de($email) === 'perso') {
                            $bilan['ecartees_perso']++;

                            continue;
                        }
                        if ($this->option('non-informes') && $a['deja_informe']) {
                            $bilan['ecartees_deja_informees']++;

                            continue;
                        }
                        if (isset($parAdresse[$email])) {
                            // Même adresse, autre organisateur : UN message, qui les cite.
                            $parAdresse[$email]['organisations'][] = (string) $org->denomination;
                            $bilan['doublons_fusionnes']++;

                            continue;
                        }
                        if (! EligibiliteCampagne::peutRecevoir($email, 'business')) {
                            $bilan['ecartees_opposition']++;

                            continue;
                        }

                        if ($evenement === null) {
                            $bilan['sans_evenement_a_venir']++;
                        }

                        $parAdresse[$email] = [
                            'crm_ref' => $a['crm_ref'],
                            'email' => $email,
                            'type' => $a['type'],
                            'prenom' => $a['prenom'],
                            'nom' => $a['nom'],
                            'fonction' => $a['fonction'],
                            'organisation' => (string) $org->denomination,
                            'organisation_id' => (int) $org->id,
                            'organisations' => [(string) $org->denomination],
                            'evenement' => $evenement,
                        ];
                    }
                }

                foreach ($parAdresse as $ligne) {
                    $ligne['organisations'] = array_values(array_unique($ligne['organisations']));
                    fwrite($flux, json_encode($ligne, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
                    $bilan['destinataires']++;
                }
            });
        } finally {
            fclose($flux);
        }

        $this->info("Liste écrite (rien n'a été envoyé ni modifié) : {$chemin}");
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($bilan),
            array_values($bilan),
        ));

        return self::SUCCESS;
    }

    /** @return iterable<\stdClass> */
    private function organisateurs(string $workspaceId): iterable
    {
        return DB::table('companies')
            ->where('companies.workspace_id', $workspaceId)
            ->whereNull('companies.deleted_at')
            ->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('company_tag')
                    ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
                    ->whereColumn('company_tag.company_id', 'companies.id')
                    ->where('tags.slug', Segments::tagOrganisateurs());
            })
            ->orderBy('companies.id')
            ->get(['companies.id', 'companies.denomination', 'companies.email_generic', 'companies.first_info_at']);
    }

    /**
     * Les adresses d'un organisateur : sa boîte générique, puis ses contacts.
     *
     * @return list<array{crm_ref: string, email: string, type: string, prenom: ?string, nom: ?string, fonction: ?string, status: ?string, perso: bool, deja_informe: bool}>
     */
    private function adresses(string $workspaceId, \stdClass $org): array
    {
        $adresses = [];
        if (is_string($org->email_generic) && $org->email_generic !== '') {
            $adresses[] = [
                'crm_ref' => 'organisation:' . $org->id,
                'email' => $org->email_generic,
                'type' => 'generique',
                'prenom' => null, 'nom' => null, 'fonction' => null,
                'status' => null,
                'perso' => false,
                'deja_informe' => $org->first_info_at !== null,
            ];
        }

        $contacts = DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('company_id', $org->id)
            ->whereNull('deleted_at')
            ->whereNotNull('email')
            ->orderBy('id')
            ->get(['id', 'email', 'first_name', 'last_name', 'role', 'email_status', 'metadata', 'first_info_at']);

        foreach ($contacts as $c) {
            $meta = json_decode(is_string($c->metadata) ? $c->metadata : '{}', true);
            $adresses[] = [
                'crm_ref' => 'contact:' . $c->id,
                'email' => (string) $c->email,
                'type' => 'personne',
                'prenom' => $c->first_name,
                'nom' => $c->last_name,
                'fonction' => $c->role,
                'status' => $c->email_status,
                'perso' => is_array($meta) && ($meta['email_nature'] ?? null) === 'perso',
                'deja_informe' => $c->first_info_at !== null,
            ];
        }

        return $adresses;
    }

    /** @return array{id: int, nom: string, date_debut: ?string, date_fin: ?string, recurrence: ?string, ville: ?string, lien: ?string}|null */
    private function prochainEvenement(string $workspaceId, int $companyId): ?array
    {
        $e = DB::table('events')
            ->join('event_organizers', 'event_organizers.event_id', '=', 'events.id')
            ->where('events.workspace_id', $workspaceId)
            ->where('event_organizers.company_id', $companyId)
            ->where(function ($q): void {
                // À venir, ou récurrent sans date (un BNI se tient chaque semaine).
                $q->whereRaw('COALESCE(events.date_fin, events.date_debut) >= CURRENT_DATE')
                    ->orWhereNull('events.date_debut');
            })
            ->orderByRaw('events.date_debut IS NULL, events.date_debut ASC, events.id ASC')
            ->first(['events.id', 'events.nom', 'events.date_debut', 'events.date_fin', 'events.recurrence', 'events.ville', 'events.lien_evenement']);

        if ($e === null) {
            return null;
        }

        return [
            'id' => (int) $e->id,
            'nom' => (string) $e->nom,
            'date_debut' => $e->date_debut,
            'date_fin' => $e->date_fin,
            'recurrence' => $e->recurrence,
            'ville' => $e->ville,
            'lien' => $e->lien_evenement,
        ];
    }
}
