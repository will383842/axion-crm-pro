# Lot B3-A — la matérialisation vérifie les adresses avant d'inscrire

Cahier : `docs/envoi/lots/B6-B8-cahier.md` (PR brouillon #671), section 3. B3-B (#676) est fusionné : un verdict absent est reporté, jamais arrêté.

## Comportement
Dans `MaterialisationCampagne::derouler`, après la lecture du lot et AVANT l'écriture (donc hors transaction et hors verrou : le DNS n'en tient jamais), `PreparationVerdicts::pourContacts` vérifie et écrit les verdicts, sous le contexte d'espace.
- `invalide` : écarté, compteur `adresse_invalide` ; `risque` : écarté, compteur `adresse_risquee` (défaut de l'ESCALADE 2).
- `valide` récent, ou aucun verdict utilisable (DNS indéterminé, quota épuisé, ligne ancienne) : inscrit ; la porte reste seule juge et B3-B reporte sans arrêter.
- `retenus` est diminué des écartés : `membres - retenus` reste le total des exclus de préparation. Les compteurs s'ajoutent au `jsonb` existant (aucune migration).
- Schéma des verdicts incomplet, ou exception du vérificateur : `MaterialisationRefusee('verdicts_indisponibles', réessayable)`, rien inscrit, curseur inchangé.
- Les verdicts déjà écrits d'un lot ensuite refusé (`concurrence`) restent : faits datés, INSERT seul, pas de doublon avant 20 jours.
- Une personne opposée est écartée par la sélection avant toute vérification : aucune requête DNS pour son domaine.

## Ce qui ne change pas
Aucune garde retirée, `ENVOI_REEL_ACTIF` intact, aucune migration, aucune suppression de données. `SelectionDestinataires` reste en lecture seule.

## Fichiers
`app/Envoi/Campagnes/MaterialisationCampagne.php`, `app/Envoi/Verdicts/PreparationVerdicts.php` (docblock), `tests/Feature/Envoi/MaterialisationVerdictsTest.php`, ce document. Filet existant non modifié.

## Tests
7 cas du cahier, chacun sous le rôle applicatif ET propriétaire. Mutations vérifiées rouges : appel retiré (1 à 5, 7) ; `null` écarté (1) ; `retenus` non corrigé (1). Non-régression verte : `MaterialisationCampagneTest`, `MaterialisationCommandeTest`, `GestesCampagneSequenceTest`, `ClotureInscriptionsCampagneTest`, `RafraichissementVerdictsTest`, `ReetalementStockTest`.
