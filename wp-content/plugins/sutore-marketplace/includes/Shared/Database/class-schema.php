<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Database;

final class Schema
{
    public const VERSION = 102;

    public static function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sutore_marketplace_' . $suffix;
    }

    public static function install(): void
    {
        $lockKey = 'sutore_marketplace_schema_install_lock';
        if (get_transient($lockKey)) {
            return;
        }
        set_transient($lockKey, 1, 5 * MINUTE_IN_SECONDS);

        try {
            self::installUnlocked();
        } finally {
            delete_transient($lockKey);
        }
    }

    private static function installUnlocked(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $listings = self::table('listings');
        $conditions = self::table('listing_conditions');
        $events = self::table('listing_events');
        $restrictions = self::table('merchant_restrictions');
        $taskDefs = self::table('task_definitions');
        $taskProgress = self::table('merchant_task_progress');
        $rewards = self::table('merchant_rewards');
        $campaigns = self::table('campaigns');
        $campaignOffers = self::table('campaign_offers');
        $payoutLines = self::table('merchant_payout_lines');
        $notifications = self::table('merchant_notifications');
        $merchantProfiles = self::table('merchant_profiles');
        $merchantEvents = self::table('merchant_events');
        $commissionOverrides = self::table('merchant_commission_overrides');
        $catalogRequests = self::table('catalog_product_requests');
        $outletWindows = self::table('outlet_windows');
        $outletItems = self::table('outlet_items');
        $outletOptins = self::table('outlet_optins');
        $customerOffers = self::table('customer_offers');
        $offerDailyCounters = self::table('customer_offer_daily_counters');
        $invoices = self::table('invoices');

        $sql = [];

        $sql[] = "CREATE TABLE {$merchantProfiles} (
            user_id bigint(20) unsigned NOT NULL,
            account_name varchar(191) NOT NULL DEFAULT '',
            account_lastname varchar(191) NOT NULL DEFAULT '',
            account_iban varchar(255) NOT NULL DEFAULT '',
            account_tckno varchar(255) NOT NULL DEFAULT '',
            account_birth_year varchar(8) NOT NULL DEFAULT '',
            account_email varchar(191) NOT NULL DEFAULT '',
            account_phone varchar(32) NOT NULL DEFAULT '',
            account_city varchar(32) NOT NULL DEFAULT '',
            account_state varchar(191) NOT NULL DEFAULT '',
            tckno_verified tinyint(1) NOT NULL DEFAULT 0,
            tckno_verified_at bigint(20) unsigned NOT NULL DEFAULT 0,
            tckno_verify_method varchar(32) NOT NULL DEFAULT '',
            marketing_consent tinyint(1) NOT NULL DEFAULT 0,
            merchant_status varchar(32) NOT NULL DEFAULT 'normal',
            behavior_score decimal(3,2) NOT NULL DEFAULT 5.00,
            behavior_summary_key varchar(64) NOT NULL DEFAULT 'no_sales_yet',
            score_computed_at datetime NULL,
            referral_code varchar(16) NULL,
            referred_by_user_id bigint(20) unsigned NULL,
            referral_rewarded_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (user_id),
            KEY account_phone (account_phone),
            KEY merchant_status (merchant_status),
            KEY tckno_verified (tckno_verified),
            UNIQUE KEY referral_code (referral_code),
            KEY referred_by (referred_by_user_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$campaigns} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'draft',
            source varchar(16) NOT NULL DEFAULT 'admin',
            seller_discount_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            seller_discount_type varchar(16) NOT NULL DEFAULT 'fixed',
            platform_discount_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            platform_discount_type varchar(16) NOT NULL DEFAULT 'fixed',
            starts_at datetime NULL,
            ends_at datetime NULL,
            targeting longtext NULL,
            notes text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY ends_at (ends_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$campaignOffers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            variation_id bigint(20) unsigned NOT NULL,
            merchant_id bigint(20) unsigned NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            asking_before decimal(12,2) NOT NULL DEFAULT 0.00,
            seller_discount_type varchar(16) NOT NULL DEFAULT 'fixed',
            seller_discount_value decimal(12,2) NOT NULL DEFAULT 0.00,
            seller_discount decimal(12,2) NOT NULL DEFAULT 0.00,
            platform_discount_type varchar(16) NOT NULL DEFAULT 'fixed',
            platform_discount_value decimal(12,2) NOT NULL DEFAULT 0.00,
            platform_discount decimal(12,2) NOT NULL DEFAULT 0.00,
            compare_regular decimal(12,2) NULL,
            customer_sale decimal(12,2) NULL,
            responded_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY campaign_variation (campaign_id, variation_id),
            KEY variation_id (variation_id),
            KEY merchant_status (merchant_id, status),
            KEY campaign_status (campaign_id, status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$listings} (
            variation_id bigint(20) unsigned NOT NULL,
            parent_product_id bigint(20) unsigned NOT NULL,
            size_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
            merchant_id bigint(20) unsigned NOT NULL,
            listing_status varchar(32) NOT NULL DEFAULT 'pending',
            asking decimal(12,2) NOT NULL DEFAULT 0.00,
            commission_percent decimal(5,2) NULL,
            sale_commission_percent decimal(5,2) NULL,
            condition_fingerprint varchar(64) NOT NULL DEFAULT '',
            campaign_status varchar(32) NOT NULL DEFAULT 'none',
            campaign_id bigint(20) unsigned NULL,
            campaign_cooled_until datetime NULL,
            campaign_aging_step tinyint unsigned NOT NULL DEFAULT 0,
            expire_at datetime NULL,
            listing_duration_days smallint unsigned NOT NULL DEFAULT 45,
            sold_at datetime NULL,
            order_id bigint(20) unsigned NULL,
            order_item_id bigint(20) unsigned NULL,
            order_shipment_type varchar(32) NULL,
            order_shipment_deadline_at datetime NULL,
            fast_shipment tinyint(1) NOT NULL DEFAULT 0,
            has_invoice tinyint(1) NOT NULL DEFAULT 0,
            is_imported tinyint(1) NOT NULL DEFAULT 0,
            product_desc text NULL,
            is_winner tinyint(1) NOT NULL DEFAULT 0,
            confirm_deadline_at datetime NULL,
            seller_confirmed_at datetime NULL,
            cargo_deadline_at datetime NULL,
            merchant_shipped_at datetime NULL,
            merchant_shipment_code varchar(64) NULL,
            sutore_shipment_code varchar(64) NULL,
            sutore_shipped_at datetime NULL,
            merchant_snapshot longtext NULL,
            confirm_notice_sent tinyint(1) NOT NULL DEFAULT 0,
            confirm_punished tinyint(1) NOT NULL DEFAULT 0,
            cargo_notice_sent tinyint(1) NOT NULL DEFAULT 0,
            cargo_expired_flag tinyint(1) NOT NULL DEFAULT 0,
            delivered_at datetime NULL,
            return_window_ends_at datetime NULL,
            notes text NULL,
            last_operation_id varchar(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (variation_id),
            KEY parent_size_status (parent_product_id, size_term_id, listing_status),
            KEY merchant_status (merchant_id, listing_status),
            KEY expire_at (expire_at),
            KEY condition_fingerprint (condition_fingerprint),
            KEY is_winner (is_winner),
            KEY order_id (order_id),
            KEY order_shipment_type (order_shipment_type),
            KEY order_shipment_deadline (order_shipment_deadline_at),
            KEY is_imported (is_imported),
            KEY status_confirm_deadline (listing_status, confirm_deadline_at),
            KEY status_cargo_deadline (listing_status, cargo_deadline_at),
            KEY status_return_window (listing_status, return_window_ends_at),
            KEY campaign_status_id (campaign_status, campaign_id),
            KEY campaign_cooled_until (campaign_cooled_until),
            KEY parent_winner_status (parent_product_id, is_winner, listing_status),
            KEY last_operation_id (last_operation_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$conditions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            variation_id bigint(20) unsigned NOT NULL,
            condition_key varchar(64) NOT NULL,
            condition_value tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY variation_condition (variation_id, condition_key),
            KEY variation_id (variation_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            variation_id bigint(20) unsigned NULL,
            merchant_id bigint(20) unsigned NULL,
            event_type varchar(64) NOT NULL,
            visibility varchar(32) NOT NULL DEFAULT 'admin_only',
            payload longtext NULL,
            reverses_event_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY variation_id (variation_id),
            KEY variation_created (variation_id, created_at),
            KEY event_type (event_type),
            KEY merchant_id (merchant_id),
            KEY created_at (created_at),
            KEY reverses_event_id (reverses_event_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$restrictions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            merchant_id bigint(20) unsigned NOT NULL,
            restriction_key varchar(64) NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            reason text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            expires_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY merchant_key (merchant_id, restriction_key, is_active)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$taskDefs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_key varchar(64) NOT NULL,
            title varchar(191) NOT NULL,
            description text NULL,
            target_count int(11) NOT NULL DEFAULT 1,
            reward_type varchar(64) NOT NULL DEFAULT 'none',
            reward_value decimal(12,2) NOT NULL DEFAULT 0.00,
            reward_duration_days int(11) NOT NULL DEFAULT 0,
            card_family varchar(32) NOT NULL DEFAULT 'growth',
            template_key varchar(64) NOT NULL DEFAULT '',
            template_params longtext NULL,
            period_key varchar(16) NOT NULL DEFAULT '',
            merchant_id bigint(20) unsigned NOT NULL DEFAULT 0,
            is_template tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY task_key (task_key),
            KEY merchant_period (merchant_id, period_key),
            KEY template_active (template_key, is_template, is_active)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$taskProgress} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            merchant_id bigint(20) unsigned NOT NULL,
            task_id bigint(20) unsigned NOT NULL,
            progress_count int(11) NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT 'in_progress',
            completed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY merchant_task (merchant_id, task_id),
            KEY status (status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$rewards} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            merchant_id bigint(20) unsigned NOT NULL,
            task_id bigint(20) unsigned NULL,
            reward_type varchar(64) NOT NULL,
            reward_value decimal(12,2) NOT NULL DEFAULT 0.00,
            note text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY merchant_id (merchant_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$payoutLines} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            variation_id bigint(20) unsigned NOT NULL,
            parent_product_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            merchant_id bigint(20) unsigned NOT NULL,
            product_title varchar(255) NOT NULL DEFAULT '',
            gross_asking decimal(12,2) NOT NULL DEFAULT 0.00,
            commission_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            commission_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            hizmet_fee decimal(12,2) NOT NULL DEFAULT 0.00,
            guvence_fee decimal(12,2) NOT NULL DEFAULT 0.00,
            extra_deduction decimal(12,2) NOT NULL DEFAULT 0.00,
            net_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            payout_status varchar(32) NOT NULL DEFAULT 'pending',
            scheduled_payout_date date NULL,
            paid_at datetime NULL,
            paid_by bigint(20) unsigned NULL,
            payment_ref varchar(191) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY variation_id (variation_id),
            KEY merchant_status (merchant_id, payout_status),
            KEY payout_due (payout_status, scheduled_payout_date),
            KEY order_id (order_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$notifications} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            type varchar(64) NOT NULL,
            category varchar(32) NOT NULL,
            title varchar(255) NOT NULL,
            body text NULL,
            payload longtext NULL,
            entity_type varchar(32) NULL,
            entity_id bigint(20) unsigned NULL,
            variation_id bigint(20) unsigned NULL,
            dedupe_key varchar(128) NULL,
            read_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_unread (user_id, read_at, created_at),
            KEY user_feed (user_id, created_at),
            KEY variation_id (variation_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$merchantEvents} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            merchant_id bigint(20) unsigned NOT NULL,
            event_type varchar(64) NOT NULL,
            visibility varchar(32) NOT NULL DEFAULT 'admin_only',
            payload longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY merchant_id (merchant_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$commissionOverrides} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            merchant_id bigint(20) unsigned NOT NULL,
            commission_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            adjustment varchar(32) NOT NULL DEFAULT 'absolute',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            starts_at datetime NULL,
            expires_at datetime NULL,
            source varchar(32) NOT NULL DEFAULT 'staff',
            task_id bigint(20) unsigned NULL,
            reward_id bigint(20) unsigned NULL,
            note text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY merchant_active_expires (merchant_id, is_active, expires_at),
            KEY merchant_active_window (merchant_id, is_active, starts_at, expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$catalogRequests} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            merchant_id bigint(20) unsigned NOT NULL,
            sku_or_link varchar(500) NOT NULL DEFAULT '',
            size_note varchar(80) NOT NULL DEFAULT '',
            note text NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            resolved_parent_product_id bigint(20) unsigned NULL,
            resolved_by bigint(20) unsigned NULL,
            resolved_at datetime NULL,
            staff_note text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY merchant_status (merchant_id, status),
            KEY status_created (status, created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$outletWindows} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'draft',
            starts_at datetime NOT NULL,
            ends_at datetime NOT NULL,
            notes text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY starts_at (starts_at),
            KEY ends_at (ends_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$outletItems} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            window_id bigint(20) unsigned NOT NULL,
            parent_product_id bigint(20) unsigned NOT NULL,
            size_term_id bigint(20) unsigned NOT NULL,
            customer_sale decimal(12,2) NOT NULL DEFAULT 0.00,
            seller_net decimal(12,2) NOT NULL DEFAULT 0.00,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY window_product_size (window_id, parent_product_id, size_term_id),
            KEY window_id (window_id),
            KEY parent_size (parent_product_id, size_term_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$outletOptins} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            item_id bigint(20) unsigned NOT NULL,
            merchant_id bigint(20) unsigned NOT NULL,
            variation_id bigint(20) unsigned NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY item_merchant (item_id, merchant_id),
            UNIQUE KEY variation_id (variation_id),
            KEY merchant_status (merchant_id, status),
            KEY item_status (item_id, status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$customerOffers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL,
            listing_id bigint(20) unsigned NOT NULL,
            parent_product_id bigint(20) unsigned NOT NULL,
            size_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
            merchant_id bigint(20) unsigned NOT NULL,
            bid_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            asking_at_offer decimal(12,2) NOT NULL DEFAULT 0.00,
            status varchar(32) NOT NULL DEFAULT 'pending',
            expires_at datetime NULL,
            coupon_id bigint(20) unsigned NULL,
            coupon_code varchar(64) NOT NULL DEFAULT '',
            origin_offer_id bigint(20) unsigned NULL,
            responded_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY customer_status (customer_id, status),
            KEY merchant_status (merchant_id, status),
            KEY listing_status (listing_id, status),
            KEY parent_size_status (parent_product_id, size_term_id, status),
            KEY expires_at (expires_at),
            KEY origin_offer_id (origin_offer_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$offerDailyCounters} (
            customer_id bigint(20) unsigned NOT NULL,
            day_ymd date NOT NULL,
            offer_count int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (customer_id, day_ymd)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$invoices} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(32) NOT NULL,
            variation_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            merchant_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT 'queued',
            hizmet_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            guvence_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            commission_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            total_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            line_items longtext NULL,
            recipient_email varchar(191) NOT NULL DEFAULT '',
            parasut_contact_id varchar(64) NOT NULL DEFAULT '',
            parasut_invoice_id varchar(64) NOT NULL DEFAULT '',
            parasut_job_id varchar(64) NOT NULL DEFAULT '',
            parasut_earchive_id varchar(64) NOT NULL DEFAULT '',
            invoice_number varchar(64) NOT NULL DEFAULT '',
            invoice_date date NULL,
            pdf_path varchar(255) NOT NULL DEFAULT '',
            last_error text NULL,
            retry_count smallint unsigned NOT NULL DEFAULT 0,
            next_retry_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY kind_scope (kind, variation_id, order_id),
            KEY status_retry (status, next_retry_at),
            KEY order_id (order_id)
        ) {$charset};";

        $outboundEffects = self::table('outbound_effects');
        $sql[] = "CREATE TABLE {$outboundEffects} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            effect_type varchar(32) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            dedupe_key varchar(191) NOT NULL DEFAULT '',
            payload longtext NULL,
            last_error text NULL,
            attempts smallint unsigned NOT NULL DEFAULT 0,
            next_retry_at datetime NULL,
            processed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY status_retry (status, next_retry_at),
            KEY effect_type (effect_type)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        self::sealStoredSecrets();

        update_option('sutore_marketplace_db_version', self::VERSION);
    }

    /**
     * Canonical table suffixes for install / uninstall / health.
     *
     * @return list<string>
     */
    public static function tableSuffixes(): array
    {
        return [
            'merchant_profiles',
            'campaigns',
            'campaign_offers',
            'listings',
            'listing_conditions',
            'listing_events',
            'merchant_restrictions',
            'task_definitions',
            'merchant_task_progress',
            'merchant_rewards',
            'merchant_payout_lines',
            'merchant_notifications',
            'merchant_events',
            'merchant_commission_overrides',
            'catalog_product_requests',
            'outlet_windows',
            'outlet_items',
            'outlet_optins',
            'customer_offers',
            'customer_offer_daily_counters',
            'invoices',
            'outbound_effects',
        ];
    }

    private static function sealStoredSecrets(): void
    {
        $netgsm = (string) \SutoreMarketplace\Shared\Settings\Settings::get('netgsm_password', '');
        if ($netgsm !== '' && !\SutoreMarketplace\Shared\Security\SecretBox::isSealed($netgsm)) {
            \SutoreMarketplace\Shared\Settings\Settings::update([
                'netgsm_password' => \SutoreMarketplace\Shared\Security\SecretBox::seal($netgsm),
            ]);
        }

        $webhook = (string) \SutoreMarketplace\Modules\Orders\Settings\Settings::get('webhook_secret', '');
        if ($webhook !== '' && !\SutoreMarketplace\Shared\Security\SecretBox::isSealed($webhook)) {
            \SutoreMarketplace\Modules\Orders\Settings\Settings::update([
                'webhook_secret' => \SutoreMarketplace\Shared\Security\SecretBox::seal($webhook),
            ]);
        }

        $invoices = \SutoreMarketplace\Modules\Invoices\Settings\InvoiceSettings::all();
        $changed = false;
        foreach (['client_secret', 'password'] as $secretKey) {
            $value = (string) ($invoices[$secretKey] ?? '');
            if ($value !== '' && !\SutoreMarketplace\Shared\Security\SecretBox::isSealed($value)) {
                $invoices[$secretKey] = \SutoreMarketplace\Shared\Security\SecretBox::seal($value);
                $changed = true;
            }
        }
        if ($changed) {
            \SutoreMarketplace\Shared\Settings\Settings::update(['invoices' => $invoices]);
        }
    }
}
