"""Socle du serveur d'envoi (K6) : plafond dur, homme mort, mode témoins, jetons sur disque.

Règle d'or : un jeton est écrit et synchronisé sur disque AVANT le 250. Un arrêt brutal à n'importe quel
point est rattrapé par le rejeu du client (même jeton => même id, dépôt idempotent) : zéro perte, zéro doublon.
Tout doute => FERMÉ (451), jamais « accepté ».
"""
import hashlib
import hmac
import secrets
import threading
import time
from dataclasses import dataclass

from .horloge import jour_paris
from .sortie import Sortie
from .stockage import ErreurStockage, Stockage

SEPT_JOURS = 7 * 86400
HOMME_MORT = 15 * 60


@dataclass(frozen=True)
class Parametres:
    plafond: int
    cle: bytes
    liste_blanche: frozenset = frozenset()
    delai_homme_mort: int = HOMME_MORT
    retention_jetons: int = SEPT_JOURS


@dataclass(frozen=True)
class Reponse:
    code: int
    texte: str
    id: str | None = None
    rejoue: bool = False


def signer(cle: bytes, commande: str, ts: float) -> str:
    return hmac.new(cle, f"{commande}|{ts!r}".encode(), hashlib.sha256).hexdigest()


class Socle:
    def __init__(self, stockage: Stockage | None, horloge, sortie: Sortie, p: Parametres):
        self.stockage, self.horloge, self.sortie, self.p = stockage, horloge, sortie, p
        self.ferme = stockage is None
        self._verrou = threading.RLock()

    @classmethod
    def ouvrir(cls, chemin, horloge, sortie, p):
        try:
            return cls(Stockage(chemin), horloge, sortie, p)
        except ErreurStockage:
            return cls(None, horloge, sortie, p)  # FERMÉ par défaut

    # --- temps -----------------------------------------------------------------------------------
    def _now(self) -> float:
        """Jamais en arrière : une horloge reculée ne rouvre ni plafond ni homme mort."""
        return max(self.horloge(), float(self.stockage.meta("dernier_instant", 0)))

    def _jour(self, now) -> str:
        return max(jour_paris(now), self.stockage.meta("dernier_jour", ""))

    def _signature_ok(self, commande, ts, sig) -> bool:
        return isinstance(sig, str) and hmac.compare_digest(sig, signer(self.p.cle, commande, ts))

    def _journaliser(self, c, type_, detail):
        c.execute("INSERT INTO journal(ts,type,detail) VALUES(?,?,?)", (time.time(), type_, detail))

    # --- jetons / envoi --------------------------------------------------------------------------
    def recevoir(self, jeton: str, destinataire: str, contenu: bytes) -> Reponse:
        if self.ferme:
            return Reponse(451, "4.3.0 socle ferme, report")
        try:
            with self._verrou:
                return self._recevoir(jeton, destinataire, contenu)
        except Exception:  # fermé par défaut
            return Reponse(451, "4.3.0 erreur interne, report")

    def _recevoir(self, jeton, dest, contenu) -> Reponse:
        st = self.stockage
        if len(jeton) < 32:
            return Reponse(550, "5.5.4 jeton de moins de 128 bits")
        try:
            now = self._now()
            pause = self._verifier_homme_mort(now)
            emp = hashlib.sha256(dest.encode() + b"\0" + contenu).hexdigest()
            st.ecrire(lambda c: c.execute("DELETE FROM jetons WHERE depose=1 AND cree_a<?",
                                          (now - self.p.retention_jetons,)))
            j = st.jeton(jeton)
            if j:
                if j[0] != emp:
                    st.ecrire(lambda c: self._journaliser(c, "alerte-jeton-contenu", jeton[:8]))
                    return Reponse(550, "5.5.4 jeton deja utilise avec un autre contenu")
                if not j[4]:
                    if pause:
                        return Reponse(451, "4.7.0 pause, report")
                    if not self._deposer(jeton, j[1], dest, bytes(j[3])):
                        return Reponse(451, "4.3.2 file indisponible, report")
                return Reponse(250, "2.0.0 ok", j[1], rejoue=True)
            if pause:
                return Reponse(451, "4.7.0 pause totale (homme mort), report")
            if self.temoins_actifs() and dest not in self.p.liste_blanche:
                return Reponse(550, "5.7.1 mode temoins : destinataire hors liste")
            id_ = secrets.token_hex(12)
            if not st.ecrire(lambda c: self._reserver(c, jeton, emp, id_, dest, contenu, now)):
                return Reponse(451, "4.7.1 plafond journalier atteint, report")
            if not self._deposer(jeton, id_, dest, contenu):
                return Reponse(451, "4.3.2 file indisponible, report")
            return Reponse(250, "2.0.0 ok", id_)
        except ErreurStockage:
            return Reponse(451, "4.3.1 stockage indisponible, report")

    def _reserver(self, c, jeton, emp, id_, dest, contenu, now) -> bool:
        jour = max(jour_paris(now), self.stockage.meta("dernier_jour", ""))
        n = self.stockage.compteur(jour)
        if n >= self.p.plafond:
            return False
        c.execute("INSERT OR REPLACE INTO compteur VALUES(?,?)", (jour, n + 1))
        c.execute("INSERT INTO jetons(jeton,empreinte,id,destinataire,contenu,cree_a) VALUES(?,?,?,?,?,?)",
                  (jeton, emp, id_, dest, contenu, now))
        self.stockage.poser(c, "dernier_jour", jour)
        self.stockage.poser(c, "dernier_instant", now)
        return True

    def _deposer(self, jeton, id_, dest, contenu) -> bool:
        try:
            self.sortie.deposer(id_, dest, contenu)
        except Exception:
            return False
        self.stockage.ecrire(lambda c: c.execute("UPDATE jetons SET depose=1 WHERE jeton=?", (jeton,)))
        return True

    def recuperer(self) -> int:
        """Au redémarrage : redépose (idempotent) ce qui était accepté mais pas encore déposé."""
        if self.ferme:
            return 0
        with self._verrou:
            if self._verifier_homme_mort(self._now()):
                return 0
            return sum(self._deposer(j, i, d, bytes(c)) for j, i, d, c in self.stockage.en_attente())

    def compteur_du_jour(self) -> int:
        return self.stockage.compteur(self._jour(self._now()))

    # --- homme mort ------------------------------------------------------------------------------
    def _verifier_homme_mort(self, now) -> bool:
        st = self.stockage
        if st.meta("pause_hm") == "1":
            return True
        ls = st.meta("derniere_sante")
        if ls is None or now - float(ls) > self.p.delai_homme_mort:
            st.ecrire(lambda c: (st.poser(c, "pause_hm", 1), st.poser(c, "dernier_instant", now),
                                 self._journaliser(c, "homme-mort", "pause totale")))
            return True
        return False

    def sante(self, ts: float, signature: str) -> bool:
        """Signe de vie du CRM. Forgée ou rejouée : ignorée. Ne lève jamais la pause (voir `reprise`)."""
        if self.ferme:
            return False
        with self._verrou:
            st, now = self.stockage, self._now()
            if not self._signature_ok("sante", ts, signature) or ts <= float(st.meta("ts_sante", 0)) \
                    or ts > now + 60:
                return False
            st.ecrire(lambda c: (st.poser(c, "derniere_sante", now), st.poser(c, "ts_sante", ts),
                                 st.poser(c, "dernier_instant", now)))
            return True

    def reprise(self, signature: str, ts: float = 0) -> bool:
        if self.ferme:
            return False
        with self._verrou:
            st, now = self.stockage, self._now()
            if not self._signature_ok("reprise", ts, signature) or ts <= float(st.meta("ts_reprise", 0)):
                return False
            st.ecrire(lambda c: (st.poser(c, "pause_hm", 0), st.poser(c, "derniere_sante", now),
                                 st.poser(c, "ts_reprise", ts), st.poser(c, "dernier_instant", now),
                                 self._journaliser(c, "reprise", "commande reprise")))
            return True

    # --- mode témoins ----------------------------------------------------------------------------
    def temoins_actifs(self) -> bool:
        return self.ferme or self.stockage.meta("temoins") == "1"

    def allumer_temoins(self, signature: str, ts: float) -> bool:
        if self.ferme or not self._signature_ok("temoins-on", ts, signature):
            return False
        st = self.stockage
        st.ecrire(lambda c: (st.poser(c, "temoins", 1), self._journaliser(c, "temoins-on", "crm")))
        return True

    def eteindre_temoins(self, origine: str, operateur: str) -> bool:
        """Seule l'origine « local » (commande sur le serveur) éteint, et c'est journalisé."""
        if self.ferme:
            return False
        st = self.stockage
        if origine != "local":
            st.ecrire(lambda c: self._journaliser(c, "alerte-temoins-off-refuse", origine))
            return False
        st.ecrire(lambda c: (st.poser(c, "temoins", 0), self._journaliser(c, "temoins-off", operateur)))
        return True

    def journal(self):
        return self.stockage.journal()
