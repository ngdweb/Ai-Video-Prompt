# Admin Panel Media Security — Images & Videos

How every **image** and **video** in the NGD Admin panel is protected, as actually
implemented in this project. Two different strategies are used because images and videos
have different threat profiles:

| Media | Strategy | Real URL in page? |
| :--- | :--- | :--- |
| **Images** | Encrypted, expiring token URL + rewritten to `<canvas>` on screen | No — only an opaque 30s token |
| **Videos** | **No URL at all** — id-based authenticated POST that streams bytes to a hardened blob player | No — not even a token |

> **Stack:** Laravel 8, guard `admin`, media under `public/upload/...`, shared admin layout
> `resources/views/layouts/kaiadmin.blade.php`.

---

## 1. The honest ceiling (read this first)

No website can make displayed media **impossible** to copy:

- **Screenshots / screen recording** of anything on screen are always possible.
- To **play a video**, the browser must receive the actual bytes — only DRM
  (Widevine / FairPlay + a license server) prevents a logged-in user from capturing them,
  and that is a large, separate system not used here.

What this system **does** guarantee:

- The real file path (`/upload/ngendev/videos/Old Dance/.../old dance_1.mp4`) **never appears**
  in the page, DOM, view-source, or network response.
- Nothing streams without a **logged-in admin session** (and, for video, a valid **CSRF** token).
- Image links **expire** (30 s) and die if tampered.
- The obvious grab vectors are removed: drag, right-click "Save as", middle-click open,
  the browser's native video **download** button, and "open raw file in new tab".

The only residual is a **logged-in admin** ripping media via DevTools or screen recording —
your own trusted user, which no technology can stop.

---

## 2. Files involved

| File | Role |
| :--- | :--- |
| `app/Support/SecureMediaHelper.php` | Encrypts/decrypts a path into a URL-safe, expiring token (images) |
| `app/Http/Controllers/SecureMediaController.php` | Streams a token's file (images, fonts, etc.) with no-store headers |
| `app/Http/Middleware/ProtectAdminMediaUrls.php` | Rewrites every `/upload/...` **image** URL on admin pages → token URL |
| `app/Http/Controllers/NgendevVideoController.php` → `stream()` | **Video**: auth+CSRF POST that streams bytes by DB id (no URL exposed) |
| `app/helpers.php` | Global `secure_media()` helper |
| `public/js/admin-media-protect.js` | Canvas-converts images, hardens media, and the blob video player |
| `routes/web.php` | Registers the stream routes + middleware on the admin group |
| `resources/views/layouts/kaiadmin.blade.php` | Includes the protection engine before `</body>` |

---

## 3. How IMAGES are secured

### 3.1 Flow

```
Admin page renders  (views still write normal /upload/....webp URLs)
        │
        ▼
ProtectAdminMediaUrls middleware
   scans the HTML/JSON response and rewrites EVERY
   /upload/....(png|jpg|webp|gif|svg...) URL  →  /secure-media?token=<encrypted, 30s>
        │
        ▼
Browser receives HTML containing ONLY token URLs (no real paths)
        │
        ▼
admin-media-protect.js
   for each <img>: fetch the token URL → draw onto a <canvas> → remove the <img>
   (so there is no <img> element to drag, open, or "Save image as")
        │
        ▼
SecureMediaController@stream
   decrypt token → reject if expired / tampered / path traversal / outside upload/
   → stream the file with Cache-Control: no-store
```

### 3.2 Why it covers every module

The middleware rewrites URLs in the **response HTML**, so it does not care which controller
or view produced the image — **all admin pages are covered at once** with no per-module code.
The JS engine likewise scans every `<img>`/`<video>` on the page and any added later by AJAX
(via `MutationObserver`).

### 3.3 Key detail — spaces in paths

Category folders contain spaces (e.g. `Old Dance`). The middleware regex is **space-aware**
(lazy, bounded by quotes/angle-brackets), so paths like
`upload/ngendev/videos/Old Dance/video_thumbnail/thumb.webp` are matched and tokenized.
Images get a **30 s** token (they load on render); click-to-open types (video/zip) get **1 hour**.

### 3.4 What you see in DevTools

- The thumbnail is a `<canvas>`, **not** an `<img>`.
- No `/upload/...` path anywhere.
- A copied `/secure-media?token=...` URL returns **HTTP 403** after 30 s or if one character is changed.

---

## 4. How VIDEOS are secured (stronger — no URL in the page)

Videos use a different, stronger model: **there is no media URL in the page to begin with** —
not even a token.

### 4.1 The trigger (no URL)

`resources/views/ngendev/videos/index.blade.php` and `.../form.blade.php`:

```blade
<a href="javascript:void(0)" class="video-btn" title="Play video"
   data-stream-url="{{ route('ngendev.videos.stream', $video->id) }}">
   <i class="fas fa-play"></i>
</a>
```

The button carries only a **POST endpoint keyed by the video's DB id** — no file path, no token,
nothing openable in a browser tab.

### 4.2 The stream endpoint (auth + CSRF)

`routes/web.php` (inside the `auth:admin` admin group):

```php
Route::post('videos/{video}/stream', [NgendevVideoController::class, 'stream'])
    ->name('videos.stream');
```

