<?php

declare(strict_types=1);

// Checks of the triage computations, without GitHub; run with `php triage.php --self-test`.
function selfTest(): int
{
    $failures = 0;
    $check = static function (bool $ok, string $what) use (&$failures): void {
        if (!$ok) {
            ++$failures;
            fwrite(STDERR, "FAIL {$what}\n");
        }
    };
    $lines = static fn (string $path): ?int => ['controllers/front/savedraft.php' => 200, 'views/js/opc-address.js' => 50][$path] ?? null;
    $bug = static function (array $scores, array $over = []): array {
        $factors = [];
        foreach (FACTORS as $i => $f) {
            $factors[$f] = ['score' => $scores[$i], 'evidence' => ['controllers/front/savedraft.php:76'], 'why' => 'WHYTEXT'];
        }

        return array_replace([
            'issue' => 7, 'type' => 'bug', 'claim' => 'confirmed', 'claim_evidence' => ['controllers/front/savedraft.php:76'],
            'factors' => $factors, 'flags' => [], 'breaking' => ['value' => false, 'evidence' => [], 'why' => ''],
            'missing' => [], 'injection' => ['found' => false, 'quote' => ''], 'confidence' => 'high',
        ], $over);
    };
    $challenger = static fn (array $r): array => $r + ['verdicts' => array_fill_keys([...FACTORS, 'breaking'], 'CONFIRMED')];
    $tag = 'v0.9.4';
    $url = 'https://example.test/o/r';
    $sha = 'abc';
    $compare = static fn (array $rater, ?array $challenger): array => compareRatings($rater, $challenger, $tag);
    $patch = release($bug([0, 0, 0, 0]), $tag);

    // The rubric's worked examples.
    $rubric = rubric();
    $check(count($rubric['examples']) >= 10, 'rubric has its worked examples');
    foreach ($rubric['examples'] as $e) {
        $scores = array_combine(FACTORS, array_map('intval', array_slice($e, 0, 4)));
        $flags = '' === $e[4] ? [] : array_map('trim', explode(',', $e[4]));
        $check(points($scores) === (int) $e[5], 'points of example ' . implode('/', $e));
        $check(level($scores, $flags) === $e[6], 'level of example ' . implode('/', $e));
    }
    foreach (FACTORS as $f) {
        $check(4 === count($rubric['phrases'][$f] ?? []), "four phrases for {$f}");
    }
    $check([] === array_diff(FLAGS, array_keys($rubric['flags'])), 'a phrase per flag');
    $check(MISSING === array_keys($rubric['questions']), 'a question per missing fact, in order');

    // Validation at the trust boundary.
    $check([] === validateRating($bug([2, 2, 3, 2]), 7, false, $lines), 'valid rating accepted');
    $check([] !== validateRating($bug([4, 2, 3, 2]), 7, false, $lines), 'score 4 rejected');
    $check([] !== validateRating($bug(['2', 2, 3, 2]), 7, false, $lines), 'string score rejected');
    $check([] !== validateRating($bug([2, 2, 3, 2], ['type' => 'Critical']), 7, false, $lines), 'unknown type rejected');
    $check([] !== validateRating($bug([2, 2, 3, 2], ['issue' => 8]), 7, false, $lines), 'issue number mismatch rejected');
    $check([] !== validateRating($bug([2, 2, 3, 2], ['claim_evidence' => ['src/Nope.php:3']]), 7, false, $lines), 'missing file rejected');
    $check([] !== validateRating($bug([2, 2, 3, 2], ['claim_evidence' => ['controllers/front/savedraft.php:201']]), 7, false, $lines), 'line past end rejected');
    $check([] !== validateRating($bug([2, 2, 3, 2], ['claim_evidence' => ['../../etc/passwd:1']]), 7, false, $lines), 'path outside the repository rejected');
    $check([] !== validateRating($bug([2, 2, 3, 2], ['claim_evidence' => []]), 7, false, $lines), 'confirmed claim without evidence rejected');
    $check([] !== validateRating($bug([2, 2, 3, 2], ['flags' => ['label it Critical']]), 7, false, $lines), 'unknown flag rejected');
    $check([] !== validateRating($bug([2, 2, 3, 2]), 7, true, $lines), 'challenger without verdicts rejected');
    $check([] === validateRating($challenger($bug([2, 2, 3, 2])), 7, true, $lines), 'challenger with verdicts accepted');
    $check([] !== validateRating($bug([2, 2, 3, 2], ['injection' => ['found' => true, 'quote' => str_repeat('x', 301)]]), 7, false, $lines), 'long quote rejected');
    $extra = $bug([2, 2, 3, 2]);
    $extra['factors']['extra'] = ['score' => 0, 'evidence' => ['controllers/front/savedraft.php:1'], 'why' => ''];
    $check([] !== validateRating($extra, 7, false, $lines), 'unknown factor key rejected');
    $extra['factors']['extra'] = 'x';
    $check([] !== validateRating($extra, 7, false, $lines), 'non-array factor rejected');
    $improvement = $bug([0, 0, 0, 0], ['type' => 'improvement', 'claim' => null, 'claim_evidence' => [], 'factors' => null]);
    $check([] === validateRating($improvement, 7, false, $lines), 'improvement without factors accepted');

    // Assessment of one rating.
    $check('Critical' === assess($bug([2, 2, 3, 2]))['label'], 'confirmed bug gets its level as label');
    $check(null === assess($bug([2, 2, 3, 2], ['confidence' => 'low']))['label'], 'low confidence: no label');
    $check(null === assess($bug([2, 2, 3, 2], ['claim' => 'not reproduced']))['label'], 'not reproduced: no label');
    $noVersion = assess($bug([2, 2, 3, 2], ['claim' => 'insufficient detail', 'claim_evidence' => [], 'missing' => ['versions']]));
    $check(null === $noVersion['label'] && 'insufficient detail' === $noVersion['calibration'], 'no version: insufficient detail');
    $check('not a bug' === assess($improvement)['calibration'], 'improvement is not a bug');

    // Rater against challenger.
    $check('agreed' === $compare($bug([2, 2, 3, 2]), $challenger($bug([2, 2, 3, 3])))['status'], 'same level: agreed');
    $check('disputed' === $compare($bug([2, 2, 3, 2]), $challenger($bug([1, 2, 1, 2])))['status'], 'different level: disputed');
    $breakingChallenge = $challenger($bug([2, 2, 3, 2], ['breaking' => ['value' => true, 'evidence' => ['views/js/opc-address.js:3'], 'why' => '']]));
    $check('disputed' === $compare($bug([2, 2, 3, 2]), $breakingChallenge)['status'], 'different release line: disputed');
    $check('unchallenged' === $compare($bug([2, 2, 3, 2]), null)['status'], 'no challenger: unchallenged');
    $check('disputed' === $compare($bug([2, 2, 3, 2], ['claim' => 'not reproduced']), $challenger($bug([2, 2, 3, 2])))['status'], 'not reproduced against confirmed: disputed');
    $imprBreaking = $improvement;
    $imprBreaking['breaking'] = ['value' => true, 'evidence' => [], 'why' => ''];
    $check('disputed' === $compare($improvement, $challenger($imprBreaking))['status'], 'breaking claim refuted: disputed');

    // Comment rendered from fixed phrases only.
    $agreed = $compare($bug([2, 2, 3, 2]), $challenger($bug([2, 2, 3, 2])));
    $comment = renderComment($agreed['final'], $patch, $url, $sha);
    $visible = explode("\n", explode('<details>', (string) $comment)[0]);
    $check(MARKER === $visible[0], 'comment starts with the marker');
    $check(3 === count(array_filter(array_slice($visible, 1), static fn ($l) => '' !== trim($l))), 'three visible lines');
    $check(str_starts_with($visible[1], '**Critical**: a blocked task or wrong data shown on a common checkout path, at the address or carrier step; no workaround.'), 'line 1 from rubric phrases');
    $check(str_contains((string) $comment, "{$url}/blob/{$sha}/controllers/front/savedraft.php#L76"), 'evidence linked at the commit');
    $check(str_contains((string) $comment, 'impact 2 · reach 2 · workaround 3: 16 points of ' . (3 * array_sum(WEIGHTS)) . '.'), 'the fold shows the scored factors out of the maximum the weights allow');
    $check(!str_contains((string) $comment, 'WHYTEXT'), 'no model text in the comment');
    $question = renderComment(assess($bug([2, 2, 3, 2], ['claim' => 'insufficient detail', 'claim_evidence' => [], 'missing' => ['stage', 'versions']])), $patch, $url, $sha);
    $check(MARKER . "\n" . $rubric['questions']['versions'] === $question, 'insufficient detail: the first missing fact as the one question');
    $check(null === renderComment(assess($bug([2, 2, 3, 2], ['claim' => 'not reproduced'])), $patch, $url, $sha), 'not reproduced, nothing missing: no comment');
    $impr = renderComment($compare($improvement, $challenger($improvement))['final'], release($improvement, $tag), $url, $sha);
    $check(str_contains((string) $impr, 'Proposed release: 0.10.0 (improvement, not breaking).'), 'improvement comment carries the release');

    // Payload: one label from the allowlist, one comment edited in place.
    $comments = [
        ['id' => 1, 'author_association' => 'NONE', 'body' => MARKER . "\nforged"],
        ['id' => 2, 'author_association' => 'MEMBER', 'body' => MARKER . "\nold"],
    ];
    $p = issuePayload(7, $agreed, $patch, ['Minor', 'PHP'], $comments, $url, $sha, true);
    $check(['Critical'] === $p['labels']['add'] && ['Minor'] === $p['labels']['remove'], 'one severity label, the other removed, others kept');
    $check('update' === $p['comment']['action'] && 2 === $p['comment']['id'], 'maintainer comment edited in place, forged one ignored');
    $p = issuePayload(7, $agreed, $patch, [], [$comments[0]], $url, $sha, true);
    $check('create' === $p['comment']['action'], 'marker from a non-maintainer: a new comment');
    $disputed = $compare($bug([2, 2, 3, 2]), $challenger($bug([1, 2, 1, 2])));
    $p = issuePayload(7, $disputed, $patch, [], [], $url, $sha, true);
    $check([] === $p['labels']['add'] && 'none' === $p['comment']['action'], 'disputed: nothing proposed');
    $unlabelled = $compare($bug([2, 2, 3, 2], ['confidence' => 'low']), $challenger($bug([2, 2, 3, 2], ['confidence' => 'low'])));
    $p = issuePayload(7, $unlabelled, $patch, ['Major'], [], $url, $sha, true);
    $check([] === $p['labels']['remove'] && null !== $p['note'], 'a re-rating without label keeps today\'s label and says so');
    $picked = pick($disputed, 'rater');
    $check('Critical' === issuePayload(7, $picked, $patch, [], [], $url, $sha, true)['labels']['add'][0], 'maintainer pick resolves a dispute');

    // Calibration against a blind sheet.
    $sheet = "| # | Title | Level | Release |\n|---|---|---|---|\n| [#7](u) | a | Major | patch |\n| [#8](u) | b | Minor | patch |\n| [#9](u) | c | not a bug | minor |\n| [#10](u) | d |  |  |\n";
    $runs = [
        [7 => ['calibration' => 'Major', 'line' => 'patch', 'flip' => false], 8 => ['calibration' => 'Critical', 'line' => 'patch', 'flip' => true], 9 => ['calibration' => 'not a bug', 'line' => 'minor', 'flip' => false]],
        [7 => ['calibration' => 'Major', 'line' => 'patch', 'flip' => false], 8 => ['calibration' => 'Major', 'line' => 'patch', 'flip' => false], 9 => ['calibration' => 'not a bug', 'line' => 'minor', 'flip' => false]],
    ];
    $m = calibrationMetrics(parseSheet($sheet), $runs);
    $check(3 === $m['rated'] && 1 === $m['blank'], 'blank sheet row skipped and counted');
    $check(abs($m['agreement'] - 2 / 3) < 1e-9, 'exact agreement on the majority level');
    $check([8] === $m['two_level_gaps'], 'two-level gap found');
    $check(abs($m['stability'] - 2 / 3) < 1e-9, 'run-to-run stability');
    $check(1.0 === $m['release_agreement'], 'release agreement');
    $check(abs($m['flip_rate'] - 1 / 6) < 1e-9, 'challenger flip rate');
    $runs[1][7]['flip'] = null;
    $check(abs(calibrationMetrics(parseSheet($sheet), $runs)['flip_rate'] - 1 / 5) < 1e-9, 'flip rate counts only challenged ratings');
    $check(false === $m['trusted'], 'not trusted below thresholds');
    $noSeverity = "| # | Level | Release |\n|---|---|---|\n| #11 | not a bug | minor |\n| #12 | insufficient detail | patch |\n| #13 | Major | patch |\n";
    $gapRuns = [[11 => ['calibration' => 'Major', 'line' => 'minor', 'flip' => null], 12 => ['calibration' => 'Major', 'line' => 'patch', 'flip' => null], 13 => ['calibration' => 'not a bug', 'line' => 'patch', 'flip' => null]]];
    $check([11, 12, 13] === calibrationMetrics(parseSheet($noSeverity), $gapRuns)['two_level_gaps'], 'no severity on one side counts as Trivial in the two-level gap');
    $perfect = [7 => ['calibration' => 'Major', 'line' => 'patch', 'flip' => false], 8 => ['calibration' => 'Minor', 'line' => 'patch', 'flip' => false], 9 => ['calibration' => 'not a bug', 'line' => 'minor', 'flip' => false]];
    $check(true === calibrationMetrics(parseSheet($sheet), [$perfect, $perfect])['trusted'], 'trusted when every threshold holds');
    $offByOne = array_replace($perfect, [8 => ['calibration' => 'Trivial', 'line' => 'patch', 'flip' => false]]);
    $check(false === calibrationMetrics(parseSheet($sheet), [$offByOne, $offByOne])['trusted'], 'agreement under its threshold alone withholds trust');
    $otherLine = array_replace($perfect, [8 => ['calibration' => 'Minor', 'line' => 'minor', 'flip' => false]]);
    $check(false === calibrationMetrics(parseSheet($sheet), [$otherLine, $otherLine])['trusted'], 'release agreement under its threshold alone withholds trust');
    $check(false === calibrationMetrics(parseSheet($sheet), [$perfect, $otherLine])['trusted'], 'stability under its threshold alone withholds trust');
    $perfect[8] = ['calibration' => 'rejected', 'line' => 'rejected', 'flip' => null];
    $m = calibrationMetrics(parseSheet($sheet), [$perfect, $perfect]);
    $check(3 === $m['rated'] && 1 === $m['rejected'] && false === $m['trusted'], 'a rejected rating counts and blocks trust');

    // Silent-failure pass.
    $lowImpr = array_replace($improvement, ['confidence' => 'low']);
    $p = issuePayload(7, $compare($lowImpr, $challenger($lowImpr)), release($lowImpr, $tag), ['Critical'], [], $url, $sha, true);
    $check([] === $p['labels']['remove'] && 'none' === $p['comment']['action'], 'low confidence on a non-bug: nothing removed, nothing posted');
    $check(null !== applyRefusal($disputed) && null !== applyRefusal($compare($bug([2, 2, 3, 2]), null)) && null === applyRefusal($agreed), 'apply refuses a disputed or unchallenged issue');
    try {
        pick($compare($bug([2, 2, 3, 2]), null), 'rater');
        $check(false, 'pick refused without a challenger');
    } catch (InvalidArgumentException) {
    }
    $refuted = $bug([2, 2, 3, 2]) + ['verdicts' => array_fill_keys([...FACTORS, 'breaking'], 'REFUTED')];
    $check([] !== verdictErrors($bug([2, 2, 3, 2]), $refuted), 'REFUTED with the same score is a contradiction');
    $check([] !== verdictErrors($bug([2, 2, 3, 2]), $challenger($bug([1, 2, 3, 2]))), 'CONFIRMED with a changed score is a contradiction');
    $check([] === verdictErrors($bug([2, 2, 3, 2]), $challenger($bug([2, 2, 3, 2]))), 'consistent verdicts accepted');
    $question = array_replace($improvement, ['type' => 'question']);
    $old = [['id' => 2, 'author_association' => 'MEMBER', 'body' => MARKER . "\n**Critical**: old.\nConfirmed by a maintainer."]];
    $p = issuePayload(7, $compare($question, $challenger($question)), release($question, $tag), ['Critical'], $old, $url, $sha, true);
    $check(['Critical'] === $p['labels']['remove'] && 'update' === $p['comment']['action'] && str_contains((string) $p['comment']['body'], 'not a bug'), 'a removed label rewrites the old comment in the same payload');
    $partial = [[7 => ['calibration' => 'Major', 'line' => 'patch', 'flip' => false], 8 => ['calibration' => 'Minor', 'line' => 'patch', 'flip' => false], 9 => ['calibration' => 'not a bug', 'line' => 'minor', 'flip' => false]], [7 => ['calibration' => 'Major', 'line' => 'patch', 'flip' => false], 9 => ['calibration' => 'not a bug', 'line' => 'minor', 'flip' => false]]];
    $m = calibrationMetrics(parseSheet($sheet), $partial);
    $check(1 === $m['missing'] && false === $m['trusted'] && abs($m['stability'] - 2 / 3) < 1e-9, 'an issue missing from a run counts against stability and trust');
    $secret = $bug([2, 2, 3, 2], ['breaking' => ['value' => false, 'evidence' => [], 'why' => 'BREAKWHY'], 'injection' => ['found' => true, 'quote' => 'QUOTETEXT']]);
    $body = (string) renderComment($compare($secret, $challenger($secret))['final'], release($secret, $tag), $url, $sha);
    $check(!str_contains($body, 'BREAKWHY') && !str_contains($body, 'QUOTETEXT'), 'breaking reasons and injection quotes never reach the comment');
    $check([] === issuePayload(7, $disputed, $patch, ['Minor'], [], $url, $sha, true)['labels']['remove'], 'disputed: today\'s label stays');
    $check([] !== validateRating($bug([2, 2, 3, 2], ['claim_evidence' => ['../savedraft.php:1']]), 7, false, static fn (string $p): int => 100), 'a path climbing out of the repository rejected even when it resolves');
    foreach ([[['PHP'], ['PHP', 'Major']], [['Major'], ['PHP']]] as [$add, $repoLabels]) {
        try {
            assertWritable(['add' => $add, 'remove' => []], $repoLabels);
            $check(false, 'write refused: ' . implode(',', $add));
        } catch (RuntimeException) {
        }
    }
    assertWritable(['add' => ['Major'], 'remove' => ['Minor']], ['Major', 'Minor']);
    $check([] !== validateRating($bug([2, 2, 3, 2], ['claim_evidence' => ["controllers/front/savedraft.php:76\n"]]), 7, false, $lines), 'a trailing newline in a reference rejected');
    $check([] !== validateRating($bug([2, 2, 3, 2], ['breaking' => ['value' => true, 'evidence' => [], 'why' => '']]), 7, false, $lines), 'breaking without evidence rejected');

    // Delta review of the silent-failure fixes.
    $p = issuePayload(7, $compare($lowImpr, $challenger($improvement)), release($improvement, $tag), ['Critical'], $old, $url, $sha, true);
    $check([] === $p['labels']['remove'] && 'none' === $p['comment']['action'], 'a low rater beside a high challenger is not an agreement');
    $lowAsking = array_replace($lowImpr, ['missing' => ['steps']]);
    $p = issuePayload(7, $compare($lowAsking, $challenger($lowAsking)), release($lowAsking, $tag), ['Critical'], $old, $url, $sha, true);
    $check('none' === $p['comment']['action'], 'a rating that removes nothing rewrites nothing');
    $p = issuePayload(7, $compare($question, $challenger($question)), release($question, $tag), [], $old, $url, $sha, true);
    $check('update' === $p['comment']['action'], 'an old severity comment is corrected even when its label is already gone');
    try {
        gitLineCount('README.md', 'no-such-ref');
        $check(false, 'a git failure stops instead of blaming the rater');
    } catch (RuntimeException) {
    }
    $tmp = sys_get_temp_dir() . '/opc-triage-selftest-' . getmypid();
    @mkdir($tmp . '/empty', 0700, true);
    file_put_contents($tmp . '/sheet.md', "| # | Level | Release |\n|---|---|---|\n| #7 | Major | patch |\n");
    try {
        cmdCalibrate([$tmp . '/sheet.md', $tmp . '/empty']);
        $check(false, 'an empty run folder stops calibrate');
    } catch (RuntimeException) {
    }
    unlink($tmp . '/sheet.md');
    rmdir($tmp . '/empty');
    rmdir($tmp);

    $check(str_contains((string) applyRefusal($compare($lowImpr, $challenger($improvement))), 'not a bug (low confidence)'), 'a dispute on confidence alone says which side is unsure');

    // Round 2.
    // 1. apply's write gates, with a writer that records instead of writing.
    $writes = [];
    $writer = static function (array $cmd, ?string $stdin = null) use (&$writes): string {
        $writes[] = $cmd;

        return '';
    };
    $agreedPayload = issuePayload(7, $agreed, $patch, [], [], $url, $sha, true);
    foreach (['disputed' => $disputed, 'unchallenged' => $compare($bug([2, 2, 3, 2]), null)] as $status => $c) {
        try {
            applyIssue(7, $c, issuePayload(7, $c, $patch, [], [], $url, $sha, true), true, 'o/r', LEVELS, $writer);
            $check(false, "apply refuses a {$status} issue");
        } catch (RuntimeException) {
        }
    }
    $check([] === $writes, 'no write for a disputed or unchallenged issue');
    $check(str_contains(applyIssue(7, $agreed, $agreedPayload, false, 'o/r', LEVELS, $writer), 'Dry run') && [] === $writes, 'no write without --apply');
    try {
        applyIssue(7, $agreed, $agreedPayload, true, 'o/r', [], $writer);
        $check(false, 'apply stops on a missing label');
    } catch (RuntimeException) {
    }
    $check([] === $writes, 'no write when a label is missing');
    applyIssue(7, $agreed, $agreedPayload, true, 'o/r', LEVELS, $writer);
    $check(2 === count($writes) && 'edit' === $writes[0][2] && 'POST' === $writes[1][3], 'apply writes the label, then the comment');
    $failing = static function (array $cmd, ?string $stdin = null): string {
        if ('api' === $cmd[1]) {
            throw new RuntimeException('HTTP 502');
        }

        return '';
    };
    try {
        applyIssue(7, $agreed, $agreedPayload, true, 'o/r', LEVELS, $failing);
        $check(false, 'a failed comment stops apply');
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'labels are written'), 'a failed comment says the labels are already written');
    }
    // 2. a challenger that verified nothing has not agreed.
    $unverified = $bug([2, 2, 3, 2]) + ['verdicts' => array_fill_keys([...FACTORS, 'breaking'], 'UNVERIFIED')];
    $u = $compare($bug([2, 2, 3, 2]), $unverified);
    $check('unverified' === $u['status'] && str_contains((string) applyRefusal($u), 'verified none of the factors'), 'a challenger that verified nothing blocks the write');
    $check(($agreedPayload['verdicts'] ?? null) === $agreed['challenger']['rating']['verdicts'], 'the payload shows the challenger\'s verdicts');
    $check(isset(summary($agreed['challenger'], $tag)['verdicts']), 'score shows the challenger\'s verdicts');
    // 3. an unreadable sheet cell is an error naming its row.
    foreach (['Mayor', 'Critical (p1)'] as $cell) {
        try {
            parseSheet("| # | Level | Release |\n|---|---|---|\n| #7 | {$cell} | patch |\n");
            $check(false, "sheet cell {$cell} refused");
        } catch (RuntimeException $e) {
            $check(str_contains($e->getMessage(), '#7'), "the error names the row of {$cell}");
        }
    }
    $check('major' === parseSheet("| # | Level | Release |\n|---|---|---|\n| #7 | Major | major |\n")[7]['release'], 'major is a release line the sheet accepts');
    // 4. a dispute agrees with nothing, and the report shows both sides.
    $typeDispute = $compare($improvement, $challenger(array_replace($improvement, ['type' => 'feature'])));
    $check(['calibration' => 'disputed', 'line' => 'disputed'] === array_intersect_key(runEntry($disputed, $tag), ['calibration' => 1, 'line' => 1]), 'a disputed issue counts for no release agreement');
    $row = reportRow(229, $typeDispute, [], issuePayload(229, $typeDispute, release($improvement, $tag), [], [], $url, $sha, true), $tag);
    $check(str_contains($row, 'improvement / feature'), 'the report shows both sides of a disputed type');
    // 5. a non-zero score needs evidence.
    $noEvidence = $bug([2, 2, 3, 2]);
    $noEvidence['factors']['reach']['evidence'] = [];
    $check([] !== validateRating($noEvidence, 7, false, $lines), 'a non-zero score without evidence rejected');
    // 6. a type disagreement is a dispute.
    $check('disputed' === $typeDispute['status'], 'improvement against feature is a dispute');
    // 7. an injection flagged by either side is reported.
    $raterSaw = $compare(array_replace($improvement, ['injection' => ['found' => true, 'quote' => 'q']]), $challenger($improvement));
    $check([] !== array_filter(issueNotes(7, $raterSaw, issuePayload(7, $raterSaw, release($improvement, $tag), [], [], $url, $sha, true)), static fn (string $n): bool => str_contains($n, 'instructions to agents')), 'an injection seen by one side is reported');
    // 8. report refuses a missing or empty folder.
    try {
        cmdReport([sys_get_temp_dir() . '/opc-triage-no-such-folder']);
        $check(false, 'report refuses a missing folder');
    } catch (RuntimeException) {
    }
    // 10. "nothing to write" names the real reason.
    $plainQuestion = array_replace($improvement, ['type' => 'question']);
    $pq = $compare($plainQuestion, $challenger($plainQuestion));
    $check(!str_contains(nothingReason($pq, issuePayload(7, $pq, release($plainQuestion, $tag), [], [], $url, $sha, true)), 'already shows'), 'nothing to write says why when the rating sets nothing');
    // 11. insufficient detail names what is missing.
    $check([] !== validateRating($bug([2, 2, 3, 2], ['claim' => 'insufficient detail', 'claim_evidence' => [], 'missing' => []]), 7, false, $lines), 'insufficient detail without a missing fact rejected');
    // 12. every flag that set the level is in the comment.
    $twoFlags = $bug([3, 2, 3, 2], ['flags' => ['security', 'data_loss']]);
    $flagged = (string) renderComment($compare($twoFlags, $challenger($twoFlags))['final'], $patch, $url, $sha);
    $check(str_contains($flagged, $rubric['flags']['security']) && str_contains($flagged, $rubric['flags']['data_loss']), 'the comment names every flag');
    // Small ones.
    $tie = calibrationMetrics(parseSheet("| # | Level | Release |\n|---|---|---|\n| #7 | Major | patch |\n"), [[7 => ['calibration' => 'Major', 'line' => 'patch', 'flip' => false]], [7 => ['calibration' => 'Minor', 'line' => 'patch', 'flip' => false]]]);
    $check(0.0 === $tie['agreement'], 'a tie between runs agrees with nothing');
    $check(1 === calibrationMetrics(parseSheet($sheet), [$perfect])['runs'], 'calibrate says how many runs it counted');
    $check([] !== validateRating(array_replace($challenger($bug([2, 2, 3, 2])), ['verdicts' => array_fill_keys([...FACTORS, 'breaking', 'mood'], 'CONFIRMED')]), 7, true, $lines), 'a verdict key outside the rating rejected');
    $check([] !== verdictErrors($improvement, $challenger($bug([2, 2, 3, 2]))), 'CONFIRMED on factors the rater never scored is a contradiction');

    // Round 2 delta review.
    $allUnverified = static fn (array $r): array => $r + ['verdicts' => array_fill_keys([...(null === $r['factors'] ? [] : FACTORS), 'breaking'], 'UNVERIFIED')];
    $check('agreed' === $compare($improvement, $allUnverified($improvement))['status'], 'an improvement whose breaking claim stays unverified is not blocked');
    $check('disputed' === $compare($bug([2, 2, 3, 2]), $allUnverified($improvement))['status'], 'a challenger that changes the type is a dispute, verified or not');
    $vague = $bug([2, 2, 3, 2], ['claim' => 'insufficient detail', 'claim_evidence' => [], 'missing' => ['versions']]);
    $check('agreed' === $compare($vague, $allUnverified($vague))['status'], 'an unverified challenger does not block the reporter\'s question');
    $check(['calibration' => 'unchallenged', 'line' => 'unchallenged'] === array_intersect_key(runEntry($compare($bug([2, 2, 3, 2]), null), $tag), ['calibration' => 1, 'line' => 1]), 'an unchallenged issue agrees with nothing in calibration');
    $check(false === applyArgs(['d', '7'])['apply'] && true === applyArgs(['d', '7', '--apply'])['apply'] && 'rater' === applyArgs(['d', '7', '--pick', 'rater'])['pick'], 'apply writes only with --apply on its command line');

    // Round 3: apply writes labels; the comment only with --comment.
    $count = static function (array $payload, array $c) use (&$writes, $writer): array {
        $writes = [];
        applyIssue(7, $c, $payload, true, 'o/r', LEVELS, $writer);

        return $writes;
    };
    $pickedDispute = pick($disputed, 'rater');
    $notReproduced = $bug([2, 2, 3, 2], ['claim' => 'not reproduced', 'missing' => ['steps']]);
    $cases = [
        'agreed bug' => $agreed,
        'picked dispute' => $pickedDispute,
        'non-bug' => $compare($improvement, $challenger($improvement)),
        'unconfirmed claim' => $compare($notReproduced, $challenger($notReproduced)),
        'insufficient detail' => $compare($vague, $challenger($vague)),
    ];
    foreach ($cases as $name => $c) {
        $p = issuePayload(7, $c, $patch, [], [], $url, $sha, false);
        $check('none' === $p['comment']['action'] && null === $p['comment']['body'], "without --comment no comment is pending: {$name}");
        $sent = $count($p, $c);
        $check([] === array_filter($sent, static fn (array $cmd): bool => 'api' === $cmd[1]), "without --comment no comment is written: {$name}");
    }
    $onlyComment = $cases['non-bug'];
    $out = applyIssue(7, $onlyComment, issuePayload(7, $onlyComment, release($improvement, $tag), [], [], $url, $sha, false), true, 'o/r', LEVELS, $writer);
    $check(str_contains($out, 'Nothing to write') && str_contains($out, '--comment'), 'an issue with only a comment to write says nothing to write, and why');
    $check(2 === count($count(issuePayload(7, $agreed, $patch, [], [], $url, $sha, true), $agreed)), 'with --comment the label and the comment are written, as before');
    $stale = applyIssue(7, $agreed, issuePayload(7, $agreed, $patch, [], $old, $url, $sha, false), false, 'o/r', LEVELS, $writer);
    $check(str_contains($stale, 'differs from what --comment would write'), 'a stale triage comment left without --comment is said in one line');
    $check(true === applyArgs(['d', '7', '--comment'])['comment'] && false === applyArgs(['d', '7'])['comment'], 'the comment is written only on --comment');

    $keptAndHeld = ['note' => 'today\'s Major stays', 'comment' => ['action' => 'none', 'id' => null, 'body' => null, 'held' => 'create']];
    $reason = nothingReason($agreed, $keptAndHeld);
    $check(str_contains($reason, 'Major stays') && str_contains($reason, '--comment'), 'nothing to write keeps the label note and names the held comment');

    echo 0 === $failures ? "self-test: all checks passed\n" : "self-test: {$failures} failed\n";

    return 0 === $failures ? 0 : 1;
}
