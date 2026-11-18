# VERDICTS — l'écrivain des verdicts d'adresse (cahier de conception)

Conception Opus du 16/11/2026, vérifiée sur `main` (tête `11bce50`). Deux cahiers pour un développeur seul :
**VERDICTS-MIGRATION** (une PR) puis **VERDICTS-CODE** (deux PR : CODE-1 puis CODE-2).
Rien ici n'est du code à copier tel quel : ce sont des contrats. Tout chemin cité a été relu.

Règles qui ne bougent pas : `ENVOI_REEL_ACTIF` reste faux ; aucun drapeau touché ; aucun appel réseau autre que
le DNS ; **jamais de connexion SMTP vers un serveur de destinataire** ; adresses de test en `*.example.test` ;
migrations en ajout seul.

---

## 0. Le constat, vérifié (et corrigé)

1. **Il n'existe aucun écrivain persistant.** Le seul `insert` dans `envoi_verdicts_adresse` hors tests est
   `backend/app/Envoi/Orchestration/EssaiABlanc.php:271` (source `essai`), et il a lieu DANS la transaction de
   l'essai, annulée à la fin (`EssaiABlanc.php:95` `beginTransaction`, `:104` `rollBack`). En base réelle, la table
   reste vide.
2. **La porte exige un verdict « valide » de moins de 30 jours calendaires** :
   `backend/app/Envoi/Gardes/EnvoiAutorise.php:37` (`VERDICT_MAX_JOURS = 30`), `:172-174` (refus
   `verdict_adresse_absent_ou_perime`), `:260-281` (le DERNIER verdict fait foi ; un verdict daté du futur est
   refusé). La lecture filtre `workspace_id` et l'empreinte `ListeSuppression::empreinte(mb_strtolower(trim($email)))`
   (`:262-266`).
3. **Correction du constat : ce n'est pas un report sans fin, c'est pire.**
   - Aucun verdict, ou dernier verdict ≠ `valide` : refus **DÉFINITIF**. `RefusSequenceur.php:39` classe
     `VERDICT_ABSENT` parmi les définitifs ; `Sequenceur.php:393-406` (`passager`) ne reporte que si le dernier
     verdict est `valide` ; sinon `refuserSaufDejaParti` → `refuser` (`Sequenceur.php:571-583`) : l'inscription
     passe `arretee` (motif `garde:verdict_adresse_absent_ou_perime`) et **ne peut jamais être recréée**
     (contrainte unique espace/séquence/contact, `RefusSequenceur.php:11-12`).
   - Verdict `valide` périmé : report plafonné à `REPORTS_MAX = 10` (`Sequenceur.php:65`, `:586-601`), puis arrêt
     définitif + alerte.
   - Donc : **le verdict doit exister AVANT que l'inscription soit échue.** L'échéance de la 1ʳᵉ étape peut tomber
     dès l'inscription (`Inscriptions.php:44` : `$fenetre->echeance($maintenant, $premier)`).
4. **La porte demande AUSSI une vérification de la fiche du contact**, en amont du verdict :
   `EligibiliteAdresse.php:191-195` (`NON_VERIFIEE` si aucune occurrence `verification = valide`), alimentée par
   `LecteurFicheDb.php:110-116` (fiche `crm:emails:verifier` dans `contacts.metadata`, ou à défaut
   `email_status = 'valid'`). La tâche hebdomadaire `crm:emails:verifier` est FERMÉE par défaut
   (`routes/console.php:423-433`, `CRM_EMAILS_VERIFICATION_PLANIFIEE`). Ce lot n'y touche pas : voir ESCALADE 3.
