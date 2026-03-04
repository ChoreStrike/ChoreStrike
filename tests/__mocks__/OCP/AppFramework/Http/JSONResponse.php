<?php

/**
 * Mock JSONResponse for testing
 */

declare(strict_types=1);

namespace OCP\AppFramework\Http;

class JSONResponse
{
    /** @var mixed */
    private $data;

    private int $status;

    /**
     * @param mixed $data
     */
    public function __construct($data = [], int $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }
}
