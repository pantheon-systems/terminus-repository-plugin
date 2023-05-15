<?php

namespace Pantheon\TerminusRepository\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\TerminusHello\Model\Greeter;

/**
 * Create a new pantheon site using ICR
 */
class RepositorySiteCreateCommand extends TerminusCommand
{
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
     * @option region Specify the service region where the site should be
     *   created. See documentation for valid regions.
     *
     * @usage <site> <label> <upstream> Creates a new site named <site>, human-readably labeled <label>, using code from <upstream>.
     * @usage <site> <label> <upstream> --org=<org> Creates a new site named <site>, human-readably labeled <label>, using code from <upstream>, associated with <organization>.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */

    public function create($site_name, $label, $upstream_id, $options = ['org' => null, 'region' => null,])
    {
        $this->log()->notice('Creating a new site...');
        $this->log()->notice("Site name: $site_name");
    }
}
