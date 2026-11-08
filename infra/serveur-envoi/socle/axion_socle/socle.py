"""Socle du serveur d'envoi (K6) : plafond dur, homme mort, mode témoins, jetons sur disque.

Règle d'or : un jeton est écrit et synchronisé sur disque AVANT le 250. Un arrêt brutal à n'importe quel
point est rattrapé par le rejeu du client (même jeton => même id, dépôt idempotent) : zéro perte, zéro doublon.
Tout doute => FERMÉ (451), jamais « accepté ».
"""
import hashlib
import hmac
import math
import re
import secrets
import threading
import time
from collections import Counter
from dataclasses import dataclass

from .horloge import jour_paris
from .sortie import Sortie
from .stockage import ErreurStockage, Stockage, assainir

SEPT_JOURS = 7 * 86400
HOMME_MORT = 15 * 60
TOLERANCE_HORLOGE = 60  # écart toléré entre horloge murale et monotone avant de fermer
JETON_MIN, JETON_MAX = 32, 128
_JETON_RE = re.compile(r"[A-Za-z0-9_-]+")  # hex ou base64url


def jeton_valide(jeton) -> bool:
    """Jeton opaque tiré au hasard : jeu hex/base64url, 32 à 128 caractères, entropie raisonnable."""
    if not isinstance(jeton, str) or not JETON_MIN <= len(jeton) <= JETON_MAX or not _JETON_RE.fullmatch(jeton):
        return False
    n = len(jeton)
    entropie = -sum(k / n * math.log2(k / n) for k in Counter(jeton).values())
    return len(set(jeton)) >= 10 and entropie >= 3.0  # refuse « aaaa… », « 0000…1 », « abab… »


def _ts_entier(ts):
    """Format canonique de l'horodatage signé : entier de secondes. None si invalide."""
    if isinstance(ts, bool) or not isinstance(ts, (int, float)) or not math.isfinite(ts):
        return None
    return int(ts)


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
    return hmac.new(cle, f"{commande}|{int(ts)}".encode(), hashlib.sha256).hexdigest()


