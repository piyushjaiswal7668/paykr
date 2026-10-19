# Repository-owned CI

`.github/workflows/checks.yml` uses only our local composite action,
`.github/actions/setup-runtime/action.yml`. It does not invoke external GitHub Actions.

The workflow checks out the exact GitHub event SHA using ordinary Git commands over HTTPS.
For a pull request this is GitHub's test merge commit, so the proposed changes are tested
against the base branch. No checkout token or persisted Git credentials are needed for this
public repository. Private repositories would need a separately reviewed authentication step.

The setup action configures PHP 8.3 and PCOV and verifies the required extensions, Node 22,
Composer and Go supplied by the GitHub-hosted Ubuntu 24.04 image. It fails if requirements
are absent; it does not download or execute another maintainer's setup script. The runner
label is explicit so an ubuntu-latest OS migration cannot silently change the setup.
The underlying hosted image and runtime patch versions remain maintained by their upstreams.

MySQL and Redis service containers, Composer/npm packages and Playwright remain upstream
dependencies. Owning the workflow does not mean owning these tools or removing their licenses.
See THIRD_PARTY_NOTICES.md. This CI change does not alter the application payment flow.

The existing PHP, MySQL concurrency, coverage, formatting, static analysis, sidecar, SDK,
webhook-verifier and browser checks remain required. Workflow permissions are read-only,
PRs run without production credentials, and the main-branch merge rules remain in force.
Do not introduce pull_request_target execution of untrusted PR code.

When changing the setup action, validate it with a real pull-request CI run on Ubuntu 24.04.
Local macOS cannot validate Ubuntu's PHP module configuration. Review runner-image changes
if the runtime checks fail; do not silently remove coverage or skip a required language.
