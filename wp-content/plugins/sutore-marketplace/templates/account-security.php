<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @var \WP_User $user */
/** @var int $userId */
/** @var string $phone */
/** @var mixed $marketingConsent */

$displayPhone = $phone;
?>
<div class="sutore-mp-account-security woocommerce-EditAccountForm edit-account">
    <fieldset class="sutore-mp-account-security__section">
        <legend><?php echo esc_html__('Account details', 'sutore-marketplace'); ?></legend>
        <p class="sutore-mp-account-security__lead">
            <?php echo esc_html__('Update your name, email, phone, and marketing preferences.', 'sutore-marketplace'); ?>
        </p>
        <form id="sutore-mp-account-details-form" class="sutore-mp-account-security__form" autocomplete="off">
            <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
                <label for="sutore_user_name"><?php echo esc_html__('First name', 'sutore-marketplace'); ?>&nbsp;<span class="required">*</span></label>
                <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="user_name" id="sutore_user_name" value="<?php echo esc_attr($user->first_name); ?>" required />
            </p>
            <p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
                <label for="sutore_user_lastname"><?php echo esc_html__('Last name', 'sutore-marketplace'); ?>&nbsp;<span class="required">*</span></label>
                <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="user_lastname" id="sutore_user_lastname" value="<?php echo esc_attr($user->last_name); ?>" required />
            </p>
            <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
                <label for="sutore_user_email"><?php echo esc_html__('Email address', 'sutore-marketplace'); ?>&nbsp;<span class="required">*</span></label>
                <input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="user_email" id="sutore_user_email" value="<?php echo esc_attr($user->user_email); ?>" required />
            </p>
            <p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
                <label for="sutore_user_phone"><?php echo esc_html__('Phone', 'sutore-marketplace'); ?>&nbsp;<span class="required">*</span></label>
                <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="user_phone" id="sutore_user_phone" value="<?php echo esc_attr($displayPhone); ?>" required />
            </p>
            <p class="woocommerce-form-row form-row form-row-wide">
                <label for="sutore_details_current_password"><?php echo esc_html__('Current password', 'sutore-marketplace'); ?>&nbsp;<span class="required">*</span></label>
                <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="current_password" id="sutore_details_current_password" autocomplete="current-password" required />
            </p>
            <p class="form-row form-row-wide sutore-mp-account-security__checkbox">
                <label>
                    <input type="checkbox" name="marketing_consent" value="1" <?php checked((string) $marketingConsent, '1'); ?> />
                    <?php echo esc_html__('I would like to receive marketing communications from Sutore via email and phone.', 'sutore-marketplace'); ?>
                </label>
            </p>
            <p class="sutore-mp-account-security__feedback" aria-live="polite"></p>
            <p class="woocommerce-form-row form-row">
                <button type="submit" class="woocommerce-Button button wp-element-button">
                    <?php echo esc_html__('Save changes', 'sutore-marketplace'); ?>
                </button>
            </p>
        </form>
    </fieldset>

    <fieldset class="sutore-mp-account-security__section">
        <legend><?php echo esc_html__('Password change', 'sutore-marketplace'); ?></legend>
        <form id="sutore-mp-account-password-form" class="sutore-mp-account-security__form" autocomplete="off">
            <p class="woocommerce-form-row form-row form-row-wide">
                <label for="sutore_password_current"><?php echo esc_html__('Current password', 'sutore-marketplace'); ?>&nbsp;<span class="required">*</span></label>
                <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="current_password" id="sutore_password_current" autocomplete="current-password" required />
            </p>
            <p class="woocommerce-form-row form-row form-row-wide">
                <label for="sutore_new_password"><?php echo esc_html__('New password', 'sutore-marketplace'); ?>&nbsp;<span class="required">*</span></label>
                <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="new_password" id="sutore_new_password" autocomplete="new-password" required />
            </p>
            <p class="woocommerce-form-row form-row form-row-wide">
                <label for="sutore_new_password_repeat"><?php echo esc_html__('Confirm new password', 'sutore-marketplace'); ?>&nbsp;<span class="required">*</span></label>
                <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="new_password_repeat" id="sutore_new_password_repeat" autocomplete="new-password" required />
            </p>
            <p class="sutore-mp-account-security__hint">
                <?php echo esc_html__('Your password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.', 'sutore-marketplace'); ?>
            </p>
            <p class="sutore-mp-account-security__feedback" aria-live="polite"></p>
            <p class="woocommerce-form-row form-row">
                <button type="submit" class="woocommerce-Button button wp-element-button">
                    <?php echo esc_html__('Update password', 'sutore-marketplace'); ?>
                </button>
            </p>
        </form>
    </fieldset>

    <fieldset class="sutore-mp-account-security__section sutore-mp-account-security__section--danger">
        <legend><?php echo esc_html__('Delete account', 'sutore-marketplace'); ?></legend>
        <?php if (user_can($userId, 'administrator')) : ?>
            <p class="sutore-mp-account-security__warning">
                <?php echo esc_html__('Administrator accounts cannot be deleted from this screen.', 'sutore-marketplace'); ?>
            </p>
        <?php else : ?>
        <p class="sutore-mp-account-security__warning">
            <?php echo esc_html__('All information regarding your account will be permanently deleted. This action cannot be undone.', 'sutore-marketplace'); ?>
        </p>
        <form id="sutore-mp-account-delete-form" class="sutore-mp-account-security__form" autocomplete="off">
            <p class="woocommerce-form-row form-row form-row-wide">
                <label for="sutore_delete_current_password"><?php echo esc_html__('Current password', 'sutore-marketplace'); ?>&nbsp;<span class="required">*</span></label>
                <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="current_password" id="sutore_delete_current_password" autocomplete="current-password" required />
            </p>
            <p class="sutore-mp-account-security__feedback" aria-live="polite"></p>
            <p class="woocommerce-form-row form-row">
                <button type="submit" class="woocommerce-Button button wp-element-button is-destructive">
                    <?php echo esc_html__('Delete my account', 'sutore-marketplace'); ?>
                </button>
            </p>
        </form>
        <?php endif; ?>
    </fieldset>
</div>
