<?php

namespace Pantheon\TerminusRepository\Commands\Vcs;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusRepository\VcsApi\VcsClientAwareTrait;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Symfony\Component\Process\Process;
use Pantheon\Terminus\Commands\StructuredListTrait;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;

/**
 * Class GithubInstallCommand.
 *
 * @package Pantheon\TerminusRepository\Commands
 */
class ConnectListCommand extends TerminusCommand implements RequestAwareInterface
{
    use VcsClientAwareTrait;
    use StructuredListTrait;

    /**
     * Lists connected VCS installations from the VCS API.
     *
     * @authorize
     *
     * @command vcs:connect:list
     * @aliases vcs-connect-list
     *
     * @field-labels
     *   id: Installation ID
     *   vcs_provider: VCS Provider
     *   type: Type
     *   login_name: Login name
     * @default-table-fields id,vcs_provider,type,login_name
     *
     * @param string $organization Organization name, label, or ID.
     *
     *
     * @usage <organization> Lists connected VCS installations from the VCS API.
     *
     * @usage Lists connected VCS installations from the VCS API.
     *
     * @return RowsOfFields
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function connectList(string $organization)
    {
        $organization = $this->session()->getUser()->getOrganizationMemberships()->get(
            $organization
        )->getOrganization();

        $installations_resp = $this->getVcsClient()->getInstallations($organization->id, $this->session()->getUser()->id);
        $existing_installations_data = $installations_resp['data'] ?? [];
        $this->log()->debug('Existing installations: {installations}', ['installations' => print_r($existing_installations_data, true)]);

        if (count($existing_installations_data) === 0) {
            $this->log()->info('No connected VCS installations found for organization {org}.', ['org' => $organization->name]);
            return new RowsOfFields([]);
        }

        $table_data = [];
        foreach ($existing_installations_data as $installation) {
            $table_data[$installation->installation_id] = [
                'id' => $installation->installation_id,
                'vcs_provider' => $installation->alias,
                'type' => $installation->type,
                'login_name' => $installation->login_name,
            ];
        }

        $table = new RowsOfFields($table_data);
        return $table;
    }
}
