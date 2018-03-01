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

use Symfony\Component\HttpKernel\Event\PostResponseEvent;

/**
 * Class HeaderReplayStopPropagationListener.
 *
 * This listener is registered with a very high priority on the kernel.terminate
 * event. It makes sure to stop propagation of the event if the current
 * request is a preflight request. kernel.terminate event listeners are usually
 * services that perform "heavy" actions so we certainly do not want these to be
 * executed during a preflight request.
 * If you really need to do this for whatever reason, register a listener
 * with an even higher priority.
 *
 * This is not relevant if you use the Symfony HttpCache because kernel.terminate
 * will not be called during the preflight request (same process). It is relevant
 * if you use a separate reverse proxy such as Varnish and spawn two separate
 * PHP processes. It is enabled by default so it works out of the box for any
 * reverse proxy you put in place.
 */
class HeaderReplayStopPropagationListener
{
    /**
     * @param PostResponseEvent $event
     */
    public function onKernelTerminate(PostResponseEvent $event)
    {
        if (\in_array(
            HeaderReplayListener::CONTENT_TYPE,
            $event->getRequest()->getAcceptableContentTypes(), true)
        ) {
            $event->stopPropagation();
        }
    }
}
