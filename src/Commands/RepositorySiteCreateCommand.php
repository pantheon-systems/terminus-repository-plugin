<?php

namespace Pantheon\TerminusRepository\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\TerminusRepository\VcsAuthApi\Client;
use Pantheon\TerminusRepository\VcsAuthApi\VcsAuthClientAwareTrait;
use Pantheon\Terminus\Request\RequestAwareInterface;

/**
 * Create a new pantheon site using ICR
 */
class RepositorySiteCreateCommand extends TerminusCommand implements RequestAwareInterface
{
    use VcsAuthClientAwareTrait;

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
        $this->log()->notice("Options: " . print_r($options, true));

        try {
            $data = $this->getClient()->authorize($vcs_organization);
        } catch (\Throwable $t) {
            throw new TerminusException(
                'Error authorizing with vcs_auth service: {error_message}',
                ['error_message' => $t->getMessage()]
            );
        }

        $this->log()->notice("Data: " . print_r($data, true));
        $this->log()->notice("Click the link, user...");

        // Poll vcs_auth for success indicator
    }
}
