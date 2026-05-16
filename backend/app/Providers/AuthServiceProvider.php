<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        \App\Models\Company::class      => \App\Policies\CompanyPolicy::class,
        \App\Models\Contact::class      => \App\Policies\ContactPolicy::class,
        \App\Models\ScraperRun::class   => \App\Policies\ScraperRunPolicy::class,
        \App\Models\Workspace::class    => \App\Policies\WorkspacePolicy::class,
        \App\Models\User::class         => \App\Policies\UserPolicy::class,
        \App\Models\Tag::class          => \App\Policies\TagPolicy::class,
        \App\Models\RgpdRequest::class  => \App\Policies\RgpdRequestPolicy::class,
        \App\Models\AuditLog::class     => \App\Policies\AuditLogPolicy::class,
        \App\Models\LlmUseCase::class   => \App\Policies\LlmUseCasePolicy::class,
        \App\Models\ProxyProvider::class=> \App\Policies\ProxyProviderPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