class Socle:
    def __init__(self, stockage: Stockage | None, horloge, sortie: Sortie, p: Parametres, monotone=time.monotonic):
        self.stockage, self.horloge, self.sortie, self.p = stockage, horloge, sortie, p
        self.monotone = monotone
        self.ferme = stockage is None
        self._verrou = threading.RLock()
        self._mono_sante = None  # monotone à la dernière santé de CE processus (jamais persisté)
        self._ref_horloge = None  # (murale, monotone) au dernier contrôle, pour détecter un saut

    @classmethod
    def ouvrir(cls, chemin, horloge, sortie, p, monotone=time.monotonic):
        try:
            return cls(Stockage(chemin), horloge, sortie, p, monotone)
        except ErreurStockage:
            return cls(None, horloge, sortie, p, monotone)  # FERMÉ par défaut

    def close(self):
        if self.stockage is not None:
            self.stockage.close()

    # --- temps -----------------------------------------------------------------------------------
    def _now(self) -> float:
        """Jamais en arrière : une horloge reculée ne rouvre ni plafond ni homme mort."""
        return max(self.horloge(), float(self.stockage.meta("dernier_instant", 0)))

    def _jour(self, now) -> str:
        return max(jour_paris(now), self.stockage.meta("dernier_jour", ""))

    def _signature_ok(self, commande, ts, sig) -> bool:
        ts = _ts_entier(ts)
        return ts is not None and isinstance(sig, str) and hmac.compare_digest(sig, signer(self.p.cle, commande, ts))

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
        if not jeton_valide(jeton):
            return Reponse(550, "5.5.4 jeton invalide (32 a 128 car. hex/base64url, aleatoire)")
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
                    refus = self._depot_refuse(dest)  # pause, mode témoins et liste blanche : à CHAQUE dépôt
                    if refus:
                        return refus
                    if not self._deposer(jeton, j[1], dest, bytes(j[3])):
                        return Reponse(451, "4.3.2 file indisponible, report")
                return Reponse(250, "2.0.0 ok", j[1], rejoue=True)
            if pause:
                return Reponse(451, "4.7.0 pause totale (homme mort), report")
            refus = self._depot_refuse(dest)
            if refus:
                return refus
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

    def _depot_refuse(self, dest) -> Reponse | None:
        """Garde unique, appelée avant TOUT dépôt (nouveau, rejeu, récupération) : homme mort, témoins, liste."""
        if self._verifier_homme_mort(self._now()):
            return Reponse(451, "4.7.0 pause totale (homme mort), report")
        if self.temoins_actifs() and dest not in self.p.liste_blanche:
            return Reponse(550, "5.7.1 mode temoins : destinataire hors liste")
        return None

    def _deposer(self, jeton, id_, dest, contenu) -> bool:
        if self._depot_refuse(dest):  # défense en profondeur : aucun chemin ne contourne la garde
            return False
        try:
            self.sortie.deposer(id_, dest, contenu)
        except Exception:
            return False
        self.stockage.ecrire(lambda c: self.stockage.marquer_depose(c, jeton))
        return True

    def recuperer(self) -> int:
        """Au redémarrage : redépose (idempotent) ce qui était accepté mais pas encore déposé."""
        if self.ferme:
            return 0
        with self._verrou:
            return sum(self._deposer(j, i, d, bytes(c)) for j, i, d, c in self.stockage.en_attente())

    def compteur_du_jour(self) -> int:
        return self.stockage.compteur(self._jour(self._now()))

    # --- homme mort ------------------------------------------------------------------------------
    def _verifier_homme_mort(self, now) -> bool:
        """True = pause. Horloge monotone ; tout saut de l'horloge murale (avant OU arrière) FERME, jamais n'ouvre."""
        st = self.stockage
        if st.meta("pause_hm") == "1":
            return True
        mur, mono = self.horloge(), self.monotone()
        ls = st.meta("derniere_sante")
        motif = None
        if ls is None:
            motif = "aucune sante recue"
        elif mur < float(ls) - TOLERANCE_HORLOGE:
            motif = "horloge reculee"
        elif self._ref_horloge and abs((mur - self._ref_horloge[0]) - (mono - self._ref_horloge[1])) > TOLERANCE_HORLOGE:
            motif = "saut d'horloge"
        elif self._mono_sante is not None and mono - self._mono_sante > self.p.delai_homme_mort:
            motif = "15 min sans sante (monotone)"
        elif mur - float(ls) > self.p.delai_homme_mort:
            motif = "15 min sans sante"
        self._ref_horloge = (mur, mono)
        if motif:
            st.ecrire(lambda c: (st.poser(c, "pause_hm", 1), st.poser(c, "dernier_instant", now),
                                 self._journaliser(c, "homme-mort", motif)))
            return True
        return False

    def sante(self, ts: float, signature: str) -> bool:
        """Signe de vie du CRM. Forgée ou rejouée : ignorée. Ne lève jamais la pause (voir `reprise`)."""
        if self.ferme:
            return False
        with self._verrou:
            st, now = self.stockage, self._now()
            ts = _ts_entier(ts)
            if ts is None or not self._signature_ok("sante", ts, signature) or ts <= int(st.meta("ts_sante", 0)) \
                    or ts > now + 60:
                return False
            mur, mono = self.horloge(), self.monotone()
            st.ecrire(lambda c: (st.poser(c, "derniere_sante", mur), st.poser(c, "ts_sante", ts),
                                 st.poser(c, "dernier_instant", now)))
            self._mono_sante, self._ref_horloge = mono, (mur, mono)
            return True

    def reprise(self, signature: str, ts: float = 0) -> bool:
        if self.ferme:
            return False
        with self._verrou:
            st, now = self.stockage, self._now()
            ts = _ts_entier(ts)
            if ts is None or not self._signature_ok("reprise", ts, signature) or ts <= int(st.meta("ts_reprise", 0)):
                return False
            mur, mono = self.horloge(), self.monotone()
            st.ecrire(lambda c: (st.poser(c, "pause_hm", 0), st.poser(c, "derniere_sante", mur),
                                 st.poser(c, "ts_reprise", ts), st.poser(c, "dernier_instant", now),
                                 self._journaliser(c, "reprise", "commande reprise")))
            self._mono_sante, self._ref_horloge = mono, (mur, mono)
            return True

    # --- mode témoins ----------------------------------------------------------------------------
    def temoins_actifs(self) -> bool:
        return self.ferme or self.stockage.meta("temoins") == "1"

    def allumer_temoins(self, signature: str, ts: float) -> bool:
        if self.ferme or not self._signature_ok("temoins-on", ts, signature):
            return False
        with self._verrou:
            st = self.stockage
            st.ecrire(lambda c: (st.poser(c, "temoins", 1), self._journaliser(c, "temoins-on", "crm")))
            return True

    def eteindre_temoins(self, origine: str, operateur: str) -> bool:
        """REFUS systématique. Ce chemin (atteignable par le réseau) n'éteint jamais, quelle que soit l'« origine »
        déclarée : une chaîne fournie par l'appelant n'est pas une preuve de localité. L'extinction passe par
        `axion_socle.local.eteindre_temoins_local` (console du serveur, non exposée à la couche HTTP)."""
        if self.ferme:
            return False
        with self._verrou:
            st = self.stockage
            st.ecrire(lambda c: self._journaliser(c, "alerte-temoins-off-refuse", assainir(origine)))
            return False

    def journal(self):
        return self.stockage.journal()
