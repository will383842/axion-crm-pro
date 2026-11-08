"""Validateur CI : python3 -m axion_loghook.valider_toml <fichier|dossier>. Code != 0 si une regle est invalide."""
import pathlib
import sys

from .regles import ReglesInvalides, charger


def main(argv):
    if len(argv) != 2:
        print("usage: valider_toml <fichier|dossier>", file=sys.stderr)
        return 2
    p = pathlib.Path(argv[1])
    fichiers = sorted(p.glob("*.toml")) if p.is_dir() else [p]
    if not fichiers:
        print("aucun fichier .toml : une CI sans regle est rouge", file=sys.stderr)
        return 1
    ko = 0
    for f in fichiers:
        try:
            charger(f)
            print("ok   %s" % f)
        except ReglesInvalides as e:
            ko = 1
            print("ROUGE %s" % e, file=sys.stderr)
    return ko


if __name__ == "__main__":
    sys.exit(main(sys.argv))
