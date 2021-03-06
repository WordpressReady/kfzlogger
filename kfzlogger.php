<?php
/**
 * kfzlogger: almost same as Klogger with improvements to make it simpler to initialize and better handling.
 * 20210904: I found var_export would do almost the same as an old function that analyzes and displays
 * objects and arrays. Therefore it seems that function is not needed any longer.
 * 20210905: DOCUMENTATION AT THE END OF THE CLASS
 */

if(!class_exists('kfzlogger')) exit(0); //Avoid if brackets sourrounding the class definition

class kfzlogger
{
    /**
     * Error severity, from low to high. From BSD syslog RFC, secion 4.1.1
     * @link http://www.faqs.org/rfcs/rfc3164.html
     */
    const EMERG  = 0;  // Emergency: system is unusable
    const ALERT  = 1;  // Alert: action must be taken immediately
    const CRIT   = 2;  // Critical: critical conditions
    const ERR    = 3;  // Error: error conditions
    const WARN   = 4;  // Warning: warning conditions
    const NOTICE = 5;  // Notice: normal but significant condition
    const INFO   = 6;  // Informational: informational messages
    const DEBUG  = 7;  // Debug: debug messages

    //custom logging level
    /**
     * Log nothing at all
     */
    const OFF    = 8;
    /**
     * Alias for CRIT
     * @deprecated
     */
    const FATAL  = 2;

    /**
     * Internal status codes
     */
    const STATUS_LOG_OPEN    = 1;
    const STATUS_OPEN_FAILED = 2;
    const STATUS_LOG_CLOSED  = 3;

    /**
     * Current status of the log file
     * @var integer
     */
    private $_logStatus         = self::STATUS_LOG_CLOSED;

    /**
     * Holds messages generated by the class
     * @var array
     */
    private $_messageQueue      = array();

    /**
     * Path to the log file
     * @var string
     */

    private $_logFilePath       = null;
    /**
     * Current minimum logging threshold.
     * FZSM: Default value if nothing is passed to the constructor
     * @var integer
     */

    private $_severityThreshold = self::INFO;
    /**
     * This holds the file handle for this instance's log file
     * @var resource
     */
    private $_fileHandle        = null;

    /**
     * Standard messages produced by the class. Can be modified for il8n
     * @var array
     */
    private $_messages = array(
        'writefail'   => 'The file could not be written to. Check that appropriate permissions have been set.',
        'opensuccess' => 'The log file was opened successfully.',
        'openfail'    => 'The file could not be opened. Check permissions.',
    );

    /**
     * Valid PHP date() format string for log timestamps
     * @var string
     */
    private static $_dateFormat         = 'Y-m-d G:i:s';

    /**
     * Octal notation for default permissions of the log file
     * @var integer
     */
    private static $_defaultPermissions = 0777;

    /**
     * Class constructor
     * FZSM: improved, taking the concepts from the static instance technique
     * Now it's possible launching default class , it will start logging
     * in the current directory and INFO as default parameters.
     * This is the very usual way of working.
     *
     * @param string  $logDirectory File path to the logging directory
     * @param integer $severity     One of the pre-defined severity constants
     * @return void
     */
    public function __construct($logDirectory = false, $severity = false)
    {
        if ($severity === false)  $severity = $this->_severityThreshold;
        if ($severity === self::OFF) return;

        if ($logDirectory === false) {
                $logDirectory = dirname(__FILE__); //use current dir to create log file.
        }

        $logDirectory = rtrim($logDirectory, '\\/');

        $this->_logFilePath = $logDirectory
            . DIRECTORY_SEPARATOR
            . 'log_'
            . date('Y-m-d')
            . '.txt';

        $this->_severityThreshold = $severity;
        if (!file_exists($logDirectory)) {
            mkdir($logDirectory, self::$_defaultPermissions, true);
        }

        if (file_exists($this->_logFilePath) && !is_writable($this->_logFilePath)) {
            $this->_logStatus = self::STATUS_OPEN_FAILED;
            $this->_messageQueue[] = $this->_messages['writefail'];
            return;
        }

        if (($this->_fileHandle = fopen($this->_logFilePath, 'a'))) {
            $this->_logStatus = self::STATUS_LOG_OPEN;
            $this->_messageQueue[] = $this->_messages['opensuccess'];
        } else {
            $this->_logStatus = self::STATUS_OPEN_FAILED;
            $this->_messageQueue[] = $this->_messages['openfail'];
        }
    }

