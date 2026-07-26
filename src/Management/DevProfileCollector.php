<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Contracts\Service\ResetInterface;

// Runs one local path through the kernel with the profiler on, and reads back the numbers the dev toolbar shows (sql queries, deprecations, missing translations, twig templates, cache, external http calls). Dev only (#[When('dev')]): the "profiler" service doesn't even exist in prod. The request never goes over HTTP - it's handed straight to the kernel, exactly like a functional test does, so what's measured is the local code and the local database, not the live site the health check fetches at "site-url"
#[When('dev')]
class DevProfileCollector
{
    // Any host works, none of the collected data depends on it - https so a route requiring that scheme, or an app-level http-to-https redirect, doesn't answer a 301 instead of the page
    private const BASE_URL = 'https://localhost';

    // DataCollectorTranslator::MESSAGE_MISSING, hardcoded so ConfigBundle doesn't require symfony/translation for it
    private const TRANSLATION_MISSING = 1;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ?Profiler $profiler,
        private readonly ResetInterface $servicesResetter,
    ) {
    }

    // Numbers for one path, ready for DevProfileAnalyzer - the keys a collector isn't installed for (no twig, no http client...) simply stay at 0
    public function collect(string $path, ?string $label = null): array
    {
        $metrics = [
            'path' => $path,
            'label' => $label,
            'statusCode' => 0,
            'error' => null,
            'queries' => 0,
            'duplicateQueries' => 0,
            'worstDuplicateQuery' => null,
            'queryTime' => 0.0,
            'deprecations' => 0,
            'deprecationMessages' => [],
            'logErrors' => 0,
            'missingTranslations' => 0,
            'missingTranslationKeys' => [],
            'fallbackTranslations' => 0,
            'templates' => 0,
            'twigTime' => 0.0,
            'cacheHits' => 0,
            'cacheMisses' => 0,
            'cacheWrites' => 0,
            'httpRequests' => 0,
            'duration' => 0.0,
            'memory' => 0,
        ];

        // Null on a dev environment set up without symfony/profiler-pack: there's nothing to measure, and saying so beats a page reported as clean
        if (null === $this->profiler) {
            $metrics['error'] = 'Le profiler n\'est pas disponible - installez symfony/profiler-pack pour profiler les pages.';

            return $metrics;
        }

        $request = Request::create(self::BASE_URL . $path);
        $this->profiler->enable();

        try {
            $response = $this->kernel->handle($request);
        } catch (\Throwable $e) {
            // handle() catches the app's own exceptions and turns them into a 500 response, so getting here means the kernel itself couldn't answer at all (a listener throwing, a missing service...) - reported as its own row rather than aborting the whole run
            $this->reset();
            $metrics['error'] = $e->getMessage();

            return $metrics;
        }

        // The profile is only written to storage on terminate (see ProfilerListener), and loadProfileFromResponse() reads it back from there - the very sequence KernelBrowser::getProfile() goes through in a functional test
        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }

        $metrics['statusCode'] = $response->getStatusCode();

        // Null when the app runs with framework.profiler.collect: false and something re-disabled the profiler after enable() - the status code alone is still worth reporting
        $profile = $this->profiler->loadProfileFromResponse($response);
        if (null !== $profile) {
            $metrics = array_merge($metrics, $this->readCollectors($profile));
        }

        $this->reset();

        return $metrics;
    }

    // Every collected number, collector by collector - the ones an app doesn't have (no doctrine, no twig, no http client) simply keep their default
    private function readCollectors(Profile $profile): array
    {
        $totals = $this->read($profile, 'cache', 'getTotals', []);

        return [
            'queries' => $this->read($profile, 'db', 'getQueryCount', 0),
            'queryTime' => round((float) $this->read($profile, 'db', 'getTime', 0.0), 1),
            'deprecations' => $this->read($profile, 'logger', 'countDeprecations', 0),
            'logErrors' => $this->read($profile, 'logger', 'countErrors', 0),
            'deprecationMessages' => $this->readDeprecationMessages($this->read($profile, 'logger', 'getProcessedLogs', [])),
            'missingTranslations' => $this->read($profile, 'translation', 'getCountMissings', 0),
            'fallbackTranslations' => $this->read($profile, 'translation', 'getCountFallbacks', 0),
            'missingTranslationKeys' => $this->readMissingTranslationKeys($this->read($profile, 'translation', 'getMessages', [])),
            'templates' => $this->read($profile, 'twig', 'getTemplateCount', 0),
            'twigTime' => round((float) $this->read($profile, 'twig', 'getTime', 0.0), 1),
            'cacheHits' => $totals['hits'] ?? 0,
            'cacheMisses' => $totals['misses'] ?? 0,
            'cacheWrites' => $totals['writes'] ?? 0,
            'httpRequests' => $this->read($profile, 'http_client', 'getRequestCount', 0),
            'duration' => round((float) $this->read($profile, 'time', 'getDuration', 0.0), 1),
            'memory' => $this->read($profile, 'memory', 'getMemory', 0),
        ] + $this->readDuplicateQueries($this->read($profile, 'db', 'getGroupedQueries', []));
    }

    // Profile::getCollector() only ever promises a DataCollectorInterface, every accessor read above belonging to a concrete collector shipped by an optional package (doctrine-bundle, twig-bridge, symfony/http-client...). Going through method_exists() rather than type-hinting those classes keeps ConfigBundle from requiring any of them, and keeps a version of one of them that renamed an accessor from fataling the whole run
    private function read(Profile $profile, string $collector, string $method, mixed $default): mixed
    {
        if (!$profile->hasCollector($collector)) {
            return $default;
        }

        $instance = $profile->getCollector($collector);

        return method_exists($instance, $method) ? $instance->$method() : $default;
    }

    // The one signal a raw query count can't give: the same sql fired again and again is an n+1, whatever the total. Counted as "queries that didn't need to be there" (a query run 12 times counts as 11), and the worst offender is kept so the report can name it
    private function readDuplicateQueries(array $groupedQueries): array
    {
        $duplicates = 0;
        $worst = null;

        foreach ($groupedQueries as $queries) {
            foreach ($queries as $query) {
                $count = $query['count'] ?? 1;
                if ($count < 2) {
                    continue;
                }

                $duplicates += $count - 1;
                if (null === $worst || $count > $worst['count']) {
                    $worst = ['sql' => (string) ($query['sql'] ?? ''), 'count' => $count];
                }
            }
        }

        return ['duplicateQueries' => $duplicates, 'worstDuplicateQuery' => $worst];
    }

    // Distinct deprecation messages, the same one usually being triggered on every call site of the deprecated code
    private function readDeprecationMessages(array $logs): array
    {
        $messages = [];
        foreach ($logs as $log) {
            if ('deprecation' !== ($log['type'] ?? null)) {
                continue;
            }

            $messages[] = (string) $log['message'];
        }

        return array_values(array_unique($messages));
    }

    // The keys the translator had no translation for, in the current locale - getMessages() comes back as a VarDumper Data once the profile has been through storage, getValue(true) turning it back into plain arrays
    private function readMissingTranslationKeys(array|Data $messages): array
    {
        if ($messages instanceof Data) {
            $messages = $messages->getValue(true);
        }

        $keys = [];
        foreach ($messages as $message) {
            if (self::TRANSLATION_MISSING !== ($message['state'] ?? null)) {
                continue;
            }

            $keys[] = sprintf('%s (%s)', $message['id'] ?? '', $message['domain'] ?? '');
        }

        return array_values(array_unique($keys));
    }

    // Data collectors, the doctrine query log and the traceable cache/http client all accumulate for the whole process, so without this every path would be reported carrying the previous ones' numbers - it's what the messenger worker does between two messages, and what rebooting the kernel does between two functional test requests
    private function reset(): void
    {
        $this->servicesResetter->reset();
    }
}
