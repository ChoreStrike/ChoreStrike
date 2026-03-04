<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\PageController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageController
 */
class PageControllerTest extends TestCase
{
    private PageController $controller;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);

        $this->controller = new PageController(
            'chorestrike',
            $this->request
        );
    }

    /**
     * Test index returns template response
     */
    public function testIndex_ReturnsTemplateResponse(): void
    {
        $response = $this->controller->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);
    }
}
