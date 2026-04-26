<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers.php';

if (!function_exists('memo_pdf_render_html')) {
    /**
     * @param array<string, mixed> $data
     */
    function memo_pdf_render_html(array $data): string
    {
        $document_title = trim((string) ($data['document_title'] ?? 'บันทึกข้อความ'));
        $school_name = trim((string) ($data['school_name'] ?? 'โรงเรียนดีบุกพังงาวิทยายน'));
        $section_name = trim((string) ($data['section_name'] ?? '-'));
        $memo_no = trim((string) ($data['memo_no'] ?? '-'));
        $subject = trim((string) ($data['subject'] ?? ''));
        $to_name = trim((string) ($data['to_name'] ?? 'ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน'));
        $write_date_label = trim((string) ($data['write_date_label'] ?? '19 มิถุนายน 2569'));
        $logo_data_uri = trim((string) ($data['logo_data_uri'] ?? ''));
        $owner_signature = trim((string) ($data['owner_signature'] ?? ''));
        $owner_name = trim((string) ($data['owner_name'] ?? '-'));
        $owner_position = app_format_position_label(trim((string) ($data['owner_position'] ?? '-')));
        $review_blocks = $data['review_blocks'] ?? [];
        $body_paragraphs = $data['body_paragraphs'] ?? [];
        $section_line = $section_name !== '' ? $section_name : '-';

        if ($school_name !== '' && $school_name !== '-') {
            if ($section_line === '' || $section_line === '-') {
                $section_line = $school_name;
            } elseif (mb_strpos($section_line, $school_name) === false) {
                $section_line .= ' ' . $school_name;
            }
        }

        if (!is_array($review_blocks)) {
            $review_blocks = [];
        }

        if (!is_array($body_paragraphs)) {
            $body_paragraphs = [];
        }

        $body_paragraphs = array_values(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $body_paragraphs
        ), static fn(string $value): bool => $value !== ''));

        ob_start();
        ?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <style>
        body {
            margin: 0;
            font-family: sarabun;
            font-size: 12pt;
            line-height: 1.55;
            color: #111111;
            background: #ffffff;
        }
        .memo-header-table,
        .memo-line-table,
        .signature-table,
        .review-signature-table {
            width: 100%;
            border-collapse: collapse;
        }
        .memo-header-table td,
        .memo-line-table td,
        .signature-table td,
        .review-signature-table td {
            vertical-align: top;
        }
        .memo-header {
            margin: 0 0 10pt 0;
        }
        .memo-header-logo {
            width: 60pt;
        }
        .memo-header-logo img {
            display: block;
            width: 24px;
            height: auto;
        }
        .memo-header-title {
            text-align: center;
            font-size: 18pt;
            font-weight: bold;
            padding-top: 12pt;
        }
        .memo-header-spacer {
            width: 60pt;
        }
        .form-group-row {
            margin: 0 0 8pt 0;
        }
        .line-label {
            width: 74pt;
            font-weight: bold;
            white-space: nowrap;
        }
        .line-gap {
            width: 8pt;
        }
        .subject-value,
        .detail-paragraph,
        .review-note,
        .line-value {
            text-align: justify;
        }
        .inline-line {
            padding-top: 1pt;
        }
        .line-value-half {
            width: 50%;
            padding-top: 1pt;
        }
        .subject-value,
        .line-value {
            padding-top: 1pt;
        }
        .detail-label,
        .review-title {
            margin: 0 0 6pt 0;
            font-weight: bold;
        }
        .subject-divider {
            border-bottom: 2.4pt solid #000000;
            height: 0;
            margin: 4pt 0 10pt 0;
        }
        .detail-block {
            margin-top: 14pt;
            min-height: 260pt;
        }
        .detail-paragraph {
            margin: 0 0 8pt 0;
            text-indent: 1.8em;
        }
        .detail-placeholder {
            height: 220pt;
        }
        .signature-table {
            margin-top: 16pt;
        }
        .signature-spacer,
        .review-signature-spacer {
            width: 50%;
        }
        .signature-block,
        .review-signature-block {
            width: 50%;
            text-align: center;
        }
        .signature-image {
            display: block;
            width: 95pt;
            height: 46pt;
            object-fit: contain;
            margin: 0 auto;
        }
        .signature-placeholder {
            height: 46pt;
        }
        .signature-name,
        .signature-role {
            margin: 0;
        }
        .review-block {
            margin: 0 0 18pt 0;
            page-break-inside: avoid;
        }
        .review-note {
            min-height: 70pt;
            white-space: pre-line;
        }
        .review-placeholder {
            height: 18pt;
        }
    </style>
