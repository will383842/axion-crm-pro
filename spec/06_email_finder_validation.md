# 06 — EMAIL FINDER + VALIDATION SMTP

## Vue d'ensemble

Le module Email Finder est responsable de **trouver l'email professionnel d'un contact** à partir de son `first_name`, `last_name` et du `domain` de son entreprise (extrait de `companies.website`). Le module Email Validation est responsable de **vérifier la livrabilité réelle** de chaque email candidat via une cascade SMTP en 5 niveaux. Les deux modules sont fortement couplés : trouver un email sans le valider n'a pas de valeur opérationnelle.

L'objectif d'ensemble : pour ~80 % des contacts identifiés avec nom + entreprise, obtenir au moins **un email validé score ≥ 70/100**. Coût cible : < 0,1 ct par email validé.

---

## 1. Génération de patterns (15+ variantes)

### Tokens disponibles

| Token | Définition | Exemple |
|---|---|---|
| `{first}` | prénom complet en minuscule, sans accent | `jean` |
| `{last}` | nom complet en minuscule, sans accent, particules retirées | `dupont` |
| `{f}` | initiale prénom | `j` |
| `{l}` | initiale nom | `d` |
| `{fl}` | initiale prénom + nom | `jdupont` |
| `{f_last}` | initiale prénom + `_` + nom | `j_dupont` |
| `{l_first}` | nom + `.` + initiale prénom | `dupont.j` |
| `{first_l}` | prénom + initiale nom | `jeand` |
| `{first_l_last}` | prénom + dernier prénom (multi-prénoms) | `jean.dupont` |
| `{full}` | prénom+nom concaténés | `jeandupont` |

### Normalisation tokens (PHP)

```php
final class NameNormalizer
{
    public function normalize(string $first, string $last): array
    {
        $first = $this->slugify($first);
        $last = $this->slugify($last);
        return [
            'first' => $first,
            'last' => $last,
            'f' => substr($first, 0, 1),
            'l' => substr($last, 0, 1),
            'fl' => substr($first, 0, 1).$last,
            'f_last' => substr($first, 0, 1).'_'.$last,
            'l_first' => $last.'.'.substr($first, 0, 1),
            'first_l' => $first.substr($last, 0, 1),
            'full' => $first.$last,
        ];
    }

    private function slugify(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        // Retirer particules FR
        $s = preg_replace('/^(de|du|de\sla|le|la|d|von|van|el|al|saint|sainte)\s+/i', '', $s);
        // Garder uniquement a-z et tirets
        return preg_replace('/[^a-z0-9-]/', '', $s);
    }
}
```

### Liste des 18 patterns générés

```php
const PATTERNS = [
    '{first}.{last}',
    '{first}_{last}',
    '{first}-{last}',
    '{first}{last}',
    '{f}.{last}',
    '{f}_{last}',
    '{f}-{last}',
    '{f}{last}',
    '{first}.{l}',
    '{first}{l}',
    '{last}.{first}',
    '{last}_{first}',
    '{last}{first}',
    '{l}{first}',
    '{l}.{first}',
    '{l_first}',
    '{full}',
    '{first}{f}{last}',           // ex : jeanj.dupont (rare mais utilisé certaines TPE)
];
```

### Génération candidats

```php
final class EmailPatternGenerator
{
    public function __construct(private NameNormalizer $norm) {}

    /** Retourne 18 candidats triés par confiance descendante (basée sur fréquence empirique FR B2B). */
    public function generate(string $firstName, string $lastName, string $domain): array
    {
        $tokens = $this->norm->normalize($firstName, $lastName);
        $candidates = [];
        foreach (PATTERNS as $i => $template) {
            $local = strtr($template, $tokens);
            $local = preg_replace('/[^a-z0-9._\-]/', '', $local);
            $candidates[] = [
                'email' => "{$local}@{$domain}",
                'pattern' => $template,
                'priority' => 100 - $i,
            ];
        }
        return $candidates;
    }
}
```

---

## 2. Détection pattern entreprise existant

Si la table `email_patterns` contient déjà un pattern pour le `domain` avec `confidence >= 75`, on l'utilise **en priorité** (1 candidat unique avant d'essayer les 18 patterns) :

