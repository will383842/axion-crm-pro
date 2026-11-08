# Runbook K16 — le serveur d'envoi est perdu ou compromis

> **K16 PARTIEL.** Ce runbook et son script couvrent la reconstruction du socle (pare-feu, WireGuard d'administration, KumoMTA, clés DKIM). Les critères du plan 33 (l. 79) ne sont **pas tous tenus** : lots ouverts **avant K17**, listés en fin de document (K16-D1 à D6) : reconstruction en moins de 2 h sur VM vierge non démontrée (D1), état « à vérifier » non codé (D2), passerelle non livrée (D4), curseur par serveur (D5), test « opposition reçue entre sauvegarde et panne » absent (D6), écart avec l'ADR 34 D3 (D3, ESCALADE).

Dépôt public : uniquement des marqueurs (`<domaine-1>`, `<ip-envoi>`, `<ip-gestion>`, `<ip-crm-prod>`). Aucun envoi réel n'est repris par ce document : la reprise est un geste humain, avec feu vert écrit du responsable du projet. La prod du CRM n'est jamais modifiée par ce runbook (ADR D7) : côté CRM, les secrets, l'URL et la source du nouveau serveur passent par la configuration d'environnement ; la seule écriture de données (la ligne `envoi_serveurs` du nouveau serveur, étape 6) est un geste du responsable, pas du script (dette K16-D5).

**Quand l'utiliser** : serveur injoignable plus de 15 min sans cause connue (l'homme mort a déjà mis les files en pause), disque ou VPS détruit, hébergeur qui coupe le compte, ou soupçon d'intrusion (accès inconnu, clé exposée, trafic sortant inattendu).

**Règle d'or** : un serveur compromis est traité comme perdu. On ne le « nettoie » pas : on le coupe et on reconstruit.

## Ce qui est perdu, ce qui ne l'est pas
- **Perdu avec le serveur** : les clés DKIM privées (principale ET secours : elles ne vivent que là, ADR D3), la file d'attente des messages, le journal local d'événements, les mots de passe d'injection, la clé privée WireGuard.
- **Conservé côté CRM** : la liste des envois, les oppositions, les plaintes, le curseur d'événements, le registre des domaines.
- **Conséquence sur le « sélecteur de secours déjà publié »** : sa clé privée est sur le même serveur. Si le serveur est perdu ou compromis, le secours est donc **brûlé aussi**. Il ne sert qu'à une rotation planifiée sur un serveur sain. On repart de NOUVEAUX sélecteurs (étape 4).

## Étapes (dans cet ordre, sans en sauter)

### 1. Arrêter les envois côté CRM (minutes)
- Mettre le module d'envoi en pause (arrêt global) : `php artisan envoi:tout-arreter <workspace> --auteur=<qui>` (par workspace ; la levée est `envoi:reprendre`, étape 8 seulement). Laisser `ENVOI_REEL_ACTIF=false`. Ne rien lancer d'autre.
- Noter l'heure UTC d'arrêt : c'est le début de la zone à rattraper (étape 6).
- Soupçon d'intrusion : demander à l'hébergeur de couper le réseau du VPS ou l'éteindre depuis sa console ; **conserver le disque** (instantané) pour l'analyse, sans le rebrancher.

### 2. Faire tourner les secrets HMAC (côté CRM, par le canal d'environnement habituel) — OBLIGATOIRE
- Nouveau secret par espace et par serveur ; l'ancien est invalidé tout de suite (pas de période de grâce en cas de compromission).
- Faire tourner aussi l'AC privée et le certificat client si le serveur est compromis (le CRM épingle l'AC : il refusera l'ancienne).
- Sans cette étape, quiconque détient l'ancien secret pourrait signer des réponses au nom du serveur. Cette étape n'est **pas facultative** : le script de reconstruction refuse de tourner sans la confirmation `CONFIRME_HMAC_TOURNE=oui`. Même pour un serveur simplement éteint sans soupçon, la rotation est exigée (le coût est faible, le doute coûte plus cher).

