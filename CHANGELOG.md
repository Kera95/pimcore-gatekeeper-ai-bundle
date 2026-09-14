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
- `tsf:gatekeeper:ai:validate`: checks the API key is set, the model has a price, the knowledge
  base folder exists and has files, and every enriched class has a Gatekeeper rule whose fields exist, may be
  proposed and are actually required.
