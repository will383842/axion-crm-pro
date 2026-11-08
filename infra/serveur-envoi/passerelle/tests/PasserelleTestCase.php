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

    protected string $fichier;
    protected int $maintenant = 1_800_000_000;
    protected FauxKumo $kumo;
    protected Passerelle $passerelle;
    protected Stockage $stockage;
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
        $this->stockage = new Stockage($this->fichier, fn (): int => $this->maintenant);
        $this->passerelle = new Passerelle(
            $this->stockage,
            $this->kumo,
            ['espace-a' => self::CLE_A, 'espace-b' => self::CLE_B],
            fn (): int => $this->maintenant,
        );
    }

    /** @param array<string,mixed> $corps */
    protected function commande(string $operation, array $corps, string $ws = 'espace-a', ?string $cle = null, ?int $ts = null, ?string $nonce = null): Requete
    {
        return $this->signer('POST', '/v1/commandes/'.$operation, json_encode($corps, JSON_THROW_ON_ERROR), $ws, $cle, $ts, $nonce);
    }

    protected function signer(string $methode, string $chemin, string $corps, string $ws = 'espace-a', ?string $cle = null, ?int $ts = null, ?string $nonce = null): Requete
    {
        $cle ??= $ws === 'espace-b' ? self::CLE_B : self::CLE_A;
        $ts ??= $this->maintenant;
        $nonce ??= 'nonce-'.(++$this->n).'-'.bin2hex(random_bytes(4));
        $sig = Signature::signer($cle, $ws, (string) $ts, $nonce, $methode, $chemin, $corps);

        return new Requete($methode, $chemin, $corps, [
            'x-workspace-id' => [$ws], 'x-timestamp' => [(string) $ts],
            'x-nonce' => [$nonce], 'x-signature' => [$sig],
        ]);
    }

    protected function envoyer(Requete $r): Reponse
    {
        return $this->passerelle->traiter($r);
    }
}
