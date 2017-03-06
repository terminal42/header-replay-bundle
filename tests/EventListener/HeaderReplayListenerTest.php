<?php

/*
 * terminal42/header-replay-bundle for Symfony
 *
 * @copyright  Copyright (c) 2008-2017, terminal42 gmbh
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
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Terminal42\HeaderReplay\Event\HeaderReplayEvent;
use Terminal42\HeaderReplay\EventListener\HeaderReplayListener;

class HeaderReplayListenerTest extends TestCase
{
    public function testSetAndGetUsercontextHeaders()
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $listener = new HeaderReplayListener($dispatcher);

        $this->assertSame(['cookie', 'authorization'], $listener->getUserContextHeaders());
        $listener->setUserContextHeaders(['Whatever', 'Foobar']);
        $this->assertSame(['whatever', 'foobar'], $listener->getUserContextHeaders());
    }

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

    /**
     * @param Request $request
     * @dataProvider nothingHappensIfRequestIsNotApplicable
     */
    public function testNothingHappensIfRequestIsNotApplicable(Request $request)
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())
            ->method('dispatch');

        $listener = new HeaderReplayListener($dispatcher);

        $event = new GetResponseEvent(
            $this->mockKernel(),
            $request,
            HttpKernelInterface::MASTER_REQUEST
        );

        $listener->onKernelRequest($event);
    }

    public function testNothingHappensIfRequestApplicableButNoHeaders()
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $listener = new HeaderReplayListener($dispatcher);

        $request = Request::create('foobar', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic foobar']);
        $request->headers->set('Accept', HeaderReplayListener::CONTENT_TYPE);

        $event = new GetResponseEvent(
            $this->mockKernel(),
            $request,
            HttpKernelInterface::MASTER_REQUEST
        );

        $listener->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testValidResponseWithoutTtl()
    {
        $request = Request::create('foobar', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic foobar']);
        $request->headers->set('Accept', HeaderReplayListener::CONTENT_TYPE);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(HeaderReplayEvent::EVENT_NAME, function(HeaderReplayEvent $event) {
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

    public function testValidResponseWithTtl()
    {
        $request = Request::create('foobar', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic foobar']);
        $request->headers->set('Accept', HeaderReplayListener::CONTENT_TYPE);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(HeaderReplayEvent::EVENT_NAME, function(HeaderReplayEvent $event) {
            $event->getHeaders()->set('Foo', 'Bar');
            $event->setTtl(40);
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
        $this->assertSame('foo', $event->getResponse()->headers->get(HeaderReplayListener::REPLAY_HEADER_NAME));
        $this->assertTrue($event->getResponse()->isCacheable());
        $this->assertTrue($event->getResponse()->headers->hasCacheControlDirective('max-age'));
    }

    public function nothingHappensIfRequestIsNotApplicable()
    {
        return [
            [
                Request::create('foobar', 'POST') // Not a GET request
            ],
            [
                Request::create('foobar', 'GET') // Not having any user context header
            ],
            [
                Request::create('foobar', 'GET', [], ['Test-Cookie']) // Not having the correct Content-Type but Cookie
            ],
            [
                Request::create('foobar', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic foobar']) // Not having the correct Content-Type but Authorization
            ],
        ];
    }

    private function mockKernel()
    {
        return $this->createMock(HttpKernelInterface::class);
    }
}
