<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Engine\Support;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionFunction;
use BadMethodCallException;

/**
 * Add Methods to a Class at Runtime
 *
 *   Model::macro('active', function () {
 *       return $this->where(['status' => 'active']);
 *   });
 *
 *   (new UsersModel)->active()->get();
 *
 * A closure macro is bound to the instance it is called on, so $this and
 * protected members work inside it exactly as in a real method. Called
 * statically it is bound to the class instead. A macro never shadows a real
 * method: PHP only reaches __call() for a method that does not exist.
 *
 * Macros are registered per class and inherited: one added to Model is
 * available on every model, one added to UsersModel only on UsersModel and its
 * subclasses. Register them once, from a relay provider's boot().
 */
trait Macroable
{
    /** @var array<class-string,array<string,callable>> Registered macros, keyed by the class they were added to */
    protected static array $macros = [];

    /**
     * Register a Macro
     * @param string $name Method name
     * @param callable $macro A closure is bound to the object (or class) it is called on
     * @return void
     */
    public static function macro(string $name, callable $macro): void
    {
        static::$macros[static::class][$name] = $macro;
    }

    /**
     * Register Every Public and Protected Method of an Object as a Macro
     *
     * Each method of the mixin must return a closure, which becomes the macro.
     * @param object $mixin
     * @param bool $replace False keeps macros that are already registered
     * @return void
     */
    public static function mixin(object $mixin, bool $replace = true): void
    {
        $methods = (new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
        );

        foreach ($methods as $method) {
            if ($method->isStatic() || str_starts_with($method->name, '__')) {
                continue;
            }

            if ($replace || !static::hasMacro($method->name)) {
                static::macro($method->name, $method->invoke($mixin));
            }
        }
    }

    /**
     * Check a Macro Is Registered on This Class or a Parent
     * @param string $name
     * @return bool
     */
    public static function hasMacro(string $name): bool
    {
        return static::findMacro($name) !== null;
    }

    /**
     * Forget The Macros Registered on This Class. Intended for tests.
     * @return void
     */
    public static function flushMacros(): void
    {
        unset(static::$macros[static::class]);
    }

    /**
     * Call a Macro on The Class
     * @param string $method
     * @param array $parameters
     * @return mixed
     * @throws BadMethodCallException
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        $macro = static::findMacro($method);

        if ($macro === null) {
            throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }

        if ($macro instanceof Closure) {
            $macro = Closure::bind($macro, null, static::class);
        }

        return $macro(...$parameters);
    }

    /**
     * Call a Macro on an Instance
     * @param string $method
     * @param array $parameters
     * @return mixed
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        $macro = static::findMacro($method);

        if ($macro === null) {
            throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }

        // A static closure cannot take $this; it keeps the scope it was made in
        if ($macro instanceof Closure && !(new ReflectionFunction($macro))->isStatic()) {
            $macro = $macro->bindTo($this, static::class);
        }

        return $macro(...$parameters);
    }

    /**
     * Find a Macro on This Class, Then on Each Parent in Turn
     * @param string $name
     * @return ?callable
     */
    protected static function findMacro(string $name): ?callable
    {
        for ($class = static::class; $class !== false; $class = get_parent_class($class)) {
            if (isset(static::$macros[$class][$name])) {
                return static::$macros[$class][$name];
            }
        }

        return null;
    }
}
