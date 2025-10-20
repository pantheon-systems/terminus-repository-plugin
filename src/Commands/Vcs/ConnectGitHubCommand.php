<?php

namespace Pantheon\TerminusRepository\Commands\Vcs;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusRepository\VcsApi\VcsClientAwareTrait;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Symfony\Component\Process\Process;
use Pantheon\TerminusRepository\Traits\GithubInstallTrait;

/**
 * Class GithubInstallCommand.
 *
 * @package Pantheon\TerminusRepository\Commands
 */
class ConnectGitHubCommand extends TerminusCommand implements RequestAwareInterface
{
    use VcsClientAwareTrait;
    use GithubInstallTrait;

    protected Process $serverProcess;

    protected const AUTH_LINK_TIMEOUT = 600;
    protected const REDIRECT_URL = 'https://docs.pantheon.io/github-application';

    public function __destruct()
    {
        if (isset($this->serverProcess) && $this->serverProcess->isRunning()) {
            $this->serverProcess->stop(0);
        }
    }

    /**
     * Registers a GitHub App installation with the VCS API.
     *
     * @authorize
     *
     * @command vcs:connect:github
     * @aliases vcs-connect-github
     *
     * @param string $organization Organization name, label, or ID.

     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     *
     * @usage <organization> Registers a GitHub App installation with the VCS API.
     */
    public function connectGithub(string $organization)
    {
        $organization = $this->session()->getUser()->getOrganizationMemberships()->get(
            $organization
        )->getOrganization();

        list($url, $flag_file, $process) = $this->startTemporaryServer();
        // Store the process so we can stop it later.
        $this->serverProcess = $process;

        $auth_links_resp = $this->getVcsClient()->getAuthLinks($organization->id, $this->session()->getUser()->id, "cms-drupal", $url);
        $auth_links = $auth_links_resp['data'] ?? null;
        $this->log()->debug('VCS Auth Links: {auth_links}', ['auth_links' => print_r($auth_links, true)]);
        $auth_url = null;
        // Iterate over the two possible auth options for the given VCS.
        foreach (['app', 'oauth'] as $auth_option) {
            if (isset($auth_links->{sprintf("github_%s", $auth_option)})) {
                $auth_url = sprintf('"%s"', $auth_links->{sprintf("github_%s", $auth_option)});
                break;
            }
        }
        if (is_null($auth_url)) {
            throw new TerminusException('No authentication URL found for the GitHub VCS provider.');
        }

        $success = $this->handleGithubNewInstallation($auth_url, $flag_file, self::AUTH_LINK_TIMEOUT);
        if (!$success) {
            throw new TerminusException('GitHub App installation was not completed within the timeout period.');
        }
        $this->log()->notice('GitHub App installation completed successfully.');
    }
}
