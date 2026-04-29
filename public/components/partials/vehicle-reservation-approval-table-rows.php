<?php if (empty($vehicle_booking_requests)): ?>
    <tr>
        <td colspan="7" class="booking-empty">ไม่พบรายการ</td>
    </tr>
<?php else: ?>
    <?php foreach ($vehicle_booking_requests as $request_item): ?>
        <?php
        $status_key = strtoupper(trim((string) ($request_item['status'] ?? 'PENDING')));
        $status_meta = $vehicle_approval_status_labels[$status_key] ?? $vehicle_approval_status_labels['PENDING'];
        $status_label = $status_meta['label'] ?? 'ส่งเอกสารแล้ว';
        $status_class = $status_meta['class'] ?? 'pending';

        $start_at = (string) ($request_item['startAt'] ?? '');
        $end_at = (string) ($request_item['endAt'] ?? '');
        $start_date = $start_at !== '' ? substr($start_at, 0, 10) : '';
        $end_date = $end_at !== '' ? substr($end_at, 0, 10) : '';
        $date_range = $format_thai_date_range($start_date, $end_date !== '' ? $end_date : $start_date);
        $time_range = '-';

        if ($start_at !== '' && $end_at !== '') {
            $start_time = substr($start_at, 11, 5);
            $end_time = substr($end_at, 11, 5);
            $time_range = trim($start_time . '-' . $end_time);
        }

        $requester_name = trim((string) ($request_item['requesterDisplayName'] ?? ''));

        if ($requester_name === '') {
            $requester_name = trim((string) ($request_item['requester_name'] ?? ''));
        }
        // Show the requester's department from teacher master data (more reliable than free-text booking.department).
        $department_name = trim((string) ($request_item['department_name'] ?? ''));

        if ($department_name === '') {
            $department_name = trim((string) ($request_item['department'] ?? ''));
        }
        $contact_phone = trim((string) ($request_item['requester_phone'] ?? ''));
        $purpose_text = trim((string) ($request_item['purpose'] ?? ''));
        $purpose_text = $purpose_text !== '' ? $purpose_text : '-';
        $purpose_display = $purpose_text;

        if ($purpose_text !== '-' && $purpose_text !== '') {
            $max_chars = 50;
            $purpose_trimmed = trim($purpose_text);
            $purpose_len = function_exists('mb_strlen') ? mb_strlen($purpose_trimmed, 'UTF-8') : strlen($purpose_trimmed);

            if ($purpose_len > $max_chars) {
                $purpose_slice = function_exists('mb_substr')
                    ? mb_substr($purpose_trimmed, 0, $max_chars, 'UTF-8')
                    : substr($purpose_trimmed, 0, $max_chars);
                $purpose_display = rtrim($purpose_slice) . '...';
            }
        }
        $location_text = trim((string) ($request_item['location'] ?? ''));
        $location_text = $location_text !== '' ? $location_text : '-';
        $passenger_count = (string) ($request_item['passengerCount'] ?? $request_item['companionCount'] ?? '-');
        $driver_pid = trim((string) ($request_item['driverPID'] ?? ''));
        $driver_name = trim((string) ($request_item['driverName'] ?? ''));
        $driver_tel = trim((string) ($request_item['driverTel'] ?? ''));
        $driver_label = $driver_name !== '' ? $driver_name : '-';

        if ($driver_tel !== '') {
            $driver_label .= ' (' . $driver_tel . ')';
        }

        $assigned_name = trim((string) ($request_item['assigned_name'] ?? ''));
        $assigned_at_raw = trim((string) ($request_item['assignedAt'] ?? ''));
        $assigned_at = $assigned_at_raw !== '' ? $format_thai_datetime($assigned_at_raw) : '';
        $assigned_note = trim((string) ($request_item['assignedNote'] ?? ''));
        $final_approver_pid = trim((string) ($request_item['finalApproverPID'] ?? ''));
        $final_approver_name = trim((string) ($request_item['final_approver_name'] ?? ''));
        $approval_note = trim((string) ($request_item['approvalNote'] ?? ''));

        $vehicle_id = trim((string) ($request_item['vehicleID'] ?? ''));
        $vehicle_plate = trim((string) ($request_item['vehiclePlate'] ?? ''));
        $vehicle_type = trim((string) ($request_item['vehicleType'] ?? ''));
        $vehicle_label = $vehicle_plate !== '' ? $vehicle_plate : $vehicle_type;

        if ($vehicle_label === '') {
            $vehicle_label = (string) ($request_item['vehicleID'] ?? '-');
        }
        $vehicle_detail = trim($vehicle_type . ' ' . (string) ($request_item['vehicleModel'] ?? ''));
        $vehicle_detail = trim($vehicle_detail) !== '' ? trim($vehicle_detail) : '-';

        $approval_name = trim((string) ($request_item['approver_name'] ?? ''));

        if ($status_key === 'ASSIGNED') {
            $approval_name = $assigned_name !== '' ? $assigned_name : ($approval_name !== '' ? $approval_name : 'เจ้าหน้าที่ระบบ');
        } elseif ($approval_name === '' && $status_key !== 'PENDING') {
            $approval_name = 'เจ้าหน้าที่ระบบ';
        }
        $approval_name = $status_key === 'PENDING' ? 'รอการอนุมัติ' : $approval_name;
        $approval_at = $status_key === 'ASSIGNED'
            ? ($assigned_at !== '' ? $assigned_at : $format_thai_datetime((string) ($request_item['approvedAt'] ?? '')))
            : $format_thai_datetime((string) ($request_item['approvedAt'] ?? ''));
        $created_label = $format_thai_datetime((string) ($request_item['createdAt'] ?? ''));
        $updated_label = $format_thai_datetime((string) ($request_item['updatedAt'] ?? ''));

        $attachments = $vehicle_booking_attachments[(string) ($request_item['bookingID'] ?? '')] ?? [];
        $attachments_json = htmlspecialchars(
            json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES,
            'UTF-8'
        );
        ?>
        <tr class="approval-row <?= htmlspecialchars($status_class, ENT_QUOTES, 'UTF-8') ?>">
            <td>
                <?= htmlspecialchars($date_range, ENT_QUOTES, 'UTF-8') ?><br>
                <span class="detail-subtext"><?= htmlspecialchars($time_range, ENT_QUOTES, 'UTF-8') ?></span>
            </td>
            <td>
                <?= htmlspecialchars($requester_name !== '' ? $requester_name : '-', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td>
                <?= htmlspecialchars($vehicle_label, ENT_QUOTES, 'UTF-8') ?>
                <div class="detail-subtext"><?= htmlspecialchars($vehicle_detail, ENT_QUOTES, 'UTF-8') ?></div>
            </td>
            <td>
                <?= htmlspecialchars($purpose_display, ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td>
                <?= htmlspecialchars($location_text, ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td>
                <span class="status-pill <?= htmlspecialchars($status_class, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </td>
            <td class="booking-action-cell">
                <div class="booking-action-group">
                    <button type="button" class="booking-action-btn secondary" data-vehicle-approval-action="detail"
                        data-approval-id="<?= htmlspecialchars((string) ($request_item['bookingID'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-code="<?= htmlspecialchars((string) ($request_item['bookingID'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-vehicle-id="<?= htmlspecialchars($vehicle_id, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-vehicle="<?= htmlspecialchars($vehicle_label, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-driver-id="<?= htmlspecialchars($driver_pid, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-driver-name="<?= htmlspecialchars($driver_name, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-driver-tel="<?= htmlspecialchars($driver_tel, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-final-approver-id="<?= htmlspecialchars($final_approver_pid, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-final-approver-name="<?= htmlspecialchars($final_approver_name, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-date="<?= htmlspecialchars($date_range, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-time="<?= htmlspecialchars($time_range, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-requester="<?= htmlspecialchars($requester_name !== '' ? $requester_name : '-', ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-department="<?= htmlspecialchars($department_name !== '' ? $department_name : '-', ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-contact="<?= htmlspecialchars($contact_phone !== '' ? $contact_phone : '-', ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-purpose="<?= htmlspecialchars($purpose_text, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-location="<?= htmlspecialchars($location_text, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-passengers="<?= htmlspecialchars((string) $passenger_count, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-driver="<?= htmlspecialchars($driver_label, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-status="<?= htmlspecialchars($status_key, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-status-label="<?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-status-class="<?= htmlspecialchars($status_class, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-name="<?= htmlspecialchars($approval_name, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-at="<?= htmlspecialchars($approval_at, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-created="<?= htmlspecialchars($created_label, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-updated="<?= htmlspecialchars($updated_label, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-assigned-note="<?= htmlspecialchars($assigned_note, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-approval-note="<?= htmlspecialchars($approval_note, ENT_QUOTES, 'UTF-8') ?>"
                        data-approval-attachments="<?= $attachments_json ?>">
                        <i class="fa-solid fa-eye"></i>
                        <span class="tooltip">ดูรายละเอียด</span>
                    </button>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
