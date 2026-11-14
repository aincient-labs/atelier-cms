<?php

/**
 * @file
 * Prints the docs Glossary page rendered from aincient_core/glossary.yml.
 *
 * Run by bin/sync-docs on the host: php glossary-page.php > glossary.mdx
 */

declare(strict_types=1);

$root = dirname(__DIR__, 5);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Docs/GlossaryPage.php';

echo \Drupal\aincient_core\Docs\GlossaryPage::render();
