<?php

namespace Pantheon\TerminusRepository\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\TerminusRepository\VcsAuthApi\Client;
use Pantheon\TerminusRepository\VcsAuthApi\VcsAuthClientAwareTrait;

/**
 * Create a new pantheon site using ICR
 */
class RepositorySiteCreateCommand extends TerminusCommand implements RequestAwareInterface
{
    use VcsAuthClientAwareTrait;

    const AUTH_COMPLETE_STATUS = 'auth_complete';

    /**
     * Creates a new site.
     *
     * @authorize
     *
     * @command repository:site:create
     * @aliases repository:site-create
     *
     * @param string $site_name Site name
     * @param string $label Site label
     * @param string $upstream_id Upstream name or UUID
     * @param string $vcs_organization Off-platform VCS provider organization
     * @option org Organization name, label, or ID
     * @option region Specify the service region where the site should be
     *   created. See documentation for valid regions.
     *
     * @usage <site> <label> <upstream> <vcs_organization> Creates a new site named <site>, human-readably labeled <label>, using code from <upstream>, owned by <vcs_organization> at GitHub.
     * @usage <site> <label> <upstream> <vcs_organization> --org=<org> Creates a new site named <site>, human-readably labeled <label>, using code from <upstream>, owned by <vcs_organization> at GitHub, associated with Pantheon <organization>.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */

    public function create($site_name, $label, $upstream_id, $vcs_organization, $options = ['org' => null, 'region' => null,])
    {
        $this->log()->notice('Creating a new site...');
        $this->log()->notice("Site name: $site_name");
        $this->log()->notice("Label: $label");
        $this->log()->notice("Upstream ID: $upstream_id");
        $this->log()->notice("VCS Organization: $vcs_organization");
        $this->log()->debug("Options: " . print_r($options, true));

        try {
            $data = $this->getClient()->authorize($vcs_organization);
        } catch (\Throwable $t) {
            throw new TerminusException(
                'Error authorizing with vcs_auth service: {error_message}',
                ['error_message' => $t->getMessage()]
            );
        }

        $this->log()->debug("Data: " . print_r($data, true));

        // Confirm required data is present
        if (!isset($data['workflow_id'])) {
            throw new TerminusException(
                'Error authorizing with vcs_auth service: {error_message}',
                ['error_message' => 'No workflow_id returned']
            );
        }
        if (!isset($data['vcs_auth_link'])) {
            throw new TerminusException(
                'Error authorizing with vcs_auth service: {error_message}',
                ['error_message' => 'No vcs_auth_link returned']
            );
        }


        $this->getContainer()
            ->get(LocalMachineHelper::class)
            ->openUrl($data['vcs_auth_link']);

        $this->log()->notice("Waiting for authorization to complete in browser...");
        $workflow = $this->getClient()->processWorkflow($data['workflow_id'], self::AUTH_COMPLETE_STATUS);

        $this->log()->notice("Authorization complete. Moving on...");

        $this->log()->debug("Workflow: " . print_r($workflow, true));
    }
}
