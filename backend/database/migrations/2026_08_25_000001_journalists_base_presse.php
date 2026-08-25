<?php

use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BASE PRESSE — les colonnes qui manquaient à `journalists` pour tenir un
 * fichier de relations presse, et non plus seulement un résidu de scraping.
 *
 * ── Ce que la mesure du 2026-08-25 a montré (production) ───────────────────
 *   - `media`       : 55 830 lignes. Peuplée, mais ce n'est PAS un annuaire de
 *                     rédactions : majoritairement des entités juridiques SIRENE
 *                     (« STUDIO TF1 FICTION »), plus des émissions importées de
 *                     Wikidata (« Journal de 20 heures de TF1 »), plus quelques
 *                     vraies marques (« TF1 », « 01net »). Une recherche « TF1 »
 *                     rend 22 candidats dont 2 seulement sont la rédaction visée
 *                     — et « TF1 » y figure DEUX fois, « TF1 SERIES FILMS » /
 *                     « TF1 Séries Films » aussi.
 *   - `journalists` : 1 257 lignes, **0 email**, 0 opt-out. Leur nature : des
 *                     crédits d'émissions (« présentateur », « producteur »),
 *                     pas des contacts presse.
 *
 * Deux conséquences portées par cette migration :
 *   1. le rattachement à un média NE PEUT PAS être automatique — d'où
 *      `media_raw`, qui garde la chaîne d'origine telle quelle tant qu'un
 *      humain n'a pas tranché. On n'invente pas un `media_id` ;
 *   2. la population « contacts presse » doit rester distinguable des 1 257
 *      crédits existants — d'où `source = 'linkedin'` et les colonnes de
 *      ciblage, qui n'ont de sens que pour elle.
 *
 * ── `acces` : la colonne qui empêche de griller le fichier ────────────────
 * Elle ne dit pas d'où vient le contact (c'est `source`), elle dit par quelle
 * PORTE on l'atteint. C'est un filtre d'envoi, pas une étiquette :
 *   - `email_redaction`  (125 contacts) — seul bucket diffusable par mailing ;
 *   - `redaction_prod`   (116) — on passe par la production de l'émission ;
 *                         un communiqué envoyé en direct brûle le contact ;
 *   - `linkedin_direct`  (107) — message manuel, jamais d'email ;
 *   - `a_qualifier`      (64)  — hors diffusion tant que ce n'est pas tranché.
 * Liste FERMÉE par un CHECK : ce n'est pas un réglage de console, c'est une
 * règle de diffusion. La confondre avec un motif d'échange (table ouverte,
 * cf. `crm_activites`) serait la rendre modifiable par erreur.
 *
 * ── `lien_linkedin` : un état, pas un booléen ─────────────────────────────
 * LinkedIn n'a pas deux états mais cinq qui commandent un geste différent. Un
 * booléen « ami oui/non » écrase « demande envoyée le 12/08, sans réponse
 * depuis 13 jours » — précisément l'état qu'on veut piloter. La date qui
 * l'accompagne n'est pas décorative : sans elle, le champ pourrit en silence.
 *
 * `connecte` se remplit par rapprochement de l'export officiel des relations
 * LinkedIn (Paramètres → Confidentialité des données → Obtenir une copie de
 * vos données), rejoué périodiquement. Le scraping du réseau ferait fermer le
 * compte ; l'export est gratuit, exhaustif et sans risque.
 *
 * ── `dedup_key` : INDEX SIMPLE ICI, UNIQUE PLUS TARD — et pourquoi ────────
 * La clé reprend le mécanisme éprouvé de `contacts.normalized_hash`, avec une
 * différence imposée par le terrain : `media_id` est NULL pour la quasi-totalité
 * des fiches tant que le rattachement n'a pas eu lieu. Or PostgreSQL considère
 * deux NULL comme DISTINCTS dans un index unique : une clé portant `media_id`
 * seul ne dédoublonnerait donc RIEN avant rattachement — exactement quand on en
 * a besoin. D'où le `coalesce(media_id, normalize_name(media_raw), '')`.
 *
 * L'index est créé NON UNIQUE, délibérément. Poser un UNIQUE sur 1 257 lignes
 * live dont on n'a pas mesuré les collisions, c'est une migration qui échoue en
 * production — et l'ancien index (`workspace_id, media_id, last_name,
 * first_name` WHERE last_name IS NOT NULL) laissait justement passer les fiches
 * sans nom de famille. On mesure d'abord, on contraint ensuite. La requête de
 * mesure est en commentaire au pied de `up()` ; le UNIQUE fera l'objet d'une
 * migration dédiée, une fois les collisions résorbées.
 *
 * ⚠️ Effet attendu au rattachement : renseigner `media_id` CHANGE la clé. Deux
 * fiches jusque-là distinctes peuvent alors devenir identiques. C'est voulu —
 * c'est le moment où le doublon se révèle — et l'écran de rattachement doit le
 * présenter comme une fusion à confirmer, jamais comme une erreur technique.
 *
 * PUREMENT ADDITIVE : aucune colonne existante n'est modifiée ni supprimée,
 * l'ancien index de dédoublonnage est CONSERVÉ. `down()` rend l'état d'avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Identité LinkedIn ----------
        // Stocké NORMALISÉ (le slug seul : « prenom-nom-1a2b3c »), jamais l'URL
        // brute. Copiée depuis LinkedIn, la même personne arrive sous quatre
        // formes (www./fr./?trk=…/?originalSubdomain=…) : sans normalisation, la
        // colonne ne peut pas servir de clé, et c'est le mode de doublon n°1.
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS linkedin_slug TEXT');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS journalists_linkedin_slug_uidx
             ON journalists (workspace_id, linkedin_slug) WHERE linkedin_slug IS NOT NULL',
        );

        // ---------- Média non résolu ----------
        // La chaîne d'origine du fichier source, telle quelle. Sur les 412
        // contacts du fichier presse, 47 % seulement portent un séparateur
        // exploitable, et le découpage naïf fabrique de faux médias
        // (« Journaliste », « Freelance », « Pigiste »). On conserve donc la
        // vérité de la source et on résout à la main.
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS media_raw TEXT');

        // ---------- Porte d'accès (règle de diffusion) ----------
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS acces TEXT');
        DB::statement('ALTER TABLE journalists DROP CONSTRAINT IF EXISTS journalists_acces_check');
        DB::statement(
            'ALTER TABLE journalists ADD CONSTRAINT journalists_acces_check
             CHECK (acces IS NULL OR acces IN (' . Taxonomy::sqlList(Taxonomy::ACCES_PRESSE) . '))',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS journalists_workspace_acces_idx
             ON journalists (workspace_id, acces) WHERE acces IS NOT NULL',
        );

        // ---------- Relation LinkedIn ----------
        DB::statement("ALTER TABLE journalists ADD COLUMN IF NOT EXISTS lien_linkedin TEXT NOT NULL DEFAULT 'inconnu'");
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS lien_linkedin_le DATE');
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS lien_linkedin_verifie_le DATE');
        DB::statement('ALTER TABLE journalists DROP CONSTRAINT IF EXISTS journalists_lien_linkedin_check');
        DB::statement(
            'ALTER TABLE journalists ADD CONSTRAINT journalists_lien_linkedin_check
             CHECK (lien_linkedin IN (' . Taxonomy::sqlList(Taxonomy::LIENS_LINKEDIN) . '))',
        );

        // ---------- Timeline : six gestes de presse qui n'existaient pas ----------
        // `activities.kind` est un vocabulaire FERMÉ par un CHECK reconstruit
        // depuis `Taxonomy::ACTIVITY_KINDS` (cf. migration du 2026-08-14). Y
        // ajouter les gestes de presse sans reconstruire le CHECK ferait diverger
        // le code de la base — et `SocleCrmTest` compare précisément les deux.
        // Les deux bougent donc ENSEMBLE, ici, dans la même migration.
        //
        // Sans ces valeurs, un communiqué envoyé n'a aucune ligne où se ranger :
        // la fiche journaliste resterait muette sur ce qu'on lui a adressé.
        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_kind_check');
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT activities_kind_check
             CHECK (kind IS NULL OR kind IN (' . Taxonomy::sqlList(Taxonomy::ACTIVITY_KINDS) . '))',
        );

        // ---------- Ciblage (aide au tri, pas un jugement) ----------
        // `priorite` 1..4 et `score` viennent du fichier source et sont datés :
        // ils reflètent un intitulé de poste et un nombre d'abonnés au jour de
        // la collecte. Un poste en rédaction change vite — d'où `collecte_le`,
        // sans lequel on relirait dans six mois un classement périmé en le
        // croyant actuel.
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS priorite SMALLINT');
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS score SMALLINT');
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS abonnes INTEGER');
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS collecte_le DATE');
        DB::statement('ALTER TABLE journalists DROP CONSTRAINT IF EXISTS journalists_priorite_check');
        DB::statement(
            'ALTER TABLE journalists ADD CONSTRAINT journalists_priorite_check
             CHECK (priorite IS NULL OR priorite BETWEEN 1 AND 4)',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS journalists_workspace_priorite_idx
             ON journalists (workspace_id, priorite) WHERE priorite IS NOT NULL',
        );

        // ---------- Attributs qui appartiennent au MÉDIA, garés ici ----------
        // `portee` et `support` décrivent le média, pas la personne : ils ont
        // déjà leur place en face (`media.diffusion_zone`, `media.media_type`).
        // Ils stationnent sur la fiche tant que `media_id` est NULL, et seront
        // PROMUS vers le média au rattachement. Le suffixe `_raw` dit qu'ils
        // sont en transit — les appeler `portee` tout court laisserait croire
        // qu'ils sont une propriété du journaliste, et ils y resteraient.
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS media_portee_raw TEXT');
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS media_support_raw TEXT');

        // ---------- Clé de dédoublonnage (index simple — cf. en-tête) ----------
        DB::statement(<<<'SQL'
            ALTER TABLE journalists ADD COLUMN IF NOT EXISTS dedup_key TEXT
            GENERATED ALWAYS AS (
                encode(digest(
                    normalize_name(coalesce(first_name, '') || '_' || coalesce(last_name, ''))
                    || '@' ||
                    coalesce(media_id::TEXT, normalize_name(coalesce(media_raw, '')), ''),
                    'sha256'
                ), 'hex')
            ) STORED
        SQL);
        DB::statement(
            'CREATE INDEX IF NOT EXISTS journalists_dedup_key_idx ON journalists (workspace_id, dedup_key)',
        );

        // ---------- Commentaires (le dépôt n'est pas le seul lecteur) ----------
        DB::statement("COMMENT ON COLUMN journalists.linkedin_slug IS 'Slug LinkedIn NORMALISE (prenom-nom-1a2b3c), sans www/fr/?trk. Cle d''identite la plus fiable quand elle est connue.'");
        DB::statement("COMMENT ON COLUMN journalists.media_raw IS 'Chaine media/specialite d''origine, non decoupee. Fait foi tant que media_id est NULL — on ne devine pas un media.'");
        DB::statement("COMMENT ON COLUMN journalists.acces IS 'Par quelle PORTE on atteint le contact. Filtre d''envoi : seul email_redaction est diffusable par mailing.'");
        DB::statement("COMMENT ON COLUMN journalists.lien_linkedin IS 'Etat de la relation LinkedIn. Rempli par rapprochement de l''export officiel des relations ; jamais par scraping.'");
        DB::statement("COMMENT ON COLUMN journalists.dedup_key IS 'sha256(nom normalise @ media_id OU media_raw normalise). NON UNIQUE tant que les collisions du stock ne sont pas mesurees — cf. en-tete de la migration.'");

        // ── Requête de mesure des collisions, à jouer AVANT de poser le UNIQUE :
        //
        //   SELECT dedup_key, count(*) AS n,
        //          array_agg(coalesce(first_name,'') || ' ' || coalesce(last_name,'')) AS fiches
        //   FROM journalists
        //   WHERE deleted_at IS NULL
        //   GROUP BY workspace_id, dedup_key
        //   HAVING count(*) > 1
        //   ORDER BY n DESC;
    }

    /**
     * Les 16 valeurs de `ACTIVITY_KINDS` D'AVANT cette migration, recopiées en
     * dur. `down()` ne peut pas les relire dans `Taxonomy` : la constante y
     * porte désormais les six gestes de presse, et s'y référer reconstruirait le
     * CHECK AVEC ce qu'on est en train de retirer — un `down()` qui ne défait
     * rien. Une liste figée est ici la seule qui dise la vérité.
     *
     * @var list<string>
     */
    private const ACTIVITY_KINDS_AVANT = [
        'form_submission', 'calendly_booked', 'calendly_completed', 'calendly_no_show',
        'calendly_canceled', 'review_posted', 'newsletter_optin', 'newsletter_optout',
        'application_submitted', 'stage_changed', 'reclassified', 'scraped',
        'enriched', 'opt_out', 'gdpr_export', 'gdpr_erasure',
    ];

    public function down(): void
    {
        // ⚠️ Les activités déjà consignées avec un `kind` de presse violeraient
        // le CHECK restauré. On les neutralise (`kind = NULL`, colonne
        // nullable) plutôt que de les supprimer : le `down()` d'une migration
        // ne doit jamais détruire une trace d'échange avec un journaliste.
        DB::statement(
            'UPDATE activities SET kind = NULL
             WHERE kind IS NOT NULL AND kind NOT IN (' . Taxonomy::sqlList(self::ACTIVITY_KINDS_AVANT) . ')',
        );
        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_kind_check');
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT activities_kind_check
             CHECK (kind IS NULL OR kind IN (' . Taxonomy::sqlList(self::ACTIVITY_KINDS_AVANT) . '))',
        );

        DB::statement('DROP INDEX IF EXISTS journalists_dedup_key_idx');
        DB::statement('DROP INDEX IF EXISTS journalists_workspace_priorite_idx');
        DB::statement('DROP INDEX IF EXISTS journalists_workspace_acces_idx');
        DB::statement('DROP INDEX IF EXISTS journalists_linkedin_slug_uidx');

        DB::statement('ALTER TABLE journalists DROP CONSTRAINT IF EXISTS journalists_priorite_check');
        DB::statement('ALTER TABLE journalists DROP CONSTRAINT IF EXISTS journalists_lien_linkedin_check');
        DB::statement('ALTER TABLE journalists DROP CONSTRAINT IF EXISTS journalists_acces_check');

        foreach ([
            'dedup_key', 'media_support_raw', 'media_portee_raw', 'collecte_le',
            'abonnes', 'score', 'priorite', 'lien_linkedin_verifie_le',
            'lien_linkedin_le', 'lien_linkedin', 'acces', 'media_raw', 'linkedin_slug',
        ] as $column) {
            DB::statement("ALTER TABLE journalists DROP COLUMN IF EXISTS {$column}");
        }
    }
};
