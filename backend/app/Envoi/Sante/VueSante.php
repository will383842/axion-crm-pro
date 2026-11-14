<?php

namespace App\Envoi\Sante;

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\Fournisseur;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
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
 *
 * Choix assumés : un témoin d'un fournisseur hors des 6 requis compte comme « mauvais » (test du matin ambre) ; les lignes de chauffe
 * au fournisseur `inconnu` sont comptées dans « Autres messageries », pour que la somme des messageries égale `volume_jour.envoyes`.
 */
final class VueSante
{
    private const GRIS = 'gris';

    private const ORDRE = ['vert' => 1, 'gris' => 2, 'ambre' => 3, 'rouge' => 4];

    private const MESSAGERIES = [Fournisseur::GMAIL => 'Gmail et Google Workspace', Fournisseur::MICROSOFT => 'Outlook et Microsoft 365', Fournisseur::AUTRE => 'Autres messageries'];

    public function __construct(private readonly DepotSante $sante, private readonly DepotChauffe $chauffe, private readonly DepotSocle $socle) {}

    /** @return array<string, mixed> */
    public function pour(string $ws, DateTimeImmutable $maintenant): array
    {
        $jour = JourEnvoi::de($maintenant);
        $pauses = $this->chauffe->pausesActives($ws, $maintenant);
        $arret = $this->socle->toutArreter($ws); // arrêt général armé : plus rien ne part
        $toutEstFreine = $arret || array_filter($pauses, static fn ($p): bool => $p->portee === 'global') !== [];
        $serveurs = $this->serveurs($ws);
        $constatsEspace = $this->sante->constatsOuverts($ws, SuperviseurSante::ESPACE);
        $santeConnue = array_filter($serveurs, static fn (array $s): bool => $s['etat'] !== self::GRIS) !== [];
        $domaines = $this->domaines($ws, $toutEstFreine);
        $boites = $this->boites($ws, $jour, $domaines, $toutEstFreine, $santeConnue, $arret);
        $test = $this->testDuMatin($ws, $jour);

        // Le pire feu l'emporte : rouge > ambre > gris (inconnu) > vert. Aucun serveur déclaré = on ne sait pas (gris), sans masquer un rouge.
        $feux = array_values(array_merge(
            $serveurs === [] ? [self::GRIS] : [],
            $arret ? ['rouge'] : [],
            array_column($serveurs, 'etat'),
            array_column($domaines, 'etat'),
            array_column($boites, 'etat'),
            [$test['etat']],
            array_map(static fn (array $c): string => $c['gravite'] === Constat::CRITIQUE ? 'rouge' : 'ambre', $constatsEspace),
            array_map(static fn ($p): string => $p->portee === 'global' ? 'rouge' : 'ambre', $pauses),
        ));
        $feu = $this->pire($feux);
        $nbConstats = count($constatsEspace) + array_sum(array_map(fn (array $s): int => $s['_constats'], $serveurs));
        $verifie = array_filter(array_column($serveurs, '_releve_le'));

        return [
            'feu' => $feu,
            'boites' => $boites,
            'domaines' => array_map(static fn (array $d): array => array_diff_key($d, ['_cle' => 1]), $domaines),
            'serveurs' => array_map(static fn (array $s): array => array_diff_key($s, ['_constats' => 1, '_releve_le' => 1]), $serveurs),
            'test_du_matin' => $test,
            'alertes' => $nbConstats + count($pauses) + ($arret ? 1 : 0),
            'verifie_le' => $verifie === [] ? null : self::iso(max($verifie)),
            'volume_jour' => [
                'possibles' => array_sum(array_map(static fn (array $b): int => $b['statut'] === 'suspendue' ? 0 : $b['quota_jour'], $boites)),
                'envoyes' => array_sum(array_column($boites, 'envoyes_aujourdhui')),
            ],
            'rythme_messageries' => $this->rythme($ws, $jour, $pauses, $toutEstFreine),
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
        $nb = (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_config_versions')->where('workspace_id', $ws)->where('statut', 'appliquee')->count());
        $versions = $nb > 0 ? $nb : null; // aucune version appliquée : inconnue, pas « 1 »
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
     * @param  list<array<string, mixed>>  $domaines
     * @return list<array<string, mixed>>
     */
    private function boites(string $ws, DateTimeImmutable $jour, array $domaines, bool $toutEstFreine, bool $santeConnue, bool $arretGeneral): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('workspace_id', $ws)->orderBy('adresse')->get(['id', 'adresse', 'etat', 'limite_jour']));
        $c = $this->chauffe->chiffresParBoite($ws, $jour);
        $parNom = array_column($domaines, null, '_cle');
        $out = [];
        foreach ($lignes as $b) {
            $m = $c[(string) $b->id] ?? null;
            $adresse = (string) $b->adresse;
            $domaine = $parNom[Fournisseur::domaine($adresse)] ?? null;
            $feuDomaine = $domaine['etat'] ?? self::GRIS; // domaine non enregistré dans l'espace : inconnu
            // Une pause visant une seule messagerie ne change pas l'état de la boîte : elle se lit dans le rythme par messagerie.
            [$etat, $statut] = match (true) {
                $arretGeneral || (string) $b->etat === 'en_pause' => ['rouge', 'suspendue'], // arrêt général : rien ne peut partir
                $toutEstFreine || $feuDomaine === 'rouge' => ['rouge', 'ralentie'],
                $feuDomaine === 'ambre' => ['ambre', 'ralentie'],
                ! $santeConnue || $feuDomaine === self::GRIS => [self::GRIS, 'inconnu'], // on ne sait pas : jamais « prête »
                default => ['vert', 'prete'],
            };
            $out[] = [
                'id' => (string) $b->id, 'adresse' => $adresse, 'domaine_id' => $domaine['id'] ?? null, 'nom_affiche' => $adresse,
                'etat' => $etat, 'statut' => $statut, 'quota_jour' => (int) $b->limite_jour, 'envoyes_aujourdhui' => (int) ($m->e1 ?? 0),
                'taux_refus' => null, // refus durs ET mous : la source des refus mous par boîte n'existe pas encore, donc non mesuré
                'reponses_7j' => (int) ($m->a7 ?? 0),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function domaines(string $ws, bool $toutEstFreine): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->orderBy('nom')->get(['id', 'nom', 'cree_le']));
        $nbBoites = WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('workspace_id', $ws)->selectRaw("split_part(lower(adresse), '@', 2) AS d, count(*) AS n")->groupBy('d')->pluck('n', 'd')->all());
        $dns = array_column(array_map(static fn (array $c): array => ['domaine' => mb_strtolower((string) $c['domaine'])] + $c, $this->sante->controlesDns($ws)), null, 'domaine');
        $listes = array_filter($this->sante->controlesListes($ws), static fn (array $l): bool => $l['type'] === 'domaine');
        $out = [];
        foreach ($lignes as $d) {
            $nom = mb_strtolower((string) $d->nom);
            $statuts = array_column(array_filter($listes, static fn (array $l): bool => mb_strtolower($l['ressource']) === $nom), 'statut');
            $etat = match (true) {
                $toutEstFreine => 'rouge',
                ! isset($dns[$nom]) => self::GRIS,
                ! $dns[$nom]['pret'] || in_array('liste', $statuts, true) => 'rouge',
                in_array('non_fiable', $statuts, true) || $dns[$nom]['pause'] => 'ambre',
                default => 'vert',
            };
            $out[] = [
                'id' => (string) $d->id, 'nom' => (string) $d->nom, 'etat' => $etat, 'boites' => (int) ($nbBoites[$nom] ?? 0),
                'dmarc' => null, // le contrôle DNS ne garde que « politique valide ou non », pas la politique elle-même : inconnue
                'dmarc_prochaine_etape' => null, 'listes_noires' => count(array_filter($statuts, static fn (string $s): bool => $s === 'liste')),
                'ajoute_le' => self::iso((string) $d->cree_le), 'chauffe' => null, // palier de chauffe par domaine : pas encore calculé ici
                '_cle' => $nom,
            ];
        }

        return $out;
    }

    /** @return array{le: ?string, temoins_ok: int, temoins_total: int, etat: string} les 6 fournisseurs témoins sont requis */
    private function testDuMatin(string $ws, DateTimeImmutable $jour): array
    {
        $requis = DepotSocleDb::FOURNISSEURS_TEMOINS;
        $l = WorkspaceContext::run($ws, fn () => DB::select(
            'SELECT DISTINCT ON (fournisseur) fournisseur, jour, reception, auth_alignee, cree_le FROM envoi_temoins_journees
             WHERE workspace_id = ? AND jour = (SELECT max(jour) FROM envoi_temoins_journees WHERE workspace_id = ?) ORDER BY fournisseur, id DESC',
            [$ws, $ws],
        ));
        if ($l === []) {
            return ['le' => null, 'temoins_ok' => 0, 'temoins_total' => count($requis), 'etat' => self::GRIS];
        }
        $bons = array_filter($l, static fn ($r): bool => $r->reception === 'inbox' && (bool) $r->auth_alignee && in_array($r->fournisseur, $requis, true));
        $indesirable = array_filter($l, static fn ($r): bool => $r->reception === 'indesirables') !== [];
        $mauvais = count($l) - count($bons) > 0;
        $perime = (string) $l[0]->jour < $jour->format('Y-m-d'); // pas de test aujourd'hui : on ne l'affirme pas
        $etat = match (true) {
            $perime => self::GRIS,
            $indesirable => 'rouge',
            $mauvais => 'ambre',
            count($bons) === count($requis) => 'vert', // vert seulement si les 6 sont bons
            default => self::GRIS, // témoins manquants : inconnu
        };

        return ['le' => self::iso(max(array_map(static fn ($r): string => (string) $r->cree_le, $l))), 'temoins_ok' => count($bons), 'temoins_total' => count($requis), 'etat' => $etat];
    }

    /**
     * @param  list<\stdClass>  $pauses
     * @return list<array<string, mixed>>
     */
    private function rythme(string $ws, DateTimeImmutable $jour, array $pauses, bool $toutEstFreine): array
    {
        $out = [];
        foreach (self::MESSAGERIES as $f => $libelle) {
            $freine = $toutEstFreine || array_filter($pauses, static fn ($p): bool => $p->fournisseur === $f) !== [];
            $out[] = [
                'messagerie' => $libelle, 'envoyes' => $this->chauffe->totauxDuJour($ws, $jour, BudgetChauffe::RESSOURCE_DOMAINE, $f)['envoyes']
                    + ($f === Fournisseur::AUTRE ? $this->chauffe->totauxDuJour($ws, $jour, BudgetChauffe::RESSOURCE_DOMAINE, Fournisseur::INCONNU)['envoyes'] : 0),
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
