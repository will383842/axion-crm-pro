"""État durable en SQLite. Chaque écriture est validée avec fsync (synchronous=FULL) AVANT toute réponse."""
import os
import re
import sqlite3

PRAGMAS_AUTORISES = frozenset({"synchronous", "journal_mode", "foreign_keys", "user_version"})


def assainir(texte, borne: int = 64) -> str:
    """Texte à journaliser : borné, sans retour ligne ni caractère de contrôle ou exotique."""
    return re.sub(r"[^A-Za-z0-9._@-]", "_", str(texte))[:borne]


class ErreurStockage(Exception):
    """Fichier absent, illisible, disque plein : le socle se ferme (451)."""


SCHEMA = """
CREATE TABLE meta(cle TEXT PRIMARY KEY, valeur TEXT);
CREATE TABLE compteur(jour TEXT PRIMARY KEY, n INTEGER NOT NULL);
CREATE TABLE jetons(jeton TEXT PRIMARY KEY, empreinte TEXT NOT NULL, id TEXT NOT NULL,
  destinataire TEXT NOT NULL, contenu BLOB NOT NULL, cree_a REAL NOT NULL, depose INTEGER NOT NULL DEFAULT 0);
CREATE TABLE journal(n INTEGER PRIMARY KEY AUTOINCREMENT, ts REAL, type TEXT, detail TEXT);
"""


class Stockage:
    simuler_disque_plein = False

    def __init__(self, chemin: str):
        if not os.path.isfile(chemin):
            raise ErreurStockage("fichier d'état absent")
        try:
            self.c = sqlite3.connect(f"file:{chemin}?mode=rw", uri=True, isolation_level=None,
                                     check_same_thread=False)
            self.c.execute("PRAGMA synchronous=FULL")
            if self.c.execute("PRAGMA integrity_check").fetchone()[0] != "ok":
                raise ErreurStockage("fichier d'état corrompu")
            self.c.execute("SELECT 1 FROM meta LIMIT 1").fetchall()
        except (sqlite3.Error, ErreurStockage) as e:
            self.close()
            if isinstance(e, ErreurStockage):
                raise
            raise ErreurStockage(f"fichier d'état illisible: {e}") from e

    @classmethod
    def creer(cls, chemin: str) -> None:
        """Installation uniquement. Départ FERMÉ : témoins allumés, aucune santé reçue."""
        if os.path.lexists(chemin):
            raise ErreurStockage("existe déjà")
        dossier = os.path.dirname(os.path.abspath(chemin))
        if not os.path.isdir(dossier):
            os.makedirs(dossier, mode=0o700)
            os.chmod(dossier, 0o700)  # indépendant de l'umask
        try:
            fd = os.open(chemin, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        except FileExistsError as e:
            raise ErreurStockage("existe déjà") from e
        os.close(fd)
        os.chmod(chemin, 0o600)  # SQLite crée le journal avec les droits de ce fichier
        c = sqlite3.connect(chemin, isolation_level=None)
        try:
            c.execute("PRAGMA synchronous=FULL")
            c.executescript(SCHEMA)
            c.execute("INSERT INTO meta VALUES('temoins','1'),('pause_hm','0')")
        finally:
            c.close()

    def close(self) -> None:
        """Idempotent. Libère le fichier (nécessaire pour le nettoyage sous Windows)."""
        c, self.c = getattr(self, "c", None), None
        if c is not None:
            c.close()

    def pragma(self, nom: str) -> int:
        if nom not in PRAGMAS_AUTORISES:
            raise ValueError("pragma non autorisé")
        return self.c.execute(f"PRAGMA {nom}").fetchone()[0]

    def meta(self, cle, defaut=None):
        r = self.c.execute("SELECT valeur FROM meta WHERE cle=?", (cle,)).fetchone()
        return r[0] if r else defaut

    def ecrire(self, f):
        """Exécute f(c) dans une transaction exclusive validée (fsync) ; toute erreur => ErreurStockage."""
        if self.simuler_disque_plein:
            raise ErreurStockage("disque plein")
        try:
            self.c.execute("BEGIN IMMEDIATE")
            try:
                r = f(self.c)
                self.c.execute("COMMIT")
                return r
            except BaseException:
                self.c.execute("ROLLBACK")
                raise
        except sqlite3.Error as e:
            raise ErreurStockage(str(e)) from e

    def poser(self, c, cle, valeur):
        c.execute("INSERT OR REPLACE INTO meta VALUES(?,?)", (cle, str(valeur)))

    def jeton(self, jeton):
        return self.c.execute("SELECT empreinte,id,destinataire,contenu,depose FROM jetons WHERE jeton=?",
                              (jeton,)).fetchone()

    def jeton_present(self, jeton) -> bool:
        return self.jeton(jeton) is not None

    def en_attente(self):
        return self.c.execute("SELECT jeton,id,destinataire,contenu FROM jetons WHERE depose=0").fetchall()

    def compteur(self, jour) -> int:
        r = self.c.execute("SELECT n FROM compteur WHERE jour=?", (jour,)).fetchone()
        return r[0] if r else 0

    def journal(self):
        return self.c.execute("SELECT type,detail,ts FROM journal ORDER BY n").fetchall()

    def marquer_depose(self, c, jeton):
        """Déposé : le contenu n'a plus à rester sur disque (on garde l'empreinte pour le rejeu)."""
        c.execute("UPDATE jetons SET depose=1, contenu=x'' WHERE jeton=?", (jeton,))
