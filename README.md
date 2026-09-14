# Pimcore Gatekeeper AI Bundle

AI enrichment for Pimcore DataObjects, driven by the
[Gatekeeper completeness bundle](https://github.com/Kera95/pimcore-gatekeeper-bundle): proposes
values for the fields your gate reports missing, per language, from a Markdown knowledge base in
the asset tree. Proposals land in a table, are reviewed and applied by console command, and are
never written blind. Anthropic Claude, prompt caching, cost ceiling, no vector search, MIT.

**What it is not:** a chat window, a RAG pipeline, a Studio plugin. It is a batch tool for the
person who owns product data: run it, read the table, approve what is good, apply, done.

## How it fits together

```
tsf_gatekeeper (v1)            tsf_gatekeeper_ai (this bundle)
────────────────────           ─────────────────────────────────────────────────
required fields, gate    ──▶   findFailing(): which object misses which field
                               + knowledge base (Markdown assets)
                               + field definitions (type, options, length)
                               ──▶ one request per (object, language)
                               ──▶ tsf_gatekeeper_ai_proposal (pending)
                               review ▶ approve ▶ apply ▶ object->save()
score, gate re-evaluated ◀──
```

Only fields that are **required by a Gatekeeper rule** and **listed under `enrich`** are ever
proposed. Only `input`, `textarea`, `wysiwyg`, `select` and `multiselect` fields can be proposed;
identifiers and prices are on a deny list. Bricks and field collections are not supported in 2.0.

## Quick start

```bash
composer require kerimkaralic/pimcore-gatekeeper-ai-bundle
# register Tsf\GatekeeperAiBundle\TsfGatekeeperAiBundle in config/bundles.php (after the Gatekeeper bundle)
bin/console pimcore:bundle:install TsfGatekeeperAiBundle
export ANTHROPIC_API_KEY=sk-ant-...             # .env.local, container env, secret store - never a committed file

# 1. say which fields the model may fill (config/packages/tsf_gatekeeper_ai.yaml)
# 2. upload a few Markdown files to /gatekeeper/context (who you are, tone, per-field rules)
bin/console tsf:gatekeeper:ai:validate --live   # key, model, fields, knowledge base, exact prefix size
bin/console tsf:gatekeeper:ai:propose --estimate
bin/console tsf:gatekeeper:ai:propose -c Product --limit 20
bin/console tsf:gatekeeper:ai:review
bin/console tsf:gatekeeper:ai:approve --id 12,13,14
bin/console tsf:gatekeeper:ai:apply --dry-run && bin/console tsf:gatekeeper:ai:apply
```

## Commands

| Command | Does | Talks to the API |
| --- | --- | --- |
| `tsf:gatekeeper:ai:validate [--live]` | Checks config, classes, fields, key, pricing, knowledge base; `--live` counts the tokens of a sample request | only with `--live` (token counting, free) |
| `tsf:gatekeeper:ai:context [-c Class] [--summary]` | Prints the assembled knowledge base, its size, hash and cache-floor check | no |
| `tsf:gatekeeper:ai:propose [...]` | Plans, estimates, dry-runs or runs the generation; stores proposals | yes (not with `--estimate` / `--dry-run`) |
| `tsf:gatekeeper:ai:review [...]` | Lists proposals as table, CSV or Markdown | no |
| `tsf:gatekeeper:ai:approve` / `reject` | Records the decision on rows | no |
| `tsf:gatekeeper:ai:apply [--dry-run]` | Writes approved rows into the objects, reports the score delta | no |

## Requirements

- PHP 8.1 – 8.5, Pimcore 11.x, 12.x or 2026.x
- `kerimkaralic/pimcore-gatekeeper-bundle` ^1.1, installed and configured
- An Anthropic API key

## Installation

```bash
composer require kerimkaralic/pimcore-gatekeeper-ai-bundle
```

Register the bundle in `config/bundles.php` (after the Gatekeeper bundle) and install it — the
installer creates the `tsf_gatekeeper_ai_proposal` table:

```php
Tsf\GatekeeperBundle\TsfGatekeeperBundle::class => ['all' => true],
Tsf\GatekeeperAiBundle\TsfGatekeeperAiBundle::class => ['all' => true],
```

```bash
bin/console pimcore:bundle:install TsfGatekeeperAiBundle
```

Put the API key into the environment (`.env.local`, the container environment, your secret store —
never a committed file):

```
ANTHROPIC_API_KEY=sk-ant-...
```

## Configuration

`config/packages/tsf_gatekeeper_ai.yaml`; every key is optional except `classes.*.enrich`. The
full annotated example is in [`docs/configuration.example.yaml`](docs/configuration.example.yaml).

```yaml
tsf_gatekeeper_ai:
    anthropic:
        model: claude-opus-5          # or claude-sonnet-5, claude-haiku-4-5
        effort: low
    context:
        asset_folder: /gatekeeper/context
    limits:
        max_objects_per_run: 200
        max_cost_per_run: 10.00       # USD, hard stop
    classes:
        Product:
            enrich: [title, short_description, long_description]
            languages: [en, de]       # omit for the languages of the Gatekeeper rule
            instructions: >
                Write for online shoppers. Short sentences, no superlatives.
```

| Key | Default | Meaning |
| --- | --- | --- |
| `provider` | `anthropic` | The only provider in this version. |
| `anthropic.api_key` | `%env(default::ANTHROPIC_API_KEY)%` | Never logged, never printed. |
| `anthropic.model` | `claude-opus-5` | Needs a `pricing` entry; three models are built in. |
| `anthropic.max_tokens` | `2048` | Output ceiling per request. |
| `anthropic.effort` | `low` | `low`, `medium`, `high`. |
| `anthropic.prompt_caching` | `true` | Cache the static prefix across a run. |
| `anthropic.cache_ttl` | `5m` | `5m` or `1h`. |
| `anthropic.timeout` | `60` | Seconds per request. |
| `anthropic.max_retries` | `3` | Attempts for rate limits, overloads, network errors. |
| `pricing.<model>` | built in for the three models | `input`, `output`, `cache_write`, `cache_read` in USD per million tokens. |
| `context.asset_folder` | `/gatekeeper/context` | Markdown knowledge base. |
| `context.max_tokens` | `20000` | Knowledge base ceiling; larger aborts the run. |
| `limits.max_objects_per_run` | `200` | Hard stop. |
| `limits.max_cost_per_run` | `10.0` | Hard stop in USD, from the reported usage. |
| `limits.avg_output_tokens_per_field` | `150` | For the estimate only. |
| `fields.deny` | `[sku, ean, gtin, price, id]` | Never proposed; matched case-insensitively on the last path segment. |
| `classes.<Class>.enrich` | required | Fields the model may fill. |
| `classes.<Class>.languages` | rule languages | Languages for localized fields. |
| `classes.<Class>.instructions` | `''` | Per-class text after the knowledge base. |

## The knowledge base

The quality of the output is the quality of this folder. Put Markdown files into the asset
folder `context.asset_folder` (default `/gatekeeper/context`): who the company is, tone of voice,
terminology per language, what each field is for, what buyers of each category care about,
examples of good copy, things never to write. No retrieval, no embeddings — the whole folder goes
into every request as a cached prompt prefix, so keep it focused (a few thousand tokens is a
good size; `context.max_tokens` caps it).

Layout, by folder convention only:

```
/gatekeeper/context/
    tone.md                 <- files directly in the folder: always read
    _global/company.md      <- _global/: always read
    Product/writing-rules.md   <- <ClassName>/: read for that class only
    Category/writing-rules.md
```

One level deep; other subfolders are ignored. Files are concatenated in path order, each under
a `## <path>` heading, so the same files always produce the same text and the same `kb_hash`.

```bash
bin/console tsf:gatekeeper:ai:context                 # prints the assembled text + numbers
bin/console tsf:gatekeeper:ai:context --class=Product --summary
```

Reports the files, characters, estimated tokens (chars / 4), the hash, and whether the size is
above the model's prompt-cache floor (512 tokens for Claude Opus 5, 1024 for Sonnet 5, 4096 for
Haiku 4.5) and below `context.max_tokens`. Exit code 1 when the folder is missing, empty or over
the ceiling.

