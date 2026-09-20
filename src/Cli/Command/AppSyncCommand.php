<?php

declare(strict_types=1);

namespace Laika\Engine\Cli\Command;

use Laika\Engine\Cli\Contracts\CommandInterface;
use Laika\Engine\Cli\EntryPoints;
use Laika\Engine\Cli\Stub;
use Laika\Engine\Services\File;
use Laika\Engine\Services\Infra;
use Laika\Engine\Services\AppKey;
use Laika\Engine\Services\Directory;
use Laika\Engine\Services\Resource;

class AppSyncCommand implements CommandInterface
{
    public function signature(): string
    {
        return 'app:sync';
    }

    public function handle(array $args, string $basePath): int
    {
        if (count($args) != 0) {
            Message::suggestion($this->command());
            return 1;
        }

        // Entry Points
        //
        // First, so `laika` and `worker` exist even if a later step fails. This
        // is also what the root composer.json's post-autoload-dump relies on --
        // it runs `@php laika app:sync` and nothing else.
        $entries = EntryPoints::write($basePath);

        foreach ($entries['missing'] as $stub) {
            Message::warning("Entry point stub not found: {$stub}");
        }

        // Quiet when nothing changed: app:sync runs on every Composer command
        if ($entries['written']) {
            Message::info('Generated ' . implode(', ', $entries['written']) . ' in project root.');
        }

        if ($entries['removed']) {
            Message::info('Removed ' . implode(', ', $entries['removed']) . ' in project root.');
        }

        // Storage Link
        $storage_path = $basePath . DS . 'lf-storage';
        Directory::make($storage_path);

        if (!is_file($storage_path . DS . '.htaccess')) {
            File::touch($storage_path . DS . '.htaccess');
            File::write('Deny from all', $storage_path . DS . '.htaccess');
            @chmod($storage_path . DS . '.htaccess', 644);
        }

        // Cache Dir
        $cache_dir = $storage_path . DS . 'cache';
        Directory::make($cache_dir);
        foreach (Directory::scan($cache_dir, true, 'php') as $f) {
            try {
                if (File::exists($f)) {
                    File::pop($f);
                } elseif (Directory::exists($f)) {
                    Directory::pop($f);
                }
            } catch (\Throwable $th) {
                Message::error($th->getMessage());
                return 1;
            }
        }

        // Make Uploads Directory
        Directory::make($basePath . DS . 'uploads');

        // Sync .HTACCESS. public/ is the document root and gets the front
        // controller rewrite; the project root gets a deny-all in case a vhost
        // is ever pointed at it instead of at public/.
        Directory::make($basePath . DS . 'public');
        $htaccess = [
            $basePath . DS . 'public' . DS . '.htaccess' => 'htaccess',
            $basePath . DS . '.htaccess'                 => 'htaccess-root',
        ];
        foreach ($htaccess as $ht_file => $stub) {
            if (File::exists($ht_file)) {
                continue;
            }

            try {
                Stub::write($ht_file, Stub::load($stub));
            } catch (\Throwable $th) {
                Message::error($th->getMessage());
                return 1;
            }
        }

        // Fix App Key
        try {
            AppKey::fix();
        } catch (\Throwable $th) {
            Message::error($th->getMessage());
            return 1;
        }

        // Refresh The Resource Manifest If One Is In Use. The cache wipe above no
        // longer touches it, but dependencies may have changed since it was built.
        
        // Create Manifest Path if Doesn't Exists
        if (!File::exists(Resource::manifestPath())) File::touch(Resource::manifestPath());
        try {
            Resource::cache();
        } catch (\Throwable $th) {
            Message::error($th->getMessage());
            return 1;
        }

        Message::success("App Sync Successfull.");

        return 0;
    }

    public function command(): string
    {
        return "php laika app:sync";
    }

    public function help(): array
    {
        return [
            'signature'     =>  $this->signature(),
            'description'   =>  'Generate the laika/worker executables, then sync app files and settings',
            'command'       =>  $this->command(),
            'inputs'        =>  [],
            'params'        =>  []
        ];
    }
}
