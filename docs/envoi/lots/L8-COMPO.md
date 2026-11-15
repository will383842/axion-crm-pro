# L8-COMPO — Compositeur de séquence, expéditeur par espace, registre des messages envoyés (vague 3)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche L8-COMPO. `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune migration, données fictives (`*.example.test`) en test seulement.

## Fait
- `ComposeurSequenceMessage` (implémente `ComposeurSequence`) : s'appuie sur `ComposeurMessage` ; ajoute lien et `mailto` de désinscription (jeton signé, référence opaque), entité, adresse postale et texte article 14 tirés de l'identité de l'espace. Le Message-ID réservé par le séquenceur remplace celui de `ComposeurMessage` ; pas de signature DKIM côté CRM (le serveur signe). Aucun suivi d'ouverture.
- `SourceExpeditionDb` : lit l'identité (`DepotReglages::identite`), le domaine (`envoi_domaines`) et la boîte active de l'espace sur ce domaine (`envoi_boites`) ; refus (`RuntimeException`) si l'un manque. `sequenceurPour($ws, $serveur, $fiches)` construit **un `Sequenceur` par espace**.
- `Sequenceur` : à la conclusion `acceptee` (et à la reprise d'un message déjà accepté), crée si besoin la ligne `envoi_injections_locales` (clé `seq-<inscription>-<rang>`, idempotente) puis écrit `envoi_messages_envoyes` (empreintes seulement). L'identifiant du serveur reste dans `envoi_messages.id_injection`.
- Échec d'écriture du registre : écrit sous point de sauvegarde ; la conclusion d'un message réellement parti est conservée, et une alerte critique `envoi.registre_ecriture_echouee` est émise après le COMMIT (jamais silencieux).

## Reste
- Aucun appelant en production : la fabrique est branchée par L8-ORCH (vague 4).
- Une référence de désinscription est créée à chaque composition (ajout seul) ; un rejeu en crée une de plus.
- Un message dont le registre a échoué doit être inscrit à la main (l'alerte donne l'inscription et l'étape).

## Risques
- Si plusieurs domaines ou boîtes existent, le premier domaine ayant une boîte active est retenu.

## Tests lancés
`ComposeurSequenceMessageTest`, `RegistreInjectionLocaleTest` (rouges d'abord : classes absentes, puis verts), `tests/Feature/Envoi`, `EnvoiSequenceurFigeTest`, PHPStan, Pint.
