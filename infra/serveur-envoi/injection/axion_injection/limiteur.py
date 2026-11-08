"""Compteur d'échecs d'authentification par IP source (pas par session)."""
import time


class LimiteurEchecs:
    def __init__(self, max_echecs: int = 10, fenetre: float = 900.0, horloge=time.monotonic):
        self.max, self.fenetre, self.horloge = max_echecs, fenetre, horloge
        self._echecs: dict[str, list[float]] = {}

    def _purger(self, ip):
        lim = self.horloge() - self.fenetre
        v = [t for t in self._echecs.get(ip, []) if t > lim]
        if v:
            self._echecs[ip] = v
        else:
            self._echecs.pop(ip, None)
        return len(v)

    def echec(self, ip: str) -> float:
        """Enregistre un échec ; retourne le délai (s) à imposer avant la réponse."""
        self._echecs.setdefault(ip, []).append(self.horloge())
        return min(0.05 * 2 ** (self._purger(ip) - 1), 5.0)

    def bloque(self, ip: str) -> bool:
        return self._purger(ip) >= self.max
