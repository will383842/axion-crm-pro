<?php

namespace App\Services\Smtp\Mocks;

use App\Contracts\SmtpProber;
use App\Data\Email\SmtpProbeResult;
use App\Services\Smtp\RealSmtpProber;

class MockSmtpProber implements SmtpProber
{
    /** @var array<string,string>|null */
    private ?array $statusMap = null;

    public function probe(string $email): SmtpProbeResult
    {
        $this->statusMap ??= $this->loadFixture();
        $email = strtolower(trim($email));

        // Le jetable est une règle de DOMAINE, pas une liste d'adresses :
        // `RealSmtpProber` rejette n'importe quelle adresse @mailinator.com,
        // quel que soit son préfixe. La fixture ne connaît que les adresses
        // qu'on a pensé à y écrire — sans cette règle, le mock qualifiait
        // « valid » (score 95) toute boîte jetable absente du fichier, et la
        // doctrine « 0 email douteux » ne tenait plus dès qu'on passe en
        // SMTP_PROBER=mock (dev, CI, jeux de démonstration).
        $domain = strstr($email, '@') ? substr((string) strrchr($email, '@'), 1) : '';
        $status = in_array($domain, RealSmtpProber::DISPOSABLE_DOMAINS, true)
            ? 'disposable'
            : ($this->statusMap[$email] ?? 'valid');

        return new SmtpProbeResult(
            email: $email,
            status: $status,
            score: match ($status) {
                'valid' => 95,
                'catchall' => 60,
                'unknown' => 30,
                'disposable' => 0,
                'role' => 40,
                default => 0,
            },
            mxHost: 'mx.mock',
            message: 'mock probe',
            isCatchAll: $status === 'catchall',
            isDisposable: $status === 'disposable',
            isRole: $status === 'role',
        );
    }

    /** @return array<string,string> */
    private function loadFixture(): array
    {
        $path = base_path('tests/fixtures/smtp/email_status_map.json');
        if (! file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
