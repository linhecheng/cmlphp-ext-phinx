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
 * @subpackage Phinx\Config
 */
namespace Phinx\Config;

use Cml\Cml;
use Cml\Console\Format\Colour;
use Cml\Console\Format\Format;
use Cml\Console\IO\Output;


/**
 * Phinx configuration class.
 *
 * @package Phinx
 * @author Rob Morgan
 */
class Config implements \ArrayAccess
{
    /**
     * @var array
     */
    private $values = [];

    /**
     * Config constructor.
     *
     * @param string $env
     */
    public function __construct($env = 'development')
    {
        $appConfig = Cml::getApplicationDir('global_config_path') . DIRECTORY_SEPARATOR . $env . DIRECTORY_SEPARATOR . 'normal.php';
        $this->values = Cml::requireFile($appConfig);
    }

    /**
     * 返回迁移文件存放路径
     *
     * @return bool|string
     */
    public function getMigrationPath()
    {
        $dir = Cml::getApplicationDir('migration_path');
        if ($dir) {
            return $dir;
        } else {
            return Cml::getApplicationDir('secure_src') . DIRECTORY_SEPARATOR . 'databases' . DIRECTORY_SEPARATOR . 'migrations';
        }
    }

    /**
     * 显示数据库信息
     *
     */
    public function echoAdapterInfo()
    {
        $config = isset($this->values['migration_use_db']) ? $this->values[$this->values['migration_use_db']] : $this->values['default_db'];

        $format = new Format(['foregroundColors' => Colour::GREEN]);
        $driver = explode('.', $config['driver']);
        Output::writeln('using adapter ' . $format->format($driver[0]));
        Output::writeln('using database ' . $format->format($config['master']['dbname']));
        Output::writeln('using table prefix ' . $format->format($config['master']['tableprefix']));
        if (isset($this->values['migration_use_table']) && !empty($this->values['migration_use_table'])) {
            Output::writeln('using migration table ' . $format->format($this->values['migration_use_table']));
        } else {
            Output::writeln('using migration table ' . $format->format('phinxlog'));
        }
    }

    /**
     * Gets the base class name for migrations.
     *
     * @param boolean $dropNamespace Return the base migration class name without the namespace.
     * @return string
     */
    public function getMigrationBaseClassName($dropNamespace = true)
    {
        $className = !isset($this->values['migration_base_class']) ? 'Phinx\Migration\AbstractMigration' : $this->values['migration_base_class'];

        return $dropNamespace ? substr(strrchr($className, '\\'), 1) : $className;
    }

    /**
     * 返回seed文件存放路径
     *
     * @return bool|string
     */
    public function getSeedPath()
    {
        $dir = Cml::getApplicationDir('seed_path');
        if ($dir) {
            return $dir;
        } else {
            return Cml::getApplicationDir('secure_src') . DIRECTORY_SEPARATOR . 'databases' . DIRECTORY_SEPARATOR . 'seeds';
        }
    }

    /**
     * 返回导出的sql文件存放路径
     *
     * @return bool|string
     */
    public function getExportPath()
    {
        $dir = Cml::getApplicationDir('migration_export_path');
        if ($dir) {
            return $dir;
        } else {
            return Cml::getApplicationDir('secure_src') . DIRECTORY_SEPARATOR . 'databases' . DIRECTORY_SEPARATOR . 'sql';
        }
    }

    /**
     * {@inheritdoc}
     */
    public function offsetSet($id, $value)
    {
        $this->values[$id] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function offsetGet($id)
    {
        if (!array_key_exists($id, $this->values)) {
            throw new \InvalidArgumentException(sprintf('Identifier "%s" is not defined.', $id));
        }

        return $this->values[$id] instanceof \Closure ? $this->values[$id]($this) : $this->values[$id];
    }

    /**
     * {@inheritdoc}
     */
    public function offsetExists($id)
    {
        return isset($this->values[$id]);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($id)
    {
        unset($this->values[$id]);
    }
}
