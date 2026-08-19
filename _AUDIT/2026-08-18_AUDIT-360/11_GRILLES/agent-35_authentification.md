# AGENT 35 — Auditeur d'authentification

**Référence mesurée** : `main = e8924b81ad64c0b236acd99ac5cbac4cd68eada7` (`e8924b8`), relue par
`git log` / `git rev-parse` au début **et** à la fin de la session (identique).
**Atelier** : conteneur dédié `a35-api` (image `axion-crm-pro-api`, réseau `axion-crm`,
port 58135), bases dédiées `axion_crm_a35` et `axion_crm_test_a35` — **aucun** conteneur ni
aucune base partagée avec les autres agents n'a été muté.
**Production** : **aucune requête n'a été émise**. Voir §« Ce que je n'ai PAS pu vérifier ».
**Aucun fichier du produit n'a été modifié.** La sonde vit dans `/tmp/a35` du conteneur ;
ses sources sont archivées dans `04_PREUVES/agent-35/sonde/`.

---

## 1. Grille

Légende : ✅ conforme · ⚠️ défaut · 🔴 grave · ⬜ non vérifié (raison donnée)

| # | Objet du périmètre | Existe | Comportement mesuré | Garde vue rougir | Verdict | Constat |
|---|---|---|---|---|---|---|
| 1 | `bootstrap/app.php` — `withExceptions` | oui | bloc **vide** ; aucune personnalisation du rendu des erreurs | oui (test 2) | 🔴 | F35-001 |
| 2 | `routes/web.php` | oui | 4 lignes, une seule route `/`, **aucune route nommée `login`** | oui | 🔴 | F35-001 |
| 3 | `AuthService::attemptLogin()` | oui | throttle IP+e-mail 5/min ; verrou compte à 10 échecs / 24 h | oui | ⚠️ | F35-009, F35-012 |
| 4 | `AuthService::logout()` | oui | `Auth::logout` + `session()->invalidate()` + `regenerateToken()` | oui | ✅ | — |
| 5 | `TwoFactorService::startEnrolment()` | oui | écrit `two_factor_secret` — **colonne inexistante** | oui (test 2 bis) | 🔴 | F35-002 |
| 6 | `TwoFactorService::confirmEnrolment()` | oui | écrit 3 colonnes inexistantes ; seul point qui pose `first_login_completed_at` | oui | 🔴 | F35-002 |
| 7 | `TwoFactorService::verify()` | oui | TOTP fenêtre ±1 pas ; codes de secours à usage unique **en mémoire** | oui | ⚠️ | F35-002 |
| 8 | `MagicLinkService::issue()` | oui | jeton `Str::random(64)`, haché SHA-256, TTL 15 min | oui | ⚠️ | F35-013 |
| 9 | `MagicLinkService::consume()` | oui | usage unique réel (`consumed_at`) ; résolution par **e-mail**, pas par `user_id` | oui | ⚠️ | F35-013 |
| 10 | `HibpChecker::getBreachCount()` | oui | **retourne 0 sur toute erreur réseau** (fail-open assumé en commentaire) | oui (test 9) | 🔴 | F35-004 |
| 11 | `NotPwnedPassword` | oui | branchée **uniquement** sur `POST /auth/password/reset` | oui | 🔴 | F35-004 |
| 12 | `AuthController::login()` | oui | 419 explicite si pas de session ; 422 sinon | oui | ✅ | — |
| 13 | `AuthController::logout/me/onboarding` | oui | contrôle `$request->user()` en plus de `auth:sanctum` | oui | ✅ | — |
| 14 | `MagicLinkController` | oui | réponse identique e-mail connu/inconnu | oui | ⚠️ | F35-009 |
| 15 | `PasswordResetController::forgot()` | oui | réponse identique ; écrit une ligne pour **tout** e-mail | oui | ⚠️ | F35-009 |
| 16 | `PasswordResetController::reset()` | oui | jeton à usage unique ; **contrôle d'expiration inopérant** | oui (test 7) | 🔴 | F35-005 |
| 17 | `TwoFactorController::verify()` | oui | pose `2fa_passed_at` en session — **jamais relu ailleurs** | oui (test 8) | 🔴 | F35-003 |
| 18 | `EnforceFirstLoginSetup` | oui | 403 tant que `first_login_completed_at` est nul ; liste blanche exacte | oui (test 10) | ⚠️ | F35-002 |
| 19 | `PersonalAccessToken` (modèle) | oui | enregistré par `AppServiceProvider:83` ; casts conformes | oui | ✅ | — |
| 20 | `config/sanctum.php` | oui | `expiration => null` → **jetons d'API sans expiration** | oui (test 3) | ⚠️ | F35-010 |
| 21 | `config/session.php` | oui | 120 min, chiffrée, HttpOnly, SameSite=lax ; `secure` = variable | oui (test 3) | ✅ | — |
| 22 | Révocation d'un jeton d'API | — | jeton supprimé ⇒ 401 | oui (test 3) | ✅ | — |
| 23 | `infra/scripts/definir-mot-de-passe-crm.sh` | oui | mot de passe **dans `argv` de `docker exec`** | oui | ⚠️ | F35-007 |
| 24 | `OwnerUserSeeder` (chemin d'identifiant) | oui | mot de passe en clair sur disque en **0644** + sur la sortie standard | oui | ⚠️ | F35-008 |
| 25 | `LoginRequest` | oui | `Password::min(12)` **appliqué à la connexion** | oui (test 11) | ⚠️ | F35-011 |
| 26 | Journalisation des refus d'authentification | — | **8 475 octets** de trace par 500 | oui | 🔴 | F35-001 |
| 27 | A-001 en **production** | — | non rejoué | — | ⬜ | §5 |

---

## 2. La cause exacte de A-001, et le correctif

_(section détaillée plus bas dans F35-001)_

---

## 3. Constats

_(en cours de rédaction)_

---

## 4. Le produit a-t-il jamais été utilisable ?

_(en cours de rédaction)_

---

## 5. Ce que je n'ai PAS pu vérifier

_(en cours de rédaction)_
