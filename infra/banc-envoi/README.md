# Banc d'envoi (K10)

Banc minimal pour tester l'envoi de prospection **sans aucun envoi réel**.

- Réseau Docker `internal: true` : aucune sortie Internet, aucun port publié.
- `dns-banc` (répond à tout nom vers `puits-accepte`), puits SMTP programmables
  (`puits-accepte`, `puits-4xx`, `puits-5xx`, `puits-lent`), `imap-banc` (IMAP + puits SMTP « accepte » dans un même processus, Banc partagé),
  `compteur` (HTTP `/inc`, `/metrics`), `sonde` (prouve zéro connexion sortante).
- Images figées par empreinte (`@sha256:`) ; scripts Python stdlib montés en lecture seule.
- Place réservée : `simulateur` puis `kumomta` (lots suivants), sur le même réseau.

```
cd infra/banc-envoi
docker compose up -d dns-banc puits-accepte puits-4xx puits-5xx puits-lent imap-banc compteur
docker compose run --rm sonde      # code 0 = zéro connexion sortante
python3 -m unittest discover -s tests
```

Les 6 témoins (`temoin@<fournisseur>.test`) : tout `RCPT` hors de ces 6 adresses est refusé en 550 par
tous les puits. Sur `imap-banc` (SMTP 25 / IMAP 143), un message livré est déposé dans la boîte selon
`BANC_TEMOIN_<FOURNISSEUR>=inbox|junk|absent` (GMAIL, GOOGLE_WORKSPACE, OUTLOOK_COM, MICROSOFT_365, YAHOO,
ORANGE) puis relu par IMAP. Mots de passe IMAP dérivés de `BANC_SECRET` (généré par `demarrer-banc.sh`,
jamais dans le dépôt) : `temoins.mot_de_passe(secret, adresse)`. Sans `BANC_SECRET`, aucun LOGIN n'est possible.
Les boîtes vivent en mémoire (read_only inchangé) : un redémarrage les vide.

Scénario S01 : `backend/tests/Unit/Envoi/ScenarioS01BancTest.php` (contre `FauxServeurEnvoi`).
