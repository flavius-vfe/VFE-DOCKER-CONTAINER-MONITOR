# Community Applications publication checklist

## Included in this repository package

- Public-repository file layout
- Installable PLG
- GitHub update URL for the `main` branch
- MIT License
- CA XML template
- `ca_profile.xml` with a non-empty original-author profile
- 256×256 PNG CA icon
- Source and standalone TXZ package
- Automatic Plugin Manager removal block
- SHA-256 checksums
- Minimum Unraid version 7.3.0

## Required before CA submission

1. Create the public repository:
   `https://github.com/flavius-vfe/VFE-docker-container-monitor`
2. Upload the contents of this ZIP to the repository root on branch `main`.
3. Enable GitHub Issues.
4. Create an Unraid forum support thread.
5. Replace the `<Support>` URL in `vfe.docker.container.monitor.xml` and the `supportURL` entity in the PLG with that forum thread URL.
6. Install using the raw GitHub PLG URL on a real Unraid 7.3.0 system.
7. Verify installation, settings, monitoring, manual tests, automatic actions, update detection, and Plugin Manager removal.
8. Submit the public repository through the current Community Applications intake form.

## Responsibility note

Community Applications may require an active person to receive support requests. The profile identifies VFE Flavius only as the original author and describes the project as community-supported. Approval is controlled by the Community Applications reviewers.
