# Registre des violations de données personnelles — AXION IA SAS

> **Obligation** : article 33, paragraphe 5, du RGPD. Le responsable de
> traitement documente **toute** violation de données personnelles — les faits,
> ses effets, et les mesures prises pour y remédier — **qu'elle ait été notifiée
> à la CNIL ou non**.
>
> **Ce registre est interne.** Il ne s'envoie à personne. Il est tenu à
> disposition de la CNIL, qui peut le demander lors d'un contrôle. C'est lui qui
> permet de vérifier que l'obligation de documentation a été respectée.
>
> **Où le garder** : ce fichier, dans le dépôt, plus une copie hors dépôt si le
> dépôt devenait indisponible. Ne pas le supprimer : une violation consignée puis
> effacée est pire qu'une violation non consignée.
>
> **Responsable de traitement** : AXION IA SAS — SIREN 108 018 631 — 11 avenue
> Paul Verlaine, 38100 Grenoble. Aucun délégué à la protection des données
> désigné. Point de contact : contact@axion-ia.com.

---

## Table des entrées

| N° | Date de découverte | Objet | Notifiée à la CNIL ? |
|---|---|---|---|
| **2026-001** | 2026-08-19 | Base de données du CRM accessible depuis internet | **Non** — décision motivée, voir l'entrée |

---

## Entrée 2026-001

### 1. Nature de la violation

Violation de **confidentialité** et d'**intégrité** : le service de base de
données PostgreSQL du CRM (port 55432) et son cache Redis (port 56379) étaient
publiés sur l'adresse IP publique du serveur, et joignables depuis internet.

L'accès obtenu était de niveau **administrateur**, en lecture **et en écriture**,
sur l'intégralité de la base.

**Origine** : la configuration `docker-compose.yml`, prévue pour un poste de
développement, publiait ces ports ; elle a été appliquée telle quelle à
l'environnement de production.

**Pourquoi cela n'a pas été vu plus tôt** : le pare-feu (`ufw`) était configuré
pour n'autoriser que les ports 22, 80 et 443, et l'annonçait. Il était contourné :
Docker insère ses propres règles dans `iptables` **avant** celles d'`ufw`. Le
pare-feu affichait « fermé » pendant que le port répondait.

**Circonstance aggravante** : le mot de passe du compte PostgreSQL était écrit en
clair dans `docker-compose.yml`, fichier hébergé dans un dépôt de code **public**.
Le service Redis n'était protégé par aucun mot de passe.

### 2. Période concernée

| | |
|---|---|
| Début de l'exposition | **2026-05-17** — mise en service du serveur, mesurée (volume Postgres créé à 09:37 UTC, dossier de déploiement à 08:53 UTC, première fiche enregistrée à 18:16 UTC) |
| Fin de l'exposition | **2026-08-19** — confinement le matin même de la découverte |
| **Durée** | **94 jours** |

### 3. Découverte

**2026-08-19**, lors d'un audit interne de l'infrastructure mené en préparation
d'un environnement de préproduction. La faille n'était signalée par aucun outil
de surveillance ; un rapport interne antérieur (`_REPORTS/2026-08-18_ETAT-PARE-FEU.md`)
concluait que le pare-feu était en ordre — il l'était au niveau d'`ufw`, le trou
était en dessous.

L'exploitabilité a été **vérifiée en conditions réelles** depuis un poste
extérieur au serveur, par internet : la connexion a abouti.

### 4. Catégories et volumes de données concernées

Chiffres mesurés en base le 2026-08-19 :

| Donnée | Volume | Nature |
|---|---|---|
| `companies` | 4 295 349 | identification d'**entreprises** (dénomination, SIREN, adresse, secteur), issues de registres **publics** (INSEE/Sirene, BODACC, annuaires professionnels) |
| `contacts` | **1 319 567 personnes physiques** | coordonnées **professionnelles** : nom, prénom, fonction, e-mail professionnel, téléphone professionnel, profil LinkedIn public |
| `candidates` (vivier) | **0** | aucune candidature, aucun CV |
| `users` (comptes applicatifs) | 1 | le dirigeant. Mot de passe sous forme d'empreinte. ⚠️ Ce compte a servi le 2026-05-17 — voir §5 bis |
| sessions / jetons | **0 au 2026-08-19** | ⚠️ voir la rectification au §5 bis : cela ne signifie **pas** qu'aucune session n'a jamais existé |

