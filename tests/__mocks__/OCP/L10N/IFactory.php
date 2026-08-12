<?php

namespace OCP\L10N;

use OCP\IL10N;

/**
 * Mock interface for the Nextcloud localization factory.
 */
interface IFactory {
	/**
	 * Get a localization instance for an app and language code.
	 *
	 * @param string $app App ID
	 * @param string|null $lang Language code (null = user default)
	 * @param string|null $default Default language if lookup fails
	 * @return IL10N
	 */
	public function get(string $app, ?string $lang = null, ?string $default = null);
}
