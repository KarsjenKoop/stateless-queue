<?php

namespace Karsjen\StatelessQueue\Adapters;

use Karsjen\StatelessQueue\Messages\OutgoingJobMessage;
use Karsjen\StatelessQueue\Contracts\OutboundAdapter;
use Illuminate\Support\Facades\Log;

/**
 * Adapter: NullAdapter
 *
 * A no-op outbound adapter for local development and testing. Instead of publishing to a queue
 * provider it writes the message to the application log, so you can see what would have been sent.
 *
 * ### Not for production
 * Two reasons, and the second is the one that bites:
 *
 * 1. **Nothing is published.** Jobs are logged and dropped. There is no queue, no delivery, and no
 *    webhook. This is the default adapter, so an application that never sets
 *    `STATELESS_QUEUE_ADAPTER` silently discards every job it pushes.
 * 2. **The whole payload goes to the log, at info level.** Job payloads carry whatever the
 *    application put in them — email addresses, order contents, tokens, personal data. Under this
 *    adapter all of it lands in the log file and in any aggregator the logs are shipped to, at a
 *    level most deployments keep. Pointing a production system at the null adapter turns every
 *    dispatched job into a plaintext log record of its own payload.
 *
 * Use `google` or `aws` in production. If you want a deliberately inert adapter there, write one
 * that logs the job class and UUID without the payload.
 *
 * @see \Karsjen\StatelessQueue\Contracts\OutboundAdapter
 */
final class NullAdapter implements OutboundAdapter
{
    public function name(): string
    {
        return 'null';
    }

    /**
     * Logs the outgoing message — payload included — instead of publishing it.
     *
     * The payload is logged verbatim and is not redacted. See the class docblock: this is a
     * development aid, not a production adapter.
     */
    public function publish(OutgoingJobMessage $message): void
    {
        Log::info('StatelessQueue (NullAdapter): Job pushed', $message->toArray());
    }
}
