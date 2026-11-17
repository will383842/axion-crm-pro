# Lot L7-B — banc de bout en bout : S2 réponse, S3 STOP, S4 rebond dur, S5 rebond mou

Cahier : `docs/envoi/lots/B6-B8-cahier.md` § 5 (PR brouillon #671). Préalable fusionné : L7-A (#701). Aucun fichier de `app/`, `config/`, migration ou `NonRegression` touché.

Fichiers : `backend/tests/Feature/Envoi/BoutEnBout/CampagneRetoursTest.php` (`bebr`), `backend/tests/Support/BancCampagne.php` (aides ajoutées), ce fichier.

Montage : 2 entreprises, A avec 2 contacts dont un seul inscrit (E7), B avec un ; 2 étapes ; étape 1 partie le jour J ; chaque scénario est joué sous `pgsql_app` ET propriétaire ; 0 ouverture de flux http(s) exigée.

Scénarios : `bebr 1` S2 réponse (A `arretee/reponse`, trace, étape 2 pour B seul, A = 1 message à vie, réponse visible dans `ServiceReponses`) ; `bebr 2` S3 STOP (`email_suppressions`, A `arretee/opposition`, 0 injection pour A, `contacts`/`companies` non supprimés, `deleted_at` nul) ; `bebr 3` S4 rebond dur rattaché par VERP signé SEUL (aucun Message-ID cité), `rebonds_durs = 1` ; `bebr 4` S5 rebond mou (aucun arrêt, étape 2 envoyée, `rebonds_mous = 1`), puis dur : compté dur seulement.
Chaque scénario rejoue la relève (curseur remis à zéro) : `envoi_evenements`, traces, `envoi_reponses`, `email_suppressions` inchangés.

Aides ajoutées au banc : `campagne(..., $secondContact)`, `inscriptionDe`, `adresseInscrite`, `idInjectionRegistre`, `reinitialiserCurseur`, `rebondDur(?messageId, adresse, ?verp)`. `messageId` prend l'identifiant d'inscription en `string` (UUID ; l'ancien type `int` n'avait aucun appelant).

## Réserves honnêtes
- **Preuve locale impossible ici** : la base du conteneur n'a ni PostGIS ni pgvector ; les scénarios et les mutations (récepteur de séquence retiré ⇒ `bebr 1` ; classe `desinscription` ignorée ⇒ `bebr 2` ; rattachement VERP coupé ⇒ `bebr 3`) n'ont donc pas été exécutés localement. La preuve est la CI ; les mutations restent à vérifier par la relecture.
- S3 : la relève arrête A tout de suite (`RecepteurRetoursSequence`) ; l'étape 2 n'est donc jamais présentée à la porte pour A. La porte (refus pour opposition) n'est pas exercée ici.
- Non repris de la relecture de L7-A : `EspionneFluxBanc` reste dans `BancCampagne.php` (déplacer vers un fichier propre exige un fichier hors liste autorisée ; à décider pour L7-C).
- À corriger dans L7-C (fichier du banc) : `BancCampagne::arf` place le VERP dans `destinataireDsn` et ne pose pas `typeArf`, il est à reprendre avant S6.
