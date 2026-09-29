<?php

declare(strict_types=1);

namespace Znuny2Zammad;

/**
 * Hilfsfunktionen fuer E-Mail-Adresszeilen wie
 * "Max Mustermann <max@example.com>, \"Mueller, Hans\" <hans@example.com>".
 */
final class EmailAddress
{
    /**
     * Zerlegt eine Adressliste. Kommas innerhalb von Anfuehrungszeichen oder
     * spitzen Klammern trennen keine Adressen.
     *
     * @return array<int,array{name:string,email:string}>
     */
    public static function parseList(string $list): array
    {
        $result = [];
        foreach (self::splitList($list) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^<>]*)>\s*$/s', $part, $m)) {
                $name  = trim($m[1]);
                $email = trim($m[2]);
            } else {
                $name  = '';
                $email = $part;
            }
            $name = trim($name, " \t\"'");
            $name = str_replace(['\\"', '\\\\'], ['"', '\\'], $name);
            $result[] = ['name' => $name, 'email' => $email];
        }

        return $result;
    }

    /**
     * Liefert die erste gueltige Adresse einer Liste.
     *
     * @return array{name:string,email:string}|null
     */
    public static function first(string $list): ?array
    {
        foreach (self::parseList($list) as $address) {
            if (self::isValid($address['email'])) {
                return ['name' => $address['name'], 'email' => mb_strtolower($address['email'])];
            }
        }

        return null;
    }

    /**
     * true, wenn die Liste mindestens eine Adresse enthaelt und alle gueltig sind
     * (so prueft Zammad Empfaenger von E-Mail-Artikeln).
     */
    public static function isValidList(string $list): bool
    {
        $addresses = self::parseList($list);
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (!self::isValid($address['email'])) {
                return false;
            }
        }

        return true;
    }

    public static function isValid(string $email): bool
    {
        $email = trim($email);
        if ($email === '' || strpos($email, '@') === false) {
            return false;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false) {
            return true;
        }
        // Umlaut-Domains (IDN) in Punycode umwandeln und erneut pruefen.
        $at = strrpos($email, '@');
        $domain = substr($email, $at + 1);
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== $domain) {
                return filter_var(substr($email, 0, $at) . '@' . $ascii, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false;
            }
        }

        return false;
    }

    /**
     * Teilt einen Anzeigenamen in Vor- und Nachname.
     * "Max Mustermann" => [Max, Mustermann], "Mustermann, Max" => [Max, Mustermann].
     *
     * @return array{0:string,1:string}
     */
    public static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || strpos($name, '@') !== false) {
            return ['', ''];
        }
        if (strpos($name, ',') !== false) {
            [$last, $first] = array_map('trim', explode(',', $name, 2));

            return [$first, $last];
        }
        $pos = mb_strrpos($name, ' ');
        if ($pos === false) {
            return [$name, ''];
        }

        return [mb_substr($name, 0, $pos), mb_substr($name, $pos + 1)];
    }

    /**
     * @return string[]
     */
    private static function splitList(string $list): array
    {
        $parts   = [];
        $current = '';
        $quoted  = false;
        $angle   = false;
        $length  = strlen($list);
        for ($i = 0; $i < $length; $i++) {
            $char = $list[$i];
            if ($char === '\\' && $quoted && $i + 1 < $length) {
                $current .= $char . $list[++$i];
                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
            } elseif ($char === '<' && !$quoted) {
                $angle = true;
            } elseif ($char === '>' && !$quoted) {
                $angle = false;
            } elseif (($char === ',' || $char === ';') && !$quoted && !$angle) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }
}
