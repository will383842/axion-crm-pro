<?php

namespace App\Socle\Alertes;

use Illuminate\Support\Facades\Log;

/**
 * Alerte à deux canaux : la cloche (console) et l'e-mail. Rien d'autre :
 * ni téléphone, ni SMS, ni messagerie tierce.
 *
 * Règles héritées de l'ancien canal et conservées :
 *  - elle ne casse JAMAIS son appelant ;
 *  - elle écrit TOUJOURS dans le journal, drapeau ouvert ou non ;
 *  - drapeau éteint (`alertes.actif`, éteint par défaut) : journal seul, et le
 *    journal le dit une fois par processus, pour que le silence ne passe pas
 *    pour un canal qui marche.
 */
final class Alerte
{
    private static bool $absenceSignalee = false;

    public function __construct(
        private readonly CanalConsole $console,
        private readonly CanalEmail $email,
        private readonly Regroupement $regroupement,
    ) {}

    /** Remet le témoin « avertissement déjà émis » à zéro (tests). */
    public static function oublierLAvertissement(): void
    {
        self::$absenceSignalee = false;
    }

    /**
     * @param  array<string, mixed>  $contexte  joint au journal, pas au message
     * @param  bool  $test  alerte de test : jamais absorbée par le regroupement
     * @return bool vrai si au moins un canal l'a acceptée
     */
    public function envoyer(
        string $titre,
        string $corps,
        string $produit = 'crm',
        string $gravite = 'critique',
        ?string $lien = null,
        array $contexte = [],
        bool $test = false,
    ): bool {
        $titre = trim($titre);
        $corps = trim($corps);

        // Le journal d'abord, toujours : si le processus meurt pendant l'envoi, la trace existe.
        Log::critical('[ALERTE] ' . $titre, $contexte + ['corps' => $corps]);

        if (! filter_var(config('alertes.actif', false), FILTER_VALIDATE_BOOLEAN)) {
            if (! self::$absenceSignalee) {
                self::$absenceSignalee = true;
                Log::warning(
                    'Alertes console + e-mail ÉTEINTES : une alerte vient de rester dans le journal. '
                    . 'Poser ALERTES_CONSOLE_EMAIL_ACTIVES=true et ALERTES_EMAIL_DESTINATAIRE, '
                    . 'puis déployer (un restart ne relit pas l’environnement).',
                );
            }

            return false;
        }

        try {
            if (! $test && $this->regroupement->dejaVue($titre, $produit, $gravite)) {
                return false;
            }

            $cloche = $this->console->ecrire($titre, $corps, $produit, $gravite, $lien, $contexte);
            $courrier = $this->email->envoyer($titre, $corps, $lien);

            return $cloche || $courrier;
        } catch (\Throwable $e) {
            Log::error('Alerte : échec d’envoi.', ['exception' => $e::class]);

            return false;
        }
    }
}
