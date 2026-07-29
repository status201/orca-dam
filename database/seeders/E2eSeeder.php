<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Passkey;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fixtures for the Playwright browser suite (specs/features/e2e-testing.md).
 *
 * Only ever run against the e2e environment — it wipes and rebuilds everything
 * it touches, and `npm run e2e:reset` calls it with `migrate:fresh`.
 *
 * Assets are seeded DB-only: their S3 objects do not exist, which is fine
 * because thumbnails are never asserted on for seeded rows (specs that need real
 * bytes upload them and are guarded by `requiresS3()`). Names are namespaced per
 * spec area (`e2e-<area>-NN`) so no two spec files compete for a row.
 */
class E2eSeeder extends Seeder
{
    /** Password shared by every seeded user. */
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment(['e2e', 'testing', 'local'])) {
            $this->command?->error('E2eSeeder refuses to run in '.app()->environment().'.');

            return;
        }

        $admin = $this->user('Admin E2E', 'admin@e2e.test', 'admin');
        $editor = $this->user('Editor E2E', 'editor@e2e.test', 'editor');
        $api = $this->user('Api E2E', 'api@e2e.test', 'api');
        // A second editor exists so user-management specs have someone safe to
        // re-role and delete without touching the accounts the suite logs in as.
        $this->user('Spare Editor', 'spare@e2e.test', 'editor');

        $this->settings();
        $this->tags();
        $this->assets($editor, $admin);
        // AssetApiController::update refuses a non-admin editing someone else's
        // asset, so the api role needs one of its own to exercise the happy path.
        $this->image('e2e-api-owned-01.png', $api);
        // Passkeys belong to the editor so the admin profile keeps its empty
        // state — the passkey spec asserts both, and each needs a clean account.
        $this->passkeys($editor);
        $this->tokens($admin, $editor, $api);
    }

    private function user(string $name, string $email, string $role): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'role' => $role,
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ]
        );
    }

    /**
     * The runtime settings the UI reads. Values are deliberately explicit rather
     * than inherited from the migrations' defaults, so a default change can't
     * quietly alter what the suite asserts.
     */
    private function settings(): void
    {
        $settings = [
            ['items_per_page', '24', 'integer', 'general'],
            ['timezone', 'Europe/Amsterdam', 'string', 'general'],
            ['locale', 'en', 'string', 'general'],
            ['s3_root_folder', 'assets', 'string', 's3'],
            ['s3_folders', json_encode(['assets', 'assets/e2e', 'assets/archive']), 'json', 's3'],
            ['maintenance_mode', '0', 'boolean', 'general'],
            ['custom_domain', '', 'string', 's3'],
            ['cloudflare_cache_purge', '0', 'boolean', 's3'],
            ['embed_allowed_domains', json_encode(['127.0.0.1:8100', 'localhost:8100']), 'json', 'embed'],
            ['api_upload_enabled', '1', 'boolean', 'api'],
            ['api_meta_endpoint_enabled', '1', 'boolean', 'api'],
            ['jwt_enabled_override', '1', 'boolean', 'api'],
            ['resize_s_width', '400', 'integer', 'images'],
            ['resize_s_height', '400', 'integer', 'images'],
            ['resize_m_width', '800', 'integer', 'images'],
            ['resize_m_height', '800', 'integer', 'images'],
            ['resize_l_width', '1600', 'integer', 'images'],
            ['resize_l_height', '1600', 'integer', 'images'],
            ['rekognition_max_labels', '3', 'integer', 'ai'],
            ['rekognition_min_confidence', '80', 'integer', 'ai'],
            ['rekognition_language', 'nl', 'string', 'ai'],
        ];

        foreach ($settings as [$key, $value, $type, $group]) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'type' => $type, 'group' => $group]
            );
        }
    }

    private function tags(): void
    {
        foreach (['e2e-shared', 'e2e-alpha', 'e2e-rename-me', 'e2e-delete-me'] as $name) {
            Tag::updateOrCreate(['name' => $name], ['type' => 'user']);
        }

        Tag::updateOrCreate(['name' => 'e2e-ai-tag'], ['type' => 'ai']);
        Tag::updateOrCreate(['name' => 'e2e-reference-tag'], ['type' => 'reference']);
    }

    private function assets(User $editor, User $admin): void
    {
        // Grid / search / sort / pagination fixtures. Sizes and timestamps ascend
        // with the index so sort assertions have a deterministic first row.
        $grid = $this->imageSet('e2e-grid', 14, $editor);

        $grid->first()->tags()->syncWithoutDetaching(
            Tag::whereIn('name', ['e2e-shared', 'e2e-alpha'])->pluck('id')
        );
        $grid->get(1)?->tags()->syncWithoutDetaching(Tag::where('name', 'e2e-shared')->pluck('id'));

        // Detail / edit fixtures.
        $this->image('e2e-detail-alpha.png', $editor, [
            'alt_text' => 'Seeded alt text',
            'caption' => 'Seeded caption',
            'license_type' => 'cc_by',
            'copyright' => 'ORCA E2E',
        ]);
        $this->image('e2e-detail-beta.png', $admin);

        // Trash lifecycle: -04 starts soft-deleted so trash has a row before any
        // test deletes anything.
        $trash = $this->imageSet('e2e-trash', 4, $editor);
        $trash->last()->delete();

        // Bulk bar fixtures.
        $this->imageSet('e2e-bulk', 6, $editor);

        // Embed fixture + the two non-image types the type filter needs.
        $this->image('e2e-embed-01.png', $editor);

        // CSV import fixtures. alt_text/caption stay null so an import has
        // something to write; -02 carries a tag to prove import only ever adds
        // tags, never removes them; -03 is never named in a CSV, so it pins the
        // "not matched" count.
        //
        // That tag is its own, not `e2e-shared`: the grid and tags specs both assert
        // exactly two assets carry the shared one.
        $import = $this->imageSet('e2e-import', 3, $editor);
        $importTag = Tag::updateOrCreate(['name' => 'e2e-import-existing'], ['type' => 'user']);
        $import->get(1)?->tags()->syncWithoutDetaching([$importTag->id]);

        // CSV export: a tag used by exactly one asset, so a tag-filtered export
        // has an exact expected row count no matter what else is seeded.
        $exportTag = Tag::updateOrCreate(['name' => 'e2e-export-only'], ['type' => 'user']);
        $this->image('e2e-export-01.png', $editor)
            ->tags()->syncWithoutDetaching([$exportTag->id]);

        // Replace fixtures: -01 is consumed by the successful replace (its
        // filename/size/etag change), -02 is only ever rejected, so it stays clean.
        $this->imageSet('e2e-replace', 2, $editor);

        // Discovery needs an object in the bucket with no row here, which no seeder
        // can arrange — discover.spec.js makes one at runtime.

        Asset::create([
            's3_key' => 'assets/e2e/e2e-doc-01.pdf',
            'filename' => 'e2e-doc-01.pdf',
            'mime_type' => 'application/pdf',
            'size' => 245_678,
            'etag' => Str::random(32),
            'user_id' => $editor->id,
        ]);

        Asset::create([
            's3_key' => 'assets/e2e/e2e-video-01.mp4',
            'filename' => 'e2e-video-01.mp4',
            'mime_type' => 'video/mp4',
            'size' => 3_456_789,
            'etag' => Str::random(32),
            'user_id' => $editor->id,
        ]);
    }

    /** @return Collection<int, Asset> */
    private function imageSet(string $prefix, int $count, User $owner): Collection
    {
        return collect(range(1, $count))->map(fn (int $i) => $this->image(
            sprintf('%s-%02d.png', $prefix, $i),
            $owner,
            [
                'size' => 10_000 * $i,
                'created_at' => now()->subDays($count - $i),
                'updated_at' => now()->subDays($count - $i),
            ]
        ));
    }

    private function image(string $filename, User $owner, array $attributes = []): Asset
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        return Asset::create(array_merge([
            's3_key' => "assets/e2e/{$filename}",
            'filename' => $filename,
            'mime_type' => 'image/png',
            'size' => 51_200,
            'etag' => Str::random(32),
            'width' => 800,
            'height' => 600,
            'thumbnail_s3_key' => "thumbnails/e2e/{$base}_thumb.jpg",
            'resize_s_s3_key' => "thumbnails/S/e2e/{$base}.png",
            'resize_m_s3_key' => "thumbnails/M/e2e/{$base}.png",
            'resize_l_s3_key' => "thumbnails/L/e2e/{$base}.png",
            'user_id' => $owner->id,
        ], $attributes));
    }

    /**
     * Two passkeys so the management UI can be driven without a WebAuthn
     * ceremony: one to rename, one to delete, so neither test consumes the
     * other's fixture. The `credential` payload is deliberately empty — a real
     * ceremony writes a CredentialRecord there, but nothing short of signing in
     * reads it, and the suite never signs in with a passkey (it has no virtual
     * authenticator). Same shape as tests/Feature/PasskeyTest.php's helper.
     */
    private function passkeys(User $owner): void
    {
        foreach (['e2e-passkey-rename', 'e2e-passkey-delete'] as $name) {
            Passkey::forceCreate([
                'user_id' => $owner->getKey(),
                'name' => $name,
                'credential_id' => 'e2e-'.hash('sha256', $name.$owner->getKey()),
                'credential' => [],
            ]);
        }
    }

    /**
     * Sanctum tokens for the specs that assert API behaviour through Playwright's
     * request context. Written to disk because the plaintext exists only once, at
     * creation time.
     */
    private function tokens(User $admin, User $editor, User $api): void
    {
        $tokens = [
            'admin' => $admin->createToken('e2e')->plainTextToken,
            'editor' => $editor->createToken('e2e')->plainTextToken,
            'api' => $api->createToken('e2e')->plainTextToken,
        ];

        $dir = storage_path('e2e');
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($dir.'/tokens.json', json_encode($tokens, JSON_PRETTY_PRINT));
    }
}
