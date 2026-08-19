# -*- coding: utf-8 -*-
"""Agent 12 — genere le tableau de grille 18 points, une ligne par route.

Les valeurs par defaut viennent de faits MESURES (route:list, lecture des 42
controleurs, sondes noyau). Les exceptions sont declarees route par route.
Aucune case n'est laissee vide : a defaut de mesure, on ecrit
« non verifie — <raison> ».
"""
import json, io, re

BS = chr(92)
d = json.load(open('route-list-api.json', encoding='utf-8'))
rows = [r for r in d if r['uri'].startswith('api/v1/') or r['uri'].startswith('api/internal/')]
rows.sort(key=lambda r: (r['uri'], r['method']))

cites = [l.strip().lstrip('/') for l in io.open('uris-citees-par-les-tests.txt', encoding='utf-8') if l.strip()]
STATIQUES = {r['uri'] for r in rows if '{' not in r['uri']}

def motif(uri):
    u = re.escape(uri).replace(re.escape('{any?}'), '(/.*)?')
    return re.compile('^' + re.sub(r'\\\{[^}]+\\\}', '[^/]+', u) + '$')

def couverte(uri):
    m = motif(uri)
    for c in cites:
        if c in STATIQUES and c != uri:
            continue
        if m.match(c):
            return True
    return False

# ---------------------------------------------------------------- defauts
NON_POLICY = 'non — 0 `authorize()`/`Gate::` dans les 42 controleurs'
PERM = 'partielle — middleware `permission:%s`, teste (403 mesure)'
NV = 'non verifie — %s'
BUDGET = NV % 'hors budget agent 12 (EXPLAIN non joue)'
AUDIT_OUI = 'oui — `AuditHashChainLogger` (methode/chemin/statut/hash du corps)'
AUDIT_NON = 'NON — le middleware n audite que POST/PUT/PATCH/DELETE'
DEBIT_NON = 'aucune — 0 `throttle`, limiteur `api` declare mais jamais attache'

# exceptions par (methode-groupe, uri) ; cle = uri
EX = {}

def ex(uri, **kw):
    EX.setdefault(uri, {}).update(kw)

# --- internes ---------------------------------------------------------------
ex('api/internal/scraper-result',
   p3='sans objet — canal machine, pas de session',
   p4='sans objet — n interroge aucune table',
   p5='aucune si `CRM_SCRAPE_FUNNEL_ENABLED=false` (defaut) ; `ScrapedRecord::fromArray` sinon',
   p6='aucun — le corps est journalise tel quel',
   p7='sans objet — aucune requete',
   p8='sans objet',
   p9='sans objet', p10='sans objet',
   p11='`{error:bad_signature}` 401 / `{ingested:true}` 200',
   p12='NON — rejeu a l identique accepte 3 fois sur 3 (mesure)',
   p13=AUDIT_OUI,
   p14='aucune donnee rendue',
   p15='AUCUNE — seule route interne sans `throttle:internal` (mesure : 9/9 sans 429)',
   p16='OUI mais FAIL-OPEN : HMAC inline, sans garde de secret vide ; secret `""` ⇒ signature forgee acceptee (200 mesure)',
   p18='vivante')
for u, n in (('api/internal/site-sync', 'SiteSync'), ('api/internal/site-sync/gdpr', 'SiteGdpr')):
    ex(u, p3='sans objet — canal machine', p4='sans objet',
       p5='contrat strict inline (422 sur champ inconnu / action / person_key / email / scope)' if 'gdpr' in u else 'pivot `SiteSyncEvent::fromArray` (422)',
       p6='oui — enum fermees, sha256 64 hex, fenetre 300 s',
       p7='sans objet — requetes parametrees', p8='sans objet', p9='sans objet', p10='sans objet',
       p11='`{error,message}` + 401/422/503/500 coherents',
       p12='partielle — fenetre de rejeu de 300 s ouverte, aucun nonce ; idempotence par `event_id` en aval',
       p13=AUDIT_OUI, p14='aucune donnee rendue (503, drapeau ferme)',
       p15='oui — `throttle:internal` 600/min/IP',
       p16='OUI et FAIL-CLOSED — `HmacSignature::verify` refuse un secret vide ; signature forgee REJETEE (401 mesure)',
       p18='inerte — 503 tant que `CRM_INGEST_ENABLED=false`')
