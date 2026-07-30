# Security Policy

## Reporting a vulnerability

Use the private repository's GitHub Security Advisory feature or contact the repository owner, Flavius. Do not publish credentials, backup contents, private configuration, or vulnerability details in a public issue.

## Secrets and backup contents

The repository and release artifacts do not contain API keys, access tokens, private keys, `.env` files, passwords, hard-coded credentials, or remote execution scripts.

Backups created by the plugin can contain application configuration, Docker metadata, XML templates, Compose files, environment files, and other data selected by the administrator. Those backup sets may contain secrets originating from the administrator's containers. Protect the destination with appropriate filesystem permissions, physical security, encryption, and access controls.

## Privilege model

Unraid plugins execute with root privileges. Install only release artifacts that match the published SHA-256 checksums. Per-container hook scripts also execute as root and should be reviewed before use.
