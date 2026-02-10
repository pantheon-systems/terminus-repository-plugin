<?php

namespace Pantheon\TerminusRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pantheon\TerminusRepository\Commands\NodeBuildRebuildCommand;

/**
 * Test NodeBuildRebuildCommand.
 */
class NodeBuildRebuildCommandTest extends TestCase
{
    /**
     * Test that the command can be instantiated.
     */
    public function testCommandCanBeInstantiated(): void
    {
        $command = new NodeBuildRebuildCommand();
        $this->assertInstanceOf(NodeBuildRebuildCommand::class, $command);
    }
}
