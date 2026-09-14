# Pimcore Gatekeeper AI Bundle

AI enrichment for Pimcore DataObjects, driven by the
[Gatekeeper completeness bundle](https://github.com/Kera95/pimcore-gatekeeper-bundle): proposes
values for the fields your gate reports missing, per language, from a Markdown knowledge base in
the asset tree. Proposals are stored in a table, reviewed and applied by console command, never
written blind. Anthropic Claude, prompt caching, cost ceiling, MIT.

> **Status: in development.** Skeleton, proposal table, knowledge base, field layer, the
> Anthropic provider and the `validate` / `context` commands are in place; the propose / review /
> apply commands follow. Until then only `validate --live` talks to the API (token counting, no
> generation).

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
