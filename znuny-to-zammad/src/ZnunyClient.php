<?php

declare(strict_types=1);

namespace Znuny2Zammad;

use Znuny2Zammad\Http\CurlHttpClient;
use Znuny2Zammad\Http\HttpClient;
use Znuny2Zammad\Http\HttpResponse;

/**
 * Zugriff auf den Znuny-Webservice (GenericInterface, Transport HTTP::REST).
 *
 * Erwartet einen Webservice mit den Operationen SessionCreate, TicketGet,
 * TicketSearch und TicketUpdate (siehe znuny/Znuny2Zammad.yml).
 *
 * Besonderheiten der Znuny-API, die hier behandelt werden:
 *  - Fachliche Fehler kommen mit HTTP 200 und {"Error": {...}} zurueck.
 *  - Zugangsdaten werden als X-OTRS-Header-* gesendet, damit sie nicht in
 *    Webserver-Logs (Query-String) landen.
 *  - Request-Bodies muessen als application/json gesendet werden.
 *  - Listen in Query-Strings werden als wiederholte Schluessel uebergeben.
 */
final class ZnunyClient
{
    /** @var HttpClient */
    private $http;

    /** @var Logger */
    private $logger;

    /** @var string */
    private $webserviceUrl;

    /** @var string */
    private $user;

    /** @var string */
    private $password;

    /** @var string session|password */
    private $auth;

    /** @var array<string,array{0:string,1:string}> */
    private $routes;

    /** @var string|null */
    private $sessionId;

