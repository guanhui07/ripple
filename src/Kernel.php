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

namespace Psc;

use Closure;
use Fiber;
use P\Coroutine;
use P\System;
use Psc\Core\Coroutine\Promise;
use Psc\Library\System\Process\Process;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;

use function call_user_func;
use function count;

class Kernel
{
    /**
     * @var Kernel
     */
    public static Kernel $instance;

    /**
     * @return Kernel
     */
    public static function getInstance(): Kernel
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param Promise $promise
     * @return mixed
     * @throws Throwable
     */
    public function await(Promise $promise): mixed
    {
        return Coroutine::Async()->await($promise);
    }

    /**
     * async闭包中抛出的异常落地位置可能为调用上下文/挂起恢复处,因此对异常的管理要谨慎
     * @param Closure $closure
     * @return Promise
     */
    public function async(Closure $closure): Promise
    {
        return Coroutine::Async()->async($closure);
    }

    /**
     * @param Closure $closure
     * @return Promise
     */
    public function promise(Closure $closure): Promise
    {
        return new Promise($closure);
    }

    /**
     * @param int|float $second
     * @return void
     */
    public function sleep(int|float $second): void
    {
        $mainSuspension = EventLoop::getSuspension();
        $this->delay(fn () => $mainSuspension->resume(), $second);
        $mainSuspension->suspend();
    }

    /**
     * @param Closure   $closure
     * @param int|float $second
     * @return string
     */
    public function delay(Closure $closure, int|float $second): string
    {
        return EventLoop::delay($second, $closure);
    }

    /**
     * @param Closure $closure
     * @return void
     */
    public function defer(Closure $closure): void
    {
        EventLoop::defer($closure);
    }

    /**
     * @param string $id
     * @return void
     */
    public function cancel(string $id): void
    {
        if (EventLoop::getType($id) === CallbackType::Signal) {
            Process::getInstance()->cancelSignalEvent($id);
        }

        EventLoop::cancel($id);
    }

    /**
     * @param Closure(Closure):void $closure
     * @param int|float             $second
     * @return string
     */
    public function repeat(Closure $closure, int|float $second): string
    {
        return EventLoop::repeat($second, function ($cancelId) use ($closure) {
            call_user_func($closure, fn () => $this->cancel($cancelId));
        });
    }

    /**
     * @param int     $signal
     * @param Closure $closure
     * @return string
     * @throws UnsupportedFeatureException
     */
    public function onSignal(int $signal, Closure $closure): string
    {
        return System::Process()->onSignal($signal, $closure);
    }

    /**
     * @param Closure $closure
     * @return void
     */
    public function onFork(Closure $closure): void
    {
        System::Process()->onFork($closure);
    }

    /**
     * @var EventLoop\Suspension
     */
    public EventLoop\Suspension $mainSuspension;

    /**
     * @param int $microseconds
     * @return void
     */
    public function run(int $microseconds = 100000): void
    {
        //loop
        while (1) {
            //预加载阶段进入loop,凡跳出者声明预加载完毕
            $this->mainSuspension = EventLoop::getSuspension();

            if (count(EventLoop::getIdentifiers()) === 0) {
                //无事可做
                break;
            }

            $reinstall = $this->mainSuspension->suspend();

            if ($reinstall) {
                $reinstall();
            }
        }
    }

    /**
     * @param Closure|null $configure
     * @param bool         $jumpMain
     * @return void
     * @throws Throwable
     */
    public function reinstall(Closure|null $configure = null, bool $jumpMain = false): void
    {
        $this->handleOnMain(function () use ($configure) {
            $originDriver = EventLoop::getDriver();
            $originDriver->stop();

            @$originDriver->run();

            EventLoop::setDriver(
                (new EventLoop\DriverFactory())->create()
            );

            if ($configure) {
                $configure();
            }
        });

        if ($jumpMain) {
            Fiber::suspend();
        }
    }

    /**
     * @param Closure $closure
     * @return void
     */
    public function handleOnMain(Closure $closure): void
    {
        $this->mainSuspension->resume($closure);
    }
}