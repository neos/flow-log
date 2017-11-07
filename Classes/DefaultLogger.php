<?php
namespace Neos\Flow\Log;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Log\Exception\NoSuchBackendException;

/**
 * The default logger of the Flow framework
 *
 * @api
 */
class DefaultLogger implements ThrowableLoggerInterface
{
    /**
     * @var \SplObjectStorage
     */
    protected $backends;

    /**
     * @var \closure
     */
    protected $requestInfoCallback = null;

    /**
     * @var \closure
     */
    protected $renderBacktraceCallback = null;

    /**
     * Constructs the logger
     *
     */
    public function __construct()
    {
        $this->backends = new \SplObjectStorage();
    }

    /**
     * @param \Closure $closure
     */
    public function setRequestInfoCallback(\Closure $closure)
    {
        $this->requestInfoCallback = $closure;
    }

    /**
     * @param \Closure $closure
     */
    public function setRenderBacktraceCallback(\Closure $closure)
    {
        $this->renderBacktraceCallback = $closure;
    }

    /**
     * Sets the given backend as the only backend for this Logger.
     *
     * This method allows for conveniently injecting a backend through some Objects.yaml configuration.
     *
     * @param Backend\BackendInterface $backend A backend implementation
     * @return void
     * @api
     */
    public function setBackend(Backend\BackendInterface $backend)
    {
        $this->backends = new \SplObjectStorage();
        $this->backends->attach($backend);
    }

    /**
     * Adds the backend to which the logger sends the logging data
     *
     * @param Backend\BackendInterface $backend A backend implementation
     * @return void
     * @api
     */
    public function addBackend(Backend\BackendInterface $backend)
    {
        $this->backends->attach($backend);
        $backend->open();
    }

    /**
     * Runs the close() method of a backend and removes the backend
     * from the logger.
     *
     * @param Backend\BackendInterface $backend The backend to remove
     * @return void
     * @throws NoSuchBackendException if the given backend is unknown to this logger
     * @api
     */
    public function removeBackend(Backend\BackendInterface $backend)
    {
        if (!$this->backends->contains($backend)) {
            throw new NoSuchBackendException('Backend is unknown to this logger.', 1229430381);
        }
        $backend->close();
        $this->backends->detach($backend);
    }

    /**
     * Writes the given message along with the additional information into the log.
     *
     * @param string $message The message to log
     * @param integer $severity An integer value, one of the LOG_* constants
     * @param mixed $additionalData A variable containing more information about the event to be logged
     * @param string $packageKey Key of the package triggering the log (determined automatically if not specified)
     * @param string $className Name of the class triggering the log (determined automatically if not specified)
     * @param string $methodName Name of the method triggering the log (determined automatically if not specified)
     * @return void
     * @api
     */
    public function log($message, $severity = LOG_INFO, $additionalData = null, $packageKey = null, $className = null, $methodName = null)
    {
        if ($packageKey === null) {
            $backtrace = debug_backtrace(false);
            $className = isset($backtrace[1]['class']) ? $backtrace[1]['class'] : null;
            $methodName = isset($backtrace[1]['function']) ? $backtrace[1]['function'] : null;
            $explodedClassName = explode('\\', $className);
            // FIXME: This is not really the package key:
            $packageKey = isset($explodedClassName[1]) ? $explodedClassName[1] : '';
        }
        foreach ($this->backends as $backend) {
            $backend->append($message, $severity, $additionalData, $packageKey, $className, $methodName);
        }
    }

    /**
     * @param \Exception $exception The exception to log
     * @param array $additionalData Additional data to log
     * @return void
     * @api
     */
    public function logException(\Exception $exception, array $additionalData = [])
    {
        $this->logError($exception, $additionalData);
    }

    /**
     * @param \Throwable $throwable The throwable to log
     * @param array $additionalData Additional data to log
     * @return void
     * @api
     */
    public function logThrowable(\Throwable $throwable, array $additionalData = [])
    {
        $this->logError($throwable, $additionalData);
    }

