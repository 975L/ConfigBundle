<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Security;

use Nelmio\SecurityBundle\ContentSecurityPolicy\NonceGeneratorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

// NelmioSecurityBundle's default generator returns a fresh random nonce per request, but Turbo Drive/Frames/Streams re-merge/re-execute <script> tags from fetched HTML into the already-loaded document without a real navigation, so a per-request nonce mismatches the CSP header the browser already enforces and every re-executed script gets blocked - storing the nonce in session keeps it stable for the whole visit, same fix as turbo-rails documents for this exact issue
class SessionNonceGenerator implements NonceGeneratorInterface
{
    private const SESSION_KEY = 'csp_nonce';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function generate(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        // An error page must never start a session. A 404 raised by the router is thrown before the firewall runs, so the session has not been loaded yet at that point: starting one here creates a brand new session, whose cookie overwrites the one the visitor is already carrying - an authenticated visitor is signed out by the very next click, and every dead url a crawler hits leaves an empty session row behind. Sub-requests rendering an error page are the ones carrying the "exception" attribute (see Symfony's ErrorListener::duplicateRequest), and they need no stable nonce anyway, as an error page is never part of a Turbo visit
        if (!$request?->hasSession() || $request->attributes->has('exception')) {
            return $this->randomNonce();
        }

        $session = $this->requestStack->getSession();

        $nonce = $session->get(self::SESSION_KEY);
        if (null === $nonce) {
            // Storing an attribute (rather than deriving from the session id) also matters here: Symfony's SessionListener only sends the session cookie for a non-empty session, and start()-ing without ever writing to it leaves the session empty
            $nonce = $this->randomNonce();
            $session->set(self::SESSION_KEY, $nonce);
        }

        return $nonce;
    }

    // Same random nonce format for both the session-backed and session-less (fallback) paths
    private function randomNonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
