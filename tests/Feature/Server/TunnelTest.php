<?php

namespace Tests\Feature\Server;

use App\Client\Client;
use App\Server\Factory;
use Clue\React\Buzz\Browser;
use Clue\React\Buzz\Message\ResponseException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Server;
use React\Promise\Timer\TimeoutException;
use React\Socket\Connection;
use Tests\Feature\TestCase;

class TunnelTest extends TestCase
{
    /** @var Browser */
    protected $browser;

    /** @var Factory */
    protected $serverFactory;

    /** @var \React\Socket\Server */
    protected $testHttpServer;

    /** @var \React\Socket\Server */
    protected $testTcpServer;

    public function setUp(): void
    {
        parent::setUp();

        $this->browser = new Browser($this->loop);
        $this->browser = $this->browser->withOptions([
            'followRedirects' => false,
        ]);

        $this->startServer();
    }

    public function tearDown(): void
    {
        $this->serverFactory->getSocket()->close();

        if (isset($this->testHttpServer)) {
            $this->testHttpServer->close();
        }

        if (isset($this->testTcpServer)) {
            $this->testTcpServer->close();
        }

        parent::tearDown();
    }

    /** @test */
    public function it_returns_404_for_non_existing_clients()
    {
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage(404);

        $response = $this->await($this->browser->get('http://127.0.0.1:8080/', [
            'Host' => 'tunnel.localhost',
        ]));
    }

    /** @test */
    public function it_sends_incoming_requests_to_the_connected_client()
    {
        $this->createTestHttpServer();

        $this->app['config']['expose.admin.validate_auth_tokens'] = false;

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $this->await($client->connectToServer('127.0.0.1:8085', 'tunnel'));

        /**
         * Once the client is connected, we perform a GET request on the
         * created tunnel.
         */
        $response = $this->await($this->browser->get('http://127.0.0.1:8080/', [
            'Host' => 'tunnel.localhost',
        ]));

        $this->assertSame('Hello World!', $response->getBody()->getContents());
    }

    /** @test */
    public function it_sends_incoming_requests_to_the_connected_client_with_random_subdomain()
    {
        $this->createTestHttpServer();

        $this->app['config']['expose.admin.validate_auth_tokens'] = false;

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $data = $this->await($client->connectToServer('127.0.0.1:8085', null));

        /**
         * Once the client is connected, we perform a GET request on the
         * created tunnel.
         */
        $response = $this->await($this->browser->get('http://127.0.0.1:8080/', [
            'Host' => $data->subdomain.'.localhost',
        ]));

        $this->assertSame('Hello World!', $response->getBody()->getContents());
    }

