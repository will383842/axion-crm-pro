"""K6a suite 2 : rejeu ambigu (B1), plafond du lendemain (I1), reprise après pas NTP (I2), gardes (I3)."""
from axion_socle.socle import Reponse
from tests.test_socle import OK, WS, Base, jt


class TestRejeuAmbigu(Base):  # B1 : « déposer : oui / non / à trancher »
    def ancien(self, nom="a"):
        r = self.envoi(jt(nom))
        self.avance(121)
        self.assertEqual(self.sante(), 200)
        return r

    def test_reponse_a_un_champ_deposer(self):
        self.assertIn("deposer", Reponse.__dataclass_fields__)

    def test_neuf_depose_confirme_ne_depose_pas(self):
        r1 = self.envoi(jt("a"))
        self.assertEqual((r1.code, r1.deposer, r1.rejeu), (250, True, ""))
        self.socle.confirmer(r1.id)
        r2 = self.envoi(jt("a"))
        self.assertEqual((r2.code, r2.deposer, r2.rejeu, r2.rejoue), (250, False, "confirme", True))

    def test_reserve_ancien_est_ambigu_jamais_un_depot_silencieux(self):
        r1 = self.ancien()
        r2 = self.envoi(jt("a"))
        self.assertIsNone(r2.deposer)  # ni oui ni non : à trancher côté appelant
        self.assertEqual((r2.rejeu, r2.id, r2.rejoue), ("reserve-ancien", r1.id, False))
        self.assertNotEqual(r2.code, 250)  # un appelant naïf ne peut pas déposer
        self.assertEqual(self.envoi(jt("a")).deposer, None)  # et ça ne se débloque pas tout seul
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_trancher_deja_depose_confirme(self):
        r1 = self.ancien()
        self.assertTrue(self.socle.trancher(r1.id, deja_depose=True))
        r2 = self.envoi(jt("a"))
        self.assertEqual((r2.code, r2.deposer, r2.rejeu), (250, False, "confirme"))

    def test_trancher_pas_depose_autorise_un_seul_redepot(self):
        r1 = self.ancien()
        self.assertTrue(self.socle.trancher(r1.id, deja_depose=False))
        r2 = self.envoi(jt("a"))
        self.assertEqual((r2.code, r2.id, r2.deposer), (250, r1.id, True))
        self.avance(121)
        self.assertEqual(self.sante(), 200)
        self.assertIsNone(self.envoi(jt("a")).deposer)  # l'autorisation est consommée
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_trancher_inconnu_ou_ferme(self):
        self.assertFalse(self.socle.trancher("inconnu", deja_depose=False))

    def test_pause_prime_sur_l_autorisation_de_redepot(self):
        r1 = self.ancien()
        self.socle.trancher(r1.id, deja_depose=False)
        self.avance(1000)  # homme mort
        r = self.envoi(jt("a"))
        self.assertEqual((r.code, r.deposer), (451, False))


class TestPlafondLendemain(Base):  # I1
    def test_redepot_d_un_jeton_d_hier_consomme_une_place_d_aujourd_hui(self):
        s = self.socle = self.ouvrir(plafond=2)
        r1 = self.envoi(jt("hier"))  # jour J
        self.assertEqual(r1.code, 250)
        self.vivre(86400)  # jour J+1
        self.assertEqual(self.envoi(jt("c")).code, 250)
        self.assertEqual(self.envoi(jt("d")).code, 250)  # J+1 plein (2/2)
        self.assertTrue(s.trancher(r1.id, deja_depose=False))
        r = self.envoi(jt("hier"))
        self.assertEqual(r.code, 451)  # pas un 3e dépôt le même jour
        self.assertIn("plafond", r.texte)
        self.assertFalse(r.deposer)

    def test_redepot_du_lendemain_compte_sur_le_nouveau_jour(self):
        s = self.socle = self.ouvrir(plafond=2)
        r1 = self.envoi(jt("hier"))
        j = s.jour_courant()
        self.vivre(86400)
        self.assertTrue(s.trancher(r1.id, deja_depose=False))
        r = self.envoi(jt("hier"))
        self.assertEqual((r.code, r.deposer), (250, True))
        self.assertEqual(s.compteur_du_jour(), 1)
        self.assertEqual(s.compteur_du_jour(jour=j), 1)  # la place d'hier reste comptée


