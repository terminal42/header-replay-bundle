<?php

/*
 * terminal42/header-replay-bundle for Symfony
 *
 * @copyright  Copyright (c) 2008-2017, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 * @link       http://github.com/terminal42/header-replay-bundle
 */

namespace Terminal42\HeaderReplay\EventListener;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Terminal42\HeaderReplay\Event\HeaderReplayEvent;

class HeaderReplayListener
{
    const CONTENT_TYPE = 'application/vnd.t42.header-replay';
    const REPLAY_HEADER_NAME = 'T42-Replay-Headers';
    const FORCE_NO_CACHE_HEADER_NAME = 'T42-Force-No-Cache';

     /**
      * @var EventDispatcherInterface
      */
     private $eventDispatcher;

    /**
     * @var array
     */
    private $userContextHeaders = [];

    /**
     * HeaderReplayListener constructor.
     *
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->setUserContextHeaders(['Cookie', 'Authorization']);
    }

    /**
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->checkRequest($request)) {
            return;
        }

        $replayEvent = new HeaderReplayEvent($event->getRequest(), new ResponseHeaderBag());
        $this->eventDispatcher->dispatch(HeaderReplayEvent::EVENT_NAME, $replayEvent);

        $headers = $replayEvent->getHeaders()->all();

        // Unset cache-control which is added by default
        unset($headers['cache-control']);

        if (0 === count($headers)) {
            return;
        }

        $response = new Response('', 200, ['Content-Type' => self::CONTENT_TYPE]);

        // TTL
        $ttl = $replayEvent->getTtl();

        if ($ttl > 0) {
            $response->setClientTtl($ttl);
            $response->setPublic();
        } else {
            $response->setClientTtl(0);
            $response->headers->addCacheControlDirective('no-cache');
        }

        $replayHeaders = [];

        foreach ($headers as $k => $v) {
            $replayHeaders[] = $k;
            $response->headers->set($k, $v);
        }

        $response->headers->set(self::REPLAY_HEADER_NAME, implode(',', array_unique($replayHeaders)));

        $event->setResponse($response);
    }

    /**
     * @return array
     */
    public function getUserContextHeaders()
    {
        return $this->userContextHeaders;
    }

    /**
     * @param array $userContextHeaders
     *
     * @return HeaderReplayListener
     */
    public function setUserContextHeaders($userContextHeaders)
    {
        $this->userContextHeaders = array_map('strtolower', $userContextHeaders);

        return $this;
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
        // Only GET requests are allowed
        if ('GET' !== $request->getMethod()) {
            return false;
        }

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

        if (!in_array(self::CONTENT_TYPE, $request->getAcceptableContentTypes(), true)) {
            return false;
        }

        return true;
    }
}
