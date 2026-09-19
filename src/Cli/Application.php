<?php

declare(strict_types=1);

namespace Laika\Engine\Cli;

use Laika\Engine\Service\Infra;
use Laika\Engine\Cli\Command\Message;
use Laika\Engine\Cli\Command\Argument;
use Laika\Engine\Cli\Command\HelpCommand;
use Laika\Engine\Cli\Command\AppSyncCommand;
use Laika\Engine\Cli\Command\RouteListCommand;
use Laika\Engine\Cli\Command\RouteMakeCommand;
use Laika\Engine\Cli\Command\ModelListCommand;
use Laika\Engine\Cli\Command\ModelMakeCommand;
use Laika\Engine\Cli\Contracts\CommandInterface;
use Laika\Engine\Cli\Command\SecretFixCommand;
use Laika\Engine\Cli\Command\RelayListCommand;
use Laika\Engine\Cli\Command\SchemaListCommand;
use Laika\Engine\Cli\Command\FilterListCommand;
use Laika\Engine\Cli\Command\FilterMakeCommand;
use Laika\Engine\Cli\Command\AppMigrateCommand;
use Laika\Engine\Cli\Command\AppStartCommand;
use Laika\Engine\Cli\Command\NginxMakeCommand;
use Laika\Engine\Cli\Command\NginxServerCommand;
use Laika\Engine\Cli\Command\ResourceListCommand;
use Laika\Engine\Cli\Command\AppCacheCommand;
use Laika\Engine\Cli\Command\AppClearCommand;
use Laika\Engine\Cli\Command\CacheClearCommand;
use Laika\Engine\Cli\Command\CacheForgetCommand;
use Laika\Engine\Cli\Command\ModelRemoveCommand;
use Laika\Engine\Cli\Command\ServiceMakeCommand;
use Laika\Engine\Cli\Command\ModelRenameCommand;
use Laika\Engine\Cli\Command\PipelineListCommand;
use Laika\Engine\Cli\Command\TemplateListCommand;
use Laika\Engine\Cli\Command\PipelineMakeCommand;
use Laika\Engine\Cli\Command\FilterRemoveCommand;
use Laika\Engine\Cli\Command\FilterRenameCommand;
use Laika\Engine\Cli\Command\TemplateMakeCommand;
use Laika\Engine\Cli\Command\ServiceRemoveCommand;
use Laika\Engine\Cli\Command\PipelineRenameCommand;
use Laika\Engine\Cli\Command\ControllerMakeCommand;
use Laika\Engine\Cli\Command\ControllerListCommand;
use Laika\Engine\Cli\Command\SecretGenerateCommand;
use Laika\Engine\Cli\Command\PipelineRemoveCommand;
use Laika\Engine\Cli\Command\ControllerRemoveCommand;
use Laika\Engine\Cli\Command\ControllerRenameCommand;
use Laika\Engine\Cli\Command\JobListCommand;
use Laika\Engine\Cli\Command\JobMakeCommand;
use Laika\Engine\Cli\Command\JobRemoveCommand;
use Laika\Engine\Cli\Command\JobRenameCommand;
use Laika\Engine\Cli\Command\QueueWorkCommand;
use Laika\Engine\Cli\Command\QueueFailedCommand;
use Laika\Engine\Cli\Command\QueueRetryCommand;
use Laika\Engine\Cli\Command\QueueFlushCommand;
use Laika\Engine\Cli\Command\CommandMakeCommand;

foreach (Infra::getFunctionFiles() as $file) require_once $file;

class Application
{
    /** @var CommandInterface[] */
    protected array $commands = [];

