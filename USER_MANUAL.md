# ORCA DAM User Manual

**ORCA Retrieves Cloud Assets** — Your friendly Digital Asset Manager

---

## Welcome to ORCA!

Congratulations on getting access to ORCA DAM! Whether you're uploading images for course materials, managing documents, or organizing media files for Studyflow, you've come to the right place.

### Why Does ORCA Exist?

Ever tried managing files directly on Amazon S3? It's like trying to organize a library where:

- There's no search function
- You can't add notes to books
- Anyone with access can accidentally delete important files
- There's no way to know who uploaded what
- Oops, you just made a file public that shouldn't be

**Not fun.**

That's exactly why we built ORCA. Think of it as a friendly reception desk in front of a massive warehouse:

```
┌─────────────────────────────────────────────────────────────┐
│                     SPARK/STUDYFLOW                         │
│                 (Your courses & content)                    │
│                             │                               │
│                             │ Search and                    │
│                             │ link to files                 │
│                             ▼                               │
│  ┌──────────────────────────────────────────────────────┐   │
│  │                                                      │   │
│  │                      ORCA DAM                        │   │
│  │                                                      │   │
│  │   * Search & filter        * User management         │   │
│  │   * AI-powered tagging     * Safe deletion           │   │
│  │   * Add & edit metadata    * Access control          │   │
│  │   * Organized folders      * Manage storage          │   │
│  │                                                      │   │
│  └──────────────────────────────────────────────────────┘   │
│                             │                               │
│                             │ managed access                │
│                             ▼                               │
│  ┌──────────────────────────────────────────────────────┐   │
│  │                                                      │   │
│  │                       AWS S3                         │   │
│  │                   (Cloud Storage)                    │   │
│  │                                                      │   │
│  │              Your actual files live here             │   │
│  │                                                      │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

ORCA sits between you and the raw cloud storage, making everything safer, searchable, and manageable.

---

## Before You Start: Important Things to Know

### The Golden Rules of ORCA

ORCA has some deliberate limitations. These aren't bugs — they're safety features!

#### 1. You Cannot Rename Files

Once a file is uploaded, its name is set in stone. Why? Because Studyflow and other systems link directly to these files using their exact URL. Renaming would break all those links instantly.

**What to do instead:** If you really need a different filename, you'll need to delete the old file and upload a new one with the correct name.

#### 2. You Cannot Move Files Between Folders

Same reason as above — moving a file changes its URL, breaking all existing links.

**What to do instead:** See the "Moving Files (The Long Way)" section below.

#### 3. Soft Delete is Your Safety Net

When you delete a file, it doesn't actually vanish into thin air. It goes to the Trash first (we call this "soft delete"). Only administrators can permanently delete files or restore them from the Trash.

This means: accidentally deleted something? Don't panic! Ask an admin to restore it.

---

## Getting Started

### The Dashboard

When you log in, you'll see your personal dashboard showing:

- **Total Assets** — All files in the system
- **My Assets** — Files you personally uploaded
- **Total Storage** — How much space is being used
- **Tags** — Count of user-created and AI-generated tags

*Admins see additional stats like total users and trashed items.*

### Your Role: Editor vs Admin

ORCA has two user roles with different capabilities:

| Feature | Editor | Admin |
|---------|:------:|:-----:|
| View all assets | ✓ | ✓ |
| Upload files | ✓ | ✓ |
| Edit asset details (alt text, caption, tags) | ✓ | ✓ |
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

---

## Uploading Files

### Choosing the Right Folder — This is Important!

Remember how we said you can't move files? This is why **choosing the correct folder before uploading is crucial**.

Think before you click that upload button:

- Where does this file belong?
- Will other team members know where to find it?
- Does a suitable folder already exist?

*Admins can create new folders if needed — just ask!*

### How to Upload

1. Click **Upload** in the navigation menu
2. Select your target folder from the dropdown
3. Either:
   - Drag and drop files onto the upload area, or
   - Click to browse and select files
4. Watch the progress bars — larger files may take a moment
5. Done! Your files are now in ORCA

**File size limit:** Up to 500MB per file. Larger files are automatically uploaded in chunks, so don't worry if your connection hiccups — it can resume where it left off.

### What Happens After Upload?

1. Your file is safely stored in AWS S3
2. A thumbnail is generated (for images)
3. If AI tagging is enabled, ORCA automatically analyzes images and suggests relevant tags
4. The file appears in your asset library, ready to use

---

## Browsing & Finding Assets

### The Assets Page

This is where you'll spend most of your time. You can view assets in two ways:

- **Grid View** — Visual thumbnails, great for images
- **List View** — Detailed table, better for managing lots of files

Toggle between them using the buttons in the top right.

### Searching & Filtering

**Search box:** Type any part of a filename to find it quickly.

**Filters available:**
- **File type** — Images, Videos, Documents
- **Folder** — Browse by folder location
- **Tags** — Filter by one or more tags (checkboxes let you select multiple)

**Sorting options:**
- Date (newest/oldest)
- Size (largest/smallest)
- Name (A-Z / Z-A)
- S3 key (the technical file path)

### Quick Actions

Hover over any asset to see action buttons:

- **👁 View** — See full details
- **📋 Copy URL** — Copy the public link to your clipboard (for pasting into Studyflow)
- **✏️ Edit** — Modify asset details
- **🗑 Delete** — Send to Trash

In List View, you can also edit tags and license info directly inline — no need to open the full edit page!

---

## Working with Tags

Tags help you organize and find assets. They're like labels you stick on files.

### Two Types of Tags

| Type | Icon | How Created |
|------|------|-------------|
| **User tags** | Blue badge | Added manually by you |
| **AI tags** | Purple badge | Generated automatically by artificial intelligence |

### Important Tag Rules

#### Tags Must Be Unique

You can't have two tags with the same name. This keeps things organized — imagine having three different "logo" tags!

#### Tag Type is Set at Birth

Here's something that trips people up: a tag's type (user or AI) is determined when it's **first created**.

**Example:**
1. AI analyzes an image and creates the tag "sunset" (type: AI)
2. Later, you manually add "sunset" to a different image
3. The tag is still type "AI" — because that's how it was first created

This doesn't really matter for day-to-day use, but it's good to know when you're looking at tag statistics.

### Adding Tags to Assets

**From the Edit page:**
1. Open any asset
2. Scroll to the Tags section
3. Type a tag name and press Enter
4. Repeat for more tags

**From List View (inline):**
1. Find the Tags column
2. Click the **+** button
3. Type your tag and press Enter or click Add

### Removing Tags

Click the **×** next to any tag to remove it from that asset. This doesn't delete the tag itself — it just removes the connection.

### A Warning About Tags and Trash

Here's a subtle but important thing:

> **Tags showing "0 assets" might not be truly empty!**

Why? Because they could still be attached to assets sitting in the Trash. You just can't see those trashed assets in the normal view.

**When restoring from Trash:**
- Tags that were still attached → **Preserved** ✓
- Tags you removed before restoring → **Gone forever** ✗

So if you delete an asset, then remove its tags, then restore it — those removed tags won't magically reappear.

---

## Editing Asset Details

Click on any asset or hit the Edit button to modify:

### Alt Text
A short description of the image for accessibility (screen readers use this). Keep it brief but descriptive.

*Example: "Student studying at a laptop in a library"*

### Caption
A longer description or credit line that might be displayed alongside the image.

### License Information

Track the usage rights for your assets:

- **License Type** — Public Domain, Creative Commons variants, Fair Use, All Rights Reserved
- **License Expiry Date** — When does the license run out? (leave empty if perpetual)
- **Copyright Holder** — Who owns the rights?
- **Copyright Source** — Link to where you found the licensing info

### Generating AI Tags

If AI tagging is enabled and you want fresh suggestions:
1. Open the Edit page for any image
2. Click **Generate AI Tags**
3. Wait a moment while the AI analyzes the image
4. New tags appear automatically!

*Note: This replaces any existing AI tags on that asset.*

---

## Replacing Assets

Sometimes you need to update a file without changing its URL. Maybe you uploaded a placeholder image while the final version was still being designed, or you need to fix a typo in a document. That's where **Asset Replace** comes in.

### Why Replace Instead of Re-upload?

Remember the Golden Rules? You can't rename or move files because it would break links. The same problem applies if you delete a file and upload a new one — you'd get a completely new URL.

**Asset Replace solves this:**
- The URL stays exactly the same
- All existing links in (published or draft) content continue to work
- All metadata (alt text, caption, tags, license info) is preserved
- Only the file itself changes

### How to Replace an Asset

1. Go to the **Edit** page for the asset you want to replace
2. Click the **Replace File** button (below the preview image)
3. On the Replace page, you'll see:
   - The current file preview and details
   - A drop zone for your new file
4. Drag and drop your replacement file, or click to browse
5. **Important:** The new file must have the same extension (e.g., you can't replace a `.jpg` with a `.png`)
6. Click **Replace File** and confirm the warning dialog
7. Wait for the upload to complete — you'll be redirected back to the Edit page

### The Draft/Placeholder Workflow

This is where Asset Replace really shines. Here's a common scenario:

1. **Create content early:** You're building a course in Studyflow, but the final images aren't ready yet
2. **Upload placeholders:** Upload temporary images with clear names like `hero-image-DRAFT.jpg`
3. **Link them in Studyflow:** Add the images to your content using the ORCA URLs
4. **Replace when ready:** When the final images arrive, simply replace the placeholders
5. **No broken links:** Studyflow automatically shows the new images!

### Tips for Using Drafts

**Tag your placeholders!** Add a tag like `draft` or `placeholder` to temporary uploads. This makes them easy to find later:

1. Filter by the `draft` tag to see all your placeholders
2. Replace each one with the final version
3. Remove the `draft` tag when done

**Use descriptive filenames:** Even for placeholders, name them clearly:
- `hero-section-DRAFT.jpg` ✓
- `temp1.jpg` ✗

**Keep a list:** For larger projects, maintain a simple checklist of placeholder files that need replacing.

### Important Warnings

#### Same Extension Required

You must replace a file with one of the same type:
- `.jpg` can only be replaced with `.jpg`
- `.pdf` can only be replaced with `.pdf`
- `.png` cannot replace `.jpg` (different format!)

If you need to change the file format, you'll have to delete and re-upload (which means updating all links).

#### The Previous Version is Lost

This is crucial to understand:

> **Without S3 versioning enabled, the original file is permanently deleted when you replace it.**

Ask your administrator if versioning is enabled on your S3 bucket. If it is, previous versions are kept and can potentially be recovered. If not, replacement is a one-way operation — there's no undo.

#### Thumbnail and Dimensions Update

When you replace an image:
- A new thumbnail is generated automatically
- The stored dimensions (width/height) update to match the new file
- The file size updates

This is expected behavior — the metadata should reflect the actual file.

---

## The Trash (Admin Only)

When files are deleted, they go to the Trash — a holding area before permanent deletion.

### Viewing the Trash

Admins can access the Trash from the navigation menu. Here you'll see all soft-deleted assets with options to:

- **Restore** — Bring the asset back to life
- **Delete Permanently** — Remove forever (this also deletes the actual file from S3)

### Why Soft Delete?

It's your safety net:
- Accidentally deleted something? Restore it!
- Need to audit what was removed? Check the Trash!
- Prevents the "oh no" moment of irreversible deletion

---

## Moving Files (The Long Way)

Since ORCA doesn't allow moving files directly (remember, it would break links!), here's the workaround:

1. **Download the file** to your computer first
2. **Soft delete** the original in ORCA
3. Ask an **admin to permanently delete** the trashed file
4. **Upload the file again** to the correct folder
5. **Update all links** in Studyflow to point to the new URL

Yes, it's tedious. That's intentional — it makes you think twice about whether you really need to move something, and reminds you to update those links.

---

## Discover Feature (Admin Only)

Sometimes files end up in S3 without going through ORCA (uploaded directly, migrated from another system, etc.).

The **Discover** feature lets admins:
1. Scan S3 for files that aren't in ORCA's database
2. Preview what was found
3. Import selected files into ORCA

Files that belong to trashed assets are shown with a red "Deleted" badge to prevent accidentally re-importing something that was intentionally removed.

---

## Export to CSV (Admin Only)

Need to analyze your asset library in a spreadsheet? Admins can export everything to CSV:

1. Go to Assets
2. Apply any filters you want (the export respects your current filter)
3. Click the **Export** button
4. Open the downloaded CSV in Excel, Google Sheets, or your preferred tool

The export includes:
- All file details (name, size, type, dimensions)
- Tags (user and AI in separate columns)
- License and copyright info
- Public URLs
- Who uploaded it and when

---

## Tips & Tricks

### Keyboard Shortcuts

- **Enter** — Confirm tag input
- **Escape** — Cancel current action

### Best Practices

1. **Name files clearly before uploading** — You can't rename them later!
2. **Use tags generously** — They make searching so much easier
3. **Fill in alt text** — It's good for accessibility and helps you remember what's in the image
4. **Choose folders wisely** — Think of the folder structure as permanent
5. **Check the Trash before asking "where did my file go?"** — Someone might have deleted it

### Getting Help

Stuck? Here's what to do:
- Check this manual first (you're already here!)
- Ask your admin for help with permissions or restoring files
- For technical issues, contact your system administrator

---

## User Preferences

You can customize ORCA to work the way you prefer. Access your preferences via the **Profile** page (click your name in the top-right menu).

### Available Preferences

#### Home Folder

Set your default starting folder when browsing assets. This is useful if you mostly work in a specific folder.

- When you visit the Assets page without specifying a folder, ORCA will automatically show your home folder
- The folder must be within the system's configured root folder
- Leave empty to use the default (root folder)

**Example:** If you always work with marketing assets, set your home folder to `assets/marketing`. Every time you go to Assets, you'll start there instead of the root.

#### Items Per Page

Set how many assets you want to see per page by default.

- Choose from: 12, 24, 36, 48, 60, 72, or 96
- Select "Use default" to follow the global system setting
- This affects both Grid and List views

**Note:** The "Results per page" dropdown on the Assets page still works — it overrides your preference for that session. Your preference is just the default when you first load the page.

### How Preferences Work

Preferences follow a hierarchy (highest priority first):

1. **URL parameters** — If you click a link with `?folder=assets/docs`, that wins
2. **Your user preference** — What you set in Profile → Preferences
3. **Global system setting** — The default configured by your admin

This means your preferences are respected, but you can still navigate freely — clicking a different folder or changing the results dropdown won't reset to your preferences until you load a fresh page.

### Setting Your Preferences

1. Click your name in the top-right corner
2. Select **Profile**
3. Scroll down to the **Preferences** section
4. Choose your preferred home folder from the dropdown
5. Choose your preferred items per page
6. Click **Save**

You'll see a "Saved" confirmation when successful.

**Tip:** Click the refresh icon (↻) next to the folder dropdown to reload the folder list if new folders were recently created.

---

## Glossary

| Term | Meaning |
|------|---------|
| **Asset** | Any file stored in ORCA (image, document, video, etc.) |
| **S3** | Amazon's cloud storage service where your files actually live |
| **Soft Delete** | Sending a file to Trash (recoverable) |
| **Hard/Permanent Delete** | Removing a file forever (not recoverable) |
| **AI Tags** | Tags automatically generated by artificial intelligence |
| **User Tags** | Tags manually added by people |
| **S3 Key** | The technical path/address of a file in cloud storage |
| **Thumbnail** | A small preview image generated for visual files |
| **Preferences** | Personal settings (like home folder) that customize your ORCA experience |
| **Rekognition** | Amazon's AI service that analyzes images and suggests tags |
| **Replace** | Uploading a new file to overwrite an existing asset while keeping the same URL |

---

## Need More Help?

If you're an admin looking for technical documentation, check out:
- `README.md` — General project overview
- `CLAUDE.md` — Technical architecture details
- `DEPLOYMENT.md` — Server setup instructions

---

*Happy asset managing! 🐋*

*— The ORCA Team*
