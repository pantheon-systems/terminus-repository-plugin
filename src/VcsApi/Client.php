<?php

namespace Pantheon\TerminusRepository\VcsApi;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Request\Request;
use Pantheon\Terminus\Config\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;

/**
 * Vcs Auth API Client.
 */
class Client implements ConfigAwareInterface
{
    use ConfigAwareTrait;

    /**
     * @var \Pantheon\Terminus\Request\Request
     */
    protected Request $request;

    /**
     * @var int
     */
    protected int $pollingInterval;

    /**
     * Constructor.
     *
     * @param \Pantheon\Terminus\Request\Request $request
     * @param int $polling_interval
     */
    public function __construct(Request $request, int $polling_interval)
    {
        $this->request = $request;
        $this->pollingInterval = $polling_interval;
    }

    /**
     * Create site workflow.
     *
     * @param array $workflow_data
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function createWorkflow(array $workflow_data): array
    {
        $request_options = [
            'json' => $workflow_data,
            'method' => 'POST',
        ];

        return $this->requestApi('workflow', $request_options, "X-Pantheon-Session");
    }

    /**
     * Process site details until we get the expected status or an error.
     */
    public function processSiteDetails(string $site_id, $timeout = 0): array
    {
        $start = time();
        do {
            $polling_count++;
            $data = $this->getSiteDetails($site_id);
            $data = (array) $data['data'][0];
            // Multiply by 1000 to convert milliseconds to microseconds.
            usleep($this->pollingInterval * 1000);
            $current = time();
            $elapsed = $current - $start;
            if ($timeout > 0 && $elapsed > $timeout) {
                throw new TerminusException(
                    'Timeout while waiting for site details. Elapsed: {elapsed}. Timeout: {timeout}.',
                    [
                        'elapsed' => $elapsed,
                        'timeout' => $timeout,
                    ]
                );
            }
        } while ($data['is_active'] != true);

        return $data;
    }

    /**
     * Get site details by id.
     */
    public function getSiteDetails(string $site_id): array
    {
        $request_options = [
            'method' => 'GET',
        ];

        return $this->requestApi('site-details/' . $site_id, $request_options);
    }

    /**
     * Initialize repo.
     *
     * @param array $repo_initialize_data
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function repoInitialize(array $repo_initialize_data): array
    {
        $request_options = [
            'json' => $repo_initialize_data,
            'method' => 'POST',
        ];

        return $this->requestApi('repo-initialize', $request_options, "X-Pantheon-Session");
    }

    /**
     * Performs the request to API path.
     *
     * @param string $path
     * @param array $options
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function requestApi(string $path, array $options = [], string $auth_header_name = "Authorization"): array
    {
        $url = sprintf('%s/%s', $this->getPantheonApiBaseUri(), $path);
        $options = array_merge(
            [
                'headers' => [
                    'Accept' => 'application/json',
                    $auth_header_name => $this->request->session()->get('session'),
                ],
                // Do not convert http errors to exceptions
                'http_errors' => false,
            ],
            $options
        );

        $result = $this->request->request($url, $options);
        $statusCode = $result->getStatusCode();
        $data = $result->getData();
        // If it went ok, just return data.
        if ($statusCode >= 200 && $statusCode < 300) {
            return (array) $result->getData();
        }
        if (!empty($data->error)) {
            // If error was correctly set from backend, throw it.
            throw new TerminusException($data->error);
        }
        throw new TerminusException(
            'An error ocurred. Code: {code}. Message: {reason}',
            [
                'code' => $statusCode,
                'reason' => $result->getStatusCodeReason(),
            ]
        );
    }

    /**
     * Returns Pantheon API base URI.
     *
     * @return string
     */
    public function getPantheonApiBaseUri(): string
    {
        $config = $this->request->getConfig();

        return sprintf(
            '%s://%s:%s/vcs/v1',
            $config->get('papi_protocol') ?? $config->get('protocol') ?? 'https',
            $this->getHost(),
            $config->get('papi_port') ?? $config->get('port') ?? '443'
        );
    }

    /**
     * Returns Pantheon API host.
     *
     * @return string
     */
    protected function getHost(): string
    {
        $config = $this->request->getConfig();

        if ($config->get('papi_host')) {
            return $config->get('papi_host');
        }

        if ($config->get('host') && false !== strpos($config->get('host'), 'hermes.sandbox-')) {
            return str_replace('hermes', 'pantheonapi', $config->get('host'));
        }

        return 'api.pantheon.io';
    }
}
