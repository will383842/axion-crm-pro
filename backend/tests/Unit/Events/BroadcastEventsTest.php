<?php

use App\Events\CompanyEnriched;
use App\Events\NotificationCreated;
use App\Events\ScrapeJobCompleted;
use Illuminate\Broadcasting\PrivateChannel;

test('ScrapeJobCompleted broadcastWith expose les bons champs', function () {
    $event = new ScrapeJobCompleted(
        workspaceId: '11111111-1111-1111-1111-111111111111',
        scraperRunId: 42,
        status: 'success',
        companiesCreated: 10,
        companiesUpdated: 3,
    );

    $payload = $event->broadcastWith();
    expect($payload)
        ->toHaveKey('scraper_run_id', 42)
        ->toHaveKey('status', 'success')
        ->toHaveKey('companies_created', 10)
        ->toHaveKey('companies_updated', 3)
        ->toHaveKey('occurred_at');
});

test('ScrapeJobCompleted broadcast sur channel private workspace', function () {
    $event = new ScrapeJobCompleted('wsid-1', 1, 'success', 0, 0);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
});

test('ScrapeJobCompleted alias broadcast name', function () {
    $event = new ScrapeJobCompleted('wsid-1', 1, 'success', 0, 0);
    expect($event->broadcastAs())->toBe('scrape-job.completed');
});

test('CompanyEnriched payload', function () {
    $event = new CompanyEnriched(
        workspaceId: 'wsid-2',
        companyId: 99,
        companyName: 'Acme SAS',
        newQualityScore: 85,
        fieldsEnriched: ['phone', 'linkedin_url'],
    );
    $payload = $event->broadcastWith();
    expect($payload)
        ->toHaveKey('company_name', 'Acme SAS')
        ->toHaveKey('new_quality_score', 85)
        ->toHaveKey('fields_enriched');
    expect($payload['fields_enriched'])->toBe(['phone', 'linkedin_url']);
});

test('NotificationCreated route vers channel user si userId fourni', function () {
    $event = new NotificationCreated(
        workspaceId: 'wsid-3',
        notificationId: 1,
        type: 'info',
        title: 'Test',
        body: 'body',
        userId: 'user-abc',
    );
    $channels = $event->broadcastOn();
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    // PrivateChannel name est privé mais sa string contient le name
    expect((string) $channels[0]->name)->toContain('user.user-abc');
});

test('NotificationCreated route vers channel workspace si userId null', function () {
    $event = new NotificationCreated(
        workspaceId: 'wsid-4',
        notificationId: 1,
        type: 'info',
        title: 'Test',
        body: 'body',
        userId: null,
    );
    $channels = $event->broadcastOn();
    expect((string) $channels[0]->name)->toContain('workspace.wsid-4');
});

test('NotificationCreated payload contient severity', function () {
    $event = new NotificationCreated(
        workspaceId: 'wsid-5',
        notificationId: 7,
        type: 'rgpd_request',
        title: 'Nouvelle demande RGPD',
        body: 'Une demande vient d\'arriver',
        severity: 'warning',
        actionUrl: '/rgpd/requests/7',
    );
    $payload = $event->broadcastWith();
    expect($payload)
        ->toHaveKey('severity', 'warning')
        ->toHaveKey('action_url', '/rgpd/requests/7')
        ->toHaveKey('title', 'Nouvelle demande RGPD');
});
