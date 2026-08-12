<?php
// ChoreStrike PHP Coding Standard configuration.
//
// Reuses the Nextcloud coding standard rule set (tabs, Allman→same-line braces,
// ordered imports, PSR-1/2, …) by instantiating its Config class, but points
// the Finder at THIS app's `lib/` and `tests/` trees. We cannot simply
// `require` nextcloud/coding-standard/.php-cs-fixer.dist.php because that file
// binds its Finder to its own package directory (`__DIR__`), which would leave
// our source unscanned.

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->exclude('vendor')
	->in(__DIR__ . '/lib')
	->in(__DIR__ . '/tests');

return $config;