### 3. Retirer l'ancienne identité DKIM du DNS
- Pour chaque `<domaine-N>` : supprimer les TXT des anciens sélecteurs, principal ET secours (ou publier `p=` vide pour révoquer). Une clé volée ne doit plus pouvoir signer « valablement ».
- TTL court conseillé en temps normal (1 h) pour que ce retrait agisse vite.
- Rappel : les messages déjà envoyés restent vérifiables jusqu'à ce retrait ; c'est le prix d'une compromission.

### 4. Reconstruire sur un VPS vierge
- Commander/ouvrir le VPS **est une décision d'achat : ESCALADE au responsable du projet** (même hébergeur, projet dédié à l'envoi ; plan B documenté dans l'ADR D1). Le script ne commande rien.
- Ubuntu 24.04 seulement. **Amener le dépôt sur le VPS vierge** : depuis le poste d'administration, `git clone` du dépôt public (accès HTTPS sortant ouvert avant le pare-feu) puis `git checkout <commit>` où `<commit>` est le commit de `main` désigné par le responsable (relever le hash avec `git rev-parse HEAD` et le comparer à celui annoncé) ; à défaut, archive du même commit copiée par `scp`. Jamais une copie d'un autre serveur. Les valeurs réelles ne sont jamais dans le dépôt.
- Poser `/etc/axion-envoi/valeurs.env` (hors dépôt, voir `infra/serveur-envoi/RUNBOOK.md`) avec les nouvelles IP.
- **Répétition à blanc de l'installateur** avant tout : `infra/serveur-envoi/installer.sh` SANS option (valide les valeurs, rend les modèles dans un dossier temporaire, ne modifie rien), puis le script de reconstruction à blanc ci-dessous.
- Choisir deux **nouveaux** sélecteurs DKIM (jamais un ancien nom), puis, en root sur le nouveau serveur :
  ```
  export DOMAINES_ENVOI="<domaine-1>,<domaine-2>"
  export SELECTEUR_PRINCIPAL="<selecteur-nouveau-1>" SELECTEUR_SECOURS="<selecteur-nouveau-2>"
  export CONFIRME_ENVOIS_ARRETES=oui CONFIRME_HMAC_TOURNE=oui
  infra/serveur-envoi/reconstruction/reconstruire.sh        # à blanc : relire
  infra/serveur-envoi/reconstruction/reconstruire.sh --oui
  ```
  (les marqueurs ci-dessus sont à remplacer par les vraies valeurs, dans le terminal seulement, jamais dans un fichier du dépôt.) Le script refuse tout marqueur resté, installe le socle K3, génère les clés DKIM **sur place** et ne reprend **pas** l'envoi. Il pose aussi le groupe `axion-dkim` (GID 10025, celui de `docker-compose.yml`) et des clés en `0640 root:axion-dkim` (dossiers `0750`) : des clés `0600 root` seraient illisibles pour KumoMTA, qui refuserait tout en 451. Il vérifie et corrige aussi les clés déjà présentes.
  **Relance.** Après une reconstruction complète, ou un échec *avant* que `/opt/axion-envoi` existe, relancer `--oui` est sans danger. Après un **échec partiel** (socle posé, marque `/etc/axion-envoi/.reconstruit` non écrite), la relance est **refusée** (« serveur non vierge »). Le seul contournement est `AXION_FORCER_NON_VIERGE=oui`, avec ces précautions : (1) lire l'erreur et en corriger la cause ; (2) ne jamais supprimer une clé DKIM existante ; (3) s'assurer que c'est bien le serveur neuf, sans courrier ni file ; (4) relancer avec l'à blanc d'abord. L'installateur est idempotent et un domaine déjà initialisé est sauté (ses droits sont reverifiés).
