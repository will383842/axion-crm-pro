"""Relais : enregistrement Kumo -> evenement normalise -> puits (la passerelle K2 numerote et dedoublonne).

Aucune adresse en clair : seule l'empreinte du destinataire sort. Aucune action ici : le relais ne fait que transmettre.
"""
import hashlib
import os
import pathlib

from .classifieur import classifier


class PuitsMemoire:
    """Puits simule (tests) : garde les evenements ; `panne` simule une passerelle indisponible."""

    def __init__(self):
        self.evenements, self.panne = [], False

    def envoyer(self, evenement):
        if self.panne:
            raise IOError("puits indisponible")
        self.evenements.append(evenement)


class Relais:
    def __init__(self, regles, puits, source, workspace, fichier_etat=None):
        self.regles, self.puits, self.source, self.workspace = regles, puits, source, workspace
        self.fichier_etat, self.ignores = fichier_etat, 0
        self._seq = self._lire()
        self._en_attente = {}  # id_kumo+type -> src_seq : un rejeu garde le MEME numero

    def _lire(self):
        try:
            return int(pathlib.Path(self.fichier_etat).read_text().strip()) if self.fichier_etat else 0
        except (OSError, ValueError):
            return 0

    def _ecrire(self):
        if self.fichier_etat:
            tmp = str(self.fichier_etat) + ".tmp"
            with open(tmp, "w") as f:
                f.write(str(self._seq))
                f.flush()
                os.fsync(f.fileno())
            os.replace(tmp, self.fichier_etat)

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
        cle = "%s|%s" % (rec.get("id"), type_)
        if cle not in self._en_attente:
            self._seq += 1
            self._en_attente[cle] = self._seq
            self._ecrire()
        meta = rec.get("meta") or {}
        ev = {
            "workspace_id": self.workspace, "source": self.source, "src_seq": self._en_attente[cle],
            "id_kumo": str(rec.get("id")), "type": type_, "id_injection": str(meta.get("id_injection", ""))[:128],
            "jeton": meta.get("jeton"),
            "destinataire_empreinte": hashlib.sha256(str(rec.get("recipient", "")).lower().encode()).hexdigest(),
        }
        self.puits.envoyer(ev)  # IOError => l'appelant rejoue, le numero source est conserve
        del self._en_attente[cle]
        return ev
