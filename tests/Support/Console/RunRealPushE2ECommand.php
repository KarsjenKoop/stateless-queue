<?php

namespace Karsjen\StatelessQueue\Tests\Support\Console;

use Google\Auth\Credentials\InsecureCredentials;
use Google\Cloud\PubSub\PubSubClient;
use Illuminate\Console\Command;
use Karsjen\StatelessQueue\Tests\Support\Jobs\E2E\RealPushE2EDefaultTopicJob;
use Karsjen\StatelessQueue\Tests\Support\Jobs\E2E\RealPushE2EJob;
use Karsjen\StatelessQueue\Tests\Support\Jobs\E2E\RealPushE2ESnakeTopicJob;

/**
 * Drives the real-push E2E round trip, invoked by `tests/E2E/e2e-test.sh`.
 *
 * Creates the emulator topics and push subscriptions pointing at the running Laravel server, then runs
 * six scenarios — Google and AWS, each covering the three topic-resolution strategies (a custom
 * `$statelessTopic`, a snake_case `$stateless_topic`, and the config default).
 *
 * Each scenario clears `storage/app/real_push_e2e_marker.txt`, publishes one job, and polls the marker
 * for the label that job writes. The marker file is the assertion: the job can only write to it from
 * inside the webhook request, so the expected label appearing there proves the whole
 * publish -> push -> verify -> parse -> execute path worked end to end. A missing or mismatched label
 * fails the scenario, and any failed scenario fails the command.
 *
 * Registered by the E2E app's AppServiceProvider, not by the package.
 */
