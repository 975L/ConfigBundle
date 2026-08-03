<?php

namespace App\Tests\Controller;

// Registration itself (the "register" Form/FormAction) is covered by RegisterFormActionTest - this controller only keeps the signed email-verification link, see RegistrationController
class RegistrationControllerTest extends FunctionalTestCase
{
    // Every outcome lands on the login form, a route this scaffold owns itself: it is the only target that exists whether or not a site foundation is installed alongside
    public function testVerifyUserEmailRedirectsToLoginWhenIdIsMissing(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/verification/email');

        $this->assertResponseRedirects('/login');
    }

    public function testVerifyUserEmailRedirectsToLoginWhenUserIsNotFound(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/verification/email?id=999999');

        $this->assertResponseRedirects('/login');
    }
}
