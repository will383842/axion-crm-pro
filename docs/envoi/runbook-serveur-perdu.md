# Runbook K16 — le serveur d'envoi est perdu ou compromis

Dépôt public : uniquement des marqueurs (`<domaine-1>`, `<ip-envoi>`, `<ip-gestion>`, `<ip-crm-prod>`). Aucun envoi réel n'est repris par ce document : la reprise est un geste humain, avec feu vert écrit du responsable du projet. La prod du CRM n'est jamais modifiée (ADR D7) : tout se fait par la configuration d'environnement du CRM, déjà prévue.

**Quand l'utiliser** : serveur injoignable plus de 15 min sans cause connue (l'homme mort a déjà mis les files en pause), disque ou VPS détruit, hébergeur qui coupe le compte, ou soupçon d'intrusion (accès inconnu, clé exposée, trafic sortant inattendu).

**Règle d'or** : un serveur compromis est traité comme perdu. On ne le « nettoie » pas : on le coupe et on reconstruit.

## Ce qui est perdu, ce qui ne l'est pas
- **Perdu avec le serveur** : les clés DKIM privées (principale ET secours : elles ne vivent que là, ADR D3), la file d'attente des messages, le journal local d'événements, les mots de passe d'injection, la clé privée WireGuard.
- **Conservé côté CRM** : la liste des envois, les oppositions, les plaintes, le curseur d'événements, le registre des domaines.
- **Conséquence sur le « sélecteur de secours déjà publié »** : sa clé privée est sur le même serveur. Si le serveur est perdu ou compromis, le secours est donc **brûlé aussi**. Il ne sert qu'à une rotation planifiée sur un serveur sain. On repart de NOUVEAUX sélecteurs (étape 4).

## Étapes (dans cet ordre, sans en sauter)

### 1. Arrêter les envois côté CRM (minutes)
- Mettre le module d'envoi en pause (arrêt global) et laisser `ENVOI_REEL_ACTIF=false`. Ne rien lancer d'autre.
- Noter l'heure UTC d'arrêt : c'est le début de la zone à rattraper (étape 6).
- Soupçon d'intrusion : demander à l'hébergeur de couper le réseau du VPS ou l'éteindre depuis sa console ; **conserver le disque** (instantané) pour l'analyse, sans le rebrancher.

### 2. Faire tourner les secrets HMAC (côté CRM, par le canal d'environnement habituel)
- Nouveau secret par espace et par serveur ; l'ancien est invalidé tout de suite (pas de période de grâce en cas de compromission).
- Faire tourner aussi l'AC privée et le certificat client si le serveur est compromis (le CRM épingle l'AC : il refusera l'ancienne).
- Sans cette étape, quiconque détient l'ancien secret pourrait signer des réponses au nom du serveur. Le script de reconstruction refuse de tourner sans la confirmation `CONFIRME_HMAC_TOURNE=oui`.

### 3. Retirer l'ancienne identité DKIM du DNS
- Pour chaque `<domaine-N>` : supprimer les TXT des anciens sélecteurs, principal ET secours (ou publier `p=` vide pour révoquer). Une clé volée ne doit plus pouvoir signer « valablement ».
- TTL court conseillé en temps normal (1 h) pour que ce retrait agisse vite.
- Rappel : les messages déjà envoyés restent vérifiables jusqu'à ce retrait ; c'est le prix d'une compromission.

### 4. Reconstruire sur un VPS vierge
- Commander/ouvrir le VPS **est une décision d'achat : ESCALADE au responsable du projet** (même hébergeur, projet dédié à l'envoi ; plan B documenté dans l'ADR D1). Le script ne commande rien.
- Ubuntu 24.04 seulement. Poser `/etc/axion-envoi/valeurs.env` (hors dépôt, voir `infra/serveur-envoi/RUNBOOK.md`) avec les nouvelles IP.
- Choisir deux **nouveaux** sélecteurs DKIM (jamais un ancien nom), puis, en root sur le nouveau serveur :
  ```
  export DOMAINES_ENVOI="<domaine-1>,<domaine-2>"
  export SELECTEUR_PRINCIPAL="<selecteur-nouveau-1>" SELECTEUR_SECOURS="<selecteur-nouveau-2>"
  export CONFIRME_ENVOIS_ARRETES=oui CONFIRME_HMAC_TOURNE=oui
  infra/serveur-envoi/reconstruction/reconstruire.sh        # à blanc : relire
  infra/serveur-envoi/reconstruction/reconstruire.sh --oui
  ```
  (les marqueurs ci-dessus sont à remplacer par les vraies valeurs, dans le terminal seulement, jamais dans un fichier du dépôt.) Le script refuse tout marqueur resté, installe le socle K3, génère les clés DKIM **sur place** et ne reprend **pas** l'envoi. Il est relançable sans danger.
