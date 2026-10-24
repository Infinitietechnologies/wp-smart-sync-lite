<?php

namespace SmartSyncS3\Helpers;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
class SMARL_AwsHelper
{
    public static function generateOACArray($bucketName): array
    {
        return [
            'OriginAccessControlConfig' => [
                'Name' => "$bucketName-oac-" . bin2hex(random_bytes(8)),
                'Description' => "OAC for $bucketName",
                'SigningProtocol' => 'sigv4',
                'SigningBehavior' => 'always',
                'OriginAccessControlOriginType' => 's3'
            ]
        ];
    }

    public static function createCloudfrontArray($bucketName, $bucketRegion, $oacId): array
    {
        return [
            'DistributionConfig' => [
                'CallerReference' => bin2hex(random_bytes(16)),
                'Comment' => "Distribution for $bucketName",
                'DefaultRootObject' => '',
                'Enabled' => true,
                'ViewerCertificate' => ['CloudFrontDefaultCertificate' => true],
                'Origins' => [
                    'Quantity' => 1,
                    'Items' => [
                        [
                            'Id' => 'S3-' . $bucketName,
                            'DomainName' => "$bucketName.s3.$bucketRegion.amazonaws.com",
                            'OriginAccessControlId' => $oacId,
                            'S3OriginConfig' => [
                                'OriginAccessIdentity' => ''
                            ]
                        ]
                    ]
                ],
                'DefaultCacheBehavior' => [
                    'TargetOriginId' => 'S3-' . $bucketName,
                    'ViewerProtocolPolicy' => 'redirect-to-https',
                    'AllowedMethods' => [
                        'Quantity' => 2,
                        'Items' => ['HEAD', 'GET'],
                        'CachedMethods' => [
                            'Quantity' => 2,
                            'Items' => ['HEAD', 'GET']
                        ]
                    ],
                    'Compress' => true,
                    'DefaultTTL' => 86400,
                    'MinTTL' => 0,
                    'MaxTTL' => 31536000,
                    'ForwardedValues' => [
                        'QueryString' => true,
                        'Cookies' => [
                            'Forward' => 'none'
                        ]
                    ]
                ]
            ]
        ];
    }
}
