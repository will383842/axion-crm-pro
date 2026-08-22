<?php

namespace App\Services\Alertes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LE CANAL QUE QUELQU'UN LIT.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * 🔴 CE QUE CETTE CLASSE RÉPARE (audit 360, 2026-08-21)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Trois endroits du dépôt promettaient une alerte Telegram, et aucun ne
 * l'envoyait — le commentaire tenait lieu de code :
 *
 *     AnomalyDetect:66 ......  // Sprint 11 : send TelegramAlert::dispatch(...)
 *     AuditVerifyChain ......  // En prod : envoi Slack/Telegram
 *     le déploiement ........  rien
 *
 * La conséquence a été MESURÉE en production le jour même : le déploiement
 * `377febf` a détecté un 502 dès la 21ᵉ seconde et a fait échouer son job.
 * Personne ne l'a lu pendant **treize minutes**. La détection existait ; c'est
 * la lecture qui manquait. *Une alarme que personne ne reçoit n'est pas une
 * alarme.*
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LES QUATRE RÈGLES QUE CETTE CLASSE S'IMPOSE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * 1. **ELLE NE CASSE JAMAIS SON APPELANT.** Une commande nocturne ne doit pas
 *    échouer parce que Telegram est injoignable. Toute erreur réseau est
 *    attrapée, journalisée, et rendue comme un `false`.
 *
 * 2. **ELLE ÉCRIT TOUJOURS DANS LE JOURNAL, MÊME QUAND L'ENVOI RÉUSSIT.** Le
 *    journal est le canal FIABLE et collecté ; Telegram est le canal LU. Si
 *    Telegram tombe, la trace reste. C'est la leçon de `AuditVerifyChain`,
 *    dont la sortie console partait dans le vide sous le planificateur.
 *
 * 3. **NON CONFIGURÉE, ELLE LE DIT.** Se taire serait reconstruire exactement
 *    le défaut réparé ici : un canal d'alerte silencieux est indiscernable
 *    d'un canal qui marche. Sans jeton, elle journalise un avertissement qui
 *    NOMME les deux variables à poser et l'endroit où les poser.
 *
 * 4. **ELLE NE JOURNALISE JAMAIS LE JETON.** Ni en clair, ni tronqué, ni dans
 *    une URL — l'URL de l'API Telegram CONTIENT le jeton, et une exception non
 *    filtrée le recracherait dans les journaux. Les messages d'erreur ci-dessous
 *    ne citent que le code HTTP et la description rendue par Telegram.
 */
class AlerteTelegram
{
    /** Limite dure de l'API Telegram. Au-delà, elle refuse tout le message. */
    public const LIMITE_CARACTERES = 4096;

    /**
     * ⚠️ N'avertit qu'UNE FOIS par processus de l'absence de configuration.
     * Sinon une commande qui émet trente alertes noierait son propre signal
     * sous trente avertissements identiques — c'est l'idiome de
     * `MockServicesProvider::$refusSignale`, repris ici pour la même raison.
     */
    private static bool $absenceSignalee = false;

    /** Remet le témoin à zéro. Réservé aux tests : sans cela, l'ordre des tests déciderait du résultat. */
    public static function oublierLAvertissement(): void
    {
        self::$absenceSignalee = false;
    }

    /**
     * Envoie une alerte, et rend `true` UNIQUEMENT si Telegram l'a acceptée.
     *
     * @param  string  $titre  une ligne, en tête du message
     * @param  string  $corps  le détail ; ce qu'un humain doit lire à 3 h du matin
     * @param  array<string, mixed>  $contexte  joint au journal, PAS au message
     */
    public function envoyer(string $titre, string $corps, array $contexte = []): bool
    {
        $texte = trim($titre) . "\n\n" . trim($corps);

        // ── 1. LE JOURNAL D'ABORD, TOUJOURS ─────────────────────────────────
        // Avant l'envoi, jamais après : si le processus meurt pendant l'appel
        // réseau, la trace existe déjà.
        Log::critical('[ALERTE] ' . trim($titre), $contexte + ['corps' => trim($corps)]);

        $token = (string) config('alertes.telegram.token', '');
        $canal = (string) config('alertes.telegram.chat_id', '');

        if (! config('alertes.telegram.actif', true)) {
            return false;
        }

        // ── 2. NON CONFIGURÉ : ON LE DIT ────────────────────────────────────
        if ($token === '' || $canal === '') {
            if (! self::$absenceSignalee) {
                self::$absenceSignalee = true;
                Log::warning(
                    'Canal Telegram NON CONFIGURE : une alerte vient de ne partir nulle part. '
                    . 'Poser `TELEGRAM_BOT_TOKEN` et `TELEGRAM_CHAT_ID` dans '
                    . '`/opt/axion-crm-pro/.env`, puis RECREER le conteneur '
                    . '(`docker compose up -d --force-recreate --no-deps api horizon scheduler`) : '
                    . 'un `restart` ne relit pas `env_file` (constat A07-003).',
                    ['token_pose' => $token !== '', 'canal_pose' => $canal !== ''],
                );
            }

            return false;
        }

        // ── 3. LA LIMITE DE TELEGRAM ────────────────────────────────────────
        // Au-delà de 4 096 caractères, l'API refuse le message ENTIER. Une
        // alerte tronquée vaut infiniment mieux qu'une alerte perdue, et la
        // troncature se voit.
        if (mb_strlen($texte) > self::LIMITE_CARACTERES) {
            $texte = mb_substr($texte, 0, self::LIMITE_CARACTERES - 40) . "\n\n[…] message tronque";
        }

        // ── 4. L'ENVOI, QUI NE PEUT PAS FAIRE TOMBER L'APPELANT ─────────────
        try {
            $reponse = Http::timeout((int) config('alertes.telegram.delai_max', 5))
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $canal,
                    'text' => $texte,
                    // Volontairement AUCUN `parse_mode` : en Markdown ou HTML,
                    // un caractère du message (un `_` dans un nom de variable,
                    // un `<` dans une trace) fait rejeter l'envoi entier par
                    // Telegram. Une alerte qui échoue à cause de sa mise en
                    // forme est une alerte perdue au pire moment.
                    'disable_web_page_preview' => true,
                ]);

            if ($reponse->successful()) {
                return true;
            }

            // ⚠️ On ne journalise NI l'URL NI le corps de la requête : l'URL
            // contient le jeton. Seuls le code HTTP et la description rendue
            // par Telegram partent dans le journal.
            Log::error('Alerte Telegram REFUSEE par l API.', [
                'http' => $reponse->status(),
                'description' => (string) $reponse->json('description', '(aucune)'),
            ]);

            return false;
        } catch (ConnectionException $e) {
            // Réseau injoignable, DNS, délai dépassé. Le message de l'exception
            // peut contenir l'URL — donc le jeton. On ne le journalise pas.
            Log::error('Alerte Telegram INJOIGNABLE (reseau ou delai depasse).', [
                'exception' => $e::class,
            ]);

            return false;
        }
    }
}
