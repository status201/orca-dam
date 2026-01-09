<?php

namespace App\Services;

use Aws\Rekognition\RekognitionClient;
use Aws\Translate\TranslateClient;
use App\Models\Tag;

class RekognitionService
{
    protected RekognitionClient $rekognitionClient;
    protected ?TranslateClient $translateClient = null;
    protected string $bucket;
    protected bool $enabled;
    protected string $targetLanguage;

    public function __construct()
    {
        $this->bucket = config('filesystems.disks.s3.bucket');
        $this->enabled = config('services.aws.rekognition_enabled', false);
        $this->targetLanguage = config('services.aws.rekognition_language', 'en');

        if ($this->enabled) {
            $awsConfig = [
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ];

            $this->rekognitionClient = new RekognitionClient($awsConfig);

            // Initialize Translate client if language is not English
            if ($this->targetLanguage !== 'en') {
                $this->translateClient = new TranslateClient($awsConfig);
            }
        }
    }

    /**
     * Detect labels (tags) in an image using AWS Rekognition
     */
    public function detectLabels(string $s3Key, float $minConfidence = 75.0): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $maxLabels = (int) config('services.aws.rekognition_max_labels', 5);

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
                if ($this->targetLanguage !== 'en') {
                    $labelName = $this->translateText($labelName);
                }

                $labels[] = [
                    'name' => strtolower($labelName),
                    'confidence' => $label['Confidence'],
                ];
            }

            return $labels;
        } catch (\Exception $e) {
            \Log::error('Rekognition detectLabels failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect text in an image using AWS Rekognition
     */
    public function detectText(string $s3Key): array
    {
        if (!$this->enabled) {
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
            \Log::error('Rekognition detectText failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Auto-tag an asset with AI-detected labels
     */
    public function autoTagAsset(\App\Models\Asset $asset): array
    {
        if (!$asset->isImage()) {
            return [];
        }

        $labels = $this->detectLabels($asset->s3_key);
        $tagIds = [];

        foreach ($labels as $label) {
            // Create or find tag
            $tag = Tag::firstOrCreate(
                ['name' => $label['name']],
                ['type' => 'ai']
            );

            $tagIds[] = $tag->id;
        }

        // Attach tags to asset (only AI tags, don't remove user tags)
        if (!empty($tagIds)) {
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
    protected function translateText(string $text): string
    {
        if (!$this->translateClient) {
            return $text;
        }

        try {
            $result = $this->translateClient->translateText([
                'SourceLanguageCode' => 'en',
                'TargetLanguageCode' => $this->targetLanguage,
                'Text' => $text,
            ]);

            return $result['TranslatedText'] ?? $text;
        } catch (\Exception $e) {
            \Log::error('AWS Translate failed: ' . $e->getMessage());
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
