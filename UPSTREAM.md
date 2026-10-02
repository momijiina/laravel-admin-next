# Upstream

Original project: [z-song/laravel-admin](https://github.com/z-song/laravel-admin).

This project began as an import of laravel-admin and is maintained independently
at [momijiina/laravel-admin-next](https://github.com/momijiina/laravel-admin-next).

## Provenance

- Import commit: `f83accfeb615b2be280b19e62f4b4bd29f786ac7`
- Initial policy commit: `819837af94e1a4170a13ac7dc85dbe40bd6e8d9b`
- The exact original upstream commit/tag is not recorded in the import history
  examined during the audit; do not infer one from a bundled version string
- Preserve the existing MIT license and applicable bundled-asset licenses

## Referencing upstream work

Upstream may be referenced for fixes and historical behavior. For a ported change,
record the source issue/PR/commit, assess local compatibility and licensing, and
add a regression test. Review changes individually rather than synchronizing
unreviewed upstream history automatically.

## Package identity and documentation

The imported Composer identity remains `encore/laravel-admin`, with the
`Encore\Admin` namespace. This initial maintenance work does not rename or
publish the package.

The inherited README installation command, documentation links, badges, funding
links, and changelog may still refer to upstream. In particular,
`composer require encore/laravel-admin` does not establish that this fork is being
installed. A fork-specific distribution/VCS installation and release policy must
be decided and verified before publishing new installation instructions.

See [COMPATIBILITY.md](COMPATIBILITY.md) and [ROADMAP.md](ROADMAP.md) for the
independent verification and maintenance plan.
