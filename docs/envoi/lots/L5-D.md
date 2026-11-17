# L5-D — écran des gestes de campagne (Lancer, Arrêter, refus, préparation)

Cahier : `docs/envoi/lots/L5-L6-cahier.md` § 8 (PR #649). Préalables fusionnés : L5-C (#661, routes) et L6-B (#663, `preparation`). Frontend seul : aucun backend, aucune migration, aucun drapeau touché.

## Fait
- `api.ts` : mutations `lancer` / `arreter` avec l'en-tête `Idempotency-Key` ; `texteRefus` donne un texte français par code de refus du § 4.2 (jamais le message technique du serveur) ; `materialisation_en_cours` dit « 20 personnes préparées sur 30 » à partir de `preparation`.
- `GestesCampagne.tsx` : `programmee` ⇒ Lancer, Arrêter ; `en_cours` ⇒ Pause, Terminer, Arrêter ; `en_pause` ⇒ Reprendre, Terminer, Arrêter ; aucun geste sur les quatre autres statuts (Dupliquer reste partout, inchangé). Arrêter et Terminer demandent une confirmation (« Irréversible : les personnes déjà prévues ne recevront rien »). Une clé de rejeu par clic : gardée après une panne réseau (même clé au second essai), jetée dès que le serveur a répondu. Le double clic ne part qu'une fois.
- `CampagneSuiviPage.tsx` : bandeau de préparation (progression ou motif de blocage, campagne programmée seulement) ; bandeau « Envoi réel fermé : rien ne part. » tant que `envoi_ferme` est vrai.
- Démo : gestes `lancer` et `arreter`, `preparation` dans la fiche.

## Écarts et remarques
- « Terminer » n'est plus proposé sur une campagne programmée : la matrice du serveur (`DepotCampagnes::TRANSITIONS`) la refuserait ; elle se lance ou s'arrête.
- Le cahier demande « axe sans violation » : `axe-core` n'est pas une dépendance directe du dépôt (seulement via Playwright) et `package.json` n'est pas dans les fichiers du lot. Les tests vérifient les noms accessibles, le focus dans la confirmation (Tab ×6) et la fermeture à Échap ; le contrôle axe reste celui de `tests/e2e/a11y.spec.ts`.
- La démo garde `envoi_ferme: false` (elle simule un serveur ouvert) : les tests du bandeau remplacent la lecture de la fiche.
- Les remarques non bloquantes des relectures de #656 et #658 visent `GestesCampagne.php` (backend) : hors de ce lot.
- Taille : un peu au-dessus de 400 lignes (le cahier cadre un seul lot de 5 fichiers) ; aucun fichier hors liste.

## Tests (`CampagneSuiviPage.test.tsx`, 35 verts ; suite frontend complète 1778 verts)
Boutons des 7 statuts ; double clic = 1 requête, même clé après échec réseau ; 10 refus 409 = 10 textes ; 20 sur 30 ; Arrêter annulé = 0 requête ; confirmation irréversible ; noms accessibles et focus ; bandeaux. Mutations tuées : garde de double clic retirée, clé non réutilisée, Terminer rouvert sur programmée, Arrêter sans confirmation.
