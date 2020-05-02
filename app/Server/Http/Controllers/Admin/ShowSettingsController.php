<?php

namespace App\Server\Http\Controllers\Admin;

use App\Contracts\ConnectionManager;
use App\Server\Configuration;
use Illuminate\Http\Request;
use Ratchet\ConnectionInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use function GuzzleHttp\Psr7\str;
use function GuzzleHttp\Psr7\stream_for;

class ShowSettingsController extends AdminController
{
    /** @var ConnectionManager */
    protected $connectionManager;

    /** @var Configuration */
    protected $configuration;

    public function __construct(ConnectionManager $connectionManager, Configuration $configuration)
    {
        $this->connectionManager = $connectionManager;
        $this->configuration = $configuration;
    }

    public function handle(Request $request, ConnectionInterface $httpConnection)
    {
        $httpConnection->send(
            respond_html($this->getView($httpConnection, 'server.settings.index', [
                'configuration' => $this->configuration,
            ]))
        );
    }
}
