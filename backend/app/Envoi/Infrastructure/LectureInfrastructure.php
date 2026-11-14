<?php

namespace App\Envoi\Infrastructure;

use App\Envoi\Sante\Constat;
use App\Envoi\Sante\DepotSante;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lecture seule de l'infrastructure d'envoi (lot R03), pour UN espace. Tout vient de la base : aucun appel réseau, aucune
 * interrogation DNS ou de liste noire en direct. Ne rend jamais hôte, port, tunnel, identifiant, clé, ni IP privée.
 * Valeur non calculable : null (ou liste vide), jamais inventée.
 */
final class LectureInfrastructure
{
    /** Limite de domaines par serveur d'envoi (garde anti-dispersion du contrat). */
    private const DOMAINES_PAR_SERVEUR = 3;

    private const HISTORIQUE_MAX = 50;

    public function __construct(private readonly DepotSante $sante) {}

    /** @return array<string, mixed> */
    public function domaines(string $ws): array
    {
        $lignes = $this->lignesDomaines($ws);
        $contexte = $this->contexteDomaines($ws);
        $resumes = array_map(fn (array $d): array => $this->resumeDomaine($d, $contexte), $lignes);
        $serveurs = count($this->serveurs($ws));

        return [
            'data' => $resumes,
            'garde_dispersion' => ['utilises' => count($resumes), 'limite' => max(1, self::DOMAINES_PAR_SERVEUR * $serveurs)],
            // Aucune date d'expiration n'est conservée : objet vide, rien d'inventé.
            'expirations' => (object) [],
        ];
    }

    /** @return ?array<string, mixed> null : domaine inconnu de cet espace */
    public function domaine(string $ws, string $id): ?array
    {
        foreach ($this->lignesDomaines($ws) as $d) {
            if ((string) $d['id'] === $id) {
                $resume = $this->resumeDomaine($d, $this->contexteDomaines($ws));

                // DNS (valeurs publiées) et activité des 30 jours : pas de source lisible ici => listes vides, expire_le null.
                return [
                    'domaine' => $resume,
                    'dns' => [],
                    'jours' => [],
                    'listes_noires' => array_values(array_filter($this->listesNoires($ws), static fn (array $l): bool => $l['element'] === $resume['nom'])),
                    'expire_le' => null,
                ];
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function listesNoires(string $ws): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::select(
            'SELECT DISTINCT ON (type, ressource, zone) type, ressource, zone, statut, controle_le FROM envoi_controles_listes WHERE workspace_id = ? ORDER BY type, ressource, zone, id DESC',
            [$ws],
        ));
        $out = [];
        foreach ($l as $r) {
            // « non fiable » = on ne sait pas : ni « propre » ni « listé », donc pas de ligne. Une IP privée ne sort jamais.
            if ($r->statut === 'non_fiable' || ($r->type === 'ip' && ! $this->ipPublique((string) $r->ressource))) {
                continue;
            }
            $out[] = ['liste' => (string) $r->zone, 'que_faire' => null, 'lien_retrait' => null, 'element' => (string) $r->ressource,
                'present' => $r->statut === 'liste', 'verifie_le' => $this->date((string) $r->controle_le)];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function serveursResumes(string $ws): array
    {
        // MÊME définition que `configuration().courante.numero` (voir versionsConfiguration) ; 1 au minimum (contrat) si rien n'est appliqué.
        $version = 1;
        foreach ($this->versionsConfiguration($ws) as $v) {
            if ($v['resultat'] === 'appliquee') {
                $version = $v['numero'];
            }
        }

        return ['data' => array_map(fn (array $s): array => [
            'id' => (string) $s['id'], 'nom' => (string) $s['source'], 'etat' => $this->etatServeur($ws, $s),
            'adresses_ip' => [], // aucune source d'adresses IP en base : jamais deviné
            'version_configuration' => $version,
        ], $this->serveurs($ws))];
    }

    /** @return ?array<string, mixed> null : serveur inconnu de cet espace */
    public function santeServeur(string $ws, string $id): ?array
    {
        foreach ($this->serveurs($ws) as $s) {
            if ((string) $s['id'] === $id) {
                return ['serveur_id' => (string) $s['id'], 'en_ligne_depuis_jours' => null, 'en_file' => null, 'test' => null, 'paliers' => []];
            }
        }

        return null;
    }

    /**
     * Numéro, statut, date, auteur, empreinte : jamais le contenu d'un paquet (la base n'en garde aucun).
     *
     * @return array<string, mixed>
     */
    public function configuration(string $ws): array
    {
        $versions = $this->versionsConfiguration($ws);
        $courante = null;
        foreach (array_reverse($versions) as $v) {
            if ($v['resultat'] === 'appliquee') {
                $courante = $v;
                break;
            }
        }

        return ['courante' => $courante, 'prete' => null, 'historique' => array_slice(array_reverse($versions), 0, self::HISTORIQUE_MAX)];
    }

    /**
     * LA définition du numéro de version : rang (à partir de 1) dans l'historique COMPLET de l'espace, toutes issues confondues
     * (appliquée, invalide, retour arrière…), par id croissant. La version courante est la dernière « appliquee » ; son numéro est
     * donc celui que rendent `/configuration` (courante.numero) et `/serveurs` (version_configuration). Jamais un compte des seules appliquées.
     *
     * @return list<array{numero: int, par: null, resultat: string, empreinte: string, creee_le: string, appliquee_le: ?string, changements: list<mixed>}>
     */
    private function versionsConfiguration(string $ws): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_config_versions')->where('workspace_id', $ws)->orderBy('id')->get(['statut', 'empreinte', 'cree_le']));
        $versions = [];
        foreach ($lignes as $i => $v) {
            $versions[] = [
                'numero' => $i + 1,
                'par' => null, // l'auteur n'est pas conservé
                'resultat' => (string) $v->statut,
                'empreinte' => (string) $v->empreinte,
                'creee_le' => $this->date((string) $v->cree_le),
                'appliquee_le' => $v->statut === 'appliquee' ? $this->date((string) $v->cree_le) : null,
                'changements' => [],
            ];
        }

        return $versions;
    }

    /** @return list<array{id: string, nom: string, cree_le: string}> */
    private function lignesDomaines(string $ws): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->orderBy('nom')->get(['id', 'nom', 'cree_le']));

        return array_values(array_map(static fn ($d): array => ['id' => (string) $d->id, 'nom' => (string) $d->nom, 'cree_le' => (string) $d->cree_le], $l->all()));
    }

