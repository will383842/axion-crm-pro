# Construit les tables de référence du chantier 1 (secteurs) à partir des fichiers officiels INSEE.
import xlrd, csv, re, os, collections, unicodedata
ICI = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(ICI, "sortie")
os.makedirs(OUT, exist_ok=True)

SECTEURS = [
 ("agriculture", "Agriculture, sylviculture, pêche"), ("agroalimentaire", "Agroalimentaire et boissons"),
 ("industrie", "Industrie"), ("energie", "Énergie"), ("eau_dechets", "Eau, déchets, dépollution"),
 ("btp", "Bâtiment et travaux publics"), ("automobile", "Automobile (commerce et réparation)"),
 ("commerce_gros", "Commerce de gros"), ("commerce_detail", "Commerce de détail"),
 ("transport_logistique", "Transport et logistique"), ("hebergement_tourisme", "Hébergement et tourisme"),
 ("restauration", "Restauration"), ("edition_medias", "Édition, audiovisuel, médias"),
 ("numerique_telecoms", "Numérique et télécoms"), ("banque_finance", "Banque et finance"), ("assurance", "Assurance"),
 ("immobilier", "Immobilier"), ("droit", "Droit"), ("comptabilite_audit", "Comptabilité et audit"),
 ("conseil_management", "Conseil et management"), ("architecture_ingenierie", "Architecture, ingénierie, contrôle technique"),
 ("recherche_developpement", "Recherche et développement"), ("marketing_publicite", "Marketing, publicité, études"),
 ("services_specialises", "Design, photo, traduction et services spécialisés"),
 ("services_entreprises", "Services aux entreprises"), ("enseignement_formation", "Enseignement et formation"),
 ("sante", "Santé humaine et vétérinaire"), ("medico_social", "Médico-social et action sociale"),
 ("culture_sport_loisirs", "Culture, sport et loisirs"), ("services_personne", "Services à la personne"),
 ("administration_publique", "Administration publique"),
 ("interprofessionnel", "Interprofessionnel"), ("non_classe", "Non classé"),
]
CLES = {k for k, _ in SECTEURS}


def secteur_rev2(code):
    """code rév. 2 'NN.NNL' -> secteur."""
    d = int(code[:2]); g = code[:4]
    if d <= 3: return "agriculture"
    if d in (10, 11, 12): return "agroalimentaire"
    if 5 <= d <= 9 or 13 <= d <= 33: return "industrie"
    if d == 35: return "energie"
    if 36 <= d <= 39: return "eau_dechets"
    if 41 <= d <= 43: return "btp"
    if d == 45: return "automobile"
    if d == 46: return "commerce_gros"
    if d == 47: return "commerce_detail"
    if 49 <= d <= 53: return "transport_logistique"
    if d in (55, 79): return "hebergement_tourisme"
    if d == 56: return "restauration"
    if d == 58: return "numerique_telecoms" if g == "58.2" else "edition_medias"
    if d in (59, 60): return "edition_medias"
    if d in (61, 62, 63): return "numerique_telecoms"
    if d == 64: return "banque_finance"
    if d == 65: return "assurance"
    if d == 66: return "assurance" if g == "66.2" else "banque_finance"
    if d == 68: return "immobilier"
    if d == 69: return "droit" if g == "69.1" else "comptabilite_audit"
    if d == 70: return "conseil_management"
    if d == 71: return "architecture_ingenierie"
    if d == 72: return "recherche_developpement"
    if d == 73: return "marketing_publicite"
    if d == 74: return "services_specialises"
    if d in (77, 78, 80, 81, 82): return "services_entreprises"
    if d in (84, 99): return "administration_publique"
    if d == 85: return "enseignement_formation"
    if d in (75, 86): return "sante"
    if d in (87, 88): return "medico_social"
    if 90 <= d <= 93: return "culture_sport_loisirs"
    if d == 94: return "non_classe"  # organisations : secteur « représenté » posé par le modèle fédérations
    if d in (95, 96, 97, 98): return "services_personne"
    return "non_classe"


# 1) NAF rév. 2 : toutes les sous-classes
s = xlrd.open_workbook(os.path.join(ICI, "naf2008_liste_n5.xls")).sheet_by_index(0)
rev2 = {}
for i in range(3, s.nrows):
    c, lib = s.row_values(i)[:2]
    if re.fullmatch(r"\d\d\.\d\d[A-Z]", str(c)):
        rev2[c] = (lib, secteur_rev2(c))
with open(os.path.join(OUT, "naf_rev2_secteurs.csv"), "w", encoding="utf-8", newline="\n") as f:
    w = csv.writer(f, lineterminator="\n"); w.writerow(["naf_rev2", "secteur", "libelle"])
    for c in sorted(rev2): w.writerow([c, rev2[c][1], rev2[c][0]])

