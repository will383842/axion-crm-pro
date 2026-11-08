<?php

namespace App\Envoi\Imap;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applique les arrêts PERMANENTS en réutilisant `crm:campagne:retours` SANS le
 * modifier : désinscription, plainte (retour hostile), rebond dur, rebond mou.
 * Réponses humaines et auto-réponses : aucun arrêt permanent ici (elles sont
 * transmises aux arrêts de séquence de L1 par une autre implémentation).
 *
 * La commande ne sert que l'espace commercial : pour tout autre espace on
 * REFUSE (exception) plutôt que d'oublier une opposition en silence.
 * L'adresse n'existe que dans un fichier temporaire (0600) supprimé dans un `finally`.
 * Adresse invalide = `RetourInapplicable` (propre au message, isolée par la relève) ;
 * toute autre exception = indisponibilité, qui interrompt la relève.
 */
final class RecepteurRetoursCrm implements RecepteurRetours
{
    private const TYPES = [
        'desinscription' => 'desinscription',
        'hostile' => 'plainte',
        'rebond_dur' => 'rebond_dur',
        'rebond_mou' => 'rebond_mou',
    ];

    private const MINUTES_EN_COURS = 10;

    public function recevoir(string $workspaceId, RetourDetecte $retour): void
    {
        $type = self::TYPES[$retour->classe->value] ?? null;
        if ($type === null) {
            return;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $espace = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($espace === null || (string) $espace !== $workspaceId) {
            throw new RuntimeException('Arrêt non appliqué : la commande de retours ne sert que l\'espace commercial.');
        }

        $adresses = [$retour->adresse];
        if ($retour->adresseExpediteur !== null) {
            $adresses[] = $retour->adresseExpediteur; // opposition : restrictive, donc sûre
        }
        $lignes = '';
        foreach ($adresses as $adresse) {
            if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
                throw new RetourInapplicable('Arrêt non appliqué : adresse invalide (revue humaine).');
            }
            $lignes .= json_encode([
                'type' => $type, 'email' => $adresse, 'campagne' => 'releve-imap', 'date' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR) . "\n";
        }

        // Le compteur de rebonds mous du CRM vit en cache, hors transaction : si un récepteur suivant échoue, la
        // relève relit le message ; sans marque (clé stable du message) le rebond serait recompté.
        // Deux marques : « en cours » (10 min, posée AVANT le comptage, comme un verrou) et « appliqué » (30 jours, posée
        // APRÈS le succès). Un processus tué entre les deux ne laisse que la marque courte : elle expire et le rebond
        // est compté à la relève suivante (avant, la marque de 30 jours posée d'avance le faisait perdre pour de bon).
        $marque = $type === 'rebond_mou' ? 'envoi:rebond_mou_applique:' . $retour->cle : null;
        $enCours = $marque . ':en_cours';
        if ($marque !== null) {
            if (Cache::has($marque)) {
                return; // déjà compté
            }
            if (! Cache::add($enCours, 1, now()->addMinutes(self::MINUTES_EN_COURS))) {
                // Traitement en cours (ou interrompu il y a moins de 10 min) : NE PAS rendre la main comme si c'était fait,
                // la relève avancerait son curseur et le rebond serait perdu. Indisponibilité : la relève reprendra.
                throw new RuntimeException('Rebond mou en cours de traitement ou interrompu récemment : à reprendre.');
            }
        }

        $fichier = tempnam(sys_get_temp_dir(), 'imap-retour-');
        if ($fichier === false) {
            $marque !== null && Cache::forget($enCours);

            throw new RuntimeException('Fichier temporaire impossible.');
        }
        try {
            chmod($fichier, 0600);
            if (file_put_contents($fichier, $lignes) === false) {
                throw new RuntimeException('Écriture du fichier temporaire impossible.');
            }
            $code = Artisan::call('crm:campagne:retours', ['file' => $fichier]);
        } catch (\Throwable $e) {
            $marque !== null && Cache::forget($enCours); // rien n'a été compté : le message pourra l'être

            throw $e;
        } finally {
            if (is_file($fichier)) {
                unlink($fichier);
            }
        }
        if ($code !== 0) {
            $marque !== null && Cache::forget($enCours);

            throw new RuntimeException('Arrêt non appliqué : crm:campagne:retours a échoué.');
        }
        if ($marque !== null) {
            Cache::put($marque, 1, now()->addDays(30));   // compté : la marque définitive remplace la marque courte
            Cache::forget($enCours);
        }
    }
}
