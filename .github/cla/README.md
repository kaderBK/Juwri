# CLA bot — configuration (staged, NOT enabled)

This directory holds a ready-to-use config for the CLA bot. Nothing here is active yet:
no workflow runs and no signature is collected until a maintainer enables it.

## Owner steps to enable
1. Copy `cla-workflow.yml.disabled` to `.github/workflows/cla.yml` and commit it.
2. Create the repository secret `CLA_BOT_TOKEN` (token with repo/workflow scope).
3. In branch protection for `main`, require the CLA check before merging.

## Signatures and companies
Individuals accept `CLA-INDIVIDUAL.md` through the bot on their first pull request.
Companies use the Corporate CLA `CLA-CORPORATE.md`; maintainers record accepted
companies in `signedContributors` inside `signatures.json`.
See `cla.json` in this directory for the bot settings.