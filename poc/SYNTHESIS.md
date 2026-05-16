# Synthèse POCs Axion CRM Pro

> Récapitulatif des verdicts des 5 POCs de validation avant Sprint 1.
> Mise à jour : 2026-05-16

---

## Tableau de bord

| POC | Hypothèse | Exécuté | Résultat | Verdict |
|-----|-----------|---------|----------|---------|
| **#5** Dedup perf 1M+ rows | p95 < 50 ms à 10M | ✅ 2026-05-16 | **p95 35.94 ms, 0 seq scan, idx_runs_dedup utilisé** | **🟢 GO** |
| **#4** SMTP cascade validation | accuracy ≥ 90 % sur 100 emails gold | ❌ pas exécuté | — | ⏳ Pending |
| **#3** Direction Finder 20 ETI | ≥ 25 % ETI avec C-level | ❌ pas exécuté | — | ⏳ Pending |
| **#1** Google Maps anti-ban | success rate ≥ 75 % à J7 | ❌ pas exécuté | — | ⏳ Pending |
| **#2** Google Search Wrapper | captcha rate < 15 % | ❌ pas exécuté | — | ⏳ Pending |

**Score actuel : 1 / 5 validés.**

---

## Détail POC #5 — Anti-doublon performance 🟢 VALIDÉ

**Date exécution :** 2026-05-16
**Tag git :** `poc05-validated-2026-05-16`
**Setup :** PostgreSQL 16 Docker local (port 55432), 10 000 000 rows scraper_runs, 12 partitions mensuelles, 4 indexes dont `idx_runs_dedup`.

### KPIs mesurés

| Métrique | Mesure | Cible | Statut |
|----------|--------|-------|--------|
| **p95 latence query dedup** | **35.94 ms** | < 50 ms | **🟢 GO** |
| p50 | 10.46 ms | < 10 ms | 🟡 (légèrement au-dessus) |
| p99 | 87.91 ms | < 200 ms | 🟢 |
| Max | 572 ms | — | — |
| Avg | 15.25 ms | — | — |
| Throughput | 655 qps | — | — |
| `idx_runs_dedup` utilisé | ✅ YES | yes | 🟢 |
| Présence Seq Scan | ✅ NO | no | 🟢 |

### Conclusion

**Architecture dedup spec v1.2 §12 validée pour Sprint 1.** Aucune modification nécessaire.

L'index partitionné cascadé `scraper_runs_YYYY_MM_target_id_source_completed_at_idx` (12 indexes, 1 par partition) gère correctement la query dedup à 10M rows. Pas de seq scan.

**Note technique :** sur Postgres natif partitionné (sans pg_partman), il faut DROP INDEX → COPY → CREATE INDEX pour seed rapide. Avec indexes maintenus en temps réel, le COPY de 10M rows est inadmissiblement lent. Cette technique est documentée dans `src/seed.ts`.

### Issues détectées + résolues

1. Port 5432 conflit Windows → décalage 55432 (fix dans `docker-compose.yml`)
2. SIREN random → collisions sur 100k tirages (paradoxe anniversaires) → fix SIREN séquentiel
3. Indexes maintenus pendant COPY → blocage à 0 rows en DB → fix DROP/CREATE INDEX pattern
4. randomDateInPastMonths génère hors-partition → fix borne à `(months-1) × 28j`
5. Check `usesIndex` ne reconnaît pas les indexes cascadés sur partitions → fix regex pattern

Tous les fix commités sur main.

---

## Détail POCs #1, #2, #3, #4 — NON EXÉCUTÉS

**Pourquoi :** ces POCs nécessitent des actions humaines (Will) non automatisables :

### POC #4 — SMTP validation
- ✅ Code prêt (`poc/04_smtp_validation/src/validate.ts`)
- ⏳ **Bloqué par Will** : compléter `datasets/emails_gold.json` (100 emails réels étiquetés — placeholders `REPLACE_WITH_REAL_*` actuels)
- ⏳ Vérification port 25 sortant (FAI peut bloquer)

### POC #3 — Direction Finder
- ✅ Code prêt (`poc/03_direction_finder/src/main.ts` + 4 sources)
- ⏳ **Bloqué par Will** : créer compte Anthropic + charger 10 $ + récupérer clé `sk-ant-...`
- ⏳ **Bloqué par Will** : créer compte Webshare 10 $/mo + exporter `proxies.txt`
- ⏳ Remplir `.env` avec credentials

### POC #1 — Google Maps anti-ban
- ✅ Code prêt (`poc/01_google_maps/src/scraper.ts` + runner 7 jours)
- ⏳ **Bloqué par Will** : créer compte IPRoyal + charger 30 $ residential proxies
- ⏳ Remplir `.env` avec credentials
- ⏳ Runtime 7 jours consécutifs

### POC #2 — Google Search Wrapper
- ✅ Code prêt (`poc/02_google_search/src/engines.ts` + main + synthesize)
- ⏳ **Bloqué par Will** : créer compte 2captcha + charger 20 $
- ⏳ IPRoyal sticky sessions (depuis POC #1)
- ⏳ Runtime 5 jours consécutifs

---

## Verdict global actuel

**🟡 1/5 POCs validés. 4 POCs en attente d'actions humaines (souscriptions + credentials).**

**Recommandation :** Will doit souscrire les services tiers (~150 $ total budget) puis relancer les POCs avec les credentials dans `.env` de chaque dossier `poc/0X_*/`.

**Une fois 5/5 verts :** tag git `pocs-validated-YYYY-MM-DD` + démarrage Sprint 1 (Bootstrap infra).

---

**Synthèse maintenue automatiquement par Claude Code en mode autopilot.**
