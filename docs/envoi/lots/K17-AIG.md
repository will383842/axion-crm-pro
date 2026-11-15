# K17-AIG — Adaptateur choisi par domaine (vague 4)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche K17-AIG et décision D11. `ENVOI_REEL_ACTIF=false` : aucun envoi, aucune connexion réseau, aucun serveur réel construit (seulement des doubles de test), aucune migration (elles viennent de S1-B, non modifiées).

## Fait
- `AiguilleurServeurEnvoi` (serveur interne de `PasserelleLocale`, service `envoi.serveur_interne`). Un adaptateur réel (kumo, smtp) n'est atteint que si **les trois** conditions valent : `PorteEnvoiReelle::ouverte()` ; dernière décision de feu vert = « accorde » **et** aucun point bloquant rouge **maintenant**, hors `auto.reel_actif_faux` (lu à chaque appel par `ListeFeuVert::derniereDecision` et `points`, aucune empreinte comparée ; il n'y a donc aucun cache entre une preuve défaite et l'injection) ; dernier contrôle DNS du jour Europe/Paris du domaine avec `pret = true` et `pause = false`, lu en base, jamais résolu.
- Refus « condition non remplie » = `EnvoiRefuse::conditionNonRemplie(raison)` (code `ATTENTE`, nouveau constructeur nommé) : report sans compteur, jamais `ServeurEnvoiRefuse`. Raisons : `domaine_inconnu`, `domaine_sur_faux_serveur`, `envoi_reel_inactif`, `feu_vert_non_accorde`, `dns_non_vert`, `adaptateur_indisponible`. Jamais de repli silencieux sur le faux serveur.
- N'implémente **pas** `RenvoiParMemeCle` : un rejeu de clé `reservee` ou `a_verifier` n'est jamais renvoyé.
- `pause()` : transmise à tout adaptateur réel qui porte au moins un domaine, sans feu vert ni DNS, par `envoi_adaptateurs_reels_portes()` (jamais une lecture directe de `envoi_domaines`) ; prouvée sur la connexion `pgsql_app` sans contexte d'espace, avec témoin (lecture directe ⇒ le double ne reçoit rien).
- `reprise()`, `etat()`, `sante()`, `lireEvenements()`, `etatEnvoi()` : adaptateur appelé seulement si les trois conditions valent pour au moins un de ses domaines dans l'espace courant ; sinon réponse du faux serveur ; `sante()` rend `ok = false` (« aucun adaptateur réel »), donc aucun battement noté. Hors contexte d'espace : même réponse.
- `envoi:domaine-adaptateur {espace} {domaine} {faux|kumo|smtp} --auteur=` : seule voie de changement, par la fonction journalisée de S1-B ; valeur hors liste refusée sans rien écrire.
- `AiguillageServiceProvider` relie seulement `envoi.serveur_interne`. **Les fabriques d'adaptateurs réels sont vides** : un domaine `kumo`/`smtp` est refusé `adaptateur_indisponible` tant qu'une PR ultérieure (avec audit) ne les branche pas. `FauxServeurEnvoi` reste le seul adaptateur construit.
- `LectureInfrastructure` : champ `adaptateur` en lecture sur chaque domaine.

## Filet modifié : pourquoi
- `ChauffeFreinsTest` (rattaché au filet, `tests/Pest.php`) : trois tests (« Tout arrêter », `envoi:reprendre`, fenêtre d'envoi) posent `app()->instance('envoi.serveur_interne', $faux)` au lieu de `FauxServeurEnvoi::class` : seule la mise en place change, assertions identiques (le singleton reste celui de la commande). Vus rouges avant le changement (3 échecs), verts après.
- `ChauffeFreinsTest` : **donnée de test privée (CLAUDE.md)** : le prénom réel du responsable est remplacé partout par `auteur-test`, valeurs écrites (`--auteur`, `leverPauseJournalisee`) et relues (comptes, `levee_par`, `toContain`) ensemble ; la propriété vérifiée est identique.
- `DesinscriptionTest` : **non modifié** (reste vert avec l'aiguilleur).
- Hors liste du plan (obligatoire) : `SocleFichiersCommunsTest`, test « `envoi.serveur_interne` vaut FauxServeurEnvoi par défaut » : K17-AIG relie ce service, l'assertion devient « vaut l'aiguilleur, dont le seul adaptateur construit est `FauxServeurEnvoi` » (Test modifié : le service par défaut change par construction).

## Limites assumées
- `lireEvenements`/`etatEnvoi` s'adressent au premier adaptateur autorisé (ordre alphabétique) : un seul adaptateur réel à la fois dans ce plan.
- Un rouge `auto.mode_temoins_vrai` ou `auto.signature_absente` rend l'adaptateur inatteignable (acceptable au premier envoi, témoins ; à revoir en sortie du mode témoins, section 9).
- La résolution MX du destinataire a lieu avant l'aiguilleur (porte ouverte) : inatteignable dans ce plan.
- Couper `ENVOI_REEL_ACTIF` ne vide pas une file déjà chez Kumo : « Tout arrêter » d'abord, couper le drapeau ensuite.

## Risques / à auditer
Sécurité : les trois conditions, le code `ATTENTE`, l'absence de `RenvoiParMemeCle`, `pause()` sans contexte, `sante()` hors contexte.

## Tests lancés
`AiguilleurServeurEnvoiTest` (40), `ChauffeFreinsTest`, `DesinscriptionTest`, `SocleFichiersCommunsTest`, `tests/Feature/Envoi`, groupe `non-regression`, PHPStan, Pint.
