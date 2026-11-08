"""K6a suite 4 (audit) : I-2 socle fermé = exception explicite, M-1 confirmation bornée à l'espace.
Écrits AVANT le code, vus ROUGES."""
from axion_socle.socle import ErreurSocleFerme
from tests.test_socle import WS, WS2, Base, jt


class TestSocleFerme(Base):  # I-2 : « False » se confondait avec « jeton inconnu »
    def test_confirmer_ferme_leve_une_exception_explicite(self):
        r = self.envoi(jt("a"))
        self.socle.ferme = True
        with self.assertRaises(ErreurSocleFerme):
            self.socle.confirmer(r.id)

    def test_trancher_ferme_leve_une_exception_explicite(self):
        r = self.envoi(jt("a"))
        self.socle.ferme = True
        for deja in (True, False):
            with self.assertRaises(ErreurSocleFerme):
                self.socle.trancher(r.id, deja_depose=deja)

    def test_ouvert_inconnu_reste_false(self):  # la distinction « fermé » / « inconnu » est conservée
        self.assertFalse(self.socle.confirmer("inconnu"))
        self.assertFalse(self.socle.trancher("inconnu", deja_depose=False))


class TestConfirmerParEspace(Base):  # M-1 : un identifiant d'un autre espace ne se confirme pas
    def test_confirmer_filtre_sur_l_espace(self):
        r = self.envoi(jt("a"), ws=WS)
        self.assertFalse(self.socle.confirmer(r.id, espace=WS2))  # mauvais espace : refusé, état intact
        self.assertEqual(self.envoi(jt("a"), ws=WS).rejeu, "")  # toujours « reserve » (pas de rejeu confirmé)
        self.assertTrue(self.socle.confirmer(r.id, espace=WS))
        self.assertEqual(self.envoi(jt("a"), ws=WS).rejeu, "confirme")

    def test_trancher_confirme_filtre_sur_l_espace(self):
        r = self.envoi(jt("a"), ws=WS)
        self.assertFalse(self.socle.trancher(r.id, deja_depose=True, espace=WS2))
        self.assertTrue(self.socle.trancher(r.id, deja_depose=True, espace=WS))

    def test_trancher_redepot_filtre_sur_l_espace(self):
        r = self.envoi(jt("a"), ws=WS)
        self.avance(121)
        self.assertEqual(self.sante(), 200)
        self.assertFalse(self.socle.trancher(r.id, deja_depose=False, espace=WS2))
        self.assertIsNone(self.envoi(jt("a"), ws=WS).deposer)  # aucun redépôt autorisé
        self.assertTrue(self.socle.trancher(r.id, deja_depose=False, espace=WS))

    def test_sans_espace_comportement_inchange(self):  # appelants existants
        r = self.envoi(jt("a"))
        self.assertTrue(self.socle.confirmer(r.id))
