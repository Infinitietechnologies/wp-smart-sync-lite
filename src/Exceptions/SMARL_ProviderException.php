<?php
namespace SmartSyncS3\Exceptions;
if (!defined('ABSPATH')) exit;

/** A deliberately credential-free provider error that may be shown to an administrator. */
class SMARL_ProviderException extends \RuntimeException {}
