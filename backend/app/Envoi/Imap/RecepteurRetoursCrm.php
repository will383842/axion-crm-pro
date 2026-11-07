<?php

namespace App\Envoi\Imap;

use Illuminate\Support\Facades\Artisan;
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

        $fichier = tempnam(sys_get_temp_dir(), 'imap-retour-');
        if ($fichier === false) {
            throw new RuntimeException('Fichier temporaire impossible.');
        }
        try {
            chmod($fichier, 0600);
            if (file_put_contents($fichier, $lignes) === false) {
                throw new RuntimeException('Écriture du fichier temporaire impossible.');
            }
            $code = Artisan::call('crm:campagne:retours', ['file' => $fichier]);
        } finally {
            if (is_file($fichier)) {
                unlink($fichier);
            }
        }
        if ($code !== 0) {
            throw new RuntimeException('Arrêt non appliqué : crm:campagne:retours a échoué.');
        }
    }
}
