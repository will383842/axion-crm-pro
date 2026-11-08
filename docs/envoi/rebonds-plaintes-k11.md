# Envoi K11 — rebonds et plaintes par la réception D2

Extension de la relève IMAP (L6, `docs/envoi/releve-imap.md`). Aucun envoi. Une seule migration, en ajout seul : index `(workspace_id, id_injection)` sur `envoi_messages_envoyes` (rattachement par VERP).

- **VERP** : `rb-<jeton>@bounce.<domaine>` (fourre-tout). Jeton = id d'injection (32 hex) + 10 hex HMAC (`envoi.cle_empreinte`). Jeton falsifié, autre clé ou clé vide : aucun rattachement (`Verp`).
- **ARF** (JMRP, Yahoo CFL) : `AnalyseArf` lit Feedback-Type, Original-Rcpt-To, Original-Mail-From (VERP) et le Message-ID cité. `abuse`/`fraud`/`virus`/`other` = plainte ; `not-spam`/`auth-failure` = sans effet. L'expéditeur du rapport (le fournisseur) n'est jamais opposé.
- **abuse@** : un message reçu sur `abuse@` est classé plainte (sauf auto-réponse déclarée).
- **Rattachement** : VERP signé d'abord, puis Message-ID. Sinon, ou si Original-Rcpt-To ≠ adresse écrite : file « à rattacher », **jamais d'opposition automatique** (revue humaine, lot L8).
- **Effet d'une plainte** : opposition + liste de suppression « plainte » via `crm:campagne:retours`. Le contact n'est ni supprimé ni modifié : il est écarté des envois, ses données restent.
- **Rejeu** : clé (boîte, UIDVALIDITY, UID) + empreinte du message ; une renumération ne recrée ni événement ni effet.

Scénarios du banc (`tests/Feature/Envoi/RebondsPlaintesK11Test.php`) : S09 rebond asynchrone par VERP · S10 ARF JMRP · S11 ARF Yahoo CFL · S12 réponse hostile · S13 message sur abuse@.

## Dette connue

- **`Verp::adressePour` n'est appelé nulle part en production** : seul le décodage (`idInjectionDepuis`) est branché. Le lot qui posera le `Return-Path` VERP (`rb-<jeton>@bounce.<domaine>`) à l'injection devra l'appeler. Aucun envoi réel n'est codé ici (`ENVOI_REEL_ACTIF=false`) ; tant que ce lot n'existe pas, aucun message sortant ne porte de VERP et le rattachement se fait par Message-ID.
- Plusieurs `Delivered-To` : `AnalyseDsn::enTetes` les joint par « , » et chaque adresse est testée pour le jeton `rb-`.
