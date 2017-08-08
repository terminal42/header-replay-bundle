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

use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;

/**
 * Class HeaderReplayStopPropagationListener.
 *
 * This listener is registered with a very high priority on both, the
 * kernel.response and the kernel.terminate listeners. It makes sure
 * it stops propagation of these events if the current request is a preflight
 * request. It never makes sense to modify a preflight request by one of these
 * events as the bundle provides its own event for this.
 * If you really need to do this for whatever reason, register a listener
 * with an even higher priority.
 */
class HeaderReplayStopPropagationListener
{
    /**
     * @param FilterResponseEvent $event
     */
    public function onKernelResponse(FilterResponseEvent $event)
    {
        $this->stopPropagationIfApplicable($event);
    }

    /**
     * @param FinishRequestEvent $event
     */
    public function onKernelTerminate(FinishRequestEvent $event)
    {
        $this->stopPropagationIfApplicable($event);
    }

    /**
     * @param KernelEvent $event
     */
    private function stopPropagationIfApplicable(KernelEvent $event)
    {
        $request = $event->getRequest();

        if (in_array(HeaderReplayListener::CONTENT_TYPE, $request->getAcceptableContentTypes(), true)) {
            $event->stopPropagation();
        }
    }
}
