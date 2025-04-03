<?php

namespace Pantheon\TerminusRepository\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusRepository\VcsApi\VcsClientAwareTrait;
use Pantheon\Terminus\Request\RequestAwareInterface;

/**
 * Class ResumeBuildCommand.
 *
 * @package Pantheon\TerminusRepository\Commands
 */
class ResumeBuildCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use VcsClientAwareTrait;

    /**
     * Resumes build for a given site
     *
     * @authorize
     *
     * @command site:resume-builds
     * @aliases resume-builds
     *
     * @param string $site Site name or ID
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     *
     * @usage <site> Resumes build for <site>.
     */
    public function resumeBuilds($site)
    {
        $site_env = "{$site}.dev";
        $this->requireSiteIsNotFrozen($site_env);
        $site = $this->getSiteById($site_env);
        $env = $this->getEnv($site_env);

        if (!$env->isEvcsSite()) {
            throw new TerminusException('This command only works for eVCS sites.');
        }

        $data = $this->getVcsClient()->resumeBuild($site->id);
        if (isset($data['error'])) {
            throw new TerminusException('Error resuming build: {error}', ['error' => $data['error']]);
        }
        if ($data['success'] !== true) {
            throw new TerminusException('Error resuming build: {error}', ['error' => $data['message']]);
        }
        $this->log()->notice('Build for {site} has been resumed.', ['site' => $site->getName()]);
    }

}