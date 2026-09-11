<?php

namespace Karsjen\StatelessQueue;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Karsjen\StatelessQueue\Contracts\JobExecutor;
use Karsjen\StatelessQueue\Contracts\OutboundAdapter;
use Karsjen\StatelessQueue\Contracts\QueueProviderAdapter;
use Karsjen\StatelessQueue\Adapters\AwsSnsAdapter;
use Karsjen\StatelessQueue\Adapters\GooglePubSubAdapter;
use Karsjen\StatelessQueue\Adapters\NullAdapter;
use Karsjen\StatelessQueue\Http\Middleware\VerifyWebhookSignature;
use Karsjen\StatelessQueue\Runtime\JobRunner;
use Karsjen\StatelessQueue\Runtime\ProviderRegistry;
use RuntimeException;

/**
 * Provider: StatelessQueueServiceProvider
 *
 * Bootstraps the stateless queue package into the Laravel application.
 *
 * ### Registration
 * - Merges the package config under the `stateless-queue` key.
 * - Binds each adapter (AWS, Google, Null) as singletons, injecting their connection config.
 * - Registers ProviderRegistry with the inbound-capable adapters (NullAdapter is excluded as it is outbound-only).
 * - Binds OutboundAdapter and the legacy QueueProviderAdapter alias to the configured default adapter.
 *
 * ### Boot
 * - Registers the `stateless.signature` middleware alias for use in route definitions.
 * - Conditionally registers the webhook route (controlled by `stateless-queue.register_routes`).
 * - Publishes the config file under the `stateless-queue-config` tag.
 *
 * ### Adding your own adapter
 * Extend this provider and add your class to `$adapters`, then register your subclass instead of this
 * one in your application's `bootstrap/providers.php`.
 *
 * @see \Karsjen\StatelessQueue\Runtime\ProviderRegistry
 * @see \Karsjen\StatelessQueue\Http\Middleware\VerifyWebhookSignature
 * @see \Karsjen\StatelessQueue\Contracts\OutboundAdapter
 */
class StatelessQueueServiceProvider extends ServiceProvider
{
    /** Maps config alias to adapter class — the single source of truth for registered adapters. */
    protected array $adapters = [
        'aws'    => AwsSnsAdapter::class,
        'google' => GooglePubSubAdapter::class,
        'null'   => NullAdapter::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stateless-queue.php', 'stateless-queue');

        $this->registerAdapters();
        $this->registerProviderRegistry();
        $this->registerJobRunner();
        $this->registerDefaultAdapter();
    }

    public function boot(): void
    {
        $this->bootRoutesAndMiddleware();
        $this->bootPublishing();
    }

    /** Registers each adapter as a singleton, injecting its connection config from the merged config. */
    protected function registerAdapters(): void
    {
        foreach ($this->adapters as $name => $class) {
            $this->app->singleton($class, function ($app) use ($name, $class) {
                if ($name === 'null') {
                    return new $class();
                }

                return new $class(
                    $app['config']->get("stateless-queue.connections.{$name}", [])
                );
            });
        }
    }

    /** Registers the ProviderRegistry with all inbound-capable adapters (NullAdapter excluded). */
    protected function registerProviderRegistry(): void
    {
        $this->app->singleton(ProviderRegistry::class, function ($app) {
            $incomingAdapters = array_filter(
                $this->adapters,
                fn($name) => $name !== 'null',
                ARRAY_FILTER_USE_KEY
            );

            return new ProviderRegistry($app, array_values($incomingAdapters));
        });
    }

    /**
     * Binds the default {@see \Karsjen\StatelessQueue\Contracts\JobExecutor} implementation.
     *
     * Rebind the JobExecutor contract in your own provider to replace how incoming jobs are executed
     * without replacing the rest of the package.
     */
    protected function registerJobRunner(): void
    {
        $this->app->singleton(JobRunner::class, fn () => new JobRunner());
        $this->app->bind(JobExecutor::class, JobRunner::class);
    }

    /**
     * Binds OutboundAdapter (and the legacy QueueProviderAdapter alias) to the configured default adapter.
     *
     * @see \Karsjen\StatelessQueue\Contracts\OutboundAdapter
     */
    protected function registerDefaultAdapter(): void
    {
        $this->app->bind(OutboundAdapter::class, $this->defaultAdapterClosure());

        // Backward compatibility for anything still resolving the legacy contract type.
        $this->app->bind(QueueProviderAdapter::class, $this->defaultAdapterClosure());
    }

    /** @return \Closure(object): object */
    private function defaultAdapterClosure(): \Closure
    {
        return function ($app) {
            $default = $app['config']->get('stateless-queue.default');
            $default = empty($default) ? 'null' : $default;
            if (! isset($this->adapters[$default])) {
                throw new RuntimeException("Unsupported stateless queue adapter [{$default}]");
            }
            return $app->make($this->adapters[$default]);
        };
    }

    /**
     * Makes the config file publishable via `php artisan vendor:publish --tag=stateless-queue-config`.
     *
     * Only registered when running in the console, so the paths are not computed on web requests.
     */
    protected function bootPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/stateless-queue.php' => $this->app->configPath('stateless-queue.php'),
        ], 'stateless-queue-config');
    }

    /** Registers the middleware alias and optionally loads the package webhook route. */
    protected function bootRoutesAndMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('stateless.signature', VerifyWebhookSignature::class);

        if ($this->app->make('config')->get('stateless-queue.register_routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }
    }
}
