<?php if (empty($room_booking_approval_requests)) : ?>
    <tr>
        <td colspan="7" class="booking-empty">ยังไม่มีรายการการจอง</td>
    </tr>
<?php else : ?>
    <?php foreach ($room_booking_approval_requests as $request_item) : ?>
        <?php
        $status_value = (int) ($request_item['status'] ?? 0);
        $status_label = $room_booking_approval_status_labels[$status_value]['label'] ?? $room_booking_approval_status_labels[0]['label'];
        $status_class = $room_booking_approval_status_labels[$status_value]['class'] ?? $room_booking_approval_status_labels[0]['class'];
        $date_range = $format_thai_date_range(
            (string) ($request_item['startDate'] ?? ''),
            (string) ($request_item['endDate'] ?? '')
        );
        $time_range = trim((string) ($request_item['startTime'] ?? '') . '-' . (string) ($request_item['endTime'] ?? ''));
        $detail_text = trim((string) ($request_item['bookingDetail'] ?? ''));
        $detail_text = $detail_text !== '' ? $detail_text : 'ไม่มีรายละเอียดเพิ่มเติม';
        $equipment_text = trim((string) ($request_item['equipmentDetail'] ?? ''));
        $equipment_text = $equipment_text !== '' ? $equipment_text : '-';
        $contact_phone = trim((string) ($request_item['contactPhone'] ?? ''));
        $contact_label = $contact_phone !== '' ? $contact_phone : '-';
        $approval_note = trim((string) ($request_item['approvalNote'] ?? ''));
        $approval_note = $approval_note !== '' ? $approval_note : '-';
        $approval_name = trim((string) ($request_item['approvedByName'] ?? ''));

        if ($approval_name === '' && $status_value !== 0) {
            $approval_name = 'เจ้าหน้าที่ระบบ';
        }
        $approval_name = $status_value === 0 ? 'รอการอนุมัติ' : $approval_name;
        $approval_at = $format_thai_datetime((string) ($request_item['approvedAt'] ?? ''));
        $created_label = $format_thai_datetime((string) ($request_item['createdAt'] ?? ''));
        $updated_label = $format_thai_datetime((string) ($request_item['updatedAt'] ?? ''));
        ?>
        <tr class="approval-row <?= h($status_class) ?>">
            <td class="booking-action-cell">
                <div class="booking-action-group">
                    <button type="button" class="booking-action-btn secondary" data-approval-action="detail"
                        data-approval-id="<?= h((string) ($request_item['roomBookingID'] ?? '-')) ?>"
                        data-approval-code="<?= h((string) ($request_item['roomBookingID'] ?? '-')) ?>"
                        data-approval-room="<?= h($request_item['roomName'] ?? '-') ?>"
                        data-approval-date="<?= h($date_range) ?>"
                        data-approval-time="<?= h($time_range) ?>"
                        data-approval-requester="<?= h($request_item['requesterName'] ?? '-') ?>"
                        data-approval-department="<?= h($request_item['departmentName'] ?? '-') ?>"
                        data-approval-contact="<?= h($contact_label) ?>"
                        data-approval-topic="<?= h($request_item['bookingTopic'] ?? '-') ?>"
                        data-approval-detail="<?= h($detail_text) ?>"
                        data-approval-equipment="<?= h($equipment_text) ?>"
                        data-approval-attendees="<?= h((string) ($request_item['attendeeCount'] ?? '-')) ?>"
                        data-approval-status="<?= h((string) $status_value) ?>"
                        data-approval-status-label="<?= h($status_label) ?>"
                        data-approval-status-class="<?= h($status_class) ?>"
                        data-approval-note="<?= h($approval_note) ?>"
                        data-approval-name="<?= h($approval_name) ?>"
                        data-approval-at="<?= h($approval_at) ?>"
                        data-approval-created="<?= h($created_label) ?>"
                        data-approval-updated="<?= h($updated_label) ?>">
                        <i class="fa-solid fa-eye"></i>
                        <span class="tooltip">ดูรายละเอียด</span>
                    </button>
                </div>
            </td>
            <td>
                <?= h($request_item['roomName'] ?? '-') ?>
            </td>
            <td>
                <?= h($date_range) ?><br>
                <span class="detail-subtext"><?= h($time_range !== '' ? $time_range : '-') ?></span>
            </td>
            <td>
                <?= h($request_item['requesterName'] ?? '-') ?>
                <div class="detail-subtext">โทร <?= h($contact_label) ?></div>
            </td>
            <td>
                <?= h($request_item['bookingTopic'] ?? '-') ?>
                <div class="detail-subtext">ส่งคำขอเมื่อ
                    <?= h($created_label) ?>
                </div>
            </td>
            <td>
                <?= h((string) ($request_item['attendeeCount'] ?? '-')) ?>
            </td>
            <td>
                <span class="status-pill <?= h($status_class) ?>">
                    <?= h($status_label) ?>
                </span>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
