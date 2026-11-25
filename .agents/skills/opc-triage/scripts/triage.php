<?php

declare(strict_types=1);

// The skill's folder and the scripts every skill shares: the only places that know the layout.
const SKILL_DIR = __DIR__ . '/..';
const AGENTS_BIN = __DIR__ . '/../../../bin';

require_once AGENTS_BIN . '/helpers.php';

/*
 * Issue triage for this repository. The model rates, this script decides: it checks each rating, computes the severity,
 * takes the release target from the shared release rule, and writes on an issue only with --apply, after the maintainer
 * has seen the same payload.
 */

const LEVELS = ['Trivial', 'Minor', 'Major', 'Critical'];
const TYPES = ['bug', 'improvement', 'feature', 'question', 'not actionable'];
const FACTORS = ['impact', 'reach', 'workaround', 'stage'];
// The stage is rated for the comment and scores nothing: a late defect's gravity is already its impact.
const WEIGHTS = ['impact' => 3, 'reach' => 2, 'workaround' => 2];
// Lowest points of each level above Trivial; tuned at calibration with the weights.
const CUTS = ['Critical' => 15, 'Major' => 10, 'Minor' => 5];
// What calibration must reach before the ratings are trusted.
const TRUST = ['agreement' => 0.8, 'release_agreement' => 0.8, 'stability' => 0.9];
// Longest free text a rating may carry; it is shown to the maintainer, never posted.
const TEXT_MAX = 300;
const FLAGS = ['security', 'data_loss', 'wrong_amount', 'order_blocked'];
const MISSING = ['steps', 'versions', 'impact', 'workaround', 'stage'];
const VERDICTS = ['CONFIRMED', 'REFUTED', 'UNVERIFIED'];
const MAINTAINERS = ['OWNER', 'MEMBER', 'COLLABORATOR'];
const MARKER = '<!-- opc-triage -->';
const EVIDENCE_REF = 'origin/main';
// A rating file the model must redo; any other failure (gh, git) stops the run.
final class RejectedRating extends RuntimeException
{
}

const USAGE = <<<'TXT'
    usage: php triage.php <command>
      fetch <issue>                  issue data for the rater, labels held back
      fetch --unrated                open issues without a severity label
      score <dir> <issue> [--last-tag <tag>]
                                     level and release from <dir>/<issue>.rater.json and <issue>.challenger.json
      report <dir> [--comment]       one row per rated issue, today's labels, and every payload
      apply <dir> <issue> [--pick rater|challenger] [--comment] [--apply]
                                     writes the severity label; the triage comment only with --comment
      calibrate <sheet> <dir>...     agreement with a filled calibration sheet, one <dir> per run
      --self-test
    TXT;

/** @return array{phrases: array<string, array<int, string>>, flags: array<string, string>, questions: array<string, string>, examples: list<list<string>>} */
function rubric(): array
{
    static $rubric = null;
    if (null === $rubric) {
        $file = SKILL_DIR . '/references/rubric.md';
        $rubric = ['phrases' => [], 'flags' => [], 'questions' => [], 'examples' => mdTable($file, 'impact')];
        foreach (mdTable($file, 'Factor') as [$factor, $score, , $phrase]) {
            $rubric['phrases'][$factor][(int) $score] = $phrase;
        }
        foreach (mdTable($file, 'Flag') as [$flag, , $phrase]) {
            $rubric['flags'][$flag] = $phrase;
        }
        foreach (mdTable($file, 'Missing') as [$fact, $question]) {
            $rubric['questions'][$fact] = $question;
        }
    }

    return $rubric;
}

/** @param array<string, int> $scores */
function points(array $scores): int
{
    $points = 0;
    foreach (WEIGHTS as $factor => $weight) {
        $points += $weight * $scores[$factor];
    }

    return $points;
}

/**
 * @param array<string, int> $scores
 * @param list<string> $flags
 */
function level(array $scores, array $flags): string
{
    $points = points($scores);
    $index = $points >= CUTS['Critical'] ? 3 : ($points >= CUTS['Major'] ? 2 : ($points >= CUTS['Minor'] ? 1 : 0));
    if ([] !== array_intersect($flags, ['security', 'data_loss', 'wrong_amount'])) {
        $index = max($index, $scores['reach'] >= 2 ? 3 : 2);
    }
    if (in_array('order_blocked', $flags, true) && $scores['reach'] >= 2) {
        $index = 3;
    }

    return LEVELS[$index];
}

/**
 * Model output is untrusted: every field is checked before anything reads it.
 *
 * @param callable(string): ?int $lineCount lines of a file on EVIDENCE_REF, null when absent
 *
 * @return list<string> one message per broken rule
 */
