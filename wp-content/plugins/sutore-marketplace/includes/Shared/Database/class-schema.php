<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Database;

final class Schema
{
    public const VERSION = 48;

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
        $invoices = self::table('invoices');

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
            KEY parent_winner_status (parent_product_id, is_winner, listing_status)
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
        self::migrateDropSourcingRequestsTable();
        self::migrateOrderShipmentOntoListings();
        self::migrateIsImportedOntoListings();
        self::migrateSutoreShippedAtOntoListings();
        self::migratePerformanceIndexes();
        self::migrateVariationIdPrimaryKey();
        self::migrateOrderDetachedStatus();
        self::migrateListingDurationDays();
        self::migrateBehaviorSystem();
        self::migrateListingEventReversals();
        self::migrateCommissionPlanes();
        self::migratePayoutSchedule();
        self::migrateCampaignDiscountLanguage();
        self::migrateReferralColumns();
        self::migrateDropTrDistrictsTable();
        self::migrateInvoiceOrderScope();
        self::sealStoredSecrets();

        update_option('sutore_marketplace_db_version', self::VERSION);
    }

    /**
     * One-time: listings.variation_id becomes PK; child listing_id FKs remap + rename to variation_id.
     * Safe to re-run: also cleans dual listing_id/variation_id columns left by dbDelta mid-upgrade.
     */
    private static function migrateVariationIdPrimaryKey(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        self::cleanupListingIdForeignKeys();

        $listingCols = self::describeColumns($listings);
        if (!in_array('id', $listingCols, true)) {
            return;
        }

        // Drop AUTO_INCREMENT before swapping primary key.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "ALTER TABLE {$listings} MODIFY id bigint(20) unsigned NOT NULL"
        );
        $indexes = self::indexNames($listings);
        if (in_array('variation_id', $indexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$listings} DROP INDEX variation_id");
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("ALTER TABLE {$listings} DROP PRIMARY KEY, ADD PRIMARY KEY (variation_id)");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("ALTER TABLE {$listings} DROP COLUMN id");
    }

    /**
     * Remap/rename leftover listing_id child columns onto variation_id.
     */
    private static function cleanupListingIdForeignKeys(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        $listingCols = self::describeColumns($listings);
        $hasListingPkId = in_array('id', $listingCols, true);

        $conditions = self::table('listing_conditions');
        $events = self::table('listing_events');
        $offers = self::table('campaign_offers');
        $payout = self::table('merchant_payout_lines');
        $notifications = self::table('merchant_notifications');

        if (self::tableExists($conditions)) {
            self::reconcileChildListingId($conditions, $listings, $hasListingPkId, 'listing_condition', 'variation_condition', false);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DELETE FROM {$conditions} WHERE variation_id = 0 OR variation_id IS NULL");
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "DELETE c1 FROM {$conditions} c1
                 INNER JOIN {$conditions} c2
                   ON c1.variation_id = c2.variation_id
                  AND c1.condition_key = c2.condition_key
                  AND c1.id > c2.id"
            );
            if (!in_array('variation_condition', self::indexNames($conditions), true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$conditions} ADD UNIQUE KEY variation_condition (variation_id, condition_key)");
            }
        }

        if (self::tableExists($events)) {
            $eventCols = self::describeColumns($events);
            if (in_array('listing_id', $eventCols, true)) {
                if ($hasListingPkId) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query(
                        "UPDATE {$events} e
                         INNER JOIN {$listings} l ON l.id = e.listing_id
                         SET e.variation_id = l.variation_id
                         WHERE e.listing_id IS NOT NULL"
                    );
                } else {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query(
                        "UPDATE {$events}
                         SET variation_id = listing_id
                         WHERE (variation_id IS NULL OR variation_id = 0) AND listing_id IS NOT NULL AND listing_id > 0"
                    );
                }
                foreach (['listing_created', 'listing_id'] as $indexName) {
                    if (in_array($indexName, self::indexNames($events), true)) {
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $wpdb->query("ALTER TABLE {$events} DROP INDEX {$indexName}");
                    }
                }
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$events} DROP COLUMN listing_id");
            }
            if (!in_array('variation_created', self::indexNames($events), true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$events} ADD KEY variation_created (variation_id, created_at)");
            }
        }

        if (self::tableExists($offers)) {
            self::reconcileChildListingId($offers, $listings, $hasListingPkId, 'campaign_listing', 'campaign_variation', false);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DELETE FROM {$offers} WHERE variation_id = 0 OR variation_id IS NULL");
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "DELETE o1 FROM {$offers} o1
                 INNER JOIN {$offers} o2
                   ON o1.campaign_id = o2.campaign_id
                  AND o1.variation_id = o2.variation_id
                  AND o1.id > o2.id"
            );
            if (!in_array('campaign_variation', self::indexNames($offers), true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$offers} ADD UNIQUE KEY campaign_variation (campaign_id, variation_id)");
            }
        }

        if (self::tableExists($payout)) {
            $payoutCols = self::describeColumns($payout);
            if (in_array('listing_id', $payoutCols, true)) {
                if ($hasListingPkId) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query(
                        "UPDATE {$payout} p
                         INNER JOIN {$listings} l ON l.id = p.listing_id
                         SET p.variation_id = l.variation_id"
                    );
                } elseif (in_array('variation_id', $payoutCols, true)) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query(
                        "UPDATE {$payout}
                         SET variation_id = listing_id
                         WHERE (variation_id IS NULL OR variation_id = 0) AND listing_id > 0"
                    );
                }
                if (in_array('listing_id', self::indexNames($payout), true)) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query("ALTER TABLE {$payout} DROP INDEX listing_id");
                }
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$payout} DROP COLUMN listing_id");
            }
            $indexes = self::indexNames($payout);
            if (!in_array('variation_id', $indexes, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$payout} ADD UNIQUE KEY variation_id (variation_id)");
            } elseif (!self::isUniqueIndex($payout, 'variation_id')) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$payout} DROP INDEX variation_id");
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$payout} ADD UNIQUE KEY variation_id (variation_id)");
            }
        }

        if (self::tableExists($notifications)) {
            self::reconcileChildListingId($notifications, $listings, $hasListingPkId, null, null, true);
        }
    }

    /**
     * @param ?string $oldUnique Unique index name on listing_id (or composite including it)
     * @param ?string $newUnique Unique index name for variation_id
     */
    private static function reconcileChildListingId(
        string $table,
        string $listings,
        bool $hasListingPkId,
        ?string $oldUnique,
        ?string $newUnique,
        bool $nullable
    ): void {
        global $wpdb;
        $cols = self::describeColumns($table);
        if (!in_array('listing_id', $cols, true)) {
            return;
        }

        $hasVariationCol = in_array('variation_id', $cols, true);
        if ($hasVariationCol) {
            if ($hasListingPkId) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "UPDATE {$table} c
                     INNER JOIN {$listings} l ON l.id = c.listing_id
                     SET c.variation_id = l.variation_id
                     WHERE c.variation_id IS NULL OR c.variation_id = 0"
                );
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "UPDATE {$table}
                     SET variation_id = listing_id
                     WHERE (variation_id IS NULL OR variation_id = 0) AND listing_id IS NOT NULL AND listing_id > 0"
                );
            }
            foreach (array_filter([$oldUnique, 'listing_id', 'campaign_listing', 'listing_condition']) as $indexName) {
                if (in_array($indexName, self::indexNames($table), true)) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query("ALTER TABLE {$table} DROP INDEX {$indexName}");
                }
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN listing_id");
        } else {
            self::renameListingIdColumn($table, $oldUnique, $newUnique, $nullable);
            return;
        }

        $indexes = self::indexNames($table);
        if ($newUnique !== null && !in_array($newUnique, $indexes, true)) {
            if ($newUnique === 'variation_condition') {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY variation_condition (variation_id, condition_key)");
            } elseif ($newUnique === 'campaign_variation') {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY campaign_variation (campaign_id, variation_id)");
            }
        }
        if (!in_array('variation_id', self::indexNames($table), true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$table} ADD KEY variation_id (variation_id)");
        }
    }

    /**
     * Remap already-done: rename listing_id column to variation_id and refresh indexes.
     */
    private static function renameListingIdColumn(
        string $table,
        ?string $oldUnique,
        ?string $newUnique,
        bool $nullable = false
    ): void {
        global $wpdb;
        $cols = self::describeColumns($table);
        if (!in_array('listing_id', $cols, true)) {
            return;
        }
        if (in_array('variation_id', $cols, true)) {
            // Dual columns: handled by reconcileChildListingId.
            return;
        }

        $indexes = self::indexNames($table);
        if ($oldUnique !== null && in_array($oldUnique, $indexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$table} DROP INDEX {$oldUnique}");
        }
        if (in_array('listing_id', $indexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$table} DROP INDEX listing_id");
        }

        $nullSql = $nullable ? 'NULL' : 'NOT NULL';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "ALTER TABLE {$table} CHANGE listing_id variation_id bigint(20) unsigned {$nullSql}"
        );

        $indexes = self::indexNames($table);
        if ($newUnique !== null && !in_array($newUnique, $indexes, true)) {
            if ($newUnique === 'variation_condition') {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY variation_condition (variation_id, condition_key)");
            } elseif ($newUnique === 'campaign_variation') {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY campaign_variation (campaign_id, variation_id)");
            }
        }
        $indexes = self::indexNames($table);
        if (!in_array('variation_id', $indexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$table} ADD KEY variation_id (variation_id)");
        }
    }

    /**
     * Add composite indexes used by staff queues, PDP winner lookup, cron deadlines.
     */
    private static function migratePerformanceIndexes(): void
    {
        global $wpdb;
        $listings = self::table('listings');
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

        if (self::tableExists($events)) {
            $indexes = self::indexNames($events);
            $eventCols = self::describeColumns($events);
            if (in_array('listing_id', $eventCols, true) && !in_array('listing_created', $indexes, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$events} ADD KEY listing_created (listing_id, created_at)");
            }
            if (!in_array('listing_id', $eventCols, true) && !in_array('variation_created', $indexes, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$events} ADD KEY variation_created (variation_id, created_at)");
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
     * One-time: drop sourcing_requests table; pre-order board uses listing_status pre_order.
     */
    private static function migrateDropSourcingRequestsTable(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        $sourcing = self::table('sourcing_requests');
        if (!self::tableExists($listings)) {
            return;
        }

        $listingCols = self::describeColumns($listings);
        if (self::tableExists($sourcing)) {
            if (in_array('sourcing_request_id', $listingCols, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "UPDATE {$listings} l
                     INNER JOIN {$sourcing} sr ON l.sourcing_request_id = sr.id
                     SET l.listing_status = 'pre_order',
                         l.order_id = COALESCE(NULLIF(l.order_id, 0), sr.order_id),
                         l.order_item_id = CASE
                             WHEN l.order_item_id IS NULL OR l.order_item_id = 0 THEN sr.order_item_id
                             ELSE l.order_item_id
                         END"
                );
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "UPDATE {$listings} l
                 INNER JOIN {$sourcing} sr
                    ON sr.order_id = l.order_id
                   AND sr.order_item_id = l.order_item_id
                   AND sr.status IN ('open', 'accepted')
                 SET l.listing_status = 'pre_order'
                 WHERE l.listing_status IN ('not_sale', 'sold', 'payment', 'sourcing')"
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE {$sourcing}");
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$listings} SET listing_status = 'pre_order' WHERE listing_status = 'sourcing'"
        );

        $listingCols = self::describeColumns($listings);
        if (in_array('sourcing_request_id', $listingCols, true)) {
            $indexes = self::indexNames($listings);
            if (in_array('sourcing_request_id', $indexes, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$listings} DROP INDEX sourcing_request_id");
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$listings} DROP COLUMN sourcing_request_id");
        }
    }

    /**
     * One-time: pre_order rows → not_sale (historical). Superseded — pre_order is restored
     * as the open board status; see migrateDropSourcingRequestsTable().
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
     * One-time: accepted pre-order holds use market status `sourcing`
     * (not_sale + sourcing_request_id → sourcing).
     */
    private static function migrateSourcingHoldStatus(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$listings}
             SET listing_status = 'sourcing'
             WHERE sourcing_request_id IS NOT NULL
               AND listing_status = 'not_sale'"
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

        $idColumn = in_array('id', self::describeColumns($listings), true) ? 'id' : 'variation_id';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT {$idColumn} AS row_id, order_id FROM {$listings}
             WHERE order_id IS NOT NULL AND order_id > 0
               AND (order_shipment_type IS NULL OR order_shipment_type = '')
             ORDER BY {$idColumn} ASC
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
                [$idColumn => (int) $row->row_id]
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

        $eventCols = self::describeColumns($events);
        $listingCols = self::describeColumns($listings);
        if (in_array('variation_id', $eventCols, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "UPDATE {$listings} l
                 INNER JOIN (
                    SELECT variation_id, MIN(created_at) AS shipped_at
                    FROM {$events}
                    WHERE event_type = 'fulfillment_shipped'
                      AND variation_id IS NOT NULL AND variation_id > 0
                    GROUP BY variation_id
                 ) e ON e.variation_id = l.variation_id
                 SET l.sutore_shipped_at = e.shipped_at
                 WHERE l.sutore_shipped_at IS NULL
                   AND l.sutore_shipment_code IS NOT NULL
                   AND l.sutore_shipment_code <> ''"
            );
        } elseif (in_array('listing_id', $eventCols, true) && in_array('id', $listingCols, true)) {
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
            $payoutCols = self::describeColumns($payout);
            if (!in_array('listing_id', $payoutCols, true)) {
                // Already migrated to variation_id SoT.
                return;
            }

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

        foreach (['ship_by_at', 'shipping_sla_hours', 'lifecycle_closed_at', 'sourcing_request_id'] as $column) {
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

    /**
     * One-time: order-detachment rows leave mixed not_sale → order_detached.
     * Seller remove-from-sale (listing_removed_from_sale) stays not_sale.
     */
    private static function migrateOrderDetachedStatus(): void
    {
        if (get_option('sutore_marketplace_migrated_order_detached_v1') === '1') {
            return;
        }

        global $wpdb;
        $listings = self::table('listings');
        $events = self::table('listing_events');
        if (!self::tableExists($listings) || !self::tableExists($events)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$listings} l
             SET l.listing_status = 'order_detached'
             WHERE l.listing_status = 'not_sale'
               AND EXISTS (
                   SELECT 1 FROM {$events} e
                   WHERE e.variation_id = l.variation_id
                     AND e.event_type IN ('listing_left_sale', 'order_listing_detached')
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$events} e2
                   WHERE e2.variation_id = l.variation_id
                     AND e2.event_type = 'listing_removed_from_sale'
               )"
        );

        update_option('sutore_marketplace_migrated_order_detached_v1', '1');
    }

    private static function migrateListingDurationDays(): void
    {
        if (get_option('sutore_marketplace_migrated_listing_duration_days_v1') === '1') {
            return;
        }

        global $wpdb;
        $listings = self::table('listings');
        if (!self::tableExists($listings)) {
            return;
        }

        $columns = self::describeColumns($listings);
        if (!in_array('listing_duration_days', $columns, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$listings} ADD COLUMN listing_duration_days smallint unsigned NOT NULL DEFAULT 45 AFTER expire_at"
            );
        }

        $default = \SutoreMarketplace\Shared\Settings\Settings::defaultListingDurationDays();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare(
            "UPDATE {$listings} SET listing_duration_days = %d WHERE listing_duration_days = 0",
            $default
        ));

        update_option('sutore_marketplace_migrated_listing_duration_days_v1', '1');
    }

    private static function migrateBehaviorSystem(): void
    {
        if (get_option('sutore_marketplace_migrated_behavior_system_v1') === '1') {
            return;
        }

        global $wpdb;
        $profiles = self::table('merchant_profiles');
        $taskDefs = self::table('task_definitions');

        if (self::tableExists($profiles)) {
            $cols = self::describeColumns($profiles);
            if (!in_array('behavior_score', $cols, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$profiles} ADD COLUMN behavior_score decimal(3,2) NOT NULL DEFAULT 5.00 AFTER merchant_status");
            }
            if (!in_array('behavior_summary_key', $cols, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$profiles} ADD COLUMN behavior_summary_key varchar(64) NOT NULL DEFAULT 'no_sales_yet' AFTER behavior_score");
            }
            if (!in_array('score_computed_at', $cols, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$profiles} ADD COLUMN score_computed_at datetime NULL AFTER behavior_summary_key");
            }
        }

        if (self::tableExists($taskDefs)) {
            $cols = self::describeColumns($taskDefs);
            $additions = [
                'card_family' => "ADD COLUMN card_family varchar(32) NOT NULL DEFAULT 'growth' AFTER reward_duration_days",
                'template_key' => "ADD COLUMN template_key varchar(64) NOT NULL DEFAULT '' AFTER card_family",
                'template_params' => 'ADD COLUMN template_params longtext NULL AFTER template_key',
                'period_key' => "ADD COLUMN period_key varchar(16) NOT NULL DEFAULT '' AFTER template_params",
                'merchant_id' => 'ADD COLUMN merchant_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER period_key',
                'is_template' => 'ADD COLUMN is_template tinyint(1) NOT NULL DEFAULT 0 AFTER merchant_id',
            ];
            foreach ($additions as $column => $ddl) {
                if (!in_array($column, $cols, true)) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query("ALTER TABLE {$taskDefs} {$ddl}");
                }
            }
        }

        update_option('sutore_marketplace_migrated_behavior_system_v1', '1');
    }

    private static function migrateListingEventReversals(): void
    {
        if (get_option('sutore_marketplace_migrated_listing_event_reversals_v1') === '1') {
            return;
        }

        global $wpdb;
        $events = self::table('listing_events');
        if (self::tableExists($events)) {
            $cols = self::describeColumns($events);
            if (!in_array('reverses_event_id', $cols, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "ALTER TABLE {$events} ADD COLUMN reverses_event_id bigint(20) unsigned NULL AFTER payload"
                );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$events} ADD KEY reverses_event_id (reverses_event_id)");
            }
        }

        $behaviorEvents = self::table('merchant_behavior_events');
        if (self::tableExists($behaviorEvents)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE {$behaviorEvents}");
        }

        update_option('sutore_marketplace_migrated_listing_event_reversals_v1', '1');
    }

    /**
     * Listing optional/sale-locked commission rates + override window/adjustment.
     */
    private static function migrateCommissionPlanes(): void
    {
        global $wpdb;
        $listings = self::table('listings');
        if (self::tableExists($listings)) {
            $cols = self::describeColumns($listings);
            if (!in_array('commission_percent', $cols, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "ALTER TABLE {$listings} ADD COLUMN commission_percent decimal(5,2) NULL AFTER asking"
                );
            }
            if (!in_array('sale_commission_percent', $cols, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "ALTER TABLE {$listings} ADD COLUMN sale_commission_percent decimal(5,2) NULL AFTER commission_percent"
                );
            }
        }

        $overrides = self::table('merchant_commission_overrides');
        if (!self::tableExists($overrides)) {
            return;
        }

        $cols = self::describeColumns($overrides);
        if (!in_array('adjustment', $cols, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$overrides} ADD COLUMN adjustment varchar(32) NOT NULL DEFAULT 'absolute' AFTER commission_percent"
            );
        }
        if (!in_array('starts_at', $cols, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$overrides} ADD COLUMN starts_at datetime NULL AFTER is_active"
            );
        }

        $indexes = self::indexNames($overrides);
        if (!in_array('merchant_active_window', $indexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$overrides} ADD KEY merchant_active_window (merchant_id, is_active, starts_at, expires_at)"
            );
        }
    }

    private static function migratePayoutSchedule(): void
    {
        global $wpdb;
        $payout = self::table('merchant_payout_lines');
        if (!self::tableExists($payout)) {
            return;
        }

        $cols = self::describeColumns($payout);
        $alters = [
            'commission_amount' => 'ADD COLUMN commission_amount decimal(12,2) NOT NULL DEFAULT 0.00 AFTER commission_percent',
            'hizmet_fee' => 'ADD COLUMN hizmet_fee decimal(12,2) NOT NULL DEFAULT 0.00 AFTER commission_amount',
            'guvence_fee' => 'ADD COLUMN guvence_fee decimal(12,2) NOT NULL DEFAULT 0.00 AFTER hizmet_fee',
            'extra_deduction' => 'ADD COLUMN extra_deduction decimal(12,2) NOT NULL DEFAULT 0.00 AFTER guvence_fee',
            'scheduled_payout_date' => 'ADD COLUMN scheduled_payout_date date NULL AFTER payout_status',
        ];
        foreach ($alters as $column => $ddl) {
            if (in_array($column, $cols, true)) {
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$payout} {$ddl}");
        }

        $indexes = self::indexNames($payout);
        if (!in_array('payout_due', $indexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$payout} ADD KEY payout_due (payout_status, scheduled_payout_date)");
        }

        $cols = self::describeColumns($payout);
        if (!in_array('scheduled_payout_date', $cols, true)) {
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT id, gross_asking, commission_percent, created_at, scheduled_payout_date
             FROM {$payout}
             WHERE scheduled_payout_date IS NULL
                OR commission_amount = 0"
        ) ?: [];
        if ($rows === []) {
            return;
        }

        $hizmet = \SutoreMarketplace\Shared\Settings\Settings::hizmetBedeli();
        $guvencePercent = \SutoreMarketplace\Shared\Settings\Settings::guvenceBedeli();
        foreach ($rows as $row) {
            $gross = (float) ($row->gross_asking ?? 0);
            $percent = (float) ($row->commission_percent ?? 0);
            $commissionAmount = round($gross * max(0.0, $percent) / 100, 2);
            $guvence = $guvencePercent > 0 && $gross > 0
                ? round($gross * $guvencePercent / 100, 2)
                : 0.0;
            $scheduled = (string) ($row->scheduled_payout_date ?? '');
            if ($scheduled === '') {
                $scheduled = \SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule::scheduledDateFrom(
                    (string) ($row->created_at ?? '')
                );
            }
            $wpdb->update(
                $payout,
                [
                    'commission_amount' => $commissionAmount,
                    'hizmet_fee' => $hizmet,
                    'guvence_fee' => $guvence,
                    'extra_deduction' => 0,
                    'scheduled_payout_date' => $scheduled,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => (int) $row->id]
            );
        }
    }

    private static function migrateCampaignDiscountLanguage(): void
    {
        if (get_option('sutore_marketplace_migrated_campaign_discount_language_v1') === '1') {
            return;
        }

        global $wpdb;
        $campaigns = self::table('campaigns');
        $listings = self::table('listings');

        if (self::tableExists($campaigns)) {
            $columns = self::describeColumns($campaigns);
            if (!in_array('source', $columns, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "ALTER TABLE {$campaigns} ADD COLUMN source varchar(16) NOT NULL DEFAULT 'admin' AFTER status"
                );
            }
        }

        if (self::tableExists($listings)) {
            $columns = self::describeColumns($listings);
            if (!in_array('campaign_cooled_until', $columns, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "ALTER TABLE {$listings} ADD COLUMN campaign_cooled_until datetime NULL AFTER campaign_id"
                );
            }
            if (!in_array('campaign_aging_step', $columns, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "ALTER TABLE {$listings} ADD COLUMN campaign_aging_step tinyint unsigned NOT NULL DEFAULT 0 AFTER campaign_cooled_until"
                );
            }
            $indexes = self::indexNames($listings);
            if (!in_array('campaign_cooled_until', $indexes, true)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$listings} ADD KEY campaign_cooled_until (campaign_cooled_until)");
            }
        }

        update_option('sutore_marketplace_migrated_campaign_discount_language_v1', '1');
    }

    private static function migrateReferralColumns(): void
    {
        global $wpdb;
        $profiles = self::table('merchant_profiles');
        if (!self::tableExists($profiles)) {
            return;
        }

        $cols = self::describeColumns($profiles);
        if (!in_array('referral_code', $cols, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$profiles} ADD COLUMN referral_code varchar(16) NULL AFTER score_computed_at"
            );
        }
        if (!in_array('referred_by_user_id', $cols, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$profiles} ADD COLUMN referred_by_user_id bigint(20) unsigned NULL AFTER referral_code"
            );
        }
        if (!in_array('referral_rewarded_at', $cols, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$profiles} ADD COLUMN referral_rewarded_at datetime NULL AFTER referred_by_user_id"
            );
        }

        $indexes = self::indexNames($profiles);
        if (!in_array('referral_code', $indexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$profiles} ADD UNIQUE KEY referral_code (referral_code)");
        }
        if (!in_array('referred_by', $indexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$profiles} ADD KEY referred_by (referred_by_user_id)");
        }
    }

    /**
     * One-time: TR districts live in tr-districts-data.php. Drop the unused table + transients.
     */
    private static function migrateDropTrDistrictsTable(): void
    {
        global $wpdb;
        $table = self::table('tr_districts');
        if (self::tableExists($table)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE {$table}");
        }

        $like = $wpdb->esc_like('_transient_sutore_mp_tr_districts_') . '%';
        $timeoutLike = $wpdb->esc_like('_transient_timeout_sutore_mp_tr_districts_') . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like,
            $timeoutLike
        ));
    }

    /**
     * Customer e-Archive is one invoice per order (variation_id = 0). Line items stored as JSON.
     */
    private static function migrateInvoiceOrderScope(): void
    {
        global $wpdb;
        $table = self::table('invoices');
        if (!self::tableExists($table)) {
            return;
        }

        $cols = self::describeColumns($table);
        if (!in_array('line_items', $cols, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$table} ADD COLUMN line_items longtext NULL AFTER total_amount"
            );
        }

        $indexes = self::indexNames($table);
        if (in_array('kind_variation', $indexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$table} DROP INDEX kind_variation");
        }
        if (!in_array('kind_scope', $indexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$table} ADD UNIQUE KEY kind_scope (kind, variation_id, order_id)"
            );
        }

        $rows = $wpdb->get_results(
            "SELECT id, variation_id, hizmet_amount, guvence_amount, commission_amount, line_items
             FROM {$table}
             WHERE line_items IS NULL OR line_items = ''"
        );
        foreach ($rows ?: [] as $row) {
            $lines = [];
            $variationId = (int) ($row->variation_id ?? 0);
            if ((float) $row->hizmet_amount >= 0.01) {
                $lines[] = [
                    'variation_id' => $variationId,
                    'title' => '',
                    'code' => 'hizmet',
                    'amount' => round((float) $row->hizmet_amount, 2),
                ];
            }
            if ((float) $row->guvence_amount >= 0.01) {
                $lines[] = [
                    'variation_id' => $variationId,
                    'title' => '',
                    'code' => 'guvence',
                    'amount' => round((float) $row->guvence_amount, 2),
                ];
            }
            if ((float) $row->commission_amount >= 0.01) {
                $lines[] = [
                    'variation_id' => $variationId,
                    'title' => '',
                    'code' => 'commission',
                    'amount' => round((float) $row->commission_amount, 2),
                ];
            }
            $wpdb->update(
                $table,
                ['line_items' => wp_json_encode($lines)],
                ['id' => (int) $row->id]
            );
        }
    }
}
