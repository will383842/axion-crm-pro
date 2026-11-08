"""Socle du serveur d'envoi (K6a) : il DÉCIDE (accepter 250 / reporter 451 / refuser 550). KumoMTA dépose SEUL.

Règle d'or : un jeton est RÉSERVÉ sur disque (fsync) AVANT le 250, avec sa place de plafond et un identifiant de
message. Il passe à « confirme » plus tard (log hook K7, hors de ce lot). Le contenu des messages n'est jamais stocké.
Tout doute => FERMÉ (451), jamais « accepté ».

Temps : les DURÉES (purge 7 jours, nonces, homme mort, fenêtre de 2 min) se mesurent à l'horloge MONOTONE
(compteur `duree` persisté, qui ne compte que le temps d'exécution : un arrêt du processus ne vieillit rien, donc ne
purge jamais trop tôt). L'horloge MURALE ne sert qu'au jour Europe/Paris du plafond. Un saut de l'horloge murale
(avant ou arrière) ferme (pause persistée) et n'est levé que par la commande signée `reprise`.
"""
import hashlib
import hmac
import json
import math
import re
import secrets
import threading
import time
from collections import Counter
from dataclasses import dataclass

from . import signature
from .empreinte import empreinte_temoin, liste_valide
from .horloge import jour_paris
from .stockage import ErreurStockage, Stockage, assainir, cle_liste_temoins, cle_temoins

SEPT_JOURS = 7 * 86400
HOMME_MORT = 15 * 60
DELAI_RESERVE = 120  # un jeton réservé depuis moins de 2 min : un dépôt est peut-être en cours
TOLERANCE_HORLOGE = 60  # écart toléré entre horloge murale et monotone avant de fermer
JETON_MIN, JETON_MAX = 32, 128
_JETON_RE = re.compile(r"[A-Za-z0-9_-]+")  # hex ou base64url
MAX_REACCEPTATIONS = 3  # un jeton « reserve » jamais confirmé n'est ré-accepté que 3 fois, puis 451 définitif
# « retrait » (CRM) relève de la passerelle K2 : le socle ne le traite pas (404, nonce consommé, aucun effet).
COMMANDES = ("sante", "reprise", "temoins", "pause")
PREFIXE_COMMANDES = "/v1/commandes/"


def jeton_valide(jeton) -> bool:
    """Jeton opaque tiré au hasard : jeu hex/base64url, 32 à 128 caractères, entropie raisonnable."""
    if not isinstance(jeton, str) or not JETON_MIN <= len(jeton) <= JETON_MAX or not _JETON_RE.fullmatch(jeton):
        return False
    n = len(jeton)
    entropie = -sum(k / n * math.log2(k / n) for k in Counter(jeton).values())
    return len(set(jeton)) >= 10 and entropie >= 3.0  # refuse « aaaa… », « 0000…1 », « abab… »


@dataclass(frozen=True)
class Parametres:
    plafond: int  # plafond GLOBAL par jour Paris
    secret: bytes  # secret HMAC du contrat v2 (vide => tout est refusé)
    cle_empreinte: bytes = b""  # clé d'empreinte des témoins (ENVOI_CLE_EMPREINTE du CRM)
    plafond_espace: int | None = None  # plafond PAR ESPACE (défaut : le plafond global)
    delai_homme_mort: int = HOMME_MORT
    retention_jetons: int = SEPT_JOURS
    delai_reserve: int = DELAI_RESERVE
    retention_nonces: int = signature.NONCE_SECONDES

    @property
    def plafond_par_espace(self) -> int:
        return self.plafond if self.plafond_espace is None else self.plafond_espace


@dataclass(frozen=True)
class Reponse:
    code: int
    texte: str
    id: str | None = None
    rejoue: bool = False


