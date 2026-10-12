<?php

declare(strict_types=1);

namespace Znuny2Zammad\Tests;

use Znuny2Zammad\EmailAddress;

final class EmailAddressTest extends TestCase
{
    public function testParseList(): void
    {
        $list = EmailAddress::parseList('"Mueller, Hans" <hans@example.com>, max@example.com; Erika Muster <erika@example.com>');
        $this->assertSame([
            ['name' => 'Mueller, Hans', 'email' => 'hans@example.com'],
            ['name' => '', 'email' => 'max@example.com'],
            ['name' => 'Erika Muster', 'email' => 'erika@example.com'],
        ], $list);
    }

    public function testIsValidList(): void
    {
        $this->assertTrue(EmailAddress::isValidList('"Mueller, Hans" <hans@example.com>, max@example.com'));
        $this->assertTrue(EmailAddress::isValidList('info@müller.example'));
        $this->assertFalse(EmailAddress::isValidList(''));
        $this->assertFalse(EmailAddress::isValidList('undisclosed-recipients:;'));
        $this->assertFalse(EmailAddress::isValidList('Mueller, Hans <hans@example.com>'), 'unquotiertes Komma trennt Adressen');
    }

    public function testFirst(): void
    {
        $this->assertSame(['name' => 'Max', 'email' => 'max@example.com'], EmailAddress::first('kaputt, Max <MAX@example.com>'));
        $this->assertSame(null, EmailAddress::first('niemand'));
    }

    public function testSplitName(): void
    {
        $this->assertSame(['Max', 'Mustermann'], EmailAddress::splitName('Max Mustermann'));
        $this->assertSame(['Anna Maria', 'Schmidt'], EmailAddress::splitName('Anna Maria Schmidt'));
        $this->assertSame(['Max', 'Mustermann'], EmailAddress::splitName('Mustermann, Max'));
        $this->assertSame(['Support', ''], EmailAddress::splitName('Support'));
        $this->assertSame(['', ''], EmailAddress::splitName('max@example.com'));
    }
}