**Catégories particulières (art. 9)** : aucune. Pas de données de santé,
d'opinions, de convictions, d'orientation, ni de données de mineurs.
**Données bancaires** : aucune.

**Personnes concernées : environ 1 320 000**, toutes en qualité de
professionnels, données collectées auprès de sources publiques.

### 5. Effets et conséquences

**Aucun effet constaté.** Aucune modification anormale des données, aucune
demande de rançon, aucune fuite observée, aucune réclamation reçue.

⚠️ **Il n'est pas possible de démontrer qu'aucun accès non autorisé n'a eu lieu** :
les journaux de connexion de PostgreSQL n'étaient pas conservés. L'absence
d'effet constaté n'est donc pas une preuve d'absence d'accès.

**Risque théorique principal** : réutilisation des coordonnées professionnelles
par un tiers à des fins de prospection non sollicitée.

### 5 bis. ⚠️ RECTIFICATION — une affirmation favorable de ce registre était fausse

**Portée le 2026-08-19, le jour même, avant tout usage de ce registre.**

La première rédaction affirmait qu'« aucun utilisateur ne s'était jamais
connecté ». C'était une **déduction**, tirée de deux compteurs à zéro
(`sessions` = 0, `personal_access_tokens` = 0) mesurés le 2026-08-19. Elle a été
**réfutée le même jour** par la table `users` :

```
première connexion  : 2026-05-17 12:18:43 UTC
parcours d'accueil terminé : 2026-05-17 12:33:15 UTC
```

Une session a donc bel et bien existé, **le jour même de la mise en service** —
c'est-à-dire à l'intérieur de la fenêtre d'exposition. Des compteurs à zéro au
19 août signifient que les sessions avaient **expiré**, pas qu'il n'y en a jamais
eu.

**Conséquence sur l'analyse du risque.** Redis était joignable depuis internet
**et dépourvu de tout mot de passe** ; c'est lui qui stocke les sessions
(`SESSION_DRIVER=redis`). Le vol d'un jeton de session pendant la fenêtre
**ne peut donc pas être exclu**.

**Portée réelle de ce risque, sans le minimiser ni l'exagérer** : la session
concernée est celle de **l'unique compte administrateur** (le dirigeant), et non
celles des personnes dont les données figurent dans la base — aucune personne
concernée n'a de compte sur cet outil. Le risque porte donc sur l'**accès à
l'outil**, non sur un identifiant appartenant à l'une des 1,32 million de
personnes. Il ne modifie pas l'appréciation du risque **pour les droits et
libertés des personnes concernées**, qui est l'objet des articles 33 et 34.

**Observation associée** : l'authentification à deux facteurs de ce compte est
**désactivée** (`totp_enabled_at` = null au 2026-08-19). Pour le compte qui
détient 1,32 million de fiches, c'est une faiblesse à traiter, indépendamment de
la présente violation.

**Pourquoi cette rectification figure ici plutôt que d'être une correction
silencieuse** : un registre qui contient une affirmation favorable et fausse perd
toute valeur devant un contrôle. Mieux vaut une erreur corrigée et datée qu'une
affirmation confortable jamais vérifiée. La cause de l'erreur est identifiable et
générale : *un compteur à zéro décrit un instant, jamais une histoire.*

### 6. Mesures prises

Toutes datées du **2026-08-19**, toutes vérifiées :

1. **Confinement immédiat** — règles `iptables` (chaîne `DOCKER-USER`) bloquant
   tout accès externe aux ports 5432 et 6379, sans interruption de service.
   Vérifié depuis un poste extérieur : la connexion qui aboutissait expire.
2. **Persistance** — règles rendues permanentes au redémarrage
   (`iptables-persistent`), puis re-sauvegardées sur l'état propre après la
   mesure 3.
3. **Correctif de fond** — suppression de la publication des ports dans la
   configuration de production (`ports: !override []`), puis recréation des
   conteneurs concernés. Vérifié : les redirections réseau ont disparu de la
   table `nat`, les ports ne répondent plus depuis internet.
