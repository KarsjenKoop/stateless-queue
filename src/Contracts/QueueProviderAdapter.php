<?php

namespace Karsjen\StatelessQueue\Contracts;

/**
 * Contract: QueueProviderAdapter
 *
 * Combined contract for adapters that both publish outbound jobs and receive inbound webhooks.
 *
 * Implement this when a single provider handles both directions. Implement only
 * {@see \Karsjen\StatelessQueue\Contracts\OutboundAdapter} or only
 * {@see \Karsjen\StatelessQueue\Contracts\InboundWebhookAdapter} when it does not — as
 * {@see \Karsjen\StatelessQueue\Adapters\NullAdapter} does, being publish-only.
 *
 * @see \Karsjen\StatelessQueue\Adapters\AwsSnsAdapter
 * @see \Karsjen\StatelessQueue\Adapters\GooglePubSubAdapter
 */
interface QueueProviderAdapter extends InboundWebhookAdapter, OutboundAdapter
{
}
