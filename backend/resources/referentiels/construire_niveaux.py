# Construit naf_rev2_niveaux.csv : les quatre niveaux SUPÉRIEURS de la NAF rév. 2
# (sections, divisions, groupes, classes), avec leur parent et leur libellé INSEE.
# Les sous-classes (5e niveau) restent dans naf_rev2_secteurs.csv.
#
# Entrées (INSEE, https://www.insee.fr/fr/information/2120875), posées à côté du script :
#   naf2008_5_niveaux.xls          — l'arborescence (sous-classe → classe → groupe → division → section)
#   naf2008_liste_n1.xls … n4.xls  — les libellés de chaque niveau
# Sortie : sortie/naf_rev2_niveaux.csv (à recopier ici, comme les autres CSV).
import xlrd, csv, os, re
ICI = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(ICI, "sortie")
os.makedirs(OUT, exist_ok=True)


def texte(v, decimales):
    """Une cellule de code : Excel stocke parfois « 11 » ou « 11.0 » en NOMBRE
    (11.0), qu'on remet au format INSEE (« 11 », « 11.0 », « 11.01 »)."""
    if isinstance(v, float):
        return f"{v:0{3 + decimales}.{decimales}f}" if decimales else f"{int(v):02d}"
    return str(v).strip()


def libelles(fichier, motif, decimales=0):
    s = xlrd.open_workbook(os.path.join(ICI, fichier)).sheet_by_index(0)
    res = {}
    for i in range(s.nrows):
        c, lib = s.row_values(i)[:2]
        c, lib = texte(c, decimales), str(lib).strip()
        if re.fullmatch(motif, c):
            res[c] = lib
    return res


SECTIONS = libelles("naf2008_liste_n1.xls", r"[A-U]")
DIVISIONS = libelles("naf2008_liste_n2.xls", r"\d\d")
GROUPES = libelles("naf2008_liste_n3.xls", r"\d\d\.\d", 1)
CLASSES = libelles("naf2008_liste_n4.xls", r"\d\d\.\d\d", 2)

parent = {}
s = xlrd.open_workbook(os.path.join(ICI, "naf2008_5_niveaux.xls")).sheet_by_index(0)
for i in range(1, s.nrows):
    v = s.row_values(i)[:5]
    n5, n4, n3, n2, n1 = str(v[0]).strip(), texte(v[1], 2), texte(v[2], 1), texte(v[3], 0), str(v[4]).strip()
    if not re.fullmatch(r"\d\d\.\d\d[A-Z]", n5):
        continue
    for enfant, pere in ((n4, n3), (n3, n2), (n2, n1)):
        if parent.setdefault(enfant, pere) != pere:
            raise SystemExit(f"Deux parents pour {enfant}")

lignes = [("section", c, "", SECTIONS[c]) for c in sorted(SECTIONS)]
for niveau, table in (("division", DIVISIONS), ("groupe", GROUPES), ("classe", CLASSES)):
    for c in sorted(table):
        if c not in parent:
            raise SystemExit(f"{niveau} {c} absente de l'arborescence")
        lignes.append((niveau, c, parent[c], table[c]))

with open(os.path.join(OUT, "naf_rev2_niveaux.csv"), "w", encoding="utf-8", newline="\n") as f:
    w = csv.writer(f, lineterminator="\n")
    w.writerow(["niveau", "code", "parent", "libelle"])
    w.writerows(lignes)
print({n: sum(1 for l in lignes if l[0] == n) for n in ("section", "division", "groupe", "classe")})
