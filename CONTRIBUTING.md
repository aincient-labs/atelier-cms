# Contributing to Atelier

**Atelier gets better through the people who run it** — every install and every report
brings us closer to our north star of a million running sites. You don't need to write code
to make a real difference.

## The best ways to help

- 🐛 **Try it and break it.** File bugs in the
  [issue queue](https://github.com/aincient-labs/atelier-cms/issues). A clear reproduction is
  genuinely valuable work.
- 💡 **Tell us what's missing.** Feature requests and shared experiences shape what ships next.
- 🙋 **Ask for help.** If something confused you, it'll confuse the next person too — questions
  are welcome and often turn into docs or fixes.
- 📣 **Spread the word.** Blog about your build, share your exported site, tell someone who's
  fighting with a heavier CMS.

When you open an issue, the templates will prompt you for the details we need (version, steps,
what you expected). The more specific, the faster we can act.

## About this repository (please read before opening a PR)

This repo is the **public source home** of Atelier, but it is **not where day-to-day
development happens.** Each release lands as a single, history-free **snapshot commit**
produced by our deployment pipeline — so the history here tracks *shipped states*, not
individual changes.

Practically, that means **code changes committed directly to this repository are overwritten
on the next release.** A pull request against these files can't be merged in the usual way and
will be lost when the pipeline next publishes.

So if you have a **code contribution or a concrete fix**, the highest-leverage path is to
**open an issue** describing the problem and your proposed change (a patch or diff in the issue
is perfect). We fold accepted changes into the private development source and they ship — with
credit — in the next release. It feels indirect, but it's what keeps the public repo a clean,
verifiable snapshot of exactly what's running.

## Security issues

Please **do not** report vulnerabilities in public issues or PRs. See
[SECURITY.md](SECURITY.md) for private reporting via GitHub's security advisories.

## Code of conduct

Be kind and assume good faith. We want Atelier to be a welcoming place for people who've never
touched Drupal and seasoned developers alike. Harassment or disrespect toward anyone in the
community isn't tolerated.

Thank you for being here — running Atelier and reporting what you find is real participation.