function validateRating(mixed $r, int $issue, bool $challenger, callable $lineCount): array
{
    if (!is_array($r)) {
        return ['the rating is not a JSON object'];
    }
    $errors = [];
    $oneOf = static function (string $field, mixed $value, array $allowed) use (&$errors): void {
        if (!in_array($value, $allowed, true)) {
            $errors[] = "{$field}: one of " . implode(', ', array_map(static fn ($a) => json_encode($a), $allowed));
        }
    };
    $text = static function (string $field, mixed $value) use (&$errors): void {
        if (!is_string($value) || mb_strlen($value) > TEXT_MAX) {
            $errors[] = "{$field}: a text of at most " . TEXT_MAX . ' characters';
        }
    };
    $list = static fn (mixed $v): bool => is_array($v) && array_is_list($v);
    $evidence = static function (string $field, mixed $refs) use (&$errors, $lineCount, $list): void {
        if (!$list($refs)) {
            $errors[] = "{$field}: a list of path:line";

            return;
        }
        foreach ($refs as $ref) {
            if (!is_string($ref) || str_contains($ref, '..') || !preg_match('#^([\w.-]+(?:/[\w.-]+)*):([1-9]\d*)$#D', $ref, $m)) {
                $errors[] = "{$field}: " . json_encode($ref) . ' is not path:line';
            } elseif ((int) $m[2] > ($lineCount($m[1]) ?? 0)) {
                $errors[] = "{$field}: {$ref} is not a line of a file on " . EVIDENCE_REF;
            }
        }
    };
    $part = static fn (mixed $v): array => is_array($v) ? $v : [];

    if (($r['issue'] ?? null) !== $issue) {
        $errors[] = "issue: {$issue}";
    }
    $oneOf('type', $r['type'] ?? null, TYPES);
    $isBug = 'bug' === ($r['type'] ?? null);
    $oneOf('claim', $r['claim'] ?? null, $isBug ? ['confirmed', 'not reproduced', 'insufficient detail'] : [null]);
    $evidence('claim_evidence', $r['claim_evidence'] ?? null);
    if ('insufficient detail' === ($r['claim'] ?? null) && [] === ($r['missing'] ?? null)) {
        $errors[] = 'missing: insufficient detail names the facts the reporter must add';
    }
    if ('confirmed' === ($r['claim'] ?? null) && [] === ($r['claim_evidence'] ?? null)) {
        $errors[] = 'claim_evidence: a confirmed claim needs evidence';
    }
    $factors = $part($r['factors'] ?? null);
    if ($isBug) {
        $keys = array_keys($factors);
        sort($keys);
        if ($keys !== ['impact', 'reach', 'stage', 'workaround']) {
            $errors[] = 'factors: exactly ' . implode(', ', FACTORS);
        }
        foreach (FACTORS as $f) {
            $factor = $part($factors[$f] ?? null);
            $oneOf("factors.{$f}.score", $factor['score'] ?? null, [0, 1, 2, 3]);
            $evidence("factors.{$f}.evidence", $factor['evidence'] ?? null);
            $text("factors.{$f}.why", $factor['why'] ?? null);
            if (is_int($factor['score'] ?? null) && $factor['score'] > 0 && [] === ($factor['evidence'] ?? null)) {
                $errors[] = "factors.{$f}.evidence: a score above 0 cites at least one line";
            }
        }
    } elseif (null !== ($r['factors'] ?? null)) {
        $errors[] = 'factors: null for a type other than bug';
    }
    $listOf = static function (string $field, mixed $values, array $allowed) use (&$errors, $list, $oneOf): bool {
        if (!$list($values)) {
            $errors[] = "{$field}: a list";

            return false;
        }
        foreach ($values as $value) {
            $oneOf($field, $value, $allowed);
        }

        return true;
    };
    if ($listOf('flags', $r['flags'] ?? null, FLAGS) && [] !== $r['flags'] && [] === ($part($factors['impact'] ?? null)['evidence'] ?? [])) {
        $errors[] = 'flags: a flag needs evidence in factors.impact';
    }
    $breaking = $part($r['breaking'] ?? null);
    $oneOf('breaking.value', $breaking['value'] ?? null, [true, false]);
    $evidence('breaking.evidence', $breaking['evidence'] ?? null);
    $text('breaking.why', $breaking['why'] ?? null);
    if (true === ($breaking['value'] ?? null) && [] === ($breaking['evidence'] ?? [])) {
        $errors[] = 'breaking.evidence: a breaking change names the surface it changes';
    }
    $listOf('missing', $r['missing'] ?? null, MISSING);
    $injection = $part($r['injection'] ?? null);
    $oneOf('injection.found', $injection['found'] ?? null, [true, false]);
    $text('injection.quote', $injection['quote'] ?? null);
    $oneOf('confidence', $r['confidence'] ?? null, ['high', 'medium', 'low']);
    if ($challenger) {
        $verdicts = $part($r['verdicts'] ?? null);
        $expected = [...($isBug ? FACTORS : []), 'breaking'];
        foreach ($expected as $key) {
            $oneOf("verdicts.{$key}", $verdicts[$key] ?? null, VERDICTS);
        }
        if ([] !== array_diff(array_keys($verdicts), $expected)) {
            $errors[] = 'verdicts: only ' . implode(', ', $expected);
        }
    }

    return $errors;
}

/**
 * What one valid rating amounts to. `calibration` is the value a maintainer writes in the calibration sheet.
 *
 * @return array{rating: array, level: ?string, points: ?int, label: ?string, calibration: string}
 */
function assess(array $r): array
{
    if ('bug' !== $r['type'] || 'insufficient detail' === $r['claim']) {
        $calibration = 'bug' === $r['type'] ? 'insufficient detail' : 'not a bug';

        return ['rating' => $r, 'level' => null, 'points' => null, 'label' => null, 'calibration' => $calibration];
    }
    $scores = array_map(static fn (array $f): int => $f['score'], $r['factors']);
    $level = level($scores, $r['flags']);
    $label = 'confirmed' === $r['claim'] && 'low' !== $r['confidence'] ? $level : null;

    return ['rating' => $r, 'level' => $level, 'points' => points($scores), 'label' => $label, 'calibration' => $level];
}

