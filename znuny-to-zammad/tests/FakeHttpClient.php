<?php

declare(strict_types=1);

namespace Znuny2Zammad\Tests;

use Znuny2Zammad\Http\HttpClient;
use Znuny2Zammad\Http\HttpResponse;

/**
 * HTTP-Client fuer Tests: beantwortet Anfragen anhand registrierter Routen
 * und zeichnet alle Anfragen auf.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<int,array{method:string,pattern:string,handler:callable}> */
    private $routes = [];

    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public $requests = [];

    /**
     * @param callable|array<mixed>|HttpResponse $response Handler(request): HttpResponse|array, oder feste Antwort
     */
    public function on(string $method, string $pattern, $response): self
    {
        $handler = is_callable($response) ? $response : static function () use ($response) {
            return $response;
        };
        // Neuere Registrierungen haben Vorrang.
        array_unshift($this->routes, ['method' => $method, 'pattern' => $pattern, 'handler' => $handler]);

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $request = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $this->requests[] = $request;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || !preg_match($route['pattern'], $url)) {
                continue;
            }
            $response = ($route['handler'])($request);
            if ($response instanceof HttpResponse) {
                return $response;
            }

            return self::json($response);
        }

        return new HttpResponse(404, sprintf('{"error":"FakeHttpClient: keine Route fuer %s %s"}', $method, $url));
    }

    /**
     * @param mixed $data
     */
    public static function json($data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($data));
    }

    /**
     * @return array<int,array{method:string,url:string,headers:array<string,string>,body:?string}>
     */
    public function requestsMatching(string $method, string $pattern): array
    {
        return array_values(array_filter($this->requests, static function (array $request) use ($method, $pattern): bool {
            return $request['method'] === $method && preg_match($pattern, $request['url']) === 1;
        }));
    }

    /**
     * @param array{body:?string} $request
     *
     * @return array<string,mixed>
     */
    public static function body(array $request): array
    {
        $data = json_decode((string) $request['body'], true);

        return is_array($data) ? $data : [];
    }
}