class Socle:
    _apres_reserve = None  # crochet de test : simule un kill -9 entre la réservation et le 250

    def __init__(self, stockage: Stockage | None, horloge, p: Parametres, monotone=time.monotonic):
        self.stockage, self.horloge, self.p = stockage, horloge, p
        self.monotone = monotone
        self.ferme = stockage is None
        self._verrou = threading.RLock()
        self._mono_sante = None  # monotone à la dernière santé de CE processus (jamais persisté)
        self._ref = None  # (murale, monotone) au dernier contrôle SAIN : sert à détecter (et à lever) un saut
        self._mono_prec = None
        self._duree_cumulee = 0.0
        if stockage is not None:
            try:
                self._duree_cumulee = float(stockage.meta("duree", 0))
            except (ValueError, ErreurStockage):
                self.ferme = True

    @classmethod
    def ouvrir(cls, chemin, horloge, p, monotone=time.monotonic):
        try:
            return cls(Stockage(chemin), horloge, p, monotone)
        except ErreurStockage:
            return cls(None, horloge, p, monotone)  # FERMÉ par défaut

    def close(self):
        if self.stockage is not None:
            self.stockage.close()

    # --- temps -----------------------------------------------------------------------------------
    def _duree(self) -> float:
        """Temps d'exécution cumulé (monotone). Ne recule jamais ; un saut de l'horloge murale ne le touche pas."""
        m = self.monotone()
        if self._mono_prec is not None:
            self._duree_cumulee += max(0.0, m - self._mono_prec)
        self._mono_prec = m
        return self._duree_cumulee

    def _poser_duree(self, c):
        self.stockage.poser(c, "duree", self._duree_cumulee)

    def jour_courant(self) -> str:
        return max(jour_paris(self.horloge()), self.stockage.meta("dernier_jour", ""))

    def compteur_du_jour(self, espace="*", jour=None) -> int:
        return self.stockage.compteur(jour or self.jour_courant(), espace)

    def _journaliser(self, c, type_, detail):
        c.execute("INSERT INTO journal(ts,type,detail) VALUES(?,?,?)", (time.time(), type_, detail))

    # --- requêtes signées (contrat v2) -----------------------------------------------------------
    def verifier_requete(self, methode, chemin, horodatage, nonce, workspace_id, corps, entete) -> int:
        """200 accepté ; 401 signature/horodatage/nonce invalide ; 409 nonce rejoué ; 503 stockage indisponible."""
        if self.ferme:
            return 503
        if not signature.verifier(self.p.secret, methode, chemin, horodatage, nonce, workspace_id, corps, entete,
                                  self.horloge()):
            return 401
        cle = hashlib.sha256(f"{workspace_id}|{nonce}".encode()).hexdigest()
        try:
            with self._verrou:
                d = self._duree()

                def consommer(c):
                    c.execute("DELETE FROM nonces WHERE expire_duree<?", (d,))
                    neuf = c.execute("INSERT OR IGNORE INTO nonces VALUES(?,?)",
                                     (cle, d + self.p.retention_nonces)).rowcount == 1
                    self._poser_duree(c)
                    return neuf
                return 200 if self.stockage.ecrire(consommer) else 409
        except ErreurStockage:
            return 503

    def traiter(self, methode, chemin, horodatage, nonce, workspace_id, corps, entete) -> int:
        """Commande signée du CRM : /v1/commandes/{sante,reprise,temoins,pause}. Rend un statut HTTP (200 401 403 404 405 409
        422 500 503 ; 500 = exception interne, fermé)."""
        statut = self.verifier_requete(methode, chemin, horodatage, nonce, workspace_id, corps, entete)
        if statut != 200:
            return statut
        nom = chemin[len(PREFIXE_COMMANDES):] if chemin.startswith(PREFIXE_COMMANDES) else ""
        if nom not in COMMANDES:
            return 404
        if methode.upper() != "POST":
            return 405
        try:
            with self._verrou:
                return {"sante": self._sante, "reprise": self._reprise,
                        "temoins": lambda: self._temoins(workspace_id, corps),
                        "pause": lambda: self._pause(corps)}[nom]()
        except ErreurStockage:
            return 503
        except Exception:  # fermé par défaut
            return 500

    # --- jetons / décision -----------------------------------------------------------------------
    def decider(self, workspace_id: str, jeton: str, destinataire: str, contenu: bytes) -> Reponse:
        if self.ferme:
            return Reponse(451, "4.3.0 socle ferme, report")
        try:
            with self._verrou:
                return self._decider(workspace_id, jeton, destinataire, contenu)
        except ErreurStockage:
            return Reponse(451, "4.3.1 stockage indisponible, report")
        except Exception:  # fermé par défaut
            return Reponse(451, "4.3.0 erreur interne, report")

    def _decider(self, ws, jeton, dest, contenu) -> Reponse:
        st = self.stockage
        if not jeton_valide(jeton):
            return Reponse(550, "5.5.4 jeton invalide (32 a 128 car. hex/base64url, aleatoire)")
        if not signature.est_uuid(ws):
            return Reponse(550, "5.5.4 espace invalide")
        mur = self.horloge()
        pause = self._verifier_homme_mort(mur)
        d = self._duree()
        jh = hashlib.sha256(jeton.encode()).hexdigest()
        emp = hmac.new(self.p.secret, dest.encode() + b"\0" + contenu, hashlib.sha256).hexdigest()
        seuil = d - self.p.retention_jetons
        if st.c.execute("SELECT 1 FROM jetons WHERE cree_duree<? LIMIT 1", (seuil,)).fetchone():
            st.ecrire(lambda c: c.execute("DELETE FROM jetons WHERE cree_duree<?", (seuil,)))
        j = st.jeton(jh)
        if j:
            if j[4] != ws:  # un jeton appartient à SON espace
                st.ecrire(lambda c: self._journaliser(c, "alerte-jeton-espace", jh[:8]))
                return Reponse(550, "5.5.4 jeton deja utilise dans un autre espace")
            if j[0] != emp:
                st.ecrire(lambda c: self._journaliser(c, "alerte-jeton-contenu", jh[:8]))
                return Reponse(550, "5.5.4 jeton deja utilise avec un autre contenu")
            if j[2] == "confirme":
                # Rejeu d'un message déjà reçu par KumoMTA : jamais un passe-droit. Pause, témoins et liste valent AUSSI ici.
                refus = self._refus(ws, dest, pause)
                if refus:
                    return refus
                # rejoue=True : répondre 250 au client SANS mettre en file (le message est jeté). Aucun dépôt.
                return Reponse(250, "2.0.0 ok", j[1], rejoue=True)
            if d - j[3] < self.p.delai_reserve:
                return Reponse(451, "4.3.2 depot en cours, report")
            refus = self._refus(ws, dest, pause)  # pause, mode témoins et liste : à CHAQUE nouvelle acceptation
            if refus:
                return refus
            if j[5] >= MAX_REACCEPTATIONS:
                st.ecrire(lambda c: self._journaliser(c, "jeton-reacceptations-max", jh[:8]))
                return Reponse(451, "4.7.2 jeton reaccepte trop de fois sans confirmation, refus definitif")
            st.ecrire(lambda c: (c.execute("UPDATE jetons SET reserve_duree=?, reacceptations=reacceptations+1 "
                                           "WHERE jeton_h=?", (d, jh)), self._poser_duree(c)))
            # Même id, même place de plafond ; rien n'est confirmé : le message est à DÉPOSER (rejoue=False).
            return Reponse(250, "2.0.0 ok", j[1])
        refus = self._refus(ws, dest, pause)
        if refus:
            return refus
        id_ = secrets.token_hex(12)
        motif = st.ecrire(lambda c: self._reserver(c, jh, emp, id_, ws, mur, d))
        if motif:
            return Reponse(451, f"4.7.1 plafond {motif} journalier atteint, report")
        if self._apres_reserve:
            self._apres_reserve()
        return Reponse(250, "2.0.0 ok", id_)

    def _reserver(self, c, jh, emp, id_, ws, mur, d):
        """Dans la transaction : plafond global ET par espace, place, jeton réservé. None = réservé."""
        st = self.stockage
        jour = max(jour_paris(mur), st.meta("dernier_jour", ""))
        if st.compteur(jour) >= self.p.plafond:
            return "global"
        if st.compteur(jour, ws) >= self.p.plafond_par_espace:
            return "de l'espace"
        for espace in ("*", ws):
            c.execute("INSERT INTO compteur VALUES(?,?,1) ON CONFLICT(jour,espace) DO UPDATE SET n=n+1",
                      (jour, espace))
        c.execute("INSERT INTO jetons(jeton_h,empreinte,id,espace,jour,etat,cree_duree,reserve_duree) "
                  "VALUES(?,?,?,?,?,'reserve',?,?)", (jh, emp, id_, ws, jour, d, d))
        st.poser(c, "dernier_jour", jour)
        self._poser_duree(c)
        return None

    def _refus(self, ws, dest, pause) -> Reponse | None:
        if pause:
            return Reponse(451, "4.7.0 pause totale (homme mort), report")
        if self.temoins_actifs(ws):
            emp = empreinte_temoin(dest, self.p.cle_empreinte)
            if emp is None or emp not in self.liste_temoins(ws):
                # 451 (report) et non 550 : un message n'est JAMAIS perdu ; il repart quand l'espace sort du mode témoins.
                return Reponse(451, "4.7.1 mode temoins : destinataire hors liste, report")
        return None

    def confirmer(self, id_message: str) -> bool:
        """reserve -> confirme. Réservé au log hook K7 (local, hors réseau) ; non exposé par `traiter`."""
        if self.ferme:
            return False
        with self._verrou:
            def f(c):
                return c.execute("UPDATE jetons SET etat='confirme' WHERE id=?", (str(id_message),)).rowcount == 1
            return bool(self.stockage.ecrire(f))

    # --- homme mort ------------------------------------------------------------------------------
    def _verifier_homme_mort(self, mur) -> bool:
        """True = pause. Durées monotones ; tout saut de l'horloge murale (avant OU arrière) FERME, jamais n'ouvre."""
        st = self.stockage
        if st.meta("pause_hm") != "0":  # absente ou illisible => FERMÉ ; seule la valeur « 0 » ouvre
            return True
        mono = self.monotone()
        ls = st.meta("derniere_sante")
        motif = None
        if ls is None:
            motif = "aucune sante recue"
        elif mur < float(ls) - TOLERANCE_HORLOGE:
            motif = "horloge reculee"
        elif self._ref and abs((mur - self._ref[0]) - (mono - self._ref[1])) > TOLERANCE_HORLOGE:
            motif = "saut d'horloge"
        elif self._mono_sante is not None and mono - self._mono_sante > self.p.delai_homme_mort:
            motif = "15 min sans sante (monotone)"
        elif mur - float(ls) > self.p.delai_homme_mort:
            motif = "15 min sans sante"
        if motif:  # la référence saine n'est PAS avancée : elle sert à refuser une reprise sans correction
            self._duree()
            st.ecrire(lambda c: (st.poser(c, "pause_hm", 1), self._poser_duree(c),
                                 self._journaliser(c, "homme-mort", motif)))
            return True
        self._ref = (mur, mono)
        return False

    def tick(self) -> bool:
        """À appeler toutes les 30 s par un fil : constate la pause SANS trafic. True = en pause (ou fermé)."""
        if self.ferme:
            return True
        with self._verrou:
            try:
                return self._verifier_homme_mort(self.horloge())
            except ErreurStockage:
                return True

    def lancer_minuterie(self, intervalle: float = 30):
        """Fil démon qui appelle tick(). Rend la fonction d'arrêt. Le branchement du service est en K6b."""
        arret = threading.Event()

        def boucle():
            while not arret.wait(intervalle):
                self.tick()
        t = threading.Thread(target=boucle, name="socle-minuterie", daemon=True)
        t.start()

        def stop():
            arret.set()
            t.join(5)
        return stop

    def _sante(self) -> int:
        """Signe de vie du CRM. Constate d'abord l'absence éventuelle ; ne lève JAMAIS la pause (voir `reprise`)."""
        st, mur = self.stockage, self.horloge()
        self._verifier_homme_mort(mur)
        self._duree()
        st.ecrire(lambda c: (st.poser(c, "derniere_sante", mur), self._poser_duree(c)))
        self._mono_sante = self.monotone()
        return 200

    def _reprise(self) -> int:
        st, mur, mono = self.stockage, self.horloge(), self.monotone()
        ls = st.meta("derniere_sante")
        if (self._ref and abs((mur - self._ref[0]) - (mono - self._ref[1])) > TOLERANCE_HORLOGE) \
                or (ls is not None and mur < float(ls) - TOLERANCE_HORLOGE):
            return 409  # horloge murale pas corrigée : on ne rouvre pas
        self._duree()
        st.ecrire(lambda c: (st.poser(c, "pause_hm", 0), st.poser(c, "derniere_sante", mur), self._poser_duree(c),
                             self._journaliser(c, "reprise", "commande reprise")))
        self._mono_sante, self._ref = mono, (mur, mono)
        return 200

    # --- mode témoins (PAR ESPACE, comme dans le CRM) ----------------------------------------------
    def temoins_actifs(self, ws) -> bool:
        """Fermé par défaut : seule la valeur « 0 » enregistrée pour CET espace éteint le mode."""
        return self.ferme or self.stockage.meta(cle_temoins(ws)) != "0"

    def liste_temoins(self, ws) -> frozenset:
        if self.ferme:
            return frozenset()
        try:
            liste = json.loads(self.stockage.meta(cle_liste_temoins(ws), "[]"))
        except ValueError:
            return frozenset()
        return frozenset(liste) if liste_valide(liste) else frozenset()

    def _pause(self, corps) -> int:
        """Commande `pause` du CRM : {commande_id, commande:"pause"}. Arrêt LOCAL écrit sur disque. Restrictif donc
        toujours permis (idempotent) ; seule la commande signée `reprise` rouvre."""
        try:
            data = json.loads(corps)
        except ValueError:
            return 422
        if not isinstance(data, dict) or set(data) != {"commande_id", "commande"} or data["commande"] != "pause" \
                or not signature.est_uuid(data["commande_id"]):
            return 422
        st = self.stockage
        self._duree()
        st.ecrire(lambda c: (st.poser(c, "pause_hm", 1), self._poser_duree(c),
                             self._journaliser(c, "pause-crm", data["commande_id"][:8])))
        return 200

    def _temoins(self, ws, corps) -> int:
        """modeTemoins(actif, liste d'EMPREINTES) pour l'espace `ws` (signé). Éteint : le CRM peut allumer. Déjà actif :
        il ne peut que RÉDUIRE (intersection) ; agrandir ou éteindre passe par la console locale."""
        try:
            data = json.loads(corps)
        except ValueError:
            return 422
        if not isinstance(data, dict) or not isinstance(data.get("actif"), bool) or not liste_valide(data.get("liste")):
            return 422
        st = self.stockage
        if not self.temoins_actifs(ws):
            if not data["actif"]:
                return 200
            liste = sorted(set(data["liste"]))
            st.ecrire(lambda c: (st.poser(c, cle_temoins(ws), 1), st.poser(c, cle_liste_temoins(ws), json.dumps(liste)),
                                 self._journaliser(c, "temoins-on", f"{len(liste)} empreintes")))
            return 200
        if not data["actif"]:
            st.ecrire(lambda c: self._journaliser(c, "alerte-temoins-crm-refuse", "extinction demandee par le CRM"))
            return 403
        liste = sorted(self.liste_temoins(ws) & set(data["liste"]))
        st.ecrire(lambda c: (st.poser(c, cle_liste_temoins(ws), json.dumps(liste)),
                             self._journaliser(c, "temoins-reduit", f"{len(liste)} empreintes")))
        return 200

    def eteindre_temoins(self, origine: str, operateur: str) -> bool:
        """REFUS systématique. Ce chemin (atteignable par le réseau) n'éteint jamais, quelle que soit l'« origine »
        déclarée : une chaîne fournie par l'appelant n'est pas une preuve de localité. L'extinction passe par
        `axion_socle.local` (console du serveur, non exposée à la couche HTTP)."""
        if self.ferme:
            return False
        with self._verrou:
            st = self.stockage
            st.ecrire(lambda c: self._journaliser(c, "alerte-temoins-off-refuse", assainir(origine)))
            return False

    def journal(self):
        return self.stockage.journal()