class TestGardesSansTest(Base):  # I3
    def test_jour_courant_ne_recule_jamais(self):
        self.envoi(jt("a"))
        self.assertEqual(self.socle.compteur_du_jour(), 1)
        self.saut(-2 * 86400)  # murale reculée : le plafond du jour reste celui du dernier jour vu
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_reserver_ne_remet_pas_le_plafond_a_zero_en_reculant(self):
        s = self.socle = self.ouvrir(plafond=1)
        self.assertEqual(self.envoi(jt("a")).code, 250)
        hier = self.h.t - 2 * 86400
        motif = s.stockage.ecrire(lambda c: s._reserver(c, "h" * 64, "e" * 64, "i" * 24, WS, hier, 5.0))
        self.assertEqual(motif, "global")

    def test_reprise_refusee_si_murale_avant_la_derniere_sante_apres_redemarrage(self):
        self.avance(100)
        self.assertEqual(self.sante(), 200)
        s2 = self.ouvrir()  # aucune référence en mémoire : seule la garde « ls » protège
        self.saut(-300)
        self.assertEqual(self.reprise(socle=s2), 409)

    def test_quinze_minutes_sans_sante_apres_redemarrage_ferment(self):
        s2 = self.ouvrir()  # ni référence ni monotone de santé : seule la garde murale « 15 min » protège
        self.avance(1000)
        self.assertEqual(self.envoi(jt("a"), socle=s2).code, 451)
        self.assertTrue(any(e[0] == "homme-mort" and e[1] == "15 min sans sante" for e in s2.journal()))


class TestRepriseApresPasNtp(Base):  # I2
    def test_pas_ntp_de_2_minutes_puis_reprise_200(self):
        self.avance(60)
        self.assertEqual(self.sante(), 200)
        self.saut(120)  # pas NTP > 60 s, même jour
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertTrue(self.en_pause())
        self.assertEqual(self.reprise(), 200)  # avant : 409 à vie car _ref n'était jamais recalé
        self.assertEqual(self.envoi(jt("a")).code, 250)

    def test_pas_ntp_en_arriere_dans_le_meme_jour_puis_reprise(self):
        self.avance(60)
        self.assertEqual(self.sante(), 200)
        self.avance(300)
        self.saut(-200)  # reste après la dernière santé, mais écart > 60 s avec la monotone
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertEqual(self.reprise(), 200)

    def test_changement_de_jour_reste_refuse_et_le_jour_ne_recule_pas(self):
        self.avance(60)
        self.assertEqual(self.sante(), 200)
        self.saut(30 * 86400)
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertEqual(self.reprise(), 409)  # un saut de jours n'est pas recalé


class TestDocsEtLua(Base):  # M1, M3, M4 et contrat B1 figés dans les documents
    def lire(self, *c):
        import os
        with open(os.path.join(os.path.dirname(__file__), os.pardir, *c), encoding="utf-8") as f:
            return f.read()

    def test_m1_le_relais_lua_transmet_le_contenu_au_socle_local(self):
        lua = self.lire("kumomta", "socle_adaptateur.lua.modele")
        self.assertIn("get_data", lua)  # le socle calcule l'empreinte (destinataire + contenu), il ne stocke rien
        self.assertNotIn("JAMAIS le contenu", lua)

    def test_b1_le_relais_lua_traite_le_cas_ambigu(self):
        lua = self.lire("kumomta", "socle_adaptateur.lua.modele")
        self.assertRegex(lua, r"reponse\.deposer")
        self.assertIn("reserve-ancien", lua)

    def test_m3_readme_statut_500_precis(self):
        r = self.lire("README.md")
        self.assertIn("nonce déjà consommé", r)
        self.assertIn("nouveau nonce", r)

    def test_m4_dettes_crm_nommees_avec_plan(self):
        r = self.lire("README.md")
        for mot in ("réponse signée", "commande_id", "retrait", "pause", "Plan"):
            self.assertIn(mot, r)
        self.assertIn("deja_depose", r)  # B1 documenté
