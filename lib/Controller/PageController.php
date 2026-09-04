<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AppLogger;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
	use ControllerLoggingTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
		AppLogger $appLogger,
	) {
		parent::__construct($appName, $request);
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): TemplateResponse {
		Util::addScript($this->appName, 'chorestrike-main');
		Util::addStyle($this->appName, 'chorestrike-main');

		$this->registerPwaHeaders();

		return new TemplateResponse($this->appName, 'main');
	}

	/**
	 * Inject the PWA manifest <link> and a brand theme-color <meta>.
	 * Best-effort: if the OCP layer rejects either call (older host or
	 * stricter CSP), the page still renders — PWA features degrade to a
	 * regular webview, which is the pre-Phase-5 behaviour. The swallow is
	 * documented and debug-logged (CS-PLAN-061 §3.6), never silent.
	 */
	private function registerPwaHeaders(): void {
		try {
			Util::addLink(
				'manifest',
				$this->urlGenerator->imagePath($this->appName, 'manifest.webmanifest')
			);
		} catch (\Throwable $e) {
			// Best-effort — host may not support addLink.
			$this->logDebug('PWA manifest link injection failed (best-effort)', [
				'event' => 'pwa_manifest_link_failed',
				'exception' => get_class($e),
			]);
		}

		try {
			Util::addHeader('meta', [
				'name' => 'theme-color',
				'content' => '#0984E3',
			]);
			Util::addHeader('meta', [
				'name' => 'apple-mobile-web-app-capable',
				'content' => 'yes',
			]);
			Util::addHeader('meta', [
				'name' => 'mobile-web-app-capable',
				'content' => 'yes',
			]);
		} catch (\Throwable $e) {
			// Best-effort — host may not support addHeader.
			$this->logDebug('PWA meta header injection failed (best-effort)', [
				'event' => 'pwa_meta_header_failed',
				'exception' => get_class($e),
			]);
		}
	}
}
