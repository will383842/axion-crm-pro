<?php

namespace App\Crm\Campagnes;

use App\Crm\Doublons\AdressesPartagees;
use App\Crm\Emails\QualificationEmail;
use App\Crm\Emails\VerificationEmail;
use App\Crm\Listes\ListesManuelles;
use App\Models\Company;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Audiences\CritereAudienceInvalide;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LES DESTINATAIRES D'UNE AUDIENCE — à qui écrire dans chaque organisation.
 *
 * Une audience retient des ORGANISATIONS (`AudienceBuilderService`). Ce
 * résolveur en tire des ADRESSES, sans rien envoyer ni rien écrire :
 *
 *  1. pour chaque organisation, ses adresses candidates :
 *       - génériques : `companies.email_generic` et les canaux typés
 *         `generique` de `signals.contact_channels` (#255) ;
 *       - nominatives : `contacts.email` (personnes vivantes) et les canaux
 *         typés `nominatif` ;
 *       - un canal SANS type connu (`details` absent) n'est jamais retenu
 *         (`type_inconnu`, REQ-CAM-082) ;
 *  2. le réglage (`ReglageDestinataires`) choisit parmi elles : générique,
 *     personnes nommées, les deux, ou la personne d'abord sinon la générique ;
 *     filtre éventuel par fonction (`contacts.role`) et par personnes cochées
 *     dans une liste manuelle ;
 *  3. chaque adresse est jugée UNE fois, sur toutes ses occurrences, par la
 *     règle de #253 (`EligibiliteAdresse` : invalide, non vérifiée valide,
 *     personnelle, opposition/suppression via
 *     `EligibiliteCampagne::peutRecevoir`), puis par `AdressesPartagees`
 *     (cabinet, domiciliation — #260) ;
 *  4. JAMAIS deux fois la même adresse : le regroupement se fait par adresse
 *     NORMALISÉE, toutes organisations confondues ; une adresse partagée par
 *     plusieurs organisations est UN destinataire qui les cite toutes.
 *
 * Ce service est le côté CRM du « constructeur de destinataires » du plan
 * d'envoi (REQ-CAM-018, 082) : il ne construit ni vague ni envoi, n'écrit
 * dans aucune table, et ne remplace pas la porte finale `EnvoiAutorise` —
 * une audience est une PHOTO, l'éligibilité se repose juste avant d'écrire.
 *
 * AUDIENCE PRESSE (`AudienceBuilderService::estAudiencePresse`, 01/10/2026) :
 * les candidates sont la boîte générique de la fiche et les personnes de la
 * presse — JAMAIS un autre contact (GOFAB, organisateur, prospection) ni un
 * canal typé de la fiche (relecture A09) —, et chaque adresse doit en
 * plus avoir une PROVENANCE fiable (`AdressePresseFiable`) — sinon elle est
 * exclue pour `site_devine`, `journaliste_sans_acces` ou
 * `journaliste_retire`, comptée et dite à l'écran.
 *
 * @phpstan-type Candidat array{email: string, classe: string, crm_ref: string, fonction: ?string, status: ?string, verification: ?string, perso: bool, deja_informe: bool, ecartee: ?string, provenance?: string, provenance_fiable?: bool, journaliste_retire?: bool}
 */
final class ResolveurDestinataires
{
    /**
     * Au-delà, l'aperçu direct n'est pas calculé (REQ-CAM-011 prévoit un
     * calcul asynchrone avec progression au-delà de 50 000 adresses).
     */
    public const ORGANISATIONS_MAX = 50000;

    public const GENERIQUE = 'generique';

    public const NOMINATIVE = 'nominative';

    public const INCONNUE = 'inconnue';

    /** Pourquoi une adresse éligible n'a pas été retenue : le réglage. */
    public const ECARTEES = [
        'type_inconnu', 'fonction_non_retenue', 'personne_non_cochee',
        'generique_non_demandee', 'personnes_non_demandees', 'generique_remplacee_par_une_personne',
    ];

    public function __construct(private readonly AudienceBuilderService $builder) {}

    /**
     * @param  array<mixed>  $criteria  critères de l'audience (DSL validé par le constructeur)
     * @param  int|null  $echantillon  nombre de destinataires rendus en détail ; null = tous
     * @return array<string, mixed>
     *
     * @throws RuntimeException audience trop large pour un aperçu direct
     * @throws CritereAudienceInvalide critères refusés (liste inconnue, champ hors liste blanche…)
     */
    public function resoudre(string $workspaceId, array $criteria, ReglageDestinataires $reglage, ?int $echantillon = 20): array
    {
        return WorkspaceContext::run($workspaceId, fn (): array => $this->calculer($workspaceId, $criteria, $reglage, $echantillon));
    }

    /**
     * @param  array<mixed>  $criteria
     * @return array<string, mixed>
     */
    private function calculer(string $ws, array $criteria, ReglageDestinataires $reglage, ?int $echantillon): array
    {
        $query = $this->builder->buildPublicQuery($ws, $criteria);
        $total = (clone $query)->count();
        if ($total > self::ORGANISATIONS_MAX) {
            throw new RuntimeException(sprintf(
                'Audience trop large pour l aperçu direct : %d organisations (au plus %d). Affiner les critères.',
                $total,
                self::ORGANISATIONS_MAX,
            ));
        }
        $exigees = AudienceBuilderService::listesCitees($criteria)['exigees'];
        $presse = AudienceBuilderService::estAudiencePresse($criteria);

        /** @var array<int, array{nom: string, candidats: list<Candidat>}> $organisations */
        $organisations = [];
        /** @var array<string, list<Candidat>> $occurrences */
        $occurrences = [];

        $query->select(['companies.id', 'companies.denomination', 'companies.email_generic', 'companies.first_info_at', 'companies.signals'])
            ->when($presse, static fn ($q) => $q->selectRaw(
                AdressePresseFiable::siteDevineSql('companies.id', 'companies') . ' AS site_devine, '
                . AdressePresseFiable::siteVerifieSql('companies') . ' AS site_verifie',
            ))
            ->chunkById(1000, function (iterable $lot) use ($ws, $reglage, $exigees, $presse, &$organisations, &$occurrences): void {
                $fiches = [];
                foreach ($lot as $f) {
                    if ($f instanceof Company) {
                        $fiches[] = $f;
                    }
                }
                $ids = array_map(static fn (Company $f): int => (int) $f->getAttribute('id'), $fiches);
                $contacts = DB::table('contacts')
                    ->whereNull('deleted_at')
                    ->where('workspace_id', $ws)
                    ->whereIn('company_id', $ids)
                    ->whereNotNull('email')
                    // Une personne de la presse n'est JAMAIS une adresse
                    // candidate d'une audience ordinaire — même sur une fiche
                    // non-presse, même cochée dans une liste exigée (garde PAR
                    // CONTACT, `GardePresse`). La fiche de presse, elle, est
                    // déjà écartée par `buildPublicQuery`. Dans une audience
                    // presse, elle l'est, et sa provenance est jugée.
                    ->when(! $presse, static fn ($q) => $q->whereRaw(GardePresse::conditionContactsSql('contacts')))
                    ->when($presse, static fn ($q) => $q->whereRaw(GardePresse::estContactPresseSql('contacts')))
                    ->orderBy('id')
                    ->select(['id', 'company_id', 'email', 'role', 'email_status', 'metadata', 'first_info_at'])
                    ->when($presse, static fn ($q) => $q->selectRaw(
                        GardePresse::estContactPresseSql('contacts') . ' AS est_presse, '
                        . AdressePresseFiable::journalisteRetireSql('contacts') . ' AS journaliste_retire',
                    ))
                    ->get()
                    ->groupBy('company_id');
                $cochees = $reglage->personnesListees ? ListesManuelles::personnesMembres($exigees, $ids) : [];

                foreach ($fiches as $f) {
                    $id = (int) $f->getAttribute('id');
                    $candidats = $this->candidats($f->getAttributes(), $contacts->get($id, collect())->all(), $reglage, $cochees, $presse);
                    if ($presse) {
                        $candidats = $this->avecProvenance($id, $f->getAttributes(), $contacts->get($id, collect())->all(), $candidats);
                    }
                    $organisations[$id] = ['nom' => (string) $f->getAttribute('denomination'), 'candidats' => $candidats];
                    foreach ($candidats as $c) {
                        $occurrences[$c['email']][] = $c;
                    }
                }
            }, 'companies.id', 'id');

        // ── Le verdict, UNE fois par adresse, sur toutes ses occurrences ──────
        $verdicts = [];
        // Audience presse : les adresses écartées par leur PROVENANCE, sur
        // TOUTES les candidates, quel que soit le réglage — la définition de
        // `AudienceBuilderService::previewPresse` (relecture A09).
        $presseEcartees = $presse ? array_fill_keys(AdressePresseFiable::MOTIFS, 0) : null;
        foreach ($occurrences as $email => $occ) {
            if ($presseEcartees !== null && ($m = self::motifProvenance($occ)) !== null) {
                $presseEcartees[$m]++;
            }
        }
        foreach ($occurrences as $email => $occ) {
            $verdicts[(string) $email] = ($presse ? self::motifProvenance($occ) : null)
                ?? EligibiliteAdresse::motif((string) $email, $occ);
        }
        if (! $reglage->avecAdressesPartagees) {
            $eligibles = array_keys(array_filter($verdicts, static fn (?string $m): bool => $m === null));
            $partagees = AdressesPartagees::exclues($ws, array_map(static fn (int|string $e): string => (string) $e, $eligibles));
            foreach (array_keys($partagees) as $e) {
                $verdicts[(string) $e] = EligibiliteAdresse::ADRESSE_PARTAGEE;
            }
        }

        // ── Le choix, organisation par organisation ──────────────────────────
        /** @var array<string, array{type: string, crm_ref: string, fonction: ?string, organisations: array<int, string>}> $retenues */
        $retenues = [];
        /** @var array<string, string> $exclues adresse => motif d'inéligibilité */
        $exclues = [];
        /** @var array<string, string> $ecartees adresse => raison du réglage */
        $ecartees = [];
        $avecDestinataire = 0;

        foreach ($organisations as $orgId => $org) {
            $generiques = [];
            $nominatives = [];
            foreach ($org['candidats'] as $c) {
                if ($c['ecartee'] !== null) {
                    $ecartees[$c['email']] ??= $c['ecartee'];

                    continue;
                }
                if ($c['classe'] === self::GENERIQUE) {
                    $generiques[] = $c;
                } else {
                    $nominatives[] = $c;
                }
            }

            $eligible = /** @param Candidat $c */ static fn (array $c): bool => ($verdicts[$c['email']] ?? null) === null;
            $nominativesOk = array_values(array_filter($nominatives, $eligible));
            $generiquesOk = array_values(array_filter($generiques, $eligible));

            $prendre = [];
            switch ($reglage->mode) {
                case ReglageDestinataires::GENERIQUE:
                    $prendre = $generiquesOk;
                    $this->noter($ecartees, $nominatives, 'personnes_non_demandees');
                    $this->noterIneligibles($exclues, $generiques, $verdicts);
                    break;
                case ReglageDestinataires::NOMINATIVES:
                    $prendre = $nominativesOk;
                    $this->noter($ecartees, $generiques, 'generique_non_demandee');
                    $this->noterIneligibles($exclues, $nominatives, $verdicts);
                    break;
                case ReglageDestinataires::LES_DEUX:
                    $prendre = array_merge($nominativesOk, $generiquesOk);
                    $this->noterIneligibles($exclues, array_merge($nominatives, $generiques), $verdicts);
                    break;
                default:
                    // La personne nommée d'abord ; la générique seulement quand
                    // AUCUNE personne éligible ne reste (règle de #253).
                    $this->noterIneligibles($exclues, $nominatives, $verdicts);
                    if ($nominativesOk !== []) {
                        $prendre = $nominativesOk;
                        $this->noter($ecartees, $generiques, 'generique_remplacee_par_une_personne');
                    } else {
                        $prendre = $generiquesOk;
                        $this->noterIneligibles($exclues, $generiques, $verdicts);
                    }
            }

            if ($prendre !== []) {
                $avecDestinataire++;
            }
            foreach ($prendre as $c) {
                $e = $c['email'];
                if (! isset($retenues[$e])) {
                    $retenues[$e] = ['type' => $c['classe'], 'crm_ref' => $c['crm_ref'], 'fonction' => $c['fonction'], 'organisations' => []];
                } elseif ($c['classe'] === self::NOMINATIVE && $retenues[$e]['type'] !== self::NOMINATIVE) {
                    // Une même boîte, générique ici et nominative ailleurs : la
                    // personne d'abord (message plus personnel, règle de #253).
                    $retenues[$e]['type'] = self::NOMINATIVE;
                    $retenues[$e]['crm_ref'] = $c['crm_ref'];
                    $retenues[$e]['fonction'] = $c['fonction'];
                }
                $retenues[$e]['organisations'][$orgId] = $org['nom'];
            }
        }

        // Une adresse retenue quelque part n'est ni exclue ni écartée ; une
        // adresse inéligible n'est pas « écartée par le réglage ».
        $exclues = array_diff_key($exclues, $retenues);
        $ecartees = array_diff_key($ecartees, $retenues, $exclues);

        $bilan = $this->bilan($reglage, count($organisations), $avecDestinataire, $retenues, $exclues, $ecartees, $echantillon, $presse);
        if ($presseEcartees !== null) {
            $bilan['presse_ecartees'] = $presseEcartees;
        }

        return $bilan;
    }

    /**
     * Audience presse : la provenance de chaque candidate
     * (`AdressePresseFiable::juger`, la même règle que
     * `crm:campagne:destinataires presse`).
     *
     * @param  array<string, mixed>  $fiche
     * @param  array<array-key, \stdClass>  $contacts
     * @param  list<Candidat>  $candidats
     * @return list<Candidat>
     */
    private function avecProvenance(int $id, array $fiche, array $contacts, array $candidats): array
    {
        $base = [
            'site_devine' => (bool) ($fiche['site_devine'] ?? false),
            'site_verifie' => (bool) ($fiche['site_verifie'] ?? false),
            'emails_surs' => AdressePresseFiable::emailsSurs($id),
        ];
        $parContact = [];
        foreach ($contacts as $c) {
            $parContact['contact:' . (int) $c->id] = $c;
        }
        foreach ($candidats as $i => $c) {
            $ct = $parContact[$c['crm_ref']] ?? null;
            $meta = $ct !== null ? json_decode(is_string($ct->metadata ?? null) ? $ct->metadata : '{}', true) : null;
            $acces = is_array($meta) && is_string($meta['acces'] ?? null) ? $meta['acces'] : null;
            [$fiable, $provenance] = AdressePresseFiable::juger($c['email'], $base + [
                'presse' => $ct !== null && (bool) ($ct->est_presse ?? false),
                'acces' => $acces,
            ]);
            $candidats[$i]['provenance'] = $provenance;
            $candidats[$i]['provenance_fiable'] = $fiable;
            $candidats[$i]['journaliste_retire'] = $ct !== null && (bool) ($ct->journaliste_retire ?? false);
        }

        return $candidats;
    }

    /**
     * Le motif de PROVENANCE qui exclut une adresse d'une audience presse, ou
     * null : un journaliste retiré derrière elle l'exclut ; sinon il faut au
     * moins une occurrence fiable.
     *
     * @param  list<Candidat>  $occ
     */
    private static function motifProvenance(array $occ): ?string
    {
        $fiable = false;
        $sansAcces = false;
        foreach ($occ as $o) {
            if (($o['journaliste_retire'] ?? false) === true) {
                return AdressePresseFiable::JOURNALISTE_RETIRE;
            }
            $fiable = $fiable || ($o['provenance_fiable'] ?? false) === true;
            $sansAcces = $sansAcces || ($o['provenance'] ?? null) === AdressePresseFiable::JOURNALISTE_SANS_ACCES;
        }
        if ($fiable) {
            return null;
        }

        return $sansAcces ? AdressePresseFiable::JOURNALISTE_SANS_ACCES : AdressePresseFiable::SITE_DEVINE;
    }

    /**
     * Les adresses candidates d'une organisation, avec ce que le réglage en
     * écarte d'emblée (`ecartee`) — le verdict d'éligibilité vient après.
     *
     * @param  array<string, mixed>  $fiche
     * @param  array<array-key, \stdClass>  $contacts
     * @param  array<int, true>  $cochees
     * @return list<Candidat>
     */
    private function candidats(array $fiche, array $contacts, ReglageDestinataires $reglage, array $cochees, bool $presse = false): array
    {
        $id = (int) $fiche['id'];
        $signals = json_decode(is_string($fiche['signals'] ?? null) ? $fiche['signals'] : '{}', true);
        $signals = is_array($signals) ? $signals : [];
        $dejaInformee = $fiche['first_info_at'] !== null;
        $candidats = [];
        $vues = [];

        $generique = is_string($fiche['email_generic'] ?? null) ? QualificationEmail::normaliser($fiche['email_generic']) : '';
        if ($generique !== '') {
            $verification = is_array($signals['email_generic_verification'] ?? null) ? $signals['email_generic_verification'] : null;
            $candidats[] = [
                'email' => $generique, 'classe' => self::GENERIQUE, 'crm_ref' => 'organisation:' . $id, 'fonction' => null,
                'status' => null, 'verification' => VerificationEmail::statutDe($verification, $generique),
                'perso' => false, 'deja_informe' => $dejaInformee, 'ecartee' => null,
            ];
            $vues[$generique] = true;
        }

        // Canaux typés (#255) : la liste `emails` et les clés de `details`.
        // Audience presse : aucun — seules la boîte générique et les
        // personnes de la presse y sont candidates.
        $canaux = ! $presse && is_array($signals['contact_channels'] ?? null) ? $signals['contact_channels'] : [];
        $details = [];
        foreach (is_array($canaux['details'] ?? null) ? $canaux['details'] : [] as $cle => $d) {
            $details[QualificationEmail::normaliser((string) $cle)] = is_array($d) ? $d : [];
        }
        $adressesCanaux = array_keys($details);
        foreach (is_array($canaux['emails'] ?? null) ? $canaux['emails'] : [] as $e) {
            if (is_string($e) && trim($e) !== '') {
                $adressesCanaux[] = QualificationEmail::normaliser($e);
            }
        }
        foreach (array_unique($adressesCanaux) as $e) {
            $e = (string) $e;
            if (isset($vues[$e])) {
                continue;
            }
            $vues[$e] = true;
            $d = $details[$e] ?? [];
            $type = $d['type'] ?? null;
            $classe = match ($type) {
                'generique' => self::GENERIQUE,
                'nominatif' => self::NOMINATIVE,
                default => self::INCONNUE,
            };
            $ecartee = null;
            if ($classe === self::INCONNUE) {
                $ecartee = 'type_inconnu';
            } elseif ($classe === self::NOMINATIVE && $reglage->fonctions !== []) {
                // Un canal nominatif n'a pas de fonction connue.
                $ecartee = 'fonction_non_retenue';
            } elseif ($classe === self::NOMINATIVE && $reglage->personnesListees) {
                $ecartee = 'personne_non_cochee';
            }
            $candidats[] = [
                'email' => $e, 'classe' => $classe === self::INCONNUE ? self::NOMINATIVE : $classe,
                'crm_ref' => 'organisation:' . $id, 'fonction' => null,
                'status' => null, 'verification' => VerificationEmail::statutDe($d, $e),
                'perso' => false, 'deja_informe' => $dejaInformee, 'ecartee' => $ecartee,
            ];
        }

        foreach ($contacts as $c) {
            $e = QualificationEmail::normaliser((string) ($c->email ?? ''));
            if ($e === '') {
                continue;
            }
            $meta = json_decode(is_string($c->metadata ?? null) ? $c->metadata : '{}', true);
            $meta = is_array($meta) ? $meta : [];
            $role = is_string($c->role ?? null) ? $c->role : null;
            $ecartee = null;
            if (! $reglage->retientFonction($role)) {
                $ecartee = 'fonction_non_retenue';
            } elseif ($reglage->personnesListees && ! isset($cochees[(int) $c->id])) {
                $ecartee = 'personne_non_cochee';
            }
            $candidats[] = [
                'email' => $e, 'classe' => self::NOMINATIVE, 'crm_ref' => 'contact:' . (int) $c->id, 'fonction' => $role,
                'status' => is_string($c->email_status ?? null) ? $c->email_status : null,
                'verification' => VerificationEmail::statutDe($meta['email_verification'] ?? null, $e),
                'perso' => ($meta['email_nature'] ?? null) === 'perso',
                'deja_informe' => ($c->first_info_at ?? null) !== null,
                'ecartee' => $ecartee,
            ];
        }

        return $candidats;
    }

    /**
     * @param  array<string, string>  $ecartees
     * @param  list<Candidat>  $candidats
     */
    private function noter(array &$ecartees, array $candidats, string $raison): void
    {
        foreach ($candidats as $c) {
            $ecartees[$c['email']] ??= $raison;
        }
    }

    /**
     * @param  array<string, string>  $exclues
     * @param  list<Candidat>  $candidats
     * @param  array<string, ?string>  $verdicts
     */
    private function noterIneligibles(array &$exclues, array $candidats, array $verdicts): void
    {
        foreach ($candidats as $c) {
            $motif = $verdicts[$c['email']] ?? null;
            if ($motif !== null) {
                $exclues[$c['email']] ??= $motif;
            }
        }
    }

    /**
     * @param  array<string, array{type: string, crm_ref: string, fonction: ?string, organisations: array<int, string>}>  $retenues
     * @param  array<string, string>  $exclues
     * @param  array<string, string>  $ecartees
     * @return array<string, mixed>
     */
    private function bilan(ReglageDestinataires $reglage, int $organisations, int $avecDestinataire, array $retenues, array $exclues, array $ecartees, ?int $echantillon, bool $presse = false): array
    {
        /** @var array<string, int> $parType */
        $parType = [self::GENERIQUE => 0, self::NOMINATIVE => 0];
        $partagees = 0;
        $regroupees = 0;
        $lignes = [];
        foreach ($retenues as $email => $r) {
            $parType[$r['type']] = ($parType[$r['type']] ?? 0) + 1;
            $n = count($r['organisations']);
            if ($n > 1) {
                $partagees++;
                $regroupees += $n - 1;
            }
            if ($echantillon === null || count($lignes) < $echantillon) {
                $lignes[] = [
                    'email' => (string) $email,
                    'type' => $r['type'],
                    'crm_ref' => $r['crm_ref'],
                    'fonction' => $r['fonction'],
                    'nb_organisations' => $n,
                    'organisations' => array_map(
                        static fn (int $id, string $nom): array => ['id' => $id, 'nom' => $nom],
                        array_keys($r['organisations']),
                        array_values($r['organisations']),
                    ),
                ];
            }
        }

        $parMotif = array_fill_keys(array_values(array_diff(EligibiliteAdresse::MOTIFS, [EligibiliteAdresse::DEJA_INFORMEE])), 0);
        if ($presse) {
            // Audience presse : les motifs de provenance, toujours dits.
            $parMotif += array_fill_keys(AdressePresseFiable::MOTIFS, 0);
        }
        foreach ($exclues as $motif) {
            $parMotif[$motif] = ($parMotif[$motif] ?? 0) + 1;
        }
        $parRaison = array_fill_keys(self::ECARTEES, 0);
        foreach ($ecartees as $raison) {
            $parRaison[$raison] = ($parRaison[$raison] ?? 0) + 1;
        }

        return [
            'reglage' => $reglage->enTableau(),
            'organisations' => $organisations,
            'organisations_avec_destinataire' => $avecDestinataire,
            'organisations_sans_destinataire' => $organisations - $avecDestinataire,
            'destinataires' => count($retenues),
            'par_type' => $parType,
            'adresses_partagees_entre_organisations' => $partagees,
            'doublons_evites' => $regroupees,
            'exclues' => $parMotif,
            'exclues_total' => count($exclues),
            'ecartees_par_le_reglage' => $parRaison,
            'ecartees_total' => count($ecartees),
            'lignes' => $lignes,
        ];
    }
}
