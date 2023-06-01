<?php

namespace Pantheon\TerminusRepository\VcsAuthApi;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Request\Request;

/**
 * Vcs Auth API Client.
 */
class Client
{
    /**
     * @var \Pantheon\Terminus\Request\Request
     */
    protected Request $request;

    /**
     * Constructor.
     *
     * @param \Pantheon\Terminus\Request\Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }


    /**
     * go-vcs-auth/authorize - returns (at least) workflow_id and vcs_auth_url
     *
     * @param string $vcs_organization
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function authorize($vcs_organization): array
    {
      $request_options = [
        'json' => [
            'vcs_organization' => $vcs_organization,
        ],
        'method' => 'POST',
      ];

      return $this->requestApi('authorize', $request_options);
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
    public function requestApi(string $path, array $options = []): array
    {
        $url = sprintf('%s/%s', $this->getPantheonApiBaseUri(), $path);
        $options = array_merge(
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => $this->request->session()->get('session'),
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
            'An error ocurred. Code: %code. Message: %reason',
            [
                '%code' => $statusCode,
                '%reason' => $result->getStatusCodeReason(),
            ]
        );
    }

    /**
     * Returns Pantheon API base URI.
     *
     * @return string
     */
    protected function getPantheonApiBaseUri(): string
    {
        $config = $this->request->getConfig();

        return sprintf(
            '%s://%s:%s/vcs-auth/v1',
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