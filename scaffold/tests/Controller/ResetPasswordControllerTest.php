<?php

namespace App\Tests\Controller;

// This controller only keeps the signed reset-token link, the request itself being covered elsewhere
class ResetPasswordControllerTest extends FunctionalTestCase
{
    // No token in session, the redirect having consumed it and the session then expired
    public function testResetThrowsNotFoundWhenNoTokenIsStoredInSession(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/reset-password/reset');

        $this->assertResponseStatusCodeSame(404);
    }

    // A token in the URL is stored in session and stripped from the URL via redirect, before ever being validated
    public function testResetWithATokenInTheUrlRedirectsToStripIt(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/reset-password/reset/some-token');

        $this->assertResponseRedirects('/reset-password/reset');
    }

    // A token that was never really issued by ResetPasswordHelper (e.g. tampered/expired) fails validateTokenAndFetchUser() - the visitor is sent back to the login form, where the "forgot password" link starts the flow over
    public function testResetRedirectsToLoginWhenTokenIsInvalid(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/reset-password/reset/some-invalid-token');
        $client->request('GET', '/reset-password/reset');

        $this->assertResponseRedirects('/login');
    }
}
