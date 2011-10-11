<?php
/**
 * PHPUnit
 *
 * Copyright (c) 2002-2011, Sebastian Bergmann <sebastian@phpunit.de>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Sebastian Bergmann nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package    PHPUnit
 * @subpackage Util
 * @author     Sebastian Bergmann <sebastian@phpunit.de>
 * @copyright  2002-2011 Sebastian Bergmann <sebastian@phpunit.de>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @link       http://www.phpunit.de/
 * @since      File available since Release 3.4.0
 */

/**
 * Utility methods for PHP sub-processes.
 *
 * @package    PHPUnit
 * @subpackage Util
 * @author     Sebastian Bergmann <sebastian@phpunit.de>
 * @copyright  2002-2011 Sebastian Bergmann <sebastian@phpunit.de>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version    Release: @package_version@
 * @link       http://www.phpunit.de/
 * @since      Class available since Release 3.4.0
 */
abstract class PHPUnit_Util_PHP
{
    /**
     * @var string $phpBinary
     */
    protected $phpBinary;

    /**
     * Array of metadata about currently running jobs.
     *
     * @var    array $jobs
     */
    protected $jobs = array();

    /**
     * Returns the path to a PHP interpreter.
     *
     * PHPUnit_Util_PHP::$phpBinary contains the path to the PHP
     * interpreter.
     *
     * When not set, the following assumptions will be made:
     *
     *   1. When the PHP CLI/CGI binary configured with the PEAR Installer
     *      (php_bin configuration value) is readable, it will be used.
     *
     *   2. When PHPUnit is run using the CLI SAPI and the $_SERVER['_']
     *      variable does not contain the string "PHPUnit", $_SERVER['_']
     *      is assumed to contain the path to the current PHP interpreter
     *      and that will be used.
     *
     *   3. When PHPUnit is run using the CLI SAPI and the $_SERVER['_']
     *      variable contains the string "PHPUnit", the file that $_SERVER['_']
     *      points to is assumed to be the PHPUnit TextUI CLI wrapper script
     *      "phpunit" and the binary set up using #! on that file's first
     *      line of code is assumed to contain the path to the current PHP
     *      interpreter and that will be used.
     *
     *   4. The current PHP interpreter is assumed to be in the $PATH and
     *      to be invokable through "php".
     *
     * @return string
     */
    protected function getPhpBinary()
    {
        if ($this->phpBinary === NULL) {
            if (is_readable('@php_bin@')) {
                $this->phpBinary = '@php_bin@';
            }

            else if (PHP_SAPI == 'cli' && isset($_SERVER['_']) &&
                     strpos($_SERVER['_'], 'phpunit') !== FALSE) {
                $file            = file($_SERVER['_']);
                $tmp             = explode(' ', $file[0]);
                $this->phpBinary = trim($tmp[1]);
            }

            if (!is_readable($this->phpBinary)) {
                $this->phpBinary = 'php';
            } else {
                $this->phpBinary = escapeshellcmd($this->phpBinary);
            }
        }

        return $this->phpBinary;
    }

    /**
     * @return PHPUnit_Util_PHP
     * @since  Method available since Release 3.5.12
     */
    public static function factory()
    {
        if (DIRECTORY_SEPARATOR == '\\') {
            return new PHPUnit_Util_PHP_Windows;
        }

        return new PHPUnit_Util_PHP_Default;
    }

    /**
     * Starts the separate process to run a single job(test).
     * Returns process id of new proces
     *
     * @param  string                       $job
     * @param  PHPUnit_Framework_Test       $test
     * @param  PHPUnit_Framework_TestResult $result
     * @return int
     * @throws PHPUnit_Framework_Exception
     */
    public function startJob($job, PHPUnit_Framework_Test $test = NULL, PHPUnit_Framework_TestResult $result = NULL)
    {
        $process = proc_open(
          $this->getPhpBinary(),
          array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
          ),
          $pipes
        );
        $status = proc_get_status($process);
        $pid = $status['pid'];

        if (!is_resource($process)) {
            throw new PHPUnit_Framework_Exception(
              'Unable to create process for process isolation.'
            );
        }

        if ($result !== NULL) {
            $result->startTest($test);
        }

        $this->process($pipes[0], $job);
        fclose($pipes[0]);
        $this->jobs[$pid] = array('process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'test' => $test, 'result' => $result);
        return $pid;
    }

