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

use FOS\HttpCache\SymfonyCache\CacheEvent;
use FOS\HttpCache\SymfonyCache\CacheInvalidation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Terminal42\HeaderReplay\EventListener\HeaderReplayListener;
use Terminal42\HeaderReplay\SymfonyCache\HeaderReplaySubscriber;

class HeaderReplaySubscriberTest extends TestCase
{
    public function testSubscribedEvents()
    {
        $subscriber = new HeaderReplaySubscriber();
        $events = $subscriber::getSubscribedEvents();

        $this->assertCount(2, $events);
    }

    public function testPreHandle()
    {
        $request = Request::create('/foobar');
        $request->headers->set('Accept', 'foobar');
        $cacheEvent = new CacheEvent(
            $this->createMock(CacheInvalidation::class),
            $request
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($cacheEvent);

        $this->assertSame('foobar', $cacheEvent->getRequest()->headers->get(HeaderReplaySubscriber::BACKUP_ACCEPT_HEADER));
        $this->assertSame(HeaderReplayListener::CONTENT_TYPE, $cacheEvent->getRequest()->headers->get('Accept'));
    }

    public function testNothingHappensInPostHandleIfResponseNull()
    {
        $kernel = $this->createMock(CacheInvalidation::class);
        $kernel
            ->expects($this->never())
            ->method('handle');

        $request = Request::create('/foobar');
        $cacheEvent = new CacheEvent(
            $kernel,
            $request
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->postHandle($cacheEvent);
    }

    public function testNothingHappensInPostHandleIfResponseHasNoHeaders()
    {
        $kernel = $this->createMock(CacheInvalidation::class);
        $kernel
            ->expects($this->never())
            ->method('handle');

        $response = new Response();

        $request = Request::create('/foobar');
        $cacheEvent = new CacheEvent(
            $kernel,
            $request,
            $response
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->postHandle($cacheEvent);
    }

    public function testNothingHappensInPostHandleIfResponseHasWrongContentType()
    {
        $kernel = $this->createMock(CacheInvalidation::class);
        $kernel
            ->expects($this->never())
            ->method('handle');

        $response = new Response();
        $response->headers->set(HeaderReplayListener::REPLAY_HEADER_NAME, 'foobar');

        $request = Request::create('/foobar');
        $cacheEvent = new CacheEvent(
            $kernel,
            $request,
            $response
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->postHandle($cacheEvent);
    }

    public function testReplayHeadersInPostHandle()
    {
        /** @var Request $requestPassedToHandle */
        $requestPassedToHandle = null;

        $kernel = $this->createMock(CacheInvalidation::class);
        $kernel
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(function($request) use (&$requestPassedToHandle) {
                $requestPassedToHandle = $request;
                return true;
            }))
            ->willReturn(new Response());

        $response = new Response();
        $response->headers->set(HeaderReplayListener::REPLAY_HEADER_NAME, 'foobar,twobar');
        $response->headers->set('Content-Type', HeaderReplayListener::CONTENT_TYPE);
        $response->headers->set('foobar', 'nonsense');
        $response->headers->set('twobar', 'whatever');

        $request = Request::create('/foobar');
        $request->headers->set('Accept', 'complicated-original-accept');
        $cacheEvent = new CacheEvent(
            $kernel,
            $request,
            $response
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($cacheEvent);
        $subscriber->postHandle($cacheEvent);

        // Assert headers replicated
        $this->assertSame('nonsense', $requestPassedToHandle->headers->get('foobar'));
        $this->assertSame('whatever', $requestPassedToHandle->headers->get('twobar'));
        $this->assertSame('complicated-original-accept', $requestPassedToHandle->headers->get('Accept'));

        // Assert "internal" headers are not present anymore
        $this->assertNull($requestPassedToHandle->headers->get(HeaderReplaySubscriber::BACKUP_ACCEPT_HEADER));
        $this->assertNull($requestPassedToHandle->headers->get(HeaderReplayListener::REPLAY_HEADER_NAME));
    }

    public function testForceNoCacheInPostHandle()
    {
        /** @var Request $requestPassedToHandle */
        $requestPassedToHandle = null;

        $kernel = $this->createMock(CacheInvalidation::class);
        $kernel
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(function($request) use (&$requestPassedToHandle) {
                $requestPassedToHandle = $request;
                return true;
            }))
            ->willReturn(new Response());

        $response = new Response();
        $response->headers->set(HeaderReplayListener::FORCE_NO_CACHE_HEADER_NAME, 'not-relevant');
        $response->headers->set('Content-Type', HeaderReplayListener::CONTENT_TYPE);

        $request = Request::create('/foobar');
        $cacheEvent = new CacheEvent(
            $kernel,
            $request,
            $response
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($cacheEvent);
        $subscriber->postHandle($cacheEvent);

        // Assert headers replicated
        $this->assertTrue($requestPassedToHandle->headers->getCacheControlDirective('no-cache'));
    }
}
