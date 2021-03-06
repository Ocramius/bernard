<?php

namespace Bernard\Event;

use Bernard\Envelope;
use Bernard\Queue;

/**
 * @package Bernard
 */
class RejectEnvelopeEvent extends EnvelopeEvent
{
    protected $exception;

    /**
     * @param Envelope   $envelope
     * @param Queue      $queue
     * @param \Exception $exception
     */
    public function __construct(Envelope $envelope, Queue $queue, \Exception $exception)
    {
        parent::__construct($envelope, $queue);

        $this->exception = $exception;
    }

    /**
     * @return \Exception
     */
    public function getException()
    {
        return $this->exception;
    }
}
