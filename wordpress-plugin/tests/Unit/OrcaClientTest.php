<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use OrcaDam\Api\Cache;
use OrcaDam\Api\OrcaClient;
use OrcaDam\Api\Transport\Transport;
use OrcaDam\Api\Transport\TransportResponse;
use OrcaDam\Settings\CredentialStore;
use OrcaDam\Settings\Encryption;
use PHPUnit\Framework\TestCase;

final class OrcaClientTest extends TestCase
{
    private FakeTransport $transport;
    private OrcaClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
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
        $this->client = new OrcaClient($this->transport, $credentials, $cache);
    }

    public function test_search_assets_sends_expected_query(): void
    {
        $this->transport->next = new TransportResponse(200, ['data' => []]);
        $this->client->searchAssets(['q' => 'logo', 'sort' => 'name_asc']);

        $this->assertSame('GET', $this->transport->lastMethod);
        $this->assertSame('https://dam.test/api/assets/search', $this->transport->lastUrl);
        $this->assertSame('logo', $this->transport->lastQuery['q']);
        $this->assertSame('name_asc', $this->transport->lastQuery['sort']);
        $this->assertSame('image', $this->transport->lastQuery['type']);
        $this->assertSame('Bearer fake-token', $this->transport->lastHeaders['Authorization']);
    }

    public function test_add_reference_tags_posts_expected_body(): void
    {
        $this->transport->next = new TransportResponse(200, ['message' => 'ok']);
        $this->client->addReferenceTags(42, ['wp:site.test/post/1']);

        $this->assertSame('POST', $this->transport->lastMethod);
        $this->assertSame('https://dam.test/api/reference-tags', $this->transport->lastUrl);
        $this->assertSame(['asset_id' => 42, 'tags' => ['wp:site.test/post/1']], $this->transport->lastBody);
    }

    public function test_remove_reference_tag_sends_delete_with_body(): void
    {
        $this->transport->next = new TransportResponse(200, []);
        $this->client->removeReferenceTagByName(7, 'wp:site.test/post/9');

        $this->assertSame('DELETE', $this->transport->lastMethod);
        $this->assertSame('https://dam.test/api/reference-tags', $this->transport->lastUrl);
        $this->assertSame(['asset_id' => 7, 'tag_name' => 'wp:site.test/post/9'], $this->transport->lastBody);
    }
}

final class FakeTransport implements Transport
{
    public TransportResponse $next;
    public string $lastMethod = '';
    public string $lastUrl = '';
    public array $lastQuery = [];
    public ?array $lastBody = null;
    public array $lastHeaders = [];

    public function request(string $method, string $url, array $query = [], ?array $body = null, array $headers = []): TransportResponse
    {
        $this->lastMethod = $method;
        $this->lastUrl = $url;
        $this->lastQuery = $query;
        $this->lastBody = $body;
        $this->lastHeaders = $headers;
        return $this->next ?? new TransportResponse(200, []);
    }
}
