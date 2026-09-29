<?php

declare(strict_types=1);

namespace Znuny2Zammad\Tests;

use Znuny2Zammad\Http\HttpResponse;

/**
 * Simuliert Znuny (GenericInterface REST) und Zammad (REST-API) fuer Tests.
 */
final class FakeServers
{
    public const ZNUNY  = 'https://znuny.test/znuny/nph-genericinterface.pl/Webservice/Znuny2Zammad';
    public const ZAMMAD = 'https://zammad.test';

    /** @var FakeHttpClient */
    public $http;

    /** @var array<string,mixed> Znuny-Ticket (TicketGet-Format) */
    public $znunyTicket;

    /** @var array<int,array<string,mixed>> Zammad-Tickets nach ID */
    public $tickets = [];

    /** @var array<int,array<string,mixed>> Zammad-Artikel */
    public $articles = [];

    /** @var array<int,array<string,mixed>> */
    public $users = [];

    /** @var array<int,array<string,mixed>> */
    public $groups = [
        ['id' => 1, 'name' => 'Users', 'active' => true, 'email_address_id' => null],
        ['id' => 3, 'name' => 'Support', 'active' => true, 'email_address_id' => 1],
        ['id' => 4, 'name' => 'Support::2nd Level', 'active' => true, 'email_address_id' => 1],
        ['id' => 5, 'name' => 'Archiv', 'active' => false, 'email_address_id' => 1],
    ];

    /** @var array<int,callable> Zammad-Artikel-Anfragen, die fehlschlagen sollen (Nummer => Antwort) */
    public $failArticleCall = [];

    /** @var int */
    private $articleCalls = 0;

    /** @var callable|null */
    public $znunyUpdateHandler;

    public function __construct(array $znunyTicket)
    {
        $this->http        = new FakeHttpClient();
        $this->znunyTicket = $znunyTicket;
        $this->registerZnuny();
        $this->registerZammad();
    }

    /**
     * @return array<string,mixed>
     */
    public static function config(array $override = []): array
    {
        return \Znuny2Zammad\Config::merge(\Znuny2Zammad\Config::defaults(), \Znuny2Zammad\Config::merge([
            'znuny' => [
                'base_url'      => 'https://znuny.test/znuny',
                'webservice'    => 'Znuny2Zammad',
                'user'          => 'bridge',
                'password'      => 'p@ss=word&x',
                'after_forward' => ['state' => 'closed successful'],
            ],
            'zammad' => [
                'url'           => self::ZAMMAD,
                'token'         => 'tok123',
                'default_group' => 'Users',
                'group_map'     => ['Support::*' => 'Support'],
                'state'         => 'open',
            ],
        ], $override));
    }

    /**
     * Alle an Zammad gesendeten Artikel (Ticket-Erstellung + weitere Artikel).
     *
     * @return array<int,array<string,mixed>>
     */
    public function sentArticles(): array
    {
        return $this->articles;
    }

    private function registerZnuny(): void
    {
        $base = preg_quote(self::ZNUNY, '~');
        $this->http
            ->on('POST', '~^' . $base . '/Session$~', static function (array $request) {
                $body = FakeHttpClient::body($request);
                if (($body['UserLogin'] ?? '') !== 'bridge' || ($body['Password'] ?? '') !== 'p@ss=word&x') {
                    return ['Error' => ['ErrorCode' => 'SessionCreate.AuthFail', 'ErrorMessage' => 'SessionCreate: Authorization failing!']];
                }

                return ['SessionID' => 'sess1'];
            })
            ->on('POST', '~^' . $base . '/Ticket/Search$~', function (array $request) {
                $body = FakeHttpClient::body($request);
                if (isset($body['TicketNumber'])) {
                    return $body['TicketNumber'] === $this->znunyTicket['TicketNumber'] ? ['TicketID' => [$this->znunyTicket['TicketID']]] : [];
                }

                return ['TicketID' => [$this->znunyTicket['TicketID']]];
            })
            ->on('GET', '~^' . $base . '/Ticket/\d+\?~', function (array $request) {
                if (($request['headers']['X-OTRS-Header-SessionID'] ?? '') !== 'sess1') {
                    return ['Error' => ['ErrorCode' => 'TicketGet.AuthFail', 'ErrorMessage' => 'TicketGet: Authorization failing!']];
                }
                if (!preg_match('~/Ticket/' . $this->znunyTicket['TicketID'] . '\?~', $request['url'])) {
                    return ['Error' => ['ErrorCode' => 'TicketGet.AccessDenied', 'ErrorMessage' => 'TicketGet: User does not have access to the ticket!']];
                }

                return ['Ticket' => [$this->znunyTicket]];
            })
            ->on('PATCH', '~^' . $base . '/Ticket/\d+$~', function (array $request) {
                if ($this->znunyUpdateHandler !== null) {
                    $response = ($this->znunyUpdateHandler)($request);
                    if ($response !== null) {
                        return $response;
                    }
                }

                return ['TicketID' => $this->znunyTicket['TicketID'], 'TicketNumber' => $this->znunyTicket['TicketNumber'], 'ArticleID' => '900'];
            });
    }

