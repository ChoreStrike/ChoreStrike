<?php

namespace OCP;

/**
 * Mock interface for Nextcloud localization service.
 */
interface IL10N {
	/**
	 * Translate a string.
	 *
	 * @param string $text Source text key.
	 * @param array<string, scalar> $parameters Placeholder replacements.
	 * @param int|null $count Optional plural count.
	 */
	public function t($text, array $parameters = [], ?int $count = null);

	/**
	 * Translate a plural string (real signature per OCP\IL10N — used by
	 * the digest notifier, CS-PLAN-070 WP5).
	 *
	 * @param string $textSingular Singular source text key.
	 * @param string $textPlural Plural source text key.
	 * @param int $count The number of items (drives the plural form).
	 * @param array<string, scalar> $parameters Placeholder replacements.
	 */
	public function n($text_singular, $text_plural, $count, array $parameters = []);
}
