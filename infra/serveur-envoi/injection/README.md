# Écouteur d'injection (lot K4)

Écouteur SMTP d'injection du serveur d'envoi. Python 3.11, bibliothèque standard seule. **Aucun envoi réel** : la file KumoMTA est une interface (`file_kumo.FileKumo`), seul un faux est fourni.

- Port 2525 sur l'IP du tunnel (`ip_ecoute`, jamais une adresse joker).
- STARTTLS obligatoire (TLS 1.2 minimum). Avant TLS : seulement EHLO, STARTTLS, NOOP, RSET, QUIT ; AUTH n'est pas annoncé.
- AUTH PLAIN après TLS. L'espace vient du compte authentifié ; `authz` non vide et différent de `authc` = refus. Mots de passe en empreinte PBKDF2.
- En-tête `X-Tenant` du client retiré (lignes repliées comprises).
- Anti-relais en 4 couches : IP autorisée, identité, registre MAIL FROM par espace, domaine d'envoi connu. Un seul destinataire par message.
- `domaines_interdits` (config) : refus permanent 550, domaine et sous-domaines, MAIL FROM et en-tête From. Valeur de test : `principal.example.test`.
- Fin de ligne sans CR refusée (anti « SMTP smuggling »). Panne de la file : 451, jamais « accepté ».

Configuration : fichier JSON dont le chemin est dans `INJECTION_CONFIG` (champs : voir `config.Config`). Aucun secret dans le dépôt.

Tests : `cd infra/serveur-envoi/injection && python3 -m unittest discover -s tests -t .`

Écart avec l'ADR K0 (§ 8) : l'ADR prévoit une politique Lua dans KumoMTA sur 127.0.0.1 ; ce lot suit la consigne (écouteur testable, IP du tunnel). Le branchement sur la vraie file reste à faire.
