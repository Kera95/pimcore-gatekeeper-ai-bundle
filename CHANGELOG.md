# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Bundle skeleton: `TsfGatekeeperAiBundle`, configuration tree `tsf_gatekeeper_ai` (provider,
  Anthropic settings, pricing, knowledge base folder, run limits, field deny list, per-class
  `enrich` / `languages` / `instructions`), installer with the `tsf_gatekeeper_ai_proposal` table.
- `ProposalStore` and `Model\Proposal` / `Model\ProposalStatus`: one row per (object, field,
  language); a re-run replaces `pending`, `invalid` and `stale` rows and never touches
  `approved`, `rejected`, `applied` or `blocked_by_gate` ones.
- `FieldPolicy`: only `input`, `textarea`, `wysiwyg`, `select` and `multiselect` fields can be
  proposed, never a field on the deny list.
- `KnowledgeBase`: assembles the Markdown assets of `context.asset_folder` (root and `_global/`
  always, `<ClassName>/` per class) in path order under `## <path>` headings into one text with a
  sha256 hash; `TokenEstimator` (chars / 4) and the per-model prompt-cache floors.
- `tsf:gatekeeper:ai:context [--class] [--summary]`: prints the assembled knowledge base, its
  files, characters, estimated tokens and hash, warns below the cache floor, fails above
  `context.max_tokens`.
- Field layer, pure and unit-tested: `Model\FieldSpec` (the constraining parts of a Pimcore
  definition: type, title, tooltip, localized, max length, options, max items),
  `FieldSpecFactory` (class + path → spec via the core `FieldReader`), `FieldDescriber` (the
  byte-stable prose block "Fields to produce (language: x)" for the prompt), `SchemaBuilder`
  (closed JSON schema for structured output: strings, enums, arrays of enums — no length
  constraints, the API rejects them) and `ProposalValidator` (trims, flattens inputs, strips
  tags outside `<p> <ul> <ol> <li> <strong> <em> <br>` from wysiwyg and all tags from plain
  text, checks length, select values by value or label, multiselect lists stored as JSON).
- `FieldPolicy` also refuses select / multiselect fields without static options.
- Provider layer: `EnrichmentProviderInterface` (`generate`, `countTokens`), `Model\EnrichmentRequest`
  (prefix blocks + user message + schema, `getPrefixHash()`), `Model\EnrichmentResponse` (values,
  usage, stop reason, refusal category, request id), `Model\Usage` (input, output, cache read,
  cache write), `Pricing` (USD from the four token kinds at the configured rates).
- `AnthropicProvider` over `symfony/http-client`: `system` blocks with one `cache_control`
  breakpoint on the last block (`5m` or `1h`), `output_config.effort` and
  `output_config.format: json_schema`, usage read back, `request-id` kept, retries with
  `retry-after` or exponential backoff for 429 / 5xx / network errors, refusal and `max_tokens`
  returned as unusable responses, everything else as a typed `ProviderException` (vendor type,
  HTTP status, request id, retryable, fatal, and the thing to check - key, model, billing,
  permissions, size). The API key is never logged.
- `FakeProvider` (`provider: fake`): deterministic stand-in values from the schema, no network,
  queueable responses for tests.
- `PromptBuilder`: the cache-friendly layout - fixed instructions (`PROMPT_VERSION`), knowledge
  base + class instructions, field descriptions per language - and the per-object user message.
- `tsf:gatekeeper:ai:validate --live`: sends a request shaped like the first one of a run to the
  token counting endpoint (free, generates nothing) and reports key / model / exact prefix size /
  whether the prefix is above the cache floor, or the mapped API error.
- `tsf:gatekeeper:ai:review [-c] [-l] [-o object] [-s status|all] [-f table|csv|md] [--limit] [--full]`:
  lists proposals; CSV and Markdown for spreadsheets and tickets.
- `tsf:gatekeeper:ai:approve` / `tsf:gatekeeper:ai:reject` `--id 1,2,3` or `--all` with the
  filters: approve moves pending rows on, reject takes pending and approved rows out; nothing
  else is touched.
- `tsf:gatekeeper:ai:apply [-c] [-l] [-o] [--id] [--limit] [--dry-run]`: writes approved rows
  into their objects, all fields of one object in one save through the generated setters (so
  versioning applies). A row is marked `stale` when its field is no longer empty or the object's
  input for that language changed since the proposal; a save Pimcore refuses marks the rows
  `blocked_by_gate`. The save skips the Gatekeeper's warn/block step (the object gets more
  complete) but is still scored; the score per profile and language before and after is printed.
  The publish state is never changed.
- `source_hash` is now the hash of the object's non-localized fields plus the localized fields of
  the proposal's language, so applying one language does not make another language's proposals
  stale; a person editing what the model saw does.
- `FieldPolicy` refuses paths inside object bricks and field collections.
- `tsf:gatekeeper:ai:propose [-c Class] [-p gate-profile] [-l language] [-f fields] [--limit N]
  [--estimate] [--dry-run] [--force]`: reads the Gatekeeper's failing rows, plans one group per
  (class, language) with only the `enrich` fields the policy allows, one request per object
  with the object's filled fields as context and a schema of exactly the missing fields, and
  stores every answer as a `pending` or `invalid` proposal. Skips objects that already have
  proposals for the same input / knowledge base / prompt version, stops at
  `max_objects_per_run` and `max_cost_per_run` and on fatal API errors, skips objects on
  refusals, truncation and retryable failures. `--estimate` prices the plan without sending,
  `--dry-run` runs it on the fake provider without storing. Run summary with tokens
  (in / cache read / cache write / out) and cost, also in the log.
- `ObjectSnapshot`: the object as the model sees it - filled top-level and localized fields with
  a textual reading, relations by key, capped per value, the score field left out.
- A 400 "prompt is too long" is no longer fatal for the run; the object is skipped.
- `tsf:gatekeeper:ai:validate`: checks the API key is set, the model has a price, the knowledge
  base folder exists and has files, and every enriched class has a Gatekeeper rule whose fields exist, may be
  proposed and are actually required.
