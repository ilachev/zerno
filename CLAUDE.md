# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@docs/design/protoc-php-gen-guidelines.md

NOTE: All communication with users must be in Russian only. Comments in code must always be in English.

IMPORTANT: Never create hacks or workarounds for narrow scenarios. Always focus on proper solutions that address the root cause of issues. Avoid creating ad-hoc scripts, adding conditional code, or other approaches that don't solve the actual problem. Instead, diagnose issues thoroughly by examining logs, traces, and existing code to understand the underlying issue.

## Commands
- Dependencies and tools are bundled; no installation step or package manager is required.
- Command help: `php tools/project.php help`
- Lint: `php tools/project.php lint` (dry-run) or `php tools/project.php fixcs` (fix issues)
- Static analysis: `php tools/project.php phpstan`
- Run all tests: `php tools/project.php test`
- Run single test: `php tools/project.php test "tests/Unit/Path/To/TestFile.php"` or specific method with `--filter=methodName`
- Verify (lint+tests): `php tools/project.php verify`
- Performance tests: `php tools/project.php perf:test "scenario-name.js"`
- Always run `php tools/project.php verify` before committing to ensure code quality
- Generate proto artifacts: `php tools/project.php proto:gen:all`
- Start services: `php tools/project.php services:start`
- Stop services: `php tools/project.php services:stop`

## Development workflow
- Use `php tools/project.php` for build, generation and checks. Task aliases are optional conveniences only.
- Do not add package download or installation steps to runtime, builds or verification.
- Always run `php tools/project.php verify` after completing a task, not just tests
- `php tools/project.php verify` runs static analysis, code style checks, and tests in one command
- Address any issues found during verification before committing
- When working with the database, use `php tools/project.php services:start` to start PostgreSQL
- After making changes to database-related code, always test with the actual PostgreSQL database

## Architecture
- Clean Architecture approach with strict separation of concerns
- Three primary layers:
  - **Domain**: Pure business logic and domain models
  - **Application**: Use cases, mappers, and coordination between layers
  - **Infrastructure**: Framework and external systems integration
- Business logic should reside in the Domain layer, never in Infrastructure
- Application layer coordinates between Domain and Infrastructure
- Handlers should be thin adapters delegating to Domain services
- Use Mappers (not Builders or DTOs) for transforming between layers
- Domain objects should never depend on infrastructure or protocol-specific types
- Domain model objects (DTOs) should be as simple as possible - avoid adding business logic methods to them
- Data structures in the Domain layer should be pure containers, with behavior residing in services
- Infrastructure components (like Hydrator) should be used by mappers to transform data
- Each layer should have its own tests focused on its responsibilities
- Do not use mocks for internal services - use real implementations in tests
- Always consider creating reusable components instead of narrow-focused solutions
- Before implementing specific functionality, think about designing more general abstractions that can be reused

## Database Strategy
- Use PostgreSQL for all environments (development, testing, production)
- Consistent database schemas ensure reliable behavior across environments
- Testing should use the same database engine as production
- Database-specific features should be designed for PostgreSQL compatibility
- When optimizing performance, use real-world PostgreSQL performance characteristics
- Avoid SQLite for testing as it may hide PostgreSQL-specific issues or constraints
- Database connections should be properly configured for each environment
- Integration tests should reset the database to a known state before each test
- Use database transactions for test isolation when possible
- Migrations should be designed for PostgreSQL compatibility
- Use JSONB type for complex data structures to leverage PostgreSQL's powerful JSON capabilities
- Use BIGSERIAL for auto-incrementing primary keys
- Always use BIGINT (not INTEGER) for timestamp fields to handle PHP_INT_MAX values
- Follow PostgreSQL best practices for indexing, especially for JSON fields
- Always pass null for ID values when inserting new records with auto-incrementing primary keys
- Use TEXT type for string fields instead of VARCHAR for flexibility
- Handle null values appropriately in PostgreSQL, which has stricter constraints than SQLite

## Performance and Memory Management
- Application runs on RoadRunner server - be careful with static caches and memory management
- Avoid using static arrays that grow indefinitely, use bounded caches or reset mechanisms
- Be careful with circular references that prevent garbage collection
- All services should be stateless when possible
- Reset any accumulated state between requests where needed
- Consider memory impacts of caching in long-running processes
- Prefer limited, time-bound, or LRU caches over unbounded storage
- Test memory usage with load tests before production deployment
- Never ignore static analysis warnings - fix underlying issues in code instead
- Use correct types to prevent type-related static analysis warnings
- Avoid relying on PHPDoc types for runtime conditions (use instanceof checks instead)
- Never modify the PHPStan configuration - always fix the actual code issues
- If you consider modifying the PHPStan configuration, stop and re-read CLAUDE.md

## Code Style
- PHP 8.5, strict typing required (`declare(strict_types=1)`)
- PSR-4 autoloading: `App\` namespace for src/ and generated proto files
- @PER-CS2.0 and PhpCsFixer ruleset with custom modifications
- PHPStan level 10 for static analysis
- Classes should be final when possible
- One-space concatenation (`$a . $b`)
- Import ordering: classes, functions, constants
- No global namespace imports
- No "Interface" suffix in interface names (use `Repository` not `RepositoryInterface`)
- Trailing commas in multiline arrays, arguments and parameters
- Comments only when necessary and ALWAYS in English (not Russian)
- Comments should explain "why", not "what" or "how" when the code is complex
- All code comments must be in English, even in small debugging/temporary sections
- Use `self::assert*` instead of `$this->assert*` in PHPUnit tests
- Commit messages should be short, concise, and written in English
- Each commit message should be a single sentence that conveys the essence of the change
- Do not include Claude mentions in commit messages
- Always prefer single-line commit messages

## CI/CD
- GitHub Actions are configured for CI/CD
- CI workflow runs on every push/PR to master branch:
  - Code style checking
  - Static analysis
  - Tests
  - Proto artifacts generation
- Release workflow creates distributable package when a new release is created
- Never disable checks or bypass CI workflows
- Fix CI issues locally before pushing to remote