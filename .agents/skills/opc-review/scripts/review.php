<?php

/**
 * Scopes a review of this repository and checks and renders the reviewers' JSON; reads only.
 */
declare(strict_types=1);

// A change made only of documentation is not announced as reviewed, and a file is documentation by what it is, never by
// its folder. Built bundles and lockfiles are not read by a reviewer, so their lines never make a diff large.
const DOCS = ['*.md', 'LICENSE', 'LICENSE.txt'];
const GENERATED = ['views/public/*', 'composer.lock', '*package-lock.json'];
// The documents a finding may cite as the rule it breaks; matched one path segment at a time.
const RULE_DOCS = ['AGENTS.md', 'README.md', 'docs/*.md'];

// Above this many changed source lines one bug voice cannot hold the diff: the skill splits it by folder.
const LARGE_DIFF = 2000;
const VOICES = ['bug', 'architecture', 'product'];
// The one place the two review profiles differ: light unless the runner asks for full.
const PROFILES = [
    'light' => ['bug_tier' => 'standard', 'second_bug_pass' => false, 'rerun' => false],
    'full' => ['bug_tier' => 'deep', 'second_bug_pass' => true, 'rerun' => true],
];
const REPORT_VOICES = [...VOICES, 'bug-second-pass'];

function matches(string $path, array $globs): bool
{
    foreach ($globs as $glob) {
        if (fnmatch($glob, $path)) {
            return true;
        }
    }

    return false;
}

/** The voices a set of changed paths calls for: every voice on any code, none on documentation alone. */
function roster(array $paths, bool $full = false): array
{
    $profile = ['profile' => $full ? 'full' : 'light'] + PROFILES[$full ? 'full' : 'light'];
    $stop = fn (string $verdict) => ['roster' => [], 'second_bug_pass' => false, 'verdict' => $verdict] + $profile;
    if ($paths === []) {
        return $stop('NO CHANGE');
    }
    $code = array_values(array_filter($paths, fn (string $p) => !matches($p, DOCS)));
    if ($code === []) {
        // No voice reads documentation: a diff made only of it is not a pass, and a maintainer reads the files named here.
        $count = count($paths) === 1 ? '1 file' : count($paths) . ' files';

        return $stop("NOT REVIEWED — documentation only, $count: " . implode(', ', array_map('pathText', $paths))
            . '. No voice reads documentation; a maintainer does.');
    }

    return ['roster' => VOICES] + $profile + ['verdict' => null];
}

/** The repository documents a finding may cite as the rule it breaks, present in the tree under review. */
function isRuleFile(string $rule, string $root): bool
{
    // A plain name only: fnmatch throws on a NUL byte, which JSON can carry.
    $file = explode('#', $rule, 2)[0];

    return preg_match('#^[\w./-]+$#D', $file) && array_filter(RULE_DOCS, fn (string $glob) => fnmatch($glob, $file, FNM_PATHNAME)) && is_file("$root/$file");
}

/** A path as the report prints it: control characters, which git allows in file names, become `?`. */
function pathText(string $path): string
{
    return preg_replace('/[\x00-\x1f\x7f]/', '?', $path);
}

/** A file of the working tree whose real location stays under the root: a symlinked folder never leads out of it. */
function insideTree(string $root, string $path): bool
{
    $real = realpath("$root/" . dirname($path));
    $base = realpath($root);

    return is_file("$root/$path") && $real !== false && $base !== false && ($real === $base || str_starts_with($real, "$base/"));
}

