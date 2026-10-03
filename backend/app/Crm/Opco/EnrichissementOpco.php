<?php

namespace App\Crm\Opco;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * L'ENRICHISSEMENT IDCC / OPCO (lot O14) — `crm:enrichir-opco`.
 *
 * Lit la table SIRET → OPCO de France compétences (`SourceSiro`) et reporte,
 * pour chaque entreprise d'un espace dont `companies.siret` figure dans le
 * fichier, son IDCC, son OPCO propriétaire et son OPCO de gestion dans
 * `companies_opco`. `companies` n'est JAMAIS écrite.
 *
 * RÈGLES :
 *  - jointure : SIRET du fichier = `companies.siret` (CHAR(14)), par paquets
 *    de `TAILLE_PAQUET` lignes ; la recherche passe par l'index unique
 *    (workspace_id, siren) — le SIREN est le préfixe du SIRET — puis compare
 *    le SIRET exact (aucun index sur `companies.siret`, et aucun n'est créé :
 *    disque presque plein) ;
 *  - `OPCO_PROPRIETAIRE` fait foi pour `opco`, `OPCO_GESTION` va dans
 *    `opco_gestion` ; traduction FERMÉE (`Opco::depuisLibelleSiro`) ;
 *  - REJETÉES ET COMPTÉES : SIRET malformé (`rejet_siret_malforme`), IDCC
 *    vide (`rejet_idcc_vide`, ligne en anomalie) ou malformé
 *    (`rejet_idcc_malforme`), OPCO propriétaire vide ou inconnu
 *    (`rejet_opco_inconnu`), OPCO de gestion inconnu
 *    (`rejet_opco_gestion_inconnu`), ligne au mauvais nombre de colonnes
 *    (`rejet_ligne_malformee`) ;
 *  - écriture : `INSERT … ON CONFLICT (workspace_id, company_id) DO UPDATE`
 *    UNIQUEMENT si la ligne existante a `source = 'siro'` — une ligne
 *    `saisie` (posée par une personne) n'est JAMAIS écrasée
 *    (`ignorees_saisie`) ; une ligne déjà identique n'est pas réécrite
 *    (`inchangees`) : deux passages donnent le même état, et le second
 *    n'écrit rien ;
 *  - RIEN N'EST JAMAIS SUPPRIMÉ : aucune ligne de ce fichier n'émet de
 *    DELETE (le rôle applicatif n'en a d'ailleurs pas le droit).
 *
 * MÉMOIRE CONSTANTE (patron de #320, `fix/insee-memoire`) : le fichier est
 * téléchargé en flux dans le dossier temporaire du conteneur, lu ligne par
 * ligne (`fgetcsv`), un seul paquet vit à la fois, le bilan n'est fait que
 * de compteurs ; le fichier est supprimé à la fin, MÊME en cas d'erreur.
 *
 * REPRISE : le CURSEUR est le numéro de la dernière ligne de données
 * entièrement traitée, mémorisé dans `companies_opco_passages` DANS LA MÊME
 * TRANSACTION que les écritures du paquet. Une coupure ou `--limite`
 * laissent le passage `en_cours` : le passage suivant, sur la MÊME
 * ressource, saute les lignes déjà traitées. Une ressource nouvelle (mois de
 * DSN suivant) ouvre un passage nouveau, depuis la première ligne.
 *
 * Un essai à blanc lit tout, compte tout, et n'écrit RIEN (ni
 * `companies_opco`, ni journal).
 */
final class EnrichissementOpco
{
    public const TAILLE_PAQUET = 1000;

    /** Compteurs du bilan, dans l'ordre d'affichage. */
    public const COMPTEURS = [
        'lues', 'rapprochees', 'ecrites', 'inchangees', 'ignorees_saisie', 'non_rapprochees', 'doublons',
        'rejet_siret_malforme', 'rejet_idcc_vide', 'rejet_idcc_malforme',
        'rejet_opco_inconnu', 'rejet_opco_gestion_inconnu', 'rejet_ligne_malformee',
    ];

    /** Les colonnes attendues dans l'en-tête (`OPCO_GESTION` peut manquer). */
    public const COLONNES = ['SIRET', 'IDCC', 'OPCO_PROPRIETAIRE', 'OPCO_GESTION'];

