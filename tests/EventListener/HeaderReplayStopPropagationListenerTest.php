<?php

/*
 * terminal42/header-replay-bundle for Symfony
 *
 * @copyright  Copyright (c) 2008-2018, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 * @link       http://github.com/terminal42/header-replay-bundle
 */

namespace Terminal42\HeaderReplay\Test\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Terminal42\HeaderReplay\EventListener\HeaderReplayListener;
use Terminal42\HeaderReplay\EventListener\HeaderReplayStopPropagationListener;

class HeaderReplayStopPropagationListenerTest extends TestCase
{
    public function testPropagationStopped()
    {
        $listener = new HeaderReplayStopPropagationListener();

        $response = new Response();
        $request = new Request();
        $request->headers->set('Accept', HeaderReplayListener::CONTENT_TYPE);

        // kernel.terminate
        $event = new PostResponseEvent($this->mockKernel(), $request, $response);

        $listener->onKernelTerminate($event);
        $this->assertTrue($event->isPropagationStopped());
    }

    public function testPropagationNotStopped()
    {
        $listener = new HeaderReplayStopPropagationListener();

        $response = new Response();
        $request = new Request();

        // kernel.terminate
        $event = new PostResponseEvent($this->mockKernel(), $request, $response);

        $listener->onKernelTerminate($event);
        $this->assertFalse($event->isPropagationStopped());
    }

    private function mockKernel()
    {
        return $this->createMock(HttpKernelInterface::class);
    }
}
