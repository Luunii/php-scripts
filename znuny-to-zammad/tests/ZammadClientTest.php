<?php

declare(strict_types=1);

namespace Znuny2Zammad\Tests;

use Znuny2Zammad\ApiException;
use Znuny2Zammad\Http\HttpResponse;
use Znuny2Zammad\Logger;
use Znuny2Zammad\ZammadClient;

final class ZammadClientTest extends TestCase
{
    private function client(FakeHttpClient $http): ZammadClient
    {
        return new ZammadClient($http, new Logger(Logger::ERROR + 1), 'https://zammad.test/', 'tok');
    }

    public function testAuthorizationHeaderAndUrl(): void
    {
        $http = new FakeHttpClient();
        $http->on('GET', '~/api/v1/users/me$~', ['id' => 1]);
        $client = $this->client($http);
        $client->me();
        $this->assertSame('Token token=tok', $http->requests[0]['headers']['Authorization']);
        $this->assertFalse(isset($http->requests[0]['headers']['From']));
        $this->assertSame('https://zammad.test/#ticket/zoom/12', $client->ticketUrl(12));
    }

    public function testFindGroupAcceptsUiNotationAndPaging(): void
    {
        $http = new FakeHttpClient();
        $page1 = [];
        for ($i = 1; $i <= 500; $i++) {
            $page1[] = ['id' => $i, 'name' => 'Gruppe ' . $i];
        }
        $http->on('GET', '~/api/v1/groups\?.*page=1$~', $page1)
            ->on('GET', '~/api/v1/groups\?.*page=2$~', [['id' => 501, 'name' => 'Sales::Europe']]);
        $client = $this->client($http);

        $this->assertSame(501, $client->findGroup('Sales › Europe')['id']);
        $this->assertSame(501, $client->findGroup('sales::europe')['id']);
        $this->assertSame(7, $client->findGroup('Gruppe 7')['id']);
        $this->assertSame(null, $client->findGroup('Unbekannt'));
        $this->assertCount(2, $http->requests, 'Gruppen werden nur einmal geladen');
    }

    public function testFindUserByEmailRequiresExactMatch(): void
    {
        $http = new FakeHttpClient();
        $http->on('GET', '~/api/v1/users/search\?query=anna%40example.com&limit=100$~', [
            ['id' => 1, 'email' => 'joanna@example.com', 'active' => true],
            ['id' => 2, 'email' => 'Anna@Example.com', 'active' => false],
            ['id' => 3, 'email' => 'anna@example.com', 'active' => true],
        ]);
        $client = $this->client($http);
        $this->assertSame(3, $client->findUserByEmail(' Anna@example.com ')['id']);
        $this->assertSame(null, $client->findUserByEmail(''), 'leere Suche wuerde alle Benutzer liefern');
        $this->assertCount(1, $http->requests);
    }

    public function testErrorMessages(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', '~/api/v1/tickets$~', FakeHttpClient::json(['error' => 'raw', 'error_human' => 'Gruppe fehlt'], 422))
            ->on('POST', '~/api/v1/ticket_articles$~', new HttpResponse(413, '<html><body><h1>413 Request Entity Too Large</h1></body></html>'));
        $client = $this->client($http);

        $this->assertThrows(ApiException::class, static function () use ($client): void {
            $client->createTicket(['title' => 'x']);
        }, 'HTTP 422 Gruppe fehlt');
        $this->assertThrows(ApiException::class, static function () use ($client): void {
            $client->createArticle(['ticket_id' => 1], true);
        }, 'Anfrage zu gross');
        $this->assertSame('true', $http->requests[1]['headers']['X-Zammad-Suppress-Notifications']);

        try {
            $client->createTicket([]);
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getCode());
        }
    }

    public function testStatesAndPriorities(): void
    {
        $http = new FakeHttpClient();
        $http->on('GET', '~/api/v1/ticket_states\?expand=true&per_page=500&page=1$~', [['id' => 3, 'name' => 'pending reminder', 'state_type' => 'pending reminder']])
            ->on('GET', '~/api/v1/ticket_priorities\?per_page=500&page=1$~', [['id' => 2, 'name' => '2 normal']]);
        $client = $this->client($http);
        $this->assertSame(3, $client->findState('Pending Reminder')['id']);
        $this->assertSame(2, $client->findPriority('2 normal')['id']);
        $this->assertSame(null, $client->findPriority('9 egal'));
    }
}
