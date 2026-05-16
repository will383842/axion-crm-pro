# POC #4 — Validation SMTP cascade

> **Hypothèse à valider** (spec v1.2 `06_email_finder_validation.md` § 3) :
> la cascade SMTP N1→N5 classifie correctement **≥ 90 %** d'un dataset gold de 100 emails étiquetés.
>
> **Budget : 0-5 €** (peut tourner depuis localhost ; 2 IPs Hetzner optionnelles si tu veux tester depuis IPs propres).
> **Durée : 1-2 heures.**

---

## Pré-requis

- Node 22 LTS + pnpm
- Connexion internet (port 25 sortant ouvert — sinon utiliser une VPS, beaucoup de FAI résidentiels bloquent le port 25)
- Dataset gold à compléter (cf. §3 ci-dessous)

> ⚠️ **Note importante port 25** : beaucoup d'ISPs résidentiels (Orange, Free, SFR, Bouygues...) bloquent le port 25 sortant. Si le POC échoue avec `Connection refused` ou `Connection timeout` systématique, c'est probablement ton FAI. Solutions :
> 1. Lancer depuis une VPS Hetzner CAX11 (~3,80 €/mo, créer + tester + détruire en 1h = ~0,01 €)
> 2. Lancer depuis 4G partagé en hotspot smartphone (port 25 souvent ouvert)
> 3. Lancer depuis un VPS dédié rapide (Scaleway DEV1-S = 0,0044 €/h)

---

## Setup

```powershell
cd "C:\Users\willi\Documents\Projets\Axion-CRM-Pro\poc\04_smtp_validation"
pnpm install
copy .env.example .env
notepad .env       # ajouter VALIDATOR_FROM_EMAIL et VALIDATOR_HELO_DOMAIN
```

---

## Dataset gold — IMPORTANT à compléter

Le fichier `datasets/emails_gold.json` contient un **template** de 100 emails étiquetés.

Pour avoir un résultat fiable, tu dois remplacer les emails fictifs par de VRAIS emails dont tu connais le statut :

### 50 emails VALIDES connus
- Tes contacts pros perso (collègues, freelances, clients) — emails dont tu sais qu'ils répondent
- Emails publics récents sur sites corporate ETI/Grandes (ex: `contact@<entreprise>.com` listé en mentions légales 2024-2025)

### 30 emails INVALIDES connus
- Random local-part sur domaines valides : `xkqz9mvr3@gmail.com`, `nonexistent12345@orange.fr`
- Typos volontaires : `wwiliamsjullin@gmail.com` (double w)
- Domaines inexistants : `test@axion-pro-fake-domain-12345.com`

### 20 emails CATCH-ALL connus
- Domaines dont tu sais qu'ils acceptent tout (test avec un random qui passe en valid_probable, puis 2ᵉ test avec autre random qui passe aussi = catch-all confirmé)
- Petites entreprises avec MX OVH/Gandi config catch-all

**Sans dataset réel, le POC est inutile (mesure rien).**

---

## Lancement

```powershell
pnpm run validate
```

Le worker :
1. Charge `datasets/emails_gold.json`
2. Pour chaque email, exécute la cascade complète (N1 syntaxe → N2 DNS MX → N3 SMTP probe → N4 catch-all → N5 score)
3. Compare le résultat à l'étiquette gold
4. Produit `RESULTS.md` avec confusion matrix + accuracy

---

## Critères GO / NO-GO

| KPI | Cible | Statut |
|-----|-------|--------|
| Accuracy globale | ≥ 90 % | (mesuré) |
| Recall sur "valid" | ≥ 85 % | |
| False positive rate (invalid classés valid) | < 5 % | |
| Recall sur catch-all | ≥ 80 % | |

---

## Cleanup

Rien à nettoyer. Aucun service récurrent.
