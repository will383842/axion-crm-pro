"""Chargement et validation des regles de classification (TOML). Tout ecart => ReglesInvalides (CI rouge)."""
import pathlib
import re
import tomllib

CLASSES = ("rebond_dur", "rebond_mou", "report")
TYPES_KUMO = ("Bounce", "TransientFailure", "Expiration")
PREFIXE = re.compile(r"^[245](\.\d{1,3}){0,2}$")


class ReglesInvalides(Exception):
    pass


def charger(chemin):
    try:
        d = tomllib.loads(pathlib.Path(chemin).read_text(encoding="utf-8"))
    except (tomllib.TOMLDecodeError, OSError, UnicodeDecodeError) as e:
        raise ReglesInvalides("%s : %s" % (chemin, e)) from e
    for cle in ("defaut_bounce", "defaut_transitoire"):
        if d.get(cle) not in CLASSES:
            raise ReglesInvalides("%s : %s absent ou classe inconnue" % (chemin, cle))
    regles, noms = [], set()
    for r in d.get("regle", []):
        nom = r.get("nom")
        if not isinstance(nom, str) or not nom or nom in noms:
            raise ReglesInvalides("%s : nom de regle absent ou en double" % chemin)
        noms.add(nom)
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
        regles.append(r)
    return {"defaut_bounce": d["defaut_bounce"], "defaut_transitoire": d["defaut_transitoire"], "regles": regles}
