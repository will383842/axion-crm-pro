<?php

namespace App\Envoi\Serveur;

use App\Envoi\Chauffe\Fournisseur;
use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\JourEnvoi;
use App\Support\WorkspaceContext;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aiguilleur par domaine (D11) : serveur interne de `PasserelleLocale`. Choisit l'adaptateur selon le domaine de l'expéditeur
 * (`envoi_domaines.adaptateur` : faux, kumo, smtp). Un adaptateur RÉEL n'est atteint que si LES TROIS conditions valent :
 *   1. `PorteEnvoiReelle::ouverte()` ;
 *   2. dernière décision de feu vert = « accorde » ET aucun point bloquant rouge MAINTENANT (hors `auto.reel_actif_faux`) ;
 *   3. dernier contrôle DNS du jour (Europe/Paris) du domaine : `pret = true` et `pause = false`, lu en base, jamais résolu.
 * Sinon : `EnvoiRefuse` de code ATTENTE (report sans compteur), JAMAIS de repli silencieux sur le faux serveur.
 * N'implémente PAS `RenvoiParMemeCle` : un rejeu de clé `reservee` ou `a_verifier` n'est jamais renvoyé, quel que soit l'adaptateur.
 * Les adaptateurs réels sont construits à la demande par des fabriques `(espace, domaine)` (jamais au chargement, JAMAIS mis en cache :
 * l'aiguilleur vit dans un singleton, un worker de longue durée servirait sinon l'adaptateur de l'espace A à l'espace B ; l'état vit
 * dans le registre). Une fabrique n'ouvre AUCUNE connexion à la construction : aucune connexion réseau ici.
 */
final class AiguilleurServeurEnvoi implements ServeurEnvoi
{
    /**
     * @param  array<string, Closure(string $workspaceId, string $domaine): ServeurEnvoi>  $fabriques  adaptateurs réels (`kumo`, `smtp`), appelés seulement quand les trois conditions valent (sauf `pause()`)
     * @param  Closure(): bool  $porte  `PorteEnvoiReelle::ouverte()`
     * @param  Closure(): ListeFeuVert  $liste  liste des points du feu vert (lue à chaque appel)
     * @param  (Closure(): list<string>)|null  $adaptateursPortes  lecteur des adaptateurs réels portés (défaut : `envoi_adaptateurs_reels_portes()`)
     */
    public function __construct(
        private readonly ServeurEnvoi $faux,
        private readonly array $fabriques,
        private readonly Closure $porte,
        private readonly Closure $liste,
        private readonly ?Closure $maintenant = null,
        private readonly ?Closure $adaptateursPortes = null,
    ) {}

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        $ws = $message->workspaceId;
        $domaine = Fournisseur::domaine($message->expediteur);
        $adaptateur = $this->adaptateurDuDomaine($ws, $domaine);
        if ($adaptateur === null) {
            throw EnvoiRefuse::conditionNonRemplie('domaine_inconnu');
        }
        $ouverte = ($this->porte)();
        if ($adaptateur === 'faux') {
            if ($ouverte) {
                throw EnvoiRefuse::conditionNonRemplie('domaine_sur_faux_serveur');
            }

            return $this->faux->injecter($message);
        }
        if (! $ouverte) {
            throw EnvoiRefuse::conditionNonRemplie('envoi_reel_inactif');
        }
        if (! $this->feuVertAccorde($ws)) {
            throw EnvoiRefuse::conditionNonRemplie('feu_vert_non_accorde');
        }
        if (! $this->dnsVertDuJour($ws, $domaine)) {
            throw EnvoiRefuse::conditionNonRemplie('dns_non_vert');
        }

