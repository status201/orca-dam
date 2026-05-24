# ORCA DAM Picker — Setup Guide

This is the WordPress plugin that lets marketing pick images from ORCA without copy-pasting URLs. It also keeps ORCA in sync about who is using what.

Below is the full lifecycle: from a fresh laptop with nothing installed, all the way to a marketing team member updating the plugin on the live site. Pick the section that matches what you are doing right now.

---

## Phase A — Set up local development (developer, one time per laptop)

You only do this once. After this, you can change the code and see the changes in a local copy of WordPress.

### A.1 What you need installed first

| Tool | Why | How to check |
|---|---|---|
| Git | Get the code | `git --version` |
| PHP 8.1+ | Run the plugin's PHP code locally | `php -v` |
| Composer | Install PHP dependencies | `composer --version` |
| Node.js 20+ | Build the React picker | `node --version` |
| Docker Desktop | Run a throwaway WordPress site on your laptop | open Docker Desktop, it should say "running" |

If any of those say "command not found", install it from its official site before continuing.

### A.2 Clone the repo and install dependencies

Open a terminal and run:

```bash
git clone https://github.com/status201/orca-dam.git
cd orca-dam/wordpress-plugin
composer install     # downloads PHP libraries into vendor/
npm ci               # downloads JS libraries into node_modules/
npm run build        # compiles the React picker into assets/build/
```

What just happened:

- `composer install` reads `composer.json`, downloads things like Action Scheduler and the plugin-update-checker, and puts them in `vendor/`.
- `npm ci` reads `package.json`, downloads `@wordpress/scripts` and friends, and puts them in `node_modules/`.
- `npm run build` takes the React code from `assets/src/` and produces the files WordPress actually loads, in `assets/build/`. You need to re-run this whenever JS code changes (unless you're using `npm run start`, see below).

### A.3 Start a local WordPress

```bash
npx wp-env start
```

This spins up a real WordPress in Docker. It takes ~2 minutes the first time. When it's done it will print something like:

```
WordPress development site started at http://localhost:8888
WordPress test site started at http://localhost:8889
```

Open <http://localhost:8888/wp-admin> in your browser.

- **Username**: `admin`
- **Password**: `password`

You're now logged in to a clean WordPress with the ORCA DAM Picker plugin already active. By default this local site uses a **mock ORCA** (the plugin won't hit a real DAM until you turn the mock off — see A.4).

### A.4 Switch from the mock to a real ORCA (optional)

The local site ships with `ORCA_DAM_MOCK=1` so the picker is testable without internet. To point it at a real ORCA instead:

