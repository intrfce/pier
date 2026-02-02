<?php

namespace SocialSync\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'pier:install')]
class InstallCommand extends Command
{
    use Concerns\InteractsWithDockerComposeServices;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pier:install
                {--php=8.5 : The PHP version that should be used}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Laravel Pier\'s default Docker Compose file';

    /**
     * Execute the console command.
     *
     * @return int|null
     */
    public function handle()
    {
        $services = $this->services;

        $this->buildDockerCompose($services);
        $this->replaceEnvVariables($services);
        $this->configurePhpUnit();
        $this->prepareInstallation($services);

        $this->output->writeln('');
        $this->components->info('Pier scaffolding installed successfully. You may run your Docker containers using Pier\'s "up" command.');

        $this->output->writeln('<fg=gray>➜</> <options=bold>./vendor/bin/pier up</>');

        $this->components->warn('A database service was installed. Run "artisan migrate" to prepare your database:');
        $this->output->writeln('<fg=gray>➜</> <options=bold>./vendor/bin/pier artisan migrate</>');

        $this->output->writeln('');
        $this->components->info('Traefik has been installed for local domain routing.');
        $this->output->writeln('  Configure your domains in <options=bold>traefik-domains.yml</>');
        $this->output->writeln('  The Traefik dashboard is available at <options=bold>http://localhost:8080</>');
        $this->output->writeln('');
        $this->output->writeln('  <fg=gray>The domains file supports:</>');
        $this->output->writeln('  <fg=gray>- Multiple domains:</> Host(`myapp.localhost`) || Host(`api.localhost`)');
        $this->output->writeln('  <fg=gray>- Wildcard subdomains:</> HostRegexp(`{subdomain:[a-z0-9-]+}.myapp.localhost`)');
        $this->output->writeln('  <fg=gray>- Changes are applied automatically (no restart required)</>');

        $this->output->writeln('');
    }
}
