# ADR : serveur d'envoi propre du CRM Pro (lot P2-K0, décisions D1 à D7)

- Statut : PROPOSÉ, en attente de validation par le responsable du projet (W4) et de la relecture par eq-auditeur-securite.
- Date : 07/11/2026.
- Portée : palier 2 du module d'envoi (`backend/app/Envoi/`) et nouveau dossier `envoi-serveur/`.
- Fait foi après ce document : la spécification du contrat d'envoi (« spec 26 ») et ses correctifs (« correctifs 30 »). Les points de la spec 26 modifiés ici sont listés en annexe A.
- Règle du dépôt public : aucun nom de domaine réel, aucune IP, aucun prix, aucun secret. On utilise des marqueurs : `<domaine-1>`, `<ip-envoi>`, `<ip-gestion>`, `<ip-crm-prod>`.

## 0. Contexte
- Il n'y aura pas d'envoi de prospection par des boîtes mail pro. Le premier envoi réel partira donc du serveur propre.
- Le serveur d'envoi est une machine NEUVE et SÉPARÉE. Le serveur de production du CRM (« la prod ») n'est jamais touché : ni tunnel, ni règle de pare-feu, ni service ajouté sans accord écrit du responsable.
- Le responsable ajoutera des domaines et des IP au fil de l'eau, avec rotation entre eux (« pool d'envoi », section 10).
- Moteur : KumoMTA, version figée par empreinte. Repli : Postfix + rspamd, déclenché à date fixe (plan 33 § 2.5).

## D1. Hébergeur
**Décision.** Hetzner Cloud, dans un projet dédié à l'envoi et séparé de celui de la prod. Plan B : Scaleway (Instance). OVH et Contabo sont écartés.

**Raisons**
- Choix du responsable.
- Un projet séparé isole les accès, les pare-feux et les clés d'API.
- Scaleway ouvre le port 25 après une vérification d'identité, sans exiger d'ancienneté du compte. C'est le meilleur repli si Hetzner refuse.

**Conditions avant tout achat**
- Poser une question écrite au support Hetzner :
  - usage déclaré honnêtement (prospection B2B, faible volume, opposition en un clic) ;
  - ouverture possible du port 25 ;
  - IP flottante utilisable comme adresse source de l'envoi, avec son propre PTR ;
  - compte distinct de celui de la prod (voir le risque ci-dessous).
- Commander dès que la réponse est favorable : le délai d'ouverture du port 25 (au moins un mois et une première facture payée) ne démarre qu'à la commande.

**Alternatives écartées**
- OVH : écarté par le responsable.
- Contabo : plages d'IP vues sur des listes noires.
- Un serveur dédié Hetzner tout de suite : surdimensionné au début, et la politique sur le port 25 n'est pas confirmée. On le garde pour la montée vers 10 000 envois par jour (section 10).

**Risques**
- Refus ou très long délai d'ouverture du port 25, donc premier envoi décalé. Parade : réponse écrite avant achat, puis bascule Scaleway (2 à 7 jours).
- Une plainte pour abus visant le compte Hetzner pourrait toucher la prod si les deux projets partagent le même compte. Parade : un compte distinct est préférable si les conditions de Hetzner le permettent (question écrite). Sinon, c'est un risque assumé par écrit par le responsable.
- Les plages d'IP des VPS sont regardées avec méfiance par Microsoft. Parade : chauffe par fournisseur (K8), SNDS et JMRP.

## D2. Réception des réponses, rebonds et rapports
**Décision R1.**
- Les MX de `<domaine>` et de `bounce.<domaine>` pointent vers un hébergeur de RÉCEPTION seulement : boîte de lecture, aucun envoi de prospection par elle.
- Cette boîte est relevée par IMAP par la relève du lot L6, étendue en K11.
- Elle reçoit les réponses humaines, les rebonds asynchrones (adresses `rb-<jeton>@bounce.<domaine>`, en fourre-tout), les rapports ARF (JMRP, Yahoo CFL), les rapports DMARC agrégés, les rapports TLS-RPT, ainsi que `abuse@` et `postmaster@`.
- Le serveur d'envoi n'a AUCUN port entrant public pour le courrier.

**Critères de choix de l'hébergeur de réception (choix par le responsable, W3)**
- IMAP avec mot de passe d'application ;
- fourre-tout (ou alias générique) sur un sous-domaine ;
- hébergement dans l'Union européenne ;
- conditions d'utilisation compatibles avec la simple réception ;
- aucun service d'envoi de masse lié au compte ;
- pas d'IP de cet hébergeur dans le SPF (il n'envoie rien).

