<?php

namespace Tests\Support;

use App\Crm\FichesProtegees;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\GestesCampagne;
use App\Envoi\Campagnes\MaterialisationCampagne;
use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\RenvoiParMemeCle;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\EmpreinteTemoin;
use App\Envoi\Imap\FabriqueLecteurFixe;
use App\Envoi\Imap\FabriqueLecteurs;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\Verp;
use App\Envoi\Message\ComposeurMessage;
use App\Envoi\Message\SignatureDkim;
use App\Envoi\PasserelleLocale;
use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Sequence\ComposeurSequenceMessage;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\ParametresExpedition;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Banc de bout en bout d'une campagne (lot L7-A) : aides SEULEMENT, aucun test. Données fictives (`*.example.test`).
 *
 * Tout passe par le chemin légal (dépôt de campagne, matérialisation, geste « lancer », séquenceur, relève IMAP en mémoire).
 * L'appelant tient la transaction du test (RefreshDatabase) : rien n'est committé. `config('envoi.reel_actif')` n'est JAMAIS
 * modifiée ; le séquenceur du banc a son propre prédicat de porte (local), le faux serveur remplace tout transport réel.
 */
final class BancCampagne
{
    public const DOMAINE = 'banc.example.test';

    /** Lundi ouvré fictif, avant la fenêtre d'envoi (le banc avance l'horloge lui-même). */
    public const JOUR = '2026-11-16';

    public ?FauxServeurEnvoi $serveur = null;

    /** @var list<MessageAEnvoyer> messages injectés sur le faux serveur, dans l'ordre */
    public array $injectes = [];

    public string $boite = '';

    public ?LecteurBoiteMemoire $lecteur = null;

    private int $uid = 0;

    private ?Sequenceur $sequenceur = null;

    public function __construct(public readonly string $ws) {}

    public static function jour(string $heure = '10:00', int $decalageJours = 0): DateTimeImmutable
    {
        $t = new DateTimeImmutable(self::JOUR . ' ' . $heure, new DateTimeZone('Europe/Paris'));

        return $decalageJours === 0 ? $t : $t->modify(($decalageJours > 0 ? '+' : '') . $decalageJours . ' days');
    }

