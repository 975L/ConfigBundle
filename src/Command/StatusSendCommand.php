<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Management\StatusReportBuilder;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: StatusSendCommand::NAME,
    description: 'Sends this site\'s status report (versions, packages, health check summary) to the url configured in "site-status-url" - does nothing until that url is set, use --dump to see the report without sending anything'
)]
class StatusSendCommand extends Command
{
    // Named here rather than in the attribute alone so a schedule can reference the command without repeating the string
    public const NAME = 'c975l:status:send';

    // The shared key travels in a header, never in the query string: an url ends up in the receiver's access log and in the Referer of anything it serves, a header does not
    public const KEY_HEADER = 'X-Status-Key';

    public function __construct(
        private readonly StatusReportBuilder $statusReportBuilder,
        private readonly ConfigServiceInterface $configService,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // Writes the report to stdout instead of sending it: the only way to see exactly what would leave the site, and it needs no url, no key and no network
        $this->addOption('dump', null, InputOption::VALUE_NONE, 'Print the report as JSON instead of sending it - requires no configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->statusReportBuilder->build();

        // Written straight to the output rather than through SymfonyStyle, which would wrap and decorate it - this is meant to be readable, but also pipeable into a file or jq
        if ($input->getOption('dump')) {
            $output->writeln(json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $url = trim((string) $this->configService->get('site-status-url'));
        $key = trim((string) $this->configService->get('site-status-key'));

        // Nothing configured is the default state and a legitimate one: installing the bundle must never make a site talk to a third party. Success rather than failure, so a scheduled entry on a site that opted out doesn't report an error every week
        if ('' === $url) {
            $io->note('No destination url configured ("site-status-url") - nothing was sent.');

            return Command::SUCCESS;
        }

        // A configured url with no key, on the other hand, is a half-finished setup: sending unauthenticated would either be rejected or, worse, accepted by a receiver that doesn't check
        if ('' === $key) {
            $io->error('A destination url is configured but no key ("site-status-key") - sending cancelled.');

            return Command::INVALID;
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [self::KEY_HEADER => $key],
                'json' => $report,
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
        } catch (\Throwable $e) {
            $io->error(sprintf('Envoi impossible : %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($statusCode >= 300) {
            $io->error(sprintf('The recipient answered %d.', $statusCode));

            return Command::FAILURE;
        }

        $io->success(sprintf('Report sent (%d bytes, answer %d).', \strlen(json_encode($report)), $statusCode));

        return Command::SUCCESS;
    }
}