5. **Tout l'outillage sans coût existe déjà** et doit être RÉUTILISÉ, jamais recopié :
   - règles pures : `app/Crm/Emails/QualificationEmail.php` (`syntaxeValide` :81, `domaine` :59, `estJetable` :103
     → `MxEmailValidator::DISPOSABLE_DOMAINS` :38, `type` :114 générique/nominatif) ;
   - conclusion DNS : `app/Crm/Emails/VerificationEmail.php:114` (`conclure`) et `:133` (`demandeLeDns`) ;
   - cache DNS global par domaine, daté, sans adresse : `app/Crm/Emails/CacheDomaines.php:33` (`fraiches`) et `:69`
     (`enregistrer`, n'enregistre jamais un `indetermine`) ; table `email_domaines`
     (`2026_09_30_000020_verification_des_emails.php:34-45`) ;
   - résolveur : interface `app/Crm/Emails/Dns/ResolveurDns.php` (« JAMAIS de sondage SMTP »), implémentation
     `ResolveurDnsUdp.php` (constructeur :36, `serveurParDefaut` :54) ; en test, `AppServiceProvider.php:97-99` lie
     `ResolveurDnsInterdit` (toute résolution lève) et les tests installent `tests/Support/ResolveurDnsSimule.php`
     (`toutVerifier` :108, `repondre` :118, domaine témoin géré) ;
   - verdicts DNS : `app/Crm/Emails/Dns/ResultatDns.php` (`recoit()` : vrai / faux / null pour `indetermine`).
6. **La table convient déjà** (`2026_11_10_000020_envoi_verdicts_adresse.php`) : verdict en liste fermée
   (`valide|invalide|risque|inconnu`), `source` libre, index de lecture `(workspace_id, empreinte_adresse,
   verifie_le DESC)`, RLS FORCÉE par espace, rôle applicatif : `SELECT, INSERT` seulement (:36-41). Il lui manque
   seulement le **pourquoi** (aucun motif) : d'où la petite migration.
7. Points d'entrée prêts dans la PR #606 (V0, ouverte) : `app/Providers/Envoi/VerdictsAdresseServiceProvider.php` et
   `routes/console/envoi-verdicts.php`. **VERDICTS-CODE ne part qu'après la fusion de #606.**

---

## 1. Les choix de conception

### 1.1 Le vérificateur INTERNE (sans coût, sans sonder personne)
Classe `App\Envoi\Verdicts\VerificateurInterne`, source écrite `interne-v1`. Pour une adresse, première règle qui
répond, dans cet ordre :

| # | Règle | Lecture | Verdict | Motif |
|---|---|---|---|---|
| 1 | Syntaxe | `QualificationEmail::syntaxeValide` | `invalide` | `syntaxe` |
| 2 | Domaine jetable | `QualificationEmail::estJetable(domaine)` | `invalide` | `jetable` |
| 3 | Rebond dur connu | `email_suppressions` (scope `business`, `email_hash` = empreinte, `reason = 'hard_bounce'`) ; OU `envoi_inscriptions_evenements` de l'espace, `evenement = 'rebond_dur'`, même empreinte ; OU `contacts.email_status = 'invalid'` d'un contact de l'espace portant l'adresse | `invalide` | `rebond_dur` |
| 4 | Adresse technique | partie locale (avant `+`) dans la liste fermée `ROLES_TECHNIQUES` = `postmaster, abuse, noreply, no-reply, mailer-daemon, hostmaster, webmaster, root, spam` | `invalide` | `role_technique` |
| 5 | Adresse générique | `QualificationEmail::type() === 'generique'` | `risque` | `generique` (ESCALADE 1) |
| 6 | DNS | `CacheDomaines::fraiches([domaine], 7)`, sinon résolution (quota, §1.3) | `valide` si `recoit() === true` ; `invalide` si `false` | `mx` / `a` ; `mx_nul` / `sans_courrier` / `inexistant` |
| 7 | DNS sans réponse | `indetermine`, quota épuisé, résolveur absent | **rien n'est écrit** | — |

Pourquoi les plaintes, STOP et oppositions ne sont PAS ici : un verdict dit si l'adresse **reçoit**, pas si on a le
**droit** d'écrire. La porte refuse déjà ces cas avec le BON motif (`EnvoiAutorise.php:169-171` liste de
suppression ; `:196-198` art. 21, STOP, liste de refus), et ce motif se propage à l'entreprise
(`Evenement::MOTIF_GARDE_OPPOSITION`). Un verdict `risque` à leur place produirait un arrêt « verdict absent » et
perdrait cette propagation. Une adresse avec plainte reçoit donc un verdict `valide` si son domaine reçoit : la porte
la refuse quand même.

