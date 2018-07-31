<?php

namespace Amp\Artax;

use Amp\ByteStream\InputStream;
use function Amp\File\open;
use function Amp\File\size;

final class FileBody implements RequestBody
{
    /** @var string */
    private $path;

    /**
     * @param string $path The filesystem path for the file we wish to send
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function createBodyStream(): InputStream
    {
        return open($this->path, "r");
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function getBodyLength(): int
    {
        return size($this->path);
    }
}
