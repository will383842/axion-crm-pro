"""K6a suite 3 (audits Opus) : B-1 course de redépôt, BL1 reprise après saut, I-a purge des réservés, gardes, docs."""
import hashlib
import os

from axion_socle.socle import CLE_REDEPOT, _CourseRedepot
from tests.test_socle import WS, Base, jt


def pendant_lecture(socle, cle, action):
    """Exécute `action()` pile quand `socle` lit la clé meta `cle` (hors transaction) : course déterministe."""
    st = socle.stockage
    vrai = st.meta
    fait = []

    def meta(k, defaut=None):
        r = vrai(k, defaut)
        if k == cle and not fait:
            fait.append(1)
            action()
        return r
    st.meta = meta


class TestCourseRedepot(Base):  # B-1
    def pret(self):
        r1 = self.envoi(jt("a"))
        self.avance(121)
        self.assertEqual(self.sante(), 200)
        self.assertTrue(self.socle.trancher(r1.id, deja_depose=False))
        return r1

    def test_deux_instances_une_autorisation_un_seul_depot(self):
        r1 = self.pret()
        s2 = self.ouvrir()
        reponses = []
        # s1 a lu l'autorisation (« 1 ») ; avant qu'elle n'agisse, s2 consomme la même autorisation.
        pendant_lecture(self.socle, CLE_REDEPOT + r1.id, lambda: reponses.append(self.envoi(jt("a"), socle=s2)))
        r_s1 = self.envoi(jt("a"))
        tous = reponses + [r_s1]
        self.assertEqual(sum(1 for r in tous if r.code == 250 and r.deposer is True), 1)
        self.assertNotEqual((r_s1.code, r_s1.deposer), (250, True))
        self.assertEqual(r_s1.code, 451)
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_confirmer_pendant_un_redepot_ne_depose_pas(self):
        r1 = self.pret()
        pendant_lecture(self.socle, CLE_REDEPOT + r1.id, lambda: self.assertTrue(self.socle.confirmer(r1.id)))
        r = self.envoi(jt("a"))
        self.assertFalse(r.code == 250 and r.deposer is True)
        self.assertEqual(r.code, 451)
        self.assertEqual(self.ligne(jt("a"))[0], "confirme")

    def test_course_perdue_ne_consomme_ni_place_ni_reacceptation(self):
        r1 = self.pret()
        s2 = self.ouvrir()
        pendant_lecture(self.socle, CLE_REDEPOT + r1.id, lambda: self.envoi(jt("a"), socle=s2))
        self.envoi(jt("a"))
        c = self.stockage_ouvert().c
        self.assertEqual(c.execute("SELECT reacceptations FROM jetons").fetchone()[0], 1)  # celle de s2 seulement

    def test_reacceptations_max_garde_dans_la_transaction(self):
        r1 = self.pret()
        self.stockage_ouvert().c.execute("UPDATE jetons SET reacceptations=3")
        # garde « reacceptations < 3 » DANS la transaction : appel direct, sans passer par la branche amont
        s = self.socle
        jh = hashlib.sha256(jt("a").encode()).hexdigest()
        with self.assertRaises(_CourseRedepot):
            s.stockage.ecrire(lambda c: s._redeposer(c, jh, r1.id, WS, self.h.t, 9.0))
        self.assertEqual(self.ligne(jt("a"))[0], "reserve")