    /** Séparateurs reconnus, dans l'ordre de préférence à égalité. */
    private const SEPARATEURS = ['|', ';', ',', "\t"];

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, int> bilan déjà journalisé d'un passage repris */
    private array $bilanAnterieur = [];

    /**
     * Le paquet en cours : SIRET → valeurs (le dernier l'emporte).
     *
     * @var array<string, array{idcc: string, opco: string, opco_gestion: ?string}>
     */
    private array $paquet = [];

    private string $workspaceId = '';

    private bool $essai = false;

    private ?string $releveLe = null;

    private ?int $passageId = null;

    public function __construct(private readonly SourceSiro $source) {}

    /**
     * @param  ?string  $releveLeImpose  AAAA-MM-01 ; null = celui de la ressource
     * @param  int  $limite  lignes de données au plus (0 = sans limite) ; le passage reprendra ensuite
     * @param  ?callable(string): void  $journal  reçoit l'avancement
     * @return array{statut: string, reprise: bool, curseur: int, ressource: array{id: string, url: string, titre: string, releve_le: ?string}, bilan: array<string, int>}
     */
    public function executer(
        string $workspaceId,
        bool $essai = false,
        int $limite = 0,
        ?string $releveLeImpose = null,
        ?callable $journal = null,
    ): array {
        $this->workspaceId = $workspaceId;
        $this->essai = $essai;
        $this->bilan = array_fill_keys(self::COMPTEURS, 0);
        $this->bilanAnterieur = [];
        $this->paquet = [];
        $this->passageId = null;
        $journal ??= static function (string $ligne): void {};

        $ressource = $this->source->ressourceCourante();
        $this->releveLe = $releveLeImpose ?? $ressource['releve_le'];
        $journal(sprintf(
            'Ressource : %s (%s) — DSN de %s',
            $ressource['titre'] !== '' ? $ressource['titre'] : $ressource['id'],
            $ressource['url'],
            $this->releveLe !== null ? substr($this->releveLe, 0, 7) : 'mois inconnu',
        ));

        return WorkspaceContext::run($workspaceId, function () use ($ressource, $limite, $journal): array {
            [$curseur, $reprise] = $this->ouvrirPassage($ressource);
            if ($reprise) {
                $journal("Reprise du passage inachevé après la ligne {$curseur}.");
            }

            $chemin = tempnam(sys_get_temp_dir(), 'siro-opco-');
            if ($chemin === false) {
                throw new RuntimeException('Impossible de créer un fichier dans le dossier temporaire.');
            }

            try {
                $this->source->telecharger($ressource['url'], $chemin);
                [$curseur, $termine] = $this->lire($chemin, $curseur, max(0, $limite), $journal);
            } catch (\Throwable $e) {
                if ($this->passageId !== null) {
                    DB::table('companies_opco_passages')->where('id', $this->passageId)->where('workspace_id', $this->workspaceId)->update([
                        // Jamais une ligne du fichier ni un message SQL.
                        'statut' => 'echouee',
                        'erreur' => 'Erreur ' . class_basename($e) . ' (détail dans le journal applicatif)',
                        'bilan' => json_encode($this->bilanDuPassage()), 'maj_le' => now(),
                    ]);
                }
                Log::error('crm:enrichir-opco : passage interrompu', ['passage' => $this->passageId, 'erreur' => $e->getMessage()]);

                throw $e;
            } finally {
                // Le fichier part TOUJOURS, même sur une erreur.
                if (is_file($chemin)) {
                    @unlink($chemin);
                }
            }

            if ($this->passageId !== null) {
                DB::table('companies_opco_passages')->where('id', $this->passageId)->where('workspace_id', $this->workspaceId)->update([
                    'statut' => $termine ? 'reussie' : 'en_cours', 'erreur' => null, 'curseur' => $curseur,
                    'bilan' => json_encode($this->bilanDuPassage()), 'maj_le' => now(),
                    'terminee_le' => $termine ? now() : null,
                ]);
            }

            return [
                'statut' => $termine ? 'reussie' : 'en_cours',
                'reprise' => $reprise,
                'curseur' => $curseur,
                'ressource' => ['releve_le' => $this->releveLe] + $ressource,
                'bilan' => $this->bilan,
            ];
        });
    }

