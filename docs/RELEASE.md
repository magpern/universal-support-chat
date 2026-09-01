# Release process — Universal Support Chat

## Canonical version source

The plugin version is declared in **`universal-support-chat.php`** in two
places that must always agree:

- the `Version:` plugin header, and
- the `UNIVERSAL_SUPPORT_CHAT_VERSION` constant.

There is no `readme.txt` or `CHANGELOG.md` in this repository, so those two are
the whole contract. `scripts/build-release-package.sh` refuses to build if they
disagree, and `.github/workflows/release.yml` additionally refuses to publish
unless both equal the pushed Git tag (with the leading `v` removed).

## Package identity

| Item | Value |
|---|---|
| Deployable directory | `universal-support-chat/` (sole top-level entry in the ZIP) |
| ZIP | `dist/universal-support-chat-<version>.zip` |
| Checksum | `dist/universal-support-chat-<version>.zip.sha256` (`sha256sum` format) |

### Included in the package

`universal-support-chat.php`, `uninstall.php`, `src/`, `assets/`,
`composer.json`, `README.md`, `LICENSE`, and a freshly generated production
`vendor/` (`composer install --no-dev --classmap-authoritative`). This plugin
has no third-party runtime dependencies; `vendor/` is only the Composer
autoloader.

### Excluded from the package

`.git/`, `.github/`, `.claude/`, `bin/`, `docker/`, `docs/`, `tests/`,
`composer.lock`, `phpcs.xml.dist`, `phpstan.neon.dist`,
`phpstan-bootstrap.php`, `phpunit*.xml.dist`, `.phpunit.result.cache`,
`.gitignore`, and any previous build output. The packaging script fails if any
of these appear in the ZIP.

## Build and validate locally

Everything runs in the repo's Docker toolchain — no host PHP/Composer:

```bash
bin/docker/build-release-package.sh            # version from the plugin file
bin/docker/build-release-package.sh 0.8.0      # must match the plugin file

# validate
cd dist
sha256sum -c universal-support-chat-<version>.zip.sha256
unzip -l universal-support-chat-<version>.zip
```

`scripts/build-release-package.sh` is the actual builder and can be run
directly anywhere `php`, `composer`, `zip`, `unzip` and `rsync` are on `PATH`.

## Cutting a release

1. Bump `Version:` **and** `UNIVERSAL_SUPPORT_CHAT_VERSION` in
   `universal-support-chat.php` in the same commit. Do **not** change version
   files inside the release workflow.
2. Merge to **`main`** (the only branch releases are cut from) and wait for the
   full CI matrix to go green on that commit.
3. Create and push an annotated tag matching `v[0-9]+.[0-9]+.[0-9]+`
   (a `-rc.N`/`-beta.N` suffix marks the GitHub Release as a prerelease):
   ```bash
   git tag -a v0.8.0 -m "Universal Support Chat 0.8.0"
   git push origin v0.8.0
   ```
4. `release.yml` fires on the tag: it re-runs PHPCS, PHPStan and the unit
   suite, builds the ZIP with `bin/docker/build-release-package.sh`, verifies
   the packaged version equals the tag, generates the SHA-256 checksum, and
   creates the GitHub Release with the ZIP and `.zip.sha256` attached.
5. The ZIP and checksum appear as assets on the GitHub Release page
   (`https://github.com/magpern/universal-support-chat/releases/tag/v<version>`).

## Using the artifact for deployment

The ZIP is a normal WordPress plugin archive: `wp plugin install <zip> --activate`
or upload it via **Plugins → Add New → Upload Plugin**. Before deploying,
download both assets and verify:

```bash
sha256sum -c universal-support-chat-<version>.zip.sha256
```

Generated ZIPs and checksums are **CI outputs** — they are `.gitignore`d and
must never be committed.

## Recovering from a failed release

- **Gate/version-check failed** (before "Create GitHub Release"): nothing was
  published. Fix the version declarations on `main`, delete the bad tag
  (`git push --delete origin v<version>` and `git tag -d v<version>`), and
  re-tag the corrected commit.
- **Publish step failed**: delete the partial GitHub Release in the UI, then
  re-run the workflow from the Actions tab or delete and re-push the tag.
- A tag must always point at a commit already on `main`; never release from a
  feature branch or an ad-hoc commit.

## Known limitations

- The release workflow re-runs PHPCS, PHPStan and the unit suite as a fast
  gate; the full integration and interop matrices are relied upon from the
  `main` CI run on the same commit (step 2 above), not re-executed here.
