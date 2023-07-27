<?php

namespace Pantheon\TerminusRepository\VcsAuthApi;

use Pantheon\Terminus\Request\RequestAwareTrait;

/**
 * Class VcsAuthClientAwareTrait.
 *
 * @package \Pantheon\TerminusRepository\VcsAuthApi
 */
trait VcsAuthClientAwareTrait {
  use RequestAwareTrait;

  /**
   * @var \Pantheon\TerminusRepository\VcsAuthApi\Client
   */
  protected Client $vcsAuthClient;

  /**
   * Return the VcsAuth client object.
   *
   * @return \Pantheon\TerminusRepository\VcsAuthApi\Client
   */
  public function getVcsAuthClient(): Client
  {
      if (isset($this->vcsAuthClient)) {
          return $this->vcsAuthClient;
      }

      $polling_interval = $this->getConfig()->get('http_retry_delay_ms', 1000);

      return $this->vcsAuthClient = new Client($this->request(), $polling_interval);
  }

}