Date `verifie_le` : pour la règle 6, la date de résolution du domaine (`resolu_le` du cache), jamais « maintenant »
(un résultat de 6 jours ne doit pas paraître neuf) ; pour les règles 1 à 5, `maintenant`.

Garde du résolveur (même idée que `CrmEmailsVerifier.php:181`, domaine témoin
`crm.emails_verification.domaine_temoin`) : avant d'écrire un verdict DNS NÉGATIF (`mx_nul`, `sans_courrier`,
`inexistant`) obtenu par une résolution de ce passage, résoudre le domaine témoin ; s'il ne rend pas `mx`, tous les
négatifs du passage deviennent « rien n'est écrit » (une panne de DNS ne rend jamais une adresse invalide).

### 1.2 L'emplacement du vérificateur EXTERNE (non écrit)
- Interface `App\Envoi\Verdicts\VerificateurAdresse` (§3.2). Le fournisseur `VerdictsAdresseServiceProvider` lie
  `VerificateurAdresse::class` à `VerificateurInterne::class`, **et à rien d'autre**.
- Aucune classe externe, aucune clé de configuration, aucune variable d'environnement, aucune dépendance Composer
  dans ce lot. Le jour où le fondateur choisit, un lot dédié ajoutera `VerificateurExterne…` qui implémente la même
  interface, appelé seulement APRÈS l'interne et seulement pour les adresses que l'interne juge `valide`.
- Un test de garde (§5, T-CODE-1.9) relit `app/Envoi/Verdicts/**` et échoue s'il trouve `Http::`, `curl_`,
  `fsockopen`, `stream_socket_client`, `RCPT`, `SmtpProber`, `Guzzle`.
- **ESCALADE 2** : choix d'un vérificateur payant (fournisseur, budget, sous-traitant au sens du RGPD, lieu de
  traitement, contrat). Rien ne bloque d'ici là.

### 1.3 Quand et comment les verdicts sont écrits
Deux chemins, un seul écrivain (`EcrivainVerdicts`) :

1. **Avant l'inscription (chemin principal)** : `PreparationVerdicts::pourContacts()` (§3.4). La matérialisation des
   campagnes (lot L4, pas encore écrit) l'appelle AVANT `Inscriptions::inscrire` et **n'inscrit que les contacts
   dont le verdict est `valide` et âgé de moins de 20 jours**. Les autres ne sont pas inscrits (aucune place unique
   brûlée) ; L4 les compte `adresse_non_verifiee` et les reprendra au passage suivant. → **À reporter dans le
   cahier L4** (ce lot ne touche aucun fichier de L4).
