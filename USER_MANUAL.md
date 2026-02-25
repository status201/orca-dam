# ORCA DAM — Quick Start Guide

**ORCA Retrieves Cloud Assets** — Your friendly Digital Asset Manager

---

## Table of Contents

1. [Welcome to ORCA!](#welcome-to-orca)
2. [The Golden Rules](#the-golden-rules)
3. [Getting Started](#getting-started)
4. [Uploading Files](#uploading-files)
5. [Browsing & Finding Assets](#browsing--finding-assets)
6. [Working with Tags](#working-with-tags)
7. [Editing Asset Details](#editing-asset-details)
8. [Replacing Assets](#replacing-assets)
9. [The Trash (Admin Only)](#the-trash-admin-only)
10. [Moving Files (The Long Way)](#moving-files-the-long-way)
11. [Discover Feature (Admin Only)](#discover-feature-admin-only)
12. [Import Metadata (Admin Only)](#import-metadata-admin-only)
13. [Export to CSV (Admin Only)](#export-to-csv-admin-only)
13. [API Docs & Token Management (Admin Only)](#api-docs--token-management-admin-only)
14. [S3 Integrity Check (Admin Only)](#s3-integrity-check-admin-only)
15. [User Preferences](#user-preferences)
16. [Tips & Tricks](#tips--tricks)
17. [Glossary](#glossary)
18. [Getting Help](#getting-help)

---

## Welcome to ORCA!

Congratulations on getting access to ORCA DAM! Whether you're uploading images for course materials, managing documents, or organizing media files for Studyflow, you've come to the right place.

Ever tried managing files directly on Amazon S3? No search, no notes on files, no idea who uploaded what, and one wrong click makes something public that shouldn't be. **Not fun.** ORCA is the friendly reception desk in front of that massive warehouse — it sits between you and the raw cloud storage, making everything safer, searchable, and manageable.

---

## The Golden Rules

ORCA has some deliberate limitations. These aren't bugs — they're safety features!

**1. You Can Rename Files — But Not Move Them**
You can change an asset's **display filename** anytime via the Edit page. The actual URL (S3 key) stays the same, so existing links never break.

**2. You Cannot Move Files Between Folders**
Moving would change the URL, breaking all existing links. See "Moving Files (The Long Way)" below for the workaround.

**3. Soft Delete is Your Safety Net**
Deleted files go to Trash first ("soft delete"). Only admins can permanently delete or restore files. Accidentally deleted something? Don't panic — ask an admin!

---

## Getting Started

When you log in, your dashboard shows **Total Assets**, **My Assets**, **Total Storage**, and **Tag** counts. Admins also see user count and trashed items.

### Your Role: Editor vs Admin

| Feature | Editor | Admin |
|---------|:------:|:-----:|
| View all assets | ✓ | ✓ |
| Upload files | ✓ | ✓ |
| Edit asset details (filename, alt text, caption, tags) | ✓ | ✓ |
| Replace asset files | ✓ | ✓ |
| Delete files (to Trash) | ✓ | ✓ |
| Set personal preferences | ✓ | ✓ |
| Create folders | ✗ | ✓ |
| View Trash | ✗ | ✓ |
| Restore from Trash | ✗ | ✓ |
| Permanently delete files | ✗ | ✓ |
| Discover unmapped S3 files | ✗ | ✓ |
| Export assets to CSV | ✗ | ✓ |
| Manage users | ✗ | ✓ |
| Access System settings | ✗ | ✓ |
| Manage API tokens & JWT secrets | ✗ | ✓ |

---

## Uploading Files

Remember: you can't move files later, so **choose the correct folder before uploading**. Think about where the file belongs and whether other team members can find it. Need a new folder? Ask an admin!

### How to Upload

1. Click **Upload** in the navigation menu
2. Select your target folder
3. Drag and drop files onto the upload area, or click to browse
4. Watch the progress bars — larger files may take a moment
5. Done! Thumbnails and AI tags generate in the background; just refresh the page

**File size limit:** Up to 500MB per file. Larger files upload in chunks automatically, so connection hiccups won't lose your progress.

After upload, your file is stored in S3, a thumbnail is generated (for images), AI tags are added if enabled, and the asset appears in your library.

---

## Browsing & Finding Assets

View assets in **Grid View** (visual thumbnails) or **List View** (detailed table) — toggle with the buttons in the top right.

**Search:** Type any part of a filename, tag, folder, S3 key, alt text, or caption. Use search operators for precision:
- `+term` — **require** this term (must appear in results)
- `-term` — **exclude** this term (must not appear in results)
- Example: `landscape +mountain -snow` finds "landscape" assets that must include "mountain" but not "snow"

**Filters:** File type (images/videos/documents), folder, tags (multi-select).

**Sorting:** Date modified, date uploaded, size, name, or S3 key — each ascending or descending.

### Quick Actions

Hover over any asset to see: **👁 View**, **📋 Copy URL**, **✏️ Edit**, **⇄ Replace**, **🗑 Delete**.

In List View, you can edit tags and license info directly inline.

### Selecting Multiple Assets

Click checkboxes to select individual assets. Hold **Shift** and click to select a range. Once selected, a toolbar appears with bulk actions including **bulk tag management** (add or remove tags from all selected assets at once).

---

## Working with Tags

Tags are labels that help you organize and find assets. They come in three types:

| Type | Icon | How Created |
|------|------|-------------|
| **User tags** | Blue badge | Added manually by you |
| **AI tags** | Purple badge | Generated automatically by AI |
| **Reference tags** | Orange badge with link icon | Added by external systems via API |

**Tags are unique** — you can't have two tags with the same name. A tag's type (user/AI/reference) is set when it's first created and doesn't change, even if the same tag is later added manually to another asset. This mostly matters for statistics, not day-to-day use.

**Reference tags** are created by external systems (e.g., a Rich Text Editor integration) to track which assets they use. They appear as orange badges with a link icon. You can rename or delete reference tags like user tags, but they can only be created via the API.

**Adding tags:** On the Edit page, type a tag name and press Enter. In List View, click the **+** button in the Tags column.

**Removing tags:** Click the **×** next to any tag. This only removes the connection — the tag itself still exists.

**Bulk tagging:** Select multiple assets (use Shift+click for ranges), then use the bulk tag toolbar to add or remove tags across all selected assets at once.

> **Tags showing "0 assets" might not be empty!** They could still be attached to trashed assets. When restoring from Trash, tags still attached are preserved, but tags you removed before restoring are gone forever.

---

## Editing Asset Details

Click any asset or hit Edit to modify:

- **Filename** — Display name only; the URL and S3 key stay the same, so links never break
- **Alt Text** — Short description for accessibility. Keep it brief but descriptive (e.g., "Student studying at a laptop in a library")
- **Caption** — Longer description or credit line displayed alongside the image
- **License Info** — Track usage rights:
  - **License Type** — Public Domain, Creative Commons variants, Fair Use, All Rights Reserved
  - **License Expiry Date** — When does the license run out? (leave empty if perpetual)
  - **Copyright Holder** — Who owns the rights?
  - **Copyright Source** — Link to where you found the licensing info

### Generating AI Tags

If AI tagging is enabled and you want fresh suggestions:
1. Open the Edit page for any image
2. Click **Generate AI Tags**
3. New tags appear automatically

*Note: This replaces any existing AI tags on that asset.*

---

## Replacing Assets

Need to update a file without changing its URL? **Asset Replace** keeps the same URL, preserves all metadata (alt text, caption, tags, license info), and only swaps the file itself. All existing links continue to work.

### How to Replace

1. Go to the **Edit** page → click **Replace File**
2. You'll see the current file preview and a drop zone for the new file
3. Drag and drop or browse for your replacement
4. **The new file must have the same extension** (e.g., `.jpg` → `.jpg`, not `.jpg` → `.png`)
5. Click **Replace File** and confirm the warning dialog

If you need to change the file format entirely, you'll have to delete and re-upload (which means updating all links).

### The Placeholder Workflow

This is where Asset Replace really shines:

1. **Upload placeholders** with clear names like `hero-image-DRAFT.jpg` — tag them `draft`!
2. **Link them in Studyflow** using the ORCA URLs
3. **Replace when ready** — swap in the final versions
4. **No broken links** — Studyflow automatically shows the new images

Filter by the `draft` tag to see all your placeholders, replace each one, and remove the tag when done.

### Important Warnings

> **The original file is permanently gone after replacement** (unless S3 versioning is enabled — ask your admin). There's no undo.

When you replace an image, the thumbnail, dimensions, and file size all update automatically to match the new file.

---

## The Trash (Admin Only)

Deleted files go to Trash — a holding area before permanent deletion. Admins can access it from the navigation menu.

- **Restore** — Bring the asset back to life
- **Delete Permanently** — Remove forever (also deletes the file from S3)

It's your safety net: accidentally deleted something? Restore it! Need to audit what was removed? Check the Trash. Prevents the "oh no" moment of irreversible deletion.

---

## Moving Files (The Long Way)

Since ORCA doesn't allow moving files (it would break links!), here's the workaround:

1. **Download** the file to your computer
2. **Soft delete** the original in ORCA
3. Ask an **admin to permanently delete** the trashed file
4. **Upload** the file to the correct folder
5. **Update all links** in Studyflow to point to the new URL

Yes, it's tedious. That's intentional — it makes you think twice and reminds you to update those links.

---

## Discover Feature (Admin Only)

Files sometimes end up in S3 without going through ORCA (direct uploads, migrations, etc.). **Discover** lets admins scan S3 for unmapped files, preview them, and import selected ones into ORCA.

Files belonging to trashed assets show a red "Deleted" badge to prevent accidentally re-importing something intentionally removed.

---

## Import Metadata (Admin Only)

Bulk-update asset metadata from a CSV. Go to the user dropdown > **Import**, select whether to match by `s3_key` or `filename`, then paste CSV data or upload/drop a `.csv` file.

Click **Preview Import** to see which assets matched and what will change. Empty fields in the CSV are skipped (existing values preserved). Tags are added to existing ones, never removed. Click **Import** to apply.

---

## Export to CSV (Admin Only)

Admins can export the asset library to CSV: go to Assets, apply any filters, and click **Export**. The export includes file details, tags (user, AI, and reference in separate columns), license/copyright info, public URLs, and uploader info.

---

## API Docs & Token Management (Admin Only)

External systems can access your DAM via the API. Manage authentication from the **API Docs** page (click your name → API Docs).

### API Tokens (Sanctum)

Long-lived credentials for backend-to-backend integrations. Never expose these in frontend code.

1. Go to API Docs → **API Tokens** tab
2. Select a user, give the token a descriptive name (e.g., "Website CMS")
3. Click **Create Token**
4. **Copy immediately — shown only once!**

Revoke anytime from the token list.

### JWT Secrets

For frontend integrations (e.g., rich text editors). Your external backend generates short-lived JWTs using the secret, and ORCA validates them.

1. Go to API Docs → **JWT Secrets** tab
2. Select a user, click **Generate Secret**
3. **Copy immediately — shown only once!**
4. Share the secret securely with the external system's backend developer

Revoke from the list when no longer needed.

> JWT authentication must be enabled (`JWT_ENABLED=true` in `.env`). You can also toggle it at runtime from the API Docs dashboard.

---

## S3 Integrity Check (Admin Only)

Files can sometimes go missing in S3 without going through ORCA (accidental deletion, bucket issues). The integrity check detects these.

1. Go to **System** page
2. Find the **S3 Integrity** card
3. Click **Verify S3 Integrity** — this queues a background check for every asset
4. The status text confirms how many checks were queued
5. Click the **refresh** button to update the missing count as jobs complete

**Viewing missing assets:** Go to the Assets page and filter with `?missing=1` in the URL. Missing assets show a warning indicator.

**What to do with missing assets:** Investigate why they're missing, then either restore from backup or permanently delete the orphaned records via Trash.

---

## User Preferences

Customize ORCA via the **Profile** page (click your name → Profile → Preferences section → Save).

### Available Preferences

- **Home Folder** — Default starting folder when browsing assets. Useful if you mostly work in one folder (e.g., `assets/marketing`). Leave empty for root. Use the refresh icon (↻) to reload the folder list if new folders were added.
- **Items Per Page** — Choose from 12, 24, 36, 48, 60, 72, or 96. Select "Use default" to follow the global setting. The per-page dropdown on the Assets page still works as a session override.
- **Language** — English or Nederlands. Select "Use default" to follow the admin's global setting. Changes take effect on the next page load.

Preferences follow a priority: URL parameters > your user preference > global system setting. Your preferences are respected, but navigating freely (clicking folders, changing dropdowns) won't reset until you load a fresh page.

---

## Tips & Tricks

**Keyboard Shortcuts:** Enter to confirm, Escape to cancel.

**Best Practices:**
1. **Name files clearly before uploading** — you can rename later, but clear originals help
2. **Use tags generously** — they make searching much easier
3. **Fill in alt text** — good for accessibility and helps you remember what's in the image
4. **Choose folders wisely** — the folder structure is permanent
5. **Check the Trash** before asking "where did my file go?"

---

## Glossary

| Term | Meaning |
|------|---------|
| **S3** | Amazon's cloud storage where your files live |
| **Soft Delete** | Sending a file to Trash (recoverable) |
| **Hard Delete** | Removing a file forever (not recoverable) |
| **AI Tags** | Tags automatically generated by artificial intelligence |
| **User Tags** | Tags manually added by people |
| **Reference Tags** | Tags added by external systems to track asset usage (orange badges) |
| **S3 Key** | The technical path/address of a file in cloud storage |
| **Custom Domain** | A friendly URL (like `cdn.example.com`) instead of the raw S3 bucket URL |
| **Rekognition** | Amazon's AI service that analyzes images and suggests tags |
| **Replace** | Uploading a new file to overwrite an existing asset while keeping the same URL |

---

## Getting Help

- Check this manual first (you're already here!)
- Ask your admin for help with permissions or restoring files
- For technical issues, contact your system administrator
- Admins: see `README.md`, `CLAUDE.md`, and `DEPLOYMENT.md` for technical docs

---
