<?php

namespace BuildEnv\Composer;

use Composer\Command\BaseCommand;
use Dotenv;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BuildEnvCommand extends BaseCommand
{
    protected $input;
    protected $io;

    protected $env = [];
    protected $defaults = [];
    protected $defaultsFile = '';
    protected $pinnedKeys = [];
    protected $pinnedValues = '';

    protected function configure()
    {
        $this->setName('build-env')
            ->setDescription('Build .env file from .env.example with advanced configuration management')
            ->setDefinition([new InputArgument('environment', InputArgument::OPTIONAL, 'Environment to create .env as: local, testing, staging or production', 'local'),
                             new InputArgument('defaults', InputArgument::OPTIONAL, 'Optional location of file to use for variable substitution and default values if keys match'),
                             new InputOption('set-defaults', null, InputOption::VALUE_NONE, 'Remember defaults file'),
                             new InputOption('setup', null, InputOption::VALUE_NONE, 'Setup .env.example for multi-environment builds')]);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->io = $this->getIO();
        
        $this->readEnvExample();

        if ($this->input->getOption('setup')) {
            $this->setupEnvExample();
            return;
        }

        $this->getDefaults();
        $this->getPinnedValues();
        $this->buildEnv();
    }

    protected function setupEnvExample()
    {
        if (file_exists('.env.example')) {
            $exampleFile = file_get_contents('.env.example');

            if (strpos($exampleFile, 'composer build-env') !== false) {
                $this->io->writeError('<info>.env.example already setup for multi-environment builds.</info>');

                if ($this->io->isInteractive() && !$this->io->askConfirmation('Would you like to replace your old .env.example file [<comment>yes</comment>]? ', true)) {
                    $this->io->writeError('<error>Not creating new .env.example.</error>');
                    exit(1);
                }
            }
        }

        // .env.json backwards compatibility
        if (file_exists('.env.json')) {
            $this->io->writeError('<info>Converting deprecated .env.json file to .env.example file.</info>');

            if ($this->io->isInteractive() && $this->io->askConfirmation('Would you like to remove your old .env.json file [<comment>yes</comment>]? ', true)) {
                unlink('.env.json');
            }
        }

        $otherEnvironments = [];

        $compiledEnv = <<<TEMPLATE
################################################################
# This file is managed by "composer build-env"                 #
#                                                              #
# IMPORTANT:                                                   #
# Common values applicable to all environments are added to    #
# the top of the file to the common section.                   #
#                                                              #
# Environmental overrides are placed at the bottom of this     #
# file as commented out values in the designated blocks.       #
#                                                              #
# After updating .env.example run "composer build-env" to      #
# compile a new .env file.                                     #
################################################################

TEMPLATE;

        if (!empty($this->env)) {
            $keyGroup = '';
            ksort($this->env, SORT_NATURAL);

            foreach ($this->env as $key => $value) {
                $group = explode('_', $key)[0];
                if ($keyGroup != $group) {
                    $keyGroup = $group;
                    $compiledEnv .= PHP_EOL;
                }

                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        if ($k === 'default') {
                            $compiledEnv .= $this->printValue($key, $v);
                        } else {
                            $otherEnvironments[$k][$key] = $v;
                        }
                    }
                } else {
                    $compiledEnv .= $this->printValue($key, $value);
                }
            }
        }

        $order = ['local' => '', 'testing' => '', 'staging' => '', 'production' => ''];
        $otherEnvironments = array_merge($order, $otherEnvironments);

        if (!empty($otherEnvironments)) {
            foreach ($otherEnvironments as $environment => $values) {
                if (empty($values)) {
                    continue;
                }
                $compiledEnv .= <<<TEMPLATE


################################################################
# APP_ENV=$environment
################################################################

TEMPLATE;
                foreach ($values as $k => $v) {
                    $compiledEnv .= '#' . $this->printValue($k, $v);
                }
            }
        }

        if (file_put_contents('.env.example', $compiledEnv) === false) {
            $this->io->writeError('<error>Failed to create .env.example file</error>');
            exit(1);
        }

        $this->io->writeError('<info>.env.example updated</info>');
    }

    protected function readEnvExample()
    {
        // .env.json backwards compatibility
        if (file_exists('.env.json')) {
            $this->io->writeError('<info>Found deprecated .env.json file.</info>');

            if (!$this->input->getOption('setup')) {
                $this->io->writeError('<info>Run `composer build-env --setup` to create new .env.example.</info>');
            }

            $this->env = json_decode(file_get_contents('.env.json'), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->io->writeError('<error>Failed to read .env.json</error>');
                exit(1);
            }

            if (file_exists('.env.example')) {
                $this->io->writeError('<warning>Warning project has both .env.json and .env.example. Reading values from .env.json file.</warning>');
            }
            return;
        }

        if (!file_exists('.env.example')) {
            $this->io->writeError('<error>Failed to read .env.example</error>');
            exit(1);
        }

        $exampleFile = file_get_contents('.env.example');

        if (!$this->input->getOption('setup')) {
            if (strpos($exampleFile, 'composer build-env') === false) {
                $this->io->writeError('<info>Run `composer build-env --setup` to setup .env.example for multi-environment builds</info>');
            }
        }

        $dotenv = Dotenv\Dotenv::createMutable(getcwd(), '.env.example');
        $this->env = $dotenv->load();

        preg_match_all('/#{8,256}\n#\sAPP_ENV=(.*?)\n#{8,256}/', $exampleFile, $matches,PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (!empty($matches)) {
            foreach ($matches as $key => $match) {
                $environment = $match[1][0];

                $sectionStart = $match[0][1] + strlen($match[0][0]);

                if (isset($matches[$key+1][0][1])) {
                    $sectionEnd = $matches[$key+1][0][1] - $sectionStart;
                    $sectionBlock = substr($exampleFile, $sectionStart, $sectionEnd);
                } else {
                    $sectionBlock = substr($exampleFile, $sectionStart);
                }

                $sectionBlock = preg_replace('/^#/m', '', $sectionBlock);

                $tmpfile = basename(tempnam(getcwd(), '.tmp-' . $environment));
                if (file_put_contents($tmpfile, $sectionBlock) === false) {
                    $this->io->writeError('<error>Failed to create tmp file, make sure the current directory is writable</error>');
                    exit(1);
                }
                $dotenv = Dotenv\Dotenv::createMutable(getcwd(), $tmpfile);
                $tmpEnv = $dotenv->load();
                unlink($tmpfile);

                foreach ($tmpEnv as $k => $v) {
                    if (isset($this->env[$k]) && is_string($this->env[$k])) {
                        $this->env[$k] = ['default' => $this->env[$k]];
                    }

                    $this->env[$k][$environment] = $v;
                }
            }
        }

        if (isset($this->env['APP_ENV']) && is_string($this->env['APP_ENV'])) {
            $this->env['APP_ENV'] = ['default' => 'local',
                                    'testing' => 'testing',
                                    'staging' => 'staging',
                                    'production' => 'production'];
        }

        if (isset($this->env['APP_DEBUG']) && is_string($this->env['APP_DEBUG'])) {
            $this->env['APP_DEBUG'] = ['default' => true,
                                    'testing' => true,
                                    'staging' => false,
                                    'production' => false];
        }
    }

    protected function getDefaults()
    {
        $targetEnvironment = $this->input->getArgument('environment');
        $defaults = $this->input->getArgument('defaults');

        if (!empty($defaults) && !file_exists($defaults)) {
            $this->io->writeError('<error>Defaults is not a valid file</error>');
            exit(1);
        }

        $defaultsFile = dirname(dirname(__DIR__)) . '/.env.' . $targetEnvironment . '.defaults';

        if ($this->input->getOption('set-defaults')) {

            if (is_link($defaultsFile)) {
                unlink($defaultsFile);
            }

            if (empty($defaults)) {
                $this->io->writeError('<error>Argument defaults must be provided for option --set-defaults</error>');
                exit(1);
            }

            $this->io->writeError('<info>Setting defaults for ' . $targetEnvironment . ' to: ' . $defaults . '</info>');

            if (symlink($defaults, $defaultsFile) === false) {
                $this->io->writeError('<error>Failed to create symlink: ' . $defaultsFile . '</error>');
                exit(1);
            }
        }

        if (!empty($defaults)) {
            $defaultsFile = $defaults;
        } elseif (is_link($defaultsFile)) {
            $defaultsFile = readlink($defaultsFile);
        }

        if (file_exists($defaultsFile)) {
            $this->defaultsFile = $defaultsFile;
            $this->io->writeError('<info>Reading defaults from: ' . $this->defaultsFile . '</info>');

            // .env.json backwards compatibility
            $jsonDefaults = json_decode(file_get_contents($this->defaultsFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->defaults = $jsonDefaults;
                return;
            }

            $dotenv = Dotenv\Dotenv::createMutable('/', realpath($this->defaultsFile));
            $this->defaults = $dotenv->load();
        }
    }

    protected function getPinnedValues()
    {
        $this->pinnedKeys = [];
        $this->pinnedValues = '#DB_USERNAME="root"' . PHP_EOL;
        $this->pinnedValues .= '#DB_PASSWORD=""' . PHP_EOL;

        if (!file_exists('.env')) {
            return;
        }

        if (($env = file_get_contents('.env')) === false) {
            $this->io->writeError('<error>Failed reading .env file</error>');
            exit(1);
        }

        preg_match_all('/#{8,256}\n#\sLocal\spinned\svalues\s+#\n#{8,256}/', $env, $matches,PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (empty($matches)) {
            return;
        }

        $sectionStart = $matches[0][0][1] + strlen($matches[0][0][0]);
        $this->pinnedValues = trim(substr($env, $sectionStart));

        $tmpfile = basename(tempnam(getcwd(), '.tmp-pinned'));
        if (file_put_contents($tmpfile, $this->pinnedValues) === false) {
            $this->io->writeError('<error>Failed to create tmp file, make sure the current directory is writable</error>');
            exit(1);
        }
        $dotenv = Dotenv\Dotenv::createMutable(getcwd(), $tmpfile);
        $this->pinnedKeys = $dotenv->load();
        unlink($tmpfile);
    }

    protected function buildEnv()
    {
        $targetEnvironment = $this->input->getArgument('environment');

        switch ($targetEnvironment) {
            case 'test':
                $targetEnvironment = 'testing';
                break;
            case 'stag':
                $targetEnvironment = 'staging';
                break;
            case 'prod':
                $targetEnvironment = 'production';
                break;
        }

        $this->io->writeError('<info>Building .env for ' . $targetEnvironment . ' environment</info>');

        $compiledEnv = <<<TEMPLATE
################################################################
# This file is generated by "composer build-env"               #
#                                                              #
# IMPORTANT:                                                   #
# New and updated .env values should be added to .env.example  #
# and committed into your vcs. After updating .env.example     #
# run "composer build-env" to re-build the .env for the target #
# environment.                                                 #
#                                                              #
# Values in this file can be pinned to not be overwritten by   #
# "composer build-env". Add local pinned values only in the    #
# designated block below.                                      #
#                                                              #

TEMPLATE;

        $compiledEnv .= sprintf('# Generate on %s for environment %-12s #' . PHP_EOL, date('Y-m-d H:i:s'), $targetEnvironment);

        if (!empty($this->defaultsFile)) {
            $compiledEnv .= sprintf('# Using defaults file %-40s #' . PHP_EOL, $this->defaultsFile);
        }

        $compiledEnv .= '################################################################' . PHP_EOL;

        if (!empty($this->env)) {
            $keyGroup = '';
            ksort($this->env, SORT_NATURAL);

            foreach ($this->env as $key => $value) {
                $group = explode('_', $key)[0];
                if ($keyGroup != $group) {
                    $keyGroup = $group;
                    $compiledEnv .= PHP_EOL;
                }

                if (!empty($this->defaults) && isset($this->defaults[$key])) {
                    $value = $this->defaults[$key];
                } elseif (is_array($value)) {
                    if (isset($value[$targetEnvironment])) {
                        $value = $value[$targetEnvironment];

                        if ($value === '') {
                            continue;
                        }
                    } elseif (isset($value['default'])) {
                        $value = $value['default'];
                    } else {
                        continue;
                    }
                }

                $compiledEnv .= $this->printValue($key, $value);
            }
        }

        $compiledEnv .= <<<TEMPLATE

################################################################
# Local pinned values                                          #
################################################################

TEMPLATE;

        $compiledEnv .= $this->pinnedValues;

        if (file_put_contents('.env', $compiledEnv) === false) {
            $this->io->writeError('<error>Failed to create .env file</error>');
            exit(1);
        }

        $this->io->writeError('<info>.env file created</info>');
    }

    protected function printValue($key, $value)
    {
        $str = '';

        if ($value === "") {
            $str = $key . "=\n";
        } elseif (is_null($value) || ($value === "null")) {
            $str = $key . "=null\n";
        } elseif (is_numeric($value)) {
            $str = $key . "=" . $value . "\n";
        } elseif (is_bool($value)) {
            $str = $key . "=" . (($value)?'true':'false') . "\n";
        } else {
            if (preg_match('/\{\{(.*?)\}\}/', $value, $matches)) {
                if (!empty($this->defaults) && isset($this->defaults[$matches[1]])) {
                    $value = str_replace($matches[0], $this->defaults[$matches[1]], $value);
                }
            }

            if (preg_match('/[\s=#\\\$\(\)\{\}\[\]`"\']/', $value)) {
                $value = '"' . str_replace('"','\"', $value) . '"';
            }

            $str = $key . "=" . $value . "\n";
        }

        if (isset($this->pinnedKeys[$key])) {
            $str = '#' . $str;
        }

        return $str;
    }
}
