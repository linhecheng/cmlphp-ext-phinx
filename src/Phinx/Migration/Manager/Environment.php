<?php
/**
 * Phinx
 *
 * (The MIT license)
 * Copyright (c) 2015 Rob Morgan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated * documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package    Phinx
 * @subpackage Phinx\Migration\Manager
 */
namespace Phinx\Migration\Manager;

use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Migration\MigrationInterface;
use Phinx\Seed\SeedInterface;

class Environment
{
    /**
     * 导出是否将多个迁移合并
     *
     * @var mixed
     */
    private static $exportIsMerge = false;

    /**
     * 设置导出路径
     *
     * @var bool|string
     */
    protected static $exportPath = false;

    /**
     * 设置导出文件路径
     *
     * @var bool|string
     */
    protected static $exportFile = false;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var int
     */
    protected $currentVersion;

    /**
     * @var string
     */
    protected $schemaTableName = 'phinxlog';

    /**
     * @var AdapterInterface
     */
    protected $adapter;

    /**
     * Environment constructor.
     * @param $config
     */
    public function __construct($config)
    {
        if (isset($config['migration_use_table']) && !empty($config['migration_use_table'])) {
            $this->setSchemaTableName($config['migration_use_table']);
        }
        $this->config = $config;
    }

    /**
     * Executes the specified migration on this environment.
     *
     * @param MigrationInterface $migration Migration
     * @param string $direction Direction
     * @return void
     */
    public function executeMigration(MigrationInterface $migration, $direction = MigrationInterface::UP)
    {
        $startTime = time();
        $direction = ($direction === MigrationInterface::UP) ? MigrationInterface::UP : MigrationInterface::DOWN;
        $migration->setAdapter($this->getAdapter());

        if ($this->getExportPath()) {
            $this->setExportFile($migration->getName() . '.sql', $direction);
        }

        // begin the transaction if the adapter supports it
        if ($this->getAdapter()->hasTransactions()) {
            $this->getAdapter()->beginTransaction();
        }

        // Run the migration
        if (method_exists($migration, MigrationInterface::CHANGE)) {
            if ($direction === MigrationInterface::DOWN) {
                // Create an instance of the ProxyAdapter so we can record all
                // of the migration commands for reverse playback
                $proxyAdapter = AdapterFactory::instance()
                    ->getWrapper('proxy', $this->getAdapter());
                $migration->setAdapter($proxyAdapter);
                /** @noinspection PhpUndefinedMethodInspection */
                $migration->change();
                $proxyAdapter->executeInvertedCommands();
                $migration->setAdapter($this->getAdapter());
            } else {
                /** @noinspection PhpUndefinedMethodInspection */
                $migration->change();
            }
        } else {
            $migration->{$direction}();
        }

        // commit the transaction if the adapter supports it
        if ($this->getAdapter()->hasTransactions()) {
            $this->getAdapter()->commitTransaction();
        }

        // Record it in the database
        $this->getAdapter()->migrated($migration, $direction, date('Y-m-d H:i:s', $startTime), date('Y-m-d H:i:s', time()));
    }

    /**
     * Executes the specified seeder on this environment.
     *
     * @param SeedInterface $seed
     * @return void
     */
    public function executeSeed(SeedInterface $seed)
    {
        $seed->setAdapter($this->getAdapter());

        if ($this->getExportPath()) {
            $this->setExportFile($seed->getName() . '.sql', 'seed');
        }
        // begin the transaction if the adapter supports it
        if ($this->getAdapter()->hasTransactions()) {
            $this->getAdapter()->beginTransaction();
        }

        // Run the seeder
        if (method_exists($seed, SeedInterface::RUN)) {
            $seed->run();
        }

        // commit the transaction if the adapter supports it
        if ($this->getAdapter()->hasTransactions()) {
            $this->getAdapter()->commitTransaction();
        }
    }

    /**
     * Gets all migrated version numbers.
     *
     * @return array
     */
    public function getVersions()
    {
        return $this->getAdapter()->getVersions();
    }

    /**
     * Get all migration log entries, indexed by version number.
     *
     * @return array
     */
    public function getVersionLog()
    {
        return $this->getAdapter()->getVersionLog();
    }

