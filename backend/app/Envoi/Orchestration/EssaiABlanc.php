<?php

namespace App\Envoi\Orchestration;

use App\Crm\FichesProtegees;
use App\Crm\Outbound\OppositionSortante;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\EmpreinteTemoin;
use App\Envoi\Imap\AdresseParEmpreinteCrm;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\RecepteurRetours;
use App\Envoi\Imap\RecepteurRetoursEnChaine;
use App\Envoi\Imap\RecepteurRetoursSequence;
use App\Envoi\Imap\RegistreMessagesEnvoyesDb;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\RetourDetecte;
use App\Envoi\Message\ComposeurMessage;
use App\Envoi\Message\SignatureDkim;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sequence\ComposeurSequenceMessage;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\ParametresExpedition;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Services\Dedup\DeduplicationService;
use App\Socle\Alertes\Alerte;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

/**
 * Essai à blanc de bout en bout (D10). Aucune route : seule la commande `envoi:essai-a-blanc` l'appelle.
 *
 * Tout le parcours (inscriptions fictives `*.example.test`, composition, injection sur un FAUX serveur, rebond dur, STOP relu par la
 * relève, arrêt de l'étape suivante, plafond, arrêt général) se fait dans UNE transaction ANNULÉE à la fin. Seule la ligne de
 * résultat (`envoi_essais_blanc`, étapes vert/rouge, sans adresse) est écrite après l'annulation.
 *
 * Règles de fer :
 *  - la chaîne est construite ICI : jamais le `ServeurEnvoi` du conteneur, ni `RecepteurRetoursCrm` (espace commercial, cache,
 *    fichier temporaire), ni le cache, ni `Alerte` réelle (un faux exemplaire muet, un vrai provoquerait un courriel) ;
 *  - le faux serveur est construit APRÈS l'ouverture de la transaction (il refuse d'injecter à un niveau plus élevé) ;
 *  - la porte « envoi réel » n'est ouverte que pour la passerelle locale d'ici, par une fermeture : la configuration n'est jamais modifiée ;
 *  - jour fictif lointain (horloge injectée) : le verrou consultatif du vrai jour n'est jamais pris ;
 *  - aucune requête HTTP (`preventStrayRequests`).
 */
final class EssaiABlanc
{
    public const ETAPES = ['inscription', 'composition', 'injection', 'plafond', 'rebond_dur', 'stop', 'arret_general'];

    /** Année fictive de l'essai (un lundi : 2 mars 2099). */
    public const JOUR_FICTIF = 2099;

    private const PLAFOND_ESSAI = 2;

    /** @var (Closure(): FauxServeurEnvoi)|null */
    private readonly ?Closure $fabriqueServeur;

    /** @param (Closure(): FauxServeurEnvoi)|null $fabriqueServeur appelée APRÈS l'ouverture de la transaction (tests) */
    public function __construct(?Closure $fabriqueServeur = null)
    {
        $this->fabriqueServeur = $fabriqueServeur;
    }

    /** @throws InvalidArgumentException espace inconnu ou auteur contenant un « @ » (refusé AVANT tout essai) */
    public function executer(string $espace, string $auteur): RapportEssai
    {
        if (str_contains($auteur, '@')) {
            throw new InvalidArgumentException('Auteur invalide : aucune adresse (« @ ») n\'est admise.');
        }
        if (! Str::isUuid($espace) || ! DB::table('workspaces')->where('id', $espace)->whereNull('deleted_at')->exists()) {
            throw new InvalidArgumentException('Espace inconnu.');
        }
        $debut = new DateTimeImmutable('now');
        $interdisait = Http::preventingStrayRequests();
        Http::preventStrayRequests();
        $etapes = [];
        $erreur = null;
        try {
            // Tout le parcours se fait dans un espace FICTIF créé dans la transaction : les vraies inscriptions de l'espace réel ne sont
            // ni comptées, ni traitées par le séquenceur, ni composées, ni reportées. La transaction est annulée à la fin.
            DB::beginTransaction();
            try {
                $fictif = $this->creerEspaceFictif();
                WorkspaceContext::run($fictif, function () use ($fictif, &$etapes): void {
                    $this->parcours($fictif, $etapes);
                });
            } catch (Throwable $e) {
                $erreur = $e;
            } finally {
                DB::rollBack();
            }
        } finally {
            Http::preventStrayRequests($interdisait);
        }
        // Une exception non rattrapée par `essayer()` : les étapes non atteintes sont rouges (jamais de valeur ni d'adresse dans la ligne).
        $rapport = new RapportEssai(array_replace(array_fill_keys(self::ETAPES, false), $etapes), $debut, new DateTimeImmutable('now'));
        // Seule écriture durable : la ligne de résultat (sans adresse), rattachée à l'espace RÉEL demandé.
        WorkspaceContext::run($espace, fn () => DB::table('envoi_essais_blanc')->insert([
            'workspace_id' => $espace, 'auteur' => mb_substr($auteur, 0, 100), 'debut' => $debut->format('c'), 'fin' => $rapport->fin->format('c'),
            'reussi' => $erreur === null && $rapport->reussi(), 'etapes' => json_encode($rapport->pourBase(), JSON_THROW_ON_ERROR),
        ]));
        if ($erreur !== null) {
            throw $erreur;
        }

        return $rapport;
    }

