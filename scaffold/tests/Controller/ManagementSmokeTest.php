<?php

namespace App\Tests\Controller;

use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;

// The management interface is assembled at runtime from every installed c975L bundle: menu entries, essential actions, shortcuts, the guided tour and the guided projects are all contributed by providers, and no single bundle sees the whole. Each bundle checks its own providers in isolation (see ConfigBundle's ManagementTargetsTest); only the site running them together can tell whether the assembled dashboard actually answers. This walks it the way an admin would, following every link it renders - so a bundle upgrade that breaks a screen fails here rather than in front of the user.
class ManagementSmokeTest extends FunctionalTestCase
{
    // Never guessed as a literal: a site is free to mount the dashboard elsewhere, and every path below is compared against this one
    private function dashboardPath(): string
    {
        return static::getContainer()->get('router')->generate('management');
    }

    public function testTheDashboardRenders(): void
    {
        $client = $this->createAuthenticatedClient(['ROLE_SUPER_ADMIN']);
        $client->request('GET', $this->dashboardPath());

        $this->assertResponseIsSuccessful();
    }

    // Every menu entry, essential action and GET shortcut the dashboard renders, followed one by one - this is what catches a renamed route or a template broken by a bundle upgrade, whichever bundle contributed it
    public function testEveryLinkTheDashboardRendersAnswers(): void
    {
        $client = $this->createAuthenticatedClient(['ROLE_SUPER_ADMIN']);
        $crawler = $client->request('GET', $this->dashboardPath());
        $this->assertResponseIsSuccessful();

        $dashboardPath = $this->dashboardPath();
        $paths = array_unique($crawler->filter('a[href]')->each(static fn ($node) => $node->attr('href')));
        // Only what stays inside the admin: the sidebar also links to the site itself, and a dead front page is another test's business
        $paths = array_filter($paths, static fn (string $path) => str_starts_with($path, $dashboardPath));

        // A selector that stops matching (an EasyAdmin upgrade renaming its sidebar markup) would otherwise turn this into a test asserting nothing
        $this->assertGreaterThan(3, \count($paths), 'The dashboard rendered almost no internal link, which means this test is walking an empty page');

        foreach ($paths as $path) {
            $client->request('GET', $path);
            $this->assertLessThan(
                400,
                $client->getResponse()->getStatusCode(),
                sprintf('The dashboard links to "%s", which answered %d', $path, $client->getResponse()->getStatusCode()),
            );
        }
    }

    // A shortcut tile posts to its route (clearing the cache, toggling maintenance...): the form is checked against the router rather than submitted, since every one of them does something to the site
    public function testEveryShortcutFormTargetsARouteAcceptingItsPost(): void
    {
        $client = $this->createAuthenticatedClient(['ROLE_SUPER_ADMIN']);
        $crawler = $client->request('GET', $this->dashboardPath());

        $dashboardPath = $this->dashboardPath();
        // Read off every form rather than filtered by a 'form[method="post"]' selector, which DomCrawler matches case-sensitively - EasyAdmin spells that attribute its own way
        $actions = $crawler->filter('form')->each(
            static fn ($node) => 'post' === strtolower((string) $node->attr('method')) ? $node->attr('action') : null
        );
        $actions = array_filter(array_unique($actions), static fn (?string $action) => \is_string($action) && str_starts_with($action, $dashboardPath));

        $this->assertNotEmpty($actions, 'The dashboard rendered no shortcut form at all, which means this test is walking an empty page');

        $matcher = new UrlMatcher(static::getContainer()->get('router')->getRouteCollection(), new RequestContext('', 'POST'));
        foreach ($actions as $action) {
            try {
                $this->assertArrayHasKey('_route', $matcher->match(parse_url($action, \PHP_URL_PATH)));
            } catch (ResourceNotFoundException) {
                $this->fail(sprintf('A shortcut posts to "%s", which no route accepts', $action));
            }
        }
    }

    // The tour walks the sidebar by matching each step's url against the links actually rendered (see assets/js/onboarding-tour.js), so a step whose url no longer exists silently walks past it
    public function testTheGuidedTourStepsMatchTheRenderedSidebar(): void
    {
        $client = $this->createAuthenticatedClient(['ROLE_SUPER_ADMIN']);
        $crawler = $client->request('GET', $this->dashboardPath());

        $tour = $crawler->filter('[data-controller="onboarding-tour"]');
        $this->assertCount(1, $tour, 'The guided tour is no longer wired into the dashboard');

        $steps = json_decode($tour->attr('data-onboarding-tour-steps-value'), true);
        $this->assertNotEmpty($steps, 'The guided tour renders without a single step');

        $rendered = $crawler->filter('a[href]')->each(static fn ($node) => $node->attr('href'));
        foreach ($steps as $step) {
            $this->assertNotSame('', $step['label'], 'A tour step carries no label, so it would show up blank');
            $this->assertContains($step['url'], $rendered, sprintf('The tour walks to "%s", a link the dashboard does not render', $step['url']));
        }
    }

    // Each project's steps are fetched by the panel over HTTP, so a project listed on the dashboard whose endpoint fails only shows up on click
    public function testEveryGuidedProjectServesItsSteps(): void
    {
        $client = $this->createAuthenticatedClient(['ROLE_SUPER_ADMIN']);
        $crawler = $client->request('GET', $this->dashboardPath());

        $slugs = $crawler->filter('[data-guided-project-slug]')->each(static fn ($node) => $node->attr('data-guided-project-slug'));
        $this->assertNotEmpty($slugs, 'The dashboard lists no guided project at all, which means this test is walking an empty page');

        $router = static::getContainer()->get('router');
        foreach ($slugs as $slug) {
            $client->request('GET', $router->generate('management_guided_project_steps', ['slug' => $slug]));
            $this->assertResponseIsSuccessful(sprintf('The "%s" guided project does not serve its steps', $slug));
        }
    }
}
