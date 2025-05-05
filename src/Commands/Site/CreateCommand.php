<?php

// Namespace matches the new location within the plugin
namespace Pantheon\TerminusRepository\Commands\Site;

// Core Terminus dependencies
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Helpers\Traits\WaitForWakeTrait;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Organization;
use Pantheon\Terminus\Models\Site;
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
use Pantheon\TerminusRepository\VcsApi\Client;
use Pantheon\TerminusRepository\VcsApi\Installation;
use Pantheon\TerminusRepository\VcsApi\VcsClientAwareTrait;

// Symfony Console components for interaction
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface; // Added for interactive helpers
use Symfony\Component\Console\Output\OutputInterface; // Added for interactive helpers
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

// Base SiteCommand from core (contains getSiteById etc.)
use Pantheon\Terminus\Commands\Site\SiteCommand;


/**
 * Creates a new site, potentially with an external Git repository.
 * This command overrides the core site:create command when the plugin is enabled.
 */
class CreateCommand extends SiteCommand implements RequestAwareInterface, SiteAwareInterface // Extends SiteCommand now
{
    // Traits from core CreateCommand
    use WorkflowProcessingTrait;
    use WaitForWakeTrait;

    // Traits needed for plugin functionality & dependencies
    use VcsClientAwareTrait; // Provides getVcsClient()
    // SiteAwareTrait is inherited from SiteCommand
    // SessionAwareTrait is inherited from SiteCommand
    // ConfigAwareTrait is inherited from SiteCommand
    // LoggerAwareTrait is inherited from SiteCommand
    // ContainerAwareTrait is inherited from SiteCommand
    // RequestAwareTrait is needed for VcsClientAwareTrait

    // Supported VCS types (can be expanded later)
    protected $vcss = ['pantheon', 'github']; // Added 'pantheon'

