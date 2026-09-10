<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard stop before the framework boots: the suite runs RefreshDatabase, which drops
     * and recreates every table. Pointed at a real database it silently destroys live
     * data. `phpunit.xml` sets DB_CONNECTION/DB_DATABASE, but an exported value in the
     * shell wins that race (observed: an exported DB_CONNECTION=mysql sent the whole
     * suite at the development database and wiped it), so refuse to run instead of
     * trusting the environment.
     */
    protected function setUp(): void
    {
        $this->assertSafeTestDatabase();

        parent::setUp();
    }

    private function assertSafeTestDatabase(): void
    {
        $candidates = [];

        foreach ([$_ENV, $_SERVER] as $source) {
            if (isset($source['DB_CONNECTION']) || isset($source['DB_DATABASE'])) {
                $candidates[] = [
                    (string) ($source['DB_CONNECTION'] ?? ''),
                    (string) ($source['DB_DATABASE'] ?? ''),
                ];
            }
        }

        $candidates[] = [
            (string) (getenv('DB_CONNECTION') ?: ''),
            (string) (getenv('DB_DATABASE') ?: ''),
        ];

        foreach ($candidates as [$connection, $database]) {
            if ($connection === 'sqlite' && $database === ':memory:') {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Refusing to run tests: connection [%s] / database [%s] is not the in-memory '
                .'SQLite database the suite requires. RefreshDatabase wipes its database, so a '
                .'real connection here would destroy live data. Unset DB_CONNECTION/DB_DATABASE '
                .'(or set them to sqlite/:memory:) and run again.',
                $connection ?: 'unset',
                $database ?: 'unset',
            ));
        }
    }
}
