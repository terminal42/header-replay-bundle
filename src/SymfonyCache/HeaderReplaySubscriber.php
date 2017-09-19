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
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
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

        // If the response is not from the HeaderReplayListener somebody else
        // handled it so we go for an early return here
        if (200 !== $preflightResponse->getStatusCode()
            || HeaderReplayListener::CONTENT_TYPE !== $preflightResponse->headers->get('Content-Type')
        ) {
            $event->setResponse($preflightResponse);

            return;
        }

        // Replay headers onto original request
        if ($preflightResponse->headers->has(HeaderReplayListener::REPLAY_HEADER_NAME)) {
            $headersToReplay = explode(',', $preflightResponse->headers->get(HeaderReplayListener::REPLAY_HEADER_NAME));

            foreach ($headersToReplay as $header) {
                $request->headers->set($header, $preflightResponse->headers->get($header));
            }
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

        if (0 === count($this->options['ignore_cookies'])) {
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

        return 0 !== count($blackList);
    }
}
