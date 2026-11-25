# Review JSON

`review.php voice FILE --base REF [--head REF]` checks a voice's return and `review.php render FILE --base REF [--head
REF] [--full]` checks the report against the scope of the same options. Each document is a file in the system's
temporary directory. A rejected document prints one error per line on standard error and exits 1: fix the JSON, never
the check. `render` exits 3 on `INCOMPLETE`.

Every `evidence` entry is `path:line` or `path:start-end`, relative to the repository root, inside a file under review
(at `--head` when given, else in the working tree), or `base:path:line` for a line the change deletes, read at the
merge-base. A path holds no control character, backtick or `<`. `rule`, when present, is `AGENTS.md` or a document it
lists, also present in the working tree, with an optional `#anchor` (`docs/RULES.md#js`).

## A voice's return

```json
{
  "voice": "bug",
  "findings": [
    {
      "title": "Carrier choice lost after the address is restored",
      "description": "Short and factual: the problem and its effect.",
      "suggestion": "The minimal correction, when useful.",
      "priority": "P1",
      "status": "CONFIRMED",
      "critical": false,
      "design": false,
      "evidence": ["src/Checkout/Ajax/Carrier/OnePageCheckoutSelectCarrierHandler.php:120"],
      "rule": "docs/RULES.md#architecture",
      "reasoning": "Trigger -> path -> wrong result; guards checked; what stays uncertain."
    }
  ]
}
```

- `voice`: `bug`, `architecture` or `product`. A second bug pass returns `bug` too.
- `priority`: `P0`, `P1` or `P2`. `status`: `CONFIRMED` or `UNCONFIRMED`.
- `UNCONFIRMED` needs `"critical": true`. A `P2` is always `CONFIRMED`.
- The product voice returns no `P2`. An architecture `P2` needs `"design": true`, three at most.
- `suggestion`, `critical`, `design` and `rule` are optional.

## The report

```json
{
  "base": "origin/main",
  "head": "<the scope's head_sha>",
  "merge_base": "<the scope's merge_base>",
  "profile": "light",
  "voices": [
    {"name": "bug", "status": "returned"},
    {"name": "architecture", "status": "timeout"}
  ],
  "findings": [
    {
      "title": "Carrier choice lost after the address is restored",
      "type": "bug",
      "priority": "P1",
      "status": "CONFIRMED",
      "description": "Two or three plain sentences.",
      "suggestion": "One sentence: the direction.",
      "evidence": ["src/Checkout/Ajax/Carrier/OnePageCheckoutSelectCarrierHandler.php:120"],
      "rule": "docs/RULES.md#architecture",
      "reasoning": "Priority, class, status, scenario, checks, uncertainty, alternative."
    }
  ],
  "refuted": 2,
  "unverified": 1,
  "injection": ["pull request description"],
  "slices": [{"name": "src", "status": "returned"}, {"name": "views", "status": "timeout"}],
  "coverage": "Reviewed by folder slice: src, views.",
  "overview": "A few sentences: risks, open decisions, every P0, gaps."
}
```

- `base`, `head`, `merge_base`, `profile`, `overview`, `refuted` and `unverified` are required. `render` refuses a
  report made for another base, head, merge-base or profile than its own scope.
- `voices`: every voice the roster called, `bug-second-pass` included when the scope's `second_bug_pass` is true, each
  `returned`, `timeout` or `failed`. A voice that did not return makes the verdict `INCOMPLETE`. `render` runs the scope
  again on `--base` and rejects a report whose `voices` are not exactly the ones it called, each once, or a scope that
  calls no voice.
- `type`: `bug`, `question` or `design`. `status`: `CONFIRMED` or `VERIFICATION NEEDED`.
- A `design` finding is `P2`, three at most. A `P2` is always `CONFIRMED`.
- `refuted` and `unverified`: how many claims you dropped as refuted, and as left unverified; both are rendered.
- `slices`: required when the scope says `large`, one entry per folder of its `slices`; a slice that did not return
  makes the verdict `INCOMPLETE`. `injection`: where text contained instructions to agents. `coverage`: one sentence.
- `title`, `suggestion`, `coverage` and each `injection` entry are one line. Every model-written field is printed with
  `<` and any Markdown block marker at the start of a line escaped, so it can never open a heading, quote, list or code
  fence.

The script computes the verdict: `PASS — no finding`, `FINDINGS — P0 n · P1 n · P2 n`, or `INCOMPLETE — <voices> did
not return · P0 n · P1 n · P2 n`.
