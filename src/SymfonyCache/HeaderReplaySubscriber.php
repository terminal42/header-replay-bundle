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
use Terminal42\HeaderReplay\EventListener\HeaderReplayListener;

class HeaderReplaySubscriber implements EventSubscriberInterface
{
    const BACKUP_ACCEPT_HEADER = 'T42-Replay-Headers-Original-Accept';

    /**
     * Retry
     * @var int
     */
    private $retry = 0;

    /**
     * @param CacheEvent $event
     */
    public function preHandle(CacheEvent $event)
    {
        $request = $event->getRequest();

        if (0 === $this->retry) {
            $request->headers->set(self::BACKUP_ACCEPT_HEADER, $request->headers->get('Accept'));
            $request->headers->set('Accept', HeaderReplayListener::CONTENT_TYPE);
        }
    }

    /**
     * @param CacheEvent $event
     */
    public function postHandle(CacheEvent $event)
    {
        $response = $event->getResponse();

        if (null === $response || $this->retry > 0) {
            return;
        }

        if (!$response->headers->has(HeaderReplayListener::REPLAY_HEADER_NAME)
            && !$response->headers->has(HeaderReplayListener::FORCE_NO_CACHE_HEADER_NAME)
        ) {
            return;
        }

        if (HeaderReplayListener::CONTENT_TYPE !== $response->headers->get('Content-Type')) {
            return;
        }

        // Replay
        $request = $event->getRequest();
        $headersToReplay = explode(',', $response->headers->get(HeaderReplayListener::REPLAY_HEADER_NAME));

        foreach ($headersToReplay as $header) {
            $request->headers->set($header, $response->headers->get($header));
        }

        // Reset original Accept header
        $request->headers->set('Accept', $request->headers->get(self::BACKUP_ACCEPT_HEADER));
        $request->headers->remove(self::BACKUP_ACCEPT_HEADER);

        // Force no cache
        if ($response->headers->has(HeaderReplayListener::FORCE_NO_CACHE_HEADER_NAME)) {
            $request->headers->addCacheControlDirective('no-cache');
        }

        // Increase retry level
        $this->retry++;

        // This is now actually the real request to the application with the
        // added headers you can Vary on in your application.
        $response = $event->getKernel()->handle($request);

        $event->setResponse($response);
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
}