    /**
     * Writes information about the given exception into the log.
     *
     * @param object $error \Exception or \Throwable
     * @param array $additionalData Additional data to log
     * @return void
     */
    protected function logError($error, array $additionalData = [])
    {
        $backTrace = $error->getTrace();
        $className = isset($backTrace[0]['class']) ? $backTrace[0]['class'] : '?';
        $methodName = isset($backTrace[0]['function']) ? $backTrace[0]['function'] : '?';
        $message = $this->getErrorLogMessage($error);

        if ($error->getPrevious() !== null) {
            $additionalData['previousException'] = $this->getErrorLogMessage($error->getPrevious());
        }

        $explodedClassName = explode('\\', $className);
        // FIXME: This is not really the package key:
        $packageKey = (isset($explodedClassName[1])) ? $explodedClassName[1] : null;

        $flowPathDataIsAvailable = defined(FLOW_PATH_DATA);
        if ($flowPathDataIsAvailable && !file_exists(FLOW_PATH_DATA . 'Logs/Exceptions')) {
            mkdir(FLOW_PATH_DATA . 'Logs/Exceptions');
        }
        if ($flowPathDataIsAvailable && file_exists(FLOW_PATH_DATA . 'Logs/Exceptions') && is_dir(FLOW_PATH_DATA . 'Logs/Exceptions') && is_writable(FLOW_PATH_DATA . 'Logs/Exceptions')) {
            $referenceCode = ($error instanceof Exception) ? $error->getReferenceCode() : date('YmdHis', $_SERVER['REQUEST_TIME']) . substr(md5(rand()), 0, 6);
            $errorDumpPathAndFilename = FLOW_PATH_DATA . 'Logs/Exceptions/' . $referenceCode . '.txt';
            file_put_contents($errorDumpPathAndFilename, $this->renderErrorInfo($error));
            $message .= ' - See also: ' . basename($errorDumpPathAndFilename);
        } else {
            $this->log(sprintf('Could not write exception backtrace into %s because the directory could not be created or is not writable.', FLOW_PATH_DATA . 'Logs/Exceptions/'), LOG_WARNING, [], 'Flow', __CLASS__, __FUNCTION__);
        }

        $this->log($message, LOG_CRIT, $additionalData, $packageKey, $className, $methodName);
    }

    /**
     * Get current error post mortem informations with support for error chaining
     *
     * @param object $error \Exception or \Throwable
     * @return string
     */
    protected function renderErrorInfo($error)
    {
        $maximumDepth = 100;
        $backTrace = $error->getTrace();
        $message = $this->getErrorLogMessage($error);
        $postMortemInfo = $message . PHP_EOL . PHP_EOL . $this->renderBacktrace($backTrace);
        $depth = 0;
        while (($error->getPrevious() instanceof \Throwable || $error->getPrevious() instanceof \Exception) && $depth < $maximumDepth) {
            $error = $error->getPrevious();
            $message = 'Previous exception: ' . $this->getErrorLogMessage($error);
            $backTrace = $error->getTrace();
            $postMortemInfo .= PHP_EOL . $message . PHP_EOL . PHP_EOL . $this->renderBacktrace($backTrace);
            ++$depth;
        }

        $postMortemInfo .= $this->renderRequestInfo();

        if ($depth === $maximumDepth) {
            $postMortemInfo .= PHP_EOL . 'Maximum chainging depth reached ...';
        }

        return $postMortemInfo;
    }

    /**
     * @param object $error \Exception or \Throwable
     * @return string
     */
    protected function getErrorLogMessage($error)
    {
        $errorCodeNumber = ($error->getCode() > 0) ? ' #' . $error->getCode() : '';
        $backTrace = $error->getTrace();
        $line = isset($backTrace[0]['line']) ? ' in line ' . $backTrace[0]['line'] . ' of ' . $backTrace[0]['file'] : '';
        return 'Exception' . $errorCodeNumber . $line . ': ' . $error->getMessage();
    }

    /**
     * Renders background information about the circumstances of the exception.
     *
     * @param array $backTrace
     * @return string
     */
    protected function renderBacktrace($backTrace)
    {
        return $this->renderBacktraceCallback->__invoke($backTrace);
    }

    /**
     * Render information about the current request, if possible
     *
     * @return string
     */
    protected function renderRequestInfo()
    {
        $output = '';
        if ($this->requestInfoCallback !== null) {
            $output = $this->requestInfoCallback->__invoke();
        }

        return $output;
    }

    /**
     * Cleanly closes all registered backends before destructing this Logger
     *
     * @return void
     */
    public function shutdownObject()
    {
        foreach ($this->backends as $backend) {
            $backend->close();
        }
    }
}
