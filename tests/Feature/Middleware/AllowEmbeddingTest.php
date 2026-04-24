<?php

use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
});

test('empty embed_allowed_domains does not set CSP frame-ancestors', function () {
    Setting::set('embed_allowed_domains', []);

    $response = $this->get('/assets');

    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
});

test('configured embed_allowed_domains sets CSP frame-ancestors and removes X-Frame-Options', function () {
    Setting::set('embed_allowed_domains', ['https://rte.example.com', 'https://cms.example.com']);

    $response = $this->get('/assets');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("frame-ancestors 'self'");
    expect($csp)->toContain('https://rte.example.com');
    expect($csp)->toContain('https://cms.example.com');
    expect($response->headers->get('X-Frame-Options'))->toBeNull();
});

test('domains stored as JSON string are decoded', function () {
    Setting::set('embed_allowed_domains', json_encode(['https://foo.example.com']));

    $response = $this->get('/assets');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('https://foo.example.com');
});
