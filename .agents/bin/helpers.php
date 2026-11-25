<?php

declare(strict_types=1);

/*
 * Command-line plumbing the repository's skill scripts share: running gh and git without a shell, reading GitHub, and
 * parsing the Markdown tables the scripts keep their data in.
 */

/** Runs a command without a shell; a non-zero exit stops the script with the command's error. */
function run(array $cmd, ?string $stdin = null): string
{
    $errFile = tmpfile() ?: throw new RuntimeException('cannot create a temporary file');
    $proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], $errFile], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException("cannot run {$cmd[0]}");
    }
    fwrite($pipes[0], $stdin ?? '');
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($proc);
    rewind($errFile);
    $err = (string) stream_get_contents($errFile);
    if (0 !== $status) {
        throw new RuntimeException(implode(' ', $cmd) . ' failed: ' . trim($err), $status);
    }

    return $out;
}

function gh(string ...$args): mixed
{
    return json_decode(run(['gh', ...$args]), true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<array> every item of a paginated REST list */
function ghList(string $path): array
{
    return array_merge(...gh('api', '--paginate', '--slurp', $path));
}

function repo(): string
{
    static $repo = null;

    return $repo ??= gh('repo', 'view', '--json', 'nameWithOwner')['nameWithOwner'];
}

function repoRoot(): string
{
    static $root = null;

    return $root ??= trim(run(['git', '-C', __DIR__, 'rev-parse', '--show-toplevel']));
}

/** @return list<string> */
function repoLabels(): array
{
    return array_column(ghList('repos/' . repo() . '/labels'), 'name');
}

/**
 * Rows of the Markdown table whose header row starts with $header.
 *
 * @return list<list<string>>
 */
function mdTable(string $file, string $header): array
{
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: throw new RuntimeException("cannot read {$file}");
    $cells = static fn (string $l): array => array_map(static fn ($c) => trim($c, " `\t"), explode('|', substr(trim($l), 1, -1)));
    foreach ($lines as $i => $line) {
        $next = $lines[$i + 1] ?? '';
        if (!str_starts_with($line, '|') || !preg_match('/^\|\s*-/', $next) || $cells($line)[0] !== $header) {
            continue;
        }
        $rows = [];
        for ($j = $i + 2; str_starts_with($lines[$j] ?? '', '|'); ++$j) {
            $rows[] = $cells($lines[$j]);
        }

        return $rows;
    }
    throw new RuntimeException("no table '{$header}' in {$file}");
}

function json(mixed $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

function option(array $args, string $name): ?string
{
    $i = array_search($name, $args, true);

    return false === $i ? null : ($args[$i + 1] ?? throw new InvalidArgumentException("{$name} needs a value"));
}

function issueNumber(?string $value): int
{
    return null !== $value && ctype_digit($value) ? (int) $value : throw new InvalidArgumentException('an issue or pull request number is required');
}
