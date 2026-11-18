# Changelog — Axion CRM Pro

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) — SemVer.

## [Non publié] — 2026-11-18

Journée de fusions sur le chantier « envoi » et la connexion. Aucun drapeau n'a été allumé : `ENVOI_REEL_ACTIF`, la double authentification par courriel et la garde du rôle applicatif restent éteints.

### Added
- Connexion : codes de secours (consulter, régénérer, les saisir à la connexion par code courriel) — G3-D (#776) ; écrans du code courriel et du code de secours — G3-E (#787).
- Connexion : verrou sur le renvoi des codes courriel, tests du lien magique et de la session régénérée — AUTH-RL (#786).
- Connexion : un changement ou une réinitialisation du mot de passe ferme aussi les appareils de confiance — SEC-APP (#771).
- Démarrage : l'application peut refuser de démarrer sans le rôle applicatif (garde éteinte par défaut) — G4-A (#779).
- Adresses générales : repli sur l'adresse générale d'une entreprise (sélection, matérialisation, porte, séquenceur), avec opposition sur l'adresse de repli et sur le SIREN — L3 (#751), L4 (#754), L5a (#755), L5b (#758), L6 (#757), L6b (#759), REPLI-MOT (#781).
- LinkedIn : file LinkedIn dans le menu (#747), alertes, rappels de relance et relevé hebdomadaire (#756), dépôt des prospects avec journal (#774), réglage et texte (#768), lien de profil sûr (#778).
- Envoi, écrans : écran « Traitées » avec corriger et annuler — RR-6D (#753) ; « Lancer » piloté par le blocage — UI-CAMP (#769) ; nouveau code dans l'ajout de boîte — UI-BOITE (#772) ; assistant avec vrais joignables — B8-E (#773) ; assistant, A/B, boîtes et plafond grisés tant qu'ils sont refusés — B8-F (#783).
- Outils : constat en lecture seule des lignes d'ancienne forme — L8-B (#767).

### Changed
- Envoi : `envoi:sante` interroge le serveur dans le contexte de chaque espace — SANTE-ESP (#770).
- Envoi : le plafond d'une IP compte aussi les réservations en cours — IP-ENCOURS (#782) ; inventaire des IP avec auteur contrôlé et retrait atomique — IP-INV (#775).
- Envoi : feu vert, batterie et attestation corrigés — FV-BAT (#780) ; libellés des motifs d'opposition — X7-LIB (#765) ; capacités de l'assistant — MSG-CAP (#766).
- CI : un seul build du frontend pour les tests e2e et axe (#777) ; `!cancelled()` dans les audits (#764) ; cliquet Node resserré (#750).
- Dossier client : la personne née d'une demande est liée au dossier et ses demandes sont soldées — C1-C2 (#785).

### Fixed
- Cloisonnement : `courant()` filtre par espace (#760) ; `geste()` LinkedIn limité à l'expéditeur du prospect (#761).
- Test instable de lot injectable : délai SQL élargi — FLAKY-CJO3 (#788).
- Un prénom retiré d'un commentaire de migration — NOM-MIG (#763).

### Proposé, en relecture
- Commande locale `crm:compte:double-auth` : passe un compte en double authentification par courriel (refusée tant que le canal est faux ou le drapeau éteint ; le retour à l'application est refusé sans application inscrite) ou le ramène à l'application — G3-F (#792).

## [0.1.0-mocks-complete] — 2026-05-17

### Added
- Sprint 1 — Bootstrap : docker-compose maître + 3 Dockerfiles multi-stage + Caddy + .env.example
- Sprint 1 — Backend Laravel 12 + 14 contracts + 10 DTOs + 3 middlewares + 5 providers + Mock services
- Sprint 1 — Frontend React 19 + Vite 6 + Tailwind 4 + TanStack Router/Query + i18n FR/EN
- Sprint 1 — Workers Node 22 + Playwright 1.49 + BullMQ + bridge Redis HMAC
- Sprint 1 — GitHub Actions CI (4 jobs : backend + frontend + workers + security)
- Sprint 2 — 9 migrations Laravel Phase 1 + Phase 2 scaffold + RLS sur 30 tables + 11 seeders fondateurs
- Sprint 2 — 8 artisan commands (ign:import-admin-express, naf:import, coverage:refresh-matrix,
  audit:verify-chain, retention:purge, anomaly:detect, blacklists:check, signals:nightly-scan)
- Sprint 2 — Triggers SQL recompute_quality_score + updated_at sur 13 tables
- Sprint 3 — Auth réel : login Sanctum SPA + 2FA TOTP + magic-link + password reset
  + AuditHashChain crypto
- Sprint 4 — LLM Router 5 providers HTTP (Anthropic/OpenAI/Mistral/Groq/Together) +
  Webshare/IPRoyal proxies + DeduplicationService 6 niveaux + Rotations WRR/Zone/Search
- Sprint 5 — Sources HTTP réelles (INSEE Sirene V3, AnnuaireEntreprises, BODACC, BAN,
  France Travail) + WaterfallOrchestrator 10 étapes + EnrichCompanyJob / DispatchScrapeJob
- Sprint 6 + 7 — Workers Playwright réels Google Maps + Pages Jaunes + Sites web + Google Search
  Wrapper + Direction Finder (16 titles C-level)
- Sprint 8 — EmailFinderService 18 patterns + RealSmtpProber cascade N1-N5
- Sprint 9 — Carte France MapLibre + CoverageController 3 niveaux + LaunchZoneScrapingJob
- Sprint 10 — ClassifierService 4 use cases LLM + AutoTagApplier DSL JSONB +
  composants UI partagés (QualityBadge, SizeCategoryBadge, EmptyState, ErrorBoundary, Skeleton)
  + CompaniesListPage virtualisée + filters
- Sprint 11 — GdprErasureService art.17 + GdprPortabilityService art.20 + stack observabilité
  (Prometheus + Alertmanager + Grafana + Loki + Promtail + Tempo + GlitchTip + Uptime Kuma)
  + 8 alerts business + infra
- Sprint 12 — 5 specs Playwright E2E + a11y axe-core + 5 runbooks + DR drill script +
  PentestSelfCheck OWASP + OpenAPI Swagger + k6 load test

### Security
- Audit hash chain sha256 vérifiable (`php artisan audit:verify-chain`)
- RLS PostgreSQL sur 30 tables workspace-scoped
- SsrfGuard avec DENY_HOSTS + DENY_CIDR (AWS/GCP metadata, RFC 1918, link-local)
- Headers CSP strict + HSTS preload + COOP/CORP + Permissions-Policy
- Sanctum SPA cookie HttpOnly + Secure + SameSite=lax
- TOTP 2FA RFC 6238 obligatoire (EnforceFirstLoginSetup)
- RateLimiter 5/min login, 3/IP/10min magic-link
- Anti prompt-injection sanitize `ext_` prefix variables
- HMAC sha256 X-Worker-Signature endpoint /internal/scraper-result

### Compliance
- RGPD art. 6 (intérêt légitime B2B)
- RGPD art. 15-22 (accès / portabilité / effacement / rectification / opposition)
- RGPD art. 30 (registre traitements via audit_logs)
- AI Act register (table ai_act_register, profilage documenté)
- OWASP Top 10 checklist via `php artisan app:pentest-self-check`

### Notes
- Mode `MOCK_MODE=true` par défaut — aucun coût provider tant que non basculé en réel
- Cible : 200 000 entreprises/mois M1, 1 M/mois Année 1
- ~275-345 €/mois Phase 1 prod (cf. spec/21)
