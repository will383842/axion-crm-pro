# LIA — Legitimate Interest Assessment

> **Document juridique requis par l'art. 6.1.f RGPD avant invocation de la base légale "intérêt légitime"**
>
> **Responsable de traitement :** Axion-IA OÜ (Sepapaja tn 6, 15551 Tallinn, Estonie, n° d'enregistrement 16384275)
> **DPO :** `contact@axion-ia.com`
> **Version :** 1.0
> **Date :** 2026-05-16
> **Statut :** validé avant mise en production Axion CRM Pro V1
> **Revue :** annuelle ou en cas de changement substantiel

## Méthodologie

Conformément à l'opinion **WP29 06/2014** et aux recommandations **CNIL 2022-2024**, ce LIA applique le test à 3 critères :

1. **Test de but** (purpose test) : l'intérêt poursuivi est-il légitime, légal, réel et précis ?
2. **Test de nécessité** (necessity test) : le traitement est-il nécessaire pour atteindre cet intérêt ? Existe-t-il une mesure moins intrusive ?
3. **Test de mise en balance** (balancing test) : l'intérêt du responsable de traitement prévaut-il sur les droits et libertés fondamentaux des personnes concernées ?

Le LIA est rempli **par traitement** (et non globalement). Axion CRM Pro V1 active **7 traitements** documentés dans la table `data_processing_log` (cf spec fichier 17 §1).

---

## TRAITEMENT T1 — Prospection B2B nominative

### 1. Test de but

| Critère | Évaluation |
|---|---|
| **Intérêt légitime invoqué** | Identifier et qualifier les entreprises françaises (TPE/PME/ETI/GE + écoles) susceptibles d'acheter des prestations de conseil IA opérationnel à Axion-IA |
| **Légitimité** | Oui — la prospection commerciale B2B est expressément reconnue par la CNIL (FAQ "B2B et prospection commerciale", màj 2024) comme un intérêt légitime, à condition de respecter le RGPD |
| **Légalité** | Oui — aucune disposition légale n'interdit la prospection B2B vers des emails professionnels nominatifs |
| **Réalité** | Oui — Axion-IA est un cabinet IA opérationnel ayant un modèle commercial B2B documenté (site axion-ia.com, 5 offres tarifées, structure OÜ active) |
| **Précision** | Oui — limité aux emails professionnels nominatifs (`prenom.nom@entreprise.com`) ; emails personnels (`@gmail.com`, `@hotmail.fr`, etc.) sont **exclus** automatiquement (cf spec fichier 06 §10) |

**Verdict test de but :** ✅ PASS

### 2. Test de nécessité

| Critère | Évaluation |
|---|---|
| **Nécessité directe** | Sans identification des décideurs (DRH, DAF, DSI, Marketing, Commercial) au sein de l'entreprise cible, il est techniquement impossible de proposer une offre conseil IA adaptée |
| **Alternatives évaluées** | (a) Achat de listes de contacts B2B → moins fiable, qualité hétérogène, traçabilité moindre ; (b) Inbound marketing seul → trop lent pour atteindre le volume cible année 1 ; (c) Salons / réseaux physiques → cher, non scalable. **Aucune alternative moins intrusive ne permet d'atteindre le même objectif.** |
| **Proportionnalité** | Le traitement est limité aux données strictement nécessaires : nom, fonction, email pro, entreprise, secteur, signaux business publics (BODACC, France Travail). Aucune donnée sensible (santé, opinions, etc.) n'est collectée |

**Verdict test de nécessité :** ✅ PASS

### 3. Test de mise en balance

| Critère | Évaluation |
|---|---|
| **Attentes raisonnables des personnes** | Un dirigeant ou C-level dont les coordonnées professionnelles sont publiquement disponibles (BODACC, annuaire-entreprises.data.gouv.fr, site web entreprise, LinkedIn pro) s'attend raisonnablement à être contacté par des fournisseurs B2B pertinents |
| **Impact sur les personnes** | Faible — réception d'emails B2B ciblés, droit d'opposition immédiat via opt-out (cf spec fichier 17), aucun impact juridique direct, aucune décision automatisée juridiquement contraignante |
| **Mesures de sauvegarde mises en place** | (a) `opt_out` cross-workspace consulté avant tout contact ; (b) DPO joignable `contact@axion-ia.com` ; (c) droits RGPD traités < 30 jours ; (d) registre des traitements tenu à jour ; (e) audit log append-only hash chain ; (f) sous-processeurs documentés avec DPA ; (g) durées de conservation limitées (730 j max) ; (h) chiffrement TLS + au repos ; (i) minimisation des données (pas d'emails perso) ; (j) RLS PostgreSQL au niveau DB pour isolation tenant ; (k) human-in-the-loop sur classifications (cf DPIA séparé) |
| **Catégories de personnes** | Dirigeants légaux et C-level d'entreprises commerciales — personnes agissant dans un cadre professionnel, pas en tant que particuliers |
| **Vulnérabilité particulière** | Aucune — pas de mineurs, pas de personnes vulnérables au sens RGPD |

**Verdict test de mise en balance :** ✅ PASS — l'intérêt légitime d'Axion-IA prévaut sur les droits des personnes concernées, sous réserve du maintien des mesures de sauvegarde listées ci-dessus.

### 4. Conclusion T1

L'intérêt légitime est **valablement invoqué** comme base légale pour le traitement T1 (prospection B2B nominative). Le présent LIA est revu annuellement.

---

## TRAITEMENT T2 — Enrichissement légal & financier

**But :** compléter les fiches entreprises avec dirigeants légaux, CA, bilans depuis sources publiques officielles (BODACC, annuaire-entreprises.data.gouv.fr, Infogreffe).

| Test | Verdict |
|---|---|
| **But** | ✅ Données publiques officielles, finalité commerciale légitime |
| **Nécessité** | ✅ Sans dirigeants identifiés, impossibilité de prospecter ; les bilans permettent d'estimer la taille et la maturité IA de la cible |
| **Mise en balance** | ✅ Données déjà publiques par décision législative (publication BODACC, annuaire-entreprises) — attentes raisonnables = données accessibles à tous |

**Verdict :** ✅ Intérêt légitime valable.

---

## TRAITEMENT T3 — Détection signaux business

**But :** identifier signaux d'achat (recrutements C-level, levées de fonds, changements dirigeants).

| Test | Verdict |
|---|---|
| **But** | ✅ Identifier les moments propices pour proposer une offre conseil IA |
| **Nécessité** | ✅ Sans détection automatique de signaux, ROI marketing très inférieur (~10×) |
| **Mise en balance** | ✅ Données déjà publiques (BODACC, France Travail offres d'emploi, news médias) |

**Verdict :** ✅ Intérêt légitime valable.

---

## TRAITEMENT T4 — Email validation

**But :** vérifier la livrabilité d'emails professionnels sans envoyer de message réel (validation SMTP cascade).

| Test | Verdict |
|---|---|
| **But** | ✅ Éviter d'envoyer des emails B2B vers des adresses invalides ou bouncing |
| **Nécessité** | ✅ Sans validation, taux de bounce élevé → réputation IP dégradée → impacte la délivrabilité légitime ultérieure |
| **Mise en balance** | ✅ Aucun message réel envoyé pendant la validation, juste handshake SMTP technique. Mesures de sauvegarde : IP validator dédiée avec SPF/DKIM/DMARC configurés (cf spec fichier 06 §5) |

**Verdict :** ✅ Intérêt légitime valable.

---

## TRAITEMENT T5 — LinkedIn C-level enrichissement

**But :** identifier les C-level non-dirigeants (DRH, DAF, DSI, Marketing, Commercial) via PhantomBuster.

⚠️ **Point d'attention :** PhantomBuster est basé aux US (transfert hors UE). Mesures de sauvegarde : DPA signé + Standard Contractual Clauses (SCC) à signer avant mise en production.

| Test | Verdict |
|---|---|
| **But** | ✅ Identifier les bons interlocuteurs pour offres conseil IA (le dirigeant légal n'est souvent pas le décideur opérationnel) |
| **Nécessité** | ✅ Aucune source publique européenne n'expose les C-level non-légaux ; LinkedIn est la seule source pertinente |
| **Mise en balance** | ⚠️ Acceptable SOUS RÉSERVE de : (a) DPA + SCC signés avec PhantomBuster ; (b) données récupérées limitées au strict nécessaire (nom + fonction + URL profil public LinkedIn) ; (c) opt-out global respecté ; (d) personnes concernées ont elles-mêmes rendu publiques ces informations sur LinkedIn |

**Verdict :** ✅ Intérêt légitime valable, sous réserve DPA + SCC PhantomBuster (action humaine à faire avant go-live S12).

---

## TRAITEMENT T6 — Audit log + monitoring sécurité

**But :** sécurité système, traçabilité actions, registres RGPD art. 30.

**Base légale :** obligation légale (RGPD art. 32 sécurité du traitement) **+** intérêt légitime (sécurité opérationnelle).

| Test | Verdict |
|---|---|
| **But** | ✅ Obligation légale art. 32 + sécurité du SI |
| **Nécessité** | ✅ Sans audit logs, traçabilité impossible (= non-conformité art. 30) |
| **Mise en balance** | ✅ Logs limités à 365 j, IP hashées SHA-256, pas de contenu utilisateur logué |

**Verdict :** ✅ Intérêt légitime valable.

---

## TRAITEMENT T7 — Profilage IA (scoring + offer matching)

**But :** scoring automatique maturité IA + recommandation offre Axion-IA pertinente.

⚠️ Ce traitement déclenche également **DPIA obligatoire** (cf `dpia.md`). Le LIA est complémentaire mais ne suffit pas seul pour ce traitement.

| Test | Verdict |
|---|---|
| **But** | ✅ Cibler efficacement les offres conseil IA selon le profil entreprise |
| **Nécessité** | ✅ Sans scoring automatique, traitement manuel de 200k entreprises/mois impossible |
| **Mise en balance** | ✅ Sous réserve de : (a) human-in-the-loop systématique (override manuel disponible sur tous les scores — cf spec fichier 08 §6) ; (b) absence de décision juridiquement contraignante ; (c) transparence dans politique de confidentialité ; (d) DPIA documentée séparément |

**Verdict :** ✅ Intérêt légitime valable, sous réserve DPIA (cf `dpia.md`).

---

## Synthèse globale LIA

| Traitement | Verdict LIA | Action requise |
|---|---|---|
| T1 prospection B2B | ✅ Valable | Maintenir opt-out cross-workspace |
| T2 enrichissement légal | ✅ Valable | Aucune (données publiques) |
| T3 signaux business | ✅ Valable | Aucune (données publiques) |
| T4 email validation | ✅ Valable | Maintenir SPF/DKIM/DMARC validator |
| T5 LinkedIn C-level | ✅ Valable | DPA + SCC PhantomBuster à signer S12 |
| T6 audit + sécurité | ✅ Valable | Maintenir hash chain audit_logs |
| T7 profilage IA | ✅ Valable | Voir DPIA séparée |

**Verdict global :** les 7 traitements Axion CRM Pro V1 peuvent invoquer valablement l'intérêt légitime art. 6.1.f RGPD, sous réserve du maintien continu des mesures de sauvegarde et de la signature des DPA/SCC manquants avant go-live S12.

---

## Mesures continues de conformité

- ✅ Registre `data_processing_log` tenu à jour (table DB versionnée)
- ✅ DPO joignable `contact@axion-ia.com` sous 72h
- ✅ Droits CNIL (accès / effacement / rectification / opposition / portabilité / limitation) traités < 30 jours
- ✅ Mention légale + politique de confidentialité publiées (`/legal/privacy`)
- ✅ Audit logs append-only hash chain
- ✅ Chiffrement TLS in-transit + AES-256 at-rest (backups B2)
- ✅ Sous-processeurs documentés avec DPA (cf spec fichier 17 §6)
- ✅ Minimisation : pas d'emails personnels, pas de données sensibles
- ✅ Multi-tenant RLS PostgreSQL au niveau DB
- 🔄 Revue annuelle du LIA (prochaine : 2027-05-16)
- 🔄 Revue en cas de modification substantielle (nouveau traitement, nouvelle finalité)

---

## Signature et validation

**Responsable de traitement :** Axion-IA OÜ — Williams Jullin (fondateur)
**Date :** 2026-05-16
**Prochaine revue obligatoire :** 2027-05-16

---

*Ce LIA est conservé en archive interne et présentable sur demande à la CNIL en cas de contrôle.*
