import json
BS = chr(92)
d = json.load(open('route-list-api.json'))
rows = [r for r in d if r['uri'].startswith('api/v1/') or r['uri'].startswith('api/internal/')]
rows.sort(key=lambda r: (r['uri'], r['method']))
out = [str(len(rows)) + ' routes v1+internal']
for r in rows:
    mw = [m for m in r['middleware'] if m != 'api']
    mw = [m.replace('Illuminate' + BS + 'Routing' + BS + 'Middleware' + BS + 'ThrottleRequests:', 'throttle:') for m in mw]
    act = r['action'].replace('App' + BS + 'Http' + BS + 'Controllers' + BS, '')
    out.append('%-9s %-46s | %-52s | %s' % (r['method'], r['uri'], act, ','.join(mw)))
open('middleware-par-route.txt', 'w', encoding='utf-8').write('\n'.join(out))
print('\n'.join(out))
