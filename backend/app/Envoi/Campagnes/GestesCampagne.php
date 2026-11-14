<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gestes sur une campagne (R09) : pause, reprise, terminer, dupliquer, changer la date.
 * Aucun geste ne fait partir un message ni n'écrit `sequence_id` : seul le statut (matrice de DepotCampagnes) et la date prévue changent.
 * TOUT passe sous le verrou consultatif de l'espace (transaction + pg_advisory_xact_lock) : deux gestes qui se croisent
 * (pause contre reprise) s'exécutent l'un après l'autre, le second relit l'état laissé par le premier.
 * La reprise refuse sous frein (arrêt général, pause automatique) : un frein ne se contourne pas par la campagne.
 */
final class GestesCampagne
{
    public function __construct(
        private readonly DepotCampagnes $depot,
        private readonly DepotSocle $socle,
        private readonly DepotChauffe $chauffe,
    ) {}

    public function pause(string $ws, string $id, string $auteur, ?string $cle): \stdClass
    {
        return $this->transition($ws, $id, 'en_pause', 'pause', $auteur, $cle);
    }

    public function reprendre(string $ws, string $id, string $auteur, ?string $cle): \stdClass
    {
        return $this->transition($ws, $id, 'en_cours', 'reprise', $auteur, $cle, function () use ($ws): void {
            if ($this->socle->toutArreter($ws)) {
                throw CampagneRefusee::frein('frein_arret_general', 'Reprise impossible : l’arrêt général est armé pour cet espace.');
            }
            if ($this->chauffe->pausesActives($ws, new DateTimeImmutable('now')) !== []) {
                throw CampagneRefusee::frein('frein_pause_automatique', 'Reprise impossible : une pause automatique est active (frein de réputation ou de chauffe).');
            }
        });
    }

    /** Irréversible : « terminee » n'a aucune sortie dans la matrice (et la base la porte aussi). */
    public function terminer(string $ws, string $id, string $auteur, ?string $cle): \stdClass
    {
        return $this->transition($ws, $id, 'terminee', 'terminer', $auteur, $cle);
    }

    /**
     * Copie en BROUILLON (version 1, sans séquence, sans date) : jamais une campagne validée. Une clé rejouée rend la même copie.
     *
     * @param  array{nom: string, garder_cible: bool, garder_messages: bool}  $options
     */
    public function dupliquer(string $ws, string $id, array $options, string $auteur, ?string $cle): \stdClass
    {
        return $this->sousVerrou($ws, function () use ($ws, $id, $options, $auteur, $cle): \stdClass {
            $source = $this->depot->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
            if ($cle !== null && ($deja = $this->dejaFait($ws, 'campagne.dupliquer', $cle, $id)) !== null) {
                return $this->depot->lire($ws, (string) $deja['copie']) ?? throw CampagneRefusee::introuvable();
            }
            $cible = $options['garder_cible']
                ? (array) json_decode($source->cible, true)
                : ['type' => 'liste', 'reference' => 'a-choisir', 'libelle' => 'À choisir'];
            $saisie = $options['garder_messages'] ? (array) json_decode($source->saisie, true) : [];
            $copie = $this->depot->creer($ws, $options['nom'], $cible, self::boites($source->boites), (int) $source->nb_etapes, $saisie, $auteur);
            $this->noter($ws, 'campagne.dupliquer', $auteur, ['cle' => $cle, 'source' => $id, 'copie' => $copie]);

            return $this->depot->lire($ws, $copie) ?? throw CampagneRefusee::introuvable();
        });
    }

    /** Date du prochain envoi : campagne « programmee » seulement, strictement dans le futur et dans la fenêtre configurée. */
    public function changerDate(string $ws, string $id, DateTimeImmutable $le, string $auteur, ?string $cle): \stdClass
    {
        return $this->sousVerrou($ws, function () use ($ws, $id, $le, $auteur, $cle): \stdClass {
            $c = $this->depot->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
            if ($cle !== null && $this->dejaFait($ws, 'campagne.date', $cle, $id) !== null) {
                return $c;   // rejeu : déjà appliqué
            }
            if ($c->statut !== 'programmee') {
                throw CampagneRefusee::transition($c->statut, 'nouvelle date');
            }
            $maintenant = new DateTimeImmutable('now');
            $fenetre = (int) config('envoi.campagne_date_fenetre_jours', 90);
            if ($le <= $maintenant) {
                throw CampagneRefusee::contenu('la date doit être dans le futur');
            }
            if ($le > $maintenant->modify("+{$fenetre} days")) {
                throw CampagneRefusee::contenu("la date doit tomber dans les {$fenetre} prochains jours");
            }
            $this->depot->changerDate($ws, $id, (int) $c->version, $le);
            $this->noter($ws, 'campagne.date', $auteur, ['cle' => $cle, 'campagne' => $id, 'le' => $le->format('c')]);

            return $this->depot->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
        });
    }

    /** @param ?\Closure(): void $frein appelé AVANT la transition si la campagne est encore dans l'état de départ */
    private function transition(string $ws, string $id, string $vers, string $geste, string $auteur, ?string $cle, ?\Closure $frein = null): \stdClass
    {
        return $this->sousVerrou($ws, function () use ($ws, $id, $vers, $geste, $auteur, $cle, $frein): \stdClass {
            $c = $this->depot->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
            if ($frein !== null && DepotCampagnes::autorisee($c->statut, $vers)) {
                $frein();   // un rejeu (déjà appliqué) ne tombe pas ici : l'état de départ n'est plus le bon
            }
            $this->depot->transitionner($ws, $id, $vers, (int) $c->version, $auteur, $geste, $cle ?? "auto-{$geste}-{$id}-{$c->version}");

            return $this->depot->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $f
     * @return T
     */
    private function sousVerrou(string $ws, \Closure $f): mixed
    {
        return WorkspaceContext::run($ws, fn () => DB::transaction(function () use ($ws, $f) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi:campagnes:' . $ws]);

            return $f();
        }));
    }

    /** @param array<string, mixed> $details */
    private function noter(string $ws, string $commande, string $auteur, array $details): void
    {
        $this->socle->journaliser($ws, $commande, $auteur, $details);
    }

    /**
     * Retrouve un geste déjà journalisé sous cette clé ; une clé prise pour une AUTRE campagne est un refus.
     *
     * @return ?array<string, mixed>
     */
    private function dejaFait(string $ws, string $commande, string $cle, string $campagne): ?array
    {
        $ligne = DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', $commande)
            ->whereRaw("(details::jsonb)->>'cle' = ?", [$cle])->first(['details']);
        if ($ligne === null) {
            return null;
        }
        $d = (array) json_decode($ligne->details, true);
        if (($d['source'] ?? $d['campagne'] ?? null) !== $campagne) {
            throw CampagneRefusee::cleReutilisee();
        }

        return $d;
    }

    /** @return list<string> */
    private static function boites(string $pg): array
    {
        $interieur = trim($pg, '{}');

        return $interieur === '' ? [] : explode(',', $interieur);
    }
}