    /**
     * Le passage à mener : reprise du dernier passage inachevé (`en_cours` ou
     * `echouee`, postérieur à la dernière réussite) s'il porte sur la MÊME
     * ressource, sinon un nouveau depuis la première ligne. Un essai à blanc
     * lit le curseur mais n'écrit pas de journal.
     *
     * @param  array{id: string, url: string, titre: string, releve_le: ?string}  $ressource
     * @return array{0: int, 1: bool} [curseur, reprise]
     */
    private function ouvrirPassage(array $ressource): array
    {
        $idReussie = DB::table('companies_opco_passages')
            ->where('workspace_id', $this->workspaceId)->where('statut', 'reussie')->max('id');

        $inacheve = DB::table('companies_opco_passages')
            ->where('workspace_id', $this->workspaceId)->whereIn('statut', ['en_cours', 'echouee'])
            ->when($idReussie !== null, static fn ($q) => $q->where('id', '>', $idReussie))
            ->orderByDesc('id')->first(['id', 'ressource_id', 'releve_le', 'curseur', 'bilan']);

        if ($inacheve !== null && (string) $inacheve->ressource_id === $ressource['id']) {
            $anterieur = json_decode(is_string($inacheve->bilan) ? $inacheve->bilan : '{}', true);
            if (! $this->essai) {
                $this->bilanAnterieur = is_array($anterieur) ? array_map('intval', $anterieur) : [];
                $this->passageId = (int) $inacheve->id;
                DB::table('companies_opco_passages')->where('id', $this->passageId)->where('workspace_id', $this->workspaceId)
                    ->update(['statut' => 'en_cours', 'erreur' => null, 'maj_le' => now()]);
            }

            return [(int) $inacheve->curseur, true];
        }

        if (! $this->essai) {
            $this->passageId = (int) DB::table('companies_opco_passages')->insertGetId([
                'workspace_id' => $this->workspaceId,
                'ressource_id' => $ressource['id'],
                'ressource_url' => $ressource['url'],
                'releve_le' => $this->releveLe,
                'statut' => 'en_cours',
                'curseur' => 0,
            ]);
        }

        return [0, false];
    }

    /**
     * Lit le fichier ligne par ligne à partir du curseur.
     *
     * @return array{0: int, 1: bool} [nouveau curseur, fichier lu jusqu'au bout]
     */
    private function lire(string $chemin, int $curseur, int $limite, callable $journal): array
    {
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            throw new RuntimeException('Fichier SIRO illisible.');
        }