    /**
     * Closes out the process that was used to run a job(test).
     * If the process isn't finished, block until it is
     *
     * @param  int $pid
     * @return array|null
     */
    public function finishJob($pid)
    {
        $stdout = stream_get_contents($this->jobs[$pid]['stdout']);
        fclose($this->jobs[$pid]['stdout']);

        $stderr = stream_get_contents($this->jobs[$pid]['stderr']);
        fclose($this->jobs[$pid]['stderr']);
        
        proc_close($this->jobs[$pid]['process']);
        $this->cleanup();

        $result = $this->jobs[$pid]['result'];
        $test = $this->jobs[$pid]['test'];
        unset($this->jobs[$pid]);
        if ($result !== NULL) {
            $this->processChildResult($test, $result, $stdout, $stderr);
        } else {
            return array('stdout' => $stdout, 'stderr' => $stderr);
        }
    }

    /**
     * Runs a single job (PHP code) using a separate PHP process.
     *
     * @param  string                       $job
     * @param  PHPUnit_Framework_Test       $test
     * @param  PHPUnit_Framework_TestResult $result
     * @return array|null
     * @throws PHPUnit_Framework_Exception
     */
    public function runJob($job, PHPUnit_Framework_Test $test = NULL, PHPUnit_Framework_TestResult $result = NULL)
    {
        $pid = $this->startJob($job, $test, $result);
        return $this->finishJob($pid);
    }

    /**
     * Says whether a subprocess (from a job) is finished
     *
     * @param  int $pid
     * @return bool
     */
    public function isJobFinished($pid)
    {
        $status = proc_get_status($this->jobs[$pid]['process']);
        return !$status['running'];
    }

    /**
     * @param resource $pipe
     * @param string   $job
     * @since Method available since Release 3.5.12
     */
    abstract protected function process($pipe, $job);

    /**
     * @since Method available since Release 3.5.12
     */
    protected function cleanup()
    {
    }

    /**
     * Processes the TestResult object from an isolated process.
     *
     * @param PHPUnit_Framework_TestCase   $test
     * @param PHPUnit_Framework_TestResult $result
     * @param string                       $stdout
     * @param string                       $stderr
     * @since Method available since Release 3.5.0
     */
    protected function processChildResult(PHPUnit_Framework_Test $test, PHPUnit_Framework_TestResult $result, $stdout, $stderr)
    {
        if (!empty($stderr)) {
            $time = 0;
            $result->addError(
              $test,
              new RuntimeException(trim($stderr)), $time
            );
        } else {
            $childResult = @unserialize($stdout);

            if ($childResult !== FALSE) {
                if (!empty($childResult['output'])) {
                    print $childResult['output'];
                }

                $test->setResult($childResult['testResult']);
                $test->addToAssertionCount($childResult['numAssertions']);

                $childResult = $childResult['result'];

                if ($result->getCollectCodeCoverageInformation()) {
                    $result->getCodeCoverage()->merge(
                      $childResult->getCodeCoverage()
                    );
                }

                $time           = $childResult->time();
                $notImplemented = $childResult->notImplemented();
                $skipped        = $childResult->skipped();
                $errors         = $childResult->errors();
                $failures       = $childResult->failures();

                if (!empty($notImplemented)) {
                    $result->addError(
                      $test, $this->getException($notImplemented[0]), $time
                    );
                }

                else if (!empty($skipped)) {
                    $result->addError(
                      $test, $this->getException($skipped[0]), $time
                    );
                }

                else if (!empty($errors)) {
                    $result->addError(
                      $test, $this->getException($errors[0]), $time
                    );
                }

                else if (!empty($failures)) {
                    $result->addFailure(
                      $test, $this->getException($failures[0]), $time
                    );
                }
            } else {
                $time = 0;

                $result->addError(
                  $test, new RuntimeException(trim($stdout)), $time
                );
            }
        }

        $result->endTest($test, $time);
    }

    /**
     * Gets the thrown exception from a PHPUnit_Framework_TestFailure.
     *
     * @param PHPUnit_Framework_TestFailure $error
     * @since Method available since Release 3.6.0
     * @see   https://github.com/sebastianbergmann/phpunit/issues/74
     */
    protected function getException(PHPUnit_Framework_TestFailure $error)
    {
        $exception = $error->thrownException();

        if ($exception instanceof __PHP_Incomplete_Class) {
            $exceptionArray = array();
            foreach ((array)$exception as $key => $value) {
                $key = substr($key, strrpos($key, "\0") + 1);
                $exceptionArray[$key] = $value;
            }

            $exception = new PHPUnit_Framework_SyntheticError(
              sprintf(
                '%s: %s',
                $exceptionArray['_PHP_Incomplete_Class_Name'],
                $exceptionArray['message']
              ),
              $exceptionArray['code'],
              $exceptionArray['file'],
              $exceptionArray['line'],
              $exceptionArray['trace']
            );
        }

        return $exception;
    }
}
