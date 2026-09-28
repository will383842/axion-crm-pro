<?php

namespace App\Console\Commands;

use App\Crm\Campagnes\Segments;
use App\Crm\Federations\EtiquettesFederation;
use App\Crm\Personnes\NatureEmail;
use App\Crm\Taxonomy;
use App\Support\EligibiliteCampagne;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * La LISTE DES DESTINATAIRES d'une campagne, prête à donner à l'outil d'envoi.
 *
 * Le CRM ne fait QUE préparer : il n'envoie rien, ne contacte aucun service,
 * n'écrit rien en base. Il produit un fichier JSONL (hors dépôt, lisible par
 * son seul propriétaire) dont chaque ligne est UNE adresse autorisée.
 *
 * Tout se décide PAR ADRESSE, jamais par fiche : une même boîte peut être
 * portée par plusieurs organisateurs (la même CCI sous plusieurs noms) et par
 * plusieurs contacts. On regroupe d'abord toutes ses occurrences, puis l'adresse
 * est écartée si UNE seule d'entre elles l'exige :
 *
 *  - syntaxe invalide, ou `email_status` invalid/disposable ;
 *  - adresse grand public (gmail…) ou marquée personnelle : on n'écrit à une
 *    personne sur son adresse privée qu'à la main, au sujet de son rôle —
 *    jamais dans une campagne (décision D3, plan d'envoi §10) ;
 *  - avec `--non-informes` : l'une de ses fiches a déjà reçu un premier message ;
 *  - opposition ou suppression (`EligibiliteCampagne::peutRecevoir`, portée
 *    business — la porte imposée par la garde B15-009).
 *
 * Chaque ligne cite toutes les organisations de l'adresse et l'événement à venir
 * le plus proche, pour personnaliser. Pour le segment `federations`, elle porte
 * aussi la famille, le niveau et la tête de réseau de l'organisme ; les
 * organismes de pertinence FAIBLE en sont écartés sauf `--avec-pertinence-faible`
 * (décision du 28/09). `crm:campagne:retours` retrouve ensuite
 * TOUTES les fiches de l'adresse : aucune ne reste « non informée ».
 */
class CrmCampagneDestinataires extends Command
{
    protected $signature = 'crm:campagne:destinataires
                            {segment : Segment visé (liste fermée, cf. App\Crm\Campagnes\Segments)}
                            {sortie : Fichier JSONL à écrire, HORS du dépôt}
                            {--non-informes : Seulement les adresses dont aucune fiche n\'a reçu de premier message (first_info_at)}
                            {--avec-pertinence-faible : Segment federations : réintégrer les organismes de pertinence faible (écartés par défaut)}';

    protected $description = 'Prépare la liste des destinataires autorisés d\'une campagne (n\'envoie rien).';

    public function handle(): int
    {
        $segment = (string) $this->argument('segment');
        if (! in_array($segment, Segments::OUVERTS, true)) {
            $this->error("Segment fermé ou inconnu : « {$segment} ». Ouverts : " . implode(', ', Segments::OUVERTS) . '.');

            return self::FAILURE;
        }

        $chemin = (string) $this->argument('sortie');
        $dossier = realpath(dirname($chemin));
        $depot = realpath(base_path('..'));
        if ($dossier === false || ($depot !== false && str_starts_with($dossier . DIRECTORY_SEPARATOR, $depot . DIRECTORY_SEPARATOR))) {
            // Des noms et des adresses ne s'écrivent jamais dans le dépôt (public).
            $this->error('Chemin refusé : le fichier doit être écrit HORS du dépôt, dans un dossier existant.');

            return self::FAILURE;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error("Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $workspaceId = (string) $workspaceId;

        $avecFaible = (bool) $this->option('avec-pertinence-faible');
        if ($avecFaible && $segment !== Segments::FEDERATIONS) {
            $this->error('--avec-pertinence-faible ne vaut que pour le segment « ' . Segments::FEDERATIONS . ' ».');

            return self::FAILURE;
        }

        $bilan = array_fill_keys([
            'fiches', 'ecartees_pertinence_faible', 'adresses_distinctes', 'destinataires', 'ecartees_invalides', 'ecartees_perso',
            'ecartees_deja_informees', 'ecartees_opposition', 'adresses_partagees', 'sans_evenement_a_venir',
        ], 0);

        /** @var array<string, list<array<string, mixed>>> $parAdresse */
        $parAdresse = [];
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $segment, $avecFaible, &$parAdresse, &$bilan): void {
            foreach ($this->fiches($workspaceId, $segment) as $org) {
                if ($segment === Segments::FEDERATIONS && ! $avecFaible
                    && $org->pertinence === Taxonomy::FEDERATION_PERTINENCE_HORS_CAMPAGNE) {
                    $bilan['ecartees_pertinence_faible']++;

                    continue;
                }
                $bilan['fiches']++;
                $evenement = $this->prochainEvenement($workspaceId, (int) $org->id);
                $federation = $segment === Segments::FEDERATIONS ? $this->federation($workspaceId, $org) : null;
                foreach ($this->adresses($workspaceId, $org) as $a) {
                    $a['organisation'] = (string) $org->denomination;
                    $a['organisation_id'] = (int) $org->id;
                    $a['evenement'] = $evenement;
                    $a['federation'] = $federation;
                    $parAdresse[mb_strtolower(trim($a['email']))][] = $a;
                }
            }

        });

        $lignes = [];
        foreach ($parAdresse as $email => $occurrences) {
            $bilan['adresses_distinctes']++;
            $email = (string) $email;

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || $this->une($occurrences, fn ($o) => in_array($o['status'], ['invalid', 'disposable'], true))) {
                $bilan['ecartees_invalides']++;

                continue;
            }
            if (NatureEmail::de($email) === 'perso' || $this->une($occurrences, fn ($o) => $o['perso'])) {
                $bilan['ecartees_perso']++;

                continue;
            }
            if ($this->option('non-informes') && $this->une($occurrences, fn ($o) => $o['deja_informe'])) {
                $bilan['ecartees_deja_informees']++;

                continue;
            }
            if (! EligibiliteCampagne::peutRecevoir($email, 'business')) {
                $bilan['ecartees_opposition']++;

                continue;
            }

            // La personne nommée d'abord (message plus personnel), sinon la boîte.
            usort($occurrences, fn ($a, $b) => ($a['type'] === 'personne' ? 0 : 1) <=> ($b['type'] === 'personne' ? 0 : 1));
            $premiere = $occurrences[0];
            $evenement = null;
            foreach ($occurrences as $o) {
                if ($o['evenement'] !== null && ($evenement === null || $this->plusProche($o['evenement'], $evenement))) {
                    $evenement = $o['evenement'];
                }
            }
            $organisations = array_values(array_unique(array_map(fn ($o) => $o['organisation'], $occurrences)));
            if (count($organisations) > 1) {
                $bilan['adresses_partagees']++;
            }
            if ($evenement === null) {
                $bilan['sans_evenement_a_venir']++;
            }

            $ligne = [
                'crm_ref' => $premiere['crm_ref'],
                'email' => $email,
                'type' => $premiere['type'],
                'prenom' => $premiere['prenom'],
                'nom' => $premiere['nom'],
                'fonction' => $premiere['fonction'],
                'organisation' => $premiere['organisation'],
                'organisation_id' => $premiere['organisation_id'],
                'organisations' => $organisations,
                'evenement' => $evenement,
            ];
            if ($segment === Segments::FEDERATIONS) {
                $ligne['federation'] = $premiere['federation'];
            }
            $lignes[] = $ligne;
        }

        $flux = @fopen($chemin, 'wb');
        if ($flux === false) {
            $this->error("Écriture impossible : {$chemin}");

            return self::FAILURE;
        }
        @chmod($chemin, 0600);
        try {
            foreach ($lignes as $ligne) {
                fwrite($flux, json_encode($ligne, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
                $bilan['destinataires']++;
            }
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

    /**
     * @param  list<array<string, mixed>>  $occurrences
     * @param  callable(array<string, mixed>): bool  $test
     */
    private function une(array $occurrences, callable $test): bool
    {
        foreach ($occurrences as $o) {
            if ($test($o)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function plusProche(array $a, array $b): bool
    {
        if ($a['date_debut'] === null) {
            return false;
        }

        return $b['date_debut'] === null || $a['date_debut'] < $b['date_debut'];
    }

    /**
     * Les fiches du segment : celles qui portent SON tag, désigné par son nom
     * (`Segments::tag`), jamais par sa position dans une liste.
     *
     * @return iterable<\stdClass>
     */
    private function fiches(string $workspaceId, string $segment): iterable
    {
        $tag = Segments::tag($segment);

        return DB::table('companies')
            ->leftJoin('federations', 'federations.company_id', '=', 'companies.id')
            ->where('companies.workspace_id', $workspaceId)
            ->whereNull('companies.deleted_at')
            ->whereExists(function ($q) use ($tag): void {
                $q->selectRaw('1')
                    ->from('company_tag')
                    ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
                    ->whereColumn('company_tag.company_id', 'companies.id')
                    ->where('tags.slug', $tag);
            })
            ->orderBy('companies.id')
            ->get([
                'companies.id', 'companies.denomination', 'companies.email_generic', 'companies.first_info_at',
                'federations.pertinence', 'federations.famille', 'federations.niveau', 'federations.secteurs',
                'federations.parent_company_id',
            ]);
    }

    /**
     * Ce qui personnalise un message à une fédération : sa famille, son
     * niveau, ses secteurs représentés et le nom de sa tête de réseau.
     *
     * @return array{famille: ?string, niveau: ?string, secteurs: list<string>, tete_de_reseau: ?string}
     */
    private function federation(string $workspaceId, \stdClass $org): array
    {
        $tete = null;
        if ($org->parent_company_id !== null) {
            $nom = DB::table('companies')
                ->where('workspace_id', $workspaceId)
                ->where('id', $org->parent_company_id)
                ->whereNull('deleted_at')
                ->value('denomination');
            $tete = is_string($nom) ? $nom : null;
        }

        return [
            'famille' => $org->famille,
            'niveau' => $org->niveau,
            'secteurs' => EtiquettesFederation::tableau($org->secteurs),
            'tete_de_reseau' => $tete,
        ];
    }

    /**
     * Les adresses d'un organisateur : sa boîte générique, puis ses contacts.
     *
     * @return list<array{crm_ref: string, email: string, type: string, prenom: ?string, nom: ?string, fonction: ?string, status: ?string, perso: bool, deja_informe: bool}>
     */
    private function adresses(string $workspaceId, \stdClass $org): array
    {
        $adresses = [];
        if (is_string($org->email_generic) && trim($org->email_generic) !== '') {
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
