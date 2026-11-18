<?php

namespace App\Envoi\Creation;

use App\Crm\Opposition\StatutOpposition;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\FiltreOrigine;
use App\Envoi\Campagnes\FiltreOrigineContenu;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Campagnes\SelectionRefusee;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assistant de création (R11) : un brouillon est une ligne `envoi_campagnes` au statut « brouillon ». Rien ici ne touche
 * `sequence_id`, `envoi_sequences` ni `envoi_inscriptions`, et rien n'envoie. Les étapes vont dans `saisie.contenu`
 * (la cible et les boîtes aussi dans leurs colonnes, sous les contraintes de la base).
 */
final class AssistantCreation
{
    public const ETAPES_MAX = 5;

    /** Clés lues par chaque étape ; les autres sont ignorées. */
    private const CLES = [1 => ['nom', 'cible'], 2 => ['sequence', 'repli'], 3 => ['boites', 'plafond_jour'], 4 => ['depart_immediat', 'debut_le', 'jours', 'heures'], 5 => []];

    private const JOURS = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];

    /** Échéance globale de l'estimation (comptage du total + opposées), en secondes. */
    public const ECHEANCE_SECONDES = 3;

    /** Plafond de lignes examinées pour l'estimation : au-delà, 503 explicite plutôt qu'un chiffre inventé. */
    public const LIGNES_MAX = 200000;

    /** Estimation « joignables » : nombre de lots de 500 membres lus au plus (au-delà : `complet` faux, aucune extrapolation). */
    public const LOTS_MAX = 10;

    /**
     * @param  (\Closure(): (int|float))|null  $horloge  nanosecondes (hrtime) ; injectable pour les tests
     * @param  SelectionDestinataires|null  $selection  lecteur des lots « joignables » ; null = `new SelectionDestinataires` (production) ; injectable pour les tests
     */
    public function __construct(
        private readonly DepotCampagnes $depot,
        private readonly ?\Closure $horloge = null,
        private readonly int $lignesMax = self::LIGNES_MAX,
        private readonly ?SelectionDestinataires $selection = null,
    ) {}

    /** @return array<string, mixed> */
    public function creer(string $ws, string $auteur, string $nom): array
    {
        $id = $this->depot->sousVerrou($ws, null, fn () => $this->depot->creer($ws, $nom, ['type' => 'liste', 'reference' => '0', 'libelle' => 'À choisir'], [], 1, ['contenu' => [], 'etapes_faites' => []], $auteur));

        return $this->presenter($this->lire($ws, $id));
    }

    public function lire(string $ws, string $id): \stdClass
    {
        return $this->depot->lire($ws, $id) ?? throw ErreurCreation::introuvable();
    }

    /**
     * @param  array<string, mixed>  $corps
     * @return array{0: array<string, mixed>, 1: int}
     */
    public function enregistrerEtape(string $ws, string $id, int $etape, ?int $version, array $corps): array
    {
        if ($etape < 1 || $etape > self::ETAPES_MAX) {
            throw ErreurCreation::introuvable();
        }
        if ($version === null) {
            throw new ErreurCreation('version_requise', 'L\'en-tête If-Match (version du brouillon lue) est obligatoire.', 428);
        }

        return $this->depot->sousVerrou($ws, $id, fn () => $this->enregistrerEtapeVerrouillee($ws, $id, $etape, $version, $corps));
    }

    /**
     * @param  array<string, mixed>  $corps
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function enregistrerEtapeVerrouillee(string $ws, string $id, int $etape, int $version, array $corps): array
    {
        $c = $this->lire($ws, $id);
        if ($c->statut !== 'brouillon') {
            throw ErreurCreation::conflit('campagne_figee', 'Seul un brouillon se modifie : cette campagne est en cours de validation.');
        }
        $saisie = json_decode($c->saisie, true) ?: [];
        $contenu = $saisie['contenu'] ?? [];
        $changes = [];
        foreach (self::CLES[$etape] as $cle) {
            if (array_key_exists($cle, $corps)) {
                $contenu[$cle] = $corps[$cle];
            }
        }
        if ($etape === 1) {
            if (array_key_exists('nom', $corps)) {
                $changes['nom'] = $corps['nom'];
            }
            if (array_key_exists('cible', $corps)) {
                $contenu['cible'] = $this->cible($corps['cible']);
                if (array_key_exists('origine', $contenu['cible'])) {
                    [$contenu['cible']['origine'], $changes['filtre_origine'], $changes['filtre_origine_contenu']] = $this->filtreOrigine($ws, $contenu['cible']['origine']);
                }
                $this->refuserOpposes($ws, $contenu['cible']);
                $changes['cible'] = array_intersect_key($contenu['cible'], array_flip(['type', 'reference', 'libelle']));
            }
        } elseif ($etape === 2 && array_key_exists('sequence', $corps)) {
            $changes['nb_etapes'] = $this->sequence($corps['sequence']);
        } elseif ($etape === 3) {
            if (array_key_exists('boites', $corps)) {
                $changes['boites'] = array_values((array) $corps['boites']);
            }
            if (array_key_exists('plafond_jour', $corps) && (! is_int($corps['plafond_jour']) || $corps['plafond_jour'] < 1 || $corps['plafond_jour'] > 300)) {
                throw ErreurCreation::invalide('validation', 'Le plafond par jour est un entier de 1 à 300.');
            }
        } elseif ($etape === 4) {
            $this->programmation($corps);
        }
        $saisie['contenu'] = $contenu;
        $saisie['etapes_faites'] = array_values(array_unique([...($saisie['etapes_faites'] ?? []), $etape]));
        sort($saisie['etapes_faites']);
        $changes['saisie'] = $saisie;
        try {
            $nouvelle = $this->depot->modifier($ws, $id, $version, $changes);
        } catch (CampagneRefusee $e) {
            throw new ErreurCreation($e->getCode() === 409 ? 'conflit_version' : 'validation', $e->getMessage(), $e->getCode());
        }

        return [$this->presenter($this->lire($ws, $id)), $nouvelle];
    }

    /**
     * Forme `Brouillon` du contrat.
     *
     * @return array<string, mixed>
     */
    public function presenter(\stdClass $c): array
    {
        $saisie = json_decode($c->saisie, true) ?: [];
        $fait = $saisie['etapes_faites'] ?? [];
        $valide = in_array($c->statut, ['a_valider', 'programmee'], true);
        $limite = $c->statut === 'programmee' ? $this->finAnnulation($c) : null;
        $contenu = $saisie['contenu'] ?? [];
        if (isset($contenu['cible']) && is_array($contenu['cible'])) {
            // La COLONNE fait foi : la saisie ne sert qu'à l'écran et n'est jamais relue pour décider (ni ici, ni à la sélection).
            $contenu['cible']['origine'] = $this->origineDesColonnes($c);
        }

        return [
            'id' => $c->id, 'nom' => $c->nom, 'version' => (int) $c->version, 'statut' => $c->statut,
            'etape_courante' => min(self::ETAPES_MAX, ($fait === [] ? 0 : max($fait)) + 1), 'etapes_faites' => $fait,
            'contenu' => (object) $contenu,
            'mis_a_jour_le' => Carbon::parse($c->maj_le)->toIso8601String(),
            'campagne_id' => $valide ? $c->id : null,
            'annulable_jusqua' => $limite?->toIso8601String(),
        ];
    }

    /** Fin de la fenêtre d'annulation (5 minutes après la validation), lue dans le journal. */
    public function finAnnulation(\stdClass $c): ?Carbon
    {
        $le = WorkspaceContext::run($c->workspace_id, fn () => DB::table('envoi_campagnes_journal')->where('campagne_id', $c->id)
            ->where('transition', 'a_valider>programmee')->orderByDesc('id')->value('cree_le'));

        return $le === null ? null : Carbon::parse($le)->addMinutes(ValidationCampagne::ANNULATION_MINUTES);
    }

    /** @return array<string, mixed> */
    private function cible(mixed $cible): array
    {
        if (! is_array($cible) || ! in_array($cible['type'] ?? null, ['liste', 'audience', 'etiquette', 'carnet'], true)
            || ! is_string($cible['reference'] ?? null) || trim($cible['reference']) === '') {
            throw ErreurCreation::invalide('validation', 'La cible a un type connu (liste, audience, etiquette, carnet) et une référence.');
        }
        if (str_contains(json_encode($cible, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '@')) {
            throw ErreurCreation::invalide('validation', 'La cible ne contient aucune adresse e-mail : choisissez une liste, une audience ou une étiquette.');
        }
        $cible['libelle'] = is_string($cible['libelle'] ?? null) && trim($cible['libelle']) !== '' ? $cible['libelle'] : $cible['reference'];

        return $cible;
    }

    /**
     * Filtre d'origine de l'étape 1 (`cible.origine`) : null = retrait des DEUX colonnes (permis même porte fermée, c'est un choix du
     * fondateur) ; sinon un objet de clés `reseaux` (colonne `filtre_origine`) et/ou `mots`, `guides` (colonne `filtre_origine_contenu`),
     * forme exacte, ET porte ouverte dans l'espace (sinon refus : ignorer un filtre élargirait la cible). Les deux colonnes sont rendues
     * ensemble pour une SEULE écriture (`version + 1`). La COLONNE fait foi.
     *
     * @return array{0: array<string, list<string>>|null, 1: array{reseaux: list<string>}|null, 2: array{mots?: list<string>, guides?: list<string>}|null} [valeur gardée dans la saisie, colonne réseau, colonne contenu]
     */
    private function filtreOrigine(string $ws, mixed $origine): array
    {
        if ($origine === null) {
            return [null, null, null];
        }
        try {
            if (! is_array($origine) || $origine === [] || array_diff(array_keys($origine), ['reseaux', 'mots', 'guides']) !== []) {
                throw new \InvalidArgumentException('forme');
            }
            $reseaux = array_key_exists('reseaux', $origine) ? FiltreOrigine::depuisColonne(['reseaux' => $origine['reseaux']]) : null;
            $partieContenu = array_intersect_key($origine, array_flip(['mots', 'guides']));
            $contenu = $partieContenu === [] ? null : FiltreOrigineContenu::depuisSaisie($partieContenu);
        } catch (\InvalidArgumentException) {
            throw ErreurCreation::invalide('validation', 'Le filtre d\'origine est {"reseaux": [...], "mots": [...], "guides": [...]} (au moins une clé) : 1 à ' . FiltreOrigine::RESEAUX_MAX . ' éléments par clé (minuscules, chiffres, . _ -).');
        }
        if (! FiltreOrigine::actif($ws)) {
            throw ErreurCreation::invalide('filtre_origine_inactif', 'Le filtre par réseau, mot ou guide d\'origine est inactif dans cet espace : retirez-le ou attendez son ouverture.');
        }
        $colReseaux = $reseaux?->enColonne();
        $colContenu = $contenu?->enColonne();

        return [($colReseaux ?? []) + ($colContenu ?? []), $colReseaux, $colContenu];
    }

    /**
     * `cible.origine` rendu depuis les COLONNES (jamais depuis la saisie) : fusion de `filtre_origine` et `filtre_origine_contenu`, null si aucune.
     *
     * @return array<string, list<string>>|null
     */
    private function origineDesColonnes(\stdClass $c): ?array
    {
        $reseaux = isset($c->filtre_origine) ? json_decode((string) $c->filtre_origine, true) : null;
        $contenu = isset($c->filtre_origine_contenu) ? json_decode((string) $c->filtre_origine_contenu, true) : null;
        $fusion = (is_array($reseaux) ? $reseaux : []) + (is_array($contenu) ? $contenu : []);

        return $fusion === [] ? null : $fusion;
    }

    private function sequence(mixed $sequence): int
    {
        if (! is_array($sequence) || ! array_is_list($sequence) || $sequence === [] || count($sequence) > 3) {
            throw ErreurCreation::invalide('validation', 'La séquence compte 1 à 3 messages (la base n\'en accepte pas plus pour le moment).');
        }
        foreach ($sequence as $i => $e) {
            if (! is_array($e) || ($e['etape'] ?? null) !== $i + 1 || ! is_string($e['message_id'] ?? null)
                || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $e['message_id'])
                || ! is_int($e['delai_jours'] ?? null) || $e['delai_jours'] < 0) {
                throw ErreurCreation::invalide('validation', 'Chaque message de la séquence a son numéro, un message type et un délai en jours.');
            }
        }

        return count($sequence);
    }

    /** @param array<string, mixed> $c */
    private function programmation(array $c): void
    {
        $ok = (! isset($c['jours']) || (is_array($c['jours']) && array_diff($c['jours'], self::JOURS) === []))
            && (! isset($c['heures']) || (is_array($c['heures']) && preg_match('/^\d{2}:\d{2}$/', (string) ($c['heures']['de'] ?? '')) && preg_match('/^\d{2}:\d{2}$/', (string) ($c['heures']['a'] ?? ''))))
            && (! isset($c['depart_immediat']) || is_bool($c['depart_immediat']))
            && (! isset($c['debut_le']) || (is_string($c['debut_le']) && strtotime($c['debut_le']) !== false));
        if (! $ok) {
            throw ErreurCreation::invalide('validation', 'Programmation invalide : jours (lun…dim), heures HH:MM, début daté.');
        }
    }

    /**
     * Mesure bornée : échéance GLOBALE de 3 s (total + opposées, contrôlée entre les lots) et plafond de lignes examinées ;
     * trop long ou trop gros = 503 explicite, jamais un chiffre inventé.
     *
     * @param  array<string, mixed>  $cible
     * @return array<string, mixed>
     */
    public function estimer(string $ws, array $cible, ?string $brouillonId = null): array
    {
        if ($brouillonId !== null) {
            $vraie = $this->estimerJoignables($ws, $cible, $brouillonId);
            if ($vraie !== null) {
                return $vraie;
            }
        }
        [$total, $opposees] = $this->mesurer($ws, (string) ($cible['type'] ?? ''), (string) ($cible['reference'] ?? ''));

        // Les autres exclusions ne sont pas évaluées : `exclusions_evaluees` reste faux (booléen global du contrat), jamais un zéro
        // inventé pour elles. Aucun motif par personne n'est rendu.

        return ['total' => $total, 'exclus' => $opposees, 'restant' => max(0, $total - $opposees), 'exclusions' => ['opposition' => $opposees], 'estimation' => true, 'exclusions_evaluees' => false];
    }

    /**
     * B8-C — « joignables » vrais : la cible demandée est celle enregistrée dans le brouillon ; on lit les lots de la sélection réelle
     * (500 membres, lecture seule, aucun DNS, aucun verdict écrit) jusqu'à la fin, l'échéance globale ou LOTS_MAX lots.
     * Verdicts : lus seulement. Jamais d'extrapolation : `complet` faux = « au moins N sur M examinés ». Null = voie non applicable.
     *
     * @param  array<string, mixed>  $cible
     * @return array<string, mixed>|null
     */
    private function estimerJoignables(string $ws, array $cible, string $id): ?array
    {
        $type = (string) ($cible['type'] ?? '');
        $reference = (string) ($cible['reference'] ?? '');
        $c = $this->lire($ws, $id);
        $enregistree = json_decode((string) $c->cible, true);
        if (! in_array($type, ['liste', 'audience', 'etiquette'], true) || ! is_array($enregistree)
            || ($enregistree['type'] ?? null) !== $type || (string) ($enregistree['reference'] ?? '') !== $reference) {
            return null;
        }
        $saisie = json_decode((string) $c->saisie, true);
        $ids = is_array($saisie) && is_array($saisie['contenu'] ?? null) && is_array($saisie['contenu']['cible'] ?? null) ? ($saisie['contenu']['cible']['contact_ids'] ?? null) : null;
        if (is_array($ids) && $ids !== []) {
            return null;
        }

        $echeance = $this->maintenant() + self::ECHEANCE_SECONDES * 1_000_000_000;
        $selection = $this->selection ?? new SelectionDestinataires;
        $somme = array_fill_keys([...SelectionDestinataires::MOTIFS, 'sans_contact_generique_disponible', 'adresse_invalide', 'adresse_risquee'], 0);
        $total = 0;
        $retenus = 0;
        $apres = 0;
        $complet = false;
        for ($n = 1; $n <= self::LOTS_MAX; $n++) {
            if ($n > 1 && $this->maintenant() > $echeance) {
                break;
            }
            try {
                $lot = $selection->lot($ws, $id, 'entreprises', $apres, SelectionDestinataires::TAILLE_MAX);
            } catch (SelectionRefusee $e) {
                if ($e->motif === 'filtre_origine_inactif') {
                    throw ErreurCreation::conflit('filtre_origine_inactif', 'Le filtre par réseau d\'origine est inactif dans cet espace : retirez-le ou attendez son ouverture.');
                }
                if ($e->motif === 'lecture_trop_longue') {
                    throw new ErreurCreation('estimation_trop_longue', 'L\'estimation a pris trop de temps : réessayez ou choisissez une cible plus petite.', 503);
                }
                if ($e->reessayable) {
                    throw new ErreurCreation('estimation_impossible', 'L\'estimation n\'a pas pu être lue : réessayez.', 503);
                }

                return null; // cible introuvable ou non prise en charge : ancienne estimation
            }
            foreach ($lot->compteurs as $cle => $v) {
                if (isset($somme[$cle])) {
                    $somme[$cle] += $v;
                }
            }
            $total += $lot->compteurs['membres'];
            $verdicts = $this->verdictsExistants($ws, array_map(static fn ($d): string => $d->empreinte, $lot->destinataires));
            foreach ($lot->destinataires as $d) {
                $v = $verdicts[$d->empreinte] ?? null;
                if ($v === 'invalide') {
                    $somme['adresse_invalide']++;
                } elseif ($v === 'risque') {
                    $somme['adresse_risquee']++;
                } else {
                    $retenus++;
                }
            }
            if ($lot->curseurSuivant === null) {
                $complet = true;
                break;
            }
            $apres = $lot->curseurSuivant;
        }

        return ['total' => $total, 'exclus' => $total - $retenus, 'restant' => $retenus, 'exclusions' => $somme, 'estimation' => true, 'exclusions_evaluees' => true, 'complet' => $complet];
    }

    /**
     * Dernier verdict connu par empreinte (lecture seule, aucun appel réseau).
     *
     * @param  list<string>  $empreintes
     * @return array<string, string>
     */
    private function verdictsExistants(string $ws, array $empreintes): array
    {
        if ($empreintes === []) {
            return [];
        }
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_verdicts_adresse')->where('workspace_id', $ws)->whereIn('empreinte_adresse', $empreintes)
            ->orderByDesc('verifie_le')->orderByDesc('id')->get(['empreinte_adresse', 'verdict']));
        $sortie = [];
        foreach ($lignes as $l) {
            $sortie[trim((string) $l->empreinte_adresse)] ??= $l->verdict;
        }

        return $sortie;
    }

    /**
     * Total et opposées lus dans UNE seule transaction (instantané cohérent : exclus ne dépasse jamais total), lecture seule.
     *
     * @return array{0: int, 1: int} [total, opposées]
     */
    private function mesurer(string $ws, string $type, string $reference): array
    {
        $trop = static fn (): ErreurCreation => new ErreurCreation('estimation_trop_longue', 'L\'estimation a pris trop de temps : réessayez ou choisissez une cible plus petite.', 503);
        $echeance = $this->maintenant() + self::ECHEANCE_SECONDES * 1_000_000_000;
        $racine = DB::transactionLevel() === 0;
        try {
            return WorkspaceContext::run($ws, fn (): array => DB::transaction(function () use ($ws, $type, $reference, $echeance, $racine, $trop): array {
                if ($racine) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                }
                DB::statement("SET LOCAL statement_timeout = '3000'");
                $total = $this->compterDansTransaction($ws, $type, $reference);
                $opposees = $this->compterOpposees($ws, $type, $reference, $echeance, $trop);

                return [$total, min($opposees, $total)];
            }));
        } catch (QueryException $e) {
            if ($e->getCode() === '57014') {
                throw $trop();
            }
            throw $e;
        }
    }

    private function maintenant(): int|float
    {
        return $this->horloge === null ? hrtime(true) : ($this->horloge)();
    }

    /**
     * Nombre de membres de la cible dont l'adresse (ou le SIREN) est opposée, lues par lots sur la clé réelle de la table
     * parcourue (jamais companies.id, jamais N+1). Échéance globale contrôlée entre les lots ; plafond de lignes examinées.
     * Lecture seule ; mêmes filtres d'espace que `compter`. Audience et étiquette : adresse nominative du contact (s'il y en a un)
     * ET adresse générique de l'entreprise ET SIREN ; un membre est exclu si l'une d'elles est opposée.
     */
    private function compterOpposees(string $ws, string $type, string $reference, int|float $echeance, \Closure $trop): int
    {
        $numerique = ctype_digit($reference) && strlen($reference) < 18;
        $colonneCle = 'm.id';
        $requete = match (true) {
            $type === 'liste' && $numerique => DB::table('listes_manuelles_membres as m')
                ->leftJoin('contacts', 'contacts.id', '=', 'm.contact_id')
                ->leftJoin('companies', 'companies.id', '=', DB::raw('COALESCE(m.company_id, contacts.company_id)'))
                ->where('m.workspace_id', $ws)->where('m.liste_id', (int) $reference)->whereNull('m.retire_le')
                ->select(['m.id as cle', DB::raw('COALESCE(contacts.email, companies.email_generic) as email'), DB::raw('NULL as email2'), 'companies.siren as siren']),
            $type === 'audience' && $numerique => DB::table('audience_members as m')->join('companies', 'companies.id', '=', 'm.company_id')
                ->leftJoin('contacts', fn ($j) => $j->on('contacts.id', '=', 'm.contact_id')->where('contacts.workspace_id', '=', $ws))
                ->where('m.workspace_id', $ws)->where('m.audience_id', (int) $reference)
                ->select(['m.id as cle', 'contacts.email as email', 'companies.email_generic as email2', 'companies.siren as siren']),
            $type === 'etiquette' => $this->requeteEtiquette($ws, $reference),
            $type === 'carnet' && $numerique => DB::table('companies')->where('workspace_id', $ws)->where('id', (int) $reference)
                ->select(['id as cle', 'email_generic as email', DB::raw('NULL as email2'), 'siren as siren']), // G4-ENVOI : une opposition survit à la corbeille, cette lecture ignore deleted_at volontairement
            default => null,
        };
        if ($requete === null) {
            return 0;
        }
        if ($type === 'etiquette') {
            $colonneCle = 'company_tag.company_id';
        } elseif ($type === 'carnet') {
            $colonneCle = 'id';
        }

        $n = 0;
        $examinees = 0;
        $requete->chunkById(1000, function ($lignes) use ($ws, &$n, &$examinees, $echeance, $trop): bool {
            if ($this->maintenant() > $echeance) {
                throw $trop();
            }
            $examinees += count($lignes);
            if ($examinees > $this->lignesMax) {
                throw $trop();
            }
            $paquet = [];
            foreach ($lignes as $i => $l) {
                $paquet['a' . $i] = ['email' => $l->email, 'siren' => $l->siren];
                if ($l->email2 !== null) {
                    $paquet['b' . $i] = ['email' => $l->email2, 'siren' => null];
                }
            }
            $statuts = (new StatutOpposition)->pourLignes($ws, $paquet);
            foreach ($lignes as $i => $l) {
                if (($statuts['a' . $i]['opposee'] ?? false) || ($statuts['b' . $i]['opposee'] ?? false)) {
                    $n++;
                }
            }

            return true;
        }, $colonneCle, 'cle');

        return $n;
    }

    /** Étiquette : on résout d'abord l'étiquette de l'espace, puis on parcourt `company_tag` sur sa clé (company_id). */
    private function requeteEtiquette(string $ws, string $slug): ?Builder
    {
        $tag = DB::table('tags')->where('workspace_id', $ws)->where('slug', $slug)->value('id');
        if ($tag === null) {
            return null;
        }

        return DB::table('company_tag')->join('companies', 'companies.id', '=', 'company_tag.company_id')
            ->where('company_tag.tag_id', $tag)
            ->select(['company_tag.company_id as cle', DB::raw('NULL as email'), 'companies.email_generic as email2', 'companies.siren as siren']);
    }

    /**
     * Identifiants passés explicitement dans la cible (`contact_ids`, ou le carnet visé) : un seul opposé ⇒ 422 `adresse_opposee`.
     * Le refus ne dit ni lequel ni pourquoi. Les identifiants d'un autre espace sont ignorés (cloisonnement).
     *
     * @param  array<string, mixed>  $cible
     */
    private function refuserOpposes(string $ws, array $cible): void
    {
        $ids = $cible['contact_ids'] ?? [];
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 500 || array_filter($ids, static fn ($i): bool => ! is_int($i) || $i < 1) !== []) {
            throw ErreurCreation::invalide('validation', 'contact_ids est une liste de 500 identifiants entiers au plus.');
        }
        $lignes = [];
        $trouves = 0;
        $absente = false;
        WorkspaceContext::run($ws, function () use ($ws, $ids, $cible, &$lignes, &$trouves, &$absente): void {
            if ($ids !== []) {
                foreach (DB::table('contacts')->leftJoin('companies', 'companies.id', '=', 'contacts.company_id')->where('contacts.workspace_id', $ws)
                    ->whereIn('contacts.id', $ids)->get(['contacts.id', 'contacts.email', 'companies.siren']) as $c) {
                    $lignes['c' . $c->id] = ['email' => $c->email, 'siren' => $c->siren];
                    $trouves++;
                }
            }
            $ref = (string) ($cible['reference'] ?? '');
            if (($cible['type'] ?? null) === 'carnet' && ctype_digit($ref) && strlen($ref) < 18) {
                // G4-ENVOI : une opposition survit à la corbeille, ces deux lectures ignorent deleted_at volontairement
                $e = DB::table('companies')->where('workspace_id', $ws)->where('id', (int) $ref)->first(['siren', 'email_generic']);
                if ($e === null) {
                    $absente = true;
                } else {
                    $lignes['carnet'] = ['email' => $e->email_generic, 'siren' => $e->siren];
                }
            }
        });
        // Un identifiant inexistant ou d'un autre espace : même refus, sans dire lequel (cloisonnement).
        if ($absente || $trouves !== count(array_unique($ids))) {
            throw ErreurCreation::invalide('validation', 'contact_ids ou la fiche choisie contient un identifiant inconnu.');
        }
        if ($lignes === []) {
            return;
        }
        if (array_filter((new StatutOpposition)->pourLignes($ws, $lignes), static fn (array $s): bool => $s['opposee']) !== []) {
            throw ErreurCreation::invalide('adresse_opposee', 'Une adresse choisie est opposée : elle ne peut pas être sélectionnée pour un envoi.');
        }
    }

    public function compter(string $ws, string $type, string $reference): int
    {
        return WorkspaceContext::run($ws, fn (): int => DB::transaction(function () use ($ws, $type, $reference): int {
            DB::statement("SET LOCAL statement_timeout = '3000'");

            return $this->compterDansTransaction($ws, $type, $reference);
        }));
    }

    /** À appeler dans une transaction déjà ouverte (et un contexte d'espace posé). */
    private function compterDansTransaction(string $ws, string $type, string $reference): int
    {
        $numerique = ctype_digit($reference) && strlen($reference) < 18;

        return match (true) {
            $type === 'liste' && $numerique => DB::table('listes_manuelles_membres')->where('workspace_id', $ws)->where('liste_id', (int) $reference)->whereNull('retire_le')->count(),
            $type === 'audience' && $numerique => DB::table('audience_members')->where('workspace_id', $ws)->where('audience_id', (int) $reference)->count(),
            $type === 'etiquette' => DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')->where('tags.workspace_id', $ws)->where('tags.slug', $reference)->count(),
            $type === 'carnet' && $numerique => DB::table('companies')->where('workspace_id', $ws)->where('id', (int) $reference)->count(),
            default => 0,
        };
    }

    /**
     * Les 7 contrôles. Un contrôle non évalué ici est « attention », jamais « ok » : le constat peut freiner, jamais libérer.
     *
     * @return array{controles: list<array<string, string|null>>, bloquants: int}
     */
    public function controles(string $ws, \stdClass $c): array
    {
        $contenu = (json_decode($c->saisie, true) ?: [])['contenu'] ?? [];
        $c7 = static fn (string $code, string $libelle, string $etat, ?string $detail = null): array => compact('code', 'libelle', 'etat', 'detail');
        $nonEvalue = 'Contrôle fait par la porte d\'envoi au moment du départ ; non évalué à la création.';

        $cible = $contenu['cible'] ?? null;
        $total = 0;
        $opposes = null; // null = non évalué (estimation trop longue)
        if ($cible !== null) {
            try {
                [$total, $opposes] = $this->mesurer($ws, $cible['type'], $cible['reference']);
            } catch (ErreurCreation) {
                $total = $this->compter($ws, $cible['type'], $cible['reference']);
            }
        }
        $restant = $total - ($opposes ?? 0);
        $boites = $this->boitesChoisies($ws, trim((string) $c->boites, '{}') === '' ? [] : explode(',', trim((string) $c->boites, '{}')));
        $plafond = $contenu['plafond_jour'] ?? null;
        $capacite = array_sum(array_column($boites['trouvees'], 'limite_jour'));

        $liste = [
            $c7('cible_non_vide', 'La cible contient des personnes', $restant > 0 ? 'ok' : 'bloquant', $restant > 0 ? null : 'Choisissez une cible qui n\'est pas vide (les personnes opposées ne comptent pas).'),
            match (true) {
                $opposes === null || $total === 0 => $c7('opposition_retiree', 'Les oppositions sont retirées', 'attention', $nonEvalue),
                $opposes === 0 => $c7('opposition_retiree', 'Les oppositions sont retirées', 'ok'),
                default => $c7('opposition_retiree', 'Les oppositions sont retirées', 'attention', $opposes . ' personne(s) opposée(s) dans la cible : elles sont écartées de l\'envoi.'),
            },
            $c7('lien_desinscription', 'Lien de désinscription présent', 'attention', $nonEvalue),
            $c7('identite_expediteur', 'Identité de l\'expéditeur', 'attention', $nonEvalue),
            $c7(
                'boites_pretes',
                'Boîtes d\'envoi prêtes',
                $boites['manquantes'] === 0 && $boites['trouvees'] !== [] && $boites['actives'] ? 'ok' : 'bloquant',
                $boites['trouvees'] === [] ? 'Choisissez au moins une boîte.' : ($boites['manquantes'] > 0 || ! $boites['actives'] ? 'Une boîte choisie est introuvable ou en pause.' : null),
            ),
            $c7('domaines_sains', 'Domaines sains', 'attention', $nonEvalue),
            $c7(
                'plafond_respecte',
                'Plafond journalier respecté',
                $plafond === null ? 'attention' : ($plafond <= $capacite ? 'ok' : 'bloquant'),
                $plafond === null ? 'Aucun plafond saisi.' : ($plafond <= $capacite ? null : 'Le plafond dépasse la capacité des boîtes choisies.'),
            ),
        ];

        array_push($liste, ...$this->controlesFiltreOrigine($ws, $c, $c7));

        return ['controles' => $liste, 'bloquants' => count(array_filter($liste, static fn (array $x): bool => $x['etat'] === 'bloquant'))];
    }

    /**
     * Éléments « filtre par réseau » : AUCUN sans filtre. Filtre actif = ok ; inactif ou illisible = bloquant (la campagne ne partirait pas :
     * mieux vaut le dire avant). L'estimation ignore le filtre : toujours signalé.
     *
     * @param  \Closure(string, string, string, ?string=): array<string, string|null>  $c7
     * @return list<array<string, string|null>>
     */
    private function controlesFiltreOrigine(string $ws, \stdClass $c, \Closure $c7): array
    {
        $brut = $c->filtre_origine ?? null;
        $brutContenu = $c->filtre_origine_contenu ?? null;
        if ($brut === null && $brutContenu === null) {
            return [];
        }
        // Les DEUX colonnes sont couvertes : une forme illisible ou la porte fermée = bloquant (jamais ignoré).
        $parties = [];
        $filtre = null;
        $filtreContenu = null;
        try {
            $filtre = FiltreOrigine::depuisColonne($brut);
            $filtreContenu = FiltreOrigineContenu::depuisColonne($brutContenu);
            $actif = FiltreOrigine::actif($ws);
            if ($filtre !== null) {
                $parties[] = 'réseau ' . implode(', ', $filtre->reseaux);
            }
            foreach (['mots' => 'mot', 'guides' => 'guide'] as $cle => $nom) {
                if ($filtreContenu?->{$cle} !== null) {
                    $parties[] = $nom . ' ' . implode(', ', $filtreContenu->{$cle});
                }
            }
        } catch (\InvalidArgumentException) {
            $actif = false;
        }

        return [
            $actif && $parties !== []
                ? $c7('filtre_origine', 'Filtre par réseau d\'origine', 'ok', $filtreContenu === null ? 'Filtre par réseau : ' . implode(', ', $filtre->reseaux ?? []) : 'Filtre : ' . implode(' ; ', $parties))
                : $c7('filtre_origine', 'Filtre par réseau d\'origine', 'bloquant', 'Le filtre d\'origine est inactif : retirez-le ou attendez son ouverture'),
            $c7('filtre_origine_estimation', 'Estimation et filtre par réseau', 'attention', 'L\'estimation affichée ne tient pas compte du filtre ; le compte exact (hors réseau) apparaît à la préparation de l\'envoi'),
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array{trouvees: list<array<string, mixed>>, manquantes: int, actives: bool}
     */
    private function boitesChoisies(string $ws, array $ids): array
    {
        $lignes = $ids === [] ? [] : WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('workspace_id', $ws)->whereIn('id', $ids)->get(['id', 'etat', 'limite_jour'])->map(fn ($b) => (array) $b)->all());

        return ['trouvees' => array_values($lignes), 'manquantes' => count($ids) - count($lignes), 'actives' => array_filter($lignes, fn ($b) => $b['etat'] !== 'active') === []];
    }

    /** @return list<array<string, mixed>> */
    public function cibles(string $ws, ?string $recherche, ?string $entreprise, ?string $audience = null): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $recherche, $entreprise, $audience): array {
            $filtre = fn ($q, string $col) => $recherche === null || $recherche === '' ? $q : $q->where($col, 'ilike', '%' . addcslashes($recherche, '%_\\') . '%');
            $sortie = [];
            // B8-D : l'audience demandée (chiffres seulement) est TOUJOURS proposée, en tête, même hors des 50 premières par nom ;
            // hors espace, supprimée ou inactive : ignorée sans erreur (aucun oracle d'existence entre espaces).
            $demandee = null;
            if ($audience !== null && ctype_digit($audience) && strlen($audience) < 18) {
                $demandee = DB::table('email_audiences')->where('workspace_id', $ws)->whereNull('deleted_at')->where('is_active', true)->where('id', (int) $audience)->first(['id', 'name', 'member_count']);
                if ($demandee !== null) {
                    $sortie[] = ['type' => 'audience', 'reference' => (string) $demandee->id, 'libelle' => $demandee->name, 'total' => (int) $demandee->member_count, 'joignables' => null];
                }
            }
            $listes = $filtre(DB::table('listes_manuelles')->where('workspace_id', $ws)->whereNull('deleted_at'), 'nom')->orderBy('nom')->limit(50)->get(['id', 'nom']);
            foreach ($listes as $l) {
                $n = $this->compter($ws, 'liste', (string) $l->id);
                $sortie[] = ['type' => 'liste', 'reference' => (string) $l->id, 'libelle' => $l->nom, 'total' => $n, 'joignables' => null];
            }
            $audiences = $filtre(DB::table('email_audiences')->where('workspace_id', $ws)->whereNull('deleted_at')->where('is_active', true), 'name')->orderBy('name')->limit(50)->get(['id', 'name', 'member_count']);
            foreach ($audiences as $a) {
                if ($demandee !== null && (int) $a->id === (int) $demandee->id) {
                    continue;
                }
                $sortie[] = ['type' => 'audience', 'reference' => (string) $a->id, 'libelle' => $a->name, 'total' => (int) $a->member_count, 'joignables' => null];
            }
            $etiquettes = $filtre(DB::table('tags')->where('workspace_id', $ws), 'name')->orderBy('name')->limit(50)->get(['slug', 'name']);
            foreach ($etiquettes as $t) {
                $n = $this->compter($ws, 'etiquette', $t->slug);
                $sortie[] = ['type' => 'etiquette', 'reference' => $t->slug, 'libelle' => $t->name, 'total' => $n, 'joignables' => null];
            }
            if ($entreprise !== null && ctype_digit($entreprise) && strlen($entreprise) < 18) {
                $e = DB::table('companies')->where('workspace_id', $ws)->where('id', (int) $entreprise)->first(['id', 'denomination']);
                if ($e !== null) {
                    $sortie[] = ['type' => 'carnet', 'reference' => (string) $e->id, 'libelle' => $e->denomination, 'total' => 1, 'joignables' => null];
                }
            }

            return $sortie;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function boites(string $ws): array
    {
        return WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('workspace_id', $ws)->orderBy('adresse')->limit(200)->get(['id', 'adresse', 'etat', 'limite_jour'])
            ->map(fn ($b) => ['id' => $b->id, 'adresse' => $b->adresse, 'statut' => $b->etat === 'active' ? 'prete' : 'suspendue', 'quota_jour' => (int) $b->limite_jour])->values()->all());
    }

    /**
     * 3 fiches au plus, champs du contrat seulement ; jamais d'adresse e-mail. Une adresse opposée n'est jamais proposée
     * (les 200 premières fiches sont examinées, par lot).
     *
     * @return array<int, array<string, mixed>>
     */
    public function destinatairesExemples(string $ws): array
    {
        return WorkspaceContext::run($ws, function () use ($ws): array {
            $fiches = DB::table('contacts')->leftJoin('companies', 'companies.id', '=', 'contacts.company_id')
                ->where('contacts.workspace_id', $ws)->whereNull('contacts.deleted_at')->orderBy('contacts.id')->limit(200)
                ->get(['contacts.id', 'contacts.first_name', 'contacts.email', 'companies.denomination', 'companies.siren']);
            $lignes = [];
            foreach ($fiches as $f) {
                $lignes[$f->id] = ['email' => $f->email, 'siren' => $f->siren];
            }
            $statuts = (new StatutOpposition)->pourLignes($ws, $lignes);

            return $fiches->filter(static fn ($f): bool => ! $statuts[$f->id]['opposee'])->take(3)
                ->map(fn ($x) => ['id' => (string) $x->id, 'libelle' => 'Exemple ' . $x->id, 'prenom' => $x->first_name, 'organisation' => $x->denomination])->values()->all();
        });
    }
}
