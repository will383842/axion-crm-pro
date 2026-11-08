<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\Evenements\Ingestion;
use Passerelle\Evenements\JetonsConfirmation;
use Passerelle\Evenements\JetonsNonDisponibles;
use PDO;

/** K7 (ROUGE d'abord) : ingestion du log hook — S06, S07, S08, S15, observation, jetons K6. */
final class LoghookTest extends PasserelleTestCase
{
    private array $alertes = [];

    private function ingestion(?JetonsConfirmation $j = null): Ingestion
    {
        return new Ingestion($this->stockage, $j ?? new JetonsNonDisponibles, function (string $c, array $x): void { $this->alertes[] = $c; });
    }

    private function ev(int $seq, string $type = 'rebond_dur', ?string $id = null, ?string $jeton = null): array
    {
        return ['source' => 'kumo-test', 'src_seq' => $seq, 'id_kumo' => $id ?? 'k'.$seq, 'type' => $type, 'id_injection' => 'inj-'.$seq, 'jeton' => $jeton];
    }

    private function types(): array
    {
        return array_column($this->stockage->evenementsApres(self::WS_A, 0, 500), 'type');
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite:'.$this->fichier);
    }

    public function test_s06_s07_s08_les_quatre_classes_arrivent_numerotees_sans_trou(): void
    {
        $i = $this->ingestion();
        foreach (['rebond_dur', 'rebond_mou', 'report', 'livre'] as $n => $t) {
            $r = $i->ingerer(self::WS_A, $this->ev($n + 1, $t));
            $this->assertSame($n + 1, $r['seq']);
        }
        $this->assertSame(['rebond_dur', 'rebond_mou', 'report', 'livre'], $this->types());
        $this->assertSame([], $this->stockage->trous(self::WS_A));
    }

