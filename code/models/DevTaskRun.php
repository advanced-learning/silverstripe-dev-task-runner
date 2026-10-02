<?php

/**
 * @property string|null Task
 * @property string|null Params
 * @property string Status
 * @property string|null StartDate
 * @property string|null FinishDate
 * @property string|null Output
 * @property string|null Host
 * @property string|null FailureReason
 * @property bool CancelRequested
 */
class DevTaskRun extends DataObject
{
	private static $db = [
		'Task' => 'Varchar(150)',
		'Params' => 'Text',
		'Status' => 'Enum("Draft,Queued,Running,Finished,Error,Cancelled", "Draft")',
		'StartDate' => 'SS_Datetime',
		'FinishDate' => 'SS_Datetime',
		'Output' => 'Text',
		// The host (on Kubernetes, the pod) that ran the task.
		'Host' => 'Varchar(255)',
		'FailureReason' => 'Text',
		'CancelRequested' => 'Boolean',
    ];

	private static $summary_fields = [
        'ID',
		'TaskTitle' => 'Task',
		'Params' => 'Params',
		'Status' => 'Status',
        'Created' => 'Created',
		'StartDate' => 'Start Date',
		'FinishDate' => 'Finish Date',
		'OutputPreview' => 'Output Preview',
    ];

	private static $default_sort = 'Created DESC';

	private $queuedCheckBox;

	public function __construct($record = null, $isSingleton = false, $model = null)
	{
		$this->queuedCheckBox = CheckboxField::create('Queue');

		parent::__construct($record, $isSingleton, $model);
	}

	public function getCMSFields(): FieldList
	{
		$fields = parent::getCMSFields();
		$fields->removeByName(['Host', 'FailureReason', 'CancelRequested']);

		$addStatusBefore = '';

		if (!$this->exists()) {
			$taskList = array();

			//defined allowed task list
			$tasks = $this->config()->task_list;

			//default to all tasks
			if (!$tasks) {
				$tasks = ClassInfo::subclassesFor('BuildTask');
				//remove first item which is BuildTask
				array_shift($tasks);
			}

			foreach ($tasks as $task) {
				$taskList[$task] = singleton($task)->getTitle();
			}

			$fields->addFieldToTab(
				'Root.Main',
				DropdownField::create('Task', 'Task', $taskList),
			);

			$fields->addFieldToTab(
				'Root.Main',
				LiteralField::create(
					'Instruction',
					'Note: You must save this task to be able to queue it for execution.'
				)
			);

			$fields->dataFieldByName('Params')->setDescription(
				'Add a list of params to be passed to the Task.' .
				'Separate with spaces, e.g. <code>param1=value1 param2=value2</code>.'
			);

			$addStatusBefore = 'Instruction';
		} else {
            $fields->addFieldToTab(
                'Root.Main',
                ReadonlyField::create('Created', 'Created', $this->Created),
                'StartDate'
            );

			$fields->addFieldToTab(
				'Root.Main',
				ReadonlyField::create('Task', 'Task', $this->TaskTitle()),
			);

			//add checkbox control for cms user for advancing task from 'Draft to 'Queued' status
			if ($this->Status === 'Draft') {
				$fields->addFieldToTab(
					'Root.Main',
					$this->queuedCheckBox
				);

				//if no longer in draft, remove above checkbox and make the Params readonly
			} else {
				$fields->removeByName('Queue');
				$fields->addFieldToTab(
					'Root.Main',
					ReadonlyField::create('Params', 'Params', $this->Params)
				);
			}

			$fields->addFieldToTab(
				'Root.Main',
				LiteralField::create(
					'Description',
					"<div style='margin: 8px 0;'>" .
					"<p>Description:</p>" . $this->getDesc() .
					"</div>"
				),
				'Params'
			);
		}

		if (!$this->exists() || $this->Status === 'Draft' || $this->Status === 'Queued') {
			$fields->removeByName('StartDate');
			$fields->removeByName('FinishDate');
			$fields->removeByName('Output');
		} else {
			$fields->addFieldsToTab(
				'Root.Main',
				[
					ReadonlyField::create('StartDate', 'Start Date', $this->StartDate),
					ReadonlyField::create('FinishDate', 'Finish Date', $this->FinishDate),
					ReadonlyField::create('Host', 'Host', $this->Host),
				]
			);

			if ($this->FailureReason) {
				$fields->addFieldToTab(
					'Root.Main',
					ReadonlyField::create('FailureReason', 'Failure reason', $this->FailureReason)
				);
			}

			$fields->addFieldToTab(
				'Root.Output',
				ReadonlyField::create('Output', '', $this->Output)
			);
			$fields->addFieldToTab(
				'Root.Output as HTML',
				LiteralField::create('OutputAsHtml', $this->Output)
			);

			$addStatusBefore = 'StartDate';
		}

		$fields->addFieldToTab(
			'Root.Main',
			ReadonlyField::create('Status', 'Status', $this->StatusLabel()),
			$addStatusBefore
		);

		return $fields;
	}

