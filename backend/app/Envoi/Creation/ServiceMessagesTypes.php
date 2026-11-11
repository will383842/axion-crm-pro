<?php

namespace App\Envoi\Creation;

use App\Envoi\Campagnes\DepotModeles;
use App\Envoi\Exceptions\ModeleRefuse;
use App\Envoi\Message\ComposeurMessage;
use App\Envoi\Message\ControleurContenu;
use App\Envoi\Message\HtmlListeBlanche;
use App\Envoi\Message\MessageRefuse;
use App\Envoi\Message\SignatureDkim;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Messages types (lot R10) : lecture, écriture versionnée, contrôles et aperçu. RENDU SEUL.
 * Ne résout ni `ServeurEnvoi`, ni l'adaptateur SMTP, ni le séquenceur ; ne signe pas ; n'injecte rien ; n'écrit
 * que dans `envoi_modeles` et `envoi_modele_versions` (via DepotModeles). Aucune mesure d'ouverture : images,
 * pixels et liens traqueurs sont refusés par ControleurContenu / HtmlListeBlanche.
 *
 * @phpstan-type Version array{numero: int, objet: string, corps: string, objet_b: null, modifie_le: string}
 * @phpstan-type Message array{id: string, nom: string, statut: string, version_validee: ?int, version_courante: int, versions: list<Version>, utilise_par: int, bloc_information: bool}
 */
final class ServiceMessagesTypes
{
    public const CONTROLES = [
        'mots_interdits' => 'Mots et adresses interdits',
        'liens' => 'Liens directs, sans traqueur',
        'bloc_information' => 'Bloc d’information (art. 14) et désinscription',
        'texte_simple' => 'Texte simple, HTML limité à la liste blanche',
        'valeurs_repli' => 'Valeur de repli pour chaque champ',
    ];

    public function __construct(private readonly DepotModeles $depot, private readonly ControleurContenu $controleur = new ControleurContenu) {}

    /** @return list<string> motifs de refus du contenu (vide = accepté) */
    public function motifs(string ...$textes): array
    {
        $html = array_values(array_filter($textes, static fn (string $t): bool => str_contains($t, '<')));

        return $this->controleur->analyser(array_values($textes), [], $html);
    }

    /** @return array{controles: list<array<string,mixed>>, bloquants: int} */
    public function controler(string $objet, string $corps, bool $blocInformation): array
    {
        $parCode = array_fill_keys(array_keys(self::CONTROLES), []);
        foreach ($this->motifs($objet, $corps) as $m) {
            $parCode[self::codeDuMotif($m)][] = $m;
        }
        $controles = [];
        foreach (self::CONTROLES as $code => $libelle) {
            $motifs = $parCode[$code];
            if ($code === 'bloc_information' && ! $blocInformation) {
                $motifs[] = 'Le bloc d’information et le lien de désinscription sont obligatoires.';
            }
            $controles[] = ['code' => $code, 'libelle' => $libelle, 'etat' => $motifs === [] ? 'ok' : 'bloquant', 'detail' => $motifs === [] ? null : implode(' ', $motifs)];
        }

        return ['controles' => $controles, 'bloquants' => count(array_filter($controles, static fn (array $c): bool => $c['etat'] === 'bloquant'))];
    }

    private static function codeDuMotif(string $m): string
    {
        return match (true) {
            str_contains($m, 'Variable') => 'valeurs_repli',
            str_contains($m, 'traqueur'), str_contains($m, 'Lien ou adresse') => 'liens',
            self::estMotifHtml($m) => 'texte_simple',
            default => 'mots_interdits',
        };
    }

    private static function estMotifHtml(string $m): bool
    {
        return preg_match('/balise|attribut|html|image|script|href|ressource|style|iframe|commentaire|entité/iu', $m) === 1;
    }

    /** @return array{objet: string, corps: string, de: string} */
    public function apercu(string $objet, string $corps): array
    {
        if (($motifs = $this->motifs($objet, $corps)) !== []) {
            throw new MessageRefuse($motifs);
        }
        $c = new ComposeurMessage('exemple.test', null, SignatureDkim::Serveur);

        // Destinataire exemple sans donnée : chaque champ prend sa valeur de repli.
        return ['objet' => $c->rendre($objet, []), 'corps' => $c->rendre($corps, []), 'de' => 'Expéditeur exemple <contact@exemple.test>'];
    }

    /** @return list<string> noms des champs de fusion, triés, sans doublon */
    public static function champs(string ...$textes): array
    {
        preg_match_all('/\{\{(.*?)\}\}/s', implode("\n", $textes), $m);
        $noms = array_unique(array_filter(array_map(static fn (string $i): string => trim(explode('|', $i, 2)[0]), $m[1])));
        sort($noms);

        return $noms;
    }

