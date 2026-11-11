# Envoi K11 — rebonds et plaintes par la réception D2

Extension de la relève IMAP (L6, `docs/envoi/releve-imap.md`). Aucun envoi. Une seule migration, en ajout seul : index `(workspace_id, id_injection)` sur `envoi_messages_envoyes` (rattachement par VERP).

- **VERP** : `rb-<jeton>@bounce.<domaine>` (fourre-tout). Jeton = id d'injection (32 hex) + 10 hex HMAC (`envoi.cle_empreinte`). Jeton falsifié, autre clé ou clé vide : aucun rattachement (`Verp`).
- **ARF** (JMRP, Yahoo CFL) : `AnalyseArf` lit Feedback-Type, Original-Rcpt-To, Original-Mail-From (VERP) et le Message-ID cité. `abuse`/`fraud`/`virus` = plainte ; `not-spam`/`auth-failure` = sans effet ; tout autre type (`other`, inconnu, illisible) n'est JAMAIS une plainte comptée (voir ci-dessous). L'expéditeur du rapport (le fournisseur) n'est jamais opposé.
- **abuse@** : un message reçu sur `abuse@<un de NOS domaines d'envoi>` (table `envoi_domaines` de l'espace, sous-domaines inclus, ex. `bounce.<domaine>`) est classé plainte (sauf auto-réponse déclarée). `abuse@` d'un autre domaine : traité comme un message ordinaire.
- **Rattachement** : *rebonds et réponses* : VERP signé d'abord, puis Message-ID. *Rapport ARF* : **VERP signé SEUL** — le Message-ID qu'il cite, In-Reply-To et References ne rattachent jamais. Sans VERP signé, ou si Original-Rcpt-To ≠ adresse écrite, ou si Feedback-Type est illisible (type `illisible`, plus jamais « other » : `other` n'est pas déduit d'un rapport illisible ; un `other` écrit dans le rapport est un type inconnu comme un autre). **Feedback-Type inconnu ou absent = règle par défaut : `illisible`**, donc « à rattacher », aucune plainte devinée. Idem si l'analyse du rapport ARF échoue (UTF-8 invalide, limite PCRE) : type `illisible` forcé, jamais de « STOP par prudence » via In-Reply-To/References. Ces rapports sont classés `auto_reponse` en base (sans effet) ; un libellé distinct en file de revue exigerait une nouvelle classe, donc l'élargissement de la contrainte CHECK de `envoi_evenements` : reporté au lot L8 : file « à rattacher », **jamais d'opposition automatique** (revue humaine, lot L8). Un VERP d'un autre espace n'est pas retrouvé : « à rattacher ».
- **Effet d'une plainte** : opposition + liste de suppression « plainte » via `crm:campagne:retours`. Le contact n'est ni supprimé ni modifié : il est écarté des envois, ses données restent.
- **Plaintes et freins** : une plainte rattachée (ARF, réponse hostile, `abuse@`) compte pour les freins de L7 (`RecepteurRetoursFreins` → `CompteurRetours`) : la 1re sur 7 jours = palier tenu + relecture ; la 2e = arrêt de 48 h. **Attention** : la pause L7 couvre tout l'espace, ce qui est plus strict que l'« arrêt 48 h du domaine » du plan 26 (le test S10 vérifie `dureeHeures`). Le comptage est **par espace** (voir « Décision en attente »).
- **Rejeu** : clé (boîte, UIDVALIDITY, UID) + empreinte du message ; une renumération ne recrée ni événement ni effet.

Scénarios du banc (plan 26-SPEC-CONTRAT-ENVOI-ET-BANC l.257-261, `tests/Feature/Envoi/RebondsPlaintesK11Test.php`) : S09 rebond asynchrone par VERP · S10 ARF + réponse hostile + `abuse@` (3 plaintes comptées, 1re = palier tenu, 2e en 7 jours = arrêt 48 h) · S11 réponse humaine (« Réponses ») · S12 STOP (opposition) · S13 absence du bureau (auto-réponse, aucune opposition).

Limite : K11 classe, rattache et TRANSMET le retour à la chaîne de récepteurs ; S11 (arrêt de séquence) est branché depuis M3, S13 (pause) ne l'est pas. Voir la dette nommée « Lot L1-RETOURS-SEQ » ci-dessous.

## Décision en attente (plan 30, B5)

Une plainte bloque-t-elle tous les clients ou seulement l'espace concerné ? **Décision du responsable du projet en attente — non tranchée ici.** Comportement par défaut : blocage LIMITÉ à l'espace concerné (freins et VERP filtrés par espace, testé).

## Périmètre

K11 touche `Envoi/Imap/**` : c'est une extension de L6 (relève IMAP).

## Suite (types ARF, ambiguïté)

- ARF : seuls `abuse`, `fraud`, `virus` sont des plaintes. `not-spam` et `auth-failure` sont mémorisés (rattachés) mais NON transmis (ni plainte, ni auto-réponse). Tout autre type (`other`, inconnu, illisible) : « à rattacher », jamais compté.
- `retrouverParInjection` ne prend plus la première ligne : si l'injection a plusieurs destinataires différents, résultat ambigu => « à rattacher ». Plusieurs lignes du même destinataire ne sont pas une ambiguïté.
- **Alerte** : un rapport ARF de type `other`, inconnu ou illisible qui porte un VERP signé valide (preuve qu'il vise un de nos envois) lève `Log::alert('envoi.arf_type_inconnu_verp_valide')` (espace, id d'injection, type tronqué ; jamais l'adresse). Aucune opposition automatique, aucun effet : revue humaine. Sans VERP signé (ou VERP falsifié) : ni alerte ni effet.
- Décision B5 (plainte = tous les clients ou l'espace seul) : NON tranchée ; comportement par défaut = l'espace concerné.

## Dette connue

- **Lot L1-RETOURS-SEQ (S11 réponse humaine, S13 absence du bureau)** — état après M3 :
  - **S11 réponse humaine : FAIT.** `RecepteurRetoursSequence` (3e maillon de la chaîne) arrête l'inscription du destinataire (motif `reponse`, via `Inscriptions::evenement`, donc aussi les autres inscriptions de la même entreprise, comme le séquenceur le prévoit). Rattachement exact : injection du retour → `envoi_messages.id_injection` → inscription. Une inscription arrêtée n'est plus tirée par le séquenceur (il ne traite que `statut = 'active'`). Idempotent, ajout seul, aucun envoi.
  - **S13 absence du bureau : PAS de pause, NON fait.** L'état « en pause » n'existe pas : la contrainte de `envoi_inscriptions.statut` n'admet que `active`, `terminee`, `arretee`. L'ajouter demande une migration (élargir la contrainte) et un contrôle côté tirage ; ce n'est pas inventé ici. Une auto-réponse est donc classée, rattachée et journalisée, sans aucun effet sur la séquence (ni arrêt, ni relance retardée). Reste à faire : lot dédié (état `en_pause` + reprise à la date de retour + test « relance échue sur séquence en pause => non tirée »). Décision à prendre par le responsable du projet : une absence retarde-t-elle la relance, ou la laisse-t-on partir ?
  - Le séquenceur lui-même n'est pas branché au planificateur ici (hors périmètre M3).

- **Rotation de `envoi.cle_empreinte`** : elle invalide tous les VERP déjà émis (leurs retours tombent en « à rattacher »). Garder l'ancienne clé quelques jours, ou accepter la revue humaine.
- `Verp::adressePour` exige désormais un UUID (`InvalidArgumentException` sinon).
- **`Verp::adressePour` n'est appelé nulle part en production** : seul le décodage (`idInjectionDepuis`) est branché. Le lot qui posera le `Return-Path` VERP (`rb-<jeton>@bounce.<domaine>`) à l'injection devra l'appeler. Aucun envoi réel n'est codé ici (`ENVOI_REEL_ACTIF=false`) ; tant que ce lot n'existe pas, aucun message sortant ne porte de VERP et le rattachement se fait par Message-ID.
- Plusieurs `Delivered-To` : `AnalyseDsn::enTetes` les joint par « , » et chaque adresse est testée pour le jeton `rb-`.

## Branchement de la relève (M3)

Avant M3, `ReleveImap`, le registre des messages envoyés, la fabrique de lecteurs et la chaîne de récepteurs n'étaient liés **que dans les tests** : une fois `ENVOI_RELEVE_ACTIF` allumé, la commande `envoi:releve-imap` aurait échoué à la résolution de dépendance. Désormais `EnvoiServiceProvider` lie :

- `RecepteurRetours` → `RecepteurRetoursEnChaine`, dans cet ordre (le même que celui des tests de bout en bout `RebondsPlaintesK11Test` / `ReleveImapAuditTest` pour les deux premiers, inchangé) : **1. CRM** (`RecepteurRetoursCrm` : arrêts permanents via `crm:campagne:retours`) → **2. freins L7** (`RecepteurRetoursFreins`) → **3. état des séquences** (`RecepteurRetoursSequence`). Le **journal** (`envoi_evenements`) est écrit par `ReleveImap` lui-même, dans la même transaction : une exception d'un maillon l'annule et le message est relu. La première exception interrompt la chaîne.
- `RegistreMessagesEnvoyes` → `RegistreMessagesEnvoyesDb`, l'adresse étant retrouvée dans les contacts de l'espace à partir de son empreinte (`AdresseParEmpreinteCrm`, lecture seule ; introuvable => « à rattacher »).
- `FabriqueLecteurs` → `FabriqueLecteursNonFournie` : la fabrique **réelle** (boîtes, secrets) n'est pas livrée. Tant qu'elle n'est pas posée, la commande échoue (code 1) avec le message « aucune fabrique de lecteurs IMAP… » : aucune connexion réseau, aucun identifiant lu.
- `ReleveImap` → construit à partir des liaisons ci-dessus.

Garde-fous inchangés : `ENVOI_RELEVE_ACTIF=false` et `ENVOI_REEL_ACTIF=false` par défaut, la commande reste inerte avant toute résolution ; aucune route, aucune planification nouvelle ; aucune écriture dans `envoi_drapeaux`.

### Rebond mou : seuil de répétition (constat)

Il **existe** : `crm:campagne:retours` appelle `ListeSuppression::rebondTemporaire`, qui compte les rebonds mous par adresse (fenêtre glissante de 30 jours) et ne supprime qu'à partir de `SEUIL_REBONDS_TEMPORAIRES` = 3. `RecepteurRetoursCrm` n'a pas de seuil propre : il transmet chaque rebond mou une seule fois (marque d'idempotence par message) et le seuil s'applique côté CRM. Rien n'a donc été inventé. Limite connue : ce compteur vit **en cache** (hors transaction, perdu si le cache est vidé) ; les freins L7 comptent de leur côté les rebonds mous dans leurs propres seuils.
