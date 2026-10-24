<?php

namespace SmartSyncS3\Services;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use SmartSyncS3\Models\SMARL_CloudConfig;

class SMARLI_WasabiBucketManager {
    // Static function to create a Wasabi bucket
    public static function createBucket(SMARL_CloudConfig $config, string $bucketName): array {
        if ($config->serviceProvider !== 'wasabi') {
            throw new \InvalidArgumentException('A Wasabi configuration is required.');
        }
        $s3Client = $config->getS3Client();

        try {
            // Create the bucket
            $s3Client->createBucket([
                'Bucket' => $bucketName,
            ]);

            // Wait until the bucket is created
            $s3Client->waitUntil('BucketExists', [
                'Bucket' => $bucketName,
            ]);

            return [
                "error" => false,
                "message"  => "wasabi bucket created successfully",
            ];
        } catch (\Throwable $e) {
            // Output error message if fails
            return [
                "error" => true,
                "message"  => "The Wasabi bucket could not be created. Check the bucket name, region, and permissions.",
            ];
        }
    }
}