    /** Espace jetable (annulé avec la transaction) : id seul, jamais lu ailleurs. */
    private function creerEspaceFictif(): string
    {
        $id = (string) Str::uuid();
        DB::table('workspaces')->insert([
            'id' => $id, 'slug' => 'essai-a-blanc-' . substr(str_replace('-', '', $id), 0, 12), 'name' => 'Essai a blanc',
            'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, bool> $etapes rempli au fil du parcours (conservé si une exception l'interrompt) */
    private function parcours(string $ws, array &$etapes): void
    {
        $serveur = $this->fabriqueServeur !== null ? ($this->fabriqueServeur)() : new FauxServeurEnvoi; // APRÈS beginTransaction
        $t = new DateTimeImmutable(self::JOUR_FICTIF . '-03-02 10:00:00', new DateTimeZone('Europe/Paris'));
        $depot = new DepotSocleDb;
        $domaine = 'essai.example.test';
        $boite = $this->poserDomaineEtBoite($ws, $domaine, $t);
        $passerelle = new PasserelleLocale($serveur, $depot, fn (): bool => true, self::PLAFOND_ESSAI, function () use (&$t): DateTimeImmutable {
            return $t;
        }, '', 300, fn (): ?string => WorkspaceContext::current());
        $identite = [
            'raison_sociale' => 'Essai Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
            'signature' => 'Equipe Essai', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
        ];
        $sequenceur = new Sequenceur(
            $passerelle,
            new LecteurFicheDb,
            new ComposeurSequenceMessage(new ComposeurMessage($domaine, null, SignatureDkim::Serveur), $identite),
            new ParametresExpedition($domaine, 'contact@' . $domaine, 'Essai Expediteur SAS'),
        );

        // Inscriptions fictives : A (rebond dur), B (STOP), C (reportée par le plafond), D (arrêt général).
        $sid = $this->poserSequence($ws);
        $inscriptions = new Inscriptions(new LecteurFicheDb);
        $adresses = [];
        $ids = [];
        foreach (['a', 'b', 'c'] as $i => $nom) {
            $adresses[$nom] = "essai-{$nom}-" . Str::lower(Str::random(6)) . '@client.example.test';
            $ids[$nom] = $inscriptions->inscrire($ws, $sid, $this->poserContact($ws, $adresses[$nom], $t, DepotSocleDb::FOURNISSEURS_TEMOINS[$i]), $t->modify('-' . (3 - $i) . ' minutes'));
        }
        $etapes['inscription'] = $this->essayer(fn (): bool => DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('statut', 'active')->count() === 3);

        // Lundi : plafond de 2 => A et B partent, C est reportée.
        $sequenceur->executer($ws, $t);
        $messages = fn (string $nom, int $rang = 1) => DB::table('envoi_messages')->where('workspace_id', $ws)->where('inscription_id', $ids[$nom])->where('etape', $rang)->where('statut', 'envoye')->first();
        $etapes['composition'] = $this->essayer(function () use ($messages, $ws): bool {
            foreach (['a', 'b'] as $nom) {
                $m = $messages($nom);
                if ($m === null || (string) $m->message_id === '' || ! DB::table('envoi_messages_envoyes')->where('workspace_id', $ws)->where('message_id_empreinte', hash('sha256', 'mid:' . trim((string) $m->message_id, " \t\r\n<>")))->exists()) {
                    return false;
                }
            }

            return true;
        });
        $etapes['injection'] = $this->essayer(fn (): bool => $messages('a') !== null && $messages('b') !== null && $serveur->appels >= 2);
        $etapes['plafond'] = $this->essayer(fn (): bool => $serveur->appels === self::PLAFOND_ESSAI && $messages('c') === null);

        // Relève (chaîne propre à l'essai) : rebond dur de A, STOP de B.
        $this->releverRetours($ws, $boite, $t, $adresses, $messages('a')?->message_id, $messages('b')?->message_id);
        $etapes['rebond_dur'] = $this->essayer(fn (): bool => ListeSuppression::estSupprimee($adresses['a']));
        $apresRetours = $serveur->appels;

        // Jeudi : l'étape 2 de A et de B (échues) n'est JAMAIS injectée ; C, reportée par le plafond, part (le plafond ne perd rien).
        $t = $t->modify('+3 days');
        $this->reverifierDomaine($ws, $domaine, $t);
        $sequenceur->executer($ws, $t);
        $etapes['stop'] = $this->essayer(fn (): bool => DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte($adresses['b']))->exists()
            && $messages('a', 2) === null && $messages('b', 2) === null && $serveur->appels === $apresRetours + 1);

        $etapes['plafond'] = $etapes['plafond'] && $this->essayer(fn (): bool => $messages('c') !== null);

        // Vendredi : arrêt général => plus aucune injection, même pour une inscription neuve.
        $t = $t->modify('+1 day');
        $this->reverifierDomaine($ws, $domaine, $t);
        $depot->armerToutArreter($ws, 'essai-a-blanc');
        $adresseD = 'essai-d-' . Str::lower(Str::random(6)) . '@client.example.test';
        $d = $inscriptions->inscrire($ws, $sid, $this->poserContact($ws, $adresseD, $t, DepotSocleDb::FOURNISSEURS_TEMOINS[3]), $t);
        $avant = $serveur->appels;
        $sequenceur->executer($ws, $t);
        $etapes['arret_general'] = $this->essayer(fn (): bool => $serveur->appels === $avant
            && ! DB::table('envoi_messages')->where('workspace_id', $ws)->where('inscription_id', $d)->where('statut', 'envoye')->exists());
    }

    private function essayer(Closure $verifie): bool
    {
        try {
            return $verifie() === true;
        } catch (Throwable) {
            return false; // une étape en échec est rouge, jamais une exception qui cacherait les autres
        }
    }

    private function poserDomaineEtBoite(string $ws, string $domaine, DateTimeImmutable $t): string
    {
        $jour = $t->format('c');
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $domaine, 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour, 'flux' => 'prospection']);
        $boite = (string) Str::uuid();
        DB::table('envoi_boites')->insert(['id' => $boite, 'workspace_id' => $ws, 'adresse' => 'contact@' . $domaine, 'hote' => 'imap.example.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'essai']);

        return $boite;
    }

    /** Les contrôles DNS valent « ce jour » : l'essai les date de chaque jour fictif (aucune requête DNS). */
    private function reverifierDomaine(string $ws, string $domaine, DateTimeImmutable $t): void
    {
        $jour = $t->format('c');
        DB::table('envoi_domaines')->where('workspace_id', $ws)->where('nom', $domaine)->update(['dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour]);
    }

    private function poserSequence(string $ws): string
    {
        $sid = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Essai à blanc', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Bonjour', 'corps' => 'Corps fictif']);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 2, 'delai_jours_ouvres' => 2, 'sujet' => 'Relance', 'corps' => 'Corps fictif de relance']);

