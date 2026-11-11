"""6 boîtes témoins du banc (décision A10) : une par fournisseur, domaines .test uniquement.
Comportement simulable par boîte : inbox (réception), junk (indésirables), absent (rien reçu)."""
import hashlib, hmac, os, secrets

COMPORTEMENTS = ("inbox", "junk", "absent")

# Comportement par défaut = scénario S18 à 6 (les trois cas sont couverts).
TEMOINS = {
    "gmail": {"fournisseur": "gmail", "adresse": "temoin@gmail.test", "defaut": "inbox"},
    "google_workspace": {"fournisseur": "google_workspace", "adresse": "temoin@workspace.test", "defaut": "inbox"},
    "outlook_com": {"fournisseur": "outlook_com", "adresse": "temoin@outlook.test", "defaut": "junk"},
    "microsoft_365": {"fournisseur": "microsoft_365", "adresse": "temoin@m365.test", "defaut": "junk"},
    "yahoo": {"fournisseur": "yahoo", "adresse": "temoin@yahoo.test", "defaut": "absent"},
    "orange": {"fournisseur": "orange", "adresse": "temoin@orange.test", "defaut": "absent"},
}


class AdresseRefusee(Exception):
    pass


def adresses():
    return {t["adresse"] for t in TEMOINS.values()}


def mot_de_passe(secret, adresse):
    """Mot de passe dérivé d'un secret de banc fourni par l'environnement (BANC_SECRET), jamais écrit en dur."""
    return hmac.new(secret.encode(), adresse.encode(), hashlib.sha256).hexdigest()[:24]


class Boite:
    def __init__(self, adresse, comportement, secret=""):
        self.adresse, self.comportement = adresse, comportement
        # dérivé de BANC_SECRET si fourni ; sinon aléatoire (illisible : aucun LOGIN possible)
        self.mot_de_passe = mot_de_passe(secret, adresse) if secret else secrets.token_urlsafe(12)
        self.contenu = {"inbox": [], "junk": []}

    def __getitem__(self, dossier):
        return self.contenu[dossier]


class Banc:
    def __init__(self, env=None):
        """env : BANC_SECRET (mots de passe) et BANC_TEMOIN_<FOURNISSEUR>=inbox|junk|absent (comportement)."""
        env = os.environ if env is None else env
        secret = env.get("BANC_SECRET", "")
        self.boites = {}
        for cle, t in TEMOINS.items():
            c = env.get(f"BANC_TEMOIN_{cle.upper()}") or t["defaut"]
            if c not in COMPORTEMENTS:
                raise ValueError(f"BANC_TEMOIN_{cle.upper()} : comportement inconnu : {c}")
            self.boites[t["adresse"]] = Boite(t["adresse"], c, secret)

    def regler(self, adresse, comportement):
        if comportement not in COMPORTEMENTS:
            raise ValueError(f"comportement inconnu : {comportement}")
        self._boite(adresse).comportement = comportement

    def _boite(self, adresse):
        if adresse not in self.boites:
            raise AdresseRefusee(adresse)
        return self.boites[adresse]

    def livrer(self, adresse, message):
        b = self._boite(adresse)
        if b.comportement != "absent":
            b.contenu[b.comportement].append(message)
        return b.comportement
