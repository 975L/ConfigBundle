<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Management\BackupDigestBuilder;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Console command sending the recurring backup digest email.
 *
 * Usage:
 *   php bin/console c975l:config:backup:digest              # last 7 days, mailed to site-backup-mailto
 *   php bin/console c975l:config:backup:digest --days=30    # any other window
 *   php bin/console c975l:config:backup:digest --dry-run    # print it, send nothing
 *
 * Runs no backup of its own: it reads back the HealthCheckResult rows c975l:config:backup leaves behind,
 * which is what lets it be scheduled independently of them. `c975l:config:backup --report` rides on a
 * backup run and only exists if that run reaches its last line, so a dead consumer, a crontab lost on a
 * server move or a fatal mid-dump all send nothing - and a mail that never arrives is not something anyone
 * notices on a site whose dashboard they don't open daily. This one arrives either way, and says so.
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2026 975L <contact@975l.com>
 */
#[AsCommand(
    name: 'c975l:config:backup:digest',
    description: 'Emails a digest of the backups recorded over the last days'
)]
class BackupDigestCommand extends Command
{
    public function __construct(
        private readonly BackupDigestBuilder $digestBuilder,
        private readonly ConfigServiceInterface $configService,
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Number of days to report on', (string) BackupDigestBuilder::DEFAULT_DAYS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Display the digest without sending it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // No backup is configured, so none is expected to have run: reporting on a week of nothing would raise a weekly false alarm on every install that simply doesn't back up from here
        if ('' === (string) $this->configService->get('site-backup-database')) {
            $io->note('site-backup-database is not configured, nothing to report on.');

            return Command::SUCCESS;
        }

        $days = max(1, (int) $input->getOption('days'));
        $digest = $this->digestBuilder->build($days);

        // Printed whatever happens next, so the digest is in the scheduler's own log even if the mail can't leave
        $io->title($digest['subject']);
        $io->writeln($digest['body']);

        if (!$input->getOption('dry-run') && !$this->send($digest, $io)) {
            return Command::FAILURE;
        }

        // The one outcome no email can be trusted to report, its own delivery being just as able to fail silently as the backups it covers
        if (BackupDigestBuilder::STATUS_NONE === $digest['status']) {
            $io->error(sprintf('No backup recorded over the last %d days.', $days));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function send(array $digest, SymfonyStyle $io): bool
    {
        $mailto = (string) $this->configService->get('site-backup-mailto');
        if ('' === $mailto) {
            $io->warning('site-backup-mailto is not configured, the digest was not sent.');

            return false;
        }

        // An empty sender makes Email::from() throw, taking the digest down with it - the recipient doubles as the sender then, an address that is by definition deliverable here
        $from = (string) $this->configService->get('email-from');

        $email = (new Email())
            ->from('' !== $from ? $from : $mailto)
            ->to($mailto)
            ->subject($digest['subject'])
            ->text($digest['body']);

        $this->mailer->send($email);

        return true;
    }
}