ex('api/internal/email/zeptomail',
   p3='sans objet', p4='sans objet',
   p5='tolerante par conception — corps illisible ⇒ 200 compteurs a zero',
   p6='oui — evenements sur liste fermee, details tronques a 500 car.',
   p7='sans objet', p8='sans objet', p9='sans objet', p10='sans objet',
   p11='`{error,message}` 503/401 ; `{ok,counts}` 200',
   p12='oui — `ListeSuppression::inscrire` incremente au lieu de dupliquer',
   p13=AUDIT_OUI, p14='aucune donnee rendue',
   p15='oui — `throttle:internal` 600/min/IP',
   p16='jeton partage dans l URL (`?t=`), `hash_equals` — pas de HMAC, donc corps non authentifie ; jeton expose aux journaux d acces',
   p18='inerte — 503 tant que `MAIL_WEBHOOK_TOKEN` absent (mesure)')

# --- stubs 501 --------------------------------------------------------------
STUBS = {
 'api/v1/ai-act/register': ('POST', '11'), 'api/v1/rotations/{rotation}': ('PUT', '4'),
 'api/v1/notifications/{n}/read': ('POST', '10'), 'api/v1/notifications/read-all': ('POST', '10'),
 'api/v1/workspace': ('PUT', '3'), 'api/v1/saved-views': ('POST', '10'),
 'api/v1/saved-views/{saved_view}': ('*', '10'), 'api/v1/proxy-providers/{p}': ('PUT', '4'),
 'api/v1/llm/use-cases/{useCase}': ('PUT', '4'), 'api/v1/llm/use-cases/{useCase}/prompts/{v}': ('PUT', '4'),
 'api/v1/users': ('POST', '3'), 'api/v1/users/{user}': ('*', '3'),
 'api/v1/contacts/{contact}': ('PUT/DELETE', '5'),
 'api/v1/cold-email{any?}': ('*', 'Phase 2'), 'api/v1/linkedin{any?}': ('*', 'Phase 2'),
}
# --- « 200 qui ment » : reponse codee en dur -------------------------------
MENSONGES = {
 'api/v1/search': '`{companies:[],contacts:[],tags:[]}` en dur',
 'api/v1/dashboard/stats': 'tous les compteurs a 0 en dur',
 'api/v1/ai-act/register': '`{data:[]}` en dur (GET)',
 'api/v1/llm/usage': '`{data:[]}` en dur',
 'api/v1/llm/usage/summary': '`{summary:{total_eur:0}}` en dur',
 'api/v1/rotations': '`{data:[]}` en dur',
 'api/v1/notifications': '`{data:[]}` en dur',
 'api/v1/saved-views': '`{data:[]}` en dur (GET) — constat A-002 deja ouvert',
 'api/v1/llm/use-cases/{useCase}/prompts': '`{versions:[]}` en dur',
 'api/v1/proxy-providers/{p}/test': '`{healthy:true}` en dur — controle de sante qui repond toujours oui',
}

PERM_ROUTES = {
 'api/v1/companies/export': 'data.export', 'api/v1/media/export': 'data.export',
 'api/v1/journalists/export': 'data.export', 'api/v1/companies/tags/bulk': 'companies.update',
}
THROTTLES = {}
for r in rows:
    t = [m for m in r['middleware'] if 'ThrottleRequests' in m]
    if t:
        THROTTLES[(r['uri'], r['method'])] = t[0].split(':')[-1]