/**
 * The release target from the rule in .agents/bin/release.php, which every skill shares. A null tag lets that script
 * read the last tag and the open milestones on GitHub.
 *
 * @return array{target: ?string, line: string, text: string, breaking: bool, note?: ?string, stale?: list<string>}
 */
function release(array $r, ?string $tag): array
{
    static $cache = [];
    $cmd = [PHP_BINARY, AGENTS_BIN . '/release.php', '--type', $r['type'], '--breaking', $r['breaking']['value'] ? 'yes' : 'no'];
    if (null !== $tag) {
        array_push($cmd, '--last-tag', $tag);
    }

    return $cache[implode("\n", $cmd)] ??= json_decode(run($cmd), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Agreement is on the outcome, never on an average: a different type, level, label, release or low confidence on one side
 * leaves the choice to the maintainer, and a label is never written on factors the challenger verified none of.
 *
 * @return array{status: string, final: ?array, rater: array, challenger: ?array}
 */
function compareRatings(array $rater, ?array $challenger, ?string $tag): array
{
    $a = assess($rater);
    if (null === $challenger) {
        return ['status' => 'unchallenged', 'final' => null, 'rater' => $a, 'challenger' => null];
    }
    $b = assess($challenger);
    $same = $rater['type'] === $challenger['type'] && $a['calibration'] === $b['calibration'] && $a['label'] === $b['label']
        && release($rater, $tag)['line'] === release($challenger, $tag)['line'] && $rater['breaking']['value'] === $challenger['breaking']['value']
        && ('low' === $rater['confidence']) === ('low' === $challenger['confidence']);

    $factorVerdicts = array_intersect_key($challenger['verdicts'] ?? [], array_flip(FACTORS));
    if ($same && null !== $b['label'] && [] === array_diff($factorVerdicts, ['UNVERIFIED'])) {
        return ['status' => 'unverified', 'final' => null, 'rater' => $a, 'challenger' => $b];
    }

    return ['status' => $same ? 'agreed' : 'disputed', 'final' => $same ? $b : null, 'rater' => $a, 'challenger' => $b];
}

/** One side of a dispute as the maintainer reads it: its outcome and how sure it is. */
function side(array $assessed): string
{
    return "{$assessed['calibration']} ({$assessed['rating']['confidence']} confidence)";
}

/** Why nothing may be written for this comparison, or null when the maintainer can confirm it. */
function applyRefusal(array $comparison): ?string
{
    return match ($comparison['status']) {
        'disputed' => 'disputed: the rater says ' . side($comparison['rater']) . ', the challenger ' . side($comparison['challenger']) . '; nothing is written until the maintainer picks with --pick rater or --pick challenger',
        'unchallenged' => 'unchallenged: nothing is written until the challenger has saved its rating',
        'unverified' => 'unverified: the challenger verified none of the factors behind this label; nothing is written until it runs again',
        default => null,
    };
}

/**
 * A verdict must match the score the challenger returns: REFUTED changes it, CONFIRMED keeps it.
 *
 * @return list<string>
 */
function verdictErrors(array $rater, array $challenger): array
{
    $errors = [];
    $pairs = ['breaking' => [$rater['breaking']['value'], $challenger['breaking']['value']]];
    if (null !== $rater['factors'] && null !== $challenger['factors']) {
        foreach (FACTORS as $f) {
            $pairs[$f] = [$rater['factors'][$f]['score'], $challenger['factors'][$f]['score']];
        }
    }
    if (null === $rater['factors'] && null !== $challenger['factors']) {
        foreach (FACTORS as $f) {
            if ('CONFIRMED' === ($challenger['verdicts'][$f] ?? null)) {
                $errors[] = "verdicts.{$f}: CONFIRMED but the rater scored no factors";
            }
        }
    }
    foreach ($pairs as $key => [$before, $after]) {
        $verdict = $challenger['verdicts'][$key] ?? null;
        if (('REFUTED' === $verdict && $before === $after) || ('CONFIRMED' === $verdict && $before !== $after)) {
            $errors[] = "verdicts.{$key}: {$verdict} but the value goes from " . json_encode($before) . ' to ' . json_encode($after);
        }
    }

    return $errors;
}

function pick(array $comparison, string $side): array
{
    if ('disputed' !== $comparison['status'] || !in_array($side, ['rater', 'challenger'], true)) {
        throw new InvalidArgumentException("--pick rater|challenger settles a dispute only; this issue is {$comparison['status']}");
    }

    return ['status' => 'picked', 'final' => $comparison[$side]] + $comparison;
}

/** @return list<string> Markdown links to each cited line, at the commit the rating was checked against */
function evidenceLinks(array $r, string $repoUrl, string $sha): array
{
    $refs = [...$r['claim_evidence'], ...$r['breaking']['evidence']];
    foreach (null === $r['factors'] ? [] : FACTORS as $factor) {
        array_push($refs, ...$r['factors'][$factor]['evidence']);
    }

    return array_map(static function (string $ref) use ($repoUrl, $sha): string {
        [$path, $line] = explode(':', $ref);

        return "[{$ref}]({$repoUrl}/blob/{$sha}/{$path}#L{$line})";
    }, array_values(array_unique($refs)));
}

/** The issue comment, built from the rubric's fixed phrases; null when the issue gets none. */
function renderComment(array $final, array $release, string $repoUrl, string $sha): ?string
{
    $r = $final['rating'];
    $rubric = rubric();
    if (null !== $final['label']) {
        $s = array_map(static fn (array $f): int => $f['score'], $r['factors']);
        $impact = [] !== $r['flags'] ? implode(' and ', array_map(static fn (string $f): string => $rubric['flags'][$f], $r['flags'])) : $rubric['phrases']['impact'][$s['impact']];
        $head = sprintf('**%s**: %s %s, %s; %s.', $final['label'], $impact, $rubric['phrases']['reach'][$s['reach']], $rubric['phrases']['stage'][$s['stage']], $rubric['phrases']['workaround'][$s['workaround']]);
        $fold = sprintf('impact %d · reach %d · workaround %d: %d points of %d.', $s['impact'], $s['reach'], $s['workaround'], $final['points'], 3 * array_sum(WEIGHTS));
    } elseif (in_array($r['type'], ['improvement', 'feature'], true) && 'low' !== $r['confidence']) {
        $head = sprintf('**%s**: not a bug, so no severity is set.', ucfirst($r['type']));
        $fold = '';
    } else {
        $fact = array_values(array_intersect(MISSING, $r['missing']))[0] ?? null;

        return null === $fact ? null : MARKER . "\n" . $rubric['questions'][$fact];
    }
    $links = evidenceLinks($r, $repoUrl, $sha);
    $details = trim($fold . ([] === $links ? '' : ' Evidence: ' . implode(', ', $links)));
    $body = MARKER . "\n{$head}\nProposed release: {$release['text']}.\nConfirmed by a maintainer.";

    return '' === $details ? $body : "{$body}\n<details><summary>Factors and evidence</summary>\n\n{$details}\n</details>";
}

/**
 * Writes for one issue: a severity label from the allowlist, and the one triage comment when the maintainer asks for it.
 *
 * @param list<string> $labels the issue's labels today
 * @param list<array{id: int, author_association: string, body: string}> $comments
 */
function issuePayload(int $issue, array $comparison, array $release, array $labels, array $comments, string $repoUrl, string $sha, bool $withComment): array
{
    $final = in_array($comparison['status'], ['agreed', 'picked'], true) ? $comparison['final'] : null;
    $add = null !== $final && null !== $final['label'] ? [$final['label']] : [];
    $replaces = [] !== $add || (null !== $final && 'bug' !== $final['rating']['type'] && 'low' !== $final['rating']['confidence']);
    $remove = $replaces ? array_values(array_diff(array_intersect($labels, LEVELS), $add)) : [];
    $body = null === $final ? null : renderComment($final, $release, $repoUrl, $sha);
    $mine = null;
    foreach ($comments as $c) {
        if (in_array($c['author_association'], MAINTAINERS, true) && str_starts_with($c['body'], MARKER)) {
            $mine = $c;
            break;
        }
    }
    if (null !== $mine && !$replaces) {
        // A rating that sets no severity leaves today's label and comment as they are.
        $body = null;
    } elseif (null === $body && null !== $mine && $replaces) {
        // An older comment may still name a severity this rating drops: it is corrected in the same write.
        $body = MARKER . "\n" . sprintf('**%s**: not a bug, so no severity is set.', ucfirst($final['rating']['type']));
    }
    $action = null === $body || $body === ($mine['body'] ?? null) ? 'none' : (null === $mine ? 'create' : 'update');
    $kept = $replaces ? [] : array_intersect($labels, LEVELS);

    return [
        'issue' => $issue,
        'verdicts' => $comparison['challenger']['rating']['verdicts'] ?? null,
        'note' => null === $final || [] === $kept ? null : 'today\'s ' . implode(', ', $kept) . ' stays: this rating sets no severity; a maintainer removes it by hand if it is wrong',
        'labels' => ['add' => array_values(array_diff($add, $labels)), 'remove' => $remove],
        // Without --comment the comment is not written; `held` says what --comment would do.
        'comment' => $withComment || 'none' === $action
            ? ['action' => $action, 'id' => $mine['id'] ?? null, 'body' => 'none' === $action ? null : $body]
            : ['action' => 'none', 'id' => $mine['id'] ?? null, 'body' => null, 'held' => $action],
    ];
}

/**
 * The filled calibration sheet, by issue number. Columns are found by their header (level or severity, release).
 *
 * @return array<int, array{level: ?string, release: ?string}>
 */
function parseSheet(string $markdown): array
{
    $levels = [...LEVELS, 'not a bug', 'insufficient detail'];
    $levelCol = $releaseCol = null;
    $rows = [];
    foreach (explode("\n", $markdown) as $line) {
        if (!str_starts_with(trim($line), '|')) {
            continue;
        }
        $cells = array_map(static fn ($c) => trim($c, " `*\t"), explode('|', substr(trim($line), 1, -1)));
        if (null === $levelCol) {
            foreach ($cells as $i => $c) {
                $levelCol ??= preg_match('/level|severity/i', $c) ? $i : null;
                $releaseCol ??= preg_match('/release/i', $c) ? $i : null;
            }
            continue;
        }
        if (!preg_match('/^\[?#?(\d+)\b/', $cells[0], $m)) {
            continue;
        }
        $read = static function (string $cell, array $allowed, string $column) use ($m): ?string {
            $found = array_search(strtolower($cell), array_map('strtolower', $allowed), true);
            if ('' !== $cell && false === $found) {
                throw new RuntimeException("row #{$m[1]}: {$column} '{$cell}' is not one of " . implode(', ', $allowed));
            }

            return '' === $cell ? null : $allowed[$found];
        };
        $rows[(int) $m[1]] = [
            'level' => $read($cells[$levelCol] ?? '', $levels, 'level'),
            'release' => $read($cells[$releaseCol] ?? '', ['patch', 'minor', 'major', 'none'], 'release'),
        ];
    }
    if (null === $levelCol || null === $releaseCol) {
        throw new RuntimeException('the sheet needs a level column and a release column');
    }

    return $rows;
}

/** A value without severity sits with Trivial, so a Major on an issue the other side calls no bug is a two-level gap. */
function severityRank(string $value): ?int
{
    $rank = array_search($value, LEVELS, true);

    return false !== $rank ? $rank : (in_array($value, ['not a bug', 'insufficient detail'], true) ? 0 : null);
}

function ratio(int $part, int $whole): float
{
    return 0 === $whole ? 0.0 : $part / $whole;
}

/**
 * The value most runs gave; a tie decides nothing.
 *
 * @param list<string> $values
 */
function modeOf(array $values): ?string
{
    $counts = array_count_values($values);
    $top = array_keys($counts, max($counts), true);

    return 1 === count($top) ? (string) $top[0] : null;
}

/**
 * Whether the skill's levels and releases agree enough with a maintainer's blind sheet to be trusted.
 *
 * @param array<int, array{level: ?string, release: ?string}> $sheet
 * @param list<array<int, array{calibration: string, line: string, flip: ?bool}>> $runs
 */
function calibrationMetrics(array $sheet, array $runs): array
{
    $rated = $blank = $rejected = $missing = $agree = $releaseRated = $releaseAgree = $stableRated = $stable = $flips = $flipRated = 0;
    $gaps = [];
    foreach ($sheet as $issue => $row) {
        $mine = array_values(array_filter(array_map(static fn (array $run) => $run[$issue] ?? null, $runs)));
        if (null === $row['level']) {
            ++$blank;
            continue;
        }
        ++$rated;
        $missing += count($mine) < count($runs) ? 1 : 0;
        $levels = array_column($mine, 'calibration');
        $rejected += in_array('rejected', $levels, true) ? 1 : 0;
        $agree += [] !== $levels && modeOf($levels) === $row['level'] ? 1 : 0;
        if (null !== $row['release']) {
            ++$releaseRated;
            $releaseAgree += [] !== $mine && modeOf(array_column($mine, 'line')) === $row['release'] ? 1 : 0;
        }
        $lead = severityRank($row['level']);
        foreach ($levels as $level) {
            $skill = severityRank($level);
            if (null !== $lead && null !== $skill && abs($lead - $skill) >= 2) {
                $gaps[] = $issue;
                break;
            }
        }
        if (count($runs) >= 2) {
            ++$stableRated;
            $stable += count($mine) === count($runs) && 1 === count(array_unique(array_map(static fn (array $x) => $x['calibration'] . '|' . $x['line'], $mine))) ? 1 : 0;
        }
        $challenged = array_filter(array_column($mine, 'flip'), 'is_bool');
        $flipRated += count($challenged);
        $flips += count(array_filter($challenged));
    }
    $m = [
        'runs' => count($runs), 'rated' => $rated, 'blank' => $blank, 'rejected' => $rejected, 'missing' => $missing,
        'agreement' => ratio($agree, $rated), 'release_agreement' => ratio($releaseAgree, $releaseRated),
        'two_level_gaps' => $gaps, 'stability' => ratio($stable, $stableRated), 'flip_rate' => ratio($flips, $flipRated),
    ];
    $m['trusted'] = $rated > 0 && 0 === $rejected && 0 === $missing && [] === $gaps
        && [] === array_filter(TRUST, static fn (float $min, string $key): bool => $m[$key] < $min, ARRAY_FILTER_USE_BOTH);

    return $m;
}

/** Lines of a file on the ref ratings are checked against, null when the file is not there; a git failure stops the run. */
function gitLineCount(string $path, string $ref = EVIDENCE_REF): ?int
{
    static $tracked = [], $cache = [];
    $tracked[$ref] ??= array_flip(explode("\0", run(['git', '-C', repoRoot(), 'ls-tree', '-r', '-z', '--name-only', $ref])));
    if (!isset($tracked[$ref][$path])) {
        return null;
    }
    if (!isset($cache[$ref][$path])) {
        $blob = run(['git', '-C', repoRoot(), 'cat-file', 'blob', "{$ref}:{$path}"]);
        $cache[$ref][$path] = substr_count($blob, "\n") + (str_ends_with($blob, "\n") || '' === $blob ? 0 : 1);
    }

    return $cache[$ref][$path];
}

/** Where the rater and the challenger save their ratings: one file per issue and role. */
function ratingFile(string $dir, int|string $issue, string $role): string
{
    return "{$dir}/{$issue}.{$role}.json";
}

function loadRatings(string $dir, int $issue, ?string $tag): array
{
    $read = static function (string $role) use ($dir, $issue): ?array {
        $file = ratingFile($dir, $issue, $role);
        if (!is_file($file)) {
            return null;
        }
        $rating = json_decode((string) file_get_contents($file), true);
        $errors = validateRating($rating, $issue, 'challenger' === $role, 'gitLineCount');
        if ([] !== $errors) {
            throw new RejectedRating("{$file} rejected:\n- " . implode("\n- ", $errors));
        }

        return $rating;
    };

    $rater = $read('rater') ?? throw new RejectedRating(ratingFile($dir, $issue, 'rater') . ' missing');
    $challenger = $read('challenger');
    $errors = null === $challenger ? [] : verdictErrors($rater, $challenger);
    if ([] !== $errors) {
        throw new RejectedRating(ratingFile($dir, $issue, 'challenger') . " rejected:\n- " . implode("\n- ", $errors));
    }

    return compareRatings($rater, $challenger, $tag);
}

/** @return list<int> */
function ratedIssues(string $dir): array
{
    $issues = array_map(static fn (string $f): int => (int) basename($f), glob(ratingFile($dir, '*', 'rater')) ?: []);
    sort($issues);

    return $issues;
}

function summary(?array $assessed, ?string $tag): ?array
{
    $r = $assessed['rating'] ?? null;

    return null === $r ? null : array_diff_key($assessed, ['rating' => true]) + ['type' => $r['type'], 'release' => release($r, $tag)['text'], 'verdicts' => $r['verdicts'] ?? null];
}

/** The repository's web address, whatever host it lives on, for the evidence links. */
function repoUrl(): string
{
    static $url = null;

    return $url ??= gh('repo', 'view', '--json', 'url')['url'];
}

function shown(array $comparison): array
{
    return $comparison['final'] ?? $comparison['rater'];
}

function cmdFetch(array $args): int
{
    $repo = repo();
    if ('--unrated' === ($args[0] ?? null)) {
        foreach (ghList("repos/{$repo}/issues?state=open") as $i) {
            if (!isset($i['pull_request']) && [] === array_intersect(array_column($i['labels'], 'name'), LEVELS)) {
                echo $i['number'], "\n";
            }
        }

        return 0;
    }
    $n = issueNumber($args[0] ?? null);
    $issue = gh('api', "repos/{$repo}/issues/{$n}");
    if (isset($issue['pull_request'])) {
        throw new RuntimeException("#{$n} is a pull request");
    }
    $prs = [];
    foreach (ghList("repos/{$repo}/issues/{$n}/timeline") as $event) {
        $source = $event['source']['issue'] ?? null;
        if ('cross-referenced' === ($event['event'] ?? null) && isset($source['pull_request'])) {
            $prs[$source['number']] = ['number' => $source['number'], 'state' => null !== ($source['pull_request']['merged_at'] ?? null) ? 'merged' : $source['state']];
        }
    }
    echo json([
        'number' => $n, 'title' => $issue['title'], 'state' => $issue['state'], 'author_association' => $issue['author_association'],
        'body' => $issue['body'] ?? '',
        'comments' => array_map(static fn (array $c) => ['author_association' => $c['author_association'], 'body' => $c['body']], ghList("repos/{$repo}/issues/{$n}/comments")),
        'linked_pull_requests' => array_values($prs),
    ]);

    return 0;
}

function cmdScore(array $args): int
{
    $tag = option($args, '--last-tag');
    $comparison = loadRatings($args[0] ?? throw new InvalidArgumentException('score <dir> <issue>'), issueNumber($args[1] ?? null), $tag);
    echo json(['status' => $comparison['status'], 'rater' => summary($comparison['rater'], $tag), 'challenger' => summary($comparison['challenger'], $tag)]);

    return 0;
}

function issueContext(string $dir, int $n, ?string $pick, bool $withComment): array
{
    $repo = repo();
    if (isset(gh('api', "repos/{$repo}/issues/{$n}")['pull_request'])) {
        throw new RuntimeException("#{$n} is a pull request: severity labels go on issues only");
    }
    $comparison = loadRatings($dir, $n, null);
    $comparison = null === $pick ? $comparison : pick($comparison, $pick);
    $release = release(shown($comparison)['rating'], null);
    $labels = array_column(ghList("repos/{$repo}/issues/{$n}/labels"), 'name');
    $comments = array_map(static fn (array $c) => ['id' => $c['id'], 'author_association' => $c['author_association'], 'body' => $c['body']], ghList("repos/{$repo}/issues/{$n}/comments"));
    $sha = trim(run(['git', '-C', repoRoot(), 'rev-parse', EVIDENCE_REF]));

    return [$comparison, $release, $labels, issuePayload($n, $comparison, $release, $labels, $comments, repoUrl(), $sha, $withComment)];
}

/**
 * One report row. A settled rating shows its own facts; an unsettled one shows the rater's, and both sides wherever the
 * challenger says otherwise.
 */
function reportRow(int $n, array $comparison, array $labels, array $payload, ?string $tag): string
{
    $value = static function (callable $get) use ($comparison): string {
        if (null !== $comparison['final']) {
            return $get($comparison['final']);
        }
        $rater = $get($comparison['rater']);
        $challenger = null === $comparison['challenger'] ? $rater : $get($comparison['challenger']);

        return $rater === $challenger ? $rater : "{$rater} / {$challenger}";
    };
    $a = shown($comparison);
    $severity = match ($comparison['status']) {
        'disputed' => 'disputed: rater ' . side($comparison['rater']) . ', challenger ' . side($comparison['challenger']),
        'unverified' => 'unverified: the challenger verified none of the factors behind this label',
        'unchallenged' => "unchallenged, no label: rater {$a['calibration']}",
        default => null !== $a['level'] ? "{$a['level']} ({$a['points']} points" . (null === $a['label'] ? ', no label: claim or confidence' : '') . ')' : $a['calibration'],
    };
    $release = $value(static function (array $x) use ($tag): string {
        $r = release($x['rating'], $tag);
        $note = null === $r['target'] || !array_key_exists('note', $r) ? '' : ' (' . ($r['note'] ?? 'milestone exists') . ')';

        return $r['text'] . $note;
    });
    $today = implode(', ', array_intersect($labels, LEVELS)) ?: 'no severity';
    $change = [] === $payload['labels']['add'] && [] === $payload['labels']['remove'] ? 'same' : 'change';

    return sprintf('| #%d | %s | %s | %s | %s | %s | %s → %s |', $n, $value(static fn (array $x): string => $x['rating']['type']), $value(static fn (array $x): string => $x['rating']['claim'] ?? '-'), $severity, $release, $value(static fn (array $x): string => $x['rating']['confidence']), $today, $change);
}

/** @return list<string> what the maintainer must read under the table for this issue */
function issueNotes(int $n, array $comparison, array $payload): array
{
    $notes = [];
    if (($comparison['rater']['rating']['injection']['found'] ?? false) || ($comparison['challenger']['rating']['injection']['found'] ?? false)) {
        $notes[] = "#{$n}: the text contains instructions to agents. They were not followed.";
    }
    $verdicts = $comparison['challenger']['rating']['verdicts'] ?? [];
    if ([] !== $verdicts) {
        $notes[] = "#{$n} challenger: " . implode(' · ', array_map(static fn ($k, $v) => "{$k} {$v}", array_keys($verdicts), $verdicts)) . '.';
    }
    if (null !== $payload['note']) {
        $notes[] = "#{$n}: {$payload['note']}.";
    }

    return $notes;
}

function cmdReport(array $args): int
{
    $dir = $args[0] ?? throw new InvalidArgumentException('report <dir>');
    if ([] === ratedIssues($dir)) {
        throw new RuntimeException("{$dir} is missing or holds no rating");
    }
    $rows = $payloads = $critical = $notes = $stale = [];
    foreach (ratedIssues($dir) as $n) {
        try {
            [$comparison, , $labels, $payload] = issueContext($dir, $n, null, in_array('--comment', $args, true));
        } catch (RejectedRating $e) {
            $rows[] = "| #{$n} | not rated | | | | | |";
            $notes[] = "#{$n}: " . $e->getMessage();
            continue;
        }
        $rows[] = reportRow($n, $comparison, $labels, $payload, null);
        array_push($notes, ...issueNotes($n, $comparison, $payload));
        $a = shown($comparison);
        if ('Critical' === $a['level']) {
            $critical[$n] = $a['points'];
        }
        $stale = array_values(array_unique([...$stale, ...release($a['rating'], null)['stale']]));
        $payloads[] = $payload;
    }
    echo "| issue | type | claim | severity (why) | release (why) | confidence | today → gap |\n|---|---|---|---|---|---|---|\n", implode("\n", $rows), "\n";
    foreach ($notes as $note) {
        echo "\n{$note}";
    }
    if (count($critical) * 3 > count($payloads)) {
        arsort($critical);
        echo "\nMore than a third of this batch is Critical. Ranked by points: #" . implode(', #', array_keys($critical)) . '.';
    }
    echo [] === $stale ? '' : "\nOpen milestones already tagged: " . implode(', ', $stale) . '. Not used.', "\n\nPayloads (nothing written):\n", json($payloads);

    return 0;
}

/**
 * Stops before any write unless every label is a severity label and every label added exists on the repository.
 *
 * @param array{add: list<string>, remove: list<string>} $labels
 * @param list<string> $repoLabels
 */
function assertWritable(array $labels, array $repoLabels): void
{
    if ([] !== array_diff([...$labels['add'], ...$labels['remove']], LEVELS)) {
        throw new RuntimeException('only the four severity labels may be written on an issue');
    }
    $absent = array_diff($labels['add'], $repoLabels);
    if ([] !== $absent) {
        throw new RuntimeException('label ' . implode(', ', $absent) . ' missing: a maintainer creates it first. Nothing written.');
    }
}

/** Why a payload writes nothing, in the words the maintainer needs. */
function nothingReason(array $comparison, array $payload): string
{
    if (isset($payload['comment']['held'])) {
        return (null === $payload['note'] ? '' : "{$payload['note']}; ") . 'only the triage comment would change, and it is written with --comment';
    }
    if (null !== $payload['note']) {
        return $payload['note'];
    }
    $final = $comparison['final'];
    $r = $final['rating'];
    if (null !== $final['label'] || (in_array($r['type'], ['improvement', 'feature'], true) && 'low' !== $r['confidence'])) {
        return 'the issue already shows this rating';
    }
    $why = 'bug' !== $r['type'] ? "a {$r['type']} gets no severity" : ('low' === $r['confidence'] ? 'the confidence is low' : "the claim is {$r['claim']}");

    return [] === $r['missing'] ? "{$why}, and the rating asks the reporter nothing" : "{$why}, and the issue already has its triage comment";
}

/**
 * The confirmed write for one issue: an unsettled rating writes nothing, nothing is written without --apply, and a failure
 * says what is already written. $write runs one gh command.
 *
 * @param list<string> $repoLabels
 * @param callable(list<string>, ?string): string $write
 */
function applyIssue(int $n, array $comparison, array $payload, bool $apply, string $repo, array $repoLabels, callable $write): string
{
    $refusal = applyRefusal($comparison);
    if (null !== $refusal) {
        throw new RuntimeException("#{$n} {$refusal}");
    }
    $out = json($payload);
    if ('update' === ($payload['comment']['held'] ?? null)) {
        $out .= "The triage comment on this issue differs from what --comment would write.\n";
    }
    $labels = $payload['labels'];
    $comment = $payload['comment'];
    if ([] === $labels['add'] && [] === $labels['remove'] && 'none' === $comment['action']) {
        return $out . 'Nothing to write: ' . nothingReason($comparison, $payload) . ".\n";
    }
    if (!$apply) {
        return $out . "Dry run: nothing written. Rerun with --apply to write exactly this.\n";
    }
    assertWritable($labels, $repoLabels);
    $labelsWritten = false;
    if ([] !== $labels['add'] || [] !== $labels['remove']) {
        $edit = ['gh', 'issue', 'edit', (string) $n, '-R', $repo];
        foreach ($labels['add'] as $l) {
            array_push($edit, '--add-label', $l);
        }
        foreach ($labels['remove'] as $l) {
            array_push($edit, '--remove-label', $l);
        }
        $write($edit, null);
        $labelsWritten = true;
        $out .= "Written: labels.\n";
    }
    if ('none' !== $comment['action']) {
        $path = 'create' === $comment['action'] ? "repos/{$repo}/issues/{$n}/comments" : "repos/{$repo}/issues/comments/{$comment['id']}";
        try {
            $write(['gh', 'api', '-X', 'create' === $comment['action'] ? 'POST' : 'PATCH', $path, '--input', '-'], json(['body' => $comment['body']]));
        } catch (RuntimeException $e) {
            throw new RuntimeException(($labelsWritten ? 'the labels are written, ' : '') . "the comment is not ({$e->getMessage()}): rerun apply to finish");
        }
        $out .= "Written: comment ({$comment['action']}).\n";
    }

    return $out;
}

/** @return array{dir: string, n: int, pick: ?string, apply: bool, comment: bool} */
function applyArgs(array $args): array
{
    return [
        'dir' => $args[0] ?? throw new InvalidArgumentException('apply <dir> <issue>'),
        'n' => issueNumber($args[1] ?? null),
        'pick' => option($args, '--pick'),
        'apply' => in_array('--apply', $args, true),
        'comment' => in_array('--comment', $args, true),
    ];
}

function cmdApply(array $args): int
{
    ['dir' => $dir, 'n' => $n, 'pick' => $pick, 'apply' => $apply, 'comment' => $comment] = applyArgs($args);
    [$comparison, , , $payload] = issueContext($dir, $n, $pick, $comment);
    echo applyIssue($n, $comparison, $payload, $apply, repo(), $apply ? repoLabels() : [], 'run');

    return 0;
}

/** One rated issue of one run as calibration counts it; a rating that is not settled agrees with nothing. */
function runEntry(array $comparison, ?string $tag): array
{
    $unsettled = in_array($comparison['status'], ['disputed', 'unverified', 'unchallenged'], true);
    $flip = null === $comparison['challenger'] ? null : $comparison['rater']['calibration'] !== $comparison['challenger']['calibration'];

    return [
        'calibration' => $unsettled ? $comparison['status'] : shown($comparison)['calibration'],
        'line' => $unsettled ? $comparison['status'] : release(shown($comparison)['rating'], $tag)['line'],
        'flip' => $flip,
    ];
}

function cmdCalibrate(array $args): int
{
    $sheet = parseSheet((string) file_get_contents($args[0] ?? throw new InvalidArgumentException('calibrate <sheet> <dir>...')));
    $runs = [];
    foreach (array_slice($args, 1) as $dir) {
        $run = [];
        if ([] === ratedIssues($dir)) {
            throw new RuntimeException("{$dir} is missing or holds no rating: every run folder counts");
        }
        foreach (ratedIssues($dir) as $n) {
            try {
                $run[$n] = runEntry(loadRatings($dir, $n, null), null);
            } catch (RejectedRating $e) {
                fwrite(STDERR, "{$e->getMessage()}\n");
                $run[$n] = ['calibration' => 'rejected', 'line' => 'rejected', 'flip' => null];
            }
        }
        $runs[] = $run;
    }
    $metrics = calibrationMetrics($sheet, $runs);
    echo json($metrics), count($runs) < 2 ? "one run: stability needs two runs or more, so the result is never trusted\n" : '', 'trusted: ', $metrics['trusted'] ? 'yes' : 'no', "\n";

    return 0;
}

function usage(): int
{
    fwrite(STDERR, USAGE . "\n");

    return 2;
}

/** The checks live beside the script and load only when asked for. */
function runSelfTest(): int
{
    require_once __DIR__ . '/triage-test.php';

    return selfTest();
}

function main(array $args): int
{
    try {
        return match ($args[0] ?? null) {
            '--self-test' => runSelfTest(),
            'fetch' => cmdFetch(array_slice($args, 1)),
            'score' => cmdScore(array_slice($args, 1)),
            'report' => cmdReport(array_slice($args, 1)),
            'apply' => cmdApply(array_slice($args, 1)),
            'calibrate' => cmdCalibrate(array_slice($args, 1)),
            default => usage(),
        };
    } catch (Throwable $e) {
        fwrite(STDERR, 'triage: ' . $e->getMessage() . "\n");

        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    exit(main(array_slice($argv, 1)));
}
