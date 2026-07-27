<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Management\ImportmapRegistry;
use c975L\ConfigBundle\Service\ImportmapSpecifierLocator;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntries;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntry;
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'c975l:config:check-importmap',
    description: 'Adds any importmap.php entry missing from the app - the ones contributed by an ImportmapProviderInterface (see readme), plus the third-party packages the c975L bundles\' own JS imports. Safe to run on every composer update, never touches an entry already present'
)]
class CheckImportmapCommand extends Command
{
    public function __construct(
        private readonly ImportmapRegistry $importmapRegistry,
        private readonly ImportmapSpecifierLocator $specifierLocator,
        #[Autowire(service: 'asset_mapper.importmap.config_reader')]
        private readonly ImportMapConfigReader $configReader,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->warnOnEagerChartjs($io);
        $entries = $this->configReader->getEntries();

        $added = [];
        foreach ($this->importmapRegistry->all() as $importName => $data) {
            if ($entries->has($importName)) {
                continue;
            }

            $entries->add(ImportMapEntry::createLocal(
                $importName,
                ImportMapType::from($data['type'] ?? 'js'),
                $data['path'],
                $data['entrypoint'] ?? false,
            ));
            $added[] = $importName;
        }

        $added = array_merge($added, $this->addMissingBareSpecifiers($entries, $io));

        if (!$added) {
            $io->success('importmap.php est déjà à jour.');

            return Command::SUCCESS;
        }

        $this->configReader->writeEntries($entries);

        $io->success(sprintf('%d entrée(s) ajoutée(s) à importmap.php :', count($added)));
        $io->listing($added);

        return Command::SUCCESS;
    }

    // A c975L bundle's own JS may import a third-party package by bare specifier (e.g. ConfigBundle's controllers-admin.js importing "@symfony/ux-chartjs" for the health check chart). That entry is normally written by the package's own Flex recipe, which doesn't always run - and when it's missing the browser can't resolve the specifier, so the whole module fails and every Stimulus controller it registers is lost, back-office drag-and-drop included. Never an entrypoint: these are imported by another module, not loaded on their own
    private function addMissingBareSpecifiers(ImportMapEntries $entries, SymfonyStyle $io): array
    {
        $added = [];
        $unresolved = [];

        foreach ($this->specifierLocator->findBareSpecifiers() as $specifier) {
            if ($entries->has($specifier)) {
                continue;
            }

            $path = $this->specifierLocator->resolvePath($specifier);
            if (null === $path) {
                $unresolved[] = $specifier;

                continue;
            }

            $entries->add(ImportMapEntry::createLocal($specifier, ImportMapType::JS, $path, false));
            $added[] = $specifier;
        }

        if ($unresolved) {
            $io->warning(sprintf(
                "Importé(s) par le JS d'un bundle c975L mais absent(s) d'importmap.php, et introuvable(s) dans vendor/ : %s.\nLe navigateur ne pourra pas résoudre ce specifier et tout le module qui l'importe échouera. Installez le paquet, ou ajoutez l'entrée à la main.",
                implode(', ', $unresolved)
            ));
        }

        return $added;
    }

    // symfony/ux-chartjs' Flex recipe enables its chart controller eagerly in assets/controllers.json, which makes startStimulusApp() import chart.js on every front-end page and makes every admin Stimulus app register the controller a second time (see readme). Only warns - rewriting the app's controllers.json isn't this command's job
    private function warnOnEagerChartjs(SymfonyStyle $io): void
    {
        $file = $this->projectDir . '/assets/controllers.json';
        if (!is_file($file)) {
            return;
        }

        $config = json_decode((string) file_get_contents($file), true);
        if (!is_array($config) || true !== ($config['controllers']['@symfony/ux-chartjs']['chart']['enabled'] ?? false)) {
            return;
        }

        $io->warning('assets/controllers.json active "@symfony/ux-chartjs" : chart.js est chargé sur toutes les pages du site et enregistré plusieurs fois sur le dashboard. Passez "enabled" à false - ConfigBundle enregistre lui-même ce contrôleur (voir readme).');
    }
}