## Check the setup

```bash
bin/console tsf:gatekeeper:ai:validate
```

Reports, per class, whether the Gatekeeper rule exists, whether every `enrich` field exists on
the class, may be proposed (type and deny list) and is actually required by the rule — a field
that is never reported missing is never proposed, so that is a warning. Globally it checks that
the API key is set, the model has a price and the knowledge base folder exists and has files.
Exit code 1 when anything blocks a run.

```bash
bin/console tsf:gatekeeper:ai:validate --live
```

Additionally sends a request shaped like the first one of a run to Anthropic's token counting
endpoint — free, nothing is generated. Proves the key and the model work and prints the exact
prefix size and whether it is above the model's cache floor. Run it once after configuring the
key. On failure you get the mapped error, for example:

```
ERROR live check via anthropic (claude-opus-5)
 * Anthropic API error authentication_error (HTTP 401): invalid x-api-key. The API key is
   invalid, revoked or missing - check ANTHROPIC_API_KEY (tsf_gatekeeper_ai.anthropic.api_key).
```

## Proposing

```bash
bin/console tsf:gatekeeper:ai:propose --estimate                 # what would it cost
bin/console tsf:gatekeeper:ai:propose --dry-run -c Product -vv   # whole pipeline, fake provider, nothing stored
bin/console tsf:gatekeeper:ai:propose -c Product --limit 20      # the real thing
```

