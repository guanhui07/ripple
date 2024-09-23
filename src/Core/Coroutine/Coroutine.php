<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Psc\Core\Coroutine;

use Closure;
use Fiber;
use FiberError;
use Psc\Core\Coroutine\Exception\EscapeException;
use Psc\Core\Coroutine\Exception\Exception;
use Psc\Core\LibraryAbstract;
use Psc\Kernel;
use Psc\Utils\Output;
use Revolt\EventLoop;
use Throwable;

use function Co\delay;
use function Co\registerForkHandler;
use function Co\tick;
use function spl_object_hash;

/**
 * 2024-07-13 principle
 *
 * async is a single-line Fiber independent of EventLoop. Operations on Fiber must take into account the coroutine space of EventLoop.
 * Any suspend/resume should be responsible for the Fiber of the current operation, including the return processing of the results
 *
 * 2024-07-13 Compatible with Process module
 */
class Coroutine extends LibraryAbstract
{
    /**
     * @var LibraryAbstract
     */
    protected static LibraryAbstract $instance;
    /**
     * @var array $fiber2promise
     */
    private array $fiber2callback = array();

    public function __construct()
    {
        $this->registerOnFork();
    }

    /**
     * @return void
     */
    private function registerOnFork(): void
    {
        registerForkHandler(function () {
            $this->fiber2callback = array();
            $this->registerOnFork();
        });
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/28 10:25
     * @return bool
     */
    public static function isCoroutine(): bool
    {
        return Coroutine::getInstance()->hasCallback();
    }

    /**
     * @return bool
     */
    public function hasCallback(): bool
    {
        if (!$fiber = Fiber::getCurrent()) {
            return false;
        }

        if (!isset($this->fiber2callback[spl_object_hash($fiber)])) {
            return false;
        }

        return true;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/28 10:24
     * @return array|null
     */
    public static function getCurrent(): array|null
    {
        return Coroutine::getInstance()->getCoroutine();
    }

    /**
     * @return array|null
     */
    public function getCoroutine(): array|null
    {
        if (!$fiber = Fiber::getCurrent()) {
            return null;
        }

        return $this->fiber2callback[spl_object_hash($fiber)] ?? null;
    }

    /**
     * @param Promise $promise
     *
     * @return mixed
     * @throws Throwable
     */
    public function await(Promise $promise): mixed
    {
        if ($promise->getStatus() === Promise::FULFILLED) {
            $result = $promise->getResult();
            if ($result instanceof Promise) {
                return $this->await($result);
            }
            return $result;
        }

        if ($promise->getStatus() === Promise::REJECTED) {
            throw $promise->getResult();
        }

        if (!$fiber = Fiber::getCurrent()) {
            $suspend = EventLoop::getSuspension();
            $promise->then(fn ($result) => $suspend->resume($result));
            $promise->except(fn (mixed $e) => $suspend->throw($e));

            $result = $suspend->suspend();
            if ($result instanceof Promise) {
                return $this->await($result);
            }
            return $result;
        }

        if (!$callback = $this->fiber2callback[spl_object_hash($fiber)] ?? null) {
            $promise->then(fn ($result) => $fiber->resume($result));
            $promise->except(
                fn (mixed $e) => $e instanceof Throwable
                    ? $fiber->throw($e)
                    : $fiber->throw(new Exception('An exception occurred in the awaited Promise'))
            );

            $result = $fiber->suspend();
            if ($result instanceof Promise) {
                return $this->await($result);
            }
            return $result;
        }

        /**
         * To determine your own control over preparing Fiber, you must be responsible for the subsequent status of Fiber.
         */
        // When the status of the awaited Promise is completed
        $promise->then(static function (mixed $result) use ($fiber, $callback) {
            try {
                // Try to resume Fiber operation
                $fiber->resume($result);

                // Fiber has been terminated
                if ($fiber->isTerminated()) {
                    try {
                        $callback['resolve']($fiber->getReturn());
                        return;
                    } catch (FiberError $e) {
                        $callback['reject']($e);
                        return;
                    }
                }
            } catch (EscapeException $exception) {
                // An escape exception occurs during recovery operation
                $this->handleEscapeException($exception);
            } catch (Throwable $e) {
                // Unexpected exception occurred during recovery operation

                $callback['reject']($e);
                return;
            }
        });

        // When rejected by the status of the awaited Promise
        $promise->except(static function (mixed $e) use ($fiber, $callback) {
            try {
                // Try to notice Fiber: An exception occurred in the awaited Promise
                $e instanceof Throwable
                    ? $fiber->throw($e)
                    : $fiber->throw(new Exception('An exception occurred in the awaited Promise'));

                // Fiber has been terminated
                if ($fiber->isTerminated()) {
                    try {
                        $callback['resolve']($fiber->getReturn());
                        return;
                    } catch (FiberError $e) {
                        $callback['reject']($e);
                        return;
                    }
                }
            } catch (EscapeException $exception) {
                // An escape exception occurs during recovery operation
                $this->handleEscapeException($exception);
            } catch (Throwable $e) {
                // Unexpected exception occurred during recovery operation
                $callback['reject']($e);
                return;
            }
        });

        // Confirm that you have prepared to handle Fiber recovery and take over control of Fiber by suspending it
        $result = $fiber->suspend();
        if ($result instanceof Promise) {
            return $this->await($result);
        }
        return $result;
    }

    /**
     * @param EscapeException $exception
     *
     * @return void
     * @throws EscapeException
     * @throws Throwable
     */
    public function handleEscapeException(EscapeException $exception): void
    {
        if (!Fiber::getCurrent() || !$this->hasCallback()) {
            $this->fiber2callback = array();
            tick();
            exit(0);
        } else {
            throw $exception;
        }
    }

    /**
     * @param Closure $closure
     *
     * @return Promise
     */
    public function async(Closure $closure): Promise
    {
        return new Promise(function (Closure $r, Closure $d, Promise $promise) use ($closure) {
            $fiber = new Fiber($closure);
            $hash  = spl_object_hash($fiber);

            $this->fiber2callback[$hash] = array(
                'resolve' => $r,
                'reject'  => $d,
                'promise' => $promise,
                'fiber'   => $fiber,
            );

            try {
                $fiber->start($r, $d);
            } catch (EscapeException $exception) {
                $this->handleEscapeException($exception);
            }

            if ($fiber->isTerminated()) {
                try {
                    $result = $fiber->getReturn();
                    $r($result);
                    return;
                } catch (FiberError $e) {
                    $d($e);
                    return;
                }
            }

            $promise->finally(function () use ($fiber) {
                unset($this->fiber2callback[spl_object_hash($fiber)]);
            });
        });
    }

    /**
     * @param float|int $second
     *
     * @return void
     */
    public function sleep(float|int $second): void
    {
        if (!$fiber = Fiber::getCurrent()) {
            //is Revolt
            $suspension = EventLoop::getSuspension();
            Kernel::getInstance()->delay(fn () => $suspension->resume(), $second);
            $suspension->suspend();

        } elseif (!$callback = $this->fiber2callback[spl_object_hash($fiber)] ?? null) {
            //is Revolt
            $suspension = EventLoop::getSuspension();
            Kernel::getInstance()->delay(fn () => $suspension->resume(), $second);
            $suspension->suspend();

        } else {
            delay(function () use ($fiber, $callback) {
                try {
                    // Try to resume Fiber operation
                    $fiber->resume();
                } catch (EscapeException $exception) {
                    // An escape exception occurs during recovery operation
                    $this->handleEscapeException($exception);
                } catch (Throwable $e) {
                    // Unexpected exception occurred during recovery operation

                    $callback['reject']($e);
                    return;
                }

                if ($fiber->isTerminated()) {
                    try {
                        $result = $fiber->getReturn();
                        $callback['resolve']($result);
                        return;
                    } catch (FiberError $e) {
                        $callback['reject']($e);
                        return;
                    }
                }
            }, $second);

            try {
                $fiber->suspend();
            } catch (Throwable $e) {
                Output::exception($e);
            }
        }
    }
}
