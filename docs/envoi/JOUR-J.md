# Jour J — procédure ordonnée de bascule vers l'envoi réel (version complète, lot X9-B)

Règle : **le fondateur** est seul à poser `ENVOI_REEL_ACTIF=true`. Aucun agent, aucun lot ne le touche.
Chaque étape = geste, commande exacte, **contrôle qui prouve que l'étape est faite**, retour arrière.
Toutes les commandes citées existent (`php artisan list`) ; un test (`VariablesEnvoiDocumenteesTest`) le vérifie.
Dans toute la procédure, `<ID_ESPACE>` est l'identifiant de l'espace de travail du CRM.

Le dépôt est public : les vraies valeurs ne vont **jamais** dans le dépôt, seulement sur les serveurs. Dans ce document,
chaque valeur à acheter ou à poser est un marqueur : `<DOMAINE_ENVOI_1>`, `<IP_ENVOI_1>`, `<IP_GESTION>`,
`<SERVEUR_ENVOI>`, `<SELECTEUR_DKIM>`, `<HELO_1>`, `<FOURNISSEUR_TEMOIN>`. Remplacer chaque marqueur par la vraie
valeur sur le serveur, jamais dans une PR. Exemples : `*.example.test`, `192.0.2.0/24`.

## À ACHETER / À FAIRE PAR LE FONDATEUR

Aucun prix, aucun secret, aucune référence d'achat dans cette liste. Cocher chaque ligne (hors dépôt) avant l'étape citée.