SCOPE_EXPLICITE = {
 'api/v1/companies', 'api/v1/companies/export', 'api/v1/contacts', 'api/v1/tags',
 'api/v1/tags/{tag}', 'api/v1/users', 'api/v1/audiences', 'api/v1/audiences/{audience}',
 'api/v1/audiences/{audience}/members', 'api/v1/audiences/{audience}/refresh',
 'api/v1/audiences/preview', 'api/v1/companies/tags/bulk', 'api/v1/coverage',
 'api/v1/coverage/cells/{cell}', 'api/v1/coverage/next-zone', 'api/v1/coverage/launch',
 'api/v1/coverage/enrich', 'api/v1/scraper-runs', 'api/v1/scraper-runs/{run}',
 'api/v1/scraper-runs/{run}/cancel', 'api/v1/scraper-runs/{run}/retry',
 'api/v1/campaigns', 'api/v1/campaigns/{campaign}', 'api/v1/campaigns/{campaign}/start',
 'api/v1/campaigns/{campaign}/pause', 'api/v1/campaigns/{campaign}/resume',
 'api/v1/campaigns/{campaign}/cancel', 'api/v1/campaigns/{campaign}/stats',
 'api/v1/crm/contacts-hub', 'api/v1/crm/contacts-hub/counts', 'api/v1/crm/candidates',
 'api/v1/crm/candidates/counts', 'api/v1/crm/persons/{personKey}/timeline',
 'api/v1/crm/arbitrage', 'api/v1/crm/arbitrage/{activityId}/attach',
 'api/v1/crm/arbitrage/{activityId}/dismiss', 'api/v1/crm/bulk',
 'api/v1/observability/summary', 'api/v1/journalists/export', 'api/v1/media/export',
}
RLS_SEULE = {
 'api/v1/companies/{company}', 'api/v1/companies/{company}/enrich',
 'api/v1/companies/{company}/recompute-score', 'api/v1/contacts/{contact}',
 'api/v1/media', 'api/v1/media/{media}', 'api/v1/journalists',
 'api/v1/journalists/{journalist}', 'api/v1/journalists/{journalist}/opt-out',
 'api/v1/rgpd/requests', 'api/v1/rgpd/requests/{req}/process', 'api/v1/audit-logs',
 'api/v1/proxy-providers', 'api/v1/llm/use-cases', 'api/v1/companies/bulk-enrich',
}

def ligne(r):
    uri, meth = r['uri'], r['method']
    e = EX.get(uri, {})
    mw = r['middleware']
    sanctum = any('Authenticate:sanctum' in m for m in mw)

    p1 = e.get('p1', 'oui — `auth:sanctum`' if sanctum else 'NON — route publique (voulu)')
    if uri in PERM_ROUTES:
        p2 = PERM % PERM_ROUTES[uri]
    else:
        p2 = e.get('p2', NON_POLICY)
    if 'p3' in e:
        p3 = e['p3']
    elif uri in SCOPE_EXPLICITE:
        p3 = 'oui — `where(workspace_id)` explicite + RLS'
    elif uri in RLS_SEULE:
        p3 = 'RLS SEULE — aucun filtre applicatif'
    elif not sanctum:
        p3 = 'sans objet — pas de session'
    else:
        p3 = 'sans objet — la reponse ne lit aucune table'

    if 'p4' in e:
        p4 = e['p4']
    elif uri == 'api/v1/companies/{company}':
        p4 = 'NON — FUITE MESUREE : 200 + fiche de l autre espace (B12-001)'
    elif uri in RLS_SEULE:
        p4 = NV % 'meme mecanisme que B12-001 (RLS seule, role BYPASSRLS) — fuite presumee, non jouee route par route'
    elif uri in SCOPE_EXPLICITE:
        p4 = 'oui par construction (filtre applicatif) — non joue route par route'
    else:
        p4 = 'sans objet — aucune donnee d espace rendue'

    p5 = e.get('p5')
    p6 = e.get('p6')
    p7 = e.get('p7', 'sans objet — pas de tri/filtre libre' if meth != 'GET|HEAD' else 'sans objet')
    p8 = e.get('p8', 'sans objet — pas une liste')
    p9 = e.get('p9', 'sans objet — aucune relation chargee')
    p10 = e.get('p10', BUDGET)
    p11 = e.get('p11', '`{...}` via `ApiController::ok()`')
    p12 = e.get('p12', 'sans objet — pas un POST creant' if not meth.startswith('POST') else 'NON — aucune cle d idempotence')
    if 'p13' in e:
        p13 = e['p13']
    elif meth in ('POST', 'PUT', 'DELETE', 'PUT|PATCH'):
        p13 = AUDIT_OUI
    else:
        p13 = AUDIT_NON + ' — cette lecture ne laisse aucune trace'
    p14 = e.get('p14', 'aucune donnee personnelle rendue')
    if 'p15' in e:
        p15 = e['p15']
    else:
        t = THROTTLES.get((uri, meth))
        p15 = ('oui — `throttle:%s`' % t) if t else DEBIT_NON
    p16 = e.get('p16', 'sans objet — route non interne')
    if couverte(uri):
        p17 = 'route citee par au moins un test ; rougeur non verifiee par l agent 12 (suite non rejouee, conteneur sature)'
    else:
        p17 = 'AUCUN test ne cite cette route'
    if 'p18' in e:
        p18 = e['p18']
    elif uri in STUBS and (STUBS[uri][0] in ('*', meth) or meth in STUBS[uri][0]):
        p18 = 'FACTICE — 501 « a implementer en Sprint %s »' % STUBS[uri][1]
    elif uri in MENSONGES and meth == 'GET|HEAD':
        p18 = 'FACTICE MAIS 200 — ' + MENSONGES[uri]
    else:
        p18 = 'vivante'

    return [meth, '`/' + uri.replace('api/', '', 1) + '`', p1, p2, p3, p4,
            p5 or '?', p6 or '?', p7, p8, p9, p10, p11, p12, p13, p14, p15, p16, p17, p18]