`propose` never scans objects: it reads the Gatekeeper's failing rows and asks only for the
fields that are missing, listed under `enrich`, of a supported type and in a configured
language. The plan is printed first — one group per class and language, the objects and field
values it holds, and what was left out and why — then one request per (object, language) goes
out with the object's filled fields as context and a schema of exactly the missing fields.
Every value is validated and stored as `pending` or `invalid`.

| Option | Effect |
| --- | --- |
| `-c`, `--class` | Only this class. |
| `-p`, `--gate-profile` | Only fields reported by this Gatekeeper profile. |
| `-l`, `--language` | Only this language. |
| `-f`, `--fields` | Only these field paths, comma separated. |
| `--limit N` | At most N objects, in the order the Gatekeeper reports them. |
| `--estimate` | Price the plan (prefix once as a cache write, then reads; chars / 4; `avg_output_tokens_per_field`) and exit. |
| `--dry-run` | Run everything on the fake provider; nothing sent, nothing stored. |
| `--force` | Ask again for objects that already have proposals for the same input, knowledge base and prompt version. Without it those objects are skipped, so an interrupted run can simply be started again. |

Hard stops, checked before every request: `limits.max_objects_per_run`, `limits.max_cost_per_run`
(from the reported usage), and any fatal API error. A refusal, a truncated answer or a failure
that survived the retries skips the object and the run goes on. `-v` prints one line per object
with its tokens and cost, `-vv` one per field. The summary — requests, proposals by outcome,
tokens in / cache read / cache write / out, cost — is printed and logged.

A proposal remembers `source_hash` (the object as it was sent), `kb_hash`, `prefix_hash` and
`prompt_version`; a re-run replaces `pending`, `invalid` and `stale` rows and never touches
`approved`, `rejected`, `applied` or `blocked_by_gate` ones.

## Reviewing and applying

```bash
bin/console tsf:gatekeeper:ai:review                       # pending rows as a table
bin/console tsf:gatekeeper:ai:review -c Product -f csv > proposals.csv
bin/console tsf:gatekeeper:ai:review -s invalid            # what the validator threw out, and why
bin/console tsf:gatekeeper:ai:approve --id 12,13,14
bin/console tsf:gatekeeper:ai:approve --all -c Product -l de
bin/console tsf:gatekeeper:ai:reject --id 15
bin/console tsf:gatekeeper:ai:apply --dry-run              # field by field, nothing saved
bin/console tsf:gatekeeper:ai:apply -c Product
```

`review` lists rows (`-s pending` by default, `-s all` for everything) as a table, CSV or
Markdown. `approve` and `reject` need `--id` or `--all` plus filters — never everything by
accident; approve moves `pending` rows on, reject takes `pending` and `approved` rows out, and
neither touches any other status.

