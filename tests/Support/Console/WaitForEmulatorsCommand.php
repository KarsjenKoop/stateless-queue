<?php

namespace Karsjen\StatelessQueue\Tests\Support\Console;

use Google\Auth\Credentials\InsecureCredentials;
use Google\Cloud\PubSub\PubSubClient;
use Illuminate\Console\Command;

/**
 * Readiness probe for the E2E emulators, invoked by `tests/E2E/e2e-test.sh`.
 *
 * Polls whichever emulators the environment points at — Pub/Sub when `PUBSUB_EMULATOR_HOST` is set,
 * LocalStack SNS when `AWS_SNS_ENDPOINT` is set — and exits 0 only once every configured one accepts
 * requests. An unconfigured emulator counts as ready, so the command works for a single-provider run.
 *
 * Registered by the E2E app's AppServiceProvider, not by the package.
 */
class WaitForEmulatorsCommand extends Command
{
    protected $signature = 'stateless-queue:wait-for-emulators';

    protected $description = 'Wait until Pub/Sub and LocalStack SNS emulators are ready (for E2E). Exits 0 when both accept requests.';

    public function handle(): int
    {
        $googleOk = ! getenv('PUBSUB_EMULATOR_HOST') || $this->waitGoogle();
        $awsOk = ! getenv('AWS_SNS_ENDPOINT') || $this->waitAws();

        return ($googleOk && $awsOk) ? self::SUCCESS : self::FAILURE;
    }

    private function waitGoogle(): bool
    {
        try {
            $client = new PubSubClient([
                'projectId' => env('GOOGLE_CLOUD_PROJECT', 'test-project'),
                'credentials' => new InsecureCredentials(),
            ]);
            // Topic ID must not start with underscore; use a valid name for readiness check
            $client->topic('e2e-ready-check')->exists();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function waitAws(): bool
    {
        try {
            $client = new \Aws\Sns\SnsClient([
                'version' => 'latest',
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
                'endpoint' => env('AWS_SNS_ENDPOINT', 'http://127.0.0.1:4566'),
                'credentials' => [
                    'key' => env('AWS_ACCESS_KEY_ID', 'test'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY', 'test'),
                ],
            ]);
            $client->listTopics();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
