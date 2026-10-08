<?php

declare(strict_types=1);

namespace Znuny2Zammad\Tests;

use Znuny2Zammad\ApiException;
use Znuny2Zammad\Http\HttpResponse;
use Znuny2Zammad\Logger;
use Znuny2Zammad\ZnunyClient;

final class ZnunyClientTest extends TestCase
{
    private const BASE = 'https://znuny.test/otrs/nph-genericinterface.pl/Webservice/Mein%20Webservice';

    private function client(FakeHttpClient $http, array $config = []): ZnunyClient
    {
        return new ZnunyClient($http, new Logger(Logger::ERROR + 1), $config + [
            'base_url'   => 'https://znuny.test/otrs/',
            'webservice' => 'Mein Webservice',
            'user'       => 'bridge',
            'password'   => 'geheim',
            'auth'       => 'session',
        ]);
    }

    public function testUrls(): void
    {
        $this->assertSame(self::BASE, ZnunyClient::buildWebserviceUrl(['base_url' => 'https://znuny.test/otrs/', 'webservice' => 'Mein Webservice']));
        $this->assertSame('https://x.test/ws', ZnunyClient::buildWebserviceUrl(['webservice_url' => 'https://x.test/ws/', 'base_url' => 'egal']));
        $this->assertSame('https://znuny.test/znuny/index.pl?Action=AgentTicketZoom;TicketID=5', ZnunyClient::agentTicketUrl(['base_url' => 'https://znuny.test/znuny/'], 5));
    }

    public function testSessionAuthAndTicketGet(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', '~/Session$~', ['SessionID' => 'abc'])
            ->on('GET', '~/Ticket/7\?~', ['Ticket' => [['TicketID' => '7', 'TicketNumber' => '1001']]]);
        $client = $this->client($http);

        $this->assertSame('1001', $client->getTicket(7)['TicketNumber']);
        $this->assertSame('1001', $client->getTicket(7)['TicketNumber']);

        $this->assertCount(1, $http->requestsMatching('POST', '~/Session$~'), 'Session wird wiederverwendet');
        $session = $http->requests[0];
        $this->assertSame(['UserLogin' => 'bridge', 'Password' => 'geheim'], FakeHttpClient::body($session));
        $this->assertSame('application/json; charset=utf-8', $session['headers']['Content-Type']);

        $get = $http->requests[1];
        $this->assertSame(self::BASE . '/Ticket/7?AllArticles=1&Attachments=1&DynamicFields=1&HTMLBodyAsAttachment=1', $get['url']);
        $this->assertSame('abc', $get['headers']['X-OTRS-Header-SessionID']);
        $this->assertFalse(isset($get['headers']['X-OTRS-Header-Password']));
    }

    public function testPasswordAuthUsesHeaders(): void
    {
        $http = new FakeHttpClient();
        $http->on('GET', '~/Ticket/7\?~', ['Ticket' => [['TicketID' => '7']]]);
        $this->client($http, ['auth' => 'password'])->getTicket(7);
        $this->assertCount(1, $http->requests);
        $this->assertSame('bridge', $http->requests[0]['headers']['X-OTRS-Header-UserLogin']);
        $this->assertSame('geheim', $http->requests[0]['headers']['X-OTRS-Header-Password']);
        $this->assertStringNotContains('geheim', $http->requests[0]['url']);
    }

    public function testErrorsWithHttp200AreDetected(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', '~/Session$~', ['SessionID' => 'abc'])
            ->on('GET', '~/Ticket/7\?~', ['Error' => ['ErrorCode' => 'TicketGet.AccessDenied', 'ErrorMessage' => 'TicketGet: User does not have access to the ticket!']]);
        $this->assertThrows(ApiException::class, function () use ($http): void {
            $this->client($http)->getTicket(7);
        }, 'TicketGet.AccessDenied');
    }

