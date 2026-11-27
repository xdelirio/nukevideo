# Third-party nginx modules

The delivery edge (`nukevideo-proxy`) compiles these three modules into its nginx. They live here,
unmodified, because their original repositories are gone: Kaltura deleted them from GitHub in
November 2026, and no maintained upstream remains. The edge needs all three:

| Module | What the edge uses it for |
|---|---|
| `nginx-aws-auth-module` | Signs (SigV4) the edge's requests to S3, so the bucket stays private (`aws_auth`). |
| `nginx-akamai-token-validate-module` | Validates the link token the API mints: HMAC, expiry, ACL and, when the panel binds links to the viewer, the IP (`akamai_token_validate`). |
| `nginx-secure-token-module` | Rewrites manifests so every segment URL carries the link's token (`secure_token`). |

## Provenance

Each directory is the module's tree at the commit below, minus its Travis CI files (`.travis.yml`,
`travis_build.sh`), and with the changes listed under [Modifications](#modifications). The ids
were checked against the Software Heritage archive of the original Kaltura repositories
(`archive.softwareheritage.org/browse/origin/?origin_url=https://github.com/kaltura/<name>`):

| Module | Commit | What it is |
|---|---|---|
| `nginx-aws-auth-module` | `dbbac974f0699328a63f497cd911a4f991faed9b` | the tag 1.1 |
| `nginx-akamai-token-validate-module` | `818d94a712e6bbd32d9d086d2c96ccd98f79e438` | master, untagged: the `ip` check (kaltura PR #22, 2021) only exists after 1.1 |
| `nginx-secure-token-module` | `18b97a82878e09fef24fe4f6a446eed01dbe28d7` | the tag 1.5 |

They were previously fetched from forks at the same commits (`github.com/xdelirio/<name>`), which
the Dockerfiles of releases up to v2.0.39 still download.

To check a tree against its commit, download that commit's archive from Software Heritage or the
fork, drop the two CI files, and `diff -r` it with the directory here: the only differences are the
modifications below.

## Modifications

Every change is marked in the source with a `NukeVideo:` comment.

| Date | Module | Change |
|---|---|---|
| 2026-11-27 | `nginx-akamai-token-validate-module` | The token's hmac is compared with `CRYPTO_memcmp` (constant time) instead of `ngx_memcmp`, which returned as soon as a character differed and so let response timing reveal how much of a forged hmac was right. |

## License

All three modules are licensed under the **GNU Affero General Public License v3.0**; each directory
keeps its `LICENSE`. They are not covered by this repository's MIT license, and the
`nukevideo-proxy` image, which links them into nginx, is distributed under the AGPL as a whole. Its
complete corresponding source is this repository at the release's tag.

A change to these files is a modification of AGPL code: say what changed and when in this README,
and keep the license notices.
