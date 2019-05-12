<?php

namespace Amp\Artax;

use Concurrent\Network\TcpSocket;
use League\Uri;

class HttpSocketPool
{
    public const OPTION_PROXY_HTTP = 'amp.artax.httpsocketpool.proxy-http';
    public const OPTION_PROXY_HTTPS = 'amp.artax.httpsocketpool.proxy-https';

    /** @var HttpSocket */
    private $sockets = [];
    private $socketIdUriMap = [];
    private $pendingCount = [];

    private $idleTimeout;
    private $socketContext;

    private $tunneler;

    private $options = [
        self::OPTION_PROXY_HTTP => null,
        self::OPTION_PROXY_HTTPS => null,
    ];

    public function __construct(int $idleTimeout, HttpTunneler $tunneler = null)
    {
        $this->idleTimeout = $idleTimeout;
        $this->tunneler = $tunneler ?? new HttpTunneler;
        $this->autoDetectProxySettings();
    }

    private function autoDetectProxySettings(): void
    {
        // See CVE-2016-5385, due to (emulation of) header copying with PHP web SAPIs into HTTP_* variables,
        // HTTP_PROXY can be set by an user to any value he wants by setting the Proxy header.
        // Mitigate the vulnerability by only allowing CLI SAPIs to use HTTP(S)_PROXY environment variables.
        if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg" && PHP_SAPI !== "embed") {
            return;
        }

        if (($httpProxy = \getenv('http_proxy')) || ($httpProxy = \getenv('HTTP_PROXY'))) {
            $this->options[self::OPTION_PROXY_HTTP] = $this->getUriAuthority($httpProxy);
        }

        if (($httpsProxy = \getenv('https_proxy')) || ($httpsProxy = \getenv('HTTPS_PROXY'))) {
            $this->options[self::OPTION_PROXY_HTTPS] = $this->getUriAuthority($httpsProxy);
        }
    }

    private function getUriAuthority(string $uri): string
    {
        $parsedUri = Uri\parse($uri);

        return $parsedUri['host'] . ":" . $parsedUri['port'];
    }

    /** @inheritdoc */
    public function checkout(string $uri): TcpSocket
    {
        $parsedUri = Uri\parse($uri);

        $scheme = $parsedUri['scheme'];

        if ($scheme === 'tcp' || $scheme === 'http') {
            $proxy = $this->options[self::OPTION_PROXY_HTTP];
        } elseif ($scheme === 'tls' || $scheme === 'https') {
            $proxy = $this->options[self::OPTION_PROXY_HTTPS];
        } else {
            throw new \Error(
                'Either tcp://, tls://, http:// or https:// URI scheme required for HTTP socket checkout'
            );
        }

        // The underlying TCP pool will ignore the URI fragment when connecting but retain it in the
        // name when tracking hostname connection counts. This allows us to expose host connection
        // limits transparently even when connecting through a proxy.
        $authority = $parsedUri['host'] . ":" . $parsedUri['port'];


        if (empty($this->sockets[$uri])) {
            $socket = $this->checkoutNewSocket($uri, $token);
        }

        if (!$proxy) {
            return $this->doCheckout("tcp://{$authority}");
        }

        $socket = $this->doCheckout("tcp://{$proxy}#{$authority}");
        $this->tunneler->tunnel($socket, $authority);

        return $socket;
    }

    /** @inheritdoc */
    public function checkin(ClientSocket $socket): void
    {
        $this->socketPool->checkin($socket);
    }

    /** @inheritdoc */
    public function clear(ClientSocket $socket): void
    {
        $this->socketPool->clear($socket);
    }

    /** @inheritdoc */
    public function setOption(string $option, $value): void
    {
        switch ($option) {
            case self::OPTION_PROXY_HTTP:
                $this->options[self::OPTION_PROXY_HTTP] = (string) $value;
                break;
            case self::OPTION_PROXY_HTTPS:
                $this->options[self::OPTION_PROXY_HTTPS] = (string) $value;
                break;
            default:
                throw new \Error("Invalid option: $option");
        }
    }

    private function checkoutExistingSocket(string $uri): ?TcpSocket
    {
        if (empty($this->sockets[$uri])) {
            return null;
        }

        foreach ($this->sockets[$uri] as $socketId => $socket) {
            if ($socket->inUse) {
                continue;
            }

            if ($socket->socket) {
                $this->clear(new ClientSocket($socket->resource));
                continue;
            }

            $socket->isAvailable = false;

            if ($socket->idleWatcher !== null) {
                Loop::disable($socket->idleWatcher);
            }

            return new ClientSocket($socket->resource);
        }

        return $this->checkoutNewSocket($uri, $token);
    }
}
