<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,

        // Symfony drops the spaces around the concatenation operator, PSR-12 requires them: keeping them is what lets PHP_CodeSniffer, and so Codacy, still check every operator's spacing
        'concat_space' => ['spacing' => 'one'],

        // Same reason for the union types of a catch and for the arguments of an anonymous class, which PHP_CodeSniffer reads as operators
        'types_spaces' => ['space' => 'single'],
        'class_definition' => ['space_before_parenthesis' => true],
    ])
    // The tool has not declared PHP 8.5 support yet, while it runs fine on it
    ->setUnsupportedPhpVersionAllowed(true)
    // The skeleton templates are code to generate, not code to lint
    ->setFinder((new PhpCsFixer\Finder())->notName('*.tpl.php')->in(['src', 'tests']));
