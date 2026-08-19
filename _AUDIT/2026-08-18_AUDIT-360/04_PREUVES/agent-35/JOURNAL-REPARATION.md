# AGENT 35 — JOURNAL DE RÉPARATION (autopilote)

> Mandat reçu de Will en cours de session : « je veux que tu corriges et fixes au fur et à
> mesure », en appliquant à la lettre la doctrine de
> `Axion-IA/_AUDIT/PROMPT-AUDIT-QUALIOPI-E2E-50-AGENTS-2026-08-18.md`.
> Conséquences retenues : aucune question ; tout constat confirmé est réparé, testé, ouvert
> en PR ; **chaque correctif porte sa garde, et la garde a été vue ROUGIR avant le correctif** ;
> ordre imposé SÉCURITÉ/BLOQUANT → MAJEUR → … ; le périmètre n'est jamais réduit en silence.

## Décisions prises sans demander (avec leur justification)

**D1 — Où je travaille.** Worktree dédié `crmpro-wt-a35-auth`, branche `fix/a35-authentification`,
créée depuis `origin/main` = `e8924b8`, exactement ma référence d'audit.
*Pourquoi pas la copie principale* : une cinquantaine d'agents y mesurent en ce moment ; modifier
`backend/app/**` sous eux fausserait leurs mesures en cours.
⛔ Le worktree `crmpro-wt-etape1a` n'est ni lu, ni écrit, ni approché — consigne du dirigeant.

**D2 — Aligner le CODE sur le SCHÉMA, pas l'inverse (A07-001).** Le service 2FA écrit
`two_factor_secret`, `two_factor_enabled`, `two_factor_recovery_codes` ; la base porte
`totp_secret`, `totp_enabled_at`, `totp_recovery_codes`. Deux voies possibles : une migration qui
crée les colonnes manquantes, ou corriger le code.
*Je tranche pour le code*, pour trois raisons mesurées : (a) le schéma `totp_*` est cohérent et
**déjà utilisé** par `AuthService` (`totp_enabled_at` décide de `requires_2fa`) et par le script
`definir-mot-de-passe-crm.sh` ; (b) ajouter `two_factor_*` créerait **deux** jeux de colonnes pour
la même chose, donc une divergence future garantie (piège 15 du dossier) ; (c) une migration sur
une table `users` de production pour réparer une faute de frappe est un risque inutile.
`two_factor_enabled` n'a pas de colonne et n'en a pas besoin : il se **dérive** de
`totp_enabled_at !== null`.

**D3 — Ce que je ne fais pas, et qui reste à Will.** Aucune écriture en production, aucune
variable d'environnement de production touchée, aucun déploiement. Ces trois classes sont
explicitement humaines dans la doctrine reçue. Elles sont listées en fin de journal sous
« RESTE WILL », avec le geste exact.

## Chronologie

