<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PageControllerTest extends WebTestCase
{
    public function testHomePageReturns200WithoutAuthentication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');

        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorTextContains('h1', 'Support');
    }

    public function testLoginPageReturns200WithoutAuthentication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login');

        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorExists('#login-form');
    }
}