1. Edit `.wp-env.json` and remove `"ORCA_DAM_MOCK": true` from the `config` section.
2. Run `npx wp-env start` again to apply the change.
3. In WordPress admin, click **Settings → ORCA DAM** in the left sidebar.
4. Fill in the **ORCA base URL** — for example `https://dam.studyflow.nl`. No trailing slash.
5. Fill in the **API token**. To get one: open ORCA, go to **API Docs → API Tokens**, create a token for a user with the `api` role, copy the token (it's shown once).
6. Click **Save**.
7. Click **Test connection**. You should see "Connected to ORCA." If you see an error, double-check the URL and token.

### A.5 Try it

1. Click **Posts → Add New**.
2. Click the `+` button in the editor, search for "Image", insert an Image block.
3. Click **Media Library** inside the Image block — the WordPress media modal opens.
4. Click the **ORCA DAM** tab in the modal — you should see ORCA's assets.
5. Click one. It inserts into the post with the ORCA URL.
6. Click **Publish**. After ~30 seconds, open the same asset in ORCA — it should now have a reference tag like `wp:localhost/post/1`.

### A.6 The "edit, save, refresh" loop while developing

- **You changed a PHP file** (anything in `src/`): just refresh the browser. PHP changes are picked up immediately.
- **You changed a JS file** (anything in `assets/src/`): either re-run `npm run build`, **or** keep `npm run start` running in another terminal — it watches files and rebuilds automatically as you save.
- **You changed a translation string**: re-run `wp i18n make-pot . languages/orca-dam-picker.pot --domain=orca-dam-picker` and then `msgfmt languages/orca-dam-picker-nl_NL.po -o languages/orca-dam-picker-nl_NL.mo`. The release CI does this for you on tag push.
- **You changed `composer.json`**: run `composer install` again.
- **You changed `package.json`**: run `npm ci` again.

To stop the local site: `npx wp-env stop`. To wipe it clean and start fresh: `npx wp-env destroy`.

### A.7 Run the tests

```bash
./vendor/bin/phpunit                # PHP unit tests (~1 second)
npm run test:e2e:install            # one-time: download Playwright's headless Chromium
npm run test:e2e                    # end-to-end browser tests (~30 seconds)
```

If you're on Windows and `./vendor/bin/phpunit` complains, use `vendor\bin\phpunit.bat` instead.

The E2E tests use the mock ORCA, so `ORCA_DAM_MOCK=1` must be set (it already is, via `.wp-env.json`).

---

## Phase B — Cut a release (developer, every time you ship a change)

When the code is ready, you tag a release. GitHub Actions then builds a `.zip` and posts it to GitHub Releases.

### B.1 Pick a version number

Use [semver](https://semver.org/) starting with `0.1.0`. Bump:

- The **patch** number for bug fixes: `0.1.0 → 0.1.1`
- The **minor** number for new features: `0.1.1 → 0.2.0`
- The **major** number when you change behaviour that's not backwards-compatible: `0.2.0 → 1.0.0`

### B.2 Make sure the right code is on `main`

```bash
git checkout main
git pull
git log -1     # check the last commit is what you expect
```

### B.3 Tag and push

Plugin tags are prefixed with `wp-v` so they're separate from the Laravel app's own tags.

```bash
git tag wp-v0.1.1
git push origin wp-v0.1.1
```

### B.4 Watch the build

Open <https://github.com/status201/orca-dam/actions> and watch the **WordPress Plugin Release** workflow run. It will:

1. Check out your tag.
2. Install Composer + npm dependencies.
3. Build the React assets.
4. Compile translations (`.po → .mo`) and generate JS translation JSON files.
5. Write the version number into the plugin header.
6. Zip everything (excluding stuff in `.distignore`).
7. Create a GitHub Release and attach the zip.

If anything turns red, click into the failed step to see why. The most common failure is a forgotten `npm ci` lockfile bump.

### B.5 Verify the release exists

Go to <https://github.com/status201/orca-dam/releases> — your new release should be there with a file called `orca-dam-picker-0.1.1.zip` attached.

### B.6 Rollback (if needed)

If the new release is broken, don't try to fix it in place. Just delete the tag and the release on GitHub, fix the code, bump to the next patch number, and tag again. Marketing's WordPress sites will auto-update past the bad version.

---

## Phase C — Install the plugin on a live WordPress site (marketing, one time)

You only do this once per WordPress site. You don't need to be a developer.

### C.1 Get the .zip

1. Open <https://github.com/status201/orca-dam/releases> in your browser.
2. Find the most recent release (top of the list). Its name starts with `wp-v`.
3. Under **Assets**, click `orca-dam-picker-X.Y.Z.zip` to download it. Save it somewhere you can find it — your Downloads folder is fine.

### C.2 Upload it to WordPress

1. Log in to the WordPress admin of the site (`https://yoursite.com/wp-admin`).
2. In the left sidebar, click **Plugins → Add New**.
3. At the top of the page click the **Upload Plugin** button.
4. Click **Choose File** and pick the `.zip` you just downloaded.
5. Click **Install Now**.
6. When it finishes, click **Activate Plugin**.

The plugin is now installed but not configured yet.

### C.3 Get an ORCA API token

1. Ask a developer (or do it yourself if you have admin access in ORCA): open ORCA, log in as an admin.
2. Go to **API Docs → API Tokens**.
3. Click **Create token**, choose a name like "WordPress – mysite.com", and pick a user that has the **api** role.
4. Copy the token shown on the next screen — it's only displayed once.

### C.4 Configure the plugin in WordPress

1. Back in WordPress admin, in the left sidebar click **Settings → ORCA DAM**.
2. **ORCA base URL**: paste the ORCA address, for example `https://dam.studyflow.nl`. No slash at the end.
3. **API token (Sanctum)**: paste the token you just copied.
4. **Default folder filter** (optional): leave empty unless you want the picker to only show one folder by default.
5. Click **Save**.
6. Click **Test connection**. You should see a green message saying "Connected to ORCA."

If the test fails:

- Red message "ORCA returned 401": the token is wrong or revoked. Get a fresh one.
- Red message "ORCA returned 0": the URL is wrong, or your server can't reach ORCA. Try opening `https://dam.studyflow.nl/api/health` in a browser tab from the same network.

### C.5 Use it

1. Edit any post or create a new one.
2. Insert an Image block.
3. Click **Media Library** — the modal opens.
4. Click the **ORCA DAM** tab.
5. Search, pick, insert. Publish.

That's it. The first save also tells ORCA "this post uses this image", so marketing can see usage from inside ORCA.

---

## Phase D — Updating the plugin later (marketing, whenever a new version is released)

The plugin checks for new releases on GitHub once a day and shows you a banner when one is available.

### D.1 The normal path (auto-update)

1. You log in to WordPress admin one day. At the top of the page you see a banner like:
   > **ORCA DAM Picker 0.1.2 is available.** [Update now]
2. Click **Update now**.
3. WordPress downloads the new `.zip` from GitHub Releases, replaces the old plugin files, and re-activates the plugin. Takes ~10 seconds.
4. Open **Settings → ORCA DAM** to confirm your URL and token are still there (they will be).

### D.2 If auto-update doesn't show up

GitHub's release feed sometimes takes up to 12 hours to be noticed. To force an immediate check:

1. Go to **Dashboard → Updates** in WordPress admin.
2. Click **Check Again** near the top.

### D.3 Manual update (if auto-update is broken for some reason)

This is the same as a fresh install: download the new `.zip` from GitHub Releases, upload it via **Plugins → Add New → Upload Plugin**. WordPress will detect the existing plugin and offer to **Replace current with uploaded** — click that.

### D.4 Going back to an older version (rollback)

1. Go to <https://github.com/status201/orca-dam/releases> and pick an older release.
2. Download its `.zip`.
3. In WordPress, go to **Plugins**, click **Deactivate** under "ORCA DAM Picker", then **Delete**.
4. Upload the older `.zip` the same way as in C.2. Your settings (URL, token) are preserved across uninstall/reinstall.

---

## Common questions

**Where are images actually stored?**
On ORCA's S3 bucket. WordPress holds only a tiny "shell" record that points to the ORCA URL. If you delete an image in WordPress's media library, nothing happens to the file in ORCA. If you delete an image in ORCA, the WordPress shell still exists but the image will 404 on the site — the weekly broken-asset scan will flag this in **Settings → ORCA DAM → Broken assets**.

**What if two WP sites both use the same ORCA image?**
That's fine. ORCA will get two reference tags, one per site (`wp:site-a.com/post/12` and `wp:site-b.com/post/34`), so you can see exactly which sites are using it.

**Can I upload to ORCA from inside WordPress?**
Not yet. v1 is consume-only — uploads still happen in ORCA's own admin. This may change in v0.2.

**Where does the plugin store the API token?**
In the WordPress `wp_options` table, encrypted with AES-256-GCM. The encryption key is derived from your `wp-config.php` `AUTH_KEY` constant (or `ORCA_ENCRYPTION_KEY` if you set one). The token is never sent to the browser; all calls to ORCA go through a WP REST proxy.

**Is there a Dutch translation?**
Yes. Switch the site language to Nederlands in **Settings → General → Site Language**. The picker, settings page, and toolbar buttons all switch to Dutch automatically.
