<?php

namespace App\Envoi\Sequence;

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise;
use App\Support\ListeSuppression;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Séquenceur (envoi P0, lot L1). Pour une inscription échue :
 *   1. verrou de ligne (SELECT … FOR UPDATE) : une seule exécution à la fois par inscription ;
 *   2. arrêt par ENTREPRISE (une autre personne a répondu / s'est opposée) et par ADRESSE
 *      (STOP, opposition ou rebond dur sur une autre inscription de la même adresse) ;
 *   3. fenêtre horaire Europe/Paris, jours ouvrés français : sinon l'échéance est reportée ;
 *   4. réservation (inscription, étape) : unicité en base (ON CONFLICT DO NOTHING) ;
 *   5. fiche relue EN BASE, puis `EnvoiAutorise::verifier` ;
 *   6. composition (relance dans le MÊME fil : In-Reply-To / References) ;
 *   7. `$serveur` (la PasserelleLocale) : jamais d'appel réseau d'ici.
 * La version du message est celle FIGÉE à l'inscription. Aucune mesure d'ouverture.
 * Aucun effacement : un message refusé ou reporté reste en base avec son motif.
 */
final class Sequenceur
{
    public const ENVOYE = 'envoye';

    public const ARRETEE = 'arretee';

    public const DEJA = 'deja_traite';

    public const HORS_FENETRE = 'hors_fenetre';

    public const REFUSE = 'refuse';

    public const REPORTE = 'reporte';

    public const IGNORE = 'ignore';

    public function __construct(
        private readonly ServeurEnvoi $serveur,
        private readonly LecteurFiche $fiches,
        private readonly ComposeurSequence $composeur,
        private readonly ParametresExpedition $expedition,
    ) {}

    /**
     * Traite toutes les inscriptions échues de l'espace courant.
     *
     * @return array<string, int> compte par résultat
     */
    public function executer(string $ws, DateTimeImmutable $maintenant, int $limite = 100): array
    {
        $ids = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('statut', 'active')
            ->where('prochaine_echeance', '<=', $maintenant->format('c'))
            ->orderBy('prochaine_echeance')->limit($limite)->pluck('id')->all();
        $bilan = [];
        foreach ($ids as $id) {
            $r = $this->traiter($ws, (string) $id, $maintenant);
            $bilan[$r] = ($bilan[$r] ?? 0) + 1;
        }

        return $bilan;
    }

    public function traiter(string $ws, string $inscriptionId, DateTimeImmutable $maintenant): string
    {
        return DB::transaction(function () use ($ws, $inscriptionId, $maintenant): string {
            $ins = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $inscriptionId)->lockForUpdate()->first();
            if ($ins === null || $ins->statut !== 'active' || new DateTimeImmutable((string) $ins->prochaine_echeance) > $maintenant) {
                return self::IGNORE;
            }
            $version = json_decode((string) $ins->version_figee, true, 512, JSON_THROW_ON_ERROR);
            $rang = (int) $ins->etape_courante;
            $etape = $version['etapes'][$rang - 1] ?? null;
            if ($etape === null) {
                return $this->arreter($ins, 'etape_inconnue', $maintenant);
            }

            if ($this->entrepriseArretee($ws, (int) $ins->entreprise_id, (string) $ins->id)) {
                return $this->arreter($ins, Evenement::MOTIF_ENTREPRISE, $maintenant);
            }

            $fenetre = new FenetreHoraire((int) $version['fenetre']['debut'], (int) $version['fenetre']['fin']);
            if (! $fenetre->contient($maintenant)) {
                $this->echeance($ins, $fenetre->prochain($maintenant));

                return self::HORS_FENETRE;
            }

            // STOP, opposition, rebond dur : valent pour l'ADRESSE, pas pour une seule inscription.
            if ($this->adresseArretee($ws, (string) $ins->empreinte_adresse, (string) $ins->id)) {
                return $this->arreter($ins, Evenement::MOTIF_ADRESSE, $maintenant);
            }

            // Le fil : « References » = tout le fil dans l'ordre ; « In-Reply-To » = le message PRÉCÉDENT (pas le premier).
            $references = $ins->fil_references === null || trim((string) $ins->fil_references) === '' ? [] : explode(' ', trim((string) $ins->fil_references));
            $inReplyTo = $references === [] ? null : $references[array_key_last($references)];

            $messageId = $this->reserver($ins, $rang, $inReplyTo);
            if ($messageId === null) {
                return self::DEJA;
            }

            $fiche = $this->fiches->lire($ws, (int) $ins->contact_id);
            if ($fiche === null || ListeSuppression::empreinte($fiche->email) !== trim((string) $ins->empreinte_adresse)) {
                return $this->refuser($ins, $rang, 'fiche_introuvable_ou_adresse_changee', $maintenant);
            }
            if ($fiche->exclusion !== null) {
                return $this->refuser($ins, $rang, $fiche->exclusion, $maintenant);
            }
            $verdict = EnvoiAutorise::verifier($this->demande($ws, $fiche, $rang, (string) $ins->empreinte_adresse), $maintenant);
            if (! $verdict->autorise()) {
                $motif = (string) $verdict->refus;
                // Refus PASSAGER (DNS du jour non vérifié, verdict d'adresse périmé) : on reporte, on n'arrête pas.
                if ($this->passager($ws, $motif, $fiche->email)) {
                    return $this->reporter($ins, $rang, $motif, $fenetre, $maintenant);
                }

                return $this->refuser($ins, $rang, $motif, $maintenant);
            }

            $sujet = ($rang > 1 && stripos((string) $etape['sujet'], 're:') !== 0) ? 'Re: ' . $etape['sujet'] : (string) $etape['sujet'];
            $brut = $this->composeur->composer(new DemandeComposition(
                $ws, $fiche->email, $this->expedition->expediteur, $sujet, (string) $etape['corps'],
                $messageId, $inReplyTo, $references,
            ));

            try {
                $res = $this->serveur->injecter(new MessageAEnvoyer(
                    $ws, $fiche->email, $this->expedition->expediteur, $sujet, $brut, 'seq-' . $ins->id . '-' . $rang,
                ));
            } catch (EnvoiRefuse $e) {
                return $this->reporter($ins, $rang, $e->getMessage(), $fenetre, $maintenant);
            }
            if (! $res->accepte) {
                return $this->reporter($ins, $rang, 'reporte_par_la_passerelle', $fenetre, $maintenant);
            }

            DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)
                ->update(['statut' => 'envoye', 'refus' => null, 'id_injection' => $res->idInjection, 'maj_le' => $maintenant->format('c')]);
            $this->avancer($ins, $rang, $version, $messageId, $references, $fenetre, $maintenant);

            return self::ENVOYE;
        });
    }

    private function entrepriseArretee(string $ws, int $entrepriseId, string $inscriptionId): bool
    {
        return DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('entreprise_id', $entrepriseId)
            ->where('id', '<>', $inscriptionId)->where('statut', 'arretee')
            ->whereIn('motif_arret', [Evenement::REPONSE, Evenement::OPPOSITION, Evenement::MOTIF_ENTREPRISE])->exists();
    }

    private function adresseArretee(string $ws, string $empreinte, string $inscriptionId): bool
    {
        return DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('empreinte_adresse', $empreinte)
            ->where('id', '<>', $inscriptionId)->where('statut', 'arretee')
            ->whereIn('motif_arret', [...Evenement::VALENT_POUR_L_ADRESSE, Evenement::MOTIF_ADRESSE])->exists();
    }

    /**
     * Un refus qui peut se lever sans qu'une personne ait rien décidé : le contrôle DNS du jour n'est pas
     * fait, ou le verdict d'adresse (« valide ») est périmé/daté du futur. Un verdict « invalide », une
     * adresse jamais vérifiée, un refus de garde (RGPD, pays, segment…) sont DÉFINITIFS.
     */
    private function passager(string $ws, string $motif, string $email): bool
    {
        if ($motif === EnvoiAutorise::DOMAINE_DNS_NON_VERIFIE) {
            return true;
        }
        if ($motif !== EnvoiAutorise::VERDICT_ABSENT) {
            return false;
        }
        $dernier = DB::table('envoi_verdicts_adresse')->where('workspace_id', $ws)
            ->where('empreinte_adresse', ListeSuppression::empreinte(mb_strtolower(trim($email))))
            ->orderByDesc('verifie_le')->value('verdict');

        return $dernier === 'valide';
    }

    /** Réserve (inscription, étape). null = déjà réservé/envoyé/refusé par une autre exécution. */
    private function reserver(object $ins, int $rang, ?string $inReplyTo): ?string
    {
        $messageId = '<' . Str::uuid() . '@' . $this->expedition->domaine . '>';
        $lignes = DB::table('envoi_messages')->insertOrIgnore([
            'workspace_id' => $ins->workspace_id, 'inscription_id' => $ins->id, 'etape' => $rang,
            'statut' => 'reserve', 'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
        ]);
        if ($lignes === 1) {
            return $messageId;
        }
        // Une ligne « reporte » (passerelle pleine) peut être reprise ; toute autre est définitive.
        $repris = DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->where('statut', 'reporte')
            ->update(['statut' => 'reserve', 'message_id' => $messageId, 'in_reply_to' => $inReplyTo, 'maj_le' => now()]);

        return $repris === 1 ? $messageId : null;
    }

    private function demande(string $ws, Fiche $f, int $rang, string $empreinte): DemandeEnvoi
    {
        $dejaEnvoye = DB::table('envoi_messages as m')->join('envoi_inscriptions as i', 'i.id', '=', 'm.inscription_id')
            ->where('m.workspace_id', $ws)->where('i.empreinte_adresse', $empreinte)->where('m.statut', 'envoye')->exists();

        return new DemandeEnvoi(
            $ws, $f->email, $f->occurrences, $f->pays, $f->siren, $this->expedition->domaine, $this->expedition->entiteLegale,
            $f->collecteeLe, $f->premiereInfoLe, $f->segment, $rang === 1 && ! $dejaEnvoye,
        );
    }

    private function refuser(object $ins, int $rang, string $motif, DateTimeImmutable $maintenant): string
    {
        DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)
            ->update(['statut' => 'refuse', 'refus' => $motif, 'maj_le' => $maintenant->format('c')]);
        $this->arreter($ins, 'garde:' . $motif, $maintenant);

        return self::REFUSE;
    }

    private function reporter(object $ins, int $rang, string $motif, FenetreHoraire $fenetre, DateTimeImmutable $maintenant): string
    {
        DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)
            ->update(['statut' => 'reporte', 'refus' => $motif, 'maj_le' => $maintenant->format('c')]);
        $this->echeance($ins, $fenetre->echeance($maintenant, 1));

        return self::REPORTE;
    }

    private function arreter(object $ins, string $motif, DateTimeImmutable $maintenant): string
    {
        DB::table('envoi_inscriptions')->where('id', $ins->id)->update([
            'statut' => 'arretee', 'motif_arret' => $motif, 'arretee_le' => $maintenant->format('c'),
        ]);

        return self::ARRETEE;
    }

    private function echeance(object $ins, DateTimeImmutable $quand): void
    {
        DB::table('envoi_inscriptions')->where('id', $ins->id)->update(['prochaine_echeance' => $quand->format('c')]);
    }

    /**
     * @param  array<string, mixed>  $version
     * @param  list<string>  $references
     */
    private function avancer(object $ins, int $rang, array $version, string $messageId, array $references, FenetreHoraire $fenetre, DateTimeImmutable $maintenant): void
    {
        $fil = $ins->fil_message_id ?? $messageId;
        $refs = trim(implode(' ', [...$references, $messageId]));
        $maj = ['fil_message_id' => $fil, 'fil_references' => $refs];
        if ($rang >= count($version['etapes'])) {
            $maj += ['statut' => 'terminee'];
        } else {
            $maj += [
                'etape_courante' => $rang + 1,
                'prochaine_echeance' => $fenetre->echeance($maintenant, (int) $version['etapes'][$rang]['delai_jours_ouvres'])->format('c'),
            ];
        }
        DB::table('envoi_inscriptions')->where('id', $ins->id)->update($maj);
    }
}
