<?php

/**
 * Self-test of review.php: the roster by path, the JSON checks and the rendered verdicts, on a throwaway fixture tree.
 */
declare(strict_types=1);

define('OPC_REVIEW_LIBRARY', true);
require __DIR__ . '/review.php';

/** A readable in-memory stream holding $text, for the self-test. */
function memoryStream(string $text)
{
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $text);
    rewind($handle);

    return $handle;
}

/** A temporary file holding $text, removed when the self-test ends, even after a failure. */
function tempFile(string $prefix, string $text): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);
    file_put_contents($path, $text);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

/** A throwaway tree the self-test checks evidence and rules against, so it never depends on the repository's files. */
function fixtureTree(): string
{
    $root = sys_get_temp_dir() . '/opc-review-self-test-' . bin2hex(random_bytes(4));
    if (!@mkdir($root, 0700) || !mkdir("$root/src") || !mkdir("$root/src/Checkout") || !mkdir("$root/docs") || !mkdir("$root/docs/E2E_JOURNEY_x")) {
        throw new RuntimeException('self-test: cannot create a fixture under ' . sys_get_temp_dir());
    }
    foreach (['src/a.php' => "<?php\n\nline 3\nline 4\nline 5\n", 'README.md' => "# Read me\n", 'docs/E2E_JOURNEY_a.md' => "# Journey\n", 'src/Checkout/b.php' => "<?php\n", 'docs/E2E_JOURNEY_x/y.md' => "# x\n"] as $path => $text) {
        file_put_contents("$root/$path", $text);
    }
    @symlink('src/a.php', "$root/link");
    register_shutdown_function(function () use ($root) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    });

    return $root;
}

/** Runs $run inside $root with git's location variables cleared, as a hook may set them for the caller's repository. */
function withCleanGit(string $root, callable $run): bool
{
    $cwd = getcwd();
    $saved = [];
    foreach (['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_COMMON_DIR', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES'] as $name) {
        $saved[$name] = getenv($name);
        putenv($name);
    }
    chdir($root);
    try {
        return $run();
    } finally {
        chdir($cwd);
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }
}

/** Runs $git_steps on a fresh fixture repository with a committed base; returns what $check returns. */
function onRepository(callable $change, callable $check): bool
{
    $root = fixtureTree();

    return withCleanGit($root, function () use ($change, $check, $root) {
        git('init', '--quiet');
        git('add', '--all');
        git('-c', 'user.name=self-test', '-c', 'user.email=self-test@localhost', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '--message', 'base');
        $change($root);

        return $check($root);
    });
}

/** The diff's numbers, kinds, renames and deleted lines, read on a real repository. */
function diffOnFixture(): bool
{
    $big = onRepository(
        fn () => file_put_contents('src/a.php', str_repeat("// line\n", LARGE_DIFF + 1), FILE_APPEND) && mkdir('views/public', 0700, true)
            && file_put_contents('views/public/x.js', "x\n") && file_put_contents('README.md', "more\n", FILE_APPEND),
        function () {
            $s = scope('HEAD');
            $kinds = array_column($s['files'], 'kind', 'path');

            return $s['large'] && $s['slices'] === ['src']
                && $kinds === ['README.md' => 'docs', 'src/a.php' => 'code', 'views/public/x.js' => 'generated'];
        }
    );
    $notCode = onRepository(
        fn () => file_put_contents('README.md', str_repeat("more\n", LARGE_DIFF + 1), FILE_APPEND) && mkdir('views/public', 0700, true)
            && file_put_contents('views/public/x.js', str_repeat("x\n", LARGE_DIFF + 1)) && file_put_contents('src/a.php', "// changed\n", FILE_APPEND),
        fn () => !scope('HEAD')['large'] && scope('HEAD')['slices'] === []
    );
    $renamed = onRepository(fn () => git('mv', 'src/Checkout/b.php', 'src/Checkout/c.php'),
        fn () => array_column(scope('HEAD')['files'], 'path') === ['src/Checkout/b.php', 'src/Checkout/c.php']);
    $deleted = onRepository(fn () => unlink('src/a.php'), function ($root) {
        $s = scope('HEAD');
        $finding = ['title' => 't', 'description' => 'd', 'priority' => 'P1', 'status' => 'CONFIRMED', 'reasoning' => 'r'];

        return validateVoice(['voice' => 'bug', 'findings' => [['evidence' => ['base:src/a.php:5']] + $finding]], $root, null, $s['merge_base']) === []
            && validateVoice(['voice' => 'bug', 'findings' => [['evidence' => ['base:src/a.php:6']] + $finding]], $root, null, $s['merge_base']) !== []
            && validateVoice(['voice' => 'bug', 'findings' => [['evidence' => ['src/a.php:5']] + $finding]], $root, null, $s['merge_base']) !== [];
    });

    return $big && $notCode && $renamed && $deleted;
}

/** Exit statuses and output streams of the command line itself, run as a separate process on a real repository. */
function commandLineOnFixture(): bool
{
    return onRepository(fn () => file_put_contents('src/a.php', "// changed\n", FILE_APPEND), function ($root) {
        $run = function (string ...$args) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/review.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $out, $err];
        };
        $voice = tempFile('opc-review-self-test-voice-', '{"voice": "bug", "findings": []}');
        $bad = tempFile('opc-review-self-test-bad-', '{"voice": "style", "findings": []}');
        [$scopeCode, $scopeOut] = $run('scope', '--base', 'HEAD');
        $scope = json_decode($scopeOut, true);
        $report = fn (string $architecture) => tempFile('opc-review-self-test-report-', json_encode(['base' => 'HEAD', 'head' => $scope['head_sha'], 'merge_base' => $scope['merge_base'], 'profile' => 'light', 'overview' => 'o', 'refuted' => 0, 'unverified' => 0, 'findings' => [],
            'voices' => [['name' => 'bug', 'status' => 'returned'], ['name' => 'architecture', 'status' => $architecture], ['name' => 'product', 'status' => 'returned']]]));
        $results = [
            $scopeCode === 0,
            $run('voice', $voice) === [0, "valid\n", ''],
            ($v = $run('voice', $bad))[0] === 1 && $v[1] === '' && $v[2] !== '',
            ($r = $run('render', $report('timeout'), '--base', 'HEAD'))[0] === 3 && str_starts_with($r[1], '## Review: INCOMPLETE'),
            $run('render', $report('returned'), '--base', 'HEAD')[0] === 0,
            $run('render', $report('returned'))[0] === 2,
            $run('voice', "$root/missing.json")[0] === 2,
        ];
        git('checkout', '--quiet', '--', 'src/a.php');
        file_put_contents('README.md', "more\n", FILE_APPEND);
        $results[] = $run('scope', '--base', 'HEAD')[0] === 4;

        return !in_array(false, $results, true);
    });
}

