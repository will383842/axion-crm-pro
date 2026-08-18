<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Support\ListeSuppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook ENTRANT du service d'envoi transactionnel (ZeptoMail) — rebonds et
 * plaintes → liste de suppression.
 *
 * Étape 0, ligne 3 ter (F18). Le CRM n'ENVOIE toujours rien : ce contrôleur ne
 * fait qu'écouter ce que le service raconte des envois — pour que, le jour où
 * la voie B du cahier des charges (§9.0 : confirmations, rappels, compte rendu,
 * relance individuelle) s'ouvre, la liste de suppression soit déjà alimentée
 * et lue. Un rebond dur ou une plainte que personne n'enregistre, c'est la
 * deuxième vague qui réécrit aux adresses mortes (cf. en-tête de la migration
 * `email_suppressions`).
 *
 * Authentification : un JETON partagé, passé dans l'URL du webhook
 * (`?t=<jeton>`) — c'est la seule chose que l'interface de ZeptoMail permet de
 * régler sur un webhook, et c'est ce que Will colle dans son Mail Agent
 * (décision 8 : l'agent ne touche pas au compte). Comparaison à temps constant.
 *
 * INERTIE : sans `MAIL_WEBHOOK_TOKEN`, la route répond 503 et n'écrit rien.
 * Sans jeton ou avec un mauvais jeton : 401. Le corps est parsé de façon
 * TOLÉRANTE (le schéma exact du fournisseur peut évoluer ; on cherche les
 * événements et les destinataires où qu'ils soient), et un corps sans aucun
 * événement reconnu rend 200 avec des compteurs à zéro — un fournisseur qui
 * reçoit une erreur réessaie indéfiniment ce qui n'a rien à donner.
 *
 * Idempotence : `ListeSuppression::inscrire()` incrémente une occurrence au
 * lieu de dupliquer ; rejouer un webhook ne crée jamais deux lignes.
 */
class ZeptoMailWebhookController extends ApiController
{
    /** Événements du fournisseur → raison de suppression. */
    private const REBONDS_DURS = ['hardbounce', 'hard_bounce', 'bounce_hard', 'permanent_bounce'];

    private const REBONDS_MOUS = ['softbounce', 'soft_bounce', 'bounce_soft', 'transient_bounce'];

    private const PLAINTES = ['complaint', 'spam', 'abuse', 'spamcomplaint', 'spam_complaint'];

    private const SOURCE = 'zeptomail';

    public function store(Request $request): JsonResponse
    {
        $attendu = (string) config('mail.webhooks.zeptomail_token', '');
        if ($attendu === '') {
            // 503 et non 200 : le fournisseur garde l'événement en file et le
            // rejouera quand le jeton sera posé — rien n'est perdu, rien n'est
            // écrit.
            return response()->json([
                'error' => 'webhook_disabled',
                'message' => 'Webhook ZeptoMail désactivé (MAIL_WEBHOOK_TOKEN absent).',
            ], 503);
        }

        $recu = (string) ($request->query('t') ?? $request->header('X-Webhook-Token') ?? '');
        if ($recu === '' || ! hash_equals($attendu, $recu)) {
            Log::warning('zeptomail-webhook rejeté (jeton invalide)', ['ip' => $request->ip()]);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        // `json()->all()` rend toujours un tableau (vide si le corps n'est pas
        // du JSON) : un corps illisible se traduit par « aucun événement »,
        // donc 200 avec des compteurs à zéro — pas un 4xx que le fournisseur
        // réessaierait sans fin.
        $corps = $request->json()->all();

        $compteurs = ['hard_bounce' => 0, 'soft_bounce' => 0, 'complaint' => 0, 'ignored' => 0];

        foreach (self::evenements($corps) as [$nom, $adresse, $details]) {
            $nomNormalise = strtolower(str_replace([' ', '-'], '_', $nom));
            $meta = ['provider' => self::SOURCE, 'event' => $nom] + $details;

            if (in_array($nomNormalise, self::REBONDS_DURS, true)) {
                ListeSuppression::inscrire($adresse, ListeSuppression::REBOND_DUR, self::SOURCE, 'business', $meta);
                $compteurs['hard_bounce']++;
            } elseif (in_array($nomNormalise, self::PLAINTES, true)) {
                ListeSuppression::inscrire($adresse, ListeSuppression::PLAINTE, self::SOURCE, 'business', $meta);
                $compteurs['complaint']++;
            } elseif (in_array($nomNormalise, self::REBONDS_MOUS, true)) {
                ListeSuppression::rebondTemporaire($adresse, self::SOURCE);
                $compteurs['soft_bounce']++;
            } else {
                $compteurs['ignored']++;
            }
        }

        return response()->json(['ok' => true, 'counts' => $compteurs]);
    }

    /**
     * Extrait, où qu'ils soient dans le corps, les couples (événement, adresse).
     *
     * Forme documentée du fournisseur : `event_message[].event_data[]` porte
     * `event_name` et `details[]` (avec `bounced_recipient`, `reason`,
     * `diagnostic_message`) ; `event_message[].email_info.to[]` porte les
     * destinataires. On accepte aussi un `event_name` de premier niveau et des
     * variantes de nommage : le webhook ne doit pas devenir aveugle à la
     * première évolution de schéma.
     *
     * @param  array<string, mixed>  $corps
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private static function evenements(array $corps): array
    {
        $resultat = [];

        $messages = $corps['event_message'] ?? null;
        if (! is_array($messages) || $messages === []) {
            $messages = [$corps];
        }

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $destinatairesParDefaut = self::destinataires($message);
            $donnees = $message['event_data'] ?? null;
            if (! is_array($donnees) || $donnees === []) {
                $donnees = [$message];
            }

            foreach ($donnees as $donnee) {
                if (! is_array($donnee)) {
                    continue;
                }
                $nom = self::nomEvenement($donnee) ?? self::nomEvenement($message) ?? self::nomEvenement($corps);
                if ($nom === null) {
                    continue;
                }

                $details = $donnee['details'] ?? null;
                $lignes = is_array($details) && $details !== [] ? $details : [$donnee];
                $trouve = false;
                foreach ($lignes as $ligne) {
                    if (! is_array($ligne)) {
                        continue;
                    }
                    $adresse = self::adresse($ligne);
                    if ($adresse === null) {
                        continue;
                    }
                    $trouve = true;
                    $resultat[] = [$nom, $adresse, self::details($ligne)];
                }
                if (! $trouve) {
                    foreach ($destinatairesParDefaut as $adresse) {
                        $resultat[] = [$nom, $adresse, []];
                    }
                }
            }
        }

        return $resultat;
    }

    /** @param array<string, mixed> $noeud */
    private static function nomEvenement(array $noeud): ?string
    {
        $nom = $noeud['event_name'] ?? $noeud['event'] ?? $noeud['type'] ?? null;
        if (is_array($nom)) {
            $nom = $nom[0] ?? null;
        }

        return is_string($nom) && $nom !== '' ? $nom : null;
    }

    /** @param array<string, mixed> $ligne */
    private static function adresse(array $ligne): ?string
    {
        foreach (['bounced_recipient', 'recipient', 'email', 'email_address', 'address'] as $cle) {
            $valeur = $ligne[$cle] ?? null;
            if (is_array($valeur)) {
                $valeur = $valeur['address'] ?? $valeur['email'] ?? null;
            }
            if (is_string($valeur) && filter_var($valeur, FILTER_VALIDATE_EMAIL) !== false) {
                return $valeur;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    private static function destinataires(array $message): array
    {
        $info = $message['email_info'] ?? null;
        $to = is_array($info) ? ($info['to'] ?? null) : null;
        if (! is_array($to)) {
            return [];
        }
        $adresses = [];
        foreach ($to as $entree) {
            if (! is_array($entree)) {
                continue;
            }
            $adresse = self::adresse($entree);
            if ($adresse === null && isset($entree['email_address']) && is_array($entree['email_address'])) {
                $adresse = self::adresse($entree['email_address']);
            }
            if ($adresse !== null) {
                $adresses[] = $adresse;
            }
        }

        return $adresses;
    }

    /**
     * Ne garde des détails que ce qui sert au diagnostic — jamais le contenu
     * du message, jamais une adresse en clair (la ligne de suppression n'en
     * porte pas non plus).
     *
     * @param  array<string, mixed>  $ligne
     * @return array<string, mixed>
     */
    private static function details(array $ligne): array
    {
        $garde = [];
        foreach (['reason', 'diagnostic_message', 'bounce_type', 'time'] as $cle) {
            if (isset($ligne[$cle]) && is_scalar($ligne[$cle])) {
                $garde[$cle] = mb_substr((string) $ligne[$cle], 0, 500);
            }
        }

        return $garde;
    }
}
