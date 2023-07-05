<?php

namespace Pantheon\TerminusRepository\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\TerminusRepository\VcsAuthApi\Client;
use Pantheon\TerminusRepository\VcsAuthApi\VcsAuthClientAwareTrait;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;

/**
 * Create a new pantheon site using ICR
 */
class RepositorySiteCreateCommand extends TerminusCommand implements RequestAwareInterface, SiteAwareInterface
{
    use VcsAuthClientAwareTrait;
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

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

        // @todo Delete these debug lines.
        $this->log()->notice("Site name: $site_name");
        $this->log()->notice("Label: $label");
        $this->log()->notice("Upstream ID: $upstream_id");
        $this->log()->notice("VCS Organization: $vcs_organization");
        $this->log()->debug("Options: " . print_r($options, true));

        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken.', compact('site_name'));
        }

        // Site creation in Pantheon. This code is mostly coming from Terminus site:create command.
        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name,
        ];
        // If the user specified a region, then include it in the workflow
        // options. We'll allow the API to decide whether the region is valid.
        $region = $options['region'] ?? $this->config->get('command_site_options_region');
        if ($region) {
            $workflow_options['preferred_zone'] = $region;
        }

        $user = $this->session()->getUser();

        // Locate upstream.
        $upstream = $user->getUpstreams()->get($upstream_id);

        // Locate organization.
        if (!is_null($org_id = $options['org'])) {
            $org = $user->getOrganizationMemberships()->get($org_id)->getOrganization();
            $workflow_options['organization_id'] = $org->id;
        }

        // Create the site.
        $this->log()->notice('Creating a new site...');
        $site_create_workflow = $this->sites()->create($workflow_options);
        $this->processWorkflow($site_create_workflow);
        $site_uuid = $site_create_workflow->get('waiting_for_task')->site_id;
        $this->log()->notice("New Site Id: " . $site_uuid);

        // @todo Create workflow on go-vcs-service and send site_uuid.

        try {
            $data = $this->getVcsAuthClient()->authorize($vcs_organization);
        } catch (\Throwable $t) {
            throw new TerminusException(
                'Error authorizing with vcs_auth service: {error_message}',
                ['error_message' => $t->getMessage()]
            );
        }

        $this->log()->debug("Data: " . print_r($data, true));

        // @todo Update to get stuff from the right place as per the payload.
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
        $workflow = $this->getVcsAuthClient()->processWorkflow($data['workflow_id'], self::AUTH_COMPLETE_STATUS);
        $this->log()->debug("Workflow: " . print_r($workflow, true));

        $this->log()->notice("Authorization complete. Creating site...");

        // Deploy the upstream.
        if ($site = $this->getSiteById($site_create_workflow->get('waiting_for_task')->site_id)) {
            $this->log()->notice('Next: Deploying CMS...');
        }
    }
}