/** Lines of a file as it is at a git revision, or null when it has no such file. */
function linesAt(string $revision, string $path): ?int
{
    $process = @proc_open(['git', 'cat-file', 'blob', "$revision:$path"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('git cannot be started');
    }
    $lines = countLines($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0 ? $lines : null;
}

/**
 * A `path:line` or `path:start-end` inside a file under review (at the head ref when one is given, else the tree), or
 * `base:path:line` for a line the change deletes, read at the merge-base. The path holds no control character, backtick
 * or `<`, since the report prints it in a code span.
 */
function evidenceError(mixed $ref, string $root, ?string $head = null, ?string $mergeBase = null): ?string
{
    if (!is_string($ref) || !preg_match('/^(base:)?([^:\x00-\x1f\x7f`<]+):(\d+)(?:-(\d+))?$/D', $ref, $m)) {
        return 'evidence ' . json_encode($ref) . ' is not path:line (a path holds no control character, backtick or <)';
    }
    [, $onBase, $path] = $m;
    if ($onBase !== '' && $mergeBase === null) {
        return "evidence $ref: a base: anchor needs the merge-base";
    }
    if ($onBase !== '') {
        $head = $mergeBase;
    }
    if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
        return "evidence $ref: no such file in the repository";
    }
    $lines = $head !== null ? linesAt($head, $path) : (insideTree($root, $path) ? lineCount("$root/$path") : null);
    if ($lines === null) {
        return "evidence $ref: no such file in the repository";
    }
    $start = (int) $m[3];
    $end = (int) (($m[4] ?? '') !== '' ? $m[4] : $m[3]);
    if ($start < 1 || $end < $start || $end > $lines) {
        return "evidence $ref: the file has $lines lines";
    }

    return null;
}

/** Checks shared by a voice's finding and a report's finding; $statuses lists the statuses allowed at this stage. */
function findingErrors(mixed $f, string $where, array $statuses, string $root, ?string $head = null, ?string $mergeBase = null): array
{
    if (!is_array($f)) {
        return ["$where is not an object"];
    }
    $errors = [];
    foreach (['title', 'description', 'reasoning'] as $field) {
        if (!is_string($f[$field] ?? null) || trim($f[$field]) === '') {
            $errors[] = "$where: $field is required";
        }
    }
    if (!in_array($f['priority'] ?? null, ['P0', 'P1', 'P2'], true)) {
        $errors[] = "$where: priority must be P0, P1 or P2";
    }
    if (!in_array($f['status'] ?? null, $statuses, true)) {
        $errors[] = "$where: status must be one of " . implode(', ', $statuses);
    }
    if (($f['priority'] ?? null) === 'P2' && ($f['status'] ?? null) !== 'CONFIRMED') {
        $errors[] = "$where: a P2 is reported only when CONFIRMED";
    }
    if (!is_array($f['evidence'] ?? null) || $f['evidence'] === []) {
        $errors[] = "$where: evidence needs at least one path:line";
    } else {
        foreach ($f['evidence'] as $ref) {
            if ($error = evidenceError($ref, $root, $head, $mergeBase)) {
                $errors[] = "$where: $error";
            }
        }
    }
    if (isset($f['rule']) && (!is_string($f['rule']) || !isRuleFile($f['rule'], $root))) {
        $errors[] = "$where: rule must be AGENTS.md or a document it lists, present in the tree";
    }

    return $errors;
}

/** Validates one voice's return before the consolidation reads it. */
function validateVoice(mixed $v, string $root, ?string $head = null, ?string $mergeBase = null): array
{
    if (!is_array($v) || !in_array($v['voice'] ?? null, VOICES, true) || !is_array($v['findings'] ?? null)) {
        return ['a voice returns {"voice": "' . implode('|', VOICES) . '", "findings": [...]}'];
    }
    $errors = [];
    $designs = 0;
    foreach ($v['findings'] as $i => $f) {
        $where = "$v[voice] finding $i";
        $errors = array_merge($errors, findingErrors($f, $where, ['CONFIRMED', 'UNCONFIRMED'], $root, $head, $mergeBase));
        if (!is_array($f)) {
            continue;
        }
        if (($f['status'] ?? null) === 'UNCONFIRMED' && ($f['critical'] ?? false) !== true) {
            $errors[] = "$where: UNCONFIRMED is kept only for a critical scenario";
        }
        if (($f['priority'] ?? null) === 'P2' && $v['voice'] === 'product') {
            $errors[] = "$where: the product voice reports nothing below P1";
        }
        if (($f['priority'] ?? null) === 'P2' && $v['voice'] === 'architecture') {
            if (($f['design'] ?? false) !== true) {
                $errors[] = "$where: an architecture P2 is a design finding (design: true)";
            } elseif (++$designs > 3) {
                $errors[] = "$where: at most 3 P2 design findings";
            }
        }
    }

    return $errors;
}

/** Validates the consolidated report before it is rendered. */
function validateReport(mixed $r, string $root, ?string $head = null, ?string $mergeBase = null): array
{
    if (!is_array($r) || !is_array($r['voices'] ?? null) || !is_array($r['findings'] ?? null)) {
        return ['a report has "voices" and "findings" arrays'];
    }
    $errors = [];
    foreach (['base', 'head', 'merge_base', 'overview'] as $field) {
        if (!is_string($r[$field] ?? null) || $r[$field] === '') {
            $errors[] = "$field is required";
        }
    }
    if (!isset(PROFILES[$r['profile'] ?? ''])) {
        $errors[] = 'profile is ' . implode(' or ', array_keys(PROFILES));
    }
    if (isset($r['slices']) && (!is_array($r['slices']) || !array_is_list($r['slices']))) {
        $errors[] = 'slices is a list';
    } else {
        foreach ($r['slices'] ?? [] as $i => $slice) {
            if (!is_string($slice['name'] ?? null) || !preg_match('/^[^\x00-\x1f\x7f`<]+$/D', $slice['name'])
                || !in_array($slice['status'] ?? null, ['returned', 'timeout', 'failed'], true)) {
                $errors[] = "slice $i: name of a folder, status returned|timeout|failed";
            }
        }
    }
    foreach ($r['voices'] as $i => $voice) {
        if (!in_array($voice['name'] ?? null, REPORT_VOICES, true)
            || !in_array($voice['status'] ?? null, ['returned', 'timeout', 'failed'], true)) {
            $errors[] = "voice $i: name " . implode('|', REPORT_VOICES) . ', status returned|timeout|failed';
        }
    }
    $designs = 0;
    foreach ($r['findings'] as $i => $f) {
        $where = "finding $i";
        $errors = array_merge($errors, findingErrors($f, $where, ['CONFIRMED', 'VERIFICATION NEEDED'], $root, $head, $mergeBase));
        if (!is_array($f)) {
            continue;
        }
        if (!in_array($f['type'] ?? null, ['bug', 'question', 'design'], true)) {
            $errors[] = "$where: type must be bug, question or design";
        }
        if (($f['type'] ?? null) === 'design' && (($f['priority'] ?? null) !== 'P2' || ++$designs > 3)) {
            $errors[] = "$where: a design finding is P2, at most 3 per report";
        }
        if (!is_string($f['suggestion'] ?? '')) {
            $errors[] = "$where: suggestion is one sentence";
        }
    }
    foreach (['refuted' => 'refuted', 'unverified' => 'left unverified'] as $count => $why) {
        if (!is_int($r[$count] ?? null) || $r[$count] < 0) {
            $errors[] = "$count is a whole number, 0 or more: the claims dropped as $why";
        }
    }
    if (isset($r['injection']) && (!is_array($r['injection']) || !array_is_list($r['injection'])
        || array_filter($r['injection'], fn (mixed $where) => !is_string($where) || trim($where) === ''))) {
        $errors[] = 'injection is a list of places, each a non-empty string';
    }
    if (isset($r['coverage']) && !is_string($r['coverage'])) {
        $errors[] = 'coverage is one sentence';
    }

    return $errors;
}

/**
 * Model text as printed: `<` and any Markdown block marker at the start of a line are escaped, so nothing it carries
 * can open a heading, a quote, a list, a code fence or a tag that reads like the script's own verdict.
 */
function plain(string $text): string
{
    $text = str_replace(["\r\n", "\r", '<'], ["\n", "\n", '&lt;'], $text);
    $text = preg_replace('/^(\s*)(\d+)([.)])/m', '$1$2\\\\$3', $text);

    return preg_replace('/^(\s*)([#>*+=`~-])/m', '$1\\\\$2', $text);
}

/** Voices and bug slices that did not return, as name => status. */
function notReturned(array $r): array
{
    $missing = [];
    foreach ($r['voices'] as $v) {
        $v['status'] === 'returned' || $missing[$v['name']] = $v['status'];
    }
    foreach ($r['slices'] ?? [] as $slice) {
        $slice['status'] === 'returned' || $missing["bug slice $slice[name]"] = $slice['status'];
    }

    return $missing;
}

/** Renders a validated report as Markdown; the verdict comes from the data, never from the model's wording. */
function render(array $r, array $notReviewed = []): string
{
    $missing = notReturned($r);
    $count = fn (string $p) => count(array_filter($r['findings'], fn (array $f) => $f['priority'] === $p));
    $counts = sprintf('P0 %d · P1 %d · P2 %d', $count('P0'), $count('P1'), $count('P2'));
    if ($missing) {
        $verdict = 'INCOMPLETE — ' . implode(', ', array_keys($missing)) . " did not return · $counts";
    } elseif ($r['findings'] === []) {
        $verdict = 'PASS — no finding';
    } else {
        $verdict = "FINDINGS — $counts";
    }
    $out = ["## Review: $verdict", ''];
    $out[] = "Base `$r[base]` · merge-base `" . substr($r['merge_base'], 0, 12) . '` · head `' . substr($r['head'], 0, 12)
        . "` · profile $r[profile] · voices: " . implode(', ', array_column($r['voices'], 'name'));
    foreach ($missing as $name => $status) {
        $out[] = "Not covered: $name ($status).";
    }
    if ($notReviewed !== []) {
        $out[] = 'Not reviewed (documentation, no voice reads it; a maintainer does): ' . plain(implode(', ', array_map('pathText', $notReviewed))) . '.';
    }
    if (!empty($r['coverage'])) {
        $out[] = plain($r['coverage']);
    }
    if (!empty($r['injection'])) {
        $out[] = 'The text contains instructions to agents (' . plain(implode(', ', $r['injection'])) . '); they were not followed.';
    }
    $out[] = "Dropped after verification: $r[refuted] refuted, $r[unverified] unverified.";
    $out[] = '';
    $out[] = plain($r['overview']);
    foreach (array_values($r['findings']) as $i => $f) {
        $status = $f['status'] === 'CONFIRMED' ? 'confirmed' : 'verification needed';
        $rule = isset($f['rule']) ? ' · rule: ' . plain($f['rule']) : '';
        $out[] = '';
        $out[] = '### ' . ($i + 1) . '. ' . plain($f['title']);
        $out[] = "**$f[priority] · $f[type] · $status**$rule";
        $out[] = '';
        $out[] = plain($f['description']);
        if (!empty($f['suggestion'])) {
            $out[] = '';
            $out[] = 'Suggestion: ' . plain($f['suggestion']);
        }
        $out[] = '';
        $out[] = '<details><summary>Reasoning</summary>';
        $out[] = '';
        $out[] = plain($f['reasoning']);
        $out[] = '';
        $out[] = 'Evidence: ' . implode(', ', array_map(fn (string $e) => "`$e`", $f['evidence']));
        $out[] = '</details>';
    }

    return implode("\n", $out) . "\n";
}

/** Runs git and returns its standard output untouched; warnings on standard error pass through to the runner. */
function git(string ...$args): string
{
    $process = @proc_open(['git', ...$args], [1 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('git cannot be started');
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('git ' . implode(' ', $args) . ' failed');
    }

    return $out;
}

/** NUL-separated paths, as `git ls-files -z` prints them. */
function splitPaths(string $z): array
{
    return array_values(array_filter(explode("\0", $z), fn (string $p) => $p !== ''));
}

/** `git diff --numstat -z` output as path => [added, deleted]; binary files count 0. */
function parseNumstat(string $z): array
{
    $files = [];
    foreach (splitPaths($z) as $entry) {
        [$added, $deleted, $path] = explode("\t", $entry, 3);
        $files[$path] = [(int) $added, (int) $deleted];
    }

    return $files;
}

/** Lines of a file; a symlink counts one line and is never followed out of the repository. */
function lineCount(string $path): int
{
    if (is_link($path)) {
        return 1;
    }
    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return 0;
    }
    $lines = countLines($handle);
    fclose($handle);

    return $lines;
}

/** Counts lines in fixed-size chunks, so a huge file with no newline never sits in memory whole. */
function countLines($handle): int
{
    $lines = 0;
    $last = "\n";
    while (($chunk = fread($handle, 65536)) !== false && $chunk !== '') {
        $lines += substr_count($chunk, "\n");
        $last = $chunk[-1];
    }

    return $lines + ($last === "\n" ? 0 : 1);
}

/** A base git reads as a revision, never as one of its own options. */
function isRef(string $base): bool
{
    return $base !== '' && !str_starts_with($base, '-');
}

/**
 * Changed files between the merge-base of the base and the head. `HEAD` is the runner's own branch, working tree and
 * untracked files included; any other head is a fetched ref, read through git so the working tree stays the base.
 */
function scope(string $base, bool $full = false, string $head = 'HEAD'): array
{
    chdir(trim(git('rev-parse', '--show-toplevel')));
    $mergeBase = trim(git('merge-base', $base, $head));
    if ($head === 'HEAD') {
        $files = parseNumstat(git('diff', '--numstat', '-z', '--no-renames', $mergeBase));
        foreach (splitPaths(git('ls-files', '-z', '--others', '--exclude-standard')) as $path) {
            $files[$path] = [lineCount($path), 0];
        }
    } else {
        $files = parseNumstat(git('diff', '--numstat', '-z', '--no-renames', $mergeBase, $head));
    }
    $changed = 0;
    $list = [];
    foreach ($files as $path => [$added, $deleted]) {
        $path = (string) $path;
        $kind = matches($path, DOCS) ? 'docs' : (matches($path, GENERATED) ? 'generated' : 'code');
        $changed += $kind === 'code' ? $added + $deleted : 0;
        $list[] = ['path' => $path, 'kind' => $kind, 'added' => $added, 'deleted' => $deleted];
    }

    $scope = [
        'base' => $base,
        'merge_base' => $mergeBase,
        'head' => trim(git('rev-parse', '--short', "$head^{commit}")),
        'head_sha' => trim(git('rev-parse', "$head^{commit}")),
        'head_ref' => $head === 'HEAD' ? null : $head,
        'files' => $list,
        'large' => $changed > LARGE_DIFF,
        'slices' => $changed > LARGE_DIFF ? slices(array_column(array_filter($list, fn (array $f) => $f['kind'] === 'code'), 'path')) : [],
    ] + roster(array_column($list, 'path'), $full);
    if ($scope['verdict'] === 'NO CHANGE') {
        // An empty diff is not a pass: nothing was read, and the runner may be on the wrong branch.
        $scope['verdict'] = "NO CHANGE — no file differs between $base (" . trim(git('rev-parse', '--short', $mergeBase)) . ") and $head ($scope[head]); nothing was reviewed";
    }

    return $scope;
}

/** The top-level folders a large diff's code is split into, one bug run each; files at the root form `.`. */
function slices(array $paths): array
{
    $folders = array_unique(array_map(fn (string $p) => str_contains($p, '/') ? explode('/', $p, 2)[0] : '.', $paths));
    sort($folders);

    return $folders;
}

/** Checks a report against the scope of the base the runner gave, then renders it: [errors, markdown]. */
function checkedRender(mixed $report, string $root, array $scope, string $base): array
{
    if ($scope['verdict'] !== null) {
        return [[$scope['verdict']], ''];
    }
    $errors = validateReport($report, $root, $scope['head_ref'] ?? null, $scope['merge_base']);
    if ($errors !== []) {
        return [$errors, ''];
    }
    $pinned = ['base' => $base, 'head' => $scope['head_sha'], 'merge_base' => $scope['merge_base'], 'profile' => $scope['profile']];
    foreach ($pinned as $field => $value) {
        if ($report[$field] !== $value) {
            $ran = ['base' => 'against', 'head' => 'on', 'merge_base' => 'on', 'profile' => ''][$field];

            return [["$field: the report says {$report[$field]}, the review ran " . ltrim("$ran $value")], ''];
        }
    }
    $called = $scope['second_bug_pass'] ? [...$scope['roster'], 'bug-second-pass'] : $scope['roster'];
    $errors = [
        ...listErrors('voices', 'called', $called, array_column($report['voices'], 'name')),
        ...listErrors('slices', 'split out', $scope['slices'], array_column($report['slices'] ?? [], 'name')),
    ];

    $docs = array_column(array_filter($scope['files'] ?? [], fn (array $f) => $f['kind'] === 'docs'), 'path');

    return $errors === [] ? [[], render($report, $docs)] : [$errors, ''];
}

/** `[--base REF] [--head REF] [--full]` in any order, each once, or null when the arguments are anything else. */
function scopeArgs(array $args): ?array
{
    $parsed = ['base' => null, 'full' => false, 'head' => null];
    while ($args !== []) {
        $arg = array_shift($args);
        $option = ['--base' => 'base', '--head' => 'head'][$arg] ?? null;
        if ($arg === '--full' && !$parsed['full']) {
            $parsed['full'] = true;
        } elseif ($option !== null && $parsed[$option] === null && isRef($args[0] ?? '')) {
            $parsed[$option] = array_shift($args);
        } else {
            return null;
        }
    }

    return ['base' => $parsed['base'] ?? 'origin/main', 'full' => $parsed['full'], 'head' => $parsed['head'] ?? 'HEAD'];
}

/** `render FILE --base REF [--head REF] [--full]`: the file, then the scope's options with the base required. */
function renderArgs(array $argv): ?array
{
    $file = $argv[2] ?? '';
    $args = array_slice($argv, 3);
    if ($file === '' || str_starts_with($file, '--') || !in_array('--base', $args, true)) {
        return null;
    }
    $parsed = scopeArgs($args);

    return $parsed === null ? null : ['file' => $file] + $parsed;
}

/** Reads a report file and checks it against the scope of the runner's base and profile: [errors, markdown]. */
function renderReport(array $args): array
{
    $report = readJson($args['file']);

    return [...checkedRender($report, trim(git('rev-parse', '--show-toplevel')), scope($args['base'], $args['full'], $args['head']), $args['base']), $report];
}

/** A report lists exactly the voices or slices the scope set, each once: a missing one hides a gap, an extra one claims coverage. */
function listErrors(string $what, string $set, array $expected, array $listed): array
{
    $errors = [];
    foreach (array_diff($expected, $listed) as $name) {
        $errors[] = "$what: $name was $set by the scope and is missing";
    }
    foreach (array_unique(array_diff($listed, $expected)) as $name) {
        $errors[] = "$what: $name was not $set by the scope";
    }
    foreach (array_unique(array_diff_assoc($listed, array_unique($listed))) as $name) {
        $errors[] = "$what: $name is listed more than once";
    }

    return $errors;
}

/** Reads a JSON document from a file; an unreadable file stops the run, invalid JSON is the caller's to report. */
function readJson(string $path): mixed
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("cannot read $path");
    }

    return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
}

