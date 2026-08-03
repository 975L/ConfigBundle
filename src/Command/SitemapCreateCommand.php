<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Management\SitemapWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'c975l:sitemaps:create',
    description: 'Creates a sitemap for every registered SitemapProvider (site pages, books, products...), plus the sitemap index'
)]
class SitemapCreateCommand extends Command
{
    public function __construct(
        private readonly SitemapWriter $sitemapWriter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $names = $this->sitemapWriter->write();

        if ([] === $names) {
            $io->warning('No SitemapProvider has any url to declare - nothing to write.');

            return Command::SUCCESS;
        }

        $io->success('Sitemaps created: ' . implode(', ', $names) . ', plus the index.');

        return Command::SUCCESS;
    }
}
