<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Hushscript\Clock;
use Hushscript\Transcribe\PartFailure;
use Hushscript\Transcribe\PartTransport;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Part requests answered from a script: for each request it says what comes back and after how
 * many clock seconds, so a test decides the order in which answers arrive.
 */
final class FakePartTransport implements PartTransport
{
    /** @var list<array{method: string, url: string, authorization: string, length: int, timeout: float, headers: array<mixed>}> */
    public array $sent = [];

    /** @var array<int, array{Promise<ResponseInterface, \Throwable>, float, ResponseInterface|PartFailure}> */
    private array $pending = [];

    public int $cancelled = 0;

    public int $maxPending = 0;

    private int $ticks = 0;

    /**
     * @param  \Closure(array{method: string, url: string, authorization: string, length: int, timeout: float, headers: array<mixed>}, int): array{ResponseInterface|PartFailure, float}  $script
     */
    public function __construct(private readonly Clock $clock, private readonly \Closure $script)
    {
    }

    #[\Override]
    public function send(RequestInterface $request, float $timeout): PromiseInterface
    {
        $record = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'authorization' => $request->getHeaderLine('Authorization'),
            'length' => (int) $request->getBody()->getSize(),
            'timeout' => $timeout,
            'headers' => array_map(array_values(...), $request->getHeaders()),
        ];
        $index = count($this->sent);
        LoopGuard::check($index + 1);
        $this->sent[] = $record;
        [$answer, $hold] = ($this->script)($record, $index);
        /** @var Promise<ResponseInterface, \Throwable> $promise */
        $promise = new Promise(null, function (): void {
            $this->cancelled++;
        });
        $this->pending[] = [$promise, $this->clock->now() + $hold, $answer];
        $this->maxPending = max($this->maxPending, count($this->pending));

        return $promise;
    }

    #[\Override]
    public function tick(): void
    {
        LoopGuard::check(++$this->ticks);
        foreach ($this->pending as $key => [$promise, $at, $answer]) {
            if ($at <= $this->clock->now()) {
                unset($this->pending[$key]);
                $answer instanceof PartFailure ? $promise->reject($answer) : $promise->resolve($answer);
            }
        }
        Utils::queue()->run();
    }

    public function nextRelease(): ?float
    {
        $times = array_map(static fn (array $entry): float => $entry[1], $this->pending);

        return $times === [] ? null : min($times);
    }

    /** @return list<string> the request line of every part request, such as "PUT r2:3" or "PUT api:3" */
    public function lines(): array
    {
        return array_map(function (array $record): string {
            $api = str_contains($record['url'], '/v1/uploads/');
            preg_match('~/(\d+)(?:\?|$)~', $record['url'], $match);

            return $record['method'].' '.($api ? 'api' : 'r2').':'.($match[1] ?? '?');
        }, $this->sent);
    }
}
