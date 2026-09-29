<?php

declare(strict_types=1);

namespace Znuny2Zammad\Http;

final class HttpResponse
{
    /** @var int */
    public $status;

    /** @var string */
    public $body;

    public function __construct(int $status, string $body)
    {
        $this->status = $status;
        $this->body   = $body;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Dekodiert den Body als JSON. Liefert null, wenn der Body kein gueltiges JSON ist.
     *
     * @return mixed|null
     */
    public function json()
    {
        if (trim($this->body) === '') {
            return null;
        }
        $data = json_decode($this->body, true);

        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }
}