    public function __construct(protected string $basePath)
    {
        // Help
        $this->register(new HelpCommand());
        // Service
        $this->register(new RelayListCommand);
        $this->register(new ServiceMakeCommand());
        $this->register(new ServiceRemoveCommand());

        // Model
        $this->register(new ModelListCommand());
        $this->register(new ModelMakeCommand());
        $this->register(new ModelRemoveCommand());
        $this->register(new ModelRenameCommand());
        $this->register(new SchemaListCommand);

        // Pipeline
        $this->register(new PipelineListCommand);
        $this->register(new PipelineMakeCommand());
        $this->register(new PipelineRenameCommand());
        $this->register(new PipelineRemoveCommand());

        // Filter
        $this->register(new FilterListCommand);
        $this->register(new FilterMakeCommand());
        $this->register(new FilterRenameCommand());
        $this->register(new FilterRemoveCommand());

        // Controller
        $this->register(new ControllerListCommand);
        $this->register(new ControllerMakeCommand());
        $this->register(new ControllerRenameCommand());
        $this->register(new ControllerRemoveCommand());

        // Job
        $this->register(new JobListCommand);
        $this->register(new JobMakeCommand());
        $this->register(new JobRenameCommand());
        $this->register(new JobRemoveCommand());

        // Queue / Worker
        $this->register(new QueueWorkCommand());
        $this->register(new QueueFailedCommand());
        $this->register(new QueueRetryCommand());
        $this->register(new QueueFlushCommand());

        // Template
        $this->register(new TemplateListCommand);
        $this->register(new TemplateMakeCommand());

        // Route
        $this->register(new RouteListCommand());
        $this->register(new RouteMakeCommand());

        // Secret
        $this->register(new SecretGenerateCommand());
        $this->register(new SecretFixCommand());

        // Resource
        $this->register(new ResourceListCommand());
        $this->register(new AppCacheCommand());
        $this->register(new AppClearCommand());
        $this->register(new CacheClearCommand());
        $this->register(new CacheForgetCommand());

        // Nginx
        $this->register(new NginxMakeCommand());
        $this->register(new NginxServerCommand());

        // Command
        $this->register(new CommandMakeCommand());

        // App
        $this->register(new AppSyncCommand());
        $this->register(new AppMigrateCommand());
        $this->register(new AppStartCommand());

        // Commands the application defines in lf-app/Command
        $this->registerAppCommands();

        // Help lists whatever ended up registered, app commands included
        $this->commands['help']->setCommands($this->commands);
    }

    protected function register(CommandInterface $command): void
    {
        $this->commands[$command->signature()] = $command;
    }

    /**
     * Discover & Register App Commands From lf-app/Command
     *
     * Built-ins always win: an app command whose signature is already taken is
     * skipped with a warning rather than replacing a core command. Discovery
     * failures never abort the CLI — a broken command file would otherwise take
     * down `php laika help`, the very command you'd reach for to debug it.
     * @return void
     */
    protected function registerAppCommands(): void
    {
        try {
            $classes = Infra::get('commands', CommandInterface::class);
        } catch (\Throwable $th) {
            Message::error("Skipped lf-app/Command: {$th->getMessage()}");
            return;
        }

        foreach ($classes as $class) {
            try {
                $command = new $class();
            } catch (\Throwable $th) {
                Message::error("Command [{$class}] could not be created: {$th->getMessage()}");
                continue;
            }

            $signature = $command->signature();

            if (isset($this->commands[$signature])) {
                Message::error("Command [{$class}] skipped: signature [{$signature}] is already taken.");
                continue;
            }

            $this->register($command);
        }
    }

    ############################################################################
    /*############################# EXTERNAL API #############################*/
    ############################################################################
    public function run(array $argv): int
    {
        $command = implode(' ', $argv);
        array_shift($argv);
        $signature = $argv[0] ?? null;

        if (!$signature || !isset($this->commands[$signature])) {
            $keys = array_keys($this->commands);

            $matched = Argument::checkMatch($signature, $keys);

            if (empty($matched)) {
                Message::error("INVALID COMMAND!");
                echo "\n\tSUGGESTION: php laika help\n";
                return 1;
            }

            Message::error("INVALID COMMAND: <{$command}>");
            echo "\nPartial Matched Signatures Are:";
            echo "\n-----------------------------\n";
            foreach ($matched as $sig) {
                echo "-- {$sig}\n";
            }
            return 1;
        }

        $args = array_slice($argv, 1);

        try {
            return $this->commands[$signature]->handle($args, $this->basePath);
        } catch (\Throwable $e) {
            Message::error($e->getMessage());
            return 1;
        }
    }
}
