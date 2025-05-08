<?php

// Namespace matches the new location within the plugin
namespace Pantheon\TerminusRepository\Commands\Site;

// Core Terminus dependencies
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException; // Added for specific exception handling
use Pantheon\Terminus\Helpers\Traits\WaitForWakeTrait;
use Pantheon\Terminus\Models\Environment; // Needed for WaitForWake
use Pantheon\Terminus\Models\Organization; // Needed for org handling
use Pantheon\Terminus\Models\Upstream;
use Pantheon\Terminus\Models\User;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Session\SessionAwareInterface; // Needed for SiteCommand base potentially
use Pantheon\Terminus\Session\SessionAwareTrait;
use Pantheon\Terminus\Config\ConfigAwareTrait; // Added for config access
use Pantheon\Terminus\Helpers\LocalMachineHelper; // Added for browser opening

// Dependencies from the old repository plugin command
use Pantheon\TerminusRepository\VcsApi\Client; // Keep Client if directly used, otherwise remove if only via trait
use Pantheon\TerminusRepository\VcsApi\Installation; // Keep for type hinting if used
use Pantheon\TerminusRepository\VcsApi\VcsClientAwareTrait;
use Pantheon\TerminusRepository\WorkflowWaitTrait;

// Base SiteCommand from core (contains getSiteById etc.)
use Pantheon\Terminus\Commands\Site\SiteCommand;

// For prompting
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question; // Keep if direct Question needed, maybe not


/**
 * Creates a new site, potentially with an external Git repository.
 * This command overrides the core site:create command when the plugin is enabled.
 */
class CreateCommand extends SiteCommand implements RequestAwareInterface, SiteAwareInterface // Extends SiteCommand now
{
    // Traits from core CreateCommand
    use WorkflowProcessingTrait;
    use WaitForWakeTrait;
    use WorkflowWaitTrait;

    // Traits needed for plugin functionality & dependencies
    use VcsClientAwareTrait; // Provides getVcsClient()
    // SiteAwareTrait is inherited from SiteCommand
    // SessionAwareTrait is inherited from SiteCommand
    // ConfigAwareTrait is inherited from SiteCommand
    // LoggerAwareTrait is inherited from SiteCommand
    // ContainerAwareTrait is inherited from SiteCommand
    // RequestAwareTrait is needed for VcsClientAwareTrait

    // Supported VCS types (can be expanded later)
    protected $vcs_providers = ['pantheon', 'github', 'gitlab', 'bitbucket']; // Added 'pantheon'

