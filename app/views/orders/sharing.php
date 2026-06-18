<?php
require_once __DIR__ . '/../../helpers.php';

$item = $item ?? null;
$attachments = (array) ($attachments ?? []);
$share_token = trim((string) ($share_token ?? ''));

$format_thai_date = static function (?string $date): string {
    $date = trim((string) $date);

    if ($date === '' || $date === '0000-00-00') {
        return '-';
    }

    try {
        $value = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? new DateTime($date . ' 00:00:00')
            : new DateTime($date);
    } catch (Throwable $e) {
        return $date;
    }

    $months = [
        1 => 'มกราคม',
        2 => 'กุมภาพันธ์',
        3 => 'มีนาคม',
        4 => 'เมษายน',
        5 => 'พฤษภาคม',
        6 => 'มิถุนายน',
        7 => 'กรกฎาคม',
        8 => 'สิงหาคม',
        9 => 'กันยายน',
        10 => 'ตุลาคม',
        11 => 'พฤศจิกายน',
        12 => 'ธันวาคม',
    ];

    $month = (int) $value->format('n');
    $year = (int) $value->format('Y') + 543;

    return 'วันที่ ' . (int) $value->format('j') . ' ' . ($months[$month] ?? '') . ' พ.ศ. ' . $year;
};

$format_thai_datetime = static function (?string $datetime) use ($format_thai_date): string {
    $datetime = trim((string) $datetime);

    if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }

    try {
        $value = new DateTime($datetime);
    } catch (Throwable $e) {
        return $datetime;
    }

    return $format_thai_date($value->format('Y-m-d')) . ' เวลา ' . $value->format('H:i') . ' น.';
};

$parse_order_meta = static function (?string $detail): array {
    $text = trim((string) $detail);
    $meta = [
        'effective_date' => '',
        'order_date' => '',
        'issuer_name' => '',
        'group_name' => '',
    ];

    if ($text === '') {
        return $meta;
    }

    if (preg_match('/^ทั้งนี้ตั้งแต่วันที่:\s*(.+)$/m', $text, $matches) === 1) {
        $meta['effective_date'] = trim((string) ($matches[1] ?? ''));
    }

    if (preg_match('/^สั่ง ณ วันที่:\s*(.+)$/m', $text, $matches) === 1) {
        $meta['order_date'] = trim((string) ($matches[1] ?? ''));
    }

    if (preg_match('/^ผู้(?:ออก|สร้าง)เลขคำสั่ง:\s*(.+)$/m', $text, $matches) === 1) {
        $value = trim((string) ($matches[1] ?? ''));
        $meta['issuer_name'] = $value !== '-' ? $value : '';
    }

    if (preg_match('/^กลุ่ม:\s*(.+)$/m', $text, $matches) === 1) {
        $value = trim((string) ($matches[1] ?? ''));
        $meta['group_name'] = $value !== '-' ? $value : '';
    }

    return $meta;
};

$file_url = static function (int $file_id, bool $download = false) use ($share_token): string {
    if ($share_token === '' || $file_id <= 0) {
        return '#';
    }

    $url = 'orders-sharing-file.php?token=' . rawurlencode($share_token) . '&file_id=' . rawurlencode((string) $file_id);

    return $download ? $url . '&download=1' : $url;
};

$download_all_url = $share_token !== '' ? 'orders-sharing-file.php?token=' . rawurlencode($share_token) . '&all=1' : '';
$meta = $item ? $parse_order_meta((string) ($item['detail'] ?? '')) : [
    'effective_date' => '',
    'order_date' => '',
    'issuer_name' => '',
    'group_name' => '',
];

$order_no = trim((string) ($item['orderNo'] ?? ''));
$subject = trim((string) ($item['subject'] ?? ''));
$document_title = $order_no !== '' ? 'คำสั่งราชการที่ ' . $order_no : 'คำสั่งราชการ';
$issuer_name = trim((string) ($meta['issuer_name'] ?? ''));
$issuer_name = $issuer_name !== '' ? $issuer_name : trim((string) ($item['creatorName'] ?? ''));
$group_name = trim((string) ($meta['group_name'] ?? ''));
$group_name = $group_name !== '' ? $group_name : trim((string) ($item['creatorFactionName'] ?? ''));
$effective_date = trim((string) ($meta['effective_date'] ?? ''));
$order_date = trim((string) ($meta['order_date'] ?? ''));
$created_at = trim((string) ($item['createdAt'] ?? ''));
?>
<!DOCTYPE html>
<html lang="th">
<?php require __DIR__ . '/../../../public/components/x-head.php'; ?>

