<?php

declare(strict_types=1);

namespace Patchline\Cli;

use Patchline\Exception\RuntimeFailure;
use Patchline\Exception\UsageException;
use Patchline\Infrastructure\Filesystem;
use Symfony\Component\Console\Input\InputInterface;

final class ConfigResolver
{
    private const STATES = [
        'applied', 'queued', 'duplicate', 'misordered', 'missing', 'entitled', 'unknown', 'not_applicable',
    ];
    private const CROSS_CHECK = 'cross-check';
    private const DEFAULT_FAIL_ON = ['missing', 'duplicate', 'misordered'];

    private const ENV_STRINGS = [
        'format' => 'PATCHLINE_FORMAT',
        'fail-on' => 'PATCHLINE_FAIL_ON',
        'registry' => 'PATCHLINE_REGISTRY',
        'cache-dir' => 'PATCHLINE_CACHE_DIR',
    ];
    private const ENV_BOOLEANS = [
        'no-cache' => 'PATCHLINE_NO_CACHE',
        'include-entitled' => 'PATCHLINE_INCLUDE_ENTITLED',
        'no-update-check' => 'PATCHLINE_NO_UPDATE_CHECK',
    ];
    private const EXTRA_STRINGS = ['format', 'registry', 'cache-dir'];
    private const EXTRA_BOOLEANS = ['no-cache', 'include-entitled', 'cross-check', 'no-ansi', 'no-update-check'];

    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    /**
     * @param array<string, string> $env
     */
    public function resolve(InputInterface $args, string $command, array $env, string $cwd): Config
    {
        $root = $this->resolveRoot($this->optionString($args, 'root'), $env, $cwd);
        $extra = $this->readExtra($root);

        $failOn = $this->failOn($args, $env, $extra);
        $format = $this->format($args, $env, $extra);

        return new Config(
            $command,
            $root,
            $format,
            array_values(array_filter($failOn, static fn (string $s): bool => $s !== self::CROSS_CHECK)),
            in_array(self::CROSS_CHECK, $failOn, true),
            $this->string('registry', $args, $env, $extra),
            $this->string('cache-dir', $args, $env, $extra),
            $this->bool('no-cache', $args, $env, $extra),
            $this->bool('include-entitled', $args, $env, $extra),
            $this->bool('cross-check', $args, $env, $extra),
            $this->bool('no-ansi', $args, $env, $extra),
            $this->flag($args, 'dry-run'),
            $this->flag($args, 'adopt'),
            $this->flag($args, 'prune'),
            !$this->bool('no-update-check', $args, $env, $extra),
            ($env['PATCHLINE_UPDATE_URL'] ?? '') !== '' ? $env['PATCHLINE_UPDATE_URL'] : Config::DEFAULT_UPDATE_URL,
        );
    }

    /**
     * A string option given on the command line, or null when it was not given.
     */
    private function optionString(InputInterface $input, string $name): ?string
    {
        $value = $input->hasOption($name) ? $input->getOption($name) : null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new UsageException(
                sprintf('The option --%s needs a value, for example --%s=<value>.', $name, $name),
            );
        }

