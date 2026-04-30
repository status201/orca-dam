<?php

namespace App\Console\Commands;

use App\Models\Tag;
use Illuminate\Console\Command;

class ReferenceTagCreateCommand extends Command
{
    protected $signature = 'reference-tag:create
                            {names?* : One or more tag names to create as reference tags}';

    protected $description = 'Create reference tag(s) so editors can attach them to assets afterwards';

    public function handle(): int
    {
        $names = (array) $this->argument('names');

        if (empty($names)) {
            $input = $this->ask('Enter a reference tag name to create');
            if ($input === null || trim($input) === '') {
                $this->error('No tag name provided.');

                return Command::FAILURE;
            }
            $names = [$input];
        }

        $normalized = collect($names)
            ->map(fn ($n) => strtolower(trim((string) $n)))
            ->filter(fn ($n) => $n !== '')
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            $this->error('No valid tag names provided.');

            return Command::FAILURE;
        }

        $existing = Tag::whereIn('name', $normalized)->get(['id', 'name', 'type'])->keyBy('name');

        $rows = [];
        $created = 0;
        $skippedCollision = 0;

        foreach ($normalized as $name) {
            $existingTag = $existing->get($name);

            if ($existingTag === null) {
                Tag::create(['name' => $name, 'type' => 'reference']);
                $rows[] = [$name, '<fg=green>Created</>'];
                $created++;

                continue;
            }

            if ($existingTag->type === 'reference') {
                $rows[] = [$name, 'Already exists'];

                continue;
            }

            $rows[] = [$name, '<fg=red>SKIPPED (existing '.$existingTag->type.' tag)</>'];
            $this->warn(sprintf(
                '"%s" already exists with type=%s — skipped. Pick a different name (e.g. "published-%s") or delete the existing tag first.',
                $name,
                $existingTag->type,
                $name,
            ));
            $skippedCollision++;
        }

        $this->newLine();
        $this->table(['Name', 'Status'], $rows);
        $this->newLine();

        if ($created > 0) {
            $this->info(sprintf('%d reference tag(s) created.', $created));
        }

        if ($skippedCollision === count($normalized)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
