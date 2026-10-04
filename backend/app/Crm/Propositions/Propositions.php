<?php

namespace App\Crm\Propositions;

use App\Crm\FichesProtegees;
use App\Crm\Taxonomy;
use App\Http\Controllers\Api\Crm\ATraiterController;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use App\Support\ListeSuppression;
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
 * ACCEPTER EST UN « COMPARER PUIS ÉCRIRE » (relectures #316) : une
 * proposition affirme un état (« la fiche disait X, le tiers dit Y »). L'écran
 * envoie l'EMPREINTE de ce qu'il a montré (`empreinte()` : valeur du champ et
 * origine de cette valeur) ; si la fiche ne la porte plus, rien n'est écrit
 * (`FicheModifiee`, 409 « rechargez »). La valeur RÉELLEMENT remplacée est
 * gardée avec la décision (`valeur_remplacee`). La proposition et la fiche
 * sont verrouillées dans la même transaction : deux décisions simultanées
 * (double clic, deux propositions sur un même champ) se suivent, et la
 * seconde trouve l'état changé.
 *
 * Rien n'est branché : aucune route publique n'appelle `proposer()`. Le
 * service est prêt pour le futur canal Partners.
 *
 * ⚠️ HORS DE CETTE FILE, POUR TOUJOURS : ce que Partners DÉCIDE (« ne pas
 * démarcher », « occupée jusqu'au », antériorité). Ces règles s'appliquent
 * telles quelles — Partners décide, le CRM reflète — et ne passent JAMAIS par
 * une acceptation du propriétaire. Cette file ne porte que des VALEURS de
 * fiche. Avant de brancher le canal, `CHAMPS` devra correspondre champ par
 * champ aux clés de `crm-pro.ts` (INT-T68-P).
 */
final class Propositions
{
    public const ENTREPRISE = 'entreprise';

    public const PERSONNE = 'personne';

    /** @var list<string> */
    public const ORIGINES = Taxonomy::FIELD_ORIGINS_TIERS;

    /**
     * Les origines d'un AUTOMATISME du CRM (04/10/2026) : elles n'entrent dans
     * la file que par `proposerAutomatisme()`, jamais par `proposer()`.
     *
     * @var list<string>
     */
    public const ORIGINES_AUTOMATISMES = Taxonomy::FIELD_ORIGINS_AUTOMATISMES;

    /** L'adresse affichée sur un site vérifié (`crm:entreprises:email-site-verifie`). */
    public const ORIGINE_SITE_VERIFIE = 'site-verifie';

    /** @var list<string> */
    public const STATUTS = ['en_attente', 'acceptee', 'refusee', 'effacee'];

    /** Le marqueur posé par l'effacement RGPD (art. 17) à la place d'une valeur. */
    public const EFFACE = '[effacé]';

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

    /**
     * Les champs qu'un AUTOMATISME peut proposer (`proposerAutomatisme()`),
     * jamais un tiers : l'e-mail générique relevé sur un site VÉRIFIÉ (SIREN
     * prouvé) — c'est cette preuve qui manque à un tiers. Une proposition de
     * ce champ ne vient que d'une origine de `ORIGINES_AUTOMATISMES`.
     *
     * @var array<string, array<string, string>>
     */
    public const CHAMPS_AUTOMATISMES = [
        self::ENTREPRISE => [
            'email_generic' => 'E-mail générique',
        ],
    ];

    /**
     * Champs TOUJOURS proposés, jamais remplis directement, même vides :
     * `contacts.role` choisit les destinataires des campagnes (filtre
     * `fonctions` de `ReglageDestinataires`, REQ-CAM-079) — un tiers ne fait
     * pas entrer ou sortir une personne d'une campagne sans regard humain.
     *
     * @var array<string, list<string>>
     */
    private const TOUJOURS_PROPOSES = [
        self::PERSONNE => ['role'],
    ];

    /** Longueur maximale d'une valeur reçue (le CHECK de la table en borne 2 000). */
    private const LONGUEUR_MAX = 2000;

    /** @var array<string, int> */
    private const LONGUEURS_MAX_CHAMP = [
        'phone' => 40,
        'postcode' => 10,
    ];

    private const LONGUEUR_MAX_REFERENCE = 200;

    public function __construct(private readonly AuditHashChain $audit) {}

    public static function libelleChamp(string $entite, string $champ): string
    {
        return self::CHAMPS[$entite][$champ] ?? self::CHAMPS_AUTOMATISMES[$entite][$champ] ?? $champ;
    }

    /**
     * Les colonnes d'une fiche qu'une proposition peut viser (tiers et automatismes).
     *
     * @return list<string>
     */
    public static function colonnes(string $entite): array
    {
        return array_keys((self::CHAMPS[$entite] ?? []) + (self::CHAMPS_AUTOMATISMES[$entite] ?? []));
    }

    /** Ce champ peut-il être décidé pour une proposition de cette origine ? */
    private static function champAdmis(string $entite, string $champ, string $origine): bool
    {
        if (array_key_exists($champ, self::CHAMPS[$entite] ?? [])) {
            return true;
        }

        return in_array($origine, self::ORIGINES_AUTOMATISMES, true)
            && array_key_exists($champ, self::CHAMPS_AUTOMATISMES[$entite] ?? []);
    }

    /**
     * Une valeur relevée par un AUTOMATISME du CRM, pour un champ de fiche
     * qui porte DÉJÀ une valeur (ou que l'automatisme ne doit pas remplir :
     * champ déclaré, fiche protégée). Elle n'écrit JAMAIS sur la fiche :
     * c'est l'appelant qui décide de remplir un champ vide, avec ses propres
     * gardes ; ici, la valeur n'entre que dans la file, et seul le
     * propriétaire décide.
     *
     * @return self::IGNOREE|self::IDENTIQUE|self::PROPOSEE|self::DEJA_PROPOSEE
     *
     * @throws InvalidArgumentException fiche, champ ou origine inconnus
     */
    public function proposerAutomatisme(
        string $workspaceId,
        string $entite,
        int $entiteId,
        string $champ,
        ?string $valeur,
        string $origine,
        ?string $reference = null,
    ): string {
        $table = self::TABLES[$entite] ?? throw new InvalidArgumentException('Type de fiche inconnu : ' . json_encode($entite));
        if (! in_array($origine, self::ORIGINES_AUTOMATISMES, true)) {
            throw new InvalidArgumentException('Origine d\'automatisme inconnue : ' . json_encode($origine));
        }
        if (! array_key_exists($champ, self::CHAMPS_AUTOMATISMES[$entite] ?? [])) {
            throw new InvalidArgumentException('Champ non proposable par un automatisme : ' . json_encode($champ));
        }
        $valeur = $valeur === null ? '' : trim($valeur);
        if ($valeur === '') {
            return self::IGNOREE;
        }
        if (mb_strlen($valeur) > self::LONGUEUR_MAX) {
            throw new InvalidArgumentException("Valeur trop longue pour {$champ} (au plus " . self::LONGUEUR_MAX . ' caractères).');
        }
        // Une référence trop longue (adresse de page) n'empêche pas la
        // proposition : elle n'est simplement pas gardée.
        $reference = $reference === null || trim($reference) === '' || mb_strlen(trim($reference)) > self::LONGUEUR_MAX_REFERENCE ? null : trim($reference);

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
                ->first(['id', $champ]);
            if (! $fiche instanceof stdClass) {
                throw new InvalidArgumentException("Fiche {$entite} {$entiteId} introuvable dans cet espace.");
            }
            $actuelle = self::texte($fiche->{$champ} ?? null);
            if ($actuelle !== null && mb_strtolower($actuelle) === mb_strtolower($valeur)) {
                return self::IDENTIQUE;
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
            self::oublierApresCommit($workspaceId);
        }

        return $resultat;
    }

    /** Une proposition identique (même fiche, champ et valeur) attend-elle déjà ? Lecture seule. */
    public static function dejaEnAttente(string $workspaceId, string $entite, int $entiteId, string $champ, string $valeur): bool
    {
        return DB::table('propositions_champs')
            ->where('workspace_id', $workspaceId)
            ->where('entite', $entite)
            ->where('entite_id', $entiteId)
            ->where('champ', $champ)
            ->where('statut', 'en_attente')
            ->whereRaw('md5(valeur_proposee) = md5(?)', [trim($valeur)])
            ->exists();
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
        // Bornée dès l'entrée : au-delà, le CHECK de la table lèverait une
        // erreur SQL, et le remplissage écrirait sans borne sur la fiche.
        $max = self::LONGUEURS_MAX_CHAMP[$champ] ?? self::LONGUEUR_MAX;
        if (mb_strlen($valeur) > $max) {
            throw new InvalidArgumentException("Valeur trop longue pour {$champ} (au plus {$max} caractères).");
        }
        $reference = $referenceExterne === null || trim($referenceExterne) === '' ? null : trim($referenceExterne);
        if ($reference !== null && mb_strlen($reference) > self::LONGUEUR_MAX_REFERENCE) {
            throw new InvalidArgumentException('Référence externe trop longue (au plus ' . self::LONGUEUR_MAX_REFERENCE . ' caractères).');
        }

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
            if ($actuelle === null && ! in_array($champ, self::TOUJOURS_PROPOSES[$entite] ?? [], true) && ! $this->protege($entite, $fiche, $champ, $origines)) {
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
            self::oublierApresCommit($workspaceId);
        }

        return $resultat;
    }

    /**
     * Le propriétaire accepte : la valeur proposée est écrite sur la fiche,
     * `field_origins` prend l'origine tiers, la décision est tracée.
     *
     * C'est une décision HUMAINE : elle vaut aussi pour un champ protégé —
     * mais seulement sur l'état que l'humain a VU : `$empreinteVue` est
     * l'`empreinte()` envoyée à l'écran avec la proposition.
     *
     * @throws PropositionIntrouvable
     * @throws FicheModifiee la fiche ne porte plus ce qui a été affiché
     * @throws PropositionImpossible déjà décidée, ou fiche disparue
     */
    public function accepter(string $workspaceId, int $propositionId, User $par, string $empreinteVue): void
    {
        $this->decider($workspaceId, $propositionId, $par, 'acceptee', $empreinteVue);
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

    /**
     * L'empreinte de ce que l'écran montre pour un champ d'une fiche : sa
     * valeur ET l'origine de cette valeur (un champ devenu « déclaré par la
     * personne » change l'empreinte, même à valeur égale). HMAC à la clé de
     * l'application : elle ne laisse pas retrouver un numéro masqué à l'écran.
     */
    public static function empreinte(string $entite, stdClass $fiche, string $champ): string
    {
        $etat = [
            $entite,
            (int) $fiche->id,
            $champ,
            self::texte($fiche->{$champ} ?? null),
            self::origines($fiche->field_origins ?? null)[$champ] ?? null,
        ];

        return hash_hmac('sha256', json_encode($etat, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (string) config('app.key'));
    }

    /** Le champ de cette fiche a-t-il été déclaré par la personne elle-même ? */
    public static function declare(stdClass $fiche, string $champ): bool
    {
        return (self::origines($fiche->field_origins ?? null)[$champ] ?? null) === 'declared';
    }

    /**
     * La valeur de la fiche n'est plus celle du jour de la proposition (valeur
     * comparée comme `proposer()` la compare).
     */
    public static function modifieeDepuis(stdClass $fiche, string $champ, ?string $valeurAuJourDeLaProposition): bool
    {
        $aujourdhui = self::texte($fiche->{$champ} ?? null);
        $alors = self::texte($valeurAuJourDeLaProposition);
        if ($aujourdhui === null || $alors === null) {
            return $aujourdhui !== $alors;
        }

        return self::comparable($champ, $aujourdhui) !== self::comparable($champ, $alors);
    }

    private function decider(string $workspaceId, int $propositionId, User $par, string $statut, ?string $empreinteVue = null): void
    {
        WorkspaceContext::run($workspaceId, fn () => DB::transaction(function () use ($workspaceId, $propositionId, $par, $statut, $empreinteVue): void {
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
            $remplacee = null;
            $originePrecedente = null;
            if ($statut === 'acceptee') {
                $table = self::TABLES[$entite] ?? throw new PropositionImpossible('Type de fiche inconnu.');
                if (! self::champAdmis($entite, $champ, (string) $p->origine)) {
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
                // Comparer puis écrire, sous le verrou de la fiche : si elle ne
                // porte plus ce que l'humain a vu, rien n'est écrit.
                if ($empreinteVue === null || ! hash_equals(self::empreinte($entite, $fiche, $champ), $empreinteVue)) {
                    throw new FicheModifiee('La fiche a changé depuis l’affichage : rechargez la page avant de décider.');
                }
                $remplacee = self::texte($fiche->{$champ} ?? null);
                $origines = self::origines($fiche->field_origins ?? null);
                $originePrecedente = isset($origines[$champ]) && is_string($origines[$champ]) ? $origines[$champ] : null;
                $origines[$champ] = (string) $p->origine;
                DB::table($table)->where('id', (int) $p->entite_id)->update([
                    $champ => (string) $p->valeur_proposee,
                    'field_origins' => json_encode($origines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }

            DB::table('propositions_champs')->where('id', $propositionId)->update([
                'statut' => $statut,
                // La valeur RÉELLEMENT remplacée (owner seul, export art. 15) :
                // jamais dans le journal d'audit.
                'valeur_remplacee' => $remplacee,
                'decidee_par' => (string) $par->id,
                'decidee_le' => now(),
                'updated_at' => now(),
            ]);

            // La trace : numéro, fiche, champ, origine — jamais une valeur. À
            // l'acceptation, l'origine PRÉCÉDENTE du champ (ex. `declared`) :
            // la fiche, elle, ne la garde pas.
            $details = [
                'proposition' => $propositionId,
                'entite' => $entite,
                'entite_id' => (int) $p->entite_id,
                'champ' => $champ,
                'origine' => (string) $p->origine,
            ];
            $chemin = "propositions — n°{$propositionId} {$entite} {$p->entite_id} {$champ}";
            if ($statut === 'acceptee') {
                $details['origine_precedente'] = $originePrecedente;
                $chemin .= ' (origine précédente : ' . ($originePrecedente ?? 'aucune') . ')';
            }
            $this->audit->record([
                'workspace_id' => $workspaceId,
                'user_id' => (string) $par->id,
                'method' => 'proposition.' . $statut,
                'path' => $chemin,
                'status' => 200,
                'ip' => null,
                'user_agent' => 'propositions',
                'payload_hash' => hash('sha256', json_encode($details, JSON_THROW_ON_ERROR)),
            ]);
            self::oublierApresCommit($workspaceId);
        }));
    }

    /**
     * La pastille du menu est vidée APRÈS le COMMIT — celui de l'appelant si
     * `proposer()` tourne dans sa transaction (futur canal Partners) : vidée
     * avant, elle serait recalculée sur l'ancien total.
     */
    private static function oublierApresCommit(string $workspaceId): void
    {
        DB::afterCommit(static fn () => ATraiterController::oublier($workspaceId));
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

    /**
     * Deux écritures d'un même numéro (séparateurs, « +33 » ou « 0 ») sont le
     * même numéro : la forme nationale de `ListeSuppression::variantesTelephone`,
     * la normalisation déjà partagée par les listes d'opposition.
     */
    private static function comparable(string $champ, string $valeur): string
    {
        return $champ === 'phone' ? (ListeSuppression::variantesTelephone($valeur)[0] ?? $valeur) : $valeur;
    }
}