# 2) NAF rév. 1 -> rév. 2 : la table INSEE qualifie chaque lien dans « Précisions sur la nature du lien » :
#    « CC » = contenu central (lien principal), « CA » = contenu annexe, vide = correspondance totale.
#    Candidats principaux : les liens CC ; sans CC, les liens sans précision (correspondance totale) ; à défaut
#    (8 codes n'ont que des liens CA/NC), tous les liens.
#    Parmi plusieurs candidats : 1) le lien marqué « CC : tout sauf … » (il reprend tout le poste rév. 1) ;
#    2) le lien dont l'INTITULÉ rév. 2 ressemble le plus à l'intitulé rév. 1 (mots communs) ; 3) le 1er.
#    Ex. 74.1G « Conseil pour les affaires et la gestion » -> 70.22Z « Conseil pour les affaires et autres conseils
#    de gestion » (et non 70.21Z « relations publiques ») ; 70.3E « Administration d'immeubles » -> 68.32B.
s = xlrd.open_workbook(os.path.join(ICI, "table_NAF1-NAF2.xls")).sheet_by_index(0)
liens = collections.OrderedDict()
for i in range(1, s.nrows):
    r = s.row_values(i)
    a = str(r[1]).strip().rstrip("p"); b = str(r[3]).strip().rstrip("p")
    prec = str(r[5]).strip()
    genre = "CC" if prec.startswith("CC") else ("CA" if prec.startswith("CA") else ("NC" if prec.startswith("NC") else ("TOTAL" if prec == "" else "AUTRE")))
    tout = bool(re.match(r"CC\s*:\s*tout", prec, re.I))
    if re.fullmatch(r"\d\d\.\d[A-Z]", a) and re.fullmatch(r"\d\d\.\d\d[A-Z]", b):
        liens.setdefault(a, []).append((b, genre, tout, str(r[2]), str(r[4])))

VIDES = {"de", "des", "du", "la", "le", "les", "et", "a", "au", "aux", "en", "d", "l", "pour", "autres", "autre", "n", "c", "sauf", "activites", "activite"}
def mots(t):
    t = unicodedata.normalize("NFKD", t).encode("ascii", "ignore").decode().lower()
    return {m for m in re.split(r"[^a-z0-9]+", t) if m and m not in VIDES}

def principaux(bs):
    cc = [x for x in bs if x[1] == "CC"]
    if cc: return cc
    tot = [x for x in bs if x[1] == "TOTAL"]
    if tot: return tot
    return [x for x in bs if x[1] not in ("CA", "NC")] or list(bs)

# Arbitrages manuels, justifiés, quand ni « tout sauf » ni les intitulés ne départagent les liens CC :
#  - 74.8K « Services annexes à la production » : 5 liens CC sans mot commun ; 82.99Z « Autres activités de soutien
#    aux entreprises n.c.a. » est le poste « fourre-tout » rév. 2 qui reprend ce poste rév. 1 (relecture A09, 28/09).
ARBITRAGES = {"74.8K": "82.99Z"}

def choisir(bs, code=None):
    p = principaux(bs)
    if code in ARBITRAGES:
        return ARBITRAGES[code], [x[0] for x in p]
    def score(x):
        lib1, lib2 = mots(x[3]), mots(x[4])
        return (x[2], len(lib1 & lib2) / (len(lib1 | lib2) or 1))
    meilleur = max(p, key=score)  # max() garde le 1er en cas d'égalité parfaite
    return meilleur[0], [x[0] for x in p]

ambigus = 0
with open(os.path.join(OUT, "naf_rev1_vers_rev2.csv"), "w", encoding="utf-8", newline="\n") as f:
    w = csv.writer(f, lineterminator="\n"); w.writerow(["naf_rev1", "naf_rev2", "secteur", "secteurs_possibles"])
    for a, bs in liens.items():
        b, p = choisir(bs, a)
        secs = sorted({secteur_rev2(x) for x in p}); ambigus += len(secs) > 1
        w.writerow([a, b, secteur_rev2(b), "|".join(secs)])

# 2 bis) Repli par groupe rév. 1 (« NN.N ») pour les codes de la révision 2003 absents de la table (ex. 51.6G, 72.2Z) :
# secteur majoritaire des liens PRINCIPAUX du groupe.
grp = collections.defaultdict(collections.Counter)
for a, bs in liens.items():
    for x in principaux(bs): grp[a[:4]][secteur_rev2(x[0])] += 1
with open(os.path.join(OUT, "naf_rev1_groupes.csv"), "w", encoding="utf-8", newline="\n") as f:
    w = csv.writer(f, lineterminator="\n"); w.writerow(["groupe_rev1", "secteur"])
    for g in sorted(grp): w.writerow([g, grp[g].most_common(1)[0][0]])
