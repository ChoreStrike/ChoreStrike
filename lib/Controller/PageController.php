<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\ColorSchemeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {
	use ControllerLoggingTrait;

	public const COLOR_SCHEME_STATE = 'color-scheme';

	public function __construct(
		string $appName,
		IRequest $request,
		AppLogger $appLogger,
		private IInitialState $initialState,
		private IUserSession $userSession,
		private ColorSchemeService $colorSchemes,
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

		$this->registerPwaHeaders();
		$this->provideColorScheme();

		return new TemplateResponse($this->appName, 'main');
	}

	/**
	 * The viewer's colour scheme travels with the page (CS-PLAN-090 WP-K,
	 * D-090-67), so the app's first paint already has it. It is the
	 * viewer's own setting; membership is checked by every API read.
	 */
	private function provideColorScheme(): void {
		$user = $this->userSession->getUser();
		$scheme = $user === null ? ColorSchemeService::DEFAULT : $this->colorSchemes->get($user->getUID());
		$this->initialState->provideInitialState(self::COLOR_SCHEME_STATE, $scheme);
	}

	/**
	 * Inject the brand theme-color and web-app <meta> tags. The PWA manifest
	 * needs no injection: Nextcloud's page layout links the app's own
	 * img/manifest.json (CS-PLAN-087 WP-A). Best-effort: if the OCP layer
	 * rejects a call, the page still renders as a regular webview. The
	 * swallow is documented and debug-logged (CS-PLAN-061 §3.6), never silent.
	 */
	private function registerPwaHeaders(): void {
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
