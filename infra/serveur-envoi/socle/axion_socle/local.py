"""Console LOCALE du serveur d'envoi : seul chemin qui éteint le mode témoins.

Structurellement local : ce module ne dépend pas de `Socle` et n'est PAS importé par la couche réseau (HTTP/Lua).
Il ouvre directement le fichier d'état (0600) : il faut donc être sur le serveur, avec le droit de lire ce fichier.
Aucune chaîne « origine » fournie par un appelant distant ne peut remplacer cela. La future couche HTTP ne doit
JAMAIS importer `axion_socle.local` (à vérifier en revue).

Usage : python3 -m axion_socle.local eteindre-temoins ETAT.sqlite OPERATEUR
"""
import sys
import time

from .stockage import ErreurStockage, Stockage, assainir


def eteindre_temoins_local(chemin: str, operateur: str) -> bool:
    operateur = assainir(operateur)
    if not operateur:
        return False
    try:
        st = Stockage(chemin)
    except ErreurStockage:
        return False
    try:
        st.ecrire(lambda c: (st.poser(c, "temoins", 0),
                             c.execute("INSERT INTO journal(ts,type,detail) VALUES(?,?,?)",
                                       (time.time(), "temoins-off", operateur))))
        return True
    except ErreurStockage:
        return False
    finally:
        st.close()


def main(argv) -> int:
    if len(argv) != 4 or argv[1] != "eteindre-temoins":
        print("usage: python3 -m axion_socle.local eteindre-temoins ETAT.sqlite OPERATEUR", file=sys.stderr)
        return 2
    return 0 if eteindre_temoins_local(argv[2], argv[3]) else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