# -------- valeurs p5/p6/p8/p9/p11/p14 par route (validation & co) ----------
V = {}
def v(uri, meth, p5, p6, p8='sans objet — pas une liste', p9='sans objet', p14='aucune donnee personnelle rendue', p11=None, p7=None, p12=None):
    V[(uri, meth)] = dict(p5=p5, p6=p6, p8=p8, p9=p9, p14=p14, p11=p11, p7=p7, p12=p12)

# (rempli dans grille_data.py pour rester lisible)
try:
    from grille_data import remplir
    remplir(ex, v)
    from grille_data import remplir_suite
    remplir_suite(ex, v)
except Exception as exc:  # pragma: no cover
    print('donnees complementaires absentes :', exc)

out = []
COLS = ['Methode', 'Route', '1 Authentification', '2 Autorisation (policy) verifiee ET testee',
        '3 Contexte d espace', '4 Autre espace ⇒ 0 ligne (test qui rougit)',
        '5 Validation des entrees', '6 Types / bornes / defauts',
        '7 Injection SQL, tri et filtres arbitraires', '8 Pagination obligatoire',
        '9 N+1', '10 Index derriere la requete (EXPLAIN)',
        '11 Codes et forme de reponse', '12 Idempotence des POST creant',
        '13 Journal d audit', '14 Donnees personnelles dans la reponse',
        '15 Limitation de debit', '16 Signature (routes internes)',
        '17 Test automatise, vu rouge', '18 Morte / factice / dupliquee']
out.append('| ' + ' | '.join(COLS) + ' |')
out.append('|' + '|'.join(['---'] * len(COLS)) + '|')
for r in rows:
    l = ligne(r)
    e2 = V.get((r['uri'], r['method']))
    if e2:
        idx = {'p5': 6, 'p6': 7, 'p7': 8, 'p8': 9, 'p9': 10, 'p11': 12, 'p12': 13, 'p14': 15}
        for k, i in idx.items():
            if e2.get(k):
                l[i] = e2[k]
    out.append('| ' + ' | '.join(str(x).replace('|', '/') for x in l) + ' |')

io.open('grille-generee.md', 'w', encoding='utf-8').write('\n'.join(out))
print('%d lignes ecrites' % (len(out) - 2))
manquants = [o for o in out[2:] if '| ? |' in o]
print('cases vides restantes : %d' % len(manquants))
for m in manquants[:200]:
    print('   ', m.split('|')[2].strip(), m.split('|')[1].strip())