    /**
     * Sets the current version of the environment.
     *
     * @param int $version Environment Version
     * @return Environment
     */
    public function setCurrentVersion($version)
    {
        $this->currentVersion = $version;
        return $this;
    }

    /**
     * Gets the current version of the environment.
     *
     * @return int
     */
    public function getCurrentVersion()
    {
        // We don't cache this code as the current version is pretty volatile.
        // TODO - that means they're no point in a setter then?
        // maybe we should cache and call a reset() method everytime a migration is run
        $versions = $this->getVersions();
        $version = 0;

        if (!empty($versions)) {
            $version = end($versions);
        }

        $this->setCurrentVersion($version);
        return $this->currentVersion;
    }

    /**
     * Sets the database adapter.
     *
     * @param AdapterInterface $adapter Database Adapter
     * @return Environment
     */
    public function setAdapter(AdapterInterface $adapter)
    {
        $this->adapter = $adapter;
        return $this;
    }

    /**
     * Gets the database adapter.
     *
     * @return AdapterInterface
     */
    public function getAdapter()
    {
        if (isset($this->adapter)) {
            return $this->adapter;
        }

        $config = $this->config;
        $config = isset($config['migration_use_db']) ? $config[$config['migration_use_db']] : $config['default_db'];
        $driver = strtolower(substr($config['driver'], 0, strpos($config['driver'], '.')));
        $config = $config['master'];

        $host = explode(':', $config['host']);
        $option = [
            'host' => $host[0],
            'name' => $config['dbname'],
            'charset' => $config['charset'],
            'user' => $config['username'],
            'pass' => $config['password'],
            'engine' => $config['engine'],
            'table_prefix' => $config['tableprefix'],
            'default_migration_table' => $this->getSchemaTableName()
        ];
        isset($host[1]) && $option['port'] = $host[1];

        $adapter = AdapterFactory::instance()
            ->getAdapter($driver, $option);

        // Use the TablePrefixAdapter if table prefix/suffixes are in use
        if ($adapter->hasOption('table_prefix') || $adapter->hasOption('table_suffix')) {
            $adapter = AdapterFactory::instance()
                ->getWrapper('prefix', $adapter);
        }

        $this->setAdapter($adapter);

        return $adapter;
    }

    /**
     * Sets the schema table name.
     *
     * @param string $schemaTableName Schema Table Name
     * @return Environment
     */
    public function setSchemaTableName($schemaTableName)
    {
        $this->schemaTableName = $schemaTableName;
        return $this;
    }

    /**
     * Gets the schema table name.
     *
     * @return string
     */
    public function getSchemaTableName()
    {
        return $this->schemaTableName;
    }

    /**
     * 获取导出路径
     *
     * @return string|bool
     */
    public function getExportPath()
    {
        return self::$exportPath;
    }

    /**
     * 设置导出路径
     *
     * @param string $export 导出的路径
     * @param bool $merge 是否将本次执行合并成一个文件输出
     *
     * @return $this
     */
    public function setExportPath($export, $merge = false)
    {
        self::$exportIsMerge = $merge;
        self::$exportPath = $export;
        return $this;
    }

    /**
     * 设置导出文件名
     *
     * @param string $migrate 迁移的名称
     * @param string $type 类型
     *
     * @return $this
     */
    private function setExportFile($migrate, $type = 'up')
    {
        self::$exportFile = self::$exportPath . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;
        is_dir(self::$exportFile) || mkdir(self::$exportFile, 0700, true);
        self::$exportFile .= self::$exportIsMerge ? self::$exportIsMerge : $migrate;
        self::$exportIsMerge || file_put_contents(self::$exportFile, "#start\n");
        return $this;
    }

    /**
     * 导出sql到文本
     *
     * @param string $sql 要写入的sql
     * @param string $table 迁移表的表名
     */
    public static function exportSql($sql, $table)
    {
        if (
            self::$exportFile
            && false === stripos($sql, $table)
            && false === stripos($sql, 'INFORMATION_SCHEMA')
            && false === stripos($sql, 'SHOW INDEXES FROM')
        ) {
            file_put_contents(self::$exportFile, rtrim($sql, ';') . ";\n", FILE_APPEND);
        }
    }

    /**
     * 导出sql到文本
     *
     * @return bool
     */
    public static function isExport()
    {
        return !empty(self::$exportFile);
    }
}
