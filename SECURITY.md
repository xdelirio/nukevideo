# Security Policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem. Report it privately through GitHub:
**Security → Report a vulnerability** on this repository
([direct link](https://github.com/chikenare/nukevideo/security/advisories/new)).

Include what you can of:

- the affected component (API, admin panel, worker or proxy node, edge nginx, deploy scripts);
- the version or commit you tested;
- steps to reproduce, or a proof of concept;
- the impact as you understand it (what an attacker gains, and what access they need first).

You should get an acknowledgement within a few days. Once a fix is released, the advisory is
published with credit to you, unless you prefer otherwise.

## Supported versions

NukeVideo is under active development and only the **latest release** receives security fixes.
Pin a release tag in production and upgrade when an advisory is published.

## Scope

In scope: anything in this repository and the images built from it (`chikenare/nukevideo-api`,
`chikenare/nukevideo-proxy`). Examples of what matters most here:

- crossing tenant boundaries (one project or account reading or changing another's videos,
  analytics or keys);
- a project API key reaching account or admin endpoints;
- playing or downloading a video without a valid edge token;
- running commands on the API host or on a node through the panel, the API or a deploy.

Out of scope: vulnerabilities in third-party services and dependencies themselves (report those
upstream), denial of service by sheer volume, and findings that need an already-compromised admin
account or host.
