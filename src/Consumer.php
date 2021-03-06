<?php

namespace Bernard;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Bernard\Event\EnvelopeEvent;
use Bernard\Event\PingEvent;
use Bernard\Event\RejectEnvelopeEvent;

/**
 * @package Consumer
 */
class Consumer
{
    protected $router;
    protected $dispatcher;
    protected $shutdown = false;
    protected $pause = false;
    protected $configured = false;
    protected $options = [
        'max-runtime' => PHP_INT_MAX,
        'max-messages' => null,
        'stop-when-empty' => false,
        'stop-on-error' => false,
    ];

    /**
     * @param Router                   $router
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(Router $router, EventDispatcherInterface $dispatcher)
    {
        $this->router = $router;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Starts an infinite loop calling Consumer::tick();
     *
     * @param Queue $queue
     * @param array $options
     */
    public function consume(Queue $queue, array $options = [])
    {
        declare (ticks = 1);

        $this->bind();

        while ($this->tick($queue, $options)) {
            // NO op
        }
    }

    /**
     * Returns true do indicate it should be run again or false to indicate
     * it should not be run again.
     *
     * @param Queue $queue
     * @param array $options
     *
     * @return bool
     */
    public function tick(Queue $queue, array $options = [])
    {
        $this->configure($options);

        if ($this->shutdown) {
            return false;
        }

        if (microtime(true) > $this->options['max-runtime']) {
            return false;
        }

        if ($this->pause) {
            return true;
        }

        $this->dispatcher->dispatch(BernardEvents::PING, new PingEvent($queue));

        if (!$envelope = $queue->dequeue()) {
            return !$this->options['stop-when-empty'];
        }

        $this->invoke($envelope, $queue);

        if (null === $this->options['max-messages']) {
            return true;
        }

        return (boolean) --$this->options['max-messages'];
    }

    /**
     * Mark Consumer as shutdown
     */
    public function shutdown()
    {
        $this->shutdown = true;
    }

    /**
     * Pause consuming
     */
    public function pause()
    {
        $this->pause = true;
    }

    /**
     * Resume consuming
     */
    public function resume()
    {
        $this->pause = false;
    }

    /**
     * Until there is a real extension point to doing invoked stuff, this can be used
     * by wrapping the invoke method.
     *
     * @param Envelope $envelope
     * @param Queue    $queue
     */
    public function invoke(Envelope $envelope, Queue $queue)
    {
        try {
            $this->dispatcher->dispatch(BernardEvents::INVOKE, new EnvelopeEvent($envelope, $queue));

            // for 5.3 support where a function name is not a callable
            call_user_func($this->router->map($envelope), $envelope->getMessage());

            // We successfully processed the message.
            $queue->acknowledge($envelope);

            $this->dispatcher->dispatch(BernardEvents::ACKNOWLEDGE, new EnvelopeEvent($envelope, $queue));
        } catch (\Exception $e) {
            // Make sure the exception is not interfering.
            // Previously failing jobs handling have been moved to a middleware.
            //
            // Emit an event to let others log that exception
            $this->dispatcher->dispatch(BernardEvents::REJECT, new RejectEnvelopeEvent($envelope, $queue, $e));

            if ($this->options['stop-on-error']) {
                throw $e;
            }
        }
    }

    /**
     * @param array $options
     */
    protected function configure(array $options)
    {
        if ($this->configured) {
            return $this->options;
        }

        $this->options = array_filter($options) + $this->options;
        $this->options['max-runtime'] += microtime(true);
        $this->configured = true;
    }

    /**
     * Setup signal handlers for unix signals.
     */
    protected function bind()
    {
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
        pcntl_signal(SIGINT,  [$this, 'shutdown']);
        pcntl_signal(SIGQUIT, [$this, 'shutdown']);
        pcntl_signal(SIGUSR2, [$this, 'pause']);
        pcntl_signal(SIGCONT, [$this, 'resume']);
    }
}
