<?php

namespace App\Commands;

use App\Client\Factory;
use App\Logger\CliRequestLogger;
use LaravelZero\Framework\Commands\Command;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Output\ConsoleOutput;

class ShareCommand extends Command
{
    protected $signature = 'share {host} {--hostname=} {--subdomain=} {--auth=}';

    protected $description = 'Share a local url with a remote expose server';

    protected function configureConnectionLogger()
    {
        app()->bind(CliRequestLogger::class, function () {
            return new CliRequestLogger(new ConsoleOutput());
        });

        return $this;
    }

    public function handle()
    {
        if (! empty($this->option('hostname')) && ! empty($this->option('subdomain'))) {
            $this->error('You can only specify one. Either a custom hostname or a subdomain.');

            return;
        }

        $this->configureConnectionLogger();

        (new Factory())
            ->setLoop(app(LoopInterface::class))
            ->setHost(config('expose.host', 'localhost'))
            ->setPort(config('expose.port', 8080))
            ->setAuth($this->option('auth'))
            ->createClient()
            ->share(
                $this->argument('host'),
                explode(',', $this->option('subdomain')),
                $this->option('hostname')
            )
            ->createHttpServer()
            ->run();
    }
}
