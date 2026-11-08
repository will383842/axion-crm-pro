<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\FauxKumo;
use Passerelle\Passerelle;
use Passerelle\Reponse;
use Passerelle\Requete;
use Passerelle\Signature;
use Passerelle\Stockage;
use PHPUnit\Framework\TestCase;

abstract class PasserelleTestCase extends TestCase
{
    protected const CLE_A = 'cle-test-espace-a-0123456789abcdef';
    protected const CLE_B = 'cle-test-espace-b-fedcba9876543210';
    protected const WS_A = '11111111-1111-4111-8111-111111111111';
    protected const WS_B = '22222222-2222-4222-8222-222222222222';
    protected const ID_1 = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    protected const ID_2 = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    protected string $fichier;
    protected int $maintenant = 1_800_000_000;
    protected FauxKumo $kumo;
    protected Passerelle $passerelle;
    protected Stockage $stockage;
    protected bool $purgeActive = false;
    private int $n = 0;

    protected function setUp(): void
    {
        $this->fichier = sys_get_temp_dir().'/passerelle-'.bin2hex(random_bytes(6)).'.sqlite';
        $this->kumo = new FauxKumo;
        $this->demarrer();
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($this->fichier.$s);
        }
    }

    /** (Re)démarre la passerelle sur le MÊME fichier SQLite : simule un redémarrage. */
    protected function demarrer(): void
    {
        $this->stockage = new Stockage($this->fichier, fn (): int => $this->maintenant, $this->purgeActive);
        $this->passerelle = new Passerelle(
            $this->stockage,
            $this->kumo,
            [self::WS_A => self::CLE_A, self::WS_B => self::CLE_B],
            fn (): int => $this->maintenant,
            static fn (int $ms) => null,
        );
    }

    /** Commande au format K1 : corps {commande_id, commande}, SANS workspace_id. */
    protected function commande(string $nom, ?string $id = null, string $ws = self::WS_A, ?string $cle = null, ?int $ts = null, ?string $nonce = null, ?string $commande = null): Requete
    {
        $corps = json_encode(['commande_id' => $id ?? self::ID_1, 'commande' => $commande ?? $nom], JSON_THROW_ON_ERROR);

        return $this->signer('POST', '/v1/commandes/'.$nom, $corps, $ws, $cle, $ts, $nonce);
    }

    /** Signe comme le client K1 : charge « METHODE\nchemin\nts\nnonce\nws\ncorps » (GET : corps = requête). */
    protected function signer(string $methode, string $cheminComplet, string $corps, string $ws = self::WS_A, ?string $cle = null, ?int $ts = null, ?string $nonce = null): Requete
    {
        $cle ??= $ws === self::WS_B ? self::CLE_B : self::CLE_A;
        $ts ??= $this->maintenant;
        $nonce ??= self::uuid();
        [$chemin, $requete] = array_pad(explode('?', $cheminComplet, 2), 2, '');
        $signe = $methode === 'GET' ? $requete : $corps;
        $sig = Signature::entete('1', Signature::signer($cle, Signature::chargeRequete($methode, $chemin, (string) $ts, $nonce, $ws, $signe)));

        return new Requete($methode, $cheminComplet, $corps, [
            'x-envoi-workspace' => [$ws], 'x-envoi-timestamp' => [(string) $ts],
            'x-envoi-nonce' => [$nonce], 'x-envoi-signature' => [$sig],
        ]);
    }

    protected static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    protected function passerelleAvecAttente(\Closure $dormir): Passerelle
    {
        return new Passerelle($this->stockage, $this->kumo, [self::WS_A => self::CLE_A, self::WS_B => self::CLE_B], fn (): int => $this->maintenant, $dormir);
    }

    protected function envoyer(Requete $r): Reponse
    {
        return $this->passerelle->traiter($r);
    }
}
