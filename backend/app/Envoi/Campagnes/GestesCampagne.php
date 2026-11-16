<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\CampagneRefusee;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Gestes sur une campagne (R09, L5-A) : lancer, pause, reprise, terminer, dupliquer, changer la date.
 * Aucun geste ne fait partir un message ni n'écrit `sequence_id` : le statut (matrice de DepotCampagnes), la date prévue et, pour
 * « lancer » et « reprise », UN geste « reprise » de la séquence liée (écrit par l'application, jamais par un déclencheur : un oubli
 * laisse la séquence en pause = fermé). La pause de séquence reste écrite par le déclencheur de la base.
 * TOUT passe sous les verrous de DepotCampagnes::sousVerrou (espace puis campagne), puis celui de la séquence si elle existe :
 * deux gestes qui se croisent s'exécutent l'un après l'autre. lock_timeout 5 s posé AVANT les verrous (sinon l'attente est sans borne) ;
 * verrou occupé ou interblocage = refus 409 « concurrence ». Lancer et reprendre refusent sous frein (arrêt général, pause automatique)
 * et tant que la préparation (matérialisation) n'est pas terminée ; un rejeu d'une clé déjà appliquée ne contrôle rien.
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

    /** programmee -> en_cours : exige séquence, préparation terminée avec au moins un inscrit, aucun frein ; relance la séquence. */
    public function lancer(string $ws, string $id, string $auteur, ?string $cle): \stdClass
    {
        return $this->relancer($ws, $id, 'programmee', 'lancer', $auteur, $cle);
    }

    public function reprendre(string $ws, string $id, string $auteur, ?string $cle): \stdClass
    {
        return $this->relancer($ws, $id, 'en_pause', 'reprise', $auteur, $cle);
    }

    /** Étapes de « lancer » (cahier L5-A 2.3) ; « reprendre » en saute 4 et 6 (une campagne en pause a déjà tout cela). */
    private function relancer(string $ws, string $id, string $depart, string $motif, string $auteur, ?string $cle): \stdClass
    {
        return $this->sousVerrou($ws, $id, function () use ($ws, $id, $depart, $motif, $auteur, $cle): \stdClass {
            $c = $this->depot->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
            if ($cle !== null && $this->depot->rejeu($ws, $id, 'en_cours', $cle)) {
                return $c;   // rejeu : rien d'écrit, aucun contrôle (une clé rejouée après une pause rend « en_pause »)
            }
            if ($c->statut !== $depart) {
                throw CampagneRefusee::transition($c->statut, 'en_cours');
            }
            $m = DB::selectOne('SELECT etat, compteurs, motif FROM envoi_campagnes_materialisations WHERE workspace_id = ? AND campagne_id = ? FOR SHARE', [$ws, $id]);
            $compteurs = $m === null ? [] : (array) json_decode((string) $m->compteurs, true);
            if ($m?->etat === 'bloquee') {   // avant « sans séquence » : une préparation bloquée n'a jamais de séquence, le motif est plus utile
                throw CampagneRefusee::materialisationBloquee((string) $m->motif);
            }
            if ($c->sequence_id === null) {
                throw CampagneRefusee::sansSequence('en_cours', 'La campagne n\'a pas encore de séquence : elle sera préparée automatiquement ; si des boîtes ou un plafond sont choisis, la préparation attend le transport.');
            }
            $this->verrouSequence($ws, (string) $c->sequence_id);
            if ($m === null || $m->etat !== 'terminee') {
                throw CampagneRefusee::materialisationEnCours((int) ($compteurs['inscrits'] ?? 0), isset($compteurs['membres']) ? (int) $compteurs['membres'] : null);
            }
            if ($motif === 'lancer' && (int) ($compteurs['inscrits'] ?? 0) === 0) {
                throw CampagneRefusee::aucunDestinataire();   // on ne lance jamais une campagne vide
            }
            if ($this->socle->toutArreter($ws)) {
                throw CampagneRefusee::frein('frein_arret_general', 'Reprise impossible : l’arrêt général est armé pour cet espace.');
            }
            if ($this->chauffe->pausesActives($ws, new DateTimeImmutable('now')) !== []) {
                throw CampagneRefusee::frein('frein_pause_automatique', 'Reprise impossible : une pause automatique est active (frein de réputation ou de chauffe).');
            }
            if ($this->depot->transitionner($ws, $id, 'en_cours', (int) $c->version, $auteur, $motif, $cle ?? "auto-{$motif}-{$id}-{$c->version}")) {
                $this->relancerSequence($ws, (string) $c->sequence_id, 'campagne:' . $motif);
            }

            return $this->depot->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
        });
    }

    /** Écrit « reprise » sur la séquence si son dernier geste est « pause » ; un refus de la base (P0001) annule TOUT (même transaction). */
    private function relancerSequence(string $ws, string $sequence, string $auteur): void
    {
        $dernier = DB::selectOne('SELECT geste FROM envoi_sequences_gestes WHERE workspace_id = ? AND sequence_id = ? ORDER BY cree_le DESC, id DESC LIMIT 1', [$ws, $sequence]);
        if ($dernier?->geste !== 'pause') {
            return;
        }
        try {
            DB::insert('INSERT INTO envoi_sequences_gestes (workspace_id, sequence_id, geste, auteur) VALUES (?, ?, \'reprise\', ?)', [$ws, $sequence, $auteur]);
        } catch (QueryException $e) {
            throw $e->getCode() === 'P0001' ? CampagneRefusee::repriseRefuseeBase() : $e;
        }
    }

    /** Ordre fixe des verrous : espace, campagne, séquence (même clé que DepotSequences::geste). */
    private function verrouSequence(string $ws, string $sequence): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_sequence_geste|' . $ws . '|' . $sequence]);
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
        return $this->sousVerrou($ws, $id, function () use ($ws, $id, $options, $auteur, $cle): \stdClass {
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
        return $this->sousVerrou($ws, $id, function () use ($ws, $id, $le, $auteur, $cle): \stdClass {
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

    private function transition(string $ws, string $id, string $vers, string $geste, string $auteur, ?string $cle): \stdClass
    {
        return $this->sousVerrou($ws, $id, function () use ($ws, $id, $vers, $geste, $auteur, $cle): \stdClass {
            $c = $this->depot->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
            if ($c->sequence_id !== null) {
                $this->verrouSequence($ws, (string) $c->sequence_id);   // le déclencheur écrira le geste « pause » de la séquence
            }
            $this->depot->transitionner($ws, $id, $vers, (int) $c->version, $auteur, $geste, $cle ?? "auto-{$geste}-{$id}-{$c->version}");

            return $this->depot->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
        });
    }

    /**
     * Verrous de DepotCampagnes::sousVerrou (espace puis campagne) dans UNE transaction ; `lock_timeout` est posé AVANT, dans la transaction
     * englobante (posé après, l'attente des verrous serait sans borne).
     *
     * @template T
     *
     * @param  \Closure(): T  $f
     * @return T
     */
    private function sousVerrou(string $ws, string $id, \Closure $f): mixed
    {
        try {
            return DB::transaction(function () use ($ws, $id, $f) {
                DB::statement("SET LOCAL lock_timeout = '5s'");

                return $this->depot->sousVerrou($ws, $id, $f);
            });
        } catch (QueryException $e) {
            throw in_array($e->getCode(), ['55P03', '40P01', '40001'], true) ? CampagneRefusee::concurrence() : $e;
        }
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
