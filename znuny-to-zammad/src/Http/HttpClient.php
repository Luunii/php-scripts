<?php

declare(strict_types=1);

namespace Znuny2Zammad\Http;

/**
 * Minimale HTTP-Abstraktion, damit die API-Clients ohne echtes Netzwerk
 * getestet werden koennen.
 */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
