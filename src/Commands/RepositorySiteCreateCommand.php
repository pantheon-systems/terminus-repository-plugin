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
use Pantheon\Terminus\Models\Upstream;

/**
 * Create a new pantheon site using ICR
 */
class RepositorySiteCreateCommand extends TerminusCommand implements RequestAwareInterface, SiteAwareInterface
{
    use VcsAuthClientAwareTrait;
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

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
     * @option org Organization name, label, or ID
     * @option vcs VCS Type (e.g. github,gitlab,bitbucket)
     * @option region Specify the service region where the site should be
     *   created. See documentation for valid regions.
     *
     * @usage <site> <label> <upstream> Creates a new site named <site>, human-readably labeled <label>, using code from <upstream>.
     * @usage <site> <label> <upstream> --org=<org> Creates a new site named <site>, human-readably labeled <label>, using code from <upstream>, associated with Pantheon <organization>.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */

    public function create($site_name, $label, $upstream_id, $options = [
        'org' => null,
        'region' => null,
        'vcs' => 'github',
    ])
    {

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
        $icr_upstream = $this->getIcrUpstream($upstream_id);

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

        $workflow_data = [
            'user_uuid' => $user->id,
            'org_uuid' => $workflow_options['organization_id'] ?? $user->id,
            'site_uuid' => $site_uuid,
            'site_name' => $site_name,
            'site_type' => $this->getSiteName($icr_upstream),
        ];

        try {
            $data = $this->getVcsAuthClient()->createWorkflow($workflow_data);
        } catch (\Throwable $t) {
            throw new TerminusException(
                'Error authorizing with vcs_auth service: {error_message}',
                ['error_message' => $t->getMessage()]
            );
        }

        $this->log()->debug("Data: " . print_r($data, true));

        // Normalize data.
        $data = (array) $data['data'][0];

        // Confirm required data is present
        if (!isset($data['site_details_id'])) {
            throw new TerminusException(
                'Error authorizing with vcs service: {error_message}',
                ['error_message' => 'No site_details_id returned']
            );
        }
        if (!isset($data['vcs_auth_links']->{$options['vcs']})) {
            throw new TerminusException(
                'Error authorizing with vcs service: {error_message}',
                ['error_message' => 'No vcs_auth_link returned']
            );
        }

        $auth_url = sprintf('"%s"', $data['vcs_auth_links']->{$options['vcs']});

        $this->getContainer()
            ->get(LocalMachineHelper::class)
            ->openUrl($auth_url);

        $this->log()->notice("Waiting for authorization to complete in browser...");
        $site_details = $this->getVcsAuthClient()->processSiteDetails($data['site_details_id'], 300);
        $this->log()->debug("Workflow: " . print_r($workflow, true));

        if (!$site_details['is_active']) {
            throw new TerminusException(
                'Error authorizing with vcs service: {error_message}',
                ['error_message' => 'Site is not yet active']
            );
        }

        $this->log()->notice("Authorization complete. Creating site...");

        // Deploy the upstream.
        if ($site = $this->getSiteById($site_create_workflow->get('waiting_for_task')->site_id)) {
            $this->log()->notice('Next: Deploying CMS...');
            $this->processWorkflow($site->deployProduct($icr_upstream->id));
            $this->log()->notice('Deployed CMS');
        }
    }

    /**
     * Get site name as expected by ICR site creation API.
     */
    public function getSiteName(Upstream $upstream): string
    {
        $upstream_name = $upstream->get('machine_name');
        if (strpos($upstream_name, 'wordpress') !== false) {
            return 'cms-wordpress';
        }
        return 'cms-drupal';
    }

    /**
     * Get ICR upstream based on the upstream passed as argument.
     */
    public function getIcrUpstream(string $upstream_id): Upstream
    {
        $user = $this->session()->getUser();

        $upstream = $user->getUpstreams()->get($upstream_id);
        $framework = $upstream->get('framework');
        return $this->getIcrUpstreamFromFramework($framework, $user);
    }

    /**
     * Get ICR upstream based on the framework.
     */
    protected function getIcrUpstreamFromFramework(string $framework, $user): Upstream
    {
        switch ($framework) {
            case 'drupal8':
                return $user->getUpstreams()->get('drupal-icr');
            case 'wordpress':
                return $user->getUpstreams()->get('wordpress-icr');
            case 'wordpress-network':
                return $user->getUpstreams()->get('wordpress-multisite-icr');
            default:
                throw new TerminusException('Framework {framework} not supported.', compact('framework'));
        }
    }
}
