"""Machine à états SMTP pure (sans socket) : testable ligne à ligne."""
import base64
import re
from email.utils import getaddresses

from .config import Config, normaliser_domaine, verifier
from .file_kumo import ErreurFile, FileKumo

ENTETE_RETIRE = re.compile(rb"(?i)^(x-tenant|return-path)[ \t]*:")
VERBES = frozenset({"EHLO", "HELO", "NOOP", "RSET", "QUIT", "STARTTLS", "AUTH", "MAIL", "RCPT", "DATA"})
ADRESSE = re.compile(r"^<([^<>\s]+@[^<>\s@]+)>(\s.*)?$")
EN_TETES_ADRESSES = ("from", "sender", "reply-to", "resent-from", "resent-sender")
AVANT_TLS = {"EHLO", "HELO", "NOOP", "QUIT", "STARTTLS", "RSET"}


def retirer_x_tenant(message: bytes) -> bytes:
    """Retire les en-têtes X-Tenant et Return-Path fournis par le client (lignes repliées comprises)."""
    tete, sep, corps = message.partition(b"\r\n\r\n")
    sortie, saute = [], False
    for l in tete.split(b"\r\n"):
        if l[:1] in (b" ", b"\t"):
            if not saute:
                sortie.append(l)
            continue
        saute = bool(ENTETE_RETIRE.match(l))
        if not saute:
            sortie.append(l)
    return b"\r\n".join(sortie) + sep + corps


def entetes(message: bytes) -> list[tuple[str, str]]:
    """(nom en minuscules, valeur dépliée) de chaque en-tête, dans l'ordre."""
    tete = message.partition(b"\r\n\r\n")[0].decode("utf-8", "replace")
    res: list[list[str]] = []
    for l in tete.split("\r\n"):
        if l[:1] in (" ", "\t") and res:
            res[-1][1] += " " + l.strip()
        elif ":" in l:
            n, _, v = l.partition(":")
            res.append([n.strip().lower(), v.strip()])
    return [(n, v) for n, v in res]


def verifier_python() -> None:
    """Refuse de démarrer si getaddresses n'a pas le paramètre strict (Python >= 3.11.10 requis)."""
    try:
        getaddresses(["a@example.test"], strict=True)
    except TypeError as e:
        raise RuntimeError("Python trop ancien : email.utils.getaddresses sans strict (>= 3.11.10 requis)") from e


def adresses(valeur: str) -> list[str] | None:
    """Domaines (normalisés) de toutes les adresses ; None si l'en-tête est inanalysable.

    Sans le paramètre strict, aucun repli sur l'analyseur permissif : None (le message est refusé)."""
    try:
        paires = getaddresses([valeur], strict=True)
    except TypeError:
        return None
    doms = []
    for _, adr in paires:
        if adr.count("@") != 1 or not adr.rpartition("@")[2]:
            return None
        doms.append(normaliser_domaine(adr.rpartition("@")[2]))
    return doms or None


