#!/usr/bin/env python3
"""Validation du modèle de shaping K8 (stdlib seule, Python >= 3.11). Code 0 = valide, 1 = invalide."""
import sys
import tomllib

VERSION = 1
FOURNISSEURS = ("gmail", "microsoft", "autre")  # alignés sur App\Envoi\Chauffe\Fournisseur::TOUS
CLES_FOURNISSEUR = {
    "debit_max_par_heure": (1, 100_000),
    "debit_max_par_minute": (1, 10_000),
    "connexions_simultanees": (1, 100),
    "messages_par_connexion": (1, 1_000),
}
CLES_TSA = {"actif", "reduction_pourcent", "suspension_minutes"}


def _entier(valeur, bornes):
    return isinstance(valeur, int) and not isinstance(valeur, bool) and bornes[0] <= valeur <= bornes[1]


def valider(m):
    e = []
    if not isinstance(m, dict) or set(m) != {"modele", "fournisseur"}:
        return ["racine : attendu exactement [modele] et [fournisseur.*]"]
    mod = m["modele"]
    # Entier STRICT : True == 1 et 1.0 == 1 en Python, mais ni un booléen ni un flottant ne sont une version.
    if not isinstance(mod, dict) or set(mod) != {"version"} or not _entier(mod["version"], (VERSION, VERSION)):
        e.append(f"modele.version : entier {VERSION} attendu")
    f = m["fournisseur"]
    if not isinstance(f, dict) or set(f) != set(FOURNISSEURS):
        return e + [f"fournisseur : exactement {', '.join(FOURNISSEURS)}"]
    for nom in FOURNISSEURS:
        p = f[nom]
        if not isinstance(p, dict) or set(p) != set(CLES_FOURNISSEUR) | {"tsa"}:
            e.append(f"{nom} : clés attendues {sorted(set(CLES_FOURNISSEUR) | {'tsa'})}")
            continue
        for cle, bornes in CLES_FOURNISSEUR.items():
            if not _entier(p[cle], bornes):
                e.append(f"{nom}.{cle} : entier entre {bornes[0]} et {bornes[1]}")
        h, mn = p["debit_max_par_heure"], p["debit_max_par_minute"]
        if _entier(h, (1, 10**9)) and _entier(mn, (1, 10**9)) and mn > h:
            e.append(f"{nom} : débit par minute ({mn}) dépasse le débit par heure ({h})")
        t = p["tsa"]
        if not isinstance(t, dict) or set(t) != CLES_TSA:
            e.append(f"{nom}.tsa : clés attendues {sorted(CLES_TSA)}")
            continue
        if t["actif"] is not True:
            e.append(f"{nom}.tsa.actif : doit être true (TSA obligatoire)")
        if not _entier(t["reduction_pourcent"], (1, 99)):
            e.append(f"{nom}.tsa.reduction_pourcent : entier entre 1 et 99")
        if not _entier(t["suspension_minutes"], (1, 1440)):
            e.append(f"{nom}.tsa.suspension_minutes : entier entre 1 et 1440")
    return e


def main(argv):
    if len(argv) != 2:
        print("usage : valider_shaping.py <modele.toml>", file=sys.stderr)
        return 1
    try:
        with open(argv[1], "rb") as fh:
            m = tomllib.load(fh)
    except (OSError, tomllib.TOMLDecodeError) as exc:
        print(f"ROUGE illisible : {exc}")
        return 1
    erreurs = valider(m)
    for x in erreurs:
        print(f"ROUGE {x}")
    if not erreurs:
        print("ok modèle de shaping valide")
    return 1 if erreurs else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
