<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Scheduler;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

// Resolves Symfony's "hashed" cron expressions against this install's own identity, so that two installs of the same bundles never run the same maintenance command at the same minute - Symfony hashes them against the message alone, which gives every install the exact same answer for "c975l:config:backup" and piles them all up on one server
class ScheduleSpreader
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    // Builds the recurring message for an expression whose "#" placeholders are to be spread (see https://symfony.com/doc/current/scheduler.html#hashed-cron-expressions), an expression without any being used as it stands
    public function spread(string $expression, object $message, \DateTimeZone | string | null $timezone = null): RecurringMessage
    {
        return RecurringMessage::trigger(
            CronExpressionTrigger::fromSpec($expression, $this->getContext($message), $timezone),
            $message,
        );
    }

    // What the placeholders are drawn from: this install, plus the message itself so that two commands of one site are spread apart too. A message that isn't Stringable falls back on its class rather than fatalling at worker start-up on the interpolation
    private function getContext(object $message): string
    {
        return $this->getKey() . '|' . ($message instanceof \Stringable ? $message : $message::class);
    }

    // The salt the placeholders are drawn from: its value is never read back, only its being different from one install to the next matters, hence the fallback for a site whose url isn't configured yet
    private function getKey(): string
    {
        return ((string) $this->configService->get('site-url')) ?: $this->projectDir;
    }
}
