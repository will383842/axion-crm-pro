<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use App\Crm\Doublons\AdressesPartagees;
use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Crm\EspaceProspection;
use App\Crm\FichesProtegees;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * DÉTECTER LES DOUBLONS — sans jamais rien fusionner ni supprimer
 * (chantier 5, 2026-09-30).
 *
 * Deux choses, en UN parcours des fiches par lots d'identifiants :
 *
 *  1. LES ADRESSES PARTAGÉES — chaque adresse générique (`email_generic`)
 *     portée par plusieurs fiches est inscrite dans `adresses_partagees` :
 *     son empreinte, le nombre de fiches, sa nature probable (cabinet
 *     comptable, domiciliation, groupe, inconnue — `Rapprochement::natureAdresse`).
 *     Une adresse partagée NE FAIT JAMAIS une paire de doublons : c'est très
 *     souvent un expert-comptable ou une domiciliation qui sert plusieurs
 *     entreprises différentes.
 *
 *  2. LES PAIRES DE FICHES — chaque fiche SANS SIREN est comparée aux fiches
 *     de même nom normalisé EXACT (index `idx_companies_denom_btree`) : même
 *     code postal et/ou même site. Chaque paire va dans `duplicate_flags`,
 *     avec son motif et son score ; `fusion_auto` n'est vrai que pour une
 *     preuve certaine (`Rapprochement::MOTIFS_CERTAINS`). Deux fiches à SIREN
 *     différents ne forment JAMAIS une paire. Une paire écartée par un humain
 *     (« ce ne sont pas des doublons ») n'est jamais reproposée.
 *     Même SIREN et même (pays, identifiant) : les clés uniques de la base les
 *     rendent impossibles ; la commande le VÉRIFIE (catalogue) et ne parcourt
 *     la table que si une de ces clés venait à manquer.
 *
 * ── Sûre sur 4,3 M de fiches ────────────────────────────────────────────────
 *
 *  - Par lots (`--lot`, 5 000 fiches) lus par la clé primaire ; chaque
 *    recherche passe par un index (`DoublonsServisParDesIndexTest`) ; chaque
 *    lot écrit dans SA transaction courte, avec `lock_timeout`.
 *  - Reprenable : chaque lot annonce le dernier identifiant ; `--depuis-id`.
 *    Relancer ne crée rien en double (clé unique de la paire, et la paire
 *    inverse est cherchée avant d'écrire).
 *  - `--dry-run` : le MÊME calcul, la même classification (nouvelle, déjà
 *    connue, déjà traitée) lue en base, et RIEN d'écrit.
 *  - `--compteurs-seulement` : journaux des workflows (dépôt PUBLIC) — que des
 *    nombres, jamais un nom, une adresse ou un identifiant d'espace.
 *
 * Les adresses qui ne sont plus partagées sont retirées de la table (dérivée)
 * seulement à la fin d'un parcours COMPLET (depuis l'id 0, jusqu'au bout), et
 * sous le plafond de `RefuseUneSuppressionMassive` : un détecteur qui se
 * tromperait ne viderait pas la table (`--force` pour le lever).
 */
class CrmDoublonsDetecter extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'crm:doublons:detecter
                            {--dry-run : Tout lire et tout calculer, ne RIEN écrire}
                            {--workspace= : Identifiant ou slug de l\'espace (défaut : celui de prospection:collect)}
                            {--lot=5000 : Fiches lues par lot (100 à 10000)}
                            {--depuis-id=0 : Reprendre APRÈS cette fiche (dernier id annoncé par une exécution interrompue)}
                            {--max-lots=0 : S\'arrêter après N lots (0 = jusqu\'au bout)}
                            {--pause-ms=0 : Pause entre deux lots, pour ménager la base}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}
                            {--force : Lever le plafond du ménage des adresses qui ne sont plus partagées}';

    protected $description = 'Détecte les adresses partagées et les paires de fiches en double (ne fusionne rien, ne supprime rien).';

    /** Au-delà, un nom est trop répandu pour rapprocher sur lui : compté, pas proposé. */
    public const HOMONYMES_MAX = 50;

    /** Lignes par instruction d'écriture (5 paramètres par paire, 6 par adresse). */
    private const PAR_INSTRUCTION = 500;

    /** @var array<string, int> */
    private array $compteurs = [];

    private int $verrousMax = 0;

    /** @var array<string, true> adresses revues par ce parcours */
    private array $adressesVues = [];

    private FusionFiches $fusion;

    public function handle(AuditHashChain $audit, FusionFiches $fusion): int
    {
        $this->fusion = $fusion;
        $designation = is_string($this->option('workspace')) ? $this->option('workspace') : null;
        $ws = EspaceProspection::resoudre($designation);
        if ($ws === null) {
            $this->error('Espace introuvable : « ' . ($designation ?? '(défaut)') . ' ».');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $lot = max(100, min(10000, (int) $this->option('lot')));
        $depuis = max(0, (int) $this->option('depuis-id'));
        $maxLots = max(0, (int) $this->option('max-lots'));
        $pauseMs = max(0, (int) $this->option('pause-ms'));
        $discret = (bool) $this->option('compteurs-seulement');
        $operateur = self::operateur();

        $this->reinitialiser();
        $this->info(sprintf(
            '%s — espace %s, lots de %d, à partir de l\'id %d.',
            $dryRun ? '[À BLANC] rien ne sera écrit' : 'Détection des doublons',
            $discret ? '(masqué)' : $ws,
            $lot,
            $depuis,
        ));

        $debut = '';
        $dernier = $depuis;
        $termine = false;
        $erreur = null;
        try {
            WorkspaceContext::run($ws, function () use ($ws, $dryRun, $lot, $depuis, $maxLots, $pauseMs, &$debut, &$dernier, &$termine, &$erreur): void {
                $horloge = DB::selectOne('SELECT clock_timestamp()::text AS t');
                $debut = $horloge instanceof stdClass ? (string) $horloge->t : '';

                // Les clés uniques : vérifiées au catalogue, jamais supposées.
                $paires = [];
                foreach (['siren' => Rapprochement::MEME_SIREN, 'identifiant' => Rapprochement::MEME_IDENTIFIANT] as $cle => $motif) {
                    if ($this->cleUniquePresente($cle)) {
                        $this->compteurs["cle_unique_{$cle}_presente"] = 1;

                        continue;
                    }
                    $this->warn("Clé unique « {$cle} » ABSENTE de la base : parcours complet des fiches pour ce motif.");
                    $paires = array_merge($paires, $this->pairesCleIdentique($ws, $cle, $motif));
                }
                if ($paires !== [] && $depuis === 0) {
                    $this->traiterPaires($ws, $paires, $dryRun);
                }

                $lots = 0;
                while (true) {
                    $fiches = $this->lireLot($ws, $dernier, $lot);
                    if ($fiches === []) {
                        $termine = true;

                        return;
                    }
                    $ids = array_map(static fn (stdClass $f): int => (int) $f->id, $fiches);
                    $bas = min($ids);
                    $haut = max($ids);
                    $avant = $this->compteurs;

                    try {
                        $adresses = $this->adressesDuLot($ws, $fiches, $bas, $haut);
                        $paires = $this->pairesDuLot($ws, $fiches);
                        if ($dryRun) {
                            $this->classer($ws, $paires);
                        } else {
                            DB::transaction(function () use ($ws, $adresses, $paires): void {
                                DB::statement("SET LOCAL lock_timeout = '5s'");
                                $this->ecrireAdresses($ws, $adresses);
                                $this->ecrirePaires($ws, $paires);
                                $this->mesurerVerrous();
                            });
                        }
                    } catch (Throwable $e) {
                        $erreur = $e instanceof QueryException ? 'SQLSTATE ' . $e->getCode() : get_class($e);
                        Log::error('crm:doublons:detecter : lot annulé', ['apres_id' => $dernier, 'erreur' => $erreur]);
                        $this->compteurs = $avant;

                        return;
                    }

                    $lots++;
                    $dernier = $haut;
                    $this->compteurs['lots']++;
                    $this->compteurs['fiches_lues'] += count($fiches);
                    $this->line(sprintf('  lot %d : %d fiches, ids %d à %d', $lots, count($fiches), $bas, $haut));

                    if ($maxLots > 0 && $lots >= $maxLots) {
                        return;
                    }
                    if ($pauseMs > 0) {
                        usleep($pauseMs * 1000);
                    }
                }
            });

            // Le ménage de la table DÉRIVÉE, après un parcours COMPLET
            // seulement : une reprise (`--depuis-id`) n'a pas revu les
            // adresses des lots précédents.
            if ($termine && $depuis === 0 && $erreur === null && $debut !== '') {
                WorkspaceContext::run($ws, function () use ($ws, $dryRun, $debut): void {
                    if (! $dryRun) {
                        $obsoletes = DB::table('adresses_partagees')->where('workspace_id', $ws)->where('calculee_le', '<', $debut);
                        $aRetirer = (clone $obsoletes)->count();
                        $this->compteurs['adresses_plus_partagees'] = $aRetirer;
                        $total = DB::table('adresses_partagees')->where('workspace_id', $ws)->count();
                        if ($this->ecritureAutoriseeSansOperateur('adresses_partagees', $aRetirer, $total, 'retirer')) {
                            $obsoletes->delete();
                        }

                        return;
                    }
                    // À blanc, rien n'a été recalculé en base : on compte les
                    // lignes dont l'adresse n'a PAS été revue par ce parcours.
                    $this->compteurs['adresses_plus_partagees'] = AdressesPartagees::nonRevues(
                        $ws,
                        array_map(static fn (int|string $e): string => (string) $e, array_keys($this->adressesVues)),
                    );
                });
            }
        } finally {
            if (! $dryRun) {
                try {
                    $this->auditer($audit, $ws, $operateur, 'DETECTION_DOUBLONS_FIN', $erreur === null ? 200 : 500, [
                        'termine' => $termine, 'dernier_id' => $dernier, 'erreur' => $erreur, 'compteurs' => $this->compteurs,
                    ], $termine ? 'terminé' : "arrêté après l'id {$dernier}");
                } catch (Throwable $e) {
                    $erreur ??= 'audit de fin : ' . get_class($e);
                }
            }
        }

        $this->afficherBilan($dryRun);

        if ($erreur !== null) {
            $this->error("ÉCHEC : un lot a été annulé ({$erreur}). Tout ce qui précède est acquis. Reprendre avec : --depuis-id={$dernier}");

            return self::FAILURE;
        }
        if (! $termine) {
            $this->warn("Arrêt demandé après {$this->compteurs['lots']} lot(s). Reprendre avec : --depuis-id={$dernier}");
        } else {
            $this->info($dryRun ? '[À BLANC] terminé : rien n\'a été écrit.' : 'Détection terminée : rien n\'a été fusionné ni supprimé.');
        }

        return self::SUCCESS;
    }

    private function reinitialiser(): void
    {
        $this->verrousMax = 0;
        $this->adressesVues = [];
        $this->compteurs = [
            'lots' => 0,
            'fiches_lues' => 0,
            'cle_unique_siren_presente' => 0,
            'cle_unique_identifiant_presente' => 0,
            'adresses_partagees' => 0,
            'fiches_portant_une_adresse_partagee' => 0,
            'adresses_plus_partagees' => 0,
        ];
        foreach (array_keys(Rapprochement::NATURES_ADRESSE) as $nature) {
            $this->compteurs["adresses_{$nature}"] = 0;
        }
        $this->compteurs['fiches_sans_siren_comparees'] = 0;
        $this->compteurs['noms_trop_repandus'] = 0;
        $this->compteurs['preuves_ambigues'] = 0;
        foreach (array_keys(Rapprochement::MOTIFS) as $motif) {
            $this->compteurs["paires_{$motif}"] = 0;
        }
        foreach (['paires_nouvelles', 'paires_deja_connues', 'paires_deja_traitees', 'fusions_certaines', 'file_verification'] as $c) {
            $this->compteurs[$c] = 0;
        }
    }

    // ── Lecture ─────────────────────────────────────────────────────────────

    /** @return list<stdClass> */
    private function lireLot(string $ws, int $apresId, int $taille): array
    {
        $lignes = DB::select(
            'SELECT c.id, c.siren, c.country_code, c.foreign_id, c.email_generic, c.denomination_normalized,
                    c.postcode, c.website, c.discovery_source
             FROM companies c
             WHERE c.workspace_id = ? AND c.id > ? AND c.deleted_at IS NULL
             ORDER BY c.id
             LIMIT ' . $taille,
            [$ws, $apresId],
        );

        $fiches = [];
        foreach ($lignes as $l) {
            if ($l instanceof stdClass) {
                $fiches[] = $l;
            }
        }

        return $fiches;
    }

    private function cleUniquePresente(string $cle): bool
    {
        $colonnes = $cle === 'siren' ? '(workspace_id, siren)' : '(workspace_id, country_code, foreign_id)';
        $r = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM pg_index i
                JOIN pg_class t ON t.oid = i.indrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = 'public' AND t.relname = 'companies'
                  AND i.indisunique AND i.indisvalid
                  AND pg_get_indexdef(i.indexrelid) LIKE ?
             ) AS present",
            ['%' . $colonnes . '%'],
        );

        return $r instanceof stdClass && (bool) $r->present;
    }

    /**
     * Seulement si une clé unique MANQUE : les fiches qui partagent la clé.
     *
     * @return list<array{garde: int, absorbee: int, motif: string, auto: bool}>
     */
    private function pairesCleIdentique(string $ws, string $cle, string $motif): array
    {
        $groupe = $cle === 'siren' ? 'c.siren' : 'c.country_code, c.foreign_id';
        $non = $cle === 'siren' ? 'c.siren IS NOT NULL' : 'c.foreign_id IS NOT NULL';
        $groupes = DB::select(
            "SELECT array_agg(c.id ORDER BY c.id)::text AS ids
             FROM companies c
             WHERE c.workspace_id = ? AND {$non} AND c.deleted_at IS NULL
             GROUP BY {$groupe}
             HAVING count(*) > 1",
            [$ws],
        );
        $paires = [];
        foreach ($groupes as $g) {
            if (! $g instanceof stdClass) {
                continue;
            }
            $ids = array_map('intval', explode(',', trim((string) $g->ids, '{}')));
            $garde = array_shift($ids);
            foreach ($ids as $autre) {
                $paires[] = ['garde' => $garde, 'absorbee' => $autre, 'motif' => $motif, 'auto' => true];
            }
        }

        return $paires;
    }

    /**
     * Les adresses génériques du lot portées par plusieurs fiches, traitées
     * dans le lot qui contient leur PREMIÈRE fiche (jamais deux fois).
     *
     * @param  list<stdClass>  $fiches
     * @return list<array{email: string, domaine: ?string, nb: int, nature: string}>
     */
    private function adressesDuLot(string $ws, array $fiches, int $bas, int $haut): array
    {
        $emails = [];
        foreach ($fiches as $f) {
            // `strtolower` (ASCII) comme le `lower()` d'une base en locale C.
            $e = strtolower((string) $f->email_generic);
            if (trim($e) !== '') {
                $emails[$e] = true;
            }
        }
        if ($emails === []) {
            return [];
        }
        $emails = array_map(static fn (int|string $e): string => (string) $e, array_keys($emails));
        $marques = implode(', ', array_fill(0, count($emails), '?'));

        // `lower(email_generic) IN (…)` + `email_generic IS NOT NULL` : la forme
        // exacte de `idx_companies_email_generic_minuscules`.
        $groupes = DB::select(
            "SELECT lower(c.email_generic) AS email, count(*) AS n, min(c.id) AS premier
             FROM companies c
             WHERE lower(c.email_generic) IN ({$marques}) AND c.email_generic IS NOT NULL
               AND c.workspace_id = ? AND c.deleted_at IS NULL
             GROUP BY lower(c.email_generic)
             HAVING count(*) > 1",
            array_merge($emails, [$ws]),
        );
        $partagees = [];
        foreach ($groupes as $g) {
            if ($g instanceof stdClass && (int) $g->premier >= $bas && (int) $g->premier <= $haut) {
                $partagees[(string) $g->email] = (int) $g->n;
            }
        }
        if ($partagees === []) {
            return [];
        }

        $marques = implode(', ', array_fill(0, count($partagees), '?'));
        $porteuses = DB::select(
            "SELECT lower(c.email_generic) AS email, c.naf, c.naf_rev2, c.website
             FROM companies c
             WHERE lower(c.email_generic) IN ({$marques}) AND c.email_generic IS NOT NULL
               AND c.workspace_id = ? AND c.deleted_at IS NULL",
            array_merge(array_keys($partagees), [$ws]),
        );
        $parAdresse = [];
        foreach ($porteuses as $p) {
            if ($p instanceof stdClass) {
                $parAdresse[(string) $p->email][] = [
                    'naf' => is_string($p->naf_rev2) && $p->naf_rev2 !== '' ? $p->naf_rev2 : (is_string($p->naf) ? $p->naf : null),
                    'domaine' => Rapprochement::domaineSite(is_string($p->website) ? $p->website : null),
                ];
            }
        }

        $adresses = [];
        foreach ($partagees as $email => $n) {
            $email = (string) $email;
            $nature = Rapprochement::natureAdresse($email, $parAdresse[$email] ?? []);
            $this->adressesVues[$email] = true;
            $adresses[] = [
                'email' => $email,
                'domaine' => Rapprochement::domaineEmail($email),
                'nb' => $n,
                'nature' => $nature,
            ];
            $this->compteurs['adresses_partagees']++;
            $this->compteurs["adresses_{$nature}"]++;
            $this->compteurs['fiches_portant_une_adresse_partagee'] += $n;
        }

        return $adresses;
    }

    /**
     * Les paires des fiches SANS SIREN du lot, par le nom normalisé exact.
     *
     * @param  list<stdClass>  $fiches
     * @return list<array{garde: int, absorbee: int, motif: string, auto: bool}>
     */
    private function pairesDuLot(string $ws, array $fiches): array
    {
        $ids = [];
        foreach ($fiches as $f) {
            if (Rapprochement::siren(is_string($f->siren) ? $f->siren : null) === null && trim((string) $f->denomination_normalized) !== '') {
                $ids[] = (int) $f->id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $this->compteurs['fiches_sans_siren_comparees'] += count($ids);

        // Alias distincts à chaque niveau (`sx`, `hm`) : aucun ne masque
        // celui d'une requête englobante. Les candidats : même espace, même
        // nom normalisé (`idx_companies_denom_btree`), hors corbeille ; une
        // fiche SANS SIREN n'est comparée qu'aux suivantes (une paire, une
        // fois).
        $lignes = DB::select(
            'SELECT sx.id AS x_id, sx.siren AS x_siren, sx.country_code AS x_country_code, sx.foreign_id AS x_foreign_id,
                    sx.denomination_normalized AS x_nom, sx.postcode AS x_postcode, sx.website AS x_website,
                    sx.discovery_source AS x_source,
                    NOT ' . FichesProtegees::conditionSql('sx.id') . ' AS x_protegee,
                    hm.id, hm.siren, hm.country_code, hm.foreign_id, hm.denomination_normalized, hm.postcode, hm.website,
                    hm.discovery_source, hm.protegee
             FROM companies sx
             JOIN LATERAL (
                 SELECT c2.id, c2.siren, c2.country_code, c2.foreign_id, c2.denomination_normalized, c2.postcode,
                        c2.website, c2.discovery_source,
                        NOT ' . FichesProtegees::conditionSql('c2.id') . ' AS protegee
                 FROM companies c2
                 WHERE c2.workspace_id = sx.workspace_id
                   AND c2.denomination_normalized = sx.denomination_normalized
                   AND c2.id <> sx.id
                   AND c2.deleted_at IS NULL
                   AND (c2.siren IS NOT NULL OR c2.id > sx.id)
                 ORDER BY c2.id
                 LIMIT ' . (self::HOMONYMES_MAX + 1) . '
             ) hm ON true
             WHERE sx.workspace_id = ? AND sx.id = ANY(?::bigint[])
             ORDER BY sx.id, hm.id',
            [$ws, '{' . implode(',', $ids) . '}'],
        );

        /** @var array<int, list<stdClass>> $parFiche */
        $parFiche = [];
        foreach ($lignes as $l) {
            if ($l instanceof stdClass) {
                $parFiche[(int) $l->x_id][] = $l;
            }
        }

        $paires = [];
        foreach ($parFiche as $candidats) {
            if (count($candidats) > self::HOMONYMES_MAX) {
                $this->compteurs['noms_trop_repandus']++;

                continue;
            }
            $pairesX = [];
            foreach ($candidats as $c) {
                $x = (object) [
                    'id' => $c->x_id, 'siren' => $c->x_siren, 'country_code' => $c->x_country_code,
                    'foreign_id' => $c->x_foreign_id, 'denomination_normalized' => $c->x_nom,
                    'postcode' => $c->x_postcode, 'website' => $c->x_website, 'discovery_source' => $c->x_source,
                ];
                $px = FusionFiches::pourPreuve($x);
                $pc = FusionFiches::pourPreuve($c);
                $motif = Rapprochement::motifNomIdentique($px, $pc);
                if ($motif === null) {
                    continue;
                }
                if ($pc['siren'] !== null) {
                    // La fiche au SIREN est l'entité juridique : c'est elle qu'on garde.
                    [$garde, $absorbee, $pg, $pa] = [(int) $c->id, (int) $c->x_id, $pc, $px];
                } else {
                    // Deux fiches sans SIREN : la protégée d'abord, sinon la plus ancienne.
                    $xAvant = (bool) $c->x_protegee !== (bool) $c->protegee ? (bool) $c->x_protegee : (int) $c->x_id < (int) $c->id;
                    [$garde, $absorbee, $pg, $pa] = $xAvant
                        ? [(int) $c->x_id, (int) $c->id, $px, $pc]
                        : [(int) $c->id, (int) $c->x_id, $pc, $px];
                }
                $pairesX[] = [
                    'garde' => $garde,
                    'absorbee' => $absorbee,
                    'motif' => $motif,
                    'auto' => Rapprochement::preuveCertaine($motif, $pg, $pa, $this->fusion->vientDUneCollecte($pa['source'])),
                ];
            }
            // E1 — une preuve certaine ne vaut que si elle désigne UNE seule
            // fiche : deux fiches INSEE (SIREN différents) au même nom, même
            // code postal et même site → aucune fusion automatique, les deux
            // paires vont dans la file de vérification.
            if (count(array_filter($pairesX, static fn (array $p): bool => $p['auto'])) > 1) {
                $this->compteurs['preuves_ambigues']++;
                $pairesX = array_map(static fn (array $p): array => ['auto' => false] + $p, $pairesX);
            }
            array_push($paires, ...$pairesX);
        }

        return $paires;
    }

    // ── Classement et écriture ──────────────────────────────────────────────

    /**
     * Ce que la base sait déjà de chaque paire, dans un sens ou dans l'autre —
     * la MÊME lecture à blanc et en vrai.
     *
     * @param  list<array{garde: int, absorbee: int, motif: string, auto: bool}>  $paires
     * @return list<array{garde: int, absorbee: int, motif: string, auto: bool}> les paires à écrire (pas encore traitées)
     */
    private function classer(string $ws, array $paires): array
    {
        $aEcrire = [];
        foreach (array_chunk($paires, self::PAR_INSTRUCTION) as $morceau) {
            $valeurs = implode(', ', array_fill(0, count($morceau), '(?::bigint, ?::bigint)'));
            $params = [];
            foreach ($morceau as $p) {
                $params[] = $p['garde'];
                $params[] = $p['absorbee'];
            }
            $connues = DB::select(
                "SELECT v.a, v.b, bool_or(d.reviewed_at IS NOT NULL) AS traitee
                 FROM (VALUES {$valeurs}) AS v(a, b)
                 JOIN duplicate_flags d
                   ON d.workspace_id = ? AND d.entity_type = 'company'
                  AND ((d.entity_a_id = v.a AND d.entity_b_id = v.b) OR (d.entity_a_id = v.b AND d.entity_b_id = v.a))
                 GROUP BY v.a, v.b",
                array_merge($params, [$ws]),
            );
            $etat = [];
            foreach ($connues as $k) {
                if ($k instanceof stdClass) {
                    $etat[(int) $k->a . '-' . (int) $k->b] = (bool) $k->traitee;
                }
            }
            foreach ($morceau as $p) {
                $this->compteurs['paires_' . $p['motif']]++;
                $cle = $p['garde'] . '-' . $p['absorbee'];
                if (($etat[$cle] ?? false) === true) {
                    $this->compteurs['paires_deja_traitees']++;

                    continue;
                }
                $this->compteurs[array_key_exists($cle, $etat) ? 'paires_deja_connues' : 'paires_nouvelles']++;
                $this->compteurs[$p['auto'] ? 'fusions_certaines' : 'file_verification']++;
                $aEcrire[] = $p;
            }
        }

        return $aEcrire;
    }

    /** @param  list<array{garde: int, absorbee: int, motif: string, auto: bool}>  $paires */
    private function traiterPaires(string $ws, array $paires, bool $dryRun): void
    {
        if ($dryRun) {
            $this->classer($ws, $paires);

            return;
        }
        foreach (array_chunk($paires, self::PAR_INSTRUCTION) as $morceau) {
            DB::transaction(function () use ($ws, $morceau): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                $this->ecrirePaires($ws, $morceau);
            });
        }
    }

    /**
     * Une paire nouvelle est créée ; une paire en attente est mise à jour
     * (motif, score, preuve) ; une paire TRAITÉE (fusionnée, écartée) ne bouge
     * jamais ; une paire déjà inscrite dans l'autre sens n'est pas doublée.
     *
     * @param  list<array{garde: int, absorbee: int, motif: string, auto: bool}>  $paires
     */
    private function ecrirePaires(string $ws, array $paires): void
    {
        foreach (array_chunk($this->classer($ws, $paires), self::PAR_INSTRUCTION) as $morceau) {
            $valeurs = implode(', ', array_fill(0, count($morceau), '(?::bigint, ?::bigint, ?::numeric, ?::text, ?::boolean)'));
            $params = [$ws];
            foreach ($morceau as $p) {
                array_push($params, $p['garde'], $p['absorbee'], Rapprochement::score($p['motif']), $p['motif'], $p['auto'] ? 'true' : 'false');
            }
            $params[] = $ws;
            DB::insert(
                "INSERT INTO duplicate_flags (workspace_id, entity_type, entity_a_id, entity_b_id, similarity, motif, fusion_auto, detected_at)
                 SELECT ?::uuid, 'company', v.a, v.b, v.s, v.m, v.f, now()
                 FROM (VALUES {$valeurs}) AS v(a, b, s, m, f)
                 WHERE NOT EXISTS (
                     SELECT 1 FROM duplicate_flags dr
                     WHERE dr.workspace_id = ?::uuid AND dr.entity_type = 'company'
                       AND dr.entity_a_id = v.b AND dr.entity_b_id = v.a
                 )
                 ON CONFLICT (workspace_id, entity_type, entity_a_id, entity_b_id) DO UPDATE
                    SET similarity = EXCLUDED.similarity, motif = EXCLUDED.motif,
                        fusion_auto = EXCLUDED.fusion_auto, detected_at = EXCLUDED.detected_at
                    WHERE duplicate_flags.reviewed_at IS NULL",
                $params,
            );
        }
    }

    /**
     * L'empreinte SALÉE est calculée par la base (`doublons_inscrire_adresses`) :
     * le rôle applicatif n'exécute pas la fonction d'empreinte.
     *
     * @param  list<array{email: string, domaine: ?string, nb: int, nature: string}>  $adresses
     */
    private function ecrireAdresses(string $ws, array $adresses): void
    {
        AdressesPartagees::inscrire($ws, $adresses);
    }

    private function mesurerVerrous(): void
    {
        $v = DB::selectOne('SELECT count(*) AS n FROM pg_locks WHERE pid = pg_backend_pid()');
        if ($v instanceof stdClass) {
            $this->verrousMax = max($this->verrousMax, (int) $v->n);
        }
    }

    // ── Bilan et audit ──────────────────────────────────────────────────────

    private function afficherBilan(bool $dryRun): void
    {
        $this->newLine();
        $this->info($dryRun ? '═══ BILAN DE L\'ESSAI À BLANC (rien n\'a été écrit) ═══' : '═══ BILAN DE LA DÉTECTION ═══');
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($this->compteurs),
            array_values($this->compteurs),
        ));
        if (! $dryRun) {
            $this->line("Verrous tenus au plus en fin de lot : {$this->verrousMax}");
        }
        $this->line('Paires : « fusions_certaines » peuvent partir par crm:doublons:fusionner ; « file_verification » attendent un humain (onglet « Doublons à vérifier »).');
    }

    /** « utilisateur@hôte » du processus qui a lancé la commande. */
    private static function operateur(): string
    {
        $utilisateur = get_current_user();
        $hote = gethostname();

        return ($utilisateur !== '' ? $utilisateur : '?') . '@' . ($hote !== false ? $hote : '?');
    }

    /** @param  array<string, mixed>  $details */
    private function auditer(AuditHashChain $audit, string $ws, string $operateur, string $evenement, int $statut, array $details, string $resume): void
    {
        $audit->record([
            'workspace_id' => $ws,
            'user_id' => null,
            'method' => $evenement,
            'path' => 'artisan crm:doublons:detecter — ' . $resume,
            'status' => $statut,
            'ip' => null,
            'user_agent' => 'cli ' . $operateur,
            'payload_hash' => hash('sha256', json_encode($details, JSON_THROW_ON_ERROR)),
        ]);
    }
}
