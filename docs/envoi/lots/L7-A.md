# Lot L7-A — banc de bout en bout, S1 nominal, S9 porte fermée

Cahier : `docs/envoi/lots/B6-B8-cahier.md` § 4 (PR brouillon #671). Préalable fusionné : B3-A. Aucun fichier de `app/`, `config/`, migration ou `NonRegression` touché.

Fichiers : `backend/tests/Support/BancCampagne.php` (aides), `backend/tests/Feature/Envoi/BoutEnBout/CampagneNominaleTest.php` (`bebn`), ce fichier.

Banc : chemin légal (espace, identité, domaine, boîte, feu vert « accorde », campagne, matérialisation, `lancer`, `Sequenceur` à prédicat de porte local, relève IMAP réelle en mémoire).
`envoi.reel_actif` n'est jamais modifiée ; tout reste dans la transaction du test. Chaque scénario est joué sous `pgsql_app` ET propriétaire.
Pour L7-B/C : horloge = lundi 16/11/2026 (`Carbon::setTestNow`, rendue en `afterEach`) ; mode témoins ALLUMÉ (adresses du banc déclarées témoins) ; segment « fédérations » posé (sinon `segment_inconnu`) ; `$banc->injectes` garde les messages injectés.

Scénarios : `bebn 1` S1 nominal (2 personnes, 2 étapes, `In-Reply-To`, compteurs `envoyes = 4`, `touches = 2`) ; `bebn 2` S9 `envoi:cycle` réel ⇒ « porte fermée », 0 message, 0 appel.

Mutations vérifiées rouges : `enregistrerEnvoye` neutralisé ⇒ `bebn 1` ; prédicat local = drapeau réel ⇒ `bebn 1` ; `reel_actif` forcé à vrai ⇒ `bebn 2`.
Aucun scénario rouge sur `main` : pas de lot `L7-FIX`.
