# Proto PHP Endpoint Generator

`protoc-php-gen` is the internal protoc plugin used by this repository to generate and validate PHP endpoint artifacts from protobuf `service/rpc` definitions.

The current production-grade scope is intentionally narrow: the main project path supports `endpoints`, `endpoint_validation`, and `operation_manifest`.

## Current role

The supported project path is endpoint-oriented:

- parse protobuf descriptors;
- generate endpoint interfaces;
- generate HTTP handlers for the runtime adapter;
- validate handwritten endpoint implementations against generated expectations;
- generate operation registry classes for each protobuf RPC.

It is not the canonical path for domain-to-proto mapper generation.

## Product direction

This tool should be treated as a modular PHP code generation platform around protobuf descriptors, not as a one-off script and not as a grab bag of unrelated generators.

Today, `endpoints`, `endpoint_validation`, and `operation_manifest` are stable and supported.

Future generators are allowed only if they:

- solve a reusable backend problem;
- have an explicit generation flag;
- keep a separate output contract;
- have dedicated tests;
- do not leak business or persistence policy into the codegen layer.

## Technical roadmap

The internal stabilization baseline is already in place:

1. modular plugin core (`PluginOptions`, `CodeGeneratorModule`, `GeneratorRegistry`);
2. typed descriptor model instead of raw array-driven flow;
3. dedicated type resolver for fully-qualified protobuf types;
4. runtime profile abstraction for transport generation;
5. stronger end-to-end coverage for the plugin protocol.

The next stage is not “more generators at any cost”, but a cleaner protobuf-first flow in the main template:

1. generated operation registry classes as the canonical endpoint metadata surface;
2. less manual runtime glue between generated endpoints and handwritten endpoints;
3. stricter consistency checks for generated artifacts.

The detailed product-level rationale is documented in:

- `docs/design/protoc-php-gen-product.md`

## Usage

Dependencies are bundled in `third_party/codegen/protobuf/packages` and loaded through this tool's `bootstrap.php`. No package installation is required. `third_party/codegen/protobuf/manifest.json` records the package versions, source refs, licenses and file hashes.

Run the local quality gate from the project root:

```bash
php tools/project.php tools:proto:check
```

The protobuf and SQL generators run in separate PHP processes because their dependency versions are pinned independently. Do not load both generator bootstraps in one process.

Generating protobuf artifacts still requires a local `protoc` executable. OpenAPI generation additionally requires `protoc-gen-openapiv2`; the PHP plugin does not install either executable.

```bash
protoc -I=./protos/proto \
  --plugin=protoc-gen-php-endpoint=./tools/protoc-php-gen/bin/protoc-php-gen.php \
  --php-endpoint_out=namespace=App\\Generated\\Endpoint,output_dir=gen,source_root=src,bootstrap=codegen/protoc-php-gen-bootstrap.php,endpoint_profile_class=ProjectCodegen\\Protobuf\\BaseApiTemplateEndpointProfile,generate_endpoints=true,generate_endpoint_validation=true,generate_operation_manifest=true:. \
  ./protos/proto/app/v1/*.proto
```

## Parameters used by the main project

- `namespace` - Base namespace for generated endpoint classes
- `output_dir` - Output directory for generated files
- `source_root` - Root directory for handwritten endpoint implementations
- `bootstrap` - Optional PHP bootstrap file for loading project-specific profile classes
- `endpoint_profile_class` - Fully-qualified endpoint profile class used by the project
- `generate_endpoints` - Enable endpoint generation
- `generate_endpoint_validation` - Fail generation when handwritten endpoint implementations are missing or declared incorrectly
- `generate_operation_manifest` - Enable operation registry generation

## Output

The main project expects generated files in:

- `gen/Generated/Endpoint/...`
- `gen/Generated/OperationManifest/...` - generated operation registry classes

These files are used together with handwritten endpoint implementations in:

- `src/Platform/Http/Endpoint/...`