`apply` writes approved rows into their objects, **all fields of one object in one save**,
through the generated setters, so Pimcore versioning applies and a rollback is possible. Before
writing, every row is checked against the object as it is now:

- the field is still empty (the core bundle's own emptiness rules) — otherwise `stale`,
  "the field is no longer empty";
- the object's input for that language is unchanged (`source_hash`: the non-localized fields
  and the localized fields of that language, minus the enriched fields themselves) — otherwise
  `stale`, "the object changed since the proposal was made". A person who edits what the model
  worked from wins; applying the other language, or one enriched field before the next, does
  not count as a change.

The save skips the Gatekeeper's warn / block step — the object only gets more complete — but is
still scored, and the command prints the score per profile and language before and after:

```
 * 21 40941: default/de 71% → 100%, default/en 100% → 100%, print/de 25% → 38%
```

The publish state is never changed. A save Pimcore refuses for another reason (a mandatory
field, a unique constraint) marks the rows `blocked_by_gate` with the message and exits 1.

## What the model is told about a field

For every field in a request the bundle reads the Pimcore class definition and turns it into
both a prose line in the prompt and a property in the JSON schema the response is forced into:

```
- title: "Title" — single-line text, no line breaks, at most 190 characters. Shown in listings.
- long_description: "Long description" — HTML using only <p>, <ul>, <ol>, <li>, <strong>, <em>, <br>; …
- product_type: "Product type" — exactly one of: "simple" (Simple), "configurable" (Configurable).
- tags: "Tags" — a list of up to 3 values from: "new" (New), "sale" (Sale).
```

Title and tooltip come from the class definition — write them for a human and the model reads
them too. The schema guarantees the shape (one string or list per field, enums for selects); the
bundle then validates each value again before it becomes a proposal: trimmed, line breaks removed
from inputs, HTML reduced to the allowed tags, length against the field, select values by value
or label. A value that fails is stored with status `invalid` and the reason, never applied.

Supported field types: `input`, `textarea`, `wysiwyg`, `select`, `multiselect` (static options
only). Everything else, and every name on `fields.deny`, is skipped.

## The proposal table

`tsf_gatekeeper_ai_proposal` holds one row per (object, field, language) with the proposed value,
the value that was there, the hashes of the object's input (`source_hash`), the knowledge base
(`kb_hash`) and the exact cached prefix (`prefix_hash`), the prompt version, the model, the token
usage (input, output, cache read, cache write) and a status:

| Status | Meaning |
| --- | --- |
| `pending` | generated and validated, waiting for a decision |
| `approved` | a person approved it; the next apply run writes it |
| `rejected` | a person rejected it |
| `applied` | written to the object |
| `invalid` | the value did not fit the field definition; see `invalid_reason` |
| `stale` | the object changed between propose and apply; not written |
| `blocked_by_gate` | approved, but the Gatekeeper refused the save |

A propose re-run replaces `pending`, `invalid` and `stale` rows and never touches the others.

## Costs and safety

Every request carries the same prefix (instructions, knowledge base, field descriptions) and a
small per-object tail, and Anthropic serves the prefix from cache at a tenth of the input price
after the first object of a group. Rough numbers with the bundled test data (36 products, 6
categories, a 2.5k-token knowledge base, Claude Opus 5): **98 requests ≈ $1.20**. `--estimate`
prints the figure for your data before anything is sent.

Guards, all on by default:

- `limits.max_objects_per_run` (200) and `limits.max_cost_per_run` ($10) — checked before every
  request; the run stops and says so.
- `context.max_tokens` (20 000) — a knowledge base above it aborts the run.
- `--estimate` and `--dry-run` never send a generation request.
- A fatal API error (key, model, billing, permissions) stops the run at once instead of failing
  200 objects one by one.
- Nothing is written to an object without a person approving the row and running `apply`.

## What leaves the system, and when

