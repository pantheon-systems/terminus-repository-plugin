<?php

namespace Pantheon\TerminusRepository\Commands\Site;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Helpers\Traits\WaitForWakeTrait;
use Pantheon\Terminus\Models\Upstream;
use Pantheon\Terminus\Models\User;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\TerminusRepository\VcsApi\Installation;
use Pantheon\TerminusRepository\VcsApi\VcsClientAwareTrait;
use Pantheon\TerminusRepository\WorkflowWaitTrait;
use Pantheon\Terminus\Commands\Site\SiteCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Consolidation\AnnotatedCommand\AnnotationData;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Creates a new site, potentially with an external Git repository.
 * This command overrides the core site:create command when the plugin is enabled.
 */
class CreateCommand extends SiteCommand implements RequestAwareInterface, SiteAwareInterface
{
    use WaitForWakeTrait;
    use WorkflowWaitTrait;
    use VcsClientAwareTrait;

    // Wait time for GitHub app installation to succeed.
    protected const AUTH_LINK_TIMEOUT = 600;
    protected const ADD_NEW_ORG_TEXT = 'Add to a new org';
    protected const REDIRECT_URL = 'https://docs.pantheon.io/github-application';

    // Default timeout.
    protected const DEFAULT_TIMEOUT = 600;

    // Supported VCS types (can be expanded later)
    protected $vcs_providers = ['pantheon', 'github', 'gitlab', 'bitbucket'];

    protected Process $serverProcess;

    /**
     * Creates a new site
     *
     * @authorize
     * @interact
     *
     * @command site:create
     * @aliases site-create
     *
     * @param string $site_name Site name (machine name)
     * @param string $label Site label (human-readable name)
     * @param string $upstream_id Upstream name or UUID (e.g., wordpress, drupal-composer-managed)
     * @option org Organization name, label, or ID. Required if --vcs-provider=github is used.
     * @option region Specify the service region where the site should be created. See documentation for valid regions.
     * @option vcs-provider VCS provider for the site repository (e.g., github, pantheon). Default is pantheon.
     * @option vcs-org Name of the Github organization containing the repository. Required if --vcs-provider=github is used.
     * @option visibility Visibility of the external repository (private or public). Only applies if --vcs-provider=github. Default is private.
     * @option vcs-token Personal access token for the VCS provider. Only applies if --vcs-provider=gitlab.
     * @option create-repo Whether to create a repository in the VCS provider. Default is true.
     * @option repository-name Name of the repository to create in the VCS provider. Only applies if --vcs-provider is not Pantheon.
     * @option skip-clone-repo Do not clone the repository after creation. Default is false.
     *
     * @usage <site> <label> <upstream> Creates a new Pantheon-hosted site named <site>, labeled <label>, using code from <upstream>.
     * @usage <site> <label> <upstream> --org=<org> Creates site associated with <organization>, with a Pantheon-hosted git repository.
     * @usage <site> <label> <upstream> --org=<org> --vcs-provider=github --vcs-org=<github-org> Creates a new site associated with Pantheon <organization>, using <upstream> code, with the repository hosted on Github in the <github-org> organization.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Exception
     */
    public function create(
        $site_name,
        $label,
        $upstream_id,
        $options = [
            'org' => null,
            'region' => null,
            'vcs-provider' => 'pantheon',
            'vcs-org' => null,
            'visibility' => 'private',
            'create-repo' => true,
            'repository-name' => null,
            'skip-clone-repo' => false,
        ]
    ) {
        $vcs_provider = strtolower($options['vcs-provider']);
        $org_id = $options['org'];

        // Validate VCS provider
        if (!in_array($vcs_provider, $this->vcs_providers)) {
            throw new TerminusException(
                'Invalid VCS provider specified: {vcs-provider}. Supported providers are: {supported}',
                ['vcs-provider' => $vcs_provider, 'supported' => implode(', ', $this->vcs_providers)]
            );
        }

        // Validate site name uniqueness
        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken.', compact('site_name'));
        }

        // Validate eVCS Pantheon org requirement
        if ($vcs_provider !== 'pantheon') {
            if (empty($org_id)) {
                throw new TerminusException(
                    'The --org option is required when using an external VCS provider (--vcs-provider={vcs}).',
                    ['vcs-provider' => $vcs_provider]
                );
            }
        } else {
            // If using Pantheon, no create-repo is not supported.
            if (!$options['create-repo']) {
                throw new TerminusException(
                    'The --no-create-repo option is not supported when using Pantheon as the VCS provider.'
                );
            }
            if ($options['repository-name']) {
                throw new TerminusException(
                    'The --repository-name option is not supported when using Pantheon as the VCS provider.'
                );
            }
        }

        // Get User and Upstream
        $user = $this->session()->getUser();
        $upstream = $this->getValidatedUpstream($user, $upstream_id);