class TestRepriseSautDansLeJour(Base):  # BL1 / M-5
    def test_saut_en_avant_de_11h_le_meme_jour_reste_en_409(self):
        self.avance(60)
        self.assertEqual(self.sante(), 200)
        self.saut(11 * 3600)  # même jour Paris (10 h -> 21 h), mais ce n'est pas un pas NTP
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertEqual(self.reprise(), 409)
        self.assertEqual(self.envoi(jt("a")).code, 451)

    def test_saut_en_arriere_de_plus_de_5_min_reste_en_409(self):
        self.avance(900)
        self.assertEqual(self.sante(), 200)
        self.avance(600)
        self.saut(-400)  # reste après la dernière santé, écart -400 s
        self.assertEqual(self.reprise(), 409)

    def test_pas_ntp_sous_5_min_reste_accepte(self):
        self.avance(60)
        self.assertEqual(self.sante(), 200)
        self.saut(250)
        self.assertEqual(self.reprise(), 200)

    def test_reprise_refusee_si_le_jour_est_sous_le_dernier_jour_vu(self):  # M-1
        self.avance(60)
        self.assertEqual(self.sante(), 200)
        self.socle.stockage.ecrire(lambda c: self.socle.stockage.poser(c, "dernier_jour", "2026-06-16"))
        self.saut(120)  # pas NTP, même jour que « attendu », mais le jour est avant le dernier jour vu
        self.assertEqual(self.reprise(), 409)


class TestGardeRedepotJour(Base):  # M-2
    def test_redepot_prend_au_moins_le_dernier_jour_vu(self):
        r1 = self.envoi(jt("a"))
        self.avance(121)
        self.assertEqual(self.sante(), 200)
        self.assertTrue(self.socle.trancher(r1.id, deja_depose=False))
        st = self.socle.stockage
        st.ecrire(lambda c: st.poser(c, "dernier_jour", "2026-06-16"))  # horloge murale encore au 15
        r = self.envoi(jt("a"))
        self.assertEqual((r.code, r.deposer), (250, True))
        self.assertEqual(st.compteur("2026-06-16"), 1)
        self.assertEqual(st.c.execute("SELECT jour FROM jetons").fetchone()[0], "2026-06-16")


class TestPurgeNePurgePasLesReserves(Base):  # I-a / M-6
    def test_un_reserve_ambigu_ne_redevient_jamais_deposable_apres_retention(self):
        s = self.socle = self.ouvrir(retention_jetons=300)
        r1 = self.envoi(jt("a"))
        self.vivre(600, socle=s)
        self.envoi(jt("autre"))  # déclenche la purge
        self.assertIsNotNone(self.ligne(jt("a")))
        r = self.envoi(jt("a"))
        self.assertIsNone(r.deposer)
        self.assertEqual((r.code, r.id, r.rejeu), (451, r1.id, "reserve-ancien"))

    def test_un_confirme_ancien_est_purge_avec_sa_cle_redepot(self):
        s = self.socle = self.ouvrir(retention_jetons=300)
        r1 = self.envoi(jt("a"))
        self.avance(121)
        self.assertEqual(self.sante(socle=s), 200)
        self.assertTrue(s.trancher(r1.id, deja_depose=False))
        self.assertTrue(s.confirmer(r1.id))
        self.assertEqual(s.stockage.meta(CLE_REDEPOT + r1.id), "1")
        self.vivre(600, socle=s)
        self.envoi(jt("autre"))
        self.assertIsNone(self.ligne(jt("a")))
        self.assertIsNone(s.stockage.meta(CLE_REDEPOT + r1.id))


class TestDocsSuite3(Base):
    def lire(self, *c):
        with open(os.path.join(os.path.dirname(__file__), os.pardir, *c), encoding="utf-8") as f:
            return f.read()

    def test_adr_point_5_et_dettes_nommees(self):
        t = self.lire("README.md")
        self.assertIn("5. ", t.split("Amendement d'ADR nécessaire")[1].split("## Relais Lua")[0])
        self.assertIn("même jeton rejoué", t)
        self.assertIn("sans en-têtes de trace", t)
        self.assertIn("rotation", t)
        self.assertIn("le reçoit, ne le stocke pas", t)
        self.assertNotIn("ne reçoit ni ne stocke", t)

    def test_message_reacceptation_sans_definitif_avec_451(self):
        self.assertNotIn("refus definitif", self.lire("axion_socle", "socle.py"))
