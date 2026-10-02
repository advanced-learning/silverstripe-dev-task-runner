<?php

/**
 * Watches the DevTaskRun that this PHP process is running, so that the run
 * always gets a final status and keeps its output:
 *
 * - Task output is captured and appended to the run while the task runs.
 * - A shutdown handler marks the run as Error when PHP stops before the task
 *   returns (a fatal error such as out of memory, or exit() in the task).
 * - A SIGTERM/SIGINT handler marks the run as Error when the process is told
 *   to stop. This needs the pcntl extension, and PHP must receive the signal:
 *   on Kubernetes, start cli.php with `exec` (or directly), not as a child of
 *   `/bin/sh -c`, because the shell does not pass the signal on.
 *
 * The monitor reads and writes the run on its own database connection. The
 * task may have a transaction open on the default connection, and that
 * transaction is rolled back when the process dies. On a separate connection
 * the run's status and output still commit, and cancel checks see the latest
 * value instead of the transaction's snapshot.
 */
class DevTaskRunMonitor
{
    /**
     * Bytes. Below the 16777215 byte limit of a MySQL mediumtext column, with
     * room for the truncation note.
     */
    const OUTPUT_LIMIT = 16000000;

    /**
     * Name of the monitor's own database connection.
     */
    const CONNECTION = 'DevTaskRunMonitor';

    /**
     * Seconds between saves of new output to the run.
     * @var int
     */
    private static $output_save_interval = 10;

    /**
     * Seconds between database checks for a cancel request.
     * @var int
     */
    private static $cancel_check_interval = 5;

    /** @var DevTaskRunMonitor|null */
    protected static $current = null;

    /** @var bool */
    protected static $handlersRegistered = false;

    /**
     * Freed by the shutdown handler, so that it has memory to save the run
     * after PHP runs out of memory.
     * @var string|null
     */
    protected static $reservedMemory = null;

    /** @var int */
    protected $runID;

    /** @var int */
    protected $startTime = 0;

    /** @var string */
    protected $pendingOutput = '';

    /** @var int */
    protected $savedBytes = 0;

    /** @var int */
    protected $lastSave = 0;

    /** @var int */
    protected $lastCancelCheck = 0;

    /** @var int */
    protected $bufferLevel = 0;

    /** @var bool */
    protected $inOutputHandler = false;

    /** @var bool */
    protected $finished = false;

    public function __construct(DevTaskRun $run)
    {
        $this->runID = (int) $run->ID;
    }

