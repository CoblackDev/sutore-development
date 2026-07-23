<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Database;

final class Schema
{
    public const VERSION = 31;

    public static function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sutore_marketplace_' . $suffix;
    }

    /**
     * Legacy fulfillments table name. Retained ONLY so the one-time
     * migrateSaleOntoListings() upgrade can read + drop the old table.
     * Do not use in runtime code.
     */
    public static function fulfillmentTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sutore_marketplace_fulfillments';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $listings = self::table('listings');
        $conditions = self::table('listing_conditions');
        $sourcing = self::table('sourcing_requests');
        $events = self::table('listing_events');
        $restrictions = self::table('merchant_restrictions');
        $taskDefs = self::table('task_definitions');
        $taskProgress = self::table('merchant_task_progress');
        $rewards = self::table('merchant_rewards');
        $campaigns = self::table('campaigns');
        $campaignOffers = self::table('campaign_offers');
        $districts = self::table('tr_districts');
        $payoutLines = self::table('merchant_payout_lines');
        $notifications = self::table('merchant_notifications');
        $merchantProfiles = self::table('merchant_profiles');
        $merchantEvents = self::table('merchant_events');
        $commissionOverrides = self::table('merchant_commission_overrides');

        $sql = [];

        $sql[] = "CREATE TABLE {$merchantProfiles} (
            user_id bigint(20) unsigned NOT NULL,
            account_name varchar(191) NOT NULL DEFAULT '',
            account_lastname varchar(191) NOT NULL DEFAULT '',
            account_iban varchar(64) NOT NULL DEFAULT '',
            account_tckno varchar(16) NOT NULL DEFAULT '',
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
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (user_id),
            KEY account_phone (account_phone),
            KEY merchant_status (merchant_status),
            KEY tckno_verified (tckno_verified)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$campaigns} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'draft',
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
            listing_id bigint(20) unsigned NOT NULL,
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
            UNIQUE KEY campaign_listing (campaign_id, listing_id),
            KEY listing_id (listing_id),
            KEY merchant_status (merchant_id, status),
            KEY campaign_status (campaign_id, status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$listings} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            variation_id bigint(20) unsigned NOT NULL,
            parent_product_id bigint(20) unsigned NOT NULL,
            size_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
            merchant_id bigint(20) unsigned NOT NULL,
            listing_status varchar(32) NOT NULL DEFAULT 'pending',
            asking decimal(12,2) NOT NULL DEFAULT 0.00,
            condition_fingerprint varchar(64) NOT NULL DEFAULT '',
            campaign_status varchar(32) NOT NULL DEFAULT 'none',
            campaign_id bigint(20) unsigned NULL,
            expire_at datetime NULL,
            sold_at datetime NULL,
            order_id bigint(20) unsigned NULL,
            order_item_id bigint(20) unsigned NULL,
            order_shipment_type varchar(32) NULL,
            order_shipment_deadline_at datetime NULL,
            sourcing_request_id bigint(20) unsigned NULL,
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
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY variation_id (variation_id),
            KEY parent_size_status (parent_product_id, size_term_id, listing_status),
            KEY merchant_status (merchant_id, listing_status),
            KEY sourcing_request_id (sourcing_request_id),
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
            KEY parent_winner_status (parent_product_id, is_winner, listing_status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$conditions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            listing_id bigint(20) unsigned NOT NULL,
            condition_key varchar(64) NOT NULL,
            condition_value tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY listing_condition (listing_id, condition_key),
            KEY listing_id (listing_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$sourcing} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            parent_product_id bigint(20) unsigned NOT NULL,
            size_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT 'open',
            requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
            accepted_merchant_id bigint(20) unsigned NULL,
            notes text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY status (status),
            KEY parent_size (parent_product_id, size_term_id),
            KEY accepted_merchant_id (accepted_merchant_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            listing_id bigint(20) unsigned NULL,
            variation_id bigint(20) unsigned NULL,
            merchant_id bigint(20) unsigned NULL,
            event_type varchar(64) NOT NULL,
            visibility varchar(32) NOT NULL DEFAULT 'admin_only',
            payload longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY listing_id (listing_id),
            KEY listing_created (listing_id, created_at),
            KEY variation_id (variation_id),
            KEY event_type (event_type),
            KEY merchant_id (merchant_id),
            KEY created_at (created_at)
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
            reward_type varchar(64) NOT NULL DEFAULT 'points',
            reward_value decimal(12,2) NOT NULL DEFAULT 0.00,
            reward_duration_days int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY task_key (task_key)
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

        $sql[] = "CREATE TABLE {$districts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            city_code varchar(8) NOT NULL,
            district_name varchar(191) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY city_district (city_code, district_name),
            KEY city_code (city_code)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$payoutLines} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            listing_id bigint(20) unsigned NOT NULL,
            variation_id bigint(20) unsigned NOT NULL,
            parent_product_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            merchant_id bigint(20) unsigned NOT NULL,
            product_title varchar(255) NOT NULL DEFAULT '',
            gross_asking decimal(12,2) NOT NULL DEFAULT 0.00,
            commission_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            net_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            payout_status varchar(32) NOT NULL DEFAULT 'pending',
            paid_at datetime NULL,
            paid_by bigint(20) unsigned NULL,
            payment_ref varchar(191) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY listing_id (listing_id),
            KEY merchant_status (merchant_id, payout_status),
            KEY variation_id (variation_id),
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
            listing_id bigint(20) unsigned NULL,
            dedupe_key varchar(128) NULL,
            read_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_unread (user_id, read_at, created_at),
            KEY user_feed (user_id, created_at),
            KEY listing_id (listing_id)
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
            is_active tinyint(1) NOT NULL DEFAULT 1,
            expires_at datetime NULL,
            source varchar(32) NOT NULL DEFAULT 'staff',
            task_id bigint(20) unsigned NULL,
            reward_id bigint(20) unsigned NULL,
            note text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY merchant_active_expires (merchant_id, is_active, expires_at)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        self::dropRemovedListingColumns();
        self::migrateLinearListingStatus();
        self::migrateSaleOntoListings();
        self::migrateCampaignOfferModel();
        self::migrateCampaignDiscountTypes();
        self::migrateReservedToPreOrder();
        self::migrateIssueOpenRemoved();
        self::migrateClosedStatusRemoved();
        self::migrateCancelledSuspendedRemoved();
        self::migrateStatusKeysAligned();
        self::migratePreOrderStatusRemoved();
        self::migrateOrderShipmentOntoListings();
        self::migrateIsImportedOntoListings();
        self::migrateSutoreShippedAtOntoListings();
        self::migratePerformanceIndexes();
        TrDistrictsSeeder::seedIfEmpty();
        self::sealStoredSecrets();

        update_option('sutore_marketplace_db_version', self::VERSION);
    }

    /**
     * Add composite indexes used by staff queues, PDP winner lookup, cron deadlines.
     */
    private static function migratePerformanceIndexes(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        $sourcing = self::table('sourcing_requests');
        $events = self::table('listing_events');

        if (self::tableExists($listings)) {
            $indexes = self::indexNames($listings);
            $add = [
                'status_cargo_deadline' => 'ALTER TABLE %s ADD KEY status_cargo_deadline (listing_status, cargo_deadline_at)',
                'campaign_status_id' => 'ALTER TABLE %s ADD KEY campaign_status_id (campaign_status, campaign_id)',
                'parent_winner_status' => 'ALTER TABLE %s ADD KEY parent_winner_status (parent_product_id, is_winner, listing_status)',
            ];
            foreach ($add as $name => $sql) {
                if (!in_array($name, $indexes, true)) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->query(sprintf($sql, $listings));
                }
            }
        }

        if (self::tableExists($sourcing)) {
            $indexes = self::indexNames($sourcing);
            if (!in_array('accepted_merchant_id', $indexes, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$sourcing} ADD KEY accepted_merchant_id (accepted_merchant_id)");
            }
        }

        if (self::tableExists($events)) {
            $indexes = self::indexNames($events);
            if (!in_array('listing_created', $indexes, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$events} ADD KEY listing_created (listing_id, created_at)");
            }
        }
    }

    /**
     * One-time: replace campaigns.discount_amount with seller/platform amounts + targeting.
     */
    private static function migrateCampaignOfferModel(): void
    {
        global $wpdb;
        $campaigns = self::table('campaigns');
        if (!self::tableExists($campaigns)) {
            return;
        }

        $columns = self::describeColumns($campaigns);

        if (!in_array('seller_discount_amount', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$campaigns} ADD COLUMN seller_discount_amount decimal(12,2) NOT NULL DEFAULT 0.00 AFTER status"
            );
        }
        if (!in_array('platform_discount_amount', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$campaigns} ADD COLUMN platform_discount_amount decimal(12,2) NOT NULL DEFAULT 0.00 AFTER seller_discount_amount"
            );
        }
        if (!in_array('targeting', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$campaigns} ADD COLUMN targeting longtext NULL AFTER ends_at");
        }

        $columns = self::describeColumns($campaigns);
        if (in_array('discount_amount', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "UPDATE {$campaigns}
                 SET platform_discount_amount = discount_amount
                 WHERE platform_discount_amount = 0 AND discount_amount > 0"
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$campaigns} DROP COLUMN discount_amount");
        }
    }

    /**
     * One-time: fixed/percent discount types on campaigns + offer value snapshots.
     */
    private static function migrateCampaignDiscountTypes(): void
    {
        global $wpdb;
        $campaigns = self::table('campaigns');
        $offers = self::table('campaign_offers');

        if (self::tableExists($campaigns)) {
            $columns = self::describeColumns($campaigns);
            if (!in_array('seller_discount_type', $columns, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "ALTER TABLE {$campaigns} ADD COLUMN seller_discount_type varchar(16) NOT NULL DEFAULT 'fixed' AFTER seller_discount_amount"
                );
            }
            if (!in_array('platform_discount_type', $columns, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "ALTER TABLE {$campaigns} ADD COLUMN platform_discount_type varchar(16) NOT NULL DEFAULT 'fixed' AFTER platform_discount_amount"
                );
            }
        }

        if (!self::tableExists($offers)) {
            return;
        }

        $columns = self::describeColumns($offers);
        if (!in_array('seller_discount_type', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$offers} ADD COLUMN seller_discount_type varchar(16) NOT NULL DEFAULT 'fixed' AFTER asking_before"
            );
        }
        if (!in_array('seller_discount_value', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$offers} ADD COLUMN seller_discount_value decimal(12,2) NOT NULL DEFAULT 0.00 AFTER seller_discount_type"
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "UPDATE {$offers} SET seller_discount_value = seller_discount WHERE seller_discount_value = 0"
            );
        }
        if (!in_array('platform_discount_type', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$offers} ADD COLUMN platform_discount_type varchar(16) NOT NULL DEFAULT 'fixed' AFTER seller_discount"
            );
        }
        if (!in_array('platform_discount_value', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$offers} ADD COLUMN platform_discount_value decimal(12,2) NOT NULL DEFAULT 0.00 AFTER platform_discount_type"
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "UPDATE {$offers} SET platform_discount_value = platform_discount WHERE platform_discount_value = 0"
            );
        }
    }

    /** One-time: sourcing listing status reserved → pre_order. */
    private static function migrateReservedToPreOrder(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$listings} SET listing_status = 'pre_order' WHERE listing_status = 'reserved'"
        );

        $events = self::table('listing_events');
        if (self::tableExists($events)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "UPDATE {$events} SET event_type = 'sourcing_pre_order' WHERE event_type = 'sourcing_reserved'"
            );
        }
    }

    /** One-time: remove issue_open sale status → delivered_to_customer. */
    private static function migrateIssueOpenRemoved(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$listings} SET listing_status = 'delivered_to_customer' WHERE listing_status = 'issue_open'"
        );
    }

    /** One-time: remove closed sale status → delivered_to_customer; drop lifecycle_closed_at. */
    private static function migrateClosedStatusRemoved(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$listings} SET listing_status = 'delivered_to_customer' WHERE listing_status = 'closed'"
        );
    }

    /** One-time: cancelled/suspended → not_sale (old plugin not-sale). */
    private static function migrateCancelledSuspendedRemoved(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$listings} SET listing_status = 'not_sale' WHERE listing_status IN ('cancelled','suspended')"
        );
    }

    /**
     * One-time: rename intermediate listing_status keys to match old plugin slugs.
     * active→publish, inactive→not_sale, payment_pending→payment, awaiting_seller→sold, shipped_to_customer→shipped.
     */
    private static function migrateStatusKeysAligned(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        $map = [
            'active' => 'publish',
            'inactive' => 'not_sale',
            'payment_pending' => 'payment',
            'awaiting_seller' => 'sold',
            'shipped_to_customer' => 'shipped',
        ];

        foreach ($map as $from => $to) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$listings} SET listing_status = %s WHERE listing_status = %s",
                    $to,
                    $from
                )
            );
        }
    }

    /**
     * One-time: pre_order is no longer a listing status — held by sourcing_request_id.
     * Map remaining pre_order rows to not_sale (sourcing flag stays on the row).
     */
    private static function migratePreOrderStatusRemoved(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$listings} SET listing_status = 'not_sale' WHERE listing_status = 'pre_order'"
        );
    }

    /**
     * Denormalize customer checkout shipment onto listing sale rows.
     * Backfills from order meta for existing linked sales.
     */
    private static function migrateOrderShipmentOntoListings(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        $columns = self::describeColumns($listings);
        if (!in_array('order_shipment_type', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$listings} ADD COLUMN order_shipment_type varchar(32) NULL AFTER order_item_id"
            );
        }
        if (!in_array('order_shipment_deadline_at', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$listings} ADD COLUMN order_shipment_deadline_at datetime NULL AFTER order_shipment_type"
            );
        }

        $columns = self::describeColumns($listings);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$listings}", ARRAY_A) ?: [];
        $indexNames = array_map(static fn ($row) => (string) ($row['Key_name'] ?? ''), $indexes);
        if (in_array('order_shipment_type', $columns, true) && !in_array('order_shipment_type', $indexNames, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$listings} ADD KEY order_shipment_type (order_shipment_type)");
        }
        if (in_array('order_shipment_deadline_at', $columns, true) && !in_array('order_shipment_deadline', $indexNames, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$listings} ADD KEY order_shipment_deadline (order_shipment_deadline_at)");
        }
        if (in_array('order_id', $columns, true) && !in_array('order_id', $indexNames, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$listings} ADD KEY order_id (order_id)");
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT id, order_id FROM {$listings}
             WHERE order_id IS NOT NULL AND order_id > 0
               AND (order_shipment_type IS NULL OR order_shipment_type = '')
             ORDER BY id ASC
             LIMIT 500"
        ) ?: [];

        foreach ($rows as $row) {
            $cols = \SutoreMarketplace\Modules\Orders\Support\OrderShipmentSnapshot::columnsForOrder((int) $row->order_id);
            $wpdb->update(
                $listings,
                [
                    'order_shipment_type' => $cols['order_shipment_type'],
                    'order_shipment_deadline_at' => $cols['order_shipment_deadline_at'],
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => (int) $row->id]
            );
        }
    }

    /**
     * Add listings.is_imported and backfill from variation meta
     * (_sutore_marketplace_imported_product or legacy imported).
     */
    private static function migrateIsImportedOntoListings(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        $columns = self::describeColumns($listings);
        if (!in_array('is_imported', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$listings} ADD COLUMN is_imported tinyint(1) NOT NULL DEFAULT 0 AFTER has_invoice"
            );
        }

        $columns = self::describeColumns($listings);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$listings}", ARRAY_A) ?: [];
        $indexNames = array_map(static fn ($row) => (string) ($row['Key_name'] ?? ''), $indexes);
        if (in_array('is_imported', $columns, true) && !in_array('is_imported', $indexNames, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$listings} ADD KEY is_imported (is_imported)");
        }

        $postmeta = $wpdb->postmeta;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$listings} l
             INNER JOIN {$postmeta} pm ON pm.post_id = l.variation_id
               AND pm.meta_key IN ('_sutore_marketplace_imported_product', 'imported')
               AND pm.meta_value = '1'
             SET l.is_imported = 1
             WHERE l.is_imported = 0"
        );
    }

    /**
     * Add listings.sutore_shipped_at (Sutore → customer ship timestamp).
     * Backfill from listing_events when a fulfillment_shipped event exists.
     */
    private static function migrateSutoreShippedAtOntoListings(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        $columns = self::describeColumns($listings);
        if (!in_array('sutore_shipped_at', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$listings} ADD COLUMN sutore_shipped_at datetime NULL AFTER sutore_shipment_code"
            );
        }

        $events = self::table('listing_events');
        if (!self::tableExists($events)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$listings} l
             INNER JOIN (
                SELECT listing_id, MIN(created_at) AS shipped_at
                FROM {$events}
                WHERE event_type = 'fulfillment_shipped'
                GROUP BY listing_id
             ) e ON e.listing_id = l.id
             SET l.sutore_shipped_at = e.shipped_at
             WHERE l.sutore_shipped_at IS NULL
               AND l.sutore_shipment_code IS NOT NULL
               AND l.sutore_shipment_code <> ''"
        );
    }

    /**
     * One-time: sync listing_status from legacy fulfillments when present.
     * payment / sold on listings are valid linear statuses (old plugin keys).
     */
    private static function migrateLinearListingStatus(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        $fulfillments = self::fulfillmentTable();

        if (self::tableExists($fulfillments)) {
            // Normalize old fulfillment status values before mapping onto listings.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "UPDATE {$fulfillments} SET fulfillment_status = 'delivered_to_customer' WHERE fulfillment_status = 'completed'"
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "UPDATE {$listings} l
                 INNER JOIN (
                    SELECT f.listing_id, f.fulfillment_status
                    FROM {$fulfillments} f
                    INNER JOIN (
                        SELECT listing_id, MAX(id) AS max_id
                        FROM {$fulfillments}
                        GROUP BY listing_id
                    ) latest ON latest.max_id = f.id
                 ) src ON src.listing_id = l.id
                 SET l.listing_status = src.fulfillment_status
                 WHERE l.listing_status IN ('payment', 'sold')
                   AND src.fulfillment_status NOT IN ('payment', 'sold')"
            );
        }
    }

    /**
     * One-time: fold the legacy fulfillments table onto listings, then drop it.
     * Also switches the payout table from fulfillment_id → listing_id.
     *
     * Safe to re-run: every step is guarded by column / table existence checks.
     */
    private static function migrateSaleOntoListings(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        $fulfillments = self::fulfillmentTable();
        $payout = self::table('merchant_payout_lines');

        // 1. Defensive: ensure every sale column exists on listings. dbDelta
        //    usually adds them, but re-check by DESCRIBE and add any missing one.
        $listingColumns = self::describeColumns($listings);
        $saleColumns = [
            'confirm_deadline_at'    => 'ADD COLUMN confirm_deadline_at datetime NULL',
            'seller_confirmed_at'    => 'ADD COLUMN seller_confirmed_at datetime NULL',
            'cargo_deadline_at'      => 'ADD COLUMN cargo_deadline_at datetime NULL',
            'merchant_shipped_at'    => 'ADD COLUMN merchant_shipped_at datetime NULL',
            'merchant_shipment_code' => 'ADD COLUMN merchant_shipment_code varchar(64) NULL',
            'sutore_shipment_code'   => 'ADD COLUMN sutore_shipment_code varchar(64) NULL',
            'sutore_shipped_at'      => 'ADD COLUMN sutore_shipped_at datetime NULL',
            'merchant_snapshot'      => 'ADD COLUMN merchant_snapshot longtext NULL',
            'confirm_notice_sent'    => "ADD COLUMN confirm_notice_sent tinyint(1) NOT NULL DEFAULT 0",
            'confirm_punished'       => "ADD COLUMN confirm_punished tinyint(1) NOT NULL DEFAULT 0",
            'cargo_notice_sent'      => "ADD COLUMN cargo_notice_sent tinyint(1) NOT NULL DEFAULT 0",
            'cargo_expired_flag'     => "ADD COLUMN cargo_expired_flag tinyint(1) NOT NULL DEFAULT 0",
            'delivered_at'           => 'ADD COLUMN delivered_at datetime NULL',
            'return_window_ends_at'  => 'ADD COLUMN return_window_ends_at datetime NULL',
            'lifecycle_closed_at'    => 'ADD COLUMN lifecycle_closed_at datetime NULL',
            'notes'                  => 'ADD COLUMN notes text NULL',
        ];
        foreach ($saleColumns as $column => $ddl) {
            if (!in_array($column, $listingColumns, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$listings} {$ddl}");
            }
        }

        // 2. Copy latest fulfillment row per listing_id into listings.
        if (self::tableExists($fulfillments)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "UPDATE {$listings} l
                 INNER JOIN (
                    SELECT f.*
                    FROM {$fulfillments} f
                    INNER JOIN (
                        SELECT listing_id, MAX(id) AS max_id
                        FROM {$fulfillments}
                        GROUP BY listing_id
                    ) latest ON latest.max_id = f.id
                 ) src ON src.listing_id = l.id
                 SET
                    l.listing_status          = src.fulfillment_status,
                    l.confirm_deadline_at     = src.confirm_deadline_at,
                    l.seller_confirmed_at     = src.seller_confirmed_at,
                    l.cargo_deadline_at       = src.cargo_deadline_at,
                    l.merchant_shipped_at     = src.merchant_shipped_at,
                    l.merchant_shipment_code  = src.merchant_shipment_code,
                    l.sutore_shipment_code    = src.sutore_shipment_code,
                    l.merchant_snapshot       = src.merchant_snapshot,
                    l.confirm_notice_sent     = src.confirm_notice_sent,
                    l.confirm_punished        = src.confirm_punished,
                    l.cargo_notice_sent       = src.cargo_notice_sent,
                    l.cargo_expired_flag      = src.cargo_expired_flag,
                    l.delivered_at            = src.delivered_at,
                    l.return_window_ends_at   = src.return_window_ends_at,
                    l.lifecycle_closed_at     = src.lifecycle_closed_at,
                    l.notes                   = src.notes"
            );
        }

        // 3. Payout: drop the legacy fulfillment_id column + index and make
        //    listing_id the unique key.
        if (self::tableExists($payout)) {
            $payoutColumns = self::describeColumns($payout);
            $payoutIndexes = self::indexNames($payout);

            if (in_array('fulfillment_id', $payoutIndexes, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$payout} DROP INDEX fulfillment_id");
            }

            if (in_array('fulfillment_id', $payoutColumns, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$payout} DROP COLUMN fulfillment_id");
            }

            // Re-read indexes after possible modifications above.
            $payoutIndexes = self::indexNames($payout);
            if (in_array('listing_id', $payoutIndexes, true)) {
                // Drop the non-unique key so we can promote it to UNIQUE.
                $isUnique = self::isUniqueIndex($payout, 'listing_id');
                if (!$isUnique) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query("ALTER TABLE {$payout} DROP INDEX listing_id");
                }
            }

            if (!in_array('listing_id', self::indexNames($payout), true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$payout} ADD UNIQUE KEY listing_id (listing_id)");
            }
        }

        // 4. Drop the legacy fulfillments table.
        if (self::tableExists($fulfillments)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE {$fulfillments}");
        }
    }

    /**
     * dbDelta does not drop columns; remove denormalized listing fields that no
     * longer belong on the row.
     */
    private static function dropRemovedListingColumns(): void
    {
        global $wpdb;
        $table = self::table('listings');
        $columns = self::describeColumns($table);
        if ($columns === []) {
            return;
        }

        foreach (['ship_by_at', 'shipping_sla_hours', 'lifecycle_closed_at'] as $column) {
            if (in_array($column, $columns, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$table} DROP COLUMN {$column}");
            }
        }
    }

    /** @return list<string> */
    private static function describeColumns(string $table): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col("DESCRIBE {$table}", 0);

        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /** @return list<string> */
    private static function indexNames(string $table): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (array $row): string => (string) ($row['Key_name'] ?? ''),
            $rows
        )));
    }

    private static function isUniqueIndex(string $table, string $indexName): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        if (!is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if ((string) ($row['Key_name'] ?? '') === $indexName) {
                return (int) ($row['Non_unique'] ?? 1) === 0;
            }
        }

        return false;
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($exists) && $exists !== '';
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
    }
}
