<?php

namespace App\Crm\Ingest;

use App\Crm\FichesProtegees;
use App\Crm\Taxonomy;
use App\Support\ListeSuppression;
use Database\Seeders\GovernedTagsSeeder;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * SYNCHRO AUTOMATIQUE du dossier client de la console du site → CRM
 * (25/10/2026, décision de Will : « il faudrait que ça s'envoie
 * automatiquement sans que je n'intervienne »). Contrat : `DossierClient`.
 *
 * GARDE-FOUS — chacun a son test (`DossierClientSynchroTest`) :
 *
 *  (c) RAPPROCHEMENT : `console_client_ref` (ou `external_ref`) =
 *      `site:client:<uuid>`, PUIS le SIREN. Sans SIREN, ou si les deux
 *      désignent des fiches différentes, ou si la fiche est à la corbeille :
 *      la ligne part dans « Personnes à rattacher » (arbitrage). JAMAIS de
 *      fusion, jamais de création d'une seconde fiche.
 *  (d) CONFLIT : un champ modifié des deux côtés → le plus récent gagne
 *      (`field_origins_at`, ou à défaut `updated_at` de la fiche pour une
 *      valeur déclarée/manuelle non datée). L'ANCIENNE valeur est gardée dans
 *      la ligne de timeline (`changements[].avant`). Une retouche manuelle plus
 *      récente que le message n'est jamais écrasée (`ignores[]`).
 *  (e) AUCUNE SUPPRESSION ne se propage : une valeur absente ou nulle ne vide
 *      rien, un contact absent de la liste reste, une fiche ou un contact mis
 *      à la corbeille dans le CRM n'est ni restauré ni recréé.
 *  (f) Ni `relation_type` ni `lifecycle_stage` ne changent sur une fiche
 *      existante, SAUF l'étape « client » quand la facture est établie
 *      (`DossierClient::estClient`) ET que `CRM_SYNC_DOSSIER_CLIENT_STAGE` est
 *      ouvert ; jamais de retour en arrière ; jamais sur une relation posée à
 *      la main (`relation_saisie_manuelle_at`).
 *  (g) IDEMPOTENCE par `event_id` (`activities.external_ref`, vérifiée par
 *      l'appelant) ; un message de `version` ≤ à la dernière appliquée ne
 *      touche à rien.
 *  (h) AUCUN e-mail, aucune inscription : ce service n'écrit que
 *      `companies`, `contacts`, `company_tag` et `activities`. La fiche reçoit
 *      le tag PROTÉGÉ `src:console-client` (`FichesProtegees`) : hors de toute
 *      audience, purge et enrichissement automatique ; ses personnes sont
 *      écartées de toute campagne (`EligibiliteAdresse::FICHE_CONSOLE`).
 *  (i) `CRM_PURGE_ENABLED` ouvert ne purge pas ces fiches (tag protégé, base
 *      légale précontractuelle ou contractuelle).
 *  (j) Jamais de réponse ni de montant : refusé à l'entrée (`DossierClient`).
 */
final class DossierClientIngest
{
    public const DISCOVERY_SOURCE = 'console';

    public const TITRE = 'Dossier client — console du site';

    public function __construct(private readonly SiteSyncClassifier $classifier) {}

    public static function drapeauOuvert(): bool
    {
        return filter_var(config('crm.ingest.dossier_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function etapeClientOuverte(): bool
    {
        return filter_var(config('crm.ingest.dossier_client_stage', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Appelé dans la transaction et le contexte d'espace de `SiteSyncIngestService`. */
    public function ingerer(SiteSyncEvent $event, string $workspaceId, string $activityRef): IngestOutcome
    {
        $dossier = $event->dossier;
        if ($dossier === null) {
            throw SiteSyncRejection::invalid('invalid_dossier', 'Dossier client absent.');
        }
        $ref = $event->subjectRef;
        $entreprise = $this->entrepriseDeclaree($event);

        [$fiche, $motif] = $this->rapprocher($workspaceId, $ref, $event->siren());

        if ($motif !== null) {
            $activityId = $this->versArbitrage($event, $dossier, $workspaceId, $activityRef, $motif, $entreprise);

            return new IngestOutcome(status: IngestOutcome::PENDING_MATCH, activityId: $activityId);
        }

        if ($fiche === null) {
            $siren = (string) $event->siren();
            $id = $this->creer($workspaceId, $ref, $siren, $dossier, $entreprise, $event->occurredAt);
            $fiche = DB::table('companies')->where('id', $id)->first();
            if ($fiche === null) {
                throw new RuntimeException("Fiche {$id} introuvable juste après sa création.");
            }
            $statut = IngestOutcome::CREATED;
            $resultat = ['changements' => [], 'ignores' => []];
            $resultat['contacts'] = $this->contacts($workspaceId, $fiche, $ref, $dossier, $event->occurredAt);
        } else {
            if ((int) ($fiche->console_dossier_version ?? 0) >= $dossier->version) {
                // Message PÉRIMÉ (désordre, rejeu tardif) : rien n'est touché,
                // la ligne est soldée côté site.
                return new IngestOutcome(status: IngestOutcome::IDEMPOTENT, subjectType: 'company', subjectId: (int) $fiche->id);
            }
            $resultat = $this->appliquerSurFiche($workspaceId, $fiche, $ref, $dossier, $entreprise, $event->occurredAt);
            $statut = IngestOutcome::UPDATED;
        }

        $tag = $this->etiqueter($workspaceId, (int) $fiche->id);
        $activityId = $this->journaliser($workspaceId, (int) $fiche->id, $activityRef, $event->occurredAt, $ref, $dossier, $resultat);

        return new IngestOutcome(
            status: $statut,
            subjectType: 'company',
            subjectId: (int) $fiche->id,
            activityId: $activityId,
            tags: $tag === null ? [] : [$tag],
        );
    }

    /**
     * ARBITRAGE (`ArbitrageController::attach`) : l'opérateur a choisi la fiche.
     * Le rattachement est un geste HUMAIN : il pose la référence console sur
     * la fiche choisie (et la retire d'une autre fiche qui la portait, sans
     * rien y effacer d'autre), rattache toutes les lignes en attente du même
     * client, puis applique le dossier le plus récent.
     *
     * @return array<string, mixed> le résumé de l'application
     */
    public function appliquerApresArbitrage(string $workspaceId, int $companyId, string $ref, int $activityId): array
    {
        $fiche = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $companyId)->lockForUpdate()->first();
        if ($fiche === null) {
            abort(404, 'Entreprise introuvable dans cet univers.');
        }
        $refActuelle = $fiche->console_client_ref ?? null;
        if (is_string($refActuelle) && $refActuelle !== '' && $refActuelle !== $ref) {
            abort(409, 'Cette fiche est déjà rattachée à un autre client de la console.');
        }

        DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->where('console_client_ref', $ref)
            ->where('id', '!=', $companyId)
            ->update(['console_client_ref' => null, 'updated_at' => now()]);
        DB::table('companies')->where('id', $companyId)->update(['console_client_ref' => $ref, 'updated_at' => now()]);

        $enAttente = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('kind', DossierClient::EVENT_TYPE)
            ->whereNull('subject_id')
            ->whereRaw("payload -> 'pending_match' ->> 'subject_ref' = ?", [$ref])
            ->whereRaw("payload -> 'arbitrage_dismissed' IS NULL")
            ->get();

        $plusRecente = null;
        $versionMax = 0;
        foreach ($enAttente as $ligne) {
            $payload = json_decode((string) $ligne->payload, true);
            $version = is_array($payload) ? (int) ($payload['dossier']['version'] ?? 0) : 0;
            if ($version > $versionMax) {
                $versionMax = $version;
                $plusRecente = [$ligne, $payload];
            }
        }

        DB::table('activities')
            ->whereIn('id', $enAttente->pluck('id')->reject(static fn ($id): bool => (int) $id === $activityId)->all())
            ->update(['subject_type' => 'company', 'subject_id' => $companyId]);

        $fiche = DB::table('companies')->where('id', $companyId)->first();
        if ($plusRecente === null || $fiche === null || (int) ($fiche->console_dossier_version ?? 0) >= $versionMax) {
            return ['applique' => false];
        }

        [$ligne, $payload] = $plusRecente;
        $dossier = DossierClient::depuisArbitrage($ref, is_array($payload['dossier'] ?? null) ? $payload['dossier'] : []);
        $entreprise = is_array($payload['entreprise'] ?? null) ? $payload['entreprise'] : [];
        $quand = new DateTimeImmutable((string) $ligne->occurred_at);

        $resultat = $this->appliquerSurFiche($workspaceId, $fiche, $ref, $dossier, $entreprise, $quand);
        $this->etiqueter($workspaceId, $companyId);

        return ['applique' => true, 'version' => $dossier->version] + $resultat;
    }

    // ── Rapprochement ───────────────────────────────────────────────────────

    /**
     * @return array{0: ?stdClass, 1: ?string} [fiche, motif d'arbitrage]
     */
    private function rapprocher(string $workspaceId, string $ref, ?string $siren): array
    {
        $parRef = DB::table('companies')->where('workspace_id', $workspaceId)->where('console_client_ref', $ref)->lockForUpdate()->first()
            ?? DB::table('companies')->where('workspace_id', $workspaceId)->where('external_ref', $ref)->lockForUpdate()->first();

        if ($parRef !== null) {
            if ($parRef->deleted_at !== null) {
                return [null, 'fiche_a_la_corbeille'];
            }
            if ($siren !== null && is_string($parRef->siren ?? null) && $parRef->siren !== '' && $parRef->siren !== $siren) {
                return [null, 'desaccord_siren'];
            }

            return [$parRef, null];
        }

        if ($siren === null) {
            return [null, 'sans_siren'];
        }

        $parSiren = DB::table('companies')->where('workspace_id', $workspaceId)->where('siren', $siren)->lockForUpdate()->first();
        if ($parSiren === null) {
            return [null, null];
        }
        if ($parSiren->deleted_at !== null) {
            return [null, 'fiche_a_la_corbeille'];
        }
        $autre = $parSiren->console_client_ref ?? null;
        if (is_string($autre) && $autre !== '' && $autre !== $ref) {
            return [null, 'siren_deja_rattache_a_un_autre_client'];
        }

        return [$parSiren, null];
    }

    /**
     * @param  array<string, mixed>  $entreprise
     */
    private function versArbitrage(
        SiteSyncEvent $event,
        DossierClient $dossier,
        string $workspaceId,
        string $activityRef,
        string $motif,
        array $entreprise,
    ): int {
        $pending = array_filter([
            'denomination' => $entreprise['name'] ?? null,
            'postcode' => $entreprise['postcode'] ?? null,
            'city' => $entreprise['city'] ?? null,
            'siren' => $event->siren(),
            'subject_ref' => $event->subjectRef,
            'axi_cli' => $dossier->axiCli,
            'motif' => $motif,
            'legal_basis' => $dossier->estClient() ? 'contract' : 'precontractual',
        ], static fn (mixed $v): bool => $v !== null);

        return (int) DB::table('activities')->insertGetId([
            'workspace_id' => $workspaceId,
            'contact_id' => null,
            'type' => DossierClient::EVENT_TYPE,
            'kind' => DossierClient::EVENT_TYPE,
            'occurred_at' => $event->occurredAt,
            'person_key' => null,
            'external_ref' => $activityRef,
            'subject_type' => null,
            'subject_id' => null,
            'title' => self::TITRE . ' — à rattacher (' . str_replace('_', ' ', $motif) . ')',
            'payload' => json_encode([
                'source' => 'console',
                'subject_ref' => $event->subjectRef,
                'pending_match' => $pending,
                // Rejoué tel quel au rattachement (`appliquerApresArbitrage`).
                'dossier' => $dossier->enTableau(),
                'entreprise' => $entreprise,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }

    // ── Écritures ───────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $entreprise */
    private function creer(string $workspaceId, string $ref, string $siren, DossierClient $dossier, array $entreprise, DateTimeImmutable $quand): int
    {
        $valeurs = array_filter([
            'denomination' => $entreprise['name'] ?? null,
            'postcode' => $entreprise['postcode'] ?? null,
            'city' => $entreprise['city'] ?? null,
            'address' => $dossier->rue,
            'phone' => $dossier->telephone,
        ], static fn (mixed $v): bool => $v !== null);

        $origines = OriginesDesChamps::marquer(null, null, array_keys($valeurs), Taxonomy::FIELD_ORIGIN_CONSOLE, $quand->format(DATE_ATOM));

        return (int) DB::table('companies')->insertGetId(array_merge([
            'workspace_id' => $workspaceId,
            'siren' => $siren,
            'discovery_source' => self::DISCOVERY_SOURCE,
            'quality_score' => 0,
            'signals' => '{}',
            'metadata' => json_encode(['console' => $dossier->metadonnees()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            // Une fiche que le canal CRÉE n'était dans aucune prospection :
            // `prospect`, et l'étape « client » seulement quand la facture
            // est établie ET que le drapeau est ouvert.
            'relation_type' => 'prospect',
            'lifecycle_stage' => $dossier->estClient() && self::etapeClientOuverte() ? 'client' : 'qualifie',
            'legal_basis' => $dossier->estClient() ? 'contract' : 'precontractual',
            'external_ref' => $ref,
            'console_client_ref' => $ref,
            'console_dossier_version' => $dossier->version,
            'created_at' => now(),
            'updated_at' => now(),
        ], $origines, $valeurs));
    }

    /**
     * Applique le dossier à une fiche EXISTANTE.
     *
     * @param  array<string, mixed>  $entreprise
     * @return array{changements: list<array<string, mixed>>, ignores: list<array<string, mixed>>, contacts: array<string, mixed>}
     */
    private function appliquerSurFiche(string $workspaceId, stdClass $fiche, string $ref, DossierClient $dossier, array $entreprise, DateTimeImmutable $quand): array
    {
        $proposes = [
            'address' => $dossier->rue,
            'phone' => $dossier->telephone,
            'postcode' => is_string($entreprise['postcode'] ?? null) ? $entreprise['postcode'] : null,
            'city' => is_string($entreprise['city'] ?? null) ? $entreprise['city'] : null,
            // La dénomination officielle (INSEE) n'est jamais remplacée par le
            // nom d'usage de la console : seulement remplie si elle est vide.
            'denomination' => is_string($entreprise['name'] ?? null) ? $entreprise['name'] : null,
        ];

        [$maj, $changements, $ignores, $origines] = $this->trancher($fiche, $proposes, $quand, ['denomination']);

        $brut = json_decode(is_string($fiche->metadata ?? null) ? $fiche->metadata : '{}', true);
        $metadonnees = is_array($brut) ? $brut : [];
        $metadonnees['console'] = $dossier->metadonnees();
        $maj['metadata'] = json_encode($metadonnees, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $maj['legal_basis'] = $this->classifier->mergeLegalBasis(
            is_string($fiche->legal_basis ?? null) ? $fiche->legal_basis : null,
            $dossier->estClient() ? 'contract' : 'precontractual',
        );

        // (f) — l'étape « client », et RIEN d'autre, seulement à la facture.
        if ($dossier->estClient() && self::etapeClientOuverte() && ($fiche->relation_saisie_manuelle_at ?? null) === null) {
            $avant = is_string($fiche->lifecycle_stage ?? null) ? $fiche->lifecycle_stage : null;
            $apres = $this->classifier->mergeLifecycleStage($avant, 'client');
            if ($apres !== $avant) {
                $maj['lifecycle_stage'] = $apres;
                $changements[] = ['champ' => 'lifecycle_stage', 'avant' => ['lifecycle_stage' => $avant], 'apres' => ['lifecycle_stage' => $apres], 'origine_avant' => null];
            }
        }

        if (($fiche->console_client_ref ?? null) === null) {
            $maj['console_client_ref'] = $ref;
        }
        if (($fiche->external_ref ?? null) === null) {
            $maj['external_ref'] = $ref;
        }
        $maj['console_dossier_version'] = $dossier->version;
        $maj['field_origins'] = OriginesDesChamps::encoder($origines[0]);
        $maj['field_origins_at'] = OriginesDesChamps::encoder($origines[1]);
        $maj['updated_at'] = now();

        DB::table('companies')->where('id', $fiche->id)->update($maj);

        return [
            'changements' => $changements,
            'ignores' => $ignores,
            'contacts' => $this->contacts($workspaceId, $fiche, $ref, $dossier, $quand),
        ];
    }

    /**
     * LA RÈGLE DU CONFLIT, écrite une fois pour les fiches et les personnes.
     *
     * @param  array<string, ?string>  $proposes  colonne => valeur de la console
     * @param  list<string>  $remplirSeulement  colonnes jamais remplacées, seulement remplies
     * @return array{0: array<string, string>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: array{0: array<string, string>, 1: array<string, string>}}
     */
    private function trancher(stdClass $ligne, array $proposes, DateTimeImmutable $quand, array $remplirSeulement = []): array
    {
        $origines = OriginesDesChamps::decoder($ligne->field_origins ?? null);
        $dates = OriginesDesChamps::decoder($ligne->field_origins_at ?? null);
        $miseAJourLigne = $this->date($ligne->updated_at ?? null);

        $maj = [];
        $changements = [];
        $ignores = [];
        foreach ($proposes as $colonne => $valeur) {
            if ($valeur === null) {
                continue; // (e) une valeur absente ne vide jamais rien
            }
            $actuelle = $ligne->{$colonne} ?? null;
            $actuelle = is_string($actuelle) ? trim($actuelle) : ($actuelle === null ? null : (string) $actuelle);
            if ($actuelle === $valeur) {
                continue;
            }

            if ($actuelle !== null && $actuelle !== '') {
                if (in_array($colonne, $remplirSeulement, true)) {
                    $ignores[] = ['champ' => $colonne, 'raison' => 'valeur_officielle_conservee'];

                    continue;
                }
                $origine = $origines[$colonne] ?? null;
                $datee = $this->date($dates[$colonne] ?? null);
                $reference = $datee ?? (in_array($origine, Taxonomy::FIELD_ORIGINS_PROTEGEES, true) ? $miseAJourLigne : null);
                if ($reference !== null && $reference > $quand) {
                    // (d) — une retouche plus RÉCENTE que le message gagne.
                    $ignores[] = ['champ' => $colonne, 'raison' => 'valeur_crm_plus_recente', 'origine' => $origine];

                    continue;
                }
            }

            $maj[$colonne] = $valeur;
            $changements[] = [
                'champ' => $colonne,
                // Rangées sous le NOM de la colonne : le masquage des
                // coordonnées (`MasquageCoordonnees`, par clé) couvre aussi
                // l'historique.
                'avant' => [$colonne => $actuelle],
                'apres' => [$colonne => $valeur],
                'origine_avant' => $origines[$colonne] ?? null,
            ];
            $origines[$colonne] = Taxonomy::FIELD_ORIGIN_CONSOLE;
            $dates[$colonne] = $quand->format(DATE_ATOM);
        }

        return [$maj, $changements, $ignores, [$origines, $dates]];
    }

    /**
     * Les interlocuteurs du dossier. Rapprochement DANS la fiche seulement :
     * référence console (`metadata.console_ref`) → `person_key` → e-mail →
     * prénom + nom. Une personne opposée n'est ni créée ni modifiée.
     *
     * @return array{crees: list<int>, mis_a_jour: list<int>, ignores: list<array<string, mixed>>, changements: list<array<string, mixed>>}
     */
    private function contacts(string $workspaceId, stdClass $fiche, string $ref, DossierClient $dossier, DateTimeImmutable $quand): array
    {
        $bilan = ['crees' => [], 'mis_a_jour' => [], 'ignores' => [], 'changements' => []];
        $companyId = (int) $fiche->id;
        $base = $dossier->estClient() ? 'contract' : 'precontractual';

        foreach ($dossier->contacts as $c) {
            $refContact = $ref . ':contact:' . $c['ref'];

            // L'opposition gagne partout : une personne opposée n'est ni créée
            // ni modifiée (anti-réinsertion, même empreinte que le site).
            if ($c['email'] !== null
                && DB::table('opt_out')->where('scope', 'business')->where('email_hash', ListeSuppression::empreinte($c['email']))->exists()) {
                $bilan['ignores'][] = ['ref' => $c['ref'], 'raison' => 'opposition'];

                continue;
            }

            $existant = DB::table('contacts')->where('workspace_id', $workspaceId)->where('company_id', $companyId)
                ->whereRaw("metadata ->> 'console_ref' = ?", [$refContact])->lockForUpdate()->first();
            if ($existant === null && $c['person_key'] !== null) {
                $existant = DB::table('contacts')->where('workspace_id', $workspaceId)->where('company_id', $companyId)
                    ->where('person_key', $c['person_key'])->lockForUpdate()->first();
            }
            if ($existant === null && $c['email'] !== null) {
                $existant = DB::table('contacts')->where('workspace_id', $workspaceId)->where('company_id', $companyId)
                    ->where('email', $c['email'])->lockForUpdate()->first();
            }
            if ($existant === null && $c['nom'] !== null) {
                $existant = DB::table('contacts')->where('workspace_id', $workspaceId)
                    ->where('normalized_hash', $this->normalizedHash($c['prenom'], $c['nom'], $companyId))->lockForUpdate()->first();
            }

            if ($existant !== null && $existant->deleted_at !== null) {
                // (e) — mise à la corbeille dans le CRM : jamais restaurée.
                $bilan['ignores'][] = ['ref' => $c['ref'], 'raison' => 'contact_a_la_corbeille'];

                continue;
            }

            $proposes = [
                'first_name' => $c['prenom'],
                'last_name' => $c['nom'],
                'email' => $c['email'],
                'phone' => $c['telephone'],
                'role' => $c['fonction'],
            ];

            if ($existant === null) {
                if ($c['nom'] === null) {
                    // `contacts.last_name` est NOT NULL : on ne fabrique jamais
                    // un patronyme.
                    $bilan['ignores'][] = ['ref' => $c['ref'], 'raison' => 'nom_absent'];

                    continue;
                }
                $valeurs = array_filter($proposes, static fn (?string $v): bool => $v !== null);
                $origines = OriginesDesChamps::marquer(null, null, array_keys($valeurs), Taxonomy::FIELD_ORIGIN_CONSOLE, $quand->format(DATE_ATOM));
                $bilan['crees'][] = (int) DB::table('contacts')->insertGetId(array_merge([
                    'workspace_id' => $workspaceId,
                    'company_id' => $companyId,
                    'discovery_source' => self::DISCOVERY_SOURCE,
                    'sources' => json_encode([self::DISCOVERY_SOURCE], JSON_THROW_ON_ERROR),
                    'metadata' => json_encode(['console_ref' => $refContact], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'person_key' => $c['person_key'],
                    'legal_basis' => $base,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $origines, $valeurs));

                continue;
            }

            [$maj, $changements, $ignores, $origines] = $this->trancher($existant, $proposes, $quand);

            // Le nom alimente `normalized_hash` (UNIQUE) : pas d'écriture qui
            // entrerait en collision avec une autre personne de la fiche.
            if (isset($maj['first_name']) || isset($maj['last_name'])) {
                $cible = $this->normalizedHash($maj['first_name'] ?? $existant->first_name, $maj['last_name'] ?? (string) $existant->last_name, (int) $existant->company_id);
                $collision = DB::table('contacts')->where('workspace_id', $workspaceId)->where('normalized_hash', $cible)->where('id', '!=', $existant->id)->exists();
                if ($collision) {
                    unset($maj['first_name'], $maj['last_name']);
                    $changements = array_values(array_filter($changements, static fn (array $ch): bool => ! in_array($ch['champ'], ['first_name', 'last_name'], true)));
                    $ignores[] = ['champ' => 'last_name', 'raison' => 'homonyme_dans_la_fiche'];
                }
            }

            $meta = json_decode(is_string($existant->metadata ?? null) ? $existant->metadata : '{}', true);
            $meta = is_array($meta) ? $meta : [];
            $meta['console_ref'] = $refContact;
            $maj['metadata'] = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (($existant->person_key ?? null) === null && $c['person_key'] !== null) {
                $maj['person_key'] = $c['person_key'];
            }
            $maj['legal_basis'] = $this->classifier->mergeLegalBasis(is_string($existant->legal_basis ?? null) ? $existant->legal_basis : null, $base);
            $maj['field_origins'] = OriginesDesChamps::encoder($origines[0]);
            $maj['field_origins_at'] = OriginesDesChamps::encoder($origines[1]);
            $maj['updated_at'] = now();

            DB::table('contacts')->where('id', $existant->id)->update($maj);

            $bilan['mis_a_jour'][] = (int) $existant->id;
            foreach ($changements as $ch) {
                $bilan['changements'][] = ['contact_id' => (int) $existant->id] + $ch;
            }
            foreach ($ignores as $ig) {
                $bilan['ignores'][] = ['ref' => $c['ref']] + $ig;
            }
        }

        return $bilan;
    }

    /** Le tag PROTÉGÉ de provenance, posé verrouillé (idempotent). */
    private function etiqueter(string $workspaceId, int $companyId): ?string
    {
        $slug = FichesProtegees::TAG_CONSOLE_CLIENT;
        $tagId = DB::table('tags')->where('workspace_id', $workspaceId)->where('slug', $slug)->value('id');
        if ($tagId === null) {
            $referentiel = GovernedTagsSeeder::referential()[$slug] ?? ['name' => 'Dossier client (console du site)', 'category' => 'intent'];
            DB::table('tags')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'slug' => $slug,
                'name' => $referentiel['name'],
                'category' => $referentiel['category'],
                'kind' => 'auto',
                'rules' => '{}',
                'is_locked' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $tagId = DB::table('tags')->where('workspace_id', $workspaceId)->where('slug', $slug)->value('id');
        }
        if ($tagId === null) {
            return null;
        }

        DB::table('company_tag')->insertOrIgnore([
            'company_id' => $companyId,
            'tag_id' => (int) $tagId,
            'workspace_id' => $workspaceId,
            'assigned_at' => now(),
            'assigned_by' => 'auto-rule',
        ]);

        return $slug;
    }

    /** @param  array<string, mixed>  $resultat */
    private function journaliser(string $workspaceId, int $companyId, string $activityRef, DateTimeImmutable $quand, string $ref, DossierClient $dossier, array $resultat): int
    {
        return (int) DB::table('activities')->insertGetId([
            'workspace_id' => $workspaceId,
            'contact_id' => null,
            'type' => DossierClient::EVENT_TYPE,
            'kind' => DossierClient::EVENT_TYPE,
            'occurred_at' => $quand,
            'person_key' => null,
            'external_ref' => $activityRef,
            'subject_type' => 'company',
            'subject_id' => $companyId,
            'title' => self::TITRE . ' (version ' . $dossier->version . ')',
            'payload' => json_encode([
                'source' => 'console',
                'subject_ref' => $ref,
                'version' => $dossier->version,
                'statut' => $dossier->statut,
                'facture_emise' => $dossier->factureEmise,
                'url_console' => $dossier->urlConsole,
                // (d) — l'ANCIENNE valeur de chaque champ remplacé.
                'changements' => $resultat['changements'] ?? [],
                'ignores' => $resultat['ignores'] ?? [],
                'contacts' => $resultat['contacts'] ?? [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function entrepriseDeclaree(SiteSyncEvent $event): array
    {
        return array_filter([
            'name' => $event->str('company', 'name'),
            'postcode' => $event->str('company', 'postcode'),
            'city' => $event->str('company', 'city'),
        ], static fn (?string $v): bool => $v !== null);
    }

    private function date(mixed $valeur): ?DateTimeImmutable
    {
        if (! is_string($valeur) || $valeur === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($valeur);
        } catch (Throwable) {
            return null;
        }
    }

    /** Même expression que `ContactUpserter::normalizedHash` (colonne générée). */
    private function normalizedHash(?string $firstName, string $lastName, int $companyId): string
    {
        $row = DB::selectOne(
            "SELECT encode(digest(normalize_name(coalesce(?, '') || '_' || ?) || '_' || ?::TEXT, 'sha256'), 'hex') AS h",
            [$firstName, $lastName, $companyId],
        );

        return (string) ($row->h ?? '');
    }
}
