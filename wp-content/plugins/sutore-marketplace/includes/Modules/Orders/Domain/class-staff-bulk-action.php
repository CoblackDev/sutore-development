<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Domain;

/**
 * Staff Manage Products bulk workflows that need no extra input fields.
 * UI only offers an action when every selected row allows it.
 */
final class StaffBulkAction
{
    /**
     * workflow_action values accepted by POST /fulfillments/bulk-actions.
     *
     * @return list<string>
     */
    public static function workflowActions(): array
    {
        return [
            'confirm_payment',
            'mark_arrived',
            'mark_verified',
            'mark_ready_to_ship',
            'mark_delivered',
            'mark_payout_paid',
            'mark_imported',
            'unmark_imported',
            'put_on_sale',
            'approve',
            'remove_from_sale',
            'mark_not_for_sale',
            'delete_listing',
        ];
    }

    public static function isValid(string $workflowAction): bool
    {
        return in_array($workflowAction, self::workflowActions(), true);
    }

    /**
     * Maps REST workflow_action → actions{} flag key from the presenter.
     */
    public static function actionFlag(string $workflowAction): string
    {
        return match ($workflowAction) {
            'delete_listing' => 'delete',
            'mark_payout_paid' => 'mark_payout',
            default => $workflowAction,
        };
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'confirm_payment' => __('Confirm Sale', 'sutore-marketplace'),
            'mark_arrived' => __('Arrived at Sutore', 'sutore-marketplace'),
            'mark_verified' => __('Verify product', 'sutore-marketplace'),
            'mark_ready_to_ship' => __('Ready to ship', 'sutore-marketplace'),
            'mark_delivered' => __('Delivered to customer', 'sutore-marketplace'),
            'mark_payout_paid' => __('Mark as Paid to Seller', 'sutore-marketplace'),
            'mark_imported' => __('Mark as imported', 'sutore-marketplace'),
            'unmark_imported' => __('Mark as not imported', 'sutore-marketplace'),
            'put_on_sale' => __('Put on sale', 'sutore-marketplace'),
            'approve' => __('Approve & put on sale', 'sutore-marketplace'),
            'remove_from_sale' => __('Remove from sale', 'sutore-marketplace'),
            'mark_not_for_sale' => __('Not for sale', 'sutore-marketplace'),
            'delete_listing' => __('Delete', 'sutore-marketplace'),
        ];
    }

    public static function label(string $workflowAction): string
    {
        $labels = self::labels();

        return $labels[$workflowAction] ?? $workflowAction;
    }

    /** Default merchant-visible note when bulk runs a note-required action. */
    public static function bulkStaffNote(): string
    {
        return __('Bulk staff action.', 'sutore-marketplace');
    }
}
