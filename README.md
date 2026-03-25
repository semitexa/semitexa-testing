# semitexa/testing

Automated payload contract testing with strategy-based validation and multiple test profiles.

## Purpose

Validates payload contracts by executing configurable test strategies against each payload marked with `#[TestablePayload]`. Supports security, HTTP method, type enforcement, and monkey testing strategies at multiple strictness levels.

## Role in Semitexa

Depends on Core. Used by application test suites to verify that payload contracts behave correctly under various conditions without writing individual test cases per endpoint.

## Key Features

- `#[TestablePayload]` attribute with configurable strategies and context
- `PayloadContractTester` orchestrates strategy execution
- Strategies: `SecurityStrategy`, `HttpMethodStrategy`, `TypeEnforcementStrategy`, `MonkeyTestingStrategy`
- Profiles: `StandardProfileStrategy`, `StrictProfileStrategy`, `ParanoidProfileStrategy`
- `InProcessTransport` (direct Application call) and `HttpTransport` (HTTP mode)
- `TestsPayloads` PHPUnit trait for integration
- `PayloadMetadataFactory` extracts test metadata

## Notes

The InProcessTransport calls `Application::handleRequest()` directly, bypassing HTTP overhead. Use HttpTransport for end-to-end testing against a running Swoole server.
