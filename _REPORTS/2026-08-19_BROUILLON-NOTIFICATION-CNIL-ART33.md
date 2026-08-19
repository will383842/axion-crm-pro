# BROUILLON — Notification de violation de données à la CNIL (article 33 RGPD)

> ✅ **DÉCISION PRISE PAR LE DIRIGEANT LE 2026-08-19 : NOTIFIER.**
> Williams Jullin a validé la recommandation de notifier la CNIL au titre de
> l'article 33, et de **ne pas** informer individuellement les personnes
> concernées (article 34). Il a également confirmé que **AXION IA SAS est bien
> l'entité qui exploite le serveur du CRM** — la dernière incertitude du §1 est
> donc levée.
>
> ⚠️ **CE DOCUMENT RESTE UN BROUILLON À SAISIR.** Il est rédigé par l'autopilote
> à partir de faits mesurés et datés. **L'envoi n'a pas eu lieu** : la saisie du
> formulaire et sa transmission sont un acte du responsable de traitement, qui
> exige un compte personnel sur le téléservice. L'autopilote rédige, il ne
> signe pas.
>
> **Où l'envoyer** : téléservice CNIL — `notifications.cnil.fr`, rubrique
> « Notifier une violation de données personnelles ». Compte requis.
>
> **Délai** : 72 h à compter de la **connaissance** de la violation. Connaissance
> établie le **2026-08-19**. Au-delà, le formulaire demande de justifier le
> retard — ce qui se fait, mais se motive.
>
> ⚠️ Une notification peut être faite **de façon échelonnée** (art. 33-4) quand
> tout n'est pas encore connu. Ici la durée d'exposition **est** établie
> (94 jours, mesurée), mais l'existence ou non d'accès non autorisés ne l'est
> **pas** — les journaux de connexion n'étaient pas conservés.

---

## 1. Identité du responsable de traitement

> ✅ **Renseigné le 2026-08-19** — relevé sur les mentions légales publiées
> (`https://axion-ia.com/fr/mentions-legales`), pas demandé ni supposé.

| Champ | Valeur |
|---|---|
| Raison sociale | **AXION IA SAS** — société par actions simplifiée, capital 1 000 € |
| SIREN | **108 018 631** |
| SIRET (siège) | **108 018 631 00011** |
| TVA intracommunautaire | **FR51108018631** |
| RCS | **Grenoble** |
| Siège social | ELITE BUREAUX — boîte 53, 11 avenue Paul Verlaine, 38100 Grenoble |
| Directeur de la publication | **Williams Jullin** |
| Contact | contact@axion-ia.com |
| Délégué à la protection des données | **Aucun DPO désigné** — sa désignation n'est pas obligatoire au regard de l'activité (position déjà publiée dans les mentions légales). Le formulaire CNIL accepte l'absence de DPO ; c'est alors le représentant légal qui est l'interlocuteur. |
| Interlocuteur pour cette notification | Williams Jullin, président — **contact@axion-ia.com** |

✅ **Confirmé par le dirigeant le 2026-08-19** : c'est bien **AXION IA SAS** qui
exploite le serveur du CRM. Aucune autre entité n'est concernée.

### ☎️ Pas de numéro de téléphone — et le droit ne l'exige pas

Décision du dirigeant : **aucun numéro de téléphone ne sera communiqué.** Cette
décision est conforme.

L'**article 33-3-b du RGPD** exige de « communiquer le **nom et les coordonnées**
du délégué à la protection des données ou d'un autre point de contact ». Le terme
est **« coordonnées »** — une adresse électronique en est une. Le règlement
n'impose aucun canal particulier, et la page de la CNIL consacrée à la
notification ne cite pas le téléphone parmi les informations obligatoires (elle
liste : nature de la violation, nombre et catégories de personnes concernées,
nombre et catégories d'enregistrements, conséquences probables, mesures prises).

**Point de contact retenu : `contact@axion-ia.com`.**

Si le téléservice marque le champ d'un astérisque, il s'agit d'une contrainte du
formulaire et non du droit : porter « non communiqué » suffit. Ce point ne doit
en aucun cas retarder la notification — le délai, lui, est réel.

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
| **2026-05-17** | **Mise en service du serveur** — début de l'exposition. Mesuré, non supposé : volume Postgres créé à 09:37 UTC, dossier de déploiement à 08:53 UTC, première fiche enregistrée à 18:16 UTC. La configuration fautive est présente depuis l'origine. |
| **2026-08-19, matin** | Découverte lors d'un audit interne de l'infrastructure, en préparation d'un environnement de préproduction. |
| **2026-08-19, matin** | **Confinement immédiat** : règles `iptables` (chaîne `DOCKER-USER`) bloquant tout accès externe aux ports 5432 et 6379, sans interruption de service. Vérifié depuis l'extérieur. |
| **2026-08-19** | Règles rendues **persistantes** au redémarrage (`iptables-persistent`). |
| **2026-08-19** | **Correctif de fond** déployé : suppression de la publication des ports à la source, puis recréation des conteneurs concernés. Vérifié : les redirections réseau ont disparu, les ports ne répondent plus depuis internet. |
| **2026-08-19** | Mise en place d'un **contrôle automatique** vérifiant, à chaque déploiement, qu'aucun port autre que 80 et 443 n'est exposé. |

**Durée de l'exposition : 94 jours**, du 2026-05-17 au 2026-08-19. Les journaux de connexion de PostgreSQL
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
   analysable — **non fait à ce jour**, à décider ;
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

✅ **Position retenue par le dirigeant le 2026-08-19** : pas d'information
individuelle. ⚠️ La CNIL peut, après examen, demander que les personnes soient
informées — cette possibilité reste ouverte quelle que soit la position initiale.

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
