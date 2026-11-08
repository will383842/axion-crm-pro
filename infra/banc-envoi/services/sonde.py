"""Sonde réseau : tente des connexions SORTANTES vers des cibles réservées (RFC 5737 / .invalid).
Succès = zéro connexion aboutie. Code de sortie 1 si une connexion sort."""
import json, socket, sys

# Témoin positif : cible RÉELLEMENT joignable, mais sur un autre réseau (hors-banc, internal).
# Depuis le banc elle doit échouer (preuve d'isolement) ; depuis hors-banc (mode --temoin)
# elle doit réussir (preuve que la sonde sait voir une connexion qui aboutit).
TEMOIN = ("10.98.0.10", 8080)
CIBLES = [("192.0.2.1", 25), ("198.51.100.1", 443), ("203.0.113.1", 80), ("banc-sortie.invalid", 443), TEMOIN]


def tenter(h, p, t):
    try:
        socket.create_connection((h, p), timeout=t).close()
        return True
    except OSError:
        return False


def verifier(connecter=tenter, timeout=3):
    ok = [f"{h}:{p}" for h, p in CIBLES if connecter(h, p, timeout)]
    return {"cibles": len(CIBLES), "sortantes": len(ok), "abouties": ok}


def code_sortie(r):
    return 1 if r["sortantes"] else 0


def verifier_temoin(connecter=tenter, timeout=3):
    """Lancé depuis hors-banc : le témoin DOIT être joignable (sinon la sonde est aveugle)."""
    return {"temoin_joignable": bool(connecter(*TEMOIN, timeout))}


if __name__ == "__main__":
    if "--temoin" in sys.argv:
        t = verifier_temoin()
        print(json.dumps(t))
        sys.exit(0 if t["temoin_joignable"] else 1)
    r = verifier()
    print(json.dumps(r))
    sys.exit(code_sortie(r))
