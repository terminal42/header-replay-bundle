<?php

/*
 * terminal42/header-replay-bundle for Symfony
 *
 * @copyright  Copyright (c) 2008-2018, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 * @link       http://github.com/terminal42/header-replay-bundle
 */

namespace Terminal42\HeaderReplay\EventListener;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
     * HeaderReplayListener constructor.
     *
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
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

        // If Content-Type matches, we always have to send a response so we
        // abort if this is not the case
        if (!\in_array(self::CONTENT_TYPE, $request->getAcceptableContentTypes(), true)) {
            return;
        }

        $response = new Response('', 200, ['Content-Type' => self::CONTENT_TYPE]);

        $replayEvent = new HeaderReplayEvent($event->getRequest(), new ResponseHeaderBag());
        $this->eventDispatcher->dispatch(HeaderReplayEvent::EVENT_NAME, $replayEvent);

        $headers = $replayEvent->getHeaders()->all();

        // Unset cache-control and date which is added by default
        unset($headers['cache-control'], $headers['date']);

        // No headers, return empty response
        if (0 === \count($headers)) {
            $event->setResponse($response);

            return;
        }

        $response->headers->add($headers);
        $response->headers->set(self::REPLAY_HEADER_NAME, implode(',', array_keys($headers)));

        $event->setResponse($response);
    }
}