    public function test_type_hors_liste_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ingestion()->ingerer(self::WS_A, $this->ev(1, 'supprimer_tout'));
    }

    public function test_s15_doublon_ignore_et_journalise(): void
    {
        $i = $this->ingestion();
        $this->assertSame('ingere', $i->ingerer(self::WS_A, $this->ev(1))['statut']);
        $this->assertSame('doublon', $i->ingerer(self::WS_A, $this->ev(1))['statut']);
        $this->assertCount(1, $this->types());
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM loghook_journal WHERE genre='doublon'")->fetchColumn());
    }

    public function test_s15_desordre_garde_tout_et_numerote_a_l_arrivee(): void
    {
        $i = $this->ingestion();
        foreach ([1, 3, 2] as $s) {
            $i->ingerer(self::WS_A, $this->ev($s));
        }
        $this->assertSame([1, 2, 3], array_column($this->stockage->evenementsApres(self::WS_A, 0, 9), 'sequence'));
        $this->assertSame([], $this->stockage->trous(self::WS_A));
        $this->maintenant += Ingestion::DELAI_TROU + 1;
        $i->ingerer(self::WS_A, $this->ev(4));
        $this->assertSame([], $this->alertes, 'I2 : un simple désordre ne lève AUCUNE alerte');
    }

    public function test_i2_rejeu_apres_200_perdu_avec_nouveau_numero_ne_lve_aucune_alerte(): void
    {
        $i = $this->ingestion();
        $i->ingerer(self::WS_A, $this->ev(1, 'rebond_dur', 'k1'));
        $this->assertSame('doublon', $i->ingerer(self::WS_A, $this->ev(2, 'rebond_dur', 'k1'))['statut']); // relais redémarré : nouveau numéro
        $i->ingerer(self::WS_A, $this->ev(3));
        $this->maintenant += Ingestion::DELAI_TROU + 1;
        $i->ingerer(self::WS_A, $this->ev(4));
        $this->assertSame([], $this->alertes, 'I2 : le numéro d\'un doublon est bien vu, pas un trou');
    }

    public function test_i2_trou_recent_pas_d_alerte_tant_que_le_delai_n_est_pas_ecoule(): void
    {
        $i = $this->ingestion();
        $i->ingerer(self::WS_A, $this->ev(1));
        $i->ingerer(self::WS_A, $this->ev(3));
        $this->assertSame([], $this->alertes);
        $i->ingerer(self::WS_A, $this->ev(2)); // le numéro manquant arrive : jamais d'alerte
        $this->maintenant += Ingestion::DELAI_TROU + 1;
        $i->ingerer(self::WS_A, $this->ev(4));
        $this->assertSame([], $this->alertes);
    }

    public function test_s15_trou_dans_la_source_alerte_sans_casser_la_sequence(): void
    {
        $i = $this->ingestion();
        $i->ingerer(self::WS_A, $this->ev(1));
        $i->ingerer(self::WS_A, $this->ev(4));
        $this->maintenant += Ingestion::DELAI_TROU + 1; // un vrai trou : le numéro 2-3 n'est jamais venu
        $i->ingerer(self::WS_A, $this->ev(5));
        $this->assertSame(['loghook_trou_source'], $this->alertes);
        $i->ingerer(self::WS_A, $this->ev(6));
        $this->assertSame(['loghook_trou_source'], $this->alertes, 'une seule alerte par trou');
        $this->assertSame([], $this->stockage->trous(self::WS_A));
        $this->assertSame(4, $this->stockage->dernierSequence(self::WS_A));
    }

    public function test_sequence_sans_trou_ni_doublon_apres_redemarrage_et_epoque_dans_event_id(): void
    {
        $this->ingestion()->ingerer(self::WS_A, $this->ev(1));
        $this->ingestion()->ingerer(self::WS_A, $this->ev(2));
        $this->demarrer(); // redémarrage sur le même fichier
        $i = $this->ingestion();
        $this->assertSame('doublon', $i->ingerer(self::WS_A, $this->ev(2))['statut']);
        $i->ingerer(self::WS_A, $this->ev(3));
        $rep = $this->envoyer($this->signer('GET', '/v1/evenements?curseur=&limite=10', ''));
        $j = json_decode($rep->corps, true);
        $this->assertSame([1, 2, 3], array_column($j['evenements'], 'seq'));
        $this->assertStringContainsString(':'.$this->stockage->epoque().':', $j['evenements'][0]['event_id']);
    }

    public function test_observation_les_50_premiers_rebonds_durs_sans_action_automatique(): void
    {
        $i = $this->ingestion();
        for ($n = 1; $n <= 51; $n++) {
            $i->ingerer(self::WS_A, $this->ev($n));
        }
        $ev = $this->stockage->evenementsApres(self::WS_A, 0, 100);
        $this->assertTrue($ev[49]['donnees']['observation']);
        $this->assertFalse($ev[50]['donnees']['observation']);
        foreach ($ev as $e) {
            $this->assertSame('aucune', $e['donnees']['action_auto']);
        }
        $this->assertSame(51, (int) $this->pdo()->query("SELECT COUNT(*) FROM loghook_journal WHERE genre='rebond_dur'")->fetchColumn());
    }

    public function test_observation_le_compteur_survit_au_redemarrage_et_les_mous_ne_comptent_pas(): void
    {
        $i = $this->ingestion();
        for ($n = 1; $n <= 30; $n++) {
            $i->ingerer(self::WS_A, $this->ev($n, 'rebond_mou'));
        }
        for ($n = 31; $n <= 40; $n++) {
            $i->ingerer(self::WS_A, $this->ev($n));
        }
        $this->demarrer();
        $this->ingestion()->ingerer(self::WS_A, $this->ev(41));
        $e = array_values(array_filter($this->stockage->evenementsApres(self::WS_A, 0, 100), fn ($x) => $x['type'] === 'rebond_dur'));
        $this->assertCount(11, $e);
        $this->assertTrue($e[10]['donnees']['observation']);
    }

    public function test_les_donnees_stockees_ne_contiennent_aucune_adresse(): void
    {
        $this->ingestion()->ingerer(self::WS_A, $this->ev(1) + ['destinataire' => 'a@dest.example.test']);
        $brut = (string) $this->pdo()->query('SELECT group_concat(donnees) FROM evenements')->fetchColumn();
        $this->assertStringNotContainsString('@', $brut);
    }

    // --- confirmation des jetons du socle K6 ---

    public function test_reception_confirme_le_jeton_une_fois(): void
    {
        $j = new class implements JetonsConfirmation {
            public array $appels = [];
            public function confirmer(string $ws, string $jeton, string $idMessage): bool { $this->appels[] = [$ws, $jeton, $idMessage]; return true; }
        };
        $i = $this->ingestion($j);
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('a', 32)));
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('a', 32))); // rejeu : doublon
        $this->assertSame([[self::WS_A, str_repeat('a', 32), 'm1']], $j->appels);
    }

    public function test_seule_la_reception_confirme(): void
    {
        $j = new class implements JetonsConfirmation {
            public int $n = 0;
            public function confirmer(string $ws, string $jeton, string $idMessage): bool { $this->n++; return true; }
        };
        $this->ingestion($j)->ingerer(self::WS_A, $this->ev(1, 'livre', null, str_repeat('b', 32)));
        $this->assertSame(0, $j->n);
    }

    public function test_k6a_absent_dette_nommee_le_jeton_reste_en_attente_dans_le_journal(): void
    {
        $this->ingestion()->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('c', 32)));
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM loghook_journal WHERE genre='jeton_en_attente'")->fetchColumn());
        $this->assertSame(['reception'], $this->types());
    }

    public function test_echec_de_la_confirmation_ne_bloque_pas_la_sequence(): void
    {
        $j = new class implements JetonsConfirmation {
            public function confirmer(string $ws, string $jeton, string $idMessage): bool { throw new \RuntimeException('K6 indisponible'); }
        };
        $i = $this->ingestion($j);
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('d', 32)));
        $i->ingerer(self::WS_A, $this->ev(2, 'livre'));
        $this->assertSame(['reception', 'livre'], $this->types());
        $this->assertContains('loghook_jeton_echec', $this->alertes);
    }

    public function test_journal_en_ajout_seul(): void
    {
        $this->ingestion()->ingerer(self::WS_A, $this->ev(1));
        $this->expectException(\PDOException::class);
        $p = $this->pdo();
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->exec('DELETE FROM loghook_journal');
    }

    public function test_deux_espaces_ont_des_sequences_separees(): void
    {
        $i = $this->ingestion();
        $i->ingerer(self::WS_A, $this->ev(1));
        $i->ingerer(self::WS_B, $this->ev(1)); // même clé de dédoublonnage, autre espace : pas un doublon
        $this->assertSame(1, $this->stockage->dernierSequence(self::WS_B));
    }

    public function test_observation_compteur_separe_par_espace(): void
    {
        $i = $this->ingestion();
        $i->ingerer(self::WS_B, $this->ev(1));
        for ($n = 1; $n <= 50; $n++) {
            $i->ingerer(self::WS_A, $this->ev($n));
        }
        $i->ingerer(self::WS_B, $this->ev(2)); // 2e rebond dur de l'espace B : toujours en observation
        $b = $this->stockage->evenementsApres(self::WS_B, 0, 9);
        $this->assertTrue($b[1]['donnees']['observation']);
        $i->ingerer(self::WS_A, $this->ev(51));
        $this->assertFalse($this->stockage->evenementsApres(self::WS_A, 50, 9)[0]['donnees']['observation']);
    }

    public function test_i7_types_inconnu_et_alerte_config_acceptes_et_alertes(): void
    {
        $i = $this->ingestion();
        $i->ingerer(self::WS_A, $this->ev(1, 'inconnu'));
        $i->ingerer(self::WS_A, $this->ev(2, 'alerte_config'));
        $this->assertSame(['inconnu', 'alerte_config'], $this->types());
        $this->assertSame(['loghook_alerte_config'], $this->alertes);
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM loghook_journal WHERE genre='inconnu'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COALESCE(SUM(rebonds_durs),0) FROM loghook_compteurs')->fetchColumn(), 'un inconnu ne compte pas comme rebond dur');
    }

    public function test_jeton_mal_forme_alerte(): void
    {
        $this->ingestion()->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', 'trop court'));
        $this->assertContains('loghook_jeton_malforme', $this->alertes);
        $this->assertSame(['reception'], $this->types());
    }

    // --- I4 : jeton reconfirmable ---

    private function jetonsBascule(bool &$panne): JetonsConfirmation
    {
        return new class($panne) implements JetonsConfirmation {
            public int $appels = 0;
            public function __construct(private bool &$panne) {}
            public function confirmer(string $ws, string $jeton, string $idMessage): bool { $this->appels++; if ($this->panne) { throw new \RuntimeException('K6 indisponible'); } return true; }
        };
    }

    public function test_i4_jeton_dont_la_confirmation_a_echoue_est_reconfirme_au_rejeu_du_doublon(): void
    {
        $panne = true;
        $j = $this->jetonsBascule($panne);
        $i = $this->ingestion($j);
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('e', 32)));
        $this->assertContains('loghook_jeton_echec', $this->alertes);
        $panne = false;
        $this->assertSame('doublon', $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('e', 32)))['statut']);
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM loghook_journal WHERE genre='jeton_confirme'")->fetchColumn());
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('e', 32)));
        $this->assertSame(2, $j->appels, 'confirmé : plus de nouvel appel');
    }

    public function test_i4_reconfirmer_rejoue_tant_que_non_confirme(): void
    {
        $panne = true;
        $j = $this->jetonsBascule($panne);
        $i = $this->ingestion($j);
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('f', 32)));
        $this->assertSame(0, $i->reconfirmer(self::WS_A)); // toujours en panne
        $panne = false;
        $this->assertSame(1, $i->reconfirmer(self::WS_A));
        $this->assertSame(0, $i->reconfirmer(self::WS_A));
    }

    public function test_i4_k6a_absent_le_jeton_reste_rejouable(): void
    {
        $i = $this->ingestion();
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('c', 32)));
        $this->assertSame(0, $i->reconfirmer(self::WS_A));
        $this->assertSame(1, count($this->stockage->jetonsEnAttente(self::WS_A, 10)));
    }

    public function test_i4_pas_d_alerte_fausse_si_journaliser_echoue_apres_confirmation(): void
    {
        $this->pdo()->exec("CREATE TRIGGER t_refus_confirme BEFORE INSERT ON loghook_journal WHEN NEW.genre='jeton_confirme' BEGIN SELECT RAISE(ABORT,'journal indisponible'); END");
        $panne = false;
        $j = $this->jetonsBascule($panne);
        $i = $this->ingestion($j);
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('g', 32)));
        $this->assertNotContains('loghook_jeton_echec', $this->alertes, 'K6 a bien confirmé : pas d\'alerte « échec de confirmation »');
        $this->assertSame(['reception'], $this->types());
    }

    public function test_i6_la_reponse_pour_k1_porte_le_drapeau_observation_sans_adresse(): void
    {
        $i = $this->ingestion();
        for ($n = 1; $n <= 51; $n++) {
            $i->ingerer(self::WS_A, $this->ev($n));
        }
        $i->ingerer(self::WS_A, $this->ev(52, 'livre'));
        $rep = $this->envoyer($this->signer('GET', '/v1/evenements?curseur=&limite=100', ''));
        $ev = json_decode($rep->corps, true)['evenements'];
        $this->assertTrue($ev[0]['observation']);
        $this->assertTrue($ev[49]['observation']);
        $this->assertArrayNotHasKey('observation', $ev[50]);
        $this->assertArrayNotHasKey('observation', $ev[51], 'un livre n\'est jamais en observation');
        $this->assertStringNotContainsString('@', $rep->corps);
    }

    // --- point d'entrée local ---

    private const SECRET = 'secret-local-de-test-0123456789abcdef';

    private function entree(string $ip, string $corps, string $secret = self::SECRET): array
    {
        return (new \Passerelle\Evenements\PointEntree($this->ingestion(), self::SECRET, ['kumo-test']))->traiter($ip, $corps, $secret);
    }

    public function test_point_d_entree_refuse_toute_adresse_non_locale(): void
    {
        $corps = json_encode(['workspace_id' => self::WS_A] + $this->ev(1));
        $this->assertSame(403, $this->entree('203.0.113.9', $corps)[0]);
        $this->assertSame(0, $this->stockage->dernierSequence(self::WS_A));
    }

    public function test_point_d_entree_local_accepte_et_rejette_le_mal_forme(): void
    {
        $corps = json_encode(['workspace_id' => self::WS_A] + $this->ev(1));
        $this->assertSame(200, $this->entree('127.0.0.1', $corps)[0]);
        $this->assertSame(400, $this->entree('127.0.0.1', '{pas du json')[0]);
        $this->assertSame(400, $this->entree('127.0.0.1', json_encode(['workspace_id' => 'x'] + $this->ev(2)))[0]);
        $this->assertSame(400, $this->entree('127.0.0.1', json_encode(['workspace_id' => self::WS_A] + $this->ev(3, 'inconnu_type')))[0]);
        $this->assertSame(1, $this->stockage->dernierSequence(self::WS_A));
    }

    public function test_i5_127_0_0_1_sans_secret_ou_mauvais_secret_est_refuse(): void
    {
        $corps = json_encode(['workspace_id' => self::WS_A] + $this->ev(1));
        $this->assertSame(403, $this->entree('127.0.0.1', $corps, '')[0]);
        $this->assertSame(403, $this->entree('127.0.0.1', $corps, 'autre-secret-de-meme-longueur-0123456')[0]);
        $this->assertSame(0, $this->stockage->dernierSequence(self::WS_A));
    }

    public function test_i5_secret_serveur_vide_refuse_tout(): void
    {
        $corps = json_encode(['workspace_id' => self::WS_A] + $this->ev(1));
        $p = new \Passerelle\Evenements\PointEntree($this->ingestion(), '', ['kumo-test']);
        $this->assertSame(403, $p->traiter('127.0.0.1', $corps, '')[0]);
    }

    public function test_i5_source_inconnue_refusee(): void
    {
        $e = ['workspace_id' => self::WS_A] + $this->ev(1);
        $e['source'] = 'pirate';
        $this->assertSame(403, $this->entree('127.0.0.1', json_encode($e))[0]);
        $this->assertSame(0, $this->stockage->dernierSequence(self::WS_A));
    }

    public function test_i5_le_secret_n_est_pas_dans_le_depot(): void
    {
        $this->assertStringNotContainsString(self::SECRET, (string) file_get_contents(__DIR__.'/../src/Evenements/PointEntree.php'));
        $this->assertStringContainsString('hash_equals', (string) file_get_contents(__DIR__.'/../src/Evenements/PointEntree.php'));
    }

    // --- E5 : un jeton inconnu de K6 ne bloque pas la tête de liste ; E6 : index du journal ---

    /** K6 qui répond FALSE (jeton inconnu) pour les jetons « inconnus », TRUE pour les autres. */
    private function jetonsSelectif(array &$inconnus): JetonsConfirmation
    {
        return new class($inconnus) implements JetonsConfirmation {
            public int $appels = 0;
            public function __construct(private array &$inconnus) {}
            public function confirmer(string $ws, string $jeton, string $idMessage): bool { $this->appels++; return ! in_array($jeton, $this->inconnus, true); }
        };
    }

    public function test_e5_vingt_jetons_inconnus_ne_bloquent_pas_un_jeton_valide_arrive_apres(): void
    {
        $inconnus = [];
        for ($n = 1; $n <= 25; $n++) {
            $inconnus[] = str_pad((string) $n, 32, 'u');
        }
        $j = $this->jetonsSelectif($inconnus);
        $i = $this->ingestion($j);
        foreach ($inconnus as $n => $jeton) {
            $i->ingerer(self::WS_A, $this->ev($n + 1, 'reception', 'u'.$n, $jeton));
        }
        $i->ingerer(self::WS_A, $this->ev(100, 'reception', 'valide', str_repeat('v', 32)));
        for ($passage = 0; $passage < 40; $passage++) {
            $this->maintenant += Ingestion::DELAI_REJEU_JETON + 1;
            $i->reconfirmer(self::WS_A);
        }
        $this->assertTrue($this->stockage->jetonDejaConfirme(self::WS_A, 'valide'), 'le jeton valide doit finir par passer');
        $this->assertSame([], $this->stockage->jetonsEnAttente(self::WS_A, 100), 'les jetons inconnus sortent de la liste');
        $this->assertSame(25, (int) $this->pdo()->query("SELECT COUNT(DISTINCT id_kumo) FROM loghook_journal WHERE genre='jeton_abandonne'")->fetchColumn());
        $this->assertContains('loghook_jeton_abandonne', $this->alertes);
    }

    public function test_e5_un_jeton_abandonne_n_est_plus_rejoue(): void
    {
        $inconnus = [str_repeat('x', 32)];
        $j = $this->jetonsSelectif($inconnus);
        $i = $this->ingestion($j);
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('x', 32)));
        for ($passage = 0; $passage < Ingestion::TENTATIVES_JETON_MAX + 3; $passage++) {
            $this->maintenant += Ingestion::DELAI_REJEU_JETON + 1;
            $i->reconfirmer(self::WS_A);
        }
        $appels = $j->appels;
        $this->maintenant += 10 * Ingestion::DELAI_REJEU_JETON;
        $i->reconfirmer(self::WS_A);
        $this->assertSame($appels, $j->appels);
        $this->assertSame(Ingestion::TENTATIVES_JETON_MAX, $appels, 'chaque réponse FALSE de K6 compte une tentative, puis le jeton est abandonné');
    }

    public function test_e5_les_tentatives_sont_espacees_dans_le_temps(): void
    {
        $inconnus = [str_repeat('y', 32)];
        $j = $this->jetonsSelectif($inconnus);
        $i = $this->ingestion($j);
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('y', 32)));
        $avant = $j->appels;
        for ($passage = 0; $passage < 30; $passage++) { // 30 passages au même instant : une seule tentative compte
            $i->reconfirmer(self::WS_A);
        }
        $this->assertSame($avant, $j->appels, 'au même instant : aucune nouvelle tentative');
        $this->assertSame([], array_filter($this->alertes, fn ($a) => $a === 'loghook_jeton_abandonne'));
    }

    public function test_e5_k6a_absent_ne_consomme_aucune_tentative(): void
    {
        $i = $this->ingestion(); // JetonsNonDisponibles
        $i->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('c', 32)));
        for ($passage = 0; $passage < 40; $passage++) {
            $this->maintenant += Ingestion::DELAI_REJEU_JETON + 1;
            $i->reconfirmer(self::WS_A);
        }
        $this->assertCount(1, $this->stockage->jetonsEnAttente(self::WS_A, 10), 'K6a absent : le jeton reste rejouable, jamais abandonné');
    }

    public function test_e5_les_jetons_d_un_espace_sans_trafic_sont_aussi_rejoues(): void
    {
        $panne = true;
        $j = $this->jetonsBascule($panne);
        $i = $this->ingestion($j);
        $i->ingerer(self::WS_B, $this->ev(1, 'reception', 'b1', str_repeat('b', 32)));
        $panne = false;
        // Trafic uniquement dans l'espace A : le jeton de B doit quand même être confirmé.
        $this->assertSame(1, $i->reconfirmerTous());
        $this->assertTrue($this->stockage->jetonDejaConfirme(self::WS_B, 'b1'));
        $this->assertSame(0, $i->reconfirmerTous());
    }

    public function test_e5_le_point_d_entree_rejoue_les_autres_espaces(): void
    {
        $panne = true;
        $j = $this->jetonsBascule($panne);
        $i = $this->ingestion($j);
        $i->ingerer(self::WS_B, $this->ev(1, 'reception', 'b1', str_repeat('b', 32)));
        $panne = false;
        $corps = json_encode(['workspace_id' => self::WS_A] + $this->ev(1, 'livre'));
        $r = (new \Passerelle\Evenements\PointEntree($i, self::SECRET, ['kumo-test']))->traiter('127.0.0.1', $corps, self::SECRET);
        $this->assertSame(200, $r[0]);
        $this->assertTrue($this->stockage->jetonDejaConfirme(self::WS_B, 'b1'));
    }

    public function test_e6_le_journal_a_un_index_workspace_genre_id_kumo_utilise_par_les_requetes(): void
    {
        $plan = fn (string $sql): string => implode(' ', array_column($this->pdo()->query('EXPLAIN QUERY PLAN '.$sql)->fetchAll(PDO::FETCH_ASSOC), 'detail'));
        $this->assertStringContainsString('loghook_journal_ws_genre', $plan("SELECT 1 FROM loghook_journal WHERE workspace_id='w' AND genre='jeton_confirme' AND id_kumo='k' LIMIT 1"));
        $this->assertStringContainsString('loghook_journal_ws_genre', $plan("SELECT id_kumo FROM loghook_journal WHERE workspace_id='w' AND genre='jeton_en_attente'"));
    }

    public function test_e6_l_index_est_ajoute_a_une_base_existante_sans_toucher_aux_donnees(): void
    {
        $this->ingestion()->ingerer(self::WS_A, $this->ev(1, 'reception', 'm1', str_repeat('c', 32)));
        $this->pdo()->exec('DROP INDEX loghook_journal_ws_genre'); // base d'avant la correction
        $avant = (int) $this->pdo()->query('SELECT COUNT(*) FROM loghook_journal')->fetchColumn();
        new \Passerelle\Stockage($this->fichier, fn (): int => $this->maintenant);
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='loghook_journal_ws_genre'")->fetchColumn());
        $this->assertSame($avant, (int) $this->pdo()->query('SELECT COUNT(*) FROM loghook_journal')->fetchColumn());
    }
}