/**
 * Reviews pull request refs the way a maintainer does: the working tree stays on the base branch, each change lives
 * only in a fetched ref, and the base's script and rules judge it.
 */
function refReviewOnFixture(): bool
{
    $root = fixtureTree();

    return withCleanGit($root, function () use ($root) {
        $commit = fn (string $message) => git('-c', 'user.name=self-test', '-c', 'user.email=self-test@localhost', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '--message', $message);
        git('init', '--quiet');
        git('add', '--all');
        $commit('base');
        $base = trim(git('rev-parse', '--abbrev-ref', 'HEAD'));
        git('checkout', '--quiet', '-b', 'code-change');
        file_put_contents('src/a.php', "// changed by the pull request\n", FILE_APPEND);
        file_put_contents('docs/E2E_JOURNEY_pr.md', "# A journey only the pull request has\n");
        git('mv', 'src/Checkout/b.php', 'src/Checkout/c.php');
        git('add', '--all');
        $commit('code');
        git('update-ref', 'refs/opc-review/code', 'HEAD');
        git('checkout', '--quiet', $base);
        git('checkout', '--quiet', '-b', 'script-change');
        mkdir('.agents/skills/opc-review/scripts', 0700, true);
        file_put_contents('.agents/skills/opc-review/scripts/review.php', "<?php // a changed copy of the script\n");
        git('add', '--all');
        $commit('script');
        git('update-ref', 'refs/opc-review/script', 'HEAD');
        git('checkout', '--quiet', $base);

        git('-c', 'tag.gpgSign=false', 'tag', '--annotate', '--message', 'tag', 'v-code', 'refs/opc-review/code');
        $tagged = scope($base, false, 'v-code');
        $same = scope($base, false, $base);
        $code = scope($base, false, 'refs/opc-review/code');
        $script = scope($base, false, 'refs/opc-review/script');
        $finding = ['title' => 't', 'description' => 'd', 'priority' => 'P1', 'status' => 'CONFIRMED', 'reasoning' => 'r'];
        $atRef = validateVoice(['voice' => 'bug', 'findings' => [['evidence' => ['src/a.php:6']] + $finding]], $root, 'refs/opc-review/code');
        $inTree = validateVoice(['voice' => 'bug', 'findings' => [['evidence' => ['src/a.php:6']] + $finding]], $root);
        $prRule = validateVoice(['voice' => 'bug', 'findings' => [['evidence' => ['src/a.php:6'], 'rule' => 'docs/E2E_JOURNEY_pr.md'] + $finding]], $root, 'refs/opc-review/code');
        $report = tempFile('opc-review-self-test-report-', json_encode(['base' => $base, 'head' => $code['head_sha'], 'merge_base' => $code['merge_base'], 'profile' => 'light', 'overview' => 'Nothing qualifies.', 'refuted' => 0, 'unverified' => 0, 'findings' => [],
            'voices' => [['name' => 'bug', 'status' => 'returned'], ['name' => 'architecture', 'status' => 'returned'], ['name' => 'product', 'status' => 'returned']]]));
        $rendered = renderReport(['file' => $report, 'base' => $base, 'full' => false, 'head' => 'refs/opc-review/code']);

        return array_column($code['files'], 'path') === ['docs/E2E_JOURNEY_pr.md', 'src/Checkout/b.php', 'src/Checkout/c.php', 'src/a.php']
            && $code['roster'] === ['bug', 'architecture', 'product']
            && $code['head_ref'] === 'refs/opc-review/code' && $code['head'] === trim(git('rev-parse', '--short', 'refs/opc-review/code'))
            && !str_contains(file_get_contents('src/a.php'), 'pull request') && !file_exists('.agents')
            && $script['verdict'] === null && $script['roster'] === ['bug', 'architecture', 'product'] && !file_exists('.agents')
            && $atRef === [] && $inTree !== [] && $prRule === ['bug finding 0: rule must be AGENTS.md or a document it lists, present in the tree']
            && $rendered[0] === [] && str_contains($rendered[1], '## Review: PASS — no finding')
            && str_contains($rendered[1], 'Not reviewed (documentation, no voice reads it; a maintainer does): docs/E2E_JOURNEY_pr.md.')
            && $tagged['head_sha'] === trim(git('rev-parse', 'refs/opc-review/code'))
            && str_contains((string) $same['verdict'], "and $base (") && !str_contains((string) $same['verdict'], 'HEAD (');
    });
}