        // Branch based on VCS provider
        if ($vcs_provider === 'pantheon') {
            $this->createPantheonHostedSite($site_name, $label, $upstream, $user, $options);
        } else {
            // For now, only github is supported other than pantheon
            $this->createExternallyHostedSite($site_name, $label, $upstream, $user, $options);
        }
    }

    public function __destruct()
    {
        if (isset($this->serverProcess) && $this->serverProcess->isRunning()) {
            $this->serverProcess->stop(0);
        }
    }

    /**
     * Prompts for required options depending on the ones that were passed.
     *
     * @hook post-interact site:create
     */
    public function promptForRequired(InputInterface $input, OutputInterface $output, AnnotationData $annotation_data)
    {
        $original_provider = $input->getOption('vcs-provider');
        if ($original_provider === 'pantheon') {
            $upstream_id = $input->getArgument('upstream_id');
            $user = $this->session()->getUser();
            $upstream = $this->getValidatedUpstream($user, $upstream_id);

            if ($upstream->get('framework') === 'nodejs') {
                $helper = new QuestionHelper();
                $question = new ChoiceQuestion(
                    'Node.js sites cannot be created with Pantheon-hosted repositories. Please select a VCS provider:',
                    [
                        'github' => 'GitHub',
                    ]
                );
                $question->setErrorMessage('Invalid selection.');
                $chosen_provider = $helper->ask($input, $output, $question);
                $input->setOption('vcs-provider', $chosen_provider);
            }
        }

        $vcs_provider = strtolower($input->getOption('vcs-provider'));
        $org_id = $input->getOption('org');

        // If the user didn't provide --org, prompt them for it.
        if ($vcs_provider !== 'pantheon' && empty($org_id)) {
            $organizations = [];
            $user = $this->session()->getUser();
            $orgs = $user->getOrganizationMemberships()->all();
            foreach ($orgs as $org) {
                $organization = $org->getOrganization();
                $organizations[$organization->id] = $organization->getLabel();
            }

            $helper = new QuestionHelper();
            $question = new ChoiceQuestion(
                'Please specify the Pantheon organization:',
                $organizations,
            );
            $question->setErrorMessage('Invalid selection.');
            $chosen_org = $helper->ask($input, $output, $question);
            $input->setOption('org', $chosen_org);
        }
    }


    /**
     * Validates the upstream ID and returns the Upstream object.
     * Provides a more helpful error message if the upstream isn't found.
     */
    protected function getValidatedUpstream(User $user, string $upstream_id): Upstream
    {
        try {
            // Use the user's upstream collection to find the upstream
            return $user->getUpstreams()->get($upstream_id);
        } catch (TerminusNotFoundException $e) {
            // Provide a more helpful error message if the upstream isn't found.
            $this->log()->error('Could not find upstream: {upstream}', ['upstream' => $upstream_id]);
            try {
                // Attempt to list available upstreams for better user feedback
                $available_upstreams = array_map(function ($up) {
                    return $up->id; // Or $up->get('label') for human-readable names
                }, $user->getUpstreams()->all());
                if (!empty($available_upstreams)) {
                    $this->log()->error('Available upstreams: {list}', ['list' => implode(', ', $available_upstreams)]);
                } else {
                    $this->log()->warning('Could not retrieve list of available upstreams.');
                }
            } catch (\Exception $list_error) {
                $this->log()->warning(
                    'Could not retrieve list of available upstreams: {msg}',
                    ['msg' => $list_error->getMessage()]
                );
            }
            // Throw the final exception indicating the specific upstream wasn't found
            throw new TerminusException('Invalid upstream "{upstream}" specified.', ['upstream' => $upstream_id]);
        }
    }


    /**
     * Handles creation of a standard Pantheon-hosted site.
     * (Logic adapted from core CreateCommand)
     */
    protected function createPantheonHostedSite($site_name, $label, Upstream $upstream, User $user, array $options)
    {
        $this->log()->notice('Creating a new Pantheon-hosted site...');

        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name,
        ];

        $region = $options['region'] ?? $this->config->get('command_site_options_region');
        if ($region) {
            $workflow_options['preferred_zone'] = $region;
            $this->log()->notice('Attempting to create site in region: {region}', compact('region'));
        }

        $org = null;
        $org_id = $options['org'];
        if ($org_id !== null) {
            try {
                // It's better to get the membership first, then the organization
                $membership = $user->getOrganizationMemberships()->get($org_id);
                $org = $membership->getOrganization();
                $workflow_options['organization_id'] = $org->id;
                $this->log()->notice('Associating site with organization: {org_label} ({org_id})', [
                    'org_label' => $org->get('profile')->name,
                    'org_id' => $org->id,
                ]);
            } catch (TerminusNotFoundException $e) {
                throw new TerminusException(
                    'Organization "{org}" not found or you are not a member.',
                    ['org' => $org_id]
                );
            } catch (\Exception $e) {
                // Catch other potential errors during org fetching
                throw new TerminusException(
                    'Error retrieving organization "{org}": {message}',
                    ['org' => $org_id, 'message' => $e->getMessage()]
                );
            }
        } else {
             $this->log()->notice('Site will be owned by the current user: {email}', ['email' => $user->get('email')]);
        }

        // Create the site record via Pantheon API
        $this->log()->notice('Submitting site creation request to Pantheon API...');
        $workflow = $this->sites()->create($workflow_options);
        $this->processWorkflow($workflow);
        $this->log()->notice('Pantheon site record created successfully.');

        // Deploy the upstream CMS code
        $site_id = $workflow->get('waiting_for_task')->site_id ?? null;
        if (!$site_id) {
             throw new TerminusException('Could not get site ID from site creation workflow.');
        }

        if ($site = $this->getSiteById($site_id)) {
            $this->log()->notice('Deploying CMS ({upstream_label} - {upstream_id})...', [
                'upstream_label' => $upstream->get('label'),
                'upstream_id' => $upstream->id,
            ]);
            $this->processWorkflow($site->deployProduct($upstream->id));
            $this->log()->notice('CMS deployed successfully.');

            // Finally, wait for the dev environment to be ready.
            $this->waitForDevEnvironment($site, "cos");
        } else {
            // This shouldn't happen if the create workflow succeeded and returned an ID, but good to handle.
            throw new TerminusException('Failed to retrieve site object (ID: {id}) after creation workflow succeeded.', ['id' => $site_id]);
        }
    }

    /**
     * Wait for wake on the dev environment for a STA site.
     */
    protected function waitForWakeSta(Environment $env)
    {
        $domains = array_filter(
            $env->getDomains()->all(),
            function ($domain) {
                $domain_type = $domain->get('type');
                return (!empty($domain_type) && $domain_type == "platform");
            }
        );
        if (empty($domains)) {
            throw new TerminusException('No valid domains found for health check.');
        }
        $domain = array_pop($domains);
        $start_time = time();
        $polling_interval = $this->getConfig()->get('http_retry_delay_ms', 1000);
        do {
            usleep($polling_interval * 1000);
            $current_time = time();
            if (($current_time - $start_time) > self::DEFAULT_TIMEOUT) {
                throw new TerminusException('Timeout waiting for dev environment to become available.');
            }
            try {
                $response = $this->request()->request(
                    "https://{$domain->id}/",
                    [
                        'headers' => [
                            'Deterrence-Bypass' => 1,
                        ],
                    ],
                );
            } catch (TerminusException $e) {
                $this->log()->debug('Error while checking site status: {message}', ['message' => $e->getMessage()]);
                continue;
            }
            $success = $response->getStatusCode() === 200;
            if ($success) {
                $this->log()->notice('Site seems to be up and running.');
                break;
            }
        } while (true);
    }

    /**
     * Wait for dev environment to be ready to handle traffic.
     */
    protected function waitForDevEnvironment(Site $site, string $preferred_platform)
    {
        $this->log()->notice('Waiting for site dev environment to become available...');
        try {
            $env = $site->getEnvironments()->get('dev');
            if ($env) {
                if ($preferred_platform == "cos") {
                    $this->waitForWake($env, $this->logger);
                }
                if ($preferred_platform == "sta") {
                    $this->waitForWakeSta($env);
                }
                $this->log()->notice('Site dev environment is available.');
                $this->log()->notice('---');
                $this->log()->notice('Site "{site}" created successfully!', ['site' => $site->getName()]);
                $this->log()->notice('Dashboard: {url}', ['url' => $site->dashboardUrl()]);
                $this->log()->notice('---');
            } else {
                // This case should ideally not happen if the site exists
                $this->log()->warning(
                    'Could not retrieve the dev environment information, unable to confirm availability.'
                );
            }
        } catch (TerminusNotFoundException $e) {
             $this->log()->warning(
                 'Dev environment not found immediately after site creation. It might still be provisioning.'
             );
             $this->log()->debug('TerminusNotFoundException: {message}', ['message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->log()->error(
                'An error occurred while waiting for the site to wake: {message}',
                ['message' => $e->getMessage()]
            );
        }
    }

    /**
     * Handles creation of a site with an externally hosted repository (e.g., GitHub).
     */
    protected function createExternallyHostedSite($site_name, $label, Upstream $upstream, User $user, array $options)
    {
        $this->log()->notice('Starting creation process for site with external VCS ({vcs-provider})...', ['vcs-provider' => $options['vcs-provider']]);

        $input = $this->input();
        $output = $this->output();
        $is_interactive = $input->isInteractive();
        $vcs_client = $this->getVcsClient();

        // Should be 'github/gitlab/bitbucket'.
        $vcs_provider = strtolower($options['vcs-provider']);
        $this->log()->debug('VCS provider: {vcs_provider}', ['vcs_provider' => $vcs_provider]);

        // Pantheon Org ID/Name/Label
        $org_id = $options['org'];
        $this->log()->debug('Pantheon organization ID: {org_id}', ['org_id' => $org_id]);

        $repo_name = $options['repository-name'] ?? $site_name;

        // 0. Validate repo name.
        if (empty($repo_name)) {
            throw new TerminusException('Repository name cannot be empty.');
        }
        if (preg_match('/[^a-zA-Z0-9\-_]/', $repo_name)) {
            throw new TerminusException('Repository name "{name}" contains invalid characters. Only alphanumeric, hyphen, and underscore are allowed.', ['name' => $repo_name]);
        }
        $this->log()->debug('Repository name: {repo_name}', ['repo_name' => $repo_name]);


        // 1. Get Pantheon Organization.
        try {
            $membership = $user->getOrganizationMemberships()->get($org_id);
            $pantheon_org = $membership->getOrganization();
        } catch (TerminusNotFoundException $e) {
            // This should have been caught earlier, but double-check
            throw new TerminusException('Pantheon organization "{org}" not found or you are not a member.', ['org' => $org_id]);
        }

        // 2. Determine ICR Upstream & Site Type & Platform
        $icr_upstream = $this->getIcrUpstream($upstream->id, $user);
        $site_type = $this->getSiteType($upstream);
        $preferred_platform = $this->getPreferredPlatformForFramework($site_type);

        // 3. Get existing installations + link to create new installation.
        $installations_resp = $vcs_client->getInstallations($pantheon_org->id, $user->id);
        $existing_installations_data = $installations_resp['data'] ?? [];
        $this->log()->debug('Existing installations: {installations}', ['installations' => print_r($existing_installations_data, true)]);

        list($url, $flag_file) = $this->startTemporaryServer(self::AUTH_LINK_TIMEOUT);

        $auth_links_resp = $vcs_client->getAuthLinks($pantheon_org->id, $user->id, $site_type, $url);
        $auth_links = $auth_links_resp['data'] ?? null;
        $this->log()->debug('VCS Auth Links: {auth_links}', ['auth_links' => print_r($auth_links, true)]);
        $auth_url = null;
        // Iterate over the two possible auth options for the given VCS.
        foreach (['app', 'oauth'] as $auth_option) {
            if (isset($auth_links->{sprintf("%s_%s", $vcs_provider, $auth_option)})) {
                $auth_url = sprintf('"%s"', $auth_links->{sprintf("%s_%s", $vcs_provider, $auth_option)});
                break;
            }
        }

        // GitHub requires an authorization URL.
        if ($vcs_provider === 'github' && (is_null($auth_url) || $auth_url === '""')) {
            $this->cleanup($site_uuid);
            throw new TerminusException(
                'Error authorizing with vcs service: {error_message}',
                ['error_message' => 'No vcs_auth_link returned']
            );
        }

        // 4. Figure out what installation to use and authorize (or create new installation).
        $vcs_org = $options['vcs-org'];

        // Installations is the Installation class objects array.
        $installations = [];
        // Installations_map is a map of lowercase login names to installation IDs.
        $installations_map = [];
        if (!empty($existing_installations_data)) {
            foreach ($existing_installations_data as $installation) {
                // Filter for current VCS provider and backend installations
                if (strtolower($installation->alias) !== $vcs_provider || $installation->installation_type == 'front-end') {
                    continue;
                }
                $installations[$installation->installation_id] = new Installation(
                    $installation->installation_id,
                    $installation->alias,
                    $installation->login_name
                );
                $installations_map[strtolower($installation->login_name)] = $installation->installation_id;
            }
        }
         $this->log()->debug('Found {count} existing GitHub installations for Org {org}: {names}', [
            'count' => count($installations),
            'org' => $pantheon_org->id,
            'names' => implode(', ', array_keys($installations_map))
         ]);

        $this->log()->debug('Installation map: {map}', ['map' => print_r($installations_map, true)]);
        $this->log()->debug('Existing installations: {installations}', ['installations' => print_r($installations, true)]);

        $installation_id = null;

        // If vcs_org is provided, look it up in the installation map
        //   - If it matches an existing installation, use that; otherwise, assume the option was NOT provided
        // If vcs_org is NOT provided, present the user with a list of existing installations and the option for a new one.
        if ($vcs_org) {
            if (isset($installations_map[$vcs_org])) {
                $installation_id = $installations_map[$vcs_org];
                $installation_human_name = $vcs_org;
            }
        }

        if (!$installation_id) {
            // We need to prompt the user for a installation; either because vcs_org was not provided or it didn't match an existing installation.
            if (!$is_interactive) {
                // Non-interactive mode, vcs_org not provided or not found
                throw new TerminusException('--vcs-org is required to match an existing installation in non-interactive mode when --vcs-provider is not Pantheon.');
            }
            if (!empty($installations)) {
                // Prompt user to choose from existing or add new
                $choices = [];
                foreach ($installations as $id => $inst) {
                    $choices[$inst->getLoginName()] = sprintf("%s: %s (%s)", $inst->getVendor(), $inst->getLoginName(), $id);
                }
                $choices[self::ADD_NEW_ORG_TEXT] = 'new';

                $helper = new QuestionHelper();
                $question = new ChoiceQuestion(
                    'Which VCS organization should be used?',
                    array_keys($choices)
                );
                $question->setErrorMessage('Invalid selection %s.');
                $vcs_org_name = $helper->ask($input, $output, $question);

                $installation_id = $choices[$vcs_org_name];
                $installation_human_name = 'new';

                if ($installation_id !== 'new') {
                    $installation_human_name = $vcs_org_name;
                    $installation_id = $installations_map[strtolower($vcs_org_name)];
                }

                $this->log()->info('Selected to go with {installation} installation.', ['installation' => $installation_human_name]);
            } else {
                // No existing installations found, prompt for new.
                $installation_id = 'new';
            }
        }

        // Ensure we have determined the installation ID and target org name
        if (is_null($installation_id)) {
             throw new TerminusException('Could not determine GitHub installation.');
        }

        $site_details = null;
        $existing_installation = true;

        if ($installation_id === 'new') {
            $existing_installation = false;
            $success = $this->handleNewInstallation($vcs_provider, $auth_url, $flag_file, $options);
            if (!$success) {
                $this->cleanup($site_uuid);
                throw new TerminusException('Error authorizing with VCS service: Timeout waiting for authorization to complete.');
            }
            $installations_resp = $vcs_client->getInstallations($pantheon_org->id, $user->id);
            $new_existing_installations_data = $installations_resp['data'] ?? [];
            $this->log()->debug('New existing installations: {installations}', ['installations' => print_r($new_existing_installations_data, true)]);
            // Look for a new installation that wasn't in the previous list.
            foreach ($new_existing_installations_data as $installation) {
                if (strtolower($installation->alias) !== $vcs_provider || $installation->installation_type == 'front-end') {
                    continue;
                }
                if (!isset($installations[$installation->installation_id])) {
                    $installation_id = $installation->installation_id;
                    $installation_human_name = $installation->login_name;
                    break;
                }
            }
        }

        // 5. Validate repository exists (or not) depending on create-repo option.
        // TODO: DEVX-5820.


        // 6. Create Site Record in Pantheon
        $this->log()->notice('Creating Pantheon site ...');
        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name,
            'has_external_vcs' => true,
            'preferred_platform' => $preferred_platform,
            'organization_id' => $pantheon_org->id,
        ];
        $region = $options['region'] ?? $this->config->get('command_site_options_region');
        if ($region) {
            $workflow_options['preferred_zone'] = $region;
            $this->log()->notice('Attempting to create site in region: {region}', compact('region'));
        }

        $site_create_workflow = $this->sites()->create($workflow_options);
        $this->processWorkflow($site_create_workflow);
        $site_uuid = $site_create_workflow->get('waiting_for_task')->site_id ?? null;
        if (!$site_uuid) {
            throw new TerminusException('Could not get site ID from site creation workflow.');
        }
        $this->log()->notice('Pantheon site record created successfully (ID: {id}).', ['id' => $site_uuid]);

        // 7. Interact with go-vcs-service: Create Workflow
        $this->log()->notice('Initiating workflow with VCS service...');
        $vcs_workflow_data = [
            'user_uuid' => $user->id,
            'org_uuid' => $pantheon_org->id,
            'site_uuid' => $site_uuid,
            'site_name' => $site_name,
            'site_type' => $site_type,
        ];

        $vcs_workflow_response = null;
        $vcs_workflow_uuid = null;

        try {
            $vcs_workflow_response = $vcs_client->createWorkflow($vcs_workflow_data);
            $this->log()->debug("VCS Service Workflow Response: " . print_r($vcs_workflow_response, true));

            // Normalize data and extract key info.
            $data = (array) ($vcs_workflow_response['data'][0] ?? []);
            $vcs_workflow_uuid = $data['workflow_uuid'] ?? null;
            $this->log()->debug('VCS workflow UUID: {uuid}', ['uuid' => $vcs_workflow_uuid]);

            $this->log()->notice('VCS service workflow initiated successfully.');
        } catch (\Throwable $t) {
            $this->cleanupPantheonSite($site_uuid, 'Failed to initiate workflow with VCS service.');
            throw new TerminusException(
                'Error initiating workflow with VCS service: {error_message}',
                ['error_message' => $t->getMessage()]
            );
        }

        // 8. Existing installation, we need to authorize it.
        $authorize_data = [
            'site_uuid' => $site_uuid,
            'user_uuid' => $user->id,
            'installation_id' => (int) $installation_id,
            'org_uuid' => $pantheon_org->id,
        ];
        try {
            $data = $this->getVcsClient()->authorize($authorize_data);
            if (!$data['success']) {
                throw new TerminusException("An error happened while authorizing: {error_message}", ['error_message' => $data['data']]);
            }
            $site_details = $this->getVcsClient()->getSiteDetails($site_uuid);
            $site_details = (array) $site_details['data'][0];
        } catch (TerminusException $e) {
            $this->cleanupPantheonSite($site_uuid, 'Failed to authorize existing VCS installation.');
            throw $e;
        }

        if (!$site_details['is_active']) {
            $this->cleanupPantheonSite($site_uuid, 'VCS service reports site is not active after authorization.');
            throw new TerminusException('Error authorizing with VCS service: Site is not yet active according to the service.');
        }

        // 9. Create Repository via go-vcs-service (repoCreate)
        $repo_action_done = "created";
        $repo_action_working = "creating";
        $create_repo = $options['create-repo'];
        if ($create_repo) {
            $this->log()->notice("Creating repository '{repo}'...", ['repo' => $repo_name]);
        } else {
            $repo_action_done = "linked";
            $repo_action_working = "linking";
            $this->log()->notice("Linking existing repository '{repo}' as requested. Repository should exist at this point and the GitHub application should have access to it, otherwise this will fail.", ['repo' => $repo_name]);
            if ($is_interactive && $existing_installation && !$create_repo && $vcs_provider == 'github') {
                $this->pauseForGithubRepoAccess($installation_human_name ?? '', 60);
            }
        }
        $vcs_id = array_search($vcs_provider, $this->vcs_providers);
        $repo_create_data = [
            'site_uuid' => $site_uuid,
            'label' => $repo_name,
            'skip_create' => !$create_repo,
            'is_private' => strtolower($options['visibility']) === 'private',
            'vendor_id' => $vcs_id,
        ];

        $target_repo_url = null;
        try {
            $repo_create_response = $vcs_client->repoCreate($repo_create_data);
            $this->log()->debug("VCS Repo Create Response: " . print_r($repo_create_response, true));
            $data = (array) ($repo_create_response['data'] ?? []);
            $target_repo_url = $data['repo_url'] ?? null;
            if (!$target_repo_url) {
                throw new TerminusException('VCS service did not return repository URL after creation.');
            }
            $this->log()->notice('VCS repository {action} successfully: {url}', [
                'action' => $repo_action_done,
                'url' => $target_repo_url,
            ]);
        } catch (\Throwable $t) {
            $message = $t->getMessage();
            if (!$create_repo && $vcs_provider == 'github') {
                if (strpos($message, 'Code: 404') !== false) {
                    $this->log()->error('The repository could not be found. Please ensure it exists and that the GitHub application has access to it.');
                }
            }
            $this->log()->error('Error {action} repository via VCS service: {error_message}', [
                'action' => $repo_action_working,
                'error_message' => $message
            ]);
            $this->cleanupPantheonSite($site_uuid, printf("Error %s repository via VCS service.", $repo_action_working));
            throw new TerminusException(
                'Error {action} repository via VCS service: {error_message}',
                ['action' => $repo_action_working, 'error_message' => $message]
            );
        }

        // 10. Install webhook if needed.
        if ($vcs_provider != 'github') {
            $this->log()->notice('Next: Installing webhook...');
            try {
                $webhook_data = [
                    'repository' => $repo_name,
                    'vendor' => $vcs_provider,
                    'workflow_uuid' => $vcs_workflow_uuid,
                    'site_uuid' => $site_uuid,
                ];
                $data = $this->getVcsClient()->installWebhook($webhook_data);
                if (!$data['success']) {
                    throw new TerminusException("An error happened while installing webhook: {error_message}", ['error_message' => $data['data']]);
                }
            } catch (\Throwable $t) {
                $this->cleanupPantheonSite($site_uuid, 'Failed to install webhook.');
                throw new TerminusException(
                    'Error installing webhook: {error_message}',
                    ['error_message' => $t->getMessage()]
                );
            }
            $this->log()->notice('Webhook installed');
        }

        // 11. Deploy Pantheon Product/Upstream (using ICR upstream)
        $site = $this->getSiteById($site_uuid);
        if (!$site) {
             // Should not happen if we got this far, but check.
             $this->cleanupPantheonSite($site_uuid, 'Error while retrieving new site information after repo creation');
             throw new TerminusException('Failed to retrieve site object (ID: {id}) before deploying product.', ['id' => $site_uuid]);
        }

        if ($preferred_platform == "sta") {
            try {
                $this->log()->notice('Waiting for project to be ready for the next step...');
                $this->getVcsClient()->processProjectReady($site_uuid, self::DEFAULT_TIMEOUT);
            } catch (TerminusException $e) {
                $this->log()->warning("Error while waiting for project ready: {error_message}", ['error_message' => $e->getMessage()]);
                $this->log()->warning("Moving on to the next step, but this may cause issues with platform domain assignment.");
            }
        }

        $this->log()->notice('Provisioning site resources...');
        try {
            $this->processWorkflow($site->deployProduct($icr_upstream->id));
            $this->log()->notice('Site resources provisioned successfully.');
        } catch (\Throwable $e) {
            // The site exists, the repo exists, just the CMS deploy failed.
            $this->cleanupPantheonSite($site_uuid, sprintf('Error occurred while provisioning site resources: %s', $e->getMessage()), true);
            throw new TerminusException('Error deploying product: {msg}', ['msg' => $e->getMessage()]);
        }

        // 12. Push Initial Code to External Repository via go-vcs-service (repoInitialize)
        if ($preferred_platform == "sta") {
            try {
                $this->log()->notice('Waiting for project to be ready...');
                $this->getVcsClient()->processHealthcheck($site_uuid, self::DEFAULT_TIMEOUT);
            } catch (TerminusException $e) {
                $this->log()->warning("Error while waiting for project healthcheck: {error_message}", ['error_message' => $e->getMessage()]);
                $this->log()->warning("Moving on to the next step, but you may need to push another commit to the repository to get things started...");
            }
        }

        if ($options['create-repo']) {
            $wf_start_time = time();
            $this->log()->notice('Pushing initial code from upstream ({up_id}) to {repo_url}...', ['up_id' => $upstream->id, 'repo_url' => $target_repo_url]);
            $clone_repo = ($options['skip-clone-repo'] == false);
            try {
                [$upstream_repo_url, $upstream_repo_branch] = $this->getUpstreamInformation($upstream->id, $user);

                $repo_initialize_data = [
                    'site_id' => $site_uuid,
                    'target_repo_url' => $target_repo_url,
                    'upstream_id' => $upstream->id,
                    'upstream_repo_url' => $upstream_repo_url,
                    'upstream_repo_branch' => $upstream_repo_branch,
                    'installation_id' => (string) $installation_id,
                    'organization_id' => $pantheon_org->id,
                    'vendor_id' => $vcs_id,
                ];

                $vcs_client->repoInitialize($repo_initialize_data);
                $this->log()->notice('Initial code pushed successfully.');

                if ($clone_repo) {
                    $this->cloneRepo($repo_initialize_data['target_repo_url']);
                }
            } catch (\Throwable $t) {
                $clone_repo = false;

                // If repoInitialize fails, the site and repo exist, but code isn't there.
                // Don't delete the site. Log a warning and the repo URL.
                $this->log()->warning(
                    'Error initializing repository with upstream contents: {error_message}',
                    ['error_message' => $t->getMessage()]
                );
                $this->log()->warning('The site and repository have been created, but the initial code push failed.');
                $this->log()->warning('You may need to manually push the code to {repo_url}', [
                    'repo_url' => $target_repo_url
                ]);
            }

            // 13. Wait for workflow and site to wake.
            if ($upstream->get('framework') !== 'nodejs') {
                $this->log()->notice('Waiting for sync code workflow to succeed...');
                try {
                    $this->waitForWorkflow($wf_start_time, $site, 'dev', '', self::DEFAULT_TIMEOUT, 10);
                } catch (TerminusException $e) {
                    // If the workflow fails, the site and repo exist, but code isn't there.
                    // Don't delete the site. Log a warning and the repo URL.
                    $this->log()->warning(
                        'Error waiting for sync code workflow to succeed: {error_message}',
                        ['error_message' => $e->getMessage()]
                    );
                    $this->log()->warning('The site and repository have been created, but the sync_code workflow was not found; check for its completion in the Pantheon Dashboard.');
                }
            }
        } else {
            $this->log()->notice('Requesting initial code delivery...');
            $this->getVcsClient()->buildRepo($site_uuid);
        }
        // Wait for the dev environment to be ready.
        try {
            $this->waitForDevEnvironment($site, $preferred_platform);
        } catch (TerminusException $e) {
            // If the dev environment fails to wake, log a warning.
            $this->log()->warning(
                'Error waiting for dev environment to wake: {error_message}',
                ['error_message' => $e->getMessage()]
            );
            $this->log()->warning('The site and repository have been created, but the dev environment may be not yet available.');
        }

        // 14. Final Success Message & Wait for Wake
        $this->log()->notice('---');
        $this->log()->notice('Site "{site}" created successfully with GitHub repository!', ['site' => $site->getName()]);
        $this->log()->notice('GitHub Repository: {url}', ['url' => $target_repo_url]);
        $this->log()->notice('Pantheon Dashboard: {url}', ['url' => $site->dashboardUrl()]);
        if ($clone_repo) {
            $this->log()->notice('Code repository cloned successfully to the current directory.');
        }
    }

    /**
     * Pauses execution to allow user to ensure repo access.
     */
    private function pauseForGithubRepoAccess(string $installation_human_name, int $timeout)
    {
        $installation_link = sprintf(
            'https://github.com/%s',
            $installation_human_name,
        );
        $this->log()->notice(
            "If you have not already done so, please ensure that the application has access to the repository by visiting your GitHub account: {url}, " .
            "then Settings -> Applications. Select the current application and ensure it has access to the repository you wish to use.",
            ['url' => $installation_link]
        );
        $this->log()->notice(
            "Pausing for up to {$timeout} seconds to allow time for you to complete this step if needed. Press enter to continue..."
        );

        $answered = false;
        $stream = STDIN;
        $read = [$stream];
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, $timeout)) {
            $answered = true;
        }
        if (!$answered) {
            $this->log()->notice("Continuing after {$timeout} seconds...");
        }
    }

    /**
     * Start temporary server to handle app installation redirects.
     * Returns array of (url, flag_file).
     */
    private function startTemporaryServer(int $timeout = 600): array
    {
        $this->log()->debug('Starting temporary server to handle VCS installation redirects if needed...');

        $token = bin2hex(random_bytes(16));
        $port = $this->findAvailablePort();
        $url = "http://localhost:{$port}/callback?token={$token}";

        list($server_script, $flag_file) = $this->createServerScript($token, self::REDIRECT_URL);
        $process = new Process(['php', '-S', "localhost:{$port}", $server_script]);
        $process->start();

        $this->serverProcess = $process;

        $this->log()->debug("Temporary server started at: {$url}");

        return [$url, $flag_file];
    }

    /**
     * Find an available port on localhost.
     */
    private function findAvailablePort(): int
    {
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        if ($socket === false) {
            throw new \RuntimeException("Cannot find open port");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int)substr(strrchr($address, ':'), 1);
    }

    /**
     * Create a temporary PHP script to handle server requests.
     */
    private function createServerScript(string $token, $redirect_url): array
    {
        $scriptPath = sys_get_temp_dir() . "/notify_server_$token.php";
        $flagFile = sys_get_temp_dir() . "/terminus_notify_$token";

        $php = <<<PHP
<?php
parse_str(\$_SERVER['QUERY_STRING'] ?? '', \$query);
\$request_uri = \$_SERVER['REQUEST_URI'] ?? '';
if (strpos(\$request_uri, '/callback') === 0 && (\$query['token'] ?? '') === '$token') {
    echo "Authorization successful. You can close this window.\n";
    file_put_contents('$flagFile', 'done');
    header('Location: ' . '$redirect_url');
    exit;
} else {
    echo "ERROR: Invalid request.\n";
    http_response_code(403);
    echo "Forbidden";
}
PHP;
        file_put_contents($scriptPath, $php);
        return [$scriptPath, $flagFile];
    }


    /**
     * Clone the repository using the converted SSH URL.
     */
    private function cloneRepo($repo_url)
    {
        $repo_url = $this->convertToSsh($repo_url);

        // Run git clone command
        $process = new Process(['git', 'clone', $repo_url]);
        $process->run();

        if (!$process->isSuccessful()) {
            // @codingStandardsIgnoreLine
            throw new ProcessFailedException($process);
        }

        $this->log()->notice($process->getOutput());
    }

    /**
     * Convert the repository URL to SSH format.
     */
    private function convertToSsh($repo_url)
    {
        $parsedUrl = parse_url($repo_url);
        if (!isset($parsedUrl['host'], $parsedUrl['path'])) {
            // @codingStandardsIgnoreLine
            throw new TerminusException('Invalid repository URL: {repo_url}', ['repo_url' => $repo_url]);
        }

        $host = $parsedUrl['host'];
        $path = ltrim($parsedUrl['path'], '/');

        // Remove .git if present to avoid duplication
        $path = preg_replace('/\.git$/', '', $path);
        $sshUrl = "git@$host:$path.git";

        $this->log()->notice('Converted repository URL: {repo_url}', ['repo_url' => $sshUrl]);
        return $sshUrl;
    }

    /**
     * Get site type as expected by ICR site creation API.
     * Uses the original upstream, not the ICR one.
     */
    protected function getSiteType(Upstream $upstream): string
    {
        $framework = $upstream->get('framework');
        if (empty($framework)) {
            throw new TerminusException('Cannot determine site type for custom upstream without framework.');
        }
        switch ($framework) {
            case 'drupal8':
                return 'cms-drupal';
            case 'wordpress':
            case 'wordpress_network':
                return 'cms-wordpress';
            case 'nodejs':
                return 'nodejs';
            default:
                throw new TerminusException('Framework {framework} not currently supported for external VCS site creation.', compact('framework'));
        }
    }

    /**
     * Get ICR upstream based on the upstream passed as argument.
     */
    protected function getIcrUpstream(string $original_upstream_id, User $user): Upstream
    {
        $original_upstream = $user->getUpstreams()->get($original_upstream_id);
        if ($original_upstream->get('type') === 'icr') {
            // If the original upstream is already an ICR upstream, return it directly.
            return $original_upstream;
        }
        $framework = $original_upstream->get('framework');
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
            case 'wordpress_network':
                return $user->getUpstreams()->get('wordpress-multisite-icr');
            default:
                throw new TerminusException('Framework {framework} not supported.', compact('framework'));
        }
    }

    /**
     * Get upstream repository URL and branch.
     */
    protected function getUpstreamInformation(string $upstream_id, User $user): array
    {
        $upstream = $user->getUpstreams()->get($upstream_id);
        $repo_url = $upstream->get('repository_url');
        $repo_branch = $upstream->get('repository_branch');

        if (empty($repo_url) || empty($repo_branch)) {
             throw new TerminusException('Could not retrieve repository URL or branch for upstream "{id}".', ['id' => $upstream_id]);
        }
        return [$repo_url, $repo_branch];
    }

    /**
     * Get preferred platform based on framework/site_type.
     */
    private function getPreferredPlatformForFramework($site_type): string
    {
        // ATM only nodejs framework is supported on the STA platform update it when more frameworks are supported.
        // Map site_type back to framework logic if needed, or use site_type directly
        if ($site_type == 'nodejs') {
            return 'sta';
        }
        // Default to 'cos' for cms-drupal, cms-wordpress etc.
        return 'cos';
    }

    /**
     * Cleans up the Pantheon site record if creation fails mid-process.
     * Adapted from RepositorySiteCreateCommand::cleanup
     */
    protected function cleanupPantheonSite(string $site_uuid, string $failure_reason, bool $cleanup_vcs = true): void
    {
        $this->log()->error('Site creation failed: {reason}', ['reason' => $failure_reason]);
        $this->log()->notice("Attempting to clean up Pantheon site (ID: {id})...", ['id' => $site_uuid]);

        try {
            $site = $this->sites()->get($site_uuid);
            if ($site) {
                $workflow = $site->delete();
                // Watch the workflow using the user object since the site object will be gone
                $workflow->setOwnerObject($this->session()->getUser());
                $this->processWorkflow($workflow);
                $message = $workflow->getMessage();
                $this->log()->notice('Pantheon site cleanup successful: {msg}', ['msg' => $message]);

                if ($cleanup_vcs) {
                    // Call VCS service cleanup if needed
                    $this->log()->notice('Cleaning up VCS records in Pantheon...');
                    $this->getVcsClient()->cleanupSiteDetails($site_uuid);
                    $this->log()->notice('VCS records cleanup successful. You may need to manually delete the repository if it was created.');
                }
            } else {
                 $this->log()->warning('Could not find site {id} to clean up (already deleted?).', ['id' => $site_uuid]);
            }
        } catch (TerminusNotFoundException $e) {
             $this->log()->warning('Could not find site {id} to clean up (already deleted?).', ['id' => $site_uuid]);
        } catch (\Throwable $t) {
            // Catch potential errors during deletion workflow processing
            $this->log()->error("Error during Pantheon site cleanup: {error_message}", ['error_message' => $t->getMessage()]);
            throw new TerminusException('Error during Pantheon site cleanup: {error_message}', ['error_message' => $t->getMessage()]);
        }
    }

    /**
     * Handle new installation based on VCS provider.
     * Currently only supports GitHub.
     */
    protected function handleNewInstallation(string $vcs_provider, string $auth_url, string $flag_file, array $options): bool
    {
        switch ($vcs_provider) {
            case 'github':
                return $this->handleGithubNewInstallation($auth_url, $flag_file);

            case 'gitlab':
                return $this->handleGitLabNewInstallation($options);
        }
        return false;
    }

    /**
     * Handle Github new installation browser flow.
     * Adapted from RepositorySiteCreateCommand::handleGithubNewInstallation
     */
    protected function handleGithubNewInstallation(string $auth_url, string $flag_file): bool
    {
        // Ensure auth_url is unquoted for opening in the browser.
        $url_to_open = trim($auth_url, '"');

        $this->log()->notice("Opening GitHub App authorization link in your browser...");
        $this->log()->notice("If your browser does not open, please manually visit this URL:");
        $this->log()->notice($url_to_open);

        try {
            $this->getContainer()
                ->get(LocalMachineHelper::class)
                ->openUrl($url_to_open);
        } catch (\Exception $e) {
             $this->log()->warning("Could not automatically open browser: " . $e->getMessage());
             $this->log()->warning("Please open the URL manually: " . $url_to_open);
        }

        $minutes = (int) (self::AUTH_LINK_TIMEOUT / 60);

        $this->log()->notice(sprintf("Waiting for authorization to complete in browser (up to %d minutes)...", $minutes));

        // A server should be running by now and will eventually (if succeeded) write 'done' to the flag file.
        $start_time = time();
        $success = false;
        while (true) {
            if (file_exists($flag_file)) {
                $this->log()->notice('Authorization confirmed via browser.');
                // Cleanup flag file
                unlink($flag_file);
                $success = true;
                break;
            }
            if ((time() - $start_time) > self::AUTH_LINK_TIMEOUT) {
                // Timeout
                $this->log()->error('Authorization timed out.');
                break;
            }
            // Sleep for a short interval before checking again
            usleep(500000); // 0.5 seconds
        }

        return $success;
    }

    /**
     * Handle GitLab new installation.
     */
    protected function handleGitLabNewInstallation(string $site_uuid, array $options): bool
    {
        $token = $options['vcs-token'] ?? null;
        if (empty($token) && !$this->input()->isInteractive()) {
            throw new TerminusException('GitLab installation requires a token. Please provide --vcs-token or run interactively.');
        }
        if (empty($token)) {
            // @TODO Write correct instructions.
            $this->log()->notice('Get a GitLab access token. More details at https://docs.pantheon.io');

            $helper = new QuestionHelper();
            $question = new Question('Enter your GitLab token: ');
            $question->setValidator(function ($answer) {
                if (empty(trim($answer ?? ''))) {
                    throw new \RuntimeException('GitLab token cannot be empty.');
                }
                return trim($answer);
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
            // TODO: Backend should be updated to not need site_uuid here.
            'site_uuid' => '',
            'vcs_organization' => $group_name,
            // TODO: Cleanup in go-vcs-service to not need it.
            'pantheon_session' => 'UNUSED',
        ];
        $data = $this->getVcsClient()->installWithToken($post_data);
        if (!$data['success']) {
            throw new TerminusException("An error happened while authorizing: {error_message}", ['error_message' => $data['data']]);
        }

        return true;
    }
}
