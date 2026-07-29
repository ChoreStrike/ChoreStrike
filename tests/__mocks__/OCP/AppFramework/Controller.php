<?php

declare(strict_types=1);

/**
 * Mock AppFramework Controller for testing.
 *
 * Mirrors the real OCP\AppFramework\Controller constructor signature
 * `(string $appName, IRequest $request)` so that controllers extending it
 * can call `parent::__construct('chorestrike', $request)` under strict_types.
 */

namespace OCP\AppFramework;

use OCP\IRequest;

class Controller
{
    protected string $appName;
    protected IRequest $request;

    public function __construct(string $appName, IRequest $request)
    {
        $this->appName = $appName;
        $this->request = $request;
    }
}
