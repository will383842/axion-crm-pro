# Jour J — procédure ordonnée de bascule vers l'envoi réel (première version, lot X9-A)

Règle : **le responsable du projet** est seul à poser `ENVOI_REEL_ACTIF=true`. Aucun agent, aucun lot ne le touche.
Chaque étape = geste, commande exacte, vérification, retour arrière. Une commande marquée « (lot X…) » n'est pas
encore livrée : la procédure complète (X9-B) retirera ces marques. Valeurs d'exemple : `*.example.test`,
`192.0.2.0/24` ; les vraies valeurs ne vont jamais dans le dépôt.

Variables de l'envoi (toutes posées dans le `.env` du serveur du CRM, jamais dans le dépôt ; le modèle est `.env.example`) :
`ENVOI_REEL_ACTIF`, `ENVOI_PLAFOND_JOUR`, `ENVOI_RELEVE_ACTIF`, `ENVOI_TEMOINS_RELEVE_ACTIF`,
`ENVOI_PLAFOND_GLOBAL_JOUR`, `ENVOI_CLE_EMPREINTE`, `ENVOI_CLES_CODES_CAMPAGNE`, `ENVOI_CLE_CODES_CAMPAGNE_VERSION`,
`ENVOI_CLE_DESINSCRIPTION`, `ENVOI_DESINSCRIPTION_VALIDITE_JOURS`, `ENVOI_DESINSCRIPTION_HOTE`,
`ENVOI_DESINSCRIPTION_URL_APP`, `ENVOI_DESINSCRIPTION_URL_API`, `ENVOI_CONTACT_DESINSCRIPTION`, `ENVOI_K1_ACTIF`,
`ENVOI_SERVEUR_URLS`, `ENVOI_SERVEUR_SECRETS`, `ENVOI_SERVEUR_CLE_ID`, `ENVOI_SERVEUR_CLE_IDS`,
`ENVOI_SERVEUR_CA_PATH`, `ENVOI_SERVEUR_CLIENT_CERT_PATH`, `ENVOI_SERVEUR_CLIENT_KEY_PATH`,
`ENVOI_SERVEUR_EMPREINTE_CLE`, `ENVOI_SERVEUR_CONNECT_TIMEOUT`, `ENVOI_SERVEUR_TIMEOUT`,
`ENVOI_SMTP_DELAI_4XX_MINUTES`, `ENVOI_SMTP_DELAI_BOITE_BLOQUEE_MINUTES`, `ENVOI_DNS_DOMAINES`,
`ENVOI_LISTES_NON_FIABLE_BLOQUE_APRES_MINUTES`, `ENVOI_LISTES_IPS`, `ENVOI_SANTE_ACTIF`.

## J-30 — achats (le responsable du projet)
- Geste : acheter le serveur, les domaines d'envoi et les adresses IP ; ouvrir le port 25 sortant.
- Vérification : les références d'achat sont notées hors dépôt.
- Retour arrière : annuler l'achat chez le fournisseur ; rien n'est posé dans le CRM.

## J-21 — serveur installé
- Geste : suivre `infra/serveur-envoi/RUNBOOK.md` (installation).
- Vérification : `curl -s https://<serveur-envoi>/v1/sante` répond (passerelle servie).
- Retour arrière : arrêter le service sur le serveur ; `ENVOI_SERVEUR_URLS` reste vide.
- Variables posées : `ENVOI_SERVEUR_URLS`, `ENVOI_SERVEUR_SECRETS`, `ENVOI_SERVEUR_CLE_ID`, `ENVOI_SERVEUR_CLE_IDS`,
  `ENVOI_SERVEUR_CA_PATH`, `ENVOI_SERVEUR_CLIENT_CERT_PATH`, `ENVOI_SERVEUR_CLIENT_KEY_PATH`,
  `ENVOI_SERVEUR_EMPREINTE_CLE`, `ENVOI_SERVEUR_CONNECT_TIMEOUT`, `ENVOI_SERVEUR_TIMEOUT`.

## J-21 — DNS publiés
- Geste : publier SPF, DKIM, DMARC, PTR et HELO ; poser `ENVOI_DNS_DOMAINES`.
- Commande : `php artisan envoi:dns-controler <workspace>` (lot X…).
- Vérification : tous les contrôles verts.
- Retour arrière : retirer les enregistrements chez le registraire ; vider `ENVOI_DNS_DOMAINES`.

