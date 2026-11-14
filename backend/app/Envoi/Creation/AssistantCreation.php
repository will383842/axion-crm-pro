<?php

namespace App\Envoi\Creation;

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
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

    public function __construct(private readonly DepotCampagnes $depot) {}

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

        return [
            'id' => $c->id, 'nom' => $c->nom, 'version' => (int) $c->version, 'statut' => $c->statut,
            'etape_courante' => min(self::ETAPES_MAX, ($fait === [] ? 0 : max($fait)) + 1), 'etapes_faites' => $fait,
            'contenu' => (object) ($saisie['contenu'] ?? []),
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
     * Mesure bornée par le temps (3 s) : trop long = 503 explicite, jamais un chiffre inventé.
     *
     * @param  array<string, mixed>  $cible
     * @return array<string, mixed>
     */
    public function estimer(string $ws, array $cible): array
    {
        try {
            $total = $this->compter($ws, (string) ($cible['type'] ?? ''), (string) ($cible['reference'] ?? ''));
        } catch (QueryException $e) {
            if ($e->getCode() === '57014') {
                throw new ErreurCreation('estimation_trop_longue', 'L\'estimation a pris trop de temps : réessayez ou choisissez une cible plus petite.', 503);
            }
            throw $e;
        }

        // Les exclusions (opposition, clients, déjà contactés…) ne sont PAS encore évaluées : on le dit, on ne les compte pas à zéro en silence.
        return ['total' => $total, 'exclus' => 0, 'restant' => $total, 'exclusions' => (object) [], 'estimation' => true, 'exclusions_evaluees' => false];
    }

    public function compter(string $ws, string $type, string $reference): int
    {
        $numerique = ctype_digit($reference) && strlen($reference) < 18;

        return WorkspaceContext::run($ws, fn (): int => DB::transaction(function () use ($ws, $type, $reference, $numerique): int {
            DB::statement("SET LOCAL statement_timeout = '3000'");

            return match (true) {
                $type === 'liste' && $numerique => DB::table('listes_manuelles_membres')->where('workspace_id', $ws)->where('liste_id', (int) $reference)->whereNull('retire_le')->count(),
                $type === 'audience' && $numerique => DB::table('audience_members')->where('workspace_id', $ws)->where('audience_id', (int) $reference)->count(),
                $type === 'etiquette' => DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')->where('tags.workspace_id', $ws)->where('tags.slug', $reference)->count(),
                $type === 'carnet' && $numerique => DB::table('companies')->where('workspace_id', $ws)->where('id', (int) $reference)->count(),
                default => 0,
            };
        }));
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
        $total = $cible === null ? 0 : $this->compter($ws, $cible['type'], $cible['reference']);
        $boites = $this->boitesChoisies($ws, trim((string) $c->boites, '{}') === '' ? [] : explode(',', trim((string) $c->boites, '{}')));
        $plafond = $contenu['plafond_jour'] ?? null;
        $capacite = array_sum(array_column($boites['trouvees'], 'limite_jour'));

        $liste = [
            $c7('cible_non_vide', 'La cible contient des personnes', $total > 0 ? 'ok' : 'bloquant', $total > 0 ? null : 'Choisissez une cible qui n\'est pas vide.'),
            $c7('opposition_retiree', 'Les oppositions sont retirées', 'attention', $nonEvalue),
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

        return ['controles' => $liste, 'bloquants' => count(array_filter($liste, static fn (array $x): bool => $x['etat'] === 'bloquant'))];
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
    public function cibles(string $ws, ?string $recherche, ?string $entreprise): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $recherche, $entreprise): array {
            $filtre = fn ($q, string $col) => $recherche === null || $recherche === '' ? $q : $q->where($col, 'ilike', '%' . addcslashes($recherche, '%_\\') . '%');
            $sortie = [];
            $listes = $filtre(DB::table('listes_manuelles')->where('workspace_id', $ws)->whereNull('deleted_at'), 'nom')->orderBy('nom')->limit(50)->get(['id', 'nom']);
            foreach ($listes as $l) {
                $n = $this->compter($ws, 'liste', (string) $l->id);
                $sortie[] = ['type' => 'liste', 'reference' => (string) $l->id, 'libelle' => $l->nom, 'total' => $n, 'joignables' => $n];
            }
            $audiences = $filtre(DB::table('email_audiences')->where('workspace_id', $ws)->whereNull('deleted_at')->where('is_active', true), 'name')->orderBy('name')->limit(50)->get(['id', 'name', 'member_count']);
            foreach ($audiences as $a) {
                $sortie[] = ['type' => 'audience', 'reference' => (string) $a->id, 'libelle' => $a->name, 'total' => (int) $a->member_count, 'joignables' => (int) $a->member_count];
            }
            $etiquettes = $filtre(DB::table('tags')->where('workspace_id', $ws), 'name')->orderBy('name')->limit(50)->get(['slug', 'name']);
            foreach ($etiquettes as $t) {
                $n = $this->compter($ws, 'etiquette', $t->slug);
                $sortie[] = ['type' => 'etiquette', 'reference' => $t->slug, 'libelle' => $t->name, 'total' => $n, 'joignables' => $n];
            }
            if ($entreprise !== null && ctype_digit($entreprise) && strlen($entreprise) < 18) {
                $e = DB::table('companies')->where('workspace_id', $ws)->where('id', (int) $entreprise)->first(['id', 'denomination']);
                if ($e !== null) {
                    $sortie[] = ['type' => 'carnet', 'reference' => (string) $e->id, 'libelle' => $e->denomination, 'total' => 1, 'joignables' => 1];
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
     * 3 fiches au plus, champs du contrat seulement ; jamais d'adresse e-mail.
     *
     * @return array<int, array<string, mixed>>
     */
    public function destinatairesExemples(string $ws): array
    {
        return WorkspaceContext::run($ws, fn () => DB::table('contacts')->leftJoin('companies', 'companies.id', '=', 'contacts.company_id')
            ->where('contacts.workspace_id', $ws)->whereNull('contacts.deleted_at')->orderBy('contacts.id')->limit(3)
            ->get(['contacts.id', 'contacts.first_name', 'companies.denomination'])
            ->map(fn ($x) => ['id' => (string) $x->id, 'libelle' => 'Exemple ' . $x->id, 'prenom' => $x->first_name, 'organisation' => $x->denomination])->values()->all());
    }
}
