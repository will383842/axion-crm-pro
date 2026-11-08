# Envoi K11 — rebonds et plaintes par la réception D2

Extension de la relève IMAP (L6, `docs/envoi/releve-imap.md`). Aucun envoi, aucune migration.

- **VERP** : `rb-<jeton>@bounce.<domaine>` (fourre-tout). Jeton = id d'injection (32 hex) + 10 hex HMAC (`envoi.cle_empreinte`). Jeton falsifié, autre clé ou clé vide : aucun rattachement (`Verp`).
- **ARF** (JMRP, Yahoo CFL) : `AnalyseArf` lit Feedback-Type, Original-Rcpt-To, Original-Mail-From (VERP) et le Message-ID cité. `abuse`/`fraud`/`virus`/`other` = plainte ; `not-spam`/`auth-failure` = sans effet. L'expéditeur du rapport (le fournisseur) n'est jamais opposé.
- **abuse@** : un message reçu sur `abuse@` est classé plainte (sauf auto-réponse déclarée).
- **Rattachement** : VERP signé d'abord, puis Message-ID. Sinon, ou si Original-Rcpt-To ≠ adresse écrite : file « à rattacher », **jamais d'opposition automatique** (revue humaine, lot L8).
- **Effet d'une plainte** : opposition + liste de suppression « plainte » via `crm:campagne:retours`. Le contact n'est ni supprimé ni modifié : il est écarté des envois, ses données restent.
- **Rejeu** : clé (boîte, UIDVALIDITY, UID) + empreinte du message ; une renumération ne recrée ni événement ni effet.

Scénarios du banc (`tests/Feature/Envoi/RebondsPlaintesK11Test.php`) : S09 rebond asynchrone par VERP · S10 ARF JMRP · S11 ARF Yahoo CFL · S12 réponse hostile · S13 message sur abuse@.