    private function registerZammad(): void
    {
        $base = preg_quote(self::ZAMMAD, '~');
        $this->http
            ->on('GET', '~^' . $base . '/api/v1/users/me$~', static function () {
                return ['id' => 2, 'login' => 'bridge@firma.example'];
            })
            ->on('GET', '~^' . $base . '/api/v1/groups\?~', function (array $request) {
                return strpos($request['url'], 'page=1') !== false ? $this->groups : [];
            })
            ->on('GET', '~^' . $base . '/api/v1/ticket_states\?~', static function (array $request) {
                if (strpos($request['url'], 'page=1') === false) {
                    return [];
                }

                return [
                    ['id' => 1, 'name' => 'new', 'state_type' => 'new'],
                    ['id' => 2, 'name' => 'open', 'state_type' => 'open'],
                    ['id' => 3, 'name' => 'pending reminder', 'state_type' => 'pending reminder'],
                    ['id' => 4, 'name' => 'closed', 'state_type' => 'closed'],
                    ['id' => 5, 'name' => 'merged', 'state_type' => 'merged'],
                    ['id' => 6, 'name' => 'pending close', 'state_type' => 'pending action'],
                ];
            })
            ->on('GET', '~^' . $base . '/api/v1/ticket_priorities\?~', static function (array $request) {
                if (strpos($request['url'], 'page=1') === false) {
                    return [];
                }

                return [['id' => 1, 'name' => '1 low'], ['id' => 2, 'name' => '2 normal'], ['id' => 3, 'name' => '3 high']];
            })
            ->on('GET', '~^' . $base . '/api/v1/users/search\?~', function (array $request) {
                parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
                $needle = mb_strtolower((string) ($query['query'] ?? ''));

                // Wie Zammad ohne Elasticsearch: Teiltreffer.
                return array_values(array_filter($this->users, static function (array $user) use ($needle): bool {
                    return $needle !== '' && (strpos(mb_strtolower((string) $user['email']), $needle) !== false || strpos(mb_strtolower((string) ($user['login'] ?? '')), $needle) !== false);
                }));
            })
            ->on('POST', '~^' . $base . '/api/v1/tickets$~', function (array $request) {
                $body = FakeHttpClient::body($request);
                if (($request['headers']['Authorization'] ?? '') !== 'Token token=tok123') {
                    return FakeHttpClient::json(['error' => "Can't find User for Token"], 401);
                }
                if (isset($body['owner_id']) && $body['owner_id'] === 666) {
                    return FakeHttpClient::json(['error' => "Invalid value '666' for field 'owner_id'!"], 422);
                }
                $id = 55 + count($this->tickets);
                $this->tickets[$id] = ['id' => $id, 'number' => (string) (31001 + count($this->tickets)), 'state_id' => $body['state_id']] + $body;
                if (isset($body['article'])) {
                    $this->storeArticle($id, $body['article']);
                }

                return FakeHttpClient::json($this->tickets[$id], 201);
            })
            ->on('POST', '~^' . $base . '/api/v1/ticket_articles$~', function (array $request) {
                $this->articleCalls++;
                if (isset($this->failArticleCall[$this->articleCalls])) {
                    return ($this->failArticleCall[$this->articleCalls])($request);
                }
                $body = FakeHttpClient::body($request);
                $article = $this->storeArticle((int) $body['ticket_id'], $body);
                // Kunden-Artikel setzen den Status in Zammad auf "open" zurueck.
                if (($body['sender'] ?? '') === 'Customer') {
                    $this->tickets[(int) $body['ticket_id']]['state_id'] = 2;
                }

                return FakeHttpClient::json($article, 201);
            })
            ->on('GET', '~^' . $base . '/api/v1/ticket_articles/by_ticket/\d+$~', function (array $request) {
                preg_match('~/(\d+)$~', $request['url'], $m);

                return array_values(array_filter($this->articles, static function (array $article) use ($m): bool {
                    return $article['ticket_id'] === (int) $m[1];
                }));
            })
            ->on('GET', '~^' . $base . '/api/v1/tickets/\d+$~', function (array $request) {
                preg_match('~/(\d+)$~', $request['url'], $m);

                return $this->tickets[(int) $m[1]] ?? FakeHttpClient::json(['error' => 'not found'], 404);
            })
            ->on('PUT', '~^' . $base . '/api/v1/tickets/\d+$~', function (array $request) {
                preg_match('~/(\d+)$~', $request['url'], $m);
                $this->tickets[(int) $m[1]] = FakeHttpClient::body($request) + $this->tickets[(int) $m[1]];

                return $this->tickets[(int) $m[1]];
            });
    }

    /**
     * @param array<string,mixed> $article
     *
     * @return array<string,mixed>
     */
    private function storeArticle(int $ticketId, array $article): array
    {
        $article = ['id' => 1000 + count($this->articles), 'ticket_id' => $ticketId] + $article;
        $this->articles[] = $article;

        return $article;
    }

    public static function error(int $status, string $message): HttpResponse
    {
        return FakeHttpClient::json(['error' => $message], $status);
    }
}
