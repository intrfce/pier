<?php

namespace SocialSync\Console\Concerns;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

trait InteractsWithDockerComposeServices
{
    /**
     * Possible names for the compose file according to the spec.
     *
     * @var array<string>
     */
    protected $composePaths = [
        'compose.yaml',
        'compose.yml',
        'docker-compose.yaml',
        'docker-compose.yml',
    ];

    /**
     * The services that will be installed.
     *
     * @var array<string>
     */
    protected $services = [
        'pgsql',
        'valkey',
        'mailpit',
        'soketi',
        'traefik',
    ];

    /**
     * Build the Docker Compose file.
     *
     * @param  array  $services
     * @return void
     */
    protected function buildDockerCompose(array $services)
    {
        $composePath = $this->composePath();

        $compose = file_exists($composePath)
            ? Yaml::parseFile($composePath)
            : Yaml::parse(file_get_contents(__DIR__ . '/../../../stubs/compose.stub'));

        // When Traefik is used, remove direct port bindings from laravel.test to avoid conflicts...
        if (in_array('traefik', $services)) {
            unset($compose['services']['laravel.test']['ports']);
        }

        // Adds the new services as dependencies of the laravel.test service...
        if (! array_key_exists('laravel.test', $compose['services'])) {
            $this->warn('Couldn\'t find the laravel.test service. Make sure you add ['.implode(',', $services).'] to the depends_on config.');
        } else {
            $compose['services']['laravel.test']['depends_on'] = collect($compose['services']['laravel.test']['depends_on'] ?? [])
                ->merge($services)
                ->unique()
                ->values()
                ->all();
        }

        // Add the services to the compose.yaml...
        collect($services)
            ->filter(function ($service) use ($compose) {
                return ! array_key_exists($service, $compose['services'] ?? []);
            })->each(function ($service) use (&$compose) {
                $compose['services'][$service] = Yaml::parseFile(__DIR__ . "/../../../stubs/{$service}.stub")[$service];
            });

        // Merge volumes...
        collect($services)
            ->filter(function ($service) {
                return in_array($service, ['pgsql', 'valkey']);
            })->filter(function ($service) use ($compose) {
                return ! array_key_exists($service, $compose['volumes'] ?? []);
            })->each(function ($service) use (&$compose) {
                $compose['volumes']["pier-{$service}"] = ['driver' => 'local'];
            });

        // If the list of volumes is empty, we can remove it...
        if (empty($compose['volumes'])) {
            unset($compose['volumes']);
        }

        $yaml = Yaml::dump($compose, Yaml::DUMP_OBJECT_AS_MAP);

        $yaml = str_replace('{{PHP_VERSION}}', $this->hasOption('php') ? $this->option('php') : '8.5', $yaml);

        file_put_contents($composePath, $yaml);
    }