```php
final class CompanyPatternDetector
{
    public function detectFromSamples(string $domain, array $knownEmails): ?array
    {
        // knownEmails = [['email'=>'jean.dupont@x.fr','first'=>'jean','last'=>'dupont'], ...]
        if (count($knownEmails) < 2) return null;

        $hits = [];
        foreach ($knownEmails as $sample) {
            $tokens = $this->norm->normalize($sample['first'], $sample['last']);
            foreach (PATTERNS as $tpl) {
                $expected = strtr($tpl, $tokens).'@'.$domain;
                if ($expected === strtolower($sample['email'])) {
                    $hits[$tpl] = ($hits[$tpl] ?? 0) + 1;
                }
            }
        }
        if (!$hits) return null;
        arsort($hits);
        $topPattern = array_key_first($hits);
        $confidence = (int) round(($hits[$topPattern] / count($knownEmails)) * 100);
        return ['pattern' => $topPattern, 'confidence' => $confidence];
    }
}
```

Utilisation : si scraping site web extrait `jean.dupont@acme.fr` + `marie.martin@acme.fr` + `pierre.durand@acme.fr` → pattern `{first}.{last}` détecté avec confidence 100.

LLM fallback `detect_email_pattern` si heuristique ne suffit pas (ex : 1 seul email échantillon).

---

## 3. Cascade de validation SMTP — 5 niveaux

> **Objectif :** valider chaque email avec un score 0..100 sans envoyer de message réel.

### N1 — Validation syntaxe RFC 5322 + TLD

```php
final class SyntaxValidator
{
    private const REGEX_RFC5322 = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/';
    private const VALID_TLDS = ['fr','com','net','org','eu','io','co','tech','ai','app','dev', /* ~1200 TLD ICANN */];

    public function check(string $email): ValidationResult
    {
        if (strlen($email) > 254) return ValidationResult::invalid('too_long');
        if (!preg_match(self::REGEX_RFC5322, $email)) return ValidationResult::invalid('syntax');
        $tld = strtolower(substr(strrchr($email, '.'), 1));
        if (!in_array($tld, self::VALID_TLDS)) return ValidationResult::invalid('tld_unknown');
        return ValidationResult::valid(score: 20, method: 'syntax');
    }
}
```

### N2 — Résolution MX DNS

```php
final class MxValidator
{
    public function check(string $domain): ValidationResult
    {
        $records = [];
        $attempts = 0;
        while ($attempts < 3) {
            $records = dns_get_record($domain, DNS_MX);
            if (!empty($records)) break;
            $attempts++;
            usleep(200_000 * $attempts);
        }
        if (empty($records)) {
            // Fallback record A
            $aRecord = dns_get_record($domain, DNS_A);
            if (!empty($aRecord)) {
                return ValidationResult::partial(score: 40, method: 'mx', meta: ['fallback' => 'a_record']);
            }
            return ValidationResult::invalid('no_mx_no_a');
        }
        return ValidationResult::valid(score: 50, method: 'mx', meta: ['mx' => $records]);
    }
}
```

### N3 — Handshake SMTP (port 25 / 587 fallback)

```php
final class SmtpHandshake
{
    public function check(string $email, array $mxRecords): ValidationResult
    {
        usort($mxRecords, fn($a, $b) => $a['pri'] <=> $b['pri']);
        $mx = $mxRecords[0]['target'];
        $errno = 0; $errstr = '';
        $socket = @stream_socket_client("tcp://{$mx}:25", $errno, $errstr, 10);
        if (!$socket) {
            $socket = @stream_socket_client("tcp://{$mx}:587", $errno, $errstr, 10);
        }
        if (!$socket) return ValidationResult::invalid('smtp_unreachable', meta: ['errstr' => $errstr]);

        stream_set_timeout($socket, 8);
        $banner = fgets($socket, 1024);
        if (!str_starts_with($banner, '220')) {
            fclose($socket);
            return ValidationResult::invalid('smtp_banner');
        }
        $heloHost = config('axion.smtp_validator.helo_host', 'validator.axion-ia.com');
        $senderEmail = config('axion.smtp_validator.sender', 'noreply@validator.axion-ia.com');
        fwrite($socket, "EHLO {$heloHost}\r\n"); $this->drain($socket);
        fwrite($socket, "MAIL FROM:<{$senderEmail}>\r\n"); $resp = $this->readLine($socket);
        if (!str_starts_with($resp, '250')) { fclose($socket); return ValidationResult::invalid('mail_from'); }
        fwrite($socket, "RCPT TO:<{$email}>\r\n"); $rcpt = $this->readLine($socket);
        fwrite($socket, "QUIT\r\n"); fclose($socket);
        if (str_starts_with($rcpt, '250')) {
            return ValidationResult::valid(score: 80, method: 'smtp', meta: ['rcpt_response' => $rcpt]);
        }
        if (str_starts_with($rcpt, '550') || str_starts_with($rcpt, '551')) {
            return ValidationResult::invalid('mailbox_not_exists', meta: ['rcpt' => $rcpt]);
        }
        // 4xx → greylist
        if (str_starts_with($rcpt, '4')) {
            return ValidationResult::partial(score: 40, method: 'smtp', meta: ['rcpt' => $rcpt, 'greylist' => true]);
        }
        return ValidationResult::partial(score: 30, method: 'smtp', meta: ['rcpt' => $rcpt]);
    }
}
```

