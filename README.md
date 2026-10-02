[![Latest Stable Version](https://poser.pugx.org/webtorque/dev-task-runner/v/stable)](https://packagist.org/packages/webtorque/dev-task-runner)
[![Total Downloads](https://poser.pugx.org/webtorque/dev-task-runner/downloads)](https://packagist.org/packages/webtorque/dev-task-runner)
[![Latest Unstable Version](https://poser.pugx.org/webtorque/dev-task-runner/v/unstable)](https://packagist.org/packages/webtorque/dev-task-runner)
[![License](https://poser.pugx.org/webtorque/dev-task-runner/license)](https://packagist.org/packages/webtorque/dev-task-runner)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/webtorque7/silverstripe-dev-task-runner/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/webtorque7/silverstripe-dev-task-runner/?branch=master)

#Dev Task Runner

SilverStripe module for running dev tasks through the CMS.

Uses cron task to pull tasks off a queue up to three at a time.

## How a run ends

Each run gets one of these final statuses: `Finished`, `Error` or `Cancelled`.

- A cron process claims a run just before it starts. Only one process can claim a run.
- Output is saved on the run while the task runs, not only at the end.
- The run records the host (on Kubernetes, the pod) that ran it.
- A run is marked `Error`, with a failure reason, when:
  - the task throws an exception
  - PHP stops with a fatal error (for example, out of memory), or the task calls `exit()`
  - the process gets `SIGTERM` or `SIGINT` (for example, at the cron Job time limit)

Signal handling needs the `pcntl` extension. PHP must also receive the signal itself.
On Kubernetes, start `cli.php` with `exec`, or run it directly. Do not run it as a child of
`/bin/sh -c`, because the shell does not pass the signal on.

## Cancelling a run

Open the run in the Dev Tasks admin and click **Cancel run**.

- A `Queued` run gets the `Cancelled` status and does not start.
- A `Running` run gets a cancel request. The task stops at its next cancel check, and the run
  is marked `Cancelled`. A task that never checks runs to the end.

To make a task cancellable, call this at safe points, for example between batches:

```php
DevTaskRun::checkCancelled();
```

It throws `DevTaskCancelledException` when an admin has cancelled the run. Do not catch that
exception in the task. When the task does not run through the dev task runner, the call does nothing.
