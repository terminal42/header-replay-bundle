<?php

/*
 * terminal42/header-replay-bundle for Symfony
 *
 * @copyright  Copyright (c) 2008-2017, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 * @link       http://github.com/terminal42/header-replay-bundle
 */

namespace Terminal42\HeaderReplay\Test\Event;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Terminal42\HeaderReplay\Event\HeaderReplayEvent;

class HeaderReplayEventTest extends TestCase
{
    public function testSettersAndGetters()
    {
        $request = new Request();
        $headers = new ResponseHeaderBag();

        $event = new HeaderReplayEvent($request, $headers);

        $this->assertSame($request, $event->getRequest());
        $this->assertSame($headers, $event->getHeaders());

        $headers->set('Foo', 'Bar');

        $this->assertSame('Bar', $event->getHeaders()->get('Foo'));
    }

    public function testSetTtl()
    {
        $request = new Request();
        $headers = new ResponseHeaderBag();

        $event = new HeaderReplayEvent($request, $headers);

        $event->setTtl(0);
        $this->assertSame(0, $event->getTtl());

        $event->setTtl(50);
        $this->assertSame(50, $event->getTtl());

        $event->setTtl(100);
        $this->assertSame(50, $event->getTtl());

        $event->setTtl(30);
        $this->assertSame(30, $event->getTtl());
    }
}
