<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\LlmUseCase;
use App\Models\ProxyProvider;
use App\Models\RgpdRequest;
use App\Models\ScraperRun;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\AuditLogPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\ContactPolicy;
use App\Policies\LlmUseCasePolicy;
use App\Policies\ProxyProviderPolicy;
use App\Policies\RgpdRequestPolicy;
use App\Policies\ScraperRunPolicy;
use App\Policies\TagPolicy;
use App\Policies\UserPolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        Company::class => CompanyPolicy::class,
        Contact::class => ContactPolicy::class,
        ScraperRun::class => ScraperRunPolicy::class,
        Workspace::class => WorkspacePolicy::class,
        User::class => UserPolicy::class,
        Tag::class => TagPolicy::class,
        RgpdRequest::class => RgpdRequestPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        LlmUseCase::class => LlmUseCasePolicy::class,
        ProxyProvider::class => ProxyProviderPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
