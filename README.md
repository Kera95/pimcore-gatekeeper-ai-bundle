# Pimcore Gatekeeper AI Bundle

AI enrichment for Pimcore DataObjects, driven by the
[Gatekeeper completeness bundle](https://github.com/Kera95/pimcore-gatekeeper-bundle): proposes
values for the fields your gate reports missing, per language, from a Markdown knowledge base in
the asset tree. Proposals are stored in a table, reviewed and applied by console command, never
written blind. Anthropic Claude, prompt caching, cost ceiling, MIT.

> **Status: in development.** The skeleton, the proposal table, the knowledge base and the
> `validate` / `context` commands are in place; the provider and the propose / review / apply
> commands follow. Nothing leaves the system yet.

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
the API key is set, the model has a price and the knowledge base folder exists. Exit code 1 when
anything blocks a run.

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