    /** Espace + produit Envoi + identité (R14) + domaine d'envoi + une boîte + dernière décision de feu vert « accorde ». */
    public static function espace(): self
    {
        Carbon::setTestNow(self::jour('06:00'));   // l'horloge de l'application = le jour fictif (verdicts datés du même jour) ; le test la rend dans afterEach
        $ws = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $ws, 'slug' => 'banc-' . substr(str_replace('-', '', $ws), 0, 10), 'name' => 'Banc campagne', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $banc = new self($ws);
        DB::table('workspaces')->where('id', $ws)->update(['settings' => json_encode(['produits' => ['envoi' => 'actif']])]);
        app(DepotReglages::class)->enregistrerIdentite($ws, [
            'raison_sociale' => 'Banc Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test', 'signature' => 'Equipe Banc',
            'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
        ], 'banc');
        $jour = self::jour()->format('c');
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => self::DOMAINE, 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour, 'flux' => 'prospection']);
        $banc->boite = (string) Str::uuid();
        DB::table('envoi_boites')->insert(['id' => $banc->boite, 'workspace_id' => $ws, 'adresse' => 'contact@' . self::DOMAINE, 'hote' => 'imap.example.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'banc']);
        DB::table('envoi_feu_vert_decisions')->insert(['workspace_id' => $ws, 'decision' => 'accorde', 'auteur' => 'banc', 'motif' => 'banc de test', 'empreinte_liste' => 'banc', 'points' => '{}']);

        return $banc;
    }

    /**
     * Campagne de $n entreprises fictives (un contact nominatif chacune), SANS boîte ni plafond, validée puis matérialisée pour de vrai.
     *
     * @param  list<int>  $secondContact  rangs (1..n) des entreprises qui ont un SECOND contact nominatif (une seule personne est inscrite par entreprise)
     * @return string identifiant de la campagne (statut « programmee », préparée)
     */
    public function campagne(int $n = 2, int $etapes = 2, int $delaiJours = 3, array $secondContact = []): string
    {
        $ws = $this->ws;
        $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste banc ' . Str::random(6)]);
        DB::table('fonctions_espace')->updateOrInsert(['espace_id' => $ws, 'fonction' => 'segment.federations'], ['ouverte' => true, 'updated_at' => now()]);
        $slug = FichesProtegees::TAG_FEDERATIONS;   // segment reconnu par la porte (comme l'essai à blanc)
        $tag = (int) DB::table('tags')->insertGetId(['workspace_id' => $ws, 'slug' => $slug, 'name' => $slug, 'category' => 'custom', 'kind' => 'auto', 'rules' => '[]', 'is_locked' => false, 'created_at' => now(), 'updated_at' => now()]);
        $collecte = self::jour('09:00', -40)->format('c');   // collectées bien avant le jour fictif
        for ($i = 1; $i <= $n; $i++) {
            $e = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => DoublonsFixtures::siren(), 'denomination' => 'Societe banc ' . $i, 'discovery_source' => 'insee', 'first_info_at' => $collecte, 'created_at' => $collecte, 'updated_at' => $collecte]);
            $adresse = "prenom.nom{$i}.{$e}@societe.example.test";
            // Le mode témoins reste ALLUMÉ (garde réelle) : les adresses fictives du banc y sont déclarées témoins.
            DB::table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => EmpreinteTemoin::pourDecision($adresse), 'libelle' => 'banc ' . $i, 'fournisseur' => DepotSocleDb::FOURNISSEURS_TEMOINS[($i - 1) % count(DepotSocleDb::FOURNISSEURS_TEMOINS)]]);
            DB::table('company_tag')->insert(['company_id' => $e, 'tag_id' => $tag, 'workspace_id' => $ws, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
            DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $i, 'email' => $adresse, 'first_info_at' => $collecte, 'created_at' => $collecte, 'updated_at' => $collecte]);
            if (in_array($i, $secondContact, true)) {
                $autre = "autre.personne{$i}.{$e}@societe.example.test";
                DB::table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => EmpreinteTemoin::pourDecision($autre), 'libelle' => 'banc autre ' . $i, 'fournisseur' => DepotSocleDb::FOURNISSEURS_TEMOINS[($i - 1) % count(DepotSocleDb::FOURNISSEURS_TEMOINS)]]);
                DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Autre', 'last_name' => 'Personne' . $i, 'email' => $autre, 'first_info_at' => $collecte, 'created_at' => $collecte, 'updated_at' => $collecte]);
            }
            DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $e, 'origine' => 'coche']);
        }
        $sequence = [];
        for ($r = 1; $r <= $etapes; $r++) {
            $m = app(ServiceMessagesTypes::class)->creer($ws, 'Etape ' . $r, 'Objet ' . $r, 'Corps ' . $r, 'banc');
            app(ServiceMessagesTypes::class)->valider($ws, $m['id'], 1);
            $sequence[] = ['etape' => $r, 'message_id' => $m['id'], 'delai_jours' => $r === 1 ? 0 : $delaiJours];
        }
        config(['crm.ingest.business_workspace' => (string) DB::table('workspaces')->where('id', $ws)->value('slug')]);   // la vérification CRM ne porte que sur l'espace « business »
        ResolveurDnsSimule::toutVerifier();   // après les contacts : verdicts « valide » (jamais de DNS réel)
        $depot = app(DepotCampagnes::class);
        $id = $depot->creer($ws, 'Campagne banc', ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Liste banc'], [], $etapes, ['contenu' => ['sequence' => $sequence], 'etapes_faites' => []], 'banc');
        $depot->transitionner($ws, $id, 'a_valider', 1, 'banc', 'banc', 'cle-banc-' . Str::random(12));
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'programmee', 'version' => 3]);
        DB::table('envoi_campagnes_journal')->insert(['workspace_id' => $ws, 'campagne_id' => $id, 'transition' => 'a_valider>programmee', 'auteur' => 'banc', 'motif' => 'banc', 'cle_idempotence' => 'cle-j-' . Str::random(12), 'cree_le' => DB::raw("now() - interval '10 minutes'")]);
        MaterialisationCampagne::$niveauTolere = DB::transactionLevel();
        try {
            app(MaterialisationCampagne::class)->materialiser($ws, $id, self::jour('07:00'));
        } finally {
            MaterialisationCampagne::$niveauTolere = 0;
        }

        return $id;
    }

    public function lancer(string $campagne): object
    {
        return app(GestesCampagne::class)->lancer($this->ws, $campagne, 'banc', null);
    }

    /** Un tour de séquenceur à l'instant $t. Le faux serveur est construit APRÈS l'ouverture de la transaction du test. */
    public function tic(DateTimeImmutable $t): array
    {
        $this->serveur ??= new FauxServeurEnvoi;
        $horloge = $t;
        $passerelle = new PasserelleLocale($this->enregistreur(), new DepotSocleDb, fn (): bool => true, 50, function () use (&$horloge): DateTimeImmutable {
            return $horloge;
        }, '', 300, fn (): ?string => WorkspaceContext::current());
        $identite = app(DepotReglages::class)->identite($this->ws);
        $this->sequenceur = new Sequenceur(
            $passerelle,
            new LecteurFicheDb,
            new ComposeurSequenceMessage(new ComposeurMessage(self::DOMAINE, null, SignatureDkim::Serveur), $identite),
            new ParametresExpedition(self::DOMAINE, 'contact@' . self::DOMAINE, 'Banc Expediteur SAS'),
        );
        $jour = $t->format('c');

        return WorkspaceContext::run($this->ws, function () use ($jour, $t): array {
            // Les contrôles DNS valent « ce jour » : le banc les date de chaque jour fictif (aucune requête DNS).
            DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour]);

            return $this->sequenceur->executer($this->ws, $t);
        });
    }

    /** Décorateur du faux serveur : garde les messages injectés (le faux n'en garde pas le corps). */
    private function enregistreur(): ServeurEnvoi
    {
        $faux = $this->serveur;
        $banc = $this;

        return new class($faux, $banc) implements RenvoiParMemeCle, ServeurEnvoi
        {
            public function __construct(private readonly FauxServeurEnvoi $faux, private readonly BancCampagne $banc) {}

            public function injecter(MessageAEnvoyer $message): ResultatInjection
            {
                $r = $this->faux->injecter($message);
                $this->banc->injectes[] = $message;

                return $r;
            }

            public function etatEnvoi(string $idInjection): EtatMessage
            {
                return $this->faux->etatEnvoi($idInjection);
            }

            public function pause(): void
            {
                $this->faux->pause();
            }

            public function reprise(): void
            {
                $this->faux->reprise();
            }

            public function modeTemoins(): bool
            {
                return $this->faux->modeTemoins();
            }

            public function etat(): EtatServeur
            {
                return $this->faux->etat();
            }

            public function sante(): SanteServeur
            {
                return $this->faux->sante();
            }

            public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
            {
                return $this->faux->lireEvenements($curseur, $limite);
            }
        };
    }

    /** Relève réelle (chaîne de récepteurs du conteneur, registre réel) sur une boîte en mémoire. */
    public function releve(MessageBrut ...$messages): void
    {
        $this->lecteur ??= new LecteurBoiteMemoire;
        $this->lecteur->definirUidValidity($this->boite, 1);
        foreach ($messages as $m) {
            $this->lecteur->deposer($this->boite, $m);
        }
        app()->instance(FabriqueLecteurs::class, new FabriqueLecteurFixe($this->lecteur));
        $releve = app(ReleveImap::class);   // chaîne et registre RÉELS du conteneur
        $releve->relever($this->ws, $this->boite);
    }

    /** Remet le curseur de la boîte à zéro : la prochaine relève relit les mêmes messages (mêmes clés boîte/UIDVALIDITY/UID). */
    public function reinitialiserCurseur(): void
    {
        DB::table('envoi_curseurs_imap')->where('workspace_id', $this->ws)->where('boite', $this->boite)->update(['dernier_uid' => 0]);
    }

    /** Inscription de l'entreprise de rang $rang (« Societe banc <rang> »). */
    public function inscriptionDe(int $rang): object
    {
        return DB::table('envoi_inscriptions as i')->join('companies as c', 'c.id', '=', 'i.entreprise_id')->where('i.workspace_id', $this->ws)
            ->where('c.workspace_id', $this->ws)->where('c.denomination', 'Societe banc ' . $rang)->first(['i.*']);
    }

    /** Adresse du contact réellement inscrit (même empreinte que l'inscription). */
    public function adresseInscrite(object $inscription): string
    {
        foreach (DB::table('contacts')->where('workspace_id', $this->ws)->where('company_id', $inscription->entreprise_id)->orderBy('id')->pluck('email') as $email) {
            if (ListeSuppression::empreinte((string) $email) === $inscription->empreinte_adresse) {
                return (string) $email;
            }
        }
        throw new \LogicException('Aucun contact de l\'entreprise ne porte l\'empreinte de l\'inscription.');
    }

    /** Identifiant d'injection du registre (`envoi_messages_envoyes`) d'une étape : c'est lui que porte un VERP. */
    public function idInjectionRegistre(string $inscriptionId, int $etape = 1): string
    {
        return (string) DB::table('envoi_messages_envoyes')->where('workspace_id', $this->ws)
            ->where('message_id_empreinte', hash('sha256', 'mid:' . $this->messageId($inscriptionId, $etape)))->value('id_injection');
    }

    public function messagesEnvoyes(?int $etape = null): Collection
    {
        return DB::table('envoi_messages')->where('workspace_id', $this->ws)->where('statut', 'envoye')->when($etape !== null, fn ($q) => $q->where('etape', $etape))->orderBy('id')->get();
    }

    /** Message-ID (chevrons ôtés) de la première étape d'une inscription. */
    public function messageId(string $inscriptionId, int $etape = 1): string
    {
        return trim((string) DB::table('envoi_messages')->where('workspace_id', $this->ws)->where('inscription_id', $inscriptionId)->where('etape', $etape)->where('statut', 'envoye')->value('message_id'), " \t\r\n<>");
    }

    // --- fabriques de messages reçus (VERP signé, comme RebondsPlaintesK11Test) -------------------------------------------------

    public function reponse(string $messageId, string $de = 'prospect@societe.example.test'): MessageBrut
    {
        return new MessageBrut(++$this->uid, 'rep-' . $this->uid . '@mx.example.test', $messageId, [], $de, self::jour('11:00'), 'Re: Objet 1', 'Merci, je regarde cela.', [], null, null, null);
    }

    public function stop(string $messageId, string $de = 'prospect@societe.example.test'): MessageBrut
    {
        return new MessageBrut(++$this->uid, 'stop-' . $this->uid . '@mx.example.test', $messageId, [], $de, self::jour('11:00'), 'Re: Objet 1', 'STOP, ne me contactez plus.', [], null, null, null);
    }

    /** Rebond dur : rattaché par le Message-ID cité ET/OU par un VERP signé (`$verp`, de préférence seul : `$messageId` null). */
    public function rebondDur(?string $messageId, string $adresse, ?string $verp = null): MessageBrut
    {
        return new MessageBrut(++$this->uid, 'rb-' . $this->uid . '@mx.example.test', null, [], 'MAILER-DAEMON@retour.example.test', self::jour('11:00'), 'Undelivered', 'Adresse inconnue.', [], '5.1.1', $messageId, $adresse, adressesLocales: $verp === null ? [] : [$verp]);
    }

    public function rebondMou(string $messageId, string $adresse): MessageBrut
    {
        return new MessageBrut(++$this->uid, 'rm-' . $this->uid . '@mx.example.test', null, [], 'MAILER-DAEMON@retour.example.test', self::jour('11:00'), 'Delayed', 'Boite pleine.', [], '4.2.2', $messageId, $adresse);
    }

    public function arf(string $verp, string $type = 'abuse'): MessageBrut
    {
        return new MessageBrut(++$this->uid, 'arf-' . $this->uid . '@mx.example.test', null, [], 'abuse@fai.example.test', self::jour('11:00'), 'Plainte ' . $type, 'Rapport de plainte.', ['X-Feedback-Type' => $type], null, null, $verp);
    }

    public static function verp(string $idInjection): string
    {
        return Verp::adressePour($idInjection, self::DOMAINE);
    }

    /** Exécute $f sous le rôle applicatif (RLS) ou sous le propriétaire (seul le filtre `workspace_id` protège). */
    public static function sous(string $mode, Closure $f): mixed
    {
        if ($mode === 'proprio') {
            return $f();
        }
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        try {
            return $f();
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    /** Pose l'espionne de flux http(s) (modèle EssaiABlancSansReseauTest) : à appeler en `beforeEach`. */
    public static function espionnerFlux(): void
    {
        EspionneFluxBanc::$ouvertures = 0;
        foreach (['http', 'https'] as $schema) {
            @stream_wrapper_unregister($schema);
            stream_wrapper_register($schema, EspionneFluxBanc::class);
        }
    }

    /** Rend les gardes de flux d'origine : à appeler en `afterEach`. */
    public static function libererFlux(): void
    {
        foreach (['http', 'https'] as $schema) {
            @stream_wrapper_restore($schema);
        }
    }
}

/** Espionne de flux : toute ouverture est comptée, puis refusée. */
final class EspionneFluxBanc
{
    public static int $ouvertures = 0;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        self::$ouvertures++;

        return false;
    }

    /** @return array<string, mixed>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        self::$ouvertures++;

        return false;
    }
}
