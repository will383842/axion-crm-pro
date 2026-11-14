<?php

namespace Tests\Support;

/**
 * Liste EXPLICITE des chemins du contrat d'envoi (backend/openapi/envoi/*.yaml) qui existent comme route mais ne sont
 * pas servis au palier 0 : ils répondent 409 (envois fermés) ou 501 (pas encore branché), jamais autre chose.
 *
 * Une entrée n'est admise que si la route existe réellement ET renvoie bien le code annoncé : NonServiAuPalier0Test fait
 * l'appel. Un chemin sans route n'a PAS sa place ici : c'est un trou réel, listé dans
 * docs/envoi/lots/R19.md.
 *

 * `exemple` : URL concrète (sans /api/v1) appelée par le test ; un jeton @…@ est remplacé par une ressource créée par le test.
 * Clé = « MÉTHODE /chemin du contrat » (paramètres tels que dans le yaml). Valeur = code attendu + raison en une phrase.
 */
final class NonServiAuPalier0
{
    /** @return array<string, array{code:int, raison:string, exemple:string}> */
    public static function entrees(): array
    {
        $f = 'Envois fermés au palier 0';

        return [
            // Inconditionnelles : la réponse est la même que la ressource existe ou non.
            'GET /envoi/carnet' => ['code' => 409, 'raison' => 'Carnet de destinataires indisponible : décision du responsable du projet.', 'exemple' => '/envoi/carnet'],
            'POST /envoi/carnet' => ['code' => 409, 'raison' => 'Carnet de destinataires indisponible : décision du responsable du projet.', 'exemple' => '/envoi/carnet'],
            'POST /envoi/messages/test' => ['code' => 409, 'raison' => "$f : aucun message de test ne part.", 'exemple' => '/envoi/messages/test'],
            'POST /envoi/messages/suggestion' => ['code' => 501, 'raison' => 'Suggestion par IA non ouverte (coût et données confiées à un tiers).', 'exemple' => '/envoi/messages/suggestion'],
            'POST /envoi/configuration/appliquer' => ['code' => 409, 'raison' => "$f : aucune application de configuration.", 'exemple' => '/envoi/configuration/appliquer'],
            'POST /envoi/configuration/versions/{numero}/revenir' => ['code' => 409, 'raison' => "$f : aucun retour de configuration.", 'exemple' => '/envoi/configuration/versions/1/revenir'],
            // Conditionnelles : 409/501 seulement si la ressource existe (404 sinon). Le test crée la ressource dans l'espace
            // courant puis remplace le jeton @brouillon@, @reponse@ ou @serveur@ de « exemple » par son identifiant.
            'POST /envoi/brouillons/{id}/test' => ['code' => 409, 'raison' => "$f : aucun envoi de test.", 'exemple' => '/envoi/brouillons/@brouillon@/test'],
            'POST /envoi/brouillons/{id}/apercu' => ['code' => 501, 'raison' => 'Aperçu pas encore branché.', 'exemple' => '/envoi/brouillons/@brouillon@/apercu'],
            'POST /envoi/reponses/{id}/repondre' => ['code' => 409, 'raison' => "$f : aucune réponse envoyée.", 'exemple' => '/envoi/reponses/@reponse@/repondre'],
            'POST /envoi/serveurs/{id}/test' => ['code' => 409, 'raison' => "$f : aucun test d'envoi du serveur.", 'exemple' => '/envoi/serveurs/@serveur@/test'],
        ];
    }
}
