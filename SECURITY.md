# Security Policy

We take the security of Atelier seriously. Atelier is self-hosted software that runs
people's websites, so a vulnerability here can affect real sites — thank you for helping
us keep them safe.

## Reporting a vulnerability

**Please do not report security issues in public GitHub issues, pull requests, or
discussions.** Public disclosure before a fix is available puts every running install at
risk.

Instead, report privately through **GitHub's private vulnerability reporting**:

1. Go to the **[Security tab](https://github.com/aincient-labs/atelier-cms/security)** of
   this repository.
2. Click **Report a vulnerability**.
3. Fill in the advisory form.

This opens a private channel visible only to you and the maintainers, where we can discuss,
fix, and coordinate disclosure. It's the fastest way to reach us about a security issue.

A good report includes:

- The affected component and version (image tag, or `atelier --version` for the CLI).
- A clear description of the issue and its impact.
- Steps to reproduce, ideally with a minimal proof of concept.
- Any known mitigations or workarounds.

## What to expect

- **Acknowledgement** within **3 business days** that we've received your report.
- An **initial assessment** (severity, whether we can reproduce it, and a rough timeline)
  within **7 business days**.
- Regular updates as we work on a fix.
- **Credit** in the advisory and release notes when the fix ships, unless you'd prefer to
  stay anonymous.

We follow **coordinated disclosure**: we'll agree a disclosure date with you, publish a
GitHub Security Advisory when the fix is available, and ask that details stay private until
then.

## Supported versions

Atelier is distributed as a **rolling container appliance**. The image is the unit of
versioning, and every install **self-converges to the current release on upgrade**
(`atelier app update`, or re-running the installer / `docker compose pull`).

Because of this model, **security fixes ship in the next image release**, and only the
**latest published release** is supported. If you're running an older image, upgrading is
the way to receive a security fix — there are no backported patches for prior tags.

| Component | Supported |
| --- | --- |
| `ghcr.io/aincient-labs/atelier-cms` — latest release / `:latest` | ✅ |
| Older image tags | ⚠️ upgrade to receive fixes |
| The `atelier` CLI ([manager](https://github.com/aincient-labs/manager)) — latest release | ✅ |

## Verifying what you run

Every published image is **signed with [cosign](https://docs.sigstore.dev/)**. The public
key ships in this repo as [`cosign.pub`](cosign.pub):

```bash
cosign verify --key cosign.pub ghcr.io/aincient-labs/atelier-cms:latest
```

## Scope

This repository is the **public source home** of Atelier — a history-free snapshot published
by our deployment pipeline. Security reports about **any part of the shipped product** (the
container image, the operator console, the custom modules and themes, the appliance
convergence flow) are in scope and belong here.

Atelier is built on **[Drupal](https://www.drupal.org)** and bundles third-party
dependencies. If a vulnerability is in an upstream project rather than Atelier's own code,
we'll help coordinate with the relevant maintainers — the [Drupal Security
Team](https://www.drupal.org/drupal-security-team) for Drupal core and contributed modules,
or the project's own channel — but reporting it there directly is often faster.

Thank you for helping keep Atelier and the sites it runs safe.
