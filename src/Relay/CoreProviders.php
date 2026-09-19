<?php
/**
 * Laika Framework Relay Service
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 */

declare(strict_types=1);

namespace Laika\Engine\Relay;

use Laika\Engine\Core\IP\IP;
use Laika\Engine\Core\App\Key;
use Laika\Engine\Core\Http\CSRF;
use Laika\Engine\Core\Http\CORS;
use Laika\Engine\Core\Helper\Init;
use Laika\Engine\Core\App\Infra;
use Laika\Engine\Core\Helper\Url;
use Laika\Engine\Core\Helper\File;
use Laika\Engine\Core\Helper\Date;
use Laika\Engine\Core\Nav\Builder;
use Laika\Engine\Core\Http\Header;
use Laika\Engine\Core\Helper\Page;
use Laika\Engine\Core\Helper\Hook;
use Laika\Engine\Core\Helper\Math;
use Laika\Engine\Core\Regex\Regex;
use Laika\Engine\Core\Log\Activity;
use Laika\Engine\Core\Http\Request;
use Laika\Engine\Core\Helper\Image;
use Laika\Engine\Core\Helper\Local;
use Laika\Engine\Core\Helper\Vault;
use Laika\Engine\Core\App\Resource;
use Laika\Engine\Core\Http\Response;
use Laika\Engine\Core\Http\Redirect;
use Laika\Engine\Core\Generator\Uid;
use Laika\Engine\Core\Template\Meta;
use Laika\Engine\Core\Helper\Config;
use Laika\Engine\Core\Helper\Cookie;
use Laika\Engine\Core\Helper\Client;
use Laika\Engine\Core\Helper\Upload;
use Laika\Engine\Core\Generator\Icon;
use Laika\Engine\Core\Template\Asset;
use Laika\Engine\Core\Generator\Token;
use Laika\Engine\Core\Helper\MimeType;
use Laika\Engine\Core\Template\Context;
use Laika\Engine\Core\Helper\Directory;
use Laika\Engine\Core\Generator\Unique;
use Laika\Engine\Core\Model\OptionModel;
use Laika\Engine\Core\Exceptions\Handler;
use Laika\Engine\Core\Helper\PhpMetadataParser;

/**
 * CoreServiceProvider — Registers all built-in Laika core services.
 *
 * This provider is registered automatically by the framework during bootstrap.
 * You do not need to add it to your config/app.php providers array.
 *
 * Services registered include (see register() for the full list):
 *   - config   → Laika\Engine\Core\Helper\Config
 *   - date     → Laika\Engine\Core\Helper\Date
 *   - csrf     → Laika\Engine\Core\Http\CSRF
 *   - init     → Laika\Engine\Core\Helper\Init (session driver shortcuts)
 *
 * Sessions are not a registered service: use Laika\Engine\Session\Session directly.
 */
class CoreProviders extends RelayProvider
{
    public function register(): void
    {
        // Register Each Core Service As A Singleton.
        $this->registry->singleton('ip', IP::class);
        $this->registry->singleton('uid', Uid::class);
        $this->registry->singleton('url', Url::class);
        $this->registry->singleton('init', Init::class);
        $this->registry->singleton('date', Date::class);
        $this->registry->singleton('page', Page::class);
        $this->registry->singleton('hook', Hook::class);
        $this->registry->singleton('math', Math::class);
        $this->registry->singleton('file', File::class);
        $this->registry->singleton('csrf', CSRF::class);
        $this->registry->singleton('cors', CORS::class);
        $this->registry->singleton('icon', Icon::class);
        $this->registry->singleton('regex', Regex::class);
        $this->registry->singleton('infra', Infra::class);
        $this->registry->singleton('token', Token::class);
        $this->registry->singleton('nav', Builder::class);
        $this->registry->singleton('vault', Vault::class);
        // Not a singleton: Image carries a GD handle and Upload a pending
        // $_FILES entry, so a shared instance handed every caller the same
        // half-used object. Bound per-resolution instead.
        $this->registry->bind('image', Image::class);
        $this->registry->bind('upload', Upload::class);
        $this->registry->singleton('local', Local::class);
        $this->registry->singleton('app.key', Key::class);
        $this->registry->singleton('mime', MimeType::class);
        $this->registry->singleton('config', Config::class);
        $this->registry->singleton('cookie', Cookie::class);
        $this->registry->singleton('unique', Unique::class);
        $this->registry->singleton('visitor', Client::class);
        $this->registry->singleton('request', Request::class);
        $this->registry->singleton('resource', Resource::class);
        $this->registry->singleton('activity', Activity::class);
        $this->registry->singleton('redirect', Redirect::class);
        $this->registry->singleton('response', Response::class);
        $this->registry->singleton('option', OptionModel::class);
        $this->registry->singleton('template.meta', Meta::class);
        $this->registry->singleton('directory', Directory::class);
        $this->registry->singleton('template.asset', Asset::class);
        $this->registry->singleton('template.context', Context::class);
        $this->registry->singleton('php.metadata.parser', PhpMetadataParser::class);
    }

    public function boot(): void
    {
        // Set Default Timezone to UTC
        $this->registry->make('date')->setAppTimezone('UTC');
        // Register Error Handler
        Handler::register();
    }
}
