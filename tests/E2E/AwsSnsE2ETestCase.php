<?php

namespace Karsjen\StatelessQueue\Tests\E2E;

use Aws\Sns\SnsClient;
use Karsjen\StatelessQueue\Tests\TestCase;

/**
 * E2E base case for tests that require LocalStack SNS.
 *
 * Sets up:
 * - Default adapter `aws` and LocalStack SNS connection details.
 * - A helper to skip tests when LocalStack isn't reachable.
 */
abstract class AwsSnsE2ETestCase extends TestCase
{
    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Configure AWS connection for LocalStack SNS
        $app['config']->set('stateless-queue.default', 'aws');
        $app['config']->set('stateless-queue.connections.aws', [
            'key'        => env('AWS_ACCESS_KEY_ID', 'test'),
            'secret'     => env('AWS_SECRET_ACCESS_KEY', 'test'),
            'region'     => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'account_id' => env('AWS_ACCOUNT_ID', '000000000000'),
            'endpoint'   => env('AWS_SNS_ENDPOINT', 'http://127.0.0.1:4566'),
        ]);
    }

    protected function requireLocalstackSns(): void
    {
        $host = '127.0.0.1';
        $port = 4566;

        $connection = @fsockopen($host, $port, $errno, $errstr, 1.0);

        if (!$connection) {
            $this->markTestSkipped('LocalStack SNS is not reachable on 127.0.0.1:4566.');
        }

        fclose($connection);
    }

    protected function snsClient(): SnsClient
    {
        $config = config('stateless-queue.connections.aws');

        return new SnsClient([
            'version'     => 'latest',
            'region'      => $config['region'],
            'endpoint'    => $config['endpoint'],
            'credentials' => [
                'key'    => $config['key'],
                'secret' => $config['secret'],
            ],
        ]);
    }
}