**Raisons**
- Aucun port entrant public, conformément au plan 20 R9.
- Le code de relève IMAP existe déjà (L6).
- SNDS exige une adresse qui reçoit sur le domaine du PTR, donc R1 doit être en place avant l'étape X7.

**Alternative écartée.** R2, la réception sur notre serveur (port 25 entrant, stockage, IMAP) : environ 5 jours de plus, plus un audit, plus une surface d'attaque. Reportée au palier 2b.

**Risques**
- Relève IMAP en panne : rebonds et plaintes invisibles. Parade : K15 déclenche l'arrêt si le canal des retours reste muet 2 h en campagne.
- Fourre-tout pollué par du spam. Parade : rattachement par jeton ; un message non rattaché va dans la file « à rattacher », jamais en opposition automatique.

## D3. DKIM signé par le serveur seulement
**Décision**
- Les clés DKIM privées (RSA 2048, un sélecteur principal et un sélecteur de secours publié d'avance) sont générées SUR le serveur d'envoi, gardées dans un fichier `0600` et jamais exportées.
- Le CRM ne reçoit que les clés publiques, pour son plan DNS.

**Le conflit avec L2.** Le palier 0 prévoit une « signature DKIM par le CRM ». On le lève ainsi :
1. **Réglage de signature.** La façon de signer est un réglage de configuration par serveur, et non une méthode du contrat : colonne `signature_dkim` dans `envoi_serveurs`, valeurs `serveur` ou `crm`, NOT NULL, sans valeur par défaut. Le composeur de L2 lit ce réglage pour le serveur choisi par l'affectation du pool (section 10).
   - Mode `serveur` : le composeur n'ajoute AUCUNE signature.
   - Mode `crm` : réservé au relais tiers temporaire (spec 26 § 7), avec un sélecteur distinct.
2. **Test « aucune clé privée côté CRM ».** Le kit vérifie qu'aucune clé privée DKIM n'existe côté CRM pour un domaine rattaché à un serveur en mode `serveur` (variables d'environnement, base, disque de l'application).
3. **Second verrou côté serveur.** Un message injecté qui contient déjà un en-tête `DKIM-Signature` pour le domaine d'envoi est refusé en 5xx. Un mauvais réglage se voit ainsi tout de suite au banc.
4. **Calendrier.** Ce mode doit entrer dans L2 (#434) avant sa fusion, ou dans un lot de 0,5 jour juste après. Sinon, L2 doit être reprise.

**Raisons.** C'est le socle de la spec 26 (§ 1.8). Une clé qui ne quitte pas le serveur ne peut pas fuiter par le CRM ni par le dépôt public.

**Alternative écartée.** Signer dans le CRM et faire relayer par le serveur : la clé se trouverait sur la prod et dans ses sauvegardes, et le risque de double signature est réel.

**Risques**
- Perte du serveur, donc perte de la clé. Parade : sélecteur de secours publié d'avance et runbook K16.
- Oubli du réglage. Parade : NOT NULL sans défaut, plus le refus côté serveur.

## D4. Extension du contrat sans toucher à `ServeurEnvoi`
**Décision**
- L'interface `ServeurEnvoi` du palier 0 (injecter, etatEnvoi, pause, reprise, modeTemoins, etat, sante, lireEvenements) reste INCHANGÉE.
- Les opérations manquantes vont dans des interfaces SÉPARÉES, implémentées en plus par l'adaptateur KumoMTA (K9) et par un faux complet pour le banc :
  - `ServeurEnvoiCommandes` : `retirer(cible)` et `recapitulatif(jour)` ;
  - `ServeurEnvoiConfiguration` : `validerConfiguration(paquet)`, `appliquerConfiguration(paquet)` et `clesPubliquesDkim()`.
- La poussée d'événements n'est pas activée en V0 (voir D7). Son format reste celui de la spec 26 § 1.4, pour plus tard.
- Côté appelant : `if ($serveur instanceof ServeurEnvoiCommandes)`. Un adaptateur qui ne l'implémente pas, comme la boîte SMTP de L4, n'a pas de file à retirer et le code doit le tolérer (test dédié).
- Le kit de conformité est découpé : une suite par interface.
- Pour le pool, aucune méthode n'est ajoutée : la source d'envoi voyage dans l'en-tête interne `X-Envoi-Source` (section 10).

**Raisons**
- Ajouter une méthode à `ServeurEnvoi` casserait `FauxServeurEnvoi`, `PasserelleLocale` et l'adaptateur SMTP de L4.
- Les interfaces étroites rendent les capacités explicites.

**Alternatives écartées**
- Méthodes par défaut qui lèvent « non supporté » : une erreur à l'exécution au lieu d'une erreur de type.
- Une seule « grosse » interface v2 : tout le palier 0 serait à migrer.

**Risque.** Un appel oublié derrière `instanceof`, et le retrait urgent (moins de 5 min) ne part pas. Parade : un test de bout en bout « opposition, retrait sur le serveur en moins de 5 min » sur le banc, obligatoire pour K9.

## D5. IP d'envoi distincte de l'IP de gestion
**Décision**
- L'IPv4 principale du serveur sert à la GESTION : passerelle HTTPS et WireGuard d'administration.
- Une IPv4 flottante de Hetzner sert à l'ENVOI :
  - PTR = HELO = A (`mta1.<domaine-1>`) ;
  - aucune entrée acceptée ;
  - sortie limitée au port 25 ;
  - KumoMTA lié à cette source.
- IPv6 n'est pas utilisée pour l'envoi : pas d'AAAA sur `mta1`, KumoMTA en IPv4 seulement (socle 26 § 1.8).
- À confirmer en X3 et prouver au jour 1 : un message reçu par un compte témoin montre `<ip-envoi>` dans `Received`, jamais `<ip-gestion>`.
- **Si Hetzner ne le permet pas, on accepte un écart écrit :** une seule IP, et le port 443 n'accepte que `<ip-crm-prod>` ; tout le reste est rejeté sans réponse.

**Raisons**
- Une liste noire ou une coupure du port 25 sur l'IP d'envoi ne coupe pas l'administration.
- La gestion ne pollue pas la réputation.
- Le PTR est propre à l'envoi.
- C'est aussi la brique de base du pool : une IP d'envoi = un membre (section 10).

**Alternative écartée.** Une seule IP par principe : contraire au socle.

**Risques**
- L'envoi ne peut pas partir depuis l'IP flottante. Parade : test au jour 1, sinon l'écart écrit ci-dessus.
- Nombre d'IP flottantes limité par projet. Parade : plusieurs serveurs dans le pool, puis un serveur dédié avec un bloc d'IP pour le volume.

## D6. Langage de la passerelle
**Décision**
- PHP, même version majeure que le CRM, SANS framework, servi par nginx + php-fpm dans une image figée par empreinte.
- Processus non root, stockage SQLite local, aucune dépendance de production hors extensions standard. PHPUnit seulement en développement, avec `composer.lock` figé.
- Le code HMAC n'est PAS partagé par un paquet. Les deux côtés passent un fichier commun de vecteurs de test, `envoi-serveur/contrat/vecteurs-hmac.json`, vérifié par la CI du CRM et par celle de la passerelle.
- Les minuteries (homme mort, plafond du jour) vivent dans le socle Lua de KumoMTA (K6), pas dans la passerelle. La passerelle reste donc un service requête/réponse, sans démon PHP de longue durée.

**Raisons.** Mêmes formats et même famille de relecteurs que le CRM. Le volume visé (300 à 10 000 par jour) est sans enjeu de performance.

**Alternatives écartées**
- Go : un seul binaire et une petite surface, mais un second langage, une seconde chaîne CI et d'autres relecteurs. À reconsidérer seulement si le PHP posait un problème mesuré.
- Un démon PHP de longue durée (ReactPHP, Swoole, RoadRunner) : une dépendance de plus et des fuites mémoire possibles.

**Risques**
- Dérive entre les deux implémentations HMAC. Parade : vecteurs communs obligatoires dans les deux CI.
- Configuration php-fpm ou nginx bavarde. Parade : `expose_php=Off`, aucune trace d'erreur en réponse, `server_tokens off` (test K2).

## D7. Transport CRM vers serveur d'envoi, sans tunnel sur la prod
**Décision.** Les TROIS canaux passent par HTTPS signé HMAC (spec 26 § 1.5), et toute connexion est OUVERTE PAR LE CRM.

| Canal | V0 retenue |
|---|---|
| Injection | `POST /v1/injections`. Le corps est un JSON : enveloppe, en-têtes internes, message RFC 5322 en base64. La passerelle injecte en SMTP AUTH vers KumoMTA écoutant sur `127.0.0.1:2525` (K4). Elle traduit la contre-pression : un 4xx devient `429 Retry-After`, un 5xx devient `422` avec le motif ; délai de 30 s, puis `etatEnvoi` côté CRM. |
| Commandes | `POST /v1/commandes/<operation>` avec `commande_id`. |
| Événements | **Tirage seul** : le CRM appelle `lireEvenements(curseur)` toutes les 10 s pendant la fenêtre d'envoi et toutes les 60 s le reste du temps. Il tire tout de suite en cas de trou. La réponse est SIGNÉE par la passerelle, et sa signature reprend le nonce de la requête. |

**Défenses en couches, sur le serveur d'envoi uniquement**
1. nftables : le port 443 de `<ip-gestion>` n'accepte que `<ip-crm-prod>` ; tout le reste est rejeté sans réponse. Le client du CRM force l'IPv4.
2. TLS 1.3 avec une autorité de certification privée : le CRM épingle l'AC et refuse les redirections.
3. TLS mutuel : certificat client du CRM.
4. HMAC-SHA256 : fenêtre de 5 min, nonce gardé 10 min et persistant, clé par espace et par serveur, comparaison en temps constant, secret vide = refus.
5. Pas de mandataire générique : seules les routes du contrat existent, l'API d'administration de KumoMTA reste en local avec `trusted_hosts` = localhost.

**Administration.** Par WireGuard entre le poste d'administration et le serveur d'envoi (jamais la prod). SSH n'est accessible que par ce tunnel. En cas d'urgence : console de l'hébergeur.

**Rien n'est fait sur la prod**
- Pas de tunnel, pas de règle de pare-feu, pas de nouvelle route entrante : le tirage seul évite d'exposer une route de poussée.
- Le CRM n'a besoin que de variables d'environnement : URL, secret HMAC, AC épinglée, certificat client. Elles sont posées par le canal de déploiement habituel du CRM, au moment de la bascule K17, avec le feu vert du responsable.
- La règle de pare-feu sortante « port 25 interdit » sur la prod reste recommandée. C'est une modification de la prod : **ESCALADE vers le responsable et eq-concepteur-devops, jamais sans accord écrit**. En attendant, le CRM n'a aucun transport SMTP de prospection configuré, et un test CI le vérifie.

**Raisons**
- Respect de la frontière « on ne touche jamais la prod ».
- Une seule pile d'authentification (HMAC), déjà présente dans le CRM (`Support/HmacSignature`).
- Le tirage seul supprime une surface d'attaque sur la prod sans perte : les événements sont gardés 30 jours et le curseur ne recule jamais.

**Alternatives écartées**
- Un tunnel WireGuard vers la prod : modification de la prod (ESCALADE).
- SMTP 2525 exposé sur Internet, même filtré : un port SMTP public de plus, et une seconde méthode d'authentification à durcir.
- La poussée vers une route publique du CRM : surface d'attaque sur la prod pour gagner quelques secondes.

**Risques**
- L'IP de sortie de la prod change, et tout est bloqué. Parade : K15 alerte en moins de 10 min, et l'homme mort met les files en pause (fail-safe).
- Certificat expiré. Parade : K15 surveille les échéances à 30, 14 et 7 jours.
- Charge du tirage : négligeable.
- Latence des retraits : inchangée (commande `retirer` directe).

## 7. Effets sur les lots (plan 33)
- **K1** : la route de réception des lots poussés est retirée. On ajoute :
  - le client HTTPS (TLS mutuel, épinglage, IPv4) ;
  - la vérification des réponses signées ;
  - le tirage planifié.

  Pas de table `envoi_commandes` : on réutilise `envoi_journal_commandes` de L0 et on lui ajoute `commande_id` UNIQUE, en ajout seulement. Durée inchangée : 2,5 j.
- **K3** : nftables. Sur l'IP de gestion : le port 443 depuis `<ip-crm-prod>` et le port UDP de WireGuard pour l'administration. Sur l'IP d'envoi : aucune entrée et une sortie limitée au port 25. Plus l'AC privée.
- **K4** : écoute sur `127.0.0.1:2525` au lieu de l'IP du tunnel, et une route d'injection HTTPS en plus. Découpé en K4a, K4b et K4c (section 8).
- **K5** : refus d'un message déjà signé, réglage `signature_dkim`.
- **K9** : injection par HTTPS (machine à états de L4 reprise telle quelle).
- **K10** : `wg-banc` est remplacé par `tls-banc` (AC privée et TLS mutuel générés à chaque démarrage).
- **K15** : la surveillance du « tunnel » devient la surveillance du lien HTTPS et des certificats.
- **Nouveau K19, pool rotatif** : 3,5 j, vague 3, obligatoire avant K17 (section 10).

## 8. Découpage de K4 (5 j) en trois fiches de 300 à 400 lignes

**K4a, écouteur et identité (1,5 j)**
- Écouteur KumoMTA sur `127.0.0.1:2525`, certificat de l'AC privée, STARTTLS obligatoire, TLS 1.2 au minimum.
- AUTH PLAIN seulement après TLS ; refus si `authz ≠ authc`.
- Mots de passe stockés en empreinte (fichier `0600`).
- L'espace est imposé par `authc` (`set_meta('tenant')`) ; les en-têtes `X-Tenant`, `X-Envoi-Source` et `X-Envoi-Jeton` sont retirés avant la sortie, après lecture.
- Fin de ligne sans CR refusée (anti « SMTP smuggling »).
- Tests : B1 à B4, plus les attaques de la section 11.

**K4b, anti-relais et registre (2 j)**
- Anti-relais en 4 couches : AUTH obligatoire ; `relay_hosts` = localhost ; écoute locale seulement ; un seul RCPT par message.
- Registre `MAIL FROM` par espace, lu dans la configuration K18 en lecture seule.
- Domaine principal d'Axion et ses sous-domaines refusés en dur, après normalisation : casse, point final, punycode et IDN.
- Alignement `From:` / `MAIL FROM`.
- Source `X-Envoi-Source` autorisée pour ce domaine et cet espace.
- Refus d'un `DKIM-Signature` déjà présent (D3) et des pièces jointes.
- Tests : B5 à B8, plus les attaques.

**K4c, route d'injection HTTPS (1,5 j)**
- `POST /v1/injections` dans la passerelle : HMAC, puis contrôle du schéma, puis SMTP local avec l'identité de l'espace tirée de la clé HMAC, jamais du corps.
- Traduction 4xx/5xx/délai ; jeton d'au moins 128 bits obligatoire ; taille maximale ; refus des CR/LF dans les champs d'enveloppe.
- Suite du kit de conformité « injection ».
- Dépend de K2 et de K4a.

## 9. Fichiers autorisés par lot
Les chemins sont proposés : chaque agent vérifie `origin/main` avant de créer. « + tests » signifie le dossier de tests du même composant. Fichiers partagés (`backend/routes/console.php`, `backend/config/envoi.php`, `backend/composer.json`) : un lot à la fois, avec rebase. Migrations : plage `2026_11_30_0001xx`, ajouts seulement, `workspace_id` et RLS.

| Lot | Fichiers autorisés |
|---|---|
| K0 | `docs/adr/ADR-<n>-serveur-envoi-propre.md` |
| K1 | `backend/app/Envoi/Contrat/{ServeurEnvoiCommandes,ServeurEnvoiConfiguration}.php`, `backend/app/Envoi/Contrat/Dto/*`, `backend/app/Envoi/Serveur/{ClientPasserelle,VerificateurReponse,TireurEvenements,ReconciliationNocturne,RegistreServeurs}.php`, `backend/app/Console/Commands/Envoi/{TirerEvenements,ReconcilierServeur}.php`, `backend/routes/console.php`, `backend/config/envoi.php`, migrations `envoi_serveurs` (avec `signature_dkim`) et `envoi_curseurs_serveur`, ajout de `commande_id` à `envoi_journal_commandes`, `backend/tests/{Unit,Feature}/Envoi/Serveur/*` |
| K2 | `envoi-serveur/passerelle/**` sauf `src/Injection/**`, `envoi-serveur/contrat/**` (schémas JSON, `vecteurs-hmac.json`), `envoi-serveur/systeme/nginx/**` |
| K3 | `envoi-serveur/systeme/{nftables,wireguard-admin,ssh,pki,systemd}/**`, `envoi-serveur/kumo/image.lock`, `envoi-serveur/kumo/policy/init.lua` (squelette, API d'administration locale), `envoi-serveur/compose.yaml`, `envoi-serveur/tests/sonde/**` |
| K4a | `envoi-serveur/kumo/policy/injection/{ecouteur,auth,espace,entetes}.lua`, `envoi-serveur/kumo/tests/injection/**` |
| K4b | `envoi-serveur/kumo/policy/injection/{relais,registre,source,contenu}.lua`, `envoi-serveur/kumo/tests/registre/**` |
| K4c | `envoi-serveur/passerelle/src/Injection/**`, `envoi-serveur/passerelle/tests/Injection/**`, `envoi-serveur/contrat/injection.schema.json` |
| K5 | `envoi-serveur/kumo/policy/dkim.lua`, `envoi-serveur/systeme/scripts/generer-dkim.sh`, côté CRM le composeur L2 (mode `serveur`) + tests |
| K6 | `envoi-serveur/kumo/policy/socle/{plafond,homme_mort,temoins,jetons,stockage}.lua`, `envoi-serveur/outils/temoins-off`, `envoi-serveur/kumo/tests/socle/**` |
| K7 | `envoi-serveur/kumo/policy/journal.lua`, `envoi-serveur/kumo/rebonds/*.toml`, `envoi-serveur/passerelle/src/Evenements/**` (ingestion du log hook) + tests, validateur TOML dans la CI |
| K8 | `envoi-serveur/kumo/shaping/**`, `backend/app/Envoi/Chauffe/**` (IP séparée), migration `ressource_type` |
| K9 | `backend/app/Envoi/Adaptateurs/ServeurEnvoiKumo.php` + tests |
| K10 | `envoi-serveur/banc/**`, workflow CI du banc |
| K11 à K16, K18 | Comme dans le plan 33, limités à `backend/app/Envoi/{Dns,Listes,Retours,Sante,Configuration}/**`, `envoi-serveur/runbook/**`, leurs migrations et tests |
| K19 | `backend/app/Envoi/Pool/**`, migrations `envoi_ips`, `envoi_pools`, `envoi_pool_membres` et `envoi_affectations`, `envoi-serveur/kumo/policy/injection/source.lua` (extension), `envoi-serveur/passerelle/src/Configuration/Pool*` + tests |

Gardes CI communes :
- Gitleaks ;
- un test qui refuse tout nom de domaine hors `.test` / `.example` et toute IP hors plages de documentation dans `envoi-serveur/` ;
- un test qui vérifie l'absence de clé privée DKIM côté CRM.

## 10. Pool d'envoi rotatif (exigence du 07/11)
**Modèle**
- `envoi_ips` : `ip_ref`, `serveur_id`, PTR attendu, état.
- `envoi_pools` : un ou plusieurs par espace.
- `envoi_pool_membres` : type `ip`, `domaine` ou `boite` ; état `actif`, `pause` ou `retire` ; plafond par jour ; date d'entrée.
- `envoi_affectations` : une inscription à une séquence correspond à UN triplet (domaine, IP, boîte), fixé au premier message.
- Rien n'est codé en dur. Ajouter un membre = une ligne, avec une chauffe qui démarre à la ligne 1 de la spec 26 § 4.2.

**Entrée en rotation.** Un couple (domaine, IP) n'entre en rotation que si le contrôle DNS du jour (K12) est vert POUR CE COUPLE : SPF du domaine contenant l'IP, PTR = HELO = A, DKIM et DMARC. Ajouter une IP oblige donc à mettre à jour d'abord le SPF de tous les domaines du pool. Le plan DNS est généré par K12.

**Répartition**
- Budget du jour d'un couple, par fournisseur = minimum de :
  - chauffe de l'IP, chauffe du domaine ;
  - plafond de l'IP, plafond du domaine, plafond de la boîte ;
  - plafond dur restant (socle).
- Les relances déjà engagées passent AVANT les nouveaux fils, sur leur couple affecté.
- Un nouveau fil va au couple qui a le plus de marge relative. En cas d'égalité, on départage par un hachage stable de l'identifiant d'inscription (résultat déterministe et testable).
- Un membre en pause ou freiné sort du tour tout seul ; ses relances attendent sa reprise.
- **Point de décision ouvert, à poser au responsable.** Le triplet est strict sur tout le fil. Si un membre est RETIRÉ (liste noire durable, perte du serveur), les étapes restantes du fil sont suspendues et affichées, jamais basculées en silence sur un autre domaine ou une autre IP. Proposer d'en faire une décision.

**Côté serveur**
- Le composeur pose `X-Envoi-Source: <ip_ref>`.
- K4b vérifie que cette source est autorisée pour ce domaine et cet espace (registre K18) et choisit la source de sortie de KumoMTA correspondante. L'en-tête est retiré avant la sortie.
- Plafond par IP dans le socle local, en second verrou.
- Un pool peut couvrir plusieurs serveurs : `RegistreServeurs` choisit l'adaptateur selon le `serveur_id` de l'IP affectée.

**Échelle**
- Paliers 0 et 1 : 1 à 3 membres.
- 10 000 par jour : 5 à 10 IP, chacune plafonnée vers 1 000 à 2 000 par jour (serveur dédié avec un bloc d'IP, ou plusieurs serveurs).
- Le plafond global dur reste inchangé sans écrit du responsable.

**Pourquoi avant K17.** L'affectation doit exister dès le premier fil. Sinon il faudrait rattraper des fils déjà en cours.

**Relecture.** eq-auditeur-securite et eq-concepteur-backend.

## 11. Attaques à tester AVANT d'écrire le code
Les tests sont écrits en premier et rouges ; chacun doit passer au vert.

**K1 (côté CRM)**
- Réponse non signée, signature fausse, `k=` inconnu, `s=` d'un autre serveur : rejet, rien n'est écrit.
- Réponse valide mais rejouée, liée à un autre nonce de requête : rejet.
- Horodatage à ±6 min : rejet.
- Secret vide ou absent : le client refuse de démarrer, aucun appel.
- Ancienne clé au-delà de 7 jours de rotation : rejet.
- Certificat différent de l'AC épinglée (attaque de l'intermédiaire), redirection 30x, IPv6 forcée : refus.
- URL du serveur modifiée en base par un administrateur du CRM (SSRF) : l'URL vient de la configuration d'environnement uniquement.
- Lot avec un trou, un doublon, du désordre ou une séquence qui RECULE : le curseur est monotone (`GREATEST`), un recul déclenche une alerte sans rien effacer.
- Événement dont l'`espace` n'est pas celui de la clé : rejet, isolement RLS prouvé.
- Adresse en clair dans un événement : rejet ou expurgation, jamais dans un journal.
- `livre` reçu après `rebond_dur` : aucun rétablissement.
- Plus de 500 événements, JSON très profond, nombres géants, corps de plusieurs Mo : rejet borné.
- Deux tireurs en même temps : verrou consultatif, aucun double comptage.
- Même `commande_id` avec un autre corps : refus et alerte.
- Redis redémarré : un rejeu reste sans effet.
- Aucun secret ni en-tête de signature dans les journaux.

**K2 (passerelle)**
- Source hors `<ip-crm-prod>` : rejet sans réponse (sonde externe).
- Sans certificat client, ou certificat d'une autre AC : la poignée de main TLS échoue.
- HMAC faux ou absent, fenêtre dépassée, nonce rejoué y compris après redémarrage (nonces persistants), `s=` incohérent.
- Corps modifié après signature, en-tête de signature en double, signature passée dans la requête de l'URL : refus.
- Clé de l'espace A et `espace` B dans le corps : l'espace vient de la clé.
- `commande_id` rejoué : même réponse sans refaire l'action ; même `commande_id` avec un autre corps : 409.
- Extinction du mode témoins ou modification du socle (plafond, registre, domaine principal) par commande ou par paquet : refus et alerte de dérive.
- Accès à `/api/admin/*` de KumoMTA, chemin avec `../`, méthode non prévue : 404 ou 405 (pas de mandataire générique).
- Injection SQL dans les paramètres : requêtes préparées.
- Corps trop gros, JSON en bombe, connexions lentes : limites nginx.
- Curseur négatif, énorme ou non entier : refus.
- Log hook joignable seulement depuis 127.0.0.1.
- Aucune trace d'erreur, aucune version, aucune adresse en clair dans les réponses.
- Arrêt brutal : séquence sans trou ni doublon.
- Disque plein : 503 sans corruption, puis alerte.
- Fichiers de secrets en `0600`, processus non root.

**K4 (injection)**
- AUTH avant STARTTLS, suppression de STARTTLS, TLS inférieur à 1.2 : refus.
- `authz ≠ authc`, mots de passe faux répétés : refus et ralentissement.
- `X-Tenant` d'un autre espace : en-tête retiré, l'espace est celui de `authc`.
- Le port 2525 n'est pas joignable de l'extérieur (sonde).
- Relais sans AUTH : refus.
- Plusieurs RCPT, routage source `@a:b@c`, astuce `%`, partie locale entre guillemets : refus.
- `MAIL FROM` hors registre ou appartenant à un autre espace : refus.
- Domaine principal en majuscules, avec point final, en punycode, en homoglyphe ou en sous-domaine profond : refus.
- `From:` non aligné : refus.
- `X-Envoi-Source` absent ou non autorisé : refus.
- `DKIM-Signature` déjà présente, pièce jointe, taille au-delà de la limite : refus.
- Fin de ligne sans CR, `\n.\n` (« SMTP smuggling »), BDAT non prévu : refus.
- Pour K4c : CR/LF dans les champs d'enveloppe, jeton de moins de 128 bits ou absent : refus. Un 4xx du moteur devient 429, jamais 5xx. Un délai dépassé donne 504, jamais une réinjection automatique.

**K6 (socle)**
- 50 injections en parallèle quand il reste 1 envoi au plafond : exactement une acceptée.
- Plafond à 23:59:59 et 00:00 (heure de Paris), changements d'heure de mars et d'octobre, horloge système reculée : le compteur ne repart pas à zéro.
- Redémarrage : le compteur survit.
- Fichier d'état supprimé ou illisible : FERMÉ par défaut (refus en 4xx, mode témoins remis en marche).
- S21 : arrêt brutal entre la fin de DATA et le 250 : zéro doublon, zéro perte.
- Même jeton rejoué : 250 avec le même identifiant, aucun envoi.
- Même jeton avec un autre contenu : aucun envoi et une alerte.
- Disque plein : 4xx.
- Extinction des témoins par la passerelle ou par un paquet : refus. Seule la commande locale le permet, et elle est journalisée.
- Destinataire hors liste blanche avec une casse différente : refus.
- Mode témoins actif puis redémarrage : il reste actif.
- Homme mort : 15 min sans `sante` signée donne une pause totale. Une `sante` forgée ne réarme rien. La reprise exige une commande `reprise`.
- Exception Lua : fermé par défaut, jamais « accepté ».

## 12. Plan en étapes vérifiables
| # | Étape | Preuve |
|---|---|---|
| 1 | Valider cet ADR (W4) et le faire relire par eq-auditeur-securite | Verdict écrit, ESCALADE D7 tranchée |
| 2 | Question écrite au support Hetzner (D1, D5) et, en parallèle, préparation du plan B Scaleway | Réponse écrite archivée |
| 3 | Mode `signature_dkim` dans L2 avant sa fusion | Test « aucune clé privée côté CRM » vert |
| 4 | Vague 1 : K1, K3, K10, K12, K13 | Sonde : aucun port hors 443 filtré et WireGuard d'administration ; S14 vert |
| 5 | Vague 2 : K2, K4a, K4b, K4c, K5, K6 | Attaques de la section 11 vertes, kit de conformité vert sur `mta-banc` |
| 6 | Jour 1 du serveur réel : sortie par l'IP d'envoi, PTR | En-tête `Received` d'un témoin |
| 7 | Vague 3 : K7, K8, K9, K11, K18, K19 | S01 à S22 verts, test de répartition déterministe |
| 8 | Vague 4 : K14 à K17 | Essai à blanc à 100 %, 3 jours de témoins verts, feu vert écrit |

## 13. Risques résiduels
- Délai ou refus du port 25 chez Hetzner : premier envoi probablement en janvier 2027 ou plus tard.
- Sortie impossible par l'IP flottante : écart D5.
- Changement de l'IP de sortie de la prod : arrêt fail-safe.
- Dérive HMAC entre CRM et passerelle : vecteurs communs.
- Compte Hetzner partagé avec la prod : risque d'abus.
- Retrait d'un membre au milieu d'un fil : suspension du fil.
- Dépôt public : fuite de marqueurs réels (Gitleaks et test des marqueurs).
- Escalade ouverte : D7, pare-feu port 25 sur la prod.

## Annexe A : passages de la spec 26 et du plan 33 à réécrire (lot de documentation)
1. Spec 26 § 1.1 : « trois canaux dans le tunnel WireGuard » devient « trois canaux en HTTPS signé HMAC, ouverts par le CRM » (D7).
2. Spec 26 § 2.1, ligne « Injection » : l'écouteur est en local, l'injection passe par la passerelle.
3. Plan 33 K1 : retirer la route de poussée ; `envoi_commandes` devient `envoi_journal_commandes.commande_id`.
4. Plan 33 K3 et K15 : WireGuard pour l'administration seulement ; surveiller le lien HTTPS et les certificats.
5. Plan 33 W4 et section 6 point 4 : il n'y a plus d'autorisation de tunnel sur la prod à demander. Seul reste le pare-feu port 25 sur la prod (ESCALADE).