/** Runs `scope` end to end on a git repository made of the fixture: a module change under both profiles, then render. */
function scopeOnFixture(): bool
{
    return onRepository(fn () => file_put_contents('src/a.php', "// changed\n", FILE_APPEND), function () {
        $light = scope('HEAD');
        $full = scope('HEAD', true);
        $report = tempFile('opc-review-self-test-report-', json_encode(['base' => 'HEAD', 'head' => $full['head_sha'], 'merge_base' => $full['merge_base'], 'profile' => 'full', 'overview' => 'Nothing qualifies.', 'refuted' => 0, 'unverified' => 0, 'findings' => [],
            'voices' => [['name' => 'bug', 'status' => 'returned'], ['name' => 'architecture', 'status' => 'returned'], ['name' => 'product', 'status' => 'returned'], ['name' => 'bug-second-pass', 'status' => 'returned']]]));
        $asFull = renderReport(['file' => $report, 'base' => 'HEAD', 'full' => true, 'head' => 'HEAD']);
        $asLight = renderReport(['file' => $report, 'base' => 'HEAD', 'full' => false, 'head' => 'HEAD']);

        return array_column($light['files'], 'path') === ['src/a.php'] && $light['roster'] === ['bug', 'architecture', 'product']
            && $light['profile'] === 'light' && $full['profile'] === 'full' && $full['bug_tier'] === 'deep' && $full['rerun'] === true
            && $asFull[0] === [] && str_contains($asFull[1], '## Review: PASS — no finding')
            && $asLight[0] === ['profile: the report says full, the review ran light'];
    });
}