    /** @return Message */
    public function lire(string $ws, string $id): array
    {
        if (! Str::isUuid($id)) {
            throw ModeleRefuse::introuvable();
        }
        $m = WorkspaceContext::run($ws, fn () => DB::table('envoi_modeles')->where('workspace_id', $ws)->where('id', $id)->first()) ?? throw ModeleRefuse::introuvable();

        return $this->exposer($ws, $m);
    }

    /** @return list<Message> */
    public function lister(string $ws, ?string $q, bool $archives): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_modeles')->where('workspace_id', $ws)
            ->when(! $archives, fn ($r) => $r->where('statut', '<>', 'archive'))->orderByDesc('cree_le')->limit(200)->get()->all());
        $cle = static fn (string $t): string => Str::lower(Str::ascii($t));
        $q = $q === null ? '' : $cle(trim($q));

        return array_values(array_map(fn ($m) => $this->exposer($ws, $m), array_filter($lignes, static fn ($m): bool => $q === '' || str_contains($cle($m->nom), $q))));
    }

    /** @return Message */
    private function exposer(string $ws, \stdClass $m): array
    {
        $versions = array_reverse($this->depot->versions($ws, $m->id));   // de la plus ancienne à la plus récente
        $derniere = end($versions);
        if ($derniere === false) {
            throw ModeleRefuse::introuvable();   // jamais : un modèle naît avec sa version 1
        }

        return [
            'id' => (string) $m->id, 'nom' => (string) $m->nom, 'statut' => (string) $m->statut, 'version_validee' => $m->version_validee === null ? null : (int) $m->version_validee,
            'version_courante' => (int) $derniere->numero,
            'versions' => array_map(static fn ($v): array => ['numero' => (int) $v->numero, 'objet' => (string) $v->objet, 'corps' => (string) $v->corps, 'objet_b' => null,
                'modifie_le' => CarbonImmutable::parse($v->cree_le)->utc()->toIso8601String()], $versions),
            'utilise_par' => 0,
            'bloc_information' => true,
        ];
    }

    /**
     * Dernière version d'un message exposé (toujours au moins une : un modèle naît avec sa version 1).
     *
     * @param  Message  $message
     * @return Version
     */
    public static function derniere(array $message): array
    {
        $derniere = end($message['versions']);

        return $derniere === false ? throw ModeleRefuse::introuvable() : $derniere;
    }

    /** @return Message */
    public function creer(string $ws, string $nom, string $objet, string $corps, string $auteur): array
    {
        $this->exiger($objet, $corps);

        return $this->lire($ws, $this->depot->creer($ws, $nom, $objet, $corps, $auteur));
    }

    /** @return Message */
    public function enregistrer(string $ws, string $id, string $objet, string $corps, string $auteur): array
    {
        $this->exiger($objet, $corps);
        $this->depot->nouvelleVersion($ws, $id, $objet, $corps, $auteur);

        return $this->lire($ws, $id);
    }

    /** @return Message */
    public function restaurer(string $ws, string $id, int $numero, string $auteur): array
    {
        $this->depot->restaurer($ws, $id, $numero, $auteur);

        return $this->lire($ws, $id);
    }

    /**
     * Valide la DERNIÈRE version (relue au moment du geste, sous verrou) : brouillon -> à relire -> validé.
     * Le contenu est recontrôlé avant.
     *
     * @return Message
     */
    public function valider(string $ws, string $id, int $numero): array
    {
        WorkspaceContext::run($ws, fn () => DB::transaction(function () use ($ws, $id, $numero): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_modele|' . $ws . '|' . $id]);
            $m = $this->lire($ws, $id);
            if ($numero !== $m['version_courante']) {
                throw ModeleRefuse::contenu('seule la dernière version peut être validée');
            }
            $v = self::derniere($m);
            $this->exiger($v['objet'], $v['corps']);
            if ($m['statut'] === 'brouillon') {
                $this->depot->changerStatut($ws, $id, 'a_relire');
            }
            $this->depot->changerStatut($ws, $id, 'valide', $numero);
        }));

        return $this->lire($ws, $id);
    }

    /** @return Message */
    public function archiver(string $ws, string $id): array
    {
        $this->lire($ws, $id);
        $this->depot->archiver($ws, $id);

        return $this->lire($ws, $id);
    }

    private function exiger(string $objet, string $corps): void
    {
        if (($motifs = $this->motifs($objet, $corps)) !== []) {
            throw new MessageRefuse($motifs);
        }
    }
}
