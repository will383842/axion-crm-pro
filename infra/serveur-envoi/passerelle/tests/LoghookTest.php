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
    }

    public function test_s15_trou_dans_la_source_alerte_sans_casser_la_sequence(): void
    {
        $i = $this->ingestion();
        $i->ingerer(self::WS_A, $this->ev(1));
        $i->ingerer(self::WS_A, $this->ev(4));
        $this->assertContains('loghook_trou_source', $this->alertes);
        $this->assertSame([], $this->stockage->trous(self::WS_A));
        $this->assertSame(2, $this->stockage->dernierSequence(self::WS_A));
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

    // --- point d'entrée local ---

    private function entree(string $ip, string $corps): array
    {
        return (new \Passerelle\Evenements\PointEntree($this->ingestion()))->traiter($ip, $corps);
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
        $this->assertSame(400, $this->entree('127.0.0.1', json_encode(['workspace_id' => self::WS_A] + $this->ev(3, 'inconnu')))[0]);
        $this->assertSame(1, $this->stockage->dernierSequence(self::WS_A));
    }
}