- Mettre à jour : PTR/HELO/A de la nouvelle `<ip-envoi>`, les deux nouveaux TXT DKIM publiés, et le SPF des domaines : **ajouter** la nouvelle `<ip-envoi>` ET **RETIRER l'ancienne** (sinon, une fois réattribuée à un tiers, l'ancienne IP enverrait au nom de nos domaines avec un SPF valide et un DMARC aligné). Le contrôle DNS du jour (K12) doit être vert pour chaque couple (domaine, IP).
- Une nouvelle IP n'a pas de réputation : la chauffe repart au palier 1 (K8). Ne pas reprendre le volume d'avant.
- Poser côté CRM, avec le feu vert du responsable, la nouvelle URL et les nouveaux secrets (configuration d'environnement : `ENVOI_SERVEUR_URLS` et `ENVOI_SERVEUR_SECRETS`, indexées par `source`, voir étape 6 pour la source).

### 4 bis. Remettre la passerelle sur le nouveau serveur — NON LIVRÉ (dette K16-D4)
L'étape 5 (témoins) dépend de la passerelle HTTPS HMAC, que ni l'installateur ni le script de reconstruction ne posent. **Tant que K16-D4 n'est pas soldée, ne pas passer à l'étape 5 ni reprendre.** Ce qui manque, à faire à la main par le responsable technique, dans cet ordre :
1. nginx et php-fpm pour la passerelle (`infra/serveur-envoi/passerelle`), pool dédié, `clear_env = no`, fichier d'environnement `0600` hors dépôt ;
2. `PASSERELLE_CLES` : les NOUVEAUX secrets de l'étape 2 (32 octets au moins), `PASSERELLE_BASE` ;
3. une NOUVELLE AC privée, un certificat serveur pour `<ip-gestion>` signé par elle, un certificat client pour le CRM ; le CRM épingle la nouvelle AC (l'ancienne est abandonnée) ;
4. contrôle : requête signée acceptée, requête sans certificat client refusée (401), `ENVOI_REEL_ACTIF=false` déclaré dans le pool.

### 5. Mode témoins d'abord (après l'étape 4 bis)
- Remettre le serveur en **mode témoins** (messages vers les boîtes témoins seulement). Vérifier : en-tête `Received` avec `<ip-envoi>` et jamais `<ip-gestion>`, SPF/DKIM/DMARC verts sur un message témoin, injection d'un message déjà signé refusée.

### 6. Rattraper les événements manquants — état « à vérifier », sans accusé
Le serveur perdu emportait ses événements non tirés. Le CRM ne doit **pas** deviner :
- **Tout message sans événement final** (livré, rebond, plainte) passe à **« à vérifier »**, **sans borne de temps** : ni « entre deux dates », ni « plus vieux que N heures ». Un message différé (4xx) encore en file sur le serveur perdu ne doit pas rester « en cours » pour toujours.
- « À vérifier » n'est **ni livré ni échoué** : aucun accusé de livraison, aucun compteur de succès, aucun rebond supposé. Pas de réinjection automatique (risque de doublon chez le destinataire).
- Compléter par la boîte de réception D2 (rebonds, plaintes ARF, réponses) via la relève IMAP : un rebond ou une plainte qui arrive après coup se rattache par son VERP et remplace l'état « à vérifier ».
- Au bout de 7 jours sans signe, la personne responsable décide, message par message ou par lot, de la suite (clore sans accusé, ou relancer à la main). Jamais automatique.
- **Curseur d'événements.** Le client refuse un curseur qui recule (`ClientServeurEnvoiHmac`) et bloque le tirage si l'époque de la passerelle change (409, intervention humaine). Donc **jamais de remise à zéro manuelle** du curseur de l'ancien serveur. Chemin : le nouveau serveur est une **nouvelle `source`** (nouvelle ligne `envoi_serveurs`, nouvelle URL et nouveau secret dans l'environnement), avec son propre curseur (vide au premier tirage) et sa propre époque ; l'ancienne source est désactivée et gardée en lecture pour l'historique. Aujourd'hui aucune commande ne crée ni ne désactive une source, et le curseur par source n'est pas conservé par du code appelant : **dette K16-D5** (lot dédié avant K17).

### 7. Opposition rattrapée AVANT toute reprise (bloquant)
- Vider la file « à rattacher » de la relève IMAP (revue humaine L8) : un STOP, une plainte, une réponse hostile qui s'y trouve n'est **pas appliqué** tant qu'une personne ne l'a pas traité.
- Lancer la relève IMAP sur toute la fenêtre depuis l'heure d'arrêt (rattrapage 30 jours déjà prévu) et vérifier : zéro retour « à rattacher » de classe désinscription ou hostile en attente.
- Vérifier le registre de suppression côté CRM : chaque opposition connue est bien exclue de la prochaine campagne. **Tant que ce contrôle n'est pas vert, ne pas reprendre.**

### 8. Reprise (geste humain, feu vert écrit)
- Commande : `php artisan envoi:reprendre <workspace> --auteur=<qui>` (lève « Tout arrêter » ; local, journalisé, jamais par API).
- Conditions : étapes 1 à 7 faites (dont 4 bis) ; 3 jours de témoins verts sur le nouveau serveur ; DNS vert ; file « à rattacher » vide ; réputation (Postmaster/SNDS) consultée.
- Reprendre à un volume réduit (palier 1), commande `reprise` par le responsable. Surveiller 48 h.
- Compromission : conserver l'instantané du disque, noter la chronologie, et se demander si les domaines sont à renouveler (décision du responsable, jamais automatique).

## Tableau rapide
| Cas | Étapes |
|---|---|
| Serveur éteint, rien de suspect | 1, 2 (jamais facultative), 4 à 8 (3 : retrait DKIM si les clés ne sont pas récupérables) |
| Disque perdu | 1 à 8 |
| Intrusion probable | 1 à 8, couper le réseau, garder l'instantané |
| Hébergeur coupe le compte | 1, ESCALADE (plan B Scaleway, ADR D1), 2 à 8 |

## Lots ouverts avant K17 (K16 partiel)
Critères du plan 33 (l. 79) **non tenus** ; chacun est un lot à ouvrir :
- **K16-D1 — reconstruction en moins de 2 h sur VM vierge** : non démontrée. Le banc K10 (`infra/banc-envoi`) n'est pas un Ubuntu complet avec systemd ; le script n'y est pas rejoué de bout en bout. Testé : refus sans marqueurs, à blanc, idempotence, droits des clés DKIM, shellcheck. À faire : essai chronométré sur un VPS jetable décidé par le responsable (achat) ; c'est aussi l'exercice réel, jamais répété (ancienne dette D4).
- **K16-D2 — état « à vérifier »** : défini ici, **pas codé** côté CRM (aucun état de ce nom dans `backend/app/Envoi`). Lot : état, vue de revue, test « aucun accusé », règle « sans événement final, sans borne de temps ».
- **K16-D3 — secours DKIM / ADR 34 D3** : l'ADR donne comme parade un « sélecteur de secours publié d'avance » ; ses clés vivent sur le serveur et sont perdues avec lui (voir plus haut). L'ADR n'est pas modifié ici ; option à trancher par le responsable (sauvegarde chiffrée hors serveur du secours seul, contraire au principe D3 actuel). ESCALADE.
- **K16-D4 — passerelle** : nginx, `PASSERELLE_CLES`, nouvelle AC privée, certificat serveur non livrés (étape 4 bis). Bloquant pour les témoins et la reprise.
- **K16-D5 — source et curseur du nouveau serveur** : aucune commande pour créer/désactiver une source `envoi_serveurs`, curseur par source à câbler (étape 6).
- **K16-D6 — test « opposition reçue entre la sauvegarde et la panne »** : absent. Doit prouver que ce cas bloque la reprise (étape 7).
