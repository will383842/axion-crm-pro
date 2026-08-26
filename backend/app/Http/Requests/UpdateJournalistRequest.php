<?php

namespace App\Http\Requests;

/**
 * Modification d'un contact presse.
 *
 * Mêmes règles que la création, toutes rendues facultatives : un PATCH ne doit
 * pas exiger qu'on renvoie le dossier complet pour corriger une rubrique.
 *
 * ── La garde qui compte : `sometimes` ne veut pas dire `nullable` ──────────
 * On préfixe par `sometimes` SANS retirer les contraintes : un champ absent est
 * ignoré, un champ présent est validé exactement comme à la création. La
 * tentation inverse — tout passer en `nullable` pour « simplifier » — laisserait
 * un PATCH effacer un email valide en envoyant une chaîne vide, sans que rien
 * ne le signale.
 *
 * La règle croisée nom/prénom (`required_without`) est retirée ici : à la
 * modification, l'autre nom est déjà EN BASE, pas dans la requête, et la règle
 * refuserait donc à tort un simple changement de prénom. L'invariant « au moins
 * un nom » est revérifié dans le contrôleur, sur l'état FUSIONNÉ.
 */
class UpdateJournalistRequest extends StoreJournalistRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (parent::rules() as $field => $constraints) {
            $constraints = array_values(array_filter(
                $constraints,
                static fn ($rule): bool => ! (is_string($rule) && str_starts_with($rule, 'required_without')),
            ));

            // `sometimes` doit rester en tête : c'est lui qui décide que la
            // règle ne s'applique qu'aux champs réellement transmis.
            if (! in_array('sometimes', $constraints, true)) {
                array_unshift($constraints, 'sometimes');
            }

            $rules[$field] = $constraints;
        }

        return $rules;
    }
}
