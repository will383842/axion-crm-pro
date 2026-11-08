"""Relais : enregistrement Kumo -> evenement normalise -> puits (la passerelle K2 numerote et dedoublonne).

Aucune adresse en clair : seule l'empreinte du destinataire sort. Aucune action ici : le relais ne fait que transmettre.
L'espace (workspace) vient de la meta `espace` ecrite a l'injection (K4) : jamais un espace fixe, jamais devine.
Le numero source `src_seq` est tenu PAR ESPACE (sans trou pour chaque espace) et persiste avec les envois en attente.
"""
import hashlib
import json
import os
import pathlib
import re

from .classifieur import classifier

UUID = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")


class EvenementRefuse(ValueError):
    """Refus DEFINITIF de la passerelle (400) : on ne rejoue pas, sinon Kumo reessaierait sans fin."""


class PuitsMemoire:
    """Puits simule (tests) : garde les evenements ; `panne` simule une passerelle indisponible."""

    def __init__(self):
        self.evenements, self.panne = [], False

    def envoyer(self, evenement):
        if self.panne:
            raise IOError("puits indisponible")
        self.evenements.append(evenement)


class Relais:
    def __init__(self, regles, puits, source, fichier_etat=None, carte_espaces=None):
        self.regles, self.puits, self.source = regles, puits, source
        self.fichier_etat, self.carte = fichier_etat, carte_espaces or {}
        self.ignores = self.sans_espace = 0
        self.journal, self.alertes = [], []
        self._seq, self._en_attente = self._lire()  # _en_attente : cle -> src_seq (un rejeu garde le MEME numero)

    def _lire(self):
        try:
            d = json.loads(pathlib.Path(self.fichier_etat).read_text()) if self.fichier_etat else {}
            return dict(d.get("seq", {})), dict(d.get("attente", {}))
        except (OSError, ValueError, AttributeError, TypeError):
            return {}, {}

    def _ecrire(self):
        if self.fichier_etat:
            tmp = str(self.fichier_etat) + ".tmp"
            with open(tmp, "w") as f:
                f.write(json.dumps({"seq": self._seq, "attente": self._en_attente}))
                f.flush()
                os.fsync(f.fileno())
            os.replace(tmp, self.fichier_etat)

    def _espace(self, rec):
        brut = (rec.get("meta") or {}).get("espace")
        ws = self.carte.get(brut, brut) if isinstance(brut, str) else None
        return ws.lower() if isinstance(ws, str) and UUID.match(ws.lower()) else None

    def traiter(self, rec):
        t = rec.get("type")
        classe = classifier(rec, self.regles)
        if classe is not None:
            type_ = classe
        elif t == "Delivery":
            type_ = "livre"
        elif t == "Reception":
            type_ = "reception"
        else:
            self.ignores += 1
            return None
        ws = self._espace(rec)
        if ws is None:  # jamais attribue a un autre espace : on ecarte et on le dit
            self.sans_espace += 1
            self.journal.append(("sans_espace", str(rec.get("id"))))
            return None
        if type_ == "inconnu":
            self.journal.append(("inconnu", str(rec.get("id"))))
        if type_ == "alerte_config":
            self.alertes.append("alerte_config")
        cle = "%s|%s|%s" % (ws, rec.get("id"), type_)
        if cle not in self._en_attente:
            self._seq[ws] = self._seq.get(ws, 0) + 1
            self._en_attente[cle] = self._seq[ws]
            self._ecrire()
        meta = rec.get("meta") or {}
        ev = {
            "workspace_id": ws, "source": self.source, "src_seq": self._en_attente[cle],
            "id_kumo": str(rec.get("id")), "type": type_, "id_injection": str(meta.get("id_injection", ""))[:128],
            "jeton": meta.get("jeton"),
            "destinataire_empreinte": hashlib.sha256(str(rec.get("recipient", "")).lower().encode()).hexdigest(),
        }
        try:
            self.puits.envoyer(ev)  # IOError => l'appelant rejoue, le numero source est conserve
        except EvenementRefuse:
            del self._en_attente[cle]  # refus definitif : pas de rejeu (le numero reste consomme : trou REEL, alerte cote passerelle)
            self._ecrire()
            raise
        del self._en_attente[cle]
        self._ecrire()  # l'envoi abouti n'est plus « en attente » : un redemarrage ne le rejoue pas
        return ev
