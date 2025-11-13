<?php

namespace Pantheon\TerminusRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pantheon\TerminusRepository\Commands\Vcs\Connection\LinkCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Models\User;
use Pantheon\Terminus\Models\Organization;
use Pantheon\Terminus\Models\OrganizationUserMembership;
use Pantheon\Terminus\Collections\OrganizationUserMemberships;
use Pantheon\TerminusRepository\VcsApi\Client;
use Pantheon\Terminus\Session\Session;
use ReflectionClass;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test VCS connection link command validation and logic.
 */
class LinkCommandTest extends TestCase
{
    private LinkCommand $command;
    private MockObject $mockUser;
    private MockObject $mockSession;
    private MockObject $mockVcsClient;
    private MockObject $mockOrgMemberships;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = $this->getMockBuilder(LinkCommand::class)
            ->onlyMethods(['session', 'getVcsClient', 'log', 'confirm', 'input', 'output'])
            ->getMock();

        $this->mockUser = $this->createMock(User::class);
        $this->mockUser->id = 'user-123';
        $this->mockSession = $this->createMock(Session::class);
        $this->mockVcsClient = $this->createMock(Client::class);
        $this->mockOrgMemberships = $this->createMock(OrganizationUserMemberships::class);

        $this->mockSession->method('getUser')->willReturn($this->mockUser);
        $this->mockUser->method('getOrganizationMemberships')->willReturn($this->mockOrgMemberships);

