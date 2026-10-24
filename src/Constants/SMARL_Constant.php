<?php

namespace SmartSyncS3\Constants;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/**
 * Class Constant
 * Defines global constants used across the SmartSyncS3 application.
 */

class SMARL_Constant
{
    /**
     * @var int $processBatchSize The default batch size for processing items.
     */
    public static int $processBatchSize = 15;

}
