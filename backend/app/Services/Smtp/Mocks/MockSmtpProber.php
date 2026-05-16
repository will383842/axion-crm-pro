<?php

namespace App\Services\Smtp\Mocks;

use App\Contracts\SmtpProber;
use App\Data\Email\SmtpProbeResult;

class MockSmtpProber implements SmtpProber
{
    /** @var array<string,string>|null */
    private ?array $statusMap = null;

    public function probe(string $email): SmtpProbeResult
    {
        $this->statusMap ??= $this->loadFixture();
        $email = strtolower(trim($email));

        $status = $this->statusMap[$email] ?? 'valid';
        return new SmtpProbeResult(
            email: $email,
            status: $status,
            score: match ($status) {
                'valid'      => 95,
                'catchall'   => 60,
                'unknown'    => 30,
                'disposable' => 0,
                'role'       => 40,
                default      => 0,
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
