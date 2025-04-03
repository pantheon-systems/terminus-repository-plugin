<?php

namespace Pantheon\TerminusRepository\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusRepository\VcsApi\VcsClientAwareTrait;
use Pantheon\Terminus\Request\RequestAwareInterface;

/**
 * Class GithubVcsCommand.
 *
 * @package Pantheon\TerminusRepository\Commands
 */
class GithubVcsCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use VcsClientAwareTrait;
    use SiteAwareTrait;

    /**
     * Pushes GitHub VCS event to the VCS API.
     *
     * @authorize
     *
     * @command github:vcs
     * @aliases github-vcs
     *
     * @param string $site Site name or ID
     * @param string $event_name GitHub event name
     * @param string $data JSON encoded data to send to the VCS API
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     *
     * @usage <data> Pushes GitHub VCS event to the VCS API.
     */
    public function githubVcs(string $site, string $event_name, string $data)
    {

        $site_env = "{$site}.dev";
        $this->requireSiteIsNotFrozen($site_env);
        $site = $this->getSiteById($site_env);
        $env = $this->getEnv($site_env);
        if (!$env->isEvcsSite()) {
            throw new TerminusException('This command only works for eVCS sites.');
        }
        if (empty($data)) {
            throw new TerminusException('No data provided.');
        }
        if (!is_string($data)) {
            throw new TerminusException('Data must be a JSON encoded string.');
        }
        // Is it valid json?
        $data = json_decode($data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new TerminusException('Invalid JSON data provided: {error}', ['error' => json_last_error_msg()]);
        }

        $data = $this->getVcsClient()->githubVcs($data, $site->id, $event_name);
        if (isset($data['error'])) {
            throw new TerminusException('Error pausing build: {error}', ['error' => $data['error']]);
        }
        if ($data['success'] !== true) {
            throw new TerminusException('Error pausing build: {error}', ['error' => $data['message']]);
        }
        $this->log()->notice('Event sent.');
    }
}
