<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Entity\Redirect;
use c975L\ConfigBundle\Management\RedirectChainHealthCheckProvider;
use c975L\ConfigBundle\Repository\RedirectRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class RedirectChainHealthCheckProviderTest extends TestCase
{
    private function createRedirect(string $fromPath, string $toUrl): Redirect
    {
        return (new Redirect())->setFromPath($fromPath)->setToUrl($toUrl);
    }

    private function createRepository(array $redirects): RedirectRepository
    {
        $repository = $this->createStub(RedirectRepository::class);
        $repository->method('findAll')->willReturn($redirects);

        return $repository;
    }

    // A real resolver over a stubbed config, so the trailing-slash normalisation the row urls rely on is exercised rather than stubbed away
    private function createSiteUrlResolver(?string $siteUrl): SiteUrlResolver
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($siteUrl);

        return new SiteUrlResolver($configService);
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $parameters = []) => strtr($id, $parameters)
        );

        return $translator;
    }

    public function testGetKindReturnsRedirectChains(): void
    {
        $provider = new RedirectChainHealthCheckProvider($this->createRepository([]), $this->createSiteUrlResolver(null), $this->createTranslator());

        $this->assertSame('redirect-chains', $provider->getKind());
    }

    public function testRunChecksReturnsEmptyArrayWithoutASiteUrl(): void
    {
        $provider = new RedirectChainHealthCheckProvider(
            $this->createRepository([$this->createRedirect('/old', '/new')]),
            $this->createSiteUrlResolver(null),
            $this->createTranslator(),
        );

        $this->assertSame([], $provider->runChecks());
    }

    public function testRunChecksStatusIsOkForADirectRedirect(): void
    {
        $redirect = $this->createRedirect('/old', '/new');

        $provider = new RedirectChainHealthCheckProvider($this->createRepository([$redirect]), $this->createSiteUrlResolver('https://example.com'), $this->createTranslator());

        $result = $provider->runChecks()[0];
        $this->assertSame(HealthCheckResult::STATUS_OK, $result['status']);
        $this->assertSame('https://example.com/old', $result['url']);
        $this->assertSame(['hops' => 0, 'loop' => false], $result['details']);
    }

    public function testRunChecksStatusIsOkWhenTargetIsAnAbsoluteExternalUrl(): void
    {
        $redirect = $this->createRedirect('/old', 'https://elsewhere.com/page');

        $provider = new RedirectChainHealthCheckProvider($this->createRepository([$redirect]), $this->createSiteUrlResolver('https://example.com'), $this->createTranslator());

        $this->assertSame(HealthCheckResult::STATUS_OK, $provider->runChecks()[0]['status']);
    }

    public function testRunChecksStatusIsWarningWhenChainingThroughAnotherRedirect(): void
    {
        $first = $this->createRedirect('/old', '/middle');
        $second = $this->createRedirect('/middle', '/new');

        $provider = new RedirectChainHealthCheckProvider($this->createRepository([$first, $second]), $this->createSiteUrlResolver('https://example.com'), $this->createTranslator());

        $results = $provider->runChecks();
        $this->assertSame(HealthCheckResult::STATUS_WARNING, $results[0]['status']);
        $this->assertSame(1, $results[0]['details']['hops']);
        // /middle -> /new is itself a direct redirect, not part of a chain from its own point of view
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[1]['status']);
    }

    public function testRunChecksStatusIsErrorForATwoStepLoop(): void
    {
        $first = $this->createRedirect('/a', '/b');
        $second = $this->createRedirect('/b', '/a');

        $provider = new RedirectChainHealthCheckProvider($this->createRepository([$first, $second]), $this->createSiteUrlResolver('https://example.com'), $this->createTranslator());

        $results = $provider->runChecks();
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[0]['status']);
        $this->assertTrue($results[0]['details']['loop']);
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[1]['status']);
    }

    public function testRunChecksStatusIsErrorForASelfLoop(): void
    {
        $redirect = $this->createRedirect('/loop', '/loop');

        $provider = new RedirectChainHealthCheckProvider($this->createRepository([$redirect]), $this->createSiteUrlResolver('https://example.com'), $this->createTranslator());

        $result = $provider->runChecks()[0];
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $result['status']);
        $this->assertTrue($result['details']['loop']);
    }

    // A "gone" row carries no target at all: the chain ends there, it isn't a chain and it certainly isn't a crash
    public function testRunChecksStatusIsOkForAGoneRedirect(): void
    {
        $gone = (new Redirect())->setFromPath('/removed')->setGone(true);

        $provider = new RedirectChainHealthCheckProvider($this->createRepository([$gone]), $this->createSiteUrlResolver('https://example.com'), $this->createTranslator());

        $result = $provider->runChecks()[0];
        $this->assertSame(HealthCheckResult::STATUS_OK, $result['status']);
        $this->assertSame(['hops' => 0, 'loop' => false], $result['details']);
    }

    // A redirect landing on a "gone" url is still one hop, and the walk stops there rather than going on
    public function testRunChecksStopsTheChainOnAGoneTarget(): void
    {
        $redirect = $this->createRedirect('/old', '/removed');
        $gone = (new Redirect())->setFromPath('/removed')->setGone(true);

        $provider = new RedirectChainHealthCheckProvider($this->createRepository([$redirect, $gone]), $this->createSiteUrlResolver('https://example.com'), $this->createTranslator());

        $results = $provider->runChecks();
        $this->assertSame(HealthCheckResult::STATUS_WARNING, $results[0]['status']);
        $this->assertSame(1, $results[0]['details']['hops']);
        $this->assertFalse($results[0]['details']['loop']);
    }
}
