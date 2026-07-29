<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Builds the element the guided-project panel mounts on. It has to sit on every admin page, not just the dashboard: a project spans several screens, and the panel has to come back after each page load. EasyAdmin's own Assets::addHtmlContentToBody() (see DashboardController::configureAssets(), rendered by its layout.html.twig on every page, CRUD ones included) puts it there without overriding a single template - something the sidebar-only guided tour never needed. Plain markup with data-* attributes, no inline <script> nor style="": a strict CSP with a nonce covers neither
class GuidedProjectMountBuilder
{
    // Stands in for the slug in the JSON route's url, the panel only knowing which project to fetch once it has read the browser's own stored progress
    public const SLUG_PLACEHOLDER = '__SLUG__';

    private const LABELS = [
        'previous' => 'label.onboarding_previous',
        'next' => 'label.onboarding_next',
        'finish' => 'label.onboarding_finish',
        'goto' => 'label.guided_project_goto',
        'pause' => 'label.guided_project_pause',
        'quit' => 'label.guided_project_quit',
        'start' => 'label.guided_project_start',
        'resume' => 'label.guided_project_resume',
        'replay' => 'label.guided_project_replay',
    ];

    public function __construct(
        private readonly GuidedProjectKeyGenerator $keyGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Empty when nobody is logged in, there being no key to store any progress under
    public function getHtml(): string
    {
        $key = $this->keyGenerator->getKey();
        if ('' === $key) {
            return '';
        }

        return sprintf(
            '<div data-controller="guided-project" data-guided-project-key-value="%s" data-guided-project-url-value="%s" data-guided-project-labels-value="%s"></div>',
            htmlspecialchars($key, ENT_QUOTES),
            htmlspecialchars($this->urlGenerator->generate('management_guided_project_steps', ['slug' => self::SLUG_PLACEHOLDER]), ENT_QUOTES),
            htmlspecialchars(json_encode($this->getLabels()), ENT_QUOTES),
        );
    }

    // The panel's own chrome, translated here since it's built in JavaScript - "previous", "next" and "finish" are shared with the guided tour rather than duplicated, both saying the same words
    private function getLabels(): array
    {
        $labels = [];
        foreach (self::LABELS as $name => $id) {
            $labels[$name] = $this->translator->trans($id, [], 'config');
        }

        return $labels;
    }
}