    /**
     * Registers the shutdown and signal handlers, and opens the monitor's
     * database connection. Call this before the first run is claimed, so that
     * a connection failure stops the process before it claims a run.
     */
    public static function registerHandlers(): void
    {
        self::conn();

        if (self::$handlersRegistered) {
            return;
        }
        self::$handlersRegistered = true;

        self::$reservedMemory = str_repeat(' ', 1024 * 1024);
        register_shutdown_function(['DevTaskRunMonitor', 'handleShutdown']);

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, ['DevTaskRunMonitor', 'handleSignal']);
            pcntl_signal(SIGINT, ['DevTaskRunMonitor', 'handleSignal']);
        }
    }

    /**
     * The monitor's own database connection, on the same database as the
     * default connection.
     */
    public static function conn(): SS_Database
    {
        $database = DB::get_conn()->getSelectedDatabase();
        $conn = DB::get_conn(self::CONNECTION);

        if (!$conn) {
            global $databaseConfig;
            $config = $databaseConfig;
            $config['database'] = $database;
            $conn = DB::connect($config, self::CONNECTION);
        } elseif ($conn->getSelectedDatabase() !== $database) {
            // Tests switch the default connection to a temporary database.
            $conn->selectDatabase($database);
        }

        return $conn;
    }

    /**
     * The monitor of the run that is in progress, or null when no run is in
     * progress in this process.
     */
    public static function current(): ?DevTaskRunMonitor
    {
        return self::$current;
    }

    /**
     * Starts capturing output for the run.
     */
    public function start(): void
    {
        $this->startTime = time();
        $this->lastSave = $this->startTime;
        self::$current = $this;

        // A chunk size of 1 passes every echo to the handler straight away.
        ob_start([$this, 'handleOutput'], 1);
        $this->bufferLevel = ob_get_level();
    }

    /**
     * Output buffer handler. Collects the output and saves it to the run from
     * time to time. Returns '' so the output is not also sent to stdout.
     *
     * @param string $buffer
     * @param int $phase
     */
    public function handleOutput($buffer, $phase): string
    {
        $this->inOutputHandler = true;
        $this->pendingOutput .= $buffer;

        $interval = (int) Config::inst()->get('DevTaskRunMonitor', 'output_save_interval');
        if (time() - $this->lastSave >= $interval) {
            try {
                $this->saveOutput();
            } catch (Throwable $e) {
                // Keep the task running. The output is saved again at the end.
            }
        }

        $this->inOutputHandler = false;

        return '';
    }

    /**
     * Appends the output collected since the last save to the run.
     */
    public function saveOutput(): void
    {
        $this->lastSave = time();

        if ($this->pendingOutput === '') {
            return;
        }

        $chunk = $this->pendingOutput;
        $this->pendingOutput = '';

        $room = self::OUTPUT_LIMIT - $this->savedBytes;
        if ($room <= 0) {
            return;
        }

        if (strlen($chunk) > $room) {
            $chunk = mb_strcut($chunk, 0, $room, 'UTF-8')
                . "\n[Output truncated: it was longer than " . self::OUTPUT_LIMIT . " bytes.]\n";
            $this->savedBytes = self::OUTPUT_LIMIT;
        } else {
            $this->savedBytes += strlen($chunk);
        }

        self::conn()->preparedQuery(
            'UPDATE "DevTaskRun" SET "Output" = CONCAT(COALESCE("Output", \'\'), ?) WHERE "ID" = ?',
            [$chunk, $this->runID]
        );
    }

    /**
     * Saves new output, then throws DevTaskCancelledException if an admin has
     * asked to cancel the run. Checks the database at most once every
     * `cancel_check_interval` seconds.
     *
     * @throws DevTaskCancelledException
     */
    public function checkCancelled(): void
    {
        $interval = (int) Config::inst()->get('DevTaskRunMonitor', 'cancel_check_interval');
        $now = time();
        if ($this->lastCancelCheck && $now - $this->lastCancelCheck < $interval) {
            return;
        }
        $this->lastCancelCheck = $now;

        $this->saveOutput();

        $requested = self::conn()->preparedQuery(
            'SELECT "CancelRequested" FROM "DevTaskRun" WHERE "ID" = ?',
            [$this->runID]
        )->value();

        if ($requested) {
            throw new DevTaskCancelledException('An admin cancelled this run.');
        }
    }

    /**
     * Stops capturing output, saves the rest of it, and sets the final status.
     * Only a run that is still Running is updated, and only the first call
     * does anything.
     *
     * @param string $status Finished, Error or Cancelled.
     * @param string|null $failureReason
     */
    public function finish(string $status, ?string $failureReason = null): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        if (self::$current === $this) {
            self::$current = null;
        }

        $this->closeBuffers();
        $this->saveOutput();

        $now = SS_Datetime::now()->getValue();
        self::conn()->preparedQuery(
            'UPDATE "DevTaskRun"'
            . ' SET "Status" = ?, "FinishDate" = ?, "FailureReason" = ?, "LastEdited" = ?'
            . ' WHERE "ID" = ? AND "Status" = \'Running\'',
            [$status, $now, $failureReason, $now, $this->runID]
        );
    }

    public function getRunID(): int
    {
        return $this->runID;
    }

    /**
     * Seconds since the run started.
     */
    public function getElapsedSeconds(): int
    {
        return time() - $this->startTime;
    }

    /**
     * Closes this monitor's output buffer, and any buffers the task left open
     * above it, so that their content reaches handleOutput().
     */
    protected function closeBuffers(): void
    {
        // PHP does not allow output buffer functions inside an output handler.
        if ($this->inOutputHandler) {
            return;
        }

        while (ob_get_level() >= $this->bufferLevel && ob_get_level() > 0) {
            if (!@ob_end_flush()) {
                break;
            }
        }
    }

    /**
     * Shutdown handler. Marks the run in progress as Error when PHP stops
     * before the task returns.
     */
    public static function handleShutdown(): void
    {
        self::$reservedMemory = null;

        $monitor = self::$current;
        if (!$monitor) {
            return;
        }

        $error = error_get_last();
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

        if ($error && ($error['type'] & $fatalTypes)) {
            if (stripos($error['message'], 'Allowed memory size') !== false) {
                @ini_set('memory_limit', (string) (memory_get_usage(true) + 64 * 1024 * 1024));
            }
            $reason = sprintf(
                'PHP fatal error: %s in %s on line %d',
                $error['message'],
                $error['file'],
                $error['line']
            );
        } else {
            $reason = 'The PHP process ended before the task returned'
                . ' (exit() or die() in the task, or a SilverStripe fatal error). See the output.';
        }

        $monitor->finish('Error', $reason);
    }

    /**
     * Signal handler. Marks the run in progress as Error, then exits.
     *
     * @param int $signal
     * @param mixed $info
     */
    public static function handleSignal($signal, $info = null): void
    {
        $monitor = self::$current;

        if ($monitor) {
            $names = [SIGTERM => 'SIGTERM', SIGINT => 'SIGINT'];
            $name = $names[$signal] ?? "signal $signal";

            $monitor->finish('Error', sprintf(
                'The process received %s after the task ran for %d seconds, and stopped.'
                . ' On Kubernetes this is usually the cron Job time limit.',
                $name,
                $monitor->getElapsedSeconds()
            ));
        }

        exit(128 + (int) $signal);
    }
}
