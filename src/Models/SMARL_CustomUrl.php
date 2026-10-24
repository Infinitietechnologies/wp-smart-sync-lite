<?php

namespace SmartSyncS3\Models;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class SMARL_CustomUrl
{

    public string $certificateArn = "";

    public string $domain = "";

    public array $records = [];

    public static string $optionName = "custom_url_option";

    public function __construct(
        string $certificateArn = "",
        string $domain = "",
        array $records = [],

    ) {
        $this->certificateArn = $certificateArn;
        $this->domain = $domain;
        $this->records = $records;
    }

    public static function load(): SMARL_CustomUrl {
        $data = get_option(self::$optionName, []);

        return self::fromArray(is_array($data) ? $data : []);
    }

    public static function fromArray(array $data): SMARL_CustomUrl {

        return new SMARL_CustomUrl(
            certificateArn: is_string($data['certificate_arn'] ?? null) ? $data['certificate_arn'] : '',
            domain: is_string($data['domain'] ?? null) ? $data['domain'] : '',
            records: is_array($data['records'] ?? null) ? array_values(array_filter($data['records'], 'is_array')) : []
        );
    }

    public function reset(): void {
        $defaultInstance = new self();
        foreach (get_object_vars($defaultInstance) as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        $this->save();
    }


    public function save(): void {
        global $wpdb;
        $data = $this->toArray();
        update_option(self::$optionName, $data, false);
        $saved = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::$optionName)));
        if ($saved !== $data) throw new \SmartSyncS3\Exceptions\SMARL_ProviderException(__('Custom domain settings could not be saved. Check the WordPress database and try again.', 'smartsync-lite-media-offloader-and-assets-cdn'));
    }

    public function toArray(): array {


        return [
            'certificate_arn' => $this->certificateArn,
            'domain' => $this->domain,
            'records' => $this->records,
        ];
    }

}
