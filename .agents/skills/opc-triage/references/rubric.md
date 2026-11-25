# Triage rubric

The rater scores the four factors below, each with evidence. `triage.php` turns the scores into a level. The script
reads the tables in this file: keep their columns as they are.

## Type

| Type | Meaning |
|---|---|
| `bug` | The module does not do what it promises or what core's checkout does. |
| `improvement` | The module works as designed; the issue asks for a better behaviour. |
| `feature` | The issue asks for something the module does not do at all. |
| `question` | The reporter asks how something works. |
| `not actionable` | Spam, a duplicate, or nothing the module can change. |

## Claim (bugs only)

Check the claim on `origin/main` before scoring:

- `confirmed`: the code path that produces the reported behaviour is found, with its `file:line`.
- `not reproduced`: the code does not behave as reported. No label is set.
- `insufficient detail`: the issue lacks the steps or the versions needed to check it. No label is set; the reporter
  gets one question.

## Factors

The phrase column is the text of the comment posted on the issue.

| Factor | Score | Meaning | Phrase |
|---|---|---|---|
| impact | 0 | Cosmetic. | a cosmetic defect |
| impact | 1 | Annoying, with an easy workaround. | an annoyance |
| impact | 2 | Blocks a task, shows wrong data, or the workaround is hard. | a blocked task or wrong data shown |
| impact | 3 | The order cannot be placed, an amount is wrong, data is lost, or security is at stake. | a failed order, wrong amount, data loss or security risk |
| reach | 0 | A rare configuration. | in a rare configuration |
| reach | 1 | One theme, country or option. | with one theme, country or option |
| reach | 2 | A common checkout path. | on a common checkout path |
| reach | 3 | Every one-page checkout. | on every one-page checkout |
| workaround | 0 | None needed. | no workaround is needed |
| workaround | 1 | Easy. | an easy workaround exists |
| workaround | 2 | Hard, or only the merchant can apply it. | the workaround is hard or merchant-only |
| workaround | 3 | None. | no workaround |
| stage | 0 | Back office only. | in the back office |
| stage | 1 | Before the contact step, or at it. | at the contact step or before |
| stage | 2 | Address or carrier step. | at the address or carrier step |
| stage | 3 | Payment or final submit. | at payment or final submit |

## Flags

A flag is set only with evidence that the defect causes it. Its phrase replaces the impact phrase in the comment.

| Flag | Meaning | Phrase |
|---|---|---|
| security | An attacker can read or change data that is not theirs, or act as someone else. | a security risk |
| data_loss | Saved data is deleted or overwritten. | data loss |
| wrong_amount | The amount charged or shown at payment is wrong. | a wrong amount at payment |
| order_blocked | The order cannot be placed. | an order that cannot be placed |

## Level

Points are `3 × impact + 2 × reach + 2 × workaround`, out of 21. Levels are core's severity labels: 15 and more
`Critical`, 10 to 14 `Major`, 5 to 9 `Minor`, under 5 `Trivial`. The stage adds no point: what makes a late
defect serious is already its impact. It is still rated, because the comment says where the defect happens.

Floors: `security`, `data_loss` or `wrong_amount` sets at least `Major`, and `Critical` when reach is 2 or more;
`order_blocked` with reach 2 or more sets `Critical`.

Core's definitions ([severity classification](https://build.prestashop.com/news/severity-classification)): Critical
blocks orders or shop operations with no workaround, loses data or opens a security hole; Major has a hard workaround or
leaves a module not operating properly; Minor has an annoying workaround; Trivial is cosmetic.

## Questions to the reporter

When facts are missing, the comment is the first matching question, in this order.

| Missing | Question |
|---|---|
| steps | Could you list the steps that lead to the problem, starting from an empty cart? |
| versions | Which versions of PrestaShop, PHP and this module does the shop run? |
| impact | What does the buyer or the merchant see when it happens: an error, a wrong value, a blocked order? |
| workaround | Is there a way to complete the order anyway, and if so which one? |
| stage | At which step of the checkout does it happen: contact, address, carrier, payment or final submit? |

## Worked examples

`triage.php --self-test` checks every row.

| impact | reach | workaround | stage | flags | points | level |
|---|---|---|---|---|---|---|
| 0 | 0 | 0 | 0 | | 0 | Trivial |
| 0 | 2 | 0 | 3 | | 4 | Trivial |
| 1 | 1 | 0 | 3 | | 5 | Minor |
| 1 | 2 | 1 | 0 | | 9 | Minor |
| 2 | 1 | 1 | 0 | | 10 | Major |
| 2 | 2 | 2 | 3 | | 14 | Major |
| 3 | 2 | 1 | 0 | | 15 | Critical |
| 2 | 2 | 3 | 0 | | 16 | Critical |
| 2 | 2 | 3 | 3 | | 16 | Critical |
| 3 | 0 | 0 | 0 | security | 9 | Major |
| 3 | 2 | 0 | 0 | data_loss | 13 | Critical |
| 2 | 1 | 1 | 3 | order_blocked | 10 | Major |
| 2 | 2 | 1 | 3 | order_blocked | 12 | Critical |
