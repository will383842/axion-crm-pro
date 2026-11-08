"""Console LOCALE du serveur d'envoi : seul chemin qui éteint le mode témoins.

Structurellement local : ce module ne dépend pas de `Socle` et n'est PAS importé par la couche réseau (HTTP/Lua).
Il ouvre directement le fichier d'état (0600) : il faut donc être sur le serveur, avec le droit de lire ce fichier.
Aucune chaîne « origine » fournie par un appelant distant ne peut remplacer cela. La future couche HTTP ne doit
JAMAIS importer `axion_socle.local` (à vérifier en revue).

Usage : python3 -m axion_socle.local eteindre-temoins ETAT.sqlite OPERATEUR WORKSPACE_ID
"""
import json
import sys
import time

from .empreinte import liste_valide
from .signature import est_uuid
from .stockage import ErreurStockage, Stockage, assainir, cle_liste_temoins, cle_temoins


def _ecrire(chemin, f) -> bool:
    try:
        st = Stockage(chemin)
    except ErreurStockage:
        return False
    try:
        st.ecrire(lambda c: f(st, c))
        return True
    except ErreurStockage:
        return False
    finally:
        st.close()


def eteindre_temoins_local(chemin: str, operateur: str, workspace_id: str) -> bool:
    """Éteint le mode témoins d'UN espace (il est PAR ESPACE, comme dans le CRM)."""
    operateur = assainir(operateur)
    if not operateur or not est_uuid(workspace_id):
        return False
    return _ecrire(chemin, lambda st, c: (st.poser(c, cle_temoins(workspace_id), 0), c.execute(
        "INSERT INTO journal(ts,type,detail) VALUES(?,?,?)", (time.time(), "temoins-off", operateur))))


def definir_temoins_local(chemin: str, operateur: str, workspace_id: str, actif: bool, liste) -> bool:
    """Agrandir (ou remplacer) la liste d'EMPREINTES et/ou allumer, pour UN espace : console locale, journalisé."""
    operateur = assainir(operateur)
    if not operateur or not est_uuid(workspace_id) or not isinstance(actif, bool) or not liste_valide(liste):
        return False
    liste = sorted(set(liste))
    return _ecrire(chemin, lambda st, c: (
        st.poser(c, cle_temoins(workspace_id), 1 if actif else 0),
        st.poser(c, cle_liste_temoins(workspace_id), json.dumps(liste)),
        c.execute("INSERT INTO journal(ts,type,detail) VALUES(?,?,?)", (time.time(), "temoins-liste-locale", operateur))))


def main(argv) -> int:
    if len(argv) != 5 or argv[1] != "eteindre-temoins":
        print("usage: python3 -m axion_socle.local eteindre-temoins ETAT.sqlite OPERATEUR WORKSPACE_ID", file=sys.stderr)
        return 2
    return 0 if eteindre_temoins_local(argv[2], argv[3], argv[4]) else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