</head>
<body>
    <div class="content-memo">
        <div class="memo-header">
            <table class="memo-header-table" cellpadding="0" cellspacing="0">
                <tr>
                    <td class="memo-header-logo">
                        <?php if ($logo_data_uri !== '') : ?>
                            <img src="<?= h($logo_data_uri) ?>" width="60px" alt="ตราครุฑ">
                        <?php endif; ?>
                    </td>
                    <td class="memo-header-title"><?= h($document_title) ?></td>
                    <td class="memo-header-spacer"></td>
                </tr>
            </table>
        </div>

        <div class="memo-detail">
            <div class="form-group-row">
                <table class="memo-line-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="line-label">ส่วนราชการ</td>
                        <td class="line-gap"></td>
                        <td class="line-value"><?= h($section_line) ?></td>
                    </tr>
                </table>
            </div>

            <div class="form-group-row">
                <table class="memo-line-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="line-value-half"><strong>ที่</strong> <?= h($memo_no !== '' ? $memo_no : '-') ?></td>
                        <td class="line-value-half"><strong>วันที่</strong> <?= h($write_date_label !== '' ? $write_date_label : '19 มิถุนายน 2569') ?></td>
                    </tr>
                </table>
            </div>

            <div class="form-group-row">
                <table class="memo-line-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="inline-line"><strong>เรื่อง</strong> <?= h($subject !== '' ? $subject : '-') ?></td>
                    </tr>
                </table>
            </div>

            <div class="subject-divider"></div>

            <div class="form-group-row">
                <table class="memo-line-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="inline-line"><strong>เรียน</strong> <?= h($to_name !== '' ? $to_name : '-') ?></td>
                    </tr>
                </table>
            </div>

            <div class="detail-block">
                <p class="detail-label">รายละเอียด:</p>
                <?php if ($body_paragraphs === []) : ?>
                    <div class="detail-placeholder"></div>
                <?php else : ?>
                    <?php foreach ($body_paragraphs as $paragraph) : ?>
                        <p class="detail-paragraph"><?= h($paragraph) ?></p>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <table class="signature-table" cellpadding="0" cellspacing="0">
                <tr>
                    <td class="signature-spacer"></td>
                    <td class="signature-block">
                        <?php if ($owner_signature !== '') : ?>
                            <img class="signature-image" src="<?= h($owner_signature) ?>" alt="ลายเซ็น">
                        <?php else : ?>
                            <div class="signature-placeholder"></div>
                        <?php endif; ?>
                        <p class="signature-name">(<?= h($owner_name !== '' ? $owner_name : '-') ?>)</p>
                        <p class="signature-role"><?= h($owner_position !== '' ? $owner_position : '-') ?></p>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <?php if ($review_blocks !== []) : ?>
        <?php foreach ($review_blocks as $block) : ?>
            <?php
            $review_title = trim((string) ($block['title'] ?? 'ความคิดเห็นและข้อเสนอแนะ'));
            $review_note = trim((string) ($block['note'] ?? ''));
            $review_signature = trim((string) ($block['signature'] ?? ''));
            $reviewer_name = trim((string) ($block['name'] ?? '-'));
            $reviewer_position = app_format_position_label(trim((string) ($block['position'] ?? '-')));
            ?>
            <div class="review-block">
                <p class="review-title"><?= h($review_title) ?></p>
                <div class="review-note">
                    <?php if ($review_note !== '') : ?>
                        <?= nl2br(h($review_note)) ?>
                    <?php else : ?>
                        <div class="review-placeholder"></div>
                        <div class="review-placeholder"></div>
                        <div class="review-placeholder"></div>
                    <?php endif; ?>
                </div>

                <table class="review-signature-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="review-signature-spacer"></td>
                        <td class="review-signature-block">
                            <?php if ($review_signature !== '') : ?>
                                <img class="signature-image" src="<?= h($review_signature) ?>" alt="ลายเซ็น">
                            <?php else : ?>
                                <div class="signature-placeholder"></div>
                            <?php endif; ?>
                            <p class="signature-name">(<?= h($reviewer_name !== '' ? $reviewer_name : '-') ?>)</p>
                            <p class="signature-role"><?= h($reviewer_position !== '' ? $reviewer_position : '-') ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }
}