	public function onBeforeWrite()
	{
		parent::onBeforeWrite();

		/** @var CheckboxField $queueCheckbox */
		$queueCheckbox = $this->getCMSFields()->dataFieldByName('Queue');

		if ($queueCheckbox && $queueCheckbox->Value()) {
			$this->Status = 'Queued';
			$this->write();
		}
	}

	/**
	 * Marks a Queued run as Running for this process. Only one process can
	 * claim a run, because the update only matches while the run is Queued.
	 *
	 * @param string $host The host (pod) that will run the task.
	 *
	 * @return bool True if this process claimed the run.
	 */
	public function claim(string $host): bool
	{
		$now = SS_Datetime::now()->getValue();

		DB::prepared_query(
			'UPDATE "DevTaskRun"'
			. ' SET "Status" = \'Running\', "StartDate" = ?, "Host" = ?, "LastEdited" = ?'
			. ' WHERE "ID" = ? AND "Status" = \'Queued\'',
			[$now, $host, $now, $this->ID]
		);

		if (DB::affected_rows() !== 1) {
			return false;
		}

		$this->Status = 'Running';
		$this->StartDate = $now;
		$this->Host = $host;

		return true;
	}

	/**
	 * Whether an admin can cancel this run now.
	 */
	public function canCancel(): bool
	{
		return $this->Status === 'Queued'
			|| ($this->Status === 'Running' && !$this->CancelRequested);
	}

	/**
	 * Cancels the run. A Queued run gets the Cancelled status and does not
	 * start. A Running run gets a cancel request, and the task stops at its
	 * next DevTaskRun::checkCancelled() call.
	 *
	 * @return string|null 'cancelled', 'requested', or null when the run was
	 *     no longer Queued or Running.
	 */
	public function cancel(): ?string
	{
		$now = SS_Datetime::now()->getValue();

		DB::prepared_query(
			'UPDATE "DevTaskRun"'
			. ' SET "Status" = \'Cancelled\', "FinishDate" = ?, "LastEdited" = ?'
			. ' WHERE "ID" = ? AND "Status" = \'Queued\'',
			[$now, $now, $this->ID]
		);
		if (DB::affected_rows() === 1) {
			return 'cancelled';
		}

		DB::prepared_query(
			'UPDATE "DevTaskRun"'
			. ' SET "CancelRequested" = 1, "LastEdited" = ?'
			. ' WHERE "ID" = ? AND "Status" = \'Running\'',
			[$now, $this->ID]
		);
		if (DB::affected_rows() === 1) {
			return 'requested';
		}

		return null;
	}

	/**
	 * Call this from a task at safe points, for example between batches.
	 * When an admin has cancelled the run, it throws DevTaskCancelledException
	 * and the runner marks the run as Cancelled. Do not catch that exception.
	 * Does nothing when the task does not run through the dev task runner.
	 *
	 * @throws DevTaskCancelledException
	 */
	public static function checkCancelled(): void
	{
		$monitor = DevTaskRunMonitor::current();

		if ($monitor) {
			$monitor->checkCancelled();
		}
	}

	public function StatusLabel(): string
	{
		$status = $this->Status ?: 'Draft';

		if ($status === 'Running' && $this->CancelRequested) {
			return 'Running (cancel requested)';
		}

		return $status;
	}

	public function getDesc(): string
	{
		$taskName = $this->Task;

		if (class_exists($taskName)) {
			$instance = new $taskName();

			if ($instance instanceof BuildTask) {
				return $instance->getDescription();
			}
		}

		return "";
	}

	public function TaskTitle() {
		if (!class_exists($this->Task)) {
			return "Deleted Task - $this->Task";
		}
		if (!method_exists($this->Task, 'getTitle')) {
			return "$this->Task";
		}
		return singleton($this->Task)->getTitle();
	}

	public function OutputPreview()
	{
		return $this->Output ? (substr($this->Output, 0, 30) . '...') : '';
	}
}
