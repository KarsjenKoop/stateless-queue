<?php

namespace Karsjen\StatelessQueue\Tests\Support;

/**
 * Builds provider-shaped webhook envelopes for feature tests.
 *
 * Each factory wraps a plain job envelope (`uuid`, `job_class`, `topic`, `payload`, ...) in the outer
 * structure the corresponding provider actually sends, so tests state the job they mean rather than
 * hand-rolling base64 and nested provider JSON.
 *
 * @see \Karsjen\StatelessQueue\Adapters\GooglePubSubAdapter::parseRequest()
 * @see \Karsjen\StatelessQueue\Adapters\AwsSnsAdapter::parseRequest()
 */
final class WebhookPayloadBuilder
{
    /**
     * Build a Google Pub/Sub push-style webhook payload.
     *
     * The controller expects:
     * - message.data: base64(json(messageData))
     * - message.attributes.topic: topic routing hint
     */
    public static function googlePush(array $messageData, array $attributes = []): array
    {
        $topic = $attributes['topic'] ?? ($messageData['topic'] ?? 'test-topic');

        return [
            'message' => [
                'data' => base64_encode(json_encode($messageData)),
                'attributes' => array_merge(['topic' => $topic], $attributes),
            ],
        ];
    }

    /**
     * Build an AWS SNS Notification-style webhook payload.
     *
     * The controller expects:
     * - Type=Notification
     * - Message: json(messageData)
     * - MessageAttributes.topic.Value: topic routing hint (optional)
     */
    public static function awsSnsNotification(array $messageData, ?string $topicArn = null): array
    {
        $topic = $messageData['topic'] ?? 'test-topic';

        return [
            'Type' => 'Notification',
            'Message' => json_encode($messageData),
            'MessageAttributes' => [
                'topic' => [
                    'Type' => 'String',
                    'Value' => $topic,
                ],
            ],
            'TopicArn' => $topicArn ?? "arn:aws:sns:us-east-1:123456789012:{$topic}",
        ];
    }
}

