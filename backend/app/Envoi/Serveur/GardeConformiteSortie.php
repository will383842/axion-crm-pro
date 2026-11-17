<?php

namespace App\Envoi\Serveur;

use App\Envoi\Exceptions\EnvoiRefuse;

/**
 * Garde de conformité de sortie (décision E6), appliquée par l'aiguilleur aux adaptateurs RÉELS seulement, AVANT toute fabrique.
 * Fonction pure : lit les en-têtes du message RFC 5322 déjà composé, aucune base, aucun réseau, aucune adresse conservée. Exige :
 *   - un seul `List-Unsubscribe`, avec une URL `https://` (hôte non vide) ET un `mailto:` (adresse non vide) ;
 *   - un seul `List-Unsubscribe-Post: List-Unsubscribe=One-Click` ;
 *   - aucun en-tête `X-Envoi-*` (jeton et source internes : ils ne sortent jamais).
 * Échec ⇒ `EnvoiRefuse::conditionNonRemplie('conformite_sortie')` : rien n'est parti, le message est reporté.
 *
 * LIMITE (ESCALADE, cahier C3-4) : le bloc d'information art. 14 n'est PAS contrôlé ici. Le composeur (`ComposeurMessage`) ne pose
 * aucun marqueur dédié : le texte est libre et fondu dans le pied. Contrôler sans marqueur serait deviner ; le composeur n'est pas
 * un fichier de ce lot. À trancher avec le lot qui ajoute le marqueur, avant de lier une fabrique réelle.
 */
final class GardeConformiteSortie
{
    private const REFUS = 'conformite_sortie';

    /** @throws EnvoiRefuse */
    public function verifier(string $rfc5322): void
    {
        $entetes = $this->entetes($rfc5322);
        if ($entetes === null) {
            throw $this->refus();
        }
        foreach (array_keys($entetes) as $nom) {
            if (str_starts_with($nom, 'x-envoi-')) {
                throw $this->refus();
            }
        }
        if (count($entetes['list-unsubscribe'] ?? []) !== 1 || ! $this->lienValide($entetes['list-unsubscribe'][0])) {
            throw $this->refus();
        }
        if (count($entetes['list-unsubscribe-post'] ?? []) !== 1 || $entetes['list-unsubscribe-post'][0] !== 'List-Unsubscribe=One-Click') {
            throw $this->refus();
        }
    }

    private function refus(): EnvoiRefuse
    {
        return EnvoiRefuse::conditionNonRemplie(self::REFUS);
    }

    /** Une URL https (hôte non vide) ET un mailto (adresse non vide), chacun entre chevrons. */
    private function lienValide(string $valeur): bool
    {
        $https = preg_match('~<https://[^\s<>/?#]+[^\s<>]*>~i', $valeur) === 1;
        $mailto = preg_match('~<mailto:[^\s<>]+>~i', $valeur) === 1;

        return $https && $mailto;
    }

    /**
     * En-têtes seulement (jusqu'à la première ligne vide), lignes pliées dépliées, noms en minuscules.
     * `null` si le message n'a pas de fin d'en-têtes (ligne vide) : refusé.
     *
     * @return array<string, list<string>>|null
     */
    private function entetes(string $rfc): ?array
    {
        $rfc = str_replace(["\r\n", "\r"], "\n", $rfc);
        $fin = strpos($rfc, "\n\n");
        if ($fin === false) {
            return null;
        }
        $tete = preg_replace('/\n[ \t]+/', ' ', substr($rfc, 0, $fin)) ?? '';
        $sortie = [];
        foreach (explode("\n", $tete) as $ligne) {
            $p = strpos($ligne, ':');
            if ($p === false || $p === 0) {
                continue;
            }
            $sortie[strtolower(trim(substr($ligne, 0, $p)))][] = trim(substr($ligne, $p + 1));
        }

        return $sortie === [] ? null : $sortie;
    }
}
