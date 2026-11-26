# PR #811 : correctifs de la relecture (plan lot médias par région)

Fichiers touchés : `docs/plans/LOT-MEDIAS-REGION.md`, ce fichier.

## Corrections
- Interrupteur payant `crm.enrichissement.payant_actif` ajouté au plan.
- Coût de la recherche web compté dans le compteur et vérifié avant l'appel.
- Deux tests ajoutés : interrupteur payant fermé et mode à blanc, sans requête Claude.
- Étape A : aucune source payante (Places, Hunter, Brave, autre LLM).

## Décision (option la plus prudente pour l'argent)
La clé `crm.enrichissement.payant_actif` est créée par la PR #807, pas par ce lot.
Elle n'est pas ajoutée dans `config/crm.php` (évite un conflit). Elle est lue avec
`config('crm.enrichissement.payant_actif', false)` : fermée par défaut, donc aucun
appel payant tant que #807 n'est pas fusionnée et la clé ouverte.

Aucun code, aucune migration. Pint/tests : sans objet (documentation seulement).
