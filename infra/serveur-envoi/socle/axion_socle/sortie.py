"""Interface vers la file KumoMTA. Aucun envoi réel dans ce lot (K2 n'est pas encore fusionnée)."""
from abc import ABC, abstractmethod


class ErreurSortie(Exception):
    """La file est indisponible : le socle répond 451, jamais « accepté »."""


class Sortie(ABC):
    @abstractmethod
    def deposer(self, id_: str, destinataire: str, contenu: bytes) -> None:
        """Dépôt IDEMPOTENT par `id_` : déposer deux fois le même id ne crée qu'un message."""


class FausseSortie(Sortie):
    """Double de test. Les crochets simulent un arrêt brutal ou une panne."""

    def __init__(self):
        self.depots: dict[str, tuple[str, bytes]] = {}
        self.panne = False
        self.avant_depot = None
        self.apres_depot = None

    def deposer(self, id_, destinataire, contenu):
        if self.panne:
            raise ErreurSortie("file indisponible")
        if self.avant_depot:
            self.avant_depot(id_)
        self.depots.setdefault(id_, (destinataire, contenu))
        if self.apres_depot:
            self.apres_depot(id_)