### N4 — Catch-all detection

```php
final class CatchallDetector
{
    public function check(string $domain, array $mxRecords): bool
    {
        // Envoyer RCPT TO une adresse forcément inexistante
        $fake = 'nonexistent-'.bin2hex(random_bytes(8))."@{$domain}";
        $r = (new SmtpHandshake)->check($fake, $mxRecords);
        return $r->isValid();  // si valid = catch-all détecté
    }
}
```

Si catch-all détecté → score max plafonné à 50 (faible confiance, l'email "fonctionne" mais peut ne pas exister).

### N5 — Scoring final

```php
final class FinalScorer
{
    public function compute(ValidationResult $syntax, ValidationResult $mx, ValidationResult $smtp, bool $isCatchall, bool $isDisposable): int
    {
        if (!$syntax->isValid()) return 0;
        if (!$mx->isValid() && !$mx->isPartial()) return 5;
        if ($isDisposable) return 10;
        if (!$smtp->isValid() && !$smtp->isPartial()) return 25;
        $base = $smtp->score();   // 80 si SMTP OK, 40 si greylist
        if ($isCatchall) $base = min($base, 50);
        // Bonus si pattern entreprise détecté avec confidence >= 75
        // Bonus si email = type 'nominative'
        return min(100, $base);
    }
}
```

### Récap scores

| Score | Signification | Action recommandée |
|---|---|---|
| 90-100 | Valide certain (SMTP OK + pattern matché) | Envoyable en cold email Phase 2 |
| 70-89 | Valide probable | Envoyable, monitoring renforcé |
| 40-69 | Catch-all ou greylist | Risque bounce moyen — envoi prudent |
| 20-39 | Doute fort | Ne pas envoyer (retry plus tard) |
| 0-19 | Invalide / inexistant | Marquer `is_excluded = true` |

---

## 4. TTL revalidation 30 jours

Toute ligne `email_verifications` a un `ttl_expires_at = NOW() + INTERVAL '30 days'`. Au-delà, considérée périmée. Le job `RevalidateExpiredEmailsJob` quotidien re-déclenche la cascade pour les emails dont le statut `valid` a expiré (priorité aux emails dont au moins 1 contact actif est lié).

---

## 5. Architecture workers dédiés

**Workers `email-validate` séparés** des workers de scraping :
- 2 workers Horizon dédiés (concurrency 8 chacun, ~16 validations parallèles)
- IPs publiques distinctes des workers Node Playwright pour **préserver la réputation IP** du validateur (les IPs Playwright sont parfois greylistées sur certains mailservers)
- HELO host = `validator.axion-ia.com` (sous-domaine DNS dédié avec rDNS + SPF + DKIM configurés)
- Sender = `noreply@validator.axion-ia.com` (boîte qui catch les rebonds — utile pour ajuster scoring)

### Configuration SMTP du validateur

DNS pour `validator.axion-ia.com` :
- A → IP du worker validator
- rDNS PTR → `validator.axion-ia.com`
- SPF : `v=spf1 a -all`
- DKIM : clé publique enregistrée (signe automatique des sondes)
- DMARC : `v=DMARC1; p=none; rua=mailto:contact@axion-ia.com`

→ Sans ces records, certains mailservers (Microsoft 365, Gmail) refusent même le HELO du sondeur et retournent 550 systématiquement.

---

## 6. Coût estimé validation

- DNS lookups : gratuit
- SMTP handshake : gratuit (juste de la bande passante ~2 KB/email)
- Workers Hetzner : déjà payés dans `worker-php-01`
- LLM `detect_email_pattern` (occasionnel) : ~50 tokens × Mistral Small ($0.05/M) = négligeable
- **Coût total : ~0,0001€ par validation.**

Sur 200 000 entreprises × moyenne 3 contacts × moyenne 5 candidats = 3M validations/mois → 300€ MAX, en réalité ~50€ (cache 30j coupe la majorité).

---

## 7. Code pseudo-orchestrateur

```php
final class EmailFinderOrchestrator
{
    public function findAndValidate(Contact $contact, Company $company): array
    {
        $domain = parse_url($company->website, PHP_URL_HOST);
        if (!$domain) return ['status' => 'no_domain'];

        // 1. Pattern entreprise déjà connu ?
        $known = $this->patternRepo->findFor($contact->workspace_id, $domain);
        $candidates = [];
        if ($known && $known->confidence >= 75) {
            $candidates[] = $this->genFromPattern($contact, $known->pattern, $domain);
        } else {
            $candidates = (new EmailPatternGenerator($this->norm))->generate($contact->first_name, $contact->last_name, $domain);
        }

        $validated = [];
        foreach ($candidates as $c) {
            // Cache 30j
            $cached = $this->verificationsRepo->latestNonExpired($c['email']);
            if ($cached) { $validated[] = $cached; continue; }

            $syntax = (new SyntaxValidator)->check($c['email']);
            if (!$syntax->isValid()) { $this->store($c['email'], $syntax, 0); continue; }
            $mx = (new MxValidator)->check($domain);
            if (!$mx->isValid()) { $this->store($c['email'], $mx, 5); continue; }
            $smtp = (new SmtpHandshake)->check($c['email'], $mx->meta('mx'));
            $catchall = false;
            if ($smtp->isValid()) {
                $catchall = (new CatchallDetector)->check($domain, $mx->meta('mx'));
            }
            $score = (new FinalScorer)->compute($syntax, $mx, $smtp, $catchall, isDisposable: $this->isDisposable($domain));
            $row = $this->store($c['email'], $smtp, $score, $catchall);
            $validated[] = $row;
            if ($score >= 80) break;  // on s'arrête au premier email valide haute confiance
        }
        // Update pattern table si nouveaux échantillons
        $this->patternUpdater->updateFromBatch($contact->workspace_id, $domain, $validated);
        return $validated;
    }
}
```

---

## 8. Mapping DB

| Table | Quand on écrit |
|---|---|
| `email_verifications` | À CHAQUE validation (1 ligne par appel cascade) |
| `company_emails` | Si `score >= 70` : INSERT/UPDATE avec `is_validated = true`, `validation_score = $score` |
| `email_patterns` | Quand un pattern est confirmé sur ≥ 2 échantillons (UPSERT) |

---

## 9. Liste disposables

```php
const DISPOSABLE_DOMAINS = [
    'mailinator.com', 'guerrillamail.com', 'tempmail.org', 'yopmail.com',
    '10minutemail.com', 'throwaway.email', 'fakemail.fr',
    // ~600 domaines connus disposables (liste maintenue depuis github.com/disposable-email-domains/disposable-email-domains)
];
```

Mise à jour mensuelle automatique via `RefreshDisposableListJob`.

---

## 10. Anti-pattern interdit

❌ Envoyer un **vrai email de test** (avec contenu) pour valider. C'est un nettoyage hostile à la réputation IP et illégal en RGPD sans base légale.
❌ Utiliser des services tiers payants comme Hunter.io, Snov.io, Findymail (qui mutualisent les SMTP probes et burnent leurs IPs). Tout en propre.
❌ Plus de 8 validations parallèles vers le même MX server (rate-limited par Microsoft/Gmail).

---

## 11. Critères de done (S8)

- [ ] 100 % des emails extraits par source 8 (Sites web) passent la cascade en ≤ 8s p95
- [ ] Taux de faux positifs (email validé score ≥ 70 qui bounce) ≤ 3 % sur dataset de test 1000 emails
- [ ] Cache 30j fonctionne (re-test = 0 SMTP call)
- [ ] Pattern entreprise détecté correctement sur ≥ 80 % des entreprises avec ≥ 3 emails nominatifs
- [ ] Aucun spam complaint sur l'IP validator (monitoré via DMARC reports)

→ Lire `07_llm_router.md` pour le routeur LLM unifié.
