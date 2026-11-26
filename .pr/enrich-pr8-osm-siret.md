# enrich/pr8-osm-siret — correctifs de relecture

- Helper `csvSiret` déplacé dans `Tests\Support\DoublonsFixtures::csvSiret` : chaque test tourne seul.
- Nouveaux tests : fiche protégée, fiche fermée, personne physique (forme vide ou « 1… »), SIRET porté par deux fiches ; compteurs `exclues` / `ambigus` = 1 et rien n'est écrit.
- Essai à blanc : un site candidat n'est compté que si `website` de la fiche est vide (comme à l'écriture).
- Test du téléphone : valeur exacte normalisée (`0140000001`).
- Décision prudente (argent) : la commande n'appelle aucune source payante ; si un appel payant était ajouté, il devrait lire `config('crm.enrichissement.payant_actif', false)` (clé de la PR #807, fermée par défaut). Rien n'est ajouté à `config/crm.php`.
- Taille : la PR reste au-dessus de 400 lignes ; la scinder demanderait une nouvelle branche/PR, non autorisée ici. Décision laissée au relecteur.
- `composer` indisponible (pas de `vendor/`) : pint et pest non lancés ; seulement `php -l`.
