<?php

namespace Amp\Artax;

use Amp\Struct;
use Concurrent\Network\TcpSocket;

final class HttpSocket
{
    use Struct;

    public $id;

    /** @var string */
    public $uri;

    /** @var TcpSocket */
    public $socket;

    /** @var bool */
    public $inUse;
}
