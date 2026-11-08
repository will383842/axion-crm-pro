# Socle du serveur d'envoi (lot K6)

Python 3.11, bibliothèque standard seule. **Aucun envoi réel** : la sortie est l'interface `Sortie` (dépôt idempotent par id), seul un faux est fourni. Ce code ne dépend pas de K2 ; le branchement sur la vraie file reste à faire.

- **Plafond dur** : compteur par journée civile Europe/Paris (journées de 23 h et 25 h gérées par `zoneinfo`). Atteint => `451 ... report`, jamais de perte (le client rejoue le même jeton le lendemain). Le compteur ne repart pas à zéro si l'horloge recule (`dernier_instant`, `dernier_jour` persistés).
- **Homme mort** : 15 min sans `sante` signée (HMAC-SHA256) => pause totale, persistée. Une `sante` forgée ou rejouée ne fait rien ; seule une commande `reprise` signée relance.
- **Mode témoins** : allumable par le CRM (commande signée). Extinction : uniquement `eteindre_temoins("local", operateur)`, journalisée ; toute autre origine est refusée avec une alerte. Liste blanche comparée à l'identique (casse comprise).
- **Jetons 7 jours** : SQLite, `synchronous=FULL` (fsync), écrits AVANT le 250. Rejeu => même id, aucun nouvel envoi ; même jeton avec autre contenu => 550 et alerte.
- **S21** : le jeton (et le contenu) est durable avant le dépôt et avant le 250 ; `recuperer()` redépose au redémarrage ; le dépôt étant idempotent, zéro perte et zéro doublon.
- **Fermé par défaut** : fichier absent, illisible, disque plein, exception => 451, mode témoins considéré actif. `Stockage.creer()` (installation seulement) démarre fermé : témoins allumés, aucune santé.

Scénarios : S17 = plafond, S18 = homme mort, S19 = mode témoins (numérotation déduite de l'ADR K0 § 11 ; les specs 26 et 30 ne sont pas dans `docs/`, à confirmer). S21 = arrêt brutal.

Tests : `cd infra/serveur-envoi/socle && python3 -m unittest discover -s tests -t .`

## Adaptateur Lua (fin)
`kumomta/socle_adaptateur.lua.modele` : le Lua ne porte aucune logique. À la réception du message il appelle le socle local (127.0.0.1) avec jeton, destinataire et contenu, puis répond 250 ou 451/550 selon le code reçu, et en cas d'erreur répond 451 (fermé). Modèle non exécuté, sans valeur réelle ; à valider avec K2.
