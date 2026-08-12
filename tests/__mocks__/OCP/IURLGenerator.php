<?php

namespace OCP;

/**
 * Mock interface for the Nextcloud URL generator.
 */
interface IURLGenerator {
	/**
	 * Build an absolute URL for an app resource.
	 *
	 * @param string $url Relative URL
	 * @param array<string, mixed> $args Query parameters
	 */
	public function getAbsoluteURL(string $url, array $args = []): string;

	/**
	 * Build an image path inside an app.
	 *
	 * @param string $app App ID
	 * @param string $image Image file name
	 */
	public function imagePath(string $app, string $image): string;

	/**
	 * Build a link to a named route.
	 *
	 * @param string $routeName Route name
	 * @param array<string, mixed> $arguments Route arguments
	 */
	public function linkToRoute(string $routeName, array $arguments = []): string;
}
