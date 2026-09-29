<?php

declare(strict_types=1);

namespace Znuny2Zammad\Http;

use Znuny2Zammad\ApiException;

final class CurlHttpClient implements HttpClient
{
    /** @var int */
    private $timeout;

    /** @var bool */
    private $verifySsl;

    /** @var string|null */
    private $caFile;

    public function __construct(int $timeout = 120, bool $verifySsl = true, ?string $caFile = null)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Die PHP-Erweiterung "curl" wird benoetigt.');
        }
        $this->timeout   = $timeout;
        $this->verifySsl = $verifySsl;
        $this->caFile    = $caFile !== '' ? $caFile : null;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiException(sprintf('curl_init fuer %s fehlgeschlagen', $url));
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // Verhindert "Expect: 100-continue"-Verzoegerungen bei grossen Anhaengen.
        $headerLines[] = 'Expect:';

        $options = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(30, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_USERAGENT      => 'znuny2zammad/1.0',
        ];
        if ($this->caFile !== null) {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new ApiException(sprintf('HTTP-Anfrage %s %s fehlgeschlagen (curl %d): %s', $method, self::redact($url), $errno, $error));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new HttpResponse($status, (string) $responseBody);
    }

    /**
     * Entfernt Zugangsdaten aus URLs, bevor sie in Fehlermeldungen landen.
     */
    public static function redact(string $url): string
    {
        return (string) preg_replace('/((?:Password|SessionID|AccessToken)=)[^&]*/i', '$1***', $url);
    }
}
