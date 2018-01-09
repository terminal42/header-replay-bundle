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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Terminal42\HeaderReplay\Event\HeaderReplayEvent;
use Terminal42\HeaderReplay\EventListener\HeaderReplayListener;

class HeaderReplayListenerTest extends TestCase
{
    public function testNothingHappensIfNotMasterRequest()
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())
                ->method('dispatch');

        $listener = new HeaderReplayListener($dispatcher);
        $request = new Request();

        $event = new GetResponseEvent(
            $this->mockKernel(),
            $request,
            HttpKernelInterface::SUB_REQUEST
        );

        $listener->onKernelRequest($event);
    }

    public function testNothingHappensIfWrongAcceptHeader()
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())
            ->method('dispatch');

        $listener = new HeaderReplayListener($dispatcher);
        $request = Request::create('foobar', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic foobar']);

        $event = new GetResponseEvent(
            $this->mockKernel(),
            $request,
            HttpKernelInterface::MASTER_REQUEST
        );

        $listener->onKernelRequest($event);
    }

    public function testEmptyResponseWithCorrectContentTypeIfNoListeners()
    {
        $request = Request::create('foobar', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic foobar']);
        $request->headers->set('Accept', HeaderReplayListener::CONTENT_TYPE);

        $dispatcher = new EventDispatcher();
        $listener = new HeaderReplayListener($dispatcher);

        $event = new GetResponseEvent(
            $this->mockKernel(),
            $request,
            HttpKernelInterface::MASTER_REQUEST
        );

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(HeaderReplayListener::CONTENT_TYPE, $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has(HeaderReplayListener::REPLAY_HEADER_NAME));
    }

    public function testValidResponseWithoutTtl()
    {
        $request = Request::create('foobar', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic foobar']);
        $request->headers->set('Accept', HeaderReplayListener::CONTENT_TYPE);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(HeaderReplayEvent::EVENT_NAME, function (HeaderReplayEvent $event) {
            $event->getHeaders()->set('Foo', 'Bar');
            $event->getHeaders()->set('Foo2', 'Bar2');
        });

        $listener = new HeaderReplayListener($dispatcher);

        $event = new GetResponseEvent(
            $this->mockKernel(),
            $request,
            HttpKernelInterface::MASTER_REQUEST
        );

        $listener->onKernelRequest($event);

        $this->assertInstanceOf(Response::class, $event->getResponse());
        $this->assertSame('Bar', $event->getResponse()->headers->get('foo'));
        $this->assertSame('Bar2', $event->getResponse()->headers->get('foo2'));
        $this->assertSame('foo,foo2', $event->getResponse()->headers->get(HeaderReplayListener::REPLAY_HEADER_NAME));
        $this->assertFalse($event->getResponse()->isCacheable());
    }

    private function mockKernel()
    {
        return $this->createMock(HttpKernelInterface::class);
    }
}