    /**
     * Replace the Host environment variables in the app's .env file.
     *
     * @param  array  $services
     * @return void
     */
    protected function replaceEnvVariables(array $services)
    {
        $environment = file_get_contents($this->laravel->basePath('.env'));

        // Configure PostgreSQL database
        $defaults = [
            '# DB_HOST=127.0.0.1',
            '# DB_PORT=3306',
            '# DB_DATABASE=laravel',
            '# DB_USERNAME=root',
            '# DB_PASSWORD=',
        ];

        foreach ($defaults as $default) {
            $environment = str_replace($default, substr($default, 2), $environment);
        }

        $environment = preg_replace('/DB_CONNECTION=.*/', 'DB_CONNECTION=pgsql', $environment);
        $environment = str_replace('DB_HOST=127.0.0.1', "DB_HOST=pgsql", $environment);
        $environment = str_replace('DB_PORT=3306', "DB_PORT=5432", $environment);
        $environment = str_replace('DB_USERNAME=root', "DB_USERNAME=pier", $environment);
        $environment = preg_replace("/DB_PASSWORD=(.*)/", "DB_PASSWORD=password", $environment);

        // Configure Valkey (Redis-compatible)
        $environment = str_replace('REDIS_HOST=127.0.0.1', 'REDIS_HOST=valkey', $environment);

        // Configure Soketi (WebSockets)
        $environment = preg_replace("/^BROADCAST_DRIVER=(.*)/m", "BROADCAST_DRIVER=pusher", $environment);
        $environment = preg_replace("/^PUSHER_APP_ID=(.*)/m", "PUSHER_APP_ID=app-id", $environment);
        $environment = preg_replace("/^PUSHER_APP_KEY=(.*)/m", "PUSHER_APP_KEY=app-key", $environment);
        $environment = preg_replace("/^PUSHER_APP_SECRET=(.*)/m", "PUSHER_APP_SECRET=app-secret", $environment);
        $environment = preg_replace("/^PUSHER_HOST=(.*)/m", "PUSHER_HOST=soketi", $environment);
        $environment = preg_replace("/^PUSHER_PORT=(.*)/m", "PUSHER_PORT=6001", $environment);
        $environment = preg_replace("/^PUSHER_SCHEME=(.*)/m", "PUSHER_SCHEME=http", $environment);
        $environment = preg_replace("/^VITE_PUSHER_HOST=(.*)/m", "VITE_PUSHER_HOST=localhost", $environment);

        // Configure Mailpit
        $environment = preg_replace("/^MAIL_MAILER=(.*)/m", "MAIL_MAILER=smtp", $environment);
        $environment = preg_replace("/^MAIL_HOST=(.*)/m", "MAIL_HOST=mailpit", $environment);
        $environment = preg_replace("/^MAIL_PORT=(.*)/m", "MAIL_PORT=1025", $environment);

        // Install Traefik domains configuration
        $this->installTraefikDomains();

        $environment = str_replace('# PHP_CLI_SERVER_WORKERS=4', 'PHP_CLI_SERVER_WORKERS=4', $environment);

        file_put_contents($this->laravel->basePath('.env'), $environment);
    }

    /**
     * Configure PHPUnit to use the dedicated testing database.
     *
     * @return void
     */
    protected function configurePhpUnit()
    {
        if (! file_exists($path = $this->laravel->basePath('phpunit.xml'))) {
            $path = $this->laravel->basePath('phpunit.xml.dist');

            if (! file_exists($path)) {
                return;
            }
        }

        $phpunit = file_get_contents($path);

        $phpunit = preg_replace('/^.*DB_CONNECTION.*\n/m', '', $phpunit);
        $phpunit = str_replace(
            [
                '<!-- <env name="DB_DATABASE" value=":memory:"/> -->',
                '<env name="DB_DATABASE" value=":memory:"/>',
            ],
            '<env name="DB_DATABASE" value="testing"/>',
            $phpunit
        );

        file_put_contents($this->laravel->basePath('phpunit.xml'), $phpunit);
    }

    /**
     * Install the Traefik domains configuration file.
     *
     * @return void
     */
    protected function installTraefikDomains()
    {
        $domainsPath = $this->laravel->basePath('traefik-domains.yml');

        if (! file_exists($domainsPath)) {
            file_put_contents(
                $domainsPath,
                file_get_contents(__DIR__.'/../../../stubs/traefik-domains.stub')
            );
        }
    }

    /**
     * Prepare the installation by pulling and building any necessary images.
     *
     * @param  array  $services
     * @return void
     */
    protected function prepareInstallation($services)
    {
        // Ensure docker is installed...
        if ($this->runCommands(['docker info > /dev/null 2>&1']) !== 0) {
            return;
        }

        if (count($services) > 0) {
            $this->runCommands([
                './vendor/bin/pier pull '.implode(' ', $services),
            ]);
        }

        $this->runCommands([
            './vendor/bin/pier build',
        ]);
    }

    /**
     * Run the given commands.
     *
     * @param  array  $commands
     * @return int
     */
    protected function runCommands($commands)
    {
        $process = Process::fromShellCommandline(implode(' && ', $commands), null, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (\RuntimeException $e) {
                $this->output->writeln('  <bg=yellow;fg=black> WARN </> '.$e->getMessage().PHP_EOL);
            }
        }

        return $process->run(function ($type, $line) {
            $this->output->write('    '.$line);
        });
    }

    /**
     * Get the path to an existing Compose file or fall back to a default of `compose.yaml`.
     *
     * @return string
     */
    protected function composePath()
    {
        return collect($this->composePaths)
            ->map(fn ($path) => $this->laravel->basePath($path))
            ->first(fn ($path) => file_exists($path), $this->laravel->basePath('compose.yaml'));
    }
}
