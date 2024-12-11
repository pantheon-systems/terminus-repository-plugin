<?php

namespace Pantheon\TerminusRepository\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\TerminusRepository\VcsApi\Client;
use Pantheon\TerminusRepository\VcsApi\Installation;
use Pantheon\TerminusRepository\VcsApi\VcsClientAwareTrait;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Models\Upstream;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Create a new pantheon site using ICR
 */
class RepositorySiteCreateCommand extends TerminusCommand implements RequestAwareInterface, SiteAwareInterface
{
    use VcsClientAwareTrait;
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

    protected $vcss = ['github', 'gitlab', 'bitbucket'];

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
     * @option org Organization name, label, or ID (Required)
     * @option vcs VCS Type (e.g. github,gitlab,bitbucket)
     * @option installation_id Installation ID (e.g. 123456)
     *   If not specified, the user will be prompted to select an installation when there are existing installations.
     * @option region Specify the service region where the site should be
     *   created. See documentation for valid regions.
     * @option visibility Visibility of the site (private or public)
     * @option vcs_token VCS Token in case a new installation is needed and vcs is GitLab.
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
        'installation_id' => null,
        'visibility' => 'private',
        'vcs_token' => null,
    ])
    {

        if (empty($options['org'])) {
            throw new TerminusException('Please specify an organization.');
        }

        // Validate VCS and convert to id.
        if (!in_array($options['vcs'], $this->vcss)) {
            throw new TerminusException('VCS {vcs} not supported.', ['vcs' => $options['vcs']]);
        }
        $vcs_id = array_search($options['vcs'], $this->vcss) + 1;

        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken.', compact('site_name'));
        }

        // Site creation in Pantheon. This code is mostly coming from Terminus site:create command.
        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name,
            'has_external_vcs' => true,
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
        $org_id = $options['org'];
        $org = $user->getOrganizationMemberships()->get($org_id)->getOrganization();
        if (!$org) {
            throw new TerminusException('Organization {org_id} not found.', compact('org_id'));
        }
        $workflow_options['organization_id'] = $org->id;

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
            'site_type' => $this->getSiteType($icr_upstream),
        ];

        try {
            $data = $this->getVcsClient()->createWorkflow($workflow_data);
        } catch (\Throwable $t) {
            $this->cleanup($site_uuid, false);
            throw new TerminusException(
                'Error authorizing with vcs service: {error_message}',
                ['error_message' => $t->getMessage()]
            );
        }

        $this->log()->debug("Data: " . print_r($data, true));

        // Normalize data.
        $data = (array) $data['data'][0];

        $workflow_uuid = $data['workflow_uuid'];

        // Confirm required data is present
        if (!isset($data['site_details_id'])) {
            $this->cleanup($site_uuid, false);
            throw new TerminusException(
                'Error authorizing with vcs service: {error_message}',
                ['error_message' => 'No site_details_id returned']
            );
        }

        $auth_url = null;
        // Iterate over the two possible auth options for the given VCS.
        foreach (['app', 'oauth'] as $auth_option) {
            if (isset($data['vcs_auth_links']->{sprintf("%s_%s", $options['vcs'], $auth_option)})) {
                $auth_url = sprintf('"%s"', $data['vcs_auth_links']->{sprintf("%s_%s", $options['vcs'], $auth_option)});
                break;
            }
        }

        // GitHub requires an authorization URL.
        if ($options['vcs'] === 'github' && (is_null($auth_url) || $auth_url === '""')) {
            $this->cleanup($site_uuid);
            throw new TerminusException(
                'Error authorizing with vcs service: {error_message}',
                ['error_message' => 'No vcs_auth_link returned']
            );
        }

        $installations = [];
        $installation_id = 'new';
        $site_details = null;

        if (!empty($data['existing_installations'])) {
            $new_installation = new Installation(
                'New Installation',
                '',
                '',
            );
            $installations['new'] = $new_installation;
            foreach ($data['existing_installations'] as $installation) {
                if ($installation->installation_type == 'front-end') {
                    continue;
                }
                if ($installation->vendor != $options['vcs']) {
                    continue;
                }
                $installation_obj = new Installation(
                    $installation->installation_id,
                    $installation->vendor,
                    $installation->login_name
                );
                $installations[$installation->installation_id] = $installation_obj;
            }
        }

        if ($installations) {
            // If a valid option was provided, use it, otherwise, prompt the user.
            if (isset($installations[$options['installation_id']])) {
                $installation_id = $options['installation_id'];
            } else {
                $helper = new QuestionHelper();
                $question = new ChoiceQuestion(
                    'Please select your desired installation (default to new one):',
                    $installations,
                    'new'
                );
                $installation_id = $helper->ask($this->input(), $this->output(), $question);
            }

            if ($installation_id !== 'new') {
                $authorize_data = [
                    'site_uuid' => $site_uuid,
                    'user_uuid' => $user->id,
                    'installation_id' => (int) $installation_id,
                    'org_uuid' => $org->id,
                ];
                try {
                    $data = $this->getVcsClient()->authorize($authorize_data);
                    if (!$data['success']) {
                        throw new TerminusException("An error happened while authorizing: {error_message}", ['error_message' => $data['data']]);
                    }
                    $site_details = $this->getVcsClient()->getSiteDetails($site_uuid);
                    $site_details = (array) $site_details['data'][0];
                } catch (TerminusException $e) {
                    $this->cleanup($site_uuid);
                    throw $e;
                }
            }
        }

        if ($installation_id === 'new') {
            try {
                $site_details = $this->handleNewInstallation($options['vcs'], $auth_url, $site_uuid, $options);
            } catch (TerminusException $e) {
                $this->cleanup($site_uuid);
                throw $e;
            }
        }

        if (!$site_details['is_active']) {
            $this->cleanup($site_uuid);
            throw new TerminusException(
                'Error authorizing with vcs service: {error_message}',
                ['error_message' => 'Site is not yet active']
            );
        }

        $this->log()->notice("Creating repository...");

        $repo_create_data = [
            'site_uuid' => $site_uuid,
            'label' => $site_name,
            'skip_create' => false,
            'is_private' => $options['visibility'] === 'private',
            'vendor_id' => $vcs_id,
        ];
        try {
            $data = $this->getVcsClient()->repoCreate($repo_create_data);
        } catch (\Throwable $t) {
            $this->cleanup($site_uuid);
            throw new TerminusException(
                'Error creating repo: {error_message}',
                ['error_message' => $t->getMessage()]
            );
        }
        $this->log()->debug("Data: " . print_r($data, true));

        // Normalize data.
        $data = (array) $data['data'];

        if (!isset($data['repo_url'])) {
            $this->cleanup($site_uuid);
            throw new TerminusException(
                'Error creating repo: {error_message}',
                ['error_message' => 'No repo_url returned']
            );
        }
        $target_repo_url = $data['repo_url'];

        // Install webhook if needed.
        if ($options['vcs'] != 'github') {
            $this->log()->notice('Next: Installing webhook...');
            try {
                $webhook_data = [
                    'repository' => $site_name,
                    'vendor' => $options['vcs'],
                    'workflow_uuid' => $workflow_uuid,
                    'site_uuid' => $site_uuid,
                ];
                $data = $this->getVcsClient()->installWebhook($webhook_data);
                if (!$data['success']) {
                    throw new TerminusException("An error happened while installing webhook: {error_message}", ['error_message' => $data['data']]);
                }
            } catch (\Throwable $t) {
                $this->cleanup($site_uuid);
                throw new TerminusException(
                    'Error installing webhook: {error_message}',
                    ['error_message' => $t->getMessage()]
                );
            }
            $this->log()->notice('Webhook installed');
        } else {
            $this->log()->notice('Next: No webhook needed for GitHub');
        }


        // Deploy product.
        if ($site = $this->getSiteById($site_uuid)) {
            $this->log()->notice('Next: Deploying Pantheon resources...');
            try {
                $this->processWorkflow($site->deployProduct($icr_upstream->id));
            } catch (TerminusException $e) {
                $this->cleanup($site_uuid);
                throw $e;
            }
            $this->log()->notice('Deployed resources');
        }

        // Push initial code to external repository.
        $this->log()->notice('Next: Pushing initial code to your external repository...');

        // Get repo and branch from upstream.
        [$upstream_repo_url, $upstream_repo_branch] = $this->getUpstreamInformation($upstream_id);

        $installation_id = $site_details['installation_id'];
        if (!$installation_id) {
            $this->cleanup($site_uuid);
            throw new TerminusException(
                'Error authorizing with vcs service: {error_message}',
                ['error_message' => 'No installation_id returned']
            );
        }

        // Call pantheonapi vcs/v1/repo-initialize.
        $repo_initialize_data = [
            'site_id' => $site_uuid,
            'target_repo_url' => $target_repo_url,
            'upstream_repo_url' => $upstream_repo_url,
            'upstream_repo_branch' => $upstream_repo_branch,
            'installation_id' => (string) $installation_id,
            'organization_id' => $org->id,
            'vendor_id' => $vcs_id,
        ];

        try {
            $this->getVcsClient()->repoInitialize($repo_initialize_data);
        } catch (\Throwable $t) {
            $this->log()->notice(sprintf("Now you can push your code to %s", $target_repo_url));
            throw new TerminusException(
                'Error initializing repo with contents: {error_message}',
                ['error_message' => $t->getMessage()]
            );
        }

        $this->log()->notice(sprintf("Site was correctly created, you can access your repo at %s", $target_repo_url));
        $this->log()->notice(sprintf("You can also access your site dashboard at %s", $site->dashboardUrl()));
    }

    /**
     * Handle new installation.
     */
    public function handleNewInstallation($vcs, $auth_url, $site_uuid, $options): array
    {
        switch ($vcs) {
            case 'github':
                $this->log()->notice("Opening authorization link in browser...");
                $this->log()->notice("If your browser does not open, please go to the following URL:");
                $this->log()->notice($auth_url);

                $this->getContainer()
                    ->get(LocalMachineHelper::class)
                    ->openUrl($auth_url);

                $this->log()->notice("Waiting for authorization to complete in browser...");
                $site_details = $this->getVcsClient()->processSiteDetails($site_uuid, 600);
                return $site_details;

            case 'gitlab':
                $token = null;
                if (isset($installations[$options['vcs_token']])) {
                    $token = $options['vcs_token'];
                } else {
                    // @TODO Write correct instructions.
                    $this->log()->notice('Get a GitLab access token. More details at https://docs.pantheon.io');
                    // Prompt for access token.
                    $helper = new QuestionHelper();
                    $question = new Question("Please enter the GitLab token\n");
                    $question->setValidator(function ($answer) {
                        if ($answer == null || '' == trim($answer)) {
                            throw new TerminusException('Token cannot be empty');
                        }
                        return $answer;
                    });
                    $question->setMaxAttempts(3);
                    $question->setHidden(true);
                    $token = $helper->ask($this->input(), $this->output(), $question);
                }
                $question = new Question("Please enter the GitLab group name to create the repositories\n");
                $question->setValidator(function ($answer) {
                    if ($answer == null || '' == trim($answer)) {
                        throw new TerminusException('Group name cannot be empty');
                    }
                    return $answer;
                });
                $question->setMaxAttempts(3);
                $group_name = $helper->ask($this->input(), $this->output(), $question);
                if (!$group_name) {
                    // Throw error because token cannot be empty.
                    throw new TerminusException('Group name cannot be empty');
                }

                $session = $this->session();
                $user = $session->getUser();

                $post_data = [
                    'token' => $token,
                    'vendor' => 2,
                    'installation_type' => 'cms-site',
                    'platform_user' => $user->id,
                    'site_uuid' => $site_uuid,
                    'vcs_organization' => $group_name,
                    'pantheon_session' => $session->get('session'),
                ];
                $data = $this->getVcsClient()->installWithToken($post_data);
                if (!$data['success']) {
                    throw new TerminusException("An error happened while authorizing: {error_message}", ['error_message' => $data['data']]);
                }

                $site_details = $this->getVcsClient()->getSiteDetails($site_uuid);
                $site_details = (array) $site_details['data'][0];
                return $site_details;
        }
    }

    /**
     * Get site type as expected by ICR site creation API.
     */
    public function getSiteType(Upstream $upstream): string
    {
        $framework = $upstream->get('framework');
        switch ($framework) {
            case 'drupal8':
                return 'cms-drupal';
            case 'wordpress':
            case 'wordpress-network':
                return 'cms-wordpress';
            default:
                throw new TerminusException('Framework {framework} not supported.', compact('framework'));
        }
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

    /**
     * Delete site.
     */
    protected function cleanup(string $site_uuid, bool $cleanup_vcs = true): void
    {
        $this->log()->notice("Cleaning up resources due to a previous failure...");
        $exception = null;

        if ($cleanup_vcs) {
            try {
                $this->getVcsClient()->cleanupSiteDetails($site_uuid);
            } catch (TerminusException $e) {
                $exception = $e;
                $this->log()->notice("Error cleaning up vcs service: {error_message}", ['error_message' => $e->getMessage()]);
            }
        }

        $site = $this->sites()->get($site_uuid);
        $workflow = $site->delete();

        // We need to query the user workflows API to watch the delete_site workflow, since the site object won't exist anymore
        $workflow->setOwnerObject($this->session()->getUser());

        $this->processWorkflow($workflow);
        $message = $workflow->getMessage();
        $this->log()->notice($message, ['site' => $site_uuid]);

        if ($exception) {
            throw $exception;
        }
    }

    public function getUpstreamInformation(string $upstream_id): array
    {
        $user = $this->session()->getUser();
        $upstream = $user->getUpstreams()->get($upstream_id);
        return [$upstream->get('repository_url'), $upstream->get('repository_branch')];
    }
}
