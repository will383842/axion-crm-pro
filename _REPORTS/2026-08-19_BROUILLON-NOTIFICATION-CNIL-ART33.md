# BROUILLON — Notification de violation de données à la CNIL (article 33 RGPD)

> ⚠️ **CE DOCUMENT EST UN BROUILLON.** Il est rédigé par l'autopilote à partir de
> faits mesurés et datés. **Il n'est ni validé ni envoyé.** La décision de
> notifier, la validation du contenu et l'envoi reviennent au dirigeant.
>
> **Où l'envoyer** : téléservice CNIL — `notifications.cnil.fr`, rubrique
> « Notifier une violation de données personnelles ». Compte requis.
>
> **Délai** : 72 h à compter de la **connaissance** de la violation. Connaissance
> établie le **2026-08-19**. Au-delà, le formulaire demande de justifier le
> retard — ce qui se fait, mais se motive.
>
> ⚠️ Une notification peut être faite **de façon échelonnée** (art. 33-4) quand
> tout n'est pas encore connu. C'est le cas ici : la durée d'exposition et
> l'existence d'accès non autorisés ne sont **pas** établissables.

---

## 1. Identité du responsable de traitement

| Champ | À compléter par Will |
|---|---|
| Raison sociale | *(Axion-IA OÜ — à confirmer : c'est l'entité qui exploite le CRM)* |
| N° SIREN / immatriculation | *(à compléter)* |
| Adresse | *(à compléter)* |
| Délégué à la protection des données | *(à préciser : DPO désigné ou non)* |
| Contact pour cette notification | *(nom, fonction, e-mail, téléphone)* |

---

## 2. Nature de la violation

**Type** : violation de **confidentialité** et d'**intégrité** (accès en lecture
et en écriture possible), par exposition d'un service de base de données sur
internet.

**Description factuelle** :

Le serveur hébergeant l'application « Axion CRM Pro » publiait, sur son adresse
IP publique, les ports de sa base de données PostgreSQL (port 55432) et de son
cache Redis (port 56379). Cette publication provenait de la configuration
`docker-compose.yml`, prévue pour le confort d'un poste de développement et
appliquée par erreur à l'environnement de production.

Le pare-feu applicatif (`ufw`) était configuré pour n'autoriser que les ports 22,
80 et 443. Il était toutefois **contourné** : Docker insère ses propres règles
dans `iptables` **avant** celles d'`ufw`. Le pare-feu annonçait donc « fermé »
pendant que le port répondait depuis internet. Ce comportement est un piège
documenté de Docker, mais il n'avait pas été identifié sur cette installation.

**Aggravant** : le mot de passe du compte PostgreSQL était inscrit **en clair**
dans le fichier `docker-compose.yml`, lui-même hébergé dans un dépôt de code
**public**. Le service Redis n'était protégé par aucun mot de passe.

**Vérification** : la connexion a été **effectivement établie depuis un poste
extérieur au serveur, par internet**, le 2026-08-19, dans le cadre d'un audit
interne. Elle donnait un accès de niveau administrateur, en lecture et en
écriture, à l'intégralité de la base.

---

## 3. Chronologie

| Date | Événement |
|---|---|
| *(à compléter — mise en service du serveur)* | Début probable de l'exposition. La configuration fautive est présente depuis l'origine. |
| **2026-08-19, matin** | Découverte lors d'un audit interne de l'infrastructure, en préparation d'un environnement de préproduction. |
| **2026-08-19, matin** | **Confinement immédiat** : règles `iptables` (chaîne `DOCKER-USER`) bloquant tout accès externe aux ports 5432 et 6379, sans interruption de service. Vérifié depuis l'extérieur. |
| **2026-08-19** | Règles rendues **persistantes** au redémarrage (`iptables-persistent`). |
| **2026-08-19** | **Correctif de fond** déployé : suppression de la publication des ports à la source, puis recréation des conteneurs concernés. Vérifié : les redirections réseau ont disparu, les ports ne répondent plus depuis internet. |
| **2026-08-19** | Mise en place d'un **contrôle automatique** vérifiant, à chaque déploiement, qu'aucun port autre que 80 et 443 n'est exposé. |

⚠️ **Durée exacte de l'exposition : non établissable.** La configuration fautive
est présente depuis la mise en service. Les journaux de connexion de PostgreSQL
n'étant pas conservés, il n'est **pas possible de démontrer** qu'aucun accès non
autorisé n'a eu lieu. Cette incertitude est signalée en toute transparence ; elle
est la raison principale de la présente notification.

---

## 4. Catégories et volume de données concernées

Chiffres **mesurés en base** le 2026-08-19 :

| Table | Volume | Nature des données |
|---|---|---|
| `companies` | **4 295 349** | Données d'identification d'**entreprises** (dénomination, SIREN, adresse, secteur), issues de **registres publics** (INSEE / Sirene, BODACC, annuaires professionnels). |
| `contacts` | **1 319 567 personnes physiques** | Coordonnées **professionnelles** : nom, prénom, fonction, adresse e-mail professionnelle, téléphone professionnel, profil LinkedIn public. Collectées dans le cadre d'une prospection B2B sur données publiques. |
| `candidates` (vivier de recrutement) | **0** | ✅ **Aucune donnée de candidature n'était présente.** |
| `users` (comptes applicatifs) | **1** | Mot de passe stocké sous forme d'empreinte. Ce compte n'a jamais été utilisé. |
| Sessions et jetons d'authentification | **0** | ✅ Aucun jeton de session n'existait : aucun utilisateur ne s'est jamais connecté à cet environnement. |

