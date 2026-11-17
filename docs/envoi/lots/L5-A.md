# Lot L5-A — « lancer » et « reprendre » propagés à la séquence (service seul)

Cahier : `docs/envoi/lots/L5-L6-cahier.md` (PR #649), section 2. Aucune route, aucun contrat, aucune migration, aucun drapeau touché
(`ENVOI_REEL_ACTIF` reste faux ; `ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`, `USINE_AGREGATS_CRM_ENABLED` ni lus ni écrits).

## Fichiers
- `backend/app/Envoi/Campagnes/GestesCampagne.php` : `lancer`, `reprendre` propagé, verrou unique.
- `backend/app/Envoi/Campagnes/DepotCampagnes.php` : une seule modification, `cleDejaJournalisee` devient `public function rejeu(...)` (corps inchangé).
- `backend/app/Envoi/Exceptions/CampagneRefusee.php` : fabriques `materialisationEnCours`, `materialisationBloquee`, `aucunDestinataire`, `repriseRefuseeBase`, `concurrence` ; `sansSequence` accepte un message.
- `backend/tests/Feature/Envoi/GestesCampagneSequenceTest.php`, `backend/tests/Support/campagne-lancer-concurrent.php`.
- `backend/tests/Feature/Envoi/CampagnesSuiviRoutesTest.php` : l'aide `suivCampagne` seulement.

## Ce que fait le lot
- **Verrou unique** : tout geste passe par `DepotCampagnes::sousVerrou` (espace, puis campagne), puis le verrou de la séquence (`envoi_sequence_geste|<ws>|<seq>`) si elle existe. La clé `envoi:campagnes:` disparaît. Verrou occupé ou interblocage : refus 409 `concurrence`.
- **lancer** (`programmee` → `en_cours`) : rejeu d'abord (rien d'écrit, aucun contrôle) ; statut ; préparation bloquée ; séquence ; préparation terminée (`FOR SHARE`) ; au moins un inscrit ; freins ; transition ; puis, seulement si la transition vient d'être écrite et que le dernier geste de la séquence est `pause`, un geste `reprise` (auteur `campagne:lancer`) dans la même transaction. Refus `P0001` de la base : tout est annulé, 409 `reprise_refusee_base`.
- **reprendre** (`en_pause` → `en_cours`) : mêmes contrôles sauf « sans séquence » et « aucun inscrit » (déjà vrais) ; geste `reprise` auteur `campagne:reprise`.
- **pause / terminer** : inchangés (le déclencheur de la base écrit la pause de séquence) ; seul le verrou change.
- Échec fermé : le lot n'inscrit ni ne sélectionne personne ; une préparation sans inscrit (tout le monde écarté, y compris les personnes opposées) refuse le lancement. Rien n'est supprimé.

## Deux écarts à la lettre du cahier (même intention, à relire)
1. **`lock_timeout` posé AVANT les verrous.** Le cahier écrit `depot->sousVerrou(..., function () { SET LOCAL lock_timeout …})` : posé dans la fonction, il arrive après l'acquisition des verrous et ne borne pas l'attente (le test 10 attendrait sans fin). `GestesCampagne::sousVerrou` ouvre donc une transaction englobante, pose `lock_timeout = 5s`, puis appelle `DepotCampagnes::sousVerrou` (imbriqué : mêmes verrous, même ordre).
2. **Préparation bloquée contrôlée avant « sans séquence ».** Une préparation bloquée par la construction (`jours_non_applicables`…) n'a jamais de séquence ; avec l'ordre 4 puis 5 du cahier, le test 3 (`materialisation_bloquee`) ne pouvait pas passer. L'ordre devient : bloquée, sans séquence, en cours. Le test 4 (sans séquence, sans préparation) est inchangé.

## Filet modifié : pourquoi
`CampagnesSuiviRoutesTest.php`, aide `suivCampagne` : pour `en_cours`, `en_pause`, `terminee`, la matérialisation est insérée par son chemin légal (propriétaire de la base) AVANT le passage à `en_cours`. L'état « en cours sans préparation terminée » n'est plus atteignable ; aucune assertion modifiée ni retirée ; le test de reprise (200 après levée des freins) reste vrai et prouve en plus la propagation.

## Tests
Écrits avant le code, vus rouges (méthode `lancer` absente, ancienne clé de verrou présente), puis verts ; modes `app` (rôle applicatif, RLS) et `proprio` ; PostgreSQL réel. Mutations prouvées : voir le corps de la PR.