        try {
            $entete = fgets($flux);
            if ($entete === false) {
                throw new RuntimeException('Fichier SIRO vide : aucun en-tête.');
            }
            $separateur = self::separateur($entete);
            $colonnes = self::colonnes(str_getcsv(rtrim($entete, "\r\n"), $separateur, '"', ''));

            $ligne = 0;
            $traitees = 0;
            while (($valeurs = fgetcsv($flux, 0, $separateur, '"', '')) !== false) {
                if ($valeurs === [null]) {
                    continue; // ligne vide
                }
                $ligne++;
                if ($ligne <= $curseur) {
                    continue; // déjà traitée par un passage précédent
                }
                if ($limite > 0 && $traitees >= $limite) {
                    $this->vider($ligne - 1);

                    return [$ligne - 1, false];
                }
                $traitees++;
                $this->bilan['lues']++;
                $this->accepter($valeurs, $colonnes);

                if (count($this->paquet) >= self::TAILLE_PAQUET) {
                    $this->vider($ligne);
                    if ($this->bilan['lues'] % 100000 < self::TAILLE_PAQUET) {
                        $journal(sprintf('  … ligne %d — %d rapprochées, %d écrites', $ligne, $this->bilan['rapprochees'], $this->bilan['ecrites']));
                    }
                }
            }
            $this->vider(max($ligne, $curseur));

            return [max($ligne, $curseur), true];
        } finally {
            fclose($flux);
        }
    }

    /**
     * Le séparateur de l'en-tête : celui des candidats qui y paraît le plus
     * (le fichier annoncé est séparé par `|`, mais rien ne le garantit).
     */
    public static function separateur(string $entete): string
    {
        $meilleur = self::SEPARATEURS[0];
        $max = 0;
        foreach (self::SEPARATEURS as $s) {
            $n = substr_count($entete, $s);
            if ($n > $max) {
                $max = $n;
                $meilleur = $s;
            }
        }

        return $meilleur;
    }

    /**
     * Les positions des colonnes utiles, d'après l'en-tête (casse, espaces,
     * guillemets et BOM ignorés).
     *
     * @param  list<?string>  $entete
     * @return array{SIRET: int, IDCC: int, OPCO_PROPRIETAIRE: int, OPCO_GESTION: ?int, nombre: int}
     */
    public static function colonnes(array $entete): array
    {
        $positions = [];
        foreach ($entete as $i => $nom) {
            $cle = strtoupper(trim(str_replace("\u{FEFF}", '', (string) $nom), " \t\"'"));
            $cle = (string) preg_replace('/[^A-Z0-9]+/', '_', $cle);
            $positions[$cle] ??= $i;
        }
        foreach (['SIRET', 'IDCC', 'OPCO_PROPRIETAIRE'] as $obligatoire) {
            if (! isset($positions[$obligatoire])) {
                throw new RuntimeException(
                    "En-tête SIRO inattendu : colonne {$obligatoire} absente (lu : " . implode(', ', array_keys($positions)) . ').',
                );
            }
        }

        return [
            'SIRET' => $positions['SIRET'],
            'IDCC' => $positions['IDCC'],
            'OPCO_PROPRIETAIRE' => $positions['OPCO_PROPRIETAIRE'],
            'OPCO_GESTION' => $positions['OPCO_GESTION'] ?? null,
            'nombre' => count($entete),
        ];
    }

    /**
     * Valide une ligne et l'ajoute au paquet, ou la rejette et la compte.
     *
     * @param  list<?string>  $valeurs
     * @param  array{SIRET: int, IDCC: int, OPCO_PROPRIETAIRE: int, OPCO_GESTION: ?int, nombre: int}  $colonnes
     */
    private function accepter(array $valeurs, array $colonnes): void
    {
        if (count($valeurs) !== $colonnes['nombre']) {
            $this->bilan['rejet_ligne_malformee']++;

            return;
        }

        $siret = str_replace(' ', '', trim((string) $valeurs[$colonnes['SIRET']]));
        if (preg_match('/^[0-9]{14}$/', $siret) !== 1) {
            $this->bilan['rejet_siret_malforme']++;

            return;
        }

        $idcc = trim((string) $valeurs[$colonnes['IDCC']]);
        if ($idcc === '') {
            $this->bilan['rejet_idcc_vide']++;

            return;
        }
        // Un IDCC peut perdre ses zéros de tête dans un export (« 16 » pour
        // « 0016 ») : ils sont rétablis. Rien d'autre n'est corrigé.
        if (preg_match('/^[0-9]{1,4}$/', $idcc) !== 1) {
            $this->bilan['rejet_idcc_malforme']++;

            return;
        }
        $idcc = str_pad($idcc, 4, '0', STR_PAD_LEFT);

        $opco = Opco::depuisLibelleSiro((string) $valeurs[$colonnes['OPCO_PROPRIETAIRE']]);
        if ($opco === null) {
            $this->bilan['rejet_opco_inconnu']++;

            return;
        }

        $gestion = null;
        if ($colonnes['OPCO_GESTION'] !== null) {
            $libelle = trim((string) $valeurs[$colonnes['OPCO_GESTION']]);
            if ($libelle !== '') {
                $gestion = Opco::depuisLibelleSiro($libelle);
                if ($gestion === null) {
                    $this->bilan['rejet_opco_gestion_inconnu']++;

                    return;
                }
            }
        }

        // Un SIRET répété dans le même paquet : la dernière ligne l'emporte.
        if (isset($this->paquet[$siret])) {
            $this->bilan['doublons']++;
        }
        $this->paquet[$siret] = ['idcc' => $idcc, 'opco' => $opco, 'opco_gestion' => $gestion];
    }

    /**
     * Rapproche le paquet des fiches de l'espace, écrit ce qui doit l'être et
     * avance le curseur jusqu'à `$ligne`, dans UNE transaction.
     */
    private function vider(int $ligne): void
    {
        $paquet = $this->paquet;
        $this->paquet = [];

        $traiter = function () use ($paquet, $ligne): void {
            if ($paquet !== []) {
                $this->rapprocherEtEcrire($paquet);
            }
            if ($this->passageId !== null) {
                DB::table('companies_opco_passages')->where('id', $this->passageId)->where('workspace_id', $this->workspaceId)->update([
                    'curseur' => $ligne, 'bilan' => json_encode($this->bilanDuPassage()), 'maj_le' => now(),
                ]);
            }
        };

        if ($this->essai) {
            $traiter();
        } else {
            DB::transaction($traiter);
        }
    }

    /**
     * @param  array<string, array{idcc: string, opco: string, opco_gestion: ?string}>  $paquet
     */
    private function rapprocherEtEcrire(array $paquet): void
    {
        $sirets = array_map('strval', array_keys($paquet));
        $sirens = array_values(array_unique(array_map(static fn (string $s): string => substr($s, 0, 9), $sirets)));

        // Par l'index unique (workspace_id, siren), puis le SIRET exact.
        $fiches = DB::table('companies')
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('siren', $sirens)
            ->whereIn('siret', $sirets)
            ->whereNull('deleted_at')
            ->get(['id', 'siret']);

        $this->bilan['non_rapprochees'] += count($paquet) - $fiches->count();
        if ($fiches->isEmpty()) {
            return;
        }
        $this->bilan['rapprochees'] += $fiches->count();

        $existantes = DB::table('companies_opco')
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('company_id', $fiches->pluck('id')->all())
            ->get(['company_id', 'source', 'siret', 'idcc', 'opco', 'opco_gestion', 'releve_le'])
            ->keyBy('company_id');

        $aEcrire = [];
        foreach ($fiches as $fiche) {
            $siret = (string) $fiche->siret;
            $v = $paquet[$siret];
            $existante = $existantes->get($fiche->id);
            if ($existante !== null && $existante->source !== 'siro') {
                $this->bilan['ignorees_saisie']++;

                continue;
            }
            if ($existante !== null
                && (string) $existante->siret === $siret
                && (string) $existante->idcc === $v['idcc']
                && $existante->opco === $v['opco']
                && $existante->opco_gestion === $v['opco_gestion']
                && ($existante->releve_le === null ? null : substr((string) $existante->releve_le, 0, 10)) === $this->releveLe) {
                $this->bilan['inchangees']++;

                continue;
            }
            $aEcrire[] = [(int) $fiche->id, $siret, $v['idcc'], $v['opco'], $v['opco_gestion']];
        }

        if ($aEcrire === []) {
            return;
        }
        if ($this->essai) {
            // À blanc : ce qui SERAIT écrit.
            $this->bilan['ecrites'] += count($aEcrire);

            return;
        }

        $lignes = [];
        $liaisons = [];
        foreach ($aEcrire as [$companyId, $siret, $idcc, $opco, $gestion]) {
            $lignes[] = "(?::uuid, ?::bigint, ?, ?, ?, ?, 'siro', ?::date, now(), now())";
            array_push($liaisons, $this->workspaceId, $companyId, $siret, $idcc, $opco, $gestion, $this->releveLe);
        }

        // La clause WHERE du DO UPDATE re-vérifie `source = 'siro'` DANS la
        // base : une saisie posée entre la lecture et l'écriture n'est pas
        // écrasée non plus. Seules les lignes réellement écrites reviennent.
        $ecrites = DB::select(
            'INSERT INTO companies_opco (workspace_id, company_id, siret, idcc, opco, opco_gestion, source, releve_le, created_at, updated_at)
             VALUES ' . implode(', ', $lignes) . '
             ON CONFLICT (workspace_id, company_id) DO UPDATE SET
                 siret = EXCLUDED.siret,
                 idcc = EXCLUDED.idcc,
                 opco = EXCLUDED.opco,
                 opco_gestion = EXCLUDED.opco_gestion,
                 releve_le = EXCLUDED.releve_le,
                 updated_at = now()
             WHERE companies_opco.source = \'siro\'
             RETURNING company_id',
            $liaisons,
            false, // une écriture : jamais la connexion de lecture
        );
        $this->bilan['ecrites'] += count($ecrites);
        $this->bilan['ignorees_saisie'] += count($aEcrire) - count($ecrites);
    }

    /** @return array<string, int> le bilan cumulé du passage (reprises comprises) */
    private function bilanDuPassage(): array
    {
        $cumul = [];
        foreach (self::COMPTEURS as $c) {
            $cumul[$c] = ($this->bilanAnterieur[$c] ?? 0) + $this->bilan[$c];
        }

        return $cumul;
    }
}
