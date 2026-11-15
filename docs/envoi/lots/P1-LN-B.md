# P1-LN-B — Listes de refus : import (D8, première moitié)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche P1-LN-B. `ENVOI_REEL_ACTIF=false` : aucun envoi réel. Aucune migration (la table vient de S1-C).

## Fait
- `POST /envoi/opposition/import` (propriétaire seul), corps `application/json` `{etape, contenu, jeton?}`, jamais multipart. Le texte reste en mémoire.
- `ImportOppositions` : simulation (aucune écriture, aucune adresse renvoyée, jeton de 15 min en cache, clé = `envoi:import:` + le jeton, valeur = utilisateur, espace, SHA-256 du texte brut) puis confirmation (SHA-256 du texte brut recalculé, octet pour octet : casse, blancs et sauts de ligne comptent ; ligne `envoi_oppositions_imports` insérée en premier, violation d'unicité = 409 ; tout ou rien dans une transaction).
- Lignes refusées avec numéro et motif : valeur commençant par `=`, `+`, `-`, `@`, tabulation ou retour chariot (« caractère de tête refusé » : une adresse valide commençant par `+` ou `-` s'inscrit par le formulaire R13) ; domaine de messagerie grand public. 422 seulement pour le corps entier (plus de 1 048 576 octets, plus de 5 000 lignes).
- `RegistreOppositionManuel::inscrireLot` : même effet que `inscrire` (registre, `email_suppressions`, `opt_out`) avec `import_id`, sans `signalerSansBloquer`. Après le COMMIT, `OppositionSortante::signalerSansBloquer` pour chaque adresse (anti-boucle et dédoublonnage décidés par `OppositionSortante`).
- Le contrôleur pose `ATTRIBUT_JOURNAL_PROPRE` et écrit sa ligne d'audit (étape, nombres, `import_id`, statut), sans le corps ni son empreinte.
- Aucune suppression : l'import ne fait qu'ajouter des refus.

## Test modifié : pourquoi
`ContratsS2Test` (hors liste initiale) : il exigeait que l’import soit encore un trou (`trous/conformite.php`) ; le plan vide ce fichier dans ce lot, l’assertion devient « n’est plus un trou » (même chemin que K17-FV pour la décision). La déclaration au contrat reste vérifiée.

## Ajouts d'une seconde session (commits `eea148f`, `94cf1ae`, `715eebc`)
- `TrimStringsSaufImportOpposition` (+ `bootstrap/app.php`) : le contenu du CSV n'est plus rogné par le middleware global, afin que l'empreinte SHA-256 recalculée à la confirmation reste celle du texte envoyé.
- `AuditHashChainLogger` : l'audit propre est aussi écrit sur 422 et 500, et l'empreinte d'un 403 est calculée sans le contenu.
- Tests : journaux, rognage et audit 403/422/500 ; `status_code` est le nom réel de la colonne d'audit.
- Ces trois fichiers sont **hors liste fermée du plan** : validation explicite du relecteur demandée.

## Correctifs de relecture (« PAS PRÊT »)
- Le test « deux confirmations avec le même jeton » ne pouvait pas rougir : l'empreinte mise en cache n'était pas celle que `confirmer` recalculait, le 409 partait du contrôle d'empreinte avant l'insertion. Maintenant l'empreinte est le SHA-256 brut des deux côtés, et le test exige le message « Ce jeton a déjà servi » (preuve que l'insertion a été tentée). Preuve rouge : sans le `catch UniqueConstraintViolationException`, le test échoue avec `envoi_oppositions_imports_jeton_unique` (sortie dans la PR).
- Test « contenu modifié d'un octet » : détecte casse, blanc, saut de ligne (empreinte brute).
- Test « rien sur disque » : parcours récursif ; assertion « le cache ne garde jamais le contenu » faite juste après la simulation.
- Clé de cache préfixée `envoi:import:`. Commentaires de `AuditHashChainLogger` remis devant leur fonction.

## Correctif hors liste (rouge CI : Pest part 4/4)
Après la fusion de `main` (#581, P1-REPRISE), `NeDoitPasRegresserTest › ACQUIS 2` échouait (clé étrangère `fonctions_espace_espace_id_fkey` à la restauration du dump). Cause : `repNettoyer` de `RepriseApresPanneTest` supprime l'espace (rôle `replica`) mais pas sa ligne `fonctions_espace` (colonne `espace_id`, pas `workspace_id`) : un orphelin restait en base. `main` ne le voit pas par hasard de répartition des parties. Correctif : une ligne de nettoyage dans ce test. Preuve : RepriseApresPanneTest + NeDoitPasRegresserTest rouge sans la ligne (`1 failed, 14 passed`), vert avec (`15 passed`). Fichier ajouté à la liste de la PR : validation du relecteur demandée.

## Reste
- Mineurs B (PR `fix/mineurs-b`) : contrôle du caractère de tête après trim complet ; seule la contrainte `envoi_oppositions_imports_jeton_unique` devient un 409 ; une exception en base n'envoie au journal qu'un message générique (sans empreinte) ; limiteur `envoi-opposition-import` (30 par minute et par compte) ; comptes d'imports du test bornés à l'espace.
- Écran d'import : lot F-LN (vague 5).
- Aucune ligne d'audit propre pour un refus d'authentification (401/403) : le journal générique l'écrit, sans `contenu` ni `jeton` dans l'empreinte.
- Le test d'absence de contenu dans les journaux écoute `MessageLogged` (tous niveaux) et relit les fichiers `storage/logs` ; il ne voit pas un journal écrit par un processus externe.

## Risques
- Un arrêt du processus entre le COMMIT et l'émission des événements est rattrapé par `crm:outbound-rattraper` (source `registre_manuel`).
- La portée SIREN n'est pas importée (adresse ou domaine seulement).
- Le test des deux confirmations simultanées prouve l'unicité en base avec une seconde connexion (ligne d'import déjà validée par l'autre connexion), pas avec deux processus : pas d'ouvrier de test dans la liste fermée. Les lignes d'import étant en ajout seul, l'espace de ce test n'est pas nettoyé.
- Les tests base de données n'ont pas pu tourner dans l'environnement de rédaction (PostGIS, pgvector et pg_partman absents) : seuls PHPStan et Pint y ont tourné ; la CI fait foi.

## Tests lancés
`OppositionImportTest` (voir la CI) ; PHPStan niveau 8 (aucune erreur) ; Pint (réussi). Après les corrections de relecture : les 4 parties Pest de la CI sont vertes sur `715eebc` (la preuve rouge CI n'a pas pu être conservée : run annulé par un push suivant).
