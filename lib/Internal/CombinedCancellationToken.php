<?php

namespace Amp\Artax\Internal;

use Amp\Cancellation\Token;
use Amp\Cancellation\TokenSource;

/** @internal */
class CombinedCancellationToken implements Token
{
    private $token;
    private $tokens = [];

    public function __construct(Token ...$tokens)
    {
        $tokenSource = new TokenSource;
        $this->token = $tokenSource->getToken();

        foreach ($tokens as $token) {
            $id = $token->subscribe(static function ($error) use ($tokenSource) {
                $tokenSource->cancel($error);
            });

            $this->tokens[] = [$token, $id];
        }
    }

    public function __destruct()
    {
        foreach ($this->tokens as [$token, $id]) {
            /** @var Token $token */
            $token->unsubscribe($id);
        }
    }

    /** @inheritdoc */
    public function subscribe(callable $callback): string
    {
        return $this->token->subscribe($callback);
    }

    /** @inheritdoc */
    public function unsubscribe(string $id): void
    {
        $this->token->unsubscribe($id);
    }

    /** @inheritdoc */
    public function isRequested(): bool
    {
        return $this->token->isRequested();
    }

    /** @inheritdoc */
    public function throwIfRequested(): void
    {
        $this->token->throwIfRequested();
    }
}