## J-21 — IP inscrites et domaines reliés
- Commandes : `php artisan envoi:ip-inventaire <workspace>` (lot X6-A) ; `php artisan envoi:domaine-ip <workspace>` (lot X…).
- Vérification : chaque IP d'envoi est listée, chaque domaine relié à une IP ; poser `ENVOI_LISTES_IPS`
  (`192.0.2.10@<uuid-espace>`), `ENVOI_LISTES_NON_FIABLE_BLOQUE_APRES_MINUTES`.
- Retour arrière : « Tout arrêter » (`php artisan envoi:tout-arreter <workspace>`). Ne PAS vider `ENVOI_LISTES_IPS` : une IP non déclarée n'est plus surveillée par la garde des listes noires (`GardeListes.php:12`), vider la liste ouvre la garde au lieu de la fermer.

## J-20 — attestation du serveur réel et preuves des 22 points
- Commande : `php artisan envoi:serveur-reel-attester <workspace>` (lot X5-A), puis fournir les preuves des 22 points à l'écran.
- Vérification : liste de feu vert complète sauf le feu vert final.
- Retour arrière : l'attestation se révoque ; le feu vert reste refusé.

## J-20 — témoins
- Geste : `php artisan envoi:temoin-ajouter <workspace> <fournisseur>`, `envoi:temoins-acces`, puis
  `ENVOI_TEMOINS_RELEVE_ACTIF=true`, `ENVOI_RELEVE_ACTIF=true`, `ENVOI_SANTE_ACTIF=true` (la route `/v1/sante` doit répondre).
- Vérification : `php artisan envoi:temoins-lister <workspace>`, `php artisan envoi:sante`.
- Retour arrière : `php artisan envoi:temoins-off <workspace>` ; remettre les trois interrupteurs à `false`.

## J-3 — trois jours témoins verts
- Vérification : `php artisan envoi:temoins-journee <workspace> <fournisseur>` vert trois jours de suite.
- Retour arrière : toute journée rouge remet le compte à zéro ; ne pas poursuivre.
- Poser aussi `ENVOI_CONTACT_DESINSCRIPTION`, `ENVOI_CLE_DESINSCRIPTION`, `ENVOI_DESINSCRIPTION_*`,
  `ENVOI_DESINSCRIPTION_VALIDITE_JOURS`, `ENVOI_CLE_EMPREINTE`, `ENVOI_CLES_CODES_CAMPAGNE`,
  `ENVOI_CLE_CODES_CAMPAGNE_VERSION`, `ENVOI_SMTP_DELAI_4XX_MINUTES`, `ENVOI_SMTP_DELAI_BOITE_BLOQUEE_MINUTES`,
  `ENVOI_PLAFOND_JOUR`, `ENVOI_PLAFOND_GLOBAL_JOUR`.

## J-1 — essai à blanc
- Geste : `php artisan envoi:valider-tableau <workspace>` puis `php artisan envoi:essai-a-blanc <espace>`, campagne témoin, `ENVOI_REEL_ACTIF` toujours `false`.
- Vérification : aucune injection réelle (journal) ; tout est « ATTENTE ».
- Retour arrière : rien n'est parti ; ne rien supprimer, mettre la campagne en pause.

## J0 — bascule (le responsable du projet)
1. Relancer la batterie des preuves (moins de 15 min, lot X5-C) : tout vert.
2. Accorder le feu vert à l'écran.
3. Poser `ENVOI_REEL_ACTIF=true` et `ENVOI_K1_ACTIF=true` dans le `.env` du serveur du CRM, **par le responsable du projet**.
4. Redémarrer les services (`php artisan config:clear && php artisan queue:restart`).
5. Commande : `php artisan envoi:domaine-adaptateur <workspace> <domaine> kumo`.
- Vérification : première injection de 1 message à une adresse témoin ; plafonds `ENVOI_PLAFOND_JOUR` respectés.
- Retour arrière : voir « Coupure ».

## Coupure (à tout moment)
1. **« Tout arrêter » d'abord** : `php artisan envoi:tout-arreter <workspace>` (ou le bouton à l'écran).
2. Le drapeau ensuite : remettre `ENVOI_REEL_ACTIF=false`, redémarrer (`PLAN-FIN-ENVOI.md:456`).
3. Reprise : commande locale `php artisan envoi:reprendre <workspace>` seule (jamais par l'écran).