    /**
     * Class destructor
     */
    public function __destruct()
    {
        if ($this->_fileHandle)  fclose($this->_fileHandle);
    }

    /**
     * Returns (and removes) the last message from the queue.
     * @return string
     */
    public function getMessage()
    {
        return array_pop($this->_messageQueue);
    }

    /**
     * Returns the entire message queue (leaving it intact)
     * @return array
     */
    public function getMessages()
    {
        return $this->_messageQueue;
    }

    /**
     * Empties the message queue
     * @return void
     */
    public function clearMessages()
    {
        $this->_messageQueue = array();
    }

    /**
     * Sets the date format used by all instances of KLogger
     * 
     * @param string $dateFormat Valid format string for date()
     */
    public static function setDateFormat($dateFormat)
    {
        self::$_dateFormat = $dateFormat;
    }

    /**
     * Sends multiple information (default INFO status) to the file log.
     * @param mixed ...$args
     */
    public function logInfo(...$args)
    {
        //$this->log(kfzlogger::DEBUG,$args); NOPE!
        //This does not work, since it does not pass all the args, but an only one element, which is
        //1 only array with all parameters inside. This is not what I expected...
        //The solution came from here https://www.php.net/manual/en/function.call-user-func-array.php
        //ALSO, we cannot call a class method directly.
        //I mean this will also fail: call_user_func_array('log',$merge);
        //We need to specify what class and method we are going to call.
        //Again no trivial at all.

        $merge = array_merge(array(kfzlogger::INFO), $args);
        call_user_func_array(array($this,'log'),$merge);
    }

    /**
     * given a severity value and multiple args, send every arg to the log file.
     * @param $severity
     * @param $args
     */
    public function log($severity, ...$args)
    {
        if ($this->_severityThreshold < $severity) return;

        $status = $this->_getTimeLine($severity);

        if (count($args)==0) {
            $this->writeFreeFormLine($status . PHP_EOL);
            return;
        }

        for ($i=0;$i<count($args);$i++){
            $this->writeFreeFormLine($status . var_export($args[$i],true) . PHP_EOL);
        }
    }

    /**
     * Writes a line to the log without prepending a status or timestamp
     *
     * @param string $line Line to write to the log
     * @return void
     */
    public function writeFreeFormLine($line)
    {
        if ($this->_logStatus == self::STATUS_LOG_OPEN
            && $this->_severityThreshold != self::OFF) {
            if (fwrite($this->_fileHandle, $line) === false) {
                $this->_messageQueue[] = $this->_messages['writefail'];
            }
        }
    }

    private function _getTimeLine($level)
    {
        $time = date(self::$_dateFormat);
        switch ($level) {
            case self::EMERG:   return "$time - EMERG -->";
            case self::ALERT:   return "$time - ALERT -->";
            case self::CRIT:    return "$time - CRIT -->";
            case self::FATAL:   return "$time - FATAL -->";
            case self::NOTICE:  return "$time - NOTICE -->";
            case self::INFO:    return "$time - INFO -->";
            case self::WARN:    return "$time - WARN -->";
            case self::DEBUG:   return "$time - DEBUG -->";
            case self::ERR:     return "$time - ERROR -->";
            default:            return "$time - LOG -->";
        }
    }
}

/**
 * Partially implements the Singleton pattern.
 * Each $logDirectory gets one instance.
 * 20210905: Suppressed. Notice this is a particular way of handling
 *  that I not usually take advantage (notice is static)
 * FZSM: Default severity threshold changed to INFO if the constructor is empty.
 *
 * @param string  $logDirectory File path to the logging directory
 * @param integer $severity     One of the pre-defined severity constants
 * @return kfzlogger
 */

