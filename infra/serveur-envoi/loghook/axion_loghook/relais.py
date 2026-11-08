"""Relais : enregistrement Kumo -> evenement normalise -> puits (la passerelle K2 numerote et dedoublonne).

Aucune adresse en clair : seule l'empreinte du destinataire sort. Aucune action ici : le relais ne fait que transmettre.
L'espace (workspace) vient de la meta `espace` ecrite a l'injection (K4) : jamais un espace fixe, jamais devine.
Le numero source `src_seq` est tenu PAR ESPACE (sans trou pour chaque espace) et persiste avec les envois en attente.
"""
import collections
import hashlib
import hmac
import json
import os
import pathlib
import re
import secrets
import sys

from .classifieur import classifier

UUID = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")


class EvenementRefuse(ValueError):
    """Refus DEFINITIF de la passerelle (400) : on ne rejoue pas, sinon Kumo reessaierait sans fin."""


class EtatCorrompu(Exception):
    """Fichier d'etat illisible : le relais refuse de demarrer (repartir de zero reutiliserait des numeros deja servis)."""


class PuitsMemoire:
    """Puits simule (tests) : garde les evenements ; `panne` simule une passerelle indisponible."""

    def __init__(self):
        self.evenements, self.panne = [], False

    def envoyer(self, evenement):
        if self.panne:
            raise IOError("puits indisponible")
        self.evenements.append(evenement)


class Relais:
    MEMOIRE_MAX = 1000  # journal et alertes en memoire : bornes (les plus anciens sortent)
    ECARTES_MAX = 1000  # enregistrements sans espace gardes de cote : au-dela, 503 (Kumo reessaie), jamais de perte

    def __init__(self, regles, puits, source, fichier_etat=None, carte_espaces=None):
        self.regles, self.puits, self.source = regles, puits, source
        self.fichier_etat, self.carte = fichier_etat, carte_espaces if carte_espaces is not None else {}
        self.ignores = self.sans_espace = 0
        self.journal = collections.deque(maxlen=self.MEMOIRE_MAX)
        self.alertes = collections.deque(maxlen=self.MEMOIRE_MAX)
        self._seq, self._en_attente, self.ecartes, self._cle = self._lire()  # _en_attente : cle -> src_seq (un rejeu garde le MEME numero)

    def _lire(self):
        try:
            texte = pathlib.Path(self.fichier_etat).read_text() if self.fichier_etat else None
        except FileNotFoundError:
            texte = None
        except OSError as e:
            return self._corrompu(e)
        if texte is None:  # premiere mise en route : cle d'empreinte propre a cette installation
            return {}, {}, [], secrets.token_hex(32)
        try:
            d = json.loads(texte)
            seq, att, ecartes, cle = dict(d["seq"]), dict(d["attente"]), list(d.get("ecartes", [])), d["cle"]
            if not isinstance(cle, str) or len(cle) < 32:
                raise ValueError("cle d'empreinte absente")
        except (ValueError, KeyError, AttributeError, TypeError) as e:
            return self._corrompu(e)
        return seq, att, ecartes, cle

    def _corrompu(self, e):
        print("ALERTE loghook_etat_corrompu : %s illisible, le relais ne demarre pas (restaurer l'etat, ne pas repartir de zero)"
              % self.fichier_etat, file=sys.stderr)
        raise EtatCorrompu("fichier d'etat du relais illisible") from e

    def _ecrire(self):
        if self.fichier_etat:
            tmp = str(self.fichier_etat) + ".tmp"
            with open(tmp, "w") as f:
                f.write(json.dumps({"seq": self._seq, "attente": self._en_attente, "ecartes": self.ecartes, "cle": self._cle}))
                f.flush()
                os.fsync(f.fileno())
            os.replace(tmp, self.fichier_etat)

    def _espace(self, rec):
        brut = (rec.get("meta") or {}).get("espace")
        ws = self.carte.get(brut, brut) if isinstance(brut, str) else None
        return ws.lower() if isinstance(ws, str) and UUID.match(ws.lower()) else None

    def rejouer_ecartes(self):
        """Rejoue les enregistrements gardes de cote dont l'espace est desormais connu. Rend le nombre rejoue."""
        n = 0
        for rec in list(self.ecartes):
            if self._espace(rec) is None:
                continue
            self.ecartes.remove(rec)
            self._ecrire()
            self.traiter(rec)
            n += 1
        return n

    def traiter(self, rec):
        if not isinstance(rec.get("id"), str) or not 0 < len(rec["id"]) <= 128:
            raise ValueError("enregistrement sans identifiant")  # sinon tous se dedoublonneraient sous « None »
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
        if ws is None:  # jamais attribue a un autre espace, jamais jete : gardee de cote (persiste), rejouable (K7-D8)
            if len(self.ecartes) >= self.ECARTES_MAX:
                raise IOError("trop d'enregistrements sans espace : Kumo reessaie")
            self.sans_espace += 1
            self.journal.append(("sans_espace", rec["id"]))
            self.ecartes.append(rec)
            self._ecrire()
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
            "destinataire_empreinte": hmac.new(bytes.fromhex(self._cle), str(rec.get("recipient", "")).lower().encode(), hashlib.sha256).hexdigest(),
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