// Loaded by review-test.php for its functions, which defines this constant: the command line below never runs then.
if (defined('OPC_REVIEW_LIBRARY')) {
    return;
}

$usage = "usage: php review.php scope [--base REF] [--head REF] [--full] | voice FILE [--base REF] [--head REF] | render FILE --base REF [--head REF] [--full]\n";
try {
    switch ($argv[1] ?? '') {
        case 'scope':
            $args = scopeArgs(array_slice($argv, 2));
            if ($args === null) {
                fwrite(STDERR, $usage);
                exit(2);
            }
            $scope = scope($args['base'], $args['full'], $args['head']);
            echo json_encode($scope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), "\n";
            exit($scope['verdict'] === null ? 0 : 4);
        case 'voice':
            $args = isset($argv[2]) && !str_starts_with($argv[2], '--') ? scopeArgs(array_slice($argv, 3)) : null;
            if ($args === null) {
                fwrite(STDERR, $usage);
                exit(2);
            }
            $mergeBase = in_array('--base', $argv, true) ? trim(git('merge-base', $args['base'], $args['head'])) : null;
            $errors = validateVoice(readJson($argv[2]), trim(git('rev-parse', '--show-toplevel')), $args['head'] === 'HEAD' ? null : $args['head'], $mergeBase);
            fwrite($errors ? STDERR : STDOUT, ($errors ? implode("\n", $errors) : 'valid') . "\n");
            exit($errors ? 1 : 0);
        case 'render':
            $args = renderArgs($argv);
            if ($args === null) {
                fwrite(STDERR, $usage);
                exit(2);
            }
            [$errors, $markdown, $report] = renderReport($args) + [2 => null];
            fwrite($errors ? STDERR : STDOUT, $errors ? implode("\n", $errors) . "\n" : $markdown);
            exit($errors ? 1 : (notReturned($report) === [] ? 0 : 3));
        default:
            fwrite(STDERR, $usage);
            exit(2);
    }
} catch (JsonException $e) {
    fwrite(STDERR, 'invalid JSON: ' . $e->getMessage() . "\n");
    exit(1);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