    public function testFailedLoginIsNotRetried(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', '~/Session$~', ['Error' => ['ErrorCode' => 'SessionCreate.AuthFail', 'ErrorMessage' => 'SessionCreate: Authorization failing!']]);
        $this->assertThrows(ApiException::class, function () use ($http): void {
            $this->client($http)->getTicket(7);
        }, 'Anmeldung als "bridge" fehlgeschlagen');
        $this->assertCount(1, $http->requests, 'kein zweiter Login-Versuch (Kontosperre)');
    }

    public function testExpiredSessionIsRenewedOnce(): void
    {
        $http     = new FakeHttpClient();
        $sessions = 0;
        $http->on('POST', '~/Session$~', static function () use (&$sessions) {
            $sessions++;

            return ['SessionID' => 's' . $sessions];
        })->on('GET', '~/Ticket/7\?~', static function (array $request) {
            return $request['headers']['X-OTRS-Header-SessionID'] === 's2'
                ? ['Ticket' => [['TicketID' => '7']]]
                : ['Error' => ['ErrorCode' => 'TicketGet.AuthFail', 'ErrorMessage' => 'TicketGet: Authorization failing!']];
        });
        $this->assertSame('7', $this->client($http)->getTicket(7)['TicketID']);
        $this->assertSame(2, $sessions);
    }

    public function testTransportErrors(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', '~/Session$~', new HttpResponse(500, ''));
        $this->assertThrows(ApiException::class, function () use ($http): void {
            $this->client($http)->getTicket(7);
        }, 'Ist der Webservice-Name korrekt');

        $http = new FakeHttpClient();
        $http->on('POST', '~/Session$~', new HttpResponse(500, "HTTP::REST Error while determine Operation for request URI '/Sessions'."));
        $this->assertThrows(ApiException::class, function () use ($http): void {
            $this->client($http)->getTicket(7);
        }, 'determine Operation');
    }

    public function testTicketSearch(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', '~/Session$~', ['SessionID' => 'abc'])
            ->on('POST', '~/Ticket/Search$~', static function (array $request) {
                $body = FakeHttpClient::body($request);

                return $body['TicketNumber'] === '1001' ? ['TicketID' => ['7']] : [];
            });
        $client = $this->client($http);
        $this->assertSame(7, $client->findTicketIdByNumber('1001'));
        $this->assertSame(null, $client->findTicketIdByNumber('9999'), 'Znuny liefert {} ohne Treffer');
    }

    public function testGetRouteForTicketSearchUsesRepeatedQueryKeys(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', '~/Session$~', ['SessionID' => 'abc'])
            ->on('GET', '~/Ticket\?~', ['TicketID' => ['3', '4']]);
        $client = $this->client($http, ['routes' => ['TicketSearch' => 'GET /Ticket']]);

        $this->assertSame([3, 4], $client->searchTicketIds(['Queues' => ['An Zammad', 'A+B'], 'StateType' => ['new', 'open']]));
        $this->assertSame(
            self::BASE . '/Ticket?Queues=An%20Zammad&Queues=A%2BB&StateType=new&StateType=open',
            $http->requests[1]['url']
        );
    }

    public function testInternalNoteAndUpdate(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', '~/Session$~', ['SessionID' => 'abc'])
            ->on('PATCH', '~/Ticket/7$~', ['TicketID' => '7', 'ArticleID' => '99']);
        $client = $this->client($http);
        $client->addInternalNote(7, 'Betreff', 'Text');
        $client->updateTicket(7, ['State' => 'closed successful']);
        $client->updateTicket(7, []);

        $this->assertCount(3, $http->requests);
        $note = FakeHttpClient::body($http->requests[1]);
        $this->assertSame(0, $note['Article']['IsVisibleForCustomer']);
        $this->assertSame('Internal', $note['Article']['CommunicationChannel']);
        $this->assertFalse(isset($note['Article']['From']), 'kein From: vermeidet MX-Pruefung und Auto-Antworten');
        $this->assertSame(['Ticket' => ['State' => 'closed successful']], FakeHttpClient::body($http->requests[2]));
    }
}