    /**
     * Creates a new site
     *
     * @authorize
     *
     * @command site:create
     * @aliases site-create
     *
     * @param string $site_name Site name (machine name)
     * @param string $label Site label (human-readable name)
     * @param string $upstream_id Upstream name or UUID (e.g., wordpress, drupal-composer-managed)
     * @option org Organization name, label, or ID. Required if --vcs=github is used.
     * @option region Specify the service region where the site should be created. See documentation for valid regions.
     * @option vcs VCS provider for the site repository (e.g., github, pantheon). Default is pantheon.
     * @option vcs-org Name of the Github organization containing the repository. Required if --vcs=github is used.
     * @option visibility Visibility of the external repository (private or public). Only applies if --vcs=github. Default is private.
     *
     * @usage <site> <label> <upstream> Creates a new Pantheon-hosted site named <site>, labeled <label>, using code from <upstream>.
     * @usage <site> <label> <upstream> --org=<org> Creates site associated with <organization>, with a Pantheon-hosted git repository.
     * @usage <site> <label> <upstream> --org=<org> --vcs=github --vcs-org=<github-org> Creates a new site associated with Pantheon <organization>, using <upstream> code, with the repository hosted on Github in the <github-org> organization.
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
            'vcs' => 'pantheon', // Default to pantheon for now - we can make this a required argument later
            'vcs-org' => null,
            'visibility' => 'private',
            // Note: no-interaction is a global option, accessed via $this->input
        ]
    ) {
        $input = $this->input(); // Get input object for checking global options
        $vcs_provider = strtolower($options['vcs']);
        $org_id = $options['org'];
        $vcs_org = $options['vcs-org']; // Renamed from installation_id

        // Validate VCS provider
        if (!in_array($vcs_provider, $this->vcs_providers)) {
            throw new TerminusException(
                'Invalid VCS provider specified: {vcs}. Supported providers are: {supported}',
                ['vcs' => $vcs_provider, 'supported' => implode(', ', $this->vcs_providers)]
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
                    'The --org option is required when using an external VCS provider (--vcs={vcs}).',
                    ['vcs' => $vcs_provider]
                );
            }
            // Specific validation for GitHub
            if ($vcs_provider === 'github') {
                if (empty($vcs_org)) {
                    // In non-interactive mode, it's definitely required.
                    // The prompt logic will be handled later if interactive.
                    if (!$input->isInteractive()) {
                         throw new TerminusException(
                             'The --vcs-org option is required when using --vcs=github in non-interactive mode.'
                         );
                    }

                    // Interactive later, error out for now.
                     throw new TerminusException(
                         'The --vcs-org option is required when using --vcs=github.'
                     );
                }
            }

            // Other VCS providers validation here
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
            } catch (\Exception $listError) {
                 $this->log()->warning('Could not retrieve list of available upstreams: {msg}', ['msg' => $listError->getMessage()]);
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
            // 'has_external_vcs' => false, // Default is false, no need to set explicitly
        ];

        // Region handling (copied from core)
        $region = $options['region'] ?? $this->config->get('command_site_options_region');
        if ($region) {
            $workflow_options['preferred_zone'] = $region;
            $this->log()->notice('Attempting to create site in region: {region}', compact('region'));
        }

        // Organization handling (copied from core, improved error handling)
        $org = null; // Initialize org variable
        if (!is_null($org_id = $options['org'])) {
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
                 throw new TerminusException('Organization "{org}" not found or you are not a member.', ['org' => $org_id]);
            } catch (\Exception $e) {
                 // Catch other potential errors during org fetching
                 throw new TerminusException('Error retrieving organization "{org}": {message}', ['org' => $org_id, 'message' => $e->getMessage()]);
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
        // Use getSiteById which is available via SiteCommand base class
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

            // Wait for site to wake up (copied from core)
            $this->log()->notice('Waiting for site dev environment to become available...');
            try {
                $env = $site->getEnvironments()->get('dev');
                if ($env instanceof Environment) {
                    $this->waitForWake($env, $this->logger);
                    $this->log()->notice('Site dev environment is available.');
                    $this->log()->notice('---');
                    $this->log()->notice('Site "{site}" created successfully!', ['site' => $site->getName()]);
                    $this->log()->notice('Dashboard: {url}', ['url' => $site->dashboardUrl()]);
                    $this->log()->notice('---');
                } else {
                    // This case should ideally not happen if the site exists
                    $this->log()->warning('Could not retrieve the dev environment object after site creation, unable to confirm availability.');
                }
            } catch (TerminusNotFoundException $e) {
                 $this->log()->warning('Dev environment not found immediately after site creation. It might still be provisioning.');
                 $this->log()->debug('TerminusNotFoundException: {message}', ['message' => $e->getMessage()]);
            } catch (\Exception $e) {
                 $this->log()->error('An error occurred while waiting for the site to wake: {message}', ['message' => $e->getMessage()]);
            }
        } else {
            // This shouldn't happen if the create workflow succeeded and returned an ID, but good to handle.
            throw new TerminusException('Failed to retrieve site object (ID: {id}) after creation workflow succeeded.', ['id' => $site_id]);
        }
    }

    /**
     * Handles creation of a site with an externally hosted repository (e.g., GitHub).
     */
    protected function createExternallyHostedSite($site_name, $label, Upstream $upstream, User $user, array $options)
    {
        $this->log()->notice('Starting creation process for site with external VCS ({vcs})...', ['vcs' => $options['vcs']]);
        $input = $this->input(); // Get input object for checking global options
        $vcs_provider = strtolower($options['vcs']); // Should be 'github' at this point
        $vcs_org_name = $options['vcs-org']; // The GitHub org name provided by the user
        $org_id = $options['org']; // Pantheon Org ID/Name/Label

        // 1. Get Pantheon Organization (already validated that $org_id is set)
        try {
            $membership = $user->getOrganizationMemberships()->get($org_id);
            $pantheon_org = $membership->getOrganization();
        } catch (TerminusNotFoundException $e) {
            // This should have been caught earlier, but double-check
            throw new TerminusException('Pantheon organization "{org}" not found or you are not a member.', ['org' => $org_id]);
        }

        // 2. Determine ICR Upstream & Site Type & Platform
        $icr_upstream = $this->getIcrUpstream($upstream->id, $user); // Use the original upstream ID
        $site_type = $this->getSiteType($upstream); // Use the original upstream for type determination
        $preferred_platform = $this->getPreferredPlatformForFramework($site_type);

        // 3. Create Site Record in Pantheon
        $this->log()->notice('Creating Pantheon site record (with external VCS flag)...');
        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name,
            'has_external_vcs' => true,
            'preferred_platform' => $preferred_platform,
            'organization_id' => $pantheon_org->id, // Mandatory for eVCS
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

        // 4. Interact with go-vcs-service: Create Workflow
        $this->log()->notice('Initiating workflow with VCS service...');
        $vcs_workflow_data = [
            'user_uuid' => $user->id,
            'org_uuid' => $pantheon_org->id,
            'site_uuid' => $site_uuid,
            'site_name' => $site_name,
            'site_type' => $site_type,
        ];

        $vcs_client = $this->getVcsClient(); // From VcsClientAwareTrait
        $vcs_workflow_response = null;
        $auth_url = null;
        $existing_installations_raw = [];
        $vcs_workflow_uuid = null;

        try {
            $vcs_workflow_response = $vcs_client->createWorkflow($vcs_workflow_data);
            $this->log()->debug("VCS Service Workflow Response: " . print_r($vcs_workflow_response, true));

            // Normalize data and extract key info
            $data = (array) ($vcs_workflow_response['data'][0] ?? []);
            $vcs_workflow_uuid = $data['workflow_uuid'] ?? null;
            $existing_installations_raw = $data['existing_installations'] ?? [];

            // Find the GitHub auth URL
            $auth_links = $data['vcs_auth_links'] ?? null;
            if (isset($auth_links->github_app)) {
                 $auth_url = sprintf('"%s"', $auth_links->github_app);
            } elseif (isset($auth_links->github_oauth)) { // Fallback, though app is preferred
                 $auth_url = sprintf('"%s"', $auth_links->github_oauth);
            }

            if (empty($vcs_workflow_uuid) || (empty($auth_url) || $auth_url === '""') && empty($existing_installations_raw)) {
                 throw new TerminusException('VCS service did not return necessary workflow details (workflow_uuid, auth_url, or existing_installations).');
            }
            $this->log()->notice('VCS service workflow initiated successfully.');

        } catch (\Throwable $t) {
            $this->cleanupPantheonSite($site_uuid, 'Failed to initiate workflow with VCS service.');
            throw new TerminusException(
                'Error initiating workflow with VCS service: {error_message}',
                ['error_message' => $t->getMessage()]
            );
        }

        // 5. Handle GitHub App Installation / Authorization (using vcs-org)
        $this->log()->notice('Checking GitHub App installations for organization: {vcs_org}', ['vcs_org' => $vcs_org_name]);
        $installation_id = null;
        $site_details = null;
        $matching_installations = [];

        if (!empty($existing_installations_raw)) {
            foreach ($existing_installations_raw as $inst) {
                // Filter for GitHub and matching organization login name
                if (isset($inst->vendor) && $inst->vendor === 'github' && isset($inst->login_name) && strtolower($inst->login_name) === strtolower($vcs_org_name)) {
                    // Check installation type if available (prefer cms-site or backend?)
                    // The old code checked for 'front-end' and skipped it. Let's keep that.
                    if (isset($inst->installation_type) && $inst->installation_type == 'front-end') {
                        continue;
                    }
                    $matching_installations[$inst->installation_id] = new Installation(
                        $inst->installation_id,
                        $inst->vendor,
                        $inst->login_name
                    );
                }
            }
        }

        $num_matches = count($matching_installations);
        $this->log()->notice('Found {count} existing GitHub App installation(s) matching organization "{org}".', ['count' => $num_matches, 'org' => $vcs_org_name]);

        if ($num_matches === 1) {
            // Exactly one match, use it
            $installation = reset($matching_installations); // Get the first (only) element
            $installation_id = $installation->getInstallationId(); // Corrected method call
            $this->log()->notice('Using existing installation ID: {id}', ['id' => $installation_id]);
        } elseif ($num_matches > 1) {
            // Multiple matches, prompt if interactive
            if ($input->isInteractive()) {
                $helper = new QuestionHelper();
                $question = new ChoiceQuestion(
                    'Multiple GitHub App installations found for organization "{org}". Please select the one to use:',
                    // Format choices for display
                    array_map(fn($inst) => sprintf('ID: %s (Login: %s)', $inst->getInstallationId(), $inst->getLoginName()), $matching_installations), // Corrected method calls
                    null // No default
                );
                $question->setErrorMessage('Invalid selection.');
                $chosen_display = $helper->ask($input, $this->output(), $question);
                // Find the ID from the chosen display string
                foreach ($matching_installations as $id => $inst) {
                    if (sprintf('ID: %s (Login: %s)', $inst->getInstallationId(), $inst->getLoginName()) === $chosen_display) { // Corrected method calls
                        $installation_id = $id;
                        break;
                    }
                }
                if (!$installation_id) {
                     throw new TerminusException('Failed to determine installation ID from selection.'); // Should not happen
                }
                $this->log()->notice('Using selected installation ID: {id}', ['id' => $installation_id]);
            } else {
                // Non-interactive, throw error
                $ids = implode(', ', array_keys($matching_installations));
                $this->cleanupPantheonSite($site_uuid, 'Multiple GitHub installations found in non-interactive mode.');
                throw new TerminusException(
                    'Multiple GitHub App installations found for organization "{org}" ({ids}). Please specify the correct one or run interactively.',
                    ['org' => $vcs_org_name, 'ids' => $ids]
                );
            }
        } else {
            // No matches, trigger new installation flow
            $this->log()->notice('No existing installation found for "{org}". Initiating new installation flow.', ['org' => $vcs_org_name]);
            if (empty($auth_url) || $auth_url === '""') {
                 $this->cleanupPantheonSite($site_uuid, 'No existing installation found and no auth URL provided by VCS service.');
                 throw new TerminusException('Cannot initiate new GitHub App installation: No authorization URL provided by the VCS service.');
            }
            try {
                // handleNewInstallation will call handleGithubNewInstallation
                $site_details = $this->handleNewInstallation($vcs_provider, $auth_url, $site_uuid, $options);
                // Extract installation_id from site_details AFTER successful auth
                $installation_id = $site_details['installation_id'] ?? null;
                if (!$installation_id) {
                    throw new TerminusException('VCS service did not return installation ID after successful authorization.');
                }
                 $this->log()->notice('New installation authorized successfully. Installation ID: {id}', ['id' => $installation_id]);
            } catch (\Throwable $e) {
                $this->cleanupPantheonSite($site_uuid, 'Failed during new GitHub App installation flow.');
                throw $e; // Re-throw the exception caught by handleNewInstallation or thrown within it
            }
        }

        // If we used an existing installation, we need to explicitly authorize it and get site details
        if ($installation_id && !$site_details) {
            $this->log()->notice('Authorizing with existing installation ID: {id}', ['id' => $installation_id]);
            $authorize_data = [
                'site_uuid' => $site_uuid,
                'user_uuid' => $user->id,
                'installation_id' => (int) $installation_id,
                'org_uuid' => $pantheon_org->id, // Pantheon Org UUID
            ];
            try {
                $auth_response = $vcs_client->authorize($authorize_data);
                if (!($auth_response['success'] ?? false)) {
                    throw new TerminusException("Failed to authorize with existing installation: {error}", ['error' => ($auth_response['data'] ?? 'Unknown error')]);
                }
                // Fetch site details after successful authorization
                $site_details_response = $vcs_client->getSiteDetails($site_uuid);
                $site_details = (array) ($site_details_response['data'][0] ?? []);
                if (empty($site_details)) {
                     throw new TerminusException('Could not retrieve site details from VCS service after authorization.');
                }
                 $this->log()->notice('Existing installation authorized successfully.');
            } catch (\Throwable $e) {
                $this->cleanupPantheonSite($site_uuid, 'Failed to authorize existing GitHub installation.');
                throw new TerminusException('Error authorizing existing GitHub installation: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        // Final check for site_details and installation_id before proceeding
        if (empty($site_details) || empty($installation_id)) {
             $this->cleanupPantheonSite($site_uuid, 'Missing site details or installation ID after authorization step.');
             throw new TerminusException('Failed to obtain necessary site details or installation ID from VCS service.');
        }
        if (!($site_details['is_active'] ?? false)) {
            $this->cleanupPantheonSite($site_uuid, 'VCS service reports site is not active after authorization.');
            throw new TerminusException('Error authorizing with VCS service: Site is not yet active according to the service.');
        }

        // 6. Create Repository via go-vcs-service (repoCreate)
        $this->log()->notice("Creating GitHub repository '{repo}' in organization '{org}'...", ['repo' => $site_name, 'org' => $vcs_org_name]);
        $repo_create_data = [
            'site_uuid' => $site_uuid,
            'label' => $site_name, // Use site_name for the repo name
            'skip_create' => false,
            'is_private' => strtolower($options['visibility']) === 'private',
            // Explicitly set vendor_id based on backend expectation (GitHub = 1)
            'vendor_id' => ($vcs_provider === 'github') ? 1 : null, // Adjust if other providers are added
            // 'vcs_organization' => $vcs_org_name, // Does repoCreate need the org name? Old code didn't send it. Check API. Assuming not needed for now.
        ];
        // Validate vendor_id before proceeding
        if (is_null($repo_create_data['vendor_id'])) {
             $this->cleanupPantheonSite($site_uuid, "Unsupported VCS provider '{$vcs_provider}' for repo creation.");
             throw new TerminusException("Cannot determine vendor ID for VCS provider: {$vcs_provider}");
        }
        $target_repo_url = null;
        try {
            $repo_create_response = $vcs_client->repoCreate($repo_create_data);
            $this->log()->debug("VCS Repo Create Response: " . print_r($repo_create_response, true));
            $data = (array) ($repo_create_response['data'] ?? []);
            $target_repo_url = $data['repo_url'] ?? null;
            if (!$target_repo_url) {
                throw new TerminusException('VCS service did not return repository URL after creation.');
            }
            $this->log()->notice('GitHub repository created successfully: {url}', ['url' => $target_repo_url]);
        } catch (\Throwable $t) {
            $this->cleanupPantheonSite($site_uuid, 'Failed to create repository via VCS service.');
            throw new TerminusException(
                'Error creating repository via VCS service: {error_message}',
                ['error_message' => $t->getMessage()]
            );
        }

        // 7. Deploy Pantheon Product/Upstream (using ICR upstream)
        $site = $this->getSiteById($site_uuid); // Get the site object
        if (!$site) {
             // Should not happen if we got this far, but check.
             $this->cleanupPantheonSite($site_uuid, 'Error while retrieving new site information after repo creation');
             throw new TerminusException('Failed to retrieve site object (ID: {id}) before deploying product.', ['id' => $site_uuid]);
        }
        $this->log()->notice('Provisioning site resources...');
        try {
            $this->processWorkflow($site->deployProduct($icr_upstream->id));
            $this->log()->notice('Site resources provisioned successfully.');
        } catch (\Throwable $e) {
            // The site exists, the repo exists, just the CMS deploy failed.
            $this->cleanupPantheonSite($site_uuid, 'Error occurred while provisioning site resources: {msg}', ['msg' => $e->getMessage()]);
            throw new TerminusException('Error deploying product: {msg}', ['msg' => $e->getMessage()]);
        }

        // Capture the timestamp before we push code, for use in workflow wait later
        $startTime = time();

        // 8. Push Initial Code to External Repository via go-vcs-service (repoInitialize)
        $this->log()->notice('Pushing initial code from upstream ({up_id}) to {repo_url}...', ['up_id' => $upstream->id, 'repo_url' => $target_repo_url]);
        try {
            [$upstream_repo_url, $upstream_repo_branch] = $this->getUpstreamInformation($upstream->id, $user);

            $repo_initialize_data = [
                'site_id' => $site_uuid,
                'target_repo_url' => $target_repo_url,
                'upstream_id' => $upstream->id, // Original upstream ID
                'upstream_repo_url' => $upstream_repo_url,
                'upstream_repo_branch' => $upstream_repo_branch,
                'installation_id' => (string) $installation_id, // Must be string
                'organization_id' => $pantheon_org->id, // Pantheon Org UUID
                 // Explicitly set vendor_id based on backend expectation (GitHub = 1)
                'vendor_id' => ($vcs_provider === 'github') ? 1 : null, // Adjust if other providers are added
            ];
             // Validate vendor_id before proceeding
            if (is_null($repo_initialize_data['vendor_id'])) {
                 // Don't cleanup site here, just throw as repo init is the last step
                 throw new TerminusException("Cannot determine vendor ID for repo initialization for VCS provider: {$vcs_provider}");
            }

            $vcs_client->repoInitialize($repo_initialize_data);
            $this->log()->notice('Initial code pushed successfully.');

        } catch (\Throwable $t) {
            // If repoInitialize fails, the site and repo exist, but code isn't there.
            // Don't delete the site. Log a warning and the repo URL.
            $this->log()->warning(
                'Error initializing repository with upstream contents: {error_message}',
                ['error_message' => $t->getMessage()]
            );
            $this->log()->warning('The site and repository have been created, but the initial code push failed.');
            $this->log()->warning('You may need to manually push the code from the {up_id} upstream to {repo_url}', [
                'up_id' => $upstream->id,
                'repo_url' => $target_repo_url
            ]);
            // Continue to final steps like wait-for-wake, as the site itself is up.
        }

        // 9. Final Success Message & Wait for Wake
        $this->log()->notice('---');
        $this->log()->notice('Site "{site}" created successfully with GitHub repository!', ['site' => $site->getName()]);
        $this->log()->notice('GitHub Repository: {url}', ['url' => $target_repo_url]);
        $this->log()->notice('Pantheon Dashboard: {url}', ['url' => $site->dashboardUrl()]);
        $this->log()->notice('---');

        if ($preferred_platform === 'cos') {
            $this->waitForWorkflow(
                $startTime,
                $site,
                'dev',
                '', // $expectedWorkflowDescription
                600, // maxWaitInSeconds - 10 minutes
                null // $maxNotFoundAttempts
            );
        }

        $this->log()->notice('Waiting for site dev environment to become available...');
        try {
            $env = $site->getEnvironments()->get('dev');
            if ($env instanceof Environment) {
                $this->waitForWake($env, $this->logger);
                $this->log()->notice('Site dev environment is available.');
            } else {
                $this->log()->warning('Could not retrieve the dev environment object, unable to confirm availability.');
            }
        } catch (TerminusNotFoundException $e) {
             $this->log()->warning('Dev environment not found immediately after site creation. It might still be provisioning.');
        } catch (\Exception $e) {
             $this->log()->error('An error occurred while waiting for the site to wake: {message}', ['message' => $e->getMessage()]);
        }
    }

    // --- Helper methods adapted from RepositorySiteCreateCommand ---

    /**
     * Get site type as expected by ICR site creation API.
     * Uses the original upstream, not the ICR one.
     */
    protected function getSiteType(Upstream $upstream): string
    {
        $framework = $upstream->get('framework');
        switch ($framework) {
            // Assuming drupal10 maps to drupal8 for ICR type? Check API/upstream data.
            case 'drupal10':
            case 'drupal8': // Keep drupal8 for compatibility if needed
                return 'cms-drupal';
            case 'wordpress':
            case 'wordpress_network': // Check if ICR handles multisite differently
                return 'cms-wordpress';
            case 'nodejs':
                return 'nodejs';
            // Add 'empty' or other types if needed
            default:
                // Check if it's a custom upstream (no framework?)
                if (empty($framework)) {
                     // Need a way to determine type for custom upstreams if they are supported for eVCS
                     throw new TerminusException('Cannot determine site type for custom upstream "{id}" without a framework.', ['id' => $upstream->id]);
                }
                throw new TerminusException('Framework {framework} not currently supported for external VCS site creation.', compact('framework'));
        }
    }

    /**
     * Get ICR upstream based on the upstream passed as argument.
     */
    protected function getIcrUpstream(string $original_upstream_id, User $user): Upstream
    {
        $original_upstream = $user->getUpstreams()->get($original_upstream_id); // Already validated upstream exists
        $framework = $original_upstream->get('framework');
        return $this->getIcrUpstreamFromFramework($framework, $user);
    }

    /**
     * Get ICR upstream based on the framework.
     */
    protected function getIcrUpstreamFromFramework(string $framework, User $user): Upstream
    {
        $icr_upstream_map = [
            'drupal10' => 'drupal-icr', // Assuming drupal10 uses drupal-icr
            'drupal8' => 'drupal-icr',
            'wordpress' => 'wordpress-icr',
            'wordpress_network' => 'wordpress-multisite-icr', // Check if this exists
            'nodejs' => 'nodejs', // Nodejs might be its own ICR upstream? Check API.
            // Add 'empty' mapping if needed: 'empty' => 'empty-icr' ?
        ];

        if (!isset($icr_upstream_map[$framework])) {
             // Handle custom upstreams or unsupported frameworks
             if (empty($framework)) {
                 // Maybe default to 'empty-icr' or throw error?
                 throw new TerminusException('Cannot determine ICR upstream for custom upstream without framework.');
             }
             throw new TerminusException('Framework {framework} does not have a corresponding ICR upstream defined.', compact('framework'));
        }

        $icr_upstream_id = $icr_upstream_map[$framework];

        try {
            return $user->getUpstreams()->get($icr_upstream_id);
        } catch (TerminusNotFoundException $e) {
            throw new TerminusException('Required ICR upstream "{id}" not found.', ['id' => $icr_upstream_id]);
        }
    }

    /**
     * Get upstream repository URL and branch.
     */
    protected function getUpstreamInformation(string $upstream_id, User $user): array
    {
        // Use the *original* upstream ID passed by the user
        $upstream = $user->getUpstreams()->get($upstream_id); // Already validated
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
    protected function cleanupPantheonSite(string $site_uuid, string $failure_reason): void
    {
        $this->log()->error('Site creation failed: {reason}', ['reason' => $failure_reason]);
        $this->log()->notice("Attempting to clean up Pantheon site record (ID: {id})...", ['id' => $site_uuid]);
        $exception = null;

        // Note: The old cleanup also called $this->getVcsClient()->cleanupSiteDetails($site_uuid);
        // Decide if this is still needed/desired. If the VCS service workflow failed early,
        // there might not be anything to clean up there. If it failed after repo creation,
        // cleaning up the VCS side might be complex (delete repo?).
        // For now, focus on cleaning up the Pantheon site record.

        try {
            $site = $this->sites()->get($site_uuid);
            if ($site) {
                $workflow = $site->delete();
                // Watch the workflow using the user object since the site object will be gone
                $workflow->setOwnerObject($this->session()->getUser());
                $this->processWorkflow($workflow);
                $message = $workflow->getMessage();
                $this->log()->notice('Pantheon site cleanup successful: {msg}', ['msg' => $message]);
            } else {
                 $this->log()->warning('Could not find site {id} to clean up (already deleted?).', ['id' => $site_uuid]);
            }
        } catch (TerminusNotFoundException $e) {
             $this->log()->warning('Could not find site {id} to clean up (already deleted?).', ['id' => $site_uuid]);
        } catch (\Throwable $t) {
            // Catch potential errors during deletion workflow processing
            $exception = $t;
            $this->log()->error("Error during Pantheon site cleanup: {error_message}", ['error_message' => $t->getMessage()]);
        }

        // Re-throw exception if cleanup itself failed, otherwise the original failure reason is logged above.
        if ($exception) {
            // Wrap it to indicate it happened during cleanup
            throw new TerminusException('An error occurred during site cleanup after a failure: {msg}', ['msg' => $exception->getMessage()], $exception);
        }
    }

    /**
     * Handle new installation based on VCS provider.
     * Currently only supports GitHub.
     */
    protected function handleNewInstallation(string $vcs_provider, string $auth_url, string $site_uuid, array $options): array
    {
        if ($vcs_provider === 'github') {
            return $this->handleGithubNewInstallation($auth_url, $site_uuid);
        } else {
            // Placeholder for other providers like GitLab if added later
            $this->cleanupPantheonSite($site_uuid, "Unsupported VCS provider '{$vcs_provider}' for new installation flow.");
            throw new TerminusException("New installation flow for VCS provider '{vcs}' is not supported.", ['vcs' => $vcs_provider]);
        }
        // The old command had specific GitLab logic using vcs_token, removed for now.
    }

    /**
     * Handle Github new installation browser flow.
     * Adapted from RepositorySiteCreateCommand::handleGithubNewInstallation
     */
    protected function handleGithubNewInstallation(string $auth_url, string $site_uuid): array
    {
        // Ensure auth_url is unquoted for opening
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

        $this->log()->notice("Waiting for authorization to complete in browser (up to 10 minutes)...");
        // processSiteDetails polls the getSiteDetails endpoint until active or timeout
        $site_details_response = $this->getVcsClient()->processSiteDetails($site_uuid, 600); // 600 seconds = 10 minutes

        $site_details = (array) ($site_details_response['data'][0] ?? []);

        if (empty($site_details) || !($site_details['is_active'] ?? false)) {
             // Don't cleanup here, let the caller handle cleanup based on this failure
             throw new TerminusException('GitHub App authorization timed out or failed. Please check the browser window and try again.');
        }

        return $site_details;
    }

} // End of CreateCommand class
