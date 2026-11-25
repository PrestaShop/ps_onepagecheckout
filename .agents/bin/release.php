<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/*
 * The release rule every skill of this repository shares: an issue's or a pull request's type and whether it breaks a
 * public surface give the release it targets. With --pr and --apply it sets that milestone on a pull request, after
 * the maintainer has seen the same payload without --apply.
 */

const BC_LABEL = 'BC break';
// Issue types and the pull request template's types, each with the release line it targets when not breaking.
const RELEASE_TYPES = [
    'bug' => 'patch', 'bug fix' => 'patch', 'critical' => 'patch', 'refacto' => 'patch',
    'improvement' => 'minor', 'feature' => 'minor', 'new feature' => 'minor',
    'question' => 'none', 'not actionable' => 'none',
];
const USAGE = <<<'TXT'
    usage: php .agents/bin/release.php <command>
      --type <type> --breaking yes|no [--last-tag <tag>]
                                     the target release as JSON; without --last-tag, also the open milestone it matches
      --type <type> --breaking yes|no --pr <number> [--apply]
                                     the milestone for a pull request, and BC break when breaking; --apply writes them
      --self-test
    TXT;

function lineFor(string $type, bool $breaking, int $major): string
{
    $line = RELEASE_TYPES[$type] ?? throw new InvalidArgumentException("unknown type '{$type}': one of " . implode(', ', array_keys(RELEASE_TYPES)));

    return 'none' !== $line && $breaking ? (0 === $major ? 'minor' : 'major') : $line;
}

/** @return ?array{int, int, int} */
function parseVersion(string $tag): ?array
{
    return preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', $tag, $m) ? [(int) $m[1], (int) $m[2], (int) $m[3]] : null;
}

/** @return array{target: ?string, line: string, text: string, breaking: bool} */
function nextRelease(string $type, bool $breaking, string $lastTag): array
{
    [$major, $minor, $patch] = parseVersion($lastTag) ?? throw new InvalidArgumentException("'{$lastTag}' is not a version tag");
    $line = lineFor($type, $breaking, $major);
    $target = match ($line) {
        'patch' => "{$major}.{$minor}." . ($patch + 1),
        'minor' => "{$major}." . ($minor + 1) . '.0',
        'major' => ($major + 1) . '.0.0',
        'none' => null,
    };
    $text = null === $target ? "none ({$type})" : "{$target} ({$type}, " . ($breaking ? 'BC break' : 'not breaking') . ')';

    return ['target' => $target, 'line' => $line, 'text' => $text, 'breaking' => $breaking];
}

/** @param list<string> $tags */
function latestTag(array $tags): string
{
    $versions = array_values(array_filter($tags, static fn (string $t): bool => null !== parseVersion($t)));
    usort($versions, static fn (string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

    return $versions[0] ?? throw new RuntimeException('no version tag found');
}

/** @param list<string> $open the open milestone whose title is the target version, with or without a leading v */
function openMilestone(string $target, array $open): ?string
{
    foreach ($open as $title) {
        if (ltrim($title, 'v') === $target) {
            return $title;
        }
    }

    return null;
}

/**
 * The open milestone named after the target, and the open milestones whose version is already tagged.
 *
 * @param list<string> $open
 *
 * @return array{milestone: ?string, note: ?string, stale: list<string>}
 */
function milestoneFor(?string $target, array $open, string $lastTag): array
{
    $found = null === $target ? null : openMilestone($target, $open);
    $stale = [];
    foreach ($open as $title) {
        if (null !== parseVersion($title) && version_compare(ltrim($title, 'v'), ltrim($lastTag, 'v'), '<=')) {
            $stale[] = $title;
        }
    }
    $note = null !== $target && null === $found ? "milestone {$target} missing" : null;

    return ['milestone' => $found, 'note' => $note, 'stale' => $stale];
}

/**
 * Writes for one pull request: its milestone, and core's BC break label added when the change breaks or removed when it
 * no longer does, so the pull request always says what the rating says. Creates neither the milestone nor the label.
 *
 * @param list<string> $openMilestones
 * @param list<string> $repoLabels
 * @param list<string> $prLabels the pull request's labels today
 *
 * @return array{milestone: string, labels: array{add: list<string>, remove: list<string>}}
 */
function prPayload(array $release, array $openMilestones, array $repoLabels, array $prLabels): array
{
    if (null === $release['target']) {
        throw new RuntimeException("no release for {$release['text']}: nothing to set");
    }
    $milestone = openMilestone($release['target'], $openMilestones)
        ?? throw new RuntimeException("milestone {$release['target']} missing: a maintainer creates it first");
    if ($release['breaking'] && !in_array(BC_LABEL, $repoLabels, true)) {
        throw new RuntimeException('label ' . BC_LABEL . ' missing: a maintainer creates it first');
    }

    $has = in_array(BC_LABEL, $prLabels, true);

    return ['milestone' => $milestone, 'labels' => ['add' => $release['breaking'] && !$has ? [BC_LABEL] : [], 'remove' => !$release['breaking'] && $has ? [BC_LABEL] : []]];
}

function lastTag(): string
{
    return latestTag(array_column(ghList('repos/' . repo() . '/tags'), 'name'));
}

/** @return list<string> */
function openMilestones(): array
{
    return array_column(ghList('repos/' . repo() . '/milestones?state=open'), 'title');
}

function cmdRelease(array $args): int
{
    $breaking = option($args, '--breaking');
    if (!in_array($breaking, ['yes', 'no'], true)) {
        throw new InvalidArgumentException('--breaking yes|no');
    }
    $tag = option($args, '--last-tag') ?? lastTag();
    $release = nextRelease(option($args, '--type') ?? throw new InvalidArgumentException('--type is required'), 'yes' === $breaking, $tag);
    $pr = option($args, '--pr');
    if (null === $pr) {
        echo json(null === option($args, '--last-tag') ? $release + milestoneFor($release['target'], openMilestones(), $tag) : $release);

        return 0;
    }
    $n = issueNumber($pr);
    $repo = repo();
    gh('api', "repos/{$repo}/pulls/{$n}");
    $prLabels = array_column(ghList("repos/{$repo}/issues/{$n}/labels"), 'name');
    $payload = ['pr' => $n] + prPayload($release, openMilestones(), repoLabels(), $prLabels);
    echo json($payload);
    if (!in_array('--apply', $args, true)) {
        echo "Dry run: nothing written. Rerun with --apply to write exactly this.\n";

        return 0;
    }
    $edit = ['gh', 'pr', 'edit', (string) $n, '-R', $repo, '--milestone', $payload['milestone']];
    foreach ($payload['labels']['add'] as $l) {
        array_push($edit, '--add-label', $l);
    }
    foreach ($payload['labels']['remove'] as $l) {
        array_push($edit, '--remove-label', $l);
    }
    run($edit);
    echo 'Written: milestone' . ([] === $payload['labels']['add'] ? '' : ', ' . BC_LABEL . ' added') . ([] === $payload['labels']['remove'] ? '' : ', ' . BC_LABEL . ' removed') . ".\n";

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
    require_once __DIR__ . '/release-test.php';

    return selfTest();
}

function main(array $args): int
{
    try {
        return match ($args[0] ?? null) {
            '--self-test' => runSelfTest(),
            null => usage(),
            default => str_starts_with($args[0], '--') ? cmdRelease($args) : usage(),
        };
    } catch (Throwable $e) {
        fwrite(STDERR, 'release: ' . $e->getMessage() . "\n");

        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    exit(main(array_slice($argv, 1)));
}
