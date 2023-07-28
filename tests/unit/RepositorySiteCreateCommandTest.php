<?php

namespace Pantheon\TerminusRepository\Tests\Unit;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Models\Upstream;
use Pantheon\Terminus\Collections\Upstreams;
use Pantheon\Terminus\Models\User;
use Pantheon\Terminus\Session\Session;
use PHPUnit\Framework\TestCase;
use Pantheon\TerminusRepository\Commands\RepositorySiteCreateCommand;
use League\Container\Container;
use Mockery;

class RepositorySiteCreateCommandTest extends TestCase
{

    private function setUpstreams($user): Upstreams
    {
        $upstreams = new Upstreams([
            'user' => $user,
        ]);
        $container = new Container();
        $upstreams->setContainer($container);

        // Drupal-icr upstream.
        $upstream_drupal_icr = Mockery::mock(Upstream::class);
        // Add properties to the mocked upstream.
        $upstream_drupal_icr->id = "upstream_drupal_icr";
        $upstream_drupal_icr->organization_id = null;
        $upstream_drupal_icr->framework = 'drupal8';
        $upstreams->add($upstream_drupal_icr);

        // WordPress-icr upstream.
        $upstream_wordpress_icr = Mockery::mock(Upstream::class);
        // Add properties to the mocked upstream.
        $upstream_wordpress_icr->id = "upstream_wordpress_icr";
        $upstream_wordpress_icr->organization_id = null;
        $upstream_wordpress_icr->framework = 'wordpress';
        $upstreams->add($upstream_wordpress_icr);

        // Wordpress-multisite-icr upstream.
        $upstream_wordpress_multisite_icr = Mockery::mock(Upstream::class);
        // Add properties to the mocked upstream.
        $upstream_wordpress_multisite_icr->id = "upstream_wordpress_multisite_icr";
        $upstream_wordpress_multisite_icr->organization_id = null;
        $upstream_wordpress_multisite_icr->framework = 'wordpress-network';
        $upstreams->add($upstream_wordpress_multisite_icr);

        return $upstreams;
    }

    public function testGetIcrUpstreamNotFound()
    {
        $user = Mockery::mock(User::class);
        $upstreams = $this->setUpstreams($user);

        $user->shouldReceive('getUpstreams')->andReturn($upstreams);
        $session = Mockery::mock(Session::class);
        $session->shouldReceive('getUser')->andReturn($user);
        $command = Mockery::mock(RepositorySiteCreateCommand::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $command->shouldReceive('session')->andReturn($session);

        // Test that an exception is thrown when the upstream is not found.
        $upstream_id = 'invalid_upstream_id';
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage("Could not find an upstream identified by $upstream_id.");
        $command->getIcrUpstream($upstream_id);

                //$command->shouldReceive('getIcrUpstreamFromFramework')->with($framework, $user)->andReturn($upstream);


    }

    public function testGetIcrUpstreamFrameworkNotSupported()
    {
        // Setup.
        $user = Mockery::mock(User::class);
        $session = Mockery::mock(Session::class);
        $session->shouldReceive('getUser')->andReturn($user);
        $command = Mockery::mock(RepositorySiteCreateCommand::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $command->shouldReceive('session')->andReturn($session);

        $upstreams = $this->setUpstreams($user);
        $upstream_id = 'upstream_with_invalid_framework';
        $framework = 'drupal';
        $upstream = Mockery::mock(Upstream::class);
        // Add properties to the mocked upstream.
        $upstream->id = $upstream_id;
        $upstream->organization_id = null;
        $upstream->framework = $framework;
        $upstreams->add($upstream);
        $user->shouldReceive('getUpstreams')->andReturn($upstreams);

        // Test, Assert.
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage("Framework $framework not supported.");
        $command->getIcrUpstream($upstream_id);
    }
}