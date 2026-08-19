# -*- coding: utf-8 -*-
"""Couverture NOMINALE des routes par les fichiers de test.

Methode : pour chaque route enregistree, on construit le motif de son URI
({param} -> un segment quelconque) et on regarde si une URI citee par un test
lui correspond. Rapprocher par simple normalisation des chiffres serait faux :
plusieurs tests citent des identifiants NON numeriques
(`/rgpd/export/invalid_token_123`, `/crm/persons/pas-un-sha256/timeline`).
"""
import json, re, io

d = json.load(open('route-list-api.json', encoding='utf-8'))
rows = [r for r in d if r['uri'].startswith('api/v1/') or r['uri'].startswith('api/internal/')]

cites = []
for line in io.open('uris-citees-par-les-tests.txt', encoding='utf-8'):
    u = line.strip().lstrip('/')
    if u:
        cites.append(u)


def motif(uri):
    # `{any?}` est un fourre-tout : il avale 0..n segments.
    u = re.escape(uri)
    u = u.replace(re.escape('{any?}'), '(/.*)?')
    u = re.sub(r'\\\{[^}]+\\\}', '[^/]+', u)
    return re.compile('^' + u + '$')


# URI STATIQUES du routeur : `/media/export` ne doit pas compter comme une
# citation de `/media/{media}` — ce sont deux routes differentes, et c'est
# exactement le piege que le fichier de routes signale (« /media/export DOIT
# preceder /media/{media} »).
STATIQUES = {r['uri'] for r in rows if '{' not in r['uri']}


def couverte(uri):
    m = motif(uri)
    for c in cites:
        if c in STATIQUES and c != uri:
            continue
        if m.match(c):
            return True
    return False


couvert, non = [], []
for r in sorted(rows, key=lambda r: (r['uri'], r['method'])):
    (couvert if couverte(r['uri']) else non).append('%-9s %s' % (r['method'], r['uri']))

out = ['ROUTES v1+internal : %d' % len(rows),
       'CITEES au moins une fois par un fichier de test : %d' % len(couvert),
       'JAMAIS citees par aucun test                    : %d' % len(non),
       '', '--- JAMAIS citees ---'] + non + ['', '--- citees ---'] + couvert
txt = '\n'.join(out)
io.open('couverture-tests.txt', 'w', encoding='utf-8').write(txt)
print('\n'.join(out[:60]))