- Mettre à jour : PTR/HELO/A de `<ip-envoi>`, SPF des domaines (nouvelle `<ip-envoi>`), les deux nouveaux TXT DKIM publiés. Le contrôle DNS du jour (K12) doit être vert pour chaque couple (domaine, IP).
- Une nouvelle IP n'a pas de réputation : la chauffe repart au palier 1 (K8). Ne pas reprendre le volume d'avant.
- Poser côté CRM, avec le feu vert du responsable, la nouvelle URL et les nouveaux secrets (configuration d'environnement seulement).

### 5. Mode témoins d'abord
- Remettre le serveur en **mode témoins** (messages vers les boîtes témoins seulement). Vérifier : en-tête `Received` avec `<ip-envoi>` et jamais `<ip-gestion>`, SPF/DKIM/DMARC verts sur un message témoin, injection d'un message déjà signé refusée.

### 6. Rattraper les événements manquants — état « à vérifier », sans accusé
Le serveur perdu emportait ses événements non tirés. Le CRM ne doit **pas** deviner :
- Tout message injecté entre le dernier événement tiré et l'heure d'arrêt, sans événement final (livré, rebond, plainte), passe à **« à vérifier »**.
- « À vérifier » n'est **ni livré ni échoué** : aucun accusé de livraison, aucun compteur de succès, aucun rebond supposé. Pas de réinjection automatique (risque de doublon chez le destinataire).
- Compléter par la boîte de réception D2 (rebonds, plaintes ARF, réponses) via la relève IMAP : un rebond ou une plainte qui arrive après coup se rattache par son VERP et remplace l'état « à vérifier ».
- Au bout de 7 jours sans signe, la personne responsable décide, message par message ou par lot, de la suite (clore sans accusé, ou relancer à la main). Jamais automatique.
- Le curseur du CRM ne recule pas ; le nouveau serveur repart d'une séquence neuve : prévoir la remise à zéro explicite du curseur de CE serveur, par le responsable.

### 7. Opposition rattrapée AVANT toute reprise (bloquant)
- Vider la file « à rattacher » de la relève IMAP (revue humaine L8) : un STOP, une plainte, une réponse hostile qui s'y trouve n'est **pas appliqué** tant qu'une personne ne l'a pas traité.
- Lancer la relève IMAP sur toute la fenêtre depuis l'heure d'arrêt (rattrapage 30 jours déjà prévu) et vérifier : zéro retour « à rattacher » de classe désinscription ou hostile en attente.
- Vérifier le registre de suppression côté CRM : chaque opposition connue est bien exclue de la prochaine campagne. **Tant que ce contrôle n'est pas vert, ne pas reprendre.**

### 8. Reprise (geste humain, feu vert écrit)
- Conditions : étapes 1 à 7 faites ; 3 jours de témoins verts sur le nouveau serveur ; DNS vert ; file « à rattacher » vide ; réputation (Postmaster/SNDS) consultée.
- Reprendre à un volume réduit (palier 1), commande `reprise` par le responsable. Surveiller 48 h.
- Compromission : conserver l'instantané du disque, noter la chronologie, et se demander si les domaines sont à renouveler (décision du responsable, jamais automatique).

## Tableau rapide
| Cas | Étapes |
|---|---|
| Serveur éteint, rien de suspect | 1, 4 à 8 (2 et 3 : rotation HMAC par prudence, retrait DKIM si les clés ne sont pas récupérables) |
| Disque perdu | 1 à 8 |
| Intrusion probable | 1 à 8, couper le réseau, garder l'instantané |
| Hébergeur coupe le compte | 1, ESCALADE (plan B Scaleway, ADR D1), 2 à 8 |

## Dettes nommées (K16)
- **K16-D1 — banc K10** : le banc (`infra/banc-envoi`) n'est pas un Ubuntu complet avec systemd ; le script n'y est donc pas rejoué de bout en bout. Testé : refus sans marqueurs, à blanc, idempotence, shellcheck. À faire : essai à blanc sur un VPS jetable décidé par le responsable (achat).
- **K16-D2 — état « à vérifier »** : défini ici, **pas encore codé** côté CRM (aucun état de ce nom dans `backend/app/Envoi`). Lot à ouvrir : état, vue de revue, test « aucun accusé ».
- **K16-D3 — secours DKIM** : tant que les clés ne quittent jamais le serveur, le secours ne protège pas d'une perte du serveur. Option à trancher par le responsable (sauvegarde chiffrée hors serveur du secours seul, contraire au principe D3 actuel).
- **K16-D4 — exercice réel** : jamais répété sur un vrai VPS ; à planifier avant K17.
