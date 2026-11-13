# CDN & Delivery

NukeVideo serves its [static CMAF](/guide/streaming) (shared HLS + DASH segments on S3) through proxy nodes you run yourself. They gate access with time-limited tokens and read the packaged output from your S3 bucket; their signing keys and access rules live in the admin panel under **CDN Settings**.

## Proxy Nodes

Proxy nodes are servers you run and manage from the admin panel (over SSH — see [Nodes](/guide/nodes)). Each runs a custom nginx build that:

- Validates Akamai-style stream tokens (HMAC) on incoming requests.
- Reads the pre-packaged CMAF segments from S3 using AWS authentication. It only ever reads: any method but `GET`, `HEAD` and `OPTIONS` gets a `405`.
- Caches segments on the node's own disk pool so repeat requests don't hit S3 every time; **master manifests are asked of the API**, which builds them per link and caches them for 30 seconds (see [Per-link manifests](/guide/streaming#per-link-manifests)); each viewer still gets their own token written into them, and when the API cannot be reached the node serves the packaged manifest from S3 instead. HLS media playlists stay cached on the node for 30 seconds, and **downloads never enter it** (they are whole files, fetched once, and must keep supporting `Range` resumes). See [Cache disks](/guide/nodes#cache-disks).
- Embeds the token a manifest was requested with into the segment URLs it rewrites, rather than minting one per manifest, so every request of a playback session carries the same token — the one the API recorded a caller's tracking id against. The token is logged and hashed on the node; nothing downstream sees it. See [Requesting a Playback URL](/guide/streaming#requesting-a-playback-url).
- Logs, per request, what its cache did and what it fetched from the origin; the nodes page turns that into a hit ratio per node. Origin bytes are recorded under the `origin_bytes` metric and account `0` — the operator's cost, never a customer's usage.
- Answers CORS itself: the bucket's CORS rules play no part in self-hosted delivery, and one cached copy of a segment serves every embedding site. Any site may embed unless you set [allowed domains](#allowed-domains).
- Limits how fast one address can make it fetch unsigned assets from S3: 5 per second, with a burst of 200, and `429` beyond that. Only cache misses count, so a page full of thumbnails already cached is never limited.
- Keeps serving what it has cached while S3 answers with errors or is unreachable.
- Verifies the S3 endpoint's TLS certificate unless told not to with [`VOD_S3_TLS_VERIFY`](/guide/configuration#proxy-nodes).
- Resolves the real client IP behind Cloudflare or another reverse proxy.
- Ships access-log bandwidth to the API through a Vector.dev pipeline.

Token validation is configured in **CDN Settings** (token secret, token name, token window, IP binding). These are injected into the proxy container at deploy time — see [Configuration: Proxy Node Delivery](/guide/configuration#proxy-node-delivery).

### IP binding

By default a playback link plays from anywhere until it expires. Turn on **Bind playback links to
the viewer's IP** and every playback token is signed with the address it was minted for — the `ip`
the request named, or the caller's own — and the edge answers `403` to any request from another
address: the manifest and every segment, since they share the token. The address is signed, so it
cannot be edited out of the URL.

- **Integrations must mint with the viewer's `ip`.** A backend that mints without it binds every
  link to its own server's address, and nothing plays.
- **An address change ends the session.** A phone moving from Wi-Fi to mobile data, or a dual-stack
  viewer who reached the API over IPv4 and the edge over IPv6, gets a `403` and has to request a new
  link. Addresses are compared exactly; IPv6 is not widened to a `/64`.
- **Downloads are not bound**: their tokens carry no address, so a download manager can resume from
  anywhere.
- **It applies to links minted from then on**, with no redeploy. Turning it off does not release
  links minted while it was on: they stay bound until they expire.

### Allowed domains

By default any website can use a valid link. List domains under **Allowed domains** and the edge
answers `403` to a request made from a page on any other site, on playback, downloads and assets
alike. Leave the list empty for no restriction.

- `example.com` matches that exact host, and `*.example.com` its subdomains but not `example.com`
  itself. Ports are ignored.
- **List the site whose page holds the player.** If the player sits in an iframe, list the iframe's
  domain.
- **Redeploy the proxy nodes to apply a change.** The list is written into the edge's nginx config
  when the node deploys.
- **The panel is always allowed**, through the hosts in `SANCTUM_STATEFUL_DOMAINS`, so it keeps
  playing from its own origin.
- **How the edge knows the page.** It reads `Origin`, then `Referer`. A request with neither is
  refused only when `Sec-Fetch-Site` says a cross-site page made it, which covers a page that hides
  its `Referer`.
- **Your own pages must let the browser send a `Referer`.** On an allowed page with
  `Referrer-Policy: no-referrer` or `same-origin`, a plain `<video src>`, an `<img>` or a download
  link is refused like any other, and so is a link with `rel="noreferrer"` on any page. Set
  `strict-origin-when-cross-origin`, the browser default, or `referrerpolicy` on the element.
  Players built on hls.js, dash.js or Shaka are unaffected, since they send `Origin`.
- **Links from other sites are refused too.** A download link clicked on an unlisted site gets a
  `403`, as does a thumbnail in its `<img>`.
- **List every web origin that plays for you.** Hybrid apps count: Capacitor sends
  `capacitor://localhost`, which cannot be listed and is refused. A cast receiver page sends its own
  origin too.
- **A CDN in front answers from its own cache.** With a node proxied through Cloudflare, anything it
  has cached is served without reaching the edge, so the list does not apply to those requests.
- **It is about browsers.** Native apps, `curl` and other clients outside a browser send none of
  these headers, or can forge them, and they pass. Links stay protected by their token: keep the
  token window short, and consider [IP binding](#ip-binding).
- **The exception is native HLS on iOS.** The system media player may fetch the stream without any
  of these headers, and those requests cannot be told apart from a native app. For iPhones,
  [IP binding](#ip-binding) is what stops a copied link.
- A page on `file://` sends `Origin: null` and is refused, unless you
  [allow a null origin](#allow-a-null-origin).

#### Allow a null origin

A webOS or Tizen app, or a WebView loading local files, is a page on `file://`. It has no site to
name, so its player sends `Origin: null` and the list refuses it. Turn on **Allow a null origin**
in CDN Settings and redeploy the proxy nodes, and the edge serves two more kinds of request:

- One whose `Origin` is `null`, or `file://` as some WebViews write it.
- One for a thumbnail or a storyboard that names no page at all, which is what an `<img>` in such
  an app sends. Playback and downloads keep the rule above.

**This weakens the list.** `null` identifies nobody: any website sends it from a sandboxed
iframe, with no `Referer` to tell it apart from your app. With the option on, the list stops a
site that embeds your links plainly, not one that sets out to get around it. Turn
[IP binding](#ip-binding) on with it and keep the token window short, so that a link minted for
one viewer is of no use to another site's visitors. The option does nothing while the list is
empty.
