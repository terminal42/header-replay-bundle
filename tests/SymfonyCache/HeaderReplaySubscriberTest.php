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
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;
use Symfony\Component\HttpKernel\HttpCache\SurrogateInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Terminal42\HeaderReplay\EventListener\HeaderReplayListener;
use Terminal42\HeaderReplay\SymfonyCache\HeaderReplaySubscriber;
use Terminal42\HeaderReplay\Test\SymfonyCache\DummyHttpCacheKernel;
use Terminal42\HeaderReplay\Test\SymfonyCache\DummyNonHttpCacheKernel;

class HeaderReplaySubscriberTest extends TestCase
{
    public function testSubscribedEvents()
    {
        $subscriber = new HeaderReplaySubscriber();
        $events = $subscriber::getSubscribedEvents();

        $this->assertCount(1, $events);
    }

    public function testUserContextHeaders()
    {
        $subscriber = new HeaderReplaySubscriber();
        $this->assertSame(['cookie', 'authorization'], $subscriber->getUserContextHeaders());

        $subscriber = new HeaderReplaySubscriber(['Whatever', 'Foobar']);
        $this->assertSame(['whatever', 'foobar'], $subscriber->getUserContextHeaders());
    }

    public function testNothingHappensIfKernelIsNotHttpCache()
    {
        $kernel = new DummyNonHttpCacheKernel();

        $request = $this->createMock(Request::class);
        $request
            ->expects($this->never())
            ->method('isMethodCacheable');

        $cacheEvent = new CacheEvent(
            $kernel,
            $request
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($cacheEvent);
    }

    public function testNothingHappensIfMethodNotCacheable()
    {
        $kernel = $this->createMock(HttpCache::class);
        $kernel
            ->expects($this->never())
            ->method('handle');

        $httpCache = $this->getHttpCacheKernelWithGivenKernel($kernel);

        $request = Request::create('/foobar', 'POST');

        $cacheEvent = new CacheEvent(
            $httpCache,
            $request
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($cacheEvent);
    }

    public function testNothingHappensIfContextHeadersNotGiven()
    {
        $kernel = $this->createMock(HttpCache::class);
        $kernel
            ->expects($this->never())
            ->method('handle');

        $httpCache = $this->getHttpCacheKernelWithGivenKernel($kernel);

        $request = Request::create('/foobar', 'GET');

        $cacheEvent = new CacheEvent(
            $httpCache,
            $request
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($cacheEvent);
    }

    public function testKernelIsCorrectlyCalledWithPreflightAcceptHeader()
    {
        $response = new Response();
        $kernel = $this->createMock(HttpCache::class);
        $kernel
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Request $request) {
                return 'HEAD' === $request->getMethod() &&
                    HeaderReplayListener::CONTENT_TYPE === $request->headers->get('Accept');
            }))
            ->willReturn($response);

        $httpCache = $this->getHttpCacheKernelWithGivenKernel($kernel);

        $request = Request::create('/foobar', 'GET');
        $request->cookies->set('Foo', 'Bar');

        $cacheEvent = new CacheEvent(
            $httpCache,
            $request
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($cacheEvent);
    }

    /**
     * @param Response $response
     * @dataProvider noHeadersAddedAndEarlyResponseIfResponseIsNotACorrectPreflightResponse
     */
    public function testNoHeadersAddedAndEarlyResponseIfResponseIsNotACorrectPreflightResponse(Response $response)
    {
        $kernel = $this->createMock(HttpCache::class);
        $kernel
            ->expects($this->once())
            ->method('handle')
            ->willReturn($response);

        $httpCache = $this->getHttpCacheKernelWithGivenKernel($kernel);

        $request = Request::create('/foobar', 'GET');
        $request->cookies->set('Foo', 'Bar');

        $preCount = $request->headers->count();

        $cacheEvent = new CacheEvent(
            $httpCache,
            $request
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($cacheEvent);

        // Assert no headers were added
        $this->assertSame($preCount, $request->headers->count());

        // Assert response was set to event
        $this->assertSame($response, $cacheEvent->getResponse());
    }

    public function testReplayHeaders()
    {
        $response = new Response();
        $response->headers->set(HeaderReplayListener::REPLAY_HEADER_NAME, 'foobar,twobar');
        $response->headers->set('Content-Type', HeaderReplayListener::CONTENT_TYPE);
        $response->headers->set('foobar', 'nonsense');
        $response->headers->set('twobar', 'whatever');

        $kernel = $this->createMock(HttpCache::class);
        $kernel
            ->expects($this->once())
            ->method('handle')
            ->willReturn($response);

        $httpCache = $this->getHttpCacheKernelWithGivenKernel($kernel);

        $request = Request::create('/foobar', 'GET');
        $request->cookies->set('Foo', 'Bar');

        $cacheEvent = new CacheEvent(
            $httpCache,
            $request
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($cacheEvent);

        // Assert headers replicated
        $this->assertSame('nonsense', $request->headers->get('foobar'));
        $this->assertSame('whatever', $request->headers->get('twobar'));
    }

    public function testForceNoCache()
    {
        $response = new Response();
        $response->headers->set(HeaderReplayListener::FORCE_NO_CACHE_HEADER_NAME, 'not-relevant');
        $response->headers->set('Content-Type', HeaderReplayListener::CONTENT_TYPE);

        $kernel = $this->createMock(HttpCache::class);
        $kernel
            ->expects($this->once())
            ->method('handle')
            ->willReturn($response);

        $httpCache = $this->getHttpCacheKernelWithGivenKernel($kernel);

        $request = Request::create('/foobar', 'GET');
        $request->headers->set('Authorization', 'foobar');

        $cacheEvent = new CacheEvent(
            $httpCache,
            $request
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($cacheEvent);

        //var_dump($request->headers->getCacheControlDirective());exit;
        $this->assertTrue($request->headers->getCacheControlDirective('no-cache'));
    }

    public function noHeadersAddedAndEarlyResponseIfResponseIsNotACorrectPreflightResponse()
    {
        return [
            'Response has wrong status code' => [
                new Response('', 303),
            ],
            'Response has wrong content type but correct status code' => [
                new Response('', 200, ['Content-Type', 'application/json']),
            ],
            'Response has correct content type and status code but no header' => [
                new Response('', 200, ['Content-Type', HeaderReplayListener::CONTENT_TYPE]),
            ],
        ];
    }

    /**
     * @param HttpKernelInterface $httpKernel
     *
     * @return DummyHttpCacheKernel
     */
    private function getHttpCacheKernelWithGivenKernel(HttpKernelInterface $httpKernel)
    {
        return new DummyHttpCacheKernel(
            $httpKernel,
            $this->createMock(StoreInterface::class),
            $this->createMock(SurrogateInterface::class)
        );
    }
}
