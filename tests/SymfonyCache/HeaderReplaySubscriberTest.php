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

use FOS\HttpCache\SymfonyCache\CacheEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

        $this->assertCount(2, $events);
    }

    public function testUserContextHeaders()
    {
        $subscriber = new HeaderReplaySubscriber();
        $this->assertSame(['cookie', 'authorization'], $subscriber->getUserContextHeaders());

        // Test BC
        $subscriber = new HeaderReplaySubscriber(['Whatever', 'Foobar']);
        $this->assertSame(['whatever', 'foobar'], $subscriber->getUserContextHeaders());

        $subscriber = new HeaderReplaySubscriber(['user_context_headers' => ['Whatever', 'Foobar']]);
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

    /**
     * @param array $cookies
     * @param array $ignoreCookies
     * @param bool  $expectsPreflight
     *
     * @dataProvider ignoreCookiesOption
     */
    public function testIgnoreCookiesOption(array $cookies, array $ignoreCookies, $expectsPreflight)
    {
        $response = new Response();
        $kernel = $this->createMock(HttpCache::class);
        $kernel
            ->expects($expectsPreflight ? $this->once() : $this->never())
            ->method('handle')
            ->willReturn($response);

        $httpCache = $this->getHttpCacheKernelWithGivenKernel($kernel);

        $request = Request::create('/foobar', 'GET');
        $request->cookies->replace($cookies);

        $cacheEvent = new CacheEvent(
            $httpCache,
            $request
        );

        $subscriber = new HeaderReplaySubscriber([
            'ignore_cookies' => $ignoreCookies,
        ]);
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

    public function testEarlyAbortIfRedirectionOnPreflightResponse()
    {
        $response = new RedirectResponse('https://foobar.com');

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

        $this->assertSame($response, $cacheEvent->getResponse());
    }

    /**
     * @param Response $response
     * @dataProvider noHeadersAddedAndEarlyResponseIfResponseIsNotACorrectPreflightResponse
     */
    public function testNoHeadersAddedButCookiesAreReplayedIfResponseIsNotACorrectPreflightResponse(Response $response)
    {
        $validCookie = new Cookie('i-am-valid', 'foobar', time() + 5000);
        $expiredCookie = new Cookie('i-am-expired', 'foobar', 1);

        $response->headers->setCookie($validCookie);
        $response->headers->setCookie($expiredCookie);

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

        // Assert response was not set to event but instead just left for
        // the kernel to be handled
        $this->assertNull($cacheEvent->getResponse());

        // Assert cookies correctly replayed
        $this->assertSame('foobar', $request->cookies->get('i-am-valid'));
        $this->assertFalse($request->cookies->has('i-am-expired'));
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

        // Assert headers correctly replayed
        $this->assertSame('nonsense', $request->headers->get('foobar'));
        $this->assertSame('whatever', $request->headers->get('twobar'));
    }

    public function testCookiesAreCorrectlyReplayedToRequest()
    {
        $validCookie = new Cookie('i-am-valid', 'foobar', time() + 5000);
        $expiredCookie = new Cookie('i-am-expired', 'foobar', 1);

        $response = new Response();
        $response->headers->set('Content-Type', HeaderReplayListener::CONTENT_TYPE);
        $response->headers->setCookie($validCookie);
        $response->headers->setCookie($expiredCookie);

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

        // Assert cookies correctly replayed
        $this->assertSame('foobar', $request->cookies->get('i-am-valid'));
        $this->assertFalse($request->cookies->has('i-am-expired'));
    }

    public function testCookiesAreCorrectlyReplayedToResponse()
    {
        $cookieNotPresent = new Cookie('i-am-not-present-on-request', 'foobar', time() + 5000);

        $preflightResponse = new Response();
        $preflightResponse->headers->set('Content-Type', HeaderReplayListener::CONTENT_TYPE);
        $preflightResponse->headers->setCookie($cookieNotPresent);

        $kernel = $this->createMock(HttpCache::class);
        $kernel
            ->expects($this->exactly(2))
            ->method('handle')
            ->willReturn($preflightResponse);

        $httpCache = $this->getHttpCacheKernelWithGivenKernel($kernel);

        $request = Request::create('/foobar', 'GET');
        $request->cookies->set('Foo', 'Bar');

        $preHandleCacheEvent = new CacheEvent(
            $httpCache,
            $request
        );

        $subscriber = new HeaderReplaySubscriber();
        $subscriber->preHandle($preHandleCacheEvent);

        $emptyResponse = new Response();

        $postHandleCacheEvent = new CacheEvent(
            $httpCache,
            $request,
            $emptyResponse
        );

        // Imitate an irrelevant subrequest such as an ESI request
        $subscriber->preHandle($preHandleCacheEvent);
        $subscriber->postHandle($postHandleCacheEvent);

        $subscriber->postHandle($postHandleCacheEvent);

        $response = $postHandleCacheEvent->getResponse();
        $cookies = $response->headers->getCookies();

        $this->assertSame('i-am-not-present-on-request', $cookies[0]->getName());
        $this->assertSame('foobar', $cookies[0]->getValue());
    }

    public function testPostHandleDoesNotCrashIfNoPreflightResponse()
    {
        $response = new Response();

        $kernel = $this->createMock(HttpCache::class);
        $kernel
            ->expects($this->never())
            ->method('handle')
            ->willReturn($response);

        $httpCache = $this->getHttpCacheKernelWithGivenKernel($kernel);

        $request = Request::create('/foobar', 'GET');
        $preHandleCacheEvent = new CacheEvent(
            $httpCache,
            $request
        );

        $subscriber = new HeaderReplaySubscriber();

        $postHandleCacheEvent = new CacheEvent(
            $httpCache,
            $request,
            $response
        );

        $subscriber->preHandle($preHandleCacheEvent);
        $subscriber->postHandle($postHandleCacheEvent); // Would cause an error if check was not present

        $this->addToAssertionCount(1);
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

        $this->assertTrue($request->headers->has('Expect'));
    }

    public function noHeadersAddedAndEarlyResponseIfResponseIsNotACorrectPreflightResponse()
    {
        return [
            'Response has wrong status code' => [
                new Response('', 400),
            ],
            'Response has wrong content type but correct status code' => [
                new Response('', 200, ['Content-Type', 'application/json']),
            ],
            'Response has correct content type and status code but no header' => [
                new Response('', 200, ['Content-Type', HeaderReplayListener::CONTENT_TYPE]),
            ],
        ];
    }

    public function ignoreCookiesOption()
    {
        return [
            'Preflight is executed if no ignore cookies option set' => [
                ['Cookie-Name' => 'Cookie-Value', 'Second-Cookie' => 'foobar'],
                [],
                true,
            ],
            'Preflight is executed if one ignore cookie rule is set but does not match' => [
                ['Cookie-Name' => 'Cookie-Value', 'Second-Cookie' => 'foobar'],
                ['/^Nonsense/'],
                true,
            ],
            'Preflight is executed if more than one ignore cookie rule is set but not all match' => [
                ['Cookie-Name' => 'Cookie-Value', 'Second-Cookie' => 'foobar'],
                ['/^Nonsense/', '/More-Nonsense$/'],
                true,
            ],
            'No preflight is executed if one ignore cookie rule and it does match' => [
                ['Cookie-Name' => 'Cookie-Value'],
                ['/^Cookie-Name$/'],
                false,
            ],
            'No preflight is executed if more than one ignore cookie rule and all of them match' => [
                ['Cookie-Name' => 'Cookie-Value', 'Second-Cookie' => 'foobar'],
                ['/^Cookie-Name$/', '/^Second.*$/'],
                false,
            ],
            'Preflight is executed if more than one ignore cookie rule and only one of them does match' => [
                ['Cookie-Name' => 'Cookie-Value', 'Second-Cookie' => 'foobar'],
                ['/^Cookie-Name$/', '/Nonsense(.*)/'],
                true,
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
