<?php

namespace App\Crm\Propositions;

use App\Crm\FichesProtegees;
use App\Crm\Taxonomy;
use App\Http\Controllers\Api\Crm\ATraiterController;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * FILE DE PROPOSITIONS (N13, 03/10/2026) — la règle, écrite UNE fois.
 *
 * Une information venue d'un TIERS (`apporteur`, `commercial`, `societe` —
 * `Taxonomy::FIELD_ORIGINS_TIERS`, futur canal Axion Partners) n'écrase
 * JAMAIS une valeur déjà présente sur une fiche :
 *
 *  - information vide → rien (un tiers qui ne dit rien n'efface rien) ;
 *  - valeur identique → rien ;
 *  - champ vide ET non protégé → rempli directement, `field_origins` note
 *    l'origine tiers ;
 *  - sinon → PROPOSITION en attente (`propositions_champs`), la fiche reste
 *    intacte. Le propriétaire (rôle owner) l'accepte ou la refuse.
 *
 * Un champ est PROTÉGÉ quand la personne l'a déclaré elle-même
 * (`field_origins` = `declared` : « le DÉCLARÉ gagne », une valeur tiers n'est
 * jamais une déclaration) ou quand la fiche est protégée
 * (`FichesProtegees` ; pour une personne, son entreprise) : un tel champ
 * n'est JAMAIS rempli par un automatisme, même vide — il devient une
 * proposition, et seul un humain décide.
 *
 * Accepter : la valeur est écrite, `field_origins` prend l'origine tiers, la
 * décision est tracée (proposition figée + journal d'audit chaîné). Refuser :
 * la fiche ne bouge pas, seule la décision est tracée. Le journal ne recopie
 * AUCUNE valeur (ni l'ancienne ni la nouvelle) : seulement le numéro, la
 * fiche et le champ.
 *
 * Rien n'est branché : aucune route publique n'appelle `proposer()`. Le
 * service est prêt pour le futur canal Partners.
 */
final class Propositions
{
    public const ENTREPRISE = 'entreprise';

    public const PERSONNE = 'personne';

    /** @var list<string> */
    public const ORIGINES = Taxonomy::FIELD_ORIGINS_TIERS;

    /** @var list<string> */
    public const STATUTS = ['en_attente', 'acceptee', 'refusee'];

    // Résultats de `proposer()`.
    public const IGNOREE = 'ignoree';

    public const IDENTIQUE = 'identique';

    public const REMPLI = 'rempli';

    public const PROPOSEE = 'proposee';

    public const DEJA_PROPOSEE = 'deja_proposee';

    /** La table de chaque type de fiche. */
    private const TABLES = [
        self::ENTREPRISE => 'companies',
        self::PERSONNE => 'contacts',
    ];

    /**
     * Les champs qu'un tiers peut renseigner, avec leur libellé à l'écran.
     *
     * Volontairement absents : les identifiants (SIREN, nom et prénom d'une
     * personne, qui fondent son empreinte de doublon), l'adresse e-mail d'une
     * personne, l'e-mail générique et le site d'une entreprise — chacun porte
     * sa propre vérification (adresse vérifiée, sites en quarantaine) qu'une
     * écriture ici contournerait.
     *
     * @var array<string, array<string, string>>
     */
    public const CHAMPS = [
        self::ENTREPRISE => [
            'denomination' => 'Nom',
            'address' => 'Adresse',
            'postcode' => 'Code postal',
            'city' => 'Ville',
            'phone' => 'Téléphone',
            'linkedin_url' => 'Page LinkedIn',
        ],
        self::PERSONNE => [
            'title' => 'Fonction',
            'role' => 'Rôle',
            'phone' => 'Téléphone',
            'linkedin_url' => 'Profil LinkedIn',
        ],
    ];

    public function __construct(private readonly AuditHashChain $audit) {}

    public static function libelleChamp(string $entite, string $champ): string
    {
        return self::CHAMPS[$entite][$champ] ?? $champ;
    }

    /**
     * Une information venue d'un tiers pour un champ d'une fiche.
     *
     * @return self::IGNOREE|self::IDENTIQUE|self::REMPLI|self::PROPOSEE|self::DEJA_PROPOSEE
     *
     * @throws InvalidArgumentException fiche, champ ou origine inconnus
     */
    public function proposer(
        string $workspaceId,
        string $entite,
        int $entiteId,
        string $champ,
        ?string $valeur,
        string $origine,
        ?string $referenceExterne = null,
    ): string {
        $table = self::TABLES[$entite] ?? throw new InvalidArgumentException('Type de fiche inconnu : ' . json_encode($entite));
        if (! array_key_exists($champ, self::CHAMPS[$entite])) {
            throw new InvalidArgumentException('Champ non proposable : ' . json_encode($champ));
        }
        if (! in_array($origine, self::ORIGINES, true)) {
            throw new InvalidArgumentException('Origine tiers inconnue : ' . json_encode($origine));
        }
        $valeur = $valeur === null ? '' : trim($valeur);
        if ($valeur === '') {
            return self::IGNOREE;
        }
        $reference = $referenceExterne === null || trim($referenceExterne) === '' ? null : trim($referenceExterne);

        $resultat = WorkspaceContext::run($workspaceId, fn (): string => DB::transaction(function () use (
            $workspaceId,
            $entite,
            $table,
            $entiteId,
            $champ,
            $valeur,
            $origine,
            $reference,
        ): string {
            $fiche = DB::table($table)
                ->where('workspace_id', $workspaceId)
                ->where('id', $entiteId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if (! $fiche instanceof stdClass) {
                throw new InvalidArgumentException("Fiche {$entite} {$entiteId} introuvable dans cet espace.");
            }

            $actuelle = self::texte($fiche->{$champ} ?? null);
            if ($actuelle !== null && self::comparable($champ, $actuelle) === self::comparable($champ, $valeur)) {
                return self::IDENTIQUE;
            }

            $origines = self::origines($fiche->field_origins ?? null);
            if ($actuelle === null && ! $this->protege($entite, $fiche, $champ, $origines)) {
                $origines[$champ] = $origine;
                DB::table($table)->where('id', $entiteId)->update([
                    $champ => $valeur,
                    'field_origins' => json_encode($origines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);

                return self::REMPLI;
            }

            $inseree = DB::table('propositions_champs')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'entite' => $entite,
                'entite_id' => $entiteId,
                'champ' => $champ,
                'valeur_actuelle' => $actuelle,
                'valeur_proposee' => $valeur,
                'origine' => $origine,
                'reference_externe' => $reference,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $inseree > 0 ? self::PROPOSEE : self::DEJA_PROPOSEE;
        }));

        if ($resultat === self::PROPOSEE) {
            ATraiterController::oublier($workspaceId);
        }

        return $resultat;
    }

    /**
     * Le propriétaire accepte : la valeur proposée est écrite sur la fiche,
     * `field_origins` prend l'origine tiers, la décision est tracée.
     *
     * C'est une décision HUMAINE : elle vaut aussi pour un champ protégé.
     *
     * @throws PropositionIntrouvable
     * @throws PropositionImpossible déjà décidée, ou fiche disparue
     */
    public function accepter(string $workspaceId, int $propositionId, User $par): void
    {
        $this->decider($workspaceId, $propositionId, $par, 'acceptee');
    }

    /**
     * Le propriétaire refuse : la fiche ne bouge pas, la décision est tracée.
     *
     * @throws PropositionIntrouvable
     * @throws PropositionDejaDecidee
     */
    public function refuser(string $workspaceId, int $propositionId, User $par): void
    {
        $this->decider($workspaceId, $propositionId, $par, 'refusee');
    }

    private function decider(string $workspaceId, int $propositionId, User $par, string $statut): void
    {
        WorkspaceContext::run($workspaceId, fn () => DB::transaction(function () use ($workspaceId, $propositionId, $par, $statut): void {
            $p = DB::table('propositions_champs')
                ->where('workspace_id', $workspaceId)
                ->where('id', $propositionId)
                ->lockForUpdate()
                ->first();
            if (! $p instanceof stdClass) {
                throw new PropositionIntrouvable("Proposition {$propositionId} introuvable.");
            }
            if ($p->statut !== 'en_attente') {
                throw new PropositionDejaDecidee('Cette proposition a déjà été traitée.');
            }

            $entite = (string) $p->entite;
            $champ = (string) $p->champ;
            if ($statut === 'acceptee') {
                $table = self::TABLES[$entite] ?? throw new PropositionImpossible('Type de fiche inconnu.');
                if (! array_key_exists($champ, self::CHAMPS[$entite])) {
                    throw new PropositionImpossible("Ce champ n'est plus modifiable par une proposition.");
                }
                $fiche = DB::table($table)
                    ->where('workspace_id', $workspaceId)
                    ->where('id', (int) $p->entite_id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();
                if (! $fiche instanceof stdClass) {
                    throw new PropositionImpossible("La fiche visée n'existe plus.");
                }
                $origines = self::origines($fiche->field_origins ?? null);
                $origines[$champ] = (string) $p->origine;
                DB::table($table)->where('id', (int) $p->entite_id)->update([
                    $champ => (string) $p->valeur_proposee,
                    'field_origins' => json_encode($origines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }

            DB::table('propositions_champs')->where('id', $propositionId)->update([
                'statut' => $statut,
                'decidee_par' => (string) $par->id,
                'decidee_le' => now(),
                'updated_at' => now(),
            ]);

            // La trace : numéro, fiche, champ, origine — jamais une valeur.
            $details = [
                'proposition' => $propositionId,
                'entite' => $entite,
                'entite_id' => (int) $p->entite_id,
                'champ' => $champ,
                'origine' => (string) $p->origine,
            ];
            $this->audit->record([
                'workspace_id' => $workspaceId,
                'user_id' => (string) $par->id,
                'method' => 'proposition.' . $statut,
                'path' => "propositions — n°{$propositionId} {$entite} {$p->entite_id} {$champ}",
                'status' => 200,
                'ip' => null,
                'user_agent' => 'propositions',
                'payload_hash' => hash('sha256', json_encode($details, JSON_THROW_ON_ERROR)),
            ]);
        }));

        ATraiterController::oublier($workspaceId);
    }

    /** @param  array<string, mixed>  $origines */
    private function protege(string $entite, stdClass $fiche, string $champ, array $origines): bool
    {
        if (($origines[$champ] ?? null) === 'declared') {
            return true;
        }
        $entreprise = $entite === self::ENTREPRISE ? (int) $fiche->id : (int) ($fiche->company_id ?? 0);

        return $entreprise > 0 && FichesProtegees::estProtegee($entreprise);
    }

    /** @return array<string, mixed> */
    private static function origines(mixed $brut): array
    {
        $carte = is_string($brut) ? json_decode($brut, true) : $brut;

        return is_array($carte) ? $carte : [];
    }

    private static function texte(mixed $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $texte = trim((string) $valeur);

        return $texte === '' ? null : $texte;
    }

    /** Deux numéros qui ne diffèrent que par leurs séparateurs sont le même numéro. */
    private static function comparable(string $champ, string $valeur): string
    {
        return $champ === 'phone' ? (string) preg_replace('/[\s.\-()]/u', '', $valeur) : $valeur;
    }
}
