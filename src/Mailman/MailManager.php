<?php
/**
 * Laika Mailman
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Engine\Mailman;

use Laika\Engine\Mailman\Reader\ImapReader;
use Laika\Engine\Mailman\Reader\Pop3Reader;
use Laika\Engine\Mailman\Interfaces\MailerInterface;
use Laika\Engine\Mailman\Exceptions\MailmanException;
use Laika\Engine\Mailman\Interfaces\MailReaderInterface;

/**
 * Builds Mailers and Readers From a Config Array
 *
 * The extension point for mail. Applications register a transport of their own
 * (an HTTP API such as SES or Postmark, a log mailer for development) with
 * extendMailer(), or a mailbox protocol with extendReader(), usually from a
 * relay provider's boot(), then name it in the config:
 *
 *   MailManager::extendMailer('log', fn (array $config) => new LogMailer($config));
 *   MailManager::mailer(['driver' => 'log'] + config('mail'))->to(...)->send();
 *
 * Names nobody registered go to the built-in classes: a mailer 'driver' of
 * smtp, mail, sendmail or qmail builds a Mailer, and a reader 'protocol' of
 * imap (the default) or pop3 builds an ImapReader or a Pop3Reader. Building a
 * Mailer or a Reader directly still works exactly as before.
 */
class MailManager
{
    /** @var string[] Mailer drivers the built-in Mailer handles itself */
    public const MAILER_DRIVERS = ['smtp', 'mail', 'sendmail', 'qmail'];

    /** @var string[] Reader protocols with a built-in reader */
    public const READER_PROTOCOLS = ['imap', 'pop3'];

    /** @var array<string,callable> Application mailers, keyed by lowercased driver name */
    private static array $mailers = [];

    /** @var array<string,callable> Application readers, keyed by lowercased protocol name */
    private static array $readers = [];

    ##########################################################################
    /*============================ EXTERNAL API ============================*/
    ##########################################################################

    /**
     * Register an Application-Supplied Mailer
     *
     * A name that matches a built-in driver replaces it.
     * @param string $driver Value of the config's 'driver' key
     * @param callable $resolver Receives the config array, returns a MailerInterface
     * @return void
     */
    public static function extendMailer(string $driver, callable $resolver): void
    {
        self::$mailers[strtolower($driver)] = $resolver;
    }

    /**
     * Register an Application-Supplied Reader
     *
     * A name that matches a built-in protocol replaces it.
     * @param string $protocol Value of the config's 'protocol' key
     * @param callable $resolver Receives the config array, returns a MailReaderInterface
     * @return void
     */
    public static function extendReader(string $protocol, callable $resolver): void
    {
        self::$readers[strtolower($protocol)] = $resolver;
    }

    /**
     * Forget Every Application Mailer and Reader. Intended for tests.
     * @return void
     */
    public static function flushExtensions(): void
    {
        self::$mailers = [];
        self::$readers = [];
    }

    /**
     * Build a Mailer
     * @param array<string,mixed> $config Mailer config; 'driver' defaults to smtp
     * @return MailerInterface
     * @throws MailmanException
     */
    public static function mailer(array $config = []): MailerInterface
    {
        $driver = strtolower((string) ($config['driver'] ?? 'smtp'));

        if (isset(self::$mailers[$driver])) {
            return static::resolve(self::$mailers[$driver], $config, $driver, MailerInterface::class);
        }

        if (!in_array($driver, self::MAILER_DRIVERS, true)) {
            throw new MailmanException(
                "Unknown mail driver [{$driver}]. Expected one of: " . implode(', ', static::mailerDrivers()) . '.'
            );
        }

        // Mailer matches the driver case-sensitively and sends anything it does
        // not recognise through mail(), so hand it the name as it was matched here
        return new Mailer(['driver' => $driver] + $config);
    }

    /**
     * Build a Reader
     * @param array<string,mixed> $config Reader config; 'protocol' defaults to imap
     * @return MailReaderInterface
     * @throws MailmanException
     */
    public static function reader(array $config): MailReaderInterface
    {
        $protocol = strtolower((string) ($config['protocol'] ?? 'imap'));

        if (isset(self::$readers[$protocol])) {
            return static::resolve(self::$readers[$protocol], $config, $protocol, MailReaderInterface::class);
        }

        return match ($protocol) {
            'imap'  => new ImapReader($config),
            'pop3'  => new Pop3Reader($config),
            default => throw new MailmanException(
                "Unknown mail protocol [{$protocol}]. Expected one of: " . implode(', ', static::readerProtocols()) . '.'
            ),
        };
    }

    /**
     * Every Mailer Driver That Can Be Built, Built-In First
     * @return string[]
     */
    public static function mailerDrivers(): array
    {
        return array_values(array_unique(array_merge(self::MAILER_DRIVERS, array_keys(self::$mailers))));
    }

    /**
     * Every Reader Protocol That Can Be Built, Built-In First
     * @return string[]
     */
    public static function readerProtocols(): array
    {
        return array_values(array_unique(array_merge(self::READER_PROTOCOLS, array_keys(self::$readers))));
    }

    ##########################################################################
    /*============================ INTERNAL API ============================*/
    ##########################################################################

    /**
     * Call an Application Resolver and Check What It Returned
     * @param callable $resolver
     * @param array<string,mixed> $config
     * @param string $name
     * @param class-string $contract
     * @return object
     * @throws MailmanException
     */
    protected static function resolve(callable $resolver, array $config, string $name, string $contract): object
    {
        $built = $resolver($config);

        if (!$built instanceof $contract) {
            throw new MailmanException("Mail driver [{$name}] must resolve to {$contract}.");
        }

        return $built;
    }
}
