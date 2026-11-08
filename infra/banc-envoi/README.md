# Banc d'envoi (K10)

Banc minimal pour tester l'envoi de prospection **sans aucun envoi réel**.

- Réseau Docker `internal: true` : aucune sortie Internet, aucun port publié.
- `dns-banc` (répond à tout nom vers `puits-accepte`), puits SMTP programmables
  (`puits-accepte`, `puits-4xx`, `puits-5xx`, `puits-lent`), `imap-banc`,
  `compteur` (HTTP `/inc`, `/metrics`), `sonde` (prouve zéro connexion sortante).
- Images figées par empreinte (`@sha256:`) ; scripts Python stdlib montés en lecture seule.
- Place réservée : `simulateur` puis `kumomta` (lots suivants), sur le même réseau.

```
cd infra/banc-envoi
docker compose up -d dns-banc puits-accepte puits-4xx puits-5xx puits-lent imap-banc compteur
docker compose run --rm sonde      # code 0 = zéro connexion sortante
python3 -m unittest discover -s tests
```

Scénario S01 : `backend/tests/Unit/Envoi/ScenarioS01BancTest.php` (contre `FauxServeurEnvoi`).
