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
     * Call authorize endpoint.
     *
     * @param array $authorize_data
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function authorize(array $authorize_data): array
    {
        $request_options = [
            'json' => $authorize_data,
            'method' => 'POST',
        ];

        return $this->requestApi('authorize', $request_options, "X-Pantheon-Session");
    }

    /**
     * Call installwithtoken endpoint.
     *
     * @param array $data
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function installWithToken(array $data): array
    {
        $request_options = [
            'json' => $data,
            'method' => 'POST',
        ];

        return $this->requestApi('installwithtoken', $request_options, "X-Pantheon-Session");
    }

    /**
     * Install webhook.
     *
     * @param array $webhook_install_data
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function installWebhook(array $webhook_install_data): array
    {
        $request_options = [
            'json' => $webhook_install_data,
            'method' => 'POST',
        ];

        return $this->requestApi('webhook', $request_options, "X-Pantheon-Session");
    }

    /**
     * Process site details until we get the expected status or an error.
     */
    public function processSiteDetails(string $site_id, $timeout = 0): array
    {
        $start = time();
        do {
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
     * Process project ready until we get the expected status or an error.
     */
    public function processProjectReady(string $site_id, $timeout = 0): array
    {
        $start = time();
        do {
            $data = $this->getProjectReady($site_id);
            $data = (array) $data;
            // Multiply by 1000 to convert milliseconds to microseconds.
            usleep($this->pollingInterval * 1000);
            $current = time();
            $elapsed = $current - $start;
            if ($timeout > 0 && $elapsed > $timeout) {
                throw new TerminusException(
                    'Timeout while waiting for project ready. Elapsed: {elapsed}. Timeout: {timeout}.',
                    [
                        'elapsed' => $elapsed,
                        'timeout' => $timeout,
                    ]
                );
            }
        } while ($data['ready'] != true);

        return $data;
    }

    /**
     * Process project ready until we get the expected status or an error.
     */
    public function processHealthcheck(string $site_id, $timeout = 0): array
    {
        $start = time();
        $success = false;
        do {
            // Multiply by 1000 to convert milliseconds to microseconds.
            usleep($this->pollingInterval * 1000);
            try {
                $data = $this->getHealthcheck($site_id);
            } catch (TerminusException $e) {
                // If we get an error, just continue and retry.
                continue;
            }
            $data = (array) $data;

            $current = time();
            $elapsed = $current - $start;
            if ($timeout > 0 && $elapsed > $timeout) {
                throw new TerminusException(
                    'Timeout while waiting for healthcheck. Elapsed: {elapsed}. Timeout: {timeout}.',
                    [
                        'elapsed' => $elapsed,
                        'timeout' => $timeout,
                    ]
                );
            }

            if ($data['status'] != "SUCCESS") {
                continue;
            }

            foreach ($data['detail'] as $detail) {
                if ($detail->name !== "internal-provisioner") {
                    continue;
                }
                if ($detail->status !== "SUCCESS") {
                    continue;
                }
                $recorded_at = $detail->recorded_at;
                // If recorded_at is later than start, then we're ready to move on. Recorded at is a datetime string.
                if (strtotime($recorded_at) >= $start) {
                    $success = true;
                }
            }
        } while (!$success);

        return $data;
    }

    /**
     * Cleanup site details.
     */
    public function cleanupSiteDetails(string $site_details_id): void
    {
        $request_options = [
            'method' => 'DELETE',
        ];

        $this->requestApi(sprintf('site-details/%s', $site_details_id), $request_options);
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
     * Get project ready by id.
     */
    public function getProjectReady(string $site_id): array
    {
        $request_options = [
            'method' => 'GET',
        ];

        return $this->requestApi(sprintf('site-details/%s/project-ready', $site_id), $request_options);
    }

    /**
     * Get project healthcheck status.
     */
    public function getHealthcheck(string $site_id): array
    {
        $request_options = [
            'method' => 'GET',
        ];

        return $this->requestApi(sprintf('site-details/%s/tenant-healthcheck', $site_id), $request_options);
    }

    /**
     * Create repo.
     *
     * @param array $repo_create_data
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function repoCreate(array $repo_create_data): array
    {
        $request_options = [
            'json' => $repo_create_data,
            'method' => 'POST',
        ];

        return $this->requestApi('repository', $request_options, "X-Pantheon-Session");
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
            'timeout' => 240,
            'read_timeout' => 240,
        ];

        return $this->requestApi('repo-initialize', $request_options, "X-Pantheon-Session");
    }

    /**
     * Pause build for a given site.
     *
     * @param string $site_id
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function pauseBuild(string $site_id): array
    {
        $request_options = [
            'method' => 'POST',
        ];

        return $this->requestApi('site-details/' . $site_id . '/pause-builds', $request_options, "X-Pantheon-Session");
    }

    /**
     * Resume build for a given site.
     *
     * @param string $site_id
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function resumeBuild(string $site_id): array
    {
        $request_options = [
            'method' => 'POST',
        ];

        return $this->requestApi('site-details/' . $site_id . '/resume-builds', $request_options, "X-Pantheon-Session");
    }

    /**
     * Get site details by id.
     */
    public function getSiteDetailsById(string $site_id): array
    {
        $request_options = [
            'method' => 'GET',
        ];

        return $this->requestApi('site-details/' . $site_id, $request_options);
    }

    /**
     * Pushes GitHub VCS event to the VCS API.
     */
    public function githubVcs($data, string $site_id, string $event_name): array
    {
        $request_options = [
            'json' => $data,
            'method' => 'POST',
            'headers' => [
                'X-Pantheon-Site-Id' => $site_id,
                'Accept' => 'application/json',
                'X-Pantheon-Session' => $this->request->session()->get('session'),
                'X-GitHub-Event' => $event_name,
            ],
        ];

        return $this->requestApi('github-vcs', $request_options, "X-Pantheon-Session");
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
        if (!$host && strpos($config->get('host'), 'sandbox-') !== false) {
            return $config->get('host');
        }

        return 'api.pantheon.io';
    }
}
