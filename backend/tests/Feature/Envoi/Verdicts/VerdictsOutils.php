<?php

/**
 * VERDICTS-CODE-1 — aides communes des tests (fonctions préfixées `vdc`). Fixtures FICTIVES (`*.example.test`).
 */

use App\Crm\Emails\Dns\ResolveurDns;
use App\Envoi\Verdicts\QuotaDns;
use App\Envoi\Verdicts\VerificateurInterne;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ResolveurDnsSimule;

function vdcEspace(bool $contexte = true): string
{
    $ws = F::espace('zz-verdicts');
    if ($contexte) {
        WorkspaceContext::set($ws);
    }

    return $ws;
}

/** Vérificateur interne branché sur un DNS simulé (aucun réseau). */
function vdcVerificateur(ResolveurDnsSimule $dns, ?QuotaDns $quota = null): VerificateurInterne
{
    app()->instance(ResolveurDns::class, $dns);

    return new VerificateurInterne($quota ?? new QuotaDns, fn (): ResolveurDns => $dns);
}

function vdcContact(string $ws, string $email, array $attrs = []): int
{
    $societe = F::fiche($ws, 'ZZ Societe ' . random_int(1, 999999));

    return F::contact($ws, $societe, 'Zoe', 'Martin' . random_int(1, 999999), array_merge(['email' => $email], $attrs));
}

function vdcEmpreinte(string $adresse): string
{
    return ListeSuppression::empreinte($adresse);
}

function vdcLignes(string $ws): array
{
    return DB::table('envoi_verdicts_adresse')->where('workspace_id', $ws)->orderBy('id')->get()->all();
}