        $this->command->method('session')->willReturn($this->mockSession);
        $this->command->method('getVcsClient')->willReturn($this->mockVcsClient);
    }

    /**
     * Test that getAndValidateOrganization throws exception for non-existent org.
     */
    public function testGetAndValidateOrganizationThrowsExceptionForNonexistentOrg(): void
    {
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage('Could not find destination organization "nonexistent-org"');

        $this->mockOrgMemberships
            ->method('get')
            ->with('nonexistent-org')
            ->willThrowException(new \Exception('Not found'));

        $this->invokeProtectedMethod('getAndValidateOrganization', ['nonexistent-org', 'destination']);
    }

    /**
     * Test that getAndValidateOrganization returns organization for valid org.
     */
    public function testGetAndValidateOrganizationReturnsOrgForValidOrg(): void
    {
        $mockOrg = $this->createMock(Organization::class);
        $mockOrg->id = 'org-123';
        $mockOrg->method('getLabel')->willReturn('Test Org');

        $mockMembership = $this->createMock(OrganizationUserMembership::class);
        $mockMembership->method('getOrganization')->willReturn($mockOrg);

        $this->mockOrgMemberships
            ->method('get')
            ->with('test-org')
            ->willReturn($mockMembership);

        $result = $this->invokeProtectedMethod('getAndValidateOrganization', ['test-org', 'destination']);

        $this->assertSame($mockOrg, $result);
        $this->assertEquals('org-123', $result->id);
    }

    /**
     * Test findVcsOrgInPantheonOrg returns null when VCS org not found.
     */
    public function testFindVcsOrgInPantheonOrgReturnsNullWhenNotFound(): void
    {
        $mockOrg = $this->createMock(Organization::class);
        $mockOrg->id = 'org-123';

        $installation1 = new \stdClass();
        $installation1->login_name = 'github-org-1';
        $installation1->installation_id = 'install-1';

        $installation2 = new \stdClass();
        $installation2->login_name = 'github-org-2';
        $installation2->installation_id = 'install-2';

        $this->mockVcsClient
            ->method('getInstallations')
            ->with('org-123', $this->anything())
            ->willReturn(['data' => [$installation1, $installation2]]);

        $result = $this->invokeProtectedMethod(
            'findVcsOrgInPantheonOrg',
            [$this->mockUser, $mockOrg, 'github-org-3']
        );

        $this->assertNull($result);
    }

    /**
     * Test findVcsOrgInPantheonOrg returns installation when found.
     */
    public function testFindVcsOrgInPantheonOrgReturnsInstallationWhenFound(): void
    {
        $mockOrg = $this->createMock(Organization::class);
        $mockOrg->id = 'org-123';

        $installation1 = new \stdClass();
        $installation1->login_name = 'github-org-1';
        $installation1->installation_id = 'install-1';

        $installation2 = new \stdClass();
        $installation2->login_name = 'github-org-2';
        $installation2->installation_id = 'install-2';

        $this->mockVcsClient
            ->method('getInstallations')
            ->with('org-123', $this->anything())
            ->willReturn(['data' => [$installation1, $installation2]]);

        $result = $this->invokeProtectedMethod(
            'findVcsOrgInPantheonOrg',
            [$this->mockUser, $mockOrg, 'github-org-2']
        );

        $this->assertNotNull($result);
        $this->assertEquals('github-org-2', $result->login_name);
        $this->assertEquals('install-2', $result->installation_id);
    }

    /**
     * Test getAllOrgsWithVcsConnections filters orgs correctly.
     */
    public function testGetAllOrgsWithVcsConnectionsFiltersCorrectly(): void
    {
        $destinationOrg = $this->createMock(Organization::class);
        $destinationOrg->id = 'dest-org-123';

        $org1 = $this->createMock(Organization::class);
        $org1->id = 'org-1';
        $org1->method('getLabel')->willReturn('Org 1');

        $org2 = $this->createMock(Organization::class);
        $org2->id = 'org-2';
        $org2->method('getLabel')->willReturn('Org 2');

        $org3 = $this->createMock(Organization::class);
        $org3->id = 'org-3';
        $org3->method('getLabel')->willReturn('Org 3');

        $membership1 = $this->createMock(OrganizationUserMembership::class);
        $membership1->method('getOrganization')->willReturn($org1);

        $membership2 = $this->createMock(OrganizationUserMembership::class);
        $membership2->method('getOrganization')->willReturn($org2);

        $membership3 = $this->createMock(OrganizationUserMembership::class);
        $membership3->method('getOrganization')->willReturn($org3);

        $membershipDest = $this->createMock(OrganizationUserMembership::class);
        $membershipDest->method('getOrganization')->willReturn($destinationOrg);

        $this->mockOrgMemberships
            ->method('all')
            ->willReturn([$membership1, $membership2, $membership3, $membershipDest]);

        $installation = new \stdClass();
        $installation->installation_id = 'install-1';

        // org-1 has VCS connections
        // org-2 has no VCS connections
        // org-3 has VCS connections
        // dest-org should be filtered out
        $this->mockVcsClient
            ->method('getInstallations')
            ->willReturnCallback(function ($orgId) use ($installation) {
                if ($orgId === 'org-1' || $orgId === 'org-3') {
                    return ['data' => [$installation]];
                }
                return ['data' => []];
            });

        $result = $this->invokeProtectedMethod(
            'getAllOrgsWithVcsConnections',
            [$this->mockUser, $destinationOrg]
        );

        $this->assertCount(2, $result);
        $this->assertEquals('org-1', $result[0]->id);
        $this->assertEquals('org-3', $result[1]->id);
    }

    /**
     * Test promptForVcsOrgFromPantheonOrg throws exception when no VCS connections.
     */
    public function testPromptForVcsOrgFromPantheonOrgThrowsExceptionWhenNoConnections(): void
    {
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage('No VCS connections found in Pantheon organization');

        $mockOrg = $this->createMock(Organization::class);
        $mockOrg->id = 'org-123';
        $mockOrg->method('getLabel')->willReturn('Test Org');

        $this->mockVcsClient
            ->method('getInstallations')
            ->with('org-123', $this->anything())
            ->willReturn(['data' => []]);

        $this->invokeProtectedMethod(
            'promptForVcsOrgFromPantheonOrg',
            [$this->mockUser, $mockOrg]
        );
    }

    /**
     * Test promptForVcsOrgFromPantheonOrg returns single installation without prompting.
     */
    public function testPromptForVcsOrgFromPantheonOrgReturnsSingleInstallation(): void
    {
        $mockOrg = $this->createMock(Organization::class);
        $mockOrg->id = 'org-123';

        $installation = new \stdClass();
        $installation->login_name = 'github-org-1';
        $installation->installation_id = 'install-1';

        $this->mockVcsClient
            ->method('getInstallations')
            ->with('org-123', $this->anything())
            ->willReturn(['data' => [$installation]]);

        $result = $this->invokeProtectedMethod(
            'promptForVcsOrgFromPantheonOrg',
            [$this->mockUser, $mockOrg]
        );

        $this->assertSame($installation, $result);
        $this->assertEquals('github-org-1', $result->login_name);
    }

    /**
     * Helper method to invoke protected methods using reflection.
     *
     * @param string $methodName Method name to invoke
     * @param array $args Arguments to pass to the method
     * @return mixed
     */
    private function invokeProtectedMethod(string $methodName, array $args = [])
    {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->command, $args);
    }
}
