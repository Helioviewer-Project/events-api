<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Utils;

/**
 * Typed reads of the environment for the bin/ scripts.
 *
 * Everything here goes through the framework's env() helper rather than
 * getenv(), for two reasons:
 *
 *   1. getenv() cannot see .env at all. bootstrap.php loads the file with
 *      Dotenv::createImmutable(), which populates $_ENV and $_SERVER but never
 *      calls putenv() — so getenv('APPLY') is false for anything defined there,
 *      and the old reads only worked because `make` passes the variables
 *      through `docker run -e` into the real process environment.
 *
 *   2. The obvious idiom is wrong. `$_ENV['X'] ?? getenv('X') ?: ''` tests for
 *      truthiness, and PHP counts the string "0" as falsy, so an explicitly
 *      passed 0 collapses to '' and reads as "not set". env() already returns
 *      "0" intact, along with true/false/null for those literals.
 *
 * What env() does not do is coerce a flag: env('APPLY') on "no" returns the
 * string "no", which is truthy. flag() below settles that, and int() and list()
 * cover the other two shapes these scripts ask for.
 *
 * @package Helioviewer\EventsApi\Utils
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
final class Env
{
    /** Values that mean "no" when a flag is set explicitly. */
    private const FALSY = ['0', 'false', 'no', 'off'];

    /**
     * Raw value as a string. Unset and empty both yield $default; "0" survives.
     *
     * env() turns the literals true/false/null into their typed equivalents,
     * so they are put back as text here — string() promises a string.
     *
     * @param string $name Variable name
     * @param string $default Returned when unset or empty
     * @return string
     */
    public static function string(string $name, string $default = ''): string
    {
        $value = env($name);

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Boolean switch. Unset or empty yields $default; 0/false/no/off (any case)
     * yield false; anything else yields true.
     *
     * @param string $name Variable name
     * @param bool $default Returned when unset or empty
     * @return bool
     */
    public static function flag(string $name, bool $default = false): bool
    {
        $value = self::string($name);

        if ($value === '') {
            return $default;
        }

        return !in_array(strtolower($value), self::FALSY, true);
    }

    /**
     * Integer, clamped to a minimum. Unset, empty or non-numeric yields
     * $default, so a typo does not silently become 0.
     *
     * @param string $name Variable name
     * @param int|null $default Returned when unset, empty or not numeric
     * @param int $min Lowest value accepted; anything below is raised to it
     * @return int|null
     */
    public static function int(string $name, ?int $default = null, int $min = PHP_INT_MIN): ?int
    {
        $value = self::string($name);

        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return max($min, (int) $value);
    }

    /**
     * Separated list, trimmed, with empty entries dropped.
     *
     * @param string $name Variable name
     * @param string $separators Characters that separate entries
     * @return array<int, string>
     */
    public static function list(string $name, string $separators = ','): array
    {
        $value = self::string($name);

        if (trim($value) === '') {
            return [];
        }

        $parts = preg_split('/[' . preg_quote($separators, '/') . ']/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }
}