        return $this->adaptateur($adaptateur, $ws, $domaine)->injecter($message);
    }

    /**
     * L'adaptateur qui a pris l'injection est résolu à partir de l'identifiant RENDU par `injecter` (contrat du kit de conformité),
     * dans le contexte d'espace (RLS), jamais deviné par `actifs()[0]` : `envoi_injections_locales` d'abord (faux, kumo, et smtp si
     * une ligne y existe), PUIS `envoi_injections_smtp` (l'identifiant rendu par l'adaptateur smtp est l'UUID de cette table).
     * Kumo : le registre durable écrira (plus tard, PAS encore en production) `envoi_injections_locales(id_injection = id Kumo,
     * adaptateur = 'kumo')` avant le réseau ; ce chemin est déjà accepté ici. Sans ligne (faux serveur : « sim-N », non UUID) : le faux
     * serveur répond s'il est le seul actif (aucun adaptateur réel autorisé), sinon `Inconnu`. Un adaptateur réel n'est interrogé que si
     * les trois conditions valent encore. Le domaine passé à la fabrique est celui de la boîte pour smtp quand il est connu ; sinon le
     * premier domaine vert de l'adaptateur (limite : `envoi_injections_locales` ne porte pas le domaine).
     */
    public function etatEnvoi(string $idInjection): EtatMessage
    {
        $ws = WorkspaceContext::current();
        if ($ws === null) {
            return $this->faux->etatEnvoi($idInjection);
        }
        $trouve = Str::isUuid($idInjection) ? $this->adaptateurDeLInjection($ws, $idInjection) : null;
        $actifs = $this->actifs();
        if ($trouve === null) {
            return $actifs === [] ? $this->faux->etatEnvoi($idInjection) : EtatMessage::Inconnu;
        }
        [$adaptateur, $domaineConnu] = $trouve;
        if ($adaptateur === 'faux') {
            return $this->faux->etatEnvoi($idInjection);
        }
        $domaine = $domaineConnu ?? ($actifs[$adaptateur] ?? null);

        return isset($actifs[$adaptateur]) && $domaine !== null ? $this->adaptateur($adaptateur, $ws, $domaine)->etatEnvoi($idInjection) : EtatMessage::Inconnu;
    }

    /** @return array{0: string, 1: ?string}|null [adaptateur, domaine connu de l'injection] ; lectures sous le contexte et la RLS de l'espace */
    private function adaptateurDeLInjection(string $ws, string $id): ?array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $id): ?array {
            $l = DB::selectOne('SELECT adaptateur FROM envoi_injections_locales WHERE workspace_id = ? AND id_injection = ?', [$ws, $id]);
            if ($l !== null) {
                return [(string) $l->adaptateur, null];
            }
            $s = DB::selectOne('SELECT b.adresse FROM envoi_injections_smtp i JOIN envoi_boites b ON b.id = i.boite_id WHERE i.workspace_id = ? AND i.id_injection = ?', [$ws, $id]);
            if ($s === null) {
                return null;
            }
            $domaine = strtolower(substr((string) strrchr((string) $s->adresse, '@'), 1));

            return ['smtp', $domaine === '' ? null : $domaine];
        });
    }

    /**
     * Sens sûr (comportement conservé) : transmise à tout adaptateur réel qui porte au moins un domaine, MÊME porte fermée, sans feu
     * vert ni DNS ; lue par la fonction (RLS). Sans contexte d'espace, les fabriques reçoivent l'espace courant ou '' et le domaine '' :
     * l'adaptateur construit ne doit pas dépendre de l'espace pour `pause()`.
     */
    public function pause(): void
    {
        // TODO M1 (ESCALADE, non implémenté ici) : pause globale tous espaces avant de lier une fabrique réelle. Aujourd'hui les
        // fabriques sont appelées avec l'espace courant ou '' et le domaine '' : à trancher avant toute liaison réelle.
        $this->faux->pause();
        $premiere = null;
        foreach ($this->portes() as $nom) {
            try {
                $this->adaptateur($nom, WorkspaceContext::current() ?? '', '')->pause();
            } catch (Throwable $e) {
                $premiere ??= $e; // le stop gagne : les autres adaptateurs sont tout de même interrogés
            }
        }
        if ($premiere !== null) {
            throw $premiere;
        }
    }

    public function reprise(): void
    {
        $ws = (string) WorkspaceContext::current();
        $actifs = $this->actifs();
        if ($actifs === []) {
            $this->faux->reprise();

            return;
        }
        foreach ($actifs as $nom => $domaine) {
            $this->adaptateur($nom, $ws, $domaine)->reprise();
        }
    }

    public function modeTemoins(): bool
    {
        return true; // décidé par la passerelle (drapeau en base), jamais par l'adaptateur
    }

    public function etat(): EtatServeur
    {
        $actifs = $this->actifs();
        if ($actifs === []) {
            return $this->faux->etat();
        }
        $pause = false;
        $injectes = 0;
        $ws = (string) WorkspaceContext::current();
        foreach ($actifs as $nom => $domaine) {
            $e = $this->adaptateur($nom, $ws, $domaine)->etat();
            $pause = $pause || $e->enPause;
            $injectes += $e->injectes;
        }

        return new EtatServeur($pause, $injectes);
    }

    /** Hors contexte ou sans adaptateur autorisé : `ok = false`, aucun adaptateur interrogé (la passerelle ne note alors aucun battement). */
    public function sante(): SanteServeur
    {
        $actifs = $this->actifs();
        if ($actifs === []) {
            return new SanteServeur(false, 'aucun adaptateur réel');
        }
        $ok = true;
        $details = [];
        $ws = (string) WorkspaceContext::current();
        foreach ($actifs as $nom => $domaine) {
            $s = $this->adaptateur($nom, $ws, $domaine)->sante();
            $ok = $ok && $s->ok;
            $details[] = $nom . ' : ' . $s->detail;
        }

        return new SanteServeur($ok, implode(' ; ', $details));
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        $actifs = $this->actifs();
        if ($actifs === []) {
            return $this->faux->lireEvenements($curseur, $limite);
        }
        $nom = array_key_first($actifs);

        return $this->adaptateur($nom, (string) WorkspaceContext::current(), $actifs[$nom])->lireEvenements($curseur, $limite);
    }

    // ── lectures ─────────────────────────────────────────────────────────────

    /** Construit à chaque appel, pour l'espace et le domaine donnés : aucun cache (voir l'en-tête de classe). */
    private function adaptateur(string $nom, string $workspaceId, string $domaine): ServeurEnvoi
    {
        if (! isset($this->fabriques[$nom])) {
            throw EnvoiRefuse::conditionNonRemplie('adaptateur_indisponible');
        }

        return ($this->fabriques[$nom])($workspaceId, $domaine);
    }

    /** @return list<string> adaptateurs réels portés par au moins un domaine (toutes lectures par la fonction, sans contexte d'espace) */
    private function portes(): array
    {
        if ($this->adaptateursPortes !== null) {
            return ($this->adaptateursPortes)();
        }

        return array_values(array_map(static fn ($l): string => (string) $l->nom, DB::select('SELECT envoi_adaptateurs_reels_portes() AS nom')));
    }

    private function adaptateurDuDomaine(string $ws, string $domaine): ?string
    {
        $l = WorkspaceContext::run($ws, fn () => DB::selectOne('SELECT adaptateur FROM envoi_domaines WHERE workspace_id = ? AND nom = ?', [$ws, $domaine]));

        return $l === null ? null : (string) $l->adaptateur;
    }

    /**
     * Adaptateurs réels dont les trois conditions valent pour AU MOINS UN domaine de l'espace courant (nom => premier domaine vert).
     * Hors contexte : aucun.
     *
     * @return array<string, string>
     */
    private function actifs(): array
    {
        $ws = WorkspaceContext::current();
        if ($ws === null || ! ($this->porte)() || ! $this->feuVertAccorde($ws)) {
            return [];
        }
        $domaines = WorkspaceContext::run($ws, fn () => DB::select("SELECT nom, adaptateur FROM envoi_domaines WHERE workspace_id = ? AND adaptateur <> 'faux' ORDER BY adaptateur, nom", [$ws]));
        $actifs = [];
        foreach ($domaines as $d) {
            if (! isset($actifs[(string) $d->adaptateur]) && $this->dnsVertDuJour($ws, (string) $d->nom)) {
                $actifs[(string) $d->adaptateur] = (string) $d->nom;
            }
        }

        return $actifs;
    }

    /** Dernière décision = « accorde » ET aucun point bloquant rouge maintenant, hors `auto.reel_actif_faux` (D12). Aucune empreinte comparée. */
    private function feuVertAccorde(string $ws): bool
    {
        $liste = ($this->liste)();
        $decision = $liste->derniereDecision($ws);
        if ($decision === null || $decision['decision'] !== 'accorde') {
            return false;
        }
        foreach ($liste->points($ws) as $p) {
            if ($p->bloquant && $p->code !== ListeFeuVert::POINT_EXPLOITATION) {
                return false;
            }
        }

        return true;
    }

    /** Dernière ligne du domaine pour le jour Europe/Paris : `pret = true` et `pause = false`. Lue en base, jamais résolue. */
    private function dnsVertDuJour(string $ws, string $domaine): bool
    {
        $debut = JourEnvoi::de($this->maintenant === null ? null : ($this->maintenant)());
        $fin = $debut->modify('+1 day');
        $l = WorkspaceContext::run($ws, fn () => DB::selectOne(
            'SELECT pret, pause FROM envoi_controles_dns WHERE workspace_id = ? AND domaine = ? AND controle_le >= ? AND controle_le < ? ORDER BY controle_le DESC, id DESC LIMIT 1',
            [$ws, $domaine, $debut->format(DateTimeImmutable::ATOM), $fin->format(DateTimeImmutable::ATOM)],
        ));

        return $l !== null && (bool) $l->pret && ! (bool) $l->pause;
    }
}
