<?php

namespace App\Envoi\Sante;

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\Fournisseur;
use App\Envoi\JourEnvoi;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Réponse de `GET /envoi/sante`, EXACTEMENT la forme de `backend/openapi/envoi/pilotage.yaml`. Lecture seule, un seul espace.
 *
 * RÈGLE : ce qui ne peut pas être calculé vaut null ou « gris » (inconnu), jamais une valeur inventée ni un vert par défaut.
 * Hors réponse : hôtes, URL, identifiants, adresses IP et détails des constats.
 */
final class VueSante
{
    private const GRIS = 'gris';

    private const ORDRE = ['vert' => 1, 'gris' => 2, 'ambre' => 3, 'rouge' => 4];

    private const MESSAGERIES = [Fournisseur::GMAIL => 'Gmail et Google Workspace', Fournisseur::MICROSOFT => 'Outlook et Microsoft 365', Fournisseur::AUTRE => 'Autres messageries'];

    public function __construct(private readonly DepotSante $sante, private readonly DepotChauffe $chauffe) {}

    /** @return array<string, mixed> */
    public function pour(string $ws, DateTimeImmutable $maintenant): array
    {
        $jour = JourEnvoi::de($maintenant);
        $pauses = $this->chauffe->pausesActives($ws, $maintenant);
        $pauseGlobale = array_filter($pauses, static fn ($p): bool => $p->portee === 'global') !== [];
        $serveurs = $this->serveurs($ws);
        $constatsEspace = $this->sante->constatsOuverts($ws, SuperviseurSante::ESPACE);
        $sante_connue = array_filter($serveurs, static fn (array $s): bool => $s['etat'] !== self::GRIS) !== [];
        $boites = $this->boites($ws, $jour, $pauses, $pauseGlobale, $sante_connue);
        $domaines = $this->domaines($ws, $boites, $pauseGlobale);
        $test = $this->testDuMatin($ws, $jour);

        $feux = array_values(array_merge(
            array_column($serveurs, 'etat'),
            array_column($domaines, 'etat'),
            array_column($boites, 'etat'),
            [$test['etat']],
            array_map(static fn (array $c): string => $c['gravite'] === Constat::CRITIQUE ? 'rouge' : 'ambre', $constatsEspace),
            array_map(static fn ($p): string => $p->portee === 'global' ? 'rouge' : 'ambre', $pauses),
        ));
        $feu = $serveurs === [] ? self::GRIS : $this->pire($feux);
        $nbConstats = count($constatsEspace) + array_sum(array_map(fn (array $s): int => $s['_constats'], $serveurs));
        $verifie = array_filter(array_column($serveurs, '_releve_le'));

        return [
            'feu' => $feu,
            'boites' => array_map(static fn (array $b): array => array_diff_key($b, ['_domaine' => 1]), $boites),
            'domaines' => $domaines,
            'serveurs' => array_map(static fn (array $s): array => array_diff_key($s, ['_constats' => 1, '_releve_le' => 1]), $serveurs),
            'test_du_matin' => $test,
            'alertes' => $nbConstats + count($pauses),
            'verifie_le' => $verifie === [] ? null : self::iso(max($verifie)),
            'volume_jour' => [
                'possibles' => array_sum(array_map(static fn (array $b): int => $b['statut'] === 'suspendue' ? 0 : $b['quota_jour'], $boites)),
                'envoyes' => array_sum(array_column($boites, 'envoyes_aujourdhui')),
            ],
            'rythme_messageries' => $this->rythme($ws, $jour, $pauses, $pauseGlobale),
            'envoyes_14_jours' => $this->quatorzeJours($ws, $jour),
            'pauses' => array_map(static fn ($p): array => [
                'motif' => (string) $p->motif, 'portee' => (string) $p->portee,
                'fournisseur' => $p->fournisseur === null ? null : (string) $p->fournisseur,
                'fin' => $p->expire_le === null ? null : self::iso((string) $p->expire_le),
            ], $pauses),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function serveurs(string $ws): array
    {
        $sources = WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->where('workspace_id', $ws)->orderBy('source')->get(['id', 'source']));
        $versions = max(1, (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_config_versions')->where('workspace_id', $ws)->where('statut', 'appliquee')->count()));
        $out = [];
        foreach ($sources as $s) {
            $releve = $this->sante->dernierReleve($ws, (string) $s->source);
            $constats = $this->sante->constatsOuverts($ws, (string) $s->source);
            $graves = array_column($constats, 'gravite');
            $etat = $releve === null ? self::GRIS
                : (! $releve['ok'] || in_array(Constat::CRITIQUE, $graves, true) ? 'rouge' : ($constats !== [] ? 'ambre' : 'vert'));
            $out[] = [
                'id' => (string) $s->id, 'nom' => (string) $s->source, 'etat' => $etat, 'adresses_ip' => [], 'version_configuration' => $versions,
                '_constats' => count($constats), '_releve_le' => $releve['mesure_le'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<\stdClass>  $pauses
     * @return list<array<string, mixed>>
     */
    private function boites(string $ws, DateTimeImmutable $jour, array $pauses, bool $pauseGlobale, bool $santeConnue): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('workspace_id', $ws)->orderBy('adresse')->get(['id', 'adresse', 'etat', 'limite_jour']));
        $c = $this->chauffe->chiffresParBoite($ws, $jour);
        $out = [];
        foreach ($lignes as $b) {
            $m = $c[(string) $b->id] ?? null;
            $e7 = (int) ($m->e7 ?? 0);
            $enPause = (string) $b->etat === 'en_pause';
            [$etat, $statut] = match (true) {
                $enPause => ['rouge', 'suspendue'],
                $pauseGlobale => ['rouge', 'ralentie'],
                $pauses !== [] => ['ambre', 'ralentie'],
                ! $santeConnue => [self::GRIS, 'ralentie'], // aucun relevé de santé : on ne sait pas, donc pas « prête »
                default => ['vert', 'prete'],
            };
            $adresse = (string) $b->adresse;
            $out[] = [
                'id' => (string) $b->id, 'adresse' => $adresse, 'domaine_id' => Fournisseur::domaine($adresse), 'nom_affiche' => $adresse,
                'etat' => $etat, 'statut' => $statut, 'quota_jour' => (int) $b->limite_jour, 'envoyes_aujourdhui' => (int) ($m->e1 ?? 0),
                'taux_refus' => $e7 === 0 ? 0.0 : round(100 * (int) ($m->r7 ?? 0) / $e7, 1), // rebonds durs seulement : les refus mous ne sont pas comptés ici
                'reponses_7j' => (int) ($m->a7 ?? 0), '_domaine' => Fournisseur::domaine($adresse),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $boites
     * @return list<array<string, mixed>>
     */
    private function domaines(string $ws, array $boites, bool $pauseGlobale): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->orderBy('nom')->get(['id', 'nom', 'cree_le']));
        $dns = array_column($this->sante->controlesDns($ws), null, 'domaine');
        $listes = array_filter($this->sante->controlesListes($ws), static fn (array $l): bool => $l['type'] === 'domaine');
        $out = [];
        foreach ($lignes as $d) {
            $nom = mb_strtolower((string) $d->nom);
            $statuts = array_column(array_filter($listes, static fn (array $l): bool => mb_strtolower($l['ressource']) === $nom), 'statut');
            $etat = match (true) {
                ! isset($dns[$nom]) => self::GRIS,
                ! $dns[$nom]['pret'] || in_array('liste', $statuts, true) || $pauseGlobale => 'rouge',
                in_array('non_fiable', $statuts, true) || $dns[$nom]['pause'] => 'ambre',
                default => 'vert',
            };
            $out[] = [
                'id' => (string) $d->id, 'nom' => (string) $d->nom, 'etat' => $etat,
                'boites' => count(array_filter($boites, static fn (array $b): bool => $b['_domaine'] === $nom)),
                'dmarc' => 'none', // la politique publiée n'est pas conservée (seulement « valide ou non ») : la moins protectrice, jamais une valeur flatteuse
                'dmarc_prochaine_etape' => null, 'listes_noires' => count(array_filter($statuts, static fn (string $s): bool => $s === 'liste')),
                'ajoute_le' => self::iso((string) $d->cree_le), 'chauffe' => null, // palier de chauffe par domaine : pas encore calculé ici
            ];
        }

        return $out;
    }

    /** @return array{le: ?string, temoins_ok: int, temoins_total: int, etat: string} */
    private function testDuMatin(string $ws, DateTimeImmutable $jour): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::select(
            'SELECT DISTINCT ON (fournisseur) fournisseur, jour, reception, auth_alignee, cree_le FROM envoi_temoins_journees
             WHERE workspace_id = ? AND jour = (SELECT max(jour) FROM envoi_temoins_journees WHERE workspace_id = ?) ORDER BY fournisseur, id DESC',
            [$ws, $ws],
        ));
        if ($l === []) {
            return ['le' => null, 'temoins_ok' => 0, 'temoins_total' => 0, 'etat' => self::GRIS];
        }
        $ok = count(array_filter($l, static fn ($r): bool => $r->reception === 'inbox' && (bool) $r->auth_alignee));
        $indesirable = array_filter($l, static fn ($r): bool => $r->reception === 'indesirables') !== [];
        $perime = (string) $l[0]->jour < $jour->format('Y-m-d'); // pas de test aujourd'hui : on ne l'affirme pas
        $etat = $perime ? self::GRIS : ($ok === count($l) ? 'vert' : ($indesirable ? 'rouge' : 'ambre'));

        return ['le' => self::iso(max(array_map(static fn ($r): string => (string) $r->cree_le, $l))), 'temoins_ok' => $ok, 'temoins_total' => count($l), 'etat' => $etat];
    }

    /**
     * @param  list<\stdClass>  $pauses
     * @return list<array<string, mixed>>
     */
    private function rythme(string $ws, DateTimeImmutable $jour, array $pauses, bool $pauseGlobale): array
    {
        $out = [];
        foreach (self::MESSAGERIES as $f => $libelle) {
            $freine = $pauseGlobale || array_filter($pauses, static fn ($p): bool => $p->fournisseur === $f) !== [];
            $out[] = [
                'messagerie' => $libelle, 'envoyes' => $this->chauffe->totauxDuJour($ws, $jour, BudgetChauffe::RESSOURCE_DOMAINE, $f)['envoyes'],
                'par_heure' => null, 'etat' => $freine ? 'freine' : 'normal', // le rythme horaire par messagerie n'est pas mesuré
            ];
        }

        return $out;
    }

    /** @return list<array{jour: string, envoyes: int}> */
    private function quatorzeJours(string $ws, DateTimeImmutable $jour): array
    {
        $debut = $jour->modify('-13 days');
        $par = $this->chauffe->envoyesParJour($ws, $debut, $jour);
        $out = [];
        for ($i = 0; $i < 14; $i++) {
            $j = $debut->modify("+{$i} days")->format('Y-m-d');
            $out[] = ['jour' => $j, 'envoyes' => (int) ($par[$j] ?? 0)];
        }

        return $out;
    }

    /** @param list<string> $feux */
    private function pire(array $feux): string
    {
        $pire = 'vert';
        foreach ($feux as $f) {
            if (self::ORDRE[$f] > self::ORDRE[$pire]) {
                $pire = $f;
            }
        }

        return $pire;
    }

    private static function iso(string $instant): string
    {
        return (new DateTimeImmutable($instant))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
