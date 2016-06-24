<?php

namespace TiGR\DatabaseLogger;

class Backtracer
{
    private $includeDirs = [];
    private $excludeDirs = [];

    public function __construct()
    {
        $this->addExcludeDir(__DIR__);
    }

    public function addExcludeDir($dir)
    {
        $this->excludeDirs[] = $dir;
    }

    public function addIncludeDir($namespace)
    {
        $this->includeDirs[] = $namespace;
    }

    /**
     * Returns array of string representations of backtrace steps.
     *
     * @param int $maxDepth
     * @return array
     */
    public function getBacktrace($maxDepth = 10)
    {
        $result = [];

        foreach ($this->getRawBacktrace($maxDepth) as $trace) {
            $file = (isset($trace['file']) ? basename(substr($trace['file'], strlen(getcwd()) + 1)) : '');
            $function = (isset($trace['function']) ? $trace['function'] : '');
            if (isset($trace['class'])) {
                $class = explode("\\", basename($trace['class']));
                $class = array_pop($class);
                $result[] = sprintf('%s:%d %s%s%s()', $file, isset($trace['line']) ? $trace['line'] : '', $class, $trace['type'], $function);
            } else {
                $result[] = $file . ':' . $trace['line'];
            }
        }

        return $result;
    }

    /**
     * Returns filtered backtrace as is.
     *
     * @param int $maxDepth
     * @return array
     */
    public function getRawBacktrace($maxDepth = 10)
    {
        $backtrace = debug_backtrace(0);
        $result = [];
        foreach ($backtrace as $trace) {
            if (in_array($trace['function'], ['__call', '__callStatic', 'call_user_func', 'call_user_func_array']) or !isset($trace['line'])) {
                continue;
            }
            if (isset($trace['file'])) {
                if (!empty($this->excludeDirs)) {
                    foreach ($this->excludeDirs as $dir) {
                        if (strpos($trace['file'], $dir) === 0) {
                            continue 2;
                        }
                    }
                }
                if (!empty($this->includeDirs)) {
                    $match = false;
                    foreach ($this->includeDirs as $dir) {
                        if (strpos($trace['file'], $dir) === 0) {
                            $match = true;
                        }
                    }

                    if (!$match) {
                        continue;
                    }
                }
            }
            $result[] = $trace;
            if (--$maxDepth == 0) {
                break;
            }
        }

        return $result;
    }
}
