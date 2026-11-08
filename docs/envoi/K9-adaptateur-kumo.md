# K9 — adaptateur CRM `ServeurEnvoiKumo`

Fichiers : `backend/app/Envoi/Serveur/ServeurEnvoiKumo.php`, `backend/app/Envoi/Serveur/Kumo/*`, tests `backend/tests/Unit/Envoi/Kumo/*`.

## Règles
- Verrou : sans `ENVOI_REEL_ACTIF` ET `ENVOI_K1_ACTIF`, zéro connexion (injection reportée, interrogation = `inconnu`).
- Machine à états de L4 : `injection_en_cours` écrit AVANT le réseau ; 4xx = report (une tentative par appel, jamais de boucle) ;
  coupure/délai de 30 s après DATA = `a_verifier`, JAMAIS renvoyé (`etatEnvoi` d'abord, sort inconnu = reste `a_verifier`) ;
  3 × 5xx en 10 min = commande `pause`.
- `pause` / `reprise` / `retrait` : `ClientServeurEnvoiHmac::commander` (contrat v2, déjà derrière le verrou).
- Liste blanche des témoins vérifiée avant tout état.
- `TransportSmtpTunnel` : STARTTLS exigé, AUTH après TLS, en-tête `X-Envoi-Jeton`. Il ne se connecte qu'à `envoyer()`.

## Écart à l'ADR K0 D7 (à trancher par le responsable du projet)
La consigne du lot demande l'injection SMTP par un tunnel. L'ADR K0 D7 retient au contraire HTTPS signé (`POST /v1/injections`)
et écarte tout tunnel vers la prod (modification de la prod = ESCALADE). L'adaptateur est donc écrit derrière l'interface
`TransportInjection` : le transport SMTP fourni ne sert que si le tunnel est décidé ; un transport HTTPS se branche sans toucher la machine à états.
**Aucun branchement, aucune configuration, aucune route n'est ajoutée.**

## Dettes nommées
1. **Kit de conformité réel** (26 § 3.5) reporté (K3/K15). Ici : le kit D4 existant + un faux transport scripté.
2. **Registre durable** : seul `RegistreMemoire` existe. Une table en AJOUT SEUL (hors fichiers autorisés de K9) est à écrire avant toute ouverture du verrou ; sans elle, `a_verifier` ne survit pas au processus.
3. `ServeurEnvoiCommandes` (D4) n'existe pas encore : `retirer()` est une méthode de l'adaptateur ; le test de bout en bout « opposition → retrait < 5 min » est à écrire sur le banc.
4. `etatEnvoi` distant : pas de route de passerelle (K2) ; `InterrogeurEtat` n'a pas d'implémentation réelle.
5. `lireEvenements` local : ordre = ordre du registre ; les vrais événements viennent du tirage K1.
6. Aucun `vendor/` (composer : lock incompatible) : Pest et Pint non exécutés ici ; scénarios vérifiés par un banc PHP autonome et des mutations.
