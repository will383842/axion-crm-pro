# Preuves — AGENT 16 (journal d'audit, chaîne de hachage, registre AI Act)

Référence relue au moment de la mesure : **`main = 1145473`** (le dossier commun
nomme `c0c453d` ; `git log c0c453d..HEAD` ne contient que quatre commits
documentaires `docs(cnil)` / `docs(rgpd)`, aucun ne touchant `backend/`).

Base jetable employée : **`axion_crm_a16`**, créée par
`CREATE DATABASE axion_crm_a16 TEMPLATE axion_crm_test`, **détruite après mesure**
(`DROP DATABASE axion_crm_a16`). Aucune écriture en production, aucun fichier du
produit modifié, aucun accès au worktree `crmpro-wt-etape1a`.

| Fichier | Ce qu'il prouve |
|---|---|
| `01_script-alteration.php` | Script rejouable : écrit 3 maillons, altère, vérifie, restaure. Déposé dans `/tmp` du conteneur, jamais dans le dépôt. |
| `01_chaine-alteration-temoin-positif-et-negatif.txt` | **Témoin positif** (§3, §6 : chaîne intacte → code 0) et **témoin négatif** (§5 : `status_code` altéré → code 1, « INVALIDE »). Puis les trois altérations **non détectées** : `user_agent` (§7), `created_at` (§8), suppression de la dernière ligne (§9). Puis l'insert sans `prev_hash` (§10, détecté) et la purge de tête (§11, rouge à jamais). Puis l'introspection du planificateur (§12 : `output = '/dev/null'`, 0 hook) et le cloisonnement (§13 : 0 politique RLS, 0 scope global). Puis les comptes AI Act (§14). |
| `02_script-cloisonnement.php` | Script rejouable : secret de chaîne, fuite inter-espaces, empreinte de mot de passe. |
| `02_cloisonnement-et-secret.txt` | **A** : `AUDIT_HASH_CHAIN_SECRET` = chaîne vide (longueur 0). **B** : un utilisateur de l'espace B voit la ligne de l'espace A ; 0 scope global, 0 politique RLS, rôle applicatif SUPERUSER+BYPASSRLS, 0 `authorize()` dans le contrôleur. **C** : l'empreinte du corps d'une connexion est un SHA-256 non salé du mot de passe. (La section D échoue : `part_config` est dans le schéma `partman`, mesuré à part — cf. fichier 03.) |
| `03_etat-base-audit-et-ai-act.txt` | Schéma de `audit_logs` (`prev_hash DEFAULT repeat('0',64) NOT NULL`), `relrowsecurity = f`, 0 politique RLS, et `partman.part_config` : `retention = 24 months`, `retention_keep_table = t`, 14 partitions. |
| `04_contenu-reel-du-journal-avant-reconstruction.txt` | Contenu réel de `audit_logs` sur `axion_crm` à 12:00 UTC : 80 lignes, **toutes de type `POST`**, 11 chemins, 72/80 sans espace, 52/80 sans acteur. Et `ai_act_register = 0`, `llm_usage = 0`, `business_events = 0`. ⚠️ Relevé transcrit : un agent parallèle a relancé un `migrate:fresh` sur `axion_crm` entre 12:05 et 12:30 UTC. |
