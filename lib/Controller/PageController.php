<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private ILogger $logger
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse
    {
        Util::addScript($this->appName, 'chorestrike-main');
        Util::addStyle($this->appName, 'chorestrike-main');

        $this->registerPwaHeaders();

        return new TemplateResponse($this->appName, 'main');
    }

    /**
     * Inject the PWA manifest <link> and a brand theme-color <meta>.
     * Best-effort: if the OCP layer rejects either call (older host or
     * stricter CSP), the page still renders — PWA features degrade to a
     * regular webview, which is the pre-Phase-5 behaviour.
     */
    private function registerPwaHeaders(): void
    {
        try {
            Util::addLink(
                'manifest',
                $this->urlGenerator->imagePath($this->appName, 'manifest.webmanifest')
            );
        } catch (\Throwable $e) {
            $this->logger->info('PWA manifest link injection failed (host may not support addLink)', [
                'app' => $this->appName,
                'exception' => $e->getMessage(),
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
            $this->logger->info('PWA meta header injection failed', [
                'app' => $this->appName,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
