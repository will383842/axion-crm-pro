# ANALYSE — notification CNIL (article 33) : instruite, puis NON RETENUE

> 🛑 **DÉCISION DU RESPONSABLE DE TRAITEMENT, 2026-08-19 : NE PAS NOTIFIER.**
> Prise par Williams Jullin, président d'AXION IA SAS, après examen des éléments
> ci-dessous. **Aucune notification n'a été envoyée. Aucun formulaire n'a été
> saisi. Rien n'a quitté l'entreprise.**
>
> ⚠️ **Une version antérieure de ce document annonçait la décision inverse.**
> L'accord donné reposait sur un malentendu sur la nature de l'acte ; il a été
> repris et clarifié le jour même. C'est la présente version qui fait foi.
>
> **Pourquoi ce document est conservé, alors que la notification n'a pas lieu.**
> L'article 33-5 impose de documenter toute violation, notifiée ou non. Le
> document qui satisfait cette obligation est le **registre** :
> `_REPORTS/REGISTRE-DES-VIOLATIONS-DE-DONNEES.md`, entrée **2026-001**, qui
> porte la décision et sa motivation.
>
> Celui-ci en est la pièce d'instruction : il établit que la question a été
> examinée **avec les chiffres réels**, et non ignorée. Devant un contrôle, un
> dossier qui montre l'analyse vaut mieux qu'un silence. **Ne pas le supprimer.**
>
> Tout ce qui suit reste exact sur les faits. Seule la conclusion a changé : les
> §7 et §8 portent la décision définitive.

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

## 7. Décision définitive

🛑 **NE PAS NOTIFIER la CNIL.** Décision de Williams Jullin, président d'AXION IA
SAS, le 2026-08-19.

🛑 **NE PAS informer individuellement les personnes concernées** (article 34).
Cette obligation ne s'applique qu'en cas de risque **élevé**, non retenu ici.

La motivation complète — éléments retenus **et** éléments contraires examinés —
figure au §7 de l'entrée **2026-001** du registre des violations.

⚠️ **Cette appréciation devra être réexaminée** si un élément nouveau apparaît :
indice d'exploitation, réclamation d'une personne concernée, diffusion constatée
des données. Dans ce cas la notification se fait sans délai, en justifiant le
décalage.

---

## 8. L'obligation qui demeure, et qui est remplie

L'article 33-5 impose de consigner toute violation **qu'elle soit notifiée ou
non**. Cette obligation est **indépendante** de la décision du §7, et elle est
remplie :

📕 **`_REPORTS/REGISTRE-DES-VIOLATIONS-DE-DONNEES.md`** — entrée **2026-001**.

Ce registre est interne : il ne s'envoie à personne, et la CNIL peut le demander
lors d'un contrôle. **Ne pas le supprimer** — une violation consignée puis
effacée est pire qu'une violation non consignée.

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