2. **Rafraîchissement planifié borné** : commande `envoi:verdicts:rafraichir`, toutes les heures (minute 17). Pour
   chaque espace actif (corbeille exclue) qui a le produit Envoi (`ProduitsEspace::actif($ws, 'envoi')`), sous
   `WorkspaceContext::run($ws, …)` : les inscriptions `active` dont `prochaine_echeance <= maintenant + 3 jours`
   (index `idx_envoi_inscriptions_echeance` existant) et dont le dernier verdict est absent, ou `valide` âgé de
   20 jours ou plus. Elle tourne même porte d'envoi fermée : elle n'envoie rien, et sans inscription active elle
   ne fait aucune requête DNS (cas de la production aujourd'hui).

Bornes (constantes de classe, aucune variable d'environnement) :

| Constante | Valeur | Rôle |
|---|---|---|
| `RAFRAICHIR_APRES_JOURS` | 20 | marge de 10 jours avant les 30 de la porte (≥ 10 reports) |
| `CACHE_DNS_JOURS` | 7 | âge maximal d'un domaine repris du cache `email_domaines` |
| `HORIZON_JOURS` | 3 | inscriptions échues dans les 3 jours |
| `ADRESSES_MAX_PAR_ESPACE` | 500 | par passage |
| `DOMAINES_DNS_MAX_PAR_PASSAGE` | 200 | résolutions réseau, tous espaces confondus |
| `DOMAINES_DNS_MAX_PAR_JOUR` | 2000 | compteur `Cache::increment('envoi:verdicts:dns:' . Y-m-d)` ; cache indisponible = plus aucune résolution (fermé) |
| `BORNE_SECONDES` | 120 | aucun nouvel espace commencé au-delà |
| résolveur | parallèle 4, débit 20/s, délai 3 000 ms, 2 essais | `new ResolveurDnsUdp(...)` hors test |

Écriture : **INSERT seulement** (le rôle applicatif n'a ni UPDATE ni DELETE). Pas de nouvelle ligne si le dernier
verdict de l'adresse a le même `verdict`, la même `source`, le même `motif` et moins de `RAFRAICHIR_APRES_JOURS`.
Un `invalide` récent n'est jamais « blanchi » par un `valide` d'une autre source dans le même passage : la règle 3
est rejouée à chaque fois.

Cloisonnement : verdicts par espace (RLS FORCÉE, `workspace_id` toujours posé, `WorkspaceContext::current() === $ws`
vérifié AVANT tout insert, sinon exception). Le cache `email_domaines` est global et ne contient que des noms de
domaine (choix déjà fait par `CacheDomaines`). `email_suppressions` est globale par conception.

Échec fermé partout : exception de lecture ou d'écriture → l'espace est compté en erreur, rien n'est écrit pour
lui, les autres continuent ; journal = compteurs seulement (jamais d'adresse, jamais d'empreinte, classe
d'exception seule, comme `ChefOrchestre.php:86-89`).

### 1.4 Garde de schéma (le déploiement remplace le code AVANT `migrate`)
- Planification : `->when(fn () => Schema::hasTable('envoi_verdicts_adresse') && Schema::hasColumn('envoi_verdicts_adresse', 'motif') && Schema::hasTable('email_domaines'))`.
- Commande lancée à la main sans le schéma : message clair, code de sortie 0, rien d'écrit.
- `PreparationVerdicts::pourContacts` sans la colonne `motif` : lève `RuntimeException` (L4 n'inscrit alors rien).

---

## 2. Cahier VERDICTS-MIGRATION (PR « migration seule », à fusionner et déployer AVANT CODE-1)

Branche `feat/verdicts-migration` depuis `main`. Titre : `feat(envoi): VERDICTS migration — motif des verdicts d'adresse`.

### Fichiers autorisés (et seulement eux)
1. `backend/database/migrations/2026_12_05_000500_envoi_verdicts_adresse_motif.php` (nouveau)
2. `backend/tests/Feature/Envoi/VerdictsMotifMigrationTest.php` (nouveau)
3. `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` : UNE entrée ajoutée à `NR17_RETOUR_INERTE_VOULU`
   (ligne « Filet modifié : pourquoi » dans la PR)
4. `docs/envoi/lots/VERDICTS-MIGRATION.md` (nouveau, 30 lignes au plus)

### SQL de `up()` (rejouable, ajout seul)
```sql
ALTER TABLE envoi_verdicts_adresse ADD COLUMN IF NOT EXISTS motif TEXT NULL;
-- contrainte posée seulement si absente (bloc DO sur pg_constraint), NOT VALID : aucune ligne relue
ALTER TABLE envoi_verdicts_adresse ADD CONSTRAINT envoi_verdicts_adresse_motif_ck
  CHECK (motif IS NULL OR motif IN ('syntaxe','jetable','rebond_dur','role_technique','generique',
                                    'mx','a','mx_nul','sans_courrier','inexistant')) NOT VALID;
COMMENT ON COLUMN envoi_verdicts_adresse.motif IS
  'VERDICTS : pourquoi ce verdict (liste fermée). NULL pour les lignes antérieures. Ajout seul.';
```
Puis, si le rôle applicatif existe (même bloc que `2026_11_10_000020…:36-41`, nom quoté) :
`GRANT SELECT, INSERT, UPDATE ON public.email_domaines TO <role>` (UPDATE nécessaire au
`ON CONFLICT DO UPDATE` de `CacheDomaines::enregistrer`). Aucun `GRANT` sur `envoi_verdicts_adresse` (déjà
`SELECT, INSERT`, et rien de plus).

`down()` : INERTE (un `COMMENT ON COLUMN … 'conservée au retour arrière'`). Aucun `DROP`, aucun `DELETE`.

### Ce qu'il ne faut PAS faire
- Pas de déclencheur « ajout seul » sur la table : des tests existants font `->update(['verdict' => 'invalide'])`
  en propriétaire (`SequenceurTest.php:290`, `PuisJeEcrireTest.php:90`).
- Pas de contrainte sur `source` (tests et essai écrivent `test`, `essai`, `zz-etancheite`).
- Pas d'index : `idx_envoi_verdicts_adresse_lecture` et `idx_envoi_inscriptions_echeance` suffisent.
- Ne pas toucher `tests/Support/SemeurTablesScopees.php` : colonne nullable, le semis (`:567`) reste valable.
- Ne pas toucher aux numéros hors `2026_12_05_000500` à `000590`.

### Tests (rouges d'abord : écrire, lancer, voir échouer faute de migration, puis écrire la migration)
- M1 : après `migrate`, la colonne `motif` existe et est nullable ; une ligne sans motif s'insère.
- M2 : un motif hors liste est refusé (`QueryException`) ; chacun des 10 motifs passe.
- M3 : `up()` joué deux fois sans erreur.
- M4 : sous `pgsql_app` (modèle : `tests/Feature/Crm/ProvenanceTiersRoleApplicatifTest.php`, sans
  `RefreshDatabase`, nettoyage propriétaire dans un `finally`) : INSERT dans `email_domaines` puis
  `INSERT … ON CONFLICT DO UPDATE` passent ; UPDATE de `envoi_verdicts_adresse` reste refusé.
- NR-17 vert grâce à l'entrée ajoutée (commentaire : « VERDICTS : colonne motif nullable + CHECK NOT VALID ;
  la retirer effacerait le pourquoi des verdicts ; down() inerte, up() rejouable »).

Commandes : `cd backend && vendor/bin/pint --dirty && vendor/bin/pest --filter='VerdictsMotifMigration|NR-17'`.

---

## 3. Cahier VERDICTS-CODE

Préalables : VERDICTS-MIGRATION **fusionnée ET déployée** ; #606 (V0) fusionnée. Deux PR en série, chacune depuis
`main` (jamais l'une sur l'autre).

### 3.1 Dossier `backend/app/Envoi/Verdicts/` (nouveau)

### 3.2 Signatures
```php
final class VerdictAdresse {           // valeur immuable
    public const VALIDE = 'valide'; public const INVALIDE = 'invalide'; public const RISQUE = 'risque';
    public const MOTIFS = [/* les 10 motifs de la migration, même ordre */];
    public function __construct(
        public readonly string $verdict,             // VALIDE|INVALIDE|RISQUE (jamais 'inconnu' : on n'écrit pas)
        public readonly string $motif,               // in MOTIFS, sinon InvalidArgumentException
        public readonly DateTimeImmutable $verifieLe,
    ) {}
}

interface VerificateurAdresse {
    /** Nom écrit dans `source` (≤ 32 caractères, [a-z0-9-]). */
    public function source(): string;
    /**
     * @param  list<string>  $adresses  adresses en clair, de l'espace courant
     * @return array<string, VerdictAdresse|null>  clé = ListeSuppression::empreinte($adresse) ; null = ne sait pas
     */
    public function verifier(string $ws, array $adresses, DateTimeImmutable $maintenant): array;
}

final class VerificateurInterne implements VerificateurAdresse {
    public const SOURCE = 'interne-v1';
    public const ROLES_TECHNIQUES = ['postmaster','abuse','noreply','no-reply','mailer-daemon','hostmaster','webmaster','root','spam'];
    public const CACHE_DNS_JOURS = 7;
    public function __construct(private readonly QuotaDns $quota, private readonly \Closure $resolveur) {} // Closure(): ResolveurDns, appelée au 1er besoin seulement
}

final class QuotaDns {                   // compteurs par passage et par jour (Cache), fermé si le cache lève
    public function __construct(int $maxParPassage = 200, int $maxParJour = 2000) {}
    /** @param list<string> $domaines @return list<string> ceux qu'on a le droit de résoudre maintenant */
    public function accorder(array $domaines, DateTimeImmutable $maintenant): array;
}

final class EcrivainVerdicts {
    public const RAFRAICHIR_APRES_JOURS = 20;
    /** @param array<string, VerdictAdresse|null> $verdicts @return int lignes insérées */
    public function ecrire(string $ws, array $verdicts, string $source, DateTimeImmutable $maintenant): int;
}

final class PreparationVerdicts {         // ce que L4 appellera
    /** @param list<int> $contactIds @return array<int, VerdictAdresse|null> contact => DERNIER verdict utilisable (après écriture) */
    public function pourContacts(string $ws, array $contactIds, DateTimeImmutable $maintenant): array;
    /** Vrai si ce verdict permet d'inscrire maintenant : VALIDE et âge < RAFRAICHIR_APRES_JOURS. */
    public static function permetInscription(?VerdictAdresse $v, DateTimeImmutable $maintenant): bool;
}

final class RafraichissementVerdicts {
    public const HORIZON_JOURS = 3; public const ADRESSES_MAX_PAR_ESPACE = 500; public const BORNE_SECONDES = 120;
    /** @return array<string, int> compteurs seulement : espaces, adresses, ecrits, sans_reponse, erreurs, hors_borne */
    public function passage(DateTimeImmutable $maintenant, ?string $espace = null, bool $essai = false): array;
}
```
Règles de code : `DB::table(...)` sur la connexion par défaut (jamais `pgsql_owner`) ; adresse lue dans
`contacts` de l'espace (`deleted_at IS NULL`) ; empreinte = `ListeSuppression::empreinte($email)` (même
normalisation que la porte) ; aucune adresse ni empreinte dans un journal, une exception ou une sortie console.

### 3.3 PR CODE-1 — vérificateur, écrivain, préparation (sans planification)
Branche `feat/verdicts-code-1`. Fichiers autorisés :
- `backend/app/Envoi/Verdicts/VerdictAdresse.php`, `VerificateurAdresse.php`, `VerificateurInterne.php`,
  `QuotaDns.php`, `EcrivainVerdicts.php`, `PreparationVerdicts.php` (nouveaux)
- `backend/app/Providers/Envoi/VerdictsAdresseServiceProvider.php` (créé par #606) : liaisons
  `VerificateurAdresse` → `VerificateurInterne` ; `QuotaDns` singleton par requête ; fermeture de résolveur :
  `app()->bound(ResolveurDns::class) ? app(ResolveurDns::class) : new ResolveurDnsUdp(ResolveurDnsUdp::serveurParDefaut(config('crm.emails_verification.resolveur')) ?? throw …, 4, 20, 3000, 2)`
- `backend/tests/Feature/Envoi/Verdicts/VerificateurInterneTest.php`, `EcrivainVerdictsTest.php`,
  `PreparationVerdictsTest.php`, `VerdictsSansReseauTest.php` (nouveaux)
- `docs/envoi/lots/VERDICTS-CODE-1.md` (nouveau)

### 3.4 PR CODE-2 — rafraîchissement planifié
Branche `feat/verdicts-code-2`, après la fusion de CODE-1. Fichiers autorisés :
- `backend/app/Envoi/Verdicts/RafraichissementVerdicts.php` (nouveau)
- `backend/app/Console/Commands/EnvoiVerdictsRafraichir.php` (nouveau) :
  `SIGNATURE_PLANIFIEE = 'envoi:verdicts:rafraichir'`, options `{--espace=} {--essai : calcule, n'écrit rien}` ;
  code 0 si tout va bien, 1 si au moins un espace en erreur.
- `backend/routes/console/envoi-verdicts.php` (créé par #606) :
  `Schedule::command(EnvoiVerdictsRafraichir::SIGNATURE_PLANIFIEE)->hourlyAt(17)->withoutOverlapping(30)->onOneServer()->when(<garde §1.4>)->onFailure(<Log::critical, compteurs seulement>)`
- `backend/tests/Feature/NonRegression/instantanes/nr07-taches-planifiees.json` et `nr08-taches-sous-rls.json` :
  régénérés par `NR_REGENERER_INSTANTANES=1` ; diff attendu = UNE entrée chacun (ligne « Filet modifié :
  pourquoi » dans la PR)
- `backend/tests/Feature/Envoi/Verdicts/RafraichissementVerdictsTest.php`, `VerdictsRoleApplicatifTest.php`,
  `VerdictsPorteBoutEnBoutTest.php` (nouveaux)
- `docs/envoi/lots/VERDICTS-CODE-2.md` (nouveau)

### 3.5 Fichiers chauds : NE PAS TOUCHER (aucune des deux PR)
`app/Providers/EnvoiServiceProvider.php`, `routes/console.php` (les points d'entrée de #606 suffisent),
`app/Envoi/Sequence/Sequenceur.php`, `RefusSequenceur.php`, `Inscriptions.php`, `Fiche.php`, `LecteurFicheDb.php`,
`app/Envoi/Gardes/EnvoiAutorise.php`, `app/Envoi/Orchestration/*` (dont `EssaiABlanc.php`),
`app/Crm/Emails/**` (on appelle, on ne modifie pas), `app/Console/Commands/CrmEmailsVerifier.php`,
`app/Providers/AppServiceProvider.php`, `config/*.php` (aucune nouvelle clé, aucun `env()`, NR-16 intact),
`tests/Support/SemeurTablesScopees.php`, `tests/Support/ResolveurDnsSimule.php`, `nr20-routes.json` (aucune route),
`LISTE.json`. Aucune route HTTP, aucun écran dans ce lot.

---

## 4. Ordre
1. VERDICTS-MIGRATION → relecture → fusion → **déploiement vérifié** (colonne présente en production).
2. #606 fusionnée.
3. CODE-1 → relecture + audit données Opus → fusion.
4. CODE-2 → relecture + audit sécurité Opus → fusion.
5. Cahier L4 : appeler `PreparationVerdicts::pourContacts` avant `inscrire` (n'inscrire que si
   `permetInscription`) ; compteur `adresse_non_verifiee`.
6. Avant B6/I1 : le scénario de bout en bout doit inclure « adresse sans verdict → non inscrite » et « verdict
   rafraîchi à J20 → l'étape 2 part ».

---

## 5. Tests (rouges d'abord) et mutations

Tous avec `ResolveurDnsSimule` installé explicitement (sinon `ResolveurDnsInterdit` lève) et
`Http::preventStrayRequests()`. Adresses : `prenom@societe-a.example.test`, etc. Deux espaces « Espace A » et
« Espace B ».

**CODE-1**
- T1.1 syntaxe invalide → `invalide/syntaxe`, aucun appel DNS (compteur du simulé = 0).
- T1.2 domaine jetable (pris dans `DISPOSABLE_DOMAINS`) → `invalide/jetable`, aucun appel DNS.
- T1.3 rebond dur par chacune des trois sources → `invalide/rebond_dur`, même si le DNS dit `mx`.
- T1.4 `postmaster@`, `no-reply+x@` → `invalide/role_technique` ; `contact@` → `risque/generique`.
- T1.5 DNS `mx` → `valide/mx`, `verifie_le` = date du cache ; DNS `inexistant` (témoin `mx`) → `invalide/inexistant`.
- T1.6 DNS `indetermine` ou panne (domaine en `$pannes`) → `null`, aucune ligne.
- T1.7 témoin qui ne rend pas `mx` → aucun verdict négatif écrit.
- T1.8 cache `email_domaines` frais (< 7 j) → aucune résolution ; vieux de 8 j → résolu de nouveau.
- T1.9 garde statique « aucun réseau autre que DNS » sur `app/Envoi/Verdicts/**` (§1.2).
- T1.10 quota : 201 domaines → 200 résolus, 1 sans réponse ; cache qui lève → 0 résolu.
- T1.11 écrivain : insert seul ; deuxième appel identique < 20 j → 0 ligne ; ≥ 20 j → 1 ligne.
- T1.12 écrivain hors `WorkspaceContext` de `$ws` → exception, 0 ligne.
- T1.13 `pourContacts` : contact de l'Espace B demandé depuis A → absent du résultat, rien d'écrit.
- T1.14 `permetInscription` : valide 19 j vrai ; 20 j faux ; `risque` faux ; `null` faux ; futur faux.

**CODE-2**
- T2.1 sans inscription active → 0 requête DNS, 0 ligne.
- T2.2 inscription échue dans 2 j sans verdict → verdict écrit ; dans 4 j → rien.
- T2.3 verdict valide de 21 j → nouveau verdict ; de 5 j → rien.
- T2.4 espace sans produit Envoi, ou en corbeille → ignoré.
- T2.5 erreur dans l'Espace A (résolveur qui lève une erreur non DNS) → B traité, code de sortie 1, journal sans adresse.
- T2.6 borne de temps (horloge injectée) → `hors_borne` compté.
- T2.7 `--essai` → 0 ligne, 0 écriture du cache.
- T2.8 garde de schéma : colonne `motif` absente (simulée par `Schema` faux dans le test de la planification) → tâche non due.
- T2.9 rôle applicatif (`pgsql_app`, modèle `ProvenanceTiersRoleApplicatifTest.php`) : écriture dans A visible de A,
  invisible de B ; insert sans contexte refusé par la RLS ; propriétaire voit tout.
- T2.10 bout en bout : verdict écrit pour `Zoe.Martin@Societe-A.example.test` (casse mélangée) → la garde de verdict
  de `EnvoiAutorise::verifier` passe (le refus rendu, s'il y en a un, n'est plus `verdict_adresse_absent_ou_perime`).
- NR-07 et NR-08 verts après régénération (une entrée chacun).

**Mutations à jouer à la main (chacune doit faire rougir au moins un test, à noter dans la PR)**
1. `indetermine` → `valide` (T1.6). 2. Supprimer la règle rebond dur (T1.3). 3. Supprimer la règle jetable (T1.2).
4. `verifie_le = $maintenant` pour le DNS (T1.5). 5. Retirer le contrôle du témoin (T1.7). 6. `RAFRAICHIR_APRES_JOURS`
à 30 (T1.11, T2.3). 7. Retirer la vérification `WorkspaceContext::current()` (T1.12, T2.9). 8. Oublier
`mb_strtolower` dans l'empreinte (T2.10). 9. `generique` → `valide` (T1.4). 10. Ignorer le quota (T1.10).
11. Remplacer l'insert par un `updateOrInsert` (T2.9 sous rôle applicatif). 12. Retirer le filtre d'horizon (T2.2).

Commandes : `cd backend && vendor/bin/pint --dirty && vendor/bin/pest --filter=Verdicts` puis
`vendor/bin/pest --group=non-regression`.

---

## 6. ESCALADE (décisions du fondateur ; rien ne bloque l'écriture du code)

- **ESCALADE 1 — adresses génériques** (`contact@`, `info@`) en B2B quand aucune adresse nominative n'existe.
  Par défaut ce lot les classe `risque/generique` : elles ne seront jamais inscrites. Si le fondateur dit oui, un
  petit lot change UNE règle (et le test T1.4).
- **ESCALADE 2 — vérificateur externe payant** : fournisseur, budget, contrat de sous-traitance, lieu de
  traitement. Emplacement prêt (interface), rien d'écrit, aucun appel.
- **ESCALADE 3 (production) — la vérification des fiches de contact** : la porte exige aussi une fiche vérifiée
  (`EligibiliteAdresse.php:191-195`). Elle vient de `crm:emails:verifier`, dont la planification hebdomadaire est
  fermée (`CRM_EMAILS_VERIFICATION_PLANIFIEE`), ou d'un `email_status = 'valid'`. Décider quand lancer la première
  exécution manuelle puis ouvrir la planification. Ce lot ne touche pas ce réglage.
- **ESCALADE 4 (production) — le résolveur DNS utilisé en production** (réglage `CRM_EMAILS_DNS_RESOLVEUR` ou
  résolveur du système). Seuls des noms de domaine en sortent, jamais une adresse.

## 7. Ce qui reste hors de ce lot
Écran « pourquoi une adresse est écartée » (lot B8, lira `motif`) ; appel par L4 ; purge des vieux verdicts (non
prévue : ajout seul) ; tout changement du séquenceur ou de la porte.