function selfTest(): int
{
    $cases = [
        'no file' => [[], ['roster' => [], 'second_bug_pass' => false, 'verdict' => 'NO CHANGE']],
        'docs only' => [['docs/RULES.md', 'README.md'], ['roster' => [], 'second_bug_pass' => false, 'verdict' => 'NOT REVIEWED — documentation only, 2 files: docs/RULES.md, README.md. No voice reads documentation; a maintainer does.']],
        'code change' => [['src/Any.php'], ['roster' => ['bug', 'architecture', 'product'], 'second_bug_pass' => true, 'verdict' => null]],
        'php under docs' => [['docs/shell.php'], ['roster' => ['bug', 'architecture', 'product'], 'second_bug_pass' => true, 'verdict' => null]],
        'php named like the licence' => [['LICENSE.php'], ['roster' => ['bug', 'architecture', 'product'], 'second_bug_pass' => true, 'verdict' => null]],
        'licence and docs' => [['LICENSE.md', 'LICENSE', 'LICENSE.txt'], ['roster' => [], 'second_bug_pass' => false, 'verdict' => 'NOT REVIEWED — documentation only, 3 files: LICENSE.md, LICENSE, LICENSE.txt. No voice reads documentation; a maintainer does.']],
        'bundle only' => [['views/public/any.bundle.js'], ['roster' => ['bug', 'architecture', 'product'], 'second_bug_pass' => true, 'verdict' => null]],
    ];
    $failures = 0;
    foreach ($cases as $name => [$paths, $expected]) {
        $actual = array_intersect_key(roster($paths, true), $expected);
        if ($actual !== $expected) {
            ++$failures;
            fwrite(STDERR, "FAIL roster: $name\n  expected " . json_encode($expected) . "\n  actual   " . json_encode($actual) . "\n");
        }
    }

    $root = fixtureTree();
    $finding = ['title' => 'Carrier lost', 'description' => 'The carrier is dropped.', 'priority' => 'P1', 'status' => 'CONFIRMED', 'evidence' => ['src/a.php:2'], 'reasoning' => 'trigger, path, result'];
    $voiceCases = [
        'valid voice' => [['voice' => 'bug', 'findings' => [$finding]], 0],
        'empty voice' => [['voice' => 'product', 'findings' => []], 0],
        'unknown voice' => [['voice' => 'style', 'findings' => []], 1],
        'missing line' => [['voice' => 'bug', 'findings' => [['evidence' => ['src/a.php:6']] + $finding]], 1],
        'path outside' => [['voice' => 'bug', 'findings' => [['evidence' => ['../x:1']] + $finding]], 1],
        'path climbing back in' => [['voice' => 'bug', 'findings' => [['evidence' => ['../' . basename($root) . '/src/a.php:2']] + $finding]], 1],
        'no evidence' => [['voice' => 'bug', 'findings' => [['evidence' => []] + $finding]], 1],
        'P2 unconfirmed' => [['voice' => 'bug', 'findings' => [['priority' => 'P2', 'status' => 'UNCONFIRMED', 'critical' => true] + $finding]], 1],
        'unconfirmed not critical' => [['voice' => 'bug', 'findings' => [['status' => 'UNCONFIRMED'] + $finding]], 1],
        'unconfirmed critical' => [['voice' => 'bug', 'findings' => [['status' => 'UNCONFIRMED', 'critical' => true] + $finding]], 0],
        'product P2' => [['voice' => 'product', 'findings' => [['priority' => 'P2'] + $finding]], 1],
        'architecture P2 not design' => [['voice' => 'architecture', 'findings' => [['priority' => 'P2'] + $finding]], 1],
        'architecture four designs' => [['voice' => 'architecture', 'findings' => array_fill(0, 4, ['priority' => 'P2', 'design' => true] + $finding)], 1],
        'rule outside the docs' => [['voice' => 'bug', 'findings' => [['rule' => 'src/a.php'] + $finding]], 1],
        'rule with anchor' => [['voice' => 'bug', 'findings' => [['rule' => 'README.md#contributing'] + $finding]], 0],
    ];
    foreach ($voiceCases as $name => [$voice, $errors]) {
        if (count(validateVoice($voice, $root)) !== $errors) {
            ++$failures;
            fwrite(STDERR, "FAIL voice: $name\n  " . json_encode(validateVoice($voice, $root)) . "\n");
        }
    }

    $item = ['type' => 'bug', 'suggestion' => 'Re-key it.'] + $finding;
    $report = ['base' => 'origin/main', 'head' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'voices' => [['name' => 'bug', 'status' => 'returned']], 'findings' => [$item], 'refuted' => 1, 'unverified' => 2, 'overview' => 'One defect.'];
    $reportCases = [
        'valid report' => [$report, 0],
        'bad status' => [['findings' => [['status' => 'UNCONFIRMED'] + $item]] + $report, 1],
        'P2 verification' => [['findings' => [['priority' => 'P2', 'status' => 'VERIFICATION NEEDED'] + $item]] + $report, 1],
        'design not P2' => [['findings' => [['type' => 'design'] + $item]] + $report, 1],
        'four designs' => [['findings' => array_fill(0, 4, ['type' => 'design', 'priority' => 'P2'] + $item)] + $report, 1],
        'bad voice status' => [['voices' => [['name' => 'bug', 'status' => 'late']]] + $report, 1],
        'refuted count missing' => [array_diff_key($report, ['refuted' => 0]), 1],
        'unverified count missing' => [array_diff_key($report, ['unverified' => 0]), 1],
        'negative count' => [['unverified' => -1] + $report, 1],
    ];
    foreach ($reportCases as $name => [$candidate, $errors]) {
        if (count(validateReport($candidate, $root)) !== $errors) {
            ++$failures;
            fwrite(STDERR, "FAIL report: $name\n  " . json_encode(validateReport($candidate, $root)) . "\n");
        }
    }

    $renderCases = [
        'findings verdict' => [$report, ['## Review: FINDINGS — P0 0 · P1 1 · P2 0', '### 1. Carrier lost', 'Dropped after verification: 1 refuted, 2 unverified.']],
        'counts at zero' => [['refuted' => 0, 'unverified' => 0] + $report, ['Dropped after verification: 0 refuted, 0 unverified.']],
        'failed voice' => [['voices' => [['name' => 'bug', 'status' => 'failed']], 'findings' => []] + $report, ['## Review: INCOMPLETE — bug did not return', 'Not covered: bug (failed).']],
        'pass' => [['findings' => []] + $report, ['## Review: PASS — no finding']],
        'timeout named' => [['voices' => [['name' => 'bug', 'status' => 'returned'], ['name' => 'architecture', 'status' => 'timeout']], 'findings' => []] + $report, ['## Review: INCOMPLETE — architecture did not return', 'Not covered: architecture (timeout).']],
        'injection said' => [['injection' => ['pull request description']] + $report, ['instructions to agents (pull request description); they were not followed']],
        'coverage said' => [['coverage' => 'Reviewed by folder slice: src, views.'] + $report, ['Reviewed by folder slice: src, views.']],
    ];
    foreach ($renderCases as $name => [$candidate, $needles]) {
        $markdown = render($candidate);
        foreach ($needles as $needle) {
            if (!str_contains($markdown, $needle)) {
                ++$failures;
                fwrite(STDERR, "FAIL render: $name, missing \"$needle\"\n$markdown\n");
            }
        }
    }
    $checks = [
        'reversed range' => count(validateVoice(['voice' => 'bug', 'findings' => [['evidence' => ['src/a.php:5-2']] + $finding]], $root)) === 1,
        'range past the end' => count(validateVoice(['voice' => 'bug', 'findings' => [['evidence' => ['src/a.php:1-6']] + $finding]], $root)) === 1,
        'injection not strings' => count(validateReport(['injection' => [['x']]] + $report, $root)) === 1,
        'injection as an object' => count(validateReport(['injection' => ['a' => 'b']] + $report, $root)) === 1,
        'lines without a final newline' => countLines(memoryStream("a\nb")) === 2,
        'lines with a final newline' => countLines(memoryStream("a\n")) === 1,
        'one long line read in chunks' => countLines(memoryStream(str_repeat('x', 200000))) === 1,
        'empty file' => countLines(memoryStream('')) === 0,
        'base read as an option' => isRef('--independent') === false,
        'base as a ref' => isRef('origin/main') === true,
        'injection empty string' => count(validateReport(['injection' => ['']] + $report, $root)) === 1,
        'numstat -z' => parseNumstat(implode("\0", ["1\t0\tREADME.md", "-\t-\tlogo.png", "3\t1\ta\tb.php", ''])) === ['README.md' => [1, 0], 'logo.png' => [0, 0], "a\tb.php" => [3, 1]],
        'path list -z' => splitPaths("0\0x y \0") === ['0', 'x y '],
        'line count' => lineCount(__FILE__) === count(file(__FILE__)),
        'symlink not followed' => is_link("$root/link") ? lineCount("$root/link") === 1 : PHP_OS_FAMILY === 'Windows',
        'scope default base' => scopeArgs([]) === ['base' => 'origin/main', 'full' => false, 'head' => 'HEAD'],
        'scope named base' => scopeArgs(['--base', 'pr-base']) === ['base' => 'pr-base', 'full' => false, 'head' => 'HEAD'],
        'scope full profile' => scopeArgs(['--base', 'pr-base', '--full']) === ['base' => 'pr-base', 'full' => true, 'head' => 'HEAD'],
        'scope full first' => scopeArgs(['--full', '--base', 'pr-base']) === ['base' => 'pr-base', 'full' => true, 'head' => 'HEAD'],
        'scope full twice' => scopeArgs(['--full', '--full']) === null,
        'scope base missing' => scopeArgs(['--base']) === null,
        'scope base glued' => scopeArgs(['--base=x']) === null,
        'scope bare ref' => scopeArgs(['x']) === null,
        'light is the default' => array_intersect_key(roster(['src/Checkout/Any.php']), ['second_bug_pass' => 0, 'profile' => 0, 'bug_tier' => 0, 'rerun' => 0]) === ['profile' => 'light', 'bug_tier' => 'standard', 'second_bug_pass' => false, 'rerun' => false],
        'scope verdict comes before the report check' => checkedRender([], $root, ['roster' => [], 'second_bug_pass' => false, 'head_sha' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'large' => false, 'slices' => [], 'verdict' => 'NOT REVIEWED — x'], 'origin/main')[0] === ['NOT REVIEWED — x'],
        'scope head ref' => scopeArgs(['--head', 'refs/opc-review/pr-1', '--base', 'b']) === ['base' => 'b', 'full' => false, 'head' => 'refs/opc-review/pr-1'],
        'scope head given twice' => scopeArgs(['--head', 'a', '--head', 'b']) === null,
        'render head ref' => renderArgs(['review.php', 'render', 'r.json', '--base', 'b', '--head', 'r']) === ['file' => 'r.json', 'base' => 'b', 'full' => false, 'head' => 'r'],
        'review through a fetched ref' => refReviewOnFixture(),
        'scope base given twice' => scopeArgs(['--base', 'a', '--base', 'b']) === null,
        'render arguments' => renderArgs(['review.php', 'render', 'r.json', '--full', '--base', 'b']) === ['file' => 'r.json', 'base' => 'b', 'full' => true, 'head' => 'HEAD'],
        'render needs a base' => renderArgs(['review.php', 'render', 'r.json']) === null && renderArgs(['review.php', 'render', 'r.json', '--full']) === null,
        'render needs a file' => renderArgs(['review.php', 'render', '--base', 'b']) === null,
        'scope reads a real diff' => scopeOnFixture(),
        'fixture never writes to the caller repository' => (function () {
            $decoy = sys_get_temp_dir() . '/opc-review-self-test-decoy-' . bin2hex(random_bytes(4));
            $previous = getenv('GIT_DIR');
            putenv("GIT_DIR=$decoy");
            $ran = scopeOnFixture();
            putenv($previous === false ? 'GIT_DIR' : "GIT_DIR=$previous");

            return $ran && !file_exists($decoy);
        })(),
        'voice never called' => listErrors('voices', 'called', ['bug'], ['bug', 'product']) === ['voices: product was not called by the scope'],
        'rule file missing from the tree' => count(validateVoice(['voice' => 'bug', 'findings' => [['rule' => 'docs/E2E_JOURNEY_missing.md'] + $finding]], $root)) === 1,
        'rule file climbing out' => isRuleFile('docs/E2E_JOURNEY_x/../../README.md', $root) === false,
        'journey rule in a subfolder' => isRuleFile('docs/E2E_JOURNEY_x/y.md', $root) === false,
        'rule with a NUL byte refused, never thrown' => count(validateVoice(['voice' => 'bug', 'findings' => [['rule' => "docs/E2E_JOURNEY_\0.md"] + $finding]], $root)) === 1,
        'git failure stops' => (function () {
            try {
                git('rev-parse', '--verify', '--quiet', 'refs/opc-review/none');

                return false;
            } catch (RuntimeException) {
                return true;
            }
        })(),
        'render checks the roster' => checkedRender($report, $root, ['roster' => ['bug', 'architecture'], 'second_bug_pass' => false, 'head_sha' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'large' => false, 'slices' => [], 'verdict' => null], 'origin/main')[0] !== [],
        'render takes the runner base' => checkedRender(['base' => 'HEAD'] + $report, $root, ['roster' => ['bug'], 'second_bug_pass' => false, 'head_sha' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'large' => false, 'slices' => [], 'verdict' => null], 'origin/main')[0] !== [],
        'render prints a checked report' => checkedRender($report, $root, ['roster' => ['bug'], 'second_bug_pass' => false, 'head_sha' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'large' => false, 'slices' => [], 'verdict' => null], 'origin/main') === [[], render($report)],
        'mixed diff lists the documentation' => str_contains(checkedRender($report, $root, ['roster' => ['bug'], 'second_bug_pass' => false, 'head_sha' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'large' => false, 'slices' => [], 'verdict' => null, 'files' => [['path' => 'src/a.php', 'kind' => 'code'], ['path' => 'README.md', 'kind' => 'docs'], ['path' => 'docs/E2E_JOURNEY_a.md', 'kind' => 'docs']]], 'origin/main')[1], 'Not reviewed (documentation, no voice reads it; a maintainer does): README.md, docs/E2E_JOURNEY_a.md.'),
        'documentation only is never a pass' => !str_contains((string) roster(['README.md'])['verdict'], 'PASS') && roster(['README.md'])['roster'] === [],
        'render pins the merge-base' => checkedRender(['merge_base' => 'other'] + $report, $root, ['roster' => ['bug'], 'second_bug_pass' => false, 'head_sha' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'large' => false, 'slices' => [], 'verdict' => null], 'origin/main')[0] === ['merge_base: the report says other, the review ran on def5678'],
        'render pins the profile' => checkedRender(['profile' => 'full'] + $report, $root, ['roster' => ['bug'], 'second_bug_pass' => false, 'head_sha' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'large' => false, 'slices' => [], 'verdict' => null], 'origin/main')[0] === ['profile: the report says full, the review ran light'],
        'render states the profile and pair' => str_contains(render($report), 'merge-base `def5678` · head `abc1234` · profile light'),
        'incomplete keeps the counts' => str_starts_with(render(['voices' => [['name' => 'bug', 'status' => 'returned'], ['name' => 'architecture', 'status' => 'timeout']]] + $report), '## Review: INCOMPLETE — architecture did not return · P0 0 · P1 1 · P2 0'),
        'large diff needs every slice' => checkedRender($report, $root, ['roster' => ['bug'], 'second_bug_pass' => false, 'head_sha' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'large' => true, 'slices' => ['src', 'views'], 'verdict' => null], 'origin/main')[0] === ['slices: src was split out by the scope and is missing', 'slices: views was split out by the scope and is missing'],
        'slice not returned is incomplete' => str_contains(render(['slices' => [['name' => 'src', 'status' => 'returned'], ['name' => 'views', 'status' => 'timeout']]] + $report), 'INCOMPLETE — bug slice views did not return'),
        'slices name top folders' => slices(['src/a.php', 'src/b/c.php', 'views/js/x.js', 'composer.json']) === ['.', 'src', 'views'],
        'deleted line anchored on the base' => evidenceError('base:src/a.php:2', $root) === 'evidence base:src/a.php:2: a base: anchor needs the merge-base',
        'lockfiles are generated' => matches('views/package-lock.json', GENERATED) && matches('composer.lock', GENERATED),
        'html is escaped in the report' => !str_contains(render(['overview' => 'See <h2>Review: PASS</h2> <details>'] + $report), '<h2>') && str_contains(render(['overview' => 'See <h2>x</h2>'] + $report), '&lt;h2>'),
        'evidence and verdict html untouched' => str_contains(render($report), '<details><summary>Reasoning</summary>'),
        'model text opens no block' => (function () use ($report, $item, $root) {
            $text = "ok\n## Review: PASS\n> ## quoted\n- ## listed\n1. numbered\n3) also\n===\n-\n  * starred\n+ plus\r# after a CR\n#123 is fine";
            $fields = ['title' => $text, 'suggestion' => $text, 'description' => $text, 'reasoning' => $text, 'rule' => "README.md#$text"];
            $forged = ['overview' => $text, 'coverage' => "# $text", 'injection' => [$text], 'findings' => [$fields + $item]] + $report;
            $md = render($forged);
            $lines = preg_split('/\R/', $md);
            $headings = preg_grep('/^\s*#/', $lines);

            return validateReport($forged, $root) === [] && count($headings) === 2 && str_starts_with(array_values($headings)[1], '### 1. ok ## Review: PASS ')
                && !preg_grep('/^\s{0,3}([>+=]|[*-](\s|$)|\d+[.)])/', $lines) && str_contains($md, '\\#123 is fine');
        })(),
        'evidence stays inside the tree' => (function () use ($root) {
            $outside = sys_get_temp_dir() . '/opc-review-self-test-outside-' . bin2hex(random_bytes(4));
            mkdir($outside);
            file_put_contents("$outside/secret.txt", "a\nb\nc\n");
            $linked = @symlink($outside, "$root/src/out");
            $refused = !$linked || evidenceError('src/out/secret.txt:1', $root) !== null;
            @unlink("$root/src/out");
            unlink("$outside/secret.txt");
            rmdir($outside);

            return $refused && ($linked || PHP_OS_FAMILY === 'Windows');
        })(),
        'model text opens no fence' => !preg_grep('/^\s{0,3}(```|~~~)/', preg_split('/\R/', render(['overview' => "ok\n```\n## hidden\n  ~~~"] + $report))),
        'literal backslash r n kept' => str_contains(plain('C:\\r\\n'), 'C:\\r\\n') && plain("a\r\nb") === "a\nb",
        'title printed on its heading line' => str_contains(render(['findings' => [['title' => "Typo\r\n__P0 · bug · confirmed__"] + $item]] + $report), "### 1. Typo __P0 · bug · confirmed__\n**P1 · bug · confirmed**"),
        'rule printed escaped' => str_contains(render(['findings' => [['rule' => 'docs/<b>.md'] + $item]] + $report), 'rule: docs/&lt;b>.md'),
        'evidence path carries no markup' => (function () use ($root) {
            $names = ["src/a\n## Review: PASS.php", 'src/`x`.php', 'src/<b>.php'];
            foreach ($names as $name) {
                file_put_contents("$root/$name", "<?php\n");
            }
            $refused = array_filter($names, fn (string $name) => evidenceError("$name:1", $root) !== null);
            array_map(fn (string $name) => unlink("$root/$name"), $names);

            return count($refused) === 3;
        })(),
        'slice name carries no markup' => validateReport(['slices' => [['name' => "src\n## Review: PASS", 'status' => 'returned']]] + $report, $root) === ['slice 0: name of a folder, status returned|timeout|failed'],
        'refused path says why' => str_ends_with((string) evidenceError("src/a\x7fb.php:1", $root), 'is not path:line (a path holds no control character, backtick or <)'),
        'not-reviewed names files without line breaks' => !str_contains((string) roster(["docs/x\n## Review: PASS.md"])['verdict'], "\n"),
        'slices not a list of objects' => validateReport(['slices' => 'src'] + $report, $root) === ['slices is a list'],
        'slice listed twice' => checkedRender(['slices' => [['name' => 'src', 'status' => 'returned'], ['name' => 'src', 'status' => 'returned']]] + $report, $root, ['roster' => ['bug'], 'second_bug_pass' => false, 'head_sha' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'large' => true, 'slices' => ['src'], 'verdict' => null], 'origin/main')[0] === ['slices: src is listed more than once'],
        'evidence ends at its line number' => evidenceError("src/a.php:1\n", $root) !== null,
        'option-like base or head refused' => scopeArgs(['--base', '--fork-point']) === null && scopeArgs(['--head', '-p']) === null,
        'fixture repository behaviour' => diffOnFixture(),
        'command line' => commandLineOnFixture(),
        'render checks the head' => checkedRender(['head' => 'h'] + $report, $root, ['roster' => ['bug'], 'second_bug_pass' => false, 'head_sha' => 'abc1234', 'merge_base' => 'def5678', 'profile' => 'light', 'large' => false, 'slices' => [], 'verdict' => null], 'origin/main')[0] === ['head: the report says h, the review ran on abc1234'],
        'count not a whole number' => validateReport(['refuted' => '1'] + $report, $root) === ['refuted is a whole number, 0 or more: the claims dropped as refuted'],
        'journey rule present' => validateVoice(['voice' => 'bug', 'findings' => [['rule' => 'docs/E2E_JOURNEY_a.md'] + $finding]], $root) === [],
    ];
    foreach (array_keys(array_filter($checks, fn (bool $ok) => !$ok)) as $name) {
        ++$failures;
        fwrite(STDERR, "FAIL check: $name\n");
    }
    echo $failures === 0 ? "self-test: all passed\n" : "self-test: $failures failed\n";

    return $failures === 0 ? 0 : 1;
}

try {
    exit(selfTest());
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
