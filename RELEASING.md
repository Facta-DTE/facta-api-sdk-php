# Releasing facta-dte/api

Packagist reads Git tags, so `composer.json` has no `version` field. Never add one.

## One time: publish on Packagist

1. Log in to https://packagist.org with the GitHub account that owns access to the
   Facta-DTE organization.
2. Submit `https://github.com/Facta-DTE/facta-api-sdk-php` at
   https://packagist.org/packages/submit. The package name is `facta-dte/api`.
3. Keep Packagist updated automatically: use its GitHub integration (the Packagist GitHub App),
   or add the Packagist webhook (Settings, Webhooks) with the API token from your profile.

## Each release

1. On a pull request to `main`, set `Facta::VERSION` in `src/Facta.php`, move the
   `## [Unreleased]` notes in `CHANGELOG.md` under `## [X.Y.Z] - date` (keep an empty
   `## [Unreleased]` above), and update the status line in `README.md`.
2. Merge the pull request once CI is green.
3. Tag the merge commit on `main`:

       git checkout main && git pull
       git tag -a vX.Y.Z -m "facta-dte/api X.Y.Z"
       git push origin vX.Y.Z

4. The `Release` workflow checks that the tag equals `Facta::VERSION` and that the commit is
   on `main`, runs the tests, builds `dist/facta-api-php-vX.Y.Z.zip`, smoke-tests it and
   creates the GitHub Release with the ZIP and the changelog section.
5. Packagist picks up the tag. Check https://packagist.org/packages/facta-dte/api.

Release candidates use a hyphenated tag (`vX.Y.Z-rc.N`, with `Facta::VERSION` and the CHANGELOG heading to match); the workflow marks the GitHub Release as a pre-release and Composer treats it as unstable.

Never move or delete a published tag. A mistake ships as the next patch version.
