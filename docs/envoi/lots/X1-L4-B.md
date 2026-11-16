# X1-L4-B — `MaterialisationCampagne` (inscriptions étalées, inertes)

Cahier : `docs/envoi/lots/X1-L4-P2-cahier.md` (PR #609), section 4. Aucun drapeau touché : `ENVOI_REEL_ACTIF` reste faux.

## Ce que fait le lot
`MaterialisationCampagne::materialiser($ws, $campagne, $maintenant)` : construit la séquence (L3) si besoin, lit la cible par lots à curseur (L2),
répartit les échéances (L4-A) puis écrit les inscriptions `entreprises` d'un lot dans UNE transaction (verrous : espace, campagne, étalement).
Aucun envoi, aucun passage à `en_cours`, aucune ligne `envoi_messages` : la séquence naît en pause, les inscriptions sont inertes.

## Choix où le cahier laissait une latitude
- `horizon_plein` : levé comme refus (réessayable) si l'appel n'a rien écrit ; rendu dans `ResultatMaterialisation::motif` (état `en_cours`) si l'appel
  avait déjà écrit un lot. Le test 5 du cahier (20 inscrits puis arrêt) l'exige.
- `campagne_introuvable` (identifiant non UUID ou campagne absente) : refus non réessayable, ajouté à la liste des motifs.
- Garde « hors transaction » : `LogicException` si le niveau dépasse `MaterialisationCampagne::$niveauTolere` ; cette propriété ne sert qu'aux
  tests (transaction de `RefreshDatabase`) et est ignorée hors `APP_ENV=testing`.
- Taille de lot et lots par appel : paramètres de constructeur (valeurs par défaut = constantes du cahier), la classe restant `final`.
- Test 10 (course) : la lecture d'opposition injectée est appelée AVANT la lecture des inscriptions actives de L2 ; la course est donc simulée par
  un crochet `DB::beforeExecuting` posé juste avant l'`INSERT` du lot.
- `Inscriptions::inscrire` lève `InvalidArgumentException` quand l'index « une inscription active par adresse » (P2-B) ignore la ligne.
