<?php

/**
 * Le DNS de la vérification des e-mails, OCTET PAR OCTET et SANS RÉSEAU :
 * la question, la lecture d'une réponse, ce qu'on en conclut, et
 * l'enchaînement MX → A → AAAA. Domaines FICTIFS.
 */

use App\Crm\Emails\Dns\MessageDns;
use App\Crm\Emails\Dns\ResolveurDnsUdp;
use App\Crm\Emails\Dns\ResultatDns;

/** Un nom encodé (libellés), sans compression. */
function mdnsNom(string $nom): string
{
    $sortie = '';
    foreach ($nom === '' ? [] : explode('.', $nom) as $l) {
        $sortie .= chr(strlen($l)) . $l;
    }

    return $sortie . "\0";
}

/**
 * Une réponse DNS : la question reprise, puis les enregistrements donnés
 * (`[type, rdata]`). `$drapeaux` : QR posé par défaut.
 *
 * @param  list<array{0: int, 1: string}>  $rr
 */
function mdnsReponse(int $id, string $question, int $qtype, array $rr, int $rcode = 0, bool $tronque = false): string
{
    $drapeaux = 0x8180 | $rcode | ($tronque ? 0x0200 : 0);
    $paquet = pack('nnnnnn', $id, $drapeaux, 1, count($rr), 0, 0) . mdnsNom($question) . pack('nn', $qtype, 1);
    foreach ($rr as [$type, $rdata]) {
        // Nom de l'enregistrement : pointeur vers la question (octet 12).
        $paquet .= "\xC0\x0C" . pack('nnNn', $type, 1, 300, strlen($rdata)) . $rdata;
    }

    return $paquet;
}

test('la question : identifiant, recursion demandee, nom en libelles, type et classe', function () {
    $q = MessageDns::requete(0xABCD, 'zz-exemple.fr', MessageDns::TYPE_MX);

    expect(bin2hex($q))->toBe('abcd01000001000000000000' . bin2hex(mdnsNom('zz-exemple.fr')) . '000f0001');
});

test('une reponse MX est lue, pointeur de compression compris', function () {
    // Cible `mx1` + pointeur vers `zz-exemple.fr` dans la question.
    $rdata = pack('n', 10) . "\x03mx1\xC0\x0C";
    $lue = MessageDns::lire(mdnsReponse(7, 'zz-exemple.fr', MessageDns::TYPE_MX, [[MessageDns::TYPE_MX, $rdata]]));

    expect($lue['id'])->toBe(7)
        ->and($lue['reponse'])->toBeTrue()
        ->and($lue['rcode'])->toBe(0)
        ->and($lue['question'])->toBe('zz-exemple.fr')
        ->and($lue['qtype'])->toBe(MessageDns::TYPE_MX)
        ->and($lue['enregistrements'])->toBe([['type' => MessageDns::TYPE_MX, 'cible' => 'mx1.zz-exemple.fr']]);
});

test('une reponse mal formee ou un pointeur qui boucle est refuse, jamais lu de travers', function () {
    $boucle = pack('nnnnnn', 1, 0x8180, 1, 0, 0, 0) . "\xC0\x0C" . pack('nn', 15, 1);

    expect(fn () => MessageDns::lire("\x00\x01"))->toThrow(RuntimeException::class)
        ->and(fn () => MessageDns::lire($boucle))->toThrow(RuntimeException::class)
        ->and(fn () => MessageDns::lire(substr(mdnsReponse(1, 'zz-exemple.fr', 15, [[15, pack('n', 1) . mdnsNom('mx.zz-exemple.fr')]]), 0, -3)))
        ->toThrow(RuntimeException::class);
});

test('conclure MX : NXDOMAIN, panne, MX nul, vrai MX, rien', function () {
    $mx = fn (string $cible): array => ['type' => MessageDns::TYPE_MX, 'cible' => $cible];
    $rep = fn (int $rcode, array $e = [], bool $tronque = false): array => ['rcode' => $rcode, 'tronque' => $tronque, 'enregistrements' => $e];

    expect(MessageDns::conclureMx($rep(3))?->verdict)->toBe(ResultatDns::INEXISTANT)
        ->and(MessageDns::conclureMx($rep(2))?->verdict)->toBe(ResultatDns::INDETERMINE)
        ->and(MessageDns::conclureMx($rep(5))?->verdict)->toBe(ResultatDns::INDETERMINE)
        ->and(MessageDns::conclureMx($rep(0, [$mx('')]))?->verdict)->toBe(ResultatDns::MX_NUL)
        ->and(MessageDns::conclureMx($rep(0, [$mx(''), $mx('mx.zz-exemple.fr')]))?->verdict)->toBe(ResultatDns::MX)
        ->and(MessageDns::conclureMx($rep(0, [$mx('mx.zz-exemple.fr')]))?->mx)->toBe('mx.zz-exemple.fr')
        // Le domaine existe, sans MX : il faut demander l'adresse.
        ->and(MessageDns::conclureMx($rep(0)))->toBeNull()
        ->and(MessageDns::conclureMx($rep(0, [], true))?->verdict)->toBe(ResultatDns::INDETERMINE);
});

