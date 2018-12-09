<?php
/**
 * This file is part of Swoft.
 *
 * @link     https://swoft.org
 * @document https://doc.swoft.org
 * @contact  group@swoft.org
 * @license  https://github.com/swoft-cloud/swoft/blob/master/LICENSE
 */
namespace Swoft\Log;

use Monolog\Handler\AbstractProcessingHandler;
use Swoft\App;
use Swoole\Coroutine;

/**
 * 日志文件输出器
 *
 * @uses      FileHandler
 * @version   2017年07月05日
 * @author    stelin <phpcrazy@126.com>
 * @copyright Copyright 2010-2016 Swoft software
 * @license   PHP Version 7.x {@link http://www.php.net/license/3_0.txt}
 */
class FileHandler extends AbstractProcessingHandler
{
    /**
     * @var array 输出包含日志级别集合
     */
    protected $levels = [];

    /**
     * @var string 输入日志文件名称
     */
    protected $logFile = '';

    /**
     * 批量输出日志
     *
     * @param array $records 日志记录集合
     *
     * @return bool
     */
    public function handleBatch(array $records)
    {
        $records = $this->recordFilter($records);
        if (empty($records)) {
            return true;
        }

        $lines = array_column($records, 'formatted');

        $this->write($lines);
    }

    /**
     * 输出到文件
     *
     * @param array $records 日志记录集合
     */
    protected function write(array $records)
    {
        // 参数
        $this->createDir();
        $logFile = App::getAlias($this->logFile);
        $messageText = implode("\n", $records) . "\n";

        if (App::isCoContext()) {
            // 协程写
            $this->coWrite($logFile, $messageText);
        } else {
            $this->syncWrite($logFile, $messageText);
        }
    }

    /**
     * 协程写文件
     *
     * @param string $logFile     日志路径
     * @param string $messageText 文本信息
     */
    private function coWrite(string $logFile, string $messageText)
    {
        go(function () use ($logFile, $messageText) {
            $res = Coroutine::writeFile($logFile, $messageText, FILE_APPEND);
            if ($res === false) {
                throw new \InvalidArgumentException("Unable to append to log file: {$this->logFile}");
            }
        });
    }

    /**
     * 同步写文件
     *
     * @param string $logFile     日志路径
     * @param string $messageText 文本信息
     */
    private function syncWrite(string $logFile, string $messageText)
    {
        $fp = fopen($logFile, 'a');
        if ($fp === false) {
            throw new \InvalidArgumentException("Unable to append to log file: {$this->logFile}");
        }
        flock($fp, LOCK_EX);
        fwrite($fp, $messageText);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * 记录过滤器
     *
     * @param array $records 日志记录集合
     *
     * @return array
     */
    private function recordFilter(array $records)
    {
        $messages = [];
        foreach ($records as $record) {
            if (!isset($record['level'])) {
                continue;
            }
            if (!$this->isHandling($record)) {
                continue;
            }

            $record = $this->processRecord($record);
            $record['formatted'] = $this->getFormatter()->format($record);

            $messages[] = $record;
        }
        return $messages;
    }

    /**
     * 创建目录
     */
    private function createDir()
    {
        $logFile = App::getAlias($this->logFile);
        $dir = dirname($logFile);
        if ($dir !== null && !is_dir($dir)) {
            $status = mkdir($dir, 0777, true);
            if ($status === false) {
                throw new \UnexpectedValueException(sprintf('There is no existing directory at "%s" and its not buildable: ', $dir));
            }
        }
    }

    /**
     * check是否输出日志
     *
     * @param array $record
     *
     * @return bool
     */
    public function isHandling(array $record)
    {
        if (empty($this->levels)) {
            return true;
        }

        return in_array($record['level'], $this->levels);
    }
}