`NgendevVideoController@stream()` resolves the file **server-side** from the id, confirms it is
inside `public/upload`, and streams the bytes with no-store headers. It responds only to an
authenticated admin with a valid CSRF token.

### 4.3 The hardened blob player (client)

`public/js/admin-media-protect.js` intercepts clicks on `.video-btn[data-stream-url]` and:

1. does an authenticated **POST** (`X-CSRF-TOKEN`, `same-origin` credentials),
2. reads the response as a **blob**,
3. plays it in a locked-down `<video>` overlay:
   - `controlsList="nodownload noplaybackrate noremoteplayback"` → **no native download button**,
   - `disablePictureInPicture`, `disableRemotePlayback`,
   - right-click and drag blocked,
   - blob URL revoked on close (Esc / click outside / ✕).

### 4.4 Flow

```
Click "Play"  (button holds only data-stream-url = /ngendev/videos/5/stream)
        │
        ▼
admin-media-protect.js  →  POST /ngendev/videos/5/stream
                           headers: X-CSRF-TOKEN, X-Requested-With
        │
        ▼
NgendevVideoController@stream( video 5 )
   auth:admin + CSRF enforced → resolve path from DB → verify inside upload/
   → stream bytes (Content-Disposition: inline, Cache-Control: no-store)
        │
        ▼
JS receives bytes as a Blob → blob: URL → hardened <video> overlay plays it
```

### 4.5 What you see in DevTools

- **view-source / Elements:** the `<a>` is `javascript:void(0)`; its `data-stream-url` is an
  id-based POST endpoint, **not** a file URL. No `/upload/...mp4` anywhere.
- **The playing element:** `<video src="blob:http://127.0.0.1:8000/...">` — a blob, not a path.
- **Network:** a `POST /ngendev/videos/5/stream` that returns **nothing** without your admin
  cookie + CSRF token, and cannot be opened by pasting a URL (it is a POST).

---

## 5. Configuration knobs

| Setting | Where | Default |
| :--- | :--- | :--- |
| Base media folder | `SecureMediaHelper` (`upload/` check), `SecureMediaController` (`public_path('upload')`), middleware regex | `upload` |
| Image token lifetime | `ProtectAdminMediaUrls::SHORT_TTL_SECONDS` | `30` |
| Video/zip token lifetime | `ProtectAdminMediaUrls::LONG_TTL_SECONDS` | `3600` |
| Long-lived extensions | `ProtectAdminMediaUrls::LONG_LIVED_EXTENSIONS` | `mp4, mov, avi, wmv, zip` |
| Auth guard | `auth:admin` on the route group | `admin` |
| Allowed extensions | `$mimeMap` (controllers) + middleware regex | common image/video/font/zip |

---

## 6. Verification checklist

**Images**
- [ ] Open a list page → a thumbnail is a `<canvas>`, not `<img>`; no `/upload/...` in the DOM.
- [ ] Drag a thumbnail to the tab bar → nothing happens. Right-click → no "Save image as".
- [ ] Copy a `/secure-media?token=...` URL, wait 31 s, open it → **HTTP 403** (expired).
- [ ] Change one character of the token → **HTTP 403** (tampered).

**Videos**
- [ ] View-source of `/ngendev/videos` → **no** `/upload/...mp4` and **no** token URL.
- [ ] Click Play → it opens in the hardened overlay; the `<video>` src is `blob:`.
- [ ] The player has **no download button**; right-click is blocked.
- [ ] In Network, copy the `POST /ngendev/videos/{id}/stream` as a GET URL and open it in a new
      tab → it does **not** return the file (POST + auth + CSRF required).
- [ ] Log out, then POST to the stream endpoint → redirected to login, never the file.

---

## 7. Deployment notes

- **`php artisan serve` caveat:** the PHP dev server serves `public/` files directly and ignores
  `.htaccess`. For images, the real path is hidden so it can't be guessed, but a **known** path
  can still be hit directly under the dev server. Under **Apache/Nginx** direct hits are blocked.
  Videos are unaffected (no path is ever exposed).
- **Caches:** Blade recompiles automatically on edit. If the server uses OPcache, a controller/JS
  change may need a hard refresh (Ctrl+F5) or an Apache restart. There is no route cache in this
  project, so new routes are live immediately.
- **Strongest tier (optional, future):** move originals **out of the web root**
  (`storage/app/private/...`), encrypt at rest (`Crypt`), and serve only through the controllers —
  the only way to make the master files truly unreachable by direct URL under any server.

---

## 8. Limitations (be honest with stakeholders)

1. **Screenshots / screen recording** of displayed media are always possible.
2. A **logged-in admin** can still pull the blob or the streamed bytes via DevTools — only DRM
   prevents this, and the actor is your own trusted user.
3. **Whole-file blob download:** the video fully downloads into a blob before playback starts
   (fine for short review clips; slower than progressive streaming for very large files — the
   trade-off for keeping zero URL in the page).
4. **Public API:** if this app ever exposes a public API returning direct `/upload/...` URLs,
   that bypasses everything here and must be secured separately (signed, expiring URLs).

---

_Last updated: 2026-10-25._
