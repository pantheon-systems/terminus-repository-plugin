<?php

namespace Pantheon\TerminusRepository;

trait WorkflowWaitTrait
{
  /**
   * Wait for a workflow to finish.
   *
   * @param int $startTime
   *   The time the workflow started.
   * @param \Pantheon\Terminus\Models\Site $site
   *   The site object.
   * @param string $env_name
   *   The environment name.
   * @param string $expectedWorkflowDescription
   *   The expected workflow description.
   * @param int $maxWaitInSeconds
   *   The maximum wait time in seconds.
   * @param int|null $maxNotFoundAttempts
   *   The maximum number of attempts to find the workflow.
   */
    protected function waitForWorkflow(
        $startTime,
        $site,
        $env_name,
        $expectedWorkflowDescription = '',
        $maxWaitInSeconds = 180,
        $maxNotFoundAttempts = null
    ) {
        if (empty($expectedWorkflowDescription)) {
            $expectedWorkflowDescription = "Sync code on $env_name";
        }

        $startWaiting = time();
        $firstWorkflowDescription = null;
        $notFoundAttempts = 0;
        $workflows = $site->getWorkflows();

        while (true) {
            $site = $this->getSiteById($site->id);
            // Refresh env on each interation.
            $index = 0;
            $workflows->reset();
            $workflow_items = $workflows->fetch(['paged' => false,])->all();
            $found = false;
            foreach ($workflow_items as $workflow) {
                $workflowCreationTime = $workflow->get('created_at');

                $workflowDescription = str_replace('"', '', $workflow->get('description'));
                if ($index === 0) {
                    $firstWorkflowDescription = $workflowDescription;
                }
                $index++;

                if ($workflowCreationTime < $startTime) {
                    // We already passed the start time.
                    break;
                }

                if (($expectedWorkflowDescription === $workflowDescription)) {
                    $workflow->fetch();
                    $this->log()->notice(
                        "Workflow '{current}' {status}.",
                        ['current' => $workflowDescription, 'status' => $workflow->getStatus()]
                    );
                    $found = true;
                    if ($workflow->isSuccessful()) {
                        $this->log()->notice("Workflow succeeded");
                        return;
                    }
                }
            }
            if (!$found) {
                $notFoundAttempts++;
                $this->log()->notice(
                    "Current workflow is '{current}'; waiting for '{expected}'",
                    ['current' => $firstWorkflowDescription, 'expected' => $expectedWorkflowDescription]
                );
                if ($maxNotFoundAttempts && $notFoundAttempts === $maxNotFoundAttempts) {
                    $this->log()->warning(
                        "Attempted '{max}' times, giving up waiting for workflow to be found",
                        ['max' => $maxNotFoundAttempts]
                    );
                    break;
                }
            }
            // Wait a bit, then spin some more
            sleep(5);
            if (time() - $startWaiting >= $maxWaitInSeconds) {
                $this->log()->warning(
                    "Waited '{max}' seconds, giving up waiting for workflow to finish",
                    ['max' => $maxWaitInSeconds]
                );
                break;
            }
        }
    }
}
