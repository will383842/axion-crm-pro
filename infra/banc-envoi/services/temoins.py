"""6 boîtes témoins du banc (décision A10) : une par fournisseur, domaines .test uniquement.
Comportement simulable par boîte : inbox (réception), junk (indésirables), absent (rien reçu)."""
import secrets

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


class Boite:
    def __init__(self, adresse, comportement):
        self.adresse, self.comportement = adresse, comportement
        self.mot_de_passe = secrets.token_urlsafe(12)  # généré au démarrage, jamais écrit en dur
        self.contenu = {"inbox": [], "junk": []}

    def __getitem__(self, dossier):
        return self.contenu[dossier]


class Banc:
    def __init__(self):
        self.boites = {t["adresse"]: Boite(t["adresse"], t["defaut"]) for t in TEMOINS.values()}

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