        return $sid;
    }

    /** Une entreprise fictive (fédérations) et son contact éligible : id du contact. */
    private function poserContact(string $ws, string $adresse, DateTimeImmutable $t, string $fournisseur): int
    {
        DB::table('fonctions_espace')->updateOrInsert(['espace_id' => $ws, 'fonction' => 'segment.federations'], ['ouverte' => true, 'updated_at' => now()]);
        $slug = FichesProtegees::TAG_FEDERATIONS;
        $tag = DB::table('tags')->where('workspace_id', $ws)->where('slug', $slug)->value('id')
            ?? DB::table('tags')->insertGetId(['workspace_id' => $ws, 'slug' => $slug, 'name' => $slug, 'category' => 'custom', 'kind' => 'auto', 'rules' => '[]', 'is_locked' => false, 'created_at' => now(), 'updated_at' => now()]);
        $avant = $t->modify('-3 days')->format('c');
        $entreprise = (int) DB::table('companies')->insertGetId([
            'workspace_id' => $ws, 'denomination' => 'Essai Entreprise ' . Str::random(5), 'discovery_source' => 'insee', 'siren' => '94' . random_int(1000000, 9999999),
            'country_code' => 'FR', 'first_info_at' => $avant, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('company_tag')->insert(['company_id' => $entreprise, 'tag_id' => $tag, 'workspace_id' => $ws, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
        $contact = (int) DB::table('contacts')->insertGetId([
            'workspace_id' => $ws, 'company_id' => $entreprise, 'last_name' => 'Essai', 'email' => $adresse, 'email_status' => 'valid',
            'created_at' => $avant, 'first_info_at' => $avant,
        ]);
        // Mode témoins : il reste ALLUMÉ (seule la commande dédiée l'éteint) ; l'adresse fictive est témoin, dans la transaction annulée.
        DB::table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => EmpreinteTemoin::pourDecision($adresse), 'libelle' => 'essai', 'fournisseur' => $fournisseur]);
        DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($adresse), 'verdict' => 'valide', 'verifie_le' => $t->modify('-2 days')->format('c'), 'source' => 'essai']);

        return $contact;
    }

    /**
     * Boîte en mémoire + vraie `ReleveImap` + registre réel (Message-ID retrouvé), récepteurs PROPRES à l'essai (dans la transaction).
     *
     * @param  array<string, string>  $adresses
     */
    private function releverRetours(string $ws, string $boite, DateTimeImmutable $t, array $adresses, ?string $idA, ?string $idB): void
    {
        $lecteur = new LecteurBoiteMemoire;
        $lecteur->definirUidValidity($boite, 1);
        $dsn = new MessageBrut(1, 'retour-1@mx.example.test', null, [], 'MAILER-DAEMON@retour.example.test', $t, 'Undelivered', 'Adresse inconnue.', [], '5.1.1', (string) $idA, $adresses['a']);
        $stop = new MessageBrut(2, 'retour-2@mx.example.test', (string) $idB, [], $adresses['b'], $t, 'Re: Bonjour', 'STOP, ne me contactez plus.', [], null, null, null);
        $lecteur->deposer($boite, $dsn);
        $lecteur->deposer($boite, $stop);

        $chaine = new RecepteurRetoursEnChaine($this->recepteurEssai(), RecepteurRetoursSequence::parDefaut());
        // `Alerte` est finale : un exemplaire SANS canaux (jamais de courriel ni de cloche), jamais l'alerte réelle du conteneur.
        $muette = (new ReflectionClass(Alerte::class))->newInstanceWithoutConstructor();
        $releve = new ReleveImap(
            $lecteur,
            new RegistreMessagesEnvoyesDb(static fn (string $espace, string $empreinte): ?string => AdresseParEmpreinteCrm::trouver($espace, $empreinte)),
            $chaine,
            $muette,
        );
        $releve->relever($ws, $boite);
    }

    /** Applique rebond dur et opposition dans la transaction de l'essai (ni commande CRM, ni cache, ni fichier). */
    private function recepteurEssai(): RecepteurRetours
    {
        return new class implements RecepteurRetours
        {
            public function recevoir(string $workspaceId, RetourDetecte $retour): void
            {
                if ($retour->classe === ClasseRetour::RebondDur) {
                    ListeSuppression::inscrire($retour->adresse, ListeSuppression::REBOND_DUR, 'essai-a-blanc', 'business');
                    DB::table('contacts')->where('workspace_id', $workspaceId)->where('email', $retour->adresse)->update(['email_status' => 'invalid', 'updated_at' => now()]);
                }
                if ($retour->classe === ClasseRetour::Desinscription || $retour->classe === ClasseRetour::Hostile) {
                    app(DeduplicationService::class)->addOptOut($retour->adresse, null, 'campagne:essai-a-blanc', 'unsubscribe', ['business']);
                    (new OppositionSortante)->signalerAdresseSansBloquer($retour->adresse, 'business', 'retour_campagne_desinscription', 'campagne');
                }
            }
        };
    }
}
