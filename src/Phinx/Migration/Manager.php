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
 * @subpackage Phinx\Migration
 */
namespace Phinx\Migration;

use Cml\Console\Format\Colour;
use Cml\Console\IO\Output;
use Phinx\Config\Config;
use Phinx\Migration\Manager\Environment;
use Phinx\Seed\AbstractSeed;
use Phinx\Seed\SeedInterface;
use Phinx\Util\Util;

class Manager
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var array
     */
    protected $environments;

    /**
     * @var array
     */
    protected $migrations;

    /**
     * @var array
     */
    protected $seeds;

    /**
     * @var integer
     */
    const EXIT_STATUS_DOWN = 1;

    /**
     * @var integer
     */
    const EXIT_STATUS_MISSING = 2;

    /**
     * Class Constructor.
     *
     * @param Config $config Configuration Object
     * @param array $args
     * @param array $options
     */
    public function __construct(Config $config, $args, $options)
    {
        $this->setConfig($config);
    }

    /**
     * Prints the specified environment's migration status.
     *
     * @param null $format
     * @return integer 0 if all migrations are up, or an error code
     */
    public function printStatus($format = null)
    {
        $migrations = array();
        $hasDownMigration = false;
        $hasMissingMigration = false;
        is_null($format) && $format = 'text';

        $env = $this->getEnvironment();
        $versions = $env->getVersionLog();

        if ($format == 'text') {
            if (count($this->getMigrations())) {
                // included and it will fix formatting issues (e.g drawing the lines)
                Output::writeln('');
                Output::writeln(' Status  Migration ID    Started              Finished             Migration Name ');
                Output::writeln('----------------------------------------------------------------------------------');

                $maxNameLength = $versions ? max(array_map(function ($version) {
                    return strlen($version['migration_name']);
                }, $versions)) : 0;

                foreach ($this->getMigrations() as $migration) {
                    $version = array_key_exists($migration->getVersion(), $versions) ? $versions[$migration->getVersion()] : false;
                    if ($version) {
                        $status = Colour::colour('     up ', Colour::GREEN);
                    } else {
                        $hasDownMigration = true;
                        $status = Colour::colour('   down ', Colour::RED);
                    }
                    $maxNameLength = max($maxNameLength, strlen($migration->getName()));

                    Output::writeln(sprintf(
                        '%s %14.0f  %19s  %19s  ' . Colour::colour('%s', Colour::CYAN),
                        $status, $migration->getVersion(), $version['start_time'], $version['end_time'], $migration->getName()
                    ));

                    if ($version && $version['breakpoint']) {
                        Output::writeln(Colour::colour('         BREAKPOINT SET', Colour::RED));
                    }

                    $migrations[] = [
                        'migration_status' => trim(strip_tags($status)), 'migration_id' => sprintf('%14.0f', $migration->getVersion()), 'migration_name' => $migration->getName()
                    ];
                    unset($versions[$migration->getVersion()]);
                }

                if (count($versions)) {
                    $hasMissingMigration = true;
                    foreach ($versions as $missing => $version) {
                        Output::writeln(sprintf(
                            Colour::colour('     up', Colour::RED) . '  %14.0f  %19s  %19s  ' . Colour::colour('%s', Colour::CYAN) . Colour::colour('  ** MISSING **', Colour::RED),
                            $missing, $version['start_time'], $version['end_time'], str_pad($version['migration_name'], $maxNameLength, ' ')
                        ));

                        if ($version && $version['breakpoint']) {
                            Output::writeln(Colour::colour('         BREAKPOINT SET', Colour::RED));
                        }
                    }
                }
            } else {
                // there are no migrations
                Output::writeln('');
                Output::writeln('There are no available migrations. Try creating one using the ' . Colour::colour('create', Colour::GREEN) . ' command.');
            }
        } else {
            Output::writeln('');
            switch ($format) {
                case 'json':
                    foreach ($this->getMigrations() as $migration) {
                        $version = array_key_exists($migration->getVersion(), $versions) ? $versions[$migration->getVersion()] : false;
                        $status = $version ? 'up' : 'down';
                        $migrations[] = [
                            'migration_status' => trim(strip_tags($status)), 'migration_id' => sprintf('%14.0f', $migration->getVersion()), 'migration_name' => $migration->getName()
                        ];
                    }
                    Output::writeln(json_encode(
                        array(
                            'pending_count' => count($migrations),
                            'migrations' => $migrations
                        )
                    ));
                    break;
                default:
                    Output::writeln(Colour::colour('Unsupported format: ' . $format, Colour::RED));
            }
        }

        // write an empty line
        Output::writeln('');
        if ($hasMissingMigration) {
            return self::EXIT_STATUS_MISSING;
        } else if ($hasDownMigration) {
            return self::EXIT_STATUS_DOWN;
        } else {
            return 0;
        }
    }

    /**
     * Migrate to the version of the database on a given date.
     *
     * @param \DateTime $dateTime Date to migrate to
     *
     * @return void
     */
    public function migrateToDateTime(\DateTime $dateTime)
    {
        $versions = array_keys($this->getMigrations());
        $dateString = $dateTime->format('YmdHis');

        $outstandingMigrations = array_filter($versions, function ($version) use ($dateString) {
            return $version <= $dateString;
        });

        if (count($outstandingMigrations) > 0) {
            $migration = max($outstandingMigrations);
            Output::writeln('Migrating to version ' . $migration);
            $this->migrate($migration);
        }
    }

    /**
     * Roll back to the version of the database on a given date.
     *
     * @param \DateTime $dateTime Date to roll back to
     * @param bool $force
     *
     * @return void
     */
    public function rollbackToDateTime(\DateTime $dateTime, $force = false)
    {
        $env = $this->getEnvironment();
        $versions = $env->getVersions();
        $dateString = $dateTime->format('YmdHis');
        sort($versions);

        $earlierVersion = null;
        $availableMigrations = array_filter($versions, function ($version) use ($dateString, &$earlierVersion) {
            if ($version <= $dateString) {
                $earlierVersion = $version;
            }
            return $version >= $dateString;
        });

        if (count($availableMigrations) > 0) {
            if (is_null($earlierVersion)) {
                Output::writeln('Rolling back all migrations');
                $migration = 0;
            } else {
                Output::writeln('Rolling back to version ' . $earlierVersion);
                $migration = $earlierVersion;
            }
            $this->rollback($migration, $force);
        }
    }

    /**
     * Migrate an environment to the specified version.
     *
     * @param int $version
     * @return void
     */
    public function migrate($version = null)
    {
        $migrations = $this->getMigrations();
        $env = $this->getEnvironment();
        $versions = $env->getVersions();
        $current = $env->getCurrentVersion();

        if (empty($versions) && empty($migrations)) {
            return;
        }

        if (null === $version) {
            $version = max(array_merge($versions, array_keys($migrations)));
        } else {
            if (0 != $version && !isset($migrations[$version])) {
                Output::writeln(sprintf(
                    Colour::colour('warning', Colour::RED) . ' %s is not a valid version',
                    $version
                ));
                return;
            }
        }

        // are we migrating up or down?
        $direction = $version > $current ? MigrationInterface::UP : MigrationInterface::DOWN;

        if ($direction === MigrationInterface::DOWN) {
            // run downs first
            krsort($migrations);
            foreach ($migrations as $migration) {
                if ($migration->getVersion() <= $version) {
                    break;
                }

                if (in_array($migration->getVersion(), $versions)) {
                    $this->executeMigration($migration, MigrationInterface::DOWN);
                }
            }
        }

        ksort($migrations);
        foreach ($migrations as $migration) {
            if ($migration->getVersion() > $version) {
                break;
            }

            if (!in_array($migration->getVersion(), $versions)) {
                $this->executeMigration($migration, MigrationInterface::UP);
            }
        }
    }

    /**
     * Execute a migration against the specified environment.
     *
     * @param MigrationInterface $migration Migration
     * @param string $direction Direction
     * @return void
     */
    public function executeMigration(MigrationInterface $migration, $direction = MigrationInterface::UP)
    {
        Output::writeln('');
        Output::writeln(
            ' =='
            . Colour::colour($migration->getVersion() . ' ' . $migration->getName() . ':', Colour::CYAN)
            . Colour::colour($direction === MigrationInterface::UP ? 'migrating' : 'reverting', Colour::GREEN)
        );

        // Execute the migration and log the time elapsed.
        $start = microtime(true);
        $this->getEnvironment()->executeMigration($migration, $direction);
        $end = microtime(true);

        Output::writeln(
            ' =='
            . Colour::colour($migration->getVersion() . ' ' . $migration->getName() . ':', Colour::CYAN)
            . Colour::colour($direction === MigrationInterface::UP ? 'migrated' : 'reverted'
                . ' ' . sprintf('%.4fs', $end - $start), Colour::GREEN)
        );
    }

    /**
     * Execute a seeder against the specified environment.
     *
     * @param SeedInterface $seed Seed
     * @return void
     */
    public function executeSeed(SeedInterface $seed)
    {
        Output::writeln('');
        Output::writeln(
            ' =='
            . Colour::colour($seed->getName() . ':', Colour::CYAN)
            . Colour::colour('seeding', Colour::GREEN)
        );

        // Execute the seeder and log the time elapsed.
        $start = microtime(true);
        $this->getEnvironment()->executeSeed($seed);
        $end = microtime(true);

        Output::writeln(
            ' =='
            . Colour::colour($seed->getName() . ':', Colour::CYAN)
            . Colour::colour(' seeded'
                . ' ' . sprintf('%.4fs', $end - $start), Colour::GREEN)
        );
    }

    /**
     * Rollback an environment to the specified version.
     *
     * @param int $version
     * @param bool $force
     * @return void
     */
    public function rollback($version = null, $force = false)
    {
        $migrations = $this->getMigrations();
        $versionLog = $this->getEnvironment()->getVersionLog();
        $versions = array_keys($versionLog);

        ksort($migrations);
        sort($versions);

        // Check we have at least 1 migration to revert
        if (empty($versions) || $version == end($versions)) {
            Output::writeln(Colour::colour('No migrations to rollback', Colour::RED));
            return;
        }

        // If no target version was supplied, revert the last migration
        if (null === $version) {
            // Get the migration before the last run migration
            $prev = count($versions) - 2;
            $version = $prev < 0 ? 0 : $versions[$prev];
        } else {
            // Get the first migration number
            $first = $versions[0];

            // If the target version is before the first migration, revert all migrations
            if ($version < $first) {
                $version = 0;
            }
        }

        // Check the target version exists
        if (0 !== $version && !isset($migrations[$version])) {
            Output::writeln(Colour::colour("Target version ($version) not found", Colour::RED));
            return;
        }

        // Revert the migration(s)
        krsort($migrations);
        foreach ($migrations as $migration) {
            if ($migration->getVersion() <= $version) {
                break;
            }

            if (in_array($migration->getVersion(), $versions)) {
                if (isset($versionLog[$migration->getVersion()]) && 0 != $versionLog[$migration->getVersion()]['breakpoint'] && !$force) {
                    Output::writeln(Colour::colour('Breakpoint reached. Further rollbacks inhibited.', Colour::RED));
                    break;
                }
                $this->executeMigration($migration, MigrationInterface::DOWN);
            }
        }
    }

    /**
     * Run database seeders against an environment.
     *
     * @param string $seed Seeder
     * @return void
     */
    public function seed($seed = null)
    {
        $seeds = $this->getSeeds();

        if (null === $seed) {
            // run all seeders
            foreach ($seeds as $seeder) {
                if (array_key_exists($seeder->getName(), $seeds)) {
                    $this->executeSeed($seeder);
                }
            }
        } else {
            // run only one seeder
            if (array_key_exists($seed, $seeds)) {
                $this->executeSeed($seeds[$seed]);
            } else {
                throw new \InvalidArgumentException(sprintf('The seed class "%s" does not exist', $seed));
            }
        }
    }

    /**
     * Sets the environments.
     *
     * @param array $environments Environments
     * @return Manager
     */
    public function setEnvironments($environments = array())
    {
        $this->environments = $environments;
        return $this;
    }

    /**
     * Gets the manager class for the given environment.
     *
     * @throws \InvalidArgumentException
     * @return Environment
     */
    public function getEnvironment()
    {
        return new Environment($this->getConfig());
    }

    /**
     * Sets the database migrations.
     *
     * @param array $migrations Migrations
     * @return Manager
     */
    public function setMigrations(array $migrations)
    {
        $this->migrations = $migrations;
        return $this;
    }

    /**
     * Gets an array of the database migrations.
     *
     * @throws \InvalidArgumentException
     * @return AbstractMigration[]
     */
    public function getMigrations()
    {
        if (null === $this->migrations) {
            $config = $this->getConfig();
            $phpFiles = glob($config->getMigrationPath() . DIRECTORY_SEPARATOR . '*.php', defined('GLOB_BRACE') ? GLOB_BRACE : 0);

            // filter the files to only get the ones that match our naming scheme
            $fileNames = array();
            /** @var AbstractMigration[] $versions */
            $versions = array();

            foreach ($phpFiles as $filePath) {
                if (Util::isValidMigrationFileName(basename($filePath))) {
                    $version = Util::getVersionFromFileName(basename($filePath));

                    if (isset($versions[$version])) {
                        throw new \InvalidArgumentException(sprintf('Duplicate migration - "%s" has the same version as "%s"', $filePath, $versions[$version]->getVersion()));
                    }

                    // convert the filename to a class name
                    $class = Util::mapFileNameToClassName(basename($filePath));

                    if (isset($fileNames[$class])) {
                        throw new \InvalidArgumentException(sprintf(
                            'Migration "%s" has the same name as "%s"',
                            basename($filePath),
                            $fileNames[$class]
                        ));
                    }

                    $fileNames[$class] = basename($filePath);

                    // load the migration file
                    /** @noinspection PhpIncludeInspection */
                    require_once $filePath;
                    if (!class_exists($class)) {
                        throw new \InvalidArgumentException(sprintf(
                            'Could not find class "%s" in file "%s"',
                            $class,
                            $filePath
                        ));
                    }

                    // instantiate it
                    $migration = new $class($version);

                    if (!($migration instanceof AbstractMigration)) {
                        throw new \InvalidArgumentException(sprintf(
                            'The class "%s" in file "%s" must extend \Phinx\Migration\AbstractMigration',
                            $class,
                            $filePath
                        ));
                    }

                    $versions[$version] = $migration;
                }
            }

            ksort($versions);
            $this->setMigrations($versions);
        }

        return $this->migrations;
    }

    /**
     * Sets the database seeders.
     *
     * @param array $seeds Seeders
     * @return Manager
     */
    public function setSeeds(array $seeds)
    {
        $this->seeds = $seeds;
        return $this;
    }

    /**
     * Gets an array of database seeders.
     *
     * @throws \InvalidArgumentException
     * @return AbstractSeed[]
     */
    public function getSeeds()
    {
        if (null === $this->seeds) {
            $config = $this->getConfig();
            $phpFiles = glob($config->getSeedPath() . DIRECTORY_SEPARATOR . '*.php');

            // filter the files to only get the ones that match our naming scheme
            $fileNames = array();
            /** @var AbstractSeed[] $seeds */
            $seeds = array();

            foreach ($phpFiles as $filePath) {
                if (Util::isValidSeedFileName(basename($filePath))) {
                    // convert the filename to a class name
                    $class = pathinfo($filePath, PATHINFO_FILENAME);
                    $fileNames[$class] = basename($filePath);

                    // load the seed file
                    /** @noinspection PhpIncludeInspection */
                    require_once $filePath;
                    if (!class_exists($class)) {
                        throw new \InvalidArgumentException(sprintf(
                            'Could not find class "%s" in file "%s"',
                            $class,
                            $filePath
                        ));
                    }

                    // instantiate it
                    $seed = new $class();

                    if (!($seed instanceof AbstractSeed)) {
                        throw new \InvalidArgumentException(sprintf(
                            'The class "%s" in file "%s" must extend \Phinx\Seed\AbstractSeed',
                            $class,
                            $filePath
                        ));
                    }

                    $seeds[$class] = $seed;
                }
            }

            ksort($seeds);
            $this->setSeeds($seeds);
        }

        return $this->seeds;
    }

    /**
     * Sets the config.
     *
     * @param  Config $config Configuration Object
     * @return Manager
     */
    public function setConfig(Config $config)
    {
        $this->config = $config;
        return $this;
    }

    /**
     * Gets the config.
     *
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Toggles the breakpoint for a specific version.
     *
     * @param int $version
     * @return void
     */
    public function toggleBreakpoint($version)
    {
        $migrations = $this->getMigrations();
        $this->getMigrations();
        $env = $this->getEnvironment();
        $versions = $env->getVersionLog();

        if (empty($versions) || empty($migrations)) {
            return;
        }

        if (null === $version) {
            $lastVersion = end($versions);
            $version = $lastVersion['version'];
        }

        if (0 != $version && !isset($migrations[$version])) {
            Output::writeln(sprintf(
                Colour::colour('warning', Colour::RED) . ' %s is not a valid version',
                $version
            ));
            return;
        }

        $env->getAdapter()->toggleBreakpoint($migrations[$version]);

        $versions = $env->getVersionLog();

        Output::writeln(
            ' Breakpoint ' . ($versions[$version]['breakpoint'] ? 'set' : 'cleared') .
            ' for ' . Colour::colour($version, Colour::CYAN) .
            Colour::colour($migrations[$version]->getName(), Colour::GREEN)
        );
    }

    /**
     * Remove all breakpoints
     *
     * @return void
     */
    public function removeBreakpoints()
    {
        Output::writeln(sprintf(
            ' %d breakpoints cleared.',
            $this->getEnvironment()->getAdapter()->resetAllBreakpoints()
        ));
    }
}