| # | À acheter ou à faire | Marqueur de la valeur obtenue | Étape |
|---|---|---|---|
| 1 | Acheter le ou les **domaines d'envoi** (distincts du domaine principal) | `<DOMAINE_ENVOI_1>` | J-30 |
| 2 | Acheter le **serveur d'envoi** (Ubuntu 24.04, distinct de la prod du CRM) | `<IP_GESTION>` | J-30 |
| 3 | Obtenir au moins une **IP d'envoi dédiée** (IPv4 unique, hors route par défaut) | `<IP_ENVOI_1>` | J-30 |
| 4 | Faire **ouvrir le port 25 sortant** chez l'hébergeur (réponse écrite de l'hébergeur) | — | J-30 |
| 5 | Publier chez le registraire : **SPF, DKIM, DMARC** de `<DOMAINE_ENVOI_1>` | `<SELECTEUR_DKIM>` | J-21 |
| 6 | Faire poser chez l'hébergeur le **PTR** (DNS inverse) de `<IP_ENVOI_1>` vers `<HELO_1>`, puis publier le A de `<HELO_1>` | `<HELO_1>` | J-21 |
| 7 | S'inscrire à **Postmaster Tools** (Google) et à **SNDS / JMRP** (Microsoft) pour `<DOMAINE_ENVOI_1>` et `<IP_ENVOI_1>` | — | J-21 |
| 8 | Créer les **boîtes témoins** chez les fournisseurs à tester (jamais d'adresse dans le dépôt) | `<FOURNISSEUR_TEMOIN>` | J-20 |
| 9 | Prendre la **décision juridique D11** (adresses générales, repli) : écrite, datée ; sans elle `envoi.repli_generique` reste éteint | — | avant J0 |
| 10 | Donner le **feu vert écrit** à l'écran « Feu vert », puis poser `ENVOI_REEL_ACTIF=true` soi-même | — | J0 |

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

## J-30 — achats (le fondateur)
- Geste : lignes 1 à 4 du tableau ci-dessus.
- Contrôle : les quatre lignes sont cochées, les références sont notées hors dépôt, et l'hébergeur a confirmé par écrit
  l'ouverture du port 25 sortant.
- Retour arrière : annuler l'achat chez le fournisseur ; rien n'est posé dans le CRM.

## J-21 — serveur installé
1. Geste : suivre `infra/serveur-envoi/RUNBOOK.md` (installation, puis DKIM, puis la ligne `PASSERELLE_KUMO_URL`).
2. Poser sur le serveur du CRM : `ENVOI_SERVEUR_URLS`, `ENVOI_SERVEUR_SECRETS`, `ENVOI_SERVEUR_CLE_ID`,
   `ENVOI_SERVEUR_CLE_IDS`, `ENVOI_SERVEUR_CA_PATH`, `ENVOI_SERVEUR_CLIENT_CERT_PATH`, `ENVOI_SERVEUR_CLIENT_KEY_PATH`,
   `ENVOI_SERVEUR_EMPREINTE_CLE`, `ENVOI_SERVEUR_CONNECT_TIMEOUT`, `ENVOI_SERVEUR_TIMEOUT`.
- Contrôle : `curl -s https://<SERVEUR_ENVOI>/v1/sante` répond (passerelle servie) ; sur le serveur d'envoi,
  `curl -s http://127.0.0.1:8000/api/check-liveness/` répond.
- Retour arrière : arrêter le service sur le serveur ; `ENVOI_SERVEUR_URLS` reste vide.

## J-21 — DNS publiés
- Geste : publier SPF, DKIM, DMARC, PTR et HELO (lignes 5 et 6 du tableau) ; poser `ENVOI_DNS_DOMAINES`
  (`{"<DOMAINE_ENVOI_1>":{"selecteur":"<SELECTEUR_DKIM>","helo":"<HELO_1>","ip":"<IP_ENVOI_1>"}}`).
- Commande : `php artisan envoi:controle-dns` (sans argument : elle lit `ENVOI_DNS_DOMAINES` et contrôle tous les domaines d'envoi ; elle est aussi planifiée chaque jour).
- Contrôle : tous les contrôles DNS verts, aucun écart signalé. Un seul rouge : ne pas poursuivre.
- Retour arrière : retirer les enregistrements chez le registraire ; vider `ENVOI_DNS_DOMAINES`.

## J-21 — IP inscrites et domaines reliés
- Commandes : `php artisan envoi:ip-inventaire <ID_ESPACE> ajouter <IP_ENVOI_1> --flux=prospection` (`--flux=lettre` pour une IP de la lettre ; sans `--flux`, la commande refuse), puis
  `php artisan envoi:domaine-ip <ID_ESPACE> <DOMAINE_ENVOI_1> <IP_ENVOI_1>`.
- Poser `ENVOI_LISTES_IPS` (`<IP_ENVOI_1>@<ID_ESPACE>`) et `ENVOI_LISTES_NON_FIABLE_BLOQUE_APRES_MINUTES`.
- Contrôle : chaque IP d'envoi est inscrite, chaque domaine est relié à une IP, puis
  `php artisan envoi:listes-noires:controler` ne signale aucune IP listée.
- Retour arrière : « Tout arrêter » (`php artisan envoi:tout-arreter <ID_ESPACE>`). Ne PAS vider `ENVOI_LISTES_IPS` : une IP non déclarée n'est plus surveillée par la garde des listes noires (`GardeListes.php:12`), vider la liste ouvre la garde au lieu de la fermer.

## J-20 — attestation du serveur réel et preuves des 22 points
- Commande : `php artisan envoi:serveur-reel-attester <ID_ESPACE> atteste --motif="<motif de 10 à 500 caractères, sans adresse>"`,
  puis fournir les preuves des 22 points à l'écran « Feu vert ».
- Contrôle : la liste de feu vert est complète sauf le feu vert final.
- Retour arrière : `php artisan envoi:serveur-reel-attester <ID_ESPACE> retire` ; le feu vert reste refusé.

## J-20 — témoins
- Commandes : `php artisan envoi:temoin-ajouter <ID_ESPACE> <FOURNISSEUR_TEMOIN>` puis
  `php artisan envoi:temoins-acces <ID_ESPACE> <FOURNISSEUR_TEMOIN>` (adresse et mot de passe saisis à l'invite, jamais en argument).
- Poser ensuite `ENVOI_TEMOINS_RELEVE_ACTIF=true`, `ENVOI_RELEVE_ACTIF=true`, `ENVOI_SANTE_ACTIF=true`
  (la route `/v1/sante` doit répondre).
- Contrôle : `php artisan envoi:temoins-lister <ID_ESPACE>` montre chaque témoin ; `php artisan envoi:sante` est vert.
- Retour arrière : `php artisan envoi:temoins-off <ID_ESPACE>` ; remettre les trois interrupteurs à `false`.

## J-3 — trois jours témoins verts
- Commande, chaque jour : `php artisan envoi:temoins-journee <ID_ESPACE> <FOURNISSEUR_TEMOIN> --reception=inbox --auth-alignee --bouton-vu --post-effectif`
  (retirer l'option qui n'est pas constatée ; `--reception=indesirables` ou `absent` si le message n'est pas en boîte de réception).
- Contrôle : journée verte trois jours de suite, vue avec `php artisan envoi:temoins-lister <ID_ESPACE>`.
- Retour arrière : toute journée rouge remet le compte à zéro ; ne pas poursuivre.
- Poser aussi `ENVOI_CONTACT_DESINSCRIPTION`, `ENVOI_CLE_DESINSCRIPTION`, `ENVOI_DESINSCRIPTION_HOTE`,
  `ENVOI_DESINSCRIPTION_URL_APP`, `ENVOI_DESINSCRIPTION_URL_API`, `ENVOI_DESINSCRIPTION_VALIDITE_JOURS`,
  `ENVOI_CLE_EMPREINTE`, `ENVOI_CLES_CODES_CAMPAGNE`, `ENVOI_CLE_CODES_CAMPAGNE_VERSION`,
  `ENVOI_SMTP_DELAI_4XX_MINUTES`, `ENVOI_SMTP_DELAI_BOITE_BLOQUEE_MINUTES`, `ENVOI_PLAFOND_JOUR`, `ENVOI_PLAFOND_GLOBAL_JOUR`.
- Contrôle de la pose : chaque variable est présente dans le `.env` du serveur du CRM (jamais dans le dépôt).

## J-1 — essai à blanc
- Commandes : `php artisan envoi:valider-tableau <ID_ESPACE>` puis `php artisan envoi:essai-a-blanc <ID_ESPACE>`, campagne témoin,
  `ENVOI_REEL_ACTIF` toujours `false`.
- Contrôle : aucune injection réelle dans le journal ; tout est « ATTENTE ».
- Retour arrière : rien n'est parti ; ne rien supprimer, mettre la campagne en pause.

## J0 — bascule (le fondateur, lui seul)
1. Avant tout : la ligne 9 (décision juridique D11) et la ligne 10 (feu vert écrit) du tableau sont faites. Sinon, stop.
2. Relancer la batterie des preuves à l'écran « Feu vert » (moins de 15 minutes avant le feu vert) : tout vert.
3. Accorder le feu vert à l'écran.
4. Poser `ENVOI_REEL_ACTIF=true` et `ENVOI_K1_ACTIF=true` dans le `.env` du serveur du CRM, **par le fondateur**.
5. Redémarrer les services (`php artisan config:clear && php artisan queue:restart`).
6. Commande : `php artisan envoi:domaine-adaptateur <ID_ESPACE> <DOMAINE_ENVOI_1> kumo`.
7. Premier cycle : `php artisan envoi:cycle` (il tourne ensuite chaque minute, planifié, tant que la porte est ouverte).
- Contrôle : première injection de 1 message à une adresse témoin ; plafonds `ENVOI_PLAFOND_JOUR` respectés ; la réception
  est confirmée par `php artisan envoi:temoins-lister <ID_ESPACE>`.
- Retour arrière : voir « Coupure ». Pour un domaine seulement : `php artisan envoi:domaine-adaptateur <ID_ESPACE> <DOMAINE_ENVOI_1> faux`.

## Coupure (à tout moment)
1. **« Tout arrêter » d'abord** : `php artisan envoi:tout-arreter <ID_ESPACE>` (ou le bouton à l'écran).
2. Le drapeau ensuite : remettre `ENVOI_REEL_ACTIF=false`, redémarrer (`PLAN-FIN-ENVOI.md:456`).
3. Reprise : commande locale `php artisan envoi:reprendre <ID_ESPACE>` seule (jamais par l'écran).
- Contrôle : plus aucune injection dans le journal après l'arrêt.
