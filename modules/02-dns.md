# Module 02 - DNS and lookup

> **Purpose:** Measure DNS performance, determine the provider, check whether it uses Anycast, count preconnect/dns-prefetch hints in `<head>`. Based on WOW course lesson 2.

## AUTO steps

### 1. Extract domain and zone
From `SITE_URL` (e.g., `https://example.com/blog`) extract `DOMAIN = example.com` (second and first level, without subdomain - if it is e.g. `shop.example.com`, then the `apex` is `example.com`, but you perform the lookup for `shop.example.com`).

Save:
- `HOST` = full hostname from the URL (e.g., `shop.example.com`)
- `APEX` = apex domain (e.g., `example.com`)

### 2. Nameservers (who holds the DNS)

```bash
dig NS {{APEX}} +short
```

The result is a list of nameservers. Examples:
- `*.cloudflare.com` -> Cloudflare (anycast, good)
- `*.awsdns-*.com/net/org` -> AWS Route 53 (anycast, good)
- `ns*.googledomains.com` / `ns*.google.com` -> Google Cloud DNS (anycast)
- `dns*.dnsmadeeasy.com` -> DNS Made Easy (anycast)
- `ns*.nazwa.pl` / `ns*.home.pl` / `ns*.ovh.net` -> Polish/European registrar (NOT anycast in the global sense)
- `ns*.hetzner.com` -> Hetzner DNS (limited presence)
- `ns*.kinsta.com` -> Kinsta (managed)

Save `DNS_PROVIDER` and `DNS_NAMESERVERS`.

### 3. Anycast - heuristic

List of known anycast providers (if `DNS_PROVIDER` contains any of these strings):
```
cloudflare, awsdns, google, googledomains, ns1.com, dnsimple,
dnsmadeeasy, dyn.com, akamai, ultradns, nsone, fastly,
constellix, cloudns
```

If match: `DNS_ANYCAST = "YES"`, status `good`.
If no match: `DNS_ANYCAST = "Probably not"`, status `warn`. In findings add a recommendation to consider Cloudflare DNS (free) as a quick win.

### 4. Whois - who manages the domain

```bash
whois {{APEX}} 2>/dev/null | head -50
```

Extract:
- `Registrar:` - domain registrar
- `Name Server:` - nameservers (confirmation from dig)
- `Updated Date:` - when last changed (informational)

If the registrar and DNS provider are the same (e.g., nazwa.pl) - flag: registrar serves as DNS, usually not anycast. If different (e.g., registrar OVH, but DNS on Cloudflare) - good.

Save `REGISTRAR`.

### 5. TTL and records

```bash
dig {{HOST}} A +noall +answer
dig {{HOST}} AAAA +noall +answer 2>/dev/null
dig {{HOST}} CNAME +noall +answer 2>/dev/null
```

Extract TTL (second field in the output). Save `DNS_TTL`. If TTL < 300s on production records (with exceptions) - that's a flag (too aggressive), worth discussing.

### 6. DNS lookup time measurement

```bash
for i in 1 2 3 4 5; do
  curl -o /dev/null -s -w "DNS: %{time_namelookup}s\n" https://{{HOST}}
done
```

The first attempt may be slow (cold resolver), subsequent ones fast (cache). Save the **slowest** (cold) and **fastest** (warm) as `DNS_LOOKUP_COLD_MS` and `DNS_LOOKUP_WARM_MS`.

Convert seconds -> milliseconds (`0.045s` = `45ms`).

**Threshold:**
- < 30ms -> good (green)
- 30-100ms -> ok (yellow)
- > 100ms -> bad (red, recommend provider change)

### 7. dig +trace

Full resolution chain - shows how many DNS authorities the resolver queries.

```bash
dig +trace {{HOST}} 2>/dev/null | tail -30
```

Save the fragment to the report (for the appendix). If you see many "delegations" - this is essential for understanding why DNS is slow.

### 8. Preconnect / dns-prefetch in `<head>`

Fetch the homepage HTML and count hints.

```bash
curl -sL {{SITE_URL}} | grep -oE '<link[^>]*rel="(preconnect|dns-prefetch)"[^>]*>' | wc -l
```

Also list the domains:
```bash
curl -sL {{SITE_URL}} | grep -oE '<link[^>]*rel="(preconnect|dns-prefetch)"[^>]*href="[^"]*"' | grep -oE 'href="[^"]*"'
```

Save `DNS_PRECONNECT_COUNT` and `DNS_PRECONNECT_DOMAINS`.

**Threshold (from Lighthouse):** max 4 preconnects. Beyond that the effect is counterproductive (too many open connections). If > 4 - flag.

### 9. CDN detection via DNS

If `dig {{HOST}} CNAME +short` returns something like:
- `*.cloudflare.net` or IP in Cloudflare ranges -> CDN: Cloudflare proxy ON
- `*.cloudfront.net` -> AWS CloudFront
- `*.fastly.net` -> Fastly
- `*.bunnycdn.com` -> BunnyCDN
- `*.kxcdn.com` -> KeyCDN
- `*.akamaized.net` -> Akamai

Save `CDN_DETECTED`. If `none` - in findings suggest implementing a CDN (usually Cloudflare).

## ASK steps

### B1. dnsperf benchmark
> "To compare the DNS provider against others, go to **https://www.dnsperf.com/#!dns-resolvers** and see where **{{DNS_PROVIDER}}** ranks. Paste a screenshot or write: ranking position, average global response time. If you don't want to - write `skip`."

Save the result as `DNS_PERF_BENCHMARK`.

### B2. Registrar panel status (if non-anycast)
If `DNS_ANYCAST = "Probably not"`:
> "Can you go to your registrar panel ({{REGISTRAR}}) and take a screenshot of the DNS / nameserver configuration page? I want to see what options you have (e.g., whether there is an option to switch to custom NS, such as Cloudflare)."

## Findings

| Condition | Sev | Finding |
|---|---|---|
| `DNS_LOOKUP_COLD_MS > 100` | bad | DNS lookup {{ms}}ms - migrate to anycast (Cloudflare, free) |
| `DNS_ANYCAST = NO` | warn | No global anycast - consider Cloudflare DNS |
| `DNS_PRECONNECT_COUNT > 4` | warn | {{n}} preconnect hints (max 4 recommended) |
| `CDN_DETECTED = none` | warn | No CDN detected - implement Cloudflare |
| `DNS_TTL < 60` | info | TTL {{ttl}}s too aggressive - increase to 3600+ |
| `REGISTRAR = DNS_PROVIDER` | info | Same registrar and DNS - consider dedicated anycast |

## Report data

Save: `DNS_PROVIDER` + `_STATUS`, `DNS_ANYCAST` + `_STATUS`, `DNS_LOOKUP_MS` + `_STATUS`, `DNS_NS_COUNT`, `DNS_TTL`, `DNS_PRECONNECT_COUNT` + `_STATUS`, `DNS_FINDINGS`. Appendix: raw dig/whois/curl output.