For the data protection officer, in one paragraph: **only `propose` (and `validate --live`, which
counts tokens) sends data to Anthropic's API**, over HTTPS, using your own API key. Per request
the payload is: the fixed instructions, the Markdown files of the knowledge base folder, the
descriptions of the fields to fill (titles and tooltips from the class definition), and for the
one object being processed the values of its filled text-like fields (inputs, textareas, wysiwyg
stripped to text, selects, numbers, dates, quantities, the keys of related elements). Images,
files, tables, bricks, collections and password fields are never sent. Nothing is sent about
users, orders or customers unless you put it into a DataObject field the object carries or into
the knowledge base. Whether inputs are used for training and how long they are retained is
governed by your Anthropic agreement (the commercial API terms exclude training by default at the
time of writing — check yours). The full request payload is written to the log only at
`-vvv` (debug level), never the API key. If a class holds personal data, keep it off the `enrich`
list and out of the knowledge base — the bundle sends the *object's* fields as context, not only
the ones it asks for.

## What happens on an API error

Every request goes through one place that maps the outcome:

| Outcome | What the bundle does |
| --- | --- |
| 401 / 402 / 403 / 404 / 400 | Fatal: the run stops (every further request would fail the same way). The console shows the vendor message and what to check; the same line is in the log. |
| 413 request too large | The object is skipped; lower `context.max_tokens` or shorten the knowledge base. |
| 429 / 5xx / network / timeout | Retried with `retry-after` or exponential backoff, `anthropic.max_retries` times; then the object is skipped and the run continues. |
| 200 with `stop_reason: refusal` | The object is skipped, the category is logged. Usage is still counted. |
| 200 with `stop_reason: max_tokens` | The object is skipped; raise `anthropic.max_tokens` or enrich fewer fields per class. |
| 200 with a non-JSON body | The object is skipped, logged with the request id. |

Logging uses the default Pimcore logger (Monolog, `var/log/<env>.log`), every line prefixed
`Gatekeeper AI:`. `-vvv` logs the full request payload at debug level. **The API key is never
logged or printed.**

## Prompt caching

Every request of one (class, language) group shares the same three system blocks — the fixed
instructions, the knowledge base with the class instructions, the field descriptions — and only
the user message (the object) differs. The cache breakpoint sits on the last system block, so
the second object of a group reads the whole prefix from cache at a tenth of the input price.
The `prefix_hash` stored with every proposal is the sha256 of exactly those bytes: two proposals
with the same hash and no cache reads mean the cache expired between them, not that the prompt
changed. `cache_ttl: 1h` keeps it warm through longer pauses at twice the write price.

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `validate` says "No API key" | `ANTHROPIC_API_KEY` is not visible to the process (check `.env.local`, the container environment, `bin/console debug:container --env-var ANTHROPIC_API_KEY`). |
| `authentication_error (HTTP 401)` | Key invalid or revoked. New key in the Anthropic console. |
| `not_found_error (HTTP 404)` | `anthropic.model` names a model the account cannot use. |
| "field ... is not required by any profile" warning | The Gatekeeper never reports it missing, so it is never proposed. Add it to the rule's `required` list or drop it from `enrich`. |
| Plan is empty ("Nothing to propose") | No failing rows for the filters: run `tsf:gatekeeper:recalculate`, check `tsf:gatekeeper:report`. |
| Cache reads stay 0 on every request | The prefix changes between requests. Compare `prefix_hash` of two rows; `-vvv` logs the payload. Below the model's cache floor nothing is cached at all (`context` shows the floor). |
| Rows come back `invalid` | The validator rejected the value: too long, not an allowed option, empty. `review -s invalid` shows the reason; tighten the field's tooltip or the knowledge base. |
| Rows go `stale` on apply | The field was filled or the object's input edited after the proposal. Re-run `propose` (the rows are replaced). |
| Apply exits 1 with `blocked_by_gate` | Pimcore refused the save for another reason (mandatory field, unique key). The message is on the row. |
| `max_objects_per_run reached` | Expected batching stop. Run again; objects with proposals are skipped, the rest proceeds. |

## Testing

```bash
composer install
vendor/bin/codecept run Unit
PIMCORE_TEST_DB_DSN=mysql://root:root@127.0.0.1:3306/tsf_gatekeeper_ai_test vendor/bin/codecept run Functional
```

The functional suite boots its own Pimcore kernel (`tests/Support/App`) with both bundles against
a database it drops and recreates. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT, see [LICENSE](LICENSE).
