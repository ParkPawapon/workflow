<?php
require_once __DIR__ . '/../../helpers.php';

$teacher_directory = (array) ($teacher_directory ?? []);
$teacher_directory_total = (int) ($teacher_directory_total ?? 0);
$teacher_directory_total_pages = (int) ($teacher_directory_total_pages ?? 0);
$teacher_directory_page = (int) ($teacher_directory_page ?? 1);
$teacher_directory_per_page = $teacher_directory_per_page ?? 10;
$teacher_directory_display_per_page = (string) ($teacher_directory_display_per_page ?? '10');
$teacher_directory_query = (string) ($teacher_directory_query ?? '');

ob_start();
?>
<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>สมุดโทรศัพท์</p>
</div>

<div class="teacher-phone-table-control">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="text" id="search-input" value="<?= h($teacher_directory_query) ?>" placeholder="ค้นหาด้วย ชื่อจริง-นามสกุล หรือ กลุ่มสาระ หรือ เบอร์โทรศัพท์">
    </div>

    <div class="page-selector">
        <p>จำนวนต่อ 1 หน้า</p>

        <div class="custom-select-wrapper">
            <div class="custom-select-trigger">
                <p id="select-value" class="select-value"><?= h($teacher_directory_display_per_page) ?></p>
                <i class="fa-solid fa-caret-down" aria-hidden="true"></i>
            </div>

            <div class="custom-options">
                <div class="custom-option<?= $teacher_directory_per_page === 10 ? ' selected' : '' ?>" data-value="10">10</div>
                <div class="custom-option<?= $teacher_directory_per_page === 20 ? ' selected' : '' ?>" data-value="20">20</div>
                <div class="custom-option<?= $teacher_directory_per_page === 50 ? ' selected' : '' ?>" data-value="50">50</div>
                <div class="custom-option<?= $teacher_directory_per_page === 'all' ? ' selected' : '' ?>" data-value="all">ทั้งหมด</div>
            </div>

            <select name="" id="real-page-select">
                <option value="10" <?= $teacher_directory_per_page === 10 ? 'selected' : '' ?>>10</option>
                <option value="20" <?= $teacher_directory_per_page === 20 ? 'selected' : '' ?>>20</option>
                <option value="50" <?= $teacher_directory_per_page === 50 ? 'selected' : '' ?>>50</option>
                <option value="all" <?= $teacher_directory_per_page === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
            </select>
        </div>
    </div>
</div>

<div class="teacher-phone-table-container">
    <table>
        <thead>
            <tr>
                <th>ชื่อจริง-นามสกุล</th>
                <th>กลุ่มสาระฯ</th>
                <th>เบอร์โทร</th>
            </tr>
        </thead>
        <tbody id="teacher-table-body" data-endpoint="public/api/teacher-directory-api.php">
            <?php if (empty($teacher_directory)) : ?>
                <tr>
                    <td colspan="3" class="enterprise-empty">ไม่พบข้อมูล</td>
                </tr>
            <?php else : ?>
                <?php foreach ($teacher_directory as $teacher_item) : ?>
                    <tr>
                        <td><?= h($teacher_item['fName'] ?? '') ?></td>
                        <td><?= h($teacher_item['department_name'] ?? '') ?></td>
                        <td><?= h($teacher_item['telephone'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="teacher-phone-footer-control">
    <div class="count-text" id="count-text">
        <p>จำนวน <?= h((string) number_format($teacher_directory_total)) ?> รายชื่อ</p>
    </div>
    <div class="teacher-phone-pagination" id="pagination">
        <?php if ($teacher_directory_per_page !== 'all' && $teacher_directory_total_pages > 1) : ?>
            <?php
            $total_pages = $teacher_directory_total_pages;
            $current_page = $teacher_directory_page;
            $prev_page = max(1, $current_page - 1);
            $next_page = min($total_pages, $current_page + 1);
            ?>
            <button type="button" data-page="<?= h((string) $prev_page) ?>" <?= $current_page <= 1 ? 'disabled' : '' ?> aria-label="Previous page">
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </button>
            <?php
            $start_page = 1;
            $end_page = $total_pages;

            if ($total_pages > 7) {
                if ($current_page <= 4) {
                    $end_page = 5;
                } elseif ($current_page >= $total_pages - 3) {
                    $start_page = $total_pages - 4;
                } else {
                    $start_page = $current_page - 2;
                    $end_page = $current_page + 2;
                }
            }

            if ($start_page > 1) {
            ?>
                <button type="button" data-page="1" <?= $current_page === 1 ? 'class="active"' : '' ?>>1</button>
                <?php if ($start_page > 2) : ?>
                    <span class="enterprise-ellipsis">...</span>
                <?php endif; ?>
            <?php
            }

            for ($i = $start_page; $i <= $end_page; $i++) {
            ?>
                <button type="button" data-page="<?= h((string) $i) ?>" <?= $i === $current_page ? 'class="active"' : '' ?>><?= h((string) $i) ?></button>
                <?php
            }

            if ($end_page < $total_pages) {
                if ($end_page < $total_pages - 1) {
                ?>
                    <span class="enterprise-ellipsis">...</span>
                <?php
                }
                ?>
                <button type="button" data-page="<?= h((string) $total_pages) ?>" <?= $current_page === $total_pages ? 'class="active"' : '' ?>><?= h((string) $total_pages) ?></button>
            <?php
            }
            ?>
            <button type="button" data-page="<?= h((string) $next_page) ?>" <?= $current_page >= $total_pages ? 'disabled' : '' ?> aria-label="Next page">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
