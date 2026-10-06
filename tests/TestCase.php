<?php

namespace Ademakanaky\LaravelWorkflows\Tests;

use Ademakanaky\LaravelWorkflows\WorkflowServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('WORKFLOW_TEST_DB') ?: 'sqlite';
        $app['config']->set('database.default', 'testing');

        $connection = match ($driver) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'workflows',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: 'password',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'workflows',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: 'password',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };

        $app['config']->set('database.connections.testing', $connection);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--database' => 'testing'])->run();

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('uuid_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('uuid_actors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }
}