    /**
     * Creates a new site, optionally using an external Git provider.
     *
     * @authorize
     *
     * @command site:create
     * @aliases site-create
     *
     * @param string $site_name Site name (machine name)
     * @param string $label Site label (human-readable name)
     * @param string $upstream_id Upstream name or UUID (e.g., wordpress, drupal10, empty-upstream)
     * @option org Organization name, label, or ID. Required if --vcs=github is used.
     * @option region Specify the service region where the site should be created. See documentation for valid regions.
     * @option vcs VCS provider for the site repository (e.g., github, pantheon). Defaults to prompting if interactive, otherwise 'pantheon'.
     * @option vcs-org Name of the Github organization containing the repository. Required if --vcs=github is used in non-interactive mode.
     * @option visibility Visibility of the external repository (private or public). Only applies if --vcs=github.
     *
     * @usage <site> <label> <upstream> Creates a new Pantheon-hosted site named <site>, labeled <label>, using code from <upstream>.
     * @usage <site> <label> <upstream> --org=<org> Creates a new Pantheon-hosted site associated with <organization>.
     * @usage <site> <label> <upstream> --org=<org> --vcs=github --vcs-org=<gh-org> Creates a new site associated with Pantheon <organization>, using <upstream> code, with the repository hosted on Github in the <gh-org> organization.
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
            'vcs' => null, // Default to null, prompt or default later
            'vcs-org' => null,
            'visibility' => 'private',
            // Note: no-interaction is a global option, accessed via $this->input
        ]
    ) {
        $input = $this->input(); // Get input object for checking global options
        $output = $this->output(); // Get output object for prompts
        $isInteractive = !$input->getOption('no-interaction');

        // 1. Determine VCS Provider
        $vcsProvider = $options['vcs'];
        if (is_null($vcsProvider) && $isInteractive) {
            $helper = new QuestionHelper();
            $question = new ChoiceQuestion(
                'Select your version control provider:',
                // Make options case-insensitive for display but normalize value
                ['Pantheon', 'Github'],
                'Pantheon' // Default
            );
            $question->setErrorMessage('VCS provider %s is invalid.');
            // Normalize to lowercase
            $vcsProvider = strtolower($helper->ask($input, $output, $question));
            $this->log()->info('Selected VCS Provider: {vcs}', ['vcs' => $vcsProvider]);
        } elseif (is_null($vcsProvider) && !$isInteractive) {
            $vcsProvider = 'pantheon'; // Default in non-interactive mode
            $this->log()->debug('Defaulting to Pantheon VCS provider in non-interactive mode.');
        }

        // Validate VCS provider
        if (!in_array($vcsProvider, $this->vcss)) {
             throw new TerminusException('Invalid VCS provider specified or selected: {vcs}. Allowed values are "pantheon" or "github".', ['vcs' => $vcsProvider]);
        }

        // --- Branch Logic: Pantheon or GitHub ---
        if ($vcsProvider === 'pantheon') {
            $this->createPantheonHostedSite($site_name, $label, $upstream_id, $options);
        } else {
            // Only GitHub is supported for now
            if ($vcsProvider === 'github') {
                $this->createGithubHostedSite($site_name, $label, $upstream_id, $options, $isInteractive, $input, $output);
            } else {
                 // Should be caught by validation above, but as a safeguard:
                 throw new TerminusException('Unsupported VCS provider: {vcs}', ['vcs' => $vcsProvider]);
            }
        }
        // Final success messages are handled within the specific creation methods.
    }

    /**
     * Handles the creation of a standard Pantheon-hosted site.
     * (Logic copied from core Terminus site:create)
     */
    protected function createPantheonHostedSite($site_name, $label, $upstream_id, $options)
    {
        $this->log()->notice('Creating a new Pantheon-hosted site...');

        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken.', compact('site_name'));
        }

        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name,
            // Ensure has_external_vcs is false or absent for standard sites
            'has_external_vcs' => false,
        ];
        // If the user specified a region, then include it in the workflow options.
        $region = $options['region'] ?? $this->getConfig()->get('command_site_options_region');
        if ($region) {
            $workflow_options['preferred_zone'] = $region;
        }

        $user = $this->session()->getUser();

        // Locate upstream.
        $upstream = $this->getUpstream($upstream_id); // Use helper method

        // Locate organization (optional for Pantheon hosted).
        $org = null;
        if (!is_null($org_id = $options['org'])) {
            try {
                // Use helper to get validated Org object
                $org = $this->getPantheonOrg($org_id, false, $this->input(), $this->output(), false); // Non-interactive check
                $workflow_options['organization_id'] = $org->id;
            } catch (TerminusException $e) {
                 // Re-throw if org is specified but not found
                 throw $e;
            }
        }

        // Create the site.
        $this->log()->notice('Running workflow to create Pantheon site record...');
        $workflow = $this->sites()->create($workflow_options);
        $this->processWorkflow($workflow);
        $site_uuid = $workflow->get('waiting_for_task')->site_id;

        // Deploy the upstream.
        $site = $this->getSiteById($site_uuid); // Use SiteCommand's helper
        if ($site) {
            $this->log()->notice('Deploying CMS...');
            $this->processWorkflow($site->deployProduct($upstream->id));
            $this->log()->notice('Waiting for site availability...');
            $env = $site->getEnvironments()->get('dev');
            if ($env instanceof Environment) {
                // Use WaitForWakeTrait method, logger is available via LoggerAwareTrait
                $this->waitForWake($env, $this->logger);
            }
            $this->log()->notice('Site created successfully!');
            $this->log()->notice(sprintf("Dashboard: %s", $site->dashboardUrl()));
        } else {
            // Should not happen if workflow succeeded, but handle defensively.
            throw new TerminusException('Site creation workflow succeeded, but could not retrieve site object for deployment.');
        }
    }

    /**
     * Handles the creation of a site with a GitHub-hosted repository.
     * (Logic adapted from RepositorySiteCreateCommand and VcsIntegrationService plan)
     */
    protected function createGithubHostedSite($site_name, $label, $upstream_id, $options, bool $isInteractive, InputInterface $input, OutputInterface $output)
    {
        $this->log()->notice('Creating a new site with a GitHub-hosted repository...');

        // --- Configuration & Validation ---
        $pantheonOrgOpt = $options['org'];
        $vcsOrgOpt = $options['vcs-org']; // Renamed from installation_id
        $visibility = $options['visibility'];
        $region = $options['region'];
        $vcsProvider = 'github'; // We know this already
        $vcs_id = 2; // 1=pantheon, 2=github (adjust if needed based on API)

        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken.', compact('site_name'));
        }

        $user = $this->session()->getUser();

        // --- Determine Pantheon Organization (Required for GitHub flow) ---
        $pantheonOrg = $this->getPantheonOrg($pantheonOrgOpt, $isInteractive, $input, $output, true); // 'true' means required
        $pantheonOrgId = $pantheonOrg->id;

        // --- Create Pantheon Site Record ---
        // We need to do this *before* determining the installation,
        // as createInitialWorkflow needs the site_uuid.
        $this->log()->notice('Running workflow to create Pantheon site record...');
        // Map original upstream to site type and platform
        $originalUpstream = $this->getUpstream($upstream_id);
        $site_type = $this->getSiteTypeForFramework($originalUpstream->get('framework'));
        $preferred_platform = $this->getPreferredPlatformForFramework($site_type);

        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name,
            'organization_id' => $pantheonOrgId,
            'has_external_vcs' => true, // Critical flag
            'preferred_platform' => $preferred_platform,
        ];
        if ($region) {
            $workflow_options['preferred_zone'] = $region;
        }

        $site_create_workflow = $this->sites()->create($workflow_options);
        $this->processWorkflow($site_create_workflow);
        $site_uuid = $site_create_workflow->get('waiting_for_task')->site_id;
        $site = $this->getSiteById($site_uuid); // Use SiteCommand's helper
        if (!$site) {
            // Don't cleanup VCS details yet, just fail site creation.
            throw new TerminusException('Site creation workflow succeeded, but could not retrieve site object.');
        }
        $this->log()->info('Pantheon site record created: {id}', ['id' => $site->id]);

        // --- Determine GitHub Organization / Installation (Now that we have site_uuid) ---
        $this->log()->debug('Determining VCS installation for Pantheon Org: {org}', ['org' => $pantheonOrgId]);
        // Fetch existing installations and auth URL using the actual site context
        $workflowData = $this->createInitialWorkflow($user->id, $pantheonOrgId, $site_uuid, $site_name, $site_type);
        $auth_url = $workflowData['auth_url'] ?? null; // Needed for new installs
        $workflow_uuid = $workflowData['workflow_uuid'] ?? null; // Store for potential webhook use
        $existing_installations_data = $workflowData['existing_installations'] ?? [];

        $installations = []; // Map ID -> Installation Object
        $installationMap = []; // Map GH Org Name -> Installation ID
        if (!empty($existing_installations_data)) {
            foreach ($existing_installations_data as $installation) {
                // Filter for GitHub and backend installations
                if (strtolower($installation->vendor) !== 'github' || $installation->installation_type == 'front-end') {
                    continue;
                }
                $installations[$installation->installation_id] = new Installation(
                    $installation->installation_id,
                    $installation->vendor,
                    $installation->login_name // GitHub Org Name
                );
                $installationMap[strtolower($installation->login_name)] = $installation->installation_id;
            }
        }
         $this->log()->debug('Found {count} existing GitHub installations for Org {org}: {names}', [
            'count' => count($installations),
            'org' => $pantheonOrgId,
            'names' => implode(', ', array_keys($installationMap))
        ]);

        $installation_id_or_new = null;
        $vcsOrgName = null;

        // Check --vcs-org option first
        if (!is_null($vcsOrgOpt)) {
            $vcsOrgLower = strtolower($vcsOrgOpt);
            if (isset($installationMap[$vcsOrgLower])) {
                $installation_id_or_new = $installationMap[$vcsOrgLower];
                $vcsOrgName = $vcsOrgOpt; // Use the name provided by the user
                $this->log()->debug('Using provided --vcs-org "{vcs_org}" matching existing installation ID {id}', [
                    'vcs_org' => $vcsOrgName,
                    'id' => $installation_id_or_new
                ]);
            } else {
                // Provided org doesn't match existing. Treat as new unless non-interactive.
                if (!$isInteractive) {
                    throw new TerminusException('--vcs-org "{vcs_org}" does not match an existing installation for Pantheon organization ID {org}. In non-interactive mode, you must provide a valid existing GitHub organization name.', ['vcs_org' => $vcsOrgOpt, 'org' => $pantheonOrgId]);
                }
                $this->log()->debug('Provided --vcs-org "{vcs_org}" does not match existing installations. Assuming new installation.', ['vcs_org' => $vcsOrgOpt]);
                $installation_id_or_new = 'new';
                $vcsOrgName = $vcsOrgOpt; // Use the name provided by the user
            }
        } else {
            // --vcs-org option was not provided
            if (!$isInteractive) {
                 throw new TerminusException('--vcs-org is required when using --vcs=github in non-interactive mode.');
            }

            // Interactive mode, no --vcs-org provided
            if (empty($installations)) {
                $this->log()->notice('No existing GitHub installations found for this Pantheon organization. Proceeding to add a new one.');
                $helper = new QuestionHelper();
                $question = new Question('Enter the name of the GitHub organization to add: ');
                $question->setValidator(function ($answer) {
                    if (empty(trim($answer ?? ''))) {
                        throw new \RuntimeException('GitHub organization name cannot be empty.');
                    }
                    return trim($answer);
                });
                $vcsOrgName = $helper->ask($input, $output, $question);
                $installation_id_or_new = 'new';
            } else {
                // Prompt user to choose from existing or add new
                $choices = [];
                foreach ($installations as $id => $inst) {
                    $choices[$inst->getLoginName()] = $id; // Display GH Org Name, map to ID
                }
                $addNewOption = 'Add to a different Github org';
                $choices[$addNewOption] = 'new';

                $helper = new QuestionHelper();
                $question = new ChoiceQuestion(
                    'Which Github organization should be used?',
                    array_keys($choices)
                );
                $question->setErrorMessage('Invalid selection %s.');
                $chosenName = $helper->ask($input, $output, $question);

                $chosenId = $choices[$chosenName];
                 $this->log()->info('Selected GitHub organization option: {org}', ['org' => $chosenName]);

                if ($chosenId === 'new') {
                    // Ask for the name of the new org
                    $question = new Question('Enter the name of the GitHub organization to add: ');
                    $question->setValidator(function ($answer) {
                        if (empty(trim($answer ?? ''))) {
                            throw new \RuntimeException('GitHub organization name cannot be empty.');
                        }
                        return trim($answer);
                    });
                    $vcsOrgName = $helper->ask($input, $output, $question);
                    $installation_id_or_new = 'new';
                } else {
                    // User chose an existing installation
                    $installation_id_or_new = $chosenId;
                    $vcsOrgName = $chosenName;
                }
            }
        }
        // Ensure we have determined the installation ID and target org name
        if (is_null($installation_id_or_new) || is_null($vcsOrgName)) {
             throw new TerminusException('Could not determine GitHub installation or organization name.');
        }
         $this->log()->debug('Determined Installation ID: {id}, Target GitHub Org: {name}', ['id' => $installation_id_or_new, 'name' => $vcsOrgName]);


        // --- Setup External Repository ---
        $this->log()->notice('Proceeding with GitHub repository setup...');
        $site_details = null;
        // workflow_uuid was potentially set when fetching installations

        try {
            // If using an existing installation:
            if ($installation_id_or_new !== 'new') {
                $this->log()->info('Authorizing with existing GitHub installation ID: {id}', ['id' => $installation_id_or_new]);
                $authorize_data = [
                    'site_uuid' => $site_uuid,
                    'user_uuid' => $user->id,
                    'installation_id' => (int) $installation_id_or_new,
                    'org_uuid' => $pantheonOrgId,
                ];
                $authResult = $this->getVcsClient()->authorize($authorize_data);
                if (!$authResult['success']) {
                    throw new TerminusException("Error authorizing existing installation: {error}", ['error' => $authResult['data'] ?? 'Unknown error']);
                }
                // Fetch site details after authorization
                $siteDetailsResult = $this->getVcsClient()->getSiteDetails($site_uuid);
                $site_details = (array) ($siteDetailsResult['data'][0] ?? null);
                if (!$site_details) {
                     throw new TerminusException('Failed to fetch site details after authorizing existing installation.');
                }
                 $this->log()->debug('Authorization successful for existing installation.');
            } else {
                // Handle new installation (GitHub only for now)
                $this->log()->info('Initiating new GitHub installation flow for organization: {org}', ['org' => $vcsOrgName]);

                // We already called createInitialWorkflow to get installations and auth_url
                if (empty($auth_url)) {
                     throw new TerminusException('Could not retrieve authorization URL for new GitHub installation (Auth URL was empty after initial workflow).');
                }

                $site_details = $this->handleGithubNewInstallation($auth_url, $site_uuid);
            }

            // Validate site details
            if (empty($site_details) || !$site_details['is_active']) {
                 throw new TerminusException('GitHub authorization failed or site is not active.');
            }
            // Update installation_id if it was 'new'
            $final_installation_id = $site_details['installation_id'];
            if (empty($final_installation_id)) {
                 throw new TerminusException('Could not determine final installation ID after authorization.');
            }
             $this->log()->debug('Final Installation ID: {id}', ['id' => $final_installation_id]);


            // Create the repository
            $this->log()->notice("Creating GitHub repository {org}/{repo}...", ['org' => $vcsOrgName, 'repo' => $site_name]);
            $repo_create_data = [
                'site_uuid' => $site_uuid,
                'label' => $site_name, // Use site name for repo name
                'skip_create' => false,
                'is_private' => $visibility === 'private',
                'vendor_id' => $vcs_id, // GitHub
                // Removed 'vcs_organization' and 'installation_id' as they are likely implicit
            ];
            $repoCreateResult = $this->getVcsClient()->repoCreate($repo_create_data);
            $repoCreateData = (array) ($repoCreateResult['data'] ?? null);
            if (!isset($repoCreateData['repo_url'])) {
                throw new TerminusException('Failed to create GitHub repository: {error}', ['error' => $repoCreateResult['data'] ?? 'No repo_url returned']);
            }
            $target_repo_url = $repoCreateData['repo_url'];
            $this->log()->info('GitHub repository created: {url}', ['url' => $target_repo_url]);

            // Install webhook (skipped for GitHub in old command)
            $this->log()->debug('Skipping webhook installation for GitHub.');

            // Initialize the repository
            $this->log()->notice('Pushing initial code to GitHub repository...');
            // Map original upstream to ICR upstream and get details
            $icrUpstream = $this->getIcrUpstreamFromFramework($originalUpstream->get('framework'), $user);
            [$upstream_repo_url, $upstream_repo_branch] = $this->getUpstreamInformation($icrUpstream->id);

            $repo_initialize_data = [
                'site_id' => $site_uuid,
                'target_repo_url' => $target_repo_url,
                'upstream_id' => $icrUpstream->id, // Use ICR upstream ID
                'upstream_repo_url' => $upstream_repo_url,
                'upstream_repo_branch' => $upstream_repo_branch,
                'installation_id' => (string) $final_installation_id,
                'organization_id' => $pantheonOrgId,
                'vendor_id' => $vcs_id, // GitHub
            ];
            $this->getVcsClient()->repoInitialize($repo_initialize_data);
            $this->log()->info('Initial code pushed successfully.');

            // --- Deploy Product to Pantheon Site ---
            $this->log()->notice('Deploying CMS/product to Pantheon site...');
            $this->processWorkflow($site->deployProduct($icrUpstream->id));
            $this->log()->info('CMS/product deployed successfully.');

            // --- Final Success Message ---
            $this->log()->notice(sprintf("Site created successfully with GitHub repository: %s", $target_repo_url));
            $this->log()->notice(sprintf("Dashboard: %s", $site->dashboardUrl()));


        } catch (\Throwable $t) {
            $this->log()->error('Error during GitHub repository setup: {msg}', ['msg' => $t->getMessage()]);
            // Cleanup the Pantheon site if VCS setup fails at any point after site record creation
            $this->cleanupPantheonSite($site_uuid, false); // Don't cleanup VCS details as they might not exist or be in a weird state
            // Re-throw the exception
            throw new TerminusException('Error setting up GitHub repository: {msg}', ['msg' => $t->getMessage()]);
        }
    }


    // --- Helper Methods (ported/adapted from RepositorySiteCreateCommand) ---

    /**
     * Determines the Pantheon Organization object to use.
     * Handles interactive prompting and validation.
     *
     * @param string|null $orgOptionValue Value from --org option.
     * @param bool $isInteractive Whether interaction is allowed.
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param bool $isRequired Whether the org is strictly required for the current flow.
     * @return Organization The selected/validated Pantheon Organization object.
     * @throws TerminusException If required org is missing or invalid.
     */
    protected function getPantheonOrg(?string $orgOptionValue, bool $isInteractive, InputInterface $input, OutputInterface $output, bool $isRequired): Organization
    {
        $user = $this->session()->getUser();
        $orgMemberships = $user->getOrganizationMemberships()->all(); // Get all memberships

        if (!is_null($orgOptionValue)) {
            try {
                // Use the get method which handles name/label/UUID lookup
                $org = $user->getOrganizationMemberships()->get($orgOptionValue)->getOrganization();
                $this->log()->debug('Using provided Pantheon organization: {org}', ['org' => $org->getName()]);
                return $org;
            } catch (TerminusException $e) {
                // Re-throw if the provided org is simply not found
                throw new TerminusException('Pantheon organization "{org}" not found or you are not a member.', ['org' => $orgOptionValue]);
            }
        }

        // Org option was not provided
        if ($isRequired && !$isInteractive) {
            throw new TerminusException('--org is required when using --vcs=github in non-interactive mode.');
        }
        if (!$isRequired) {
             // Should not happen if called correctly, but return null if not required and not provided.
             // This case is handled in the standard Pantheon flow already.
             // This helper is primarily for the GitHub flow where it *is* required.
             throw new \LogicException('getPantheonOrg called with isRequired=false but no org provided.');
        }


        // Interactive mode, no --org provided, and it's required
        $orgCount = count($orgMemberships);

        if ($orgCount === 0) {
            throw new TerminusException('External repositories (GitHub) require the site to be associated with a Pantheon Organization, but you are not a member of any.');
        }

        if ($orgCount === 1) {
            $membership = reset($orgMemberships);
            $org = $membership->getOrganization();
            $this->log()->notice('Associating site with your only Pantheon organization: {org}', ['org' => $org->getName()]);
            return $org;
        }

        // Multiple orgs, prompt user
        $orgChoices = [];
        $orgMap = []; // Map display name back to Org object
        foreach ($orgMemberships as $membership) {
            $org = $membership->getOrganization();
            $displayName = $org->getName(); // Use Profile->name which is the label
            $orgChoices[] = $displayName;
            $orgMap[$displayName] = $org;
        }
        sort($orgChoices); // Sort alphabetically for better UX

        $helper = new QuestionHelper();
        $question = new ChoiceQuestion(
            'Choose the Pantheon organization for this site:',
            $orgChoices
        );
        $question->setErrorMessage('Organization %s is invalid.');
        $chosenOrgName = $helper->ask($input, $output, $question);

        $chosenOrg = $orgMap[$chosenOrgName];
        $this->log()->info('Selected Pantheon organization: {org}', ['org' => $chosenOrgName]);
        return $chosenOrg;
    }


    /**
     * Fetches existing installation data by creating the initial workflow.
     * This mimics the first part of the old RepositorySiteCreateCommand.
     * Requires site details.
     *
     * @param string $userUuid
     * @param string $pantheonOrgId
     * @param string $siteUuid
     * @param string $siteName
     * @param string $siteType
     * @return array ['auth_url' => ?string, 'workflow_uuid' => ?string, 'existing_installations' => array, 'raw_data' => array]
     * @throws TerminusException
     */
    protected function createInitialWorkflow(string $userUuid, string $pantheonOrgId, string $siteUuid, string $siteName, string $siteType): array
    {
        $this->log()->debug('Creating initial workflow to fetch VCS details for site {site_uuid}...', ['site_uuid' => $siteUuid]);
        $workflow_data = [
            'user_uuid' => $userUuid,
            'org_uuid' => $pantheonOrgId,
            'site_uuid' => $siteUuid,
            'site_name' => $siteName,
            'site_type' => $siteType,
        ];

        try {
            // Use the VcsClientAwareTrait's getter
            $result = $this->getVcsClient()->createWorkflow($workflow_data);
            $data = (array) ($result['data'][0] ?? null);

            if (empty($data)) {
                 throw new TerminusException('Failed to create initial VCS workflow: Empty response data.');
            }

            // Look for github_app first, then github_oauth
            $auth_url = $data['vcs_auth_links']->github_app ?? $data['vcs_auth_links']->github_oauth ?? null;
            // Remove surrounding quotes if present (API might return them)
            if ($auth_url) {
                 $auth_url = trim($auth_url, '"');
            }

            return [
                'auth_url' => $auth_url,
                'workflow_uuid' => $data['workflow_uuid'] ?? null,
                'existing_installations' => $data['existing_installations'] ?? [],
                // Pass back the raw data too, might be useful
                'raw_data' => $data,
            ];
        } catch (\Throwable $t) {
            // Log the error details if possible
            $this->log()->error('API Error creating initial VCS workflow: {message}', ['message' => $t->getMessage()]);
            throw new TerminusException('Error creating initial VCS workflow: {msg}', ['msg' => $t->getMessage()]);
        }
    }


    /**
     * Handles the browser-based authorization flow for a new GitHub installation.
     * (Copied and adapted from RepositorySiteCreateCommand)
     *
     * @param string $auth_url The URL to open.
     * @param string $site_uuid The site UUID to poll for details.
     * @return array Site details array after successful authorization.
     * @throws TerminusException
     */
    protected function handleGithubNewInstallation(string $auth_url, string $site_uuid): array
    {
        $this->log()->notice("Opening GitHub authorization link in browser...");
        $this->log()->notice("If your browser does not open, please go to the following URL:");
        $this->log()->notice($auth_url);

        // Use the LocalMachineHelper from the container
        $localMachineHelper = $this->getContainer()->get(LocalMachineHelper::class);
        $localMachineHelper->openUrl($auth_url);

        $this->log()->notice("Waiting for authorization to complete in browser (this may take a few minutes)...");
        // processSiteDetails polls the API until the site details are ready
        $site_details = $this->getVcsClient()->processSiteDetails($site_uuid, 600); // 10 min timeout
        $this->log()->info("GitHub authorization successful.");
        // Ensure site_details is an array
        return (array) $site_details;
    }


    /**
     * Cleans up (deletes) the Pantheon site record.
     * Adapted from RepositorySiteCreateCommand::cleanup()
     *
     * @param string $site_uuid
     * @param bool $cleanup_vcs If true, attempts to cleanup VCS details via API (use false if VCS setup failed early).
     */
    protected function cleanupPantheonSite(string $site_uuid, bool $cleanup_vcs = true): void
    {
        $this->log()->warning("Cleaning up Pantheon site {id} due to a failure...", ['id' => $site_uuid]);
        $vcs_exception = null;
        $site_delete_exception = null;

        if ($cleanup_vcs) {
            try {
                $this->log()->debug("Attempting to clean up VCS details for site {id}...", ['id' => $site_uuid]);
                $this->getVcsClient()->cleanupSiteDetails($site_uuid);
                 $this->log()->debug("VCS details cleanup successful for site {id}.", ['id' => $site_uuid]);
            } catch (\Throwable $e) {
                // Log VCS cleanup errors but don't stop site deletion
                $vcs_exception = $e;
                $this->log()->error("Error cleaning up VCS service details for site {id}: {error_message}", ['id' => $site_uuid, 'error_message' => $e->getMessage()]);
            }
        }

        try {
            // Use SiteAwareTrait's sites collection
            $site = $this->sites()->get($site_uuid);
            if ($site) {
                $this->log()->notice("Deleting Pantheon site {id}...", ['id' => $site_uuid]);
                $workflow = $site->delete();
                // We need to query the user workflows API to watch the delete_site workflow
                $workflow->setOwnerObject($this->session()->getUser());
                $this->processWorkflow($workflow);
                $message = $workflow->getMessage();
                $this->log()->notice("Pantheon site deleted: {msg}", ['msg' => $message]);
            } else {
                 $this->log()->debug("Site {id} not found during cleanup, assuming already deleted or never fully created.", ['id' => $site_uuid]);
            }
        } catch (\Throwable $e) {
             $site_delete_exception = $e;
             $this->log()->error("Error during Pantheon site deletion for {id}: {error_message}", ['id' => $site_uuid, 'error_message' => $e->getMessage()]);
        }

        // Prioritize throwing the VCS exception if it happened, otherwise throw site delete exception
        if ($vcs_exception) {
            throw $vcs_exception;
        }
        if ($site_delete_exception) {
             throw $site_delete_exception;
        }
    }


    // --- Upstream/Framework Helper Methods (copied from RepositorySiteCreateCommand) ---

    /**
     * Get Upstream object from id/name/label.
     */
    protected function getUpstream(string $upstream_id): Upstream
    {
        $user = $this->session()->getUser();
        try {
             return $user->getUpstreams()->get($upstream_id);
        } catch (TerminusException $e) {
             throw new TerminusException('Upstream {id} not found.', ['id' => $upstream_id], $e);
        }
    }

    /**
     * Get ICR upstream based on the framework.
     */
    protected function getIcrUpstreamFromFramework(string $framework, User $user): Upstream
    {
        $icrMap = [
            'drupal8' => 'drupal-icr', // Assuming Drupal 9/10 use this too
            'drupal9' => 'drupal-icr',
            'drupal10' => 'drupal-icr',
            'wordpress' => 'wordpress-icr',
            'wordpress_network' => 'wordpress-multisite-icr',
            'nodejs' => 'nodejs', // Nodejs might be different
            // Add other frameworks as needed
        ];
        // Handle potential variations like 'drupal'
        if (str_starts_with($framework, 'drupal')) {
            $framework = 'drupal10'; // Default to latest Drupal ICR
        }

        if (!isset($icrMap[$framework])) {
            throw new TerminusException('Framework "{framework}" is not supported for external repositories.', compact('framework'));
        }
        $icrUpstreamId = $icrMap[$framework];
        try {
            return $user->getUpstreams()->get($icrUpstreamId);
        } catch (TerminusException $e) {
             throw new TerminusException('Required ICR upstream "{id}" not found for framework "{framework}".', ['id' => $icrUpstreamId, 'framework' => $framework], $e);
        }
    }

    /**
     * Get site type as expected by createInitialWorkflow API call.
     */
    protected function getSiteTypeForFramework(string $framework): string
    {
        $typeMap = [
             'drupal8' => 'cms-drupal',
             'drupal9' => 'cms-drupal',
             'drupal10' => 'cms-drupal',
             'wordpress' => 'cms-wordpress',
             'wordpress_network' => 'cms-wordpress', // Check if this is correct
             'nodejs' => 'nodejs',
        ];
        // Handle potential variations like 'drupal'
         if (str_starts_with($framework, 'drupal')) {
            $framework = 'drupal10'; // Default to latest Drupal
        }

         if (!isset($typeMap[$framework])) {
            throw new TerminusException('Framework "{framework}" cannot be mapped to a supported site type for external repositories.', compact('framework'));
        }
        return $typeMap[$framework];
    }

    /**
     * Get preferred platform based on site type.
     */
     protected function getPreferredPlatformForFramework(string $site_type): string
    {
        // Copied from RepositorySiteCreateCommand
        if ($site_type == 'nodejs') {
            return 'sta';
        }
        return 'cos';
    }

    /**
     * Get upstream repo URL and branch.
     */
    protected function getUpstreamInformation(string $upstream_id): array
    {
        // Copied from RepositorySiteCreateCommand
        $upstream = $this->getUpstream($upstream_id);
        return [$upstream->get('repository_url'), $upstream->get('repository_branch')];
    }

}
