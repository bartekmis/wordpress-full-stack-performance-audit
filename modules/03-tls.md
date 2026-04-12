# Module 03 - TLS, HTTP protocol, connection

> **Purpose:** Check TLS version, HTTP protocol (1.1 / 2 / 3), HSTS, session resumption, HTTP -> HTTPS redirect. Based on WOW course lesson 3.

## AUTO steps

### 1. Check TLS version and ALPN

```bash
echo | openssl s_client -connect {{HOST}}:443 -servername {{HOST}} -tls1_3 2>/dev/null | grep -E "Protocol|Cipher|ALPN"
```

Results:
- `Protocol  : TLSv1.3` -> good
- `Protocol  : TLSv1.2` -> warn (TLS 1.2 still works, but 1.3 saves 1 round trip on the handshake)
- No `Protocol` -> server does not support 1.3, check 1.2:

```bash
echo | openssl s_client -connect {{HOST}}:443 -servername {{HOST}} -tls1_2 2>/dev/null | grep "Protocol"
```

Save `TLS_VERSION` (e.g., `TLS 1.3`).

The ALPN line (e.g., `ALPN protocol: h2`) confirms HTTP/2. If there is no ALPN - the server falls back to HTTP/1.1.

### 2. HTTP/2 support

```bash
curl -sI --http2 https://{{HOST}} -o /dev/null -w "Protocol: %{http_version}\n"
```

The result is `2` (HTTP/2), `1.1` (HTTP/1.1), or `3` (HTTP/3 if enabled in curl). Save `HTTP2_SUPPORT`.

### 3. HTTP/3 (QUIC) support

```bash
curl -sI --http3 https://{{HOST}} -o /dev/null -w "Protocol: %{http_version}\n" 2>/dev/null
```

If it returns `3` -> HTTP/3 is enabled. If error - curl lacks HTTP/3 support or the server doesn't have it. Alternatively, check the `Alt-Svc` header:

```bash
curl -sI https://{{HOST}} | grep -i "alt-svc"
```

If `alt-svc: h3=...` - HTTP/3 is advertised. Save `HTTP3_SUPPORT`.

### 4. HSTS header

```bash
curl -sI https://{{HOST}} | grep -i "strict-transport-security"
```

If it returns `Strict-Transport-Security: max-age=...` - HSTS is enabled. Save `HSTS_PRESENT = YES` and `HSTS_MAX_AGE`. If the header is missing - `HSTS_PRESENT = NO`.

**Threshold:** `max-age` should be >= 31536000 (1 year). Less -> warn.

### 5. HTTP -> HTTPS redirect (wasted step)

```bash
curl -sI -o /dev/null -w "%{http_code} -> %{redirect_url}\n" http://{{HOST}}
```

If the code is 301/302 and `redirect_url` starts with `https://` - the redirect exists. Connection time is wasted.

```bash
curl -o /dev/null -s -w "%{time_total}\n" -L http://{{HOST}}
```

Compare with the time when going directly via HTTPS:
```bash
curl -o /dev/null -s -w "%{time_total}\n" https://{{HOST}}
```

The difference = redirect cost. Save `REDIRECT_COST_MS`.

If HSTS is enabled and `max-age >= 31536000` with `includeSubDomains; preload` - **the browser skips the redirect** after the first visit. Without HSTS - every new client pays with the redirect.

### 6. Session Resumption test

Perform 2 connections in a row, measuring time_appconnect (TLS handshake):

```bash
curl -o /dev/null -s -w "1: %{time_appconnect}s\n" https://{{HOST}}
curl -o /dev/null -s -w "2: %{time_appconnect}s\n" https://{{HOST}}
```

curl does not share TLS sessions between invocations, so a better option is:

```bash
curl -o /dev/null -s -w "1: %{time_appconnect}s\n2: %{time_appconnect}s\n" \
  https://{{HOST}} https://{{HOST}}
```

If the second value is dramatically smaller (e.g., < 30% of the first) - session resumption works. If equal - it doesn't.

Alternatively, in phase 8 (Chrome DevTools MCP) you will do the same with two `navigate_page` calls and compare with the timing breakdown.

Save:
- `TLS_HANDSHAKE_FIRST_MS` = first value
- `TLS_HANDSHAKE_REPEAT_MS` = second value
- `TLS_RESUMPTION_WORKS` = YES/NO

### 7. Full connection timing breakdown

```bash
curl -o /dev/null -s -w "DNS: %{time_namelookup}\nConnect: %{time_connect}\nTLS: %{time_appconnect}\nTTFB: %{time_starttransfer}\nTotal: %{time_total}\n" https://{{HOST}}
```

Save the entire output to the appendix and use it for TTFB correlation in phase 10.

### 8. Cipher suite (informational)

```bash
echo | openssl s_client -connect {{HOST}}:443 -servername {{HOST}} 2>/dev/null | grep "Cipher    :"
```

Weak ciphers (3DES, RC4, CBC) - security flag. Save `TLS_CIPHER`.

## ASK steps

### C1. SSL Labs grade
> "Go to **https://www.ssllabs.com/ssltest/analyze.html?d={{DOMAIN}}** and wait for the test to finish (~2 min). Send a screenshot of the overall result AND the **Session Resumption Tickets** section (at the very bottom, in the Protocol Details section). Or write: grade (A+/A/B/...), Session Resumption status."

Save `SSL_LABS_GRADE` and `SSL_LABS_RESUMPTION`.

## Findings

| Condition | Sev | Finding |
|---|---|---|
| `TLS_VERSION = 1.2` (no 1.3) | warn | TLS 1.3 saves 1 RTT (~50-100ms). Enable in web server config |
| `HTTP2_SUPPORT = 1.1` | bad | HTTP/1.1 only. Enable HTTP/2 (Nginx: `http2 on;`, Apache: `mod_http2`) |
| `HTTP3_SUPPORT = NO` + CDN present | info | Enable HTTP/3 in CDN panel - zero cost |
| `HSTS_PRESENT = NO` | warn | Missing HSTS. Add `Strict-Transport-Security: max-age=31536000; includeSubDomains` |
| `HSTS_MAX_AGE < 31536000` | info | HSTS max-age too low - set to 31536000 (1 year) |
| `TLS_RESUMPTION_WORKS = NO` | bad | No session resumption - full handshake every visit (+100-200ms). Fix: `ssl_session_cache shared:SSL:50m` |
| `REDIRECT_COST_MS > 200` + no HSTS | bad | HTTP->HTTPS redirect costs {{val}}ms. Enable HSTS |

## Report data

Save: `TLS_VERSION` + `_STATUS`, `HTTP_PROTOCOL` + `_STATUS`, `HSTS_PRESENT` + `_STATUS`, `HTTPS_REDIRECT` + `_STATUS`, `TLS_HANDSHAKE_FIRST_MS`, `TLS_HANDSHAKE_REPEAT_MS`, `TLS_RESUMPTION_STATUS`, `TLS_FINDINGS`. Appendix: openssl/curl output.
