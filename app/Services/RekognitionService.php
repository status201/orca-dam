<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Tag;
use Aws\Rekognition\RekognitionClient;
use Aws\Translate\TranslateClient;

class RekognitionService
{
    protected RekognitionClient $rekognitionClient;

    protected ?TranslateClient $translateClient = null;

    protected string $bucket;

    protected bool $enabled;

    protected array $awsConfig;

    public function __construct()
    {
        $this->bucket = config('filesystems.disks.s3.bucket');
        $this->enabled = config('services.aws.rekognition_enabled', false);

        if ($this->enabled) {
            $this->awsConfig = [
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ];

            $this->rekognitionClient = new RekognitionClient($this->awsConfig);
        }
    }

    /**
     * Get the target language from settings or config
     */
    protected function getTargetLanguage(): string
    {
        return Setting::get('rekognition_language', config('services.aws.rekognition_language', 'nl'));
    }

    /**
     * Get the max labels from settings or config
     */
    protected function getMaxLabels(): int
    {
        return (int) Setting::get('rekognition_max_labels', config('services.aws.rekognition_max_labels', 3));
    }

    /**
     * Get the minimum confidence from settings or config
     */
    protected function getMinConfidence(): float
    {
        return (float) Setting::get('rekognition_min_confidence', config('services.aws.rekognition_min_confidence', 80.0));
    }

    /**
     * Get or initialize the translate client
     */
    protected function getTranslateClient(): ?TranslateClient
    {
        $targetLanguage = $this->getTargetLanguage();

        if ($targetLanguage === 'en') {
            return null;
        }

        if ($this->translateClient === null) {
            $this->translateClient = new TranslateClient($this->awsConfig);
        }

        return $this->translateClient;
    }

    /**
     * Detect labels (tags) in an image using AWS Rekognition
     */
    public function detectLabels(string $s3Key, ?float $minConfidence = null): array
    {
        if (! $this->enabled) {
            return [];
        }

        try {
            $maxLabels = $this->getMaxLabels();
            $targetLanguage = $this->getTargetLanguage();
            $minConfidence = $minConfidence ?? $this->getMinConfidence();

            $result = $this->rekognitionClient->detectLabels([
                'Image' => [
                    'S3Object' => [
                        'Bucket' => $this->bucket,
                        'Name' => $s3Key,
                    ],
                ],
                'MaxLabels' => $maxLabels,
                'MinConfidence' => $minConfidence,
            ]);

            $labels = [];
            foreach ($result['Labels'] as $label) {
                $labelName = $label['Name'];

                // Translate label if target language is not English
                if ($targetLanguage !== 'en') {
                    $labelName = $this->translateText($labelName, $targetLanguage);
                }

                $labels[] = [
                    'name' => strtolower($labelName),
                    'confidence' => $label['Confidence'],
                ];
                \Log::info('AWS Rekognition detected label: '.$label['Name'].' -> '.$label['Confidence']);
            }

            return $labels;
        } catch (\Exception $e) {
            \Log::error('Rekognition detectLabels failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Detect text in an image using AWS Rekognition
     */
    public function detectText(string $s3Key): array
    {
        if (! $this->enabled) {
            return [];
        }

        try {
            $result = $this->rekognitionClient->detectText([
                'Image' => [
                    'S3Object' => [
                        'Bucket' => $this->bucket,
                        'Name' => $s3Key,
                    ],
                ],
            ]);

            $texts = [];
            foreach ($result['TextDetections'] as $text) {
                if ($text['Type'] === 'LINE' && $text['Confidence'] > 80) {
                    $texts[] = $text['DetectedText'];
                }
            }

            return $texts;
        } catch (\Exception $e) {
            \Log::error('Rekognition detectText failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Auto-tag an asset with AI-detected labels
     */
    public function autoTagAsset(\App\Models\Asset $asset): array
    {
        if (! $asset->isImage()) {
            return [];
        }

        $labels = $this->detectLabels($asset->s3_key);
        $tagIds = [];

        foreach ($labels as $label) {
            // Create or find tag
            $tag = Tag::firstOrNew(['name' => $label['name']]);
            if (! $tag->exists) {
                $tag->type = 'ai';
                $tag->save();
            }

            $tagIds[] = $tag->id;
        }

        // Attach tags to asset (only AI tags, don't remove user tags)
        if (! empty($tagIds)) {
            // Get existing tag IDs
            $existingTagIds = $asset->tags()->pluck('tags.id')->toArray();

            // Merge with new AI tags (remove duplicates)
            $allTagIds = array_unique(array_merge($existingTagIds, $tagIds));

            // Sync all tags
            $asset->tags()->sync($allTagIds);
        }

        return $labels;
    }

    /**
     * Translate text from English to target language using AWS Translate
     */
    protected function translateText(string $text, string $targetLanguage): string
    {
        $translateClient = $this->getTranslateClient();

        if (! $translateClient) {
            return $text;
        }

        try {
            $result = $translateClient->translateText([
                'SourceLanguageCode' => 'en',
                'TargetLanguageCode' => $targetLanguage,
                'Text' => $text,
            ]);

            return $result['TranslatedText'] ?? $text;
        } catch (\Exception $e) {
            \Log::error('AWS Translate failed: '.$e->getMessage());

            return $text; // Return original text if translation fails
        }
    }

    /**
     * Check if Rekognition is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
