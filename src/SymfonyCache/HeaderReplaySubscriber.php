<?php

/*
 * terminal42/header-replay-bundle for Symfony
 *
 * @copyright  Copyright (c) 2008-2018, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 * @link       http://github.com/terminal42/header-replay-bundle
 */

namespace Terminal42\HeaderReplay\SymfonyCache;

use FOS\HttpCache\SymfonyCache\CacheEvent;
use FOS\HttpCache\SymfonyCache\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Terminal42\HeaderReplay\EventListener\HeaderReplayListener;

class HeaderReplaySubscriber implements EventSubscriberInterface
{
    /**
     * @var array
     */
    private $options = [];

    /**
     * @var Response[]
     */
    private $preflightResponses = [];

    /**
     * HeaderReplaySubscriber constructor.
     *
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        // BC
        if (isset($options[0])) {
            $options = ['user_context_headers' => $options];
        }

        $resolver = new OptionsResolver();
        $resolver->setDefault('user_context_headers', ['cookie', 'authorization']);
        $resolver->setNormalizer('user_context_headers', function (Options $options, $value) {
            return array_map('strtolower', $value);
        });
        $resolver->setDefault('ignore_cookies', []);

        $this->options = $resolver->resolve($options);
    }

    /**
     * @return array
     */
    public function getUserContextHeaders()
    {
        return $this->options['user_context_headers'];
    }

    /**
     * @param CacheEvent $event
     */
    public function preHandle(CacheEvent $event)
    {
        $httpCache = $event->getKernel();

        // If the kernel provided by the event is not an HttpCache kernel, we
        // don't know how to fetch the original kernel, so this cannot work.
        if (!$httpCache instanceof HttpCache) {
            return;
        }

        $request = $event->getRequest();

        // Preflight request is only relevant for cacheable requests
        if (!$request->isMethodCacheable()) {
            return;
        }

        // User context headers to not match, do not execute a preflight request
        if (!$this->checkRequest($request)) {
            return;
        }

        // Duplicate the original request for the accept header
        $duplicate = $request->duplicate();
        $duplicate->setMethod('HEAD');
        $duplicate->headers->set('Accept', HeaderReplayListener::CONTENT_TYPE);

        // Pass the duplicated request to the original kernel (so cache is bypassed)
        // which should result in the preflight request response handled by the
        // HeaderReplayListener
        $preflightResponse = $httpCache->getKernel()->handle($duplicate);

        // Early abort if the preflight response wanted to redirect for whatever
        // reason
        if ($preflightResponse->isRedirection()) {
            $event->setResponse($preflightResponse);

            return;
        }

        // Only replay headers if it's a valid preflight response
        if (200 === $preflightResponse->getStatusCode()
            && HeaderReplayListener::CONTENT_TYPE === $preflightResponse->headers->get('Content-Type')
        ) {
            $this->replayHeaders($preflightResponse, $request);
        }

        // Replay Set-Cookie headers (behave like a browser)
        $this->replayCookieHeadersToRequest($preflightResponse, $request);

        // Store preflight response for later reference in postHandle()
        $this->preflightResponses[] = $preflightResponse;

        // The original request now has our decorated/replayed headers if
        // applicable and the kernel can continue normally
    }

    /**
     * @param CacheEvent $event
     */
    public function postHandle(CacheEvent $event)
    {
        $this->replayCookieHeadersToResponse($event->getResponse());
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            Events::PRE_HANDLE => 'preHandle',
            Events::POST_HANDLE => 'postHandle',
        ];
    }

    /**
     * Replay headers onto original request.
     *
     * @param Response $preflightResponse
     * @param Request  $request
     */
    private function replayHeaders(Response $preflightResponse, Request $request)
    {
        if ($preflightResponse->headers->has(HeaderReplayListener::REPLAY_HEADER_NAME)) {
            $headersToReplay = array_map(
                'strtolower',
                explode(',', $preflightResponse->headers->get(HeaderReplayListener::REPLAY_HEADER_NAME))
            );

            foreach ($headersToReplay as $header) {
                // Make sure Set-Cookie is never considered because we do this
                // manually
                unset($headersToReplay['set-cookie']);

                $request->headers->set($header, $preflightResponse->headers->get($header));
            }
        }

        // Force no cache
        if ($preflightResponse->headers->has(HeaderReplayListener::FORCE_NO_CACHE_HEADER_NAME)) {
            $request->headers->set('Expect', '100-continue');
        }
    }

    /**
     * Replay the Cookies from the preflight request to the request in case they
     * are valid. Unset them if they are expired.
     *
     * @param Response $preflightResponse
     * @param Request  $request
     */
    private function replayCookieHeadersToRequest(Response $preflightResponse, Request $request)
    {
        /* @var Cookie[] $cookies */
        $cookies = $preflightResponse->headers->getCookies();
        foreach ($cookies as $cookie) {
            // Unset if cleared, replay if valid
            if ($cookie->isCleared()) {
                $request->cookies->remove($cookie->getName());
            } else {
                $request->cookies->set($cookie->getName(), $cookie->getValue());
            }
        }
    }

    /**
     * Replay the Cookies from the preflight response to the real response in case
     * they are not present.
     *
     * @param Response $response
     */
    private function replayCookieHeadersToResponse(Response $response)
    {
        $preflightResponse = array_pop($this->preflightResponses);

        if (!$preflightResponse instanceof Response) {
            return;
        }

        $responseCookieNames = [];

        /** @var Cookie $cookie */
        foreach ($response->headers->getCookies() as $cookie) {
            $responseCookieNames[] = $cookie->getName();
        }

        foreach ($preflightResponse->headers->getCookies() as $cookie) {
            if (!\in_array($cookie->getName(), $responseCookieNames, true)) {
                $response->headers->setCookie($cookie);
            }
        }
    }

    /**
     * Check if request is applicable.
     *
     * @param Request $request
     *
     * @return bool
     */
    private function checkRequest(Request $request)
    {
        // Only applicable if user context header submitted
        $oneMatches = false;
        foreach ($this->options['user_context_headers'] as $contextHeader) {
            if ($request->headers->has($contextHeader)) {
                $oneMatches = true;
                break;
            }

            if ('cookie' === $contextHeader) {
                if ($this->hasRelevantCookie($request->cookies)) {
                    $oneMatches = true;
                    break;
                }
            }
        }

        if (!$oneMatches) {
            return false;
        }

        return true;
    }

    /**
     * Checks if at least cookies were provided at all and if at least one
     * is relevant (not ignored) for a preflight request.
     *
     * @param ParameterBag $cookies
     *
     * @return bool
     */
    private function hasRelevantCookie(ParameterBag $cookies)
    {
        $count = $cookies->count();

        if (0 === \count($this->options['ignore_cookies'])) {
            return 0 !== $count;
        }

        $blackList = $cookies->all();

        foreach ($cookies as $name => $value) {
            foreach ($this->options['ignore_cookies'] as $rgxp) {
                if (preg_match($rgxp, $name)) {
                    unset($blackList[$name]);
                }
            }
        }

        return 0 !== \count($blackList);
    }
}
