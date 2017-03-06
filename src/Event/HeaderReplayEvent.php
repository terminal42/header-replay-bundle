<?php

/*
 * terminal42/header-replay-bundle for Symfony
 *
 * @copyright  Copyright (c) 2008-2017, terminal42 gmbh
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
    const EVENT_NAME = 't42.header_replay';

    /**
     * @var Request
     */
    private $request;

    /**
     * @var ResponseHeaderBag
     */
    private $headers;

    /**
     * @var int
     */
    private $ttl = 0;

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
     */
    public function getTtl()
    {
        return $this->ttl;
    }

    /**
     * Sets the TTL for the replay headers response.
     * 0 equals no-cache. Otherwise the lowest set value of all
     * event listeners will be taken into account.
     *
     * @param int $ttl
     *
     * @return $this
     */
    public function setTtl($ttl)
    {
        if (0 === $ttl || 0 === $this->ttl) {
            $this->ttl = $ttl;

            return $this;
        }

        // Only set it if lower than existing TTL
        if ($ttl < $this->ttl) {
            $this->ttl = $ttl;
        }

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
