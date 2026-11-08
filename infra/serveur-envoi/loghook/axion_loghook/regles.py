"""Chargement et validation des regles de classification (TOML). Tout ecart => ReglesInvalides (CI rouge)."""
import pathlib
import re
import tomllib

CLASSES = ("rebond_dur", "rebond_mou", "report", "inconnu", "alerte_config")
# Un Bounce sans regle n'est JAMAIS dur : le defaut est « inconnu ». Un echec 4xx sans regle reste un report.
DEFAUT_BOUNCE = ("inconnu",)
DEFAUT_TRANSITOIRE = ("report", "inconnu")
CLES_SOMMET = {"defaut_bounce", "defaut_transitoire", "regle"}
CLES_REGLE = {"nom", "classe", "type", "statut_prefixe", "contient"}
TYPES_KUMO = ("Bounce", "TransientFailure", "Expiration")
PREFIXE = re.compile(r"^[245](\.\d{1,3}){0,2}$")


class ReglesInvalides(Exception):
    pass


def charger(chemin):
    try:
        d = tomllib.loads(pathlib.Path(chemin).read_text(encoding="utf-8"))
    except (tomllib.TOMLDecodeError, OSError, UnicodeDecodeError) as e:
        raise ReglesInvalides("%s : %s" % (chemin, e)) from e
    inconnues = set(d) - CLES_SOMMET
    if inconnues:
        raise ReglesInvalides("%s : cle(s) inconnue(s) %s" % (chemin, sorted(inconnues)))
    for cle, permis in (("defaut_bounce", DEFAUT_BOUNCE), ("defaut_transitoire", DEFAUT_TRANSITOIRE)):
        if d.get(cle) not in permis:
            raise ReglesInvalides("%s : %s absent ou non permis (%s)" % (chemin, cle, "/".join(permis)))
    regles, noms = [], set()
    for r in d.get("regle", []):
        nom = r.get("nom")
        if not isinstance(nom, str) or not nom or nom in noms:
            raise ReglesInvalides("%s : nom de regle absent ou en double" % chemin)
        noms.add(nom)
        inconnues = set(r) - CLES_REGLE
        if inconnues:
            raise ReglesInvalides("%s : regle %s : cle(s) inconnue(s) %s" % (chemin, nom, sorted(inconnues)))
        if r.get("classe") not in CLASSES:
            raise ReglesInvalides("%s : regle %s : classe inconnue" % (chemin, nom))
        if r.get("type", TYPES_KUMO[0]) not in TYPES_KUMO:
            raise ReglesInvalides("%s : regle %s : type inconnu" % (chemin, nom))
        p = r.get("statut_prefixe")
        t = r.get("contient")
        if p is None and t is None and "type" not in r:
            raise ReglesInvalides("%s : regle %s sans condition" % (chemin, nom))
        if p is not None and (not isinstance(p, str) or not PREFIXE.match(p)):
            raise ReglesInvalides("%s : regle %s : statut_prefixe invalide" % (chemin, nom))
        if t is not None and (not isinstance(t, list) or not t or not all(isinstance(x, str) and x for x in t)):
            raise ReglesInvalides("%s : regle %s : contient invalide" % (chemin, nom))
        if r["classe"] == "rebond_dur" and not (r.get("type") == "Bounce" and str(p or "").startswith("5.1.")):
            # Dur = adresse perdue : uniquement un vrai Bounce 5.1.x. Jamais une Expiration, un 4xx, ni un 5.7 (reputation).
            raise ReglesInvalides("%s : regle %s : rebond_dur reserve a type=Bounce et statut_prefixe 5.1.x" % (chemin, nom))
        regles.append(r)
    return {"defaut_bounce": d["defaut_bounce"], "defaut_transitoire": d["defaut_transitoire"], "regles": regles}