    /**
     * @param array<string,mixed> $config Abschnitt "znuny" der Konfiguration
     */
    public function __construct(HttpClient $http, Logger $logger, array $config)
    {
        $this->http          = $http;
        $this->logger        = $logger;
        $this->webserviceUrl = self::buildWebserviceUrl($config);
        $this->user          = (string) $config['user'];
        $this->password      = (string) $config['password'];
        $this->auth          = (string) $config['auth'];
        $this->routes        = self::normalizeRoutes($config['routes'] ?? []);
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function buildWebserviceUrl(array $config): string
    {
        if (!empty($config['webservice_url'])) {
            return rtrim((string) $config['webservice_url'], '/');
        }

        return rtrim((string) $config['base_url'], '/')
            . '/nph-genericinterface.pl/Webservice/'
            . rawurlencode((string) $config['webservice']);
    }

    /**
     * Link auf die Ticketansicht in Znuny (fuer Notizen in Zammad).
     *
     * @param array<string,mixed> $config
     */
    public static function agentTicketUrl(array $config, int $ticketId): string
    {
        if (empty($config['base_url'])) {
            return '';
        }

        return rtrim((string) $config['base_url'], '/') . '/index.pl?Action=AgentTicketZoom;TicketID=' . $ticketId;
    }

    /**
     * Liefert das Ticket mit allen Artikeln, Anhaengen und dynamischen Feldern.
     *
     * @return array<string,mixed>
     */
    public function getTicket(int $ticketId): array
    {
        $params = [
            'AllArticles'          => 1,
            'Attachments'          => 1,
            'DynamicFields'        => 1,
            'HTMLBodyAsAttachment' => 1,
        ];

        $data = $this->call('TicketGet', ['TicketID' => $ticketId], $params);
        if (!isset($data['Ticket'][0]) || !is_array($data['Ticket'][0])) {
            throw new ApiException(sprintf('Znuny: Ticket mit ID %d nicht gefunden.', $ticketId));
        }

        return $data['Ticket'][0];
    }

    /**
     * Sucht die TicketID zu einer Ticketnummer.
     */
    public function findTicketIdByNumber(string $ticketNumber): ?int
    {
        $ids = $this->searchTicketIds(['TicketNumber' => $ticketNumber, 'Limit' => 2]);
        if ($ids === []) {
            return null;
        }
        if (count($ids) > 1) {
            throw new ApiException(sprintf('Znuny: Ticketnummer "%s" ist nicht eindeutig.', $ticketNumber));
        }

        return $ids[0];
    }

    /**
     * @param array<string,mixed> $criteria Parameter der Znuny-Operation TicketSearch
     *
     * @return int[]
     */
    public function searchTicketIds(array $criteria): array
    {
        [$method] = $this->route('TicketSearch');
        if ($method === 'GET') {
            $data = $this->call('TicketSearch', [], $criteria);
        } else {
            $data = $this->call('TicketSearch', [], [], $criteria);
        }

        // Ohne Treffer liefert Znuny ein leeres Objekt {} ohne "TicketID".
        $ids = $data['TicketID'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_map('intval', $ids));
    }

    /**
     * Fuegt eine interne Notiz hinzu (fuer Kunden unsichtbar).
     */
    public function addInternalNote(int $ticketId, string $subject, string $body, bool $noAgentNotify = false): void
    {
        $article = [
            'CommunicationChannel' => 'Internal',
            // Standard in Znuny ist 1 (= fuer Kunden sichtbar), daher explizit 0.
            'IsVisibleForCustomer' => 0,
            'SenderType'           => 'agent',
            'Subject'              => $subject,
            'Body'                 => $body,
            'ContentType'          => 'text/plain; charset=utf-8',
            'HistoryType'          => 'AddNote',
            'HistoryComment'       => 'An Zammad weitergeleitet',
        ];
        if ($noAgentNotify) {
            $article['NoAgentNotify'] = 1;
        }

        $this->call('TicketUpdate', ['TicketID' => $ticketId], [], ['Article' => $article]);
    }

    /**
     * Aendert Queue und/oder Status eines Tickets.
     *
     * @param array<string,mixed> $ticketFields z. B. ['Queue' => 'Weitergeleitet', 'State' => 'closed successful']
     */
    public function updateTicket(int $ticketId, array $ticketFields): void
    {
        if ($ticketFields === []) {
            return;
        }
        $this->call('TicketUpdate', ['TicketID' => $ticketId], [], ['Ticket' => $ticketFields]);
    }

    /**
     * Fuehrt eine Webservice-Operation aus.
     *
     * @param array<string,scalar>    $routeParams Platzhalter in der Route, z. B. TicketID
     * @param array<string,mixed>     $query       Query-Parameter
     * @param array<string,mixed>|null $body       JSON-Body (null = kein Body)
     *
     * @return array<string,mixed>
     */
    private function call(string $operation, array $routeParams, array $query = [], ?array $body = null, bool $retried = false): array
    {
        [$method, $route] = $this->route($operation);

        $path = preg_replace_callback('/:(\w+)/', static function (array $m) use ($routeParams, $route): string {
            if (!array_key_exists($m[1], $routeParams)) {
                throw new \LogicException(sprintf('Parameter %s fuer Route %s fehlt.', $m[1], $route));
            }

            return rawurlencode((string) $routeParams[$m[1]]);
        }, $route);

        $headers = ['Accept' => 'application/json'];
        $headers += $this->authHeaders();

        $url = $this->webserviceUrl . $path;
        if ($query !== []) {
            $url .= '?' . self::buildQuery($query);
        }

        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
            $payload = self::encodeJson($body);
        }

        $this->logger->debug(sprintf('Znuny: %s %s', $method, CurlHttpClient::redact($url)));
        $response = $this->http->request($method, $url, $headers, $payload);
        $data     = $this->decode($operation, $response);

        if (isset($data['Error'])) {
            $code    = (string) ($data['Error']['ErrorCode'] ?? 'Unbekannt');
            $message = (string) ($data['Error']['ErrorMessage'] ?? '');

            // Abgelaufene Session: einmal neu anmelden und wiederholen.
            if (!$retried && $this->auth === 'session' && $this->sessionId !== null && substr($code, -9) === '.AuthFail') {
                $this->logger->debug('Znuny: Session abgelaufen, melde neu an.');
                $this->sessionId = null;

                return $this->call($operation, $routeParams, $query, $body, true);
            }

            throw new ApiException(sprintf('Znuny %s: %s %s', $operation, $code, $message));
        }

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(string $operation, HttpResponse $response): array
    {
        if ($response->status !== 200) {
            $text = trim(strip_tags($response->body));
            if ($response->status === 500 && $text === '') {
                $text = 'Keine Antwort. Ist der Webservice-Name korrekt und der Webservice gueltig?';
            }

            throw new ApiException(sprintf(
                'Znuny %s: HTTP %d %s',
                $operation,
                $response->status,
                mb_substr($text, 0, 500)
            ));
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new ApiException(sprintf('Znuny %s: Antwort ist kein gueltiges JSON: %s', $operation, mb_substr($response->body, 0, 200)));
        }

        return $data;
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(): array
    {
        if ($this->auth === 'password') {
            return [
                'X-OTRS-Header-UserLogin' => $this->user,
                'X-OTRS-Header-Password'  => $this->password,
            ];
        }

        return ['X-OTRS-Header-SessionID' => $this->sessionId()];
    }

    private function sessionId(): string
    {
        if ($this->sessionId !== null) {
            return $this->sessionId;
        }

        [$method, $route] = $this->route('SessionCreate');
        $response = $this->http->request(
            $method,
            $this->webserviceUrl . $route,
            ['Accept' => 'application/json', 'Content-Type' => 'application/json; charset=utf-8'],
            self::encodeJson(['UserLogin' => $this->user, 'Password' => $this->password])
        );
        $data = $this->decode('SessionCreate', $response);

        if (isset($data['Error']) || empty($data['SessionID'])) {
            // Absichtlich kein automatischer Wiederholungsversuch: Znuny sperrt Agenten nach
            // zu vielen Fehlanmeldungen (PasswordMaxLoginFailed).
            throw new ApiException(sprintf(
                'Znuny: Anmeldung als "%s" fehlgeschlagen (%s). Benutzername/Passwort pruefen.',
                $this->user,
                (string) ($data['Error']['ErrorMessage'] ?? 'keine SessionID erhalten')
            ));
        }
        $this->sessionId = (string) $data['SessionID'];

        return $this->sessionId;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function route(string $operation): array
    {
        if (!isset($this->routes[$operation])) {
            throw new \LogicException(sprintf('Keine Route fuer Znuny-Operation %s konfiguriert.', $operation));
        }

        return $this->routes[$operation];
    }

    /**
     * @param array<string,mixed> $configured
     *
     * @return array<string,array{0:string,1:string}>
     */
    private static function normalizeRoutes(array $configured): array
    {
        // Entspricht znuny/Znuny2Zammad.yml bzw. dem Beispiel-Webservice
        // "GenericTicketConnectorREST" aus der Znuny-Oberflaeche.
        $routes = [
            'SessionCreate' => ['POST', '/Session'],
            'TicketGet'     => ['GET', '/Ticket/:TicketID'],
            'TicketSearch'  => ['POST', '/Ticket/Search'],
            'TicketUpdate'  => ['PATCH', '/Ticket/:TicketID'],
        ];
        foreach ($configured as $operation => $route) {
            if (is_string($route)) {
                // Kurzform "POST /Ticket/Search"
                $route = preg_split('/\s+/', trim($route), 2) ?: [];
            }
            if (!is_array($route) || count($route) !== 2) {
                throw new \RuntimeException(sprintf('Ungueltige Route fuer %s, erwartet z. B. ["POST", "/Ticket/Search"].', $operation));
            }
            $routes[$operation] = [strtoupper((string) $route[0]), '/' . ltrim((string) $route[1], '/')];
        }

        return $routes;
    }

    /**
     * Baut einen Query-String so, wie ihn der Znuny-REST-Transport versteht:
     * Listen als wiederholte Schluessel, alles mit %20-Kodierung.
     *
     * @param array<string,mixed> $params
     */
    public static function buildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            foreach ((array) $value as $item) {
                if (is_bool($item)) {
                    $item = $item ? 1 : 0;
                }
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new ApiException('JSON-Kodierung fehlgeschlagen: ' . json_last_error_msg());
        }

        return $json;
    }
}