<style>
    .booking-table thead th:first-child {
        text-align: start;
        width: 500px;
        min-width: 500px;
        max-width: 500px;
    }

    .booking-table thead th:last-child {
        width: 180px;
        min-width: 180px;
        max-width: 180px;
    }

    .booking-table {
        min-width: 0;
        max-width: none;
        width: 100%;
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
        cursor: grab;
        scrollbar-width: thin;
        scrollbar-color: var(--color-secondary-light) transparent;
        border-radius: 12px;
        border: 0.5px solid var(--color-neutral-dark);
    }

    .table-responsive.is-dragging {
        cursor: grabbing;
    }

    .table-responsive.is-dragging table {
        -webkit-user-select: none;
        user-select: none;
    }

    @media screen and (max-width: 1024px) {
        .booking-table thead th:first-child {
            width: 100%;
            min-width: 400px;
        }

        .booking-table thead th:last-child {
            width: 160px;
            min-width: 160px;
            max-width: 160px;
        }
    }

    @media screen and (max-width: 768px) {
        .booking-table thead th:first-child {
            width: 100%;
            min-width: 400px;
        }

        .booking-table thead th:last-child {
            width: 140px;
            min-width: 140px;
            max-width: 140px;
        }
    }
</style>

<body class="orders-sharing-public">
    <main class="orders-sharing-shell">
        <div class="content-header">
            <h1><?= h($item ? $document_title : 'ไม่พบคำสั่งราชการ') ?></h1>
        </div>

        <main class="content-sharing-card">
            <div class="header">
                <p><?= h($item ? ($subject !== '' ? $subject : '-') : 'ไม่พบข้อมูลคำสั่งราชการ') ?></p>
            </div>
            <div class="sharing-row">
                <div class="group">
                    <label for="">ทั้งนี้ตั้งแต่วันที่</label>
                    <p><?= h($format_thai_date($effective_date)) ?></p>
                </div>
                <div class="group">
                    <label for="">สั่ง ณ วันที่</label>
                    <p><?= h($format_thai_date($order_date)) ?></p>
                </div>
            </div>
            <div class="sharing-row">
                <div class="group">
                    <label for="">ผู้สร้างเลขคำสั่ง</label>
                    <p><?= h($issuer_name !== '' ? $issuer_name : '-') ?></p>
                </div>
                <div class="group">
                    <label for="">กลุ่ม/ฝ่าย</label>
                    <p><?= h($group_name !== '' ? $group_name : '-') ?></p>
                </div>
            </div>
            <div class="sharing-row">
                <div class="group">
                    <label for="">วันที่สร้างรายการ</label>
                    <p><?= h($format_thai_datetime($created_at)) ?></p>
                </div>
            </div>

            <hr>

            <section class="sharing-table">
                <div class="header">
                    <p>ไฟล์เอกสารแนบจากระบบ</p>
                    <?php if ($item && $attachments !== [] && $download_all_url !== '') : ?>
                        <a href="<?= h($download_all_url) ?>">ดาวน์โหลดไฟล์ทั้งหมด</a>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="custom-table booking-table">
                        <thead>
                            <tr>
                                <th>ชื่อไฟล์</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$item || $attachments === []) : ?>
                                <tr>
                                    <td colspan="2" class="enterprise-empty">ไม่พบไฟล์เอกสารแนบจากระบบ</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($attachments as $file) : ?>
                                    <?php
                                    $file_id = (int) ($file['fileID'] ?? 0);
                                    $view_href = $file_url($file_id, false);
                                    $download_href = $file_url($file_id, true);
                                    $file_name = trim((string) ($file['fileName'] ?? ''));
                                    ?>
                                    <tr>
                                        <td><?= h($file_name !== '' ? $file_name : '-') ?></td>
                                        <td>
                                            <a class="booking-action-btn secondary" href="<?= h($view_href) ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูไฟล์</span>
                                            </a>
                                            <a class="booking-action-btn secondary" href="<?= h($download_href) ?>">
                                                <i class="fa-solid fa-download"></i>
                                                <span class="tooltip">ดาวน์โหลด</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </main>
</body>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const slider = document.querySelector('.table-responsive');

        if (!slider) return;

        let isDown = false;
        let startX;
        let scrollLeft;

        slider.addEventListener('mousedown', (e) => {
            isDown = true;
            slider.classList.add('is-dragging');
            startX = e.pageX - slider.offsetLeft;
            scrollLeft = slider.scrollLeft;
        });

        slider.addEventListener('mouseleave', () => {
            isDown = false;
            slider.classList.remove('is-dragging');
        });

        slider.addEventListener('mouseup', () => {
            isDown = false;
            slider.classList.remove('is-dragging');
        });

        slider.addEventListener('mousemove', (e) => {
            if (!isDown) return;

            e.preventDefault();

            const x = e.pageX - slider.offsetLeft;
            const walk = (x - startX) * 1.5;

            slider.scrollLeft = scrollLeft - walk;
        });
    });
</script>

</html>