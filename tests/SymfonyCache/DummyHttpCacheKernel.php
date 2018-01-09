<?php

/*
 * terminal42/header-replay-bundle for Symfony
 *
 * @copyright  Copyright (c) 2008-2018, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 * @link       http://github.com/terminal42/header-replay-bundle
 */

namespace Terminal42\HeaderReplay\Test\SymfonyCache;

use FOS\HttpCache\SymfonyCache\CacheInvalidation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;

class DummyHttpCacheKernel extends HttpCache implements CacheInvalidation
{
    public function fetch(Request $request, $catch = false)
    {
        return parent::fetch($request, $catch);
    }
}
