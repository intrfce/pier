<?php

namespace SocialSync\Console;

use Illuminate\Console\Command;
use SocialSync\Console\Concerns\InteractsWithDockerComposeServices;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'pier:publish')]
class PublishCommand extends Command
{
    use InteractsWithDockerComposeServices;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pier:publish';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish the Laravel Pier Docker files';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->call('vendor:publish', ['--tag' => 'pier-docker']);
        $this->call('vendor:publish', ['--tag' => 'pier-database']);

        $composePath = $this->composePath();

        file_put_contents(
            $composePath,
            str_replace(
                [
                    './vendor/laravel/pier/runtimes/8.5',
                    './vendor/laravel/pier/runtimes/8.4',
                    './vendor/laravel/pier/database/mariadb',
                    './vendor/laravel/pier/database/mysql',
                    './vendor/laravel/pier/database/pgsql'
                ],
                [
                    './docker/8.5',
                    './docker/8.4',
                    './docker/mariadb',
                    './docker/mysql',
                    './docker/pgsql'
                ],
                file_get_contents($composePath)
            )
        );
    }
}
