<?php

/**
 * Thrown by DevTaskRun::checkCancelled() when an admin has asked to cancel the
 * running task. Do not catch it in task code: the runner catches it and marks
 * the run as Cancelled.
 */
class DevTaskCancelledException extends RuntimeException
{
}