    /** @return list<array{id: string, source: string, actif: bool}> */
    private function serveurs(string $ws): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->where('workspace_id', $ws)->orderBy('id')->get(['id', 'source', 'actif']));

        return array_values(array_map(static fn ($s): array => ['id' => (string) $s->id, 'source' => (string) $s->source, 'actif' => (bool) $s->actif], $l->all()));
    }

    /**
     * Ce dont tous les domaines ont besoin, lu UNE fois (3 requêtes en tout, quel que soit le nombre de domaines).
     *
     * @return array{dns: array<string, array<string, mixed>>, statuts: array<string, list<string>>, boites: array<string, int>}
     */
    private function contexteDomaines(string $ws): array
    {
        $dns = [];
        foreach ($this->sante->controlesDns($ws) as $c) {
            $dns[(string) $c['domaine']] = $c; // le dernier relevé l'emporte, comme avant
        }
        $statuts = [];
        foreach ($this->sante->controlesListes($ws) as $c) {
            if ($c['type'] === 'domaine') {
                $statuts[(string) $c['ressource']][] = $c['statut'];
            }
        }
        $boites = [];
        $lignes = WorkspaceContext::run($ws, fn () => DB::select(
            'SELECT lower(split_part(adresse, \'@\', 2)) AS dom, count(*) AS n FROM envoi_boites WHERE workspace_id = ? GROUP BY 1',
            [$ws],
        ));
        foreach ($lignes as $l) {
            $boites[(string) $l->dom] = (int) $l->n;
        }

        return ['dns' => $dns, 'statuts' => $statuts, 'boites' => $boites];
    }

    /**
     * @param  array{id: string, nom: string, cree_le: string}  $d
     * @param  array{dns: array<string, array<string, mixed>>, statuts: array<string, list<string>>, boites: array<string, int>}  $ctx
     * @return array<string, mixed>
     */
    private function resumeDomaine(array $d, array $ctx): array
    {
        $nom = (string) $d['nom'];
        $dns = $ctx['dns'][$nom] ?? null;
        $statuts = $ctx['statuts'][$nom] ?? [];
        $liste = in_array('liste', $statuts, true);
        $etat = match (true) {
            $liste || ($dns['pause'] ?? false) => 'rouge',
            $dns === null => 'gris',
            ! $dns['pret'] || in_array('non_fiable', $statuts, true) => 'ambre',
            default => 'vert',
        };
        $boites = $ctx['boites'][mb_strtolower($nom)] ?? 0;

        return [
            'id' => (string) $d['id'], 'nom' => $nom, 'etat' => $etat, 'boites' => $boites,
            // La politique DMARC publiée n'est pas conservée (seul « valide ou non » l'est) : « none », la moins protectrice, faute de champ nul au contrat.
            'dmarc' => 'none', 'dmarc_prochaine_etape' => null,
            'listes_noires' => $liste ? 1 : 0, 'ajoute_le' => $this->date((string) $d['cree_le']), 'chauffe' => null,
        ];
    }

    /** @param array{id: string, source: string, actif: bool} $s */
    private function etatServeur(string $ws, array $s): string
    {
        $releve = $this->sante->dernierReleve($ws, (string) $s['source']);
        if (! $s['actif'] || $releve === null) {
            return 'gris';
        }
        $gravites = array_column($this->sante->constatsOuverts($ws, (string) $s['source']), 'gravite');

        return match (true) {
            ! $releve['ok'], in_array(Constat::CRITIQUE, $gravites, true) => 'rouge',
            $gravites !== [] => 'ambre',
            default => 'vert',
        };
    }

    private function ipPublique(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function date(string $t): string
    {
        return (new DateTimeImmutable($t))->format('Y-m-d\TH:i:sP');
    }
}
