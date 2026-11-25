<?php

declare(strict_types=1);

// Checks of the release rule, without GitHub; run with `php .agents/bin/release.php --self-test`.
function selfTest(): int
{
    // Versions here are made up: the rule is tested on its arithmetic, the repository's own tags are read at run time.
    $failures = 0;
    $check = static function (bool $ok, string $what) use (&$failures): void {
        if (!$ok) {
            ++$failures;
            fwrite(STDERR, "FAIL {$what}\n");
        }
    };

    // Release target.
    $check('0.9.5' === nextRelease('bug', false, 'v0.9.4')['target'], 'bug: next patch');
    $check('0.10.0' === nextRelease('improvement', false, 'v0.9.4')['target'], 'improvement: next minor');
    $check('0.10.0 (bug, BC break)' === nextRelease('bug', true, 'v0.9.4')['text'], 'breaking bug: next minor, said');
    $check('2.0.0' === nextRelease('improvement', true, 'v1.4.2')['target'], 'breaking at 1.x: next major');
    $check(null === nextRelease('question', false, 'v0.9.4')['target'], 'question: none');
    $check('0.9.5 (bug, not breaking)' === nextRelease('bug', false, 'v0.9.4')['text'], 'release text');
    $check('v2.3.10' === latestTag(['v2.3.9', 'latest', 'v2.3.10', 'v2.4.0-rc1', 'v2.3.2']), 'latest tag by version, odd tags ignored');
    $check(['milestone' => '0.9.5', 'note' => null] === milestoneFor('0.9.5', ['0.9.4', '0.9.5']), 'open milestone found');
    $check('milestone 0.10.0 missing' === milestoneFor('0.10.0', ['0.9.5'])['note'], 'missing milestone reported');

    // Pull request: one milestone, plus core's BC break label when breaking; missing ones stop before any write.
    $check(['milestone' => '0.9.5', 'labels' => ['add' => [], 'remove' => []]] === prPayload(nextRelease('bug fix', false, 'v0.9.4'), ['0.9.5'], ['BC break'], []), 'non-breaking PR: milestone alone');
    $check(['milestone' => '0.10.0', 'labels' => ['add' => ['BC break'], 'remove' => []]] === prPayload(nextRelease('improvement', true, 'v0.9.4'), ['0.10.0'], ['BC break'], []), 'breaking PR: milestone and BC break');
    foreach ([[['0.9.5'], ['BC break'], 'milestone'], [['0.10.0'], [], 'label']] as [$open, $labels, $what]) {
        try {
            prPayload(nextRelease('improvement', true, 'v0.9.4'), $open, $labels, []);
            $check(false, "missing {$what} stops");
        } catch (RuntimeException) {
        }
    }

    $check(['milestone' => '0.9.5', 'labels' => ['add' => [], 'remove' => ['BC break']]] === prPayload(nextRelease('bug fix', false, 'v0.9.4'), ['0.9.5'], ['BC break'], ['BC break']), 'non-breaking rerun removes a stale BC break');
    $check(['milestone' => '0.10.0', 'labels' => ['add' => [], 'remove' => []]] === prPayload(nextRelease('improvement', true, 'v0.9.4'), ['0.10.0'], ['BC break'], ['BC break']), 'breaking PR that has BC break already: nothing to add');

    $check('v0.9.5' === openMilestone('0.9.5', ['v0.9.5']) && null === openMilestone('0.9.5', ['0.9.50']), 'a milestone matches with or without a leading v, and only exactly');

    // The shared runner stops on a failing command instead of returning its empty output.
    try {
        run(['git', '-C', __DIR__, 'cat-file', '-e', 'no-such-ref:no-such-file']);
        $check(false, 'a failing command stops the script');
    } catch (RuntimeException) {
    }

    // A removed command answers with the usage and exit 2.
    try {
        run([PHP_BINARY, __DIR__ . '/release.php', 'surfaces']);
        $check(false, 'surfaces is not a command');
    } catch (RuntimeException $e) {
        $check(2 === $e->getCode() && str_contains($e->getMessage(), 'usage'), 'surfaces answers with the usage and exit 2');
    }

    echo 0 === $failures ? "self-test: all checks passed\n" : "self-test: {$failures} failed\n";

    return 0 === $failures ? 0 : 1;
}
