# -*- coding: utf-8 -*-
"""Valeurs de grille route par route, lues dans les 42 controleurs."""

AUCUNE = 'AUCUNE — pas de FormRequest, pas de `validate()` dans la methode'
SANSCORPS = 'sans objet — ni corps ni parametre de requete lu'
PAG_NON = 'NON — liste rendue sans pagination'
PII_NON = 'aucune donnee personnelle rendue'


def remplir(ex, v):
    # ---------------- auth ------------------------------------------------
    v('api/v1/auth/login', 'POST',
      'oui — `LoginRequest` (le seul FormRequest d authentification)',
      'oui — email/`max:254`, mot de passe `min:12`, `remember` booleen',
      p11='422 validation / 419 sans session / 429 ; SANS en-tete JSON : **302 vers la racine de l API** (mesure)',
      p12='sans objet — n ouvre qu une session', p14='rend l identite du compte connecte (necessaire)')
    v('api/v1/auth/logout', 'POST', SANSCORPS, 'sans objet',
      p11='204 avec un corps `{ok:true}` — un 204 ne doit pas porter de corps', p12='oui — idempotent')
    v('api/v1/auth/me', 'GET|HEAD', SANSCORPS, 'sans objet', p14='identite + roles du compte connecte (necessaire)')
    v('api/v1/auth/onboarding/complete', 'POST', SANSCORPS, 'sans objet',
      p12='oui — garde `if (onboarding_tour_completed_at === null)`')
    v('api/v1/auth/2fa/setup', 'POST', SANSCORPS, 'sans objet',
      p14='rend le SECRET TOTP et le QR (necessaire a l enrolement)', p12='NON — chaque appel regenere un secret')
    v('api/v1/auth/2fa/confirm', 'POST', 'inline — `code` requis, `size:6`', 'oui',
      p14='rend les codes de secours (necessaire, une seule fois)', p12='NON')
    v('api/v1/auth/2fa/verify', 'POST', 'inline — `code` requis, chaine', 'partiel — aucune longueur imposee',
      p11='422 `ValidationException` sur code invalide', p12='sans objet')
    v('api/v1/auth/magic-link', 'POST', 'inline — `email` requis, `email`, `max:254`', 'oui',
      p11='200 `{sent:true}` quelle que soit l existence du compte (anti-enumeration, voulu)', p12='sans objet')
    v('api/v1/auth/magic-link/verify', 'POST', 'inline — `token` requis, `size:64`', 'oui',
      p11='401 `{error:invalid_or_expired_token}`', p12='oui — jeton a usage unique')
    v('api/v1/auth/password/forgot', 'POST', 'inline — `email` requis, `email`, `max:254`', 'oui',
      p11='200 `{sent:true}` toujours (anti-enumeration)',
      p14='ecrit le lien de reinitialisation EN CLAIR dans le journal quand `MOCK_MODE` (lu par `env()`, hors config)',
      p12='sans objet — `updateOrInsert` sur l adresse')
    v('api/v1/auth/password/reset', 'POST',
      'inline — email, `token` `size:64`, mot de passe `confirmed` + `Password::min(12)` + `NotPwnedPassword`', 'oui',
      p11='401 jeton invalide / **404 `user_not_found`** — un code distinct la ou 401 suffisait', p12='oui — jeton consomme')
    # ---------------- rgpd ------------------------------------------------
    v('api/v1/rgpd/export/{token}', 'GET|HEAD', 'aucune sur le jeton (aucun format impose)',
      'aucune — un jeton de longueur quelconque est accepte',
      p11='404 `{error:invalid_or_expired_token}` (voulu : jamais 401)',
      p14='REND L EXPORT DE PORTABILITE COMPLET — c est son objet ; possession du jeton (48 car., hache en base, 7 jours)')
    v('api/v1/rgpd/requests', 'GET|HEAD', 'aucune sur `status` (valeur libre passee a `where`)',
      'aucune — pas d enumeration fermee', p8='oui — `paginate(25)`',
      p11='rend le PAGINATEUR BRUT Laravel (`links`, `from`, `to`…), forme differente du reste de l API',
      p14='rend `subject_email` des demandes (necessaire au traitement)')
    v('api/v1/rgpd/requests', 'POST', 'inline — `type` enum fermee, `subject_email` email, `metadata` array',
      'oui pour type/email ; `metadata` non borne (tableau libre persiste tel quel)',
      p12='NON — deux appels identiques creent deux demandes', p14='enregistre l adresse de la personne concernee')
    v('api/v1/rgpd/requests/{req}/process', 'POST', SANSCORPS, 'sans objet',
      p11='409 `{error:already_processed}` / 200', p12='oui — garde sur `status === done`',
      p14='DECLENCHE UN EFFACEMENT OU UN EXPORT — aucune permission exigee, tout compte authentifie peut le faire')
    # ---------------- workspace / users ------------------------------------
    v('api/v1/workspace', 'GET|HEAD', SANSCORPS, 'sans objet',
      p11='rend le MODELE `Workspace` brut, sans projection ni resource', p14=PII_NON)
    v('api/v1/users', 'GET|HEAD', SANSCORPS, 'sans objet',
      p8='NON — `limit(200)` en dur, aucune meta, aucun curseur',
      p9='sans objet — projection de colonnes explicite',
      p14='rend email et nom des collegues (necessaire), non masques',
      p11='`{data:[…]}`, sans `meta` la ou les autres listes en ont un')
    # ---------------- companies -------------------------------------------
    v('api/v1/companies', 'GET|HEAD', 'aucune sur `per_page` ni sur `filter[]` (bornage a la main)',
      'oui — `per_page` borne a [1,100] ; filtres et tris sur listes FERMEES (`CompanyQueryFilters`, 4 tris)',
      p7='oui — `spatie/query-builder` : colonnes de tri et de filtre en liste fermee, aucune colonne libre',
      p8='oui — `paginate($perPage)` + `meta`',
      p9='non mesure sur cette route — aucun `with()`, donc pas de relation chargee',
      p14='email et telephone MASQUES si le compte n a pas `contacts.view_pii` (mesure : masques pour un `viewer`)')
    v('api/v1/companies/export', 'GET|HEAD', 'aucune sur les filtres (memes filtres fermes que la liste)',
      'oui — memes listes fermees',
      p7='oui — memes listes fermees',
      p8='sans objet — flux CSV ; **AUCUN plafond de lignes** : `chunkById(1000)` deroule tout l espace',
      p9='oui — `with([contacts, healthPractitioners])`',
      p11='flux `text/csv` (pas de JSON) — coherent avec les deux autres exports',
      p14='NOMS, EMAILS, TELEPHONES en clair, JAMAIS masques ; opposes exclus (`EligibiliteCampagne`) ; **et la lecture n est PAS auditee** (le journal n enregistre que les ecritures)')
    v('api/v1/companies', 'POST', 'inline — `siren` requis `size:9` + `regex:/^d{9}$/`, denomination, source',
      'oui', p12='NON — deux appels creent deux fiches du meme SIREN', p14=PII_NON)
    v('api/v1/companies/{company}', 'GET|HEAD', SANSCORPS, 'sans objet',
      p9='oui — `load([contacts, tags, healthPractitioners])`',
      p14='**FUITE MESUREE** : email de l entreprise ET emails/telephones des contacts rendus EN CLAIR a un `viewer`, alors que la LISTE les masque (B12-002)')
    v('api/v1/companies/{company}', 'PUT', 'inline — priorite (enum), denomination, url, telephone, linkedin',
      'oui — `Rule::in`, `url`, `max:`', p14=PII_NON,
      p11='rend le modele brut mis a jour')
    v('api/v1/companies/{company}', 'DELETE', SANSCORPS, 'sans objet',
      p11='204 ; la documentation annonce un « soft-delete (deleted_at pose) » — c est un **DELETE DEFINITIF** (`Company` n utilise pas `SoftDeletes`)',
      p12='sans objet', p14=PII_NON)
    v('api/v1/companies/{company}/enrich', 'POST', SANSCORPS, 'sans objet',
      p9='oui — `load(contacts)`', p12='NON — chaque appel relance la cascade payante',
      p14='rend les contacts en clair, non masques')
    v('api/v1/companies/bulk-enrich', 'POST', 'inline — `ids` requis, tableau, `max:500`, `ids.*` entier',
      'oui pour la forme ; **aucun controle d appartenance** : un identifiant d un autre espace est mis en file tel quel',
      p12='NON — chaque appel remet 500 travaux en file', p14=PII_NON)
    v('api/v1/companies/{company}/recompute-score', 'POST', SANSCORPS, 'sans objet',
      p7='oui — `DB::statement(..., [$id])` parametre', p12='oui — recalcul deterministe', p14=PII_NON)
    v('api/v1/companies/tags/bulk', 'POST',
      'inline — `ids` [1,500] entiers, `tag` chaine, `action` `in:add,remove`', 'oui',
      p12='oui — `insertOrIgnore` sur la cle (company_id, tag_id)', p14=PII_NON)
    # ---------------- contacts ---------------------------------------------
    v('api/v1/contacts', 'GET|HEAD', 'aucune sur `per_page` ni `filter[]`',
      'oui — `per_page` borne a [1,100], filtres/tris en listes fermees',
      p7='oui — `spatie/query-builder`, 3 tris autorises, tri par defaut `-id` adosse a un index',
      p8='oui — `paginate` + `meta` + `appends`',
      p9='oui — `with(company:id,denomination)`',
      p14='email et telephone MASQUES sans `contacts.view_pii` ; projection explicite')
    v('api/v1/contacts/{contact}', 'GET|HEAD', SANSCORPS, 'sans objet',
      p14='**rend le MODELE BRUT, sans masquage et sans projection** — toute colonne future sort au fil de l eau')
    v('api/v1/contacts/{contact}', 'PUT', 'sans objet — 501', 'sans objet', p14=PII_NON)
    v('api/v1/contacts/{contact}', 'DELETE', 'sans objet — 501', 'sans objet', p14=PII_NON)
    # ---------------- media / journalists ----------------------------------
    v('api/v1/media', 'GET|HEAD', 'aucune sur `per_page` ni `filter[]`',
      'oui — `per_page` borne a [1,100], filtres/tris fermes',
      p7='oui — `spatie/query-builder`', p8='oui — `paginate` + `meta`',
      p9='sans objet — aucune relation chargee',
      p14='**email et telephone de redaction rendus EN CLAIR** — aucun `MasquageCoordonnees` sur cette liste, contrairement a `/companies` et `/contacts`')
    v('api/v1/media/export', 'GET|HEAD', 'aucune (memes filtres fermes)', 'oui',
      p8='sans objet — flux CSV, aucun plafond de lignes', p9='sans objet',
      p11='flux `text/csv`',
      p14='email et telephone en clair ; opposes exclus (`EligibiliteCampagne`) ; lecture non auditee')
    v('api/v1/media/{media}', 'GET|HEAD', SANSCORPS, 'sans objet',
      p9='oui — `load([journalists,parent,children,company])`',
      p14='**modele brut + journalistes rattaches, en clair, sans masquage**')
    v('api/v1/journalists', 'GET|HEAD', 'aucune sur `per_page` ni `filter[]`',
      'oui — `per_page` borne a [1,100], filtres/tris fermes',
      p7='oui — `spatie/query-builder`', p8='oui — `paginate` + `meta`',
      p9='`allowedIncludes(media)` — chargement a la demande',
      p14='**donnees personnelles de journalistes (nom, email, telephone) rendues EN CLAIR** — aucun masquage')
    v('api/v1/journalists/export', 'GET|HEAD', 'aucune (memes filtres fermes)', 'oui',
      p8='sans objet — flux CSV, aucun plafond', p9='oui — `with(media)`', p11='flux `text/csv`',
      p14='nom, email, telephone ; `opt_out` local + `EligibiliteCampagne` ; lecture non auditee')
    v('api/v1/journalists/{journalist}', 'GET|HEAD', SANSCORPS, 'sans objet',
      p9='oui — `load(media)`', p14='**modele brut, en clair, sans masquage**')
    v('api/v1/journalists/{journalist}/opt-out', 'POST', SANSCORPS, 'sans objet',
      p12='oui — pose `opt_out = true`, rejouable',
      p14='rend la fiche complete du journaliste, en clair')
    v('api/v1/journalists/{journalist}', 'DELETE', SANSCORPS, 'sans objet',
      p11='204 — effacement RGPD par `SoftDeletes` (le modele `Journalist` en porte, contrairement a `Company`)',
      p14=PII_NON)
    # ---------------- coverage ---------------------------------------------
    v('api/v1/coverage', 'GET|HEAD', 'aucune sur `level` (valeur libre, aiguillee par `match`)',
      'partiel — `match` a branche par defaut ; cache 60 s par espace',
      p7='oui — requetes SQL brutes PARAMETREES (`?`)', p8=PAG_NON + ' (agregat de cellules)',
      p9='sans objet — SQL brut agrege')
    v('api/v1/coverage/next-zone', 'GET|HEAD', 'aucune sur `preferred_dept`', 'aucune — chaine libre',
      p7='oui — parametre lie')
    v('api/v1/coverage/launch', 'POST', 'inline — `validate()` present', 'oui',
      p12='NON — chaque appel lance une collecte payante')
    v('api/v1/coverage/enrich', 'POST', 'inline — `validate()` present', 'oui',
      p12='NON — chaque appel relance un enrichissement payant')
    v('api/v1/coverage/cells/{cell}', 'GET|HEAD', SANSCORPS, 'sans objet', p7='oui — parametre lie')
    # ---------------- scraper runs -----------------------------------------
    v('api/v1/scraper-runs', 'GET|HEAD', SANSCORPS, 'aucune — `paginate(25)` fixe, `per_page` non lu',
      p8='oui — `paginate(25)` + `meta`', p9='sans objet',
      p11='`{data,meta}` ; modeles bruts')
    v('api/v1/scraper-runs/{run}', 'GET|HEAD', SANSCORPS, 'sans objet',
      p11='404 (et non 403) sur un run d un autre espace — voulu')
    v('api/v1/scraper-runs/{run}/cancel', 'POST', SANSCORPS, 'sans objet',
      p11='422 `{error:invalid_state,message,status}` / 404 / 200', p12='oui — 422 si deja hors etat annulable')
    v('api/v1/scraper-runs/{run}/retry', 'POST', SANSCORPS, 'sans objet',
      p11='201 avec le MODELE BRUT (`response()->json`, hors `ok()`)',
      p12='NON — chaque appel cree un nouveau run')
    # ---------------- llm / proxies / rotations ----------------------------
    for u, m in (('api/v1/llm/use-cases', 'GET|HEAD'), ('api/v1/proxy-providers', 'GET|HEAD')):
        v(u, m, SANSCORPS, 'sans objet',
          p8='NON — `limit(50)` en dur, aucune meta',
          p11='`{data:[…]}` ou `{data:[],degraded:true}` en cas d exception (200 malgre l echec)',
          p14='rend les MODELES BRUTS de configuration (jetons/identifiants de fournisseur si presents en colonne)')
    v('api/v1/llm/usage', 'GET|HEAD', SANSCORPS, 'sans objet', p8='sans objet — reponse vide en dur')
    v('api/v1/llm/usage/summary', 'GET|HEAD', SANSCORPS, 'sans objet')
    v('api/v1/llm/use-cases/{useCase}', 'PUT', 'sans objet — 501', 'sans objet')
    v('api/v1/llm/use-cases/{useCase}/prompts', 'GET|HEAD', SANSCORPS, 'sans objet')
    v('api/v1/llm/use-cases/{useCase}/prompts/{v}', 'PUT', 'sans objet — 501', 'sans objet')
    v('api/v1/proxy-providers/{p}', 'PUT', 'sans objet — 501', 'sans objet')
    v('api/v1/proxy-providers/{p}/test', 'POST', SANSCORPS, 'sans objet',
      p12='sans objet', p11='200 `{healthy:true}` INCONDITIONNEL')
    v('api/v1/rotations', 'GET|HEAD', SANSCORPS, 'sans objet')
    v('api/v1/rotations/{rotation}', 'PUT', 'sans objet — 501', 'sans objet')
    # ---------------- tags --------------------------------------------------
    v('api/v1/tags', 'GET|HEAD', 'aucune sur `category` ni `kind` (valeurs libres passees a `where`)',
      'aucune — pas d enumeration fermee sur les deux filtres',
      p8='NON — `limit(500)` en dur, aucune meta',
      p9='oui — comptage groupe en UNE requete (pas de N+1)', p7='oui — valeurs liees')
    v('api/v1/tags', 'POST', 'inline — slug `regex:/^[a-z0-9-]+$/`, nom requis, categorie `in:…`', 'oui',
      p11='409 `{error:"slug already exists",tag}` — le mot `error` porte une phrase anglaise libre, pas un code',
      p12='oui de fait — 409 sur slug existant')
    v('api/v1/tags/{tag}', 'PUT', 'inline — `sometimes` sur 4 champs, categorie `in:…`', 'oui',
      p11='404 / 403 `{error:"cannot update auto/llm tag"}` — phrases libres')
    v('api/v1/tags/{tag}', 'DELETE', SANSCORPS, 'sans objet',
      p11='200 `{ok:true}` la ou les autres suppressions rendent 204')
    # ---------------- saved views / search / notifications ------------------
    v('api/v1/saved-views', 'GET|HEAD', SANSCORPS, 'sans objet', p8='sans objet — reponse vide en dur')
    v('api/v1/saved-views', 'POST', 'sans objet — 501', 'sans objet')
    for m in ('GET|HEAD', 'PUT|PATCH', 'DELETE'):
        v('api/v1/saved-views/{saved_view}', m, 'sans objet — 501', 'sans objet')
    v('api/v1/search', 'GET|HEAD', 'aucune — `q` documente `required, minLength=2`, JAMAIS valide',
      'aucune', p8='sans objet — reponse vide en dur')
    v('api/v1/notifications', 'GET|HEAD', SANSCORPS, 'sans objet', p8='sans objet — reponse vide en dur')
    v('api/v1/notifications/{n}/read', 'POST', 'sans objet — 501', 'sans objet')
    v('api/v1/notifications/read-all', 'POST', 'sans objet — 501', 'sans objet')
    # ---------------- audiences ---------------------------------------------
    v('api/v1/audiences', 'GET|HEAD', SANSCORPS, 'sans objet',
      p8='NON — `limit(200)` en dur, aucune meta', p9='sans objet')
    v('api/v1/audiences', 'POST', 'oui — `StoreEmailAudienceRequest` (FormRequest)',
      'oui pour la forme ; `criteria` est un tableau LIBRE, interprete en aval',
      p12='NON — deux appels creent deux audiences homonymes')
    v('api/v1/audiences/preview', 'POST', 'inline — `criteria` requis, tableau',
      'aucune sur le CONTENU du tableau',
      p11='200 `{companies:0,contacts:0,error:"preview_failed"}` en cas d echec — un echec rendu 200')
    v('api/v1/audiences/{audience}', 'GET|HEAD', SANSCORPS, 'sans objet')
    v('api/v1/audiences/{audience}', 'PUT', 'inline — `sometimes` sur 5 champs', 'oui pour la forme')
    v('api/v1/audiences/{audience}', 'DELETE', SANSCORPS, 'sans objet',
      p11='200 `{ok:true}` la ou d autres suppressions rendent 204')
    v('api/v1/audiences/{audience}/refresh', 'POST', SANSCORPS, 'sans objet',
      p11='500 `{error:"refresh failed"}` via `ok(...,500)`', p12='oui — recalcul deterministe')
    v('api/v1/audiences/{audience}/members', 'GET|HEAD', 'aucune sur `limit`',
      'oui — `limit` borne a [1,500]',
      p8='NON — plafond `limit`, aucun curseur ni meta',
      p9='oui — deux jointures, une seule requete',
      p14='**rend `ct.email` des contacts EN CLAIR — aucun masquage**')
    # ---------------- ai-act / audit / observabilite ------------------------
    v('api/v1/ai-act/register', 'GET|HEAD', SANSCORPS, 'sans objet', p8='sans objet — reponse vide en dur')
    v('api/v1/ai-act/register', 'POST', 'sans objet — 501', 'sans objet')
    v('api/v1/audit-logs', 'GET|HEAD', SANSCORPS, 'aucune — `paginate(50)` fixe',
      p8='oui — `paginate(50)` + `meta`', p9='sans objet',
      p14='rend `ip` et `user_agent` de chaque requete mutative (donnees personnelles de salaries)')
    v('api/v1/audit-logs/verify-chain', 'GET|HEAD', SANSCORPS, 'sans objet',
      p11='200 `{valid:false,degraded:true}` en cas d echec — un echec de verification et une chaine rompue rendent le meme corps')
    v('api/v1/observability/summary', 'GET|HEAD', SANSCORPS, 'sans objet',
      p8='NON — `limit(50)` sur les evenements recents',
      p9='sans objet — 8 requetes d agregation',
      p11='`response()->json([data=>…])` — ce controleur etend `Controller`, pas `ApiController`',
      p14='deux compteurs (`google_places_quota`, `outbound`) sont GLOBAUX, hors espace — declare comme tel')
    v('api/v1/dashboard/stats', 'GET|HEAD', SANSCORPS, 'sans objet',
      p11='fermeture anonyme declaree DANS `routes/api.php` (ligne 86), hors de tout controleur')
    v('api/v1/config/features', 'GET|HEAD', SANSCORPS, 'sans objet')
    v('api/v1/referentiels/geo', 'GET|HEAD', SANSCORPS, 'sans objet',
      p8=PAG_NON + ' — ~120 lignes, cache 1 h', p9='sans objet')
    # ---------------- campagnes de collecte ---------------------------------
    v('api/v1/campaigns', 'GET|HEAD', 'aucune sur `status`/`search`/`per_page`',
      'partiel — bornage a la main dans le controleur',
      p8='oui — `paginate($perPage)`', p9='sans objet', p7='oui — valeurs liees')
    v('api/v1/campaigns', 'POST', 'oui — `StoreScrapingCampaignRequest` (FormRequest)', 'oui',
      p12='NON — deux appels creent deux campagnes')
    v('api/v1/campaigns/{campaign}', 'PUT', 'oui — `UpdateScrapingCampaignRequest` (FormRequest)', 'oui')
    v('api/v1/campaigns/{campaign}', 'GET|HEAD', SANSCORPS, 'sans objet', p9='oui — `limit(20)` sur les runs')
    v('api/v1/campaigns/{campaign}', 'DELETE', SANSCORPS, 'sans objet')
    for a in ('start', 'pause', 'resume', 'cancel'):
        v('api/v1/campaigns/{campaign}/' + a, 'POST', SANSCORPS, 'sans objet',
          p12='oui — transition d etat gardee (404/422 hors etat)')
    v('api/v1/campaigns/{campaign}/stats', 'GET|HEAD', SANSCORPS, 'sans objet', p8='sans objet — agregat',
      p9='oui — `limit(30)`')
    # ---------------- console CRM v2 ----------------------------------------
    v('api/v1/crm/contacts-hub', 'GET|HEAD',
      'oui — `validate()` inline sur 6 parametres, enumerations FERMEES (`Taxonomy`)',
      'oui — `per_page` borne a [1,200], `tags` `max:10`, `q` `max:120`, tris en liste fermee',
      p7='oui — tri par table `SORTS` fermee + `spatie/query-builder`',
      p8='oui — `cursorPaginate` + ordre total (`colonne DESC, id DESC`)',
      p9='oui — `with([contacts,tags])`, projection explicite',
      p14='email et telephone MASQUES sans `contacts.view_pii`')
    v('api/v1/crm/contacts-hub/counts', 'GET|HEAD', SANSCORPS, 'sans objet',
      p11='compteurs + `fresh_for_seconds` (fraicheur annoncee)')
    v('api/v1/crm/candidates', 'GET|HEAD',
      'oui — `validate()` inline, enumerations fermees (`Taxonomy` candidats)',
      'oui — `per_page` borne a [1,200], `tags` `max:10`, tris fermes',
      p7='oui — table `SORTS` fermee', p8='oui — `cursorPaginate` + ordre total',
      p9='oui — `with(tags)`',
      p14='**email et telephone des CANDIDATS rendus EN CLAIR** — `MasquageCoordonnees` n est pas applique dans l univers vivier, alors qu il l est dans l univers business (B12-002)')
    v('api/v1/crm/candidates/counts', 'GET|HEAD', SANSCORPS, 'sans objet')
    v('api/v1/crm/persons/{personKey}/timeline', 'GET|HEAD',
      'oui — `person_key` doit etre un sha256 64 hex, sinon 404', 'oui',
      p8='NON — `limit` en dur sur activites (constante) et sujets (20)', p9='sans objet — SQL cible',
      p14='rend la chronologie d une personne (objet de la route) ; masquage non applique')
    v('api/v1/crm/arbitrage', 'GET|HEAD', SANSCORPS, 'oui — `per_page` borne a [1,200]',
      p8='partielle — `limit($perPage)` + `total`, sans curseur ni page suivante',
      p9='sans objet', p14='rend `pending_match` : nom, email, telephone en attente de rapprochement')
    for a in ('attach', 'dismiss'):
        v('api/v1/crm/arbitrage/{activityId}/' + a, 'POST', 'inline — `company_id` valide cote controleur',
          'oui — `whereNumber(activityId)` sur la route',
          p11='409 deja rattache / 404 entreprise hors espace / 200',
          p12='oui — `lockForUpdate()` + 409 si `subject_id` deja pose')
    v('api/v1/crm/bulk', 'POST', 'oui — `validate()` inline (action fermee, ids bornes, tag exigeant)',
      'oui — enumerations fermees, tag devant preexister',
      p11='`{action,matched,updated,skipped,refused_regressions}`',
      p12='oui — `insertOrIgnore`, et `set_lifecycle` ne recule jamais')
    # ---------------- stubs Phase 2 ------------------------------------------
    for u in ('api/v1/cold-email{any?}', 'api/v1/linkedin{any?}'):
        v(u, 'GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS', 'sans objet — 501', 'sans objet',
          p11='501 `{error:not_implemented,message,sprint}`', p12='sans objet')


def remplir_suite(ex, v):
    v('api/v1/users', 'POST', 'sans objet — 501', 'sans objet')
    v('api/v1/users/{user}', 'PUT', 'sans objet — 501', 'sans objet')
    v('api/v1/users/{user}', 'DELETE', 'sans objet — 501', 'sans objet')
    v('api/v1/workspace', 'PUT', 'sans objet — 501', 'sans objet')