    /** @test */
    public function it_sends_incoming_requests_to_the_connected_client_with_specific_hostname()
    {
        $this->createTestHttpServer();

        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_hostnames' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $this->await($this->browser->post('http://127.0.0.1:8080/api/hostnames', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'hostname' => 'reserved.beyondco.de',
            'auth_token' => $user->auth_token,
        ])));

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $data = $this->await($client->connectToServer('127.0.0.1:8085', null, 'reserved.beyondco.de', $user->auth_token));

        /**
         * Once the client is connected, we perform a GET request on the
         * created tunnel.
         */
        $response = $this->await($this->browser->get('http://127.0.0.1:8080/', [
            'Host' => 'reserved.beyondco.de',
        ]));

        $this->assertSame('Hello World!', $response->getBody()->getContents());
    }

    /** @test */
    public function it_sends_incoming_requests_to_the_connected_client_via_tcp()
    {
        $this->createTestTcpServer();

        $this->app['config']['expose.admin.validate_auth_tokens'] = false;

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $response = $this->await($client->connectToServerAndShareTcp(8085));

        /**
         * Once the client is connected, we connect to the
         * created tunnel.
         */
        $connector = new \React\Socket\Connector($this->loop);
        $connection = $this->await($connector->connect('127.0.0.1:'.$response->shared_port));

        $this->assertInstanceOf(Connection::class, $connection);
    }

    /** @test */
    public function it_rejects_tcp_sharing_if_forbidden()
    {
        $this->createTestTcpServer();

        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_share_tcp_ports' => 0,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $this->expectException(\UnexpectedValueException::class);

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $this->await($client->connectToServerAndShareTcp(8085, $user->auth_token));
    }

    /** @test */
    public function it_allows_tcp_sharing_if_enabled_for_user()
    {
        $this->createTestTcpServer();

        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_share_tcp_ports' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $response = $this->await($client->connectToServerAndShareTcp(8085, $user->auth_token));

        /**
         * Once the client is connected, we connect to the
         * created tunnel.
         */
        $connector = new \React\Socket\Connector($this->loop);
        $connection = $this->await($connector->connect('127.0.0.1:'.$response->shared_port));

        $this->assertInstanceOf(Connection::class, $connection);
    }

    /** @test */
    public function it_rejects_clients_with_invalid_auth_tokens()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $this->createTestHttpServer();

        $this->expectException(\UnexpectedValueException::class);

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $result = $this->await($client->connectToServer('127.0.0.1:8085', 'tunnel'));
    }

    /** @test */
    public function it_allows_clients_with_valid_auth_tokens()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_subdomains' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $this->createTestHttpServer();

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $response = $this->await($client->connectToServer('127.0.0.1:8085', 'tunnel', null, $user->auth_token));

        $this->assertSame('tunnel', $response->subdomain);
    }

    /** @test */
    public function it_rejects_clients_to_specify_custom_subdomains()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_subdomains' => 0,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $this->createTestHttpServer();

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $response = $this->await($client->connectToServer('127.0.0.1:8085', 'tunnel', null, $user->auth_token));

        $this->assertNotSame('tunnel', $response->subdomain);
    }

    /** @test */
    public function it_rejects_users_that_want_to_use_a_reserved_subdomain()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_subdomains' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/subdomains', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'subdomain' => 'reserved',
            'auth_token' => $user->auth_token,
        ])));

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Test-User',
            'can_specify_subdomains' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $this->createTestHttpServer();

        $this->expectException(\UnexpectedValueException::class);
        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $response = $this->await($client->connectToServer('127.0.0.1:8085', 'reserved', $user->auth_token));

        $this->assertSame('reserved', $response->subdomain);
    }

    /** @test */
    public function it_allows_users_to_use_their_own_reserved_subdomains()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_subdomains' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/subdomains', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'subdomain' => 'reserved',
            'auth_token' => $user->auth_token,
        ])));

        $this->createTestHttpServer();
        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $response = $this->await($client->connectToServer('127.0.0.1:8085', 'reserved', null, $user->auth_token));

        $this->assertSame('reserved', $response->subdomain);
    }

    /** @test */
    public function it_allows_users_to_use_their_own_reserved_hostnames()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_hostnames' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/hostnames', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'hostname' => 'reserved.beyondco.de',
            'auth_token' => $user->auth_token,
        ])));

        $this->createTestHttpServer();
        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $response = $this->await($client->connectToServer('127.0.0.1:8085', null, 'reserved.beyondco.de', $user->auth_token));

        $this->assertSame('reserved.beyondco.de', $response->hostname);
    }

    /** @test */
    public function it_allows_users_to_use_their_own_reserved_hostnames_with_wildcards()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_hostnames' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/hostnames', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'hostname' => '*.share.beyondco.de',
            'auth_token' => $user->auth_token,
        ])));

        $this->createTestHttpServer();
        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $response = $this->await($client->connectToServer('127.0.0.1:8085', null, 'foo.share.beyondco.de', $user->auth_token));

        $this->assertSame('foo.share.beyondco.de', $response->hostname);
    }

    /** @test */
    public function it_rejects_users_trying_to_use_non_registered_hostnames()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_hostnames' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/hostnames', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'hostname' => 'share.beyondco.de',
            'auth_token' => $user->auth_token,
        ])));

        $this->createTestHttpServer();

        $this->expectException(TimeoutException::class);

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $this->await($client->connectToServer('127.0.0.1:8085', null, 'foo.beyondco.de', $user->auth_token));
    }

    /** @test */
    public function it_rejects_users_trying_to_use_other_peoples_registered_hostnames()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_hostnames' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/hostnames', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'hostname' => '*.share.beyondco.de',
            'auth_token' => $user->auth_token,
        ])));

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_hostnames' => 1,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $this->createTestHttpServer();

        $this->expectException(TimeoutException::class);

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $this->await($client->connectToServer('127.0.0.1:8085', null, 'foo.share.beyondco.de', $user->auth_token));
    }

    /** @test */
    public function it_rejects_clients_to_specify_custom_hostnames()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_hostnames' => 0,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $this->createTestHttpServer();

        $this->expectException(TimeoutException::class);

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $this->await($client->connectToServer('127.0.0.1:8085', null, 'reserved.beyondco.de', $user->auth_token));
    }

    /** @test */
    public function it_allows_clients_to_use_random_subdomains_if_custom_subdomains_are_forbidden()
    {
        $this->app['config']['expose.admin.validate_auth_tokens'] = true;

        $response = $this->await($this->browser->post('http://127.0.0.1:8080/api/users', [
            'Host' => 'expose.localhost',
            'Authorization' => base64_encode('username:secret'),
            'Content-Type' => 'application/json',
        ], json_encode([
            'name' => 'Marcel',
            'can_specify_subdomains' => 0,
        ])));

        $user = json_decode($response->getBody()->getContents())->user;

        $this->createTestHttpServer();

        /**
         * We create an expose client that connects to our server and shares
         * the created test HTTP server.
         */
        $client = $this->createClient();
        $response = $this->await($client->connectToServer('127.0.0.1:8085', '', null, $user->auth_token));

        $this->assertInstanceOf(\stdClass::class, $response);
    }

    protected function startServer()
    {
        $this->app['config']['expose.admin.subdomain'] = 'expose';
        $this->app['config']['expose.admin.database'] = ':memory:';

        $this->app['config']['expose.admin.users'] = [
            'username' => 'secret',
        ];

        $this->serverFactory = new Factory();

        $this->serverFactory->setLoop($this->loop)
            ->setHost('127.0.0.1')
            ->setHostname('localhost')
            ->createServer();
    }

    protected function createClient()
    {
        (new \App\Client\Factory())
            ->setLoop($this->loop)
            ->setHost('127.0.0.1')
            ->setPort(8080)
            ->createClient();

        return app(Client::class);
    }

    protected function createTestHttpServer()
    {
        $server = new Server(function (ServerRequestInterface $request) {
            return new Response(200, ['Content-Type' => 'text/plain'], 'Hello World!');
        });

        $this->testHttpServer = new \React\Socket\Server(8085, $this->loop);
        $server->listen($this->testHttpServer);
    }

    protected function createTestTcpServer()
    {
        $this->testTcpServer = new \React\Socket\Server(8085, $this->loop);

        $this->testTcpServer->on('connection', function (\React\Socket\ConnectionInterface $connection) {
            $connection->write('Hello '.$connection->getRemoteAddress()."!\n");

            $connection->pipe($connection);
        });
    }
}
