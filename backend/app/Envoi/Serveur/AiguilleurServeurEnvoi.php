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
use Throwable;

/**
 * Aiguilleur par domaine (D11) : serveur interne de `PasserelleLocale`. Choisit l'adaptateur selon le domaine de l'expéditeur
 * (`envoi_domaines.adaptateur` : faux, kumo, smtp). Un adaptateur RÉEL n'est atteint que si LES TROIS conditions valent :
 *   1. `PorteEnvoiReelle::ouverte()` ;
 *   2. dernière décision de feu vert = « accorde » ET aucun point bloquant rouge MAINTENANT (hors `auto.reel_actif_faux`) ;
 *   3. dernier contrôle DNS du jour (Europe/Paris) du domaine : `pret = true` et `pause = false`, lu en base, jamais résolu.
 * Sinon : `EnvoiRefuse` de code ATTENTE (report sans compteur), JAMAIS de repli silencieux sur le faux serveur.
 * N'implémente PAS `RenvoiParMemeCle` : un rejeu de clé `reservee` ou `a_verifier` n'est jamais renvoyé, quel que soit l'adaptateur.
 * Les adaptateurs réels sont construits à la demande par des fabriques (jamais au chargement) : aucune connexion réseau ici.
 */
final class AiguilleurServeurEnvoi implements ServeurEnvoi
{
    /** @var array<string, ServeurEnvoi> */
    private array $construits = [];

    /**
     * @param  array<string, Closure(): ServeurEnvoi>  $fabriques  adaptateurs réels (`kumo`, `smtp`), appelés seulement quand les trois conditions valent
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

        return $this->adaptateur($adaptateur)->injecter($message);
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        $a = $this->actifs()[0] ?? null;

        return $a === null ? $this->faux->etatEnvoi($idInjection) : $this->adaptateur($a)->etatEnvoi($idInjection);
    }

    /** Sens sûr : transmise à tout adaptateur réel qui porte au moins un domaine, sans feu vert ni DNS ; lue par la fonction (RLS). */
    public function pause(): void
    {
        $this->faux->pause();
        $premiere = null;
        foreach ($this->portes() as $nom) {
            try {
                $this->adaptateur($nom)->pause();
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
        $actifs = $this->actifs();
        if ($actifs === []) {
            $this->faux->reprise();

            return;
        }
        foreach ($actifs as $nom) {
            $this->adaptateur($nom)->reprise();
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
        foreach ($actifs as $nom) {
            $e = $this->adaptateur($nom)->etat();
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
        foreach ($actifs as $nom) {
            $s = $this->adaptateur($nom)->sante();
            $ok = $ok && $s->ok;
            $details[] = $nom . ' : ' . $s->detail;
        }

        return new SanteServeur($ok, implode(' ; ', $details));
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        $a = $this->actifs()[0] ?? null;

        return $a === null ? $this->faux->lireEvenements($curseur, $limite) : $this->adaptateur($a)->lireEvenements($curseur, $limite);
    }

    // ── lectures ─────────────────────────────────────────────────────────────

    private function adaptateur(string $nom): ServeurEnvoi
    {
        if (! isset($this->fabriques[$nom])) {
            throw EnvoiRefuse::conditionNonRemplie('adaptateur_indisponible');
        }

        return $this->construits[$nom] ??= ($this->fabriques[$nom])();
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
     * Adaptateurs réels dont les trois conditions valent pour AU MOINS UN domaine de l'espace courant. Hors contexte : aucun.
     *
     * @return list<string>
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
            if (! in_array($d->adaptateur, $actifs, true) && $this->dnsVertDuJour($ws, (string) $d->nom)) {
                $actifs[] = (string) $d->adaptateur;
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
