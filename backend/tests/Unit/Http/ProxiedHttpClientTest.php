<?php

use App\Services\Http\ProxiedHttpClient;
use Illuminate\Support\Facades\Config;

it('reports proxy disabled by default', function () {
    Config::set('services.webshare.enabled', false);
    expect(app(ProxiedHttpClient::class)->isProxyEnabled())->toBeFalse();
});

it('reports proxy enabled when flag set', function () {
    Config::set('services.webshare.enabled', true);
    Config::set('services.webshare.username', 'u');
    Config::set('services.webshare.password', 'p');
    expect(app(ProxiedHttpClient::class)->isProxyEnabled())->toBeTrue();
});

it('builds a PendingRequest without proxy option when disabled', function () {
    Config::set('services.webshare.enabled', false);
    $client = app(ProxiedHttpClient::class)->request();
    $options = (fn () => $this->options)->call($client);
    expect($options['proxy'] ?? null)->toBeNull();
});

it('applies proxy option when enabled with credentials', function () {
    Config::set('services.webshare.enabled', true);
    Config::set('services.webshare.username', 'wsuser');
    Config::set('services.webshare.password', 'wspass');
    Config::set('services.webshare.endpoint', 'p.webshare.io:80');
    $client = app(ProxiedHttpClient::class)->request();
    $options = (fn () => $this->options)->call($client);
    expect($options['proxy'] ?? null)->toBe('http://wsuser:wspass@p.webshare.io:80');
});

it('skips proxy option when enabled but credentials missing', function () {
    Config::set('services.webshare.enabled', true);
    Config::set('services.webshare.username', '');
    Config::set('services.webshare.password', '');
    $client = app(ProxiedHttpClient::class)->request();
    $options = (fn () => $this->options)->call($client);
    expect($options['proxy'] ?? null)->toBeNull();
});
