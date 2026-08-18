import csv, io, re, statistics, subprocess, sys
from collections import defaultdict

csv_path = sys.argv[1]
start_line = int(sys.argv[2])

# --- HTTP ---
rows = defaultdict(list)
codes = defaultdict(set)
sizes = {}
for r in csv.reader(io.open(csv_path, encoding="utf-8")):
    if len(r) < 5:
        continue
    name, i, code, t, size = r
    rows[name].append(float(t))
    codes[name].add(code)
    sizes[name] = int(size)

def p(v, q):
    v = sorted(v)
    k = max(0, min(len(v) - 1, int(round(q * (len(v) - 1)))))
    return v[k]

base = rows.get("baseline_features", [0])
base_p50 = statistics.median(base) if base else 0
print("## HTTP (secondes) — séquentiel, serveur PHP de dev sur montage Windows ; le delta soustrait la ligne de base « features »")
print("| endpoint | n | codes | p50 | p95 | p50 − base | p95 − base | taille |")
print("|---|---|---|---|---|---|---|---|")
for name in ["baseline_features","hub_actifs_p1","hub_actifs_clients","hub_froids","hub_tous","hub_recherche_prefixe","hub_counts","timeline_personne","recherche_globale","export_clients_csv"]:
    v = rows.get(name)
    if not v:
        continue
    p50, p95 = statistics.median(v), p(v, 0.95)
    print(f"| {name} | {len(v)} | {','.join(sorted(codes[name]))} | {p50:.2f} | {p95:.2f} | {p50-base_p50:+.2f} | {p95-base_p50:+.2f} | {sizes[name]} o |")

# --- SQL from postgres logs ---
log = subprocess.run(["docker", "logs", "axion-crm-postgres"], capture_output=True, text=True, errors="replace").stdout.splitlines()
log = log[start_line:]
pat = re.compile(r"\[axion_crm_perf\] LOG:\s+duration: ([\d.]+) ms\s+(?:statement|execute [^:]+): (.*)")
stmts = []
buf = None
for line in log:
    m = pat.search(line)
    if m:
        if buf:
            stmts.append(buf)
        buf = [float(m.group(1)), m.group(2)]
    elif buf and (line.startswith("\t") or line.startswith("  ")):
        buf[1] += " " + line.strip()
if buf:
    stmts.append(buf)

def classify(sql):
    s = re.sub(r"\s+", " ", sql.lower())
    if "from \"activities\"" in s and "person_key" in s: return "timeline: activities par person_key"
    if "count(*)" in s and "group by \"relation_type\"" in s: return "counts: group by relation_type, lifecycle_stage"
    if "from \"companies\"" in s and "ilike" in s: return "hub: recherche préfixe (ILIKE)"
    if "from \"companies\"" in s and "order by" in s and "limit" in s: return "hub: page de 50 (curseur)"
    if "from \"companies\"" in s and "not exists" in s: return "hub: froids (NOT EXISTS src humaine)"
    if "from \"contacts\"" in s and "company_id" in s and " in (" in s: return "hub: eager contacts"
    if "from \"tags\"" in s and "company_tag" in s: return "hub: eager tags"
    if "from \"companies\"" in s and "relation_type" in s and "limit" not in s: return "export: companies (stream)"
    if "personal_access_tokens" in s: return "auth: jeton"
    if "from \"users\"" in s: return "auth: user"
    if "user_workspaces" in s or "model_has_roles" in s or "permissions" in s: return "auth: rôles/permissions"
    return "autre"

by = defaultdict(list)
for d, sql in stmts:
    by[classify(sql)].append(d)
print()
print(f"## SQL côté Postgres (ms) — {len(stmts)} requêtes journalisées pendant la mesure (log_min_duration_statement=0)")
print("| requête | n | p50 | p95 | max |")
print("|---|---|---|---|---|")
for k, v in sorted(by.items(), key=lambda kv: -p(kv[1], 0.95)):
    print(f"| {k} | {len(v)} | {statistics.median(v):.1f} | {p(v,0.95):.1f} | {max(v):.1f} |")