/*********START OF SUPRESSED CODE********************************/
/*
public static function instance($logDirectory = false, $severity = false)
{
    if ($severity === false)  $severity = self::$_severityThreshold;

    if ($logDirectory === false) {
        if (count(self::$instances) > 0) {
            return current(self::$instances);
        } else {
            $logDirectory = dirname(__FILE__); //use current dir to create log file.
        }
    }

    if (in_array($logDirectory, self::$instances)) {
        return self::$instances[$logDirectory];
    }

    self::$instances[$logDirectory] = new self($logDirectory, $severity);

    return self::$instances[$logDirectory];
}
*/

/**
 * Array of KLogger instances, part of Singleton pattern
 * @var array
 */
/*
private static $instances           = array();
*/
/*************END OF SUPPRESSED CODE**************************************/
/**DOCUMENTATION
 *
 * 20210904: FZSM DOCUMENTATION HERE
 * Usage: if outside a class:
 * $log = new kfzlogger('/var/log/', KLogger::INFO);
 * $log->logInfo('Returned a million search results'); //Prints to the log file
 *
 * The class now handles multiples variables at once, on in a separate line
 * Original version IGNORED additional parameters and also gives NO error about it and print nothing.
 *
 * The class was HEAVILY simplified, since most of the code won't be used in the normal debugging.
 * ONLY TWO METHODS are now implemented (instead of 9: logInfo(vars) and log (errorLevel,vars)
 *
 * $log->logInfo('Oh dear.',$a,%b,$c); //send to file with INFO level multiples variables values.
 * $log->log(kfzlogger::ERR,'x = 5');   //send to file with ERR level

 * const values:
 * EMERG=0, ALERT=1, CRIT=2, ERR=3, WARN=4, NOTICE=5, INFO=6, DEBUG=7
 * DEBUG is highest level, anything above or equal to DEBUG (7) won't print anything
 * This can be used to temporarily prevent for printing
 * $log->log(kfzlogger::DEBUG,'x = 5'); //Prints NOTHING because the current severity threshold
 *
 * We can initialize WITHOUT parameters
 * $logger = new kfzlogger(); //default values: save log in current directory, INFO as default
 * or  $this->logger = new kfzlogger();
 *
 * What is the SAME as
 * $logger = new kfzlogger(plugin_dir_path(__FILE__), kfzlogger::INFO);
 * OR
 * $logger = new kfzlogger(dirname(__FILE__), kfzlogger::INFO);
 *
 * if inside a class
 * private logger;
 * ....
 * $this->logger = new kfzlogger('/var/log/', KLogger::INFO);
 * $this->logger->logInfo('etc');
 *
 * NOTES:
 * 1 - the constructor (aka default parameters) was improved using of
 * the static instance implementation (now deactivated).
 * I usually don't work with multiples instance, since this tool's purpose is for SIMPLE , packaged use.
 * If I found that multiple instance is useful, I will add the code again.
 *
 * 2 - If directory given does not exist, the class will try to make one.
 *
 * 3 - If we use $logger->getMessages() it will return an ARRAY of file actions and statuses.
 *     getMessage() (singular) will return the last message from the array and delete it
 *     clearMessages() will erase the info array.
 * I wonder If I will ever use it?
 *
 * 4 - passing kfzlogger::OFF or 8 in the constructor, the class will do NOTHING (?)
 * $this->logger = new kfzlogger("whatever",8);
 * $this->logger = new kfzlogger("whatever", kfzlogger::OFF);
 * I wonder WHY would I use it?
 * The OFF values is used (with DEBUG) to deactivate sending messages when working with debugging
 * ergo $this->loogger (kfzlogger::OFF, $a,$b), won't send anything to the log file.
 * I HEAVILY RECOMMEND using kfzlogger::OFF than DEBUG, it is clearer.
 *
 * @author  Kenny Katzgrau <katzgrau@gmail.com> & Fernando Zorrilla de San Martin (fernando.zorrlla@gmail.com)
 * @since   July 26, 2008 ??? Last update July 1, 2012 - September 05, 2021
 * @link    http://codefury.net , http://wpgetready.com
 * @version 0.2.1
 */