# 2 ter) Dernier repli par division rév. 1 (« NN ») : ex. 51.6G / 51.7Z (révision 2003) -> commerce de gros.
div = collections.defaultdict(collections.Counter)
for a, bs in liens.items():
    for x in principaux(bs): div[a[:2]][secteur_rev2(x[0])] += 1
with open(os.path.join(OUT, "naf_rev1_divisions.csv"), "w", encoding="utf-8", newline="\n") as f:
    w = csv.writer(f, lineterminator="\n"); w.writerow(["division_rev1", "secteur"])
    for d in sorted(div): w.writerow([d, div[d].most_common(1)[0][0]])

# 3) NAP 600 (1973) -> secteur : défaut par groupe NAP 100 + exceptions ligne à ligne
DEF = {}
for g in ("01", "02", "03"): DEF[g] = "agriculture"
for g in range(35, 43): DEF[str(g)] = "agroalimentaire"
for g in ["04", "05", "09"] + [str(x) for x in list(range(10, 35)) + [43, 44, 45, 46, 47, 48, 49, 50, 52, 53, 54]]:
    DEF[g] = "industrie"
DEF.update({
    "06": "energie", "07": "energie", "08": "eau_dechets", "51": "edition_medias", "55": "btp", "56": "eau_dechets",
    "57": "commerce_gros", "58": "commerce_gros", "59": "commerce_gros", "60": "commerce_gros",
    "61": "commerce_detail", "62": "commerce_detail", "63": "commerce_detail", "64": "commerce_detail", "65": "automobile",
    "66": "services_personne", "67": "restauration", "68": "transport_logistique", "69": "transport_logistique",
    "70": "transport_logistique", "71": "transport_logistique", "72": "transport_logistique", "73": "transport_logistique",
    "74": "transport_logistique", "75": "numerique_telecoms", "76": "banque_finance", "77": "services_entreprises",
    "78": "banque_finance", "79": "immobilier", "80": "services_entreprises", "81": "immobilier", "82": "enseignement_formation",
    "83": "recherche_developpement", "84": "sante", "85": "medico_social", "86": "culture_sport_loisirs", "87": "services_personne",
    "88": "assurance", "89": "banque_finance", "90": "administration_publique", "91": "administration_publique",
    "92": "enseignement_formation", "93": "recherche_developpement", "94": "sante", "95": "medico_social",
    "96": "culture_sport_loisirs", "97": "non_classe", "98": "services_personne", "99": "administration_publique"})
EXC = {"08.02": "energie", "51.10": "industrie", "51.11": "industrie",
       "74.09": "hebergement_tourisme", "75.03": "transport_logistique",
       "77.01": "architecture_ingenierie", "77.02": "conseil_management", "77.03": "numerique_telecoms", "77.04": "numerique_telecoms",
       "77.05": "architecture_ingenierie", "77.06": "architecture_ingenierie", "77.07": "conseil_management", "77.08": "droit",
       "77.09": "comptabilite_audit", "77.10": "marketing_publicite", "77.11": "marketing_publicite", "77.15": "non_classe",
       "78.02": "assurance", "80.07": "banque_finance", "81.22": "banque_finance",
       "86.01": "edition_medias", "86.02": "edition_medias", "86.03": "edition_medias", "86.04": "edition_medias",
       "87.06": "services_specialises", "87.08": "services_entreprises", "87.09": "eau_dechets", "87.10": "eau_dechets",
       "97.12": "hebergement_tourisme"}
for n in range(8, 14): EXC[f"67.{n:02d}"] = "hebergement_tourisme"
for v in list(DEF.values()) + list(EXC.values()): assert v in CLES, v
s = xlrd.open_workbook(os.path.join(ICI, "nap1973.xls")).sheet_by_index(0)
nap = 0
with open(os.path.join(OUT, "nap600_secteurs.csv"), "w", encoding="utf-8", newline="\n") as f:
    w = csv.writer(f, lineterminator="\n"); w.writerow(["nap600", "secteur", "libelle"])
    for i in range(1, s.nrows):
        r = s.row_values(i); code = str(r[3]).strip(); g = str(r[2]).split(".")[0].zfill(2)
        w.writerow([code, EXC.get(code, DEF[g]), r[4]]); nap += 1
with open(os.path.join(OUT, "secteurs.csv"), "w", encoding="utf-8", newline="\n") as f:
    w = csv.writer(f, lineterminator="\n"); w.writerow(["cle", "libelle"]); w.writerows(SECTEURS)
print("rev2", len(rev2), "| rev1", len(liens), "dont", ambigus, "à secteur ambigu | nap", nap)
print(collections.Counter(v[1] for v in rev2.values()))
