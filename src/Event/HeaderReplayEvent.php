<?php

/*
 * terminal42/header-replay-bundle for Symfony
 *
 * @copyright  Copyright (c) 2008-2018, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 * @link       http://github.com/terminal42/header-replay-bundle
 */

namespace Terminal42\HeaderReplay\Event;

use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class HeaderReplayEvent extends Event
{
    const EVENT_NAME = 'terminal42.header_replay';

    /**
     * @var Request
     */
    private $request;

    /**
     * @var ResponseHeaderBag
     */
    private $headers;

    /**
     * ReplayHeadersEvent constructor.
     *
     * @param Request           $request
     * @param ResponseHeaderBag $headers
     */
    public function __construct(Request $request, ResponseHeaderBag $headers)
    {
        $this->request = $request;
        $this->headers = $headers;
    }

    /**
     * @return int
     * @codeCoverageIgnore
     */
    public function getTtl()
    {
        @trigger_error('Using HeaderReplayEvent::getTtl() and will always return 0. Method will be removed in version 2.0.', E_USER_DEPRECATED);

        return 0;
    }

    /**
     * Sets the TTL for the replay headers response.
     * 0 equals no-cache. Otherwise the lowest set value of all
     * event listeners will be taken into account.
     *
     * @param int $ttl
     *
     * @return $this
     * @codeCoverageIgnore
     */
    public function setTtl($ttl)
    {
        @trigger_error('Using HeaderReplayEvent::setTtl() has been deprecated and has no effect. Method will be removed in version 2.0.', E_USER_DEPRECATED);

        return $this;
    }

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return ResponseHeaderBag
     */
    public function getHeaders()
    {
        return $this->headers;
    }
}
