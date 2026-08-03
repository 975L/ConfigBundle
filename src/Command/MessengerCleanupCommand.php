<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\MessengerFailedMessageService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Purges old Messenger failed messages (queue_name = 'failed') and, if new "important"
 * failures (i.e. not spam-related, see MessengerFailedMessageService) appeared since the
 * last alert, sends a single digest email - never more than once per new batch of failures.
 *
 * Usage:
 *   php bin/console c975l:config:messenger-cleanup
 *
 * Settings managed via ConfigBundle (site-messenger-cleanup-* keys).
 *
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2026 975L <contact@975l.com>
 */
#[AsCommand(
    name: 'c975l:config:messenger-cleanup',
    description: 'Purges old failed Messenger messages and alerts on new important ones'
)]
class MessengerCleanupCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private readonly MessengerFailedMessageService $messengerFailedMessageService,
        private readonly ConfigServiceInterface $configService,
        private readonly MailerInterface $mailer,
        private readonly ParameterBagInterface $parameterBag,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stats = $this->cleanup();

        if ($stats['alerted']) {
            $io->note(sprintf('Digest email sent for %d new important failure(s).', $stats['newImportant']));
        }
        $io->success(sprintf('Purged %d message(s) older than the retention period.', $stats['purged']));

        return Command::SUCCESS;
    }

    // Purges old failed messages and sends a digest email if new important failures appeared since the last alert; called both by execute() and MessengerFailedController's "purge now" action
    public function cleanup(): array
    {
        $markerFile = $this->parameterBag->get('kernel.project_dir') . '/var/MessengerAlertDateTimeFile';
        $lastAlertTime = file_exists($markerFile) ? filemtime($markerFile) : 0;

        $messages = $this->messengerFailedMessageService->findAll();
        $important = array_filter($messages, fn (array $message) => $message['important']);
        $newImportant = array_filter(
            $important,
            fn (array $message) => $message['createdAt']->getTimestamp() > $lastAlertTime
        );

        $mailto = (string) $this->configService->get('site-messenger-cleanup-mailto');
        $alerted = false;
        if ([] !== $newImportant && '' !== $mailto) {
            $this->sendDigest($mailto, $newImportant);
            touch($markerFile);
            $alerted = true;
        }

        $retentionDays = (int) ($this->configService->get('site-messenger-cleanup-retention-days') ?: self::DEFAULT_RETENTION_DAYS);
        $purged = $this->messengerFailedMessageService->purgeOlderThan($retentionDays);

        return [
            'purged' => $purged,
            'important' => count($important),
            'newImportant' => count($newImportant),
            'alerted' => $alerted,
        ];
    }

    private function sendDigest(string $mailto, array $newImportant): void
    {
        // Dates come back as UTC from the transport, the digest reads better in the site's own timezone (the back-office listing gets that conversion from Twig)
        $report = '';
        foreach ($newImportant as $message) {
            $report .= sprintf(
                "\n- %s\n  To: %s\n  Subject: %s\n  Error: %s\n",
                $message['createdAt']->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'),
                $message['to'] ?? '(unknown)',
                $message['subject'] ?? '(unknown)',
                $message['exceptionMessage'] ?? '(unknown)',
            );
        }

        $email = (new Email())
            ->from($this->emailFrom($mailto))
            ->to($mailto)
            ->subject(sprintf('[Messenger] %d new important failure(s)', count($newImportant)))
            ->text("The following messages failed to send and need attention:\n" . $report);

        $this->mailer->send($email);
    }

    // An empty sender makes Email::from() throw, which would take the purge down with it as the digest is sent first - the recipient doubles as the sender then, an address that is by definition deliverable here
    private function emailFrom(string $mailto): string
    {
        $from = (string) $this->configService->get('email-from');

        return '' !== $from ? $from : $mailto;
    }
}
