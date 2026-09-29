<?php

declare(strict_types=1);

namespace Znuny2Zammad;

use Znuny2Zammad\Http\HttpClient;

/**
 * Zugriff auf die Zammad-REST-API (Authentifizierung per API-Token).
 *
 * Der Token braucht die Berechtigung "ticket.agent", der zugehoerige
 * Benutzer "create"-Rechte in den Zielgruppen.
 */
final class ZammadClient
{
    /** @var HttpClient */
    private $http;

    /** @var Logger */
    private $logger;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $token;

    /** @var array<int,array<string,mixed>>|null */
    private $groups;

    /** @var array<int,array<string,mixed>>|null */
    private $states;

    /** @var array<int,array<string,mixed>>|null */
    private $priorities;

    public function __construct(HttpClient $http, Logger $logger, string $baseUrl, string $token)
    {
        $this->http    = $http;
        $this->logger  = $logger;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
    }

    public function ticketUrl(int $ticketId): string
    {
        return $this->baseUrl . '/#ticket/zoom/' . $ticketId;
    }

    /**
     * @return array<string,mixed>
     */
    public function me(): array
    {
        return (array) $this->request('GET', '/api/v1/users/me');
    }

    /**
     * Sucht eine Gruppe ueber ihren Namen. Verschachtelte Gruppen heissen in der API
     * "Eltern::Kind" (in der Oberflaeche "Eltern › Kind"); beide Schreibweisen gehen.
     *
     * @return array<string,mixed>|null
     */
    public function findGroup(string $name): ?array
    {
        $wanted = self::normalizeGroupName($name);
        foreach ($this->groups() as $group) {
            if (self::normalizeGroupName((string) $group['name']) === $wanted) {
                return $group;
            }
        }
        // Zweiter Versuch ohne Beachtung der Gross-/Kleinschreibung.
        foreach ($this->groups() as $group) {
            if (mb_strtolower(self::normalizeGroupName((string) $group['name'])) === mb_strtolower($wanted)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null inkl. "state_type" (Name des Statustyps)
     */
    public function findState(string $name): ?array
    {
        if ($this->states === null) {
            $this->states = $this->listAll('/api/v1/ticket_states', ['expand' => 'true']);
        }

        return self::findByName($this->states, $name);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findPriority(string $name): ?array
    {
        if ($this->priorities === null) {
            $this->priorities = $this->listAll('/api/v1/ticket_priorities');
        }

        return self::findByName($this->priorities, $name);
    }

    /**
     * Sucht einen Benutzer mit exakt dieser E-Mail-Adresse.
     *
     * Die Zammad-Suche liefert Teiltreffer (ohne Elasticsearch z. B. auch
     * "joanna@..." fuer "anna@..."), daher wird hier exakt nachgefiltert.
     *
     * @return array<string,mixed>|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return $this->pickUser($this->searchUsers($email), static function (array $user) use ($email): bool {
            return mb_strtolower(trim((string) ($user['email'] ?? ''))) === $email;
        });
    }

    /**
     * Sucht einen Benutzer ueber Login oder E-Mail (z. B. fuer die Besitzer-Zuordnung).
     *
     * @return array<string,mixed>|null
     */
    public function findUserByLoginOrEmail(string $loginOrEmail): ?array
    {
        $value = mb_strtolower(trim($loginOrEmail));
        if ($value === '') {
            return null;
        }

        return $this->pickUser($this->searchUsers($value), static function (array $user) use ($value): bool {
            return mb_strtolower((string) ($user['login'] ?? '')) === $value
                || mb_strtolower(trim((string) ($user['email'] ?? ''))) === $value;
        });
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public function createTicket(array $payload): array
    {
        return (array) $this->request('POST', '/api/v1/tickets', $payload);
    }

    /**
     * @param array<string,mixed> $payload muss "ticket_id" enthalten
     *
     * @return array<string,mixed>
     */
    public function createArticle(array $payload, bool $suppressNotifications = false): array
    {
        $headers = [];
        if ($suppressNotifications) {
            // Ab Zammad 7.2: keine Agenten-Benachrichtigungen fuer diese Anfrage.
            // Aeltere Versionen ignorieren den Header.
            $headers['X-Zammad-Suppress-Notifications'] = 'true';
        }

        return (array) $this->request('POST', '/api/v1/ticket_articles', $payload, $headers);
    }

    /**
     * @return array<string,mixed>
     */
    public function getTicket(int $ticketId): array
    {
        return (array) $this->request('GET', '/api/v1/tickets/' . $ticketId);
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public function updateTicket(int $ticketId, array $payload): array
    {
        return (array) $this->request('PUT', '/api/v1/tickets/' . $ticketId, $payload);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function ticketArticles(int $ticketId): array
    {
        $data = $this->request('GET', '/api/v1/ticket_articles/by_ticket/' . $ticketId);

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function groups(): array
    {
        if ($this->groups === null) {
            $this->groups = $this->listAll('/api/v1/groups');
        }

        return $this->groups;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchUsers(string $query): array
    {
        $data = $this->request('GET', '/api/v1/users/search?' . http_build_query(
            ['query' => $query, 'limit' => 100],
            '',
            '&',
            PHP_QUERY_RFC3986
        ));

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * @param array<int,array<string,mixed>> $users
     *
     * @return array<string,mixed>|null
     */
    private function pickUser(array $users, callable $matches): ?array
    {
        $found = null;
        foreach ($users as $user) {
            if (!$matches($user)) {
                continue;
            }
            if (!empty($user['active'])) {
                return $user;
            }
            $found = $found ?? $user;
        }

        return $found;
    }

    /**
     * Laedt alle Eintraege einer Listen-Ressource seitenweise.
     *
     * @param array<string,string> $params
     *
     * @return array<int,array<string,mixed>>
     */
    private function listAll(string $path, array $params = []): array
    {
        $perPage = 500;
        $all     = [];
        for ($page = 1; $page <= 100; $page++) {
            $query = http_build_query($params + ['per_page' => $perPage, 'page' => $page], '', '&', PHP_QUERY_RFC3986);
            $data  = $this->request('GET', $path . '?' . $query);
            if (!is_array($data) || $data === []) {
                break;
            }
            foreach ($data as $item) {
                if (is_array($item)) {
                    $all[] = $item;
                }
            }
            if (count($data) < $perPage) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<string,mixed>|null
     */
    private static function findByName(array $items, string $name): ?array
    {
        foreach ($items as $item) {
            if ((string) ($item['name'] ?? '') === $name) {
                return $item;
            }
        }
        foreach ($items as $item) {
            if (mb_strtolower((string) ($item['name'] ?? '')) === mb_strtolower($name)) {
                return $item;
            }
        }

        return null;
    }

    private static function normalizeGroupName(string $name): string
    {
        $parts = preg_split('/\s*(?:::|›)\s*/u', trim($name)) ?: [$name];

        return implode('::', $parts);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,string>     $extraHeaders
     *
     * @return mixed
     */
    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = [])
    {
        // Wichtig: niemals einen "From"-Header senden - Zammad wertet ihn als
        // "im Namen von"-Anfrage (Impersonation).
        $headers = [
            'Authorization' => 'Token token=' . $this->token,
            'Accept'        => 'application/json',
        ] + $extraHeaders;

        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($payload === false) {
                throw new ApiException('JSON-Kodierung fehlgeschlagen: ' . json_last_error_msg());
            }
        }

        $this->logger->debug(sprintf('Zammad: %s %s', $method, $path));
        $response = $this->http->request($method, $this->baseUrl . $path, $headers, $payload);

        if (!$response->isSuccess()) {
            $data    = $response->json();
            $message = '';
            if (is_array($data)) {
                $message = (string) ($data['error_human'] ?? $data['error'] ?? '');
            }
            if ($message === '') {
                $message = mb_substr(trim(strip_tags($response->body)), 0, 300);
            }
            if ($response->status === 413) {
                $message = 'Anfrage zu gross (Anhaenge?). ' . $message;
            }

            throw new ApiException(sprintf('Zammad %s %s: HTTP %d %s', $method, strtok($path, '?'), $response->status, $message), $response->status);
        }

        return $response->json();
    }
}
