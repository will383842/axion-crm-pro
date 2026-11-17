# Lot L7-A — banc de bout en bout, S1 nominal, S9 porte fermée

Cahier : `docs/envoi/lots/B6-B8-cahier.md` § 4 (PR brouillon #671). Préalable fusionné : B3-A. Aucun fichier de `app/`, `config/`, migration ou `NonRegression` touché.

Fichiers : `backend/tests/Support/BancCampagne.php` (aides), `backend/tests/Feature/Envoi/BoutEnBout/CampagneNominaleTest.php` (`bebn`), ce fichier.

Banc : chemin légal (espace, identité, domaine, boîte, feu vert « accorde », campagne, matérialisation, `lancer`, `Sequenceur` à prédicat de porte local, relève IMAP réelle en mémoire).
`envoi.reel_actif` n'est jamais modifiée ; tout reste dans la transaction du test. Chaque scénario est joué sous `pgsql_app` ET propriétaire.
Pour L7-B/C : horloge = lundi 16/11/2026 (`Carbon::setTestNow`, rendue en `afterEach`) ; mode témoins ALLUMÉ (adresses du banc déclarées témoins) ; segment « fédérations » posé (sinon `segment_inconnu`) ; `$banc->injectes` garde les messages injectés.

Scénarios : `bebn 1` S1 nominal (2 personnes, 2 étapes, `In-Reply-To`, compteurs `envoyes = 4`, `touches = 2`) ; `bebn 2` S9 `envoi:cycle` réel ⇒ « porte fermée », 0 message, 0 appel.

Mutations vérifiées rouges : `enregistrerEnvoye` neutralisé ⇒ `bebn 1` ; prédicat local = drapeau réel ⇒ `bebn 1` ; `reel_actif` forcé à vrai ⇒ `bebn 2`.
Aucun scénario rouge sur `main` : pas de lot `L7-FIX`.

## Corrections de relecture (RELECTURE-OPUS : BLOQUÉ → corrigé)
1. **Faux serveur de `bebn 2`** : le test lit désormais le faux serveur DU CONTENEUR (`app(FauxServeurEnvoi::class)`, singleton utilisé par l'aiguillage de `envoi:cycle`), horloge à 10:00 (inscriptions dues, dans la fenêtre), et exige `appels` inchangés. L'ancienne assertion portait sur un faux serveur relié à rien.
   Limite constatée (mutation 3 : porte retirée dans `ChefOrchestre::cycle`, vue rouge seulement par le texte « porte fermée ») : porte ouverte, l'espace du banc reste « sauté » (`bloquant_rouge`). Cause : porte ouverte ⇒ `envoi.reel_actif` vrai ⇒ le point bloquant `auto.reel_actif_faux` du feu vert est rouge (et le mode témoins du banc n'a pas de ligne `envoi_drapeaux`). Pour qu'il soit traité il faudrait un feu vert complet avec réel actif, ce qui est exactement ce que ce lot ne doit jamais faire. Les assertions « 0 message / 0 appel » restent des gardes de non-régression, mais c'est le texte « porte fermée » qui prouve la porte ; le feu vert est la seconde barrière.
2. **Validation de la campagne (non bloquant)** : `a_valider` passe par `DepotCampagnes::transitionner` (chemin légal) ; le passage à `programmee` reste posé à la main, car le journal est en ajout seul (UPDATE refusé par déclencheur) et la fenêtre d'annulation se mesure sur l'horloge SQL `now()` : le chemin légal ne peut pas la clore dans un test.
3. **Espionne de flux** : `BancCampagne::espionnerFlux()` / `libererFlux()` (classe `EspionneFluxBanc`) posées en `beforeEach` / `afterEach` ; chaque scénario exige 0 ouverture de flux http(s).