test('conclure A/AAAA : une adresse suffit, un CNAME seul ne suffit pas', function () {
    $rep = fn (int $rcode, array $e = []): array => ['rcode' => $rcode, 'tronque' => false, 'enregistrements' => $e];

    expect(MessageDns::conclureAdresse($rep(0, [['type' => MessageDns::TYPE_A, 'cible' => null]]), MessageDns::TYPE_A)?->verdict)->toBe(ResultatDns::A)
        ->and(MessageDns::conclureAdresse($rep(0, [['type' => MessageDns::TYPE_CNAME, 'cible' => null]]), MessageDns::TYPE_A))->toBeNull()
        ->and(MessageDns::conclureAdresse($rep(3), MessageDns::TYPE_A)?->verdict)->toBe(ResultatDns::INEXISTANT)
        ->and(MessageDns::conclureAdresse($rep(2), MessageDns::TYPE_AAAA)?->verdict)->toBe(ResultatDns::INDETERMINE);
});

test('l enchainement : MX, sinon A, sinon AAAA, sinon sans courrier ; sans reponse, indetermine', function () {
    $ok = fn (array $e = []): array => ['rcode' => 0, 'tronque' => false, 'enregistrements' => $e];
    $monde = [
        'avec-mx.zz' => [MessageDns::TYPE_MX => $ok([['type' => MessageDns::TYPE_MX, 'cible' => 'mx.avec-mx.zz']])],
        'avec-a.zz' => [MessageDns::TYPE_MX => $ok(), MessageDns::TYPE_A => $ok([['type' => MessageDns::TYPE_A, 'cible' => null]])],
        'avec-aaaa.zz' => [MessageDns::TYPE_MX => $ok(), MessageDns::TYPE_A => $ok(), MessageDns::TYPE_AAAA => $ok([['type' => MessageDns::TYPE_AAAA, 'cible' => null]])],
        'vide.zz' => [MessageDns::TYPE_MX => $ok(), MessageDns::TYPE_A => $ok(), MessageDns::TYPE_AAAA => $ok()],
        'muet.zz' => [],
        'muet-en-a.zz' => [MessageDns::TYPE_MX => $ok()],
    ];
    $questions = [];
    $interroger = function (array $domaines, int $type) use ($monde, &$questions): array {
        $questions[] = [$type, $domaines];
        $r = [];
        foreach ($domaines as $d) {
            $r[$d] = $monde[$d][$type] ?? null;
        }

        return $r;
    };

    $resultats = ResolveurDnsUdp::enchainer(array_keys($monde), $interroger);

    expect(array_map(fn (ResultatDns $r): string => $r->verdict, $resultats))->toEqual([
        'avec-mx.zz' => ResultatDns::MX,
        'avec-a.zz' => ResultatDns::A,
        'avec-aaaa.zz' => ResultatDns::A,
        'vide.zz' => ResultatDns::SANS_COURRIER,
        'muet.zz' => ResultatDns::INDETERMINE,
        'muet-en-a.zz' => ResultatDns::INDETERMINE,
    ])
        // Un domaine qui a un MX n'est jamais interrogé en A.
        ->and($questions[1])->toBe([MessageDns::TYPE_A, ['avec-a.zz', 'avec-aaaa.zz', 'vide.zz', 'muet-en-a.zz']])
        ->and($questions[2])->toBe([MessageDns::TYPE_AAAA, ['avec-aaaa.zz', 'vide.zz']])
        ->and(count($questions))->toBe(3);
});

test('un verdict indetermine ne dit jamais « ne recoit pas »', function () {
    expect((new ResultatDns(ResultatDns::INDETERMINE))->recoit())->toBeNull()
        ->and((new ResultatDns(ResultatDns::MX))->recoit())->toBeTrue()
        ->and((new ResultatDns(ResultatDns::A))->recoit())->toBeTrue()
        ->and((new ResultatDns(ResultatDns::INEXISTANT))->recoit())->toBeFalse()
        ->and((new ResultatDns(ResultatDns::MX_NUL))->recoit())->toBeFalse()
        ->and((new ResultatDns(ResultatDns::SANS_COURRIER))->recoit())->toBeFalse()
        ->and(fn () => new ResultatDns('peut-etre'))->toThrow(InvalidArgumentException::class);
});

test('le resolveur : designe, sinon /etc/resolv.conf, sinon aucun ; une adresse qui n est pas une IP est refusee', function () {
    $conf = (string) tempnam(sys_get_temp_dir(), 'zz-resolv-');
    file_put_contents($conf, "# commentaire\nsearch zz.local\nnameserver 192.0.2.53\nnameserver 192.0.2.54\n");
    $vide = (string) tempnam(sys_get_temp_dir(), 'zz-resolv-');
    file_put_contents($vide, "search zz.local\n");

    try {
        expect(ResolveurDnsUdp::serveurParDefaut('192.0.2.1:5353', $conf))->toBe('192.0.2.1:5353')
            ->and(ResolveurDnsUdp::serveurParDefaut('', $conf))->toBe('192.0.2.53')
            ->and(ResolveurDnsUdp::serveurParDefaut(null, $vide))->toBeNull()
            ->and(ResolveurDnsUdp::serveurParDefaut(null, $conf . '-absent'))->toBeNull()
            ->and((new ResolveurDnsUdp('192.0.2.1'))->nom())->toBe('192.0.2.1:53')
            ->and((new ResolveurDnsUdp('[2001:db8::53]:5353'))->nom())->toBe('[2001:db8::53]:5353')
            ->and(fn () => new ResolveurDnsUdp('dns.zz-exemple.fr'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new ResolveurDnsUdp('192.0.2.1', debit: 0))->toThrow(InvalidArgumentException::class);
    } finally {
        @unlink($conf);
        @unlink($vide);
    }
});
