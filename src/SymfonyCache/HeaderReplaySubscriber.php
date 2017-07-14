<?php

/*
 * terminal42/header-replay-bundle for Symfony
 *
 * @copyright  Copyright (c) 2008-2017, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 * @link       http://github.com/terminal42/header-replay-bundle
 */

namespace Terminal42\HeaderReplay\SymfonyCache;

use FOS\HttpCache\SymfonyCache\CacheEvent;
use FOS\HttpCache\SymfonyCache\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;
use Terminal42\HeaderReplay\EventListener\HeaderReplayListener;

class HeaderReplaySubscriber implements EventSubscriberInterface
{
    /**
     * @var array
     */
    private $userContextHeaders = [];

    /**
     * HeaderReplaySubscriber constructor.
     *
     * @param array $userContextHeaders
     */
    public function __construct(array $userContextHeaders = ['Cookie', 'Authorization'])
    {
        $this->userContextHeaders = array_map('strtolower', $userContextHeaders);
    }

    /**
     * @return array
     */
    public function getUserContextHeaders()
    {
        return $this->userContextHeaders;
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
        $duplicate->headers->set('Accept', HeaderReplayListener::CONTENT_TYPE);

        // Pass the duplicated request to the original kernel (so cache is bypassed)
        // which should result in the preflight request response handled by the
        // HeaderReplayListener
        $preflightResponse = $httpCache->getKernel()->handle($duplicate);

        // If the response is not from the HeaderReplayListener we don't know
        // who handled it, so we don't do anything
        if (200 !== $preflightResponse->getStatusCode()
            || HeaderReplayListener::CONTENT_TYPE !== $preflightResponse->headers->get('Content-Type')
            || !($preflightResponse->headers->has(HeaderReplayListener::REPLAY_HEADER_NAME)
                || $preflightResponse->headers->has(HeaderReplayListener::FORCE_NO_CACHE_HEADER_NAME))
        ) {
            return;
        }

        // Otherwise it's our HeaderReplayListener so we replay the headers onto the original request
        $headersToReplay = explode(',', $preflightResponse->headers->get(HeaderReplayListener::REPLAY_HEADER_NAME));

        foreach ($headersToReplay as $header) {
            $request->headers->set($header, $preflightResponse->headers->get($header));
        }

        // Force no cache
        if ($preflightResponse->headers->has(HeaderReplayListener::FORCE_NO_CACHE_HEADER_NAME)) {
            $request->headers->addCacheControlDirective('no-cache');
        }

        // The original request now has our decorated/replayed headers and the
        // kernel can continue normally
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            Events::PRE_HANDLE => 'preHandle',
        ];
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
        foreach ($this->userContextHeaders as $contextHeader) {
            if ($request->headers->has($contextHeader)) {
                $oneMatches = true;
                break;
            }

            if ('cookie' === $contextHeader) {
                if (0 !== $request->cookies->count()) {
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
}