class RunRealPushE2ECommand extends Command
{
    protected $signature = 'stateless-queue:run-real-push-e2e
                            {--webhook-url= : Webhook URL the emulator will POST to (e.g. http://host.docker.internal:8329/api/stateless/webhook)}
                            {--timeout=20 : Seconds to wait for marker file}';

    protected $description = 'E2E: Publish job to emulator, emulator pushes to webhook; assert job runs (requires Laravel server and Docker emulators).';

    private const TOPIC_CUSTOM = 'real-push-e2e-topic';
    private const TOPIC_DEFAULT = 'real-push-e2e-default';
    private const TOPIC_SNAKE = 'real-push-e2e-snake';
    private const TOPIC_AWS_CUSTOM = 'real-push-e2e-aws';

    public function handle(): int
    {
        $webhookUrl = $this->option('webhook-url') ?: env('STATELESS_QUEUE_E2E_WEBHOOK_URL');
        $timeout = (int) $this->option('timeout');
        $markerPath = storage_path('app/real_push_e2e_marker.txt');

        if (! $webhookUrl) {
            $this->error('Set STATELESS_QUEUE_E2E_WEBHOOK_URL or pass --webhook-url');
            return self::FAILURE;
        }

        $failed = false;

        if (getenv('PUBSUB_EMULATOR_HOST')) {
            config(['stateless-queue.default' => 'google']);
            config(['stateless-queue.connections.google.project_id' => env('GOOGLE_CLOUD_PROJECT', 'test-project')]);
            config(['stateless-queue.default_topic' => self::TOPIC_DEFAULT]);

            $this->ensureGoogleTopicAndPushSub(self::TOPIC_CUSTOM, 'real-push-e2e-sub', $webhookUrl);
            $this->ensureGoogleTopicAndPushSub(self::TOPIC_DEFAULT, 'real-push-e2e-default-sub', $webhookUrl);
            $this->ensureGoogleTopicAndPushSub(self::TOPIC_SNAKE, 'real-push-e2e-snake-sub', $webhookUrl);

            $failed = $this->runGoogleScenarios($markerPath, $timeout) || $failed;
        } else {
            $this->warn('PUBSUB_EMULATOR_HOST not set; skipping Google real-push E2E');
        }

        if (env('AWS_SNS_ENDPOINT')) {
            config(['stateless-queue.default' => 'aws']);
            config(['stateless-queue.connections.aws' => [
                'key' => env('AWS_ACCESS_KEY_ID', 'test'),
                'secret' => env('AWS_SECRET_ACCESS_KEY', 'test'),
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
                'account_id' => env('AWS_ACCOUNT_ID', '000000000000'),
                'endpoint' => env('AWS_SNS_ENDPOINT', 'http://127.0.0.1:4566'),
            ]]);
            config(['stateless-queue.default_topic' => self::TOPIC_DEFAULT]);

            $this->ensureAwsTopicAndHttpSub(self::TOPIC_AWS_CUSTOM, $webhookUrl);
            $this->ensureAwsTopicAndHttpSub(self::TOPIC_DEFAULT, $webhookUrl);
            $this->ensureAwsTopicAndHttpSub(self::TOPIC_SNAKE, $webhookUrl);

            sleep(3);

            $failed = $this->runAwsScenarios($markerPath, $timeout) || $failed;
        } else {
            $this->warn('AWS_SNS_ENDPOINT not set; skipping AWS real-push E2E');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function runGoogleScenarios(string $markerPath, int $timeout): bool
    {
        $failed = false;

        $this->info('Google real-push E2E: custom topic...');
        @unlink($markerPath);
        $job = new RealPushE2EJob('google');
        $job->statelessTopic = self::TOPIC_CUSTOM;
        $job->push();
        if (! $this->pollForMarker($markerPath, 'google', $timeout)) {
            $this->error('Google custom topic: marker not found or wrong content');
            $failed = true;
        } else {
            $this->info('Google custom topic: OK');
        }

        $this->info('Google real-push E2E: default topic...');
        @unlink($markerPath);
        (new RealPushE2EDefaultTopicJob('google-default'))->push();
        if (! $this->pollForMarker($markerPath, 'google-default', $timeout)) {
            $this->error('Google default topic: marker not found or wrong content');
            $failed = true;
        } else {
            $this->info('Google default topic: OK');
        }

        $this->info('Google real-push E2E: snake_case topic...');
        @unlink($markerPath);
        (new RealPushE2ESnakeTopicJob('google-snake'))->push();
        if (! $this->pollForMarker($markerPath, 'google-snake', $timeout)) {
            $this->error('Google snake_case topic: marker not found or wrong content');
            $failed = true;
        } else {
            $this->info('Google snake_case topic: OK');
        }

        return $failed;
    }

    private function runAwsScenarios(string $markerPath, int $timeout): bool
    {
        $failed = false;

        $this->info('AWS SNS real-push E2E: custom topic...');
        @unlink($markerPath);
        $job = new RealPushE2EJob('aws');
        $job->statelessTopic = self::TOPIC_AWS_CUSTOM;
        $job->push();
        if (! $this->pollForMarker($markerPath, 'aws', $timeout)) {
            $this->error('AWS custom topic: marker not found or wrong content');
            $failed = true;
        } else {
            $this->info('AWS custom topic: OK');
        }

        $this->info('AWS SNS real-push E2E: default topic...');
        @unlink($markerPath);
        (new RealPushE2EDefaultTopicJob('aws-default'))->push();
        if (! $this->pollForMarker($markerPath, 'aws-default', $timeout)) {
            $this->error('AWS default topic: marker not found or wrong content');
            $failed = true;
        } else {
            $this->info('AWS default topic: OK');
        }

        $this->info('AWS SNS real-push E2E: snake_case topic...');
        @unlink($markerPath);
        (new RealPushE2ESnakeTopicJob('aws-snake'))->push();
        if (! $this->pollForMarker($markerPath, 'aws-snake', $timeout)) {
            $this->error('AWS snake_case topic: marker not found or wrong content');
            $failed = true;
        } else {
            $this->info('AWS snake_case topic: OK');
        }

        return $failed;
    }

    private function ensureGoogleTopicAndPushSub(string $topicName, string $subName, string $pushEndpoint): void
    {
        $client = new PubSubClient([
            'projectId' => env('GOOGLE_CLOUD_PROJECT', 'test-project'),
            'credentials' => new InsecureCredentials(),
        ]);

        $topic = $client->topic($topicName);
        if (! $topic->exists()) {
            $topic->create();
        }

        $subscription = $client->subscription($subName, $topicName);

        if (! $subscription->exists()) {
            $subscription->create([
                'pushConfig' => ['pushEndpoint' => $pushEndpoint],
            ]);

            return;
        }

        // The subscription outlives the run: the emulator keeps it until the container is recreated.
        // Creating it once and leaving it alone means a change of push endpoint — a different port,
        // a different host — is silently ignored, and every later run pushes to the old address.
        // That failure is near-invisible: the topics exist, publishing succeeds, and the only symptom
        // is the marker never appearing. Reconcile the endpoint on every run instead.
        $current = $subscription->reload()['pushConfig']['pushEndpoint'] ?? null;

        if ($current !== $pushEndpoint) {
            $this->line("  Re-pointing subscription [{$subName}]: ".($current ?: '<none>')." -> {$pushEndpoint}");
            $subscription->modifyPushConfig(['pushEndpoint' => $pushEndpoint]);
        }
    }

    private function ensureAwsTopicAndHttpSub(string $topicName, string $endpoint): void
    {
        $region = env('AWS_DEFAULT_REGION', 'us-east-1');
        $endpointUrl = env('AWS_SNS_ENDPOINT', 'http://127.0.0.1:4566');

        $client = new \Aws\Sns\SnsClient([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpointUrl,
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID', 'test'),
                'secret' => env('AWS_SECRET_ACCESS_KEY', 'test'),
            ],
        ]);

        $result = $client->createTopic(['Name' => $topicName]);
        $topicArn = $result->get('TopicArn');

        // SNS keys a subscription by endpoint, so pointing the run at a different port leaves the old
        // subscription in place and still receiving. Drop any subscription on this topic that is not
        // the endpoint we want, so a stale one cannot keep delivering to whatever now owns that port.
        try {
            foreach ($client->listSubscriptionsByTopic(['TopicArn' => $topicArn])['Subscriptions'] ?? [] as $existing) {
                $arn = $existing['SubscriptionArn'] ?? '';

                if (($existing['Endpoint'] ?? null) !== $endpoint && str_starts_with($arn, 'arn:')) {
                    $this->line("  Removing stale SNS subscription: {$existing['Endpoint']}");
                    $client->unsubscribe(['SubscriptionArn' => $arn]);
                }
            }
        } catch (\Throwable $e) {
            // Listing is best-effort; a stale subscription is noise, not a failure.
        }

        try {
            $client->subscribe([
                'TopicArn' => $topicArn,
                'Protocol' => 'http',
                'Endpoint' => $endpoint,
            ]);
        } catch (\Throwable $e) {
            // Subscription may already exist
        }
    }

    private function pollForMarker(string $path, string $expectedContent, int $timeoutSeconds): bool
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            if (is_file($path)) {
                $content = trim((string) file_get_contents($path));
                if ($content === $expectedContent) {
                    return true;
                }
            }
            usleep(500000);
        }
        return false;
    }
}