4. **Contrôle automatique de non-régression** — `infra/scripts/verifier-ports-publies.sh`,
   qui mesure les **conteneurs en fonctionnement** et non la seule configuration.
   Cette distinction n'est pas théorique : le correctif 3, une fois fusionné et
   déployé avec succès, **n'avait rien fermé** — le déploiement ne recrée pas les
   conteneurs de base de données. Sans ce contrôle, l'illusion aurait tenu.
5. **Environnement de préproduction** mis en service le même jour, rempli de
   données **synthétiques**, afin que les essais ne se fassent plus au contact
   des données réelles.

**Mesure examinée et écartée** :

6. **Rotation des identifiants de la base** — écartée par le dirigeant le
   2026-08-19. Motif retenu : depuis la fermeture, le service n'est plus
   joignable que depuis le réseau interne des conteneurs ; l'identifiant publié
   n'a plus d'utilité pour un tiers. **Risque résiduel assumé** : une
   republication accidentelle du port rouvrirait l'accès avec un mot de passe
   connu — risque couvert par le contrôle automatique du point 4.

**Mesure identifiée, non encore réalisée** :

7. **Journalisation des connexions à la base**, afin qu'un incident futur soit
   analysable. C'est l'absence de cette journalisation qui empêche aujourd'hui de
   conclure sur l'existence d'accès non autorisés.

### 7. Notification à la CNIL — décision et motivation

> **Décision du responsable de traitement, 2026-08-19 : NE PAS NOTIFIER.**
> Prise par Williams Jullin, président d'AXION IA SAS, en connaissance des
> éléments ci-dessus.

L'article 33-1 dispense de notification lorsque la violation « n'est pas
susceptible d'engendrer un risque pour les droits et libertés des personnes
physiques ».

**Éléments retenus au soutien de cette appréciation :**

- les données concernées sont de nature **exclusivement professionnelle** — nom,
  fonction, e-mail et téléphone professionnels — et proviennent de **sources
  publiques** (registres légaux, annuaires professionnels) : leur divulgation
  n'ajoute que marginalement à ce qui est déjà librement accessible ;
- **aucune catégorie particulière** de données au sens de l'article 9 ;
- **aucune donnée bancaire ou financière** ;
- **aucune donnée de candidature**, aucun CV ;
- **aucun identifiant de connexion appartenant à une personne concernée** :
  aucune des 1,32 million de personnes n'a de compte sur cet outil. ⚠️ Une
  session du compte administrateur a existé pendant la fenêtre et son vol ne peut
  être exclu (§5 bis) — cela concerne l'accès à l'outil, non un identifiant
  d'une personne concernée ;
- **aucun effet constaté** : ni modification anormale, ni rançon, ni fuite, ni
  réclamation ;
- la violation a été **corrigée le jour même de sa découverte**, et une garde
  automatique empêche sa réapparition.

**Éléments contraires, examinés et connus au moment de la décision :**

- le **volume** est élevé : environ 1 320 000 personnes ;
- l'accès possible était **en écriture**, ce qui touche l'intégrité et pas
  seulement la confidentialité ;
- les identifiants étaient **publiés** dans un dépôt public ;
- **l'absence d'accès non autorisé ne peut pas être démontrée** faute de
  journalisation ;
- une **session administrateur** a existé dans la fenêtre, sur un Redis sans mot
  de passe et joignable depuis internet (§5 bis).

**Information des personnes concernées (article 34)** : non. Cette obligation ne
s'applique qu'en cas de risque **élevé**, non retenu ici.

⚠️ **À savoir** : si un élément nouveau apparaissait — indice d'exploitation,
réclamation d'une personne concernée, diffusion constatée des données — cette
appréciation devrait être **réexaminée**, et la notification faite sans délai en
justifiant le décalage. La présente entrée sera alors complétée, jamais réécrite.

**Analyse détaillée conservée** :
`_REPORTS/2026-08-19_BROUILLON-NOTIFICATION-CNIL-ART33.md` — document rédigé en
vue d'une notification finalement non retenue. Il est conservé : il établit que
la question a été instruite avec les chiffres réels avant d'être tranchée.

### 8. Références internes

- `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md` §5 — découverte, preuve, mesures
- §7 — le correctif déployé qui n'avait rien fermé
- §10.10 et §10.11 — volumes réels mesurés en base
- §11 — la préproduction et les gardes posées

---

*Entrée close le 2026-08-19. Toute évolution s'ajoute, ne remplace pas.*
