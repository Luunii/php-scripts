<?php

declare(strict_types=1);

namespace Znuny2Zammad;

/**
 * Laedt die Konfiguration (PHP-Datei, die ein Array zurueckgibt) und
 * ergaenzt Standardwerte.
 */
final class Config
{
    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'znuny' => [
                'webservice_url' => '',
                'user'           => '',
                'password'       => '',
                'auth'           => 'session',
                'agent_url'      => '',
                'after_forward'  => [
                    'note'         => true,
                    'note_subject' => 'Ticket an Zammad weitergeleitet',
                    'state'        => null,
                    'queue'        => null,
                ],
            ],
            'zammad' => [
                'url'                     => '',
                'token'                   => '',
                'default_group'           => '',
                'group_map'               => [],
                'state'                   => null,
                'state_map'               => [
                    'new'                  => 'new',
                    'open'                 => 'open',
                    'pending reminder'     => 'pending reminder',
                    'pending auto close+'  => 'pending close',
                    'pending auto close-'  => 'pending close',
                    'closed successful'    => 'closed',
                    'closed unsuccessful'  => 'closed',
                ],
                'default_state'           => 'open',
                'priority_map'            => [
                    '1 very low'  => '1 low',
                    '2 low'       => '1 low',
                    '3 normal'    => '2 normal',
                    '4 high'      => '3 high',
                    '5 very high' => '3 high',
                ],
                'default_priority'        => '2 normal',
                'owner_map'               => [],
                'fallback_customer_email' => '',
                'create_customers'        => true,
                'tags'                    => ['znuny'],
                'tag_ticket_number'       => true,
                'title_prefix'            => '',
                'dynamic_field_map'       => [],
            ],
            'forward' => [
                'articles'            => 'all',
                'include_internal'    => true,
                'html_body'           => true,
                'attachments'         => true,
                'max_attachment_size' => 20 * 1024 * 1024,
                'article_header'      => true,
                'info_note'           => true,
            ],
            'batch' => [
                'queues'      => [],
                'state_types' => ['new', 'open'],
                'limit'       => 50,
            ],
            'state_file' => null,
            'lock_file'  => null,
            'log_file'   => null,
            'http'       => [
                'timeout'    => 120,
                'verify_ssl' => true,
                'ca_file'    => null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function load(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf(
                'Konfigurationsdatei "%s" nicht gefunden. Kopiere config.example.php nach config.php und passe sie an.',
                $file
            ));
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new \RuntimeException(sprintf('Die Konfigurationsdatei "%s" muss ein Array zurueckgeben.', $file));
        }

        $config = self::merge(self::defaults(), $config);
        self::validate($config);

        return $config;
    }

    /**
     * Rekursives Zusammenfuehren: assoziative Arrays (auch Mapping-Tabellen) werden gemischt,
     * Listen (z. B. tags, queues) aus der Konfiguration ersetzen die Standardwerte.
     *
     * @param array<string,mixed> $defaults
     * @param array<string,mixed> $override
     *
     * @return array<string,mixed>
     */
    public static function merge(array $defaults, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && isset($defaults[$key])
                && is_array($defaults[$key])
                && self::isAssoc($defaults[$key])
            ) {
                $defaults[$key] = self::merge($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function validate(array $config): void
    {
        $required = [
            'znuny.webservice_url'  => $config['znuny']['webservice_url'],
            'znuny.user'            => $config['znuny']['user'],
            'znuny.password'        => $config['znuny']['password'],
            'zammad.url'            => $config['zammad']['url'],
            'zammad.token'          => $config['zammad']['token'],
            'zammad.default_group'  => $config['zammad']['default_group'],
        ];
        $missing = [];
        foreach ($required as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException('Fehlende Konfigurationswerte: ' . implode(', ', $missing));
        }
        if (!in_array($config['znuny']['auth'], ['session', 'password'], true)) {
            throw new \RuntimeException('znuny.auth muss "session" oder "password" sein.');
        }
        if (!in_array($config['forward']['articles'], ['all', 'first', 'last'], true)) {
            throw new \RuntimeException('forward.articles muss "all", "first" oder "last" sein.');
        }
    }

    /**
     * @param array<mixed> $array
     */
    private static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