class Session:
    def __init__(self, cfg: Config, file: FileKumo, ip_pair: str, limiteur=None):
        self.cfg, self.file, self.ip, self.limiteur = cfg, file, ip_pair, limiteur
        self.delai = 0.0          # délai à imposer avant la prochaine réponse (anti force brute)
        self._attente = None      # AUTH en attente de vérification hors boucle
        self._reserve = None      # délai de l'échec réservé avant PBKDF2
        self.tls = False
        self.authc = None
        self.echecs = 0
        self._raz()
        self.mode = "cmd"

    def _raz(self):
        self.mail_from = self.rcpt = None
        self.data: list[bytes] = []
        self.taille = 0
        self.mode = "cmd"

    def accueil(self):
        if not self.cfg.ip_autorisee(self.ip):          # couche 1
            return ["554 5.7.1 IP non autorisee"], "close"
        if self.limiteur and self.limiteur.bloque(self.ip):
            return ["421 4.7.0 trop d'echecs depuis cette adresse"], "close"
        return ["220 injection ESMTP"], None

    def ligne(self, brut: bytes, differer_auth: bool = False):
        """Retourne (réponses, action) ; action ∈ {None, 'starttls', 'close'}."""
        if self.mode == "data":
            return self._lire_data(brut)
        if self.mode == "auth":
            self.mode = "cmd"
            return self._auth_creds(brut.strip().decode("ascii", "replace"), differer_auth)
        txt = brut.rstrip(b"\r\n").decode("utf-8", "replace")
        verbe, _, arg = txt.partition(" ")
        verbe, arg = verbe.upper(), arg.strip()
        if not self.tls and verbe not in AVANT_TLS:
            return ["530 5.7.0 STARTTLS obligatoire"], None
        if verbe not in VERBES:                           # liste blanche explicite
            return ["502 5.5.2 commande inconnue"], None
        self._differer = differer_auth
        return getattr(self, "_" + verbe.lower())(arg)

    # --- commandes ---
    def _ehlo(self, arg):
        r = ["250-injection", "250-SIZE %d" % self.cfg.max_taille]
        r.append("250 AUTH PLAIN" if self.tls else "250 STARTTLS")
        return r, None

    _helo = _ehlo

    def _noop(self, arg):
        return ["250 2.0.0 ok"], None

    def _rset(self, arg):
        self._raz()
        return ["250 2.0.0 ok"], None

    def _quit(self, arg):
        return ["221 2.0.0 au revoir"], "close"

    def _starttls(self, arg):
        if self.tls:
            return ["503 5.5.1 TLS deja actif"], None
        return ["220 2.0.0 pret pour TLS"], "starttls"

    def tls_etabli(self):
        self.tls, self.authc = True, None
        self._raz()

    def _auth(self, arg):
        parts = arg.split()
        if self.authc:
            return ["503 5.5.1 deja authentifie"], None
        if not parts or parts[0].upper() != "PLAIN":
            return ["504 5.5.4 mecanisme non supporte"], None
        if len(parts) > 1:
            return self._auth_creds(parts[1], getattr(self, "_differer", False))
        self.mode = "auth"
        return ["334 "], None

    def _auth_creds(self, b64, differer=False):
        try:
            authz, authc, mdp = base64.b64decode(b64, validate=True).decode().split("\0")
        except (ValueError, UnicodeError):
            return ["501 5.5.2 AUTH mal forme"], None
        self._attente = (authz, authc, mdp)
        if differer:                       # le serveur enchaîne preparer / executor / conclure
            return [], "auth"
        return self.verifier_auth()

    def preparer_auth(self):
        """Dans la boucle asyncio, AVANT PBKDF2 : blocage par IP puis réservation d'un échec.

        L'échec est compté tout de suite (avant le calcul) : des sessions simultanées ne peuvent pas
        lancer plus de `max_echecs_ip` vérifications. Retourne une réponse de refus, ou None."""
        self._reserve = None
        if self.limiteur:
            if self.limiteur.bloque(self.ip):
                return ["421 4.7.0 trop d'echecs depuis cette adresse"], "close"
            self._reserve = self.limiteur.echec(self.ip)
        return None

    def calculer_auth(self) -> bool:
        """PBKDF2 : coûteux, sans état partagé, exécutable dans un executor."""
        authz, authc, mdp = self._attente
        cpt = self.cfg.comptes.get(authc)
        empreinte = cpt["hash"] if cpt else self.cfg.empreinte_secours   # même coût dans les deux cas
        ok = verifier(mdp, empreinte)
        return bool(cpt and ok and not (authz and authz != authc))       # authz != authc : refus

    def conclure_auth(self, ok: bool):
        """Dans la boucle asyncio, APRÈS PBKDF2."""
        _, authc, _ = self._attente
        self._attente = None
        if not ok:
            self.echecs += 1
            if self._reserve is not None:
                self.delai = self._reserve
            if self.echecs >= 3:
                return ["421 4.7.0 trop d'echecs"], "close"
            return ["535 5.7.8 authentification refusee"], None
        if self.limiteur:
            self.limiteur.pardon(self.ip)             # succès : l'essai réservé n'était pas un échec
        self.authc = authc
        return ["235 2.7.0 ok"], None

    def verifier_auth(self):
        """Version synchrone (tests, hors serveur) : préparer, calculer, conclure."""
        refus = self.preparer_auth()
        if refus:
            self._attente = None
            return refus
        return self.conclure_auth(self.calculer_auth())

    def _mail(self, arg):
        if not self.authc:                                # couche 2
            return ["530 5.7.0 AUTH obligatoire"], None
        if self.mail_from:
            return ["503 5.5.1 MAIL deja donne (RSET d'abord)"], None
        m = re.match(r"(?i)^FROM:\s*(.*)$", arg)
        a = ADRESSE.match(m.group(1)) if m else None
        if not a:
            return ["501 5.1.7 MAIL FROM invalide"], None
        adr = a.group(1).lower()
        dom = normaliser_domaine(adr.rpartition("@")[2])
        if self.cfg.domaine_interdit(dom):                # refus permanent, avant tout
            return ["550 5.7.1 domaine interdit"], None
        espace = self.cfg.comptes[self.authc]["espace"]
        if adr not in self.cfg.registre.get(espace, set()):   # couche 3
            return ["550 5.7.1 MAIL FROM hors registre"], None
        if dom not in self.cfg.domaines_envoi:            # couche 4
            return ["550 5.7.1 domaine d'envoi inconnu"], None
        self.mail_from = adr
        return ["250 2.1.0 ok"], None

    def _rcpt(self, arg):
        if not self.mail_from:
            return ["503 5.5.1 MAIL d'abord"], None
        if self.rcpt:
            return ["452 4.5.3 un seul destinataire"], None
        m = re.match(r"(?i)^TO:\s*(.*)$", arg)
        a = ADRESSE.match(m.group(1)) if m else None
        if not a:
            return ["501 5.1.3 RCPT invalide"], None
        self.rcpt = a.group(1)
        return ["250 2.1.5 ok"], None

    def _data(self, arg):
        if not (self.mail_from and self.rcpt):
            return ["503 5.5.1 MAIL/RCPT d'abord"], None
        self.mode = "data"
        return ["354 fin par <CRLF>.<CRLF>"], None

    # --- corps ---
    def _lire_data(self, brut):
        if brut == b".\r\n":
            return self._fin()
        if not brut.endswith(b"\r\n") or b"\n" in brut[:-2] or b"\r" in brut[:-2]:
            self._raz()                                   # anti « SMTP smuggling »
            return ["550 5.6.0 fin de ligne invalide"], "close"
        self.taille += len(brut)
        if self.taille <= self.cfg.max_taille:
            self.data.append(brut[1:] if brut.startswith(b".") else brut)   # RFC 5321 §4.5.2
        return [], None

    def _fin(self):
        msg, mf, rcpt = b"".join(self.data), self.mail_from, self.rcpt
        trop = self.taille > self.cfg.max_taille
        self._raz()
        if trop:
            return ["552 5.3.4 message trop gros"], None
        refus = self._controle_entetes(msg)
        if refus:
            return [refus], None
        msg = retirer_x_tenant(msg)
        try:
            self.file.deposer(self.cfg.comptes[self.authc]["espace"], mf, rcpt, msg)
        except ErreurFile:
            return ["451 4.3.0 file indisponible"], None
        return ["250 2.0.0 en file"], None

    def _controle_entetes(self, msg: bytes) -> str | None:
        """Refus d'une DKIM-Signature déjà présente ; analyse réelle des adresses de TOUS les From, Sender, Reply-To, Resent-From, Resent-Sender."""
        ent = entetes(msg)
        if any(n == "dkim-signature" for n, _ in ent):    # ADR-K0 D3.3 : le serveur signe seul
            return "550 5.7.1 message deja signe DKIM : le serveur d'envoi signe, refuse"
        froms = [v for n, v in ent if n == "from"]
        if len(froms) > 1:
            return "550 5.7.1 plusieurs en-tetes From"
        autorises = self.cfg.domaines_registre(self.cfg.comptes[self.authc]["espace"])
        for n, v in ent:
            if n not in EN_TETES_ADRESSES:
                continue
            doms = adresses(v)
            if doms is None:
                return "550 5.7.1 en-tete %s inanalysable" % n
            if any(self.cfg.domaine_interdit(d) for d in doms):
                return "550 5.7.1 domaine interdit dans " + n
            if not all(d in autorises and d in self.cfg.domaines_envoi for d in doms):
                return "550 5.7.1 domaine hors registre de l'espace dans " + n
        return None
