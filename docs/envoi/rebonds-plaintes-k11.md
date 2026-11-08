# Envoi K11 — rebonds et plaintes par la réception D2

Extension de la relève IMAP (L6, `docs/envoi/releve-imap.md`). Aucun envoi. Une seule migration, en ajout seul : index `(workspace_id, id_injection)` sur `envoi_messages_envoyes` (rattachement par VERP).

- **VERP** : `rb-<jeton>@bounce.<domaine>` (fourre-tout). Jeton = id d'injection (32 hex) + 10 hex HMAC (`envoi.cle_empreinte`). Jeton falsifié, autre clé ou clé vide : aucun rattachement (`Verp`).
- **ARF** (JMRP, Yahoo CFL) : `AnalyseArf` lit Feedback-Type, Original-Rcpt-To, Original-Mail-From (VERP) et le Message-ID cité. `abuse`/`fraud`/`virus`/`other` = plainte ; `not-spam`/`auth-failure` = sans effet. L'expéditeur du rapport (le fournisseur) n'est jamais opposé.
- **abuse@** : un message reçu sur `abuse@<un de NOS domaines d'envoi>` (table `envoi_domaines` de l'espace, sous-domaines inclus, ex. `bounce.<domaine>`) est classé plainte (sauf auto-réponse déclarée). `abuse@` d'un autre domaine : traité comme un message ordinaire.
- **Rattachement** : *rebonds et réponses* : VERP signé d'abord, puis Message-ID. *Rapport ARF* : **VERP signé SEUL** — le Message-ID qu'il cite, In-Reply-To et References ne rattachent jamais. Sans VERP signé, ou si Original-Rcpt-To ≠ adresse écrite, ou si Feedback-Type est illisible (type `illisible`, plus jamais « other »). **Feedback-Type inconnu ou absent = règle par défaut : `illisible`**, donc « à rattacher », aucune plainte devinée. Idem si l'analyse du rapport ARF échoue (UTF-8 invalide, limite PCRE) : type `illisible` forcé, jamais de « STOP par prudence » via In-Reply-To/References. Ces rapports sont classés `auto_reponse` en base (sans effet) ; un libellé distinct en file de revue exigerait une nouvelle classe, donc l'élargissement de la contrainte CHECK de `envoi_evenements` : reporté au lot L8 : file « à rattacher », **jamais d'opposition automatique** (revue humaine, lot L8). Un VERP d'un autre espace n'est pas retrouvé : « à rattacher ».
- **Effet d'une plainte** : opposition + liste de suppression « plainte » via `crm:campagne:retours`. Le contact n'est ni supprimé ni modifié : il est écarté des envois, ses données restent.
- **Plaintes et freins** : une plainte rattachée (ARF, réponse hostile, `abuse@`) compte pour les freins de L7 (`RecepteurRetoursFreins` → `CompteurRetours`) : la 1re sur 7 jours = palier tenu + relecture ; la 2e = arrêt de 48 h. **Attention** : la pause L7 couvre tout l'espace, ce qui est plus strict que l'« arrêt 48 h du domaine » du plan 26 (le test S10 vérifie `dureeHeures`). Le comptage est **par espace** (voir « Décision en attente »).
- **Rejeu** : clé (boîte, UIDVALIDITY, UID) + empreinte du message ; une renumération ne recrée ni événement ni effet.

Scénarios du banc (plan 26-SPEC-CONTRAT-ENVOI-ET-BANC l.257-261, `tests/Feature/Envoi/RebondsPlaintesK11Test.php`) : S09 rebond asynchrone par VERP · S10 ARF + réponse hostile + `abuse@` (3 plaintes comptées, 1re = palier tenu, 2e en 7 jours = arrêt 48 h) · S11 réponse humaine (« Réponses ») · S12 STOP (opposition) · S13 absence du bureau (auto-réponse, aucune opposition).

Limite : l'arrêt/la pause de la **séquence** (S11, S13) relève du séquenceur (L1) : K11 classe, rattache et TRANSMET le retour au récepteur (testé) ; il n'arrête aucune séquence lui-même.

## Décision en attente (plan 30, B5)

Une plainte bloque-t-elle tous les clients ou seulement l'espace concerné ? **Décision du responsable du projet en attente — non tranchée ici.** Comportement par défaut : blocage LIMITÉ à l'espace concerné (freins et VERP filtrés par espace, testé).

## Périmètre

K11 touche `Envoi/Imap/**` : c'est une extension de L6 (relève IMAP).

## Dette connue

- **Rotation de `envoi.cle_empreinte`** : elle invalide tous les VERP déjà émis (leurs retours tombent en « à rattacher »). Garder l'ancienne clé quelques jours, ou accepter la revue humaine.
- `Verp::adressePour` exige désormais un UUID (`InvalidArgumentException` sinon).
- **`Verp::adressePour` n'est appelé nulle part en production** : seul le décodage (`idInjectionDepuis`) est branché. Le lot qui posera le `Return-Path` VERP (`rb-<jeton>@bounce.<domaine>`) à l'injection devra l'appeler. Aucun envoi réel n'est codé ici (`ENVOI_REEL_ACTIF=false`) ; tant que ce lot n'existe pas, aucun message sortant ne porte de VERP et le rattachement se fait par Message-ID.
- Plusieurs `Delivered-To` : `AnalyseDsn::enTetes` les joint par « , » et chaque adresse est testée pour le jeton `rb-`.