**Catégories particulières de données (art. 9)** : **aucune**. Pas de données de
santé, d'opinions, d'orientation, ni de données relatives à des mineurs.

**Données bancaires ou financières** : **aucune**.

**Documents de candidature (CV, lettres)** : **aucun** dans cet environnement.

**Nombre de personnes concernées : environ 1 320 000**, toutes en qualité de
**professionnels** (représentants ou salariés d'entreprises), les données ayant
été collectées auprès de sources publiques.

---

## 5. Conséquences probables

**Évaluation du risque : limité, mais non nul.**

Éléments **réduisant** le risque :
- les données sont de nature **professionnelle** et proviennent de **sources
  publiques** (registres légaux, annuaires professionnels) : leur divulgation
  n'ajoute que marginalement à ce qui est déjà accessible ;
- **aucune catégorie particulière** de données, aucune donnée bancaire, aucun
  document de candidature ;
- **aucun identifiant de connexion** d'une personne concernée n'était exposé
  (aucune session, aucun jeton, un seul compte applicatif jamais utilisé) ;
- aucun élément ne laisse penser qu'un accès non autorisé ait eu lieu (pas de
  modification anormale constatée, pas de rançon, pas de fuite observée).

Éléments **aggravant** le risque :
- **volume élevé** : environ 1,32 million de personnes ;
- l'accès possible était de niveau **administrateur, en écriture** : au-delà de
  la confidentialité, l'**intégrité** des données pouvait être altérée ;
- les identifiants d'accès étaient **publiés** dans un dépôt public, donc
  accessibles sans effort particulier ;
- **l'absence d'accès non autorisé ne peut pas être démontrée**, faute de
  journalisation des connexions.

**Risque principal identifié** : réutilisation des coordonnées professionnelles à
des fins de prospection non sollicitée par un tiers.

---

## 6. Mesures prises et prévues

**Prises immédiatement (2026-08-19)** :
1. blocage des accès externes aux ports de base de données au niveau du
   pare-feu, sans interruption de service, vérifié depuis l'extérieur ;
2. persistance de ce blocage au redémarrage du serveur ;
3. suppression de la publication des ports dans la configuration, puis
   recréation des conteneurs concernés — vérifié : plus aucune redirection ;
4. mise en place d'un contrôle automatique de non-régression, vérifiant l'état
   **réel** des conteneurs et non la seule configuration.

**Décidées** :
5. journalisation des connexions à la base, afin qu'un incident futur soit
   analysable *(à confirmer par Will — non fait à ce jour)* ;
6. environnement de préproduction distinct, rempli de données **synthétiques**,
   afin que les essais ne se fassent plus au contact des données réelles *(mis
   en service le 2026-08-19)*.

**Écartée, en connaissance de cause** :
7. **rotation des identifiants de la base** : le dirigeant a décidé de ne pas y
   procéder. Motif : depuis la fermeture, le service n'est plus joignable que
   depuis le réseau interne des conteneurs, l'identifiant publié ne présente donc
   plus d'utilité pour un tiers. Le risque résiduel — une republication
   accidentelle du port — est couvert par le contrôle automatique mentionné au
   point 4.

*(⚠️ Ce point 7 doit être mentionné en toute transparence : une mesure écartée et
motivée est mieux reçue qu'une mesure passée sous silence. Will reste libre de
revenir sur cette décision avant l'envoi.)*

---

## 7. Information des personnes concernées (article 34)

**Position proposée : ne pas procéder à une information individuelle.**

Motivation : l'article 34 ne l'impose qu'en cas de risque **élevé** pour les
droits et libertés. Les données concernées sont des coordonnées
**professionnelles** issues de **sources publiques**, sans catégorie
particulière, sans donnée financière et sans identifiant de connexion. Le risque,
bien que réel, n'atteint pas ce seuil.

En outre, l'information individuelle de 1,32 million de personnes dont l'adresse
a été collectée en prospection constituerait elle-même un envoi de masse non
sollicité — un remède disproportionné au regard du risque.

⚠️ **Cette appréciation appartient au responsable de traitement.** La CNIL peut,
après examen, demander que les personnes soient informées.

---

## 8. Inscription au registre des violations (article 33-5)

Cette violation doit être consignée au **registre interne des violations**,
**qu'elle soit notifiée ou non**. Cette obligation est indépendante de la
décision de notifier.

Éléments à y porter : les faits du §2, la chronologie du §3, les volumes du §4,
l'évaluation du §5, les mesures du §6, et la décision prise au §7 avec sa
motivation.

---

## 9. Sources des faits énoncés

Tous les éléments chiffrés de ce document sont issus de mesures datées, non de
déclarations :

- journal de réalisation `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md`, §5 (découverte
  et preuve), §7 (le correctif déployé qui n'avait pas pris effet), §10.10 et
  §10.11 (volumes réels mesurés en base) ;
- volumes obtenus par interrogation directe de la base de production le
  2026-08-19 ;
- vérifications de fermeture rejouées depuis un poste extérieur au serveur.