        return $value;
    }

    /**
     * Whether a switch was given on the command line. Console 7.2+ replaced --no-ansi by the negatable --ansi.
     */
    private function flag(InputInterface $input, string $name): bool
    {
        if ($name === 'no-ansi' && !$input->hasOption('no-ansi')) {
            return $input->hasOption('ansi') && $input->getOption('ansi') === false;
        }

        return $input->hasOption($name) && $input->getOption($name) === true;
    }

    /**
     * The store root: the given path (`~` and relative paths expanded) or the working directory.
     *
     * @param array<string, string> $env
     */
    public function resolveRoot(?string $given, array $env, string $cwd): string
    {
        $root = $given ?? $cwd;
        if ($root === '~' || str_starts_with($root, '~/')) {
            $home = $env['HOME'] ?? '';
            if ($home === '') {
                throw new UsageException(
                    'Cannot expand "~" in --root because HOME is not set. Pass an absolute path instead.',
                );
            }
            $root = $home . substr($root, 1);
        } elseif (!str_starts_with($root, '/')) {
            $root = rtrim($cwd, '/') . '/' . $root;
        }
        $root = rtrim($root, '/');
        $root = $root === '' ? '/' : $root;

        if (!$this->filesystem->isDirectory($root) || !is_readable($root)) {
            throw new RuntimeFailure(sprintf('Cannot read the store at %s. Check the path given with --root.', $root));
        }

        return $root;
    }

    /**
     * @return array<string, mixed>
     */
    private function readExtra(string $root): array
    {
        $path = $root . '/composer.json';
        if (!$this->filesystem->isFile($path)) {
            return [];
        }
        $data = json_decode($this->filesystem->read($path), true);
        if (!is_array($data)) {
            throw new RuntimeFailure(sprintf('%s is not valid JSON. Fix the file and run patchline again.', $path));
        }
        $extra = $data['extra'] ?? null;
        $patchline = is_array($extra) ? ($extra['patchline'] ?? null) : null;
        if ($patchline === null) {
            return [];
        }
        if (!is_array($patchline)) {
            throw new UsageException('"extra.patchline" in composer.json must be an object.');
        }

        $allowed = [...self::EXTRA_STRINGS, ...self::EXTRA_BOOLEANS, 'fail-on'];
        $result = [];
        foreach ($patchline as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new UsageException(
                    sprintf('Unknown key "%s" in "extra.patchline" in composer.json.', $key),
                );
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, string> $env
     * @param array<string, mixed> $extra
     */
    private function string(string $name, InputInterface $args, array $env, array $extra): ?string
    {
        $value = $this->optionString($args, $name) ?? $env[self::ENV_STRINGS[$name]] ?? $extra[$name] ?? null;
        if ($value !== null && (!is_string($value) || $value === '')) {
            throw new UsageException(sprintf('"%s" must be a non-empty string.', $name));
        }

        return $value;
    }

    /**
     * @param array<string, string> $env
     * @param array<string, mixed> $extra
     */
    private function bool(string $name, InputInterface $args, array $env, array $extra): bool
    {
        if ($this->flag($args, $name)) {
            return true;
        }
        $envName = self::ENV_BOOLEANS[$name] ?? null;
        if ($envName !== null && isset($env[$envName])) {
            return $this->parseEnvBoolean($envName, $env[$envName]);
        }
        $value = $extra[$name] ?? false;
        if (!is_bool($value)) {
            throw new UsageException(sprintf('"%s" in "extra.patchline" must be true or false.', $name));
        }

        return $value;
    }

    private function parseEnvBoolean(string $name, string $value): bool
    {
        return match (strtolower($value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new UsageException(sprintf('%s must be 1, 0, true or false, got "%s".', $name, $value)),
        };
    }

    /**
     * @param array<string, string> $env
     * @param array<string, mixed> $extra
     */
    private function format(InputInterface $args, array $env, array $extra): OutputFormat
    {
        $value = $this->string('format', $args, $env, $extra);
        if ($value === null) {
            return ($env['GITHUB_ACTIONS'] ?? '') === 'true' ? OutputFormat::Github : OutputFormat::Text;
        }
        $format = OutputFormat::tryFrom($value);
        if ($format === null) {
            throw new UsageException(sprintf('Unknown format "%s". Use text, json, github or junit.', $value));
        }

        return $format;
    }

    /**
     * @param array<string, string> $env
     * @param array<string, mixed> $extra
     * @return list<string>
     */
    private function failOn(InputInterface $args, array $env, array $extra): array
    {
        $raw = $this->optionString($args, 'fail-on') ?? $env[self::ENV_STRINGS['fail-on']] ?? $extra['fail-on'] ?? null;
        if ($raw === null) {
            return self::DEFAULT_FAIL_ON;
        }
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            throw new UsageException('"fail-on" must be a list of states.');
        }

        $values = [];
        foreach ($raw as $item) {
            $item = is_string($item) ? trim($item) : '';
            if ($item !== self::CROSS_CHECK && !in_array($item, self::STATES, true)) {
                throw new UsageException(sprintf(
                    'Unknown state "%s" in fail-on. Use any of: %s, %s.',
                    $item,
                    implode(', ', self::STATES),
                    self::CROSS_CHECK,
                ));
            }
            $values[] = $item;
        }

        return $values;
    }
}
