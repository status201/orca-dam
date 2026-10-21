<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Support;

use OrcaDam\Api\Cache;
use OrcaDam\Api\OrcaClient;
use OrcaDam\Api\Transport\Transport;
use OrcaDam\Api\Transport\TransportResponse;
use OrcaDam\Settings\CredentialStore;

/**
 * A real OrcaClient on a scripted transport: `$responder($method, $url)` decides
 * each response, and every request is recorded in `$requests`.
 */
final class FakeOrca implements Transport
{
    /** @var list<array{method: string, url: string}> */
    public array $requests = [];

    /** @param \Closure(string, string): TransportResponse $responder */
    public function __construct(private readonly \Closure $responder) {}

    public static function client(\Closure $responder): array
    {
        $transport = new self($responder);
        $credentials = new class extends CredentialStore {
            public function __construct() {}
            public function baseUrl(): string { return 'https://dam.test'; }
            public function token(): ?string { return 'fake-token'; }
            public function defaultFolder(): string { return ''; }
        };
        $cache = new class extends Cache {
            public function remember(string $key, int $ttl, callable $factory): mixed { return $factory(); }
            public function forget(string $key): void {}
        };

        return [new OrcaClient($transport, $credentials, $cache), $transport];
    }

    public function request(string $method, string $url, array $query = [], ?array $body = null, array $headers = []): TransportResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url];
        return ($this->responder)($method, $url);
    }
}
